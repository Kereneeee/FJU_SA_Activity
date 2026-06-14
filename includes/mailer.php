<?php
// ── 載入 PHPMailer ────────────────────────────────────────────
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\SMTP;
use PHPMailer\PHPMailer\Exception as MailerException;

require_once __DIR__ . '/../vendor/phpmailer/src/Exception.php';
require_once __DIR__ . '/../vendor/phpmailer/src/PHPMailer.php';
require_once __DIR__ . '/../vendor/phpmailer/src/SMTP.php';
if (file_exists(__DIR__ . '/mail_config.php')) {
    require_once __DIR__ . '/mail_config.php';
}

/**
 * 寄送密碼重設信
 *
 * @param  string $to_email   收件人 email
 * @param  string $to_name    收件人姓名
 * @param  string $reset_link 重設連結
 * @return array  ['ok' => bool, 'error' => string]
 */
function sendPasswordResetMail(string $to_email, string $to_name, string $reset_link): array
{
    $mail = new PHPMailer(true); // true = 開啟 exception

    try {
        _configureMailer($mail);

        // ── 寄件人 / 收件人 ────────────────────────────────────
        $mail->setFrom(MAIL_FROM_ADDRESS, MAIL_FROM_NAME);
        $mail->addAddress($to_email, $to_name);

        // ── 主旨 / 內容 ────────────────────────────────────────
        $mail->Subject = '【課指組系統】密碼重設連結';
        $mail->isHTML(true);
        $mail->Body    = buildResetEmailHtml($to_name, $reset_link);
        $mail->AltBody = buildResetEmailText($to_name, $reset_link);

        if (defined('PHPUNIT_RUNNING') && PHPUNIT_RUNNING) {
            $GLOBALS['_test_last_mail'] = ['to' => $to_email, 'subject' => $mail->Subject, 'body' => $mail->Body, 'altbody' => $mail->AltBody];
        }
        $mail->send();
        return ['ok' => true, 'error' => ''];

    } catch (MailerException $e) {
        return ['ok' => false, 'error' => $mail->ErrorInfo];
    }
}

// ── HTML 信件內容 ─────────────────────────────────────────────
function buildResetEmailHtml(string $name, string $link): string
{
    $safe_name = htmlspecialchars($name);
    $safe_link = htmlspecialchars($link);
    return <<<HTML
<!DOCTYPE html>
<html lang="zh-TW">
<body style="margin:0;padding:0;background:#f4f6fb;font-family:'Segoe UI',sans-serif;">
  <table width="100%" cellpadding="0" cellspacing="0">
    <tr><td align="center" style="padding:40px 20px;">
      <table width="480" cellpadding="0" cellspacing="0"
             style="background:#fff;border-radius:16px;box-shadow:0 4px 20px rgba(0,0,0,.08);overflow:hidden;">
        <!-- Header -->
        <tr>
          <td style="background:linear-gradient(135deg,#4a6cf7,#6a8dff);padding:32px 40px;text-align:center;">
            <h1 style="margin:0;color:#fff;font-size:22px;">🔐 密碼重設申請</h1>
            <p style="margin:8px 0 0;color:rgba(255,255,255,.85);font-size:14px;">課外活動指導組管理系統</p>
          </td>
        </tr>
        <!-- Body -->
        <tr>
          <td style="padding:36px 40px;">
            <p style="font-size:16px;color:#1f2937;">親愛的 <strong>{$safe_name}</strong> 您好，</p>
            <p style="color:#4b5563;line-height:1.7;">
              我們收到了您的密碼重設申請。<br>
              請點擊下方按鈕重設您的密碼，連結有效時間為 <strong>1 小時</strong>。
            </p>
            <div style="text-align:center;margin:32px 0;">
              <a href="{$safe_link}"
                 style="display:inline-block;background:linear-gradient(135deg,#4a6cf7,#6a8dff);
                        color:#fff;text-decoration:none;padding:14px 36px;border-radius:999px;
                        font-size:16px;font-weight:600;letter-spacing:.5px;">
                重設我的密碼
              </a>
            </div>
            <p style="color:#6b7280;font-size:13px;line-height:1.6;">
              若按鈕無法點擊，請複製以下連結到瀏覽器：<br>
              <a href="{$safe_link}" style="color:#4a6cf7;word-break:break-all;">{$safe_link}</a>
            </p>
            <hr style="border:none;border-top:1px solid #e5e7eb;margin:24px 0;">
            <p style="color:#9ca3af;font-size:12px;">
              若您未申請此操作，請直接忽略此封信，您的密碼不會有任何變更。
            </p>
          </td>
        </tr>
        <!-- Footer -->
        <tr>
          <td style="background:#f9fafb;padding:20px 40px;text-align:center;
                     color:#9ca3af;font-size:12px;border-top:1px solid #e5e7eb;">
            © 輔仁大學 課外活動指導組管理系統
          </td>
        </tr>
      </table>
    </td></tr>
  </table>
</body>
</html>
HTML;
}

