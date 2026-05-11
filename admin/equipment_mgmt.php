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

// 取得器材列表
$sql = "SELECT * FROM equipment ORDER BY equipment_id ASC";
$result = $conn->query($sql);
if (!$result) {
    die("查詢錯誤: " . $conn->error);
}
$equipment_list = $result->fetch_all(MYSQLI_ASSOC);
if (!$equipment_list) $equipment_list = [];

// 統計資料
$total_equipment = count($equipment_list);
$total_quantity = 0;
$available_quantity = 0;
foreach ($equipment_list as $eq) {
    $total_quantity += intval($eq['total_quantity']);
    $available_quantity += intval($eq['available_quantity']);
}
$borrowed_quantity = $total_quantity - $available_quantity;

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
        .card-panel.items .icon-box { background: #0d6efd; }
        .card-panel.total .icon-box { background: #6f42c1; }
        .card-panel.available .icon-box { background: #198754; }
        .card-panel.borrowed .icon-box { background: #fd7e14; }
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
            <div class="dropdown">
                <a class="nav-link active" href="review.php"><i class="bi bi-clipboard-check"></i> 審核管理</a>
                <div class="dropdown-content">
                    <a href="event_mgmt.php"><i class="bi bi-calendar-check"></i> 活動管理</a>
                    <a href="equipment_mgmt.php"><i class="bi bi-tools"></i> 器材管理</a>
                    <a href="space_mgmt.php"><i class="bi bi-building"></i> 空間管理</a>
                </div>
            </div>
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
                    <li class="breadcrumb-item active" aria-current="page">器材管理</li>
                </ol>
                <h4 class="mt-2 mb-0">器材管理</h4>
            </div>
            <div class="d-flex align-items-center gap-3">
                <span class="text-muted"><?php echo htmlspecialchars($user_name); ?></span>
                <div class="user-avatar" style="width: 40px; height: 40px; border-radius: 50%; background: var(--primary); color: white; display: flex; align-items: center; justify-content: center; font-weight: 700;">
                    <?php echo substr($user_name, 0, 1); ?>
                </div>
            </div>
        </header>

        <section class="dashboard-grid">


            <!-- 統計卡片 -->
            <div class="summary-row">
                <div class="card-panel items">
                    <div class="d-flex justify-content-between align-items-start">
                        <div>
                            <div class="label">器材種類數</div>
                            <div class="value"><?php echo $total_equipment; ?></div>
                        </div>
                        <div class="icon-box"><i class="bi bi-box-seam"></i></div>
                    </div>
                </div>
                <div class="card-panel total">
                    <div class="d-flex justify-content-between align-items-start">
                        <div>
                            <div class="label">總數量</div>
                            <div class="value"><?php echo $total_quantity; ?></div>
                        </div>
                        <div class="icon-box"><i class="bi bi-boxes"></i></div>
                    </div>
                </div>
                <div class="card-panel available">
                    <div class="d-flex justify-content-between align-items-start">
                        <div>
                            <div class="label">可借數量</div>
                            <div class="value"><?php echo $available_quantity; ?></div>
                        </div>
                        <div class="icon-box"><i class="bi bi-check-circle"></i></div>
                    </div>
                </div>
                <div class="card-panel borrowed">
                    <div class="d-flex justify-content-between align-items-start">
                        <div>
                            <div class="label">借出數量</div>
                            <div class="value"><?php echo $borrowed_quantity; ?></div>
                        </div>
                        <div class="icon-box"><i class="bi bi-arrow-left-circle"></i></div>
                    </div>
                </div>
            </div>

            <!-- 器材列表 -->
            <div class="panel-row">
                <h5><i class="bi bi-list-ul"></i> 器材庫存列表</h5>
                <table>
                    <thead>
                        <tr>
                            <th>器材名稱</th>
                            <th>總數量</th>
                            <th>可借數量</th>
                            <th>借出數量</th>
                            <th>狀態</th>
                            <th>庫存</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($equipment_list as $eq): ?>
                        <?php 
                            $borrowed = $eq['total_quantity'] - $eq['available_quantity'];
                            $usage_percent = $eq['total_quantity'] > 0 ? ($borrowed / $eq['total_quantity']) * 100 : 0;
                        ?>
                        <tr>
                            <td><strong><?php echo htmlspecialchars($eq['name']); ?></strong></td>
                            <td><?php echo $eq['total_quantity']; ?></td>
                            <td><?php echo $eq['available_quantity']; ?></td>
                            <td><?php echo $borrowed; ?></td>
                            <td>
                                <span class="status-badge status-<?php echo $eq['status']; ?>">
                                    <i class="bi bi-<?php echo $eq['status'] === 'available' ? 'check-lg' : 'x-lg'; ?>"></i>
                                    <?php echo $eq['status'] === 'available' ? '可借用' : '不可借用'; ?>
                                </span>
                            </td>
                            <td>
                                <div class="quantity-bar">
                                    <div class="bar-bg">
                                        <div class="bar-fill" style="width: <?php echo 100 - $usage_percent; ?>%"></div>
                                    </div>
                                    <span class="bar-text"><?php echo round(100 - $usage_percent); ?>%</span>
                                </div>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                        
                        <?php if (empty($equipment_list)): ?>
                        <tr>
                            <td colspan="6" style="text-align: center; color: #999; padding: 30px;">
                                <i class="bi bi-inbox"></i> 目前沒有器材記錄
                            </td>
                        </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </section>
    </main>

</body>
</html>
