<?php

require_once(__DIR__ . "/../DB/db_config.php");
require_once(__DIR__ . "/../includes/FieldCoordinationManager.php");
require_once(__DIR__ . "/../includes/field_coordination_import_helpers.php");

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    header('Location: ../login.php');
    exit();
}

$current_page = 'field_coordination_import';
$fc_manager   = new FieldCoordinationManager($conn);
$message      = '';
$message_type = '';

// ── 撤銷已核准的登記，改回待審核 ──────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'revert_decision') {
    $reg_id     = intval($_POST['registration_id'] ?? 0);
    $setting_id = intval($_POST['setting_id'] ?? 0);
    if ($reg_id && $fc_manager->revertFieldCoordinationRegistration($reg_id)) {
        $message = '✅ 已撤銷核准，該筆登記改回待審核狀態。';
        $message_type = 'success';
    } else {
        $message = '❌ 撤銷失敗，請重新整理後再試。';
        $message_type = 'danger';
    }
    $_GET['setting_id'] = $setting_id;
}

// ── 儲存結果（無衝突登記：approve / edited / pending）─────────────
//    以及（衝突協調：選定得標社團，落敗者編輯或拒絕）─────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_results'])) {
    $setting_id = intval($_POST['setting_id'] ?? 0);
    $cnt = ['approved' => 0, 'edited' => 0, 'rejected' => 0, 'skip' => 0];
    $edit_sessions_post = $_POST['edit_session'] ?? [];

    // 無衝突登記
    $decisions = $_POST['decision'] ?? [];
    $notes     = $_POST['note']     ?? [];
    foreach ($decisions as $reg_id => $decision) {
        $reg_id = intval($reg_id);
        $note   = trim($notes[$reg_id] ?? '');
        if ($decision === 'approved') {
            $fc_manager->approveFieldCoordinationRegistration($reg_id, $note ?: null);
            $cnt['approved']++;
        } elseif ($decision === 'edited') {
            $parsed = parseEditSessions($edit_sessions_post[$reg_id] ?? []);
            if (!empty($parsed)) {
                $fc_manager->editAndApproveFieldCoordinationRegistration($reg_id, $parsed, $note ?: '場協大會調整時間地點');
                $cnt['edited']++;
            } else {
                $cnt['skip']++;
            }
        } else {
            $cnt['skip']++;
        }
    }

    // 衝突組：贏家核准，落敗者改為編輯（有填才套用）或拒絕
    $conflict_groups_post = $_POST['conflict_group']  ?? [];
    $conflict_winners     = $_POST['conflict_winner'] ?? [];
    foreach ($conflict_groups_post as $gi => $members) {
        $winner = $conflict_winners[$gi] ?? 'none';
        foreach ($members as $reg_id) {
            $reg_id = intval($reg_id);
            if ($winner !== 'none' && $winner == $reg_id) {
                $fc_manager->approveFieldCoordinationRegistration($reg_id, '場協大會核准');
                $cnt['approved']++;
            } else {
                $parsed = parseEditSessions($edit_sessions_post[$reg_id] ?? []);
                if (!empty($parsed)) {
                    $fc_manager->editAndApproveFieldCoordinationRegistration($reg_id, $parsed, '場協大會調整時間地點');
                    $cnt['edited']++;
                } else {
                    $fc_manager->rejectFieldCoordinationRegistration($reg_id, '場協大會：該時段由其他社團取得');
                    $cnt['rejected']++;
                }
            }
        }
    }

    $msg_parts = [];
    if ($cnt['approved']) $msg_parts[] = "核准 {$cnt['approved']} 筆";
    if ($cnt['edited'])   $msg_parts[] = "調整核准 {$cnt['edited']} 筆";
    if ($cnt['rejected']) $msg_parts[] = "拒絕 {$cnt['rejected']} 筆";
    if ($cnt['skip'])     $msg_parts[] = "跳過 {$cnt['skip']} 筆";
    $message = "✅ 套用完成：" . ($msg_parts ? implode('、', $msg_parts) : '無變更');
    $message_type = 'success';
    $_GET['setting_id'] = $setting_id;
}

// ── CSV 匯出（每個場次一行）────────────────────────────────────
if (isset($_GET['action']) && $_GET['action'] === 'export') {
    $export_sid = intval(isset($_GET['setting_id']) ? $_GET['setting_id'] : 0);
    if ($export_sid) {
        // 取登記資料
        $e_regs = [];
        $stmt_e = $conn->prepare("SELECT fcr.registration_id, fcr.club_name, fcr.is_approved, e.event_name, e.responsible_person, e.description FROM field_coordination_registrations fcr JOIN events e ON fcr.event_id=e.event_id WHERE fcr.setting_id=? ORDER BY fcr.club_name, e.start_time");
        $stmt_e->bind_param("i", $export_sid);
        $stmt_e->execute();
        $e_res = $stmt_e->get_result();
        while ($row = $e_res->fetch_assoc()) { $e_regs[] = $row; }
        $stmt_e->close();

        // 取場次明細
        $e_sess = [];
        if (!empty($e_regs)) {
            $e_rids = array_column($e_regs, 'registration_id');
            $ph = implode(',', array_fill(0, count($e_rids), '?'));
            $stmt_s = $conn->prepare("SELECT res.start_time, res.end_time, s.space_name, fcr.registration_id FROM reservations res JOIN field_coordination_registrations fcr ON res.event_id=fcr.event_id LEFT JOIN spaces s ON res.space_id=s.space_id WHERE fcr.registration_id IN ($ph) ORDER BY fcr.registration_id, res.start_time");
            fc_bind_array($stmt_s, str_repeat('i', count($e_rids)), $e_rids);
            $stmt_s->execute();
            $s_res = $stmt_s->get_result();
            while ($sr = $s_res->fetch_assoc()) { $e_sess[$sr['registration_id']][] = $sr; }
            $stmt_s->close();
        }

        header('Content-Type: text/csv; charset=UTF-8');
        header('Content-Disposition: attachment; filename="場協登記_' . $export_sid . '.csv"');
        echo "\xEF\xBB\xBF";
        echo "社團,活動名稱,負責人,場地用途,場次,開始日期,開始時間,結束時間,場地,狀態\n";

        foreach ($e_regs as $er) {
            $rid    = $er['registration_id'];
            $status = $er['is_approved'] === null ? '待定' : ((int)$er['is_approved'] ? '核准' : '拒絕');
            $purpose = '';
            foreach (explode("\n", isset($er['description']) ? $er['description'] : '') as $dl) {
                if (strpos(trim($dl), '用途：') === 0) {
                    $purpose = mb_substr(trim($dl), 3, null, 'UTF-8');
                    break;
                }
            }
            $sessions = isset($e_sess[$rid]) ? $e_sess[$rid] : [];
            if (empty($sessions)) {
                echo implode(',', [csv_q($er['club_name']), csv_q($er['event_name']), csv_q($er['responsible_person']), csv_q($purpose), 1, '','','','', csv_q($status)]) . "\n";
            } else {
                foreach ($sessions as $si => $ss) {
                    echo implode(',', [
                        csv_q($er['club_name']), csv_q($er['event_name']),
                        csv_q($er['responsible_person']), csv_q($purpose),
                        $si + 1,
                        csv_q(date('Y-m-d', strtotime($ss['start_time']))),
                        csv_q(date('H:i',   strtotime($ss['start_time']))),
                        csv_q(date('H:i',   strtotime($ss['end_time']))),
                        csv_q(isset($ss['space_name']) ? $ss['space_name'] : ''),
                        csv_q($status),
                    ]) . "\n";
                }
            }
        }
        exit;
    }
}

// ── 取得場協設定 ──────────────────────────────────────────────
$all_settings        = $fc_manager->getAllFieldCoordinationSettings();
$selected_setting_id = intval($_GET['setting_id'] ?? 0);
if (!$selected_setting_id && !empty($all_settings)) {
    $selected_setting_id = (int)$all_settings[0]['setting_id'];
}
$selected_setting = null;
foreach ($all_settings as $s) {
    if ((int)$s['setting_id'] === $selected_setting_id) { $selected_setting = $s; break; }
}

// ── 取得登記清單／場次明細／可用場地／衝突分組 ──────────────────
$import_data     = fc_import_load_data($conn, $selected_setting_id);
$registrations   = $import_data['registrations'];
$reg_sessions    = $import_data['reg_sessions'];
$all_spaces      = $import_data['all_spaces'];
$conflict_groups = $import_data['conflict_groups'];
$all_conflicting = $import_data['all_conflicting'];
$clean_regs      = $import_data['clean_regs'];
$reg_map         = $import_data['reg_map'];
$stat            = $import_data['stat'];

// ── 分類：無衝突（待處理）／有衝突（未解決）／已審核完成 ──────────
$pending_clean = array_values(array_filter($clean_regs, function($r) {
    return $r['is_approved'] === null;
}));

