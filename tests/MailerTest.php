<?php
use PHPUnit\Framework\TestCase;

class MailerTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        // mailer.php 使用 use 陳述式，需在 require 前確保 autoload 已載入
        require_once __DIR__ . '/../includes/mailer.php';
    }

    /** buildResetEmailHtml 應包含收件人姓名 */
    public function testBuildResetEmailHtmlContainsName(): void
    {
        $html = buildResetEmailHtml('王小明', 'http://example.com/reset?token=abc123');
        $this->assertStringContainsString('王小明', $html);
    }

    /** buildResetEmailHtml 應包含重設連結 */
    public function testBuildResetEmailHtmlContainsLink(): void
    {
        $link = 'http://example.com/reset?token=abc123';
        $html = buildResetEmailHtml('測試者', $link);
        $this->assertStringContainsString($link, $html);
    }

    /** buildResetEmailHtml 應對特殊字元做 XSS 防護 */
    public function testBuildResetEmailHtmlEscapesXSS(): void
    {
        $html = buildResetEmailHtml('<script>alert(1)</script>', 'http://example.com/reset');
        $this->assertStringNotContainsString('<script>', $html);
        $this->assertStringContainsString('&lt;script&gt;', $html);
    }

    /** buildResetEmailText 應包含姓名與連結 */
    public function testBuildResetEmailTextFormat(): void
    {
        $name = '陳大文';
        $link = 'http://example.com/reset?token=xyz';
        $text = buildResetEmailText($name, $link);

        $this->assertStringContainsString($name, $text);
        $this->assertStringContainsString($link, $text);
        $this->assertStringContainsString('1 小時', $text, '應提示連結有效時間');
    }

    /** 活動編號格式應為 EVENT + 6 位數零填充 */
    public function testEventNumberFormatting(): void
    {
        $eventId  = 42;
        $eventNo  = 'EVENT' . str_pad($eventId, 6, '0', STR_PAD_LEFT);
        $this->assertSame('EVENT000042', $eventNo);

        $eventId2 = 1;
        $eventNo2 = 'EVENT' . str_pad($eventId2, 6, '0', STR_PAD_LEFT);
        $this->assertSame('EVENT000001', $eventNo2);
    }

    /** sendPasswordResetMail 在 SMTP 無法連線時應回傳含 ok/error 的陣列 */
    public function testSendPasswordResetMailReturnsArray(): void
    {
        $result = sendPasswordResetMail('test@example.com', '測試者', 'http://example.com/reset');

        $this->assertIsArray($result);
        $this->assertArrayHasKey('ok', $result);
        $this->assertArrayHasKey('error', $result);
        $this->assertIsBool($result['ok']);
        $this->assertIsString($result['error']);
    }

    /** sendApplicationSubmittedMail 應回傳含 ok/error 的陣列 */
    public function testSendApplicationSubmittedMailReturnsArray(): void
    {
        $event = [
            'event_id'     => 99,
            'event_name'   => 'PHPUnit測試活動',
            'club_name'    => '黑輪社',
            'student_name' => '測試社長',
            'start_time'   => '2026-09-01 10:00:00',
            'end_time'     => '2026-09-01 12:00:00',
        ];

        $result = sendApplicationSubmittedMail('admin@example.com', '管理員', $event);

        $this->assertIsArray($result);
        $this->assertArrayHasKey('ok', $result);
        $this->assertArrayHasKey('error', $result);
    }

    /** sendApplicationReviewedMail approved 行為正確 */
    public function testSendApplicationReviewedMailApproved(): void
    {
        $event  = ['event_id' => 1, 'event_name' => '測試活動'];
        $result = sendApplicationReviewedMail('stu@example.com', '學生甲', $event, 'approved', '');

        $this->assertIsArray($result);
        $this->assertArrayHasKey('ok', $result);
    }

    /** sendApplicationReviewedMail rejected 行為正確 */
    public function testSendApplicationReviewedMailRejected(): void
    {
        $event  = ['event_id' => 2, 'event_name' => '測試活動'];
        $result = sendApplicationReviewedMail('stu@example.com', '學生乙', $event, 'rejected', '資料不完整');

        $this->assertIsArray($result);
        $this->assertArrayHasKey('ok', $result);
        $this->assertArrayHasKey('error', $result);
    }

    /** sendNominationSubmittedMail 應回傳含 ok/error 的陣列 */
    public function testSendNominationSubmittedMailReturnsArray(): void
    {
        $info = [
            'club_name'     => '黑輪社',
            'nominator_name' => '社長A',
            'count'         => 3,
        ];

        $result = sendNominationSubmittedMail('admin@example.com', '管理員', $info);

        $this->assertIsArray($result);
        $this->assertArrayHasKey('ok', $result);
        $this->assertArrayHasKey('error', $result);
    }

    /** sendNominationReviewedMail approved 與 rejected 皆回傳正確結構 */
    public function testSendNominationReviewedMailReturnsArray(): void
    {
        $infoApproved = [
            'action'       => 'approved',
            'nominee_name' => '王小明',
            'officer_title' => '副社長',
            'club_name'    => '黑輪社',
            'review_note'  => '',
        ];

        $result = sendNominationReviewedMail('president@example.com', '社長B', $infoApproved);
        $this->assertIsArray($result);
        $this->assertArrayHasKey('ok', $result);

        $infoRejected = array_merge($infoApproved, ['action' => 'rejected', 'review_note' => '資格不符']);
        $result2 = sendNominationReviewedMail('president@example.com', '社長B', $infoRejected);
        $this->assertIsArray($result2);
        $this->assertArrayHasKey('ok', $result2);
    }
}
