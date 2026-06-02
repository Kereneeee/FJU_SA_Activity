<?php
require_once(__DIR__ . "/../DB/db_config.php");
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') { die('Access denied'); }

$migrations = [
    'migration_field_coordination.sql'   => '場協相關資料表',
    'migration_event_fields.sql'         => '活動申請新欄位',
    'migration_officer_nominations.sql'  => '幹部提名資料表',
];

$target = $_GET['file'] ?? 'migration_field_coordination.sql';
if (!array_key_exists($target, $migrations)) { die('Invalid migration file'); }

$sql_file = __DIR__ . "/../database/" . $target;
if (!file_exists($sql_file)) { die("Migration 檔案不存在：$sql_file"); }
$sql_content = file_get_contents($sql_file);

$statements = array_filter(array_map('trim', explode(';', $sql_content)));

echo "<pre style='font-family:monospace; padding:20px;'>";
echo "=== 執行 Migration：" . htmlspecialchars($migrations[$target]) . " ===\n\n";

$ok = 0; $err = 0;
foreach ($statements as $stmt_sql) {
    if (empty($stmt_sql) || strpos($stmt_sql, '--') === 0) continue;
    if ($conn->query($stmt_sql)) {
        $ok++;
        echo "✅ 成功\n";
    } else {
        if (strpos($conn->error, 'Duplicate') !== false ||
            strpos($conn->error, 'already exists') !== false) {
            echo "ℹ️  略過（已存在）\n";
        } else {
            echo "❌ 錯誤：" . $conn->error . "\n";
            $err++;
        }
    }
}

echo "\n完成！成功 $ok 筆，錯誤 $err 筆\n\n";
echo "=== 其他可執行的 Migration ===\n";
foreach ($migrations as $file => $label) {
    echo '<a href="run_migration.php?file=' . urlencode($file) . '">' . htmlspecialchars($label) . '</a>' . "\n";
}
echo '<br><a href="check_db.php">返回資料表檢查</a>';
echo "</pre>";
?>
