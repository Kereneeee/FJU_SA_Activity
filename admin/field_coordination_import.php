<?php
ini_set('display_errors', 1);
error_reporting(E_ALL);

require_once(__DIR__ . "/../DB/db_config.php");
require_once(__DIR__ . "/../includes/FieldCoordinationManager.php");

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    header('Location: ../login.php');
    exit();
}

$current_page = 'field_coordination_import';
$fc_manager   = new FieldCoordinationManager($conn);
$message      = '';
$message_type = '';

// ── 儲存結果 ──────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_results'])) {
    $setting_id = intval($_POST['setting_id'] ?? 0);
    $cnt = ['approved' => 0, 'rejected' => 0, 'skip' => 0];

    // 1. 衝突組：每組只有一個贏家
    $conflict_groups  = $_POST['conflict_group']  ?? [];   // [gi => [reg_id, ...]]
    $conflict_winners = $_POST['conflict_winner'] ?? [];   // [gi => reg_id | 'none']
    foreach ($conflict_groups as $gi => $members) {
        $winner = $conflict_winners[$gi] ?? 'none';
        foreach ($members as $reg_id) {
            $reg_id = intval($reg_id);
            if ($winner !== 'none' && $winner == $reg_id) {
                $fc_manager->approveFieldCoordinationRegistration($reg_id, '場協大會核准');
                $cnt['approved']++;
            } else {
                $fc_manager->rejectFieldCoordinationRegistration($reg_id, '場協大會衝突：本時段由他社取得');
                $cnt['rejected']++;
            }
        }
    }

    // 2. 無衝突登記：個別 approve / reject / skip
    $decisions = $_POST['decision'] ?? [];
    $notes     = $_POST['note']     ?? [];
    foreach ($decisions as $reg_id => $decision) {
        $reg_id = intval($reg_id);
        $note   = trim($notes[$reg_id] ?? '');
        if ($decision === 'approved') {
            $fc_manager->approveFieldCoordinationRegistration($reg_id, $note ?: null);
            $cnt['approved']++;
        } elseif ($decision === 'rejected') {
            $fc_manager->rejectFieldCoordinationRegistration($reg_id, $note ?: '無說明');
            $cnt['rejected']++;
        } else {
            $cnt['skip']++;
        }
    }

    $message = "✅ 套用完成：核准 {$cnt['approved']} 筆、拒絕 {$cnt['rejected']} 筆" .
               ($cnt['skip'] ? "、跳過 {$cnt['skip']} 筆" : "");
    $message_type = 'success';
    $_GET['setting_id'] = $setting_id;
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

// ── 取得登記清單 ──────────────────────────────────────────────
$registrations = [];
if ($selected_setting_id) {
    $sql = "SELECT
                fcr.registration_id, fcr.club_name, fcr.is_approved, fcr.approval_note,
                e.event_id, e.event_name, e.start_time, e.end_time,
                GROUP_CONCAT(DISTINCT s.space_name  ORDER BY s.space_name  SEPARATOR '、') AS space_names,
                GROUP_CONCAT(DISTINCT res.space_id  ORDER BY res.space_id)                 AS space_ids
            FROM field_coordination_registrations fcr
            JOIN events e            ON fcr.event_id = e.event_id
            LEFT JOIN reservations res ON e.event_id = res.event_id
            LEFT JOIN spaces s         ON res.space_id = s.space_id
            WHERE fcr.setting_id = ?
            GROUP BY fcr.registration_id, fcr.club_name, fcr.is_approved, fcr.approval_note,
                     e.event_id, e.event_name, e.start_time, e.end_time
            ORDER BY e.start_time ASC, fcr.club_name ASC";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("i", $selected_setting_id);
    $stmt->execute();
    $res = $stmt->get_result();
    while ($row = $res->fetch_assoc()) { $registrations[] = $row; }
    $stmt->close();
}

// ── 偵測衝突（BFS 分組）────────────────────────────────────────
$adj = [];
foreach ($registrations as $r) { $adj[$r['registration_id']] = []; }