// ── 純文字版（給不支援 HTML 的信箱用）────────────────────────
function buildResetEmailText(string $name, string $link): string
{
    return "親愛的 {$name} 您好，\n\n"
         . "我們收到了您的密碼重設申請。\n"
         . "請在 1 小時內點擊以下連結重設密碼：\n\n"
         . $link . "\n\n"
         . "若您未申請此操作，請忽略本信件。\n\n"
         . "— 課指組管理系統";
}

// ── 郵件發送日誌 ──────────────────────────────────────────────
function _mailLog(string $level, string $to, string $subject, string $detail = ''): void
{
    $log_file = __DIR__ . '/../document/mail_send.log';
    $ts       = date('Y-m-d H:i:s');
    $line     = "[{$ts}] [{$level}] to={$to} subject={$subject}";
    if ($detail !== '') $line .= " | {$detail}";
    @file_put_contents($log_file, $line . "\n", FILE_APPEND | LOCK_EX);
}

// ── 共用：建立已設定好 SMTP 的 PHPMailer 實例 ─────────────────
function _configureMailer(PHPMailer $mail): void
{
    $mail->isSMTP();
    $mail->Host       = SMTP_HOST;
    $mail->SMTPAuth   = true;
    $mail->Username   = SMTP_USERNAME;
    $mail->Password   = SMTP_PASSWORD;
    $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
    $mail->Port       = SMTP_PORT;
    $mail->CharSet    = 'UTF-8';
    $mail->Timeout    = 5; // SMTP 連線/回應逾時上限（秒），避免寄信卡住頁面太久
    $mail->SMTPOptions = [
        'ssl' => [
            'verify_peer' => false,
            'verify_peer_name' => false,
            'allow_self_signed' => true,
        ],
    ];
}

function _newMailer(): PHPMailer
{
    $mail = new PHPMailer(true);
    _configureMailer($mail);
    $mail->setFrom(MAIL_FROM_ADDRESS, MAIL_FROM_NAME);
    return $mail;
}

