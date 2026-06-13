<?php
use PHPUnit\Framework\TestCase;

class EquipmentRequestTest extends TestCase
{
    private $conn;
    private $userId      = 5;
    private $clubName    = 'D136';
    private $equipmentId = 1;
    private $spaceId     = 1;

    private $createdEventIds = [];

    protected function setUp(): void
    {
        $this->conn = $GLOBALS['conn'];

        $row = $this->conn->query("SELECT user_id FROM users ORDER BY user_id LIMIT 1")->fetch_assoc();
        if ($row) $this->userId = (int)$row['user_id'];

        $row2 = $this->conn->query(
            "SELECT equipment_id FROM equipment WHERE equipment_status = 'available' ORDER BY equipment_id LIMIT 1"
        )->fetch_assoc();
        if ($row2) $this->equipmentId = (int)$row2['equipment_id'];

        $row3 = $this->conn->query(
            "SELECT space_id FROM spaces WHERE space_status = 'available' ORDER BY space_id LIMIT 1"
        )->fetch_assoc();
        if ($row3) $this->spaceId = (int)$row3['space_id'];
    }

    protected function tearDown(): void
    {
        if ($this->createdEventIds) {
            $list = implode(',', array_map('intval', $this->createdEventIds));
            $this->conn->query("DELETE FROM equipment_borrow    WHERE event_id IN ($list)");
            $this->conn->query("DELETE FROM equipment_requests  WHERE parent_event_id IN ($list)");
            $this->conn->query("DELETE FROM reservations        WHERE event_id IN ($list)");
            $this->conn->query("DELETE FROM events              WHERE event_id IN ($list)");
        }
    }

