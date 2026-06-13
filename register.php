<?php

require_once(__DIR__ . "/DB/db_config.php");

if (isset($_SESSION['user_id'])) {
    header("Location: " . ($_SESSION['role'] === 'admin' ? 'admin/dashboard.php' : 'student/dashboard.php'));
    exit();
}

$errors  = [];
$success = false;
$form    = ['name' => '', 'student_id' => '', 'email' => '', 'phone' => ''];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $name       = trim($_POST['name']            ?? '');
    $student_id = trim($_POST['student_id']      ?? '');
    $email      = trim($_POST['email']           ?? '');
    $phone      = trim($_POST['phone']           ?? '');
    $password   = $_POST['password']             ?? '';
    $confirm    = $_POST['confirm_password']     ?? '';

    $form = compact('name', 'student_id', 'email', 'phone');

    if (empty($name))              $errors['name']       = '請輸入姓名';
    elseif (mb_strlen($name) > 50) $errors['name']       = '姓名不可超過 50 字';

    if (empty($student_id))        $errors['student_id'] = '請輸入學號';
    elseif (!ctype_digit($student_id)) $errors['student_id'] = '學號只能含數字';

    if (empty($email))             $errors['email']      = '請輸入電子信箱';
    elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) $errors['email'] = '電子信箱格式不正確';

    if (!empty($phone) && !preg_match('/^[0-9+\-\s]{7,20}$/', $phone))
                                   $errors['phone']      = '電話格式不正確';

    if (empty($password))          $errors['password']   = '請輸入密碼';
    elseif (strlen($password) < 6) $errors['password']   = '密碼至少需要 6 個字元';

    if (empty($confirm))           $errors['confirm']    = '請再次輸入密碼';
    elseif ($password !== $confirm)$errors['confirm']    = '兩次輸入的密碼不一致';

    if (empty($errors['email'])) {
        $chk = $conn->prepare("SELECT user_id FROM users WHERE email = ?");
        $chk->bind_param("s", $email);
        $chk->execute();
        if ($chk->get_result()->num_rows > 0)
            $errors['email'] = '此電子信箱已被註冊';
    }

    if (empty($errors)) {
        $sid = (int)$student_id;
        $phone_val = $phone === '' ? null : $phone;
        $hashed = password_hash($password, PASSWORD_DEFAULT);
        $ins = $conn->prepare("INSERT INTO users (name, student_id, email, phone, password, role) VALUES (?, ?, ?, ?, ?, 'student')");
        $ins->bind_param("sisss", $name, $sid, $email, $phone_val, $hashed);
        if ($ins->execute()) {
            $success = true;
        } else {
            $errors['general'] = '註冊失敗，請稍後再試';
        }
    }
}

function fe($k, $errors) { return isset($errors[$k]) ? 'is-invalid' : ''; }
?>
<!DOCTYPE html>
<html lang="zh-TW">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>建立帳號 — 課指組管理系統</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.5/font/bootstrap-icons.css" rel="stylesheet">
<link href="https://fonts.googleapis.com/css2?family=Nunito:wght@400;600;700;800&display=swap" rel="stylesheet">
<style>
* { box-sizing: border-box; margin: 0; padding: 0; }

body {
    font-family: 'Nunito', 'Segoe UI', sans-serif;
    min-height: 100vh;
    background: #d5e3ea;
    display: flex;
    align-items: flex-start;
    justify-content: center;
    padding: 40px 16px;
}

.page-wrap {
    width: 100%;
    max-width: 520px;
}

/* 頁首 */
.page-header {
    text-align: center;
    margin-bottom: 24px;
}
.page-header .logo-box {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    width: 56px; height: 56px;
    border-radius: 16px;
    background: #1e4d6b;
    color: white;
    font-size: 26px;
    margin-bottom: 12px;
    box-shadow: 0 8px 20px rgba(30,77,107,.3);
}
.page-header h1 {
    font-size: 24px;
    font-weight: 800;
    color: #1e293b;
}
.page-header p {
    font-size: 13px;
    color: #94a3b8;
    margin-top: 4px;
}

/* 卡片 */
.card-main {
    background: white;
    border-radius: 20px;
    padding: 32px;
    box-shadow: 0 4px 30px rgba(0,0,0,.07);
}

