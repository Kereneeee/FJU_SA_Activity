<?php
/**
 * API 端點：管理員審核器材申請
 * 用於處理管理員對器材申請的審核
 */

ini_set('display_errors', 1);
error_reporting(E_ALL);

require_once(__DIR__ . "/../DB/db_config.php");

// 檢查是否為管理員
if (!isset($_SESSION['student_id']) || $_SESSION['role'] !== 'admin') {
    http_response_code(401);
    die(json_encode(['success' => false, 'message' => '未授權']));
}

// 獲取請求數據
$input = json_decode(file_get_contents('php://input'), true);
$borrow_id = $input['borrow_id'] ?? $_POST['borrow_id'] ?? null;
$status = $input['status'] ?? $_POST['status'] ?? null;
$review_note = $input['review_note'] ?? $_POST['review_note'] ?? '';

// 驗證參數
if (!$borrow_id || !in_array($status, ['pending', 'approved', 'rejected', 'completed'])) {
    http_response_code(400);
    die(json_encode(['success' => false, 'message' => '無效的參數']));
}

try {
    $conn->begin_transaction();

    // 更新器材申請狀態
    $update_sql = "UPDATE equipment_borrow SET status = ?, review_note = ?, updated_at = NOW() WHERE borrow_id = ?";
    $update_stmt = $conn->prepare($update_sql);
    $update_stmt->bind_param("ssi", $status, $review_note, $borrow_id);

    if (!$update_stmt->execute()) {
        throw new Exception("更新失敗: " . $update_stmt->error);
    }
    $update_stmt->close();

    // 提交事務
    $conn->commit();

    // 返回成功響應
    http_response_code(200);
    echo json_encode([
        'success' => true,
        'message' => '器材申請已更新'
    ]);

} catch (Exception $e) {
    // 回滾事務
    $conn->rollback();
    
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => '更新失敗：' . $e->getMessage()
    ]);
}
?>
