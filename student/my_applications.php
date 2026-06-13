<?php

require_once(__DIR__ . "/../DB/db_config.php");

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'student') {
    header('Location: ../login.php');
    exit();
}

// 設置當前頁面用於側邊欄高亮
$current_page = 'my_applications';

// 獲取當前學生的申請
$student_email = $_SESSION['student_id'];
$applications = [];

// 先獲取學生ID
$user_sql = "SELECT user_id FROM users WHERE email = ?";
$user_stmt = $conn->prepare($user_sql);
$user_stmt->bind_param("s", $student_email);
$user_stmt->execute();
$user_result = $user_stmt->get_result();
$student_user_id = null;

if ($user_result && $user_result->num_rows > 0) {
    $user_row = $user_result->fetch_assoc();
    $student_user_id = $user_row['user_id'];
}
$user_stmt->close();

// 撈取使用者的活動申請（events 表不再含追加申請）
$sql = "SELECT e.event_id, e.event_name, e.club_name, e.description, e.start_time, e.end_time,
               e.status, e.review_note, e.user_id,
               e.responsible_person, e.event_type, e.activity_location,
               e.activity_scale, e.proposal_doc_path, e.proposal_doc_original_name,
               COALESCE(e.created_at, NOW()) as created_at,
               COALESCE(rc.session_count, 0) AS session_count
        FROM events e
        LEFT JOIN (
            SELECT event_id, COUNT(*) AS session_count
            FROM reservations GROUP BY event_id
        ) rc ON rc.event_id = e.event_id
        WHERE e.user_id = ?
        ORDER BY CASE WHEN e.status = 'pending' THEN 0 ELSE 1 END, e.created_at DESC";

$stmt = $conn->prepare($sql);
if (!$stmt) {
    // 降級查詢（舊欄位不存在時）
    $sql_fallback = "SELECT e.event_id, e.event_name, e.club_name, e.description,
                            e.start_time, e.end_time, e.status, e.review_note, e.user_id,
                            COALESCE(e.created_at, NOW()) as created_at,
                            COALESCE(rc.session_count, 0) AS session_count
                     FROM events e
                     LEFT JOIN (SELECT event_id, COUNT(*) AS session_count FROM reservations GROUP BY event_id) rc ON rc.event_id = e.event_id
                     WHERE e.user_id = ?
                     ORDER BY CASE WHEN e.status = 'pending' THEN 0 ELSE 1 END, e.created_at DESC";
    $stmt = $conn->prepare($sql_fallback);
    if (!$stmt) die("準備SQL語句失敗: " . $conn->error);
}

$stmt->bind_param("i", $student_user_id);
$stmt->execute();
$result = $stmt->get_result();

if ($result) {
    $applications = $result->fetch_all(MYSQLI_ASSOC);
}
$stmt->close();

// 為每個申請獲取器材詳情與場次資料
foreach ($applications as &$app) {
    // 器材清單（只取主申請的器材，排除追加申請的 equipment_borrow）
    $eq_stmt = $conn->prepare(
        "SELECT eb.borrow_id, eb.equipment_id, eq.name, eb.quantity, eb.reservation_id, eb.borrow_start, eb.borrow_end
         FROM equipment_borrow eb
         LEFT JOIN equipment eq ON eb.equipment_id = eq.equipment_id
         WHERE eb.event_id = ? AND eb.request_id IS NULL"
    );
    if ($eq_stmt) {
        $eq_stmt->bind_param("i", $app['event_id']);
        $eq_stmt->execute();
        $eq_result = $eq_stmt->get_result();
        $app['equipment_list'] = $eq_result ? $eq_result->fetch_all(MYSQLI_ASSOC) : [];
        $eq_stmt->close();
    } else {
        $app['equipment_list'] = [];
    }

    // 追加器材申請（equipment_requests WHERE parent_event_id = 本活動）
    $child_stmt = $conn->prepare(
        "SELECT er.request_id, er.status, er.created_at, er.review_note, er.borrow_start, er.borrow_end
         FROM equipment_requests er
         WHERE er.parent_event_id = ?
         ORDER BY er.created_at DESC"
    );
    $app['child_requests'] = [];
    if ($child_stmt) {
        $child_stmt->bind_param("i", $app['event_id']);
        $child_stmt->execute();
        $children = $child_stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $child_stmt->close();
        foreach ($children as &$child) {
            $ceq = $conn->prepare(
                "SELECT eb.equipment_id, eq.name, eq.code, eb.quantity
                 FROM equipment_borrow eb
                 LEFT JOIN equipment eq ON eb.equipment_id = eq.equipment_id
                 WHERE eb.request_id = ?"
            );
            $child['equipment'] = [];
            if ($ceq) {
                $ceq->bind_param("i", $child['request_id']);
                $ceq->execute();
                $child['equipment'] = $ceq->get_result()->fetch_all(MYSQLI_ASSOC);
                $ceq->close();
            }
        }
        unset($child);
        $app['child_requests'] = $children;
    }

    // 場次清單（含場地）
    $sess_stmt = $conn->prepare(
        "SELECT r.reservation_id, r.start_time, r.end_time, s.space_name
         FROM reservations r
         LEFT JOIN spaces s ON r.space_id = s.space_id
         WHERE r.event_id = ?
         ORDER BY r.start_time"
    );
    if ($sess_stmt) {
        $sess_stmt->bind_param("i", $app['event_id']);
        $sess_stmt->execute();
        $sess_result = $sess_stmt->get_result();
        $app['sessions'] = $sess_result ? $sess_result->fetch_all(MYSQLI_ASSOC) : [];
        $sess_stmt->close();
    } else {
        $app['sessions'] = [];
    }
}
unset($app); // 斷開傳址綁定

