<?php
session_start();
require_once '../DB/db_config.php';
header('Content-Type: application/json; charset=utf-8');

if (!isset($_SESSION['student_id'])) {
    echo json_encode(['success' => false, 'message' => '未登入']);
    exit;
}

$student_id = trim($_GET['student_id'] ?? '');
$club_id    = trim($_GET['club_id'] ?? '');

if (!$student_id) {
    echo json_encode(['success' => false, 'message' => '請輸入學號']);
    exit;
}

// 查詢使用者
$stmt = $conn->prepare(
    "SELECT user_id, name, student_id FROM users WHERE student_id = ? AND role = 'student' LIMIT 1"
);
$stmt->bind_param("s", $student_id);
$stmt->execute();
$user = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$user) {
    echo json_encode(['success' => false, 'message' => '查無此學號，請確認後再輸入。']);
    exit;
}

echo json_encode([
    'success' => true,
    'user'    => [
        'user_id'    => $user['user_id'],
        'name'       => $user['name'],
        'student_id' => $user['student_id'],
    ],
]);
