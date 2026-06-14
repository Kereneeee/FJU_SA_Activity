<?php

require_once(__DIR__ . "/../DB/db_config.php");

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    header('Location: ../login.php');
    exit();
}

$user_name = $_SESSION['user_name'] ?? '管理員';
$user_id = $_SESSION['user_id'];

// 處理編輯或刪除動作
$edit_equipment = null;
$edit_error = '';
$success_msg = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    $action = $_POST['action'];
    $equipment_id = intval($_POST['equipment_id'] ?? 0);
    
    if ($action === 'edit' && $equipment_id > 0) {
        $stmt = $conn->prepare("SELECT * FROM equipment WHERE equipment_id = ?");
        $stmt->bind_param("i", $equipment_id);
        $stmt->execute();
        $result = $stmt->get_result();
        $edit_equipment = $result->fetch_assoc();
        $stmt->close();
    } elseif ($action === 'save' && $equipment_id > 0) {
        $code = trim($_POST['code'] ?? '');
        $name = trim($_POST['name'] ?? '');
        $description = trim($_POST['description'] ?? '');
        $total_quantity = intval($_POST['total_quantity'] ?? 0);
        $equipment_status = trim($_POST['equipment_status'] ?? 'available');
        $borrowing_limit = intval($_POST['borrowing_limit'] ?? 0);

        // 驗證
        if ($name === '') {
            $edit_error = '請填寫器材名稱';
        } else {
            $stmt = $conn->prepare("UPDATE equipment SET code = ?, name = ?, description = ?, borrowing_limit = ?, total_quantity = ?, equipment_status = ? WHERE equipment_id = ?");
            if ($stmt) {
                $stmt->bind_param("sssiisi", $code, $name, $description, $borrowing_limit, $total_quantity, $equipment_status, $equipment_id);
                if ($stmt->execute()) {
                    $success_msg = '器材已更新';
                    $edit_equipment = null;
                } else {
                    $edit_error = '更新失敗: ' . $stmt->error;
                }
                $stmt->close();
            }
        }
    } elseif ($action === 'delete' && $equipment_id > 0) {
        // 檢查是否有借用記錄
        $check_sql = "SELECT COUNT(*) as cnt FROM equipment_borrow WHERE equipment_id = ?";
        $check_stmt = $conn->prepare($check_sql);
        $check_stmt->bind_param("i", $equipment_id);
        $check_stmt->execute();
        $check_result = $check_stmt->get_result();
        $count_row = $check_result->fetch_assoc();
        $check_stmt->close();
        
        if ($count_row['cnt'] > 0) {
            $edit_error = '此器材有借用記錄，無法刪除';
        } else {
            $stmt = $conn->prepare("DELETE FROM equipment WHERE equipment_id = ?");
            if ($stmt) {
                $stmt->bind_param("i", $equipment_id);
                if ($stmt->execute()) {
                    $success_msg = '器材已刪除';
                } else {
                    $edit_error = '刪除失敗: ' . $stmt->error;
                }
                $stmt->close();
            }
        }
    } elseif ($action === 'add') {
        $code = trim($_POST['code'] ?? '');
        $name = trim($_POST['name'] ?? '');
        $description = trim($_POST['description'] ?? '');
        $total_quantity = intval($_POST['total_quantity'] ?? 0);
        $equipment_status = trim($_POST['equipment_status'] ?? 'available');
        $borrowing_limit = intval($_POST['borrowing_limit'] ?? 0);

        // 驗證
        if ($name === '') {
            $edit_error = '請填寫器材名稱';
        } else {
            $stmt = $conn->prepare("INSERT INTO equipment (code, name, description, borrowing_limit, total_quantity, equipment_status) VALUES (?, ?, ?, ?, ?, ?)");
            if ($stmt) {
                $stmt->bind_param("sssiis", $code, $name, $description, $borrowing_limit, $total_quantity, $equipment_status);
                if ($stmt->execute()) {
                    $success_msg = '器材已新增';
                } else {
                    $edit_error = '新增失敗: ' . $stmt->error;
                }
                $stmt->close();
            }
        }
    }
}

// 篩選／排序參數（GET）
$club_filter = trim($_GET['club_filter'] ?? '');

// 取得器材列表
$sql = "SELECT * FROM equipment ORDER BY equipment_id ASC";
$result = $conn->query($sql);
if (!$result) {
    die("查詢錯誤: " . $conn->error);
}
$equipment_list = $result->fetch_all(MYSQLI_ASSOC);
if (!$equipment_list) $equipment_list = [];

// 計算借出數量與可用數量
$borrowed_by_equipment = [];
$borrow_sql = "SELECT eb.equipment_id, COALESCE(SUM(eb.quantity), 0) AS borrowed_quantity
               FROM equipment_borrow eb
               LEFT JOIN events ev ON eb.event_id = ev.event_id
               WHERE ev.status = 'approved' OR ev.event_id IS NULL
               GROUP BY eb.equipment_id";