// 狀態中文對應
$status_map = [
    'pending' => '審核中',
    'approved' => '已通過',
    'rejected' => '已拒絕',
    'completed' => '已完成',
    'cancelled' => '已取消'
];

// 狀態樣式對應
$status_class_map = [
    'pending' => 'status-pending',
    'approved' => 'status-approved',
    'rejected' => 'status-rejected',
    'completed' => 'status-completed',
    'cancelled' => 'status-rejected'
];

// 獲取器材狀態的最高優先級（用於分類）
function getEquipmentStatusPriority($equipment_list) {
    if (empty($equipment_list)) {
        return null; // 沒有器材申請
    }
    
    // 優先級順序：pending > rejected > approved > completed
    $priority = ['pending' => 0, 'rejected' => 1, 'approved' => 2, 'completed' => 3];
    
    $highest = null;
    $highest_priority = 999;
    
    foreach ($equipment_list as $eq) {
        $eq_status = $eq['status'] ?? 'pending';
        $eq_priority = $priority[$eq_status] ?? 999;
        if ($eq_priority < $highest_priority) {
            $highest_priority = $eq_priority;
            $highest = $eq_status;
        }
    }
    
    return $highest;
}

// 獲取器材狀態顯示文本
function getEquipmentStatusText($equipment_list) {
    if (empty($equipment_list)) {
        return null;
    }
    
    $status = getEquipmentStatusPriority($equipment_list);
    global $status_map;
    return $status_map[$status] ?? '未知';
}
?>