$unresolved_groups = [];
foreach ($conflict_groups as $gi => $group_ids) {
    $has_pending = false;
    foreach ($group_ids as $gid) {
        if ($reg_map[$gid]['is_approved'] === null) { $has_pending = true; break; }
    }
    if ($has_pending) $unresolved_groups[$gi] = $group_ids;
}
$unresolved_conflicting_ids = [];
foreach ($unresolved_groups as $g) { foreach ($g as $gid) { $unresolved_conflicting_ids[] = $gid; } }

$done_regs = array_values(array_filter($registrations, function($r) {
    return $r['is_approved'] !== null;
}));

// ── CSV 匯出（場協大會用：全部登記合併，含衝突標記，可依時間/場地/社團排序）──
if (isset($_GET['action']) && $_GET['action'] === 'export_meeting' && $selected_setting_id) {
    $sort_mode = $_GET['sort'] ?? 'time';
    if (!in_array($sort_mode, ['time', 'venue', 'club'], true)) $sort_mode = 'time';

    // 標記每筆登記屬於第幾個衝突組
    $conflict_group_no = [];
    foreach ($conflict_groups as $gi => $group_ids) {
        foreach ($group_ids as $gid) { $conflict_group_no[$gid] = $gi + 1; }
    }

    // 展開成「每場次一行」
    $rows = [];
    foreach ($registrations as $reg) {
        $rid = $reg['registration_id'];
        $status = $reg['is_approved'] === null ? '待定' : ((int)$reg['is_approved'] ? '核准' : '拒絕');
        $conflict_label = isset($conflict_group_no[$rid]) ? ('衝突組 ' . $conflict_group_no[$rid]) : '';
        $purpose = '';
        foreach (explode("\n", $reg['description'] ?? '') as $dl) {
            if (strpos(trim($dl), '用途：') === 0) {
                $purpose = mb_substr(trim($dl), 3, null, 'UTF-8');
                break;
            }
        }
        $sessions = $reg_sessions[$rid] ?? [];
        if (empty($sessions)) {
            $rows[] = [
                'club_name' => $reg['club_name'], 'event_name' => $reg['event_name'],
                'responsible_person' => $reg['responsible_person'], 'purpose' => $purpose,
                'session_no' => 1, 'start_time' => null, 'end_time' => null,
                'space_name' => '', 'status' => $status, 'conflict' => $conflict_label,
            ];
        } else {
            foreach ($sessions as $si => $ss) {
                $rows[] = [
                    'club_name' => $reg['club_name'], 'event_name' => $reg['event_name'],
                    'responsible_person' => $reg['responsible_person'], 'purpose' => $purpose,
                    'session_no' => $si + 1, 'start_time' => $ss['start_time'], 'end_time' => $ss['end_time'],
                    'space_name' => $ss['space_name'] ?? '', 'status' => $status, 'conflict' => $conflict_label,
                ];
            }
        }
    }

    usort($rows, function($a, $b) use ($sort_mode) {
        switch ($sort_mode) {
            case 'venue': $c = strcmp($a['space_name'], $b['space_name']); break;
            case 'club':  $c = strcmp($a['club_name'], $b['club_name']);   break;
            default:      $c = strcmp((string)$a['start_time'], (string)$b['start_time']); break;
        }
        if ($c !== 0) return $c;
        return strcmp((string)$a['start_time'], (string)$b['start_time']);
    });

    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="場協大會總表_' . $selected_setting_id . '.csv"');
    echo "\xEF\xBB\xBF";
    echo "衝突組別,社團,活動名稱,負責人,場地用途,場次,開始日期,開始時間,結束時間,場地,目前狀態\n";
    foreach ($rows as $r) {
        echo implode(',', [
            csv_q($r['conflict']),
            csv_q($r['club_name']), csv_q($r['event_name']),
            csv_q($r['responsible_person']), csv_q($r['purpose']),
            $r['session_no'],
            csv_q($r['start_time'] ? date('Y-m-d', strtotime($r['start_time'])) : ''),
            csv_q($r['start_time'] ? date('H:i', strtotime($r['start_time'])) : ''),
            csv_q($r['end_time']   ? date('H:i', strtotime($r['end_time']))   : ''),
            csv_q($r['space_name']),
            csv_q($r['status']),
        ]) . "\n";
    }
    exit;
}

// ── 分頁籤 ────────────────────────────────────────────────────
$tab = $_GET['tab'] ?? 'all';
if (!in_array($tab, ['all', 'clean', 'conflict', 'done'], true)) $tab = 'all';

