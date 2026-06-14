<?php

require_once(__DIR__ . "/../DB/db_config.php");

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    header('Location: ../login.php');
    exit();
}

$user_name = $_SESSION['user_name'] ?? '管理員';
$user_id = $_SESSION['user_id'];

$message = $_SESSION['dashboard_flash']['message'] ?? '';
$message_type = $_SESSION['dashboard_flash']['type'] ?? 'success';
unset($_SESSION['dashboard_flash']);

$pending_count = 0;
$recent_events = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'save_notification') {
    $notification_id = intval($_POST['notification_id'] ?? 0);
    $title = trim($_POST['title'] ?? '');
    $content = trim($_POST['content'] ?? '');
    $type = $_POST['type'] ?? 'new';
    $allowed_types = ['new', 'update', 'alert'];
    if (!in_array($type, $allowed_types, true)) $type = 'new';

    if ($title === '' || $content === '') {
        $_SESSION['dashboard_flash'] = ['type' => 'danger', 'message' => '公告標題與內容都必須填寫。'];
    } elseif ($notification_id > 0) {
        $stmt = $conn->prepare("UPDATE notifications SET title = ?, content = ?, type = ? WHERE id = ?");
        if ($stmt) {
            $stmt->bind_param("sssi", $title, $content, $type, $notification_id);
            $_SESSION['dashboard_flash'] = $stmt->execute()
                ? ['type' => 'success', 'message' => '公告已更新。']
                : ['type' => 'danger', 'message' => '公告更新失敗。'];
            $stmt->close();
        } else {
            $_SESSION['dashboard_flash'] = ['type' => 'danger', 'message' => '公告更新失敗。'];
        }
    } else {
        $stmt = $conn->prepare("INSERT INTO notifications (title, content, type) VALUES (?, ?, ?)");
        if ($stmt) {
            $stmt->bind_param("sss", $title, $content, $type);
            $_SESSION['dashboard_flash'] = $stmt->execute()
                ? ['type' => 'success', 'message' => '公告已新增。']
                : ['type' => 'danger', 'message' => '公告新增失敗。'];
            $stmt->close();
        } else {
            $_SESSION['dashboard_flash'] = ['type' => 'danger', 'message' => '公告新增失敗。'];
        }
    }

    header('Location: dashboard.php');
    exit();
}

// 1. 待審核申請計數（確認 status 欄位直接在 events 表中）
$sql_pending = "SELECT COUNT(*) AS cnt FROM events WHERE status = 'pending'";
$result_pending = $conn->query($sql_pending);
if ($result_pending) {
    $row = $result_pending->fetch_assoc();
    $pending_count = intval($row['cnt']);
}

// 2. 近期活動列表（直接改由 events 表撈取 start_time, end_time, status）
// 只保留 LEFT JOIN spaces 來抓取場地名稱
$sql_recent = "SELECT e.event_id, e.event_name, e.club_name, e.start_time, e.end_time, e.status, s.space_name
               FROM events e
               LEFT JOIN reservations r ON e.event_id = r.event_id
               LEFT JOIN spaces s ON r.space_id = s.space_id
               ORDER BY e.event_id DESC
               LIMIT 5";
