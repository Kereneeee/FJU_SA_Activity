<?php
// API 端點：處理審核通過/駁回
require_once '../DB/db_config.php';
require_once '../includes/functions.php';

checkLogin();      // 登入檢查
checkAdmin();      // 確保只有 admin 能呼叫

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $data = json_decode(file_get_contents('php://input'), true);
    $event_id = (int)($data['event_id'] ?? 0);
    $action = $data['action'] ?? '';
    $note = $data['note'] ?? '';
    
    if ($event_id && ($action === 'approved' || $action === 'rejected')) {
        $status = ($action === 'approved') ? 'approved' : 'rejected';
        
        $stmt = $conn->prepare("UPDATE events SET status = ?, review_note = ? WHERE event_id = ?");
        $stmt->bind_param("ssi", $status, $note, $event_id);
        
        if ($stmt->execute()) {
            echo json_encode(['success' => true, 'message' => '審核已保存']);
        } else {
            echo json_encode(['success' => false, 'message' => '資料庫錯誤']);
        }
        exit;
    }
    
    echo json_encode(['success' => false, 'message' => '參數錯誤']);
    exit;
}

// 非 POST 請求直接返回錯誤
// 非 POST 請求直接返回錯誤
echo json_encode(['success' => false, 'message' => '只支援 POST 請求']);
?>>
            <div class="card-header">
                <strong><?= htmlspecialchars($ev['event_name']) ?></strong>
                &nbsp;｜&nbsp;
                <?= htmlspecialchars($ev['club_name']) ?>
                &nbsp;｜&nbsp;
                申請人：<?= htmlspecialchars($ev['applicant_name']) ?>
                （<?= htmlspecialchars($ev['applicant_email']) ?>）
            </div>
            <div class="card-body">
                <p><?= htmlspecialchars($ev['description']) ?></p>
                <p>活動時間：<?= $ev['start_time'] ?> ～ <?= $ev['end_time'] ?></p>

                <!-- 場地資訊 -->
                <?php if (!empty($ev['spaces'])): ?>
                <p><strong>申請場地：</strong></p>
                <ul>
                    <?php foreach ($ev['spaces'] as $sp): ?>
                    <li><?= htmlspecialchars($sp['space_name']) ?>
                        （<?= $sp['start_time'] ?> ～ <?= $sp['end_time'] ?>）
                    </li>
                    <?php endforeach; ?>
                </ul>
                <?php endif; ?>

                <!-- 器材資訊 -->
                <?php if (!empty($ev['equipment'])): ?>
                <p><strong>申請器材：</strong></p>
                <ul>
                    <?php foreach ($ev['equipment'] as $eq): ?>
                    <li><?= htmlspecialchars($eq['name']) ?> × <?= $eq['quantity'] ?></li>
                    <?php endforeach; ?>
                </ul>
                <?php endif; ?>

                <!-- 駁回備註輸入框 -->
                <div class="mt-3">
                    <input type="text"
                           id="note-<?= $ev['event_id'] ?>"
                           class="form-control"
                           placeholder="駁回原因（選填）"
                           style="max-width: 400px;">
                </div>

                <!-- 審核按鈕 -->
                <div class="mt-2">
                    <button class="btn btn-success"
                            onclick="reviewAction(<?= $ev['event_id'] ?>, 'approved')">
                        ✓ 通過
                    </button>
                    <button class="btn btn-danger ms-2"
                            onclick="reviewAction(<?= $ev['event_id'] ?>, 'rejected')">
                        ✗ 駁回
                    </button>
                </div>
            </div>
        </div>
        <?php endforeach;?>
    <?php endif; ?>
</div>

<script>
function reviewAction(eventId, action) {
    const note = document.getElementById('note-' + eventId).value;

    // 駁回時若沒填原因，提醒一下（可選）
    if (action === 'rejected' && note.trim() === '') {
        if (!confirm('確定要駁回但不填原因嗎？')) return;
    }

    fetch('../api/review_action.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ event_id: eventId, action: action, note: note })
    })
    .then(res => res.json())
    .then(data => {
        if (data.success) {
            // 審核完就把這張卡片從畫面移除，不需要重新整理
            const card = document.getElementById('event-card-' + eventId);
            card.style.transition = 'opacity 0.4s';
            card.style.opacity = '0';
            setTimeout(() => card.remove(), 400);
        } else {
            alert('操作失敗：' + data.message);
        }
    })
    .catch(() => alert('伺服器連線錯誤'));
}
</script>

</body>
</html>