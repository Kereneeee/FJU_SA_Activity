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
    if (!$student_id_value) {
        $student_id_value = $user_id;
    }
}

if (empty($selected_club_name) && $user_id) {
    $club_sql = "SELECT c.club_name
                 FROM club_members cm
                 JOIN clubs c ON cm.club_id = c.club_id
                 WHERE cm.user_id = ?
                 LIMIT 1";
    $club_stmt = $conn->prepare($club_sql);
    if ($club_stmt) {
        $club_stmt->bind_param("i", $user_id);
        $club_stmt->execute();
        $club_result = $club_stmt->get_result();
        if ($club_row = $club_result->fetch_assoc()) {
            $selected_club_name = $club_row['club_name'];
        }
        $club_stmt->close();
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['register_spaces'])) {
    // 檢查是否已過協調大會（在非場協期間）
    if (!$is_in_registration_period && $has_meeting_passed) {
        $message_type = 'error';
        $message = '❌ 場地協調大會已結束。此後場地申請採用先到先得制，請改用常規場地申請功能。';
    } else if (!$active_setting) {
        $message_type = 'error';
        $message = '❌ 目前不在場協登記期間。請等待下一個場協登記期間。';
    } else {
        $event_name = trim($_POST['event_name'] ?? '');
        $club_name = trim($_POST['club_name'] ?? $selected_club_name);
        $activity_purpose = trim($_POST['activity_purpose'] ?? '');
        $event_date = trim($_POST['event_date'] ?? '');
        $start_time = trim($_POST['start_time'] ?? '');
        $end_time = trim($_POST['end_time'] ?? '');
        $repeat_type = $_POST['repeat_type'] ?? 'none';
        $repeat_weekday = $_POST['repeat_weekday'] ?? '';
        $repeat_weeks = intval($_POST['repeat_weeks'] ?? 1);
        $space_ids = $_POST['space_ids'] ?? [];
        $description = trim($_POST['description'] ?? '');
        $acknowledged_conflicts = isset($_POST['acknowledged_conflicts']) ? intval($_POST['acknowledged_conflicts']) : 0;

        $errors = [];
        if (empty($event_name)) {
            $errors[] = '請填寫活動名稱';
        }
        if (empty($club_name)) {
            $errors[] = '請填寫社團名稱';
        }
        if (empty($event_date)) {
            $errors[] = '請選擇活動日期';
        }
        if (empty($start_time) || empty($end_time)) {
            $errors[] = '請選擇完整的開始與結束時間';
        }
        if ($start_time >= $end_time) {
            $errors[] = '結束時間必須晚於開始時間';
        }
        if (empty($space_ids) || !is_array($space_ids)) {
            $errors[] = '請至少選擇一個場地';
        }
        if ($repeat_type === 'weekly') {
            if ($repeat_weekday === '') {
                $errors[] = '請選擇每週重複的星期日';
            }
            if ($repeat_weeks < 1) {
                $errors[] = '請輸入正確的重複週數';
            }
        }

        // 先計算登記事件的所有發生日期，用於後續驗證與衝突檢查
        $occurrence_dates = [$event_date];
        if ($repeat_type === 'weekly') {
            $weekday = intval($repeat_weekday);
            $start_date_obj = new DateTime($event_date);
            $start_weekday = intval($start_date_obj->format('N')) - 1;
            $days_until = ($weekday - $start_weekday + 7) % 7;
            $first_date = clone $start_date_obj;
            if ($days_until > 0) {
                $first_date->modify("+{$days_until} days");
            }
            $occurrence_dates = [];
            for ($i = 0; $i < $repeat_weeks; $i++) {
                $date = clone $first_date;
                if ($i > 0) {
                    $date->modify("+{$i} week");
                }
                $occurrence_dates[] = $date->format('Y-m-d');
            }
        }

        if (empty($errors) && $active_setting && !empty($active_setting['borrow_start_date']) && !empty($active_setting['borrow_end_date']) && !empty($active_setting['borrow_start_time']) && !empty($active_setting['borrow_end_time'])) {
            $borrow_start_dt = new DateTime($active_setting['borrow_start_date']);
            $borrow_end_dt = new DateTime($active_setting['borrow_end_date']);
            $borrow_start_time = date('H:i:s', strtotime($active_setting['borrow_start_time']));
            $borrow_end_time = date('H:i:s', strtotime($active_setting['borrow_end_time']));

            foreach ($occurrence_dates as $occurrence_date_item) {
                $occ_start = new DateTime($occurrence_date_item . ' ' . $start_time);
                $occ_end = new DateTime($occurrence_date_item . ' ' . $end_time);

                if ($occ_start < $borrow_start_dt || $occ_end > $borrow_end_dt) {
                    $errors[] = '❌ 活動日期必須在可借用期間內：' . date('Y-m-d', strtotime($active_setting['borrow_start_date'])) . ' ～ ' . date('Y-m-d', strtotime($active_setting['borrow_end_date']));
                    break;
                }
                if (date('H:i:s', strtotime($start_time)) < $borrow_start_time || date('H:i:s', strtotime($end_time)) > $borrow_end_time) {
                    $errors[] = '❌ 活動時段必須在可借用時間內：' . $borrow_start_time . ' ～ ' . $borrow_end_time;
                    break;
                }
            }
        }

        // 在場協期間檢測衝突
        $conflicts_detected = [];
        if (empty($errors) && $is_in_registration_period) {
            $event_start = $event_date . ' ' . $start_time . ':00';
            $event_end = $event_date . ' ' . $end_time . ':00';
            $conflicts_detected = $fc_manager->detectFieldCoordinationConflicts(
                $active_setting['setting_id'], 
                $space_ids, 
                $event_start, 
                $event_end
            );
        }

        // 如果檢測到衝突但未確認，不繼續提交
        if (!empty($conflicts_detected) && !$acknowledged_conflicts) {
            // 存儲衝突到會話以供前端顯示
            $_SESSION['pending_conflicts'] = $conflicts_detected;
            $_SESSION['pending_form_data'] = $_POST;
            $message_type = 'warning';
            $conflict_text = '';
            foreach ($conflicts_detected as $conflict) {
                $conflict_text .= '<br>- ' . htmlspecialchars($conflict['conflicting_club'], ENT_QUOTES, 'UTF-8') . ' 的 "' . 
                                 htmlspecialchars($conflict['conflicting_event'], ENT_QUOTES, 'UTF-8') . 
                                 '" (' . htmlspecialchars($conflict['conflicting_time'], ENT_QUOTES, 'UTF-8') . ')';
            }
            $message = '⚠️ 檢測到場地衝突，請確認是否繼續提交：' . $conflict_text;
        } else if (empty($errors)) {
            $occurrence_dates = [$event_date];
            if ($repeat_type === 'weekly') {
                $weekday = intval($repeat_weekday);
                $start_date = new DateTime($event_date);
                $start_weekday = intval($start_date->format('N')) - 1;
                $days_until = ($weekday - $start_weekday + 7) % 7;
                $first_date = clone $start_date;
                if ($days_until > 0) {
                    $first_date->modify("+{$days_until} days");
                }
                $occurrence_dates = [];
                for ($i = 0; $i < $repeat_weeks; $i++) {
                    $date = clone $first_date;
                    if ($i > 0) {
                        $date->modify("+{$i} week");
                    }
                    $occurrence_dates[] = $date->format('Y-m-d');
                }
            }

            $event_start = $occurrence_dates[0] . ' ' . $start_time . ':00';
            $event_end = $occurrence_dates[0] . ' ' . $end_time . ':00';

            $conn->begin_transaction();
            try {
                $stmt_event = $conn->prepare(
                    "INSERT INTO events (user_id, event_name, club_name, description, start_time, end_time, status, is_field_coordination, field_coordination_setting_id) 
                     VALUES (?, ?, ?, ?, ?, ?, 'pending', 1, ?)"
                );

                if (!$user_id) {
                    throw new Exception('尚未取得使用者識別碼，請重新登入。');
                }

                $description_lines = ["場地協調"];
                if (!empty($activity_purpose)) {
                    $description_lines[] = "用途：{$activity_purpose}";
                }
                if ($repeat_type === 'weekly') {
                    $weekday_names = ['一', '二', '三', '四', '五', '六', '日'];
                    $description_lines[] = "重複：每週{$weekday_names[$weekday]}，共 {$repeat_weeks} 週";
                }
                if (!empty($description)) {
                    $description_lines[] = "備註：{$description}";
                }
                $full_description = implode("\n", $description_lines);

                $setting_id = $active_setting['setting_id'];
                $stmt_event->bind_param('issssssi', $user_id, $event_name, $club_name, $full_description, $event_start, $event_end, $setting_id);
                if (!$stmt_event->execute()) {
                    throw new Exception('建立活動記錄失敗：' . $stmt_event->error);
                }

                $event_id = $conn->insert_id;
                $stmt_event->close();

                $stmt_reserve = $conn->prepare(
                    "INSERT INTO reservations (event_id, space_id, start_time, end_time, is_field_coordination_preliminary) 
                     VALUES (?, ?, ?, ?, 1)"
                );

                foreach ($space_ids as $space_id) {
                    $space_id = intval($space_id);
                    foreach ($occurrence_dates as $date_value) {
                        $reservation_start = $date_value . ' ' . $start_time . ':00';
                        $reservation_end = $date_value . ' ' . $end_time . ':00';
                        $stmt_reserve->bind_param('iiss', $event_id, $space_id, $reservation_start, $reservation_end);
                        if (!$stmt_reserve->execute()) {
                            throw new Exception('場地登記失敗：' . $stmt_reserve->error);
                        }
                    }
                }
                $stmt_reserve->close();

                // 取得社團ID
                $club_id_sql = "SELECT club_id FROM clubs WHERE club_name = ? LIMIT 1";
                $club_id_stmt = $conn->prepare($club_id_sql);
                $club_id_stmt->bind_param("s", $club_name);
                $club_id_stmt->execute();
                $club_id_result = $club_id_stmt->get_result();
                $club_id = ($club_row = $club_id_result->fetch_assoc()) ? $club_row['club_id'] : 0;
                $club_id_stmt->close();

                // 建立場協登記紀錄
                $fc_manager->createFieldCoordinationRegistration(
                    $active_setting['setting_id'],
                    $event_id,
                    $student_id_value,
                    $club_id,
                    $club_name
                );

                $conn->commit();
                $message_type = 'success';
                $date_range_message = implode('、', $occurrence_dates);
                $message = '✅ 場地協調登記已送出，申請編號：#' . $event_id . '。登記日期：' . $date_range_message . '。';
                if (!empty($conflicts_detected)) {
                    $message .= '該申請包含 ' . count($conflicts_detected) . ' 個場地衝突，管理員將於協調大會時協調。';
                }
                // 清空会话中的待处理数据
                unset($_SESSION['pending_conflicts']);
                unset($_SESSION['pending_form_data']);
                $_POST = [];
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
                <form method="post">
                    <input type="hidden" name="acknowledged_conflicts" value="1">
                    <?php foreach ($_SESSION['pending_form_data'] as $key => $value): ?>
                        <?php if (is_array($value)): ?>
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
                <form method="post" <?php if (!$active_setting || $has_meeting_passed) echo 'style="opacity: 0.5; pointer-events: none;"'; ?>>
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
                        <label class="form-label" for="activity_purpose">場地用途</label>
                        <input id="activity_purpose" name="activity_purpose" class="form-control" value="<?= htmlspecialchars($_SESSION['pending_form_data']['activity_purpose'] ?? $_POST['activity_purpose'] ?? '', ENT_QUOTES, 'UTF-8') ?>" placeholder="例如：熱舞社練習、比賽排練、社團會議">
                    </div>
                    <div class="row g-3 mt-3">
                        <div class="col-md-4">
                            <label class="form-label" for="event_date">首次日期 *</label>
                            <input type="date" id="event_date" name="event_date" class="form-control" value="<?= htmlspecialchars($_SESSION['pending_form_data']['event_date'] ?? $_POST['event_date'] ?? '', ENT_QUOTES, 'UTF-8') ?>" required>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label" for="start_time">開始時間 *</label>
                            <input type="time" id="start_time" name="start_time" class="form-control" value="<?= htmlspecialchars($_SESSION['pending_form_data']['start_time'] ?? $_POST['start_time'] ?? '12:00', ENT_QUOTES, 'UTF-8') ?>" required>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label" for="end_time">結束時間 *</label>
                            <input type="time" id="end_time" name="end_time" class="form-control" value="<?= htmlspecialchars($_SESSION['pending_form_data']['end_time'] ?? $_POST['end_time'] ?? '13:30', ENT_QUOTES, 'UTF-8') ?>" required>
                        </div>
                    </div>
                    <div class="row g-3 mt-3">
                        <div class="col-md-4">
                            <label class="form-label" for="repeat_type">重複方式</label>
                            <select id="repeat_type" name="repeat_type" class="form-control">
                                <option value="none" <?= (isset($_SESSION['pending_form_data']['repeat_type']) && $_SESSION['pending_form_data']['repeat_type'] === 'weekly') || (isset($_POST['repeat_type']) && $_POST['repeat_type'] === 'weekly') ? '' : 'selected' ?>>單次登記</option>
                                <option value="weekly" <?= (isset($_SESSION['pending_form_data']['repeat_type']) && $_SESSION['pending_form_data']['repeat_type'] === 'weekly') || (isset($_POST['repeat_type']) && $_POST['repeat_type'] === 'weekly') ? 'selected' : '' ?>>每週固定登記</option>
                            </select>
                        </div>
                        <div class="col-md-4" id="weeklyWeekday" style="display: <?= (isset($_SESSION['pending_form_data']['repeat_type']) && $_SESSION['pending_form_data']['repeat_type'] === 'weekly') || (isset($_POST['repeat_type']) && $_POST['repeat_type'] === 'weekly') ? 'block' : 'none' ?>;">
                            <label class="form-label" for="repeat_weekday">每週星期</label>
                            <select id="repeat_weekday" name="repeat_weekday" class="form-control">
                                <option value="">請選擇</option>
                                <?php for ($d = 0; $d < 7; $d++): ?>
                                <option value="<?= $d ?>" <?= (isset($_SESSION['pending_form_data']['repeat_weekday']) && $_SESSION['pending_form_data']['repeat_weekday'] == $d) || (isset($_POST['repeat_weekday']) && $_POST['repeat_weekday'] == $d) ? 'selected' : '' ?>>星期<?= ['一', '二', '三', '四', '五', '六', '日'][$d] ?></option>
                                <?php endfor; ?>
                            </select>
                        </div>
                        <div class="col-md-4" id="weeklyCount" style="display: <?= (isset($_SESSION['pending_form_data']['repeat_type']) && $_SESSION['pending_form_data']['repeat_type'] === 'weekly') || (isset($_POST['repeat_type']) && $_POST['repeat_type'] === 'weekly') ? 'block' : 'none' ?>;">
                            <label class="form-label" for="repeat_weeks">重複週數</label>
                            <input type="number" id="repeat_weeks" name="repeat_weeks" class="form-control" min="1" value="<?= htmlspecialchars($_SESSION['pending_form_data']['repeat_weeks'] ?? $_POST['repeat_weeks'] ?? '4', ENT_QUOTES, 'UTF-8') ?>">
                        </div>
                    </div>
                    <div class="mt-4">
                        <label class="form-label" for="buildingSelect">選擇大樓</label>
                        <select id="buildingSelect" class="form-control mb-3">
                            <option value="0">全部大樓</option>
                            <?php foreach ($buildings as $building): ?>
                                <option value="<?= $building['id'] ?>"><?= htmlspecialchars($building['name'], ENT_QUOTES, 'UTF-8') ?></option>
                            <?php endforeach; ?>
                        </select>
                        <div id="roomContainer">
                            <?php foreach ($buildings as $building): ?>
                                <div class="room-group" data-building="<?= $building['id'] ?>" style="display: block;">
                                    <div class="fw-bold mb-2"><?= htmlspecialchars($building['name'], ENT_QUOTES, 'UTF-8') ?></div>
                                    <div class="row g-3">
                                        <?php foreach ($building['rooms'] as $space): ?>
                                            <?php 
                                            $is_checked = false;
                                            if (isset($_SESSION['pending_form_data']['space_ids']) && in_array($space['space_id'], $_SESSION['pending_form_data']['space_ids'])) {
                                                $is_checked = true;
                                            } elseif (isset($_POST['space_ids']) && in_array($space['space_id'], $_POST['space_ids'])) {
                                                $is_checked = true;
                                            }
                                            ?>
                                            <div class="col-md-6">
                                                <div class="form-check" style="border:1px solid #e5e7eb; border-radius:12px; padding:1rem; background:#fff;">
                                                    <input class="form-check-input" type="checkbox" name="space_ids[]" value="<?= $space['space_id'] ?>" id="space_<?= $space['space_id'] ?>" <?= $is_checked ? 'checked' : '' ?>>
                                                    <label class="form-check-label" for="space_<?= $space['space_id'] ?>">
                                                        <?= htmlspecialchars($space['space_name'], ENT_QUOTES, 'UTF-8') ?><?php if (!empty($space['capacity'])): ?>（容納 <?= htmlspecialchars($space['capacity'], ENT_QUOTES, 'UTF-8') ?> 人）<?php endif; ?>
                                                    </label>
                                                    <div style="margin-top: 0.5rem;">
                                                        <a href="calendar.php?space_id=<?= $space['space_id'] ?>" class="btn btn-sm btn-outline-primary" target="_blank">
                                                            <i class="bi bi-calendar3"></i> 查看行事曆
                                                        </a>
                                                    </div>
                                                </div>
                                            </div>
                                        <?php endforeach; ?>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
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
        document.addEventListener('DOMContentLoaded', () => {
            const buildingSelect = document.getElementById('buildingSelect');
            const repeatType = document.getElementById('repeat_type');
            const weeklyWeekday = document.getElementById('weeklyWeekday');
            const weeklyCount = document.getElementById('weeklyCount');

            buildingSelect.addEventListener('change', () => {
                const selected = buildingSelect.value;
                document.querySelectorAll('.room-group').forEach(group => {
                    group.style.display = (selected === '0' || group.dataset.building === selected) ? 'block' : 'none';
                });
            });

            repeatType.addEventListener('change', () => {
                const show = repeatType.value === 'weekly';
                weeklyWeekday.style.display = show ? 'block' : 'none';
                weeklyCount.style.display = show ? 'block' : 'none';
            });
        });
    </script>
</body>
</html>