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
            'action'        => 'approved',
            'nominee_name'  => '王小明',
            'officer_title' => '副社長',
            'club_name'     => '黑輪社',
            'review_note'   => '',
        ];

        $result = sendNominationReviewedMail('president@example.com', '社長B', $infoApproved);
        $this->assertIsArray($result);
        $this->assertArrayHasKey('ok', $result);

        $infoRejected = array_merge($infoApproved, ['action' => 'rejected', 'review_note' => '資格不符']);
        $result2 = sendNominationReviewedMail('president@example.com', '社長B', $infoRejected);
        $this->assertIsArray($result2);
        $this->assertArrayHasKey('ok', $result2);
    }

    // ── 以下為信件內容驗證（透過測試攔截）────────────────────────────────

    /** 新活動申請通知：收件人 email 與信件主旨正確 */
    public function testApplicationSubmittedMailContent(): void
    {
        $event = [
            'event_id'     => 7,
            'event_name'   => '羽球社迎新',
            'club_name'    => 'D090',
            'student_name' => '社長C',
            'start_time'   => '2026-09-10 10:00:00',
            'end_time'     => '2026-09-10 12:00:00',
        ];

        sendApplicationSubmittedMail('admin@test.local', '管理員', $event);

        $mail = $GLOBALS['_test_last_mail'] ?? null;
        $this->assertNotNull($mail, '測試攔截應有資料');
        $this->assertSame('admin@test.local', $mail['to'], '收件人應為管理員 email');
        $this->assertStringContainsString('新活動申請通知', $mail['subject'], '主旨應含「新活動申請通知」');
        $this->assertStringContainsString('羽球社迎新', $mail['body'],    'body 應含活動名稱');
        $this->assertStringContainsString('EVENT000007', $mail['body'],  'body 應含格式化申請編號');
    }

    /** 活動審核核准通知：主旨含「審核通過」，body 含活動名稱 */
    public function testApplicationReviewedApprovedMailContent(): void
    {
        $event = ['event_id' => 3, 'event_name' => '桌球社月賽'];

        sendApplicationReviewedMail('stu@test.local', '學生甲', $event, 'approved', '');

        $mail = $GLOBALS['_test_last_mail'] ?? null;
        $this->assertNotNull($mail);
        $this->assertSame('stu@test.local', $mail['to']);
        $this->assertStringContainsString('審核通過', $mail['subject'], '核准通知主旨應含「審核通過」');
        $this->assertStringContainsString('桌球社月賽', $mail['body']);
        $this->assertStringContainsString('EVENT000003', $mail['body']);
    }

    /** 活動審核退件通知：主旨含「審核退件」，altbody 含備註 */
    public function testApplicationReviewedRejectedMailContent(): void
    {
        $event = ['event_id' => 5, 'event_name' => '射箭社週賽'];
        $note  = '缺少場地確認文件';

        sendApplicationReviewedMail('stu2@test.local', '學生乙', $event, 'rejected', $note);

        $mail = $GLOBALS['_test_last_mail'] ?? null;
        $this->assertNotNull($mail);
        $this->assertSame('stu2@test.local', $mail['to']);
        $this->assertStringContainsString('審核退件', $mail['subject'], '退件通知主旨應含「審核退件」');
        $this->assertStringContainsString($note, $mail['body'], 'body 應含備註內容');
    }

    /** 幹部提名通知寄給管理員，主旨含社團名稱 */
    public function testNominationSubmittedMailContent(): void
    {
        $info = [
            'club_name'      => '跆拳道社',
            'nominator_name' => '社長D',
            'count'          => 2,
        ];

        sendNominationSubmittedMail('admin2@test.local', '管理員2', $info);

        $mail = $GLOBALS['_test_last_mail'] ?? null;
        $this->assertNotNull($mail);
        $this->assertSame('admin2@test.local', $mail['to']);
        $this->assertStringContainsString('幹部提名', $mail['subject']);
        $this->assertStringContainsString('跆拳道社', $mail['body']);
        $this->assertStringContainsString('社長D', $mail['body']);
    }

    /** 提名審核核准通知：主旨含「核准」，body 含被提名人姓名 */
    public function testNominationReviewedApprovedMailContent(): void
    {
        $info = [
            'action'        => 'approved',
            'nominee_name'  => '陳小華',
            'officer_title' => '器材幹部',
            'club_name'     => '登山社',
            'review_note'   => '',
        ];

        sendNominationReviewedMail('president2@test.local', '社長E', $info);

        $mail = $GLOBALS['_test_last_mail'] ?? null;
        $this->assertNotNull($mail);
        $this->assertSame('president2@test.local', $mail['to']);
        $this->assertStringContainsString('核准', $mail['subject']);
        $this->assertStringContainsString('陳小華', $mail['body']);
        $this->assertStringContainsString('器材幹部', $mail['body']);
    }

    /** 提名審核駁回通知：主旨含「駁回」，altbody 含駁回備註 */
    public function testNominationReviewedRejectedMailContent(): void
    {
        $note = '該成員尚未繳交入社費用';
        $info = [
            'action'        => 'rejected',
            'nominee_name'  => '林大明',
            'officer_title' => '公關幹部',
            'club_name'     => '攝影社',
            'review_note'   => $note,
        ];

        sendNominationReviewedMail('president3@test.local', '社長F', $info);

        $mail = $GLOBALS['_test_last_mail'] ?? null;
        $this->assertNotNull($mail);
        $this->assertSame('president3@test.local', $mail['to']);
        $this->assertStringContainsString('駁回', $mail['subject']);
        $this->assertStringContainsString($note, $mail['altbody'], 'altbody 應含駁回備註');
    }
}