    private function createParentEvent(): int
    {
        $start = date('Y-m-d H:i:s', strtotime('+3 days 10:00'));
        $end   = date('Y-m-d H:i:s', strtotime('+3 days 12:00'));
        $name  = 'PHPUnit追加器材測試';
        $stmt  = $this->conn->prepare(
            "INSERT INTO events (user_id, event_name, club_name, start_time, end_time,
             responsible_person, event_type, activity_scale, status)
             VALUES (?, ?, ?, ?, ?, '測試人員', '校內', '一般活動', 'approved')"
        );
        $stmt->bind_param('issss', $this->userId, $name, $this->clubName, $start, $end);
        $stmt->execute();
        $id = (int)$this->conn->insert_id;
        $stmt->close();
        $this->createdEventIds[] = $id;
        return $id;
    }

    private function createEquipmentRequest(int $eventId, string $status = 'pending'): int
    {
        $start = date('Y-m-d H:i:s', strtotime('+3 days 09:30'));
        $end   = date('Y-m-d H:i:s', strtotime('+3 days 16:30'));
        $stmt  = $this->conn->prepare(
            "INSERT INTO equipment_requests
                (parent_event_id, user_id, club_name, borrow_start, borrow_end, status)
             VALUES (?, ?, ?, ?, ?, ?)"
        );
        $stmt->bind_param('iissss', $eventId, $this->userId, $this->clubName, $start, $end, $status);
        $stmt->execute();
        $id = (int)$this->conn->insert_id;
        $stmt->close();
        return $id;
    }

    /** 追加申請寫入 equipment_requests 成功，狀態為 pending */
    public function testEquipmentRequestInsert(): void
    {
        $eventId = $this->createParentEvent();
        $reqId   = $this->createEquipmentRequest($eventId);

        $this->assertGreaterThan(0, $reqId);

        $row = $this->conn->query(
            "SELECT * FROM equipment_requests WHERE request_id = $reqId"
        )->fetch_assoc();

        $this->assertSame('pending', $row['status']);
        $this->assertSame($eventId, (int)$row['parent_event_id']);
        $this->assertSame($this->userId, (int)$row['user_id']);
        $this->assertSame($this->clubName, $row['club_name']);
    }

    /** equipment_borrow 透過 request_id 與追加申請正確關聯 */
    public function testEquipmentBorrowLinkedToRequest(): void
    {
        $eventId = $this->createParentEvent();
        $reqId   = $this->createEquipmentRequest($eventId);

        $start = date('Y-m-d H:i:s', strtotime('+3 days 09:30'));
        $end   = date('Y-m-d H:i:s', strtotime('+3 days 16:30'));

        $stmt = $this->conn->prepare(
            "INSERT INTO equipment_borrow
                (event_id, equipment_id, quantity, borrow_start, borrow_end, request_id)
             VALUES (?, ?, 1, ?, ?, ?)"
        );
        $stmt->bind_param('iissi', $eventId, $this->equipmentId, $start, $end, $reqId);
        $result = $stmt->execute();
        $stmt->close();

        $this->assertTrue($result, 'equipment_borrow 含 request_id 應成功 INSERT');

        $row = $this->conn->query(
            "SELECT request_id FROM equipment_borrow
             WHERE event_id = $eventId AND equipment_id = {$this->equipmentId}
             AND request_id = $reqId LIMIT 1"
        )->fetch_assoc();

        $this->assertNotNull($row);
        $this->assertSame($reqId, (int)$row['request_id']);
    }

    /** 審核核准：status → approved，reviewed_at 與 reviewed_by 填寫 */
    public function testEquipmentRequestApproval(): void
    {
        $eventId = $this->createParentEvent();
        $reqId   = $this->createEquipmentRequest($eventId);

        $adminRow = $this->conn->query("SELECT user_id FROM users WHERE role='admin' LIMIT 1")->fetch_assoc();
        $adminId  = (int)($adminRow['user_id'] ?? $this->userId);

        $stmt = $this->conn->prepare(
            "UPDATE equipment_requests
             SET status = 'approved', reviewed_at = NOW(), reviewed_by = ?
             WHERE request_id = ?"
        );
        $stmt->bind_param('ii', $adminId, $reqId);
        $stmt->execute();
        $stmt->close();

        $row = $this->conn->query(
            "SELECT status, reviewed_at, reviewed_by FROM equipment_requests WHERE request_id = $reqId"
        )->fetch_assoc();

        $this->assertSame('approved', $row['status']);
        $this->assertNotNull($row['reviewed_at']);
        $this->assertSame($adminId, (int)$row['reviewed_by']);
    }

    /** 審核駁回：status → rejected，review_note 正確儲存 */
    public function testEquipmentRequestRejection(): void
    {
        $eventId = $this->createParentEvent();
        $reqId   = $this->createEquipmentRequest($eventId);
        $note    = '器材庫存不足，請減少申請數量';

        $stmt = $this->conn->prepare(
            "UPDATE equipment_requests
             SET status = 'rejected', review_note = ?, reviewed_at = NOW()
             WHERE request_id = ?"
        );
        $stmt->bind_param('si', $note, $reqId);
        $stmt->execute();
        $stmt->close();

        $row = $this->conn->query(
            "SELECT status, review_note FROM equipment_requests WHERE request_id = $reqId"
        )->fetch_assoc();

        $this->assertSame('rejected', $row['status']);
        $this->assertSame($note, $row['review_note']);
    }

    /** 追加申請可被取消：status → cancelled */
    public function testEquipmentRequestCancellation(): void
    {
        $eventId = $this->createParentEvent();
        $reqId   = $this->createEquipmentRequest($eventId);

        $stmt = $this->conn->prepare(
            "UPDATE equipment_requests SET status = 'cancelled' WHERE request_id = ?"
        );
        $stmt->bind_param('i', $reqId);
        $stmt->execute();
        $stmt->close();

        $row = $this->conn->query(
            "SELECT status FROM equipment_requests WHERE request_id = $reqId"
        )->fetch_assoc();

        $this->assertSame('cancelled', $row['status']);
    }

    /** 多場次個別追加：equipment_borrow 透過 reservation_id 指定場次 */
    public function testSessionSpecificEquipmentBorrow(): void
    {
        $eventId = $this->createParentEvent();
        $reqId   = $this->createEquipmentRequest($eventId);

        $start = date('Y-m-d H:i:s', strtotime('+3 days 10:00'));
        $end   = date('Y-m-d H:i:s', strtotime('+3 days 12:00'));

        $stmt = $this->conn->prepare(
            "INSERT INTO reservations (event_id, space_id, start_time, end_time) VALUES (?, ?, ?, ?)"
        );
        $stmt->bind_param('iiss', $eventId, $this->spaceId, $start, $end);
        $stmt->execute();
        $reservationId = (int)$this->conn->insert_id;
        $stmt->close();

        $borrowStart = date('Y-m-d H:i:s', strtotime('+3 days 09:30'));
        $borrowEnd   = date('Y-m-d H:i:s', strtotime('+3 days 16:30'));

        $stmt2 = $this->conn->prepare(
            "INSERT INTO equipment_borrow
                (event_id, equipment_id, quantity, borrow_start, borrow_end, reservation_id, request_id)
             VALUES (?, ?, 2, ?, ?, ?, ?)"
        );
        $stmt2->bind_param('iissii', $eventId, $this->equipmentId, $borrowStart, $borrowEnd, $reservationId, $reqId);
        $result = $stmt2->execute();
        $stmt2->close();

        $this->assertTrue($result, '指定場次的追加器材應成功插入');

        $row = $this->conn->query(
            "SELECT reservation_id, request_id FROM equipment_borrow
             WHERE event_id = $eventId AND reservation_id = $reservationId LIMIT 1"
        )->fetch_assoc();

        $this->assertNotNull($row);
        $this->assertSame($reservationId, (int)$row['reservation_id']);
        $this->assertSame($reqId, (int)$row['request_id']);
    }

    /** 借用時段 09:30–16:30 的業務邏輯驗證 */
    public function testBorrowTimeWindowValidation(): void
    {
        $isValid = function (string $startTime, string $endTime): bool {
            return $startTime >= '09:30' && $endTime <= '16:30' && $startTime < $endTime;
        };

        $this->assertTrue($isValid('09:30', '16:30'), '09:30–16:30 應為合法借用時段');
        $this->assertTrue($isValid('10:00', '14:00'), '10:00–14:00 應為合法借用時段');
        $this->assertFalse($isValid('08:00', '12:00'), '08:00 開始不合法');
        $this->assertFalse($isValid('10:00', '18:00'), '18:00 結束不合法');
        $this->assertFalse($isValid('12:00', '10:00'), '結束早於開始不合法');
    }

    /** 查詢指定時段器材可用數量（總量 - 已借用量） */
    public function testEquipmentAvailabilityQuery(): void
    {
        $equipRow = $this->conn->query(
            "SELECT total_quantity FROM equipment WHERE equipment_id = {$this->equipmentId}"
        )->fetch_assoc();
        $total = (int)($equipRow['total_quantity'] ?? 0);

        $start = date('Y-m-d H:i:s', strtotime('+3 days 09:30'));
        $end   = date('Y-m-d H:i:s', strtotime('+3 days 16:30'));

        $stmt = $this->conn->prepare(
            "SELECT COALESCE(SUM(eb.quantity), 0) AS borrowed
             FROM equipment_borrow eb
             JOIN events e ON eb.event_id = e.event_id
             WHERE eb.equipment_id = ?
               AND e.status = 'approved'
               AND eb.borrow_start < ?
               AND eb.borrow_end   > ?"
        );
        $stmt->bind_param('iss', $this->equipmentId, $end, $start);
        $stmt->execute();
        $borrowed  = (int)$stmt->get_result()->fetch_assoc()['borrowed'];
        $stmt->close();

        $available = $total - $borrowed;

        $this->assertGreaterThanOrEqual(0, $available, '可用數量不應為負');
        $this->assertLessThanOrEqual($total, $borrowed, '已借用量不應超過總量');
    }

    /** 追加申請透過 JOIN 可查詢到關聯的活動名稱 */
    public function testEquipmentRequestJoinsParentEvent(): void
    {
        $eventId = $this->createParentEvent();
        $reqId   = $this->createEquipmentRequest($eventId);

        $stmt = $this->conn->prepare(
            "SELECT er.request_id, e.event_name, e.status AS event_status
             FROM equipment_requests er
             JOIN events e ON er.parent_event_id = e.event_id
             WHERE er.request_id = ?"
        );
        $stmt->bind_param('i', $reqId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        $this->assertNotNull($row);
        $this->assertSame($reqId, (int)$row['request_id']);
        $this->assertStringContainsString('PHPUnit', $row['event_name']);
    }

    /** equipment_requests 表欄位完整性驗證 */
    public function testEquipmentRequestsTableStructure(): void
    {
        $cols = $this->conn->query("SHOW COLUMNS FROM equipment_requests");
        $colNames = [];
        while ($row = $cols->fetch_assoc()) {
            $colNames[] = $row['Field'];
        }

        foreach (['request_id', 'parent_event_id', 'user_id', 'club_name',
                  'borrow_start', 'borrow_end', 'status', 'review_note',
                  'reviewed_at', 'reviewed_by', 'created_at'] as $col) {
            $this->assertContains($col, $colNames, "equipment_requests 應有 $col 欄位");
        }
    }
}
