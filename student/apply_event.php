<?php
ini_set('display_errors', 1);
error_reporting(E_ALL);

require_once(__DIR__ . "/../DB/db_config.php");

if (!isset($_SESSION['student_id'])) {
    header('Location: ../login.php');
    exit();
}

$message = "";
$message_type = "";

// 從資料庫獲取場地
$sql_spaces = "SELECT space_id, space_name, capacity FROM spaces WHERE status = 'available'";
$result_spaces = $conn->query($sql_spaces);
$venues = [];
if ($result_spaces) {
    $venues = $result_spaces->fetch_all(MYSQLI_ASSOC);
}

// 從資料庫獲取器材
$sql_equipment = "SELECT equipment_id, name, total_quantity, available_quantity FROM equipment WHERE status = 'available'";
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
            'unit' => '件'
        ];
    }
}

// 處理表單提交
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
    // 修正後的必填文件檢查
    if (!isset($_FILES['event_document']) || $_FILES['event_document']['error'] == UPLOAD_ERR_NO_FILE) {
        $errors[] = "請上傳已簽署的活動申請表(PDF)";
    }
    if (!isset($_FILES['venue_document']) || $_FILES['venue_document']['error'] == UPLOAD_ERR_NO_FILE) {
        $errors[] = "請上傳已簽署的場地申請表(PDF)";
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

        // --- 1. 處理 3 個檔案的上傳 ---
        $files_to_upload = [
            'event_document' => ['required' => true, 'prefix' => 'event_'],
            'venue_document' => ['required' => true, 'prefix' => 'venue_'],
            'equipment_document' => ['required' => false, 'prefix' => 'equip_']
        ];

        $uploaded_filenames = [
            'event_document' => null,
            'venue_document' => null,
            'equipment_document' => null
        ];

        foreach ($files_to_upload as $field_name => $config) {
            if (isset($_FILES[$field_name]) && $_FILES[$field_name]['error'] == UPLOAD_ERR_OK) {
                $file_ext = pathinfo($_FILES[$field_name]['name'], PATHINFO_EXTENSION);
                $new_filename = $config['prefix'] . time() . "_" . uniqid() . "." . $file_ext;
                $target_path = $upload_dir . $new_filename;

                if (move_uploaded_file($_FILES[$field_name]['tmp_name'], $target_path)) {
                    $uploaded_filenames[$field_name] = $new_filename;
                } else {
                    throw new Exception($field_name . " 檔案搬移失敗。");
                }
            } elseif ($config['required']) {
                throw new Exception("請務必上傳必填的申請表單！");
            }
        }

        // --- 2. 準備時間與變數 ---
        $event_start = $event_date . " " . $start_time . ":00";
        $event_end = $event_date . " " . $end_time . ":00";   
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
                user_id, event_name, club_name, description, 
                start_time, end_time, document_path, venue_doc_path, equipment_doc_path, 
                review_note
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";

            $stmt_event = $conn->prepare($sql_event);
            if (!$stmt_event) { throw new Exception("SQL 準備失敗: " . $conn->error); }

            // 綁定 10 個參數: i + 9個s
            $stmt_event->bind_param("isssssssss", 
                $user_id,                               // 1
                $event_name,                            // 2
                $club_name,                             // 3
                $description,                           // 4
                $event_start,                           // 5
                $event_end,                             // 6
                $uploaded_filenames['event_document'],  // 7 (活動單檔名)
                $uploaded_filenames['venue_document'],  // 8 (場地單檔名)
                $uploaded_filenames['equipment_document'], // 9 (器材單檔名，可為 null)
                $empty_note                             // 10
            );

            if (!$stmt_event->execute()) { throw new Exception("活動記錄插入失敗: " . $stmt_event->error); }
            $event_id = $conn->insert_id;
            $stmt_event->close();
            
            // 插入預約記錄
            $stmt_reserve = $conn->prepare(
                "INSERT INTO reservations (event_id, space_id, start_time, end_time) 
                 VALUES (?, ?, ?, ?)"
            );
            $stmt_reserve->bind_param("iiss", $event_id, $venue_id, $event_start, $event_end);
            
            if (!$stmt_reserve->execute()) {
                throw new Exception("預約記錄插入失敗: " . $stmt_reserve->error);
            }
            
            $stmt_reserve->close();
            
            // 處理器材選擇
            if (isset($_POST['equipment']) && is_array($_POST['equipment'])) {
                // 嘗試使用新的字段，如果失敗則使用舊字段
                $stmt_borrow = $conn->prepare(
                    "INSERT INTO equipment_borrow (event_id, equipment_id, quantity, status, created_at) 
                     VALUES (?, ?, ?, 'pending', NOW())"
                );
                
                if (!$stmt_borrow) {
                    // 如果新字段不存在，使用舊的 INSERT 語句
                    $stmt_borrow = $conn->prepare(
                        "INSERT INTO equipment_borrow (event_id, equipment_id, quantity) 
                         VALUES (?, ?, ?)"
                    );
                }
                
                foreach ($_POST['equipment'] as $equip_id => $quantity) {
                    $quantity = intval($quantity);
                    if ($quantity > 0) {
                        // 使用 equipment_id 查詢器材
                        $stmt_borrow->bind_param("iii", $event_id, $equip_id, $quantity);
                        
                        if (!$stmt_borrow->execute()) {
                            throw new Exception("器材借用記錄插入失敗: " . $stmt_borrow->error);
                        }
                    }
                }
                
                $stmt_borrow->close();
            }
            
            // 提交事務
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
    $icons = [
        1 => 'mic-fill',        // 投影機
        2 => 'speaker-fill',    // 音響設備
        3 => 'chair',           // 折疊椅
        4 => 'table'            // 長桌
    ];
    return $icons[$equipId] ?? 'tools';
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
        .venue-capacity {
            color: #6b7280;
            font-size: 0.9rem;
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
    <aside class="sidebar">
        <div class="brand">
            <h4>輔仁大學<br>課外活動指導組</h4>
        </div>
        <nav class="nav flex-column">
            <a class="nav-link" href="dashboard.php"><i class="bi bi-house-door"></i> 儀表板</a>
            <a class="nav-link" href="calendar.php"><i class="bi bi-calendar3"></i> 行事曆</a>
            <a class="nav-link" href="my_applications.php"><i class="bi bi-bookmark"></i> 我的申請</a>
            <a class="nav-link active" href="apply_event.php"><i class="bi bi-calendar-plus"></i> 新增申請</a>
        </nav>
        <div class="sidebar-section">
            <p class="mb-2">快捷操作</p>
            <a class="nav-link" href="../logout.php"><i class="bi bi-box-arrow-right"></i> 登出系統</a>
        </div>
    </aside>

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
                                <label>開始時間 *</label>
                                <input type="time" id="start_time" name="start_time" class="form-control" required>
                            </div>
                            <div class="form-group">
                                <label>結束時間 *</label>
                                <input type="time" id="end_time" name="end_time" class="form-control" required>
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
                        <div class="venue-grid">
                            <?php foreach ($venues as $venue): ?>
                            <div class="venue-card" data-venue-id="<?= $venue['space_id'] ?>" onclick="selectVenue(<?= $venue['space_id'] ?>)">
                                <div class="venue-header">
                                    <div class="venue-name"><?= htmlspecialchars($venue['space_name']) ?></div>
                                    <div class="venue-status">可預約</div>
                                </div>
                                <div class="venue-capacity"><i class="bi bi-people"></i> 容納：<?= $venue['capacity'] ?> 人</div>
                                <input type="radio" name="venue_id" value="<?= $venue['space_id'] ?>" style="display: none;">
                            </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </div>

                <div class="card">
                    <h3><i class="bi bi-tools"></i> 器材借用</h3>
                    <div class="form-section">
                        <div class="equipment-grid">
                            <?php foreach ($equipment as $item): ?><div class="equipment-card">
                                <div class="equipment-header">
                                    <div class="equipment-name">
                                        <i class="bi bi-<?= getEquipmentIcon($item['id']) ?>"></i>
                                        <?= htmlspecialchars($item['name']) ?>
                                    </div>
                                    <div class="equipment-stock">
                                        <div class="stock-<?= $item['available'] > 0 ? ($item['available'] < 3 ? 'low' : 'available') : 'empty' ?>">
                                            剩餘: <?= $item['available'] ?>/<?= $item['total'] ?>
                                        </div>                                            
                                    </div>
                                </div>
                                <div class="counter mt-2">
                                    <button type="button" onclick="changeQuantity(<?= $item['id'] ?>, -1)" <?= $item['available'] == 0 ? 'disabled' : '' ?>>-</button>
                                    
                                    <?php $maxBorrow = $item['available']; ?>

                                    <input type="number" id="qty_<?= $item['id'] ?>" 
                                    name="equipment[<?= $item['id'] ?>]" 
                                    value="0" min="0" 
                                    max="<?= $maxBorrow ?>" 
                                    readonly>

                                    <button type="button" onclick="changeQuantity(<?= $item['id'] ?>, 1)" <?= $item['available'] == 0 ? 'disabled' : '' ?>>+</button>
                                </div>
                            </div><?php endforeach; ?>
                        </div>
                    </div>
                </div>

                <div class="card">
                    <h3><i class="bi bi-file-earmark-arrow-up"></i> 三單下載與上傳</h3>
                    <div class="form-section">
                        <div class="row align-items-center">
                            <div class="col-md-6">
                                <p class="mb-2"><strong>下載空白三單</strong></p>
                                <p>
                                <div><a href="../document/活動申請表(黃單)1141120.docx" class="btn btn-outline-secondary btn-sm" download>
                                    <i class="bi bi-download"></i> 下載活動申請表(黃單)
                                </a></div><br>
                                <div><a href="../document/例行活動場地核定登記表.docx" class="btn btn-outline-secondary btn-sm" download>
                                    <i class="bi bi-download"></i> 下載例行活動場地核定登記表
                                </a></div><br>
                                <div><a href="../document/課指組 器材借用申請表115.02.01.docx" class="btn btn-outline-secondary btn-sm" download>
                                    <i class="bi bi-download"></i> 下載器材借用申請表
                                </a></div>
                                </p>
                                <p class="text-muted small mt-2">請填寫完整並加蓋社團公章後掃描上傳。</p>
                            </div>
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label class="form-label">1. 活動申請單 (PDF) <span class="text-danger">*</span></label>
                                    <input type="file" name="event_document" class="form-control" accept=".pdf" required>
                                </div>

                                <div class="mb-3">
                                    <label class="form-label">2. 場地申請單 (PDF) <span class="text-danger">*</span></label>
                                    <input type="file" name="venue_document" class="form-control" accept=".pdf" required>
                                </div>

                                <div class="mb-3">
                                    <label class="form-label">3. 器材借用單 (PDF，若無借用可不傳)</label>
                                    <input type="file" name="equipment_document" class="form-control" accept=".pdf">
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
        function selectVenue(venueId) {
            document.querySelectorAll('.venue-card').forEach(card => card.classList.remove('selected'));
            const selectedCard = document.querySelector(`[data-venue-id="${venueId}"]`);
            selectedCard.classList.add('selected');
            document.querySelector(`input[name="venue_id"][value="${venueId}"]`).checked = true;
        }

        function changeQuantity(equipId, delta) {
            const input = document.getElementById('qty_' + equipId);
            const max = parseInt(input.getAttribute('max'));
            let value = parseInt(input.value) + delta;
            if (value < 0) value = 0;
            if (value > max) value = max;
            input.value = value;
        }

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
        });
    </script>
</body>
</html>
