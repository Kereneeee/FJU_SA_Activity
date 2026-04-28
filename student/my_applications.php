<?php
ini_set('display_errors', 1);
error_reporting(E_ALL);

session_start();
require_once(__DIR__ . "/../DB/db_config.php");

if (!isset($_SESSION['student_id'])) {
    header('Location: ../login.php');
    exit();
}

// 設置當前頁面用於側邊欄高亮
$current_page = 'my_applications';
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
                    <!-- 申請卡片將動態生成 -->
                    <div class="application-card">
                        <div class="application-header">
                            <div>
                                <div class="application-title">社團聯歡活動</div>
                                <div class="application-meta">申請日期：2024-01-15 | 申請編號：APP2024001</div>
                            </div>
                            <span class="status-badge status-approved">已通過</span>
                        </div>
                        <div class="application-details">
                            <div class="detail-item">
                                <div class="detail-label">活動日期</div>
                                <div class="detail-value">2024-01-20</div>
                            </div>
                            <div class="detail-item">
                                <div class="detail-label">活動時間</div>
                                <div class="detail-value">14:00 - 17:00</div>
                            </div>
                            <div class="detail-item">
                                <div class="detail-label">場地</div>
                                <div class="detail-value">大禮堂</div>
                            </div>
                            <div class="detail-item">
                                <div class="detail-label">狀態</div>
                                <div class="detail-value">等待活動執行</div>
                            </div>
                        </div>
                        <div class="application-actions">
                            <button class="btn-action btn-edit" onclick="editApplication('APP2024001')">編輯</button>
                            <button class="btn-action btn-cancel" onclick="cancelApplication('APP2024001')">取消</button>
                        </div>
                    </div>

                    <div class="application-card">
                        <div class="application-header">
                            <div>
                                <div class="application-title">音樂社表演</div>
                                <div class="application-meta">申請日期：2024-01-10 | 申請編號：APP2024002</div>
                            </div>
                            <span class="status-badge status-pending">審核中</span>
                        </div>
                        <div class="application-details">
                            <div class="detail-item">
                                <div class="detail-label">活動日期</div>
                                <div class="detail-value">2024-02-15</div>
                            </div>
                            <div class="detail-item">
                                <div class="detail-label">活動時間</div>
                                <div class="detail-value">18:00 - 20:00</div>
                            </div>
                            <div class="detail-item">
                                <div class="detail-label">場地</div>
                                <div class="detail-value">活動中心</div>
                            </div>
                            <div class="detail-item">
                                <div class="detail-label">狀態</div>
                                <div class="detail-value">等待管理員審核</div>
                            </div>
                        </div>
                        <div class="application-actions">
                            <button class="btn-action btn-edit" onclick="editApplication('APP2024002')">編輯</button>
                            <button class="btn-action btn-cancel" onclick="cancelApplication('APP2024002')">取消</button>
                        </div>
                    </div>

                    <div class="application-card">
                        <div class="application-header">
                            <div>
                                <div class="application-title">講座活動</div>
                                <div class="application-meta">申請日期：2024-01-05 | 申請編號：APP2024003</div>
                            </div>
                            <span class="status-badge status-completed">已完成</span>
                        </div>
                        <div class="application-details">
                            <div class="detail-item">
                                <div class="detail-label">活動日期</div>
                                <div class="detail-value">2024-01-08</div>
                            </div>
                            <div class="detail-item">
                                <div class="detail-label">活動時間</div>
                                <div class="detail-value">15:00 - 17:00</div>
                            </div>
                            <div class="detail-item">
                                <div class="detail-label">場地</div>
                                <div class="detail-value">會議室A</div>
                            </div>
                            <div class="detail-item">
                                <div class="detail-label">狀態</div>
                                <div class="detail-value">活動已結束</div>
                            </div>
                        </div>
                        <div class="application-actions">
                            <button class="btn-action" onclick="viewDetails('APP2024003')">查看詳情</button>
                        </div>
                    </div>
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

            // 這裡可以添加實際的過濾邏輯
            // 目前只是示例，實際應用中應該從資料庫獲取資料
            console.log('Filtering applications by:', filter);
        }

        function editApplication(appId) {
            window.location.href = `edit_application.php?id=${appId}`;
        }

        function cancelApplication(appId) {
            if (confirm('確定要取消此申請嗎？取消後無法恢復。')) {
                // 這裡可以添加取消申請的邏輯
                alert(`申請 ${appId} 已取消`);
                // 重新載入頁面或更新UI
                location.reload();
            }
        }

        function viewDetails(appId) {
            // 這裡可以跳轉到詳細頁面或打開模態框
            alert(`查看申請 ${appId} 的詳細資訊`);
        }
    </script>
</body>
</html>