<?php
session_start();

ini_set('display_errors', 1);
error_reporting(E_ALL);

require_once(__DIR__ . "/../DB/db_config.php");
require_once(__DIR__ . "/../includes/FieldCoordinationManager.php");

if (!isset($_SESSION['student_id'])) {
    header('Location: ../login.php');
    exit();
}

// 設置當前頁面用於側邊欄高亮
$current_page = 'apply_event';

$message = "";
$message_type = "";
$user_id = $_SESSION['user_id'] ?? null;
$field_coordination_results = [];
$fc_manager = null;
$prefill_event_name = '';
$prefill_club_name = '';
$prefill_event_date = '';
$prefill_start_time = '';
$prefill_end_time = '';
$prefill_end_date = '';
$prefill_venue_id = '';
if ($user_id) {
    $fc_manager = new FieldCoordinationManager($conn);
    $club_sql = "SELECT cm.club_id, c.club_name
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
            $field_coordination_results = $fc_manager->getAllApprovedFieldCoordinationForClub($club_row['club_id']);
            if (empty($field_coordination_results)) {
                $field_coordination_results = [];
            }
        }
        $club_stmt->close();
    }
}

// 從資料庫獲取場地
$sql_spaces = "SELECT space_id, space_name, capacity FROM spaces WHERE status = 'available'";
$result_spaces = $conn->query($sql_spaces);
$venues = [];
if ($result_spaces) {
    $venues = $result_spaces->fetch_all(MYSQLI_ASSOC);
}

// 從資料庫獲取器材
$sql_equipment = "SELECT equipment_id, name, total_quantity, borrowing_limit FROM equipment WHERE equipment_status = 'available'";
$result_equipment = $conn->query($sql_equipment);
if (!$result_equipment) {
    // 欄位名稱 fallback
    $sql_equipment = "SELECT equipment_id, name, total_quantity, borrowing_limit FROM equipment";
    $result_equipment = $conn->query($sql_equipment);
}
$equipment = [];
if ($result_equipment) {
    $equipment_list = $result_equipment->fetch_all(MYSQLI_ASSOC);
    foreach ($equipment_list as $eq) {
        $equipment[] = [
            'id'              => $eq['equipment_id'],
            'name'            => $eq['name'],
            'total'           => $eq['total_quantity'],
            'available'       => $eq['total_quantity'],
            'borrowing_limit' => (int)($eq['borrowing_limit'] ?? 0),
            'unit'            => '件'
        ];
    }
}

