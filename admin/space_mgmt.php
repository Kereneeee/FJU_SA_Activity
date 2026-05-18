<?php
ini_set('display_errors', 1);
error_reporting(E_ALL);

require_once(__DIR__ . "/../DB/db_config.php");

if (!isset($_SESSION['user_id'])) {
    header('Location: ../login.php');
    exit();
}

$user_name = $_SESSION['user_name'] ?? '管理員';
$user_id = $_SESSION['user_id'];

// 取得所有場地
$sql_spaces = "SELECT * FROM spaces ORDER BY space_id ASC";
$result_spaces = $conn->query($sql_spaces);
if (!$result_spaces) {
    die("查詢錯誤: " . $conn->error);
}
$spaces_list = $result_spaces->fetch_all(MYSQLI_ASSOC);
if (!$spaces_list) $spaces_list = [];

// 統計資料
$total_spaces = count($spaces_list);
$available_spaces = 0;
$unavailable_spaces = 0;
$total_capacity = 0;

foreach ($spaces_list as $space) {
    $total_capacity += intval($space['capacity']);
    if ($space['status'] === 'available') {
        $available_spaces++;
    } else {
        $unavailable_spaces++;
    }
}

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
        $status = trim($_POST['status'] ?? 'available');
        
        if ($space_name === '') {
            $edit_error = '請填寫場地名稱';
        } else {
            $stmt = $conn->prepare("UPDATE spaces SET space_name = ?, capacity = ?, status = ? WHERE space_id = ?");
            if ($stmt) {
                $stmt->bind_param("sisi", $space_name, $capacity, $status, $space_id);
                if ($stmt->execute()) {
                    $success_msg = '場地已更新';
                    $edit_space = null;
                    // 刷新列表
                    $result_spaces = $conn->query($sql_spaces);
                    $spaces_list = $result_spaces->fetch_all(MYSQLI_ASSOC);
                } else {
                    $edit_error = '更新失敗: ' . $stmt->error;
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
                    // 刷新列表
                    $result_spaces = $conn->query($sql_spaces);
                    $spaces_list = $result_spaces->fetch_all(MYSQLI_ASSOC);
                } else {
                    $edit_error = '刪除失敗: ' . $stmt->error;
                }
                $stmt->close();
            }
        }
    } elseif ($action === 'add') {
        $space_name = trim($_POST['space_name'] ?? '');
        $capacity = intval($_POST['capacity'] ?? 0);
        $status = trim($_POST['status'] ?? 'available');
        
        if ($space_name === '') {
            $edit_error = '請填寫場地名稱';
        } else {
            $stmt = $conn->prepare("INSERT INTO spaces (space_name, capacity, status) VALUES (?, ?, ?)");
            if ($stmt) {
                $stmt->bind_param("sis", $space_name, $capacity, $status);
                if ($stmt->execute()) {
                    $success_msg = '場地已新增';
                    // 刷新列表
                    $result_spaces = $conn->query($sql_spaces);
                    $spaces_list = $result_spaces->fetch_all(MYSQLI_ASSOC);
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
            border-radius: 18px;
            box-shadow: 0 10px 30px rgba(15,23,42,0.06);
            padding: 1.5rem;
            min-height: 150px;
            display: flex;
            flex-direction: column;
            justify-content: space-between;
        }
        .card-panel .icon-box {
            width: 50px;
            height: 50px;
            border-radius: 14px;
            display: grid;
            place-items: center;
            color: white;
            font-size: 1.25rem;
        }
        .card-panel.total .icon-box { background: #6f42c1; }
        .card-panel.available .icon-box { background: #198754; }
        .card-panel.unavailable .icon-box { background: #dc3545; }
        .card-panel.capacity .icon-box { background: #0d6efd; }
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
            box-shadow: 0 0 0 0.2rem rgba(139,21,56,0.25);
            outline: none;
        }
        @media (max-width: 1100px) {
            .summary-row { grid-template-columns: repeat(2, 1fr); }
            .main-content { margin-left: 0; }
        }
        @media (max-width: 768px) {
            .top-navbar { flex-direction: column; align-items: flex-start; gap: 1rem; padding: 1rem; }
            .sidebar { position: relative; width: 100%; height: auto; box-shadow: none; }
            .summary-row { grid-template-columns: 1fr; }
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
            <a class="nav-link" href="review.php"><i class="bi bi-clipboard-check"></i> 審核管理</a>
            <a class="nav-link" href="event_mgmt.php"><i class="bi bi-calendar-check"></i> 申請紀錄</a>
            <a class="nav-link" href="equipment_mgmt.php"><i class="bi bi-tools"></i> 器材庫存管理</a>
            <a class="nav-link active" href="space_mgmt.php"><i class="bi bi-building"></i> 空間管理</a>
            <a class="nav-link" href="calendar.php"><i class="bi bi-calendar3"></i> 完整行事曆</a>
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
                    <li class="breadcrumb-item active" aria-current="page">空間管理</li>
                </ol>
                <h4 class="mt-2 mb-0">空間管理</h4>
            </div>
            <div class="d-flex align-items-center gap-3" style="cursor: pointer;" onclick="location.href='profile.php'" title="點擊查看個人檔案">
                <span class="text-muted"><?php echo htmlspecialchars($user_name); ?></span>
                <div class="user-avatar" style="width: 40px; height: 40px; border-radius: 50%; background: var(--primary); color: white; display: flex; align-items: center; justify-content: center; font-weight: 700;">
                    <?php echo substr($user_name, 0, 1); ?>
                </div>
            </div>
        </header>

        <section class="dashboard-grid">
            <!-- 統計卡片 -->
            <div class="summary-row">
                <div class="card-panel total">
                    <div class="d-flex justify-content-between align-items-start">
                        <div>
                            <div class="label">場地總數</div>
                            <div class="value"><?php echo $total_spaces; ?></div>
                        </div>
                        <div class="icon-box"><i class="bi bi-building"></i></div>
                    </div>
                </div>
                <div class="card-panel available">
                    <div class="d-flex justify-content-between align-items-start">
                        <div>
                            <div class="label">可用場地</div>
                            <div class="value"><?php echo $available_spaces; ?></div>
                        </div>
                        <div class="icon-box"><i class="bi bi-check-circle"></i></div>
                    </div>
                </div>
                <div class="card-panel unavailable">
                    <div class="d-flex justify-content-between align-items-start">
                        <div>
                            <div class="label">不可用場地</div>
                            <div class="value"><?php echo $unavailable_spaces; ?></div>
                        </div>
                        <div class="icon-box"><i class="bi bi-x-circle"></i></div>
                    </div>
                </div>
                <div class="card-panel capacity">
                    <div class="d-flex justify-content-between align-items-start">
                        <div>
                            <div class="label">總容納人數</div>
                            <div class="value"><?php echo $total_capacity; ?></div>
                        </div>
                        <div class="icon-box"><i class="bi bi-people"></i></div>
                    </div>
                </div>
            </div>

            <?php if ($success_msg): ?>
            <div class="alert alert-success">
                <i class="bi bi-check-circle"></i> <?php echo htmlspecialchars($success_msg); ?>
            </div>
            <?php endif; ?>

            <?php if (!empty($edit_space)): ?>
            <div class="panel-row">
                <h5><i class="bi bi-pencil-square"></i> 編輯場地</h5>
                <?php if ($edit_error): ?>
                    <div class="alert alert-danger"><?php echo htmlspecialchars($edit_error); ?></div>
                <?php endif; ?>
                <form method="POST" class="mb-4">
                    <input type="hidden" name="action" value="save">
                    <input type="hidden" name="space_id" value="<?php echo intval($edit_space['space_id']); ?>">
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label">場地名稱</label>
                            <input type="text" name="space_name" class="form-control" value="<?php echo htmlspecialchars($edit_space['space_name']); ?>" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">容納人數</label>
                            <input type="number" name="capacity" class="form-control" value="<?php echo intval($edit_space['capacity']); ?>" min="0" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">狀態</label>
                            <select name="status" class="form-select" required>
                                <option value="available" <?php echo $edit_space['status'] === 'available' ? 'selected' : ''; ?>>可用</option>
                                <option value="unavailable" <?php echo $edit_space['status'] === 'unavailable' ? 'selected' : ''; ?>>不可用</option>
                            </select>
                        </div>
                    </div>
                    <div class="mt-3 d-flex gap-2">
                        <button type="submit" class="btn btn-primary btn-sm"><i class="bi bi-save"></i> 儲存變更</button>
                        <a href="space_mgmt.php" class="btn btn-outline-primary btn-sm"><i class="bi bi-x-circle"></i> 取消</a>
                    </div>
                </form>
            </div>
            <?php else: ?>
            <div class="panel-row">
                <h5><i class="bi bi-plus-circle"></i> 新增場地</h5>
                <?php if ($edit_error): ?>
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
                            <select name="status" class="form-select" required>
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
            <?php endif; ?>

            <!-- 場地列表 -->
            <div class="panel-row">
                <h5><i class="bi bi-list-ul"></i> 場地列表</h5>
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
                            <tr>
                                <td><strong><?php echo htmlspecialchars($space['space_name']); ?></strong></td>
                                <td><?php echo intval($space['capacity']); ?></td>
                                <td>
                                    <span class="status-badge status-<?php echo $space['status']; ?>">
                                        <i class="bi bi-<?php echo $space['status'] === 'available' ? 'check-lg' : 'x-lg'; ?>"></i>
                                        <?php echo $space['status'] === 'available' ? '可用' : '不可用'; ?>
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
        </section>
    </main>

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
</body>
</html>
