<?php
use PHPUnit\Framework\TestCase;

class PasswordResetTest extends TestCase
{
    private $conn;
    private $createdResetIds = [];

    protected function setUp(): void
    {
        $this->conn = $GLOBALS['conn'];
    }

    protected function tearDown(): void
    {
        if ($this->createdResetIds) {
            $list = implode(',', array_map('intval', $this->createdResetIds));
            $this->conn->query("DELETE FROM password_resets WHERE id IN ($list)");
        }
    }

    private function insertReset(string $email, int $expiresInSeconds = 3600, bool $used = false): array
    {
        $token     = bin2hex(random_bytes(32));
        $expiresAt = date('Y-m-d H:i:s', time() + $expiresInSeconds);
        $usedInt   = $used ? 1 : 0;

        $stmt = $this->conn->prepare(
            "INSERT INTO password_resets (email, token, expires_at, used) VALUES (?, ?, ?, ?)"
        );
        $stmt->bind_param('sssi', $email, $token, $expiresAt, $usedInt);
        $stmt->execute();
        $id = (int)$this->conn->insert_id;
        $stmt->close();
        $this->createdResetIds[] = $id;

        return ['id' => $id, 'token' => $token, 'expires_at' => $expiresAt];
    }

    /** token 應為 64 字元 hex 字串 */
    public function testTokenFormatAndLength(): void
    {
        $token = bin2hex(random_bytes(32));
        $this->assertSame(64, strlen($token), 'token 應為 64 字元');
        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $token, 'token 應為合法 hex 字串');
    }

    /** 每次產生的 token 應不重複 */
    public function testTokensAreUnique(): void
    {
        $tokens = [];
        for ($i = 0; $i < 5; $i++) {
            $tokens[] = bin2hex(random_bytes(32));
        }
        $this->assertSame(count($tokens), count(array_unique($tokens)), 'token 應具唯一性');
    }

    /** token 寫入 password_resets 並可查詢回來 */
    public function testTokenStoredInDb(): void
    {
        $email = 'test_reset@phpunit.local';
        $data  = $this->insertReset($email);

        $row = $this->conn->query(
            "SELECT * FROM password_resets WHERE id = {$data['id']}"
        )->fetch_assoc();

        $this->assertSame($email, $row['email']);
        $this->assertSame($data['token'], $row['token']);
        $this->assertSame(0, (int)$row['used'], '新建 token 的 used 應為 0');
        $this->assertNotNull($row['created_at']);
    }

    /** 有效 token 查詢：未過期且未使用 */
    public function testValidTokenLookup(): void
    {
        $email = 'valid_reset@phpunit.local';
        $data  = $this->insertReset($email, 3600, false);

        $stmt = $this->conn->prepare(
            "SELECT * FROM password_resets
             WHERE token = ? AND used = 0 AND expires_at > NOW()"
        );
        $stmt->bind_param('s', $data['token']);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        $this->assertNotNull($row, '有效 token 應可查詢到');
        $this->assertSame($email, $row['email']);
        $this->assertSame(0, (int)$row['used']);
    }

    /** 過期 token 不應通過驗證 */
    public function testExpiredTokenRejected(): void
    {
        $email = 'expired_reset@phpunit.local';
        $data  = $this->insertReset($email, -10, false); // 已過期

        $stmt = $this->conn->prepare(
            "SELECT * FROM password_resets
             WHERE token = ? AND used = 0 AND expires_at > NOW()"
        );
        $stmt->bind_param('s', $data['token']);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        $this->assertNull($row, '過期 token 不應通過驗證');
    }

    /** 使用後將 token 標記為 used=1 */
    public function testTokenMarkedUsedAfterReset(): void
    {
        $email = 'used_reset@phpunit.local';
        $data  = $this->insertReset($email, 3600, false);

        $stmt = $this->conn->prepare(
            "UPDATE password_resets SET used = 1
             WHERE token = ? AND used = 0 AND expires_at > NOW()"
        );
        $stmt->bind_param('s', $data['token']);
        $stmt->execute();
        $affected = $stmt->affected_rows;
        $stmt->close();

        $this->assertSame(1, $affected, '應成功標記 token 為已使用');

        $row = $this->conn->query(
            "SELECT used FROM password_resets WHERE id = {$data['id']}"
        )->fetch_assoc();
        $this->assertSame(1, (int)$row['used'], '使用後 used 欄位應為 1');
    }

    /** 已使用的 token 不可再次通過驗證 */
    public function testUsedTokenRejected(): void
    {
        $email = 'reuse_reset@phpunit.local';
        $data  = $this->insertReset($email, 3600, true); // 已標記 used

        $stmt = $this->conn->prepare(
            "SELECT * FROM password_resets
             WHERE token = ? AND used = 0 AND expires_at > NOW()"
        );
        $stmt->bind_param('s', $data['token']);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        $this->assertNull($row, '已使用的 token 不應通過驗證');
    }

    /** 同一 email 可建立多筆有效 token（舊 token 不自動失效） */
    public function testMultipleActiveTokensForSameEmail(): void
    {
        $email = 'multi_reset@phpunit.local';
        $this->insertReset($email, 3600);
        $this->insertReset($email, 3600);

        $stmt = $this->conn->prepare(
            "SELECT COUNT(*) AS cnt FROM password_resets
             WHERE email = ? AND used = 0 AND expires_at > NOW()"
        );
        $stmt->bind_param('s', $email);
        $stmt->execute();
        $cnt = (int)$stmt->get_result()->fetch_assoc()['cnt'];
        $stmt->close();

        $this->assertGreaterThanOrEqual(2, $cnt, '同一 email 可有多筆有效 token');
    }

    /** 只有正確的 token 字串才能查到對應 email */
    public function testWrongTokenReturnsNothing(): void
    {
        $email = 'correct_reset@phpunit.local';
        $this->insertReset($email, 3600);

        $fakeToken = str_repeat('0', 64);
        $stmt = $this->conn->prepare(
            "SELECT * FROM password_resets
             WHERE token = ? AND used = 0 AND expires_at > NOW()"
        );
        $stmt->bind_param('s', $fakeToken);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        $this->assertNull($row, '錯誤的 token 不應查到任何記錄');
    }

    /** password_resets 表欄位完整性驗證 */
    public function testPasswordResetsTableStructure(): void
    {
        $cols = $this->conn->query("SHOW COLUMNS FROM password_resets");
        $colNames = [];
        while ($row = $cols->fetch_assoc()) {
            $colNames[] = $row['Field'];
        }

        foreach (['id', 'email', 'token', 'expires_at', 'used', 'created_at'] as $col) {
            $this->assertContains($col, $colNames, "password_resets 應有 $col 欄位");
        }
    }

    /** token 欄位有 index（idx_token）以支援快速查詢 */
    public function testTokenColumnHasIndex(): void
    {
        $indexes = $this->conn->query("SHOW INDEX FROM password_resets WHERE Key_name = 'idx_token'");
        $this->assertGreaterThan(0, $indexes->num_rows, 'token 欄位應有 idx_token 索引');
    }
}
