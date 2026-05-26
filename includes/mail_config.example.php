<?php
// ══════════════════════════════════════════════════════════════
//  郵件 SMTP 設定範本
//
//  使用方式：
//  1. 複製這個檔案，重新命名為 mail_config.php
//     cp mail_config.example.php mail_config.php
//
//  2. 填入你自己的 Gmail 帳號與應用程式密碼
//
//  3. mail_config.php 已加入 .gitignore，不會被 push 上去
//     !! 請勿把真實帳號密碼 commit 進去 !!
//
//  Gmail 應用程式密碼申請：
//  https://myaccount.google.com/apppasswords
//  （需要先開啟兩步驟驗證）
// ══════════════════════════════════════════════════════════════

// ── 寄件人設定 ─────────────────────────────────────────────────
define('MAIL_FROM_ADDRESS', 'your_gmail@gmail.com');   // ← 填你的 Gmail
define('MAIL_FROM_NAME',    '課指組管理系統');

// ── Gmail SMTP ─────────────────────────────────────────────────
define('SMTP_HOST',     'smtp.gmail.com');
define('SMTP_PORT',     587);
define('SMTP_USERNAME', 'your_gmail@gmail.com');       // ← 填你的 Gmail
define('SMTP_PASSWORD', 'xxxx xxxx xxxx xxxx');        // ← 16碼應用程式密碼

// ── 開發模式：true = 頁面顯示重設連結（方便測試），false = 只寄信 ──
define('MAIL_DEV_MODE', true);
