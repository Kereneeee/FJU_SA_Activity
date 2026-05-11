<?php
require_once(__DIR__ . "/DB/db_config.php");

// 获取测试学生用户ID
$email = '410123456';
$sql = "SELECT user_id FROM users WHERE email = ?";
$stmt = $conn->prepare($sql);
$stmt->bind_param("s", $email);
$stmt->execute();
$result = $stmt->get_result();

if ($result && $result->num_rows > 0) {
    $user = $result->fetch_assoc();
    $student_id = $user['user_id'];
    
    echo "找到学生用户ID: $student_id\n";
    
    // 添加三个测试申请 - 不同的状态
    $events = [
        [
            'event_name' => '吉他社春季演唱會',
            'club_name' => '吉他社',
            'description' => '本社春季成果演唱會，展示社員這一學年的學習成果',
            'start_time' => '2026-05-20 18:00:00',
            'end_time' => '2026-05-20 20:30:00',
            'status' => 'pending',
            'space_id' => 1
        ],
        [
            'event_name' => '英文讀書會討論活動',
            'club_name' => '英文讀書會',
            'description' => '本月討論《To Kill a Mockingbird》英文經典著作',
            'start_time' => '2026-05-25 15:00:00',
            'end_time' => '2026-05-25 17:00:00',
            'status' => 'approved',
            'space_id' => 2
        ],
        [
            'event_name' => '美術社作品展示會',
            'club_name' => '美術社',
            'description' => '展示社員創作的繪畫、雕塑、攝影作品',
            'start_time' => '2026-04-15 10:00:00',
            'end_time' => '2026-04-15 12:00:00',
            'status' => 'completed',
            'space_id' => 3
        ]
    ];
    
    foreach ($events as $event) {
        $insert_sql = "INSERT INTO events (user_id, event_name, club_name, description, start_time, end_time, status, created_at) 
                      VALUES (?, ?, ?, ?, ?, ?, ?, NOW())";
        $insert_stmt = $conn->prepare($insert_sql);
        $insert_stmt->bind_param("issssss", 
            $student_id, 
            $event['event_name'], 
            $event['club_name'],
            $event['description'],
            $event['start_time'],
            $event['end_time'],
            $event['status']
        );
        
        if ($insert_stmt->execute()) {
            $event_id = $insert_stmt->insert_id;
            echo "✓ 已添加申請: {$event['event_name']} (狀態: {$event['status']}, ID: $event_id)\n";
            
            // 為該事件添加場地預約
            $res_sql = "INSERT INTO reservations (event_id, space_id, start_time, end_time) VALUES (?, ?, ?, ?)";
            $res_stmt = $conn->prepare($res_sql);
            $res_stmt->bind_param("iiss", $event_id, $event['space_id'], $event['start_time'], $event['end_time']);
            $res_stmt->execute();
            $res_stmt->close();
        } else {
            echo "✗ 添加失敗: {$event['event_name']} - " . $insert_stmt->error . "\n";
        }
        $insert_stmt->close();
    }
    
    echo "\n✓ 所有測試申請已添加完成！\n";
} else {
    echo "✗ 找不到測試學生用戶\n";
}

$stmt->close();
$conn->close();
?>
