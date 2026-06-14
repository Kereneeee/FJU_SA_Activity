<?php

require_once(__DIR__ . "/../DB/db_config.php");

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'student') {
    header('Location: ../login.php');
    exit();
}

$student_name = $_SESSION['student_name'] ?? '學生';
$student_id = $_SESSION['student_id'];
$user_id = $_SESSION['user_id'] ?? null;

// 設置當前頁面用於側邊欄高亮
$current_page = 'dashboard';

// 應用根路徑（例如 /FJU_SA_Activity），用於生成不受包含文件位置影響的絕對連結
$appRoot = '/' . basename(dirname(__DIR__));

// 獲取用戶的社團及幹部身分（初始化變數）
$current_club = $_SESSION['active_club_name'] ?? null;
$officer_title = $_SESSION['active_position'] ?? null;

// 【修正點 1】判斷是否為幹部：不能只看有沒有社團，必須看 active_position 是否有值
$is_officer = !empty($_SESSION['active_position']);

// 如果 Session 是空的，且 user_id 存在，則去資料庫抓取預設身分
if (!$current_club && $user_id) {
    // 【修正點 2】表名與欄位修正：根據前面的結構，使用者與社團關係在 club_members，而非 user_club_roles
    $club_sql = "SELECT cm.*, c.club_name
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
            // 設定變數供頁面顯示
            $current_club = $club_row['club_name'];
            $officer_title = $club_row['is_officer'] ? ($club_row['officer_title'] ?: '幹部') : null;
            $is_officer = !empty($officer_title);

            // 同時存入 Session，供各頁面同步狀態
            $_SESSION['active_club_id'] = $club_row['club_id'];
            $_SESSION['active_club_name'] = $club_row['club_name'];
            $_SESSION['active_position'] = $officer_title;
        }
    }
}

// 獲取近期活動資料
$activities = [];

// 查詢 events、reservations 與 spaces，取得活動名稱、時間、狀態與場地名稱
$activities_sql = "SELECT e.event_name, e.start_time, e.status, s.space_name
                   FROM events e
                   LEFT JOIN reservations r ON e.event_id = r.event_id
                   LEFT JOIN spaces s ON r.space_id = s.space_id
                   WHERE e.club_name = ?
                   ORDER BY e.start_time DESC LIMIT 5";

$act_stmt = $conn->prepare($activities_sql);
if ($act_stmt) {
    // 【關鍵修正】先用一個乾淨的變數接收值，再放入 bind_param 中傳遞引用，徹底解決 Fatal error
    $search_club = $current_club ?: ''; 
    
    $act_stmt->bind_param("s", $search_club);
    $act_stmt->execute();
    $act_result = $act_stmt->get_result();
    while ($row = $act_result->fetch_assoc()) {
        $activities[] = $row;
    }
    $act_stmt->close(); // 養成好習慣關閉 statement
}

// 獲取最新通知資料
$notifications = [];
$noti_sql = "SELECT id, title, content, type, created_at FROM notifications ORDER BY created_at DESC LIMIT 5";
$noti_result = $conn->query($noti_sql);

