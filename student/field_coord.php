<?php
ini_set('display_errors', 1);
error_reporting(E_ALL);

require_once(__DIR__ . "/../DB/db_config.php");
require_once(__DIR__ . "/../includes/FieldCoordinationManager.php");

if (!isset($_SESSION['student_id'])) {
    header('Location: ../login.php');
    exit();
}

// 設置當前頁面用於側邊欄高亮
$current_page = 'field_coord';

// 初始化場協管理器
$fc_manager = new FieldCoordinationManager($conn);
$active_setting = $fc_manager->getActiveSettings();
$is_in_registration_period = $fc_manager->isInRegistrationPeriod();
$has_meeting_passed = $fc_manager->hasCoordinationMeetingPassed();

$message = '';
$message_type = '';
$spaces = [];
$buildings = [
    [
        'id' => 1,
        'name' => 'A焯炤館',
        'rooms' => [
            ['space_id' => 1, 'space_name' => 'A焯炤館', 'capacity' => 0],
            ['space_id' => 2, 'space_name' => 'A焯炤館－四音', 'capacity' => 0],
            ['space_id' => 3, 'space_name' => 'A焯炤館－四康', 'capacity' => 0],
            ['space_id' => 4, 'space_name' => 'A焯炤館－地下演講廳', 'capacity' => 0],
            ['space_id' => 5, 'space_name' => 'A焯炤館－旋律廣場－冷氣損壞', 'capacity' => 0],
            ['space_id' => 6, 'space_name' => 'A焯炤館－夢幻電影院', 'capacity' => 0],
            ['space_id' => 7, 'space_name' => 'A焯炤館－鏡鏡屋', 'capacity' => 0],
        ],
    ],
    [
        'id' => 2,
        'name' => 'B進修部地下室',
        'rooms' => [
            ['space_id' => 8, 'space_name' => 'B進修部地下室教室（一）ES002', 'capacity' => 0],
            ['space_id' => 9, 'space_name' => 'B進修部地下室教室（二）ES003', 'capacity' => 0],
            ['space_id' => 10, 'space_name' => 'B進修部地下室教室（三）ES004', 'capacity' => 0],
            ['space_id' => 11, 'space_name' => 'B進修部地下室教室（四）ES005', 'capacity' => 0],
            ['space_id' => 12, 'space_name' => 'B進修部地下室教室（五）ES006', 'capacity' => 0],
            ['space_id' => 13, 'space_name' => 'B進修部地下室演講廳', 'capacity' => 0],
        ],
    ],
    [
        'id' => 3,
        'name' => 'C仁愛學苑',
        'rooms' => [
            ['space_id' => 14, 'space_name' => 'C仁愛學苑－一樓半空間', 'capacity' => 0],
            ['space_id' => 15, 'space_name' => 'C仁愛學苑－二樓半空間', 'capacity' => 0],
            ['space_id' => 16, 'space_name' => 'C仁愛學苑－三樓半空間', 'capacity' => 0],
        ],
    ],
    [
        'id' => 4,
        'name' => 'D文開區域',
        'rooms' => [
            ['space_id' => 17, 'space_name' => 'D文開地下舞蹈空間中間', 'capacity' => 0],
            ['space_id' => 18, 'space_name' => 'D文開地下舞蹈空間右側（軟墊）', 'capacity' => 0],
            ['space_id' => 19, 'space_name' => 'D文開地下舞蹈空間左側', 'capacity' => 0],
            ['space_id' => 20, 'space_name' => 'D真善美聖廣場', 'capacity' => 0],
        ],
    ],
    [
        'id' => 5,
        'name' => 'E / H 區域',
        'rooms' => [
            ['space_id' => 21, 'space_name' => 'E課指組204會議室', 'capacity' => 0],
            ['space_id' => 22, 'space_name' => 'H校門口左側（AB）', 'capacity' => 0],
            ['space_id' => 23, 'space_name' => 'H校門口左側（CD）', 'capacity' => 0],
        ],
    ],
];

foreach ($buildings as $building) {
    foreach ($building['rooms'] as $room) {
        $spaces[$room['space_id']] = $room;
    }
}

$selected_club_name = $_SESSION['active_club_name'] ?? '';
$user_id = $_SESSION['user_id'] ?? null;
$student_id_value = null;
if ($user_id) {
    $student_id_value = $fc_manager->getStudentIdByUserId($user_id);
    if (!$student_id_value) $student_id_value = $user_id;
}

if (empty($selected_club_name) && $user_id) {
    $club_stmt2 = $conn->prepare("SELECT c.club_name FROM club_members cm JOIN clubs c ON cm.club_id = c.club_id WHERE cm.user_id = ? LIMIT 1");
    if ($club_stmt2) {
        $club_stmt2->bind_param("i", $user_id);
        $club_stmt2->execute();
        $club_result2 = $club_stmt2->get_result();
        if ($club_row2 = $club_result2->fetch_assoc()) $selected_club_name = $club_row2['club_name'];
        $club_stmt2->close();
    }
}

