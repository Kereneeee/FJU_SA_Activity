<?php
// 引入你現有的資料庫設定 [cite: 21, 23]
require_once '../config/db_config.php';

header('Content-Type: application/json');

// 根據傳入的年月抓取活動，並關聯空間與使用者
$year = $_GET['year'] ?? date('Y');
$month = $_GET['month'] ?? date('m');
$targetDate = "$year-" . str_pad($month, 2, "0", STR_PAD_LEFT);

try {
    // 使用你 SQL 檔中的欄位名稱：event_name, space_name, name, start_time 等
    $sql = "SELECT e.event_name, s.space_name, e.start_time, e.end_time, u.name as user_name, e.description 
            FROM events e
            LEFT JOIN reservations r ON e.event_id = r.event_id
            LEFT JOIN spaces s ON r.space_id = s.space_id
            LEFT JOIN users u ON e.user_id = u.user_id
            WHERE e.start_time LIKE :targetDate";

    $stmt = $pdo->prepare($sql);
    $stmt->execute(['targetDate' => $targetDate . '%']);
    echo json_encode($stmt->fetchAll(PDO::FETCH_ASSOC));
} catch (Exception $e) {
    echo json_encode(['error' => $e->getMessage()]);
}
?>