if ($noti_result) {
    while ($row = $noti_result->fetch_assoc()) {
        $notifications[] = $row;
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
            --primary: #1e4d6b;
            --sidebar: #14394f;
            --sidebar-hover: #ece8dd;
            --bg: #f7f5ef;
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
            font-size: 1.1rem;
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
            background: #1e4d6b;
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
            box-shadow: 0 18px 40px rgba(30,77,107,0.2);
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
        .status-approved { background: #70a3a7; color: #1a3f42; }
        .status-pending { background: #f0e8c0; color: #6b5a20; }
        .status-alert { background: #f8d7da; color: #842029; }
        .notification-list .notification-card {
            display: grid;
            grid-template-columns: 1fr auto;
            gap: 1rem;
            align-items: flex-start;
            position: relative;
            overflow: hidden;
            border: 1px solid rgba(148,163,184,.28) !important;
            border-radius: 12px !important;
            box-shadow: 0 8px 20px rgba(15,23,42,.05) !important;
            transition: transform .15s ease, box-shadow .15s ease;
        }
        .notification-list .notification-card:hover {
            transform: translateY(-1px);
            box-shadow: 0 14px 28px rgba(15,23,42,.08) !important;
        }
        .notification-card::before {
            content: "";
            position: absolute;
            left: 0;
            top: 0;
            bottom: 0;
            width: 5px;
            background: #0d6efd;
        }
        .notification-card.type-update::before { background: #198754; }
        .notification-card.type-alert::before { background: #dc3545; }
        .notice-summary {
            display: flex;
            gap: .85rem;
            min-width: 0;
        }
        .notice-icon {
            width: 38px;
            height: 38px;
            border-radius: 10px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            flex-shrink: 0;
            background: #e8f0f5;
            color: #1e4d6b;
            font-size: 1.05rem;
        }
        .notification-card.type-update .notice-icon { background:#e7f6ed; color:#198754; }
        .notification-card.type-alert .notice-icon { background:#fdecec; color:#dc3545; }
        .notice-title-row {
            display: flex;
            align-items: center;
            gap: .5rem;
            flex-wrap: wrap;
            margin-bottom: .2rem;
        }
        .notification-card .badge {
            font-size: 0.8rem;
            border-radius: 999px;
        }
        .notification-card .badge-new { background: #0d6efd; }
        .notification-card .badge-update { background: #198754; }
        .notification-card .badge-alert { background: #dc3545; }
        .notification-card .meta {
            white-space: pre-line;
            line-height: 1.55;
            color: #64748b !important;
        }
        .notice-detail-link {
            color: white;
            background: #1e4d6b;
            text-decoration: none;
            font-size: 0.82rem;
            font-weight: 700;
            display: inline-flex;
            align-items: center;
            gap: 0.3rem;
            padding: .38rem .75rem;
            border-radius: 8px;
            white-space: nowrap;
        }
        .notice-detail-link:hover { color:white; background:#14394f; }
        .notice-date {
            color: #94a3b8;
            font-size: .78rem;
            display: inline-flex;
            align-items: center;
            gap: .25rem;
            margin-top: .45rem;
        }
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
                    <li class="breadcrumb-item active" aria-current="page">儀表板</li>
                </ol>
                <h4 class="mt-2 mb-0">學生管理中心</h4>
            </div>
            <div class="user-card" style="cursor: pointer;" onclick="location.href='profile.php'" title="點擊查看個人檔案">
                <div class="user-avatar"><?php echo htmlspecialchars(mb_substr($student_name, 0, 1)); ?></div>
                <div>
                    <div class="fw-bold"><?php echo htmlspecialchars($student_name); ?></div>
                    <small class="text-muted">學號：<?php echo htmlspecialchars($_SESSION['student_no'] ?? $student_id); ?></small>
                </div>
            </div>
        </header>

        <section class="dashboard-grid">
            <div style="background: #dce9ea; border-radius: 12px; margin-bottom: 1.5rem; padding: 1.5rem;">
                <div style="display: flex; align-items: center; gap: 1rem; flex-wrap: wrap;">
                    <div style="flex: 1;">
                        <h5 style="margin: 0 0 0.5rem; color: #1a4a4f;">
                            <i class="bi bi-sunrise"></i> 歡迎回來，<?php echo htmlspecialchars($student_name); ?>！
                        </h5>
                        <p style="margin: 0; color: #2c6b70; font-size: 0.9rem;">
                            今天是 <?php echo date('Y年m月d日'); ?>
                            <?php if ($current_club): ?>
                                | <i class="bi bi-people"></i> <strong><?php echo htmlspecialchars($current_club); ?></strong>
                                <?php if ($is_officer): ?>
                                    <span style="background: #70a3a7; color: white; padding: 0.25rem 0.75rem; border-radius: 999px; font-size: 0.8rem; font-weight: 600;">
                                        <?php echo htmlspecialchars($officer_title ?? '幹部'); ?>
                                    </span>
                                <?php endif; ?>
                            <?php endif; ?>
                        </p>
                    </div>
                    <?php if ($current_club): ?>
                    <a href="profile.php" style="padding: 0.5rem 1rem; background: #1e4d6b; color: white; text-decoration: none; border-radius: 8px; font-size: 0.85rem;">
                        切換身分
                    </a>
                    <?php endif; ?>
                </div>
            </div>


            <div class="quick-actions">
                <?php if ($is_officer): ?>
                <div class="action-card" onclick="location.href='<?= $appRoot ?>/student/apply_event.php'">
                    <div class="action-top">
                        <span>活動場地與器材申請</span>
                        <div class="action-icon"><i class="bi bi-plus-lg"></i></div>
                    </div>
                    <h6>立即新增活動場地與器材</h6>
                    <p>快速建立活動場地申請並查看審核進度。</p>
                </div>
                <div class="action-card" onclick="location.href='<?= $appRoot ?>/student/calendar.php'">
                    <div class="action-top">
                        <span>場地行事曆</span>
                        <div class="action-icon"><i class="bi bi-building"></i></div>
                    </div>
                    <h6>預約可用場地</h6>
                    <p>檢視場地空檔</p>
                </div>
                <div class="action-card" onclick="location.href='<?= $appRoot ?>/student/field_coord.php'">
                    <div class="action-top">
                        <span>場地協調</span>
                        <div class="action-icon"><i class="bi bi-people-fill"></i></div>
                    </div>
                    <h6>登記場地協調</h6>
                    <p>代表社團登記多個教室與例行練習時間。</p>
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
                        <?php if (empty($activities)): ?>
                            <div class="event-card p-3 border rounded-3 bg-white shadow-sm text-center text-muted">
                                <i class="bi bi-folder-x display-6 d-block mb-2 opacity-50"></i>
                                目前尚無近期活動申請。
                            </div>
                        <?php else: ?>
                            <?php foreach ($activities as $act): ?>
                                <?php 
                                    // 格式化時間，轉換為 (例如：12/20 14:00)
                                    $start_date = date('m/d', strtotime($act['start_time']));
                                    $start_time = date('H:i', strtotime($act['start_time']));
                                    
                                    // 安全檢查：如果資料庫未來有撈 end_time 再行處理，否則預設以 start_time 為主
                                    if (!empty($act['end_time'])) {
                                        $end_date = date('m/d', strtotime($act['end_time']));
                                        $end_time = date('H:i', strtotime($act['end_time']));
                                        
                                        $time_display = ($start_date === $end_date) 
                                            ? "{$start_date} {$start_time}-{$end_time}" 
                                            : "{$start_date} {$start_time} - {$end_date} {$end_time}";
                                    } else {
                                        $time_display = "{$start_date} {$start_time}";
                                    }
                                    
                                    // 統一狀態標籤判斷
                                    $status = $act['status'] ?? 'pending';
                                    $is_approved = in_array($status, ['approved', '已通過', '通過']);
                                    $is_rejected = in_array($status, ['rejected', '已拒絕', '拒絕', '已駁回']);
                                ?>
                                <div class="event-card p-3 mb-2 border rounded-3 bg-white shadow-sm">
                                    <div class="d-flex justify-content-between align-items-start">
                                        <div style="flex: 1; padding-right: 15px;">
                                            <div class="title fw-bold text-dark mb-1" style="font-size: 0.95rem;">
                                                <?php echo htmlspecialchars($act['event_name'] ?? '未命名活動'); ?>
                                            </div>
                                            <div class="meta text-secondary small">
                                                <i class="bi bi-calendar3 me-1"></i><?php echo htmlspecialchars($time_display); ?> 
                                                <span class="mx-1.5 text-muted">·</span> 
                                                <i class="bi bi-geo-alt-fill text-danger me-1"></i><?php echo htmlspecialchars($act['space_name'] ?? '外校營地/未指定場地'); ?>
                                            </div>
                                        </div>
                                        
                                        <div>
                                            <?php if ($is_approved): ?>
                                                <span class="status-pill status-approved">已核准</span>
                                            <?php elseif ($is_rejected): ?>
                                                <span class="status-pill status-alert">已駁回</span>
                                            <?php else: ?>
                                                <span class="status-pill status-pending">審核中</span>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                </section>
                <section class="panel-full">
                    <h5>最新通知</h5>
                    <div class="notification-list">
                        <?php if (!empty($notifications)): ?>
                            <?php foreach ($notifications as $noti): ?>
                                    <?php 
                                    $type = $noti['type'];
                                    if ($type === 'update' || $type === '更新') {
                                        $notice_class = 'type-update';
                                        $notice_icon = 'bi-arrow-repeat';
                                        $notice_badge = '<span class="badge bg-success text-white px-2 py-1 small">更新</span>';
                                    } elseif ($type === 'alert' || $type === '提醒' || $type === '緊急') {
                                        $notice_class = 'type-alert';
                                        $notice_icon = 'bi-exclamation-triangle-fill';
                                        $notice_badge = '<span class="badge bg-danger text-white px-2 py-1 small">提醒</span>';
                                    } else {
                                        $notice_class = 'type-new';
                                        $notice_icon = 'bi-megaphone-fill';
                                        $notice_badge = '<span class="badge bg-primary text-white px-2 py-1 small">新消息</span>';
                                    }
                                    ?>
                                <div class="notification-card <?= $notice_class ?> p-3 mb-2 bg-white">
                                    <div class="notice-summary">
                                        <div class="notice-icon"><i class="bi <?= $notice_icon ?>"></i></div>
                                        <div style="min-width:0;">
                                            <div class="notice-title-row">
                                                <div class="title fw-bold text-dark"><?php echo htmlspecialchars($noti['title']); ?></div>
                                                <?= $notice_badge ?>
                                            </div>
                                            <div class="meta small"><?php echo htmlspecialchars(mb_substr($noti['content'], 0, 110)); ?><?= mb_strlen($noti['content']) > 110 ? '...' : '' ?></div>
                                            <div class="notice-date"><i class="bi bi-clock"></i><?= date('Y/m/d H:i', strtotime($noti['created_at'])) ?></div>
                                        </div>
                                    </div>
                                    <a class="notice-detail-link" href="notification_detail.php?id=<?= (int)$noti['id'] ?>">
                                        詳細資料 <i class="bi bi-chevron-right"></i>
                                    </a>
                                </div>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <div class="text-center text-muted py-4">
                                <i class="bi bi-bell-slash display-6 d-block mb-2 opacity-50"></i>
                                目前沒有新通知。
                            </div>
                        <?php endif; ?>
                    </div>
                </section>
            </div>
        </section>
    </main>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
