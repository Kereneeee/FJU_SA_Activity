<?php
require_once(__DIR__ . "/../DB/db_config.php");
require_once(__DIR__ . "/../includes/FieldCoordinationManager.php");
require_once(__DIR__ . "/../includes/student_permissions.php");

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'student') {
    header('Location: ../login.php');
    exit();
}

$current_page = 'field_coord_records';

// 初始化場協管理器
$fc_manager = new FieldCoordinationManager($conn);

// 取得整數 user_id（session 可能存 email）
$user_id = null;
$_raw = isset($_SESSION['user_id']) ? $_SESSION['user_id'] : null;
if ($_raw !== null) {
    if (is_numeric($_raw)) {
        $user_id = (int)$_raw;
    } else {
        $s = $conn->prepare("SELECT user_id FROM users WHERE email = ? LIMIT 1");
        $s->bind_param("s", $_raw); $s->execute();
        $r = $s->get_result()->fetch_assoc(); $s->close();
        if ($r) $user_id = (int)$r['user_id'];
    }
}

$requested_access_club_id = $_POST['club_id'] ?? ($_GET['club_id'] ?? ($_SESSION['current_club_id'] ?? ($_SESSION['active_club_id'] ?? null)));
student_require_application_access($conn, (int)$user_id, $requested_access_club_id);

// 取得社團清單
$clubs = [];
if ($user_id) {
    $eligible_membership_sql = student_current_membership_sql_condition('cm');
    $s = $conn->prepare("SELECT cm.club_id, c.club_name
                         FROM club_members cm
                         JOIN clubs c ON cm.club_id=c.club_id
                         WHERE cm.user_id=? AND $eligible_membership_sql");
    $s->bind_param("i", $user_id); $s->execute();
    $cr = $s->get_result();
    while ($row = $cr->fetch_assoc()) $clubs[] = $row;
    $s->close();
}
$my_club_ids = array_column($clubs, 'club_id');

// 依個人檔案目前身分，決定本頁僅顯示哪個社團的登記紀錄
$active_club_id = '';
$active_club_name = '';
if (!empty($_SESSION['current_club_id'])) {
    foreach ($clubs as $c) {
        if ($c['club_id'] === $_SESSION['current_club_id']) {
            $active_club_id   = $c['club_id'];
            $active_club_name = $c['club_name'];
            break;
        }
    }
}
if ($active_club_id === '' && !empty($clubs)) {
    $active_club_id   = $clubs[0]['club_id'];
    $active_club_name = $clubs[0]['club_name'];
}
$display_club_ids = $active_club_id !== '' ? [$active_club_id] : [];

// ── AJAX：刪除登記 ─────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['ajax_action']) && $_POST['ajax_action'] === 'delete') {
    header('Content-Type: application/json');
    $reg_id = intval($_POST['reg_id'] ?? 0);
    // 驗證屬於本社
    $vs = $conn->prepare("SELECT fcr.event_id, fcr.club_id FROM field_coordination_registrations fcr WHERE fcr.registration_id=?");
    $vs->bind_param("i", $reg_id); $vs->execute();
    $vr = $vs->get_result()->fetch_assoc(); $vs->close();
    if (!$vr || !in_array($vr['club_id'], $my_club_ids)) {
        echo json_encode(['ok'=>false,'msg'=>'無權限']); exit;
    }
    $eid = $vr['event_id'];
    $conn->begin_transaction();
    try {
        $s = $conn->prepare("DELETE FROM reservations WHERE event_id=?");
        $s->bind_param("i",$eid); $s->execute(); $s->close();
        $s = $conn->prepare("DELETE FROM field_coordination_registrations WHERE registration_id=?");
        $s->bind_param("i",$reg_id); $s->execute(); $s->close();
        $s = $conn->prepare("UPDATE events SET status='cancelled' WHERE event_id=?");
        $s->bind_param("i",$eid); $s->execute(); $s->close();
        $conn->commit();
        echo json_encode(['ok'=>true]);
    } catch (Exception $e) {
        $conn->rollback();
        echo json_encode(['ok'=>false,'msg'=>$e->getMessage()]);
    }
    exit;
}