// 場次資料（用於表單還原）
$sessions_data = [['date'=>'','start_time'=>'','end_date'=>'','end_time'=>'','space_id'=>'']];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['register_spaces'])) {
    if (!$is_in_registration_period && $has_meeting_passed) {
        $message_type = 'error';
        $message = '❌ 場地協調大會已結束。此後場地申請採用先到先得制，請改用常規場地申請功能。';
    } elseif (!$active_setting) {
        $message_type = 'error';
        $message = '❌ 目前不在場協登記期間。請等待下一個場協登記期間。';
    } else {
        $event_name           = trim($_POST['event_name'] ?? '');
        $club_name            = trim($_POST['club_name'] ?? $selected_club_name);
        $responsible_person   = trim($_POST['responsible_person'] ?? '');
        $activity_purpose     = trim($_POST['activity_purpose'] ?? '');
        $description          = trim($_POST['description'] ?? '');
        $sessions             = isset($_POST['sessions']) && is_array($_POST['sessions']) ? $_POST['sessions'] : [];
        $acknowledged_conflicts = isset($_POST['acknowledged_conflicts']) ? intval($_POST['acknowledged_conflicts']) : 0;

        // 還原場次以便顯示
        $sessions_data = !empty($sessions) ? $sessions : [['date'=>'','start_time'=>'','end_date'=>'','end_time'=>'','space_id'=>'']];

        $errors = [];
        if (empty($event_name))         $errors[] = '請填寫活動名稱';
        if (empty($club_name))          $errors[] = '請填寫社團名稱';
        if (empty($responsible_person)) $errors[] = '請填寫活動負責人';
        if (empty($sessions))           $errors[] = '請至少新增一個場次';

        foreach ($sessions as $i => $sess) {
            $n = $i + 1;
            if (empty($sess['date']))       $errors[] = "場次{$n}：請選擇開始日期";
            if (empty($sess['start_time'])) $errors[] = "場次{$n}：請填寫開始時間";
            if (empty($sess['end_date']))   $errors[] = "場次{$n}：請選擇結束日期";
            if (empty($sess['end_time']))   $errors[] = "場次{$n}：請填寫結束時間";
            if (empty($sess['space_id']))   $errors[] = "場次{$n}：請選擇場地";
            if (!empty($sess['date']) && !empty($sess['end_date']) && $sess['end_date'] < $sess['date'])
                $errors[] = "場次{$n}：結束日期不能早於開始日期";
            if (!empty($sess['date']) && !empty($sess['end_date']) && $sess['date'] === $sess['end_date'] &&
                !empty($sess['start_time']) && !empty($sess['end_time']) && $sess['start_time'] >= $sess['end_time'])
                $errors[] = "場次{$n}：同日結束時間必須晚於開始時間";
        }

        // 可借用期間驗證
        if (empty($errors) && $active_setting &&
            !empty($active_setting['borrow_start_date']) && !empty($active_setting['borrow_end_date']) &&
            !empty($active_setting['borrow_start_time']) && !empty($active_setting['borrow_end_time'])) {
            $borrow_start_dt   = new DateTime($active_setting['borrow_start_date']);
            $borrow_end_dt     = new DateTime($active_setting['borrow_end_date']);
            $borrow_start_time = date('H:i:s', strtotime($active_setting['borrow_start_time']));
            $borrow_end_time   = date('H:i:s', strtotime($active_setting['borrow_end_time']));
            $borrow_date_range = date('Y-m-d', strtotime($active_setting['borrow_start_date'])) . ' ～ ' . date('Y-m-d', strtotime($active_setting['borrow_end_date']));

            foreach ($sessions as $i => $sess) {
                $n = $i + 1;
                $occ_start = new DateTime($sess['date'] . ' ' . $sess['start_time']);
                $occ_end   = new DateTime(($sess['end_date'] ?? $sess['date']) . ' ' . $sess['end_time']);
                if ($occ_start < $borrow_start_dt || $occ_end > $borrow_end_dt) {
                    $errors[] = "場次{$n}：日期必須在可借用期間 {$borrow_date_range}";
                    break;
                }
                if (date('H:i:s', strtotime($sess['start_time'])) < $borrow_start_time ||
                    date('H:i:s', strtotime($sess['end_time']))   > $borrow_end_time) {
                    $errors[] = "場次{$n}：時段必須在可借用時間 {$borrow_start_time} ～ {$borrow_end_time}";
                    break;
                }
            }
        }

        // 逐場次衝突檢測
        $conflicts_detected = [];
        if (empty($errors) && $is_in_registration_period) {
            foreach ($sessions as $i => $sess) {
                $s = $sess['date'] . ' ' . $sess['start_time'] . ':00';
                $e = ($sess['end_date'] ?? $sess['date']) . ' ' . $sess['end_time'] . ':00';
                $sess_conflicts = $fc_manager->detectFieldCoordinationConflicts(
                    $active_setting['setting_id'],
                    [intval($sess['space_id'])],
                    $s, $e
                );
                foreach ($sess_conflicts as $c) {
                    $c['session_index'] = $i + 1;
                    $conflicts_detected[] = $c;
                }
            }
        }

        if (!empty($conflicts_detected) && !$acknowledged_conflicts) {
            $_SESSION['pending_conflicts'] = $conflicts_detected;
            $_SESSION['pending_form_data'] = $_POST;
            $message_type = 'warning';
            $conflict_text = '';
            foreach ($conflicts_detected as $conflict) {
                $si_label = isset($conflict['session_index']) ? "（場次{$conflict['session_index']}）" : '';
                $conflict_text .= '<br>- ' . htmlspecialchars($conflict['conflicting_club'], ENT_QUOTES, 'UTF-8') .
                    ' 的 "' . htmlspecialchars($conflict['conflicting_event'], ENT_QUOTES, 'UTF-8') .
                    '" (' . htmlspecialchars($conflict['conflicting_time'], ENT_QUOTES, 'UTF-8') . ')' . $si_label;
            }
            $message = '⚠️ 檢測到場地衝突，請確認是否繼續提交：' . $conflict_text;
        } elseif (empty($errors)) {
            // 計算整體 start/end
            $event_start = null;
            $event_end   = null;
            foreach ($sessions as $sess) {
                $s = $sess['date'] . ' ' . $sess['start_time'] . ':00';
                $e = ($sess['end_date'] ?? $sess['date']) . ' ' . $sess['end_time'] . ':00';
                if ($event_start === null || $s < $event_start) $event_start = $s;
                if ($event_end   === null || $e > $event_end)   $event_end   = $e;
            }

            $conn->begin_transaction();
            try {
                $stmt_event = $conn->prepare(
                    "INSERT INTO events (user_id, event_name, club_name, description, start_time, end_time, status, is_field_coordination, field_coordination_setting_id, responsible_person)
                     VALUES (?, ?, ?, ?, ?, ?, 'pending', 1, ?, ?)"
                );
                if (!$user_id) throw new Exception('尚未取得使用者識別碼，請重新登入。');

                $description_lines = ['場地協調'];
                if (!empty($activity_purpose)) $description_lines[] = "用途：{$activity_purpose}";
                if (!empty($description))       $description_lines[] = "備註：{$description}";
                $full_description = implode("\n", $description_lines);

                $setting_id = $active_setting['setting_id'];
                $stmt_event->bind_param('isssssis', $user_id, $event_name, $club_name, $full_description, $event_start, $event_end, $setting_id, $responsible_person);
                if (!$stmt_event->execute()) throw new Exception('建立活動記錄失敗：' . $stmt_event->error);
                $event_id = $conn->insert_id;
                $stmt_event->close();

                $stmt_reserve = $conn->prepare(
                    "INSERT INTO reservations (event_id, space_id, start_time, end_time, is_field_coordination_preliminary)
                     VALUES (?, ?, ?, ?, 1)"
                );
                foreach ($sessions as $sess) {
                    $space_id  = intval($sess['space_id']);
                    $res_start = $sess['date'] . ' ' . $sess['start_time'] . ':00';
                    $res_end   = ($sess['end_date'] ?? $sess['date']) . ' ' . $sess['end_time'] . ':00';
                    $stmt_reserve->bind_param('iiss', $event_id, $space_id, $res_start, $res_end);
                    if (!$stmt_reserve->execute()) throw new Exception('場地登記失敗：' . $stmt_reserve->error);
                }
                $stmt_reserve->close();

                // 取得社團 ID
                $cid_stmt = $conn->prepare("SELECT club_id FROM clubs WHERE club_name = ? LIMIT 1");
                $cid_stmt->bind_param("s", $club_name);
                $cid_stmt->execute();
                $cid_result = $cid_stmt->get_result();
                $club_id = ($cid_row = $cid_result->fetch_assoc()) ? $cid_row['club_id'] : 0;
                $cid_stmt->close();

                $fc_manager->createFieldCoordinationRegistration(
                    $active_setting['setting_id'], $event_id, $student_id_value, $club_id, $club_name
                );

                $conn->commit();
                $message_type = 'success';
                $message = '✅ 場地協調登記已送出，申請編號：#' . $event_id . '，共 ' . count($sessions) . ' 個場次。';
                if (!empty($conflicts_detected))
                    $message .= '該申請包含 ' . count($conflicts_detected) . ' 個場地衝突，管理員將於協調大會時協調。';
                unset($_SESSION['pending_conflicts'], $_SESSION['pending_form_data']);
                $_POST = [];
                $sessions_data = [['date'=>'','start_time'=>'','end_date'=>'','end_time'=>'','space_id'=>'']];
            } catch (Exception $e) {
                $conn->rollback();
                $message_type = 'error';
                $message = '❌ 登記失敗：' . $e->getMessage();
            }
        } else {
            $message_type = 'error';
            $message = '❌ ' . implode('<br>', $errors);
        }
    }
}

