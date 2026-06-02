<?php
require_once '../DB/db_config.php';
require_once '../includes/functions.php';

checkLogin();
checkAdmin();

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'message' => '只支援 POST 請求']);
    exit;
}

$data     = json_decode(file_get_contents('php://input'), true);
$event_id = (int)($data['event_id'] ?? 0);
$action   = $data['action'] ?? '';
$note     = $data['note'] ?? '';

if (!$event_id || !in_array($action, ['approved', 'rejected'])) {
    echo json_encode(['success' => false, 'message' => '參數錯誤']);
    exit;
}

$status = $action;
$stmt = $conn->prepare("UPDATE events SET status = ?, review_note = ?, reviewed_at = NOW() WHERE event_id = ?");
$stmt->bind_param("ssi", $status, $note, $event_id);

if (!$stmt->execute()) {
    echo json_encode(['success' => false, 'message' => '資料庫錯誤']);
    exit;
}
$stmt->close();

// 寄信通知申請人
try {
    require_once '../includes/mailer.php';

    $info_stmt = $conn->prepare(
        "SELECT e.event_name, u.email, u.name
         FROM events e
         JOIN users u ON e.user_id = u.user_id
         WHERE e.event_id = ?"
    );
    $info_stmt->bind_param("i", $event_id);
    $info_stmt->execute();
    $info = $info_stmt->get_result()->fetch_assoc();
    $info_stmt->close();

    if ($info && !empty($info['email'])) {
        sendApplicationReviewedMail(
            $info['email'],
            $info['name'] ?? '申請人',
            ['event_id' => $event_id, 'event_name' => $info['event_name']],
            $action,
            $note
        );
    }
} catch (\Throwable $mailEx) {
    // 寄信失敗不影響審核結果
}

echo json_encode(['success' => true, 'message' => '審核已保存']);
