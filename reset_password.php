<?php
ini_set('display_errors', 1);
error_reporting(E_ALL);

require_once(__DIR__ . "/DB/db_config.php");

$message      = "";
$message_type = "";
$step         = "form";   // form = 顯示表單, done = 重設成功, invalid = token 無效/過期
$token        = trim($_GET['token'] ?? $_POST['token'] ?? "");

// ── 驗證 token ─────────────────────────────────────────────────────
if (empty($token)) {
    $step = "invalid";
    $message = "無效的連結，請重新申請忘記密碼。";
} else {
    $stmt = $conn->prepare("
        SELECT pr.id, pr.email, pr.expires_at, pr.used, u.name
        FROM password_resets pr
        JOIN users u ON u.email = pr.email
        WHERE pr.token = ?
        LIMIT 1
    ");
    $stmt->bind_param("s", $token);
    $stmt->execute();
    $result = $stmt->get_result();

    if ($result->num_rows === 0) {
        $step    = "invalid";
        $message = "連結無效，請重新申請忘記密碼。";
    } else {
        $row = $result->fetch_assoc();

        if ($row['used'] == 1) {
            $step    = "invalid";
            $message = "此連結已使用過，請重新申請忘記密碼。";
        } elseif (strtotime($row['expires_at']) < time()) {
            $step    = "invalid";
            $message = "連結已過期（有效期限 1 小時），請重新申請忘記密碼。";
        }
        // token 合法，繼續
    }
}

// ── 處理重設密碼送出 ───────────────────────────────────────────────
if ($_SERVER["REQUEST_METHOD"] == "POST" && $step === "form") {
    $new_password  = $_POST['new_password']  ?? "";
    $confirm_password = $_POST['confirm_password'] ?? "";

    if (empty($new_password) || empty($confirm_password)) {
        $message = "請填寫新密碼與確認密碼";
        $message_type = "danger";
    } elseif (strlen($new_password) < 6) {
        $message = "密碼長度至少需要 6 個字元";
        $message_type = "danger";
    } elseif ($new_password !== $confirm_password) {
        $message = "兩次輸入的密碼不一致";
        $message_type = "danger";
    } else {
        // 更新密碼
        $upd = $conn->prepare("UPDATE users SET password = ? WHERE email = ?");
        $upd->bind_param("ss", $new_password, $row['email']);
        $upd->execute();

        // 標記 token 已使用
        $mark = $conn->prepare("UPDATE password_resets SET used = 1 WHERE token = ?");
        $mark->bind_param("s", $token);
        $mark->execute();

        $step = "done";
    }
}
?>
<!DOCTYPE html>
<html lang="zh-TW">
<head>
<meta charset="UTF-8">
<title>重設密碼 — 課指組管理系統</title>
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
.password-wrapper { position: relative; }
.password-wrapper i.toggle-eye {
    position: absolute;
    right: 12px;
    top: 38px;
    cursor: pointer;
    color: #666;
}
.strength-bar {
    height: 6px;
    border-radius: 3px;
    transition: width 0.3s, background 0.3s;
    margin-top: 6px;
}
.back-link { text-align: center; margin-top: 15px; font-size: 14px; }
</style>
</head>
<body>

<div class="card-box">
    <div class="title">
        <i class="bi bi-shield-lock-fill"></i>
        <h4>重設密碼</h4>
    </div>

    <?php if ($message && $message_type): ?>
        <div class="alert alert-<?= htmlspecialchars($message_type) ?>"><?= htmlspecialchars($message) ?></div>
    <?php endif; ?>

    <?php if ($step === "invalid"): ?>
    <!-- ── Token 無效 ── -->
    <div class="text-center">
        <i class="bi bi-x-circle-fill text-danger" style="font-size:50px;"></i>
        <h5 class="mt-3 mb-2">連結無效或已過期</h5>
        <p class="text-muted" style="font-size:14px;">
            <?= htmlspecialchars($message) ?>
        </p>
        <a href="forgot_password.php" class="btn btn-primary-custom w-100 mt-2">
            <i class="bi bi-arrow-repeat me-1"></i>重新申請忘記密碼
        </a>
    </div>

    <?php elseif ($step === "done"): ?>
    <!-- ── 重設成功 ── -->
    <div class="text-center">
        <i class="bi bi-check-circle-fill text-success" style="font-size:50px;"></i>
        <h5 class="mt-3 mb-2">密碼重設成功！</h5>
        <p class="text-muted" style="font-size:14px;">
            您的密碼已成功更新，請使用新密碼登入。
        </p>
        <a href="login.php" class="btn btn-primary-custom w-100 mt-2">
            <i class="bi bi-box-arrow-in-right me-1"></i>前往登入
        </a>
    </div>

    <?php else: ?>
    <!-- ── 重設密碼表單 ── -->
    <?php if (isset($row['name'])): ?>
    <p class="text-muted text-center mb-3" style="font-size:14px;">
        您好，<strong><?= htmlspecialchars($row['name']) ?></strong>，請設定您的新密碼。
    </p>
    <?php endif; ?>

    <form method="POST" onsubmit="return validateForm()">
        <input type="hidden" name="token" value="<?= htmlspecialchars($token) ?>">

        <div class="mb-3 password-wrapper">
            <label class="form-label">新密碼</label>
            <input
                type="password"
                id="new_password"
                name="new_password"
                class="form-control"
                placeholder="至少 6 個字元"
                required
                oninput="checkStrength(this.value)"
            >
            <i class="bi bi-eye toggle-eye" onclick="togglePwd('new_password', this)"></i>
            <div id="strength-bar" class="strength-bar" style="width:0%; background:#ccc;"></div>
            <small id="strength-text" class="text-muted"></small>
        </div>

        <div class="mb-4 password-wrapper">
            <label class="form-label">確認新密碼</label>
            <input
                type="password"
                id="confirm_password"
                name="confirm_password"
                class="form-control"
                placeholder="再輸入一次新密碼"
                required
            >
            <i class="bi bi-eye toggle-eye" onclick="togglePwd('confirm_password', this)"></i>
            <small id="match-text" class="text-muted"></small>
        </div>

        <button type="submit" class="btn btn-primary-custom w-100">
            <i class="bi bi-check-lg me-1"></i>確認重設密碼
        </button>
    </form>
    <?php endif; ?>

    <div class="back-link">
        <a href="login.php" class="text-secondary text-decoration-none">
            <i class="bi bi-arrow-left me-1"></i>返回登入
        </a>
    </div>
</div>

<script>
function togglePwd(id, icon) {
    const input = document.getElementById(id);
    if (input.type === "password") {
        input.type = "text";
        icon.classList.replace("bi-eye", "bi-eye-slash");
    } else {
        input.type = "password";
        icon.classList.replace("bi-eye-slash", "bi-eye");
    }
}

function checkStrength(val) {
    const bar  = document.getElementById("strength-bar");
    const text = document.getElementById("strength-text");
    let score = 0;
    if (val.length >= 6)  score++;
    if (val.length >= 10) score++;
    if (/[A-Z]/.test(val)) score++;
    if (/[0-9]/.test(val)) score++;
    if (/[^A-Za-z0-9]/.test(val)) score++;

    const levels = [
        { pct: "20%",  color: "#dc3545", label: "太短" },
        { pct: "40%",  color: "#fd7e14", label: "弱" },
        { pct: "60%",  color: "#ffc107", label: "普通" },
        { pct: "80%",  color: "#20c997", label: "強" },
        { pct: "100%", color: "#198754", label: "非常強" },
    ];
    const lv = levels[Math.max(0, score - 1)] || levels[0];
    bar.style.width     = lv.pct;
    bar.style.background = lv.color;
    text.textContent   = val.length === 0 ? "" : "密碼強度：" + lv.label;

    // 即時比對確認密碼
    checkMatch();
}

function checkMatch() {
    const p1   = document.getElementById("new_password").value;
    const p2   = document.getElementById("confirm_password").value;
    const text = document.getElementById("match-text");
    if (p2.length === 0) { text.textContent = ""; return; }
    if (p1 === p2) {
        text.textContent = "✅ 密碼一致";
        text.style.color = "#198754";
    } else {
        text.textContent = "❌ 密碼不一致";
        text.style.color = "#dc3545";
    }
}

document.getElementById("confirm_password")?.addEventListener("input", checkMatch);

function validateForm() {
    const p1 = document.getElementById("new_password").value;
    const p2 = document.getElementById("confirm_password").value;
    if (p1.length < 6) {
        alert("密碼長度至少需要 6 個字元");
        return false;
    }
    if (p1 !== p2) {
        alert("兩次輸入的密碼不一致");
        return false;
    }
    return true;
}
</script>

</body>
</html>
