<?php
ini_set('display_errors', 1);
error_reporting(E_ALL);

require_once(__DIR__ . "/DB/db_config.php");
require_once(__DIR__ . "/includes/mailer.php");

$message = "";
$message_type = ""; // success / danger
$step = "request"; // request = 輸入email, done = 已送出

// ── 建立 password_resets 資料表（若尚未存在）──────────────────────
$conn->query("
    CREATE TABLE IF NOT EXISTS `password_resets` (
        `id` int(11) NOT NULL AUTO_INCREMENT,
        `email` varchar(100) NOT NULL,
        `token` varchar(64) NOT NULL,
        `expires_at` datetime NOT NULL,
        `used` tinyint(1) DEFAULT 0,
        `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (`id`),
        KEY `idx_token` (`token`),
        KEY `idx_email` (`email`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8
");

// ── 處理表單送出 ──────────────────────────────────────────────────
if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $email = trim($_POST["email"] ?? "");

    if (empty($email)) {
        $message = "請輸入電子信箱 / 帳號";
        $message_type = "danger";
    } else {
        // 查詢使用者
        $stmt = $conn->prepare("SELECT user_id, name, email FROM users WHERE email = ?");
        $stmt->bind_param("s", $email);
        $stmt->execute();
        $result = $stmt->get_result();

        if ($result->num_rows === 0) {
            // 故意不透露帳號是否存在（安全考量），同樣顯示成功訊息
            $step = "done";
        } else {
            $user = $result->fetch_assoc();

            // 刪除該帳號之前的未使用 token（避免累積）
            $del = $conn->prepare("DELETE FROM password_resets WHERE email = ? AND used = 0");
            $del->bind_param("s", $email);
            $del->execute();

            // 產生安全 token
            $token = bin2hex(random_bytes(32)); // 64 字元 hex
            $expires_at = date("Y-m-d H:i:s", strtotime("+1 hour"));

            $ins = $conn->prepare("
                INSERT INTO password_resets (email, token, expires_at) VALUES (?, ?, ?)
            ");
            $ins->bind_param("sss", $email, $token, $expires_at);
            $ins->execute();

            // 組成重設連結
            $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? "https" : "http";
            $host = $_SERVER['HTTP_HOST'];
            $base_path = dirname($_SERVER['SCRIPT_NAME']);
            $reset_link = $protocol . "://" . $host . $base_path . "/reset_password.php?token=" . urlencode($token);

            // 送出 email（使用 PHPMailer + SMTP）
            $mail_result = sendPasswordResetMail($user['email'], $user['name'], $reset_link);

            // 儲存結果供頁面顯示
            $_SESSION['_dev_reset_link'] = $reset_link;
            $_SESSION['_dev_mail_sent']  = $mail_result['ok'];
            $_SESSION['_dev_mail_error'] = $mail_result['error'];

            $step = "done";
        }
    }
}
?>
<!DOCTYPE html>
<html lang="zh-TW">
<head>
<meta charset="UTF-8">
<title>忘記密碼 — 課指組管理系統</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.5/font/bootstrap-icons.css" rel="stylesheet">
<style>
body {
    height: 100vh;
    margin: 0;
    background: linear-gradient(135deg, #74ebd5, #9face6);
    display: flex;
    justify-content: center;
    align-items: center;
    font-family: "Segoe UI", sans-serif;
}
.card-box {
    width: 420px;
    padding: 35px;
    border-radius: 20px;
    background: rgba(255,255,255,0.92);
    backdrop-filter: blur(10px);
    box-shadow: 0 15px 35px rgba(0,0,0,0.2);
}
.title { text-align: center; margin-bottom: 25px; }
.title i { font-size: 40px; color: #4a6cf7; }
.title h4 { margin-top: 10px; font-weight: bold; }
.btn-primary-custom {
    border-radius: 10px;
    background: linear-gradient(135deg, #4a6cf7, #6a8dff);
    border: none;
    color: white;
    transition: 0.3s;
}
.btn-primary-custom:hover { transform: scale(1.03); color: white; }
.form-control { border-radius: 10px; }
.back-link { text-align: center; margin-top: 15px; font-size: 14px; }
.dev-box {
    background: #fff3cd;
    border: 1px solid #ffc107;
    border-radius: 10px;
    padding: 12px 15px;
    margin-top: 15px;
    font-size: 13px;
    word-break: break-all;
}
</style>
</head>
<body>

<div class="card-box">
    <div class="title">
        <i class="bi bi-key-fill"></i>
        <h4>忘記密碼</h4>
    </div>

    <?php if ($message): ?>
        <div class="alert alert-<?= htmlspecialchars($message_type) ?>"><?= htmlspecialchars($message) ?></div>
    <?php endif; ?>

    <?php if ($step === "request"): ?>
    <!-- ── 輸入 email 表單 ── -->
    <p class="text-muted text-center mb-4" style="font-size:14px;">
        請輸入您註冊時使用的<strong>電子信箱</strong>，<br>系統將寄送重設密碼連結至該信箱。
    </p>
    <form method="POST">
        <div class="mb-3">
            <label class="form-label">電子信箱（登入帳號）</label>
            <input
                type="email"
                name="email"
                class="form-control"
                placeholder="請輸入您的電子信箱"
                value="<?= htmlspecialchars($_POST['email'] ?? '') ?>"
                required
                autofocus
            >
        </div>
        <button type="submit" class="btn btn-primary-custom w-100">
            <i class="bi bi-send me-1"></i> 送出重設申請
        </button>
    </form>

    <?php else: ?>
    <!-- ── 送出成功畫面 ── -->
    <div class="text-center">
        <i class="bi bi-envelope-check-fill text-success" style="font-size:50px;"></i>
        <h5 class="mt-3 mb-2">申請已送出！</h5>
        <p class="text-muted" style="font-size:14px;">
            若此帳號存在，重設密碼連結已寄至您的電子信箱。<br>
            連結有效時間為 <strong>1 小時</strong>，請盡快完成重設。
        </p>
        <p class="text-muted" style="font-size:13px;">
            若未收到信件，請檢查垃圾郵件匣，或聯繫管理員。
        </p>

        <?php
        // ── 開發模式：顯示重設連結（MAIL_DEV_MODE = true 時顯示）──
        if (defined('MAIL_DEV_MODE') && MAIL_DEV_MODE && isset($_SESSION['_dev_reset_link'])):
            $dev_link   = $_SESSION['_dev_reset_link'];
            $mail_ok    = $_SESSION['_dev_mail_sent']  ?? false;
            $mail_err   = $_SESSION['_dev_mail_error'] ?? '';
            unset($_SESSION['_dev_reset_link'], $_SESSION['_dev_mail_sent'], $_SESSION['_dev_mail_error']);
        ?>
        <div class="dev-box text-start">
            <strong><i class="bi bi-bug-fill text-warning me-1"></i>開發模式提示</strong>
            （將 mail_config.php 的 MAIL_DEV_MODE 改為 false 可關閉）<br><br>
            <?php if ($mail_ok): ?>
                ✅ Email 已透過 SMTP 成功寄出<br><br>
            <?php else: ?>
                ⚠️ SMTP 寄送失敗<?= $mail_err ? "：<code>{$mail_err}</code>" : "" ?>
                <br>重設連結（測試用）：<br><br>
            <?php endif; ?>
            <a href="<?= htmlspecialchars($dev_link) ?>" class="text-primary">
                <?= htmlspecialchars($dev_link) ?>
            </a>
        </div>
        <?php endif; ?>
    </div>
    <?php endif; ?>

    <div class="back-link">
        <a href="login.php" class="text-secondary text-decoration-none">
            <i class="bi bi-arrow-left me-1"></i>返回登入
        </a>
    </div>
</div>

</body>
</html>
