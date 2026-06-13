<?php
// 統一關閉錯誤顯示（不在畫面上洩漏路徑與語法）
ini_set('display_errors', 0);
ini_set('display_startup_errors', 0);
ini_set('log_errors', 1);
error_reporting(E_ALL);

//資料庫連線（UTF-8設定）
date_default_timezone_set('Asia/Taipei'); // 統一使用台灣時區（UTC+8）

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
$conn = new mysqli("localhost","root","12345678","fjusa");

if($conn->connect_error){
 die("連線失敗");
}

// MySQL 連線也設定為台灣時區
$conn->query("SET time_zone = '+08:00'")
?>