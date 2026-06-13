<?php
use PHPUnit\Framework\TestCase;

class LoginTest extends TestCase
{
    private $conn;

    protected function setUp(): void
    {
        $this->conn = $GLOBALS['conn'];
    }

    /** 正確帳號密碼能查到使用者 */
    public function testValidCredentialsReturnUser(): void
    {
        $stmt = $this->conn->prepare(
            "SELECT * FROM users WHERE email = ? OR CAST(student_id AS CHAR) = ?"
        );
        $id = 'kkerene130@gmail.com';
        $stmt->bind_param('ss', $id, $id);
        $stmt->execute();
        $user = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        $this->assertNotNull($user, '應能查到使用者');
        $this->assertSame('student', $user['role']);
        // 明文密碼或 hash 都能驗證
        $this->assertTrue(
            password_verify('12345678', $user['password']) || $user['password'] === '12345678',
            '密碼應能驗證'
        );
    }

    /** 錯誤帳號查不到使用者 */
    public function testInvalidIdentifierReturnsNoUser(): void
    {
        $stmt = $this->conn->prepare(
            "SELECT * FROM users WHERE email = ? OR CAST(student_id AS CHAR) = ?"
        );
        $id = 'notexist@example.com';
        $stmt->bind_param('ss', $id, $id);
        $stmt->execute();
        $rows = $stmt->get_result()->num_rows;
        $stmt->close();

        $this->assertSame(0, $rows, '不存在的帳號應查無結果');
    }

    /** 管理員帳號 role 正確 */
    public function testAdminRoleIsCorrect(): void
    {
        $stmt = $this->conn->prepare("SELECT role FROM users WHERE role = 'admin' LIMIT 1");
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        $this->assertSame('admin', $row['role'], '應存在管理員帳號');
    }

    /** password_hash 產生的 hash 能被 password_verify 驗證 */
    public function testPasswordHashAndVerify(): void
    {
        $plain  = 'testPassword123';
        $hashed = password_hash($plain, PASSWORD_DEFAULT);

        $this->assertTrue(password_verify($plain, $hashed));
        $this->assertFalse(password_verify('wrongPassword', $hashed));
    }
}
