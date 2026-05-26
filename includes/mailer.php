<?php
// ── 載入 PHPMailer ────────────────────────────────────────────
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\SMTP;
use PHPMailer\PHPMailer\Exception as MailerException;

require_once __DIR__ . '/../vendor/phpmailer/src/Exception.php';
require_once __DIR__ . '/../vendor/phpmailer/src/PHPMailer.php';
require_once __DIR__ . '/../vendor/phpmailer/src/SMTP.php';
require_once __DIR__ . '/mail_config.php';

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
        // ── SMTP 設定 ──────────────────────────────────────────
        $mail->isSMTP();
        $mail->Host       = SMTP_HOST;
        $mail->SMTPAuth   = true;
        $mail->Username   = SMTP_USERNAME;
        $mail->Password   = SMTP_PASSWORD;
        $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
        $mail->Port       = SMTP_PORT;
        $mail->CharSet    = 'UTF-8';

        // ── 寄件人 / 收件人 ────────────────────────────────────
        $mail->setFrom(MAIL_FROM_ADDRESS, MAIL_FROM_NAME);
        $mail->addAddress($to_email, $to_name);

        // ── 主旨 / 內容 ────────────────────────────────────────
        $mail->Subject = '【課指組系統】密碼重設連結';
        $mail->isHTML(true);
        $mail->Body    = buildResetEmailHtml($to_name, $reset_link);
        $mail->AltBody = buildResetEmailText($to_name, $reset_link);

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
