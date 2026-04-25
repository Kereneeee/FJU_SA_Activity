<?php
require_once '../DB/db_config.php';

global $conn;

if (!$conn || $conn->connect_error) {
    die("錯誤：無法連接資料庫 - " . ($conn->connect_error ?? '未知錯誤'));
}

$conn->set_charset("utf8mb4");

// 處理審核動作
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    $event_id = $_POST['event_id'] ?? 0;
    $action = $_POST['action'];
    $review_note = $_POST['review_note'] ?? '';
    
    if ($action === 'approve') {
        $status = 'approved';
    } elseif ($action === 'reject') {
        $status = 'rejected';
    } else {
        $status = 'pending';
    }
    
    $stmt = $conn->prepare("UPDATE events SET status = ?, review_note = ? WHERE event_id = ?");
    $stmt->bind_param("ssi", $status, $review_note, $event_id);
    $stmt->execute();
    $stmt->close();
    
    header("Location: event_mgmt.php");
    exit;
}

// 取得活動列表（包含申請人資訊）
$sql = "
    SELECT e.*, u.name as applicant_name, u.email as applicant_email, u.username,
           s.space_name, r.start_time as reservation_start, r.end_time as reservation_end
    FROM events e
    JOIN users u ON e.user_id = u.user_id
    LEFT JOIN reservations r ON e.event_id = r.event_id
    LEFT JOIN spaces s ON r.space_id = s.space_id
    ORDER BY e.start_time DESC
";
$result = $conn->query($sql);
if (!$result) {
    die("查詢錯誤: " . $conn->error);
}
$events = $result->fetch_all(MYSQLI_ASSOC);
if (!$events) $events = [];

// 統計資料
$total_count = count($events);
$pending_count = 0;
$approved_count = 0;
$rejected_count = 0;
foreach ($events as $e) {
    if ($e['status'] === 'pending') $pending_count++;
    if ($e['status'] === 'approved') $approved_count++;
    if ($e['status'] === 'rejected') $rejected_count++;
}
?>

