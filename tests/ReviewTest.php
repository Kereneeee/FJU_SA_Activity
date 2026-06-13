<?php
use PHPUnit\Framework\TestCase;

class ReviewTest extends TestCase
{
    private $conn;
    private $userId   = 5;
    private $adminId  = 1;
    private $clubName = 'D136';

    private $createdEventIds = [];

    protected function setUp(): void
    {
        $this->conn = $GLOBALS['conn'];

        $row = $this->conn->query("SELECT user_id FROM users ORDER BY user_id LIMIT 1")->fetch_assoc();
        if ($row) $this->userId = (int)$row['user_id'];

        $adminRow = $this->conn->query("SELECT user_id FROM users WHERE role='admin' LIMIT 1")->fetch_assoc();
        $this->adminId = (int)($adminRow['user_id'] ?? $this->userId);
    }

    protected function tearDown(): void
    {
        if ($this->createdEventIds) {
            $list = implode(',', array_map('intval', $this->createdEventIds));
            $this->conn->query("DELETE FROM equipment_borrow   WHERE event_id IN ($list)");
            $this->conn->query("DELETE FROM equipment_requests WHERE parent_event_id IN ($list)");
            $this->conn->query("DELETE FROM reservations       WHERE event_id IN ($list)");
            $this->conn->query("DELETE FROM events             WHERE event_id IN ($list)");
        }
    }

