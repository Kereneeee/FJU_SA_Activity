<?php
ini_set('display_errors', 1);
error_reporting(E_ALL);

session_start();
require_once(__DIR__ . "/../DB/db_config.php");

if (!isset($_SESSION['student_id'])) {
    header('Location: ../login.php');
    exit();
}

$message = "";
$message_type = "";

// 設置當前頁面用於側邊欄高亮
$current_page = 'apply_event';

// 模擬資料 - 實際應用中應該從資料庫獲取
$venues = [
    ['id' => 1, 'name' => '大禮堂', 'capacity' => 500, 'available' => true],
    ['id' => 2, 'name' => '會議室A', 'capacity' => 50, 'available' => false],
    ['id' => 3, 'name' => '活動中心', 'capacity' => 200, 'available' => true],
    ['id' => 4, 'name' => '多功能教室', 'capacity' => 100, 'available' => true]
];

$equipment = [
    ['id' => 'mic', 'name' => '無線麥克風', 'total' => 10, 'available' => 8, 'unit' => '支'],
    ['id' => 'projector', 'name' => '投影機', 'total' => 5, 'available' => 3, 'unit' => '台'],
    ['id' => 'speaker', 'name' => '音響系統', 'total' => 3, 'available' => 2, 'unit' => '組'],
    ['id' => 'table_chair', 'name' => '桌椅組', 'total' => 50, 'available' => 45, 'unit' => '組'],
    ['id' => 'extension', 'name' => '延長線', 'total' => 20, 'available' => 15, 'unit' => '條']
];

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $club_name = $_POST['club_name'] ?? '';
    $event_name = $_POST['event_name'] ?? '';
    $event_date = $_POST['event_date'] ?? '';
    $start_time = $_POST['start_time'] ?? '';
    $end_time = $_POST['end_time'] ?? '';
    $venue_id = $_POST['venue_id'] ?? '';
    $expected_attendees = $_POST['expected_attendees'] ?? '';
    $description = $_POST['description'] ?? '';

    // 驗證必填欄位
    $errors = [];

    if (empty($event_name)) $errors[] = "請填寫活動名稱";
    if (empty($club_name)) $errors[] = "請填寫社團名稱";
    if (empty($event_date)) $errors[] = "請選擇活動日期";
    if (empty($start_time) || empty($end_time)) $errors[] = "請填寫活動時間";
    if (empty($venue_id)) $errors[] = "請選擇場地";
    if ($start_time >= $end_time) $errors[] = "結束時間必須晚於開始時間";

    if (empty($errors)) {
        // 處理器材選擇
        $selected_equipment = [];
        if (isset($_POST['equipment'])) {
            foreach ($_POST['equipment'] as $equip_id => $quantity) {
                if ($quantity > 0) {
                    $selected_equipment[$equip_id] = $quantity;
                }
            }
        }

        // 這裡可以添加資料庫儲存邏輯
        $message = "✅ 活動申請已提交！我們將在2個工作天內審核您的申請。";
        $message_type = "success";
    } else {
        $message = "❌ " . implode("<br>", $errors);
        $message_type = "error";
    }
}
?>

