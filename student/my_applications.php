<?php
ini_set('display_errors', 1);
error_reporting(E_ALL);

require_once(__DIR__ . "/../DB/db_config.php");

if (!isset($_SESSION['student_id'])) {
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

$sql = "SELECT e.event_id, e.event_name, e.club_name, e.description, e.start_time, e.end_time, e.status, e.review_note,
               e.document_path, e.venue_doc_path, e.equipment_doc_path, s.space_name
        FROM events e
        LEFT JOIN reservations r ON e.event_id = r.event_id
        LEFT JOIN spaces s ON r.space_id = s.space_id
        ORDER BY CASE WHEN e.status = 'pending' THEN 0 ELSE 1 END, e.created_at DESC";

$stmt = $conn->prepare($sql);
if (!$stmt) {
    die("準備SQL語句失敗: " . $conn->error);
}

$stmt->bind_param("i", $student_user_id);
$stmt->execute();
$result = $stmt->get_result();

if ($result) {
    $applications = $result->fetch_all(MYSQLI_ASSOC);
}
$stmt->close();

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
        .status-badge {
            padding: 0.25rem 0.75rem;
            border-radius: 999px;
            font-size: 0.8rem;
            font-weight: 600;
        }
        .status-pending { background: #fff3cd; color: #664d03; }
        .status-approved { background: #d1e7dd; color: #0f5132; }
        .status-rejected { background: #f8d7da; color: #721c24; }
        .status-completed { background: #e7f3ff; color: #084c7d; }
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
    </style>
</head>
<body>
    <?php include(__DIR__ . "/../includes/sidebar.php"); ?>

    <main class="main-content">
        <header class="top-navbar">
            <div>
                <ol class="breadcrumb mb-0">
                    <li class="breadcrumb-item"><a href="dashboard.php">首頁</a></li>
                    <li class="breadcrumb-item active" aria-current="page">我的申請</li>
                </ol>
                <h4 class="mt-2 mb-0">我的申請</h4>
            </div>
        </header>

        <section class="content-wrapper">
            <div class="card">
                <h3>申請記錄</h3>

                <div class="filter-tabs">
                    <button class="filter-tab active" onclick="filterApplications('all')">全部</button>
                    <button class="filter-tab" onclick="filterApplications('pending')">審核中</button>
                    <button class="filter-tab" onclick="filterApplications('approved')">已通過</button>
                    <button class="filter-tab" onclick="filterApplications('completed')">已完成</button>
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
                        <?php foreach ($applications as $app): ?>
                            <div class="application-card" data-status="<?php echo htmlspecialchars($app['status']); ?>">
                                <div class="application-header">
                                    <div>
                                        <div class="application-title"><?php echo htmlspecialchars($app['event_name']); ?></div>
                                        <div class="application-meta">申請日期：<?php echo date('Y-m-d', strtotime($app['created_at'])); ?> | 申請編號：EVENT<?php echo str_pad($app['event_id'], 6, '0', STR_PAD_LEFT); ?></div>
                                    </div>
                                    <span class="status-badge <?php echo $status_class_map[$app['status']] ?? 'status-pending'; ?>">
                                        <?php echo $status_map[$app['status']] ?? '未知'; ?>
                                    </span>
                                </div>
                                <div class="application-details">
                                    <div class="detail-item">
                                        <div class="detail-label">活動日期</div>
                                        <div class="detail-value"><?php echo date('Y-m-d', strtotime($app['start_time'])); ?></div>
                                    </div>
                                    <div class="detail-item">
                                        <div class="detail-label">活動時間</div>
                                        <div class="detail-value"><?php echo date('H:i', strtotime($app['start_time'])); ?> - <?php echo date('H:i', strtotime($app['end_time'])); ?></div>
                                    </div>
                                    <div class="detail-item">
                                        <div class="detail-label">場地</div>
                                        <div class="detail-value"><?php echo htmlspecialchars($app['space_name'] ?? '未指定'); ?></div>
                                    </div>
                                    <div class="detail-item">
                                        <div class="detail-label">社團</div>
                                        <div class="detail-value"><?php echo htmlspecialchars($app['club_name']); ?></div>
                                    </div>
                                </div>
                                <div class="application-actions">
                                    <?php 
                                    // 根據申請狀態決定顯示的按鈕
                                    // 審核中和已通過的申請不能編輯，只能取消
                                    if ($app['status'] === 'pending'):
                                    ?>
                                        <button class="btn-action btn-cancel" onclick="cancelApplication(<?php echo $app['event_id']; ?>, 'EVENT<?php echo str_pad($app['event_id'], 6, '0', STR_PAD_LEFT); ?>')">取消</button>
                                    <?php elseif ($app['status'] === 'approved'): ?>
                                        <button class="btn-action btn-cancel" onclick="cancelApplication(<?php echo $app['event_id']; ?>, 'EVENT<?php echo str_pad($app['event_id'], 6, '0', STR_PAD_LEFT); ?>')">取消</button>
                                    <?php elseif ($app['status'] === 'completed'): ?>
                                        <button class="btn-action" onclick="viewDetails(<?php echo $app['event_id']; ?>)">查看詳情</button>
                                    <?php elseif ($app['status'] === 'rejected'): ?>
                                        <span style="color: #dc3545; font-size: 0.9rem;">
                                            <?php if (!empty($app['review_note'])): ?>
                                                審核意見：<?php echo htmlspecialchars($app['review_note']); ?>
                                            <?php else: ?>
                                                申請已拒絕
                                            <?php endif; ?>
                                        </span>
                                    <?php endif; ?>
                                </div>
                            </div>
                        <?php endforeach; ?>
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

        function filterApplications(filter) {
            currentFilter = filter;

            // 更新按鈕狀態
            document.querySelectorAll('.filter-tab').forEach(tab => {
                tab.classList.remove('active');
            });
            event.target.classList.add('active');

            // 根據篩選條件顯示/隱藏申請卡片
            const cards = document.querySelectorAll('.application-card');
            cards.forEach(card => {
                const status = card.getAttribute('data-status');
                
                if (filter === 'all') {
                    card.style.display = 'block';
                } else if (filter === 'pending' && status === 'pending') {
                    card.style.display = 'block';
                } else if (filter === 'approved' && status === 'approved') {
                    card.style.display = 'block';
                } else if (filter === 'completed' && status === 'completed') {
                    card.style.display = 'block';
                } else {
                    card.style.display = 'none';
                }
            });
        }

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

        function viewDetails(eventId) {
            // 跳轉到事件詳情頁面
            window.location.href = `event_details.php?id=${eventId}`;
        }
    </script>
</body>
</html>