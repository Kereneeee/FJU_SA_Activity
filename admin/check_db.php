<?php
require_once(__DIR__ . "/../DB/db_config.php");
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') { die('Access denied'); }

echo "<pre style='font-family:monospace; padding:20px;'>";
echo "=== 場協相關資料表檢查 ===\n\n";

$tables = ['field_coordination_settings', 'field_coordination_registrations', 'coordination_conflicts'];
foreach ($tables as $t) {
    $r = $conn->query("SHOW TABLES LIKE '$t'");
    $exists = $r && $r->num_rows > 0;
    echo ($exists ? "✅" : "❌") . " $t\n";
    if ($exists) {
        $cols = $conn->query("SHOW COLUMNS FROM `$t`");
        while ($c = $cols->fetch_assoc()) {
            echo "   - {$c['Field']} ({$c['Type']}) default={$c['Default']}\n";
        }
    }
    echo "\n";
}

// 如果表不存在，提供建立連結
$missing = false;
foreach ($tables as $t) {
    $r = $conn->query("SHOW TABLES LIKE '$t'");
    if (!$r || $r->num_rows === 0) { $missing = true; break; }
}

if ($missing) {
    echo "⚠️  有缺少的資料表，請點下方連結執行 migration：\n";
    echo '<a href="run_migration.php">執行場協 Migration</a>';
}

echo "\n=== 場協設定列表 ===\n";
$r2 = $conn->query("SELECT setting_id, academic_year, semester, status FROM field_coordination_settings");
if ($r2) {
    while ($row = $r2->fetch_assoc()) {
        echo "  #{$row['setting_id']}: {$row['academic_year']}-{$row['semester']} ({$row['status']})\n";
    }
} else {
    echo "查詢失敗：" . $conn->error . "\n";
}

echo "</pre>";
?>
