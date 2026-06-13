<?php
session_start();
require_once '../DB/db_config.php';
header('Content-Type: application/json; charset=utf-8');

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'student') {
    echo json_encode(['success' => false, 'message' => '未登入']);
    exit;
}

$q = trim($_GET['student_id'] ?? '');

if (!$q) {
    echo json_encode(['success' => false, 'message' => '請輸入學號']);
    exit;
}

// 前綴模糊搜尋，最多回傳 10 筆
$like = $q . '%';
$stmt = $conn->prepare(
    "SELECT user_id, name, student_id FROM users
     WHERE CAST(student_id AS CHAR) LIKE ? AND role = 'student'
     ORDER BY student_id LIMIT 10"
);
$stmt->bind_param("s", $like);
$stmt->execute();
$rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

if (empty($rows)) {
    echo json_encode(['success' => false, 'message' => '查無符合的學號，請確認後再輸入。']);
    exit;
}

echo json_encode([
    'success' => true,
    'users'   => $rows,
]);