$result_borrowed = $conn->query($borrow_sql);
if ($result_borrowed) {
    while ($row = $result_borrowed->fetch_assoc()) {
        $borrowed_by_equipment[intval($row['equipment_id'])] = intval($row['borrowed_quantity']);
    }
}

$total_equipment = count($equipment_list);
$total_quantity = 0;
$available_quantity = 0;
$borrowed_quantity = 0;
foreach ($equipment_list as $eq) {
    $total_quantity += intval($eq['total_quantity'] ?? 0);
    $borrowed = intval($borrowed_by_equipment[intval($eq['equipment_id'])] ?? 0);
    $available = max(0, intval($eq['total_quantity'] ?? 0) - $borrowed);
    $available_quantity += $available;
    $borrowed_quantity += $borrowed;
}

// 取得借用中的器材詳情（連結學生端），支援排序與社團篩選
$borrowing_details = [];
$sql_borrow = "SELECT eb.borrow_id, eb.event_id, eb.request_id, eb.equipment_id, eb.quantity,
                      COALESCE(e.status, 'pending') AS event_status, eq.name as equipment_name,
                      e.event_name, e.club_name, e.start_time, e.end_time, e.created_at,
                      u.name as student_name, u.email
               FROM equipment_borrow eb
               LEFT JOIN equipment eq ON eb.equipment_id = eq.equipment_id
               LEFT JOIN events e ON eb.event_id = e.event_id
               LEFT JOIN users u ON e.user_id = u.user_id
               WHERE (e.event_id IS NULL OR e.status IN ('pending', 'approved'))";
if ($club_filter !== '') {
    $sql_borrow .= " AND e.club_name = ?";
}
$sql_borrow .= " ORDER BY e.start_time DESC";

if ($club_filter !== '') {
    $stmt_borrow = $conn->prepare($sql_borrow);
    $stmt_borrow->bind_param('s', $club_filter);
    $stmt_borrow->execute();
    $result_borrow = $stmt_borrow->get_result();
    if ($result_borrow) $borrowing_details = $result_borrow->fetch_all(MYSQLI_ASSOC);
    $stmt_borrow->close();
} else {
    $result_borrow = $conn->query($sql_borrow);
    if ($result_borrow) $borrowing_details = $result_borrow->fetch_all(MYSQLI_ASSOC);
}

// 取得有借用記錄的社團清單（供篩選下拉用）
$all_borrow_clubs = [];
$club_res = $conn->query(
    "SELECT DISTINCT e.club_name FROM equipment_borrow eb
     LEFT JOIN events e ON eb.event_id = e.event_id
     WHERE e.club_name IS NOT NULL ORDER BY e.club_name"
);
if ($club_res) {
    while ($r = $club_res->fetch_assoc()) $all_borrow_clubs[] = $r['club_name'];
}

// 統計借用信息
$total_borrows = count($borrowing_details);
$pending_borrows = 0;
$approved_borrows = 0;
foreach ($borrowing_details as $borrow) {
    if ($borrow['event_status'] === 'pending') {
        $pending_borrows++;
    } elseif ($borrow['event_status'] === 'approved') {
        $approved_borrows++;
    }
}

?>