// 場地資料給 JS 用（按大樓分組）
$spaces_for_js = [];
foreach ($buildings as $building) {
    $grp = ['label' => $building['name'], 'options' => []];
    foreach ($building['rooms'] as $r) {
        $grp['options'][] = ['id' => $r['space_id'], 'name' => $r['space_name']];
    }
    $spaces_for_js[] = $grp;
}
?>

<!DOCTYPE html>
<html lang="zh-TW">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>場地協調 - 輔仁大學課外活動指導組</title>

    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.5/font/bootstrap-icons.css" rel="stylesheet">

    <style>
        :root {
            --primary: #1e4d6b;
            --sidebar: #14394f;
            --sidebar-hover: #ece8dd;
            --bg: #f7f5ef;
            --card: #ffffff;
        }
        * { box-sizing: border-box; }
        body {
            margin: 0;
            min-height: 100vh;
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background: var(--bg);
            color: #1f2937;
        }
        .sidebar {
            position: fixed;
            top: 0;
            left: 0;
            width: 260px;
            height: 100vh;
            background: var(--primary);
            color: white;
            padding: 1.5rem 0.8rem;
            overflow-y: auto;
            box-shadow: 3px 0 15px rgba(0,0,0,0.12);
            z-index: 1200;
        }
        .sidebar .brand {
            text-align: center;
            margin-bottom: 1.5rem;
        }
        .sidebar .brand h4 {
            margin: 0;
            font-size: 1.1rem;
            line-height: 1.4;
            font-weight: 700;
        }
        .sidebar .nav-link {
            display: flex;
            align-items: center;
            gap: 0.75rem;
            color: rgba(255,255,255,0.9);
            padding: 0.85rem 1rem;
            margin: 0.2rem 0;
            border-radius: 16px;
            transition: background 0.25s ease, transform 0.15s ease;
        }
        .sidebar .nav-link:hover,
        .sidebar .nav-link.active {
            background: #ece8dd;
            color: #1e4d6b;
            transform: translateX(4px);
        }
        .sidebar .nav-link i { font-size: 1.1rem; }
        .sidebar .sidebar-section {
            padding: 1rem 0.5rem;
            margin-top: 1.5rem;
            border-top: 1px solid rgba(255,255,255,0.12);
        }
        .main-content {
            margin-left: 260px;
            min-height: 100vh;
            transition: margin-left 0.25s ease;
        }
        .top-navbar {
            background: #d5e3ea;
            border-bottom: 1px solid #bdd0d9;
            padding: 1rem 2rem;
            display: flex;
            justify-content: space-between;
            align-items: center;
            position: sticky;
            top: 0;
            z-index: 1100;
        }
        .top-navbar .breadcrumb {
            margin: 0;
            background: transparent;
            padding: 0;
        }
        .top-navbar .breadcrumb { font-size: 0.8rem; }
        .top-navbar .breadcrumb-item + .breadcrumb-item::before { content: '›'; font-size: 1rem; color: #c9d0d8; }
        .top-navbar .breadcrumb-item a { color: #1e4d6b; text-decoration: none; opacity: 0.75; }
        .top-navbar .breadcrumb-item a:hover { opacity: 1; }
        .top-navbar .breadcrumb-item.active { color: #6b7280; }
        .content-wrapper {
            padding: 1.5rem 2rem 2rem;
        }
        .card {
            background: var(--card);
            border-radius: 18px;
            box-shadow: 0 10px 30px rgba(15,23,42,0.06);
            padding: 1.5rem;
            margin-bottom: 1.5rem;
        }
        .card h3 {
            margin-bottom: 1rem;
            font-weight: 700;
            color: var(--primary);
        }
        .service-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
            gap: 1.5rem;
        }
        .service-card {
            background: white;
            border: 1px solid #e5e7eb;
            border-radius: 12px;
            padding: 1.5rem;
            transition: box-shadow 0.25s ease, transform 0.15s ease;
            text-align: center;
        }
        .service-card:hover {
            box-shadow: 0 8px 25px rgba(0,0,0,0.1);
            transform: translateY(-2px);
        }
        .service-icon {
            width: 60px;
            height: 60px;
            border-radius: 50%;
            background: var(--primary);
            color: white;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.8rem;
            margin: 0 auto 1rem;
        }
        .service-card h4 {
            margin-bottom: 0.5rem;
            font-weight: 600;
        }
        .service-card p {
            color: #6b7280;
            margin-bottom: 1rem;
        }
        .btn-service {
            background: var(--primary);
            color: white;
            border: none;
            padding: 0.5rem 1.5rem;
            border-radius: 8px;
            font-weight: 600;
            cursor: pointer;
            transition: background 0.25s ease;
        }
        .btn-service:hover {
            background: var(--sidebar);
        }
        .contact-info {
            background: #f8f9fa;
            border-radius: 12px;
            padding: 1.5rem;
            margin-top: 1rem;
        }
        .contact-item {
            display: flex;
            align-items: center;
            gap: 0.75rem;
            margin-bottom: 0.75rem;
        }
        .contact-item:last-child {
            margin-bottom: 0;
        }
        .contact-item i {
            color: var(--primary);
            font-size: 1.2rem;
        }
        @media (max-width: 1100px) {
            .service-grid { grid-template-columns: 1fr; }
            .main-content { margin-left: 0; }
        }
        @media (max-width: 768px) {
        .top-navbar {
                flex-direction: column;
                align-items: flex-start;
                gap: 1rem;
                padding: 1rem;
            }
            .sidebar {
                position: relative;
                width: 100%;
                height: auto;
                box-shadow: none;
            }
        }
    
        /* 提示訊息配色 */
        .alert-success { background: #c8dfe0; border-color: #70a3a7; color: #1a3f42; }
        .alert-warning { background: #ede4e5; border-color: #deb8b9; color: #6b2d2d; }
        .alert-danger  { background: #deb8b9; border-color: #c9979a; color: #5c1f22; }
        .alert-info    { background: #ede4e5; border-color: #c8c0c2; color: #5a3f42; }
        /* 場次卡片 */
        .session-row { border:1px solid #e2e8f0; border-radius:14px; padding:1.1rem 1.25rem; margin-bottom:0.85rem; background:white; transition:box-shadow 0.2s,border-color 0.2s; }
        .session-row:hover { box-shadow:0 4px 14px rgba(30,77,107,0.09); border-color:#c7d6df; }
        .session-header { display:flex; justify-content:space-between; align-items:center; margin-bottom:0.8rem; }
        .session-label { font-weight:700; color:var(--primary); font-size:0.92rem; display:flex; align-items:center; gap:0.4rem; }
        .btn-remove-session { background:none; border:1px solid #fca5a5; color:#ef4444; border-radius:6px; padding:0.2rem 0.55rem; cursor:pointer; font-size:0.8rem; transition:all 0.2s; display:inline-flex; align-items:center; gap:0.3rem; }
        .btn-remove-session:hover { background:#fee2e2; }
        .session-fields { display:grid; grid-template-columns:1.2fr 1fr 1.2fr 1fr 2fr; gap:0.65rem; align-items:end; }
        .session-field label { display:block; font-size:0.8rem; color:#6b7280; margin-bottom:0.25rem; font-weight:500; }
        .btn-add-session { display:flex; align-items:center; justify-content:center; gap:0.5rem; width:100%; border:2px dashed #c8d6df; border-radius:12px; padding:0.75rem; background:white; color:var(--primary); font-weight:600; font-size:0.92rem; cursor:pointer; transition:all 0.2s; margin-top:0.25rem; }
        .btn-add-session:hover { border-color:var(--primary); background:rgba(30,77,107,0.04); }
        @media (max-width:960px) { .session-fields { grid-template-columns:1fr 1fr 1fr; } .session-fields .session-field:nth-child(4), .session-fields .session-field:nth-child(5) { grid-column:1/-1; } }
        @media (max-width:600px) { .session-fields { grid-template-columns:1fr; } }
    </style>
</head>
<body>
    <?php include(__DIR__ . "/../includes/sidebar.php"); ?>

    <main class="main-content">
        <header class="top-navbar">
            <div>
                <ol class="breadcrumb mb-0">
                    <li class="breadcrumb-item"><a href="dashboard.php">首頁</a></li>
                    <li class="breadcrumb-item active" aria-current="page">場地協調</li>
                </ol>
                <h4 class="mt-2 mb-0">場地協調</h4>
            </div>
        </header>

        <section class="content-wrapper">
            <div class="card">
                <h3>場地協調登記</h3>
                <p class="text-muted">本頁提供社團幹部一次選擇多個場地、設定固定週次的例行活動登記。</p>
            </div>

            <!-- 場協狀態提示 -->
            <?php if (!$active_setting): ?>
            <div class="card" style="border-left: 5px solid #f59e0b;">
                <h3><i class="bi bi-info-circle"></i> 場協登記未開放</h3>
                <p class="text-muted mb-0">目前不在場協登記期間。系統將於學期開始前開放場協登記。</p>
            </div>
            <?php elseif ($has_meeting_passed): ?>
            <div class="card" style="border-left: 5px solid #f59e0b;">
                <h3><i class="bi bi-info-circle"></i> 場協大會已結束</h3>
                <p class="text-muted mb-0">場地協調大會已於 <?= date('Y-m-d', strtotime($active_setting['coordination_meeting_date'])) ?> 進行。此後場地申請採用先到先得制，請改用常規場地申請功能。</p>
            </div>
            <?php else: ?>
            <div class="card" style="border-left: 5px solid #10b981;">
                <h3><i class="bi bi-check-circle"></i> 場協登記開放中</h3>
                <p class="text-muted mb-1"><strong>登記期限：</strong><?= date('Y-m-d', strtotime($active_setting['registration_start_date'])) ?> ~ <?= date('Y-m-d', strtotime($active_setting['registration_end_date'])) ?></p>
                <p class="text-muted mb-0"><strong>協調大會：</strong><?= date('Y-m-d H:i', strtotime($active_setting['coordination_meeting_date'])) ?></p>
            </div>
            <?php endif; ?>

            <?php if (!empty($message)): ?>
            <div class="card" style="border-left: 5px solid <?= $message_type === 'success' ? '#10b981' : ($message_type === 'warning' ? '#f59e0b' : '#ef4444'); ?>;">
                <h3><?= $message_type === 'success' ? '登記成功' : ($message_type === 'warning' ? '衝突提示' : '錯誤提醒') ?></h3>
                <p class="text-muted mb-0"><?= $message ?></p>
            </div>
            <?php endif; ?>

            <!-- 顯示待確認的衝突 -->
            <?php if (isset($_SESSION['pending_conflicts']) && !empty($_SESSION['pending_conflicts'])): ?>
            <div class="card" style="border: 2px solid #f59e0b; background: #fffbf0;">
                <h3><i class="bi bi-exclamation-triangle"></i> 請確認衝突</h3>
                <p class="text-muted">您的場地申請與以下活動存在時間衝突。請確認是否繼續提交：</p>
                <div style="background: white; border-radius: 8px; padding: 1rem; margin-bottom: 1rem;">
                    <?php foreach ($_SESSION['pending_conflicts'] as $conflict): ?>
                    <div style="padding: 0.5rem 0; border-bottom: 1px solid #e5e7eb;">
                        <p class="mb-1">
                            <strong><?= htmlspecialchars($conflict['conflicting_club'], ENT_QUOTES, 'UTF-8') ?></strong> - 
                            <?= htmlspecialchars($conflict['conflicting_event'], ENT_QUOTES, 'UTF-8') ?><br>
                            <small class="text-muted">場地：<?= htmlspecialchars($spaces[$conflict['space_id']]['space_name'] ?? '未知', ENT_QUOTES, 'UTF-8') ?></small><br>
                            <small class="text-muted">時間：<?= htmlspecialchars($conflict['conflicting_time'], ENT_QUOTES, 'UTF-8') ?></small>
                        </p>
                    </div>
                    <?php endforeach; ?>
                </div>
                <form method="post" id="conflict_form">
                    <input type="hidden" name="acknowledged_conflicts" value="1">
                    <?php foreach ($_SESSION['pending_form_data'] as $key => $value): ?>
                        <?php if ($key === 'sessions' && is_array($value)): ?>
                            <?php foreach ($value as $si => $sess): ?>
                                <?php if (is_array($sess)): ?>
                                    <?php foreach ($sess as $field => $fval): ?>
                                    <input type="hidden" name="sessions[<?= $si ?>][<?= htmlspecialchars($field, ENT_QUOTES, 'UTF-8') ?>]" value="<?= htmlspecialchars($fval, ENT_QUOTES, 'UTF-8') ?>">
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            <?php endforeach; ?>
                        <?php elseif (is_array($value)): ?>
                            <?php foreach ($value as $v): ?>
                            <input type="hidden" name="<?= htmlspecialchars($key, ENT_QUOTES, 'UTF-8') ?>[]" value="<?= htmlspecialchars($v, ENT_QUOTES, 'UTF-8') ?>">
                            <?php endforeach; ?>
                        <?php else: ?>
                            <input type="hidden" name="<?= htmlspecialchars($key, ENT_QUOTES, 'UTF-8') ?>" value="<?= htmlspecialchars($value, ENT_QUOTES, 'UTF-8') ?>">
                        <?php endif; ?>
                    <?php endforeach; ?>
                    <div class="d-flex gap-2">
                        <button type="submit" class="btn btn-warning">繼續提交（確認衝突）</button>
                        <button type="button" class="btn btn-secondary" onclick="location.reload();">取消修改</button>
                    </div>
                </form>
            </div>
            <?php endif; ?>

            <div class="card">
                <h3><i class="bi bi-calendar-check"></i> 場地登記日曆</h3>
                <p class="text-muted">按下按鈕查看每棟大樓與空間的登記狀況，避免與現有活動衝突。</p>
                <button class="btn-service" onclick="location.href='calendar.php'">查看場地日曆</button>
            </div>

            <div class="card">
                <h3><i class="bi bi-grid-1x2"></i> 批次場地協調登記</h3>
                <p class="text-muted">一次選擇多個教室，並支援固定週次的例行練習或活動登記。</p>
                <?php if (!$active_setting || $has_meeting_passed): ?>
                <div class="alert alert-warning">
                    <i class="bi bi-info-circle"></i> 
                    <?php if (!$active_setting): ?>
                    目前不在場協登記期間，表單已禁用。
                    <?php else: ?>
                    場地協調大會已結束，場地申請已恢復至先到先得制。
                    <?php endif; ?>
                </div>
                <?php endif; ?>
                <form id="reg_form" method="post" <?php if (!$active_setting || $has_meeting_passed) echo 'style="opacity: 0.5; pointer-events: none;"'; ?>>
                    <input type="hidden" name="register_spaces" value="1">
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label" for="club_name">主辦社團 *</label>
                            <input id="club_name" name="club_name" class="form-control" value="<?= htmlspecialchars($_SESSION['pending_form_data']['club_name'] ?? $_POST['club_name'] ?? $selected_club_name, ENT_QUOTES, 'UTF-8') ?>" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label" for="event_name">活動名稱 *</label>
                            <input id="event_name" name="event_name" class="form-control" value="<?= htmlspecialchars($_SESSION['pending_form_data']['event_name'] ?? $_POST['event_name'] ?? '', ENT_QUOTES, 'UTF-8') ?>" required>
                        </div>
                    </div>
                    <div class="mt-3">
                        <label class="form-label" for="responsible_person">活動負責人 *</label>
                        <input id="responsible_person" name="responsible_person" class="form-control" value="<?= htmlspecialchars($_SESSION['pending_form_data']['responsible_person'] ?? $_POST['responsible_person'] ?? '', ENT_QUOTES, 'UTF-8') ?>" placeholder="負責人姓名（例：社長 王小明）" required>
                    </div>
                    <div class="mt-3">
                        <label class="form-label" for="activity_purpose">場地用途</label>
                        <input id="activity_purpose" name="activity_purpose" class="form-control" value="<?= htmlspecialchars($_SESSION['pending_form_data']['activity_purpose'] ?? $_POST['activity_purpose'] ?? '', ENT_QUOTES, 'UTF-8') ?>" placeholder="例如：熱舞社練習、比賽排練、社團會議">
                    </div>
                    <!-- 活動場次 -->
                    <div class="mt-4">
                        <label class="form-label fw-semibold">活動場次 <span class="text-danger">*</span></label>
                        <p class="text-muted" style="font-size:0.9rem; margin-bottom:0.75rem;">每個場次可設定不同日期、時間與場地。若有固定週次練習，請逐一新增場次。</p>
                        <div id="sessions_container">
                            <?php foreach ($sessions_data as $si => $sess): ?>
                            <div class="session-row" data-idx="<?= $si ?>">
                                <div class="session-header">
                                    <span class="session-label"><i class="bi bi-calendar3"></i> 場次 <?= $si + 1 ?></span>
                                    <button type="button" class="btn-remove-session" onclick="removeSession(this)" style="display:<?= count($sessions_data) > 1 ? 'inline-flex' : 'none' ?>;">
                                        <i class="bi bi-trash"></i> 刪除
                                    </button>
                                </div>
                                <div class="session-fields">
                                    <div class="session-field">
                                        <label>開始日期 *</label>
                                        <input type="date" name="sessions[<?= $si ?>][date]" class="form-control" value="<?= htmlspecialchars($sess['date'] ?? '', ENT_QUOTES, 'UTF-8') ?>" required>
                                    </div>
                                    <div class="session-field">
                                        <label>開始時間 *</label>
                                        <input type="time" name="sessions[<?= $si ?>][start_time]" class="form-control" value="<?= htmlspecialchars($sess['start_time'] ?? '', ENT_QUOTES, 'UTF-8') ?>" required>
                                    </div>
                                    <div class="session-field">
                                        <label>結束日期 *</label>
                                        <input type="date" name="sessions[<?= $si ?>][end_date]" class="form-control" value="<?= htmlspecialchars($sess['end_date'] ?? $sess['date'] ?? '', ENT_QUOTES, 'UTF-8') ?>" required>
                                    </div>
                                    <div class="session-field">
                                        <label>結束時間 *</label>
                                        <input type="time" name="sessions[<?= $si ?>][end_time]" class="form-control" value="<?= htmlspecialchars($sess['end_time'] ?? '', ENT_QUOTES, 'UTF-8') ?>" required>
                                    </div>
                                    <div class="session-field">
                                        <label>場地 *</label>
                                        <select name="sessions[<?= $si ?>][space_id]" class="form-control" required>
                                            <option value="">-- 選擇場地 --</option>
                                            <?php foreach ($buildings as $building): ?>
                                            <optgroup label="<?= htmlspecialchars($building['name'], ENT_QUOTES, 'UTF-8') ?>">
                                                <?php foreach ($building['rooms'] as $r): ?>
                                                <option value="<?= $r['space_id'] ?>" <?= ($sess['space_id'] ?? '') == $r['space_id'] ? 'selected' : '' ?>><?= htmlspecialchars($r['space_name'], ENT_QUOTES, 'UTF-8') ?></option>
                                                <?php endforeach; ?>
                                            </optgroup>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                </div>
                            </div>
                            <?php endforeach; ?>
                        </div>
                        <button type="button" class="btn-add-session" onclick="addSession()">
                            <i class="bi bi-plus-circle"></i> 新增場次
                        </button>
                    </div>
                    <div class="mt-3">
                        <label class="form-label" for="description">備註</label>
                        <textarea id="description" name="description" class="form-control" rows="3"><?= htmlspecialchars($_SESSION['pending_form_data']['description'] ?? $_POST['description'] ?? '', ENT_QUOTES, 'UTF-8') ?></textarea>
                    </div>
                    <div class="mt-4 d-flex flex-wrap gap-2">
                        <button type="submit" class="btn btn-primary" <?php if (!$active_setting || $has_meeting_passed) echo 'disabled'; ?>>提交批次登記</button>
                        <button type="button" class="btn btn-secondary" onclick="location.href='calendar.php'">先查看日曆</button>
                    </div>
                    <p class="mt-3 text-muted" style="font-size:0.95rem;">* 若您是社團幹部，系統會自動帶入您目前身份對應的社團。</p>
                </form>
            </div>

            <div class="card">
                <h3>聯絡資訊</h3>
                <div class="contact-info">
                    <div class="contact-item">
                        <i class="bi bi-telephone"></i>
                        <div>
                            <strong>輔仁大學課外活動指導組 張秉倪輔導老師</strong><br>
                            電話：(02) 2905-2233 轉 3085
                        </div>
                    </div>
                    <div class="contact-item">
                        <i class="bi bi-envelope"></i>
                        <div>
                            <strong>電子郵件</strong><br>
                            163341@mail.fju.edu.tw
                        </div>
                    </div>
                    <div class="contact-item">
                        <i class="bi bi-clock"></i>
                        <div>
                            <strong>服務時間</strong><br>
                            週一至週五 08:00-16:30
                        </div>
                    </div>
                    <div class="contact-item">
                        <i class="bi bi-geo-alt"></i>
                        <div>
                            <strong>辦公室位置</strong><br>
                            輔仁大學 課外活動指導組(法籃旁)
                        </div>
                    </div>
                </div>
            </div>
        </section>
    </main>

    <script>
    // ── 場地資料（按大樓分組）────────────────────────────────
    var spacesForJs = <?= json_encode($spaces_for_js, JSON_UNESCAPED_UNICODE) ?>;
    var sessionCount = <?= count($sessions_data) ?>;

    function escHtml(str) {
        return String(str)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;');
    }

    function buildSpaceOptions(selectedId) {
        var html = '<option value="">-- 選擇場地 --</option>';
        spacesForJs.forEach(function(grp) {
            html += '<optgroup label="' + escHtml(grp.label) + '">';
            grp.options.forEach(function(opt) {
                var sel = (selectedId && String(opt.id) === String(selectedId)) ? ' selected' : '';
                html += '<option value="' + opt.id + '"' + sel + '>' + escHtml(opt.name) + '</option>';
            });
            html += '</optgroup>';
        });
        return html;
    }

    // ── 場次新增 / 刪除 / 重新編號 ──────────────────────────
    function addSession() {
        var idx = sessionCount;
        sessionCount++;
        var html = '<div class="session-row" data-idx="' + idx + '">'
            + '<div class="session-header">'
            +   '<span class="session-label"><i class="bi bi-calendar3"></i> 場次</span>'
            +   '<button type="button" class="btn-remove-session" onclick="removeSession(this)">'
            +     '<i class="bi bi-trash"></i> 刪除'
            +   '</button>'
            + '</div>'
            + '<div class="session-fields">'
            +   '<div class="session-field">'
            +     '<label>開始日期 *</label>'
            +     '<input type="date" name="sessions[' + idx + '][date]" class="form-control" required>'
            +   '</div>'
            +   '<div class="session-field">'
            +     '<label>開始時間 *</label>'
            +     '<input type="time" name="sessions[' + idx + '][start_time]" class="form-control" required>'
            +   '</div>'
            +   '<div class="session-field">'
            +     '<label>結束日期 *</label>'
            +     '<input type="date" name="sessions[' + idx + '][end_date]" class="form-control" required>'
            +   '</div>'
            +   '<div class="session-field">'
            +     '<label>結束時間 *</label>'
            +     '<input type="time" name="sessions[' + idx + '][end_time]" class="form-control" required>'
            +   '</div>'
            +   '<div class="session-field">'
            +     '<label>場地 *</label>'
            +     '<select name="sessions[' + idx + '][space_id]" class="form-control" required>'
            +       buildSpaceOptions('')
            +     '</select>'
            +   '</div>'
            + '</div>'
            + '</div>';
        document.getElementById('sessions_container').insertAdjacentHTML('beforeend', html);
        renumberSessions();
    }

    function removeSession(btn) {
        var rows = document.querySelectorAll('.session-row');
        if (rows.length <= 1) { alert('至少需要保留一個場次！'); return; }
        btn.closest('.session-row').remove();
        renumberSessions();
    }

    function renumberSessions() {
        var rows = document.querySelectorAll('.session-row');
        rows.forEach(function(row, i) {
            row.dataset.idx = i;
            row.querySelector('.session-label').innerHTML = '<i class="bi bi-calendar3"></i> 場次 ' + (i + 1);
            row.querySelectorAll('input[name], select[name]').forEach(function(el) {
                el.name = el.name.replace(/sessions\[\d+\]/, 'sessions[' + i + ']');
            });
            var removeBtn = row.querySelector('.btn-remove-session');
            if (removeBtn) removeBtn.style.display = rows.length > 1 ? 'inline-flex' : 'none';
        });
        sessionCount = rows.length;
    }

    // ── 開始日期自動同步至結束日期 ───────────────────────────
    document.getElementById('sessions_container').addEventListener('change', function(e) {
        if (!e.target) return;
        if (e.target.name && e.target.name.indexOf('[date]') !== -1) {
            // 當開始日期變更時，若結束日期為空則自動填入相同日期
            var row = e.target.closest('.session-row');
            if (row) {
                var endDateInput = row.querySelector('[name*="[end_date]"]');
                if (endDateInput && !endDateInput.value) {
                    endDateInput.value = e.target.value;
                }
            }
        }
    });

    // ── 表單提交驗證 ─────────────────────────────────────────
    var regForm = document.getElementById('reg_form');
    if (regForm) {
        regForm.addEventListener('submit', function(e) {
            var rows = document.querySelectorAll('.session-row');
            if (rows.length === 0) {
                e.preventDefault(); alert('請至少新增一個場次！'); return;
            }
            for (var i = 0; i < rows.length; i++) {
                var n   = i + 1;
                var di  = rows[i].querySelector('[name*="[date]"]');
                var sti = rows[i].querySelector('[name*="[start_time]"]');
                var edi = rows[i].querySelector('[name*="[end_date]"]');
                var eti = rows[i].querySelector('[name*="[end_time]"]');
                var spi = rows[i].querySelector('[name*="[space_id]"]');
                if (!di  || !di.value)  { e.preventDefault(); alert('場次' + n + '：請選擇開始日期！');  di.focus();  return; }
                if (!sti || !sti.value) { e.preventDefault(); alert('場次' + n + '：請填寫開始時間！'); sti.focus(); return; }
                if (!edi || !edi.value) { e.preventDefault(); alert('場次' + n + '：請選擇結束日期！');  edi.focus(); return; }
                if (!eti || !eti.value) { e.preventDefault(); alert('場次' + n + '：請填寫結束時間！'); eti.focus(); return; }
                if (edi.value < di.value) {
                    e.preventDefault(); alert('場次' + n + '：結束日期不能早於開始日期！'); return;
                }
                if (edi.value === di.value && sti.value >= eti.value) {
                    e.preventDefault(); alert('場次' + n + '：同日結束時間必須晚於開始時間！'); return;
                }
                if (spi && !spi.value) { e.preventDefault(); alert('場次' + n + '：請選擇場地！'); spi.focus(); return; }
            }
        });
    }
    </script>
</body>
</html>