for ($i = 0; $i < count($registrations); $i++) {
    $a = $registrations[$i];
    $a_spaces = $a['space_ids'] ? explode(',', $a['space_ids']) : [];
    for ($j = $i + 1; $j < count($registrations); $j++) {
        $b = $registrations[$j];
        $b_spaces = $b['space_ids'] ? explode(',', $b['space_ids']) : [];
        if (empty(array_intersect($a_spaces, $b_spaces))) continue;
        // 時間重疊
        if ($a['start_time'] < $b['end_time'] && $b['start_time'] < $a['end_time']) {
            $adj[$a['registration_id']][] = $b['registration_id'];
            $adj[$b['registration_id']][] = $a['registration_id'];
        }
    }
}

// BFS 找連通分量（衝突組）
$visited        = [];
$conflict_groups = [];   // [ [reg_id, ...], ... ]
$reg_map        = [];
foreach ($registrations as $r) { $reg_map[$r['registration_id']] = $r; }

foreach ($registrations as $r) {
    $rid = $r['registration_id'];
    if (isset($visited[$rid]) || empty($adj[$rid])) continue;
    $group = [];
    $queue = [$rid];
    $visited[$rid] = true;
    while (!empty($queue)) {
        $cur = array_shift($queue);
        $group[] = $cur;
        foreach ($adj[$cur] as $nb) {
            if (!isset($visited[$nb])) { $visited[$nb] = true; $queue[] = $nb; }
        }
    }
    if (count($group) > 1) { $conflict_groups[] = $group; }
}

$all_conflicting = [];
foreach ($conflict_groups as $g) { foreach ($g as $id) { $all_conflicting[] = $id; } }

$clean_regs = array_values(array_filter($registrations, function($r) use ($all_conflicting) {
    return !in_array($r['registration_id'], $all_conflicting);
}));

