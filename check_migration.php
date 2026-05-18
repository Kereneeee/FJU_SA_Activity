<?php
/**
 * 自動遷移檢查腳本
 * 檢查並添加所有缺失的數據庫列
 */

require_once(__DIR__ . "/DB/db_config.php");

echo "=== 開始數據庫遷移檢查 ===\n\n";

$migrations = [
    // equipment_borrow 表的遷移
    [
        'table' => 'equipment_borrow',
        'column' => 'status',
        'sql' => "ALTER TABLE `equipment_borrow` ADD COLUMN `status` VARCHAR(50) NOT NULL DEFAULT 'pending' COMMENT '器材審核狀態' AFTER `quantity`"
    ],
    [
        'table' => 'equipment_borrow',
        'column' => 'created_at',
        'sql' => "ALTER TABLE `equipment_borrow` ADD COLUMN `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP COMMENT '申請時間' AFTER `status`"
    ],
    [
        'table' => 'equipment_borrow',
        'column' => 'updated_at',
        'sql' => "ALTER TABLE `equipment_borrow` ADD COLUMN `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP COMMENT '最後更新時間' AFTER `created_at`"
    ],
    [
        'table' => 'equipment_borrow',
        'column' => 'review_note',
        'sql' => "ALTER TABLE `equipment_borrow` ADD COLUMN `review_note` TEXT NULL COMMENT '審核意見' AFTER `updated_at`"
    ],
    // events 表的遷移
    [
        'table' => 'events',
        'column' => 'created_at',
        'sql' => "ALTER TABLE `events` ADD COLUMN `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP COMMENT '申請時間' AFTER `review_note`"
    ],
    [
        'table' => 'events',
        'column' => 'updated_at',
        'sql' => "ALTER TABLE `events` ADD COLUMN `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP COMMENT '最後更新時間' AFTER `created_at`"
    ]
];

$success_count = 0;
$skip_count = 0;
$error_count = 0;

foreach ($migrations as $migration) {
    $table = $migration['table'];
    $column = $migration['column'];
    $sql = $migration['sql'];
    
    // 檢查列是否存在
    $check_sql = "SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS 
                  WHERE TABLE_SCHEMA = DATABASE() 
                  AND TABLE_NAME = '{$table}' 
                  AND COLUMN_NAME = '{$column}'";
    
    $result = $conn->query($check_sql);
    
    if ($result && $result->num_rows > 0) {
        echo "✓ 列已存在：{$table}.{$column}\n";
        $skip_count++;
    } else {
        // 執行遷移
        if ($conn->query($sql)) {
            echo "✓ 已添加列：{$table}.{$column}\n";
            $success_count++;
        } else {
            echo "✗ 添加失敗：{$table}.{$column}\n";
            echo "  錯誤：" . $conn->error . "\n";
            $error_count++;
        }
    }
}

echo "\n=== 遷移結果 ===\n";
echo "成功添加：{$success_count}\n";
echo "已存在：{$skip_count}\n";
echo "失敗：{$error_count}\n";

if ($error_count === 0) {
    echo "\n✓ 所有遷移完成！系統已準備好使用。\n";
} else {
    echo "\n✗ 部分遷移失敗，請檢查錯誤信息。\n";
}

?>