// ── 通知管理員：學生送出新申請 ───────────────────────────────
function sendApplicationSubmittedMail(string $admin_email, string $admin_name, array $event): array
{
    try {
        $mail = _newMailer();
        $mail->addAddress($admin_email, $admin_name);

        $event_no   = 'EVENT' . str_pad($event['event_id'], 6, '0', STR_PAD_LEFT);
        $safe_en    = htmlspecialchars($event['event_name']);
        $safe_club  = htmlspecialchars($event['club_name']);
        $safe_stu   = htmlspecialchars($event['student_name']);
        $safe_start = htmlspecialchars($event['start_time']);
        $safe_end   = htmlspecialchars($event['end_time']);

        $mail->Subject = "【課指組系統】新活動申請通知 - {$safe_club} {$safe_en}";
        $mail->isHTML(true);
        $mail->Body = <<<HTML
<!DOCTYPE html><html lang="zh-TW"><body style="margin:0;padding:0;background:#f4f6fb;font-family:'Segoe UI',sans-serif;">
<table width="100%" cellpadding="0" cellspacing="0"><tr><td align="center" style="padding:40px 20px;">
<table width="520" cellpadding="0" cellspacing="0" style="background:#fff;border-radius:16px;box-shadow:0 4px 20px rgba(0,0,0,.08);overflow:hidden;">
  <tr><td style="background:linear-gradient(135deg,#1e4d6b,#2d6a8f);padding:28px 40px;text-align:center;">
    <h1 style="margin:0;color:#fff;font-size:20px;">📋 新活動申請通知</h1>
    <p style="margin:6px 0 0;color:rgba(255,255,255,.85);font-size:13px;">輔仁大學 課外活動指導組管理系統</p>
  </td></tr>
  <tr><td style="padding:32px 40px;">
    <p style="font-size:15px;color:#1f2937;">親愛的 <strong>{$admin_name}</strong> 您好，</p>
    <p style="color:#4b5563;line-height:1.7;">有一筆新的活動申請正在等待審核，請盡快登入系統處理。</p>
    <table style="width:100%;background:#f9fafb;border:1px solid #e5e7eb;border-radius:10px;padding:16px;border-collapse:collapse;margin:20px 0;">
      <tr><td style="padding:6px 12px;color:#6b7280;font-size:13px;width:100px;">申請編號</td><td style="padding:6px 12px;font-weight:600;color:#1f2937;">{$event_no}</td></tr>
      <tr><td style="padding:6px 12px;color:#6b7280;font-size:13px;">活動名稱</td><td style="padding:6px 12px;color:#1f2937;">{$safe_en}</td></tr>
      <tr><td style="padding:6px 12px;color:#6b7280;font-size:13px;">社團名稱</td><td style="padding:6px 12px;color:#1f2937;">{$safe_club}</td></tr>
      <tr><td style="padding:6px 12px;color:#6b7280;font-size:13px;">申請人</td><td style="padding:6px 12px;color:#1f2937;">{$safe_stu}</td></tr>
      <tr><td style="padding:6px 12px;color:#6b7280;font-size:13px;">活動時間</td><td style="padding:6px 12px;color:#1f2937;">{$safe_start} ～ {$safe_end}</td></tr>
    </table>
    <p style="color:#6b7280;font-size:13px;">請於 <strong>3 個工作天</strong>內完成審核。</p>
  </td></tr>
  <tr><td style="background:#f9fafb;padding:16px 40px;text-align:center;color:#9ca3af;font-size:12px;border-top:1px solid #e5e7eb;">
    © 輔仁大學 課外活動指導組管理系統
  </td></tr>
</table></td></tr></table></body></html>
HTML;
        $mail->AltBody = "新活動申請通知\n申請編號：{$event_no}\n活動：{$event['event_name']}\n社團：{$event['club_name']}\n申請人：{$event['student_name']}\n時間：{$event['start_time']} ～ {$event['end_time']}\n請於3個工作天內審核。";
        if (defined('PHPUNIT_RUNNING') && PHPUNIT_RUNNING) {
            $GLOBALS['_test_last_mail'] = ['to' => $admin_email, 'subject' => $mail->Subject, 'body' => $mail->Body, 'altbody' => $mail->AltBody];
        }
        $mail->send();
        _mailLog('OK', $admin_email, $mail->Subject);
        return ['ok' => true, 'error' => ''];
    } catch (MailerException $e) {
        _mailLog('FAIL', $admin_email, '新活動申請通知', $e->getMessage());
        return ['ok' => false, 'error' => $e->getMessage()];
    }
}

