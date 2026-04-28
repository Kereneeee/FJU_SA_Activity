<?php
ini_set('display_errors', 1);
error_reporting(E_ALL);


require_once(__DIR__ . "/../DB/db_config.php");

if (!isset($_SESSION['student_id'])) {
    header('Location: ../login.php');
    exit();
}

$student_name = $_SESSION['student_name'] ?? '學生';
$student_id = $_SESSION['student_id'];
$user_id = $_SESSION['user_id'] ?? null;

// 設置當前頁面用於側邊欄高亮
$current_page = 'dashboard';

// 獲取用戶的社團及幹部身分
$is_officer = false;
$current_club = null;
$officer_title = null;

if ($user_id) {
    // 先查詢用戶的所有社團
    $club_sql = "SELECT cm.*, c.club_name
                 FROM club_members cm
                 JOIN clubs c ON cm.club_id = c.club_id
                 WHERE cm.user_id = ?
                 ORDER BY cm.join_date DESC
                 LIMIT 1";
    
    $club_stmt = $conn->prepare($club_sql);
    if ($club_stmt) {
        $club_stmt->bind_param("i", $user_id);
        $club_stmt->execute();
        $club_result = $club_stmt->get_result();
        
        if ($club_row = $club_result->fetch_assoc()) {
            $current_club = $club_row['club_name'];
            $officer_title = $club_row['officer_title'];
            
            // 檢查幹部身分是否有效（未超過一年）
            if ($club_row['is_officer'] && $club_row['officer_confirmation_date']) {
                $confirm_date = strtotime($club_row['officer_confirmation_date']);
                $today = time();
                $days_diff = floor(($today - $confirm_date) / (60 * 60 * 24));
                
                if ($days_diff < 365) {
                    $is_officer = true;
                }
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
    <title>學生儀表板 - 輔仁大學課外活動指導組</title>

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
    <?php include(__DIR__ . "/../includes/sidebar.php"); ?>

    <main class="main-content">
        <header class="top-navbar">
            <div>
                <ol class="breadcrumb mb-0">
                    <li class="breadcrumb-item"><a href="dashboard.php">首頁</a></li>
                    <li class="breadcrumb-item active" aria-current="page">儀表板</li>
                </ol>
                <h4 class="mt-2 mb-0">學生管理中心</h4>
            </div>
            <div class="user-card" style="cursor: pointer;" onclick="location.href='profile.php'" title="點擊查看個人檔案">
                <div class="user-avatar"><?php echo htmlspecialchars(substr($student_name, 0, 1)); ?></div>
                <div>
                    <div class="fw-bold"><?php echo htmlspecialchars($student_name); ?></div>
                    <small class="text-muted">學號：<?php echo htmlspecialchars($student_id); ?></small>
                </div>
            </div>
        </header>

        <section class="dashboard-grid">
            <div style="padding: 1.5rem 0; background: #e3f2fd; border-radius: 12px; margin-bottom: 1.5rem; padding: 1.5rem;">
                <div style="display: flex; align-items: center; gap: 1rem; flex-wrap: wrap;">
                    <div style="flex: 1;">
                        <h5 style="margin: 0 0 0.5rem; color: #1565c0;">
                            <i class="bi bi-sunrise"></i> 歡迎回來，<?php echo htmlspecialchars($student_name); ?>！
                        </h5>
                        <p style="margin: 0; color: #0d47a1; font-size: 0.9rem;">
                            今天是 <?php echo date('Y年m月d日'); ?>
                            <?php if ($current_club): ?>
                                | <i class="bi bi-people"></i> <strong><?php echo htmlspecialchars($current_club); ?></strong>
                                <?php if ($is_officer): ?>
                                    <span style="background: #4caf50; color: white; padding: 0.25rem 0.75rem; border-radius: 999px; font-size: 0.8rem; font-weight: 600;">
                                        <?php echo htmlspecialchars($officer_title ?? '幹部'); ?>
                                    </span>
                                <?php endif; ?>
                            <?php endif; ?>
                        </p>
                    </div>
                    <?php if ($current_club): ?>
                    <a href="profile.php" style="padding: 0.5rem 1rem; background: #1565c0; color: white; text-decoration: none; border-radius: 8px; font-size: 0.85rem;">
                        切換身分
                    </a>
                    <?php endif; ?>
                </div>
            </div>
            <div class="summary-row">
                <div class="card-panel events">
                    <div class="d-flex justify-content-between align-items-start">
                        <div>
                            <div class="label">本月活動申請</div>
                            <div class="value">18</div>
                        </div>
                        <div class="icon-box"><i class="bi bi-calendar-event"></i></div>
                    </div>
                    <p class="footer-note">查看最新申請狀態與提醒。</p>
                </div>
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
                <div class="card-panel spaces">
                    <div class="d-flex justify-content-between align-items-start">
                        <div>
                            <div class="label">可用空間</div>
                            <div class="value">9</div>
                        </div>
                        <div class="icon-box"><i class="bi bi-geo-alt"></i></div>
                    </div>
                    <p class="footer-note">快速前往場地申請頁面。</p>
                </div>
                <div class="card-panel equipment">
                    <div class="d-flex justify-content-between align-items-start">
                        <div>
                            <div class="label">可借器材</div>
                            <div class="value">26</div>
                        </div>
                        <div class="icon-box"><i class="bi bi-tools"></i></div>
                    </div>
                    <p class="footer-note">檢視器材庫存與借用紀錄。</p>
                </div>
            </div>

            <div class="quick-actions">
                <?php if ($is_officer): ?>
                <div class="action-card" onclick="location.href='apply_event.php'">
                    <div class="action-top">
                        <span>活動申請</span>
                        <div class="action-icon"><i class="bi bi-plus-lg"></i></div>
                    </div>
                    <h6>立即新增活動</h6>
                    <p>快速建立活動申請並查看審核進度。</p>
                </div>
                <div class="action-card" onclick="location.href='calendar.php'">
                    <div class="action-top">
                        <span>場地申請</span>
                        <div class="action-icon"><i class="bi bi-building"></i></div>
                    </div>
                    <h6>預約可用場地</h6>
                    <p>檢視場地空檔並提交申請表單。</p>
                </div>
                <div class="action-card" onclick="location.href='field_coord.php'">
                    <div class="action-top">
                        <span>場協意願</span>
                        <div class="action-icon"><i class="bi bi-people-fill"></i></div>
                    </div>
                    <h6>登記場地協助</h6>
                    <p>加入場協團隊並管理您的可協助時段。</p>
                </div>
                <?php else: ?>
                <div class="action-card" style="opacity: 0.6; cursor: not-allowed;">
                    <div class="action-top">
                        <span>活動申請</span>
                        <div class="action-icon"><i class="bi bi-lock"></i></div>
                    </div>
                    <h6>限社團幹部</h6>
                    <p>只有社團幹部可以申請活動。</p>
                </div>
                <div class="action-card" onclick="location.href='calendar.php'">
                    <div class="action-top">
                        <span>場地查詢</span>
                        <div class="action-icon"><i class="bi bi-building"></i></div>
                    </div>
                    <h6>查看場地租借情況</h6>
                    <p>查看各場地的租借狀態。</p>
                </div>
                <div class="action-card" style="opacity: 0.6; cursor: not-allowed;">
                    <div class="action-top">
                        <span>場協意願</span>
                        <div class="action-icon"><i class="bi bi-lock"></i></div>
                    </div>
                    <h6>限社團幹部</h6>
                    <p>只有社團幹部可以登記場協。</p>
                </div>
                <?php endif; ?>
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