<!DOCTYPE html>
<html lang="zh-TW">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>活動申請 - 輔仁大學課外活動指導組</title>

    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.5/font/bootstrap-icons.css" rel="stylesheet">

    <style>
        :root {
            --primary: #8B1538;
            --sidebar: #4c0f2a;
            --sidebar-hover: #6a1d43;
            --bg: #f4f6fb;
            --card: #ffffff;
            --success: #10b981;
            --warning: #f59e0b;
            --danger: #ef4444;
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
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }
        .form-section {
            background: #f8fafc;
            border-radius: 12px;
            padding: 1.5rem;
            margin-bottom: 1.5rem;
        }
        .form-section h4 {
            color: var(--primary);
            margin-bottom: 1rem;
            font-weight: 600;
        }
        .form-group {
            margin-bottom: 1rem;
        }
        .form-group label {
            display: block;
            margin-bottom: 0.5rem;
            font-weight: 600;
            color: #374151;
        }
        .form-control {
            width: 100%;
            padding: 0.75rem;
            border: 1px solid #e5e7eb;
            border-radius: 8px;
            font-size: 0.95rem;
        }
        .form-control:focus {
            outline: none;
            border-color: var(--primary);
            box-shadow: 0 0 0 3px rgba(139, 21, 56, 0.1);
        }
        .time-inputs {
            display: grid;
            grid-template-columns: 1fr auto 1fr;
            gap: 0.5rem;
            align-items: center;
        }
        .time-separator {
            text-align: center;
            color: #6b7280;
            font-weight: 600;
        }
        .venue-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(280px, 1fr));
            gap: 1rem;
        }
        .venue-card {
            border: 2px solid #e5e7eb;
            border-radius: 12px;
            padding: 1rem;
            cursor: pointer;
            transition: all 0.25s ease;
            position: relative;
        }
        .venue-card:hover {
            border-color: var(--primary);
            box-shadow: 0 4px 15px rgba(139, 21, 56, 0.1);
        }
        .venue-card.selected {
            border-color: var(--primary);
            background: rgba(139, 21, 56, 0.05);
        }
        .venue-card.unavailable {
            opacity: 0.6;
            cursor: not-allowed;
        }
        .venue-card.unavailable:hover {
            border-color: #ef4444;
            box-shadow: 0 4px 15px rgba(239, 68, 68, 0.1);
        }
        .venue-header {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            margin-bottom: 0.75rem;
        }
        .venue-name {
            font-weight: 600;
            font-size: 1.1rem;
        }
        .venue-status {
            padding: 0.25rem 0.5rem;
            border-radius: 999px;
            font-size: 0.8rem;
            font-weight: 600;
        }
        .status-available { background: #d1e7dd; color: #0f5132; }
        .status-unavailable { background: #f8d7da; color: #721c24; }
        .venue-capacity {
            color: #6b7280;
            font-size: 0.9rem;
            margin-bottom: 0.5rem;
        }
        .equipment-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
            gap: 1rem;
        }
        .equipment-card {
            border: 1px solid #e5e7eb;
            border-radius: 12px;
            padding: 1rem;
            background: white;
        }
        .equipment-header {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            margin-bottom: 1rem;
        }
        .equipment-name {
            font-weight: 600;
        }
        .equipment-stock {
            text-align: right;
            font-size: 0.9rem;
        }
        .stock-available {
            color: var(--success);
            font-weight: 600;
        }
        .stock-low {
            color: var(--warning);
            font-weight: 600;
        }
        .stock-empty {
            color: var(--danger);
            font-weight: 600;
        }
        .counter {
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }
        .counter button {
            width: 32px;
            height: 32px;
            border: 1px solid #d1d5db;
            background: white;
            border-radius: 6px;
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
            transition: all 0.25s ease;
        }
        .counter button:hover:not(:disabled) {
            background: var(--primary);
            color: white;
            border-color: var(--primary);
        }
        .counter button:disabled {
            opacity: 0.5;
            cursor: not-allowed;
        }
        .counter input {
            width: 60px;
            text-align: center;
            border: 1px solid #d1d5db;
            border-radius: 6px;
            padding: 0.25rem;
            font-weight: 600;
        }
        .message {
            padding: 1rem;
            border-radius: 12px;
            margin-bottom: 1.5rem;
            font-weight: 600;
        }
        .message.success {
            background: #d1e7dd;
            color: #0f5132;
            border: 1px solid #a3cfbb;
        }
        .message.error {
            background: #f8d7da;
            color: #721c24;
            border: 1px solid #f1aeb5;
        }
        .btn-submit {
            background: var(--primary);
            color: white;
            border: none;
            padding: 1rem 3rem;
            border-radius: 12px;
            font-weight: 600;
            font-size: 1.1rem;
            cursor: pointer;
            transition: all 0.25s ease;
            display: block;
            margin: 2rem auto 0;
            box-shadow: 0 4px 15px rgba(139, 21, 56, 0.2);
        }
        .btn-submit:hover {
            background: var(--sidebar);
            transform: translateY(-2px);
            box-shadow: 0 6px 20px rgba(139, 21, 56, 0.3);
        }
        .progress-indicator {
            display: flex;
            justify-content: center;
            margin-bottom: 2rem;
        }
        .progress-step {
            display: flex;
            align-items: center;
            gap: 0.5rem;
            padding: 0.5rem 1rem;
            border-radius: 25px;
            background: #e5e7eb;
            color: #6b7280;
            font-size: 0.9rem;
            font-weight: 600;
        }
        .progress-step.active {
            background: var(--primary);
            color: white;
        }
        .progress-step::before {
            content: "1";
            width: 24px;
            height: 24px;
            border-radius: 50%;
            background: white;
            color: #6b7280;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: 700;
            font-size: 0.8rem;
        }
        .progress-step.active::before {
            background: white;
            color: var(--primary);
        }
        @media (max-width: 1100px) {
            .venue-grid, .equipment-grid { grid-template-columns: 1fr; }
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
            .time-inputs {
                grid-template-columns: 1fr;
                gap: 0.25rem;
            }
            .time-separator {
                order: -1;
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
                    <li class="breadcrumb-item active" aria-current="page">活動申請</li>
                </ol>
                <h4 class="mt-2 mb-0">活動申請</h4>
            </div>
        </header>

        <section class="content-wrapper">
            <div class="progress-indicator">
                <div class="progress-step active">
                    <i class="bi bi-calendar-plus"></i>
                    填寫申請資訊
                </div>
            </div>

            <?php if($message): ?>
            <div class="message <?= $message_type ?>">
                <?= $message ?>
            </div>
            <?php endif; ?>

            <form method="POST" id="applicationForm">
                <!-- 基本資訊 -->
                <div class="card">
                    <h3>
                        <i class="bi bi-info-circle"></i>
                        基本資訊
                    </h3>
                    <div class="form-section">
                        <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 1rem;">
                            <div class="form-group">
                                <label for="event_name">活動名稱 *</label>
                                <input type="text" id="event_name" name="event_name" class="form-control"
                                       value="<?= htmlspecialchars($_POST['event_name'] ?? '') ?>" required>
                            </div>
                            <div class="form-group">
                                <label for="club_name">主辦社團 *</label>
                                <input type="text" id="club_name" name="club_name" class="form-control"
                                       value="<?= htmlspecialchars($_POST['club_name'] ?? '') ?>" required>
                            </div>
                        </div>

                        <div style="display: grid; grid-template-columns: 1fr 1fr 1fr; gap: 1rem;">
                            <div class="form-group">
                                <label for="event_date">活動日期 *</label>
                                <input type="date" id="event_date" name="event_date" class="form-control"
                                       value="<?= htmlspecialchars($_POST['event_date'] ?? '') ?>" required>
                            </div>
                            <div class="form-group">
                                <label>開始時間 *</label>
                                <input type="time" id="start_time" name="start_time" class="form-control"
                                       value="<?= htmlspecialchars($_POST['start_time'] ?? '') ?>" required>
                            </div>
                            <div class="form-group">
                                <label>結束時間 *</label>
                                <input type="time" id="end_time" name="end_time" class="form-control"
                                       value="<?= htmlspecialchars($_POST['end_time'] ?? '') ?>" required>
                            </div>
                        </div>

                        <div class="form-group">
                            <label for="expected_attendees">預計參加人數</label>
                            <input type="number" id="expected_attendees" name="expected_attendees" class="form-control"
                                   value="<?= htmlspecialchars($_POST['expected_attendees'] ?? '') ?>" min="1" placeholder="請輸入預計人數">
                        </div>

                        <div class="form-group">
                            <label for="description">活動說明</label>
                            <textarea id="description" name="description" class="form-control" rows="3"
                                      placeholder="請簡述活動內容、目的及特別需求..."><?= htmlspecialchars($_POST['description'] ?? '') ?></textarea>
                        </div>
                    </div>
                </div>

                <!-- 場地選擇 -->
                <div class="card">
                    <h3>
                        <i class="bi bi-geo-alt"></i>
                        場地選擇
                    </h3>
                    <div class="form-section">
                        <p style="color: #6b7280; margin-bottom: 1rem;">
                            請選擇您需要的場地。系統會根據您選擇的日期和時間顯示可用性。
                        </p>
                        <div class="venue-grid">
                            <?php foreach ($venues as $venue): ?>
                            <div class="venue-card <?= $venue['available'] ? '' : 'unavailable' ?>"
                                 data-venue-id="<?= $venue['id'] ?>"
                                 onclick="selectVenue(<?= $venue['id'] ?>, <?= $venue['available'] ? 'true' : 'false' ?>)">
                                <div class="venue-header">
                                    <div class="venue-name"><?= htmlspecialchars($venue['name']) ?></div>
                                    <div class="venue-status <?= $venue['available'] ? 'status-available' : 'status-unavailable' ?>">
                                        <?= $venue['available'] ? '可預約' : '已被預約' ?>
                                    </div>
                                </div>
                                <div class="venue-capacity">
                                    <i class="bi bi-people"></i> 容納人數：<?= $venue['capacity'] ?> 人
                                </div>
                                <?php if (!$venue['available']): ?>
                                <div style="color: #ef4444; font-size: 0.9rem; margin-top: 0.5rem;">
                                    <i class="bi bi-exclamation-triangle"></i> 此時段已被其他活動預約
                                </div>
                                <?php endif; ?>
                                <input type="radio" name="venue_id" value="<?= $venue['id'] ?>" style="display: none;"
                                       <?= $venue['available'] ? '' : 'disabled' ?>>
                            </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </div>

                <!-- 器材借用 -->
                <div class="card">
                    <h3>
                        <i class="bi bi-tools"></i>
                        器材借用
                    </h3>
                    <div class="form-section">
                        <p style="color: #6b7280; margin-bottom: 1rem;">
                            選擇您需要的器材及數量。系統會即時顯示剩餘數量。
                        </p>
                        <div class="equipment-grid">
                            <?php foreach ($equipment as $item): ?>
                            <div class="equipment-card">
                                <div class="equipment-header">
                                    <div class="equipment-name">
                                        <i class="bi bi-<?= getEquipmentIcon($item['id']) ?>"></i>
                                        <?= htmlspecialchars($item['name']) ?>
                                    </div>
                                    <div class="equipment-stock">
                                        <div class="stock-<?= $item['available'] > 0 ? ($item['available'] < 3 ? 'low' : 'available') : 'empty' ?>">
                                            剩餘: <?= $item['available'] ?>/<?= $item['total'] ?> <?= $item['unit'] ?>
                                        </div>
                                    </div>
                                </div>
                                <div class="counter">
                                    <button type="button" onclick="changeQuantity('<?= $item['id'] ?>', -1)" <?= $item['available'] == 0 ? 'disabled' : '' ?>>-</button>
                                    <input type="number" id="qty_<?= $item['id'] ?>" name="equipment[<?= $item['id'] ?>]"
                                           value="0" min="0" max="<?= $item['available'] ?>" readonly>
                                    <button type="button" onclick="changeQuantity('<?= $item['id'] ?>', 1)" <?= $item['available'] == 0 ? 'disabled' : '' ?>>+</button>
                                </div>
                            </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </div>

                <!-- 提交按鈕 -->
                <button type="submit" class="btn-submit">
                    <i class="bi bi-send"></i>
                    提交申請
                </button>
            </form>
        </section>
    </main>

    <script>
        let selectedVenue = null;

        function selectVenue(venueId, available) {
            if (!available) return;

            // 移除之前的選擇
            document.querySelectorAll('.venue-card').forEach(card => {
                card.classList.remove('selected');
            });

            // 選擇新的場地
            const selectedCard = document.querySelector(`[data-venue-id="${venueId}"]`);
            selectedCard.classList.add('selected');

            // 設置radio button
            document.querySelector(`input[name="venue_id"][value="${venueId}"]`).checked = true;
            selectedVenue = venueId;
        }

        function changeQuantity(equipId, delta) {
            const input = document.getElementById('qty_' + equipId);
            const max = parseInt(input.getAttribute('max'));
            let value = parseInt(input.value) + delta;

            if (value < 0) value = 0;
            if (value > max) value = max;

            input.value = value;
        }

        function getEquipmentIcon(equipId) {
            const icons = {
                'mic': 'mic',
                'projector': 'projector',
                'speaker': 'speaker',
                'table_chair': 'table',
                'extension': 'plug'
            };
            return icons[equipId] || 'tools';
        }

        // 表單驗證
        document.getElementById('applicationForm').addEventListener('submit', function(e) {
            const startTime = document.getElementById('start_time').value;
            const endTime = document.getElementById('end_time').value;
            const venueSelected = document.querySelector('input[name="venue_id"]:checked');

            if (startTime && endTime && startTime >= endTime) {
                e.preventDefault();
                alert('結束時間必須晚於開始時間！');
                return false;
            }

            if (!venueSelected) {
                e.preventDefault();
                alert('請選擇活動場地！');
                return false;
            }

            // 檢查場地是否可用
            const selectedCard = document.querySelector('.venue-card.selected');
            if (selectedCard && selectedCard.classList.contains('unavailable')) {
                e.preventDefault();
                alert('所選場地在此時段已被預約，請選擇其他場地！');
                return false;
            }
        });

        // 日期變更時更新場地可用性（模擬）
        document.getElementById('event_date').addEventListener('change', function() {
            // 這裡可以添加AJAX請求來檢查場地可用性
            console.log('日期變更，檢查場地可用性...');
        });

        // 時間變更時更新場地可用性（模擬）
        document.getElementById('start_time').addEventListener('change', function() {
            console.log('開始時間變更，檢查場地可用性...');
        });

        document.getElementById('end_time').addEventListener('change', function() {
            console.log('結束時間變更，檢查場地可用性...');
        });
    </script>
</body>
</html>

<?php
function getEquipmentIcon($equipId) {
    $icons = [
        'mic' => 'mic',
        'projector' => 'projector',
        'speaker' => 'speaker',
        'table_chair' => 'table',
        'extension' => 'plug'
    ];
    return $icons[$equipId] ?? 'tools';
}
?>