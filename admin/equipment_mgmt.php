<?php
require_once '../DB/db_config.php';

global $conn;

if (!$conn || $conn->connect_error) {
    die("錯誤：無法連接資料庫 - " . ($conn->connect_error ?? '未知錯誤'));
}

$conn->set_charset("utf8mb4");

// 取得器材列表
$sql = "SELECT * FROM equipment ORDER BY equipment_id ASC";
$result = $conn->query($sql);
if (!$result) {
    die("查詢錯誤: " . $conn->error);
}
$equipment_list = $result->fetch_all(MYSQLI_ASSOC);
if (!$equipment_list) $equipment_list = [];

// 統計資料
$total_equipment = count($equipment_list);
$total_quantity = 0;
$available_quantity = 0;
foreach ($equipment_list as $eq) {
    $total_quantity += intval($eq['total_quantity']);
    $available_quantity += intval($eq['available_quantity']);
}
$borrowed_quantity = $total_quantity - $available_quantity;
?>

<!DOCTYPE html>
<html lang="zh-TW">
<head>
    <meta charset="UTF-8">
    <title>器材管理 - EAMS 系統</title>
    <style>
        body { font-family: "Microsoft JhengHei", sans-serif; background-color: #f4f1de; margin: 0; }
        
        /* 側邊欄 */
        .sidebar { width: 260px; background: #646d8a; color: white; padding: 30px 20px; position: fixed; height: 100%; box-sizing: border-box; }
        .sidebar h3 { margin-top: 0; }
        .sidebar a { color: white; text-decoration: none; padding: 12px; margin: 5px 0; border-radius: 8px; display: block; transition: 0.3s; }
        .sidebar a:hover { background: #8d9da5; }
        .sidebar .admin-section { margin-top: 15px; padding-top: 15px; border-top: 1px solid rgba(255,255,255,0.3); }
        .sidebar .admin-label { color: #ddbea9; font-size: 12px; padding: 12px 12px 5px; display: block; }
        
        /* 主內容 */
        .main-content { margin-left: 260px; padding: 25px; min-height: 100vh; box-sizing: border-box; }
        
        /* 統計卡片 */
        .stats-grid { display: grid; grid-template-columns: repeat(4, 1fr); gap: 20px; margin-bottom: 30px; }
        .stat-card { background: white; padding: 20px; border-radius: 12px; box-shadow: 0 2px 10px rgba(0,0,0,0.1); }
        .stat-card h4 { margin: 0 0 10px 0; color: #6b705c; font-size: 14px; }
        .stat-card .number { font-size: 32px; font-weight: bold; color: #646d8a; }
        .stat-card.total { border-left: 4px solid #646d8a; }
        .stat-card.available { border-left: 4px solid #2a9d8f; }
        .stat-card.borrowed { border-left: 4px solid #f4a261; }
        .stat-card.items { border-left: 4px solid #e9c46a; }
        
        /* 表格 */
        .table-container { background: white; border-radius: 12px; padding: 20px; box-shadow: 0 2px 10px rgba(0,0,0,0.1); }
        table { width: 100%; border-collapse: collapse; }
        th, td { padding: 12px; text-align: left; border-bottom: 1px solid #eee; }
        th { background: #f8f9fa; color: #6b705c; font-weight: 600; }
        tr:hover { background: #f8f9fa; }
        
        /* 狀態標籤 */
        .status-badge { padding: 4px 12px; border-radius: 20px; font-size: 12px; }
        .status-available { background: #d1fae5; color: #059669; }
        .status-unavailable { background: #fee2e2; color: #dc2626; }
        
        /* 數量進度條 */
        .quantity-bar { display: flex; align-items: center; gap: 10px; }
        .quantity-bar .bar-bg { flex: 1; height: 8px; background: #eee; border-radius: 4px; overflow: hidden; }
        .quantity-bar .bar-fill { height: 100%; background: #2a9d8f; border-radius: 4px; }
        .quantity-bar .bar-borrowed { background: #f4a261; }
        .quantity-bar .text { font-size: 12px; color: #666; min-width: 60px; }
        
        h2 { color: #6b705c; margin-top: 0; }
        
        .info-text { color: #666; font-size: 13px; margin-top: 10px; }
    </style>
</head>
<body>

<div class="sidebar">
    <h3>EAMS 系統</h3>
    <a href="dashboard.php">🏠 首頁控制台</a>
    <a href="calendar.php">📅 完整行事曆</a>
    <a href="apply_event.php">📝 申請借用</a>
    
    <div class="admin-section">
        <span class="admin-label">⚙️ 後台管理介面</span>
        <a href="event_mgmt.php">📋 活動管理</a>
        <a href="equipment_mgmt.php">🔧 器材管理</a>
    </div>

    <div style="margin-top: auto; padding-top: 20px;">
        <a href="../logout.php" style="color: #ddbea9;">🚪 登出系統</a>
    </div>
</div>

<div class="main-content">
    <h2>🔧 器材管理</h2>
    
    <!-- 統計卡片 -->
    <div class="stats-grid">
        <div class="stat-card items">
            <h4>📦 器材種類數</h4>
            <div class="number"><?php echo $total_equipment; ?></div>
        </div>
        <div class="stat-card total">
            <h4>🔢 總數量</h4>
            <div class="number"><?php echo $total_quantity; ?></div>
        </div>
        <div class="stat-card available">
            <h4>✅ 可借數量</h4>
            <div class="number"><?php echo $available_quantity; ?></div>
        </div>
        <div class="stat-card borrowed">
            <h4>📤 借出數量</h4>
            <div class="number"><?php echo $borrowed_quantity; ?></div>
        </div>
    </div>
    
    <!-- 器材列表 -->
    <div class="table-container">
        <table>
            <thead>
                <tr>
                    <th>器材名稱</th>
                    <th>總數量</th>
                    <th>可借數量</th>
                    <th>借出數量</th>
                    <th>使用狀態</th>
                    <th>庫存狀態</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($equipment_list as $eq): ?>
                <?php 
                    $borrowed = $eq['total_quantity'] - $eq['available_quantity'];
                    $usage_percent = $eq['total_quantity'] > 0 ? ($borrowed / $eq['total_quantity']) * 100 : 0;
                ?>
                <tr>
                    <td><strong><?php echo htmlspecialchars($eq['name']); ?></strong></td>
                    <td><?php echo $eq['total_quantity']; ?></td>
                    <td><?php echo $eq['available_quantity']; ?></td>
                    <td><?php echo $borrowed; ?></td>
                    <td>
                        <span class="status-badge status-<?php echo $eq['status']; ?>">
                            <?php echo $eq['status'] === 'available' ? '可借用' : '不可借用'; ?>
                        </span>
                    </td>
                    <td>
                        <div class="quantity-bar">
                            <div class="bar-bg">
                                <div class="bar-fill <?php echo $usage_percent > 80 ? 'bar-borrowed' : ''; ?>" style="width: <?php echo 100 - $usage_percent; ?>%"></div>
                            </div>
                            <span class="text"><?php echo round(100 - $usage_percent); ?>%</span>
                        </div>
                    </td>
                </tr>
                <?php endforeach; ?>
                
                <?php if (empty($equipment_list)): ?>
                <tr>
                    <td colspan="6" style="text-align: center; color: #999; padding: 30px;">目前沒有器材記錄</td>
                </tr>
                <?php endif; ?>
            </tbody>
        </table>
        
        <p class="info-text">💡 備註：學生可透過「學生專區 > 器材借用」頁面新增借用申請，借出數量將自動更新。</p>
    </div>
</div>

</body>
</html>