$show_clean    = in_array($tab, ['all', 'clean'], true);
$show_conflict = in_array($tab, ['all', 'conflict'], true);
$show_done     = in_array($tab, ['all', 'done'], true);
?>
<!DOCTYPE html>
<html lang="zh-TW">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>場協結果匯入 - 輔仁大學課外活動指導組</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.5/font/bootstrap-icons.css" rel="stylesheet">
    <style>
        :root { --primary:#1e4d6b; --sidebar:#14394f; --bg:#f7f5ef; --card:#fff; }
        * { box-sizing:border-box; }
        body { margin:0; min-height:100vh; font-family:'Segoe UI',sans-serif; background:var(--bg); color:#1f2937; }

        /* sidebar */
        .sidebar { position:fixed; top:0; left:0; width:260px; height:100vh; background:var(--primary); color:white; padding:1.5rem 0.8rem; overflow-y:hidden; box-shadow:3px 0 15px rgba(0,0,0,.12); z-index:1200; }
        .sidebar .brand { text-align:center; margin-bottom:1.5rem; }
        .sidebar .brand h4 { margin:0; font-size:1.1rem; line-height:1.4; font-weight:700; }
        .sidebar .nav-link { display:flex; align-items:center; gap:.75rem; color:rgba(255,255,255,.9); padding:.85rem 1rem; margin:.2rem 0; border-radius:16px; transition:background .25s,transform .15s; }
        .sidebar .nav-link:hover,.sidebar .nav-link.active { background:#ece8dd; color:#1e4d6b; transform:translateX(4px); }
        .sidebar .nav-link i { font-size:1.1rem; }
        .sidebar .sidebar-section { padding:1rem .5rem; margin-top:1.5rem; border-top:1px solid rgba(255,255,255,.12); }

        /* layout */
        .main-content { margin-left:260px; min-height:100vh; }
        .top-navbar { background:#d5e3ea; border-bottom:1px solid #bdd0d9; padding:1rem 2rem; display:flex; justify-content:space-between; align-items:center; position:sticky; top:0; z-index:1100; }
        .top-navbar .breadcrumb { margin:0; background:transparent; padding:0; font-size:.8rem; }
        .top-navbar .breadcrumb-item+.breadcrumb-item::before { content:'›'; font-size:1rem; color:#c9d0d8; }
        .top-navbar .breadcrumb-item a { color:#1e4d6b; text-decoration:none; opacity:.75; }
        .top-navbar .breadcrumb-item a:hover { opacity:1; }
        .top-navbar .breadcrumb-item.active { color:#6b7280; }
        .user-avatar { width:38px; height:38px; border-radius:50%; background:var(--primary); color:white; display:inline-flex; align-items:center; justify-content:center; font-weight:700; font-size:1rem; flex-shrink:0; }
        .content-wrapper { padding:1.5rem 2rem; }

        /* card */
        .card { background:var(--card); border-radius:18px; box-shadow:0 10px 30px rgba(15,23,42,.06); padding:1.5rem; margin-bottom:1.5rem; }
        .card-title { font-weight:700; color:var(--primary); font-size:1.05rem; display:flex; align-items:center; gap:.5rem; margin-bottom:1rem; }

        /* tabs */
        .tabs-bar { display:flex; gap:.6rem; flex-wrap:wrap; }
        .filter-tab { display:inline-flex; align-items:center; gap:.45rem; padding:.4rem 1rem; border-radius:999px; font-size:.85rem; font-weight:600; text-decoration:none; border:1.5px solid transparent; transition:all .2s; cursor:pointer; }
        .filter-tab .ftab-count { background:rgba(0,0,0,.1); border-radius:999px; padding:.05rem .5rem; font-size:.75rem; }
        .filter-tab.tab-all      { color:#1e4d6b; border-color:#1e4d6b; }
        .filter-tab.tab-all.active      { background:#1e4d6b; color:white; }
        .filter-tab.tab-all.active .ftab-count      { background:rgba(255,255,255,.25); }
        .filter-tab.tab-clean    { color:#198754; border-color:#198754; }
        .filter-tab.tab-clean.active    { background:#198754; color:white; }
        .filter-tab.tab-clean.active .ftab-count    { background:rgba(255,255,255,.25); }
        .filter-tab.tab-conflict { color:#dc3545; border-color:#dc3545; }
        .filter-tab.tab-conflict.active { background:#dc3545; color:white; }
        .filter-tab.tab-conflict.active .ftab-count { background:rgba(255,255,255,.25); }
        .filter-tab.tab-done     { color:#6f42c1; border-color:#6f42c1; }
        .filter-tab.tab-done.active     { background:#6f42c1; color:white; }
        .filter-tab.tab-done.active .ftab-count     { background:rgba(255,255,255,.25); }

        /* clean regs table */
        .result-table { width:100%; border-collapse:collapse; font-size:.88rem; }
        .result-table th { background:#f0f4f8; color:#374151; font-weight:600; padding:.7rem .9rem; text-align:left; border-bottom:2px solid #e5e7eb; white-space:nowrap; }
        .result-table td { padding:.65rem .9rem; border-bottom:1px solid #f3f4f6; vertical-align:middle; }
        .result-table tr:hover td { background:#fafafa; }
        .result-table tr.row-approved td { background:#f0faf5; }
        .result-table tr.row-rejected td { background:#fff5f5; }

        /* radio chips */
        .chip-group { display:flex; gap:.4rem; }
        .chip-group input[type=radio] { display:none; }
        .chip-group label { padding:.25rem .65rem; border-radius:8px; border:1.5px solid #e5e7eb; font-size:.8rem; font-weight:600; cursor:pointer; transition:all .15s; }
        .chip-group input[value=approved]:checked+label { background:#c8dfe0; border-color:#70a3a7; color:#1a3f42; }
        .chip-group input[value=rejected]:checked+label { background:#deb8b9; border-color:#c9979a; color:#5c1f22; }
        .chip-group input[value=pending]:checked+label  { background:#f0e8c0; border-color:#d4c870; color:#6b5a20; }

        /* badges */
        .bdg-approved { background:#c8dfe0; color:#1a3f42; padding:.18rem .55rem; border-radius:999px; font-size:.74rem; font-weight:600; }
        .bdg-rejected { background:#deb8b9; color:#5c1f22; padding:.18rem .55rem; border-radius:999px; font-size:.74rem; font-weight:600; }
        .bdg-pending  { background:#f0e8c0; color:#6b5a20; padding:.18rem .55rem; border-radius:999px; font-size:.74rem; font-weight:600; }

        /* action bar */
        .action-bar { display:flex; align-items:center; gap:.75rem; flex-wrap:wrap; margin-bottom:1rem; }
        .btn-sm-outline { background:white; color:#1e4d6b; border:1.5px solid #1e4d6b; border-radius:8px; padding:.4rem .9rem; font-size:.85rem; font-weight:600; cursor:pointer; transition:all .2s; text-decoration:none; display:inline-flex; align-items:center; gap:.3rem; }
        .btn-sm-outline:hover { background:#1e4d6b; color:white; }

        /* conflict card */
        .conflict-card { border:2px solid #deb8b9; border-radius:14px; padding:1.2rem 1.4rem; margin-bottom:1rem; background:#fff; }
        .conflict-header { display:flex; align-items:center; gap:.75rem; margin-bottom:1rem; flex-wrap:wrap; }
        .conflict-badge { background:#deb8b9; color:#5c1f22; padding:.2rem .7rem; border-radius:999px; font-size:.78rem; font-weight:700; }
        .conflict-time { font-size:.9rem; color:#374151; font-weight:600; }

        .conflict-options { display:flex; flex-direction:column; gap:.6rem; }
        .conflict-option { border:2px solid #e5e7eb; border-radius:12px; background:white; transition:all .2s; overflow:hidden; }
        .conflict-option:has(input:checked) { border-color:#70a3a7; background:#f6fbf8; }
        .conflict-option.option-none:has(input:checked) { border-color:#c9979a; background:#fdf6f6; }
        .conflict-option-main { display:flex; align-items:center; gap:.85rem; padding:.85rem 1.1rem; cursor:pointer; }
        .conflict-option-main:hover { background:#f0f4f8; }
        .conflict-option-main input[type=radio] { width:18px; height:18px; flex-shrink:0; accent-color:#1e4d6b; cursor:pointer; }
        .conflict-option-info { flex:1; min-width:0; }
        .conflict-option-status { flex-shrink:0; }
        .status-pill { font-size:.78rem; font-weight:700; padding:.25rem .75rem; border-radius:999px; white-space:nowrap; }
        .status-pill.pill-approve { background:#c8dfe0; color:#1a3f42; }
        .status-pill.pill-reject { background:#deb8b9; color:#5c1f22; }
        .winner-label-club { font-weight:700; font-size:.95rem; color:#1f2937; margin-bottom:.2rem; }
        .winner-label-event { font-size:.82rem; color:#6b7280; }
        .winner-none-label { font-weight:600; color:#5c1f22; }
        .adjust-toggle { padding:0 1.1rem .75rem 3rem; font-size:.8rem; color:#6b7280; }
        .adjust-toggle label { cursor:pointer; display:flex; align-items:center; gap:.4rem; }
        .conflict-edit-area { display:none; margin:0 1.1rem .85rem 3rem; padding:.5rem; background:#f8fafc; border-radius:8px; border:1px dashed #cbd5e1; font-size:.8rem; }

        /* section divider（同一白底面板內的區塊分隔線）*/
        .section-divider { border:none; border-top:2px dashed #d6dde5; margin:2.25rem 0; }

        /* section head（區塊標題色塊，讓各區塊分隔更明顯）*/
        .section-head { display:flex; align-items:center; gap:.5rem; padding:.6rem 1rem; border-radius:10px; margin-bottom:1rem; font-weight:700; font-size:1.05rem; }
        .section-head.head-clean    { background:#eaf6ef; color:#198754; }
        .section-head.head-conflict { background:#fdecee; color:#dc3545; }
        .section-head.head-done     { background:#f1ecfb; color:#6f42c1; }

        /* save row（跟著內容排版，非浮動）*/
        .save-row { border-top:1px solid #e5e7eb; padding-top:1rem; margin-top:1.25rem; display:flex; align-items:center; justify-content:space-between; gap:1rem; flex-wrap:wrap; }
        .btn-save { background:#1e4d6b; color:white; border:none; border-radius:10px; padding:.65rem 1.6rem; font-weight:700; font-size:.95rem; cursor:pointer; display:flex; align-items:center; gap:.5rem; transition:background .2s; }
        .btn-save:hover { background:#14394f; }

        /* alerts */
        .alert-success { background:#c8dfe0; border-color:#70a3a7; color:#1a3f42; }
        .alert-danger  { background:#deb8b9; border-color:#c9979a; color:#5c1f22; }

        /* notice */
        .notice { background:#f0e8c0; border:1px solid #d4c870; border-radius:10px; padding:.75rem 1rem; color:#6b5a20; font-size:.88rem; margin-bottom:1rem; }

        @media(max-width:1100px){ .main-content{margin-left:0;} }
    </style>
</head>
<body>
<?php include(__DIR__ . "/../includes/admin_sidebar.php"); ?>

<main class="main-content">
    <?php
    $nav_breadcrumbs = [
        ['label' => '首頁', 'url' => 'dashboard.php'],
        ['label' => '場協管理', 'url' => 'field_coordination_mgmt.php'],
        ['label' => '結果匯入'],
    ];
    $nav_title = '場協結果匯入';
    include __DIR__ . '/../includes/admin_navbar.php';
    ?>

    <section class="content-wrapper">

        <?php if ($message): ?>
        <div class="alert alert-<?= $message_type === 'success' ? 'success' : 'danger' ?> rounded-3 mb-3">
            <?= $message ?>
        </div>
        <?php endif; ?>

        <!-- 學期選擇 -->
        <div class="card">
            <div class="card-title"><i class="bi bi-calendar3"></i> 選擇場協學期</div>
            <form method="GET" style="display:flex; align-items:center; gap:1rem; flex-wrap:wrap;">
                <select name="setting_id" onchange="this.form.submit()"
                        style="border:1.5px solid #d1d5db; border-radius:10px; padding:.55rem 1rem; font-size:.95rem; background:white;">
                    <?php foreach ($all_settings as $s): ?>
                    <option value="<?= $s['setting_id'] ?>" <?= (int)$s['setting_id'] === $selected_setting_id ? 'selected' : '' ?>>
                        民國 <?= $s['academic_year'] ?> 學年 <?= $s['semester'] == 1 ? '上' : '下' ?>學期
                        （<?= date('Y-m-d', strtotime($s['coordination_meeting_date'])) ?> 協調大會）
                    </option>
                    <?php endforeach; ?>
                    <?php if (empty($all_settings)): ?><option>尚無場協設定</option><?php endif; ?>
                </select>
                <?php if ($selected_setting): ?>
                <span style="font-size:.85rem; color:#6b7280;">
                    <i class="bi bi-calendar-range me-1"></i>
                    登記：<?= date('m/d', strtotime($selected_setting['registration_start_date'])) ?>
                    ～ <?= date('m/d', strtotime($selected_setting['registration_end_date'])) ?>
                </span>
                <?php endif; ?>
            </form>
        </div>

        <?php if (empty($registrations) && $selected_setting_id): ?>
        <div class="card text-center py-5">
            <i class="bi bi-inbox fs-1 text-muted d-block mb-2"></i>
            <div style="color:#6b7280;">此學期尚無任何場協登記。</div>
        </div>
        <?php elseif (!empty($registrations)): ?>

        <!-- 分頁籤 -->
        <div class="card" style="padding:1rem 1.5rem;">
            <div class="tabs-bar">
                <a href="?setting_id=<?= $selected_setting_id ?>&tab=all" class="filter-tab tab-all <?= $tab==='all'?'active':'' ?>">
                    <i class="bi bi-grid"></i> 全部
                    <span class="ftab-count"><?= count($registrations) ?></span>
                </a>
                <a href="?setting_id=<?= $selected_setting_id ?>&tab=clean" class="filter-tab tab-clean <?= $tab==='clean'?'active':'' ?>">
                    <i class="bi bi-check2-circle"></i> 無衝突
                    <span class="ftab-count"><?= count($pending_clean) ?></span>
                </a>
                <a href="?setting_id=<?= $selected_setting_id ?>&tab=conflict" class="filter-tab tab-conflict <?= $tab==='conflict'?'active':'' ?>">
                    <i class="bi bi-exclamation-triangle"></i> 有衝突
                    <span class="ftab-count"><?= count($unresolved_conflicting_ids) ?></span>
                </a>
                <a href="?setting_id=<?= $selected_setting_id ?>&tab=done" class="filter-tab tab-done <?= $tab==='done'?'active':'' ?>">
                    <i class="bi bi-clipboard2-check"></i> 已審核完成
                    <span class="ftab-count"><?= count($done_regs) ?></span>
                </a>
            </div>
        </div>

        <!-- 主面板：所有內容統一包在同一個白色容器內，依區塊以分隔線區分 -->
        <div class="card">
            <?php $section_no = 0; ?>

            <?php if ($tab === 'all' && $selected_setting_id): ?>
            <?php if ($section_no++ > 0): ?><hr class="section-divider"><?php endif; ?>
            <div style="display:flex; align-items:center; justify-content:space-between; flex-wrap:wrap; gap:.75rem;">
                <div style="display:flex; align-items:center; gap:.5rem; color:#374151; font-size:.9rem;">
                    <i class="bi bi-file-earmark-spreadsheet" style="color:#1e4d6b;"></i>
                    <strong>場協大會總表匯出</strong>
                    <span style="color:#9ca3af;">（無衝突＋有衝突全部登記合併，並標示衝突組別，供大會現場使用）</span>
                </div>
                <form method="GET" action="" style="display:flex; align-items:center; gap:.5rem;">
                    <input type="hidden" name="action" value="export_meeting">
                    <input type="hidden" name="setting_id" value="<?= $selected_setting_id ?>">
                    <label style="font-size:.85rem; color:#6b7280;">排序方式：</label>
                    <select name="sort" style="border:1px solid #e5e7eb; border-radius:7px; padding:.3rem .6rem; font-size:.85rem;">
                        <option value="time">時間順序（常用）</option>
                        <option value="venue">場地</option>
                        <option value="club">社團</option>
                    </select>
                    <button type="submit" style="background:#1e4d6b; color:white; border:none; border-radius:7px; padding:.35rem 1rem; font-size:.85rem; font-weight:600; cursor:pointer;">
                        <i class="bi bi-download"></i> 匯出大會總表
                    </button>
                </form>
            </div>
            <?php endif; ?>

            <!-- ── 無衝突登記 ── -->
            <?php if ($show_clean): ?>
            <?php if ($section_no++ > 0): ?><hr class="section-divider"><?php endif; ?>
            <div>
                <div class="section-head head-clean">
                    <i class="bi bi-check2-circle"></i>
                    無衝突登記<?php if (!empty($pending_clean)): ?>（<?= count($pending_clean) ?> 筆待處理）<?php endif; ?>
                </div>

                <?php if (empty($pending_clean)): ?>
                <div class="text-center py-4" style="color:#6b7280;">
                    <i class="bi bi-check2-circle fs-1 text-muted d-block mb-2"></i>
                    目前沒有待處理的無衝突登記。
                </div>
                <?php else: ?>
                <form method="POST" id="cleanForm" class="track-dirty">
                    <input type="hidden" name="save_results" value="1">
                    <input type="hidden" name="setting_id" value="<?= $selected_setting_id ?>">

                    <div style="display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:.5rem; margin-bottom:.75rem;">
                        <div class="action-bar" style="margin:0;">
                            <button type="button" class="btn-sm-outline" onclick="setAllClean('approved')">
                                <i class="bi bi-check-all me-1"></i>全部核准（預設）
                            </button>
                        </div>
                        <div style="display:flex; gap:.5rem; align-items:center; flex-wrap:wrap;">
                            <!-- 社團搜尋 -->
                            <input type="text" id="clubFilter" placeholder="搜尋社團…"
                                   style="border:1px solid #e5e7eb; border-radius:7px; padding:.28rem .65rem; font-size:.83rem; width:130px;"
                                   oninput="filterTable()">
                            <!-- 排序切換 -->
                            <div style="display:flex; border:1px solid #e5e7eb; border-radius:7px; overflow:hidden; font-size:.82rem;">
                                <button type="button" id="sortTime" class="sort-btn active" onclick="setSortMode('time')"
                                        style="padding:.28rem .7rem; border:none; background:#1e4d6b; color:white; cursor:pointer;">
                                    <i class="bi bi-clock me-1"></i>依時間
                                </button>
                                <button type="button" id="sortClub" class="sort-btn" onclick="setSortMode('club')"
                                        style="padding:.28rem .7rem; border:none; background:white; color:#374151; cursor:pointer;">
                                    <i class="bi bi-people me-1"></i>依社團
                                </button>
                            </div>
                        </div>
                    </div>

                    <div style="overflow-x:auto;">
                        <table class="result-table">
                        <thead>
                            <tr>
                                <th>#</th>
                                <th>社團</th>
                                <th>活動名稱 / 負責人</th>
                                <th>場次（日期 時間 場地）</th>
                                <th>目前狀態</th>
                                <th>結果</th>
                                <th>備註</th>
                            </tr>
                        </thead>
                        <tbody id="cleanBody">
                        <?php foreach ($pending_clean as $idx => $reg):
                            $ap = $reg['is_approved'];
                            // 無衝突登記：尚未決定時預設為「核准」，管理者只需處理需編輯/拒絕的少數項目
                            $defaultDec = 'approved';
                            $rowCls = 'row-approved';
                            $rid = (int)$reg['registration_id'];
                            // 解析場地用途（從 description 第2行）
                            $desc_lines = explode("\n", $reg['description'] ?? '');
                            $purpose_line = '';
                            foreach ($desc_lines as $dl) {
                                if (strpos($dl, '用途：') === 0) { $purpose_line = mb_substr($dl, 3, null, 'UTF-8'); break; }
                            }
                            $sess_list = $reg_sessions[$rid] ?? [];
                        ?>
                        <tr class="<?= $rowCls ?> clean-row"
                            data-club="<?= htmlspecialchars($reg['club_name'], ENT_QUOTES, 'UTF-8') ?>"
                            data-time="<?= $reg['start_time'] ?>"
                            data-rid="<?= $rid ?>">
                            <td style="color:#9ca3af;"><?= $idx + 1 ?></td>
                            <td style="font-weight:600;"><?= htmlspecialchars($reg['club_name']) ?></td>
                            <td>
                                <div style="font-weight:600; color:#1f2937;"><?= htmlspecialchars($reg['event_name']) ?></div>
                                <?php if (!empty($reg['responsible_person'])): ?>
                                <div style="font-size:.8rem; color:#6b7280; margin-top:.15rem;">
                                    <i class="bi bi-person me-1"></i><?= htmlspecialchars($reg['responsible_person']) ?>
                                </div>
                                <?php endif; ?>
                                <?php if (!empty($purpose_line)): ?>
                                <div style="font-size:.78rem; color:#9ca3af; margin-top:.1rem;">
                                    用途：<?= htmlspecialchars($purpose_line) ?>
                                </div>
                                <?php endif; ?>
                            </td>
                            <td style="font-size:.82rem;">
                                <?php if (!empty($sess_list)): ?>
                                    <?php foreach ($sess_list as $sl): ?>
                                    <div style="white-space:nowrap; padding:.1rem 0; border-bottom:1px solid #f3f4f6;">
                                        <span style="color:#374151; font-weight:500;"><?= date('m/d', strtotime($sl['start_time'])) ?></span>
                                        <span style="color:#6b7280;"> <?= date('H:i', strtotime($sl['start_time'])) ?>–<?= date('H:i', strtotime($sl['end_time'])) ?></span><br>
                                        <span style="color:#9ca3af; font-size:.76rem;"><?= htmlspecialchars($sl['space_name'] ?? '場地未定') ?></span>
                                    </div>
                                    <?php endforeach; ?>
                                <?php else: ?>
                                    <span style="color:#9ca3af;">無場次資料</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <span class="bdg-pending">待定</span>
                            </td>
                            <td>
                                <div class="chip-group">
                                    <input type="radio" name="decision[<?= $rid ?>]" id="a_<?= $rid ?>" value="approved" <?= $defaultDec==='approved'?'checked':'' ?> onchange="updateCleanRow(this);toggleEditRow(<?= $rid ?>,false)">
                                    <label for="a_<?= $rid ?>">✓ 核准</label>
                                    <input type="radio" name="decision[<?= $rid ?>]" id="e_<?= $rid ?>" value="edited" <?= $defaultDec==='edited'?'checked':'' ?> onchange="updateCleanRow(this);toggleEditRow(<?= $rid ?>,true)">
                                    <label for="e_<?= $rid ?>">✎ 編輯</label>
                                    <input type="radio" name="decision[<?= $rid ?>]" id="p_<?= $rid ?>" value="pending" <?= $defaultDec==='pending'?'checked':'' ?> onchange="updateCleanRow(this);toggleEditRow(<?= $rid ?>,false)">
                                    <label for="p_<?= $rid ?>">— 待定</label>
                                </div>
                            </td>
                            <td>
                                <input type="text" name="note[<?= $rid ?>]"
                                       style="border:1px solid #e5e7eb; border-radius:6px; padding:.28rem .6rem; font-size:.82rem; width:130px;"
                                       placeholder="備註" value="<?= htmlspecialchars($reg['approval_note'] ?? '') ?>">
                            </td>
                        </tr>
                        <!-- 編輯場次列（預設隱藏） -->
                        <tr id="editrow_<?= $rid ?>" style="display:none;">
                            <td colspan="7" style="background:#f8fafc; padding:.75rem 1rem; border-top:2px dashed #cbd5e1;">
                                <div style="font-size:.83rem; font-weight:600; color:#1e4d6b; margin-bottom:.5rem;">
                                    <i class="bi bi-pencil-square me-1"></i>調整後場次
                                </div>
                                <?php foreach ($sess_list as $si_e => $sl_e): ?>
                                <div style="display:flex; flex-wrap:wrap; gap:.5rem; align-items:flex-end; margin-bottom:.4rem; padding:.4rem .5rem; background:white; border-radius:8px; border:1px solid #e2e8f0;">
                                    <input type="hidden" name="edit_session[<?= $rid ?>][<?= $si_e ?>][reservation_id]" value="<?= $sl_e['reservation_id'] ?>">
                                    <div>
                                        <div style="font-size:.74rem; color:#6b7280; margin-bottom:2px;">開始日期</div>
                                        <input type="date" name="edit_session[<?= $rid ?>][<?= $si_e ?>][date]"
                                               value="<?= date('Y-m-d', strtotime($sl_e['start_time'])) ?>"
                                               style="border:1px solid #cbd5e1; border-radius:6px; padding:.25rem .5rem; font-size:.82rem;">
                                    </div>
                                    <div>
                                        <div style="font-size:.74rem; color:#6b7280; margin-bottom:2px;">開始時間</div>
                                        <div style="display:flex; align-items:center; gap:3px;">
                                            <select name="edit_session[<?= $rid ?>][<?= $si_e ?>][start_time_h]" class="es-hour" data-rid="<?= $rid ?>" data-idx="<?= $si_e ?>" data-field="start" style="border:1px solid #cbd5e1; border-radius:6px; padding:.25rem .4rem; font-size:.82rem;">
                                                <?php for($h=8;$h<=21;$h++){$hh=sprintf('%02d',$h);$sel=date('H',strtotime($sl_e['start_time']))==$hh?'selected':'';echo "<option value=\"$hh\" $sel>$hh</option>";}?>
                                            </select>
                                            <span>:</span>
                                            <select name="edit_session[<?= $rid ?>][<?= $si_e ?>][start_time_m]" class="es-min" data-rid="<?= $rid ?>" data-idx="<?= $si_e ?>" data-field="start" style="border:1px solid #cbd5e1; border-radius:6px; padding:.25rem .4rem; font-size:.82rem;">
                                                <?php foreach([0,10,20,30,40,50] as $m){$mm=sprintf('%02d',$m);$sel=date('i',strtotime($sl_e['start_time']))==$mm?'selected':'';echo "<option value=\"$mm\" $sel>$mm</option>";}?>
                                            </select>
                                            <input type="hidden" name="edit_session[<?= $rid ?>][<?= $si_e ?>][start_time]" class="es-time-val" data-rid="<?= $rid ?>" data-idx="<?= $si_e ?>" data-field="start" value="<?= date('H:i', strtotime($sl_e['start_time'])) ?>">
                                        </div>
                                    </div>
                                    <div>
                                        <div style="font-size:.74rem; color:#6b7280; margin-bottom:2px;">結束日期</div>
                                        <input type="date" name="edit_session[<?= $rid ?>][<?= $si_e ?>][end_date]"
                                               value="<?= date('Y-m-d', strtotime($sl_e['end_time'])) ?>"
                                               style="border:1px solid #cbd5e1; border-radius:6px; padding:.25rem .5rem; font-size:.82rem;">
                                    </div>
                                    <div>
                                        <div style="font-size:.74rem; color:#6b7280; margin-bottom:2px;">結束時間</div>
                                        <div style="display:flex; align-items:center; gap:3px;">
                                            <select name="edit_session[<?= $rid ?>][<?= $si_e ?>][end_time_h]" class="es-hour" data-rid="<?= $rid ?>" data-idx="<?= $si_e ?>" data-field="end" style="border:1px solid #cbd5e1; border-radius:6px; padding:.25rem .4rem; font-size:.82rem;">
                                                <?php for($h=8;$h<=21;$h++){$hh=sprintf('%02d',$h);$sel=date('H',strtotime($sl_e['end_time']))==$hh?'selected':'';echo "<option value=\"$hh\" $sel>$hh</option>";}?>
                                            </select>
                                            <span>:</span>
                                            <select name="edit_session[<?= $rid ?>][<?= $si_e ?>][end_time_m]" class="es-min" data-rid="<?= $rid ?>" data-idx="<?= $si_e ?>" data-field="end" style="border:1px solid #cbd5e1; border-radius:6px; padding:.25rem .4rem; font-size:.82rem;">
                                                <?php foreach([0,10,20,30,40,50] as $m){$mm=sprintf('%02d',$m);$sel=date('i',strtotime($sl_e['end_time']))==$mm?'selected':'';echo "<option value=\"$mm\" $sel>$mm</option>";}?>
                                            </select>
                                            <input type="hidden" name="edit_session[<?= $rid ?>][<?= $si_e ?>][end_time]" class="es-time-val" data-rid="<?= $rid ?>" data-idx="<?= $si_e ?>" data-field="end" value="<?= date('H:i', strtotime($sl_e['end_time'])) ?>">
                                        </div>
                                    </div>
                                    <div>
                                        <div style="font-size:.74rem; color:#6b7280; margin-bottom:2px;">場地</div>
                                        <select name="edit_session[<?= $rid ?>][<?= $si_e ?>][space_id]" style="border:1px solid #cbd5e1; border-radius:6px; padding:.25rem .5rem; font-size:.82rem;">
                                            <?php foreach($all_spaces as $sp):?>
                                            <option value="<?= $sp['space_id'] ?>" <?= $sp['space_id']==$sl_e['space_id']?'selected':''?>><?= htmlspecialchars($sp['space_name']) ?></option>
                                            <?php endforeach;?>
                                        </select>
                                    </div>
                                </div>
                                <?php endforeach; ?>
                                <div style="margin-top:.6rem; font-size:.78rem; color:#6b7280;">
                                    <i class="bi bi-info-circle me-1"></i>調整後場次將於按下下方「套用並儲存『無衝突』結果」時一併儲存。
                                </div>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                    </div>

                    <div class="save-row">
                        <div style="font-size:.88rem; color:#6b7280;">
                            <i class="bi bi-info-circle me-1"></i>
                            儲存後即時生效：核准的時段將反映於場地日曆，社團申請活動時可自動帶入場次資訊。
                        </div>
                        <button type="submit" class="btn-save">
                            <i class="bi bi-cloud-upload"></i> 套用並儲存「無衝突」結果
                        </button>
                    </div>
                </form>
                <?php endif; ?>
            </div>
            <?php endif; ?>

            <!-- ── 衝突需協調 ── -->
            <?php if ($show_conflict): ?>
            <?php if ($section_no++ > 0): ?><hr class="section-divider"><?php endif; ?>
            <div>
                <div class="section-head head-conflict">
                    <i class="bi bi-exclamation-triangle-fill"></i>
                    衝突需協調<?php if (!empty($unresolved_groups)): ?>（<?= count($unresolved_groups) ?> 組，共 <?= count($unresolved_conflicting_ids) ?> 筆登記）<?php endif; ?>
                </div>

                <?php if (empty($unresolved_groups)): ?>
                <div class="text-center py-4" style="color:#6b7280;">
                    <i class="bi bi-check2-circle fs-1 text-muted d-block mb-2"></i>
                    目前沒有待協調的衝突時段。
                </div>
                <?php else: ?>
                <div class="notice">
                    <i class="bi bi-info-circle me-1"></i>
                    以下為場協大會中有衝突的時段。請根據實體協調結果，<strong>點選獲得該時段的社團</strong>，其右側會顯示「將核准」。
                    其他未選中的社團預設為「將拒絕」；若該社團另有調整後的時間/場地，可勾選「此社團另有調整後場次」並填入，將改為調整核准。
                    <strong>每組可獨立按下「套用此組結果」儲存</strong>，協調完一組就能立即送出，不必等所有組別都確認完才一起儲存。
                </div>

                <?php foreach ($unresolved_groups as $gi => $group_ids): ?>
                <?php
                    $group_regs = array_values(array_filter($registrations, function($r) use ($group_ids) {
                        return in_array($r['registration_id'], $group_ids);
                    }));
                    // 找衝突場次：找出真正重疊的那些場次做標題
                    $conflict_sess_labels = [];
                    for ($ci = 0; $ci < count($group_ids); $ci++) {
                        for ($cj = $ci + 1; $cj < count($group_ids); $cj++) {
                            $sa_list = $reg_sessions[$group_ids[$ci]] ?? [];
                            $sb_list = $reg_sessions[$group_ids[$cj]] ?? [];
                            foreach ($sa_list as $sa) {
                                foreach ($sb_list as $sb) {
                                    if ($sa['space_id'] == $sb['space_id'] &&
                                        $sa['start_time'] < $sb['end_time'] &&
                                        $sb['start_time'] < $sa['end_time']) {
                                        $key = $sa['space_id'] . '|' . $sa['start_time'];
                                        if (!isset($conflict_sess_labels[$key])) {
                                            $conflict_sess_labels[$key] = date('m/d H:i', strtotime($sa['start_time']))
                                                . '–' . date('H:i', strtotime($sa['end_time']))
                                                . ' ' . htmlspecialchars($sa['space_name'] ?? '');
                                        }
                                    }
                                }
                            }
                        }
                    }

                    // 預設 winner（若已有核准紀錄）
                    $defaultWinner = 'none';
                    foreach ($group_regs as $gr) {
                        if ($gr['is_approved'] !== null && (int)$gr['is_approved'] === 1) {
                            $defaultWinner = $gr['registration_id'];
                        }
                    }
                ?>
                <form method="POST" class="track-dirty">
                    <input type="hidden" name="save_results" value="1">
                    <input type="hidden" name="setting_id" value="<?= $selected_setting_id ?>">
                    <?php foreach ($group_ids as $gid): ?>
                    <input type="hidden" name="conflict_group[0][]" value="<?= $gid ?>">
                    <?php endforeach; ?>
                <div class="conflict-card">
                    <div class="conflict-header">
                        <span class="conflict-badge">衝突 <?= $gi + 1 ?></span>
                        <?php foreach ($conflict_sess_labels as $lbl): ?>
                        <span class="conflict-time"><i class="bi bi-clock me-1"></i><?= $lbl ?></span>
                        <?php endforeach; ?>
                    </div>

                    <div class="conflict-options">
                        <?php foreach ($group_regs as $gr): ?>
                        <?php
                            $gr_sess = $reg_sessions[$gr['registration_id']] ?? [];
                            $isDefaultWinner = ($defaultWinner == $gr['registration_id']);
                        ?>
                        <div class="conflict-option">
                            <label class="conflict-option-main" for="w_<?= $gi ?>_<?= $gr['registration_id'] ?>">
                                <input type="radio"
                                       name="conflict_winner[0]"
                                       id="w_<?= $gi ?>_<?= $gr['registration_id'] ?>"
                                       value="<?= $gr['registration_id'] ?>"
                                       <?= $isDefaultWinner ? 'checked' : '' ?>
                                       onchange="updateConflictOption(this)">
                                <div class="conflict-option-info">
                                    <div class="winner-label-club">
                                        <?php
                                            $curStatus = ($gr['is_approved'] === null) ? '' :
                                                ((int)$gr['is_approved'] === 1 ? '（目前：核准）' : '（目前：拒絕）');
                                        ?>
                                        <i class="bi bi-people me-1" style="color:#1e4d6b;"></i>
                                        <?= htmlspecialchars($gr['club_name']) ?>
                                        <?php if ($curStatus): ?>
                                            <span style="font-size:.75rem; color:#9ca3af;"><?= $curStatus ?></span>
                                        <?php endif; ?>
                                    </div>
                                    <div class="winner-label-event">
                                        <i class="bi bi-calendar2-event me-1"></i><?= htmlspecialchars($gr['event_name']) ?>
                                        <?php if (!empty($gr['responsible_person'])): ?>
                                        <span style="color:#9ca3af;"> · <?= htmlspecialchars($gr['responsible_person']) ?></span>
                                        <?php endif; ?>
                                    </div>
                                    <?php if (!empty($gr_sess)): ?>
                                    <div style="margin-top:.4rem; font-size:.78rem; color:#6b7280;">
                                        <?php foreach ($gr_sess as $gsess): ?>
                                        <div><?= date('m/d', strtotime($gsess['start_time'])) ?>
                                             <?= date('H:i', strtotime($gsess['start_time'])) ?>–<?= date('H:i', strtotime($gsess['end_time'])) ?>
                                             <?= htmlspecialchars($gsess['space_name'] ?? '') ?></div>
                                        <?php endforeach; ?>
                                    </div>
                                    <?php endif; ?>
                                </div>
                                <div class="conflict-option-status">
                                    <span class="status-pill <?= $isDefaultWinner ? 'pill-approve' : 'pill-reject' ?>">
                                        <?= $isDefaultWinner ? '將核准' : '將拒絕' ?>
                                    </span>
                                </div>
                            </label>
                            <!-- 非獲勝者的調整切換 -->
                            <div class="adjust-toggle" style="<?= $isDefaultWinner ? 'display:none;' : '' ?>">
                                <label>
                                    <input type="checkbox" class="adjust-checkbox" onchange="toggleAdjustArea(this)">
                                    此社團另有調整後場次（勾選後填寫，否則將直接拒絕）
                                </label>
                            </div>
                            <div id="conflict_edit_<?= $gi ?>_<?= $gr['registration_id'] ?>" class="conflict-edit-area">
                                <div style="font-weight:600; color:#1e4d6b; margin-bottom:.4rem;"><i class="bi bi-pencil-square me-1"></i>調整後場次</div>
                                <?php foreach ($reg_sessions[$gr['registration_id']] ?? [] as $si_c => $sl_c): ?>
                                <div style="display:flex; flex-wrap:wrap; gap:.4rem; align-items:flex-end; margin-bottom:.3rem; padding:.3rem .4rem; background:white; border-radius:6px; border:1px solid #e2e8f0;">
                                    <input type="hidden" name="edit_session[<?= $gr['registration_id'] ?>][<?= $si_c ?>][reservation_id]" value="<?= $sl_c['reservation_id'] ?>">
                                    <div><div style="font-size:.72rem; color:#9ca3af;">開始日期</div>
                                        <input type="date" name="edit_session[<?= $gr['registration_id'] ?>][<?= $si_c ?>][date]" value="<?= date('Y-m-d',strtotime($sl_c['start_time'])) ?>" style="border:1px solid #cbd5e1;border-radius:5px;padding:.2rem .4rem;font-size:.8rem;">
                                    </div>
                                    <div><div style="font-size:.72rem; color:#9ca3af;">開始時間</div>
                                        <div style="display:flex;align-items:center;gap:2px;">
                                            <select name="edit_session[<?= $gr['registration_id'] ?>][<?= $si_c ?>][start_time_h]" class="es-hour" data-rid="<?= $gr['registration_id'] ?>" data-idx="<?= $si_c ?>" data-field="start" style="border:1px solid #cbd5e1;border-radius:5px;padding:.2rem .35rem;font-size:.8rem;"><?php for($h=8;$h<=21;$h++){$hh=sprintf('%02d',$h);$sel=date('H',strtotime($sl_c['start_time']))==$hh?'selected':'';echo "<option value=\"$hh\" $sel>$hh</option>";}?></select>
                                            <span>:</span>
                                            <select name="edit_session[<?= $gr['registration_id'] ?>][<?= $si_c ?>][start_time_m]" class="es-min" data-rid="<?= $gr['registration_id'] ?>" data-idx="<?= $si_c ?>" data-field="start" style="border:1px solid #cbd5e1;border-radius:5px;padding:.2rem .35rem;font-size:.8rem;"><?php foreach([0,10,20,30,40,50] as $m){$mm=sprintf('%02d',$m);$sel=date('i',strtotime($sl_c['start_time']))==$mm?'selected':'';echo "<option value=\"$mm\" $sel>$mm</option>";}?></select>
                                            <input type="hidden" name="edit_session[<?= $gr['registration_id'] ?>][<?= $si_c ?>][start_time]" class="es-time-val" data-rid="<?= $gr['registration_id'] ?>" data-idx="<?= $si_c ?>" data-field="start" value="<?= date('H:i',strtotime($sl_c['start_time'])) ?>">
                                        </div>
                                    </div>
                                    <div><div style="font-size:.72rem; color:#9ca3af;">結束日期</div>
                                        <input type="date" name="edit_session[<?= $gr['registration_id'] ?>][<?= $si_c ?>][end_date]" value="<?= date('Y-m-d',strtotime($sl_c['end_time'])) ?>" style="border:1px solid #cbd5e1;border-radius:5px;padding:.2rem .4rem;font-size:.8rem;">
                                    </div>
                                    <div><div style="font-size:.72rem; color:#9ca3af;">結束時間</div>
                                        <div style="display:flex;align-items:center;gap:2px;">
                                            <select name="edit_session[<?= $gr['registration_id'] ?>][<?= $si_c ?>][end_time_h]" class="es-hour" data-rid="<?= $gr['registration_id'] ?>" data-idx="<?= $si_c ?>" data-field="end" style="border:1px solid #cbd5e1;border-radius:5px;padding:.2rem .35rem;font-size:.8rem;"><?php for($h=8;$h<=21;$h++){$hh=sprintf('%02d',$h);$sel=date('H',strtotime($sl_c['end_time']))==$hh?'selected':'';echo "<option value=\"$hh\" $sel>$hh</option>";}?></select>
                                            <span>:</span>
                                            <select name="edit_session[<?= $gr['registration_id'] ?>][<?= $si_c ?>][end_time_m]" class="es-min" data-rid="<?= $gr['registration_id'] ?>" data-idx="<?= $si_c ?>" data-field="end" style="border:1px solid #cbd5e1;border-radius:5px;padding:.2rem .35rem;font-size:.8rem;"><?php foreach([0,10,20,30,40,50] as $m){$mm=sprintf('%02d',$m);$sel=date('i',strtotime($sl_c['end_time']))==$mm?'selected':'';echo "<option value=\"$mm\" $sel>$mm</option>";}?></select>
                                            <input type="hidden" name="edit_session[<?= $gr['registration_id'] ?>][<?= $si_c ?>][end_time]" class="es-time-val" data-rid="<?= $gr['registration_id'] ?>" data-idx="<?= $si_c ?>" data-field="end" value="<?= date('H:i',strtotime($sl_c['end_time'])) ?>">
                                        </div>
                                    </div>
                                    <div><div style="font-size:.72rem; color:#9ca3af;">場地</div>
                                        <select name="edit_session[<?= $gr['registration_id'] ?>][<?= $si_c ?>][space_id]" style="border:1px solid #cbd5e1;border-radius:5px;padding:.2rem .4rem;font-size:.8rem;">
                                            <?php foreach($all_spaces as $sp):?><option value="<?= $sp['space_id'] ?>" <?= $sp['space_id']==$sl_c['space_id']?'selected':''?>><?= htmlspecialchars($sp['space_name'])?></option><?php endforeach;?>
                                        </select>
                                    </div>
                                </div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                        <?php endforeach; ?>

                        <!-- 無社團取得 -->
                        <div class="conflict-option option-none">
                            <label class="conflict-option-main" for="w_<?= $gi ?>_none">
                                <input type="radio"
                                       name="conflict_winner[0]"
                                       id="w_<?= $gi ?>_none"
                                       value="none"
                                       <?= $defaultWinner === 'none' ? 'checked' : '' ?>
                                       onchange="updateConflictOption(this)">
                                <div class="conflict-option-info">
                                    <div class="winner-none-label">
                                        <i class="bi bi-x-circle me-1"></i>無社團取得此時段（全部拒絕）
                                    </div>
                                </div>
                            </label>
                        </div>
                    </div>

                    <div class="save-row" style="justify-content:flex-end;">
                        <button type="submit" class="btn-save">
                            <i class="bi bi-cloud-upload"></i> 套用此組協調結果
                        </button>
                    </div>
                </div>
                </form>
                <?php endforeach; ?>
                <?php endif; ?>
            </div>
            <?php endif; ?>

            <!-- ── 已審核完成 ── -->
            <?php if ($show_done): ?>
            <?php if ($section_no++ > 0): ?><hr class="section-divider"><?php endif; ?>
            <div>
                <div style="display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:.5rem; margin-bottom:.75rem;">
                    <div class="section-head head-done" style="margin-bottom:0;">
                        <i class="bi bi-clipboard2-check"></i>
                        已審核完成（<?= count($done_regs) ?> 筆）
                    </div>
                    <?php if ($selected_setting_id): ?>
                    <a href="?action=export&setting_id=<?= $selected_setting_id ?>"
                       style="display:inline-flex; align-items:center; gap:.3rem; padding:.32rem .85rem; background:#e8f0f5; color:#1e4d6b; border-radius:7px; font-size:.85rem; font-weight:600; text-decoration:none;"
                       onmouseover="this.style.background='#d5e3ea'" onmouseout="this.style.background='#e8f0f5'">
                        <i class="bi bi-file-earmark-spreadsheet"></i> 匯出試算表
                    </a>
                    <?php endif; ?>
                </div>

                <?php if (empty($done_regs)): ?>
                <div class="text-center py-4" style="color:#6b7280;">
                    <i class="bi bi-inbox fs-1 text-muted d-block mb-2"></i>
                    尚無已審核完成的登記。
                </div>
                <?php else: ?>
                <div class="notice" style="margin-bottom:1rem;">
                    <i class="bi bi-info-circle me-1"></i>
                    以下登記已完成審核，核准／調整核准的時段已即時反映於場地日曆；拒絕的時段已釋出。
                </div>
                <div style="overflow-x:auto;">
                <table class="result-table">
                    <thead>
                        <tr>
                            <th>#</th>
                            <th>社團</th>
                            <th>活動名稱 / 負責人</th>
                            <th>場次（日期 時間 場地）</th>
                            <th>結果</th>
                            <th>備註</th>
                            <th>操作</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($done_regs as $idx => $reg):
                        $rid = (int)$reg['registration_id'];
                        $ap  = (int)$reg['is_approved'];
                        $sess_list = $reg_sessions[$rid] ?? [];
                        $rowCls = $ap === 1 ? 'row-approved' : 'row-rejected';
                    ?>
                    <tr class="<?= $rowCls ?>">
                        <td style="color:#9ca3af;"><?= $idx + 1 ?></td>
                        <td style="font-weight:600;"><?= htmlspecialchars($reg['club_name']) ?></td>
                        <td>
                            <div style="font-weight:600; color:#1f2937;"><?= htmlspecialchars($reg['event_name']) ?></div>
                            <?php if (!empty($reg['responsible_person'])): ?>
                            <div style="font-size:.8rem; color:#6b7280; margin-top:.15rem;">
                                <i class="bi bi-person me-1"></i><?= htmlspecialchars($reg['responsible_person']) ?>
                            </div>
                            <?php endif; ?>
                        </td>
                        <td style="font-size:.82rem;">
                            <?php if (!empty($sess_list)): ?>
                                <?php foreach ($sess_list as $sl): ?>
                                <div style="white-space:nowrap; padding:.1rem 0; border-bottom:1px solid #f3f4f6;">
                                    <span style="color:#374151; font-weight:500;"><?= date('m/d', strtotime($sl['start_time'])) ?></span>
                                    <span style="color:#6b7280;"> <?= date('H:i', strtotime($sl['start_time'])) ?>–<?= date('H:i', strtotime($sl['end_time'])) ?></span><br>
                                    <span style="color:#9ca3af; font-size:.76rem;"><?= htmlspecialchars($sl['space_name'] ?? '場地未定') ?></span>
                                </div>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <span style="color:#9ca3af;">無場次資料</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php if ($ap === 1): ?>
                                <span class="bdg-approved">✓ 核准</span>
                            <?php else: ?>
                                <span class="bdg-rejected">✗ 拒絕</span>
                            <?php endif; ?>
                        </td>
                        <td style="font-size:.82rem; color:#6b7280;"><?= htmlspecialchars($reg['approval_note'] ?? '') ?></td>
                        <td>
                            <?php if ($ap === 1): ?>
                            <form method="POST" style="margin:0;" onsubmit="return confirm('確定要撤銷核准嗎？此筆登記將改回「待審核」狀態，需重新處理。');">
                                <input type="hidden" name="action" value="revert_decision">
                                <input type="hidden" name="registration_id" value="<?= $rid ?>">
                                <input type="hidden" name="setting_id" value="<?= $selected_setting_id ?>">
                                <button type="submit" class="btn-sm-outline" style="color:#5c1f22; border-color:#deb8b9; padding:.25rem .65rem; font-size:.78rem;">
                                    <i class="bi bi-arrow-counterclockwise"></i> 撤銷核准
                                </button>
                            </form>
                            <?php else: ?>
                            <span style="font-size:.76rem; color:#c1c8d1;" title="已駁回的登記，原預約時段已釋出，無法還原">無法撤銷</span>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
                </div>
                <?php endif; ?>
            </div>
            <?php endif; ?>

        </div>

        <?php elseif (empty($all_settings)): ?>
        <div class="card text-center py-5">
            <i class="bi bi-calendar-x fs-1 text-muted d-block mb-2"></i>
            <div style="color:#6b7280; margin-bottom:1rem;">尚未建立場協設定。</div>
            <a href="field_coordination_mgmt.php" style="background:#1e4d6b; color:white; border-radius:8px; padding:.55rem 1.2rem; font-weight:600; text-decoration:none;">
                前往建立場協設定
            </a>
        </div>
        <?php endif; ?>

    </section>
</main>

<script>
// 無衝突列：更新列背景色
function updateCleanRow(radio) {
    const tr = radio.closest('tr');
    tr.className = radio.value === 'approved' ? 'row-approved clean-row' :
                   radio.value === 'edited'   ? 'row-approved clean-row' : 'clean-row';
}

// 無衝突：批次設定
function setAllClean(value) {
    document.querySelectorAll('#cleanBody input[type=radio][value="' + value + '"]').forEach(r => {
        r.checked = true;
        updateCleanRow(r);
        const rid = r.name.match(/\d+/)?.[0];
        if (rid) toggleEditRow(parseInt(rid), value === 'edited');
    });
}

// 顯示/隱藏無衝突列的編輯區
function toggleEditRow(rid, show) {
    const row = document.getElementById('editrow_' + rid);
    if (row) row.style.display = show ? '' : 'none';
}

// 衝突組：依目前選定的獲勝社團，更新各選項的「將核准／將拒絕」標籤與調整區
function updateConflictOption(radio) {
    const card = radio.closest('.conflict-card');
    const winner = card.querySelector('input[name="conflict_winner[0]"]:checked')?.value;
    card.querySelectorAll('.conflict-option').forEach(opt => {
        const optRadio = opt.querySelector('input[type=radio]');
        if (optRadio.value === 'none') return;
        const isWinner = optRadio.value === winner;
        const pill = opt.querySelector('.status-pill');
        if (pill) {
            pill.textContent = isWinner ? '將核准' : '將拒絕';
            pill.className = 'status-pill ' + (isWinner ? 'pill-approve' : 'pill-reject');
        }
        const adjustToggle = opt.querySelector('.adjust-toggle');
        const editArea = opt.querySelector('.conflict-edit-area');
        if (isWinner) {
            if (adjustToggle) {
                adjustToggle.style.display = 'none';
                const cb = adjustToggle.querySelector('.adjust-checkbox');
                if (cb) cb.checked = false;
            }
            if (editArea) {
                editArea.style.display = 'none';
                setEditAreaDisabled(editArea, true);
            }
        } else {
            if (adjustToggle) adjustToggle.style.display = '';
        }
    });
}

// 切換「此社團另有調整後場次」的編輯區顯示與欄位啟用狀態
function toggleAdjustArea(checkbox) {
    const editArea = checkbox.closest('.conflict-option').querySelector('.conflict-edit-area');
    if (!editArea) return;
    editArea.style.display = checkbox.checked ? 'block' : 'none';
    setEditAreaDisabled(editArea, !checkbox.checked);
}

function setEditAreaDisabled(area, disabled) {
    area.querySelectorAll('input, select').forEach(el => el.disabled = disabled);
}

// 初始化：所有調整區預設停用欄位，並依預設獲勝者套用一次狀態
document.querySelectorAll('.conflict-card').forEach(card => {
    card.querySelectorAll('.conflict-edit-area').forEach(area => setEditAreaDisabled(area, true));
    const checked = card.querySelector('input[name="conflict_winner[0]"]:checked');
    if (checked) updateConflictOption(checked);
});

// hour/min select → hidden input 同步
document.addEventListener('change', function(e) {
    const el = e.target;
    if (!el.classList.contains('es-hour') && !el.classList.contains('es-min')) return;
    const rid = el.dataset.rid, idx = el.dataset.idx, field = el.dataset.field;
    const prefix = `[name="edit_session[${rid}][${idx}][${field}_time_`;
    const h = document.querySelector(`${prefix}h]`)?.value || '08';
    const m = document.querySelector(`${prefix}m]`)?.value || '00';
    const hidden = document.querySelector(`.es-time-val[data-rid="${rid}"][data-idx="${idx}"][data-field="${field}"]`);
    if (hidden) hidden.value = h + ':' + m;
});

// ── 社團搜尋 + 排序 ────────────────────────────────────────────
let currentSort = 'time';

function filterTable() {
    const kw = (document.getElementById('clubFilter')?.value || '').toLowerCase();
    document.querySelectorAll('.clean-row').forEach(tr => {
        const club = (tr.dataset.club || '').toLowerCase();
        const editRow = document.getElementById('editrow_' + tr.dataset.rid);
        const show = club.includes(kw);
        tr.style.display = show ? '' : 'none';
        if (editRow) editRow.style.display = (show && editRow.style.display !== 'none') ? '' : 'none';
    });
    renderClubGroupHeaders();
}

function setSortMode(mode) {
    currentSort = mode;
    document.getElementById('sortTime').style.background = mode==='time' ? '#1e4d6b' : 'white';
    document.getElementById('sortTime').style.color      = mode==='time' ? 'white'   : '#374151';
    document.getElementById('sortClub').style.background = mode==='club' ? '#1e4d6b' : 'white';
    document.getElementById('sortClub').style.color      = mode==='club' ? 'white'   : '#374151';

    const tbody = document.getElementById('cleanBody');
    const rows  = Array.from(tbody.querySelectorAll('tr.clean-row'));
    rows.sort((a, b) => {
        if (mode === 'club') {
            const clubCmp = a.dataset.club.localeCompare(b.dataset.club, 'zh-Hant');
            if (clubCmp !== 0) return clubCmp;
        }
        return (a.dataset.time || '').localeCompare(b.dataset.time || '');
    });
    rows.forEach(row => {
        tbody.appendChild(row);
        const editRow = document.getElementById('editrow_' + row.dataset.rid);
        if (editRow) tbody.appendChild(editRow);
    });
    renderClubGroupHeaders();
}

function renderClubGroupHeaders() {
    // 依社團模式：在社團第一行前插入分組標題
    document.querySelectorAll('.club-group-header').forEach(el => el.remove());
    if (currentSort !== 'club') return;
    const tbody = document.getElementById('cleanBody');
    if (!tbody) return;
    let lastClub = null;
    Array.from(tbody.querySelectorAll('tr.clean-row')).forEach(row => {
        if (row.style.display === 'none') return;
        const club = row.dataset.club;
        if (club !== lastClub) {
            const hdr = document.createElement('tr');
            hdr.className = 'club-group-header';
            hdr.innerHTML = `<td colspan="7" style="background:#f1f5f9; font-weight:700; color:#1e4d6b; padding:.4rem .8rem; font-size:.83rem; border-bottom:2px solid #e2e8f0;">${escHtml(club)}</td>`;
            tbody.insertBefore(hdr, row);
            lastClub = club;
        }
    });
}

function escHtml(str) {
    return String(str).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
}

// 離開前提示
let dirty = false;
document.querySelectorAll('form.track-dirty').forEach(f => {
    f.addEventListener('change', () => dirty = true);
    f.addEventListener('submit', () => dirty = false);
});
window.addEventListener('beforeunload', e => { if (dirty) { e.preventDefault(); e.returnValue = ''; } });
</script>
</body>
</html>
