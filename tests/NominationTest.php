<?php
use PHPUnit\Framework\TestCase;

class NominationTest extends TestCase
{
    private $conn;
    private $nominatorId;
    private $nomineeId;
    private $clubId = 'D136';
    private $createdNominationIds = [];

    protected function setUp(): void
    {
        $this->conn = $GLOBALS['conn'];
        $result = $this->conn->query("SELECT user_id FROM users ORDER BY user_id LIMIT 2");
        $ids = [];
        while ($row = $result->fetch_assoc()) {
            $ids[] = (int)$row['user_id'];
        }
        $this->nominatorId = $ids[0] ?? 1;
        $this->nomineeId   = $ids[1] ?? 2;
    }

    protected function tearDown(): void
    {
        if ($this->createdNominationIds) {
            $list = implode(',', array_map('intval', $this->createdNominationIds));
            $this->conn->query("DELETE FROM officer_nominations WHERE nomination_id IN ($list)");
        }
    }

    private function insertNomination(string $status = 'pending', string $title = '測試幹部'): int
    {
        $stmt = $this->conn->prepare(
            "INSERT INTO officer_nominations
                (club_id, nominated_user_id, nominator_user_id, officer_title, status)
             VALUES (?, ?, ?, ?, ?)"
        );
        $stmt->bind_param('siiss', $this->clubId, $this->nomineeId, $this->nominatorId, $title, $status);
        $stmt->execute();
        $id = (int)$this->conn->insert_id;
        $stmt->close();
        $this->createdNominationIds[] = $id;
        return $id;
    }

    /** 提名寫入 DB 成功，預設狀態為 pending */
    public function testNominationInsertSucceeds(): void
    {
        $id = $this->insertNomination();
        $this->assertGreaterThan(0, $id);

        $row = $this->conn->query(
            "SELECT * FROM officer_nominations WHERE nomination_id = $id"
        )->fetch_assoc();

        $this->assertSame('pending', $row['status']);
        $this->assertSame($this->clubId, $row['club_id']);
        $this->assertSame($this->nomineeId, (int)$row['nominated_user_id']);
        $this->assertSame($this->nominatorId, (int)$row['nominator_user_id']);
    }

    /** 重複 pending 提名可被業務邏輯偵測 */
    public function testDuplicatePendingNominationDetected(): void
    {
        $this->insertNomination('pending');

        $stmt = $this->conn->prepare(
            "SELECT COUNT(*) AS cnt FROM officer_nominations
             WHERE nominated_user_id = ? AND club_id = ? AND status = 'pending'"
        );
        $stmt->bind_param('is', $this->nomineeId, $this->clubId);
        $stmt->execute();
        $cnt = (int)$stmt->get_result()->fetch_assoc()['cnt'];
        $stmt->close();

        $this->assertGreaterThan(0, $cnt, '重複 pending 提名應可被偵測到');
    }

    /** 審核核准：status → approved，reviewed_at 填寫 */
    public function testNominationApproval(): void
    {
        $id = $this->insertNomination('pending');

        $stmt = $this->conn->prepare(
            "UPDATE officer_nominations SET status = 'approved', reviewed_at = NOW() WHERE nomination_id = ?"
        );
        $stmt->bind_param('i', $id);
        $stmt->execute();
        $stmt->close();

        $row = $this->conn->query(
            "SELECT status, reviewed_at FROM officer_nominations WHERE nomination_id = $id"
        )->fetch_assoc();

        $this->assertSame('approved', $row['status']);
        $this->assertNotNull($row['reviewed_at']);
    }

    /** 審核駁回：status → rejected，review_note 正確儲存 */
    public function testNominationRejection(): void
    {
        $id   = $this->insertNomination('pending');
        $note = '申請資格不符合規定';

        $stmt = $this->conn->prepare(
            "UPDATE officer_nominations
             SET status = 'rejected', review_note = ?, reviewed_at = NOW()
             WHERE nomination_id = ?"
        );
        $stmt->bind_param('si', $note, $id);
        $stmt->execute();
        $stmt->close();

        $row = $this->conn->query(
            "SELECT status, review_note FROM officer_nominations WHERE nomination_id = $id"
        )->fetch_assoc();

        $this->assertSame('rejected', $row['status']);
        $this->assertSame($note, $row['review_note']);
    }

    /** 社長可撤回 pending 提名（DELETE WHERE status='pending'） */
    public function testNominationWithdrawal(): void
    {
        $id = $this->insertNomination('pending');

        $stmt = $this->conn->prepare(
            "DELETE FROM officer_nominations WHERE nomination_id = ? AND status = 'pending'"
        );
        $stmt->bind_param('i', $id);
        $stmt->execute();
        $affected = $stmt->affected_rows;
        $stmt->close();

        $this->assertSame(1, $affected, 'pending 提名應可被撤回');
        $this->createdNominationIds = array_diff($this->createdNominationIds, [$id]);
    }

    /** 已核准的提名無法用撤回語法刪除 */
    public function testCannotWithdrawApprovedNomination(): void
    {
        $id = $this->insertNomination('approved');

        $stmt = $this->conn->prepare(
            "DELETE FROM officer_nominations WHERE nomination_id = ? AND status = 'pending'"
        );
        $stmt->bind_param('i', $id);
        $stmt->execute();
        $affected = $stmt->affected_rows;
        $stmt->close();

        $this->assertSame(0, $affected, '已核准的提名不應可被撤回');
    }

