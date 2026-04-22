//資料庫連線（UTF-8設定）<?php
session_start();
$conn = new mysqli("localhost","root","12345678","school");

if($conn->connect_error){
 die("連線失敗");
}
?>