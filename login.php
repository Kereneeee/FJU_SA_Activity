<?php
ini_set('display_errors', 1);
error_reporting(E_ALL);

session_start();
require_once(__DIR__ . "/DB/db_config.php");

$error = "";
$email_value = "";

if ($_SERVER["REQUEST_METHOD"] == "POST") {

    $email = trim($_POST["email"]);
    $password = trim($_POST["password"]);

    $email_value = $email;

    if (empty($email) || empty($password)) {
        $error = "請輸入帳號與密碼";
    } else {

        $sql = "SELECT * FROM users WHERE email = ?";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("s", $email);
        $stmt->execute();

        $result = $stmt->get_result();

        if ($result->num_rows > 0) {
            $user = $result->fetch_assoc();

            if ($password === $user["password"]) {

                $_SESSION["user_id"] = $user["user_id"];
                $_SESSION["user"] = $user["name"];
                $_SESSION["role"] = $user["role"];
                $_SESSION["student_name"] = $user["name"];
                $_SESSION["student_id"] = $email;

                session_regenerate_id(true);

                if ($user["role"] == "admin") {
                    header("Location: admin/dashboard.php");
                } else {
                    header("Location: student/dashboard.php");
                }
                exit();

            } else {
                $error = "密碼錯誤";
            }

        } else {
            $error = "帳號不存在";
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
    background: linear-gradient(135deg, #74ebd5, #9face6);
    display: flex;
    justify-content: center;
    align-items: center;
    font-family: "Segoe UI", sans-serif;
}

.login-card {
    width: 380px;
    padding: 35px;
    border-radius: 20px;
    background: rgba(255,255,255,0.9);
    backdrop-filter: blur(10px);
    box-shadow: 0 15px 35px rgba(0,0,0,0.2);
}

.title {
    text-align: center;
    margin-bottom: 20px;
}

.title i {
    font-size: 40px;
    color: #4a6cf7;
}

.title h4 {
    margin-top: 10px;
    font-weight: bold;
}

.form-control {
    border-radius: 10px;
}

.btn-login {
    border-radius: 10px;
    background: linear-gradient(135deg, #4a6cf7, #6a8dff);
    border: none;
    color: white;
    transition: 0.3s;
}

.btn-login:hover {
    transform: scale(1.03);
}

.password-wrapper {
    position: relative;
}

.password-wrapper input {
    padding-right: 40px; /* 留空間給眼睛 */
}

.password-wrapper i {
    position: absolute;
    right: 12px;
    top: 38px;  /* ⭐關鍵：直接固定在 input 中間 */
    cursor: pointer;
    color: #666;
}

.footer-text {
    font-size: 13px;
    text-align: center;
    margin-top: 15px;
    color: #666;
}
</style>
</head>

<body>

<div class="login-card">

    <div class="title">
        <i class="bi bi-building"></i>
        <h4>課指組管理系統</h4>
    </div>

    <?php if ($error): ?>
        <div class="alert alert-danger"><?php echo $error; ?></div>
    <?php endif; ?>

    <form method="POST" onsubmit="showLoading()">

        <div class="mb-3">
            <label class="form-label">帳號（學號）</label>
            <input 
                type="text" 
                name="email" 
                class="form-control"
                value="<?php echo htmlspecialchars($email_value); ?>"
                placeholder="請輸入學號"
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
        請先在資料庫 users 表新增帳號
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