// ── 通知學生：審核結果（核准 / 退件）────────────────────────
// Send one SMTP message with admin recipients in BCC to avoid blocking submit pages.
function sendApplicationSubmittedMailToAdmins(array $admin_emails, array $event): array
{
    $admin_emails = array_values(array_filter(array_map('trim', $admin_emails)));
    if (empty($admin_emails)) {
        return ['ok' => true, 'error' => ''];
    }

    try {
        $mail = _newMailer();
        $mail->addAddress(MAIL_FROM_ADDRESS, MAIL_FROM_NAME);
        foreach ($admin_emails as $email) {
            $mail->addBCC($email);
        }

        $event_no   = 'EVENT' . str_pad($event['event_id'], 6, '0', STR_PAD_LEFT);
        $safe_en    = htmlspecialchars($event['event_name']);
        $safe_club  = htmlspecialchars($event['club_name']);
        $safe_stu   = htmlspecialchars($event['student_name']);
        $safe_start = htmlspecialchars($event['start_time']);
        $safe_end   = htmlspecialchars($event['end_time']);

        $mail->Subject = "【課指組系統】新活動申請通知 - {$safe_club} {$safe_en}";
        $mail->isHTML(true);
        $mail->Body = <<<HTML
<!DOCTYPE html><html lang="zh-TW"><body style="margin:0;padding:0;background:#f4f6fb;font-family:'Segoe UI',sans-serif;">
<table width="100%" cellpadding="0" cellspacing="0"><tr><td align="center" style="padding:40px 20px;">
<table width="520" cellpadding="0" cellspacing="0" style="background:#fff;border-radius:16px;box-shadow:0 4px 20px rgba(0,0,0,.08);overflow:hidden;">
  <tr><td style="background:linear-gradient(135deg,#1e4d6b,#2d6a8f);padding:28px 40px;text-align:center;">
    <h1 style="margin:0;color:#fff;font-size:20px;">新活動申請通知</h1>
    <p style="margin:6px 0 0;color:rgba(255,255,255,.85);font-size:13px;">輔仁大學課外活動指導組系統</p>
  </td></tr>
  <tr><td style="padding:32px 40px;">
    <p style="font-size:15px;color:#1f2937;">各位管理員您好，</p>
    <p style="color:#4b5563;line-height:1.7;">系統收到新的申請，請至後台審核。</p>
    <table style="width:100%;background:#f9fafb;border:1px solid #e5e7eb;border-radius:10px;padding:16px;border-collapse:collapse;margin:20px 0;">
      <tr><td style="padding:6px 12px;color:#6b7280;font-size:13px;width:100px;">申請編號</td><td style="padding:6px 12px;font-weight:600;color:#1f2937;">{$event_no}</td></tr>
      <tr><td style="padding:6px 12px;color:#6b7280;font-size:13px;">活動名稱</td><td style="padding:6px 12px;color:#1f2937;">{$safe_en}</td></tr>
      <tr><td style="padding:6px 12px;color:#6b7280;font-size:13px;">社團名稱</td><td style="padding:6px 12px;color:#1f2937;">{$safe_club}</td></tr>
      <tr><td style="padding:6px 12px;color:#6b7280;font-size:13px;">申請人</td><td style="padding:6px 12px;color:#1f2937;">{$safe_stu}</td></tr>
      <tr><td style="padding:6px 12px;color:#6b7280;font-size:13px;">活動時間</td><td style="padding:6px 12px;color:#1f2937;">{$safe_start} ～ {$safe_end}</td></tr>
    </table>
    <p style="color:#6b7280;font-size:13px;">請於 3 個工作天內完成審核。</p>
  </td></tr>
  <tr><td style="background:#f9fafb;padding:16px 40px;text-align:center;color:#9ca3af;font-size:12px;border-top:1px solid #e5e7eb;">
    輔仁大學課外活動指導組
  </td></tr>
</table></td></tr></table></body></html>
HTML;
        $mail->AltBody = "新活動申請通知\n申請編號：{$event_no}\n活動：{$event['event_name']}\n社團：{$event['club_name']}\n申請人：{$event['student_name']}\n時間：{$event['start_time']} ～ {$event['end_time']}\n請於3個工作天內審核。";
        if (defined('PHPUNIT_RUNNING') && PHPUNIT_RUNNING) {
            $GLOBALS['_test_last_mail'] = ['to' => $admin_emails, 'subject' => $mail->Subject, 'body' => $mail->Body, 'altbody' => $mail->AltBody];
        }
        $mail->send();
        _mailLog('OK', implode(',', $admin_emails), $mail->Subject);
        return ['ok' => true, 'error' => ''];
    } catch (MailerException $e) {
        _mailLog('FAIL', implode(',', $admin_emails), '新活動申請通知', $e->getMessage());
        return ['ok' => false, 'error' => $e->getMessage()];
    }
}

