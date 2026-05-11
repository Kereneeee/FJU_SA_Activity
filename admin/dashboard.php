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
?>

<!DOCTYPE html>
<html lang="zh-TW">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>管理員儀表板 - 輔仁大學課外活動指導組</title>

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
        .top-navbar .user-card {
            display: flex;
            align-items: center;
            gap: 0.85rem;
        }
        .user-avatar {
            width: 44px;
            height: 44px;
            border-radius: 50%;
            background: var(--primary);
            color: white;
            display: grid;
            place-items: center;
            font-weight: 700;
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
        .card-panel.events .icon-box { background: #d63384; }
        .card-panel.pending .icon-box { background: #f59f00; }
        .card-panel.spaces .icon-box { background: #198754; }
        .card-panel.equipment .icon-box { background: #0d6efd; }
        .card-panel .value {
            font-size: 2rem;
            font-weight: 700;
            margin-top: 1rem;
        }
        .card-panel .label { color: #6b7280; }
        .quick-actions {
            display: grid;
            grid-template-columns: repeat(3, minmax(0, 1fr));
            gap: 1.25rem;
            margin-bottom: 1.5rem;
        }
        .action-card {
            background: linear-gradient(135deg, #8b1538 0%, #c2185b 100%);
            color: white;
            border-radius: 18px;
            padding: 1.7rem;
            cursor: pointer;
            transition: transform 0.25s ease, box-shadow 0.25s ease;
            min-height: 180px;
            display: flex;
            flex-direction: column;
            justify-content: space-between;
        }
        .action-card:hover {
            transform: translateY(-4px);
            box-shadow: 0 18px 40px rgba(139,21,56,0.2);
        }
        .action-card .action-top {
            display: flex;
            align-items: center;
            justify-content: space-between;
        }
        .action-card .action-icon {
            width: 52px;
            height: 52px;
            border-radius: 14px;
            background: rgba(255,255,255,0.15);
            display: grid;
            place-items: center;
            font-size: 1.35rem;
        }
        .action-card h6 {
            margin: 1rem 0 0.5rem;
            font-size: 1.05rem;
            font-weight: 700;
        }
        .action-card p {
            margin: 0;
            color: rgba(255,255,255,0.85);
            line-height: 1.6;
        }
        .panel-row {
            display: grid;
            grid-template-columns: 2fr 1fr;
            gap: 1.25rem;
        }
        .panel-full {
            background: var(--card);
            border-radius: 18px;
            box-shadow: 0 10px 30px rgba(15,23,42,0.06);
            padding: 1.5rem;
        }
        .panel-full h5 {
            margin-bottom: 1rem;
            font-weight: 700;
        }
        .event-list, .notification-list {
            display: grid;
            gap: 0.75rem;
        }
        .event-card, .notification-card {
            border: 1px solid #e5e7eb;
            border-radius: 14px;
            padding: 1rem 1.15rem;
            background: white;
        }
        .event-card .title,
        .notification-card .title {
            font-weight: 700;
            margin-bottom: 0.4rem;
        }
        .event-card .meta,
        .notification-card .meta {
            color: #6b7280;
            font-size: 0.9rem;
        }
        .status-pill {
            display: inline-flex;
            align-items: center;
            gap: 0.35rem;
            padding: 0.45rem 0.85rem;
            border-radius: 999px;
            font-size: 0.8rem;
            font-weight: 600;
        }
        .status-approved { background: #d1e7dd; color: #0f5132; }
        .status-pending { background: #fff3cd; color: #664d03; }
        .status-alert { background: #f8d7da; color: #842029; }
        .notification-list .notification-card {
            display: grid;
            grid-template-columns: 1fr auto;
            gap: 1rem;
            align-items: flex-start;
        }
        .notification-card .badge {
            font-size: 0.8rem;
            border-radius: 999px;
        }
        .notification-card .badge-new { background: #0d6efd; }
        .notification-card .badge-update { background: #198754; }
        .notification-card .badge-alert { background: #dc3545; }
        .footer-note {
            margin-top: 0.75rem;
            color: #6b7280;
            font-size: 0.9rem;
        }
        @media (max-width: 1100px) {
            .summary-row, .quick-actions, .panel-row { grid-template-columns: 1fr; }
            .main-content { margin-left: 0; }
        }
        @media (max-width: 768px) {
            .top-navbar { flex-direction: column; align-items: flex-start; gap: 1rem; padding: 1rem; }
            .sidebar { position: relative; width: 100%; height: auto; box-shadow: none; }
        }
    </style>
</head>
<body>
    <aside class="sidebar">
        <div class="brand">
            <h4>輔仁大學<br>課外活動指導組</h4>
        </div>
        <nav class="nav flex-column">
            <a class="nav-link active" href="dashboard.php"><i class="bi bi-house-door"></i> 儀表板</a>
            <div class="dropdown">
                <a class="nav-link" href="review.php"><i class="bi bi-clipboard-check"></i> 審核管理</a>
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
                    <li class="breadcrumb-item active" aria-current="page">管理員儀表板</li>
                </ol>
                <h4 class="mt-2 mb-0">系統管理中心</h4>
            </div>
            <div class="user-card">
                <div class="user-avatar"><?php echo htmlspecialchars(substr($user_name, 0, 1)); ?></div>
                <div>
                    <div class="fw-bold"><?php echo htmlspecialchars($user_name); ?></div>
                    <small class="text-muted">管理員</small>
                </div>
            </div>
        </header>

        <section class="dashboard-grid">
            <div style="padding: 1.5rem 0; background: #e3f2fd; border-radius: 12px; margin-bottom: 1.5rem; padding: 1.5rem;">
                <div style="display: flex; align-items: center; gap: 1rem; flex-wrap: wrap;">
                    <div style="flex: 1;">
                        <h5 style="margin: 0 0 0.5rem; color: #1565c0;">
                            <i class="bi bi-shield-check"></i> 歡迎回來，<?php echo htmlspecialchars($user_name); ?>！
                        </h5>
                        <p style="margin: 0; color: #0d47a1; font-size: 0.9rem;">
                            今天是 <?php echo date('Y年m月d日'); ?>
                        </p>
                    </div>
                </div>
            </div>
            <div class="summary-row">
                <div class="card-panel pending">
                    <div class="d-flex justify-content-between align-items-start">
                        <div>
                            <div class="label">待審核申請</div>
                            <div class="value">4</div>
                        </div>
                        <div class="icon-box"><i class="bi bi-hourglass-split"></i></div>
                    </div>
                    <p class="footer-note">提醒您處理未決申請。</p>
                </div>
            </div>

            <div class="quick-actions">
                <div class="action-card" onclick="location.href='event_mgmt.php'">
                    <div class="action-top">
                        <span>活動管理</span>
                        <div class="action-icon"><i class="bi bi-calendar-check"></i></div>
                    </div>
                    <h6>管理活動申請</h6>
                    <p>審核、編輯或刪除活動申請。</p>
                </div>
                <div class="action-card" onclick="location.href='equipment_mgmt.php'">
                    <div class="action-top">
                        <span>器材管理</span>
                        <div class="action-icon"><i class="bi bi-tools"></i></div>
                    </div>
                    <h6>管理器材庫存</h6>
                    <p>新增、編輯或刪除器材項目。</p>
                </div>
                <div class="action-card" onclick="location.href='review.php'">
                    <div class="action-top">
                        <span>審核管理</span>
                        <div class="action-icon"><i class="bi bi-clipboard-check"></i></div>
                    </div>
                    <h6>審核申請案件</h6>
                    <p>快速處理待審核的申請案件。</p>
                </div>
            </div>

            <div class="panel-row">
                <section class="panel-full">
                    <h5>近期活動列表</h5>
                    <div class="event-list">
                        <div class="event-card">
                            <div class="d-flex justify-content-between align-items-start">
                                <div>
                                    <div class="title">聖誕節慶祝會</div>
                                    <div class="meta">12/20 14:00-17:00 · 綜合大樓一樓</div>
                                </div>
                                <span class="status-pill status-approved">已核准</span>
                            </div>
                        </div>
                        <div class="event-card">
                            <div class="d-flex justify-content-between align-items-start">
                                <div>
                                    <div class="title">迎新宿營籌備</div>
                                    <div class="meta">12/08 全天 · 校外營地</div>
                                </div>
                                <span class="status-pill status-pending">審核中</span>
                            </div>
                        </div>
                        <div class="event-card">
                            <div class="d-flex justify-content-between align-items-start">
                                <div>
                                    <div class="title">期末讀書交流</div>
                                    <div class="meta">12/10 19:00-21:00 · 圖書館討論室</div>
                                </div>
                                <span class="status-pill status-approved">已核准</span>
                            </div>
                        </div>
                    </div>
                </section>
                <section class="panel-full">
                    <h5>最新通知</h5>
                    <div class="notification-list">
                        <div class="notification-card">
                            <div>
                                <div class="title">系統維護提醒</div>
                                <div class="meta">今晚 22:00-24:00 進行系統更新。</div>
                            </div>
                            <span class="badge badge-update text-white px-2 py-1">更新</span>
                        </div>
                        <div class="notification-card">
                            <div>
                                <div class="title">申請通過通知</div>
                                <div class="meta">您的「聖誕節慶祝會」申請已完成審核。</div>
                            </div>
                            <span class="badge badge-new text-white px-2 py-1">新消息</span>
                        </div>
                        <div class="notification-card">
                            <div>
                                <div class="title">器材歸還提醒</div>
                                <div class="meta">請於今日內歸還麥克風設備。</div>
                            </div>
                            <span class="badge badge-alert text-white px-2 py-1">提醒</span>
                        </div>
                    </div>
                </section>
            </div>
        </section>
    </main>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