// 處理表單提交
if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $club_name = $_POST['club_name'] ?? '';
    $event_name = $_POST['event_name'] ?? '';
    $event_date = $_POST['event_date'] ?? '';
    $end_date   = $_POST['end_date']   ?? $event_date;
    $start_time = $_POST['start_time'] ?? '';
    $end_time   = $_POST['end_time']   ?? '';
    $venue_id = $_POST['venue_id'] ?? '';
    $expected_attendees = $_POST['expected_attendees'] ?? '';
    $description = $_POST['description'] ?? '';

    // 驗證必填欄位
    $errors = [];

    if (empty($event_name)) $errors[] = "請填寫活動名稱";
    if (empty($club_name)) $errors[] = "請填寫社團名稱";
    if (empty($event_date)) $errors[] = "請選擇開始日期";
    if (empty($end_date))   $errors[] = "請選擇結束日期";
    if (empty($start_time) || empty($end_time)) $errors[] = "請填寫活動時間";
    if (!empty($start_time) && ($start_time < '08:30' || $start_time > '21:30'))
        $errors[] = "開始時間必須在 08:30 至 21:30 之間";
    if (!empty($end_time) && ($end_time < '08:30' || $end_time > '21:30'))
        $errors[] = "結束時間必須在 08:30 至 21:30 之間";
    if (!empty($event_date) && !empty($end_date) && $end_date < $event_date)
        $errors[] = "結束日期不能早於開始日期";
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
        $event_end   = $end_date   . " " . $end_time   . ":00";
        $venue_id = intval($venue_id);
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
                status
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 'pending')";

            $stmt_event = $conn->prepare($sql_event);
            if (!$stmt_event) { throw new Exception("SQL 準備失敗: " . $conn->error); }

            // 修正：綁定 9 個參數，並移除未定義變數與多餘參數
            $stmt_event->bind_param("issssssss", 
                $user_id,
                $event_name,
                $club_name,
                $description,
                $event_start,
                $event_end,
                $uploaded_filenames['event_document'],
                $uploaded_filenames['venue_document'],
                $uploaded_filenames['equipment_document']
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
                $stmt_borrow = $conn->prepare(
                    "INSERT INTO equipment_borrow (event_id, equipment_id, quantity) VALUES (?, ?, ?)"
                );
                if (!$stmt_borrow) {
                    throw new Exception("器材借用記錄準備失敗: " . $conn->error);
                }

                foreach ($_POST['equipment'] as $equip_id => $quantity) {
                    $quantity = intval($quantity);
                    if ($quantity > 0) {
                        $equip_id = intval($equip_id);
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
            --primary: #1e4d6b;
            --sidebar: #14394f;
            --sidebar-hover: #ece8dd;
            --bg: #f7f5ef;
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
            box-shadow: 0 0 0 3px rgba(30, 77, 107, 0.1);
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
            box-shadow: 0 4px 15px rgba(30, 77, 107, 0.1);
        }
        .venue-card.selected {
            border-color: var(--primary);
            background: rgba(30, 77, 107, 0.05);
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
            background: #fff;
            cursor: text;
        }
        .counter input:focus {
            outline: none;
            border-color: var(--primary);
            box-shadow: 0 0 0 2px rgba(30,77,107,0.12);
        }
        /* 隱藏瀏覽器預設的數字上下箭頭 */
        .counter input::-webkit-outer-spin-button,
        .counter input::-webkit-inner-spin-button { -webkit-appearance: none; margin: 0; }
        .counter input[type=number] { -moz-appearance: textfield; }
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
            box-shadow: 0 4px 15px rgba(30, 77, 107, 0.2);
        }
        .btn-submit:hover {
            background: var(--sidebar);
            transform: translateY(-2px);
            box-shadow: 0 6px 20px rgba(30, 77, 107, 0.3);
        }
        @media (max-width: 1100px) {
            .venue-grid, .equipment-grid { grid-template-columns: 1fr; }
            .main-content { margin-left: 0; }
        }
        @media (max-width: 768px) {
        .top-navbar { flex-direction: column; align-items: flex-start; gap: 1rem; padding: 1rem; }
            .sidebar { position: relative; width: 100%; height: auto; }
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

            <?php if (!empty($field_coordination_results)): ?>
            <div class="card">
                <h3><i class="bi bi-check-circle"></i> 場協登記結果選擇</h3>
                <p class="text-muted">社團有以下已核准的場協結果，點擊任一項可自動帶入相關資訊到下方表單。</p>
                <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(280px, 1fr)); gap: 1rem;">
                    <?php foreach ($field_coordination_results as $idx => $fc_result): ?>
                    <div class="field-coord-card" onclick="loadFieldCoordinationData(<?= $idx ?>, event)" style="cursor: pointer; border: 2px solid #e5e7eb; border-radius: 12px; padding: 1rem; transition: all 0.25s ease; background: white;">
                        <div style="display: flex; justify-content: space-between; align-items: start; margin-bottom: 0.5rem;">
                            <div>
                                <div style="font-weight: 700; color: #1f2937; font-size: 1.05rem;"><?= htmlspecialchars($fc_result['event_name'], ENT_QUOTES, 'UTF-8') ?></div>
                                <div style="font-size: 0.9rem; color: #6b7280;">民國 <?= htmlspecialchars($fc_result['academic_year'], ENT_QUOTES, 'UTF-8') ?> <?= $fc_result['semester'] == 1 ? '上學期' : '下學期' ?></div>
                            </div>
                            <input type="radio" name="field_coord_selection" value="<?= $idx ?>" style="margin-top: 0.25rem;">
                        </div>
                        <hr style="margin: 0.5rem 0; border: none; border-top: 1px solid #e5e7eb;">
                        <div style="font-size: 0.9rem; color: #374151;">
                            <div style="margin-bottom: 0.3rem;"><i class="bi bi-calendar-event"></i> <?= date('Y-m-d', strtotime($fc_result['start_time'])) ?></div>
                            <div style="margin-bottom: 0.3rem;"><i class="bi bi-clock"></i> <?= date('H:i', strtotime($fc_result['start_time'])) ?> - <?= date('H:i', strtotime($fc_result['end_time'])) ?></div>
                        </div>
                        <input type="hidden" class="fc-data" value='<?= json_encode($fc_result) ?>'>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
            <?php endif; ?>

            <?php if ($field_coordination_results): ?>
            <div class="alert alert-info" style="border-radius: 12px; margin-bottom: 1rem;">
                <strong>場協大會已通過</strong>，系統已找到社團可用的核准場協結果。請選擇一筆場協結果自動帶入相關欄位，再視需要調整活動名稱和說明。
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
                                <input type="text" id="event_name" name="event_name" class="form-control" value="<?= htmlspecialchars($_POST['event_name'] ?? $prefill_event_name, ENT_QUOTES, 'UTF-8') ?>" required>
                            </div>
                            <div class="form-group">
                                <label for="club_name">主辦社團 *</label>
                                <input type="text" id="club_name" name="club_name" class="form-control" value="<?= htmlspecialchars($_POST['club_name'] ?? $prefill_club_name, ENT_QUOTES, 'UTF-8') ?>" required>
                            </div>
                        </div>

                        <div style="display: grid; grid-template-columns: 1fr 1fr 1fr 1fr; gap: 1rem;">
                            <div class="form-group">
                                <label for="event_date">開始日期 *</label>
                                <input type="date" id="event_date" name="event_date" class="form-control" value="<?= htmlspecialchars($_POST['event_date'] ?? $prefill_event_date, ENT_QUOTES, 'UTF-8') ?>" required>
                            </div>
                            <div class="form-group">
                                <label for="start_time">開始時間 * <small class="text-muted">(08:30–21:30)</small></label>
                                <input type="time" id="start_time" name="start_time" class="form-control" min="08:30" max="21:30" step="1800" value="<?= htmlspecialchars($_POST['start_time'] ?? $prefill_start_time, ENT_QUOTES, 'UTF-8') ?>" required>
                            </div>
                            <div class="form-group">
                                <label for="end_date">結束日期 *</label>
                                <input type="date" id="end_date" name="end_date" class="form-control" value="<?= htmlspecialchars($_POST['end_date'] ?? $prefill_end_date, ENT_QUOTES, 'UTF-8') ?>" required>
                            </div>
                            <div class="form-group">
                                <label for="end_time">結束時間 * <small class="text-muted">(08:30–21:30)</small></label>
                                <input type="time" id="end_time" name="end_time" class="form-control" min="08:30" max="21:30" step="1800" value="<?= htmlspecialchars($_POST['end_time'] ?? $prefill_end_time, ENT_QUOTES, 'UTF-8') ?>" required>
                            </div>
                        </div>

                        <div class="form-group">
                            <label for="description">活動說明</label>
                            <textarea id="description" name="description" class="form-control" rows="3" placeholder="請簡述活動內容及特別需求..."><?= htmlspecialchars($_POST['description'] ?? '', ENT_QUOTES, 'UTF-8') ?></textarea>
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
                                <input type="radio" name="venue_id" value="<?= $venue['space_id'] ?>" style="display: none;" <?= (isset($_POST['venue_id']) && $_POST['venue_id'] == $venue['space_id']) || (!isset($_POST['venue_id']) && $prefill_venue_id == $venue['space_id']) ? 'checked' : '' ?>>
                            </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </div>

                <div class="card">
                    <h3><i class="bi bi-tools"></i> 器材借用</h3>
                    <div class="form-section">

                        <!-- 時段選擇 -->
                        <div style="background:#f0f4f8; border-radius:12px; padding:1rem 1.25rem; margin-bottom:1rem;">
                            <div style="font-weight:600; color:#1e4d6b; margin-bottom:0.75rem; font-size:0.95rem;">
                                <i class="bi bi-clock me-1"></i>選擇器材借用時段
                                <small style="font-weight:400; color:#6b7280; margin-left:0.5rem;">（可用量將依時段即時更新）</small>
                            </div>
                            <div style="display:grid; grid-template-columns:1fr 1fr auto; gap:0.75rem; align-items:flex-end;">
                                <div>
                                    <label style="font-size:0.85rem; color:#374151; display:block; margin-bottom:0.3rem;">
                                        借用時間 <small style="color:#9ca3af;">(09:30–16:30)</small>
                                    </label>
                                    <input type="datetime-local" id="equip_borrow_time" name="equip_borrow_time" class="form-control">
                                </div>
                                <div>
                                    <label style="font-size:0.85rem; color:#374151; display:block; margin-bottom:0.3rem;">
                                        歸還時間 <small style="color:#9ca3af;">(09:30–16:30)</small>
                                    </label>
                                    <input type="datetime-local" id="equip_return_time" name="equip_return_time" class="form-control">
                                </div>
                                <button type="button" onclick="queryEquipmentAvailability()"
                                    style="background:#1e4d6b; color:white; border:none; border-radius:8px; padding:0.65rem 1.2rem; font-weight:600; cursor:pointer; white-space:nowrap; transition:background 0.2s;"
                                    onmouseover="this.style.background='#14394f'" onmouseout="this.style.background='#1e4d6b'">
                                    <i class="bi bi-search me-1"></i>查詢可用數量
                                </button>
                            </div>
                            <div id="equipTimeWarning" style="display:none; margin-top:0.75rem; padding:0.6rem 0.9rem; background:#f0e8c0; border-radius:8px; color:#6b5a20; font-size:0.88rem;"></div>
                        </div>

                        <!-- 器材卡片 -->
                        <div class="equipment-grid">
                            <?php foreach ($equipment as $item):
                                $initMax = $item['borrowing_limit'] > 0
                                    ? min($item['available'], $item['borrowing_limit'])
                                    : $item['available'];
                                $stockClass = $item['available'] > 0 ? ($item['available'] < 3 ? 'low' : 'available') : 'empty';
                            ?>
                            <div class="equipment-card"
                                 data-equip-id="<?= $item['id'] ?>"
                                 data-total="<?= $item['total'] ?>"
                                 data-limit="<?= $item['borrowing_limit'] ?>">
                                <div class="equipment-header">
                                    <div class="equipment-name">
                                        <i class="bi bi-<?= getEquipmentIcon($item['id']) ?>"></i>
                                        <?= htmlspecialchars($item['name']) ?>
                                    </div>
                                    <div class="equipment-stock">
                                        <div class="avail-text stock-<?= $stockClass ?>">
                                            剩餘：<span class="avail-qty"><?= $item['available'] ?></span>/<?= $item['total'] ?>
                                        </div>
                                    </div>
                                </div>
                                <?php if ($item['borrowing_limit'] > 0): ?>
                                <div style="font-size:0.78rem; color:#9ca3af; margin-bottom:0.4rem;">
                                    <i class="bi bi-info-circle me-1"></i>每次借用上限：<?= $item['borrowing_limit'] ?> 件
                                </div>
                                <?php endif; ?>
                                <div class="counter mt-1">
                                    <button type="button" class="btn-minus"
                                            onclick="changeQuantity(<?= $item['id'] ?>, -1)"
                                            <?= $item['available'] == 0 ? 'disabled' : '' ?>>-</button>
                                    <input type="number"
                                           id="qty_<?= $item['id'] ?>"
                                           name="equipment[<?= $item['id'] ?>]"
                                           value="0" min="0"
                                           max="<?= $initMax ?>"
                                           data-borrowing-limit="<?= $item['borrowing_limit'] ?>"
                                           oninput="clampQtyInput(this)"
                                           onchange="syncQtyButtons(this)">
                                    <button type="button" class="btn-plus"
                                            onclick="changeQuantity(<?= $item['id'] ?>, 1)"
                                            <?= $item['available'] == 0 ? 'disabled' : '' ?>>+</button>
                                </div>
                            </div>
                            <?php endforeach; ?>
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

        // 取得有效上限（庫存 vs 借用上限取小值）
        function getEffectiveMax(input) {
            const availMax    = parseInt(input.getAttribute('max')) || 0;
            const borrowLimit = parseInt(input.getAttribute('data-borrowing-limit')) || 0;
            return borrowLimit > 0 ? Math.min(availMax, borrowLimit) : availMax;
        }

        // 手動輸入時即時 clamp（打字中）
        function clampQtyInput(input) {
            const effectiveMax = getEffectiveMax(input);
            let v = parseInt(input.value);
            if (isNaN(v) || v < 0) { input.value = 0; return; }
            if (v > effectiveMax)   { input.value = effectiveMax; }
        }

        // 輸入完成後同步按鈕狀態
        function syncQtyButtons(input) {
            clampQtyInput(input);
            const effectiveMax = getEffectiveMax(input);
            const availMax     = parseInt(input.getAttribute('max')) || 0;
            const v            = parseInt(input.value) || 0;
            const card = input.closest('.equipment-card');
            if (card) {
                card.querySelector('.btn-minus').disabled = (v <= 0);
                card.querySelector('.btn-plus').disabled  = (v >= effectiveMax) || (availMax <= 0);
            }
        }

        function changeQuantity(equipId, delta) {
            const input        = document.getElementById('qty_' + equipId);
            const effectiveMax = getEffectiveMax(input);
            let value = (parseInt(input.value) || 0) + delta;
            if (value < 0) value = 0;
            if (value > effectiveMax) value = effectiveMax;
            input.value = value;
            syncQtyButtons(input);
        }

        // ── 查詢器材可用數量 ──────────────────────────────────
        async function queryEquipmentAvailability() {
            const borrowTime = document.getElementById('equip_borrow_time').value;
            const returnTime = document.getElementById('equip_return_time').value;
            const warnDiv    = document.getElementById('equipTimeWarning');

            warnDiv.style.display = 'none';
            warnDiv.innerHTML = '';

            if (!borrowTime || !returnTime) {
                alert('請先選擇借用時間與歸還時間。'); return;
            }

            // ① 時段順序
            if (borrowTime >= returnTime) {
                alert('歸還時間必須晚於借用時間。'); return;
            }

            // ② 時間規範 09:30–16:30
            function timeMinutes(dtStr) {
                const d = new Date(dtStr);
                return d.getHours() * 60 + d.getMinutes();
            }
            const MIN = 9*60+30, MAX = 16*60+30;
            if (timeMinutes(borrowTime) < MIN || timeMinutes(borrowTime) > MAX ||
                timeMinutes(returnTime) < MIN || timeMinutes(returnTime) > MAX) {
                warnDiv.innerHTML = '<i class="bi bi-exclamation-triangle me-1"></i>器材借還時間須在 09:30–16:30 之間，請重新選擇。';
                warnDiv.style.display = 'block';
                return;
            }

            // ③ 與活動時間相容性檢查（提示，不阻擋）
            const actStart = document.getElementById('event_date').value;
            const actEnd   = document.getElementById('end_date').value;
            if (actStart && actEnd) {
                const btDate = borrowTime.split('T')[0];
                const rtDate = returnTime.split('T')[0];
                const warns  = [];
                if (btDate > actStart)
                    warns.push('借用日期（' + btDate + '）晚於活動開始日（' + actStart + '），建議提前借用器材。');
                if (rtDate < actEnd)
                    warns.push('歸還日期（' + rtDate + '）早於活動結束日（' + actEnd + '），建議活動結束後再歸還。');
                if (warns.length) {
                    warnDiv.innerHTML = '<i class="bi bi-exclamation-triangle me-1"></i>' + warns.join('<br>');
                    warnDiv.style.display = 'block';
                }
            }

            // ④ 呼叫 API 取得可用數量
            try {
                const res  = await fetch(`get_equipment_availability.php?borrow_time=${encodeURIComponent(borrowTime)}&return_time=${encodeURIComponent(returnTime)}`);
                const data = await res.json();

                document.querySelectorAll('.equipment-card[data-equip-id]').forEach(card => {
                    const id    = card.dataset.equipId;
                    const total = parseInt(card.dataset.total) || 0;
                    const limit = parseInt(card.dataset.limit) || 0;

                    const avail = (data[id] !== undefined) ? parseInt(data[id]) : total;
                    const effectiveMax = limit > 0 ? Math.min(avail, limit) : avail;

                    // 更新顯示
                    const qtySpan  = card.querySelector('.avail-qty');
                    const textDiv  = card.querySelector('.avail-text');
                    const input    = document.getElementById('qty_' + id);
                    const btnMinus = card.querySelector('.btn-minus');
                    const btnPlus  = card.querySelector('.btn-plus');

                    if (qtySpan) qtySpan.textContent = avail;
                    if (textDiv) textDiv.className = 'avail-text stock-' + (avail <= 0 ? 'empty' : avail <= 2 ? 'low' : 'available');

                    if (input) {
                        input.setAttribute('max', avail);
                        // 若已選數量超過新的可用量，自動調低
                        if (parseInt(input.value) > effectiveMax) input.value = effectiveMax;
                    }
                    if (btnMinus) btnMinus.disabled = !input || parseInt(input?.value) <= 0;
                    if (btnPlus)  btnPlus.disabled  = avail <= 0;
                });
            } catch (err) {
                alert('查詢失敗，請稍後再試。');
            }
        }

        // ── 器材時間 clamp（09:30–16:30）──────────────────────
        function clampEquipTime(inputId) {
            const input = document.getElementById(inputId);
            if (!input) return;
            function clamp() {
                if (!input.value) return;
                const dt = new Date(input.value);
                const min = dt.getHours() * 60 + dt.getMinutes();
                if (min < 9*60+30) dt.setHours(9, 30, 0, 0);
                else if (min > 16*60+30) dt.setHours(16, 30, 0, 0);
                else return;
                const pad = n => String(n).padStart(2, '0');
                input.value = `${dt.getFullYear()}-${pad(dt.getMonth()+1)}-${pad(dt.getDate())}T${pad(dt.getHours())}:${pad(dt.getMinutes())}`;
            }
            input.addEventListener('change', clamp);
            input.addEventListener('blur', clamp);
        }
        clampEquipTime('equip_borrow_time');
        clampEquipTime('equip_return_time');

        document.getElementById('applicationForm').addEventListener('submit', function(e) {
            const startDate = document.getElementById('event_date').value;
            const endDate   = document.getElementById('end_date').value;
            const startTime = document.getElementById('start_time').value;
            const endTime   = document.getElementById('end_time').value;
            const venueSelected = document.querySelector('input[name="venue_id"]:checked');

            if (startDate && endDate && startDate > endDate) {
                e.preventDefault();
                alert('結束日期不能早於開始日期！');
                return false;
            }

            const startDT = startDate + 'T' + startTime;
            const endDT   = endDate   + 'T' + endTime;
            if (startTime && endTime && startDT >= endDT) {
                e.preventDefault();
                alert('結束時間必須晚於開始時間！');
                return false;
            }

            // 時間範圍檢查 08:30–21:30
            const min = '08:30', max = '21:30';
            if (startTime && (startTime < min || startTime > max)) {
                e.preventDefault();
                alert('開始時間必須在 08:30 至 21:30 之間！');
                return false;
            }
            if (endTime && (endTime < min || endTime > max)) {
                e.preventDefault();
                alert('結束時間必須在 08:30 至 21:30 之間！');
                return false;
            }

            if (!venueSelected) {
                e.preventDefault();
                alert('請選擇活動場地！');
                return false;
            }

            // ── 器材借用時間驗證（如有選器材才驗） ──
            const anyEquip = Array.from(
                document.querySelectorAll('[name^="equipment["]')
            ).some(i => parseInt(i.value) > 0);

            if (anyEquip) {
                const ebt = document.getElementById('equip_borrow_time').value;
                const ert = document.getElementById('equip_return_time').value;

                if (!ebt || !ert) {
                    e.preventDefault();
                    alert('有選擇器材時，請填寫器材的借用與歸還時間！');
                    return false;
                }
                if (ebt >= ert) {
                    e.preventDefault();
                    alert('器材歸還時間必須晚於借用時間！');
                    return false;
                }
                function chkMin(dtStr) {
                    const d = new Date(dtStr);
                    return d.getHours() * 60 + d.getMinutes();
                }
                if (chkMin(ebt) < 9*60+30 || chkMin(ebt) > 16*60+30) {
                    e.preventDefault();
                    alert('器材借用時間必須在 09:30 至 16:30 之間！');
                    return false;
                }
                if (chkMin(ert) < 9*60+30 || chkMin(ert) > 16*60+30) {
                    e.preventDefault();
                    alert('器材歸還時間必須在 09:30 至 16:30 之間！');
                    return false;
                }
            }
        });

        // 時間範圍自動修正（08:30–21:30）
        ['start_time', 'end_time'].forEach(function(id) {
            const input = document.getElementById(id);
            if (!input) return;
            function clamp() {
                if (!input.value) return;
                if (input.value < '08:30') input.value = '08:30';
                if (input.value > '21:30') input.value = '21:30';
            }
            input.addEventListener('change', clamp);
            input.addEventListener('blur', clamp);
        });

        window.addEventListener('DOMContentLoaded', function () {
            const selectedVenue = document.querySelector('input[name="venue_id"]:checked');
            if (selectedVenue) {
                const venueId = selectedVenue.value;
                const selectedCard = document.querySelector(`[data-venue-id="${venueId}"]`);
                if (selectedCard) {
                    selectedCard.classList.add('selected');
                }
            }
        });
    </script>
</body>
</html>