    /** 查詢提名記錄時 JOIN users 取得被提名人姓名 */
    public function testNominationQueryJoinsNomineeInfo(): void
    {
        $id = $this->insertNomination('pending', '副社長');

        $stmt = $this->conn->prepare(
            "SELECT nom.nomination_id, nom.officer_title, nom.status, u.name AS nominee_name
             FROM officer_nominations nom
             JOIN users u ON nom.nominated_user_id = u.user_id
             WHERE nom.nomination_id = ?"
        );
        $stmt->bind_param('i', $id);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        $this->assertNotNull($row, 'JOIN 查詢應有結果');
        $this->assertSame('副社長', $row['officer_title']);
        $this->assertSame('pending', $row['status']);
        $this->assertArrayHasKey('nominee_name', $row);
        $this->assertNotEmpty($row['nominee_name']);
    }

    /** 核准後同步 club_members：新增記錄並設 is_officer=1 */
    public function testApprovedNominationSyncsNewClubMember(): void
    {
        $stmt = $this->conn->prepare(
            "SELECT COUNT(*) AS cnt FROM club_members WHERE user_id = ? AND club_id = ?"
        );
        $stmt->bind_param('is', $this->nomineeId, $this->clubId);
        $stmt->execute();
        $cnt = (int)$stmt->get_result()->fetch_assoc()['cnt'];
        $stmt->close();

        if ($cnt > 0) {
            $this->markTestSkipped('被提名人已是社團成員，略過 INSERT 路徑測試');
        }

        $title = 'PHPUnit測試幹部';
        $stmt2 = $this->conn->prepare(
            "INSERT INTO club_members (user_id, club_id, is_officer, officer_title, join_date, officer_confirmation_date)
             VALUES (?, ?, 1, ?, CURDATE(), CURDATE())"
        );
        $stmt2->bind_param('iss', $this->nomineeId, $this->clubId, $title);
        $stmt2->execute();
        $membershipId = (int)$this->conn->insert_id;
        $stmt2->close();

        $this->assertGreaterThan(0, $membershipId);

        $row = $this->conn->query(
            "SELECT is_officer, officer_title FROM club_members WHERE membership_id = $membershipId"
        )->fetch_assoc();

        $this->assertSame(1, (int)$row['is_officer'], '核准後 is_officer 應為 1');
        $this->assertSame($title, $row['officer_title']);

        $this->conn->query("DELETE FROM club_members WHERE membership_id = $membershipId");
    }

    /** 查詢指定社團所有 pending 提名 */
    public function testQueryPendingNominationsByClub(): void
    {
        $this->insertNomination('pending', '公關幹部');

        $stmt = $this->conn->prepare(
            "SELECT COUNT(*) AS cnt FROM officer_nominations
             WHERE club_id = ? AND status = 'pending'"
        );
        $stmt->bind_param('s', $this->clubId);
        $stmt->execute();
        $cnt = (int)$stmt->get_result()->fetch_assoc()['cnt'];
        $stmt->close();

        $this->assertGreaterThan(0, $cnt, '社團 pending 提名應可被查詢到');
    }

    /** officer_nominations 表欄位完整性驗證 */
    public function testNominationTableStructure(): void
    {
        $cols = $this->conn->query("SHOW COLUMNS FROM officer_nominations");
        $colNames = [];
        while ($row = $cols->fetch_assoc()) {
            $colNames[] = $row['Field'];
        }

        foreach (['nomination_id', 'club_id', 'nominated_user_id', 'nominator_user_id',
                  'officer_title', 'status', 'review_note', 'created_at', 'reviewed_at'] as $col) {
            $this->assertContains($col, $colNames, "officer_nominations 應有 $col 欄位");
        }
    }

    /** status 欄位只允許合法 enum 值 */
    public function testNominationStatusIsValidEnum(): void
    {
        $id  = $this->insertNomination('pending');
        $row = $this->conn->query(
            "SELECT status FROM officer_nominations WHERE nomination_id = $id"
        )->fetch_assoc();

        $this->assertContains($row['status'], ['pending', 'approved', 'rejected'],
            'status 應為合法 enum 值');
    }

    /** 核准後同步 club_members：UPDATE 路徑（被提名人已是社團成員） */
    public function testApprovedNominationUpdatesExistingClubMember(): void
    {
        // 先建立一筆非幹部的社團成員記錄
        $stmt = $this->conn->prepare(
            "INSERT INTO club_members (user_id, club_id, is_officer, officer_title, join_date)
             VALUES (?, ?, 0, NULL, CURDATE())"
        );
        $stmt->bind_param('is', $this->nomineeId, $this->clubId);
        $stmt->execute();
        $membershipId = (int)$this->conn->insert_id;
        $stmt->close();

        $this->assertGreaterThan(0, $membershipId, '前置：新增社團成員應成功');

        // 模擬核准提名：UPDATE 已存在的 club_members 記錄
        $title = 'PHPUnit升任幹部';
        $stmt2 = $this->conn->prepare(
            "UPDATE club_members
             SET is_officer = 1, officer_title = ?, officer_confirmation_date = CURDATE()
             WHERE user_id = ? AND club_id = ?"
        );
        $stmt2->bind_param('sis', $title, $this->nomineeId, $this->clubId);
        $stmt2->execute();
        $affected = $stmt2->affected_rows;
        $stmt2->close();

        $this->assertGreaterThan(0, $affected, 'UPDATE club_members 應影響至少一筆記錄');

        $row = $this->conn->query(
            "SELECT is_officer, officer_title, officer_confirmation_date
             FROM club_members WHERE membership_id = $membershipId"
        )->fetch_assoc();

        $this->assertSame(1, (int)$row['is_officer'],
            '原本非幹部成員，核准提名後 is_officer 應更新為 1');
        $this->assertSame($title, $row['officer_title'],
            'officer_title 應更新為提名職稱');
        $this->assertNotNull($row['officer_confirmation_date'],
            'officer_confirmation_date 應在核准時填寫');

        $this->conn->query("DELETE FROM club_members WHERE membership_id = $membershipId");
    }
}