    private function createPendingEvent(): int
    {
        $start = date('Y-m-d H:i:s', strtotime('+10 days 10:00'));
        $end   = date('Y-m-d H:i:s', strtotime('+10 days 12:00'));
        $name  = 'PHPUnit審核測試活動';
        $stmt  = $this->conn->prepare(
            "INSERT INTO events (user_id, event_name, club_name, start_time, end_time,
             responsible_person, event_type, activity_scale, status)
             VALUES (?, ?, ?, ?, ?, '測試人員', '校內', '一般活動', 'pending')"
        );
        $stmt->bind_param('issss', $this->userId, $name, $this->clubName, $start, $end);
        $stmt->execute();
        $id = (int)$this->conn->insert_id;
        $stmt->close();
        $this->createdEventIds[] = $id;
        return $id;
    }

    private function createPendingEquipmentRequest(int $eventId): int
    {
        $start = date('Y-m-d H:i:s', strtotime('+10 days 09:30'));
        $end   = date('Y-m-d H:i:s', strtotime('+10 days 16:30'));
        $stmt  = $this->conn->prepare(
            "INSERT INTO equipment_requests
                (parent_event_id, user_id, club_name, borrow_start, borrow_end, status)
             VALUES (?, ?, ?, ?, ?, 'pending')"
        );
        $stmt->bind_param('iisss', $eventId, $this->userId, $this->clubName, $start, $end);
        $stmt->execute();
        $id = (int)$this->conn->insert_id;
        $stmt->close();
        return $id;
    }

    /** 審核核准活動：status → approved，reviewed_at 與 reviewed_by 填寫 */
    public function testEventApproval(): void
    {
        $eventId = $this->createPendingEvent();

        $stmt = $this->conn->prepare(
            "UPDATE events SET status = 'approved', reviewed_at = NOW(), reviewed_by = ?
             WHERE event_id = ?"
        );
        $stmt->bind_param('ii', $this->adminId, $eventId);
        $stmt->execute();
        $stmt->close();

        $row = $this->conn->query(
            "SELECT status, reviewed_at, reviewed_by FROM events WHERE event_id = $eventId"
        )->fetch_assoc();

        $this->assertSame('approved', $row['status']);
        $this->assertNotNull($row['reviewed_at'], 'reviewed_at 應在審核後填寫');
        $this->assertSame($this->adminId, (int)$row['reviewed_by'], 'reviewed_by 應記錄審核者 ID');
    }

    /** 審核退件活動：status → rejected，review_note 儲存 */
    public function testEventRejection(): void
    {
        $eventId = $this->createPendingEvent();
        $note    = '活動說明不完整，請補充詳細資訊後重新申請';

        $stmt = $this->conn->prepare(
            "UPDATE events SET status = 'rejected', review_note = ?, reviewed_at = NOW(), reviewed_by = ?
             WHERE event_id = ?"
        );
        $stmt->bind_param('sii', $note, $this->adminId, $eventId);
        $stmt->execute();
        $stmt->close();

        $row = $this->conn->query(
            "SELECT status, review_note FROM events WHERE event_id = $eventId"
        )->fetch_assoc();

        $this->assertSame('rejected', $row['status']);
        $this->assertSame($note, $row['review_note']);
    }

    /** 審核操作不影響其他欄位（活動名稱、社團等應保持不變） */
    public function testReviewActionPreservesEventData(): void
    {
        $eventId = $this->createPendingEvent();

        $before = $this->conn->query(
            "SELECT event_name, club_name, start_time, end_time FROM events WHERE event_id = $eventId"
        )->fetch_assoc();

        $stmt = $this->conn->prepare(
            "UPDATE events SET status = 'approved', reviewed_at = NOW(), reviewed_by = ?
             WHERE event_id = ?"
        );
        $stmt->bind_param('ii', $this->adminId, $eventId);
        $stmt->execute();
        $stmt->close();

        $after = $this->conn->query(
            "SELECT event_name, club_name, start_time, end_time FROM events WHERE event_id = $eventId"
        )->fetch_assoc();

        $this->assertSame($before['event_name'], $after['event_name'],  'event_name 不應改變');
        $this->assertSame($before['club_name'],  $after['club_name'],   'club_name 不應改變');
        $this->assertSame($before['start_time'], $after['start_time'],  'start_time 不應改變');
        $this->assertSame($before['end_time'],   $after['end_time'],    'end_time 不應改變');
    }

    /** pending 活動列表查詢應包含新建的活動 */
    public function testPendingEventsListQuery(): void
    {
        $eventId = $this->createPendingEvent();

        $result = $this->conn->query(
            "SELECT event_id FROM events WHERE status = 'pending' ORDER BY created_at DESC"
        );
        $ids = [];
        while ($row = $result->fetch_assoc()) {
            $ids[] = (int)$row['event_id'];
        }

        $this->assertContains($eventId, $ids, '新建的 pending 活動應出現在待審清單');
    }

    /** 追加器材申請審核：核准後 status → approved */
    public function testEquipmentRequestApprovalViaReview(): void
    {
        $eventId = $this->createPendingEvent();
        $reqId   = $this->createPendingEquipmentRequest($eventId);

        $stmt = $this->conn->prepare(
            "UPDATE equipment_requests
             SET status = 'approved', reviewed_at = NOW(), reviewed_by = ?
             WHERE request_id = ?"
        );
        $stmt->bind_param('ii', $this->adminId, $reqId);
        $stmt->execute();
        $stmt->close();

        $row = $this->conn->query(
            "SELECT status, reviewed_at, reviewed_by FROM equipment_requests WHERE request_id = $reqId"
        )->fetch_assoc();

        $this->assertSame('approved', $row['status']);
        $this->assertNotNull($row['reviewed_at']);
        $this->assertSame($this->adminId, (int)$row['reviewed_by']);
    }

    /** 追加器材申請審核：駁回後 status → rejected，review_note 儲存 */
    public function testEquipmentRequestRejectionViaReview(): void
    {
        $eventId = $this->createPendingEvent();
        $reqId   = $this->createPendingEquipmentRequest($eventId);
        $note    = '借用時段與其他活動衝突';

        $stmt = $this->conn->prepare(
            "UPDATE equipment_requests
             SET status = 'rejected', review_note = ?, reviewed_at = NOW(), reviewed_by = ?
             WHERE request_id = ?"
        );
        $stmt->bind_param('sii', $note, $this->adminId, $reqId);
        $stmt->execute();
        $stmt->close();

        $row = $this->conn->query(
            "SELECT status, review_note FROM equipment_requests WHERE request_id = $reqId"
        )->fetch_assoc();

        $this->assertSame('rejected', $row['status']);
        $this->assertSame($note, $row['review_note']);
    }

    /** 審核端可同時列出一般活動與追加器材申請的 pending 清單 */
    public function testCombinedPendingListQuery(): void
    {
        $eventId = $this->createPendingEvent();
        $this->createPendingEquipmentRequest($eventId);

        $eventCnt = (int)$this->conn->query(
            "SELECT COUNT(*) AS cnt FROM events WHERE status = 'pending'"
        )->fetch_assoc()['cnt'];

        $reqCnt = (int)$this->conn->query(
            "SELECT COUNT(*) AS cnt FROM equipment_requests WHERE status = 'pending'"
        )->fetch_assoc()['cnt'];

        $this->assertGreaterThan(0, $eventCnt, '待審活動清單應有資料');
        $this->assertGreaterThan(0, $reqCnt,   '待審追加器材清單應有資料');
    }
}