// ── AJAX：編輯場次 ──────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['ajax_action']) && $_POST['ajax_action'] === 'edit') {
    header('Content-Type: application/json');
    $reg_id = intval($_POST['reg_id'] ?? 0);
    $vs = $conn->prepare("SELECT fcr.event_id, fcr.setting_id, fcr.club_id FROM field_coordination_registrations fcr WHERE fcr.registration_id=?");
    $vs->bind_param("i",$reg_id); $vs->execute();
    $vr = $vs->get_result()->fetch_assoc(); $vs->close();
    if (!$vr || !in_array($vr['club_id'], $my_club_ids)) {
        echo json_encode(['ok'=>false,'msg'=>'無權限']); exit;
    }
    if (!$fc_manager->isInRegistrationPeriod($vr['setting_id'])) {
        echo json_encode(['ok'=>false,'msg'=>'目前不在場地協調登記期間，無法修改場次']); exit;
    }
    $eid = $vr['event_id'];
    $setting_id = $vr['setting_id'];
    $sessions = $_POST['sessions'] ?? [];
    $has_conflict = false;
    $conflict_rows = [];
    $updated_count = 0;
    $conn->begin_transaction();
    try {
        foreach ($sessions as $idx => $s_data) {
            $res_id   = intval($s_data['reservation_id'] ?? 0);
            $space_id = intval($s_data['space_id'] ?? 0);
            $date     = $s_data['date'] ?? '';
            $end_date = !empty($s_data['end_date']) ? $s_data['end_date'] : $date;
            $st       = $s_data['start_time'] ?? '';
            $et       = $s_data['end_time'] ?? '';
            if (!$res_id || !$date || !$st || !$et) {
                throw new Exception("場次 $idx 資料不完整：res_id=$res_id, date=$date, st=$st, et=$et");
            }
            if ($st < '08:30' || $st > '21:30' || $et < '08:30' || $et > '21:30') {
                throw new Exception("場次 $idx 時間須在 08:30–21:30 之間");
            }
            $start_dt = $date     . ' ' . $st . ':00';
            $end_dt   = $end_date . ' ' . $et . ':00';
            if (strtotime($start_dt) < time()) {
                throw new Exception("場次 $idx 開始時間不能早於現在");
            }
            if (strtotime($end_dt) <= strtotime($start_dt)) {
                throw new Exception("場次 $idx 結束時間必須晚於開始時間");
            }
            $chk_own = $conn->prepare("SELECT 1 FROM reservations WHERE reservation_id=? AND event_id=?");
            $chk_own->bind_param("ii", $res_id, $eid);
            $chk_own->execute();
            $owns = $chk_own->get_result()->num_rows > 0;
            $chk_own->close();
            if (!$owns) {
                throw new Exception("場次 $idx 無法更新：reservation_id=$res_id, event_id=$eid");
            }

            $us = $conn->prepare("UPDATE reservations SET space_id=?, start_time=?, end_time=? WHERE reservation_id=? AND event_id=?");
            if (!$us) throw new Exception('prepare 失敗：' . $conn->error);
            $us->bind_param("issii", $space_id, $start_dt, $end_dt, $res_id, $eid);
            if (!$us->execute()) throw new Exception('更新場次失敗：' . $us->error);
            $us->close();
            $updated_count++;

            $session_conflicts = $fc_manager->detectFieldCoordinationConflicts($setting_id, [$space_id], $start_dt, $end_dt, $reg_id);
            if (!empty($session_conflicts)) {
                $has_conflict = true;
                $conflict_rows[] = $idx;
            }
        }
        $conn->commit();
        echo json_encode(['ok'=>true, 'has_conflict'=>$has_conflict, 'conflict_rows'=>$conflict_rows, 'updated_count'=>$updated_count, 'total_sessions'=>count($sessions)]);
    } catch (Exception $e) {
        $conn->rollback();
        echo json_encode(['ok'=>false,'msg'=>$e->getMessage()]);
    }
    exit;
}

// ── 取得登記清單 ────────────────────────────────────────────
$records = [];
if (!empty($display_club_ids)) {
    $ph  = implode(',', array_fill(0, count($display_club_ids), '?'));
    $rs  = $conn->prepare(
        "SELECT fcr.registration_id, fcr.event_id, fcr.club_id, fcr.club_name, fcr.setting_id,
                fcr.is_approved, fcr.approval_note,
                e.event_name, e.description, e.status, e.responsible_person,
                fcs.academic_year, fcs.semester, fcs.coordination_meeting_date
         FROM field_coordination_registrations fcr
         JOIN events e ON fcr.event_id=e.event_id
         JOIN field_coordination_settings fcs ON fcr.setting_id=fcs.setting_id
         WHERE fcr.club_id IN ($ph)
         ORDER BY fcs.academic_year DESC, fcs.semester DESC, fcr.registration_id DESC"
    );
    $ba = array(str_repeat('s', count($display_club_ids)));
    foreach ($display_club_ids as &$cid) $ba[] = &$cid;
    call_user_func_array(array($rs, 'bind_param'), $ba);
    $rs->execute();
    $rr = $rs->get_result();
    while ($row = $rr->fetch_assoc()) $records[] = $row;
    $rs->close();
}

