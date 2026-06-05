<?php
require_once(__DIR__ . "/../DB/db_config.php");
require_once(__DIR__ . "/../includes/FieldCoordinationManager.php");

if (!isset($_SESSION['student_id'])) {
    header('Location: ../login.php');
    exit();
}

$current_page = 'field_coord_records';

// $_SESSION['user_id'] 可能存的是 email，統一從 DB 查出整數 user_id
$user_id = null;
$_raw_uid = isset($_SESSION['user_id']) ? $_SESSION['user_id'] : null;
if ($_raw_uid !== null) {
    if (is_numeric($_raw_uid)) {
        $user_id = (int)$_raw_uid;
    } else {
        // 存的是 email，查出對應的整數 user_id
        $_uid_stmt = $conn->prepare("SELECT user_id FROM users WHERE email = ? LIMIT 1");
        $_uid_stmt->bind_param("s", $_raw_uid);
        $_uid_stmt->execute();
        $_uid_res = $_uid_stmt->get_result()->fetch_assoc();
        $_uid_stmt->close();
        if ($_uid_res) $user_id = (int)$_uid_res['user_id'];
    }
}


// 取得使用者所屬社團
$clubs = [];
if ($user_id) {
    $club_stmt = $conn->prepare(
        "SELECT cm.club_id, c.club_name FROM club_members cm
         JOIN clubs c ON cm.club_id = c.club_id WHERE cm.user_id = ?"
    );
    $club_stmt->bind_param("i", $user_id);
    $club_stmt->execute();
    $cr = $club_stmt->get_result();
    while ($row = $cr->fetch_assoc()) { $clubs[] = $row; }
    $club_stmt->close();
}

$records = [];
if (!empty($clubs)) {
    $club_ids = array_column($clubs, 'club_id');
    $ph       = implode(',', array_fill(0, count($club_ids), '?'));

    $rec_stmt = $conn->prepare(
        "SELECT fcr.registration_id, fcr.event_id, fcr.club_id, fcr.club_name,
                fcr.is_approved, fcr.approval_note,
                e.event_name, e.description, e.status, e.responsible_person,
                fcs.academic_year, fcs.semester, fcs.coordination_meeting_date
         FROM field_coordination_registrations fcr
         JOIN events e   ON fcr.event_id  = e.event_id
         JOIN field_coordination_settings fcs ON fcr.setting_id = fcs.setting_id
         WHERE fcr.club_id IN ($ph)
         ORDER BY fcs.academic_year DESC, fcs.semester DESC, fcr.registration_id DESC"
    );
    // PHP 7.3 相容的 bind_param（不能用 spread + by-ref）
    $bind_args = array(str_repeat('s', count($club_ids)));
    foreach ($club_ids as &$cid) { $bind_args[] = &$cid; }
    call_user_func_array(array($rec_stmt, 'bind_param'), $bind_args);
    $rec_stmt->execute();
    $rr = $rec_stmt->get_result();
    while ($row = $rr->fetch_assoc()) { $records[] = $row; }
    $rec_stmt->close();
}

// 預先抓每筆登記的場次明細
$sessions_map = [];
foreach ($records as $rec) {
    $eid = $rec['event_id'];
    if (isset($sessions_map[$eid])) continue;
    $s_stmt = $conn->prepare(
        "SELECT r.start_time, r.end_time, s.space_name
         FROM reservations r
         LEFT JOIN spaces s ON r.space_id = s.space_id
         WHERE r.event_id = ?
         ORDER BY r.start_time"
    );
    $s_stmt->bind_param("i", $eid);
    $s_stmt->execute();
    $sr = $s_stmt->get_result();
    $sessions_map[$eid] = [];
    while ($row = $sr->fetch_assoc()) { $sessions_map[$eid][] = $row; }
    $s_stmt->close();
}

