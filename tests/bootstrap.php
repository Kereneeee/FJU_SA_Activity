<?php
// PHPUnit bootstrap — set up DB connection for tests
define('PHPUNIT_RUNNING', true);

require_once __DIR__ . '/../vendor/autoload.php';

// Minimal DB bootstrap (suppress session_start inside db_config)
$_SERVER['REQUEST_METHOD'] = 'GET';
require_once __DIR__ . '/../DB/db_config.php';

// Expose $conn globally so test cases can access it
$GLOBALS['conn'] = $conn;

// Stub SMTP constants for CI/test environments where mail_config.php is gitignored
if (!file_exists(__DIR__ . '/../includes/mail_config.php')) {
    define('SMTP_HOST',         'localhost');
    define('SMTP_PORT',         587);
    define('SMTP_USERNAME',     'phpunit@test.local');
    define('SMTP_PASSWORD',     'phpunit_test_password');
    define('MAIL_FROM_ADDRESS', 'noreply@test.local');
    define('MAIL_FROM_NAME',    '課指組系統');
}