function sendApplicationReviewedMail(string $student_email, string $student_name, array $event, string $action, string $note): array
{
    try {
        $mail = _newMailer();
        $mail->addAddress($student_email, $student_name);

        $is_approved = ($action === 'approved');
        $event_no    = 'EVENT' . str_pad($event['event_id'], 6, '0', STR_PAD_LEFT);
        $safe_en     = htmlspecialchars($event['event_name']);
        $safe_stu    = htmlspecialchars($student_name);
        $safe_note   = htmlspecialchars($note ?: '（無備註）');
        $result_text = $is_approved ? '✅ 已核准' : '❌ 已退件';
        $result_color= $is_approved ? '#0f5132' : '#721c24';
        $result_bg   = $is_approved ? '#d1e7dd'  : '#f8d7da';
        $subject_tag = $is_approved ? '審核通過' : '審核退件';

        $mail->Subject = "【課指組系統】您的申請{$subject_tag} - {$safe_en}";
        $mail->isHTML(true);
        $mail->Body = <<<HTML
<!DOCTYPE html><html lang="zh-TW"><body style="margin:0;padding:0;background:#f4f6fb;font-family:'Segoe UI',sans-serif;">
<table width="100%" cellpadding="0" cellspacing="0"><tr><td align="center" style="padding:40px 20px;">
<table width="520" cellpadding="0" cellspacing="0" style="background:#fff;border-radius:16px;box-shadow:0 4px 20px rgba(0,0,0,.08);overflow:hidden;">
  <tr><td style="background:linear-gradient(135deg,#1e4d6b,#2d6a8f);padding:28px 40px;text-align:center;">
    <h1 style="margin:0;color:#fff;font-size:20px;">活動申請審核結果通知</h1>
    <p style="margin:6px 0 0;color:rgba(255,255,255,.85);font-size:13px;">輔仁大學 課外活動指導組管理系統</p>
  </td></tr>
  <tr><td style="padding:32px 40px;">
    <p style="font-size:15px;color:#1f2937;">親愛的 <strong>{$safe_stu}</strong> 您好，</p>
    <p style="color:#4b5563;line-height:1.7;">您的活動申請已完成審核，結果如下：</p>
    <div style="text-align:center;margin:20px 0;">
      <span style="display:inline-block;padding:10px 28px;background:{$result_bg};color:{$result_color};border-radius:999px;font-size:16px;font-weight:700;">{$result_text}</span>
    </div>
    <table style="width:100%;background:#f9fafb;border:1px solid #e5e7eb;border-radius:10px;padding:16px;border-collapse:collapse;margin:20px 0;">
      <tr><td style="padding:6px 12px;color:#6b7280;font-size:13px;width:100px;">申請編號</td><td style="padding:6px 12px;font-weight:600;color:#1f2937;">{$event_no}</td></tr>
      <tr><td style="padding:6px 12px;color:#6b7280;font-size:13px;">活動名稱</td><td style="padding:6px 12px;color:#1f2937;">{$safe_en}</td></tr>
      <tr><td style="padding:6px 12px;color:#6b7280;font-size:13px;">審核備註</td><td style="padding:6px 12px;color:#1f2937;">{$safe_note}</td></tr>
    </table>
    <p style="color:#6b7280;font-size:13px;">如有疑問，請至課外活動指導組洽詢。</p>
  </td></tr>
  <tr><td style="background:#f9fafb;padding:16px 40px;text-align:center;color:#9ca3af;font-size:12px;border-top:1px solid #e5e7eb;">
    © 輔仁大學 課外活動指導組管理系統
  </td></tr>
</table></td></tr></table></body></html>
HTML;
        $mail->AltBody = "活動申請審核結果\n申請編號：{$event_no}\n活動：{$event['event_name']}\n結果：" . ($is_approved ? '核准' : '退件') . "\n備註：" . ($note ?: '無') . "\n如有疑問請洽課指組。";
        if (defined('PHPUNIT_RUNNING') && PHPUNIT_RUNNING) {
            $GLOBALS['_test_last_mail'] = ['to' => $student_email, 'subject' => $mail->Subject, 'body' => $mail->Body, 'altbody' => $mail->AltBody];
        }
        $mail->send();
        return ['ok' => true, 'error' => ''];
    } catch (MailerException $e) {
        return ['ok' => false, 'error' => $e->getMessage()];
    }
}

