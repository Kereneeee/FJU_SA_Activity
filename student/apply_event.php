<form method="POST" id="applicationForm" enctype="multipart/form-data">

<?php
ini_set('display_errors', 1);
error_reporting(E_ALL);

require_once(__DIR__ . "/../DB/db_config.php");

if (!isset($_SESSION['user_id'])) {
    header('Location: ../login.php');
    exit();
}

// 設置當前頁面用於側邊欄高亮
$current_page = 'apply_event';

$message = "";
$message_type = "";

// 從資料庫獲取學校場地
$sql_spaces = "SELECT space_id, space_name FROM spaces WHERE status = 'available'";
$result_spaces = $conn->query($sql_spaces);
$venues = [];
if ($result_spaces) {
    $venues = $result_spaces->fetch_all(MYSQLI_ASSOC);
}

// 從資料庫獲取場地預約資料，用於顯示各場地可用時間
$sql_reservations = "SELECT r.space_id, r.start_time, r.end_time, e.club_name
                     FROM reservations r
                     JOIN events e ON r.event_id = e.event_id";
$reservation_data = [];
$result_reservations = $conn->query($sql_reservations);
if ($result_reservations) {
    while ($row = $result_reservations->fetch_assoc()) {
        $reservation_data[] = $row;
    }
}

function categorizeVenues(array $venues): array {
    $groups = [
        'A焯炤館' => [],
        'B進修部' => [],
        'C仁愛學苑' => [],
        'D文開 / D真善美' => [],
        'E課指組' => [],
        'H校門口' => [],
        '其他場地' => []
    ];

    foreach ($venues as $venue) {
        $name = $venue['space_name'];
        if (strpos($name, 'A焯炤館') === 0) {
            $groups['A焯炤館'][] = $venue;
        } elseif (strpos($name, 'B進修部') === 0) {
            $groups['B進修部'][] = $venue;
        } elseif (strpos($name, 'C仁愛學苑') === 0) {
            $groups['C仁愛學苑'][] = $venue;
        } elseif (strpos($name, 'D文開') === 0 || strpos($name, 'D真善美聖廣場') === 0) {
            $groups['D文開 / D真善美'][] = $venue;
        } elseif (strpos($name, 'E課指組') === 0) {
            $groups['E課指組'][] = $venue;
        } elseif (strpos($name, 'H校門口') === 0) {
            $groups['H校門口'][] = $venue;
        } else {
            $groups['其他場地'][] = $venue;
        }
    }

    return array_filter($groups, function($items) {
        return !empty($items);
    });
}

$venue_categories = categorizeVenues($venues);

// 從資料庫獲取器材
$sql_equipment = "SELECT equipment_id, name, borrowing_limit, total_quantity, available_quantity FROM equipment WHERE status = 'available'";
$result_equipment = $conn->query($sql_equipment);
$equipment = [];
if ($result_equipment) {
    $equipment_list = $result_equipment->fetch_all(MYSQLI_ASSOC);
    // 轉換為預期的格式
    foreach ($equipment_list as $eq) {
        $equipment[] = [
            'id' => $eq['equipment_id'],
            'name' => $eq['name'],
            'total' => $eq['total_quantity'],
            'available' => $eq['available_quantity'],
            'limit' => $eq['borrowing_limit'],
            'unit' => '件'
        ];
    }
}

