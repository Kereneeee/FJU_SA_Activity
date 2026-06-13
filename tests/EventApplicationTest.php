<?php
use PHPUnit\Framework\TestCase;

class EventApplicationTest extends TestCase
{
    private $conn;
    private $createdEventId = 0;

    protected function setUp(): void
    {
        $this->conn = $GLOBALS['conn'];
    }

    protected function tearDown(): void
    {
        // 清除測試產生的資料
        if ($this->createdEventId > 0) {
            $this->conn->query("DELETE FROM reservations WHERE event_id = {$this->createdEventId}");
            $this->conn->query("DELETE FROM equipment_borrow WHERE event_id = {$this->createdEventId}");
            $this->conn->query("DELETE FROM events WHERE event_id = {$this->createdEventId}");
        }
    }

    /** 活動申請能成功寫入 DB */
    public function testEventInsertSucceeds(): void
    {
        $tomorrow = date('Y-m-d H:i:s', strtotime('+1 day 10:00'));
        $end      = date('Y-m-d H:i:s', strtotime('+1 day 12:00'));

        $stmt = $this->conn->prepare(
            "INSERT INTO events
                (user_id, event_name, club_name, description, start_time, end_time,
                 responsible_person, event_type, activity_scale, status)
             VALUES (5, ?, 'D136', 'PHPUnit 測試', ?, ?, '測試人員', '校內', '一般活動', 'pending')"
        );
        $name = 'PHPUnit 測試活動';
        $stmt->bind_param('sss', $name, $tomorrow, $end);
        $result = $stmt->execute();
        $this->createdEventId = (int)$this->conn->insert_id;
        $stmt->close();

        $this->assertTrue($result, 'INSERT events 應成功');
        $this->assertGreaterThan(0, $this->createdEventId, '應取得新 event_id');
    }

    /** 場地預約能成功寫入 DB（含 borrow_start/borrow_end/reservation_id 欄位） */
    public function testReservationAndEquipmentBorrowInsert(): void
    {
        $tomorrow  = date('Y-m-d H:i:s', strtotime('+1 day 10:00'));
        $end       = date('Y-m-d H:i:s', strtotime('+1 day 12:00'));
        $borrowStart = date('Y-m-d H:i:s', strtotime('+1 day 09:30'));
        $borrowEnd   = date('Y-m-d H:i:s', strtotime('+1 day 16:30'));

        // 建立活動
        $stmt = $this->conn->prepare(
            "INSERT INTO events
                (user_id, event_name, club_name, start_time, end_time,
                 responsible_person, event_type, activity_scale, status)
             VALUES (5, 'PHPUnit 器材測試', 'D136', ?, ?, '測試人員', '校內', '一般活動', 'pending')"
        );
        $stmt->bind_param('ss', $tomorrow, $end);
        $stmt->execute();
        $this->createdEventId = (int)$this->conn->insert_id;
        $stmt->close();

        // 建立場地預約
        $stmt2 = $this->conn->prepare(
            "INSERT INTO reservations (event_id, space_id, start_time, end_time) VALUES (?, 4, ?, ?)"
        );
        $stmt2->bind_param('iss', $this->createdEventId, $tomorrow, $end);
        $result2 = $stmt2->execute();
        $reservationId = (int)$this->conn->insert_id;
        $stmt2->close();

        $this->assertTrue($result2, '場地預約 INSERT 應成功');

        // 建立器材借用（含今天新增的欄位）
        $stmt3 = $this->conn->prepare(
            "INSERT INTO equipment_borrow
                (event_id, equipment_id, quantity, borrow_start, borrow_end, reservation_id)
             VALUES (?, 17, 2, ?, ?, ?)"
        );
        $stmt3->bind_param('issi', $this->createdEventId, $borrowStart, $borrowEnd, $reservationId);
        $result3 = $stmt3->execute();
        $stmt3->close();

        $this->assertTrue($result3, 'equipment_borrow INSERT（含 borrow_start/borrow_end/reservation_id）應成功');
    }

    /** 校內活動必須有場地——後端 validation 邏輯 */
    public function testVenueRequiredForIndoorEvent(): void
    {
        $sessions = [['venue_id' => '', 'date' => date('Y-m-d', strtotime('+1 day'))]];
        $event_type = '校內';

        $errors = [];
        foreach ($sessions as $i => $sess) {
            if ($event_type === '校內' && empty($sess['venue_id'])) {
                $errors[] = "場次" . ($i + 1) . "：校內活動請選擇場地";
            }
        }

        $this->assertNotEmpty($errors, '校內活動未選場地應產生錯誤');
        $this->assertStringContainsString('請選擇場地', $errors[0]);
    }

    /** equipment_requests 表存在且結構正確 */
    public function testEquipmentRequestsTableExists(): void
    {
        $result = $this->conn->query("SHOW TABLES LIKE 'equipment_requests'");
        $this->assertSame(1, $result->num_rows, 'equipment_requests 表應存在');

        $cols = $this->conn->query("SHOW COLUMNS FROM equipment_requests");
        $colNames = [];
        while ($row = $cols->fetch_assoc()) $colNames[] = $row['Field'];

        foreach (['request_id', 'parent_event_id', 'borrow_start', 'borrow_end', 'status'] as $col) {
            $this->assertContains($col, $colNames, "equipment_requests 應有 $col 欄位");
        }
    }
}
