<?php

require_once(__DIR__ . "/../DB/db_config.php");

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    header('Location: ../login.php');
    exit();
}

$user_name = $_SESSION['user_name'] ?? '管理員';
$user_id = $_SESSION['user_id'];

// 篩選/排序參數（GET）
$sp_status = in_array($_GET['sp_status'] ?? '', ['available', 'unavailable']) ? $_GET['sp_status'] : '';

// 取得所有場地（統計用，永遠抓全部）
$result_spaces = $conn->query("SELECT * FROM spaces ORDER BY space_id ASC");
if (!$result_spaces) die("查詢錯誤: " . $conn->error);
$spaces_all = $result_spaces->fetch_all(MYSQLI_ASSOC);
if (!$spaces_all) $spaces_all = [];

// 統計資料（以全量計算，不受篩選影響）
$total_spaces      = count($spaces_all);
$available_spaces  = 0;
$unavailable_spaces = 0;
$total_capacity    = 0;
foreach ($spaces_all as $space) {
    $total_capacity += intval($space['capacity']);
    if (($space['space_status'] ?? 'available') === 'available') $available_spaces++;
    else $unavailable_spaces++;
}

// 篩選
$spaces_list = $spaces_all;
if ($sp_status !== '') {
    $spaces_list = array_values(array_filter($spaces_list, function ($s) use ($sp_status) {
        return ($s['space_status'] ?? 'available') === $sp_status;
    }));
}

// 重新載入並套用篩選（POST 成功後呼叫）
$refresh_spaces = function() use ($conn, $sp_status, &$spaces_all, &$spaces_list) {
    $res = $conn->query("SELECT * FROM spaces ORDER BY space_id ASC");
    $spaces_all = $res ? $res->fetch_all(MYSQLI_ASSOC) : [];
    $spaces_list = $spaces_all;
    if ($sp_status !== '') {
        $spaces_list = array_values(array_filter($spaces_list, function ($s) use ($sp_status) {
            return ($s['space_status'] ?? 'available') === $sp_status;
        }));
    }
};

// 處理編輯或刪除動作
$edit_space = null;
$edit_error = '';
$success_msg = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    $action = $_POST['action'];
    $space_id = intval($_POST['space_id'] ?? 0);
    
    if ($action === 'edit' && $space_id > 0) {
        $stmt = $conn->prepare("SELECT * FROM spaces WHERE space_id = ?");
        $stmt->bind_param("i", $space_id);
        $stmt->execute();
        $result = $stmt->get_result();
        $edit_space = $result->fetch_assoc();
        $stmt->close();
    } elseif ($action === 'save' && $space_id > 0) {
        $space_name = trim($_POST['space_name'] ?? '');
        $capacity = intval($_POST['capacity'] ?? 0);
        $space_status = trim($_POST['space_status'] ?? 'available');
        
        if ($space_name === '') {
            $edit_error = '請填寫場地名稱';
            $edit_space = [
                'space_id' => $space_id,
                'space_name' => $space_name,
                'capacity' => $capacity,
                'space_status' => $space_status,
            ];
        } else {
            $stmt = $conn->prepare("UPDATE spaces SET space_name = ?, capacity = ?, space_status = ? WHERE space_id = ?");
            if ($stmt) {
                $stmt->bind_param("sisi", $space_name, $capacity, $space_status, $space_id);
                if ($stmt->execute()) {
                    $success_msg = '場地已更新';
                    $edit_space = null;
                    $refresh_spaces();
                } else {
                    $edit_error = '更新失敗: ' . $stmt->error;
                    $edit_space = [
                        'space_id' => $space_id,
                        'space_name' => $space_name,
                        'capacity' => $capacity,
                        'space_status' => $space_status,
                    ];
                }
                $stmt->close();
            }
        }
    } elseif ($action === 'delete' && $space_id > 0) {
        // 檢查是否有預約
        $check_sql = "SELECT COUNT(*) as cnt FROM reservations WHERE space_id = ?";
        $check_stmt = $conn->prepare($check_sql);
        $check_stmt->bind_param("i", $space_id);
        $check_stmt->execute();
        $check_result = $check_stmt->get_result();
        $count_row = $check_result->fetch_assoc();
        $check_stmt->close();
        
        if ($count_row['cnt'] > 0) {
            $edit_error = '此場地有預約記錄，無法刪除';
        } else {
            $stmt = $conn->prepare("DELETE FROM spaces WHERE space_id = ?");
            if ($stmt) {
                $stmt->bind_param("i", $space_id);
                if ($stmt->execute()) {
                    $success_msg = '場地已刪除';
                    $refresh_spaces();
                } else {
                    $edit_error = '刪除失敗: ' . $stmt->error;
                }
                $stmt->close();
            }
        }
    } elseif ($action === 'add') {
        $space_name = trim($_POST['space_name'] ?? '');
        $capacity = intval($_POST['capacity'] ?? 0);
        $space_status = trim($_POST['space_status'] ?? 'available');
        
        if ($space_name === '') {
            $edit_error = '請填寫場地名稱';
        } else {
            $stmt = $conn->prepare("INSERT INTO spaces (space_name, capacity, space_status) VALUES (?, ?, ?)");
            if ($stmt) {
                $stmt->bind_param("sis", $space_name, $capacity, $space_status);
                if ($stmt->execute()) {
                    $success_msg = '場地已新增';
                    $refresh_spaces();
                } else {
                    $edit_error = '新增失敗: ' . $stmt->error;
                }
                $stmt->close();
            }
        }
    }
}