<!DOCTYPE html>
<html lang="zh-TW">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>我的申請 - 輔仁大學課外活動指導組</title>

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
        }
        .filter-tabs {
            display: flex;
            gap: 0.5rem;
            margin-bottom: 1.5rem;
            border-bottom: 1px solid #e5e7eb;
        }
        .filter-tab {
            padding: 0.75rem 1.5rem;
            border: none;
            background: transparent;
            color: #6b7280;
            cursor: pointer;
            border-radius: 8px 8px 0 0;
            transition: all 0.25s ease;
        }
        .filter-tab.active {
            background: var(--primary);
            color: white;
        }
        .sort-btn {
            padding: .35rem .85rem; border: 1.5px solid #d1d5db; background: white;
            color: #374151; border-radius: 8px; cursor: pointer; font-size: .83rem;
            transition: all .2s; display: inline-flex; align-items: center; gap: .3rem;
        }
        .sort-btn:hover { border-color: var(--primary); color: var(--primary); }
        .sort-btn.sort-active { border-color: var(--primary); background: var(--primary); color: white; }
        .application-card {
            background: white;
            border: 1px solid #e5e7eb;
            border-radius: 12px;
            padding: 1.5rem;
            margin-bottom: 1rem;
            transition: box-shadow 0.25s ease;
        }
        .application-card:hover {
            box-shadow: 0 4px 15px rgba(0,0,0,0.1);
        }
        .application-header {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            margin-bottom: 1rem;
            gap: 1rem;
        }
        .application-title {
            font-weight: 600;
            font-size: 1.1rem;
            margin-bottom: 0.25rem;
        }
        .application-meta {
            color: #6b7280;
            font-size: 0.9rem;
        }
        .status-boxes {
            display: flex;
            gap: 0.5rem;
            align-items: stretch;
        }
        .status-box {
            display: flex;
            flex-direction: row;
            align-items: center;
            justify-content: center;
            flex-wrap: wrap;
            gap: 0.5rem;
            min-width: 120px;
            padding: 0.5rem 0.75rem;
            background: #f9fafb;
            border-radius: 8px;
            border: 1px solid #e5e7eb;
        }
        .status-box-label {
            font-size: 0.75rem;
            color: #6b7280;
            font-weight: 500;
        }
        .btn-add-equipment {
            padding: 0.35rem 0.75rem;
            background: var(--primary);
            color: white;
            border: none;
            border-radius: 6px;
            cursor: pointer;
            font-size: 0.75rem;
            font-weight: 600;
            display: flex;
            align-items: center;
            gap: 0.35rem;
            transition: all 0.25s ease;
            white-space: nowrap;
        }
        .btn-add-equipment:hover {
            background: #6a0e2a;
            transform: translateY(-1px);
        }
        .status-badge {
            padding: 0.25rem 0.75rem;
            border-radius: 999px;
            font-size: 0.8rem;
            font-weight: 600;
        }
        .equipment-details {
            background: #f9fafb;
            border: 1px solid #e5e7eb;
            border-radius: 8px;
            padding: 1rem;
            margin-bottom: 1rem;
            margin-top: 0.5rem;
        }
        .equipment-title {
            font-weight: 600;
            color: #374151;
            font-size: 0.9rem;
            margin-bottom: 0.5rem;
        }
        .equipment-list {
            display: flex;
            flex-direction: column;
            gap: 0.5rem;
        }
        .equipment-item {
            display: flex;
            align-items: center;
            gap: 0.75rem;
            padding: 0.5rem;
            background: white;
            border-radius: 6px;
            border: 1px solid #e5e7eb;
        }
        .equipment-name {
            flex: 1;
            color: #374151;
            font-size: 0.9rem;
        }
        .equipment-quantity {
            color: #6b7280;
            font-size: 0.85rem;
            min-width: 50px;
            text-align: right;
        }
        .equipment-status-badge {
            font-size: 0.75rem;
            padding: 0.2rem 0.5rem;
        }
        .status-pending { background: #f0e8c0; color: #6b5a20; }
        .status-approved { background: #70a3a7; color: #1a3f42; }
        .status-rejected { background: #c9979a; color: #5c1f22; }
        .status-completed { background: #ede4e5; color: #5a3f42; }
        .application-details {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 1rem;
            margin-bottom: 1rem;
        }
        .detail-item {
            display: flex;
            flex-direction: column;
        }
        .detail-label {
            font-weight: 600;
            color: #374151;
            font-size: 0.9rem;
            margin-bottom: 0.25rem;
        }
        .detail-value {
            color: #6b7280;
        }
        .doc-links {
            display: flex;
            flex-wrap: wrap;
            gap: 0.5rem;
            margin-bottom: 1rem;
        }
        .doc-link {
            display: inline-flex;
            align-items: center;
            gap: 0.35rem;
            padding: 0.35rem 0.75rem;
            background: #fff5f7;
            border: 1px solid #f3a0b0;
            border-radius: 6px;
            color: var(--primary);
            font-size: 0.85rem;
            font-weight: 500;
            text-decoration: none;
            transition: all 0.2s ease;
        }
        .doc-link:hover {
            background: var(--primary);
            color: white;
            border-color: var(--primary);
        }
        .application-actions {
            display: flex;
            gap: 0.5rem;
            justify-content: flex-end;
        }
        .btn-action {
            padding: 0.5rem 1rem;
            border: 1px solid #d1d5db;
            background: white;
            border-radius: 6px;
            cursor: pointer;
            font-size: 0.9rem;
            transition: all 0.25s ease;
        }
        .btn-action:hover {
            background: #f9fafb;
        }
        .btn-edit {
            color: var(--primary);
            border-color: var(--primary);
        }
        .btn-edit:hover {
            background: var(--primary);
            color: white;
        }
        .btn-cancel {
            color: #dc3545;
            border-color: #dc3545;
        }
        .btn-cancel:hover {
            background: #dc3545;
            color: white;
        }
        .empty-state {
            text-align: center;
            padding: 3rem 1rem;
            color: #6b7280;
        }
        .empty-state i {
            font-size: 3rem;
            margin-bottom: 1rem;
            opacity: 0.5;
        }
        @media (max-width: 1100px) {
            .application-details { grid-template-columns: 1fr; }
            .main-content { margin-left: 0; }
        }
        @media (max-width: 768px) {
        .top-navbar {
                flex-direction: column;
                align-items: flex-start;
                gap: 1rem;
                padding: 1rem;
            }
            .sidebar {
                position: relative;
                width: 100%;
                height: auto;
                box-shadow: none;
            }
            .application-header {
                flex-direction: column;
                gap: 0.5rem;
                align-items: flex-start;
            }
            .application-actions {
                justify-content: flex-start;
            }
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
        <?php
        $nav_breadcrumbs = [['label'=>'首頁','url'=>'dashboard.php'],['label'=>'我的申請']];
        $nav_title = '我的申請';
        include __DIR__ . '/../includes/student_navbar.php';
        ?>

        <section class="content-wrapper">
            <div class="card">
                <h3>申請記錄</h3>

                <div class="filter-tabs">
                    <button class="filter-tab active" onclick="filterApplications('all')">全部</button>
                    <button class="filter-tab" onclick="filterApplications('pending')">審核中</button>
                    <button class="filter-tab" onclick="filterApplications('approved')">已通過</button>
                    <button class="filter-tab" onclick="filterApplications('completed')">已完成</button>
                    <button class="filter-tab" onclick="filterApplications('cancelled')">已取消</button>
                </div>

                <!-- 排序按鈕 -->
                <div id="sortBar" style="display:flex;gap:.5rem;align-items:center;margin-bottom:1rem;flex-wrap:wrap;">
                    <span style="font-size:.83rem;color:#6b7280;">排序：</span>
                    <button id="sortApplyBtn" class="sort-btn sort-active" onclick="sortCards('apply')">
                        <i class="bi bi-clock"></i> 申請時間 <span id="sortApplyDir">↓</span>
                    </button>
                    <button id="sortEventBtn" class="sort-btn" onclick="sortCards('event')">
                        <i class="bi bi-calendar-event"></i> 活動時間 <span id="sortEventDir">↓</span>
                    </button>
                </div>

                <div id="applicationsList">
                    <?php if (empty($applications)): ?>
                        <div class="empty-state">
                            <i class="bi bi-inbox"></i>
                            <h4>目前沒有申請記錄</h4>
                            <p>您還沒有提交任何申請，點擊下方按鈕開始申請吧！</p>
                            <a href="apply_event.php" class="btn-action btn-edit" style="display: inline-block; margin-top: 1rem;">前往申請</a>
                        </div>
                    <?php else: ?>
                        <?php foreach ($applications as $app):
                            // 活動結束日期已過且已通過 → 歸類為已完成
                            $end_dt = !empty($app['end_time']) ? new DateTime($app['end_time']) : null;
                            $is_past = $end_dt && $end_dt < new DateTime();
                            $effective_status = ($app['status'] === 'approved' && $is_past) ? 'completed' : $app['status'];

                            $event_status = $status_map[$effective_status] ?? '未知';
                            $event_status_class = $status_class_map[$effective_status] ?? 'status-pending';
                            $has_equipment = !empty($app['equipment_list']);
                            $equipment_status = $has_equipment ? $event_status : null;
                            $equipment_status_class = $has_equipment ? $event_status_class : '';

                            $filter_status = $effective_status;
                            // 若有任何追加器材申請仍在待審核，整筆歸類為審核中
                            foreach ($app['child_requests'] ?? [] as $_cr) {
                                if ($_cr['status'] === 'pending') { $filter_status = 'pending'; break; }
                            }
                            // 申請時間：取自身或最新子追加申請的時間（較晚者）
                            $apply_ts = strtotime($app['created_at'] ?? 'now');
                            foreach ($app['child_requests'] ?? [] as $_cr) {
                                $ct = strtotime($_cr['created_at']);
                                if ($ct > $apply_ts) $apply_ts = $ct;
                            }
                            $event_start_ts = strtotime($app['start_time'] ?? 'now');
                        ?>
                            <div class="application-card"
                                 data-status="<?= htmlspecialchars($filter_status) ?>"
                                 data-apply-time="<?= $apply_ts ?>"
                                 data-event-start="<?= $event_start_ts ?>">
                                <div class="application-header">
                                    <div style="flex:1; min-width:0;">
                                        <div class="application-title">
                                            <?php echo htmlspecialchars(!empty($app['event_name']) ? $app['event_name'] : '（未命名申請）'); ?>
                                            <?php
                                            $et = $app['event_type'] ?? '';
                                            if ($et === '校外') {
                                                echo '<span style="font-size:0.76rem;font-weight:600;background:#d1fae5;color:#065f46;border-radius:5px;padding:0.1rem 0.5rem;margin-left:0.5rem;">校外</span>';
                                            } elseif ($et === '校內') {
                                                echo '<span style="font-size:0.76rem;font-weight:600;background:#e7f1ff;color:#0c4a9c;border-radius:5px;padding:0.1rem 0.5rem;margin-left:0.5rem;">校內</span>';
                                            }
                                            ?>
                                        </div>
                                        <div class="application-meta">
                                            申請日期：<?php echo date('Y-m-d', strtotime($app['created_at'] ?? 'now')); ?>
                                            &nbsp;|&nbsp;編號：EVENT<?php echo str_pad($app['event_id'], 6, '0', STR_PAD_LEFT); ?>
                                            <?php if (!empty($app['responsible_person'])): ?>
                                            &nbsp;|&nbsp;負責人：<?php echo htmlspecialchars($app['responsible_person']); ?>
                                            <?php endif; ?>
                                        </div>
                                        <?php
                                        $scale_str = $app['activity_scale'] ?? '';
                                        if ($scale_str && $scale_str !== '一般活動'):
                                            $scale_parts = array_filter(array_map('trim', explode(',', $scale_str)));
                                        ?>
                                        <div style="margin-top:0.35rem; display:flex; gap:0.35rem; flex-wrap:wrap;">
                                            <?php foreach ($scale_parts as $sp):
                                                $sc = $sp === '大型活動' ? '#fef3c7;color:#78350f' : ($sp === '含酒精活動' ? '#fff7ed;color:#c2410c' : '#fee2e2;color:#991b1b');
                                                echo '<span style="font-size:0.75rem;font-weight:600;background:' . $sc . ';border-radius:5px;padding:0.1rem 0.5rem;">' . htmlspecialchars($sp) . '</span>';
                                            endforeach; ?>
                                        </div>
                                        <?php endif; ?>
                                    </div>
                                    <div class="status-boxes">
                                        <div class="status-box event-status">
                                            <div class="status-box-label">場地狀態</div>
                                            <span class="status-badge <?php echo $event_status_class; ?>">
                                                <?php echo $event_status; ?>
                                            </span>
                                        </div>
                                        <div class="status-box equipment-status">
                                            <?php
                                            $child_requests = $app['child_requests'] ?? [];
                                            // 取最緊急的子申請狀態：pending > rejected > approved
                                            $child_priority = ['pending' => 0, 'rejected' => 1, 'approved' => 2];
                                            $urgent_child = null;
                                            foreach ($child_requests as $_c) {
                                                if ($urgent_child === null ||
                                                    ($child_priority[$_c['status']] ?? 9) < ($child_priority[$urgent_child['status']] ?? 9)) {
                                                    $urgent_child = $_c;
                                                }
                                            }
                                            if ($urgent_child):
                                                $c_label = $status_map[$urgent_child['status']] ?? '未知';
                                                $c_class = $status_class_map[$urgent_child['status']] ?? 'status-pending';
                                            ?>
                                                <div class="status-box-label">器材狀態</div>
                                                <span class="status-badge <?= $c_class ?>"><?= $c_label ?></span>
                                            <?php elseif ($has_equipment): ?>  <?php // 父活動直接掛的器材，狀態同父活動 ?>
                                                <div class="status-box-label">器材狀態</div>
                                                <span class="status-badge <?= $equipment_status_class ?>"><?= $equipment_status ?></span>
                                            <?php endif; ?>
                                            <?php if (!in_array($effective_status, ['rejected', 'cancelled', 'completed'])): ?>
                                                <button class="btn-add-equipment" onclick="redirectToAddEquipment(<?php echo $app['event_id']; ?>)">
                                                    <i class="bi bi-plus-circle"></i> 追加申請器材
                                                </button>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                </div>

                                <!-- 基本資訊列 -->
                                <div class="application-details" style="margin-bottom:0.75rem;">
                                    <div class="detail-item">
                                        <div class="detail-label">社團</div>
                                        <div class="detail-value"><?php echo htmlspecialchars($app['club_name']); ?></div>
                                    </div>
                                    <?php if ($et === '校外' && !empty($app['activity_location'])): ?>
                                    <div class="detail-item">
                                        <div class="detail-label">活動地點</div>
                                        <div class="detail-value"><?php echo htmlspecialchars($app['activity_location']); ?></div>
                                    </div>
                                    <?php endif; ?>
                                    <div class="detail-item">
                                        <div class="detail-label">場次數</div>
                                        <div class="detail-value"><?php echo intval($app['session_count']); ?> 場</div>
                                    </div>
                                </div>

                                <?php
                                // 依 reservation_id 分組器材（主申請）
                                $eq_by_sess = [];
                                $eq_no_sess = [];
                                foreach ($app['equipment_list'] as $_eq) {
                                    $_rid = $_eq['reservation_id'] ?? null;
                                    if ($_rid) {
                                        $eq_by_sess[$_rid][] = $_eq;
                                    } else {
                                        $eq_no_sess[] = $_eq;
                                    }
                                }
                                ?>

                                <!-- 場次明細（含對應器材） -->
                                <?php if (!empty($app['sessions'])): ?>
                                <div style="background:#f8fafc; border:1px solid #e2e8f0; border-radius:10px; overflow:hidden; margin-bottom:0.85rem;">
                                    <div style="background:#f0f4f8; padding:0.45rem 0.85rem; font-size:0.8rem; font-weight:700; color:#1e4d6b; display:flex; align-items:center; gap:0.4rem;">
                                        <i class="bi bi-calendar-week"></i> 活動場次
                                    </div>
                                    <table style="width:100%; border-collapse:collapse; font-size:0.85rem;">
                                        <thead>
                                            <tr style="background:#f8f9fb;">
                                                <th style="padding:0.4rem 0.85rem; color:#6b7280; font-weight:600; border-bottom:1px solid #e5e7eb; width:2rem;">#</th>
                                                <th style="padding:0.4rem 0.85rem; color:#6b7280; font-weight:600; border-bottom:1px solid #e5e7eb;">開始</th>
                                                <th style="padding:0.4rem 0.85rem; color:#6b7280; font-weight:600; border-bottom:1px solid #e5e7eb;">結束</th>
                                                <th style="padding:0.4rem 0.85rem; color:#6b7280; font-weight:600; border-bottom:1px solid #e5e7eb;">場地</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php foreach ($app['sessions'] as $si => $sess):
                                                $_sess_rid = $sess['reservation_id'] ?? null;
                                                $_sess_eq  = $_sess_rid && isset($eq_by_sess[$_sess_rid]) ? $eq_by_sess[$_sess_rid] : [];
                                                $_has_sess_eq = !empty($_sess_eq);
                                            ?>
                                            <tr style="<?= $_has_sess_eq ? '' : 'border-bottom:1px solid #f0f2f5;' ?>">
                                                <td style="padding:0.4rem 0.85rem; color:#9ca3af;"><?php echo $si+1; ?></td>
                                                <td style="padding:0.4rem 0.85rem;"><?php echo htmlspecialchars(date('Y/m/d H:i', strtotime($sess['start_time']))); ?></td>
                                                <td style="padding:0.4rem 0.85rem;"><?php echo htmlspecialchars(date('Y/m/d H:i', strtotime($sess['end_time']))); ?></td>
                                                <td style="padding:0.4rem 0.85rem; color:#6b7280;"><?php echo htmlspecialchars($sess['space_name'] ?? '（校外）'); ?></td>
                                            </tr>
                                            <?php if ($_has_sess_eq):
                                                $_bs = $_sess_eq[0]['borrow_start'] ?? null;
                                                $_be = $_sess_eq[0]['borrow_end']   ?? null;
                                                $_eq_labels = [];
                                                foreach ($_sess_eq as $_e) {
                                                    $_eq_labels[] = htmlspecialchars($_e['name'] ?? '未知') . ' ×' . intval($_e['quantity']);
                                                }
                                            ?>
                                            <tr style="background:#f5f8fc; border-bottom:1px solid #f0f2f5;">
                                                <td style="padding:0.25rem 0.85rem;"></td>
                                                <td colspan="3" style="padding:0.3rem 0.85rem 0.5rem; font-size:0.82rem; color:#374151;">
                                                    <i class="bi bi-tools" style="color:#1e4d6b; margin-right:0.3rem;"></i>
                                                    <?= implode('、', $_eq_labels) ?>
                                                    <?php if ($_bs && $_be): ?>
                                                    <span style="color:#9ca3af; margin-left:0.5rem;">
                                                        （借用 <?= date('m/d H:i', strtotime($_bs)) ?>－<?= date('m/d H:i', strtotime($_be)) ?>）
                                                    </span>
                                                    <?php endif; ?>
                                                </td>
                                            </tr>
                                            <?php endif; ?>
                                            <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                </div>
                                <?php elseif (!empty($app['start_time'])): ?>
                                <!-- 無場次紀錄時顯示 events 表的整體時間 -->
                                <div class="application-details" style="margin-bottom:0.85rem;">
                                    <div class="detail-item">
                                        <div class="detail-label">活動開始</div>
                                        <div class="detail-value"><?php echo htmlspecialchars(date('Y-m-d H:i', strtotime($app['start_time']))); ?></div>
                                    </div>
                                    <div class="detail-item">
                                        <div class="detail-label">活動結束</div>
                                        <div class="detail-value"><?php echo htmlspecialchars(date('Y-m-d H:i', strtotime($app['end_time']))); ?></div>
                                    </div>
                                </div>
                                <?php endif; ?>

                                <?php
                                $docs = [
                                    'proposal_doc_path'  => ['label' => '活動企劃書',   'icon' => 'bi-file-earmark-text-fill', 'name_col' => 'proposal_doc_original_name'],
                                ];
                                $has_docs = false;
                                foreach ($docs as $col => $_) { if (!empty($app[$col])) { $has_docs = true; break; } }
                                ?>
                                <?php if ($has_docs): ?>
                                <div class="doc-links">
                                    <?php foreach ($docs as $col => $info): ?>
                                        <?php if (!empty($app[$col])): ?>
                                        <?php $doc_name = $app[$info['name_col']] ?? '' ?: basename($app[$col]); ?>
                                        <a href="../document/<?php echo htmlspecialchars($app[$col]); ?>" download="<?php echo htmlspecialchars($doc_name); ?>" target="_blank" class="doc-link">
                                            <i class="bi <?php echo $info['icon']; ?>"></i> <?php echo $info['label']; ?>：<?php echo htmlspecialchars($doc_name); ?>
                                        </a>
                                        <?php endif; ?>
                                    <?php endforeach; ?>
                                </div>
                                <?php endif; ?>

                                <?php
                                $has_orphan_eq = !empty($eq_no_sess);
                                $has_child_req = !empty($app['child_requests']);
                                if ($has_orphan_eq || $has_child_req):
                                ?>
                                <div class="equipment-details">
                                    <?php if ($has_orphan_eq): ?>
                                        <div class="equipment-title">申請器材：</div>
                                        <div class="equipment-list">
                                            <?php foreach ($eq_no_sess as $_eq): ?>
                                            <div class="equipment-item">
                                                <span class="equipment-name"><?= htmlspecialchars($_eq['name'] ?? '未知器材') ?></span>
                                                <span class="equipment-quantity">× <?= intval($_eq['quantity']) ?></span>
                                            </div>
                                            <?php endforeach; ?>
                                        </div>
                                    <?php endif; ?>
                                    <?php foreach ($app['child_requests'] as $child):
                                        if (empty($child['equipment'])) continue;
                                        $cl = $status_map[$child['status']] ?? '未知';
                                        $cc = $status_class_map[$child['status']] ?? 'status-pending';
                                        $_cbs = $child['borrow_start'] ?? null;
                                        $_cbe = $child['borrow_end']   ?? null;
                                    ?>
                                        <div class="equipment-title" style="margin-top:0.6rem;">
                                            追加器材申請
                                            <span class="status-badge <?= $cc ?>" style="font-size:.75rem;padding:.1rem .5rem;vertical-align:middle;"><?= $cl ?></span>
                                            <?php if ($_cbs && $_cbe): ?>
                                            <small style="color:#374151;font-size:.8rem;margin-left:.4rem;">
                                                借用：<?= date('Y/m/d H:i', strtotime($_cbs)) ?>－<?= date('m/d H:i', strtotime($_cbe)) ?>
                                            </small>
                                            <?php else: ?>
                                            <small style="color:#9ca3af;font-size:.78rem;margin-left:.3rem;"><?= date('Y/m/d', strtotime($child['created_at'])) ?></small>
                                            <?php endif; ?>
                                        </div>
                                        <div class="equipment-list">
                                            <?php foreach ($child['equipment'] as $ceq): ?>
                                            <div class="equipment-item">
                                                <span class="equipment-name"><?= htmlspecialchars($ceq['name'] ?? '未知器材') ?></span>
                                                <span class="equipment-quantity">× <?= intval($ceq['quantity']) ?></span>
                                            </div>
                                            <?php endforeach; ?>
                                        </div>
                                        <?php if ($child['status'] === 'rejected' && !empty($child['review_note'])): ?>
                                            <div style="color:#842029;font-size:.8rem;margin-top:.2rem;">駁回原因：<?= htmlspecialchars($child['review_note']) ?></div>
                                        <?php endif; ?>
                                    <?php endforeach; ?>
                                </div>
                                <?php endif; ?>
                                
                                <div class="application-actions">
                                    <?php if (in_array($effective_status, ['pending', 'approved'])): ?>
                                        <button class="btn-action btn-cancel" onclick="cancelApplication(<?php echo $app['event_id']; ?>, 'EVENT<?php echo str_pad($app['event_id'], 6, '0', STR_PAD_LEFT); ?>')">取消申請</button>
                                    <?php elseif ($effective_status === 'completed'): ?>
                                        <span style="color:#6b7280;font-size:0.88rem;"><i class="bi bi-check-circle me-1"></i>活動已結束</span>
                                    <?php elseif ($app['status'] === 'rejected'): ?>
                                        <span style="color: #dc3545; font-size: 0.9rem;">
                                            <?php if (!empty($app['review_note'])): ?>
                                                審核意見：<?php echo htmlspecialchars($app['review_note']); ?>
                                            <?php else: ?>
                                                申請已拒絕
                                            <?php endif; ?>
                                        </span>
                                    <?php elseif ($app['status'] === 'cancelled'): ?>
                                        <span class="status-badge status-rejected" style="font-size: 0.9rem; padding: 0.4rem 1rem;">
                                            <i class="bi bi-x-circle me-1"></i>已取消
                                        </span>
                                    <?php endif; ?>
                                </div>
                            </div> <?php endforeach; ?>
                    <?php endif; ?>
                </div>

                <div id="emptyState" class="empty-state" style="display: none;">
                    <i class="bi bi-inbox"></i>
                    <h4>目前沒有申請記錄</h4>
                    <p>您還沒有提交任何申請，點擊下方按鈕開始申請吧！</p>
                    <a href="apply_event.php" class="btn-action btn-edit" style="display: inline-block; margin-top: 1rem;">前往申請</a>
                </div>
            </div>
        </section>
    </main>

    <script>
        let currentFilter = 'all';
        let sortKey = 'apply'; // 'apply' | 'event'
        let sortDir = 'desc';  // 'asc' | 'desc'

        function filterApplications(filter) {
            currentFilter = filter;
            document.querySelectorAll('.filter-tab').forEach(t => t.classList.remove('active'));
            event.target.classList.add('active');
            const cards = document.querySelectorAll('.application-card');
            cards.forEach(card => {
                const status = card.getAttribute('data-status');
                card.style.display = (filter === 'all' || filter === status) ? 'block' : 'none';
            });
        }

        function sortCards(key, toggle = true) {
            if (sortKey === key) {
                if (toggle) {
                    sortDir = sortDir === 'asc' ? 'desc' : 'asc';
                }
            } else {
                sortKey = key;
                sortDir = 'desc';
            }
            // 更新按鈕樣式
            document.querySelectorAll('.sort-btn').forEach(b => b.classList.remove('sort-active'));
            document.getElementById(key === 'apply' ? 'sortApplyBtn' : 'sortEventBtn').classList.add('sort-active');
            document.getElementById('sortApplyDir').textContent = sortKey === 'apply' ? (sortDir === 'desc' ? '↓' : '↑') : '↓';
            document.getElementById('sortEventDir').textContent = sortKey === 'event' ? (sortDir === 'desc' ? '↓' : '↑') : '↓';

            const list = document.getElementById('applicationsList');
            const cards = Array.from(list.querySelectorAll('.application-card'));
            const attr = key === 'apply' ? 'data-apply-time' : 'data-event-start';
            cards.sort((a, b) => {
                const va = parseInt(a.getAttribute(attr)) || 0;
                const vb = parseInt(b.getAttribute(attr)) || 0;
                return sortDir === 'desc' ? vb - va : va - vb;
            });
            cards.forEach(c => list.appendChild(c));
        }

        // 預設依申請時間降序
        document.addEventListener('DOMContentLoaded', () => sortCards('apply', false));

        function editApplication(eventId) {
            window.location.href = `edit_application.php?id=${eventId}`;
        }

        function cancelApplication(eventId, appNumber) {
            if (confirm(`確定要取消申請 ${appNumber} 嗎？取消後無法恢復。`)) {
                // 向服務器發送取消申請請求
                fetch('../api/cancel_application.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/x-www-form-urlencoded',
                    },
                    body: `event_id=${eventId}`
                })
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        alert('申請已取消');
                        location.reload();
                    } else {
                        alert('取消失敗：' + (data.message || '未知錯誤'));
                    }
                })
                .catch(error => {
                    console.error('Error:', error);
                    alert('發生錯誤，請重試');
                });
            }
        }

        function redirectToAddEquipment(eventId) {
            // 重定向到追加申請器材頁面
            window.location.href = `add_equipment.php?event_id=${eventId}`;
        }

        function viewDetails(eventId) {
            // 跳轉到事件詳情頁面
            window.location.href = `event_details.php?id=${eventId}`;
        }
    </script>
</body>
</html>