// ── 通知管理員：社長送出幹部提名 ─────────────────────────────
function sendNominationSubmittedMail(string $admin_email, string $admin_name, array $info): array
{
    try {
        $mail = _newMailer();
        $mail->addAddress($admin_email, $admin_name);

        $safe_club      = htmlspecialchars($info['club_name']);
        $safe_nominator = htmlspecialchars($info['nominator_name']);
        $count          = intval($info['count']);

        $mail->Subject = "【課指組系統】幹部提名申請通知 - {$safe_club}";
        $mail->isHTML(true);
        $mail->Body = <<<HTML
<!DOCTYPE html><html lang="zh-TW"><body style="margin:0;padding:0;background:#f4f6fb;font-family:'Segoe UI',sans-serif;">
<table width="100%" cellpadding="0" cellspacing="0"><tr><td align="center" style="padding:40px 20px;">
<table width="520" cellpadding="0" cellspacing="0" style="background:#fff;border-radius:16px;box-shadow:0 4px 20px rgba(0,0,0,.08);overflow:hidden;">
  <tr><td style="background:linear-gradient(135deg,#1e4d6b,#2d6a8f);padding:28px 40px;text-align:center;">
    <h1 style="margin:0;color:#fff;font-size:20px;">👥 幹部提名申請通知</h1>
    <p style="margin:6px 0 0;color:rgba(255,255,255,.85);font-size:13px;">輔仁大學 課外活動指導組管理系統</p>
  </td></tr>
  <tr><td style="padding:32px 40px;">
    <p style="font-size:15px;color:#1f2937;">親愛的 <strong>{$admin_name}</strong> 您好，</p>
    <p style="color:#4b5563;line-height:1.7;">有新的幹部提名申請需要審核，請至「身分權限管理」處理。</p>
    <table style="width:100%;background:#f9fafb;border:1px solid #e5e7eb;border-radius:10px;padding:16px;border-collapse:collapse;margin:20px 0;">
      <tr><td style="padding:6px 12px;color:#6b7280;font-size:13px;width:100px;">社團名稱</td><td style="padding:6px 12px;font-weight:600;color:#1f2937;">{$safe_club}</td></tr>
      <tr><td style="padding:6px 12px;color:#6b7280;font-size:13px;">提名人</td><td style="padding:6px 12px;color:#1f2937;">{$safe_nominator}</td></tr>
      <tr><td style="padding:6px 12px;color:#6b7280;font-size:13px;">提名筆數</td><td style="padding:6px 12px;color:#1f2937;">{$count} 筆</td></tr>
    </table>
    <p style="color:#6b7280;font-size:13px;">請至系統「身分權限管理」頁面進行審核。</p>
  </td></tr>
  <tr><td style="background:#f9fafb;padding:16px 40px;text-align:center;color:#9ca3af;font-size:12px;border-top:1px solid #e5e7eb;">
    © 輔仁大學 課外活動指導組管理系統
  </td></tr>
</table></td></tr></table></body></html>
HTML;
        $mail->AltBody = "幹部提名申請通知\n社團：{$info['club_name']}\n提名人：{$info['nominator_name']}\n筆數：{$count} 筆\n請至身分權限管理頁面審核。";
        if (defined('PHPUNIT_RUNNING') && PHPUNIT_RUNNING) {
            $GLOBALS['_test_last_mail'] = ['to' => $admin_email, 'subject' => $mail->Subject, 'body' => $mail->Body, 'altbody' => $mail->AltBody];
        }
        $mail->send();
        _mailLog('OK', $admin_email, $mail->Subject);
        return ['ok' => true, 'error' => ''];
    } catch (MailerException $e) {
        _mailLog('FAIL', $admin_email, '幹部提名申請通知', $e->getMessage());
        return ['ok' => false, 'error' => $e->getMessage()];
    }
}

