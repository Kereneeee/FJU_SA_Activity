<?php
// admin/review.php
require_once '../DB/db_config.php';

// 撈出所有待審核的活動，JOIN 申請人姓名
$sql = "
    SELECT 
        e.event_id,
        e.event_name,
        e.club_name,
        e.description,
        e.start_time,
        e.end_time,
        e.created_at,
        u.name AS applicant_name,
        u.email AS applicant_email
    FROM events e
    JOIN users u ON e.user_id = u.user_id
    WHERE e.status = 'pending'
    ORDER BY e.created_at ASC
";

$result = $conn->query($sql);
$pending_events = $result->fetch_all(MYSQLI_ASSOC);

// 對每筆活動，額外查它預約的場地和器材
foreach ($pending_events as &$event) {
    $eid = $event['event_id'];

    // 查場地
    $space_sql = "
        SELECT s.space_name, r.start_time, r.end_time
        FROM reservations r
        JOIN spaces s ON r.space_id = s.space_id
        WHERE r.event_id = $eid
    ";
    $event['spaces'] = $conn->query($space_sql)->fetch_all(MYSQLI_ASSOC);

    // 查器材
    $equip_sql = "
        SELECT eq.name, eb.quantity
        FROM equipment_borrow eb
        JOIN equipment eq ON eb.equipment_id = eq.equipment_id
        WHERE eb.event_id = $eid
    ";
    $event['equipment'] = $conn->query($equip_sql)->fetch_all(MYSQLI_ASSOC);
}
unset($event); // 解除 foreach 的參考
?>
<!DOCTYPE html>
<html lang="zh-Hant">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>審核申請</title>
    <link rel="stylesheet" href="../assets/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <style>
        body {
            background-color: #7685c7 ;
            min-height: 100vh;
            padding: 20px;
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
        }
        .page-header {
            background: white;
            padding: 30px;
            border-radius: 10px;
            margin-bottom: 30px;
            box-shadow: 0 5px 15px rgba(0,0,0,0.1);
        }
        .page-header h1 {
            color: #333;
            margin: 0;
            display: flex;
            align-items: center;
            gap: 10px;
        }
        .page-header p {
            color: #666;
            margin: 10px 0 0 0;
        }
        .review-container {
            max-width: 900px;
            margin: 0 auto;
        }
        .event-card {
            background: white;
            border-radius: 10px;
            margin-bottom: 25px;
            overflow: hidden;
            box-shadow: 0 5px 15px rgba(0,0,0,0.1);
            transition: all 0.3s ease;
        }
        .event-card:hover {
            box-shadow: 0 8px 25px rgba(0,0,0,0.15);
            transform: translateY(-5px);
        }
        .card-header {
            background-color : #3f4979;
            color: white;
            padding: 20px;
            border-bottom: none;
        }
        .card-header h3 {
            margin: 0;
            font-weight: 600;
            display: flex;
            align-items: center;
            gap: 10px;
        }
        .card-header p {
            margin: 8px 0 0 0;
            opacity: 0.95;
            font-size: 14px;
        }
        .card-body {
            padding: 25px;
        }
        .info-section {
            margin-bottom: 20px;
        }
        .info-section label {
            font-weight: 600;
            color: #333;
            margin-bottom: 8px;
            display: block;
            font-size: 14px;
            text-transform: uppercase;
            color: #667eea;
        }
        .info-section p {
            color: #555;
            margin: 5px 0;
            padding-left: 10px;
            border-left: 3px solid #667eea;
        }
        .info-section ul {
            margin: 8px 0;
            padding-left: 25px;
        }
        .info-section li {
            color: #555;
            margin: 5px 0;
        }
        .divider {
            height: 1px;
            background: #eee;
            margin: 20px 0;
        }
        .note-input {
            width: 100%;
            padding: 12px 15px;
            border: 2px solid #ddd;
            border-radius: 5px;
            font-size: 14px;
            transition: all 0.3s ease;
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
        }
        .note-input:focus {
            border-color: #667eea;
            box-shadow: 0 0 0 3px rgba(102, 126, 234, 0.1);
            outline: none;
        }
        .action-buttons {
            display: flex;
            gap: 12px;
            margin-top: 20px;
        }
        .btn {
            flex: 1;
            padding: 12px 20px;
            border: none;
            border-radius: 5px;
            font-size: 14px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s ease;
        }
        .btn-approve {
            background-color: #57c13a;
            color: white;
        }
        .btn-approve:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 15px rgba(79, 172, 254, 0.4);
        }
        .btn-reject {
            background-color: #e64e53;
            color: white;
        }
        .btn-reject:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 15px rgba(250, 112, 154, 0.4);
        }
        .empty-state {
            background: white;
            padding: 60px 30px;
            border-radius: 10px;
            text-align: center;
            box-shadow: 0 5px 15px rgba(0,0,0,0.1);
        }
        .empty-state i {
            font-size: 60px;
            color: #ddd;
            margin-bottom: 20px;
            display: block;
        }
        .empty-state p {
            color: #999;
            font-size: 16px;
        }
    </style>
