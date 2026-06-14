<?php
require_once '../DB/db_config.php';
require_once '../includes/functions.php';

checkLogin();
checkAdmin();

header('Content-Type: application/json; charset=utf-8');

function sendJson(array $payload): void
{
    echo json_encode($payload, JSON_UNESCAPED_UNICODE);
    exit;
}

function finishReviewResponse(array $payload, callable $afterResponse): void
{
    $json = json_encode($payload, JSON_UNESCAPED_UNICODE);

    if (!headers_sent()) {
        header('Content-Length: ' . strlen($json));
        header('Connection: close');
        if (function_exists('apache_setenv')) {
            apache_setenv('no-gzip', '1');
        }
    }

    echo $json;

    if (function_exists('fastcgi_finish_request')) {
        session_write_close();
        fastcgi_finish_request();
        $afterResponse();
        exit;
    }

    // AppServ/Apache on Windows often has no FastCGI finish hook. Flush the
    // fixed-length JSON response before continuing with SMTP best-effort.
    session_write_close();
    ignore_user_abort(true);
    while (ob_get_level() > 0) {
        ob_end_flush();
    }
    flush();
    $afterResponse();
    exit;
}

function queueReviewMail(mysqli $conn, int $id, string $action, string $note, bool $isEquipmentRequest): void
{
    try {
        require_once '../includes/mailer.php';

        if ($isEquipmentRequest) {
            $stmt = $conn->prepare(
                "SELECT pe.event_name, u.email, u.name
                 FROM equipment_requests er
                 JOIN events pe ON er.parent_event_id = pe.event_id
                 JOIN users u ON er.user_id = u.user_id
                 WHERE er.request_id = ?"
            );
        } else {
            $stmt = $conn->prepare(
                "SELECT e.event_name, u.email, u.name
                 FROM events e
                 JOIN users u ON e.user_id = u.user_id
                 WHERE e.event_id = ?"
            );
        }

        $stmt->bind_param("i", $id);
        $stmt->execute();
        $info = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if (!$info || empty($info['email'])) {
            return;
        }

        sendApplicationReviewedMail(
            $info['email'],
            $info['name'] ?? '申請人',
            [
                'event_id' => $id,
                'event_name' => $info['event_name'] . ($isEquipmentRequest ? '（追加器材申請）' : ''),
            ],
            $action,
            $note
        );
    } catch (Throwable $e) {
        error_log('[review_action mail] ' . $e->getMessage());
    }
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    sendJson(['success' => false, 'message' => '請使用 POST 請求']);
}

$data = json_decode(file_get_contents('php://input'), true);
if (!is_array($data)) {
    sendJson(['success' => false, 'message' => '請求格式錯誤']);
}

$event_id = (int)($data['event_id'] ?? 0);
$request_id = (int)($data['request_id'] ?? 0);
$action = $data['action'] ?? '';
$note = trim($data['note'] ?? '');

if (!in_array($action, ['approved', 'rejected'], true)) {
    sendJson(['success' => false, 'message' => '審核動作無效']);
}

$reviewer_id = (int)($_SESSION['user_id'] ?? 0);

if ($request_id > 0) {
    $stmt = $conn->prepare(
        "UPDATE equipment_requests
         SET status = ?, review_note = ?, reviewed_at = NOW(), reviewed_by = ?
         WHERE request_id = ?"
    );
    $stmt->bind_param("ssii", $action, $note, $reviewer_id, $request_id);

    if (!$stmt->execute()) {
        sendJson(['success' => false, 'message' => '資料庫更新失敗']);
    }
    $stmt->close();

    finishReviewResponse(
        ['success' => true, 'message' => '審核已完成'],
        function () use ($conn, $request_id, $action, $note) {
            queueReviewMail($conn, $request_id, $action, $note, true);
        }
    );
}

if ($event_id <= 0) {
    sendJson(['success' => false, 'message' => '申請編號無效']);
}

$stmt = $conn->prepare(
    "UPDATE events
     SET status = ?, review_note = ?, reviewed_at = NOW(), reviewed_by = ?
     WHERE event_id = ?"
);
$stmt->bind_param("ssii", $action, $note, $reviewer_id, $event_id);

if (!$stmt->execute()) {
    sendJson(['success' => false, 'message' => '資料庫更新失敗']);
}
$stmt->close();

finishReviewResponse(
    ['success' => true, 'message' => '審核已完成'],
    function () use ($conn, $event_id, $action, $note) {
        queueReviewMail($conn, $event_id, $action, $note, false);
    }
);