$result_recent = $conn->query($sql_recent);
if ($result_recent) {
    $recent_events = $result_recent->fetch_all(MYSQLI_ASSOC);
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
    <title>管理員儀表板 - 輔仁大學課外活動指導組</title>

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
            grid-template-columns: repeat(4, minmax(200px, 1fr));
            gap: 1.25rem;
            margin-bottom: 1.5rem;
        }
        .action-card {
            background: #1e4d6b;
            color: white;
            border-radius: 18px;
            padding: 1.5rem;
            cursor: pointer;
            transition: transform 0.25s ease, box-shadow 0.25s ease;
            min-height: 160px;
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
            margin: 0.8rem 0 0.3rem;
            font-size: 0.95rem;
            font-weight: 700;
        }
        .action-card p {
            margin: 0;
            color: rgba(255,255,255,0.85);
            line-height: 1.5;
            font-size: 0.85rem;
        }
        .action-card.pending-card {
            background: #6b2737;
        }
        .action-card.pending-card .pending-count {
            font-size: 2.4rem;
            font-weight: 800;
            line-height: 1;
            margin: 0.4rem 0;
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
        .welcome-banner {
            padding: 1.25rem 1.5rem;
            background: #e3f2fd;
            border-radius: 12px;
            margin-bottom: 1.5rem;
            overflow: hidden;
        }
        .welcome-inner {
            display: flex;
            align-items: center;
            gap: 1rem;
            flex-wrap: wrap;
            min-width: 0;
        }
        .welcome-inner > div {
            min-width: 0;
            overflow: hidden;
        }
        .welcome-banner h5 {
            margin: 0 0 0.3rem;
            color: #1565c0;
            font-size: 1.15rem;
            font-weight: 700;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }
        .welcome-banner p {
            margin: 0;
            color: #0d47a1;
            font-size: 0.9rem;
        }
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
        .notification-card .meta {
            white-space: pre-line;
        }
        .notification-actions {
            display: flex;
            align-items: center;
            gap: 0.5rem;
            flex-wrap: wrap;
            justify-content: flex-end;
        }
        .btn-notice {
            border: 0;
            border-radius: 8px;
            padding: 0.35rem 0.75rem;
            font-size: 0.82rem;
            font-weight: 600;
            display: inline-flex;
            align-items: center;
            gap: 0.35rem;
        }
        .btn-notice.primary { background: #1e4d6b; color: #fff; }
        .btn-notice.light { background: #e8f0f5; color: #1e4d6b; }
        .footer-note {
            margin-top: 0.75rem;
            color: #6b7280;
            font-size: 0.9rem;
        }
        @media (max-width: 1100px) {
            .summary-row, .quick-actions, .panel-row { grid-template-columns: 1fr; }
        }
        @media (max-width: 768px) {
            .main-content { margin-left: 0; }
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
    <?php
    $current_page = $current_page ?? 'dashboard';
    include __DIR__ . '/../includes/admin_sidebar.php';
    ?>

    <main class="main-content">
        <header class="top-navbar">
            <div>
                <ol class="breadcrumb mb-0">
                    <li class="breadcrumb-item"><a href="dashboard.php">首頁</a></li>
                    <li class="breadcrumb-item active" aria-current="page">管理員儀表板</li>
                </ol>
                <h4 class="mt-2 mb-0">系統管理中心</h4>
            </div>
            <div class="d-flex align-items-center gap-2">
                <div class="user-avatar" onclick="location.href='profile.php'">
                    <?php echo htmlspecialchars(mb_substr($user_name, 0, 1)); ?>
                </div>
                <small class="text-muted"><?php echo htmlspecialchars($user_name); ?></small>
            </div>
        </header>

        <section class="dashboard-grid">
            <?php if ($message): ?>
            <div class="alert alert-<?= htmlspecialchars($message_type) ?> rounded-3 mb-3">
                <?= htmlspecialchars($message) ?>
            </div>
            <?php endif; ?>

            <div class="welcome-banner">
                <div class="welcome-inner">
                    <div>
                        <h5>
                            <i class="bi bi-shield-check"></i> 歡迎回來，<?php echo htmlspecialchars($user_name); ?>！
                        </h5>
                        <p>
                            今天是 <?php echo date('Y年m月d日'); ?>
                        </p>
                    </div>
                </div>
            </div>
            <div class="quick-actions">
                <div class="action-card pending-card" onclick="location.href='review.php?filter=pending'">
                    <div class="action-top">
                        <span>待審核申請</span>
                        <div class="action-icon"><i class="bi bi-hourglass-split"></i></div>
                    </div>
                    <div class="pending-count"><?php echo htmlspecialchars($pending_count); ?></div>
                    <p>目前待處理的申請案件數量。</p>
                </div>
                <div class="action-card" onclick="location.href='review.php'">
                    <div class="action-top">
                        <span>審核管理</span>
                        <div class="action-icon"><i class="bi bi-clipboard-check"></i></div>
                    </div>
                    <h6>審核申請案件</h6>
                    <p>快速處理待審核的申請案件。</p>
                </div>
                <div class="action-card" onclick="location.href='equipment_mgmt.php'">
                    <div class="action-top">
                        <span>器材庫存管理</span>
                        <div class="action-icon"><i class="bi bi-tools"></i></div>
                    </div>
                    <h6>管理器材庫存</h6>
                    <p>新增、編輯或刪除器材項目。</p>
                </div>
                <div class="action-card" onclick="location.href='space_mgmt.php'">
                    <div class="action-top">
                        <span>空間管理</span>
                        <div class="action-icon"><i class="bi bi-building"></i></div>
                    </div>
                    <h6>管理場地資源</h6>
                    <p>查看與編輯空間使用狀態。</p>
                </div>
                <div class="action-card" onclick="location.href='field_coordination_mgmt.php'">
                    <div class="action-top">
                        <span>場協登記管理</span>
                        <div class="action-icon"><i class="bi bi-people"></i></div>
                    </div>
                    <h6>管理場協登記</h6>
                    <p>查看與審核場地協調登記申請。</p>
                </div>
                <div class="action-card" onclick="location.href='field_coordination_import.php'">
                    <div class="action-top">
                        <span>場協結果匯入</span>
                        <div class="action-icon"><i class="bi bi-cloud-upload"></i></div>
                    </div>
                    <h6>匯入場協結果</h6>
                    <p>將協調會議結果匯入系統。</p>
                </div>
                <div class="action-card" onclick="location.href='calendar.php'">
                    <div class="action-top">
                        <span>完整行事曆</span>
                        <div class="action-icon"><i class="bi bi-calendar-week"></i></div>
                    </div>
                    <h6>查看完整行事曆</h6>
                    <p>檢視所有場地預約與活動時程。</p>
                </div>
                <div class="action-card" onclick="location.href='user_mgmt.php'">
                    <div class="action-top">
                        <span>身分權限管理</span>
                        <div class="action-icon"><i class="bi bi-people-fill"></i></div>
                    </div>
                    <h6>管理社團幹部</h6>
                    <p>設定社團幹部名單與身分權限。</p>
                </div>
            </div>

            <div class="panel-row">
                <section class="panel-full">
                    <h5>近期活動列表</h5>
                    <div class="event-list">
                        <?php if (empty($recent_events)): ?>
                            <div class="event-card">
                                <div class="title">目前尚無近期活動申請。</div>
                            </div>
                        <?php else: ?>
                            <?php foreach ($recent_events as $event): ?>
                                <?php 
                                    // 格式化時間，轉換為與 UI 圖片一致的格式 (例如：12/20 14:00-17:00)
                                    $start_date = date('m/d', strtotime($event['start_time']));
                                    $end_date = date('m/d', strtotime($event['end_time']));
                                    $start_time = date('H:i', strtotime($event['start_time']));
                                    $end_time = date('H:i', strtotime($event['end_time']));
                                    
                                    // 判斷是否為同一天
                                    $time_display = ($start_date === $end_date) 
                                        ? "{$start_date} {$start_time}-{$end_time}" 
                                        : "{$start_date} {$start_time} - {$end_date} {$end_time}";
                                ?>
                                <div class="event-card">
                                    <div class="d-flex justify-content-between align-items-start">
                                        <div>
                                            <div class="title"><?= htmlspecialchars($event['event_name']) ?></div>
                                            <div class="meta">
                                                <?= htmlspecialchars($time_display) ?> · <?= htmlspecialchars($event['space_name'] ?? '外校營地/未指定場地') ?>
                                            </div>
                                        </div>
                                        
                                        <?php if ($event['status'] === 'approved'): ?>
                                            <span class="status-pill status-approved">已核准</span>
                                        <?php elseif ($event['status'] === 'pending'): ?>
                                            <span class="status-pill status-pending">審核中</span>
                                        <?php else: ?>
                                            <span class="status-pill status-alert">已駁回</span>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                </section>
                <section class="panel-full">
                    <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-2">
                        <h5 class="mb-0">最新通知</h5>
                        <button type="button" class="btn-notice primary"
                                data-bs-toggle="modal"
                                data-bs-target="#notificationModal"
                                data-notification-id=""
                                data-title=""
                                data-content=""
                                data-type="new">
                            <i class="bi bi-plus-circle"></i> 新增公告
                        </button>
                    </div>
                    <div class="notification-list">
                        <?php if (!empty($notifications)): ?>
                            <?php foreach ($notifications as $noti): ?>
                                <div class="notification-card p-3 mb-2 border rounded-3 bg-white shadow-sm d-flex justify-content-between align-items-start">
                                    <div style="flex: 1; padding-right: 10px;">
                                        <div class="title fw-bold text-dark mb-1"><?php echo htmlspecialchars($noti['title']); ?></div>
                                        <div class="meta text-secondary small"><?php echo htmlspecialchars(mb_substr($noti['content'], 0, 90)); ?><?= mb_strlen($noti['content']) > 90 ? '...' : '' ?></div>
                                        <div class="text-muted small mt-1">
                                            <?= date('Y/m/d H:i', strtotime($noti['created_at'])) ?>
                                        </div>
                                    </div>
                                    <div class="notification-actions">
                                    <?php 
                                    // 根據 type 顯示不同的 Badge 顏色與文字
                                    $type = $noti['type'];
                                    $form_type = in_array($type, ['new', 'update', 'alert'], true) ? $type : 'new';
                                    if ($type === 'update' || $type === '更新') {
                                        echo '<span class="badge bg-success text-white px-2 py-1 small">更新</span>';
                                    } elseif ($type === 'alert' || $type === '提醒' || $type === '緊急') {
                                        echo '<span class="badge bg-danger text-white px-2 py-1 small">提醒</span>';
                                    } else {
                                        echo '<span class="badge bg-primary text-white px-2 py-1 small">新消息</span>';
                                    }
                                    ?>
                                        <button type="button" class="btn-notice light"
                                                data-bs-toggle="modal"
                                                data-bs-target="#notificationModal"
                                                data-notification-id="<?= (int)$noti['id'] ?>"
                                                data-title="<?= htmlspecialchars($noti['title'], ENT_QUOTES, 'UTF-8') ?>"
                                                data-content="<?= htmlspecialchars($noti['content'], ENT_QUOTES, 'UTF-8') ?>"
                                                data-type="<?= htmlspecialchars($form_type, ENT_QUOTES, 'UTF-8') ?>">
                                            <i class="bi bi-pencil-square"></i> 編輯
                                        </button>
                                    </div>
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

    <div class="modal fade" id="notificationModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-lg modal-dialog-centered">
            <form method="POST" class="modal-content">
                <input type="hidden" name="action" value="save_notification">
                <input type="hidden" name="notification_id" id="notice_id">
                <div class="modal-header">
                    <h5 class="modal-title" id="notice_modal_title">新增公告</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label fw-semibold" for="notice_title">公告標題</label>
                        <input type="text" class="form-control" id="notice_title" name="title" required maxlength="255">
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-semibold" for="notice_type">公告類型</label>
                        <select class="form-select" id="notice_type" name="type">
                            <option value="new">新消息</option>
                            <option value="update">更新</option>
                            <option value="alert">提醒</option>
                        </select>
                    </div>
                    <div>
                        <label class="form-label fw-semibold" for="notice_content">公告內容</label>
                        <textarea class="form-control" id="notice_content" name="content" rows="9" required></textarea>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">取消</button>
                    <button type="submit" class="btn btn-primary" style="background:#1e4d6b;border-color:#1e4d6b;">儲存公告</button>
                </div>
            </form>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        const notificationModal = document.getElementById('notificationModal');
        notificationModal.addEventListener('show.bs.modal', event => {
            const button = event.relatedTarget;
            const id = button.getAttribute('data-notification-id') || '';
            document.getElementById('notice_id').value = id;
            document.getElementById('notice_title').value = button.getAttribute('data-title') || '';
            document.getElementById('notice_content').value = button.getAttribute('data-content') || '';
            document.getElementById('notice_type').value = button.getAttribute('data-type') || 'new';
            document.getElementById('notice_modal_title').textContent = id ? '編輯公告' : '新增公告';
        });
    </script>
</body>
</html>