// ── 通知所有管理員：學生送出幹部提名（一次 SMTP 連線，BCC 給所有管理員，縮短送出等待時間）──
function sendNominationSubmittedMailToAdmins(array $admin_emails, array $info): array
{
    if (empty($admin_emails)) {
        return ['ok' => true, 'error' => ''];
    }

    try {
        $mail = _newMailer();
        $mail->addAddress(MAIL_FROM_ADDRESS, MAIL_FROM_NAME);
        foreach ($admin_emails as $email) {
            $mail->addBCC($email);
        }

        $safe_club      = htmlspecialchars($info['club_name']);
        $safe_nominator = htmlspecialchars($info['nominator_name']);
        $count          = intval($info['count']);

        $mail->Subject = "【課指組系統】幹部提名申請通知 - {$safe_club}";
        $mail->isHTML(true);
        $mail->Body = <<<HTML
<!DOCTYPE html><html lang="zh-TW"><body style="margin:0;padding:0;background:#f4f6fb;font-family:'Segoe UI',sans-serif;">
<table width="100%" cellpadding="0" cellspacing="0"><tr><td align="center" style="padding:40px 20px;">
<table width="520" cellpadding="0" cellspacing="0" style="background:#fff;border-radius:16px;box-shadow:0 4px 20px rgba(0,0,0,.08);overflow:hidden;">
  <tr><td style="background:linear-gradient(135deg,#1e4d6b,#2d6a8f);padding:28px 40px;text-align:center;">
    <h1 style="margin:0;color:#fff;font-size:20px;">👥 幹部提名申請通知</h1>
    <p style="margin:6px 0 0;color:rgba(255,255,255,.85);font-size:13px;">輔仁大學 課外活動指導組管理系統</p>
  </td></tr>
  <tr><td style="padding:32px 40px;">
    <p style="font-size:15px;color:#1f2937;">管理員您好，</p>
    <p style="color:#4b5563;line-height:1.7;">有新的幹部提名申請需要審核，請至「身分權限管理」處理。</p>
    <table style="width:100%;background:#f9fafb;border:1px solid #e5e7eb;border-radius:10px;padding:16px;border-collapse:collapse;margin:20px 0;">
      <tr><td style="padding:6px 12px;color:#6b7280;font-size:13px;width:100px;">社團名稱</td><td style="padding:6px 12px;font-weight:600;color:#1f2937;">{$safe_club}</td></tr>
      <tr><td style="padding:6px 12px;color:#6b7280;font-size:13px;">提名人</td><td style="padding:6px 12px;color:#1f2937;">{$safe_nominator}</td></tr>
      <tr><td style="padding:6px 12px;color:#6b7280;font-size:13px;">提名筆數</td><td style="padding:6px 12px;color:#1f2937;">{$count} 筆</td></tr>
    </table>
    <p style="color:#6b7280;font-size:13px;">請至系統「身分權限管理」頁面進行審核。</p>
  </td></tr>
  <tr><td style="background:#f9fafb;padding:16px 40px;text-align:center;color:#9ca3af;font-size:12px;border-top:1px solid #e5e7eb;">
    © 輔仁大學 課外活動指導組管理系統
  </td></tr>
</table></td></tr></table></body></html>
HTML;
        $mail->AltBody = "幹部提名申請通知\n社團：{$info['club_name']}\n提名人：{$info['nominator_name']}\n筆數：{$count} 筆\n請至身分權限管理頁面審核。";
        if (defined('PHPUNIT_RUNNING') && PHPUNIT_RUNNING) {
            $GLOBALS['_test_last_mail'] = ['to' => $admin_emails, 'subject' => $mail->Subject, 'body' => $mail->Body, 'altbody' => $mail->AltBody];
        }
        $mail->send();
        _mailLog('OK', implode(',', $admin_emails), $mail->Subject);
        return ['ok' => true, 'error' => ''];
    } catch (MailerException $e) {
        _mailLog('FAIL', implode(',', $admin_emails), '幹部提名申請通知', $e->getMessage());
        return ['ok' => false, 'error' => $e->getMessage()];
    }
}

