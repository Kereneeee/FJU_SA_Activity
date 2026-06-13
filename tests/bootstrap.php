<?php
// PHPUnit bootstrap — set up DB connection for tests
define('PHPUNIT_RUNNING', true);

require_once __DIR__ . '/../vendor/autoload.php';

// Minimal DB bootstrap (suppress session_start inside db_config)
$_SERVER['REQUEST_METHOD'] = 'GET';
require_once __DIR__ . '/../DB/db_config.php';

// Expose $conn globally so test cases can access it
$GLOBALS['conn'] = $conn;
