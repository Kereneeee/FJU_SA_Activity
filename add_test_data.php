<?php
ini_set('display_errors', 1);
error_reporting(E_ALL);

require_once(__DIR__ . "/DB/db_config.php");

// 獲取或創建測試學生用戶
$email = "410123456";
$name = "廖同學";
$password = "1234";
$role = "student";

// 先檢查是否存在
$check_stmt = $conn->prepare("SELECT user_id FROM users WHERE email = ?");
$check_stmt->bind_param("s", $email);
$check_stmt->execute();
$result = $check_stmt->get_result();

if ($result->num_rows === 0) {
    // 創建新用戶
    $insert_stmt = $conn->prepare("INSERT INTO users (name, email, password, role) VALUES (?, ?, ?, ?)");
    $insert_stmt->bind_param("ssss", $name, $email, $password, $role);
    $insert_stmt->execute();
    $user_id = $insert_stmt->insert_id;
    $insert_stmt->close();
    echo "創建新用戶: $email (ID: $user_id)\n";
} else {
    $user = $result->fetch_assoc();
    $user_id = $user['user_id'];
    echo "找到現有用戶: $email (ID: $user_id)\n";
}
$check_stmt->close();

// 添加測試申請 1: 審核中 (pending)
$event_data_1 = [
    'user_id' => $user_id,
    'event_name' => '吉他社成果發表會',
    'club_name' => '吉他社',
    'description' => '年度吉他社成果發表演奏會',
    'start_time' => '2026-05-20 18:00:00',
    'end_time' => '2026-05-20 20:30:00',
    'status' => 'pending',
    'space_id' => 1
];

// 添加測試申請 2: 已通過 (approved)
$event_data_2 = [
    'user_id' => $user_id,
    'event_name' => '創意手工坊工作坊',
    'club_name' => '美勞社',
    'description' => '教授各種手工藝製作技巧',
    'start_time' => '2026-05-15 14:00:00',
    'end_time' => '2026-05-15 17:00:00',
    'status' => 'approved',
    'space_id' => 2
];

// 添加測試申請 3: 已完成 (completed)
$event_data_3 = [
    'user_id' => $user_id,
    'event_name' => '英文讀書會',
    'club_name' => '英文讀書會',
    'description' => '分享和討論英文文學作品',
    'start_time' => '2026-05-05 15:00:00',
    'end_time' => '2026-05-05 17:00:00',
    'status' => 'completed',
    'space_id' => 2
];

$events_data = [$event_data_1, $event_data_2, $event_data_3];
$statuses = ['pending' => '審核中', 'approved' => '已通過', 'completed' => '已完成'];

foreach ($events_data as $event_data) {
    // 檢查是否已存在相同的申請
    $check_event = $conn->prepare("SELECT event_id FROM events WHERE user_id = ? AND event_name = ?");
    $check_event->bind_param("is", $event_data['user_id'], $event_data['event_name']);
    $check_event->execute();
    $result_check = $check_event->get_result();
    
    if ($result_check->num_rows === 0) {
        // 插入事件
        $insert_event = $conn->prepare(
            "INSERT INTO events (user_id, event_name, club_name, description, start_time, end_time, status) 
             VALUES (?, ?, ?, ?, ?, ?, ?)"
        );
        $insert_event->bind_param(
            "issssss",
            $event_data['user_id'],
            $event_data['event_name'],
            $event_data['club_name'],
            $event_data['description'],
            $event_data['start_time'],
            $event_data['end_time'],
            $event_data['status']
        );
        $insert_event->execute();
        $event_id = $insert_event->insert_id;
        $insert_event->close();
        
        // 插入場地預約
        $insert_reservation = $conn->prepare(
            "INSERT INTO reservations (event_id, space_id, start_time, end_time) 
             VALUES (?, ?, ?, ?)"
        );
        $insert_reservation->bind_param(
            "iiss",
            $event_id,
            $event_data['space_id'],
            $event_data['start_time'],
            $event_data['end_time']
        );
        $insert_reservation->execute();
        $insert_reservation->close();
        
        echo "✓ 添加申請: " . $event_data['event_name'] . " (狀態: " . $statuses[$event_data['status']] . ")\n";
    } else {
        echo "✗ 申請已存在: " . $event_data['event_name'] . "\n";
    }
    $check_event->close();
}

echo "\n所有測試數據已添加完成！\n";
$conn->close();
?>