// 定義校園節次時間
$time_periods = [
    'D1' => ['name' => '第一節', 'start' => '08:10', 'end' => '09:00'],
    'D2' => ['name' => '第二節', 'start' => '09:10', 'end' => '10:00'],
    'D3' => ['name' => '第三節', 'start' => '10:10', 'end' => '11:00'],
    'D4' => ['name' => '第四節', 'start' => '11:10', 'end' => '12:00'],
    'DN' => ['name' => '特午節', 'start' => '12:40', 'end' => '13:30'],
    'D5' => ['name' => '第五節', 'start' => '13:40', 'end' => '14:30'],
    'D6' => ['name' => '第六節', 'start' => '14:40', 'end' => '15:30'],
    'D7' => ['name' => '第七節', 'start' => '15:40', 'end' => '16:30'],
    'D8' => ['name' => '第八節', 'start' => '16:40', 'end' => '17:30'],
    'E0' => ['name' => '第九節', 'start' => '17:40', 'end' => '18:30'],
    'E1' => ['name' => '夜一節', 'start' => '18:40', 'end' => '19:30'],
    'E2' => ['name' => '夜二節', 'start' => '19:35', 'end' => '20:20'],
    'E3' => ['name' => '夜三節', 'start' => '20:30', 'end' => '21:20']
];

