<?php
session_start();

// 清除所有 session 資料
$_SESSION = [];

// 如果有 session cookie，將它刪除
if (ini_get("session.use_cookies")) {
    $params = session_get_cookie_params();
    setcookie(
        session_name(),
        '',
        time() - 42000,
        $params['path'],
        $params['domain'],
        $params['secure'],
        $params['httponly']
    );
}

session_destroy();

// 導回登入頁
header('Location: login.php');
exit;
?>
