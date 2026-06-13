<?php

require_once(__DIR__ . "/DB/db_config.php");

$error      = "";
$error_type = "";   // "empty" | "account" | "password"
$input_value = "";

// 暴力破解保護
$_SESSION['login_attempts']    = $_SESSION['login_attempts']    ?? 0;
$_SESSION['login_locked_until'] = $_SESSION['login_locked_until'] ?? 0;

$locked = false;
$lock_seconds_left = 0;
if ($_SESSION['login_locked_until'] > time()) {
    $locked = true;
    $lock_seconds_left = $_SESSION['login_locked_until'] - time();
    $error      = "登入嘗試次數過多，請 {$lock_seconds_left} 秒後再試。";
    $error_type = "locked";
}

if ($_SERVER["REQUEST_METHOD"] == "POST" && !$locked) {

    $identifier = trim($_POST["identifier"]);   // 學號 or email
    $password   = trim($_POST["password"]);

    $input_value = $identifier;

    if (empty($identifier) || empty($password)) {
        $error      = "請輸入帳號與密碼";
        $error_type = "empty";
    } else {

        // 同時比對 email 及 student_id（學號為數字，MySQL 會自動轉型）
        $sql  = "SELECT * FROM users WHERE email = ? OR CAST(student_id AS CHAR) = ?";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("ss", $identifier, $identifier);
        $stmt->execute();

        $result = $stmt->get_result();

        if ($result->num_rows > 0) {
            $user = $result->fetch_assoc();

            $passwordOk = false;
            if (password_verify($password, $user["password"])) {
                $passwordOk = true;
            } elseif ($password === $user["password"]) {
                // 舊明文密碼：驗證通過後自動升級為 hash
                $passwordOk = true;
                $newHash = password_hash($password, PASSWORD_DEFAULT);
                $upd = $conn->prepare("UPDATE users SET password = ? WHERE user_id = ?");
                $upd->bind_param("si", $newHash, $user["user_id"]);
                $upd->execute();
                $upd->close();
            }

            if ($passwordOk) {

                // 清除所有舊 session 資料，防止雙分頁登入不同帳號時的角色殘留
                $_SESSION = [];
                session_regenerate_id(true);

                $_SESSION["user_id"]      = $user["user_id"];
                $_SESSION["user_name"]    = $user["name"];
                $_SESSION["role"]         = $user["role"];
                $_SESSION["student_name"] = $user["name"];
                $_SESSION["student_id"]   = $user["email"];        // 統一用 email 當識別碼
                $_SESSION["student_no"]   = $user["student_id"];   // 真實學號（int）

                if ($user["role"] == "admin") {
                    header("Location: admin/dashboard.php");
                } else {
                    header("Location: student/dashboard.php");
                }
                exit();

            } else {
                $_SESSION['login_attempts']++;
                if ($_SESSION['login_attempts'] >= 5) {
                    $_SESSION['login_locked_until'] = time() + 300; // 鎖定 5 分鐘
                    $_SESSION['login_attempts']     = 0;
                    $error      = "登入嘗試次數過多，帳號已暫時鎖定 5 分鐘。";
                    $error_type = "locked";
                } else {
                    $remaining  = 5 - $_SESSION['login_attempts'];
                    $error      = "密碼錯誤，請確認後再試（還剩 {$remaining} 次機會）。";
                    $error_type = "password";
                }
            }


        } else {
            $_SESSION['login_attempts']++;
            if ($_SESSION['login_attempts'] >= 5) {
                $_SESSION['login_locked_until'] = time() + 300;
                $_SESSION['login_attempts']     = 0;
                $error      = "登入嘗試次數過多，帳號已暫時鎖定 5 分鐘。";
                $error_type = "locked";
            } else {
                $error      = "查無此帳號，請確認學號或電子信箱是否正確";
                $error_type = "account";
            }
        }
    }
}
?>

<!DOCTYPE html>
<html lang="zh-TW">
<head>
<meta charset="UTF-8">
<title>課指組管理系統登入</title>

<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.5/font/bootstrap-icons.css" rel="stylesheet">

<style>
body {
    height: 100vh;
    margin: 0;
    background: #d5e3ea;
    display: flex;
    justify-content: center;
    align-items: center;
    font-family: "Segoe UI", sans-serif;
}