<!DOCTYPE html>
<html lang="zh-TW">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>器材管理 - 輔仁大學課外活動指導組</title>

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
        html { overflow-y: scroll; }

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
            overflow-y: hidden;
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
        .dropdown {
            position: relative;
        }
        .dropdown-content {
            display: block;
            max-height: 0;
            overflow: hidden;
            transition: max-height 0.3s ease;
            background-color: rgba(255,255,255,0.1);
            border-radius: 16px;
            margin-top: 0.2rem;
        }
        .dropdown:hover .dropdown-content {
            max-height: 200px;
        }
        .dropdown-content a {
            color: rgba(255,255,255,0.9);
            padding: 0.75rem 1rem;
            text-decoration: none;
            display: block;
            border-radius: 0;
        }
        .dropdown-content a:hover {
            background-color: rgba(255,255,255,0.2);
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
        .user-avatar {
            width: 38px;
            height: 38px;
            border-radius: 50%;
            background: var(--primary);
            color: white;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            font-weight: 700;
            font-size: 1rem;
            cursor: pointer;
            flex-shrink: 0;
        }
        .dashboard-grid {
            padding: 1.5rem 2rem 2rem;
        }
        .summary-row {
            display: grid;
            grid-template-columns: repeat(4, minmax(0, 1fr));
            gap: 1.25rem;
            margin-bottom: 1.5rem;
        }
        .card-panel {
            background: var(--card);
            border-radius: 14px;
            box-shadow: 0 2px 12px rgba(15,23,42,0.07);
            padding: 1.5rem;
            min-height: 130px;
            display: flex;
            flex-direction: column;
            justify-content: space-between;
            border-left: 4px solid transparent;
        }
        .card-panel .icon-box {
            width: 46px;
            height: 46px;
            border-radius: 12px;
            display: grid;
            place-items: center;
            font-size: 1.2rem;
        }
        .card-panel.items    { border-left-color: #0d6efd; }
        .card-panel.items    .icon-box { background: rgba(13,110,253,0.12); color: #0d6efd; }
        .card-panel.total    { border-left-color: #6f42c1; }
        .card-panel.total    .icon-box { background: rgba(111,66,193,0.12); color: #6f42c1; }
        .card-panel.available { border-left-color: #198754; }
        .card-panel.available .icon-box { background: rgba(25,135,84,0.12); color: #198754; }
        .card-panel.borrowed { border-left-color: #fd7e14; }
        .card-panel.borrowed .icon-box { background: rgba(253,126,20,0.12); color: #fd7e14; }
        .card-panel.pending  { border-left-color: #fd7e14; }
        .card-panel.pending  .icon-box { background: rgba(253,126,20,0.12); color: #fd7e14; }
        .card-panel .value {
            font-size: 2rem;
            font-weight: 700;
            margin-top: 1rem;
        }
        .card-panel .label { color: #6b7280; }
        .panel-row {
            background: var(--card);
            border-radius: 18px;
            box-shadow: 0 10px 30px rgba(15,23,42,0.06);
            padding: 1.5rem;
            margin-bottom: 1.5rem;
        }
        .panel-row h5 {
            margin-bottom: 1rem;
            font-weight: 700;
            color: var(--primary);
        }
        table { width: 100%; border-collapse: collapse; }
        th, td { padding: 0.75rem 0.8rem; text-align: left; border-bottom: 1px solid #e5e7eb; }
        th { background: #f3f4f6; color: #374151; font-weight: 600; white-space: nowrap; }
        tbody tr:hover { background: #f9fafb; }
        .inventory-table {
            table-layout: fixed;
            width: 100%;
            min-width: 900px;
        }
        .inventory-table th,
        .inventory-table td {
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }
        .inventory-table .status-badge,
        .inventory-table .action-cell {
            white-space: nowrap;
            flex-wrap: nowrap;
        }
        .inventory-table .action-cell form,
        .inventory-table .action-cell .btn {
            flex-shrink: 0;
            white-space: nowrap;
        }
        .inventory-table .col-code { width: 9%; }
        .inventory-table .col-name { width: 20%; }
        .inventory-table .col-total { width: 8%; }
        .inventory-table .col-available { width: 8%; }
        .inventory-table .col-borrowed { width: 8%; }
        .inventory-table .col-limit { width: 8%; }
        .inventory-table .col-status { width: 12%; }
        .inventory-table .col-stock { width: 12%; }
        .inventory-table .col-action { width: 15%; }
        .stock-text {
            font-weight: 600;
            color: var(--primary);
        }
        .status-badge {
            display: inline-flex;
            align-items: center;
            gap: 0.35rem;
            padding: 0.45rem 0.85rem;
            border-radius: 999px;
            font-size: 0.8rem;
            font-weight: 600;
        }
        .status-available { background: #d1e7dd; color: #0f5132; }
        .status-unavailable { background: #f8d7da; color: #842029; }
        .status-maintenance { background: #fff3cd; color: #664d03; }
        .quantity-bar {
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }
        .bar-bg {
            flex: 1;
            height: 6px;
            background: #e5e7eb;
            border-radius: 3px;
            overflow: hidden;
        }
        .bar-fill {
            height: 100%;
            background: #198754;
            border-radius: 3px;
        }
        .bar-borrowed {
            background: #fd7e14;
        }
        .bar-text {
            font-size: 0.85rem;
            color: #6b7280;
            min-width: 50px;
        }
        .btn {
            padding: 0.5rem 0.95rem;
            border: none;
            border-radius: 6px;
            cursor: pointer;
            font-size: 0.85rem;
            font-weight: 600;
            transition: all 0.25s ease;
            margin-right: 0.35rem;
        }
        .btn-primary {
            background: var(--primary);
            color: white;
        }
        .btn-primary:hover {
            background: #6a0f2a;
            transform: translateY(-2px);
        }
        .btn-outline-primary {
            background: transparent;
            color: #0d6efd;
            border: 1px solid #0d6efd;
        }
        .btn-outline-primary:hover {
            background: #0d6efd;
            color: white;
        }
        .btn-sm {
            padding: 0.35rem 0.7rem;
            font-size: 0.78rem;
        }
        .btn-edit {
            background: #0d6efd;
            color: white;
        }
        .btn-edit:hover {
            background: #0b5ed7;
            transform: translateY(-2px);
        }
        .btn-delete {
            background: #dc3545;
            color: white;
        }
        .btn-delete:hover {
            background: #c82333;
            transform: translateY(-2px);
        }
        .action-cell {
            display: flex;
            gap: 0.5rem;
            align-items: center;
        }
        .alert {
            padding: 0.75rem 1rem;
            border-radius: 8px;
            margin-bottom: 1rem;
        }
        .alert-success {
            background: #d1e7dd;
            color: #0f5132;
            border: 1px solid #badbcc;
        }
        .alert-danger {
            background: #f8d7da;
            color: #842029;
            border: 1px solid #f5c2c7;
        }
        .form-control, .form-select {
            padding: 0.5rem 0.75rem;
            border: 1px solid #ddd;
            border-radius: 6px;
            font-size: 0.9rem;
        }
        .form-control:focus, .form-select:focus {
            border-color: var(--primary);
            box-shadow: 0 0 0 0.2rem rgba(30,77,107,0.25);
            outline: none;
        }
        .form-label {
            font-weight: 600;
            margin-bottom: 0.35rem;
        }
        .borrowing-table {
            font-size: 0.9rem;
            min-width: 700px;
        }
        .borrowing-table td { white-space: nowrap; }
        .borrowing-table tbody tr.borrow-group-alt td { background: #f6f7f9; }
        .borrowing-table tbody tr.borrow-group-base td { background: #ffffff; }
        .borrowing-table tbody tr.borrow-group-alt:hover td,
        .borrowing-table tbody tr.borrow-group-base:hover td { background: #eef4f7; }
        .borrow-status {
            display: inline-flex;
            align-items: center;
            gap: 0.35rem;
            padding: 0.35rem 0.7rem;
            border-radius: 999px;
            font-size: 0.75rem;
            font-weight: 600;
        }
        .borrow-status.pending {
            background: #fff3cd;
            color: #664d03;
        }
        .borrow-status.approved {
            background: #d1e7dd;
            color: #0f5132;
        }
        .borrow-status.rejected {
            background: #f8d7da;
            color: #842029;
        }
        @media (max-width: 1100px) {
            .summary-row { grid-template-columns: repeat(2, 1fr); }
        }
        @media (max-width: 768px) {
            .main-content { margin-left: 0; }
        .top-navbar { flex-direction: column; align-items: flex-start; gap: 1rem; padding: 1rem; }
            .sidebar { position: relative; width: 100%; height: auto; box-shadow: none; }
            .summary-row { grid-template-columns: 1fr; }
        }
    
        /* 提示訊息配色 */
        .alert-success { background: #c8dfe0; border-color: #70a3a7; color: #1a3f42; }
        .alert-warning { background: #ede4e5; border-color: #deb8b9; color: #6b2d2d; }
        .alert-danger  { background: #deb8b9; border-color: #c9979a; color: #5c1f22; }
        .alert-info    { background: #ede4e5; border-color: #c8c0c2; color: #5a3f42; }
        .sort-btn {
            padding: .35rem .85rem; border: 1.5px solid #d1d5db; background: white;
            color: #374151; border-radius: 8px; cursor: pointer; font-size: .83rem;
            transition: all .2s; display: inline-flex; align-items: center; gap: .3rem;
        }
        .sort-btn:hover { border-color: var(--primary); color: var(--primary); }
        .sort-btn.sort-active { border-color: var(--primary); background: var(--primary); color: white; }
        .borrow-pagination {
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: .75rem;
            flex-wrap: wrap;
            margin-top: .9rem;
            color: #6b7280;
            font-size: .85rem;
        }
        .borrow-page-controls {
            display: inline-flex;
            gap: .35rem;
            align-items: center;
            flex-wrap: wrap;
        }
        .borrow-page-btn {
            min-width: 34px;
            height: 34px;
            border: 1px solid #d1d5db;
            background: #fff;
            color: #374151;
            border-radius: 7px;
            font-size: .82rem;
            cursor: pointer;
            transition: all .18s;
        }
        .borrow-page-btn:hover:not(:disabled) { border-color: var(--primary); color: var(--primary); }
        .borrow-page-btn.active { background: var(--primary); border-color: var(--primary); color: #fff; }
        .borrow-page-btn:disabled { opacity: .45; cursor: not-allowed; }
    </style>
</head>
<body>
    <?php
    $current_page = $current_page ?? 'equipment_mgmt';
    include __DIR__ . '/../includes/admin_sidebar.php';
    ?>

    <main class="main-content">
        <header class="top-navbar">
            <div>
                <ol class="breadcrumb mb-0">
                    <li class="breadcrumb-item"><a href="dashboard.php">首頁</a></li>
                    <li class="breadcrumb-item active" aria-current="page">器材管理</li>
                </ol>
                <h4 class="mt-2 mb-0">器材管理</h4>
            </div>
            <div class="d-flex align-items-center gap-2">
                <div class="user-avatar" onclick="location.href='profile.php'">
                    <?= htmlspecialchars(mb_substr($user_name, 0, 1)) ?>
                </div>
                <small class="text-muted"><?= htmlspecialchars($user_name) ?></small>
            </div>
        </header>

        <section class="dashboard-grid">

            <?php if ($success_msg): ?>
            <div class="alert alert-success">
                <i class="bi bi-check-circle"></i> <?php echo htmlspecialchars($success_msg); ?>
            </div>
            <?php endif; ?>

            <!-- 借用詳情 -->
            <div class="panel-row">
                <h5><i class="bi bi-clipboard-list"></i> 器材借用狀態</h5>

                <!-- 篩選列 -->
                <form method="GET" class="d-flex gap-2 align-items-center flex-wrap mb-2">
                    <label class="form-label mb-0" style="white-space:nowrap;">社團：</label>
                    <select name="club_filter" class="form-select form-select-sm" style="width:auto">
                        <option value="">所有社團</option>
                        <?php foreach ($all_borrow_clubs as $club): ?>
                        <option value="<?= htmlspecialchars($club) ?>" <?= $club_filter===$club?'selected':'' ?>><?= htmlspecialchars($club) ?></option>
                        <?php endforeach; ?>
                    </select>
                    <button type="submit" class="btn btn-primary btn-sm"><i class="bi bi-funnel"></i> 套用</button>
                    <?php if ($club_filter!==''): ?>
                    <a href="equipment_mgmt.php" class="btn btn-sm btn-outline-secondary"><i class="bi bi-x-circle"></i> 清除</a>
                    <?php endif; ?>
                    <?php if (!empty($borrowing_details)): ?>
                    <small class="text-muted ms-auto">共 <?= count($borrowing_details) ?> 筆</small>
                    <?php endif; ?>
                </form>
                <div id="equipSortBar" style="display:flex;gap:.5rem;align-items:center;margin-bottom:1rem;flex-wrap:wrap;">
                    <span style="font-size:.83rem;color:#6b7280;">排序：</span>
                    <button id="equipSortEventBtn" class="sort-btn sort-active" onclick="sortEquipTable('event')">
                        <i class="bi bi-calendar-event"></i> 活動時間 <span id="equipSortEventDir">↓</span>
                    </button>
                    <button id="equipSortApplyBtn" class="sort-btn" onclick="sortEquipTable('apply')">
                        <i class="bi bi-clock"></i> 申請時間 <span id="equipSortApplyDir">↓</span>
                    </button>
                </div>

                <?php if (!empty($borrowing_details)): ?>
                <div style="overflow-x: auto;">
                    <table class="borrowing-table">
                        <thead>
                            <tr>
                                <th>申請活動</th>
                                <th>社團</th>
                                <th>器材名稱</th>
                                <th>借用數量</th>
                                <th>申請學生</th>
                                <th>活動日期</th>
                                <th>申請日期</th>
                                <th>申請狀態</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($borrowing_details as $borrow):
                                $b_event_ts = !empty($borrow['start_time'])  ? strtotime($borrow['start_time'])  : 0;
                                $b_apply_ts = !empty($borrow['created_at'])  ? strtotime($borrow['created_at'])  : 0;
                                $borrow_group = !empty($borrow['request_id'])
                                    ? 'request-' . intval($borrow['request_id'])
                                    : 'event-' . intval($borrow['event_id']);
                            ?>
                            <tr data-event-ts="<?= $b_event_ts ?>" data-apply-ts="<?= $b_apply_ts ?>" data-borrow-group="<?= htmlspecialchars($borrow_group) ?>">
                                <td><?php echo htmlspecialchars($borrow['event_name'] ?? '未知活動'); ?></td>
                                <td><?php echo htmlspecialchars($borrow['club_name'] ?? '未知社團'); ?></td>
                                <td><?php echo htmlspecialchars($borrow['equipment_name'] ?? '未知器材'); ?></td>
                                <td><?php echo intval($borrow['quantity']); ?></td>
                                <td>
                                    <small><?php echo htmlspecialchars($borrow['student_name'] ?? '未知'); ?><br/>
                                    <?php echo htmlspecialchars($borrow['email'] ?? ''); ?></small>
                                </td>
                                <td>
                                    <small><?php echo $borrow['start_time'] ? date('Y/m/d H:i', strtotime($borrow['start_time'])) : '—'; ?></small>
                                </td>
                                <td>
                                    <small><?php echo !empty($borrow['created_at']) ? date('Y/m/d H:i', strtotime($borrow['created_at'])) : '—'; ?></small>
                                </td>
                                <td>
                                    <span class="borrow-status <?php echo htmlspecialchars($borrow['event_status']); ?>">
                                        <i class="bi bi-<?php echo $borrow['event_status'] === 'pending' ? 'hourglass-split' : 'check-lg'; ?>"></i>
                                        <?php echo $borrow['event_status'] === 'pending' ? '待審核' : '已核准'; ?>
                                    </span>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <div class="borrow-pagination" id="borrowPagination">
                    <span id="borrowPageInfo"></span>
                    <div class="borrow-page-controls" id="borrowPageControls"></div>
                </div>
                <?php else: ?>
                <p class="text-muted mb-0"><i class="bi bi-inbox"></i> 目前無符合條件的借用記錄</p>
                <?php endif; ?>
            </div>

            <!-- 器材庫存列表 -->
            <div class="panel-row">
                <h5><i class="bi bi-list-ul"></i> 器材庫存列表</h5>
                <div style="overflow-x:auto;">
                    <table class="inventory-table">
                        <thead>
                            <tr>
                                <th class="col-code">器材代碼</th>
                                <th class="col-name">器材名稱</th>
                                <th class="col-total">總數量</th>
                                <th class="col-available">可借數量</th>
                                <th class="col-borrowed">借出數量</th>
                                <th class="col-limit">借用限制</th>
                                <th class="col-status">狀態</th>
                                <th class="col-stock">庫存</th>
                                <th class="col-action">操作</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($equipment_list as $eq):
                                $eid      = intval($eq['equipment_id']);
                                $total_q  = intval($eq['total_quantity'] ?? 0);
                                $borrowed = intval($borrowed_by_equipment[$eid] ?? 0);
                                $avail_q  = max(0, $total_q - $borrowed);
                                $stock_pct = $total_q > 0 ? round(($avail_q / $total_q) * 100) : 0;
                                $equip_status = $eq['equipment_status'] ?? 'available';
                            ?>
                            <tr>
                                <td><?php echo htmlspecialchars($eq['code'] ?? ''); ?></td>
                                <td><strong><?php echo htmlspecialchars($eq['name']); ?></strong></td>
                                <td><?php echo $total_q; ?></td>
                                <td><?php echo $avail_q; ?></td>
                                <td><?php echo $borrowed; ?></td>
                                <td><?php echo intval($eq['borrowing_limit'] ?? 0); ?></td>
                                <td>
                                    <span class="status-badge status-<?php echo htmlspecialchars($equip_status); ?>">
                                        <i class="bi bi-<?php echo $equip_status === 'available' ? 'check-lg' : 'x-lg'; ?>"></i>
                                        <?php echo $equip_status === 'available' ? '可借用' : '維修中'; ?>
                                    </span>
                                </td>
                                <td><span class="stock-text"><?php echo $stock_pct; ?>%</span></td>
                                <td>
                                    <div class="action-cell">
                                        <form method="POST" style="display:inline;">
                                            <input type="hidden" name="action" value="edit">
                                            <input type="hidden" name="equipment_id" value="<?php echo $eid; ?>">
                                            <button type="submit" class="btn btn-edit btn-sm"><i class="bi bi-pencil"></i> 編輯</button>
                                        </form>
                                        <form method="POST" style="display:inline;" onsubmit="return confirm('確定要刪除此器材？');">
                                            <input type="hidden" name="action" value="delete">
                                            <input type="hidden" name="equipment_id" value="<?php echo $eid; ?>">
                                            <button type="submit" class="btn btn-delete btn-sm"><i class="bi bi-trash"></i> 刪除</button>
                                        </form>
                                    </div>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                            <?php if (empty($equipment_list)): ?>
                            <tr>
                                <td colspan="9" style="text-align:center;color:#999;padding:30px;">
                                    <i class="bi bi-inbox"></i> 目前沒有器材記錄
                                </td>
                            </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>

            <?php if (!empty($edit_equipment)): ?>
            <div class="modal fade show" tabindex="-1" role="dialog" style="display: block; background: rgba(0, 0, 0, 0.5);">
                <div class="modal-dialog modal-lg modal-dialog-centered" role="document">
                    <div class="modal-content">
                        <form method="POST" class="mb-0">
                            <input type="hidden" name="action" value="save">
                            <input type="hidden" name="equipment_id" value="<?php echo intval($edit_equipment['equipment_id']); ?>">
                            <div class="modal-header">
                                <h5 class="modal-title"><i class="bi bi-pencil-square"></i> 編輯器材</h5>
                                <a href="equipment_mgmt.php" class="btn-close" aria-label="關閉"></a>
                            </div>
                            <div class="modal-body">
                                <?php if ($edit_error): ?>
                                    <div class="alert alert-danger"><?php echo htmlspecialchars($edit_error); ?></div>
                                <?php endif; ?>
                                <div class="row g-3">
                                    <div class="col-md-6">
                                        <label class="form-label">器材代碼</label>
                                        <input type="text" name="code" class="form-control" value="<?php echo htmlspecialchars($edit_equipment['code'] ?? ''); ?>">
                                    </div>
                                    <div class="col-md-6">
                                        <label class="form-label">器材名稱</label>
                                        <input type="text" name="name" class="form-control" value="<?php echo htmlspecialchars($edit_equipment['name']); ?>" required>
                                    </div>
                                    <div class="col-md-4">
                                        <label class="form-label">狀態</label>
                                        <select name="equipment_status" class="form-select" required>
                                            <option value="available" <?php echo $edit_equipment['equipment_status'] === 'available' ? 'selected' : ''; ?>>可借用</option>
                                            <option value="maintenance" <?php echo $edit_equipment['equipment_status'] === 'maintenance' ? 'selected' : ''; ?>>維修中</option>
                                        </select>
                                    </div>
                                    <div class="col-md-4">
                                        <label class="form-label">總數量</label>
                                        <input type="number" name="total_quantity" class="form-control" value="<?php echo intval($edit_equipment['total_quantity'] ?? 0); ?>" min="0" required>
                                    </div>
                                    <div class="col-md-4">
                                        <label class="form-label">借用限制數量</label>
                                        <input type="number" name="borrowing_limit" class="form-control" value="<?php echo intval($edit_equipment['borrowing_limit'] ?? 0); ?>" min="0" required>
                                    </div>
                                    <div class="col-12">
                                        <label class="form-label">描述</label>
                                        <textarea name="description" class="form-control" rows="3"><?php echo htmlspecialchars($edit_equipment['description'] ?? ''); ?></textarea>
                                    </div>
                                </div>
                            </div>
                            <div class="modal-footer">
                                <a href="equipment_mgmt.php" class="btn btn-outline-secondary btn-sm"><i class="bi bi-x-circle"></i> 取消</a>
                                <button type="submit" class="btn btn-primary btn-sm"><i class="bi bi-save"></i> 儲存變更</button>
                            </div>
                        </form>
                    </div>
                </div>
            </div>
            <div class="modal-backdrop fade show"></div>
            <?php else: ?>
            <div class="panel-row">
                <h5><i class="bi bi-plus-circle"></i> 新增器材</h5>
                <?php if ($edit_error): ?>
                    <div class="alert alert-danger"><?php echo htmlspecialchars($edit_error); ?></div>
                <?php endif; ?>
                <form method="POST" class="mb-4">
                    <input type="hidden" name="action" value="add">
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label">器材代碼</label>
                            <input type="text" name="code" class="form-control">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">器材名稱</label>
                            <input type="text" name="name" class="form-control" required>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">狀態</label>
                            <select name="equipment_status" class="form-select" required>
                                <option value="available">可借用</option>
                                <option value="maintenance">維修中</option>
                            </select>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">總數量</label>
                            <input type="number" name="total_quantity" class="form-control" value="0" min="0" required>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">借用限制數量</label>
                            <input type="number" name="borrowing_limit" class="form-control" value="0" min="0" required>
                        </div>
                        <div class="col-12">
                            <label class="form-label">描述</label>
                            <textarea name="description" class="form-control" rows="3"></textarea>
                        </div>
                    </div>
                    <div class="mt-3 d-flex gap-2">
                        <button type="submit" class="btn btn-primary btn-sm"><i class="bi bi-plus-lg"></i> 新增器材</button>
                    </div>
                </form>
            </div>
            <?php endif; ?>
        </section>
    </main>
    <script>
        var equipSortKey = 'event';
        var equipSortDir = 'desc';
        var borrowCurrentPage = 1;
        var borrowRowsPerPage = 10;

        function getBorrowRows() {
            var tbody = document.querySelector('.borrowing-table tbody');
            return tbody ? Array.from(tbody.querySelectorAll('tr[data-event-ts]')) : [];
        }

        function applyBorrowGroupStripes(rows) {
            var lastGroup = null;
            var groupIndex = -1;
            rows.forEach(function(row) {
                var group = row.dataset.borrowGroup || '';
                if (group !== lastGroup) {
                    groupIndex++;
                    lastGroup = group;
                }
                row.classList.toggle('borrow-group-alt', groupIndex % 2 === 1);
                row.classList.toggle('borrow-group-base', groupIndex % 2 === 0);
            });
        }

        function buildBorrowPages(rows) {
            var groups = [];
            var currentGroup = null;

            rows.forEach(function(row) {
                var groupKey = row.dataset.borrowGroup || '';
                if (!currentGroup || currentGroup.key !== groupKey) {
                    currentGroup = { key: groupKey, rows: [] };
                    groups.push(currentGroup);
                }
                currentGroup.rows.push(row);
            });

            var pages = [];
            var currentPage = [];
            groups.forEach(function(group) {
                var wouldOverflow = currentPage.length > 0 && currentPage.length + group.rows.length > borrowRowsPerPage;
                if (wouldOverflow) {
                    pages.push(currentPage);
                    currentPage = [];
                }
                currentPage = currentPage.concat(group.rows);
            });

            if (currentPage.length > 0 || pages.length === 0) {
                pages.push(currentPage);
            }

            return pages;
        }

        function renderBorrowPagination() {
            var rows = getBorrowRows();
            var totalRows = rows.length;
            var pageRows = buildBorrowPages(rows);
            var totalPages = pageRows.length;
            if (borrowCurrentPage > totalPages) borrowCurrentPage = totalPages;
            if (borrowCurrentPage < 1) borrowCurrentPage = 1;

            var visibleRows = pageRows[borrowCurrentPage - 1] || [];
            var visibleSet = new Set(visibleRows);
            rows.forEach(function(row) {
                row.style.display = visibleSet.has(row) ? '' : 'none';
            });

            var info = document.getElementById('borrowPageInfo');
            if (info) {
                var firstVisible = visibleRows.length ? rows.indexOf(visibleRows[0]) + 1 : 0;
                var lastVisible = visibleRows.length ? rows.indexOf(visibleRows[visibleRows.length - 1]) + 1 : 0;
                var from = totalRows === 0 ? 0 : firstVisible;
                var to = totalRows === 0 ? 0 : lastVisible;
                info.textContent = '顯示 ' + from + '–' + to + ' 筆，共 ' + totalRows + ' 筆';
            }

            var controls = document.getElementById('borrowPageControls');
            if (!controls) return;

            var html = '';
            html += '<button type="button" class="borrow-page-btn" onclick="goBorrowPage(' + (borrowCurrentPage - 1) + ')" ' + (borrowCurrentPage === 1 ? 'disabled' : '') + '>上一頁</button>';

            var pages = [];
            for (var page = 1; page <= totalPages; page++) {
                if (page === 1 || page === totalPages || Math.abs(page - borrowCurrentPage) <= 2) {
                    pages.push(page);
                }
            }
            var lastPage = 0;
            pages.forEach(function(page) {
                if (page - lastPage > 1) {
                    html += '<span style="padding:0 .25rem;">...</span>';
                }
                html += '<button type="button" class="borrow-page-btn ' + (page === borrowCurrentPage ? 'active' : '') + '" onclick="goBorrowPage(' + page + ')">' + page + '</button>';
                lastPage = page;
            });
            html += '<button type="button" class="borrow-page-btn" onclick="goBorrowPage(' + (borrowCurrentPage + 1) + ')" ' + (borrowCurrentPage === totalPages ? 'disabled' : '') + '>下一頁</button>';
            controls.innerHTML = html;
        }

        function goBorrowPage(page) {
            borrowCurrentPage = page;
            renderBorrowPagination();
        }

        function sortEquipTable(key, toggle) {
            if (toggle === undefined) toggle = true;
            if (equipSortKey === key) {
                if (toggle) equipSortDir = equipSortDir === 'asc' ? 'desc' : 'asc';
            } else {
                equipSortKey = key;
                equipSortDir = 'desc';
            }
            document.querySelectorAll('#equipSortBar .sort-btn').forEach(function(b) { b.classList.remove('sort-active'); });
            document.getElementById(key === 'event' ? 'equipSortEventBtn' : 'equipSortApplyBtn').classList.add('sort-active');
            document.getElementById('equipSortEventDir').textContent = equipSortKey === 'event' ? (equipSortDir === 'desc' ? '↓' : '↑') : '↓';
            document.getElementById('equipSortApplyDir').textContent = equipSortKey === 'apply' ? (equipSortDir === 'desc' ? '↓' : '↑') : '↓';
            var tbody = document.querySelector('.borrowing-table tbody');
            if (!tbody) return;
            var rows = getBorrowRows();
            var attr = equipSortKey === 'event' ? 'eventTs' : 'applyTs';
            rows.sort(function(a, b) {
                var av = parseInt(a.dataset[attr]) || 0;
                var bv = parseInt(b.dataset[attr]) || 0;
                return equipSortDir === 'desc' ? bv - av : av - bv;
            });
            rows.forEach(function(r) { tbody.appendChild(r); });
            applyBorrowGroupStripes(rows);
            borrowCurrentPage = 1;
            renderBorrowPagination();
        }
        document.addEventListener('DOMContentLoaded', function() { sortEquipTable('event', false); });
    </script>
</body>
</html>
