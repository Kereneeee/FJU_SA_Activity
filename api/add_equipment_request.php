<?php
/**
 * API 端點：添加或更新器材申請
 * 用於處理"追加申請器材"功能
 */

ini_set('display_errors', 1);
error_reporting(E_ALL);

require_once(__DIR__ . "/../DB/db_config.php");

if (!isset($_SESSION['student_id'])) {
    http_response_code(401);
    die(json_encode(['success' => false, 'message' => '未登入']));
}

// 獲取請求數據
$input = json_decode(file_get_contents('php://input'), true);
$event_id = $input['event_id'] ?? $_POST['event_id'] ?? null;
$equipment_ids = $input['equipment'] ?? $_POST['equipment'] ?? [];

if (!$event_id) {
    http_response_code(400);
    die(json_encode(['success' => false, 'message' => '缺少活動ID']));
}

// 驗證活動是否屬於當前使用者
$user_sql = "SELECT user_id FROM users WHERE email = ?";
$user_stmt = $conn->prepare($user_sql);
$user_stmt->bind_param("s", $_SESSION['student_id']);
$user_stmt->execute();
$user_result = $user_stmt->get_result();
$user_id = null;

if ($user_result && $user_result->num_rows > 0) {
    $user_row = $user_result->fetch_assoc();
    $user_id = $user_row['user_id'];
}
$user_stmt->close();

// 驗證活動存在且屬於當前使用者
$event_sql = "SELECT event_id FROM events WHERE event_id = ? AND user_id = ?";
$event_stmt = $conn->prepare($event_sql);
$event_stmt->bind_param("ii", $event_id, $user_id);
$event_stmt->execute();
$event_result = $event_stmt->get_result();

if ($event_result->num_rows === 0) {
    http_response_code(403);
    die(json_encode(['success' => false, 'message' => '無權限修改此申請']));
}
$event_stmt->close();

try {
    $conn->begin_transaction();

    // 刪除現有的器材申請（如果有）
    $delete_sql = "DELETE FROM equipment_borrow WHERE event_id = ?";
    $delete_stmt = $conn->prepare($delete_sql);
    $delete_stmt->bind_param("i", $event_id);
    $delete_stmt->execute();
    $delete_stmt->close();

    // 如果有新的器材申請，插入它們
    if (!empty($equipment_ids) && is_array($equipment_ids)) {
        $insert_sql = "INSERT INTO equipment_borrow (event_id, equipment_id, quantity, status) VALUES (?, ?, ?, 'pending')";
        $insert_stmt = $conn->prepare($insert_sql);

        foreach ($equipment_ids as $equipment_id => $quantity) {
            $quantity = intval($quantity);
            if ($quantity > 0) {
                $equipment_id = intval($equipment_id);
                $insert_stmt->bind_param("iii", $event_id, $equipment_id, $quantity);
                
                if (!$insert_stmt->execute()) {
                    throw new Exception("器材申請插入失敗: " . $insert_stmt->error);
                }
            }
        }
        $insert_stmt->close();
    }

    // 提交事務
    $conn->commit();

    // 返回成功響應
    http_response_code(200);
    echo json_encode([
        'success' => true,
        'message' => '器材申請已提交成功'
    ]);

} catch (Exception $e) {
    // 回滾事務
    $conn->rollback();
    
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => '提交失敗：' . $e->getMessage()
    ]);
}
?>