?>

<!DOCTYPE html>
<html lang="zh-TW">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>空間管理 - 輔仁大學課外活動指導組</title>

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
            z-index: 1100;
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
        .card-panel.total       { border-left-color: #6f42c1; }
        .card-panel.total       .icon-box { background: rgba(111,66,193,0.12); color: #6f42c1; }
        .card-panel.available   { border-left-color: #198754; }
        .card-panel.available   .icon-box { background: rgba(25,135,84,0.12); color: #198754; }
        .card-panel.unavailable { border-left-color: #dc3545; }
        .card-panel.unavailable .icon-box { background: rgba(220,53,69,0.12); color: #dc3545; }
        .card-panel.capacity    { border-left-color: #0d6efd; }
        .card-panel.capacity    .icon-box { background: rgba(13,110,253,0.12); color: #0d6efd; }
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
        th, td { padding: 0.85rem 1rem; text-align: left; border-bottom: 1px solid #e5e7eb; }
        th { background: #f3f4f6; color: #374151; font-weight: 600; }
        tbody tr:hover { background: #f9fafb; }
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
        .btn-outline-danger {
            background: transparent;
            color: #dc3545;
            border: 1px solid #dc3545;
        }
        .btn-outline-danger:hover {
            background: #dc3545;
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

        .modal {
            z-index: 1350;
        }
        .modal-backdrop {
            z-index: 1340;
        }
        .sort-btn {
            padding: .35rem .85rem; border: 1.5px solid #d1d5db; background: white;
            color: #374151; border-radius: 8px; cursor: pointer; font-size: .83rem;
            transition: all .2s; display: inline-flex; align-items: center; gap: .3rem;
        }
        .sort-btn:hover { border-color: var(--primary); color: var(--primary); }
        .sort-btn.sort-active { border-color: var(--primary); background: var(--primary); color: white; }
    </style>
</head>
<body>
    <?php
    $current_page = $current_page ?? 'space_mgmt';
    include __DIR__ . '/../includes/admin_sidebar.php';
    ?>

    <main class="main-content">
        <header class="top-navbar">
            <div>
                <ol class="breadcrumb mb-0">
                    <li class="breadcrumb-item"><a href="dashboard.php">首頁</a></li>
                    <li class="breadcrumb-item active" aria-current="page">空間管理</li>
                </ol>
                <h4 class="mt-2 mb-0">空間管理</h4>
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

            <!-- 場地列表 -->
            <div class="panel-row">
                <h5><i class="bi bi-list-ul"></i> 場地列表</h5>
                <form method="GET" class="d-flex gap-2 align-items-center flex-wrap mb-2">
                    <select name="sp_status" class="form-select form-select-sm" style="width:auto">
                        <option value="">所有狀態</option>
                        <option value="available"   <?= $sp_status==='available'  ?'selected':'' ?>>可用</option>
                        <option value="unavailable" <?= $sp_status==='unavailable'?'selected':'' ?>>不可用</option>
                    </select>
                    <button type="submit" class="btn btn-primary btn-sm"><i class="bi bi-funnel"></i> 套用</button>
                    <?php if ($sp_status !== ''): ?>
                    <a href="space_mgmt.php" class="btn btn-sm btn-outline-secondary"><i class="bi bi-x-circle"></i> 清除</a>
                    <?php endif; ?>
                    <small class="text-muted ms-auto">顯示 <?= count($spaces_list) ?> / <?= $total_spaces ?> 個場地</small>
                </form>
                <div id="spaceSortBar" style="display:flex;gap:.5rem;align-items:center;margin-bottom:1rem;flex-wrap:wrap;">
                    <span style="font-size:.83rem;color:#6b7280;">排序：</span>
                    <button id="sortNameBtn" class="sort-btn sort-active" onclick="sortSpaces('name')">
                        <i class="bi bi-sort-alpha-down"></i> 名稱 <span id="sortNameDir">↓</span>
                    </button>
                    <button id="sortCapBtn" class="sort-btn" onclick="sortSpaces('capacity')">
                        <i class="bi bi-people"></i> 容量 <span id="sortCapDir">↓</span>
                    </button>
                </div>
                <div style="overflow-x: auto;">
                    <table>
                        <thead>
                            <tr>
                                <th>場地名稱</th>
                                <th>容納人數</th>
                                <th>狀態</th>
                                <th>操作</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($spaces_list as $space): ?>
                            <tr data-name="<?= htmlspecialchars(mb_strtolower($space['space_name'])) ?>" data-capacity="<?= intval($space['capacity']) ?>">
                                <td><strong><?php echo htmlspecialchars($space['space_name']); ?></strong></td>
                                <td><?php echo intval($space['capacity']); ?></td>
                                <td>
                                    <span class="status-badge status-<?php echo htmlspecialchars($space['space_status'] ?? 'available'); ?>">
                                        <i class="bi bi-<?php echo ($space['space_status'] ?? 'available') === 'available' ? 'check-lg' : 'x-lg'; ?>"></i>
                                        <?php echo ($space['space_status'] ?? 'available') === 'available' ? '可用' : '不可用'; ?>
                                    </span>
                                </td>
                                <td>
                                    <div class="action-cell">
                                        <form method="POST" style="display: inline;">
                                            <input type="hidden" name="action" value="edit">
                                            <input type="hidden" name="space_id" value="<?php echo intval($space['space_id']); ?>">
                                            <button type="submit" class="btn btn-edit btn-sm"><i class="bi bi-pencil"></i> 編輯</button>
                                        </form>
                                        <form method="POST" style="display: inline;" onsubmit="return confirm('確定要刪除此場地？');">
                                            <input type="hidden" name="action" value="delete">
                                            <input type="hidden" name="space_id" value="<?php echo intval($space['space_id']); ?>">
                                            <button type="submit" class="btn btn-delete btn-sm"><i class="bi bi-trash"></i> 刪除</button>
                                        </form>
                                    </div>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                            
                            <?php if (empty($spaces_list)): ?>
                            <tr>
                                <td colspan="4" style="text-align: center; color: #999; padding: 30px;">
                                    <i class="bi bi-inbox"></i> 目前沒有場地記錄
                                </td>
                            </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>

            <div class="panel-row">
                <h5><i class="bi bi-plus-circle"></i> 新增場地</h5>
                <?php if ($edit_error && empty($edit_space)): ?>
                    <div class="alert alert-danger"><?php echo htmlspecialchars($edit_error); ?></div>
                <?php endif; ?>
                <form method="POST" class="mb-4">
                    <input type="hidden" name="action" value="add">
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label">場地名稱</label>
                            <input type="text" name="space_name" class="form-control" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">容納人數</label>
                            <input type="number" name="capacity" class="form-control" value="0" min="0" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">狀態</label>
                            <select name="space_status" class="form-select" required>
                                <option value="available">可用</option>
                                <option value="unavailable">不可用</option>
                            </select>
                        </div>
                    </div>
                    <div class="mt-3 d-flex gap-2">
                        <button type="submit" class="btn btn-primary btn-sm"><i class="bi bi-plus-lg"></i> 新增場地</button>
                    </div>
                </form>
            </div>

            <?php if (!empty($edit_space)): ?>
            <div class="modal fade" id="editSpaceModal" tabindex="-1" aria-labelledby="editSpaceModalLabel" aria-hidden="true">
                <div class="modal-dialog">
                    <div class="modal-content">
                        <div class="modal-header">
                            <h5 class="modal-title" id="editSpaceModalLabel"><i class="bi bi-pencil-square"></i> 編輯場地</h5>
                            <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="關閉"></button>
                        </div>
                        <div class="modal-body">
                            <?php if ($edit_error): ?>
                                <div class="alert alert-danger"><?php echo htmlspecialchars($edit_error); ?></div>
                            <?php endif; ?>
                            <form method="POST" id="editSpaceForm">
                                <input type="hidden" name="action" value="save">
                                <input type="hidden" name="space_id" value="<?php echo intval($edit_space['space_id']); ?>">
                                <div class="row g-3">
                                    <div class="col-12">
                                        <label class="form-label">場地名稱</label>
                                        <input type="text" name="space_name" class="form-control" value="<?php echo htmlspecialchars($edit_space['space_name']); ?>" required>
                                    </div>
                                    <div class="col-12">
                                        <label class="form-label">容納人數</label>
                                        <input type="number" name="capacity" class="form-control" value="<?php echo intval($edit_space['capacity']); ?>" min="0" required>
                                    </div>
                                    <div class="col-12">
                                        <label class="form-label">狀態</label>
                                        <select name="space_status" class="form-select" required>
                                            <option value="available" <?php echo ($edit_space['space_status'] ?? 'available') === 'available' ? 'selected' : ''; ?>>可用</option>
                                            <option value="unavailable" <?php echo ($edit_space['space_status'] ?? 'available') === 'unavailable' ? 'selected' : ''; ?>>不可用</option>
                                        </select>
                                    </div>
                                </div>
                            </form>
                        </div>
                        <div class="modal-footer">
                            <button type="button" class="btn btn-outline-primary btn-sm" data-bs-dismiss="modal"><i class="bi bi-x-circle"></i> 取消</button>
                            <button type="submit" form="editSpaceForm" class="btn btn-primary btn-sm"><i class="bi bi-save"></i> 儲存變更</button>
                        </div>
                    </div>
                </div>
            </div>
            <script>
                document.addEventListener('DOMContentLoaded', function () {
                    const editModal = new bootstrap.Modal(document.getElementById('editSpaceModal'));
                    editModal.show();
                });
            </script>
            <?php endif; ?>
        </section>
    </main>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // 設置菜單活動狀態
        document.addEventListener('DOMContentLoaded', function() {
            const currentPath = window.location.pathname;
            document.querySelectorAll('.nav-link').forEach(link => {
                if (link.href.includes('space_mgmt.php')) {
                    link.classList.add('active');
                } else {
                    link.classList.remove('active');
                }
            });
        });
    </script>
    <script>
        let spaceSortKey = 'name';
        let spaceSortDir = 'asc';
        function sortSpaces(key, toggle) {
            if (toggle === undefined) toggle = true;
            if (spaceSortKey === key) {
                if (toggle) spaceSortDir = spaceSortDir === 'asc' ? 'desc' : 'asc';
            } else {
                spaceSortKey = key;
                spaceSortDir = key === 'name' ? 'asc' : 'desc';
            }
            document.querySelectorAll('#spaceSortBar .sort-btn').forEach(b => b.classList.remove('sort-active'));
            document.getElementById(key === 'name' ? 'sortNameBtn' : 'sortCapBtn').classList.add('sort-active');
            document.getElementById('sortNameDir').textContent = spaceSortKey === 'name' ? (spaceSortDir === 'asc' ? '↑' : '↓') : '↑';
            document.getElementById('sortCapDir').textContent  = spaceSortKey === 'capacity' ? (spaceSortDir === 'asc' ? '↑' : '↓') : '↓';
            var tbody = document.querySelector('#spaceSortBar ~ div table tbody');
            var rows = Array.from(tbody.querySelectorAll('tr[data-name]'));
            rows.sort(function(a, b) {
                if (spaceSortKey === 'name') {
                    var cmp = a.dataset.name.localeCompare(b.dataset.name, 'zh-TW');
                    return spaceSortDir === 'asc' ? cmp : -cmp;
                } else {
                    var cmp = parseInt(a.dataset.capacity) - parseInt(b.dataset.capacity);
                    return spaceSortDir === 'asc' ? cmp : -cmp;
                }
            });
            rows.forEach(function(r) { tbody.appendChild(r); });
        }
        document.addEventListener('DOMContentLoaded', function() { sortSpaces('name', false); });
    </script>
</body>
</html>
