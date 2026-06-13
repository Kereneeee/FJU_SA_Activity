<?php
use PHPUnit\Framework\TestCase;

class MultiSessionTest extends TestCase
{
    private $conn;
    private $userId      = 5;
    private $clubName    = 'D136';
    private $spaceId     = 1;
    private $equipmentId = 1;

    private $createdEventIds = [];

    protected function setUp(): void
    {
        $this->conn = $GLOBALS['conn'];

        $row = $this->conn->query("SELECT user_id FROM users ORDER BY user_id LIMIT 1")->fetch_assoc();
        if ($row) $this->userId = (int)$row['user_id'];

        $row2 = $this->conn->query(
            "SELECT space_id FROM spaces WHERE space_status = 'available' ORDER BY space_id LIMIT 1"
        )->fetch_assoc();
        if ($row2) $this->spaceId = (int)$row2['space_id'];

        $row3 = $this->conn->query(
            "SELECT equipment_id FROM equipment WHERE equipment_status = 'available' ORDER BY equipment_id LIMIT 1"
        )->fetch_assoc();
        if ($row3) $this->equipmentId = (int)$row3['equipment_id'];
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

    private function createEvent(string $start, string $end): int
    {
        $name = 'PHPUnit多場次測試';
        $stmt = $this->conn->prepare(
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

    private function createReservation(int $eventId, string $start, string $end, bool $preliminary = false): int
    {
        $isPreliminary = $preliminary ? 1 : 0;
        $stmt = $this->conn->prepare(
            "INSERT INTO reservations (event_id, space_id, start_time, end_time, is_field_coordination_preliminary)
             VALUES (?, ?, ?, ?, ?)"
        );
        $stmt->bind_param('iissi', $eventId, $this->spaceId, $start, $end, $isPreliminary);
        $stmt->execute();
        $id = (int)$this->conn->insert_id;
        $stmt->close();
        return $id;
    }

    /** 多場次活動：每個場次各建立一筆 reservation */
    public function testMultiSessionReservationsInsert(): void
    {
        $day1Start = date('Y-m-d H:i:s', strtotime('+4 days 10:00'));
        $day1End   = date('Y-m-d H:i:s', strtotime('+4 days 12:00'));
        $day2Start = date('Y-m-d H:i:s', strtotime('+5 days 14:00'));
        $day2End   = date('Y-m-d H:i:s', strtotime('+5 days 16:00'));

        $eventId = $this->createEvent($day1Start, $day2End);
        $res1    = $this->createReservation($eventId, $day1Start, $day1End);
        $res2    = $this->createReservation($eventId, $day2Start, $day2End);

        $this->assertGreaterThan(0, $res1);
        $this->assertGreaterThan(0, $res2);
        $this->assertNotSame($res1, $res2, '兩個場次應有不同的 reservation_id');

        $cnt = (int)$this->conn->query(
            "SELECT COUNT(*) AS cnt FROM reservations WHERE event_id = $eventId"
        )->fetch_assoc()['cnt'];

        $this->assertSame(2, $cnt, '多場次活動應有 2 筆 reservation');
    }

    /** events.start_time 應等於所有場次中最早的開始時間 */
    public function testEventStartTimeCoversAllSessions(): void
    {
        $session1Start = date('Y-m-d H:i:s', strtotime('+4 days 10:00'));
        $session1End   = date('Y-m-d H:i:s', strtotime('+4 days 12:00'));
        $session2Start = date('Y-m-d H:i:s', strtotime('+5 days 08:00'));
        $session2End   = date('Y-m-d H:i:s', strtotime('+5 days 16:00'));

        $eventStart = min($session1Start, $session2Start);
        $eventEnd   = max($session1End,   $session2End);

        $eventId = $this->createEvent($eventStart, $eventEnd);
        $this->createReservation($eventId, $session1Start, $session1End);
        $this->createReservation($eventId, $session2Start, $session2End);

        $stmt = $this->conn->prepare(
            "SELECT e.start_time, e.end_time,
                    MIN(r.start_time) AS res_min_start,
                    MAX(r.end_time)   AS res_max_end
             FROM events e
             JOIN reservations r ON r.event_id = e.event_id
             WHERE e.event_id = ?
             GROUP BY e.event_id"
        );
        $stmt->bind_param('i', $eventId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        $this->assertNotNull($row);
        $this->assertSame($row['start_time'], $row['res_min_start'],
            'events.start_time 應等於所有場次中最早時間');
        $this->assertSame($row['end_time'], $row['res_max_end'],
            'events.end_time 應等於所有場次中最晚時間');
    }

    /** 每個場次可獨立關聯自己的 equipment_borrow */
    public function testEachSessionHasOwnEquipmentBorrow(): void
    {
        $start1  = date('Y-m-d H:i:s', strtotime('+4 days 10:00'));
        $end1    = date('Y-m-d H:i:s', strtotime('+4 days 12:00'));
        $start2  = date('Y-m-d H:i:s', strtotime('+5 days 14:00'));
        $end2    = date('Y-m-d H:i:s', strtotime('+5 days 16:00'));
        $eventId = $this->createEvent($start1, $end2);
        $res1    = $this->createReservation($eventId, $start1, $end1);
        $res2    = $this->createReservation($eventId, $start2, $end2);

        $borrowStart = date('Y-m-d H:i:s', strtotime('+4 days 09:30'));
        $borrowEnd   = date('Y-m-d H:i:s', strtotime('+4 days 16:30'));

        foreach ([$res1, $res2] as $resId) {
            $stmt = $this->conn->prepare(
                "INSERT INTO equipment_borrow
                    (event_id, equipment_id, quantity, borrow_start, borrow_end, reservation_id)
                 VALUES (?, ?, 1, ?, ?, ?)"
            );
            $stmt->bind_param('iissi', $eventId, $this->equipmentId, $borrowStart, $borrowEnd, $resId);
            $ok = $stmt->execute();
            $stmt->close();
            $this->assertTrue($ok, "場次 $resId 的器材記錄應成功插入");
        }

        $cnt = (int)$this->conn->query(
            "SELECT COUNT(DISTINCT reservation_id) AS cnt
             FROM equipment_borrow
             WHERE event_id = $eventId AND reservation_id IS NOT NULL"
        )->fetch_assoc()['cnt'];

        $this->assertSame(2, $cnt, '應有 2 個場次各自關聯器材');
    }

    /** 場地衝突偵測：同一場地同時段不能重複預約 */
    public function testVenueConflictDetection(): void
    {
        $start   = date('Y-m-d H:i:s', strtotime('+6 days 10:00'));
        $end     = date('Y-m-d H:i:s', strtotime('+6 days 12:00'));
        $event1  = $this->createEvent($start, $end);
        $this->createReservation($event1, $start, $end);

        $event2 = $this->createEvent($start, $end);

        // 重疊時段（10:00–12:00 與 11:00–13:00 有重疊）
        $overlapStart = date('Y-m-d H:i:s', strtotime('+6 days 11:00'));
        $overlapEnd   = date('Y-m-d H:i:s', strtotime('+6 days 13:00'));

        $stmt = $this->conn->prepare(
            "SELECT COUNT(*) AS cnt FROM reservations
             WHERE space_id = ?
               AND event_id != ?
               AND NOT (end_time <= ? OR start_time >= ?)"
        );
        $stmt->bind_param('iiss', $this->spaceId, $event2, $overlapStart, $overlapEnd);
        $stmt->execute();
        $cnt = (int)$stmt->get_result()->fetch_assoc()['cnt'];
        $stmt->close();

        $this->assertGreaterThan(0, $cnt, '重疊時段應偵測到衝突');
    }

    /** 非衝突時段不應觸發衝突警告 */
    public function testNoConflictForNonOverlappingTimes(): void
    {
        $start   = date('Y-m-d H:i:s', strtotime('+7 days 10:00'));
        $end     = date('Y-m-d H:i:s', strtotime('+7 days 12:00'));
        $eventId = $this->createEvent($start, $end);
        $this->createReservation($eventId, $start, $end);

        $laterStart  = date('Y-m-d H:i:s', strtotime('+7 days 14:00'));
        $laterEnd    = date('Y-m-d H:i:s', strtotime('+7 days 16:00'));
        $newEventId  = $this->createEvent($laterStart, $laterEnd);

        $stmt = $this->conn->prepare(
            "SELECT COUNT(*) AS cnt FROM reservations
             WHERE space_id = ?
               AND event_id != ?
               AND NOT (end_time <= ? OR start_time >= ?)"
        );
        $stmt->bind_param('iiss', $this->spaceId, $newEventId, $laterStart, $laterEnd);
        $stmt->execute();
        $cnt = (int)$stmt->get_result()->fetch_assoc()['cnt'];
        $stmt->close();

        $this->assertSame(0, $cnt, '不重疊時段不應有衝突');
    }

    /** 場協暫定預約：is_field_coordination_preliminary = 1 */
    public function testPreliminaryReservationFlag(): void
    {
        $start   = date('Y-m-d H:i:s', strtotime('+8 days 10:00'));
        $end     = date('Y-m-d H:i:s', strtotime('+8 days 12:00'));
        $eventId = $this->createEvent($start, $end);
        $resId   = $this->createReservation($eventId, $start, $end, true);

        $row = $this->conn->query(
            "SELECT is_field_coordination_preliminary FROM reservations WHERE reservation_id = $resId"
        )->fetch_assoc();

        $this->assertSame(1, (int)$row['is_field_coordination_preliminary'],
            '場協暫定預約 flag 應為 1');
    }

    /** 查詢活動所有場次並驗證總器材借用筆數 */
    public function testMultiSessionEquipmentBorrowCount(): void
    {
        $start1  = date('Y-m-d H:i:s', strtotime('+9 days 10:00'));
        $end1    = date('Y-m-d H:i:s', strtotime('+9 days 12:00'));
        $start2  = date('Y-m-d H:i:s', strtotime('+10 days 10:00'));
        $end2    = date('Y-m-d H:i:s', strtotime('+10 days 12:00'));
        $eventId = $this->createEvent($start1, $end2);
        $res1    = $this->createReservation($eventId, $start1, $end1);
        $res2    = $this->createReservation($eventId, $start2, $end2);

        $borrowStart = date('Y-m-d H:i:s', strtotime('+9 days 09:30'));
        $borrowEnd   = date('Y-m-d H:i:s', strtotime('+9 days 16:30'));

        foreach ([$res1, $res2] as $resId) {
            $stmt = $this->conn->prepare(
                "INSERT INTO equipment_borrow
                    (event_id, equipment_id, quantity, borrow_start, borrow_end, reservation_id)
                 VALUES (?, ?, 1, ?, ?, ?)"
            );
            $stmt->bind_param('iissi', $eventId, $this->equipmentId, $borrowStart, $borrowEnd, $resId);
            $stmt->execute();
            $stmt->close();
        }

        $cnt = (int)$this->conn->query(
            "SELECT COUNT(*) AS cnt FROM equipment_borrow WHERE event_id = $eventId"
        )->fetch_assoc()['cnt'];

        $this->assertSame(2, $cnt, '2 個場次應各有 1 筆器材借用記錄');
    }
}