// 統計
$stat = ['pending' => 0, 'approved' => 0, 'rejected' => 0, 'conflict' => count($all_conflicting)];
foreach ($registrations as $r) {
    if ($r['is_approved'] === null)         $stat['pending']++;
    elseif ((int)$r['is_approved'] === 1)   $stat['approved']++;
    else                                    $stat['rejected']++;
}
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
        .sidebar { position:fixed; top:0; left:0; width:260px; height:100vh; background:var(--primary); color:white; padding:1.5rem 0.8rem; overflow-y:auto; box-shadow:3px 0 15px rgba(0,0,0,.12); z-index:1200; }
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
        .content-wrapper { padding:1.5rem 2rem 120px; }

        /* card */
        .card { background:var(--card); border-radius:18px; box-shadow:0 10px 30px rgba(15,23,42,.06); padding:1.5rem; margin-bottom:1.5rem; }
        .card-title { font-weight:700; color:var(--primary); font-size:1.05rem; display:flex; align-items:center; gap:.5rem; margin-bottom:1rem; }

        /* stats */
        .stat-row { display:flex; gap:.75rem; flex-wrap:wrap; margin-bottom:1.25rem; }
        .stat-box { padding:.55rem 1rem; border-radius:10px; font-size:.88rem; font-weight:600; display:flex; align-items:center; gap:.4rem; }
        .stat-conflict  { background:#deb8b9; color:#5c1f22; }
        .stat-pending   { background:#f0e8c0; color:#6b5a20; }
        .stat-approved  { background:#c8dfe0; color:#1a3f42; }
        .stat-rejected  { background:#ede4e5; color:#5a3f42; }
        .stat-total     { background:#f3f4f6; color:#374151; }

        /* conflict card */
        .conflict-card { border:2px solid #deb8b9; border-radius:14px; padding:1.2rem 1.4rem; margin-bottom:1rem; background:#fff; }
        .conflict-header { display:flex; align-items:center; gap:.75rem; margin-bottom:1rem; flex-wrap:wrap; }
        .conflict-badge { background:#deb8b9; color:#5c1f22; padding:.2rem .7rem; border-radius:999px; font-size:.78rem; font-weight:700; }
        .conflict-time { font-size:.9rem; color:#374151; font-weight:600; }
        .conflict-venue { font-size:.85rem; color:#6b7280; }

        .winner-options { display:flex; flex-wrap:wrap; gap:.75rem; }
        .winner-option { flex:1; min-width:200px; }
        .winner-option input[type=radio] { display:none; }
        .winner-option label {
            display:block; padding:.85rem 1.1rem; border:2px solid #e5e7eb; border-radius:12px;
            cursor:pointer; transition:all .2s; background:white;
        }
        .winner-option label:hover { border-color:#1e4d6b; background:#f0f4f8; }
        .winner-option input:checked + label { border-color:#70a3a7; background:#c8dfe0; }
        .winner-option input[value="none"]:checked + label { border-color:#c9979a; background:#deb8b9; }
        .winner-label-club { font-weight:700; font-size:.95rem; color:#1f2937; margin-bottom:.2rem; }
        .winner-label-event { font-size:.82rem; color:#6b7280; }
        .winner-none-label { font-weight:600; color:#5c1f22; text-align:center; padding:.3rem 0; }

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
        .btn-sm-outline { background:white; color:#1e4d6b; border:1.5px solid #1e4d6b; border-radius:8px; padding:.4rem .9rem; font-size:.85rem; font-weight:600; cursor:pointer; transition:all .2s; }
        .btn-sm-outline:hover { background:#1e4d6b; color:white; }

        /* sticky save bar */
        .save-bar { position:fixed; bottom:0; left:260px; right:0; background:white; border-top:1px solid #e5e7eb; padding:1rem 2rem; display:flex; align-items:center; justify-content:space-between; gap:1rem; z-index:100; box-shadow:0 -4px 20px rgba(0,0,0,.07); }
        .btn-save { background:#1e4d6b; color:white; border:none; border-radius:10px; padding:.75rem 2rem; font-weight:700; font-size:1rem; cursor:pointer; display:flex; align-items:center; gap:.5rem; transition:background .2s; }
        .btn-save:hover { background:#14394f; }

        /* alerts */
        .alert-success { background:#c8dfe0; border-color:#70a3a7; color:#1a3f42; }
        .alert-danger  { background:#deb8b9; border-color:#c9979a; color:#5c1f22; }

        /* notice */
        .notice { background:#f0e8c0; border:1px solid #d4c870; border-radius:10px; padding:.75rem 1rem; color:#6b5a20; font-size:.88rem; margin-bottom:1rem; }

        @media(max-width:1100px){ .main-content{margin-left:0;} .save-bar{left:0;} }
    </style>
</head>
<body>
<?php include(__DIR__ . "/../includes/admin_sidebar.php"); ?>

<main class="main-content">
    <header class="top-navbar">
        <div>
            <ol class="breadcrumb mb-0">
                <li class="breadcrumb-item"><a href="dashboard.php">首頁</a></li>
                <li class="breadcrumb-item"><a href="field_coordination_mgmt.php">場協管理</a></li>
                <li class="breadcrumb-item active">結果匯入</li>
            </ol>
            <h4 class="mt-2 mb-0">場協結果匯入</h4>
        </div>
    </header>

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

        <!-- 統計 -->
        <div class="stat-row">
            <div class="stat-box stat-conflict"><i class="bi bi-exclamation-triangle"></i>衝突 <?= count($all_conflicting) ?> 筆</div>
            <div class="stat-box stat-pending"><i class="bi bi-hourglass-split"></i>待定 <?= $stat['pending'] ?> 筆</div>
            <div class="stat-box stat-approved"><i class="bi bi-check-circle"></i>已核准 <?= $stat['approved'] ?> 筆</div>
            <div class="stat-box stat-rejected"><i class="bi bi-x-circle"></i>已拒絕 <?= $stat['rejected'] ?> 筆</div>
            <div class="stat-box stat-total">共 <?= count($registrations) ?> 筆</div>
        </div>

        <form method="POST" id="importForm">
            <input type="hidden" name="save_results" value="1">
            <input type="hidden" name="setting_id" value="<?= $selected_setting_id ?>">

            <!-- ── 衝突時段 ── -->
            <?php if (!empty($conflict_groups)): ?>
            <div class="card">
                <div class="card-title">
                    <i class="bi bi-exclamation-triangle-fill" style="color:#c9979a;"></i>
                    衝突需協調（<?= count($conflict_groups) ?> 組）
                </div>
                <div class="notice">
                    <i class="bi bi-info-circle me-1"></i>
                    以下為場協大會中有衝突的時段。請根據實體協調結果，<strong>點選獲得該時段的社團</strong>。
                    選定後，同組其他社團將自動設為「拒絕」。
                </div>

                <?php foreach ($conflict_groups as $gi => $group_ids): ?>
                <?php
                    $group_regs = array_values(array_filter($registrations, function($r) use ($group_ids) {
                        return in_array($r['registration_id'], $group_ids);
                    }));
                    // 找代表時間（取第一筆）
                    $rep = $group_regs[0];
                    $timeStr = date('m/d', strtotime($rep['start_time'])) . '　' .
                               date('H:i', strtotime($rep['start_time'])) . ' – ' .
                               date('H:i', strtotime($rep['end_time']));

                    // 預設 winner（若已有核准紀錄）
                    $defaultWinner = 'none';
                    foreach ($group_regs as $gr) {
                        if ($gr['is_approved'] !== null && (int)$gr['is_approved'] === 1) {
                            $defaultWinner = $gr['registration_id'];
                        }
                    }
                ?>
                <div class="conflict-card">
                    <div class="conflict-header">
                        <span class="conflict-badge">衝突</span>
                        <span class="conflict-time"><i class="bi bi-clock me-1"></i><?= $timeStr ?></span>
                        <span class="conflict-venue"><i class="bi bi-geo-alt me-1"></i><?= htmlspecialchars($rep['space_names'] ?? '場地未定') ?></span>
                    </div>

                    <!-- 傳遞 group members 到後端 -->
                    <?php foreach ($group_ids as $gid): ?>
                    <input type="hidden" name="conflict_group[<?= $gi ?>][]" value="<?= $gid ?>">
                    <?php endforeach; ?>

                    <div class="winner-options">
                        <?php foreach ($group_regs as $gr): ?>
                        <div class="winner-option">
                            <input type="radio"
                                   name="conflict_winner[<?= $gi ?>]"
                                   id="w_<?= $gi ?>_<?= $gr['registration_id'] ?>"
                                   value="<?= $gr['registration_id'] ?>"
                                   <?= $defaultWinner == $gr['registration_id'] ? 'checked' : '' ?>>
                            <label for="w_<?= $gi ?>_<?= $gr['registration_id'] ?>">
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
                                </div>
                            </label>
                        </div>
                        <?php endforeach; ?>

                        <!-- 無社團取得 -->
                        <div class="winner-option" style="flex:0 0 140px;">
                            <input type="radio"
                                   name="conflict_winner[<?= $gi ?>]"
                                   id="w_<?= $gi ?>_none"
                                   value="none"
                                   <?= $defaultWinner === 'none' ? 'checked' : '' ?>>
                            <label for="w_<?= $gi ?>_none" style="height:100%; display:flex; align-items:center; justify-content:center;">
                                <div class="winner-none-label">
                                    <i class="bi bi-x-circle d-block mb-1" style="font-size:1.3rem;"></i>
                                    無社團取得<br>（全部拒絕）
                                </div>
                            </label>
                        </div>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>

            <!-- ── 無衝突登記 ── -->
            <?php if (!empty($clean_regs)): ?>
            <div class="card">
                <div class="card-title">
                    <i class="bi bi-check2-circle" style="color:#70a3a7;"></i>
                    無衝突登記（<?= count($clean_regs) ?> 筆）
                </div>

                <div class="action-bar">
                    <button type="button" class="btn-sm-outline" onclick="setAllClean('approved')">
                        <i class="bi bi-check-all me-1"></i>全部核准
                    </button>
                    <button type="button" class="btn-sm-outline" onclick="setAllClean('rejected')" style="color:#c9979a; border-color:#c9979a;">
                        <i class="bi bi-x-lg me-1"></i>全部拒絕
                    </button>
                    <button type="button" class="btn-sm-outline" onclick="setAllClean('pending')">
                        <i class="bi bi-arrow-counterclockwise me-1"></i>重設
                    </button>
                </div>

                <div style="overflow-x:auto;">
                    <table class="result-table">
                        <thead>
                            <tr>
                                <th>#</th>
                                <th>社團</th>
                                <th>活動名稱</th>
                                <th>場地</th>
                                <th>時段</th>
                                <th>目前狀態</th>
                                <th>結果</th>
                                <th>備註</th>
                            </tr>
                        </thead>
                        <tbody id="cleanBody">
                        <?php foreach ($clean_regs as $idx => $reg):
                            $ap = $reg['is_approved'];
                            $defaultDec = ($ap === null) ? 'pending' : ((int)$ap === 1 ? 'approved' : 'rejected');
                            $rowCls = ($ap === null) ? '' : ((int)$ap === 1 ? 'row-approved' : 'row-rejected');
                            $rid = (int)$reg['registration_id'];
                        ?>
                        <tr class="<?= $rowCls ?>">
                            <td style="color:#9ca3af;"><?= $idx + 1 ?></td>
                            <td style="font-weight:600;"><?= htmlspecialchars($reg['club_name']) ?></td>
                            <td><?= htmlspecialchars($reg['event_name']) ?></td>
                            <td><?= htmlspecialchars($reg['space_names'] ?? '－') ?></td>
                            <td style="white-space:nowrap; font-size:.82rem;">
                                <?= date('m/d', strtotime($reg['start_time'])) ?><br>
                                <?= date('H:i', strtotime($reg['start_time'])) ?>–<?= date('H:i', strtotime($reg['end_time'])) ?>
                            </td>
                            <td>
                                <?php if ($ap === null): ?>
                                    <span class="bdg-pending">待定</span>
                                <?php elseif ((int)$ap === 1): ?>
                                    <span class="bdg-approved">✓ 核准</span>
                                <?php else: ?>
                                    <span class="bdg-rejected">✗ 拒絕</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <div class="chip-group">
                                    <input type="radio" name="decision[<?= $rid ?>]" id="a_<?= $rid ?>" value="approved" <?= $defaultDec==='approved'?'checked':'' ?> onchange="updateCleanRow(this)">
                                    <label for="a_<?= $rid ?>">✓ 核准</label>
                                    <input type="radio" name="decision[<?= $rid ?>]" id="r_<?= $rid ?>" value="rejected" <?= $defaultDec==='rejected'?'checked':'' ?> onchange="updateCleanRow(this)">
                                    <label for="r_<?= $rid ?>">✗ 拒絕</label>
                                    <input type="radio" name="decision[<?= $rid ?>]" id="p_<?= $rid ?>" value="pending" <?= $defaultDec==='pending'?'checked':'' ?> onchange="updateCleanRow(this)">
                                    <label for="p_<?= $rid ?>">— 待定</label>
                                </div>
                            </td>
                            <td>
                                <input type="text" name="note[<?= $rid ?>]"
                                       style="border:1px solid #e5e7eb; border-radius:6px; padding:.28rem .6rem; font-size:.82rem; width:130px;"
                                       placeholder="備註" value="<?= htmlspecialchars($reg['approval_note'] ?? '') ?>">
                            </td>
                        </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
            <?php endif; ?>

            <!-- 儲存列 -->
            <div class="save-bar">
                <div style="font-size:.88rem; color:#6b7280;">
                    <i class="bi bi-info-circle me-1"></i>
                    儲存後即時生效：核准的時段將反映於場地日曆，社團可在申請活動時帶入。
                </div>
                <button type="submit" class="btn-save">
                    <i class="bi bi-cloud-upload"></i> 套用並儲存結果
                </button>
            </div>
        </form>

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
    tr.className = radio.value === 'approved' ? 'row-approved' :
                   radio.value === 'rejected'  ? 'row-rejected' : '';
}

// 無衝突：批次設定
function setAllClean(value) {
    document.querySelectorAll('#cleanBody input[type=radio][value="' + value + '"]').forEach(r => {
        r.checked = true;
        updateCleanRow(r);
    });
}

// 離開前提示
let dirty = false;
document.getElementById('importForm')?.addEventListener('change', () => dirty = true);
document.getElementById('importForm')?.addEventListener('submit', () => dirty = false);
window.addEventListener('beforeunload', e => { if (dirty) { e.preventDefault(); e.returnValue = ''; } });
</script>
</body>
</html>
