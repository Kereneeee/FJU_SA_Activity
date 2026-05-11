<?php
// API 端點：處理取消申請
ini_set('display_errors', 1);
error_reporting(E_ALL);

require_once '../DB/db_config.php';

if (!isset($_SESSION['student_id'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => '未登入']);
    exit;
}

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $event_id = (int)($_POST['event_id'] ?? 0);
    $student_id = $_SESSION['student_id'];
    
    if (!$event_id) {
        echo json_encode(['success' => false, 'message' => '無效的申請ID']);
        exit;
    }
    
    // 檢查該事件是否屬於該學生
    $check_stmt = $conn->prepare("SELECT event_id, status FROM events WHERE event_id = ? AND user_id = ?");
    $check_stmt->bind_param("ii", $event_id, $student_id);
    $check_stmt->execute();
    $result = $check_stmt->get_result();
    
    if ($result->num_rows === 0) {
        echo json_encode(['success' => false, 'message' => '您無權取消此申請']);
        exit;
    }
    
    $event = $result->fetch_assoc();
    $check_stmt->close();
    
    // 檢查是否可以取消（只有審核中和已通過的可以取消）
    if (!in_array($event['status'], ['pending', 'approved'])) {
        echo json_encode(['success' => false, 'message' => '該申請狀態無法取消']);
        exit;
    }
    
    // 更新申請狀態為已取消
    $update_stmt = $conn->prepare("UPDATE events SET status = 'cancelled' WHERE event_id = ?");
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
