<?php
ini_set('display_errors', 1);
error_reporting(E_ALL);


require_once(__DIR__ . "/../DB/db_config.php");

if (!isset($_SESSION['student_id'])) {
    header('Location: ../login.php');
    exit();
}

// 設置當前頁面用於側邊欄高亮
$current_page = 'field_coord';

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

    if (empty($errors)) {
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
                "INSERT INTO events (user_id, event_name, club_name, description, start_time, end_time, status) 
                 VALUES (?, ?, ?, ?, ?, ?, 'pending')"
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

            $stmt_event->bind_param('isssss', $user_id, $event_name, $club_name, $full_description, $event_start, $event_end);
            if (!$stmt_event->execute()) {
                throw new Exception('建立活動記錄失敗：' . $stmt_event->error);
            }

            $event_id = $conn->insert_id;
            $stmt_event->close();

            $stmt_reserve = $conn->prepare(
                "INSERT INTO reservations (event_id, space_id, start_time, end_time) 
                 VALUES (?, ?, ?, ?)"
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

            $conn->commit();
            $message_type = 'success';
            $date_range_message = implode('、', $occurrence_dates);
            $message = '✅ 場地協調登記已送出，申請編號：#' . $event_id . '。登記日期：' . $date_range_message . '。管理員將儘速協調場地衝突。';
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
            --primary: #8B1538;
            --sidebar: #4c0f2a;
            --sidebar-hover: #6a1d43;
            --bg: #f4f6fb;
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
            background: linear-gradient(180deg, var(--primary), var(--sidebar));
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
            background: rgba(255,255,255,0.12);
            color: #ffffff;
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
            background: white;
            border-bottom: 1px solid #e9ecef;
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

            <?php if (!empty($message)): ?>
            <div class="card" style="border-left: 5px solid <?= $message_type === 'success' ? '#10b981' : '#ef4444'; ?>;">
                <h3><?= $message_type === 'success' ? '登記成功' : '錯誤提醒' ?></h3>
                <p class="text-muted"><?= $message ?></p>
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
                <form method="post">
                    <input type="hidden" name="register_spaces" value="1">
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label" for="club_name">主辦社團 *</label>
                            <input id="club_name" name="club_name" class="form-control" value="<?= htmlspecialchars($_POST['club_name'] ?? $selected_club_name, ENT_QUOTES, 'UTF-8') ?>" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label" for="event_name">活動名稱 *</label>
                            <input id="event_name" name="event_name" class="form-control" value="<?= htmlspecialchars($_POST['event_name'] ?? '', ENT_QUOTES, 'UTF-8') ?>" required>
                        </div>
                    </div>
                    <div class="mt-3">
                        <label class="form-label" for="activity_purpose">場地用途</label>
                        <input id="activity_purpose" name="activity_purpose" class="form-control" value="<?= htmlspecialchars($_POST['activity_purpose'] ?? '', ENT_QUOTES, 'UTF-8') ?>" placeholder="例如：熱舞社練習、比賽排練、社團會議">
                    </div>
                    <div class="row g-3 mt-3">
                        <div class="col-md-4">
                            <label class="form-label" for="event_date">首次日期 *</label>
                            <input type="date" id="event_date" name="event_date" class="form-control" value="<?= htmlspecialchars($_POST['event_date'] ?? '', ENT_QUOTES, 'UTF-8') ?>" required>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label" for="start_time">開始時間 *</label>
                            <input type="time" id="start_time" name="start_time" class="form-control" value="<?= htmlspecialchars($_POST['start_time'] ?? '12:00', ENT_QUOTES, 'UTF-8') ?>" required>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label" for="end_time">結束時間 *</label>
                            <input type="time" id="end_time" name="end_time" class="form-control" value="<?= htmlspecialchars($_POST['end_time'] ?? '13:30', ENT_QUOTES, 'UTF-8') ?>" required>
                        </div>
                    </div>
                    <div class="row g-3 mt-3">
                        <div class="col-md-4">
                            <label class="form-label" for="repeat_type">重複方式</label>
                            <select id="repeat_type" name="repeat_type" class="form-control">
                                <option value="none" <?= (isset($_POST['repeat_type']) && $_POST['repeat_type'] === 'weekly') ? '' : 'selected' ?>>單次登記</option>
                                <option value="weekly" <?= (isset($_POST['repeat_type']) && $_POST['repeat_type'] === 'weekly') ? 'selected' : '' ?>>每週固定登記</option>
                            </select>
                        </div>
                        <div class="col-md-4" id="weeklyWeekday" style="display: <?= (isset($_POST['repeat_type']) && $_POST['repeat_type'] === 'weekly') ? 'block' : 'none' ?>;">
                            <label class="form-label" for="repeat_weekday">每週星期</label>
                            <select id="repeat_weekday" name="repeat_weekday" class="form-control">
                                <option value="">請選擇</option>
                                <option value="0" <?= (isset($_POST['repeat_weekday']) && $_POST['repeat_weekday'] === '0') ? 'selected' : '' ?>>星期一</option>
                                <option value="1" <?= (isset($_POST['repeat_weekday']) && $_POST['repeat_weekday'] === '1') ? 'selected' : '' ?>>星期二</option>
                                <option value="2" <?= (isset($_POST['repeat_weekday']) && $_POST['repeat_weekday'] === '2') ? 'selected' : '' ?>>星期三</option>
                                <option value="3" <?= (isset($_POST['repeat_weekday']) && $_POST['repeat_weekday'] === '3') ? 'selected' : '' ?>>星期四</option>
                                <option value="4" <?= (isset($_POST['repeat_weekday']) && $_POST['repeat_weekday'] === '4') ? 'selected' : '' ?>>星期五</option>
                                <option value="5" <?= (isset($_POST['repeat_weekday']) && $_POST['repeat_weekday'] === '5') ? 'selected' : '' ?>>星期六</option>
                                <option value="6" <?= (isset($_POST['repeat_weekday']) && $_POST['repeat_weekday'] === '6') ? 'selected' : '' ?>>星期日</option>
                            </select>
                        </div>
                        <div class="col-md-4" id="weeklyCount" style="display: <?= (isset($_POST['repeat_type']) && $_POST['repeat_type'] === 'weekly') ? 'block' : 'none' ?>;">
                            <label class="form-label" for="repeat_weeks">重複週數</label>
                            <input type="number" id="repeat_weeks" name="repeat_weeks" class="form-control" min="1" value="<?= htmlspecialchars($_POST['repeat_weeks'] ?? '4', ENT_QUOTES, 'UTF-8') ?>">
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
                                            <div class="col-md-6">
                                                <div class="form-check" style="border:1px solid #e5e7eb; border-radius:12px; padding:1rem; background:#fff;">
                                                    <input class="form-check-input" type="checkbox" name="space_ids[]" value="<?= $space['space_id'] ?>" id="space_<?= $space['space_id'] ?>" <?= (isset($_POST['space_ids']) && in_array($space['space_id'], $_POST['space_ids'])) ? 'checked' : '' ?>>
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
                        <textarea id="description" name="description" class="form-control" rows="3"><?= htmlspecialchars($_POST['description'] ?? '', ENT_QUOTES, 'UTF-8') ?></textarea>
                    </div>
                    <div class="mt-4 d-flex flex-wrap gap-2">
                        <button type="submit" class="btn btn-primary">提交批次登記</button>
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
                            <strong>場地管理中心</strong><br>
                            電話：(02) 2905-2000 轉 1234
                        </div>
                    </div>
                    <div class="contact-item">
                        <i class="bi bi-envelope"></i>
                        <div>
                            <strong>電子郵件</strong><br>
                            venue@fju.edu.tw
                        </div>
                    </div>
                    <div class="contact-item">
                        <i class="bi bi-clock"></i>
                        <div>
                            <strong>服務時間</strong><br>
                            週一至週五 08:00-17:00
                        </div>
                    </div>
                    <div class="contact-item">
                        <i class="bi bi-geo-alt"></i>
                        <div>
                            <strong>辦公室位置</strong><br>
                            輔仁大學 場地管理中心 (學生中心1樓)
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