// 處理表單提交
if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $club_name = $_POST['club_name'] ?? '';
    $event_name = $_POST['event_name'] ?? '';
    $event_date = $_POST['event_date'] ?? '';
    $start_period = $_POST['start_period'] ?? '';
    $end_period = $_POST['end_period'] ?? '';
    $venue_id = $_POST['venue_id'] ?? '';
    $expected_attendees = $_POST['expected_attendees'] ?? '';
    $description = $_POST['description'] ?? '';

    // 驗證必填欄位
    $errors = [];

    if (empty($event_name)) $errors[] = "請填寫活動名稱";
    if (empty($club_name)) $errors[] = "請填寫社團名稱";
    if (empty($event_date)) $errors[] = "請選擇活動日期";
    if (empty($start_period) || empty($end_period)) $errors[] = "請選擇活動時間";
    if (empty($venue_id)) $errors[] = "請選擇場地";
    if (!isset($_FILES['event_document']) || $_FILES['event_document']['error'] == UPLOAD_ERR_NO_FILE) {
        $errors[] = "請上傳已簽署的活動申請表(PDF)";
    }
    
    // 驗證節次選擇
    if (!empty($start_period) && !empty($end_period)) {
        if (!isset($time_periods[$start_period]) || !isset($time_periods[$end_period])) {
            $errors[] = "選擇的時間節次無效";
        }
    }

    if (empty($errors)) {
        // 開始事務
        $conn->begin_transaction();
    
    try {
        // --- 1. 處理檔案上傳 (放在最前面，失敗就直接進 catch) ---
        $base_dir = realpath(__DIR__ . DIRECTORY_SEPARATOR . '..'); 
        $upload_dir = $base_dir . DIRECTORY_SEPARATOR . 'document' . DIRECTORY_SEPARATOR;

        // 確保目錄存在
        if (!is_dir($upload_dir)) {
            mkdir($upload_dir, 0777, true);
        }

        $file_ext = pathinfo($_FILES['event_document']['name'], PATHINFO_EXTENSION);
        $new_filename = "event_" . time() . "_" . uniqid() . "." . $file_ext;
        $target_path = $upload_dir . $new_filename;
        
        // 在 move_uploaded_file 之前加入這行來測試路徑
        if (!is_writable($upload_dir)) {
            throw new Exception("資料夾不可寫入: " . realpath($upload_dir));
        }

        if (!move_uploaded_file($_FILES['event_document']['tmp_name'], $target_path)) {
            // 檢查上傳錯誤碼
            $error_code = $_FILES['event_document']['error'];
            throw new Exception("搬移失敗。錯誤碼: $error_code (路徑: $target_path)");
        }

        // --- 2. 準備時間與變數 ---
        $event_start = $event_date . " " . $time_periods[$start_period]['start'] . ":00";
        $event_end = $event_date . " " . $time_periods[$end_period]['end'] . ":00";
        $venue_id = intval($venue_id);
        $user_id = $_SESSION['user_id'] ?? null; // 注意：確認你的 session 鍵名是 user_id 還是 student_id
        $empty_note = ""; 
        
        if (!$user_id) throw new Exception("登入逾時，請重新登入。");
        // --- 3. 場地衝突檢查 ---
            $stmt_conflict = $conn->prepare(
                "SELECT e.club_name, r.created_at
                 FROM reservations r
                 JOIN events e ON r.event_id = e.event_id
                 WHERE r.space_id = ?
                   AND NOT (r.end_time <= ? OR r.start_time >= ?)
                   AND e.club_name != ?
                 ORDER BY r.created_at ASC
                 LIMIT 1"
            );
            $stmt_conflict->bind_param("isss", $venue_id, $event_start, $event_end, $club_name);
            $stmt_conflict->execute();
            $conflict_result = $stmt_conflict->get_result();

            if ($conflict_result && $conflict_result->num_rows > 0) {
                throw new Exception("該時段場地已被其他社團預約，請選擇其他時間或場地。如果是同社團，該時段仍可保留使用權。");
            }
            $stmt_conflict->close();

            // --- 4. 插入活動記錄 (修正欄位與 bind_param) ---
            // 這裡我們只插入 8 個有變數的欄位，status 在 SQL 裡直接給預設值 'pending'
            // --- 修改後的 SQL 語法 ---
            // --- 4. 插入活動記錄 (依照資料庫結構修正) ---
            // 依照你的 SQL 結構，最穩定的 INSERT 寫法
            $sql_event = "INSERT INTO events (
                user_id, 
                event_name, 
                club_name, 
                description, 
                start_time, 
                end_time, 
                document_path, 
                review_note
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?)"; // 8 個欄位對應 8 個問號

            $stmt_event = $conn->prepare($sql_event);

            if (!$stmt_event) {
                throw new Exception("SQL 預理失敗: " . $conn->error);
            }

            // 綁定 8 個參數
            // i = integer (user_id)
            // s = string (其餘)
            $stmt_event->bind_param("isssssss", 
                $user_id,       // 對應 user_id
                $event_name,    // 對應 event_name
                $club_name,     // 對應 club_name
                $description,   // 對應 description
                $event_start,   // 對應 start_time
                $event_end,     // 對應 end_time
                $new_filename,  // 對應 document_path (這就是你的 PDF 檔名)
                $empty_note     // 對應 review_note (預設給空字串)
            );

            if (!$stmt_event->execute()) {
                throw new Exception("活動記錄插入失敗: " . $stmt_event->error);
            }
            $event_id = $conn->insert_id;
            $stmt_event->close();
            
            // --- 5. 插入預約記錄 ---
            $stmt_reserve = $conn->prepare("INSERT INTO reservations (event_id, space_id, start_time, end_time) VALUES (?, ?, ?, ?)");
            $stmt_reserve->bind_param("iiss", $event_id, $venue_id, $event_start, $event_end);
            if (!$stmt_reserve->execute()) throw new Exception("預約記錄失敗");
            $stmt_reserve->close();
            
            // 處理器材選擇
            if (isset($_POST['equipment']) && is_array($_POST['equipment'])) {
                $stmt_borrow = $conn->prepare(
                    "INSERT INTO equipment_borrow (event_id, equipment_id, quantity) 
                     VALUES (?, ?, ?)"
                );
                
                foreach ($_POST['equipment'] as $equip_id => $quantity) {
                    $quantity = intval($quantity);

                    // ⭐ 查詢器材限制與已借用數量（精確到秒的先搶先贏）
                    $stmt_check = $conn->prepare(
                        "SELECT eq.available_quantity, eq.borrowing_limit, eq.total_quantity,
                                COALESCE(SUM(eb.quantity), 0) as already_borrowed
                         FROM equipment eq
                         LEFT JOIN equipment_borrow eb ON eq.equipment_id = eb.equipment_id
                         WHERE eq.equipment_id = ?
                         GROUP BY eq.equipment_id"
                    );
                    if (!$stmt_check) {
                        throw new Exception("資料庫錯誤：" . $conn->error);
                    }
                    $stmt_check->bind_param("i", $equip_id);
                    $stmt_check->execute();
                    $result = $stmt_check->get_result()->fetch_assoc();
                    $stmt_check->close();

                    if (!$result) {
                        throw new Exception("找不到器材資料");
                    }

                    // ⭐ 計算最大可借（基於已借用的實時統計）
                    $actualAvailable = $result['total_quantity'] - $result['already_borrowed'];
                    $maxAllowed = ($result['borrowing_limit'] > 0)
                        ? min($actualAvailable, $result['borrowing_limit'])
                        : $actualAvailable;

                    // ❌ 超過限制 → 擋掉
                    if ($quantity > $maxAllowed) {
                        throw new Exception("器材 {$equip_id} 超過可借上限（最多 {$maxAllowed}）。因為先搶先贏機制，請重新提交或選擇其他器材。");
                    }

                    // ✅ 正常寫入
                    if ($quantity > 0) {
                        $stmt_borrow->bind_param("iii", $event_id, $equip_id, $quantity);

                        if (!$stmt_borrow->execute()) {
                            throw new Exception("器材借用記錄插入失敗: " . $stmt_borrow->error);
                        }
                    }
                }
                
                $stmt_borrow->close();
            }
            
            // ✅ 提交事務
            $conn->commit();
           
            $message = "✅ 活動申請已提交成功！申請編號：#" . $event_id . "。我們將在2個工作天內審核您的申請。";
            $message_type = "success";
            
        } catch (Exception $e) {
            // 回滾事務
            $conn->rollback();
            $message = "❌ 申請失敗：" . $e->getMessage();
            $message_type = "error";
        }
    } else {
        $message = "❌ " . implode("<br>", $errors);
        $message_type = "error";
    }
}