// ── 場次明細（含 reservation_id 和 space_id 供編輯用）────────
$sessions_map = [];
foreach ($records as $rec) {
    $eid = $rec['event_id'];
    if (isset($sessions_map[$eid])) continue;
    $ss = $conn->prepare(
        "SELECT r.reservation_id, r.space_id, r.start_time, r.end_time, s.space_name
         FROM reservations r LEFT JOIN spaces s ON r.space_id=s.space_id
         WHERE r.event_id=? ORDER BY r.start_time"
    );
    $ss->bind_param("i",$eid); $ss->execute();
    $sr = $ss->get_result();
    $sessions_map[$eid] = [];
    while ($row = $sr->fetch_assoc()) $sessions_map[$eid][] = $row;
    $ss->close();
}

// ── 可用場地清單（供 edit form 用）─────────────────────────
$all_spaces = [];
$sp_res = $conn->query("SELECT space_id, space_name FROM spaces WHERE space_status='available' ORDER BY space_id");
if ($sp_res) { while ($sp = $sp_res->fetch_assoc()) $all_spaces[] = $sp; }

// ── 場地衝突偵測（含跨社團）──────────────────────────────────
// conflicted_sessions[event_id][session_index] = 衝突詳細資訊陣列（與哪個社團/活動/時間衝突）
// conflicted_rids[registration_id] = true（只要有任一場次衝突就標記整筆）
$conflicted_sessions = [];
$conflicted_rids     = [];
foreach ($records as $rec) {
    $eid = $rec['event_id'];
    $rid = $rec['registration_id'];
    foreach ($sessions_map[$eid] ?? [] as $si => $sess) {
        if (empty($sess['space_id'])) continue;
        $confs = $fc_manager->detectFieldCoordinationConflicts(
            $rec['setting_id'], [$sess['space_id']], $sess['start_time'], $sess['end_time'], $rid
        );
        if (!empty($confs)) {
            $conflicted_sessions[$eid][$si] = $confs;
            $conflicted_rids[$rid] = true;
        }
    }
}