<!DOCTYPE html>
<html lang="zh-TW">
<head>
    <meta charset="UTF-8">
    <title>活動管理 - EAMS 系統</title>
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
        .main-content { margin-left: 260px; padding: 25px; }
        
        /* 統計卡片 */
        .stats-grid { display: grid; grid-template-columns: repeat(4, 1fr); gap: 20px; margin-bottom: 30px; }
        .stat-card { background: white; padding: 20px; border-radius: 12px; box-shadow: 0 2px 10px rgba(0,0,0,0.1); }
        .stat-card h4 { margin: 0 0 10px 0; color: #6b705c; font-size: 14px; }
        .stat-card .number { font-size: 32px; font-weight: bold; color: #646d8a; }
        .stat-card.pending { border-left: 4px solid #f4a261; }
        .stat-card.approved { border-left: 4px solid #2a9d8f; }
        .stat-card.rejected { border-left: 4px solid #e76f51; }
        .stat-card.total { border-left: 4px solid #646d8a; }
        
        /* 表格 */
        .table-container { background: white; border-radius: 12px; padding: 20px; box-shadow: 0 2px 10px rgba(0,0,0,0.1); }
        table { width: 100%; border-collapse: collapse; }
        th, td { padding: 12px; text-align: left; border-bottom: 1px solid #eee; }
        th { background: #f8f9fa; color: #6b705c; font-weight: 600; }
        tr:hover { background: #f8f9fa; }
        
        /* 狀態標籤 */
        .status-badge { padding: 4px 12px; border-radius: 20px; font-size: 12px; }
        .status-pending { background: #fef3c7; color: #d97706; }
        .status-approved { background: #d1fae5; color: #059669; }
        .status-rejected { background: #fee2e2; color: #dc2626; }
        
        /* 按鈕 */
        .btn { padding: 6px 14px; border: none; border-radius: 6px; cursor: pointer; font-size: 13px; margin-right: 5px; }
        .btn-approve { background: #2a9d8f; color: white; }
        .btn-reject { background: #e76f51; color: white; }
        .btn-view { background: #646d8a; color: white; }
        
        /* 審核表單 */
        .review-form { display: inline; }
        .review-note { width: 150px; padding: 4px 8px; border: 1px solid #ddd; border-radius: 4px; }
        
        h2 { color: #6b705c; margin-top: 0; }
    </style>
</head>
<body>

<div class="sidebar">
    <h3>EAMS 系統</h3>
    <a href="dashboard.php"> 首頁控制台</a>
    <a href="../student/calendar.php"> 完整行事曆</a>
    <a href="apply_event.php"> 申請借用</a>
    
    <div class="admin-section">
        <span class="admin-label">⚙️ 後台管理介面</span>
        <a href="event_mgmt.php"> 活動管理</a>
        <a href="equipment_mgmt.php"> 器材管理</a>
    </div>

    <div style="margin-top: auto; padding-top: 20px;">
        <a href="../logout.php" style="color: #ddbea9;">🚪 登出系統</a>
    </div>
</div>

<div class="main-content">
    <h2>📋 活動管理</h2>
    
    <!-- 統計卡片 -->
    <div class="stats-grid">
        <div class="stat-card total">
            <h4>📊 總申請件數</h4>
            <div class="number"><?php echo $total_count; ?></div>
        </div>
        <div class="stat-card pending">
            <h4>⏳ 待審核</h4>
            <div class="number"><?php echo $pending_count; ?></div>
        </div>
        <div class="stat-card approved">
            <h4>✅ 已通過</h4>
            <div class="number"><?php echo $approved_count; ?></div>
        </div>
        <div class="stat-card rejected">
            <h4>❌ 已駁回</h4>
            <div class="number"><?php echo $rejected_count; ?></div>
        </div>
    </div>
    
    <!-- 活動列表 -->
    <div class="table-container">
        <table>
            <thead>
                <tr>
                    <th>活動名稱</th>
                    <th>申請人</th>
                    <th>聯絡Email</th>
                    <th>社團名稱</th>
                    <th>借用場地</th>
                    <th>活動時間</th>
                    <th>狀態</th>
                    <th>操作</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($events as $event): ?>
                <tr>
                    <td><?php echo htmlspecialchars($event['event_name']); ?></td>
                    <td><?php echo htmlspecialchars($event['applicant_name']); ?> (<?php echo htmlspecialchars($event['username']); ?>)</td>
                    <td><?php echo htmlspecialchars($event['applicant_email']); ?></td>
                    <td><?php echo htmlspecialchars($event['club_name']); ?></td>
                    <td><?php echo htmlspecialchars($event['space_name'] ?? '未指定'); ?></td>
                    <td>
                        <?php 
                        if ($event['start_time']) {
                            echo date('Y/m/d H:i', strtotime($event['start_time'])) . '<br>';
                            echo '~ ' . date('H:i', strtotime($event['end_time']));
                        }
                        ?>
                    </td>
                    <td>
                        <span class="status-badge status-<?php echo $event['status']; ?>">
                            <?php 
                            $status_text = ['pending' => '待審核', 'approved' => '已通過', 'rejected' => '已駁回'];
                            echo $status_text[$event['status']] ?? $event['status'];
                            ?>
                        </span>
                    </td>
                    <td>
                        <?php if ($event['status'] === 'pending'): ?>
                        <form method="POST" class="review-form">
                            <input type="hidden" name="event_id" value="<?php echo $event['event_id']; ?>">
                            <input type="text" name="review_note" class="review-note" placeholder="審核備註">
                            <button type="submit" name="action" value="approve" class="btn btn-approve">通過</button>
                            <button type="submit" name="action" value="reject" class="btn btn-reject">駁回</button>
                        </form>
                        <?php else: ?>
                        <span style="color: #999; font-size: 12px;"><?php echo htmlspecialchars($event['review_note'] ?? '無'); ?></span>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php endforeach; ?>
                
                <?php if (empty($events)): ?>
                <tr>
                    <td colspan="8" style="text-align: center; color: #999; padding: 30px;">目前沒有活動申請記錄</td>
                </tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

</body>
</html>