// ── 通知社長：提名審核結果 ────────────────────────────────────
function sendNominationReviewedMail(string $to_email, string $to_name, array $info): array
{
    try {
        $mail = _newMailer();
        $mail->addAddress($to_email, $to_name);

        $is_approved  = ($info['action'] === 'approved');
        $safe_nominee = htmlspecialchars($info['nominee_name']);
        $safe_title   = htmlspecialchars($info['officer_title'] ?: '一般成員');
        $safe_club    = htmlspecialchars($info['club_name']);
        $safe_note    = htmlspecialchars($info['review_note'] ?: '（無備註）');
        $result_text  = $is_approved ? '✅ 已核准' : '❌ 已駁回';
        $result_color = $is_approved ? '#0f5132' : '#721c24';
        $result_bg    = $is_approved ? '#d1e7dd'  : '#f8d7da';
        $subject_tag  = $is_approved ? '核准' : '駁回';

        $mail->Subject = "【課指組系統】幹部提名已{$subject_tag} - {$safe_nominee}";
        $mail->isHTML(true);
        $mail->Body = <<<HTML
<!DOCTYPE html><html lang="zh-TW"><body style="margin:0;padding:0;background:#f4f6fb;font-family:'Segoe UI',sans-serif;">
<table width="100%" cellpadding="0" cellspacing="0"><tr><td align="center" style="padding:40px 20px;">
<table width="520" cellpadding="0" cellspacing="0" style="background:#fff;border-radius:16px;box-shadow:0 4px 20px rgba(0,0,0,.08);overflow:hidden;">
  <tr><td style="background:linear-gradient(135deg,#1e4d6b,#2d6a8f);padding:28px 40px;text-align:center;">
    <h1 style="margin:0;color:#fff;font-size:20px;">幹部提名審核結果通知</h1>
    <p style="margin:6px 0 0;color:rgba(255,255,255,.85);font-size:13px;">輔仁大學 課外活動指導組管理系統</p>
  </td></tr>
  <tr><td style="padding:32px 40px;">
    <p style="font-size:15px;color:#1f2937;">親愛的 <strong>{$to_name}</strong> 您好，</p>
    <p style="color:#4b5563;line-height:1.7;">您提交的幹部提名已完成審核，結果如下：</p>
    <div style="text-align:center;margin:20px 0;">
      <span style="display:inline-block;padding:10px 28px;background:{$result_bg};color:{$result_color};border-radius:999px;font-size:16px;font-weight:700;">{$result_text}</span>
    </div>
    <table style="width:100%;background:#f9fafb;border:1px solid #e5e7eb;border-radius:10px;padding:16px;border-collapse:collapse;margin:20px 0;">
      <tr><td style="padding:6px 12px;color:#6b7280;font-size:13px;width:100px;">社團名稱</td><td style="padding:6px 12px;color:#1f2937;">{$safe_club}</td></tr>
      <tr><td style="padding:6px 12px;color:#6b7280;font-size:13px;">被提名人</td><td style="padding:6px 12px;font-weight:600;color:#1f2937;">{$safe_nominee}</td></tr>
      <tr><td style="padding:6px 12px;color:#6b7280;font-size:13px;">提名職稱</td><td style="padding:6px 12px;color:#1f2937;">{$safe_title}</td></tr>
      <tr><td style="padding:6px 12px;color:#6b7280;font-size:13px;">審核備註</td><td style="padding:6px 12px;color:#1f2937;">{$safe_note}</td></tr>
    </table>
    <p style="color:#6b7280;font-size:13px;">如有疑問，請至課外活動指導組洽詢。</p>
  </td></tr>
  <tr><td style="background:#f9fafb;padding:16px 40px;text-align:center;color:#9ca3af;font-size:12px;border-top:1px solid #e5e7eb;">
    © 輔仁大學 課外活動指導組管理系統
  </td></tr>
</table></td></tr></table></body></html>
HTML;
        $mail->AltBody = "幹部提名審核結果\n被提名人：{$info['nominee_name']}\n職稱：{$safe_title}\n社團：{$info['club_name']}\n結果：" . ($is_approved ? '核准' : '駁回') . "\n備註：" . ($info['review_note'] ?: '無');
        if (defined('PHPUNIT_RUNNING') && PHPUNIT_RUNNING) {
            $GLOBALS['_test_last_mail'] = ['to' => $to_email, 'subject' => $mail->Subject, 'body' => $mail->Body, 'altbody' => $mail->AltBody];
        }
        $mail->send();
        return ['ok' => true, 'error' => ''];
    } catch (MailerException $e) {
        return ['ok' => false, 'error' => $e->getMessage()];
    }
}
