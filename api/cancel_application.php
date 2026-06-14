<?php
// API 端點：處理取消申請

require_once '../DB/db_config.php';

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'student') {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => '未登入']);
    exit;
}

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $event_id = (int)($_POST['event_id'] ?? 0);
    $student_email = $_SESSION['student_id'];
    
    if (!$event_id) {
        echo json_encode(['success' => false, 'message' => '無效的申請ID']);
        exit;
    }
    
    // 🔴 第一步：根據 email 獲取 user_id
    $user_stmt = $conn->prepare("SELECT user_id FROM users WHERE email = ?");
    if (!$user_stmt) {
        echo json_encode(['success' => false, 'message' => '資料庫連線錯誤']);
        exit;
    }
    $user_stmt->bind_param("s", $student_email);
    $user_stmt->execute();
    $user_result = $user_stmt->get_result();
    
    if ($user_result->num_rows === 0) {
        echo json_encode(['success' => false, 'message' => '使用者查詢失敗']);
        exit;
    }
    
    $user_row = $user_result->fetch_assoc();
    $user_id = (int)$user_row['user_id'];
    $user_stmt->close();
    
    // 🔴 第二步：檢查該事件是否屬於該學生且獲取當前狀態
    $check_stmt = $conn->prepare("SELECT event_id, status FROM events WHERE event_id = ? AND user_id = ?");
    if (!$check_stmt) {
        echo json_encode(['success' => false, 'message' => '資料庫連線錯誤']);
        exit;
    }
    $check_stmt->bind_param("ii", $event_id, $user_id);
    $check_stmt->execute();
    $result = $check_stmt->get_result();
    
    if ($result->num_rows === 0) {
        echo json_encode(['success' => false, 'message' => '您無權取消此申請']);
        exit;
    }
    
    $event = $result->fetch_assoc();
    $check_stmt->close();
    
    // 🔴 第三步：檢查是否可以取消（只有審核中和已通過的可以取消）
    if (!in_array($event['status'], ['pending', 'approved', 'rejected'], true)) {
        echo json_encode(['success' => false, 'message' => '該申請狀態無法取消']);
        exit;
    }
    
    // 🔴 第四步：更新申請狀態為已取消
    $update_stmt = $conn->prepare("UPDATE events SET status = 'cancelled' WHERE event_id = ?");
    if (!$update_stmt) {
        echo json_encode(['success' => false, 'message' => '資料庫連線錯誤']);
        exit;
    }
    $update_stmt->bind_param("i", $event_id);
    
    if ($update_stmt->execute()) {
        echo json_encode(['success' => true, 'message' => '申請已成功取消']);
    } else {
        echo json_encode(['success' => false, 'message' => '更新失敗：' . $conn->error]);
    }
    $update_stmt->close();
    exit;
}

echo json_encode(['success' => false, 'message' => '無效的請求方法']);
?>