// 依學年學期分組
$grouped = [];
foreach ($records as $rec) {
    $key = $rec['academic_year'] . '-' . $rec['semester'];
    if (!isset($grouped[$key])) {
        $grouped[$key] = [
            'year'     => $rec['academic_year'],
            'semester' => $rec['semester'],
            'meeting'  => $rec['coordination_meeting_date'],
            'items'    => [],
        ];
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
        .sidebar { position:fixed; top:0; left:0; width:260px; height:100vh; background:var(--primary); color:white; padding:1.5rem 0.8rem; overflow-y:auto; box-shadow:3px 0 15px rgba(0,0,0,.12); z-index:1200; }
        .sidebar .brand { text-align:center; margin-bottom:1.5rem; }
        .sidebar .brand h4 { margin:0; font-size:1.1rem; line-height:1.4; font-weight:700; }
        .sidebar .nav-link { display:flex; align-items:center; gap:0.75rem; color:rgba(255,255,255,0.9); padding:0.85rem 1rem; margin:0.2rem 0; border-radius:16px; transition:background 0.25s ease,transform 0.15s ease; }
        .sidebar .nav-link:hover, .sidebar .nav-link.active { background:#ece8dd; color:#1e4d6b; transform:translateX(4px); }
        .sidebar .nav-link i { font-size:1.1rem; }
        .sidebar .sidebar-section { padding:1rem 0.5rem; margin-top:1.5rem; border-top:1px solid rgba(255,255,255,0.12); }
        .main-content { margin-left:260px; min-height:100vh; transition:margin-left 0.25s ease; }
        .top-navbar { background:#d5e3ea; border-bottom:1px solid #bdd0d9; padding:1rem 2rem; position:sticky; top:0; z-index:1100; }
        .content-wrapper { padding:1.5rem 2rem 2rem; }
        .card { background:#fff; border-radius:18px; box-shadow:0 10px 30px rgba(15,23,42,.06); padding:1.5rem; margin-bottom:1.5rem; }
        .card h3 { margin-bottom:1rem; font-weight:700; color:var(--primary); }
        .record-card { border:1.5px solid #e5e7eb; border-radius:12px; padding:1.1rem 1.25rem; margin-bottom:1rem; }
        .record-card.pending  { background:#fef9ec; border-color:#fcd34d; }
        .record-card.approved { background:#f0fdf4; border-color:#86efac; }
        .record-card.rejected { background:#fff1f2; border-color:#fca5a5; }
        .badge-status { display:inline-block; padding:.25rem .7rem; border-radius:6px; font-size:.78rem; font-weight:600; }
        .badge-pending  { background:#fde68a; color:#78350f; }
        .badge-approved { background:#bbf7d0; color:#14532d; }
        .badge-rejected { background:#fecaca; color:#7f1d1d; }
        .sessions-table { width:100%; border-collapse:collapse; margin-top:.6rem; font-size:.83rem; }
        .sessions-table th { background:#f1f5f9; color:#475569; font-weight:600; padding:.4rem .7rem; text-align:left; border-bottom:2px solid #e2e8f0; }
        .sessions-table td { padding:.4rem .7rem; border-bottom:1px solid #f1f5f9; color:#374151; }
        .sessions-table tr:last-child td { border-bottom:none; }
        .meta-row { display:flex; flex-wrap:wrap; gap:.4rem 1.5rem; font-size:.83rem; color:#6b7280; margin:.4rem 0; }
        .meta-row strong { color:#374151; }
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
        <header class="top-navbar">
            <ol class="breadcrumb mb-0">
                <li class="breadcrumb-item"><a href="dashboard.php">首頁</a></li>
                <li class="breadcrumb-item"><a href="field_coord.php">場地協調</a></li>
                <li class="breadcrumb-item active">登記記錄</li>
            </ol>
            <h4 class="mt-2 mb-0">場協登記記錄</h4>
        </header>

        <section class="content-wrapper">
            <div class="card" style="padding:1rem 1.25rem; margin-bottom:1rem;">
                <p class="mb-0" style="font-size:.88rem; color:#6b7280;">
                    <i class="bi bi-info-circle me-1"></i>
                    顯示本社所有已提交的場地協調登記，社團所有成員皆可查閱。
                </p>
            </div>

            <?php if (empty($records)): ?>
            <div class="card">
                <div class="text-center py-5">
                    <i class="bi bi-inbox" style="font-size:3rem; color:#ccc;"></i>
                    <p class="mt-3 text-muted">本社尚無場協登記記錄</p>
                    <a href="field_coord.php" class="btn btn-primary btn-sm mt-1">前往登記</a>
                </div>
            </div>
            <?php else: ?>
                <?php foreach ($grouped as $group): ?>
                <div class="card">
                    <h3 style="font-size:1rem; margin-bottom:1rem;">
                        <i class="bi bi-calendar2-week me-1"></i>
                        <?= htmlspecialchars($group['year'], ENT_QUOTES, 'UTF-8') ?>
                        <?= $group['semester'] == 1 ? '上學期' : '下學期' ?>
                        <small class="text-muted fw-normal ms-2" style="font-size:.83rem;">
                            協調大會：<?= date('Y-m-d H:i', strtotime($group['meeting'])) ?>
                        </small>
                    </h3>

                    <?php foreach ($group['items'] as $rec):
                        $statusKey = $rec['is_approved'] === null ? 'pending' : ($rec['is_approved'] ? 'approved' : 'rejected');
                        $statusLabel = ['pending'=>'審核中','approved'=>'已核准','rejected'=>'未核准'][$statusKey];
                        $eid = $rec['event_id'];
                        $sessions = $sessions_map[$eid] ?? [];

                        // 解析場地用途（存在 description 內以「用途：」開頭的行）
                        $purpose = '';
                        foreach (explode("\n", $rec['description'] ?? '') as $line) {
                            if (strpos(trim($line), '用途：') === 0) {
                                $purpose = trim(mb_substr(trim($line), 3, null, 'UTF-8'));
                                break;
                            }
                        }
                    ?>
                    <div class="record-card <?= $statusKey ?>">
                        <div class="d-flex justify-content-between align-items-start flex-wrap gap-2">
                            <div>
                                <h6 class="mb-1 fw-bold">
                                    <?= htmlspecialchars($rec['event_name'], ENT_QUOTES, 'UTF-8') ?>
                                    <span class="badge-status badge-<?= $statusKey ?>"><?= $statusLabel ?></span>
                                </h6>
                                <div class="meta-row">
                                    <span><strong>社團</strong> <?= htmlspecialchars($rec['club_name'], ENT_QUOTES, 'UTF-8') ?></span>
                                    <?php if ($rec['responsible_person']): ?>
                                    <span><strong>負責人</strong> <?= htmlspecialchars($rec['responsible_person'], ENT_QUOTES, 'UTF-8') ?></span>
                                    <?php endif; ?>
                                    <?php if ($purpose): ?>
                                    <span><strong>場地用途</strong> <?= htmlspecialchars($purpose, ENT_QUOTES, 'UTF-8') ?></span>
                                    <?php endif; ?>
                                </div>
                            </div>
                            <a href="calendar.php" class="btn btn-sm btn-outline-secondary">
                                <i class="bi bi-calendar3"></i> 日曆
                            </a>
                        </div>

                        <?php if (!empty($sessions)): ?>
                        <table class="sessions-table mt-2">
                            <thead>
                                <tr>
                                    <th>#</th>
                                    <th>開始</th>
                                    <th>結束</th>
                                    <th>場地</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($sessions as $i => $s): ?>
                                <tr>
                                    <td><?= $i + 1 ?></td>
                                    <td><?= date('Y-m-d H:i', strtotime($s['start_time'])) ?></td>
                                    <td><?= date('H:i', strtotime($s['end_time'])) ?></td>
                                    <td><?= htmlspecialchars($s['space_name'] ?? '未指定', ENT_QUOTES, 'UTF-8') ?></td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                        <?php else: ?>
                        <p class="text-muted mb-0 mt-2" style="font-size:.83rem;">無場次資料</p>
                        <?php endif; ?>

                        <?php if ($rec['approval_note']): ?>
                        <div class="mt-2 p-2" style="background:rgba(0,0,0,.04); border-radius:6px; font-size:.83rem;">
                            <strong>審核備註：</strong><?= htmlspecialchars($rec['approval_note'], ENT_QUOTES, 'UTF-8') ?>
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
</body>
</html>
