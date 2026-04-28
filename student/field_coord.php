<?php
ini_set('display_errors', 1);
error_reporting(E_ALL);


require_once(__DIR__ . "/../DB/db_config.php");

if (!isset($_SESSION['student_id'])) {
    header('Location: ../login.php');
    exit();
}

// 設置當前頁面用於側邊欄高亮
$current_page = 'field_coord';
?>

<!DOCTYPE html>
<html lang="zh-TW">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>場地協助 - 輔仁大學課外活動指導組</title>

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
        .service-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
            gap: 1.5rem;
        }
        .service-card {
            background: white;
            border: 1px solid #e5e7eb;
            border-radius: 12px;
            padding: 1.5rem;
            transition: box-shadow 0.25s ease, transform 0.15s ease;
            text-align: center;
        }
        .service-card:hover {
            box-shadow: 0 8px 25px rgba(0,0,0,0.1);
            transform: translateY(-2px);
        }
        .service-icon {
            width: 60px;
            height: 60px;
            border-radius: 50%;
            background: var(--primary);
            color: white;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.8rem;
            margin: 0 auto 1rem;
        }
        .service-card h4 {
            margin-bottom: 0.5rem;
            font-weight: 600;
        }
        .service-card p {
            color: #6b7280;
            margin-bottom: 1rem;
        }
        .btn-service {
            background: var(--primary);
            color: white;
            border: none;
            padding: 0.5rem 1.5rem;
            border-radius: 8px;
            font-weight: 600;
            cursor: pointer;
            transition: background 0.25s ease;
        }
        .btn-service:hover {
            background: var(--sidebar);
        }
        .contact-info {
            background: #f8f9fa;
            border-radius: 12px;
            padding: 1.5rem;
            margin-top: 1rem;
        }
        .contact-item {
            display: flex;
            align-items: center;
            gap: 0.75rem;
            margin-bottom: 0.75rem;
        }
        .contact-item:last-child {
            margin-bottom: 0;
        }
        .contact-item i {
            color: var(--primary);
            font-size: 1.2rem;
        }
        @media (max-width: 1100px) {
            .service-grid { grid-template-columns: 1fr; }
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
                    <li class="breadcrumb-item active" aria-current="page">場地協助</li>
                </ol>
                <h4 class="mt-2 mb-0">場地協助服務</h4>
            </div>
        </header>

        <section class="content-wrapper">
            <div class="card">
                <h3>場地協助服務</h3>
                <p class="text-muted">我們提供專業的場地布置、設備安裝及活動支援服務，協助您順利舉辦各項活動。</p>

                <div class="service-grid">
                    <div class="service-card">
                        <div class="service-icon">
                            <i class="bi bi-tools"></i>
                        </div>
                        <h4>設備安裝</h4>
                        <p>專業技術人員協助安裝投影機、音響、麥克風等設備</p>
                        <button class="btn-service" onclick="requestService('設備安裝')">申請服務</button>
                    </div>

                    <div class="service-card">
                        <div class="service-icon">
                            <i class="bi bi-house-gear"></i>
                        </div>
                        <h4>場地布置</h4>
                        <p>桌椅排列、舞台搭建、佈景布置等場地規劃服務</p>
                        <button class="btn-service" onclick="requestService('場地布置')">申請服務</button>
                    </div>

                    <div class="service-card">
                        <div class="service-icon">
                            <i class="bi bi-lightbulb"></i>
                        </div>
                        <h4>燈光音響</h4>
                        <p>專業燈光設計、音響調校及效果測試服務</p>
                        <button class="btn-service" onclick="requestService('燈光音響')">申請服務</button>
                    </div>

                    <div class="service-card">
                        <div class="service-icon">
                            <i class="bi bi-wrench"></i>
                        </div>
                        <h4>技術支援</h4>
                        <p>活動期間的技術問題排除及緊急處理服務</p>
                        <button class="btn-service" onclick="requestService('技術支援')">申請服務</button>
                    </div>

                    <div class="service-card">
                        <div class="service-icon">
                            <i class="bi bi-people"></i>
                        </div>
                        <h4>工作人員</h4>
                        <p>提供場地管理及活動協助的工作人員支援</p>
                        <button class="btn-service" onclick="requestService('工作人員')">申請服務</button>
                    </div>

                    <div class="service-card">
                        <div class="service-icon">
                            <i class="bi bi-clipboard-check"></i>
                        </div>
                        <h4>活動諮詢</h4>
                        <p>場地使用規範說明及活動規劃專業建議</p>
                        <button class="btn-service" onclick="requestService('活動諮詢')">聯絡我們</button>
                    </div>
                </div>
            </div>

            <div class="card">
                <h3>聯絡資訊</h3>
                <div class="contact-info">
                    <div class="contact-item">
                        <i class="bi bi-telephone"></i>
                        <div>
                            <strong>場地管理中心</strong><br>
                            電話：(02) 2905-2000 轉 1234
                        </div>
                    </div>
                    <div class="contact-item">
                        <i class="bi bi-envelope"></i>
                        <div>
                            <strong>電子郵件</strong><br>
                            venue@fju.edu.tw
                        </div>
                    </div>
                    <div class="contact-item">
                        <i class="bi bi-clock"></i>
                        <div>
                            <strong>服務時間</strong><br>
                            週一至週五 08:00-17:00
                        </div>
                    </div>
                    <div class="contact-item">
                        <i class="bi bi-geo-alt"></i>
                        <div>
                            <strong>辦公室位置</strong><br>
                            輔仁大學 場地管理中心 (學生中心1樓)
                        </div>
                    </div>
                </div>
            </div>
        </section>
    </main>

    <script>
        function requestService(serviceType) {
            const message = `您即將申請「${serviceType}」服務。\n\n請確認以下資訊：\n• 服務類型：${serviceType}\n• 申請時間：${new Date().toLocaleString('zh-TW')}\n\n我們將盡快與您聯繫確認詳細需求。`;

            if (confirm(message)) {
                // 這裡可以添加實際的服務申請邏輯
                alert('服務申請已提交！我們將在1個工作天內與您聯繫。');
            }
        }
    </script>
</body>
</html>