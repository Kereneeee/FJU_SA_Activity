<?php
ini_set('display_errors', 1);
error_reporting(E_ALL);

require_once(__DIR__ . "/../DB/db_config.php");

if (!isset($_SESSION['student_id'])) {
    header('Location: ../login.php');
    exit();
}

// 設置當前頁面用於側邊欄高亮
$current_page = 'edit_application';

// 獲取申請ID（如果有的話）
$appId = $_GET['id'] ?? null;
?>

<!DOCTYPE html>
<html lang="zh-TW">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>編輯申請 - 輔仁大學課外活動指導組</title>

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
        .form-group {
            margin-bottom: 1.5rem;
        }
        .form-group label {
            display: block;
            margin-bottom: 0.5rem;
            font-weight: 600;
        }
        .form-control {
            width: 100%;
            padding: 0.75rem;
            border: 1px solid #e5e7eb;
            border-radius: 8px;
            font-size: 0.95rem;
        }
        .form-control:focus {
            outline: none;
            border-color: var(--primary);
            box-shadow: 0 0 0 3px rgba(139, 21, 56, 0.1);
        }
        .time-inputs {
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }
        .time-inputs input {
            flex: 1;
        }
        .row {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 1.5rem;
        }
        .equipment-item {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 1rem;
            border: 1px solid #e5e7eb;
            border-radius: 12px;
            margin-bottom: 0.75rem;
        }
        .equipment-info h6 {
            margin: 0 0 0.25rem;
            font-weight: 600;
        }
        .equipment-info small {
            color: #6b7280;
        }
        .counter {
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }
        .counter button {
            width: 32px;
            height: 32px;
            border: 1px solid #d1d5db;
            background: white;
            border-radius: 6px;
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
        }
        .counter button:hover {
            background: #f9fafb;
        }
        .counter input {
            width: 50px;
            text-align: center;
            border: 1px solid #d1d5db;
            border-radius: 6px;
            padding: 0.25rem;
        }
        .message {
            padding: 1rem;
            border-radius: 12px;
            margin-bottom: 1.5rem;
            font-weight: 600;
        }
        .message.success {
            background: #d1e7dd;
            color: #0f5132;
            border: 1px solid #a3cfbb;
        }
        .message.error {
            background: #f8d7da;
            color: #721c24;
            border: 1px solid #f1aeb5;
        }
        .action-buttons {
            display: flex;
            gap: 1rem;
            justify-content: flex-end;
            margin-top: 2rem;
        }
        .btn-submit {
            background: var(--primary);
            color: white;
            border: none;
            padding: 0.75rem 2rem;
            border-radius: 8px;
            font-weight: 600;
            cursor: pointer;
            transition: background 0.25s ease;
        }
        .btn-submit:hover {
            background: var(--sidebar);
        }
        .btn-cancel {
            background: white;
            color: #6b7280;
            border: 1px solid #d1d5db;
            padding: 0.75rem 2rem;
            border-radius: 8px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.25s ease;
        }
        .btn-cancel:hover {
            background: #f9fafb;
        }
        .alert-info {
            background: #e7f3ff;
            color: #084c7d;
            border: 1px solid #b3d7ff;
            border-radius: 8px;
            padding: 1rem;
            margin-bottom: 1.5rem;
        }
        @media (max-width: 1100px) {
            .row { grid-template-columns: 1fr; }
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

    <script>
        let stocks = [10,10,10,10,10,10];

        function changeQty(id, delta) {
            let input = document.getElementById("qty_" + id);
            let value = parseInt(input.value) + delta;

            let max = stocks[id];

            if (value < 0) value = 0;
            if (value > max) value = max;

            input.value = value;
        }

        function checkTime() {
            let s = document.getElementById("start_time").value;
            let e = document.getElementById("end_time").value;

            let msg = document.getElementById("time_error");

            if (s && e && s >= e) {
                msg.innerText = "❌ 結束時間必須晚於開始時間！";
            } else {
                msg.innerText = "";
            }
        }

        function validateForm() {
            let venue = document.getElementById("venue").value;
            let s = document.getElementById("start_time").value;
            let e = document.getElementById("end_time").value;

            if (venue == "請選擇場地") {
                alert("請選擇場地！");
                return false;
            }

            if (s >= e) {
                alert("結束時間必須晚於開始時間！");
                return false;
            }

            return true;
        }
    </script>
</head>
<body>
    <?php include(__DIR__ . "/../includes/sidebar.php"); ?>

    <main class="main-content">
        <header class="top-navbar">
            <div>
                <ol class="breadcrumb mb-0">
                    <li class="breadcrumb-item"><a href="dashboard.php">首頁</a></li>
                    <li class="breadcrumb-item"><a href="my_applications.php">我的申請</a></li>
                    <li class="breadcrumb-item active" aria-current="page">編輯申請</li>
                </ol>
                <h4 class="mt-2 mb-0">編輯申請</h4>
            </div>
        </header>

        <section class="content-wrapper">
            <?php if (!$appId): ?>
            <div class="alert-info">
                <i class="bi bi-info-circle"></i>
                請從「我的申請」頁面選擇要編輯的申請，或提供申請編號。
            </div>
            <?php else: ?>
            <div class="alert-info">
                <i class="bi bi-info-circle"></i>
                正在編輯申請編號：<?php echo htmlspecialchars($appId); ?>
            </div>
            <?php endif; ?>

            <form method="POST" onsubmit="return validateForm()">
                <div class="card">
                    <h3>基本資料</h3>

                    <div class="form-group">
                        <label for="event_name">活動名稱 *</label>
                        <input type="text" id="event_name" name="event_name" class="form-control" value="社團聯歡活動" required>
                    </div>

                    <div class="form-group">
                        <label for="club_name">社團名稱 *</label>
                        <input type="text" id="club_name" name="club_name" class="form-control" value="學生會" required>
                    </div>

                    <div class="form-group">
                        <label for="date">活動日期 *</label>
                        <input type="date" id="date" name="date" class="form-control" value="2024-01-20" required>
                    </div>

                    <div class="form-group">
                        <label>活動時間 *</label>
                        <div class="time-inputs">
                            <input type="time" id="start_time" name="start_time" class="form-control" value="14:00" onchange="checkTime()" required>
                            <span>～</span>
                            <input type="time" id="end_time" name="end_time" class="form-control" value="17:00" onchange="checkTime()" required>
                        </div>
                        <div id="time_error" style="color:#dc3545; font-size:0.9rem; margin-top:0.25rem;"></div>
                    </div>
                </div>

                <div class="row">
                    <div class="card">
                        <h3>場地選擇</h3>
                        <div class="form-group">
                            <label for="venue">選擇場地 *</label>
                            <select id="venue" name="venue" class="form-control" required>
                                <option>請選擇場地</option>
                                <option selected>大禮堂</option>
                                <option>會議室A</option>
                                <option>活動中心</option>
                            </select>
                        </div>
                    </div>

                    <div class="card">
                        <h3>器材借用</h3>
                        <?php
                        $eq = ["麥克風","投影機","音響","桌子","椅子","延長線"];
                        $current_qty = [2,1,1,10,40,5]; // 模擬當前借用數量
                        foreach($eq as $i => $name){
                            $current = $current_qty[$i] ?? 0;
                            echo "
                            <div class='equipment-item'>
                                <div class='equipment-info'>
                                    <h6>$name</h6>
                                    <small>剩餘: {$stocks[$i]} | 當前借用: {$current}</small>
                                </div>
                                <div class='counter'>
                                    <button type='button' onclick='changeQty($i,-1)'>-</button>
                                    <input id='qty_$i' name='qty[]' value='$current' readonly>
                                    <button type='button' onclick='changeQty($i,1)'>+</button>
                                </div>
                            </div>";
                        }
                        ?>
                    </div>
                </div>

                <div class="action-buttons">
                    <button type="button" class="btn-cancel" onclick="window.history.back()">取消</button>
                    <button type="submit" class="btn-submit">儲存修改</button>
                </div>
            </form>
        </section>
    </main>
</body>
</html>