</head>
<body>
<div class="review-container">
    <div class="page-header">
        <h1>
            <i class="fas fa-file-check"></i>
            待審核申請
        </h1>
        <p>審查並批准或駁回活動申請</p>
    </div>

    <?php if (empty($pending_events)): ?>
    <div class="empty-state">
        <i class="fas fa-inbox"></i>
        <p>目前沒有待審核的申請</p>
    </div>
    <?php else: ?>
        <?php foreach ($pending_events as $ev): ?>
        <div class="event-card" id="event-card-<?= $ev['event_id'] ?>">
            <div class="card-header">
                <h3>
                    <i class="fas fa-calendar-alt"></i>
                    <?= htmlspecialchars($ev['event_name']) ?>
                </h3>
                <p>
                    <i class="fas fa-building"></i>
                    <?= htmlspecialchars($ev['club_name']) ?> 
                    |
                    <i class="fas fa-user"></i>
                    申請人：<?= htmlspecialchars($ev['applicant_name']) ?>
                </p>
            </div>

            <div class="card-body">
                <div class="info-section">
                    <label>活動描述</label>
                    <p><?= htmlspecialchars($ev['description']) ?></p>
                </div>

                <div class="info-section">
                    <label>
                        <i class="fas fa-clock"></i>
                        活動時間
                    </label>
                    <p><?= $ev['start_time'] ?> 至 <?= $ev['end_time'] ?></p>
                </div>

                <div class="divider"></div>

                <?php if (!empty($ev['spaces'])): ?>
                <div class="info-section">
                    <label>
                        <i class="fas fa-map-marker-alt"></i>
                        申請場地
                    </label>
                    <ul>
                        <?php foreach ($ev['spaces'] as $sp): ?>
                        <li>
                            <strong><?= htmlspecialchars($sp['space_name']) ?></strong>
                            <br>
                            <small>
                                <i class="fas fa-hourglass"></i>
                                <?= $sp['start_time'] ?> ~ <?= $sp['end_time'] ?>
                            </small>
                        </li>
                        <?php endforeach; ?>
                    </ul>
                </div>
                <?php endif; ?>

                <?php if (!empty($ev['equipment'])): ?>
                <div class="info-section">
                    <label>
                        <i class="fas fa-tools"></i>
                        申請器材
                    </label>
                    <ul>
                        <?php foreach ($ev['equipment'] as $eq): ?>
                        <li><?= htmlspecialchars($eq['name']) ?> × <?= $eq['quantity'] ?></li>
                        <?php endforeach; ?>
                    </ul>
                </div>
                <?php endif; ?>

                <div class="divider"></div>

                <div class="info-section">
                    <label>
                        <i class="fas fa-comment"></i>
                        審核備註（選填）
                    </label>
                    <input 
                        type="text"
                        id="note-<?= $ev['event_id'] ?>"
                        class="note-input"
                        placeholder="如有駁回，請填寫駁回原因..."
                    >
                </div>

                <div class="action-buttons">
                    <button class="btn btn-approve" onclick="reviewAction(<?= $ev['event_id'] ?>, 'approved')">
                        <i class="fas fa-check-circle"></i>
                        通 過
                    </button>
                    <button class="btn btn-reject" onclick="reviewAction(<?= $ev['event_id'] ?>, 'rejected')">
                        <i class="fas fa-times-circle"></i>
                        駁 回
                    </button>
                </div>
            </div>
        </div>
        <?php endforeach; ?>
    <?php endif; ?>
</div>

<script>
function reviewAction(eventId, action) {
    const note = document.getElementById('note-' + eventId).value;
    const actionLabel = action === 'approved' ? '通過' : '駁回';

    // 駁回時若沒填原因，提醒一下
    if (action === 'rejected' && note.trim() === '') {
        if (!confirm('確定要駁回但不填原因嗎？')) return;
    }

    // 禁用按鈕，顯示loading狀態
    event.target.disabled = true;
    event.target.textContent = '處理中...';

    fetch('../api/review_action.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ 
            event_id: eventId, 
            action: action, 
            note: note 
        })
    })
    .then(res => res.json())
    .then(data => {
        if (data.success) {
            // 審核完就把這張卡片從畫面移除，帶動畫效果
            const card = document.getElementById('event-card-' + eventId);
            card.style.transition = 'all 0.4s ease';
            card.style.opacity = '0';
            card.style.transform = 'translateX(20px)';
            
            setTimeout(() => {
                card.remove();
                // 檢查是否還有其他卡片
                if (document.querySelectorAll('.event-card').length === 0) {
                    location.reload();
                }
            }, 400);
            
            alert(`已${actionLabel}此申請`);
        } else {
            alert('操作失敗：' + data.message);
            event.target.disabled = false;
            event.target.textContent = actionLabel === '通過' ? '✓ 通過' : '✗ 駁回';
        }
    })
    .catch(err => {
        alert('伺服器連線錯誤：' + err.message);
        event.target.disabled = false;
        event.target.textContent = actionLabel === '通過' ? '✓ 通過' : '✗ 駁回';
    });
}
</script>

</body>
</html>