// ── 依學年學期分組 ──────────────────────────────────────────
$grouped = [];
foreach ($records as $rec) {
    $key = $rec['academic_year'] . '-' . $rec['semester'];
    if (!isset($grouped[$key])) {
        $grouped[$key] = ['year'=>$rec['academic_year'],'semester'=>$rec['semester'],'meeting'=>$rec['coordination_meeting_date'],'items'=>[]];
    }
    $grouped[$key]['items'][] = $rec;
}
?>
<!DOCTYPE html>
<html lang="zh-TW">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>場協登記記錄 - 輔仁大學課外活動指導組</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.5/font/bootstrap-icons.css" rel="stylesheet">
    <style>
        :root { --primary:#1e4d6b; }
        * { box-sizing:border-box; }
        body { margin:0; min-height:100vh; font-family:'Segoe UI',sans-serif; background:#f7f5ef; color:#1f2937; }
        .sidebar { position:fixed; top:0; left:0; width:260px; height:100vh; background:var(--primary); color:white; padding:1.5rem 0.8rem; overflow-y:hidden; box-shadow:3px 0 15px rgba(0,0,0,.12); z-index:1200; }
        .sidebar .brand { text-align:center; margin-bottom:1.5rem; }
        .sidebar .brand h4 { margin:0; font-size:1.1rem; line-height:1.4; font-weight:700; }
        .sidebar .nav-link { display:flex; align-items:center; gap:0.75rem; color:rgba(255,255,255,0.9); padding:0.85rem 1rem; margin:0.2rem 0; border-radius:16px; transition:background 0.25s,transform 0.15s; text-decoration:none; }
        .sidebar .nav-link:hover, .sidebar .nav-link.active { background:#ece8dd; color:#1e4d6b; transform:translateX(4px); }
        .sidebar .nav-link i { font-size:1.1rem; }
        .sidebar .sidebar-section { padding:1rem 0.5rem; margin-top:1.5rem; border-top:1px solid rgba(255,255,255,0.12); }
        .main-content { margin-left:260px; min-height:100vh; }
        .top-navbar { background:#d5e3ea; border-bottom:1px solid #bdd0d9; padding:1rem 2rem; display:flex; justify-content:space-between; align-items:center; position:sticky; top:0; z-index:1100; }
        .content-wrapper { padding:1.5rem 2rem 2rem; }
        .card { background:#fff; border-radius:18px; box-shadow:0 10px 30px rgba(15,23,42,.06); padding:1.5rem; margin-bottom:1.5rem; }
        .card h3 { margin-bottom:1rem; font-weight:700; color:var(--primary); font-size:1rem; }
        .record-card { border:1.5px solid #e5e7eb; border-radius:12px; padding:1.1rem 1.25rem; margin-bottom:1rem; }
        .record-card.pending  { background:#fef9ec; border-color:#fcd34d; }
        .record-card.approved { background:#f0fdf4; border-color:#86efac; }
        .record-card.rejected { background:#fff1f2; border-color:#fca5a5; }
        .record-card.conflict { border-color:#fdba74; }
        .btn-conflict-toggle { background:#fed7aa; color:#7c2d12; }
        .btn-conflict-toggle:hover { background:#fdba74; }
        .btn-conflict-toggle .bi-chevron-down, .btn-conflict-toggle .bi-chevron-up { transition:transform .2s; }
        .badge-status { display:inline-block; padding:.2rem .6rem; border-radius:6px; font-size:.75rem; font-weight:600; }
        .badge-pending  { background:#fde68a; color:#78350f; }
        .badge-approved { background:#bbf7d0; color:#14532d; }
        .badge-rejected { background:#fecaca; color:#7f1d1d; }
        .badge-conflict { background:#fed7aa; color:#7c2d12; }
        .sessions-table { width:100%; border-collapse:collapse; margin-top:.5rem; font-size:.83rem; }
        .sessions-table th { background:#f1f5f9; color:#475569; font-weight:600; padding:.35rem .65rem; text-align:left; border-bottom:2px solid #e2e8f0; }
        .sessions-table td { padding:.35rem .65rem; border-bottom:1px solid #f1f5f9; color:#374151; vertical-align:middle; }
        .sessions-table tr:last-child td { border-bottom:none; }
        .sessions-table tr.conflict { background:#fff7ed; }
        .sessions-table td.conflict { color:#7c2d12; font-weight:600; }
        .meta-row { display:flex; flex-wrap:wrap; gap:.3rem 1.2rem; font-size:.82rem; color:#6b7280; margin:.35rem 0; }
        .meta-row strong { color:#374151; }
        .edit-area { background:#f8fafc; border:1px dashed #cbd5e1; border-radius:10px; padding:.75rem 1rem; margin-top:.6rem; display:none; }
        .edit-sess-row { display:flex; flex-wrap:wrap; gap:.4rem; align-items:flex-end; margin-bottom:.4rem; background:white; border:1px solid #e2e8f0; border-radius:7px; padding:.35rem .5rem; }
        .edit-sess-row label { font-size:.72rem; color:#9ca3af; display:block; margin-bottom:2px; }
        .edit-sess-row input, .edit-sess-row select { border:1px solid #cbd5e1; border-radius:5px; padding:.22rem .4rem; font-size:.8rem; }
        .btn-action { display:inline-flex; align-items:center; gap:.3rem; padding:.25rem .65rem; border-radius:7px; font-size:.78rem; font-weight:600; cursor:pointer; border:none; }
        .btn-edit  { background:#e0f2fe; color:#0369a1; }
        .btn-edit:hover  { background:#bae6fd; }
        .btn-del   { background:#fee2e2; color:#dc2626; }
        .btn-del:hover   { background:#fecaca; }
        .btn-save-edit { background:#1e4d6b; color:white; }
        .btn-save-edit:hover { background:#155e7a; }
        .conflict-alert { background:#fff7ed; border:1.5px solid #f97316; border-radius:8px; padding:.45rem .85rem; font-size:.82rem; color:#7c2d12; margin-bottom:.6rem; display:flex; align-items:center; gap:.5rem; }
        @media(max-width:1100px){ .main-content{ margin-left:0; } }
        .top-navbar .breadcrumb { font-size:.8rem; }
        .top-navbar .breadcrumb-item+.breadcrumb-item::before { content:'›'; font-size:1rem; color:#c9d0d8; }
        .top-navbar .breadcrumb-item a { color:#1e4d6b; text-decoration:none; opacity:.75; }
        .top-navbar .breadcrumb-item a:hover { opacity:1; }
        .top-navbar .breadcrumb-item.active { color:#6b7280; }
    </style>
</head>
<body>
    <?php include(__DIR__ . "/../includes/sidebar.php"); ?>
    <main class="main-content">
        <?php
        $nav_breadcrumbs = [['label'=>'首頁','url'=>'dashboard.php'],['label'=>'場地協調','url'=>'field_coord.php'],['label'=>'登記記錄']];
        $nav_title = '場協登記記錄';
        include __DIR__ . '/../includes/student_navbar.php';
        ?>

        <section class="content-wrapper">
            <div class="card" style="padding:.8rem 1.25rem; margin-bottom:1rem;">
                <p class="mb-0" style="font-size:.85rem; color:#6b7280;">
                    <i class="bi bi-info-circle me-1"></i>
                    顯示<strong><?= htmlspecialchars($active_club_name, ENT_QUOTES, 'UTF-8') ?></strong>所有已提交的場協登記。可直接在此編輯場次時間 / 場地，或刪除登記。修改將即時同步至管理端。
                    <?php if (count($clubs) > 1): ?>
                    若要查看其他社團的登記紀錄，請至「<a href="profile.php">個人檔案</a>」切換目前身分。
                    <?php endif; ?>
                </p>
            </div>

            <?php if (!empty($conflicted_rids)): ?>
            <div class="conflict-alert">
                <i class="bi bi-exclamation-triangle-fill"></i>
                <span>本社有 <strong><?= count($conflicted_rids) ?></strong> 筆登記與其他社團場地衝突，請編輯修改或刪除重複申請。</span>
            </div>
            <?php endif; ?>

            <?php if (empty($records)): ?>
            <div class="card">
                <div class="text-center py-5">
                    <i class="bi bi-inbox" style="font-size:3rem; color:#ccc;"></i>
                    <p class="mt-3 text-muted"><?= htmlspecialchars($active_club_name, ENT_QUOTES, 'UTF-8') ?> 尚無場協登記記錄</p>
                    <a href="field_coord.php" class="btn btn-primary btn-sm mt-1">前往登記</a>
                </div>
            </div>
            <?php else: ?>
                <?php foreach ($grouped as $group): ?>
                <div class="card">
                    <h3>
                        <i class="bi bi-calendar2-week me-1"></i>
                        <?= htmlspecialchars($group['year'], ENT_QUOTES, 'UTF-8') ?>
                        <?= $group['semester'] == 1 ? '上學期' : '下學期' ?>
                        <small class="text-muted fw-normal ms-2" style="font-size:.78rem;">
                            協調大會：<?= date('Y-m-d H:i', strtotime($group['meeting'])) ?>
                        </small>
                    </h3>

                    <?php foreach ($group['items'] as $rec):
                        $rid       = $rec['registration_id'];
                        $eid       = $rec['event_id'];
                        $statusKey = is_null($rec['is_approved']) ? 'pending' : ((int)$rec['is_approved'] ? 'approved' : 'rejected');
                        $is_conflict = isset($conflicted_rids[$rid]);
                        $displayStatusKey = $is_conflict ? 'pending' : $statusKey;
                        $statusLabel = $is_conflict ? '需重新協調' : ['pending'=>'審核中','approved'=>'已核准','rejected'=>'未核准'][$statusKey];
                        $sessions  = $sessions_map[$eid] ?? [];
                        $can_edit  = ($displayStatusKey !== 'approved');

                        $purpose = '';
                        foreach (explode("\n", $rec['description'] ?? '') as $line) {
                            if (strpos(trim($line), '用途：') === 0) {
                                $purpose = trim(mb_substr(trim($line), 3, null, 'UTF-8'));
                                break;
                            }
                        }
                    ?>
                    <div class="record-card <?= $displayStatusKey ?><?= $is_conflict ? ' conflict' : '' ?>" id="rec_<?= $rid ?>">
                        <?php if ($is_conflict): ?>
                        <div class="mb-2">
                            <button type="button" class="btn-action btn-conflict-toggle" onclick="toggleConflict(<?= $rid ?>)">
                                <i class="bi bi-exclamation-triangle-fill"></i> 場地衝突，點此查看詳情
                                <i class="bi bi-chevron-down" id="conflict_chev_<?= $rid ?>"></i>
                            </button>
                            <div class="conflict-alert" id="conflict_<?= $rid ?>" style="display:none; margin-top:.4rem; flex-direction:column; align-items:stretch;">
                                <div><i class="bi bi-exclamation-triangle-fill"></i> 此登記與下列場地申請有衝突，請編輯修改時間或刪除：</div>
                                <ul class="mb-0 mt-1" style="padding-left:1.4rem;">
                                    <?php foreach ($conflicted_sessions[$eid] ?? [] as $si => $confs): ?>
                                        <?php
                                        $sess_date = isset($sessions[$si]['start_time']) ? date('Y-m-d', strtotime($sessions[$si]['start_time'])) : '';
                                        ?>
                                        <?php foreach ($confs as $c): ?>
                                        <?php $is_self_club = ($c['conflicting_club'] === $rec['club_name']); ?>
                                        <li>
                                            場次<?= $si + 1 ?>：
                                            <?php if ($is_self_club): ?>
                                            與您自己另一筆登記「<?= htmlspecialchars($c['conflicting_event'], ENT_QUOTES, 'UTF-8') ?>」
                                            <?php else: ?>
                                            與 <strong><?= htmlspecialchars($c['conflicting_club'], ENT_QUOTES, 'UTF-8') ?></strong> 的「<?= htmlspecialchars($c['conflicting_event'], ENT_QUOTES, 'UTF-8') ?>」
                                            <?php endif; ?>
                                            (<?= htmlspecialchars($c['conflicting_time'], ENT_QUOTES, 'UTF-8') ?>) 時間重疊
                                            <?php if ($sess_date): ?>
                                            <a href="calendar.php?year=<?= date('Y', strtotime($sess_date)) ?>&month=<?= date('n', strtotime($sess_date)) ?>&space_id=<?= intval($c['space_id']) ?>&date=<?= $sess_date ?>" target="_blank" class="ms-1" style="font-size:.78rem; white-space:nowrap;">
                                                <i class="bi bi-calendar3"></i> 查看行事曆
                                            </a>
                                            <?php endif; ?>
                                        </li>
                                        <?php endforeach; ?>
                                    <?php endforeach; ?>
                                </ul>
                            </div>
                        </div>
                        <?php endif; ?>

                        <div class="d-flex justify-content-between align-items-start flex-wrap gap-2">
                            <div>
                                <h6 class="mb-1 fw-bold">
                                    <?= htmlspecialchars($rec['event_name'], ENT_QUOTES, 'UTF-8') ?>
                                    <span class="badge-status badge-<?= $displayStatusKey ?>"><?= $statusLabel ?></span>
                                    <?php if ($is_conflict): ?>
                                    <span class="badge-status badge-conflict ms-1"><i class="bi bi-exclamation-triangle me-1"></i>衝突</span>
                                    <?php endif; ?>
                                </h6>
                                <div class="meta-row">
                                    <span><strong>社團</strong> <?= htmlspecialchars($rec['club_name'], ENT_QUOTES, 'UTF-8') ?></span>
                                    <?php if ($rec['responsible_person']): ?>
                                    <span><strong>負責人</strong> <?= htmlspecialchars($rec['responsible_person'], ENT_QUOTES, 'UTF-8') ?></span>
                                    <?php endif; ?>
                                    <?php if ($purpose): ?>
                                    <span><strong>用途</strong> <?= htmlspecialchars($purpose, ENT_QUOTES, 'UTF-8') ?></span>
                                    <?php endif; ?>
                                </div>
                            </div>
                            <div class="d-flex gap-2 align-items-center flex-wrap">
                                <?php if ($can_edit): ?>
                                <button type="button" class="btn-action btn-edit" onclick="toggleEdit(<?= $rid ?>)">
                                    <i class="bi bi-pencil"></i> 編輯
                                </button>
                                <button type="button" class="btn-action btn-del" onclick="deleteReg(<?= $rid ?>, '<?= htmlspecialchars($rec['event_name'], ENT_JS, 'UTF-8') ?>')">
                                    <i class="bi bi-trash"></i> 刪除
                                </button>
                                <?php endif; ?>
                            </div>
                        </div>

                        <!-- 場次表格 -->
                        <?php if (!empty($sessions)): ?>
                        <table class="sessions-table mt-2">
                            <thead><tr><th>#</th><th>開始</th><th>結束</th><th>場地</th></tr></thead>
                            <tbody id="sess_display_<?= $rid ?>">
                            <?php foreach ($sessions as $i => $s):
                                $session_conflict = isset($conflicted_sessions[$eid][$i]); ?>
                            <tr class="<?= $session_conflict ? 'conflict' : '' ?>">
                                <td><?= $i + 1 ?></td>
                                <td class="<?= $session_conflict ? 'conflict' : '' ?>">
                                    <?= date('Y-m-d H:i', strtotime($s['start_time'])) ?>
                                    <?php if ($session_conflict): ?><span class="badge-status badge-conflict" style="margin-left:.35rem;">衝突</span><?php endif; ?>
                                </td>
                                <td class="<?= $session_conflict ? 'conflict' : '' ?>"><?= date('H:i', strtotime($s['end_time'])) ?></td>
                                <td class="<?= $session_conflict ? 'conflict' : '' ?>"><?= htmlspecialchars($s['space_name'] ?? '未指定', ENT_QUOTES, 'UTF-8') ?></td>
                            </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                        <?php else: ?>
                        <p class="text-muted mb-0 mt-2" style="font-size:.83rem;">無場次資料</p>
                        <?php endif; ?>

                        <?php if ($rec['approval_note']): ?>
                        <div class="mt-2 p-2" style="background:rgba(0,0,0,.04);border-radius:6px;font-size:.83rem;">
                            <strong>審核備註：</strong><?= htmlspecialchars($rec['approval_note'], ENT_QUOTES, 'UTF-8') ?>
                        </div>
                        <?php endif; ?>

                        <!-- 編輯區域 -->
                        <?php if ($can_edit && !empty($sessions)): ?>
                        <div class="edit-area" id="edit_<?= $rid ?>">
                            <div style="font-size:.83rem;font-weight:600;color:#1e4d6b;margin-bottom:.5rem;">
                                <i class="bi bi-pencil-square me-1"></i>編輯場次
                            </div>
                            <?php foreach ($sessions as $si => $s): ?>
                            <div class="edit-sess-row">
                                <input type="hidden" name="sessions[<?= $si ?>][reservation_id]" value="<?= $s['reservation_id'] ?>" class="er-resid">
                                <div>
                                    <label>開始日期</label>
                                    <input type="date" class="er-date" value="<?= date('Y-m-d', strtotime($s['start_time'])) ?>">
                                </div>
                                <div>
                                    <label>開始時間</label>
                                    <div style="display:flex;align-items:center;gap:2px;">
                                        <select class="er-sh" data-field="start">
                                            <?php for($h=8;$h<=21;$h++){$hh=sprintf('%02d',$h);$sel=date('H',strtotime($s['start_time']))==$hh?'selected':'';echo "<option value=\"$hh\" $sel>$hh</option>";}?>
                                        </select>:
                                        <select class="er-sm" data-field="start">
                                            <?php foreach([0,10,20,30,40,50] as $m){$mm=sprintf('%02d',$m);$sel=date('i',strtotime($s['start_time']))==$mm?'selected':'';echo "<option value=\"$mm\" $sel>$mm</option>";}?>
                                        </select>
                                        <input type="hidden" class="er-st" value="<?= date('H:i', strtotime($s['start_time'])) ?>">
                                    </div>
                                </div>
                                <div>
                                    <label>結束日期</label>
                                    <input type="date" class="er-enddate" value="<?= date('Y-m-d', strtotime($s['end_time'])) ?>">
                                </div>
                                <div>
                                    <label>結束時間</label>
                                    <div style="display:flex;align-items:center;gap:2px;">
                                        <select class="er-eh" data-field="end">
                                            <?php for($h=8;$h<=21;$h++){$hh=sprintf('%02d',$h);$sel=date('H',strtotime($s['end_time']))==$hh?'selected':'';echo "<option value=\"$hh\" $sel>$hh</option>";}?>
                                        </select>:
                                        <select class="er-em" data-field="end">
                                            <?php foreach([0,10,20,30,40,50] as $m){$mm=sprintf('%02d',$m);$sel=date('i',strtotime($s['end_time']))==$mm?'selected':'';echo "<option value=\"$mm\" $sel>$mm</option>";}?>
                                        </select>
                                        <input type="hidden" class="er-et" value="<?= date('H:i', strtotime($s['end_time'])) ?>">
                                    </div>
                                </div>
                                <div>
                                    <label>場地</label>
                                    <select class="er-space">
                                        <?php foreach($all_spaces as $sp): ?>
                                        <option value="<?= $sp['space_id'] ?>" <?= $sp['space_id']==$s['space_id']?'selected':''?>>
                                            <?= htmlspecialchars($sp['space_name'],ENT_QUOTES,'UTF-8') ?>
                                        </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                            </div>
                            <?php endforeach; ?>
                            <div style="margin-top:.5rem;display:flex;align-items:center;gap:.75rem;">
                                <button type="button" class="btn-action btn-save-edit" onclick="saveEdit(<?= $rid ?>)">
                                    <i class="bi bi-cloud-check me-1"></i>儲存
                                </button>
                                <button type="button" class="btn-action" style="background:#f1f5f9;color:#374151;" onclick="toggleEdit(<?= $rid ?>)">取消</button>
                                <span id="edit_msg_<?= $rid ?>" style="font-size:.8rem;color:#059669;display:none;">✓ 已儲存</span>
                            </div>
                        </div>
                        <?php endif; ?>
                    </div>
                    <?php endforeach; ?>
                </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </section>
    </main>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
    // 依時段限制可選分鐘（08:30–21:30，08時只能選30/40/50，21時只能選00/10/20/30）
    function applyEditMinuteRestriction(hourSel, minSel) {
        if (!hourSel || !minSel) return;
        var disabledMins = [];
        if (hourSel.value === '08') disabledMins = ['00','10','20'];
        else if (hourSel.value === '21') disabledMins = ['40','50'];
        Array.prototype.forEach.call(minSel.options, function(opt) {
            opt.disabled = disabledMins.indexOf(opt.value) !== -1;
        });
        if (disabledMins.indexOf(minSel.value) !== -1) {
            minSel.value = hourSel.value === '08' ? '30' : '00';
        }
    }

    // 同步 hour/min → hidden time value
    document.addEventListener('change', function(e) {
        var el = e.target;
        if (!el.classList.contains('er-sh') && !el.classList.contains('er-sm') &&
            !el.classList.contains('er-eh') && !el.classList.contains('er-em')) return;
        var row = el.closest('.edit-sess-row');
        if (!row) return;
        if (el.classList.contains('er-sh') || el.classList.contains('er-sm')) {
            var sh = row.querySelector('.er-sh'), sm = row.querySelector('.er-sm');
            if (el.classList.contains('er-sh')) applyEditMinuteRestriction(sh, sm);
            row.querySelector('.er-st').value = sh.value + ':' + sm.value;
        } else {
            var eh = row.querySelector('.er-eh'), em = row.querySelector('.er-em');
            if (el.classList.contains('er-eh')) applyEditMinuteRestriction(eh, em);
            row.querySelector('.er-et').value = eh.value + ':' + em.value;
        }
    });

    // 初始化各編輯場次的分鐘限制
    document.querySelectorAll('.edit-sess-row').forEach(function(row) {
        applyEditMinuteRestriction(row.querySelector('.er-sh'), row.querySelector('.er-sm'));
        applyEditMinuteRestriction(row.querySelector('.er-eh'), row.querySelector('.er-em'));
    });

    function toggleEdit(rid) {
        var ea = document.getElementById('edit_' + rid);
        if (ea) ea.style.display = ea.style.display === 'none' ? 'block' : 'none';
    }

    function toggleConflict(rid) {
        var box  = document.getElementById('conflict_' + rid);
        var chev = document.getElementById('conflict_chev_' + rid);
        if (!box) return;
        var show = box.style.display === 'none';
        box.style.display = show ? 'flex' : 'none';
        if (chev) {
            chev.classList.toggle('bi-chevron-up', show);
            chev.classList.toggle('bi-chevron-down', !show);
        }
    }

    async function deleteReg(rid, name) {
        if (!confirm('確定要刪除「' + name + '」的場協登記？\n此操作無法復原，相關預約也將一併移除。')) return;
        var form = new FormData();
        form.append('ajax_action', 'delete');
        form.append('reg_id', rid);
        try {
            var res = await fetch(location.href, {method:'POST', body:form});
            var data = await res.json();
            if (data.ok) {
                var card = document.getElementById('rec_' + rid);
                if (card) {
                    card.style.transition = 'opacity .3s';
                    card.style.opacity = '0';
                    setTimeout(function() { card.remove(); }, 300);
                }
            } else {
                alert('刪除失敗：' + (data.msg || '未知錯誤'));
            }
        } catch(e) { alert('網路錯誤'); }
    }

    async function saveEdit(rid) {
        var ea = document.getElementById('edit_' + rid);
        var rows = ea.querySelectorAll('.edit-sess-row');
        var sessions = [];
        rows.forEach(function(row, idx) {
            sessions.push({
                reservation_id: row.querySelector('.er-resid').value,
                space_id:       row.querySelector('.er-space').value,
                date:           row.querySelector('.er-date').value,
                end_date:       row.querySelector('.er-enddate').value,
                start_time:     row.querySelector('.er-st').value,
                end_time:       row.querySelector('.er-et').value
            });
        });
        var form = new FormData();
        form.append('ajax_action', 'edit');
        form.append('reg_id', rid);
        sessions.forEach(function(s, idx) {
            Object.keys(s).forEach(function(k) {
                form.append('sessions[' + idx + '][' + k + ']', s[k]);
            });
        });
        try {
            var res = await fetch(location.href, {method:'POST', body:form});
            if (!res.ok) {
                var errText = await res.text();
                alert('伺服器錯誤：' + res.status + ' ' + res.statusText + '\n' + errText);
                return;
            }
            var data = await res.json();
            if (data.ok) {
                alert('編輯成功！已更新 ' + data.updated_count + ' 個場次 (共 ' + data.total_sessions + ' 個)' + (data.has_conflict ? '\n警告：有場次衝突' : ''));
                window.location.reload();
            } else {
                alert('儲存失敗：' + (data.msg || '未知錯誤'));
            }
        } catch(e) { alert('網路錯誤：' + e.message); }
    }
    </script>
</body>
</html>