// 輔助函數
function getEquipmentIcon($equipId) {
    return 'tools'; // 或你想要的 icon
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
        .venue-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(280px, 1fr));
            gap: 1rem;
        }
        .category-block {
            margin-bottom: 1.5rem;
        }
        .category-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 0.95rem 1rem;
            border-radius: 14px;
            background: #ffffff;
            border: 1px solid #e5e7eb;
            cursor: pointer;
            transition: background 0.2s ease;
        }
        .category-header:hover {
            background: #faf9ff;
        }
        .category-title {
            font-size: 1rem;
            font-weight: 700;
            color: #4b1a38;
        }
        .category-toggle {
            display: inline-flex;
            align-items: center;
            gap: 0.35rem;
            font-size: 0.95rem;
            color: #6b7280;
        }
        .category-body {
            margin-top: 1rem;
            display: none;
        }
        .category-body.open {
            display: block;
        }
        .venue-card {
            border: 2px solid #e5e7eb;
            border-radius: 12px;
            padding: 1rem;
            cursor: pointer;
            transition: all 0.25s ease;
        }
        .venue-card:hover {
            border-color: var(--primary);
            box-shadow: 0 4px 15px rgba(139, 21, 56, 0.1);
        }
        .venue-card.selected {
            border-color: var(--primary);
            background: rgba(139, 21, 56, 0.05);
        }
        .venue-card .venue-header {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            margin-bottom: 0.75rem;
        }
        .venue-card .venue-name {
            font-weight: 600;
            font-size: 1.1rem;
        }
        .venue-status {
            padding: 0.25rem 0.5rem;
            border-radius: 999px;
            font-size: 0.8rem;
            font-weight: 600;
            background: #d1e7dd;
            color: #0f5132;
        }
        .venue-availability {
            margin-top: 0.9rem;
            font-size: 0.9rem;
            color: #475569;
            line-height: 1.5;
            min-height: 1.8rem;
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
        .stock-available { color: var(--success); font-weight: 600; }
        .stock-low { color: var(--warning); font-weight: 600; }
        .stock-empty { color: var(--danger); font-weight: 600; }
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
        .equipment-content {
            transition: all 0.3s ease;
            max-height: 2000px;
            overflow: hidden;
        }
        .equipment-content.collapsed {
            max-height: 0;
            overflow: hidden;
            padding: 0 !important;
            margin: 0 !important;
        }
        .toggle-btn {
            transition: transform 0.3s ease;
        }
        .toggle-btn.collapsed {
            transform: rotate(-90deg);
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
        @media (max-width: 1100px) {
            .venue-grid, .equipment-grid { grid-template-columns: 1fr; }
            .main-content { margin-left: 0; }
        }
        @media (max-width: 768px) {
            .top-navbar { flex-direction: column; align-items: flex-start; gap: 1rem; padding: 1rem; }
            .sidebar { position: relative; width: 100%; height: auto; }
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
                <h4 class="mt-2 mb-0">新增活動申請</h4>
            </div>
        </header>

        <section class="content-wrapper">
            <?php if($message): ?>
            <div class="message <?= $message_type ?>">
                <?= $message ?>
            </div>
            <?php endif; ?>

            <form method="POST" id="applicationForm" enctype="multipart/form-data">
                <!-- 基本資訊 -->
                <div class="card">
                    <h3><i class="bi bi-info-circle"></i> 基本資訊</h3>
                    <div class="form-section">
                        <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 1rem;">
                            <div class="form-group">
                                <label for="event_name">活動名稱 *</label>
                                <input type="text" id="event_name" name="event_name" class="form-control" required>
                            </div>
                            <div class="form-group">
                                <label for="club_name">主辦社團 *</label>
                                <input type="text" id="club_name" name="club_name" class="form-control" required>
                            </div>
                        </div>

                        <div style="display: grid; grid-template-columns: 1fr 1fr 1fr; gap: 1rem;">
                            <div class="form-group">
                                <label for="event_date">活動日期 *</label>
                                <input type="date" id="event_date" name="event_date" class="form-control" required>
                            </div>
                            <div class="form-group">
                                <label for="start_period">開始節次 *</label>
                                <select id="start_period" name="start_period" class="form-control" required>
                                    <option value="">-- 請選擇開始節次 --</option>
                                    <?php foreach ($time_periods as $code => $period): ?>
                                        <option value="<?= $code ?>">
                                            <?= $period['name'] ?> (<?= $period['start'] ?>)
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="form-group">
                                <label for="end_period">結束節次 *</label>
                                <select id="end_period" name="end_period" class="form-control" required>
                                    <option value="">-- 請選擇結束節次 --</option>
                                    <?php foreach ($time_periods as $code => $period): ?>
                                        <option value="<?= $code ?>">
                                            <?= $period['name'] ?> (<?= $period['end'] ?>)
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>

                        <div class="form-group">
                            <label for="description">活動說明</label>
                            <textarea id="description" name="description" class="form-control" rows="3" placeholder="請簡述活動內容及特別需求..."></textarea>
                        </div>
                    </div>
                </div>

                <!-- 場地選擇 -->
                <div class="card">
                    <h3><i class="bi bi-geo-alt"></i> 場地選擇</h3>
                    <div class="form-section">
                    <?php foreach ($venue_categories as $category => $categoryVenues): ?>
                        <div class="category-block">
                            <div class="category-header" onclick="toggleCategory(this)">
                                <div class="category-title"><?= htmlspecialchars($category) ?></div>
                                <div class="category-toggle"><span>展開</span> <i class="bi bi-chevron-down"></i></div>
                            </div>
                            <div class="category-body">
                                <div class="venue-grid">
                                    <?php foreach ($categoryVenues as $venue): ?>
                                        <div class="venue-card" data-venue-id="<?= $venue['space_id'] ?>" onclick="selectVenue(<?= $venue['space_id'] ?>)">
                                            <div class="venue-header">
                                                <div class="venue-name"><?= htmlspecialchars($venue['space_name']) ?></div>
                                                <div class="venue-status">可預約</div>
                                            </div>
                                            <div class="venue-availability" id="availability-<?= $venue['space_id'] ?>">請先選擇日期查看可用時間。</div>
                                            <input type="radio" name="venue_id" value="<?= $venue['space_id'] ?>" style="display: none;">
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
                </div>

                <!-- 器材借用 (可選) -->
                <div class="card">
                    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 0.5rem;">
                        <h3 style="margin: 0; display: flex; align-items: center; gap: 0.5rem;">
                            <i class="bi bi-tools"></i> 器材借用 <span style="font-size: 0.85rem; color: #6b7280; font-weight: 400;">(可選)</span>
                        </h3>
                        <button type="button" id="equipmentToggle" onclick="toggleEquipmentSection()" class="toggle-btn" style="border: none; background: transparent; color: #8B1538; cursor: pointer; padding: 0.5rem; font-size: 1rem;">
                            <i class="bi bi-chevron-down"></i>
                        </button>
                    </div>
                    <p style="color: #6b7280; font-size: 0.9rem; margin-bottom: 1rem;">只需要申請活動與場地的社團可以跳過此部分。需要借用器材的社團請點擊下方展開填寫。</p>
                    
                    <div id="equipmentContent" class="equipment-content" style="display: none;">
                        <div class="form-section">
                            <div class="equipment-grid">
                                <?php foreach ($equipment as $item): ?>
                                <div class="equipment-card">
                                    <div class="equipment-header">
                                        <div class="equipment-name">
                                            <i class="bi bi-<?= getEquipmentIcon($item['id']) ?>"></i>
                                            <?= htmlspecialchars($item['name']) ?>
                                        </div>
                                        <div class="equipment-stock" style="text-align: right; line-height: 1.4;">
                                            <div class="stock-<?= $item['available'] > 0 ? ($item['available'] < 3 ? 'low' : 'available') : 'empty' ?>">
                                                剩餘: <?= $item['available'] ?>/<?= $item['total'] ?>
                                            </div>

                                            <div style="font-size: 0.8rem; color: #6b7280;">
                                                上限: <?= $item['limit'] > 0 ? $item['limit'] : '不限' ?>
                                            </div>
                                        </div>
                                            
                                    </div>
                                    <div class="counter">
                                        <button type="button" onclick="changeQuantity(<?= $item['id'] ?>, -1)" <?= $item['available'] == 0 ? 'disabled' : '' ?>>-</button>
                                        
                                        <?php
                                        $maxBorrow = ($item['limit'] > 0) 
                                            ? min($item['available'], $item['limit']) 
                                            : $item['available'];
                                        ?>

                                        <input type="number" id="qty_<?= $item['id'] ?>" 
                                        name="equipment[<?= $item['id'] ?>]" 
                                        value="0" min="0" 
                                        max="<?= $maxBorrow ?>" 
                                        readonly>

                                        <button type="button" onclick="changeQuantity(<?= $item['id'] ?>, 1)" <?= $item['available'] == 0 ? 'disabled' : '' ?>>+</button>
                                    </div>
                                </div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="card">
                <h3><i class="bi bi-file-earmark-arrow-up"></i> 三單下載與上傳</h3>
                <div class="form-section">
                    <div class="row align-items-center">
                        <div class="col-md-6">
                            <p class="mb-2"><strong>第一步：下載空白三單</strong></p>
                            <a href="../document/活動申請表(黃單)1141120.docx" class="btn btn-outline-secondary btn-sm" download>
                                <i class="bi bi-download"></i> 下載空白申請表 (範本)
                            </a>
                            <p class="text-muted small mt-2">請填寫完整並加蓋社團公章後掃描上傳。</p>
                        </div>
                        <div class="col-md-6">
                            <div class="form-group">
                                <label for="event_document"><strong>第二步：上傳已簽署三單 *</strong></label>
                                <input type="file" id="event_document" name="event_document" class="form-control" accept=".pdf" required>
                                <div class="form-text">僅接受 PDF檔，檔案大小限制 5MB。</div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

                <button type="submit" class="btn-submit"><i class="bi bi-send"></i> 提交申請</button>
            </form>
        </section>
    </main>

    <script>
        const reservationData = <?= json_encode($reservation_data, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP); ?>;

        function toggleCategory(header) {
            const body = header.nextElementSibling;
            const toggleText = header.querySelector('.category-toggle span');
            const icon = header.querySelector('.category-toggle i');
            if (!body) return;
            const open = body.classList.toggle('open');
            toggleText.textContent = open ? '收合' : '展開';
            icon.className = open ? 'bi bi-chevron-up' : 'bi bi-chevron-down';
        }

        function selectVenue(venueId) {
            document.querySelectorAll('.venue-card').forEach(card => card.classList.remove('selected'));
            const selectedCard = document.querySelector(`[data-venue-id="${venueId}"]`);
            if (selectedCard) {
                selectedCard.classList.add('selected');
            }
            const input = document.querySelector(`input[name="venue_id"][value="${venueId}"]`);
            if (input) {
                input.checked = true;
            }
        }

        function changeQuantity(equipId, delta) {
            const input = document.getElementById('qty_' + equipId);
            const max = parseInt(input.getAttribute('max')) || 0;
            let value = parseInt(input.value) + delta;
            if (value < 0) value = 0;
            if (value > max) value = max;
            input.value = value;
        }

        function toggleEquipmentSection() {
            const content = document.getElementById('equipmentContent');
            const toggle = document.getElementById('equipmentToggle');
            
            if (content.style.display === 'none') {
                content.style.display = 'block';
                toggle.innerHTML = '<i class="bi bi-chevron-up"></i>';
            } else {
                content.style.display = 'none';
                toggle.innerHTML = '<i class="bi bi-chevron-down"></i>';
            }
        }

        function parseDateTime(dateTime) {
            return new Date(dateTime.replace(' ', 'T'));
        }

        function formatTime(dateTime) {
            const date = parseDateTime(dateTime);
            return date.toLocaleTimeString('zh-TW', { hour: '2-digit', minute: '2-digit' });
        }

        function getAvailabilityText(venueId, dateValue) {
            if (!dateValue) {
                return '請先選擇日期查看可用時間。';
            }

            const reservations = reservationData.filter(r => {
                const dt = parseDateTime(r.start_time);
                return dt.toISOString().slice(0, 10) === dateValue && r.space_id == venueId;
            }).map(r => ({
                start: parseDateTime(r.start_time),
                end: parseDateTime(r.end_time),
                club: r.club_name
            }));

            if (!reservations.length) {
                return '當日全天可用。';
            }

            reservations.sort((a, b) => a.start - b.start);
            const reservedText = reservations.map(r => `${formatTime(r.start)} - ${formatTime(r.end)}`).join('，');
            return `已被預約：${reservedText}`;
        }

        function updateAvailability() {
            const dateValue = document.getElementById('event_date').value;
            document.querySelectorAll('.venue-card').forEach(card => {
                const venueId = card.getAttribute('data-venue-id');
                const availability = document.getElementById(`availability-${venueId}`);
                if (availability) {
                    availability.textContent = getAvailabilityText(venueId, dateValue);
                }
            });
        }

        document.getElementById('event_date').addEventListener('change', updateAvailability);
        document.getElementById('start_period').addEventListener('change', updateAvailability);
        document.getElementById('end_period').addEventListener('change', updateAvailability);
        document.addEventListener('DOMContentLoaded', function() {
            updateAvailability();
        });

        document.getElementById('applicationForm').addEventListener('submit', function(e) {
            const startPeriod = document.getElementById('start_period').value;
            const endPeriod = document.getElementById('end_period').value;
            const venueSelected = document.querySelector('input[name="venue_id"]:checked');

            if (!startPeriod || !endPeriod) {
                e.preventDefault();
                alert('請選擇開始和結束節次！');
                return false;
            }

            if (!venueSelected) {
                e.preventDefault();
                alert('請選擇活動場地！');
                return false;
            }
        });
    </script>
</body>
</html>