/* 步驟指示器 */
.steps {
    display: flex;
    gap: 0;
    margin-bottom: 28px;
    border-radius: 10px;
    overflow: hidden;
    background: #f1f5f9;
    padding: 4px;
}
.step {
    flex: 1;
    text-align: center;
    padding: 8px 4px;
    border-radius: 8px;
    font-size: 12px;
    font-weight: 700;
    color: #94a3b8;
    transition: all .2s;
}
.step.active {
    background: white;
    color: #1e4d6b;
    box-shadow: 0 2px 8px rgba(0,0,0,.08);
}
.step span {
    display: block;
    font-size: 10px;
    font-weight: 600;
    margin-top: 1px;
    opacity: .7;
}

/* 欄位 */
.form-label {
    font-size: 13px;
    font-weight: 700;
    color: #475569;
    margin-bottom: 6px;
}
.form-label .badge-opt {
    font-size: 10px;
    font-weight: 600;
    color: #94a3b8;
    background: #f1f5f9;
    border-radius: 6px;
    padding: 1px 6px;
    margin-left: 6px;
}
.form-control {
    border-radius: 12px;
    border: 1.5px solid #e2e8f0;
    padding: 11px 14px;
    font-size: 14px;
    color: #1e293b;
    background: #f8fafc;
    font-family: inherit;
    transition: border-color .2s, box-shadow .2s;
}
.form-control:focus {
    border-color: #1e4d6b;
    box-shadow: 0 0 0 3px rgba(30,77,107,.12);
    background: #fff;
    outline: none;
}
.form-control::placeholder { color: #cbd5e1; }
.form-control.is-invalid { border-color: #ef4444; background: #fff; }
.invalid-feedback { font-size: 12px; color: #ef4444; margin-top: 4px; display: block; }

/* 兩欄 */
.row-2 { display: grid; grid-template-columns: 1fr 1fr; gap: 16px; }

/* 密碼 */
.pwd-wrap { position: relative; }
.pwd-wrap .form-control { padding-right: 44px; }
.pwd-wrap .eye-btn {
    position: absolute; right: 14px; top: 50%;
    transform: translateY(-50%);
    background: none; border: none; padding: 0;
    color: #94a3b8; cursor: pointer; font-size: 15px;
}
.pwd-wrap .eye-btn:hover { color: #1e4d6b; }

/* 強度條 */
.strength-wrap { margin-top: 6px; }
.strength-bar {
    height: 4px; border-radius: 99px;
    background: #e2e8f0; overflow: hidden;
}
.strength-fill {
    height: 100%; border-radius: 99px;
    width: 0%; transition: width .3s, background .3s;
}
.strength-label {
    font-size: 11px; color: #94a3b8; margin-top: 3px;
}

/* 密碼比對 */
.match-msg { font-size: 12px; margin-top: 4px; min-height: 16px; }

/* 按鈕 */
.btn-submit {
    width: 100%; padding: 13px;
    border-radius: 12px;
    background: #1e4d6b;
    border: none; color: white;
    font-size: 15px; font-weight: 700;
    letter-spacing: .3px;
    transition: all .25s;
    font-family: inherit;
    margin-top: 4px;
    cursor: pointer;
}
.btn-submit:hover {
    background: #14394f;
    transform: translateY(-2px);
    box-shadow: 0 8px 20px rgba(30,77,107,.35);
}

/* 底部連結 */
.bottom-link {
    text-align: center;
    margin-top: 20px;
    font-size: 14px;
    color: #94a3b8;
}
.bottom-link a {
    color: #1e4d6b; font-weight: 700;
    text-decoration: none;
}
.bottom-link a:hover { text-decoration: underline; }

/* 成功畫面 */
.success-box {
    text-align: center;
    padding: 20px 0;
}
.success-icon {
    width: 80px; height: 80px;
    background: linear-gradient(135deg, #10b981, #34d399);
    border-radius: 50%;
    display: inline-flex; align-items: center; justify-content: center;
    font-size: 36px; color: white;
    box-shadow: 0 8px 24px rgba(16,185,129,.3);
    margin-bottom: 20px;
}

@media (max-width: 480px) {
    .row-2 { grid-template-columns: 1fr; }
    .card-main { padding: 24px 20px; }
}
</style>
</head>
<body>

<div class="page-wrap">

    <div class="page-header">
        <div class="logo-box"><i class="bi bi-mortarboard-fill"></i></div>
        <h1>建立帳號</h1>
        <p>課指組管理系統 · 輔仁大學</p>
    </div>

    <div class="card-main">

    <?php if ($success): ?>
        <div class="success-box">
            <div class="success-icon"><i class="bi bi-check-lg"></i></div>
            <h4 style="font-weight:800;color:#1e293b;margin-bottom:8px;">註冊成功！🎉</h4>
            <p style="color:#64748b;font-size:14px;line-height:1.7;margin-bottom:24px;">
                帳號已建立完成。<br>請使用您的電子信箱登入系統。
            </p>
            <a href="login.php" class="btn-submit" style="display:block;text-decoration:none;text-align:center;">
                <i class="bi bi-box-arrow-in-right me-1"></i>前往登入
            </a>
        </div>

    <?php else: ?>

        <?php if (!empty($errors['general'])): ?>
        <div style="background:#fef2f2;color:#dc2626;border-radius:12px;padding:12px 16px;margin-bottom:20px;font-size:13px;display:flex;align-items:center;gap:8px;">
            <i class="bi bi-exclamation-circle-fill"></i>
            <?= htmlspecialchars($errors['general']) ?>
        </div>
        <?php endif; ?>

        <div class="steps">
            <div class="step active">① 基本資料<span>姓名 · 學號</span></div>
            <div class="step active">② 帳號設定<span>信箱 · 密碼</span></div>
        </div>

        <form method="POST" id="registerForm" novalidate>

            <!-- 姓名 + 學號 -->
            <div class="row-2 mb-3">
                <div>
                    <label class="form-label">姓名</label>
                    <input type="text" name="name"
                        class="form-control <?= fe('name',$errors) ?>"
                        value="<?= htmlspecialchars($form['name']) ?>"
                        placeholder="王小明" maxlength="50">
                    <?php if (isset($errors['name'])): ?>
                        <div class="invalid-feedback"><?= htmlspecialchars($errors['name']) ?></div>
                    <?php endif; ?>
                </div>
                <div>
                    <label class="form-label">學號</label>
                    <input type="text" name="student_id"
                        class="form-control <?= fe('student_id',$errors) ?>"
                        value="<?= htmlspecialchars($form['student_id']) ?>"
                        placeholder="410123456" inputmode="numeric">
                    <?php if (isset($errors['student_id'])): ?>
                        <div class="invalid-feedback"><?= htmlspecialchars($errors['student_id']) ?></div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- 電子信箱 -->
            <div class="mb-3">
                <label class="form-label">電子信箱</label>
                <input type="email" name="email"
                    class="form-control <?= fe('email',$errors) ?>"
                    value="<?= htmlspecialchars($form['email']) ?>"
                    placeholder="s410123456@mail.fju.edu.tw">
                <?php if (isset($errors['email'])): ?>
                    <div class="invalid-feedback"><?= htmlspecialchars($errors['email']) ?></div>
                <?php else: ?>
                    <div style="font-size:11px;color:#94a3b8;margin-top:4px;">
                        <i class="bi bi-info-circle me-1"></i>作為登入帳號，忘記密碼時也會寄到此信箱
                    </div>
                <?php endif; ?>
            </div>

            <!-- 電話 -->
            <div class="mb-3">
                <label class="form-label">
                    電話 <span class="badge-opt">選填</span>
                </label>
                <input type="tel" name="phone"
                    class="form-control <?= fe('phone',$errors) ?>"
                    value="<?= htmlspecialchars($form['phone']) ?>"
                    placeholder="0912-345-678">
                <?php if (isset($errors['phone'])): ?>
                    <div class="invalid-feedback"><?= htmlspecialchars($errors['phone']) ?></div>
                <?php endif; ?>
            </div>

            <!-- 密碼 -->
            <div class="mb-3">
                <label class="form-label">密碼</label>
                <div class="pwd-wrap">
                    <input type="password" id="password" name="password"
                        class="form-control <?= fe('password',$errors) ?>"
                        placeholder="至少 6 個字元"
                        oninput="checkStrength(this.value);checkMatch()">
                    <button type="button" class="eye-btn" onclick="togglePwd('password','ei1')">
                        <i class="bi bi-eye" id="ei1"></i>
                    </button>
                </div>
                <?php if (isset($errors['password'])): ?>
                    <div class="invalid-feedback"><?= htmlspecialchars($errors['password']) ?></div>
                <?php else: ?>
                <div class="strength-wrap">
                    <div class="strength-bar"><div class="strength-fill" id="sBar"></div></div>
                    <div class="strength-label" id="sLabel"></div>
                </div>
                <?php endif; ?>
            </div>

            <!-- 確認密碼 -->
            <div class="mb-4">
                <label class="form-label">確認密碼</label>
                <div class="pwd-wrap">
                    <input type="password" id="confirm_password" name="confirm_password"
                        class="form-control <?= fe('confirm',$errors) ?>"
                        placeholder="再輸入一次"
                        oninput="checkMatch()">
                    <button type="button" class="eye-btn" onclick="togglePwd('confirm_password','ei2')">
                        <i class="bi bi-eye" id="ei2"></i>
                    </button>
                </div>
                <?php if (isset($errors['confirm'])): ?>
                    <div class="invalid-feedback"><?= htmlspecialchars($errors['confirm']) ?></div>
                <?php else: ?>
                    <div class="match-msg" id="matchMsg"></div>
                <?php endif; ?>
            </div>

            <button type="submit" id="submitBtn" class="btn-submit">
                <i class="bi bi-person-check me-1"></i>建立帳號
            </button>

        </form>

        <div class="bottom-link">
            已有帳號？<a href="login.php">立即登入</a>
        </div>

    <?php endif; ?>
    </div>
</div>

<script>
function togglePwd(inputId, iconId) {
    const el = document.getElementById(inputId);
    const ic = document.getElementById(iconId);
    el.type = el.type === 'password' ? 'text' : 'password';
    ic.className = el.type === 'password' ? 'bi bi-eye' : 'bi bi-eye-slash';
}

const LEVELS = [
    { w:'20%', c:'#ef4444', l:'太短' },
    { w:'40%', c:'#f97316', l:'弱' },
    { w:'60%', c:'#eab308', l:'普通' },
    { w:'80%', c:'#22c55e', l:'強' },
    { w:'100%',c:'#10b981', l:'非常強' },
];
function checkStrength(v) {
    const bar = document.getElementById('sBar');
    const lbl = document.getElementById('sLabel');
    if (!bar) return;
    let s = 0;
    if (v.length >= 6)  s++;
    if (v.length >= 10) s++;
    if (/[A-Z]/.test(v)) s++;
    if (/[0-9]/.test(v)) s++;
    if (/[^A-Za-z0-9]/.test(v)) s++;
    const lv = LEVELS[Math.max(0, s-1)];
    bar.style.width      = v.length ? lv.w : '0%';
    bar.style.background = lv.c;
    lbl.textContent      = v.length ? '密碼強度：' + lv.l : '';
    lbl.style.color      = lv.c;
}
function checkMatch() {
    const p1  = document.getElementById('password').value;
    const p2  = document.getElementById('confirm_password').value;
    const msg = document.getElementById('matchMsg');
    if (!msg || !p2) { if(msg) msg.textContent=''; return; }
    if (p1 === p2) {
        msg.innerHTML = '<span style="color:#10b981">✓ 密碼一致</span>';
    } else {
        msg.innerHTML = '<span style="color:#ef4444">✗ 密碼不一致</span>';
    }
}

document.getElementById('registerForm').addEventListener('submit', function(e) {
    const p1  = document.getElementById('password').value;
    const p2  = document.getElementById('confirm_password').value;
    const btn = document.getElementById('submitBtn');
    if (p1.length < 6) { e.preventDefault(); alert('密碼至少需要 6 個字元'); return; }
    if (p1 !== p2)     { e.preventDefault(); alert('兩次輸入的密碼不一致'); return; }
    btn.innerHTML = '<span class="spinner-border spinner-border-sm me-2"></span>建立中...';
    setTimeout(() => { btn.disabled = true; }, 0);
});
</script>
</body>
</html>