.login-card {
    width: 380px;
    padding: 35px;
    border-radius: 20px;
    background: #ffffff;
    box-shadow: 0 10px 30px rgba(15,23,42,0.09);
}

.title {
    text-align: center;
    margin-bottom: 20px;
}

.title i {
    font-size: 40px;
    color: #1e4d6b;
}

.title h4 {
    margin-top: 10px;
    font-weight: bold;
    color: #1e4d6b;
}

.form-control {
    border-radius: 10px;
    border-color: #d5e3ea;
}

.form-control:focus {
    border-color: #1e4d6b;
    box-shadow: 0 0 0 0.2rem rgba(30,77,107,0.15);
}

.btn-login {
    border-radius: 10px;
    background: #1e4d6b;
    border: none;
    color: white;
    transition: 0.3s;
}

.btn-login:hover {
    background: #14394f;
    transform: scale(1.02);
}

.password-wrapper {
    position: relative;
}

.password-wrapper input {
    padding-right: 40px;
}

.password-wrapper i {
    position: absolute;
    right: 12px;
    top: 38px;
    cursor: pointer;
    color: #666;
}

.footer-text {
    font-size: 13px;
    text-align: center;
    margin-top: 15px;
    color: #666;
}

/* 分類錯誤提示 */
.login-alert {
    display: flex;
    align-items: flex-start;
    gap: 0.6rem;
    padding: 0.75rem 1rem;
    border-radius: 10px;
    margin-bottom: 1rem;
    font-size: 0.9rem;
    line-height: 1.4;
}
.login-alert i { font-size: 1rem; flex-shrink: 0; margin-top: 1px; }
.login-alert.type-empty {
    background: #f0e8c0;
    color: #6b5a20;
    border: 1px solid #d4c870;
}
.login-alert.type-account {
    background: #dce9ea;
    color: #1a4a4f;
    border: 1px solid #9fc4cb;
}
.login-alert.type-password {
    background: #deb8b9;
    color: #5c1f22;
    border: 1px solid #c9979a;
}
.login-alert.type-locked {
    background: #f3e8ff;
    color: #5b21b6;
    border: 1px solid #c4b5fd;
}
</style>
</head>

<body>

<div class="login-card">

    <div class="title">
        <i class="bi bi-building"></i>
        <h4>課指組管理系統</h4>
    </div>

    <?php if ($error):
        if ($error_type === 'account')       $icon = 'bi-person-x-fill';
        elseif ($error_type === 'password')  $icon = 'bi-lock-fill';
        else                                 $icon = 'bi-exclamation-triangle-fill';
    ?>
    <div class="login-alert type-<?= $error_type ?>">
        <i class="bi <?= $icon ?>"></i>
        <span><?= htmlspecialchars($error) ?></span>
    </div>
    <?php endif; ?>

    <form method="POST" onsubmit="showLoading()">

        <div class="mb-3">
            <label class="form-label">帳號</label>
            <input
                type="text"
                name="identifier"
                class="form-control"
                value="<?php echo htmlspecialchars($input_value); ?>"
                placeholder="學號 或 電子信箱"
                autocomplete="username"
                required
            >
        </div>

        <div class="mb-3 password-wrapper">
            <label class="form-label">密碼</label>
            <input type="password" id="password" name="password" class="form-control" required>
            <i class="bi bi-eye" onclick="togglePassword()"></i>
        </div>

        <button id="loginBtn" type="submit" class="btn btn-login w-100">
            登入
        </button>

    </form>

    <div class="footer-text">
        <a href="forgot_password.php" class="text-decoration-none" style="color:#1e4d6b;">
            <i class="bi bi-key me-1"></i>忘記密碼？
        </a>
        <span style="color:#ccc; margin: 0 8px;">|</span>
        <a href="register.php" class="text-decoration-none" style="color:#1e4d6b;">
            <i class="bi bi-person-plus me-1"></i>註冊帳號
        </a>
    </div>

</div>

<script>
function togglePassword() {
    const pwd = document.getElementById("password");
    pwd.type = pwd.type === "password" ? "text" : "password";
}

function showLoading() {
    const btn = document.getElementById("loginBtn");
    btn.innerHTML = "登入中...";
    btn.disabled = true;
}
</script>

</body>
</html>