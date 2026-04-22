<?php
// functions.php - 共用函數

// 檢查登入狀態
function checkLogin() {
    if (!isset($_SESSION['user_id'])) {
        header('Location: ../login.php');
        exit;
    }
}

// 檢查是否為 admin
function checkAdmin() {
    checkLogin();
    if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'admin') {
        die('只有管理員可以進入此頁面');
    }
}

// 其他可能需要的函數可以在此添加
?>
