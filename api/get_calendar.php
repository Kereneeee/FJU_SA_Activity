<?php
// 引入資料庫設定
require_once '../DB/db_config.php';

global $conn;

header('Content-Type: application/json');

// 根據傳入的年月抓取活動，並關聯空間與使用者
$year = $_GET['year'] ?? date('Y');
$month = $_GET['month'] ?? date('m');
$targetDate = "$year-" . str_pad($month, 2, "0", STR_PAD_LEFT);

$events = [];

try {
    // 查詢活動預約（已審核通過的）
    $sql = "SELECT e.event_name, s.space_name, e.start_time, e.end_time, u.name as user_name, e.description, 'event' as type
            FROM events e
            LEFT JOIN reservations r ON e.event_id = r.event_id
            LEFT JOIN spaces s ON r.space_id = s.space_id
            LEFT JOIN users u ON e.user_id = u.user_id
            WHERE e.start_time LIKE ? AND e.status = 'approved'";

    $stmt = $conn->prepare($sql);
    $searchDate = $targetDate . "%";
    $stmt->bind_param("s", $searchDate);
    $stmt->execute();
    $result = $stmt->get_result();
    
    while ($row = $result->fetch_assoc()) {
        $events[] = $row;
    }
    $stmt->close();
    
    // 查詢器材借用（已審核通過的）
    $sql2 = "SELECT e.event_name, eq.name as equipment_name, e.start_time, e.end_time, u.name as user_name, 
                    CONCAT('器材: ', eq.name, ' x ', eb.quantity) as description, 'equipment' as type
            FROM equipment_borrow eb
            JOIN events e ON eb.event_id = e.event_id
            JOIN equipment eq ON eb.equipment_id = eq.equipment_id
            LEFT JOIN users u ON e.user_id = u.user_id
            WHERE e.start_time LIKE ? AND e.status = 'approved'";
    
    $stmt2 = $conn->prepare($sql2);
    $stmt2->bind_param("s", $searchDate);
    $stmt2->execute();
    $result2 = $stmt2->get_result();
    
    while ($row = $result2->fetch_assoc()) {
        $events[] = $row;
    }
    $stmt2->close();
    
    echo json_encode($events);
    
} catch (Exception $e) {
    echo json_encode(['error' => $e->getMessage()]);
}
?>