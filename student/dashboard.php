<?php
// 1. 引入你原本的 mysqli 連線檔
require_once '../DB/db_config.php'; 

// 2. 檢查連線變數是否存在 (你的 db_config.php 裡叫 $conn)
if (!isset($conn)) {
    die("錯誤：無法取得資料庫連線變數 \$conn");
}

$today = date('Y-m-d');

// 3. 改用 mysqli 的語法來抓取今日活動摘要
$stmt = $conn->prepare("
    SELECT e.event_name, s.space_name, e.start_time 
    FROM events e 
    JOIN reservations r ON e.event_id = r.event_id 
    JOIN spaces s ON r.space_id = s.space_id 
    WHERE DATE(e.start_time) = ?
    ORDER BY e.start_time ASC
");

// mysqli 的綁定參數方式： "s" 代表字串 (string)
$stmt->bind_param("s", $today);
$stmt->execute();
$result = $stmt->get_result();
$today_events = $result->fetch_all(MYSQLI_ASSOC);
?>

<!DOCTYPE html>
<html lang="zh-TW">
<head>
    <meta charset="UTF-8">
    <title>Dashboard - 輔大場地管理系統</title>
    <style>
        /* 沿用你喜歡的莫蘭迪配色 */
        body { font-family: "Microsoft JhengHei", sans-serif; background-color: #f4f1de; margin: 0; display: flex; height: 100vh; overflow: hidden; }
        
        /* 側邊欄 */
        .sidebar { width: 260px; background: #646d8a; color: white; padding: 30px 20px; display: flex; flex-direction: column; }
        .sidebar a { color: white; text-decoration: none; padding: 12px; margin: 5px 0; border-radius: 8px; transition: 0.3s; }
        .sidebar a:hover { background: #8d9da5; }

        /* 主內容區 */
        .main-content { flex: 1; padding: 25px; display: flex; flex-direction: column; overflow-y: auto; }
        .content-grid { display: grid; grid-template-columns: 1fr 2.5fr; gap: 20px; flex: 1; }

        /* 今日摘要卡片 */
        .summary-card { background: #ffe5e5; padding: 20px; border-radius: 15px; border-left: 6px solid #e5989b; height: fit-content; }
        .event-item { background: white; padding: 10px; margin-top: 10px; border-radius: 8px; font-size: 14px; color: #4f4a49; }

        /* 行事曆容器 */
        .calendar-wrapper { background: white; border-radius: 15px; overflow: hidden; box-shadow: 0 4px 15px rgba(0,0,0,0.1); }
        iframe { width: 100%; height: 100%; border: none; }
    </style>
</head>
<body>

<div class="sidebar">
    <h3>EAMS 系統</h3>
    <a href="dashboard.php">🏠 首頁控制台</a>
    <a href="calendar.php">📅 完整行事曆</a>
    <a href="apply_event.php">📝 申請借用</a>
    <div style="margin-top: auto;">
        <a href="../logout.php" style="color: #ddbea9;">🚪 登出系統</a>
    </div>
</div>

<div class="main-content">
    <div style="margin-bottom: 20px;">
        <h2 style="color: #6b705c; margin: 0;">您好，學生！</h2>
        <p style="color: #a5a58d; margin: 5px 0;">今天是 <?php echo date('Y/m/d'); ?></p>
    </div>

    <div class="content-grid">
        <div class="summary-card">
            <h3 style="margin-top:0; color: #6b705c;">📌 今日活動摘要</h3>
            <?php if (empty($today_events)): ?>
                <p style="font-size: 14px;">今天目前沒有預約活動。</p>
            <?php else: ?>
                <?php foreach ($today_events as $ev): ?>
                    <div class="event-item">
                        <strong><?php echo htmlspecialchars($ev['event_name']); ?></strong><br>
                        📍 <?php echo htmlspecialchars($ev['space_name']); ?><br>
                        🕒 <?php echo date('H:i', strtotime($ev['start_time'])); ?>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>

        <div class="calendar-wrapper">
            <iframe src="calendar.php"></iframe>
        </div>
    </div>
</div>

</body>
</html>