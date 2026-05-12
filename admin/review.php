<?php
ini_set('display_errors', 1);
error_reporting(E_ALL);

require_once(__DIR__ . "/../DB/db_config.php");

if (!isset($_SESSION['user_id'])) {
    header('Location: ../login.php');
    exit();
}

$user_name = $_SESSION['user_name'] ?? '管理員';
$current_page = 'review';
$message = '';
$message_type = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'], $_POST['event_id'])) {
    $event_id = intval($_POST['event_id']);
    $action = $_POST['action'];
    $review_note = trim($_POST['review_note'] ?? '');
    $status = 'pending';

    if ($action === 'approve') {
        $status = 'approved';
        $message = '申請已全部核准。';
        $message_type = 'success';
    } elseif ($action === 'partial_approve') {
        $status = 'approved';
        $approved_equipment = isset($_POST['approved_equipment']) && is_array($_POST['approved_equipment']) ? array_map('intval', $_POST['approved_equipment']) : [];
        $approved_list = implode(',', $approved_equipment);
        if ($approved_list === '') {
            $conn->query("DELETE FROM equipment_borrow WHERE event_id = " . $event_id);
        } else {
            $conn->query("DELETE FROM equipment_borrow WHERE event_id = " . $event_id . " AND equipment_id NOT IN (" . $approved_list . ")");
        }
        $message = '申請已部分核准，未核准器材已移除。';
        $message_type = 'success';
    } elseif ($action === 'reject') {
        $status = 'rejected';
        $message = '申請已駁回。';
        $message_type = 'error';
    }

    $stmt = $conn->prepare("UPDATE events SET status = ?, review_note = ? WHERE event_id = ?");
    $stmt->bind_param("ssi", $status, $review_note, $event_id);
    $stmt->execute();
    $stmt->close();

    header("Location: review.php?event_id=" . $event_id);
    exit;
}

// 統計資料
$status_counts = ['pending' => 0, 'approved' => 0, 'rejected' => 0];
$total_count = 0;
$sql_counts = "SELECT status, COUNT(*) AS cnt FROM events GROUP BY status";
$result_counts = $conn->query($sql_counts);
if ($result_counts) {
    while ($row = $result_counts->fetch_assoc()) {
        $status_counts[$row['status']] = intval($row['cnt']);
        $total_count += intval($row['cnt']);
    }
}

$event_id = isset($_GET['event_id']) ? intval($_GET['event_id']) : 0;
$detail_event = null;
$detail_equipment = [];
$detail_error = '';

if ($event_id > 0) {
    $stmt = $conn->prepare(
        "SELECT e.*, u.name AS applicant_name, u.email AS applicant_email, s.space_name,
                r.start_time AS reservation_start, r.end_time AS reservation_end
         FROM events e
         JOIN users u ON e.user_id = u.user_id
         LEFT JOIN reservations r ON e.event_id = r.event_id
         LEFT JOIN spaces s ON r.space_id = s.space_id
         WHERE e.event_id = ?"
    );
    $stmt->bind_param('i', $event_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $detail_event = $result ? $result->fetch_assoc() : null;
    $stmt->close();

    if (!$detail_event) {
        $detail_error = '找不到對應的活動申請。';
    } else {
        $stmt = $conn->prepare(
            "SELECT eq.equipment_id, eq.name, eb.quantity
             FROM equipment_borrow eb
             JOIN equipment eq ON eb.equipment_id = eq.equipment_id
             WHERE eb.event_id = ?"
        );
        $stmt->bind_param('i', $event_id);
        $stmt->execute();
        $result_equip = $stmt->get_result();
        if ($result_equip) {
            while ($row = $result_equip->fetch_assoc()) {
                $detail_equipment[] = $row;
            }
        }
        $stmt->close();
    }

    if ($detail_event) {
        $has_booking = !empty($detail_event['space_name']) || !empty($detail_event['reservation_start']);
        $has_equipment = !empty($detail_equipment);
        if ($has_booking && $has_equipment) {
            $detail_event['case_type'] = '活動申請+器材借用';
        } elseif ($has_booking) {
            $detail_event['case_type'] = '活動申請';
        } elseif ($has_equipment) {
            $detail_event['case_type'] = '器材借用';
        } else {
            $detail_event['case_type'] = '一般申請';
        }
    }
}

$pending_events = [];
if ($event_id === 0) {
    $sql_pending = 
        "SELECT e.*, u.name AS applicant_name, u.email AS applicant_email, s.space_name,
                r.start_time AS reservation_start, r.end_time AS reservation_end,
                COALESCE(ec.equipment_count, 0) AS equipment_count
         FROM events e
         JOIN users u ON e.user_id = u.user_id
         LEFT JOIN reservations r ON e.event_id = r.event_id
         LEFT JOIN spaces s ON r.space_id = s.space_id
         LEFT JOIN (
             SELECT event_id, COUNT(*) AS equipment_count
             FROM equipment_borrow
             GROUP BY event_id
         ) ec ON ec.event_id = e.event_id
         WHERE e.status = 'pending'
         ORDER BY e.start_time DESC";
    $result_pending = $conn->query($sql_pending);
    if ($result_pending) {
        $pending_events = $result_pending->fetch_all(MYSQLI_ASSOC);
        foreach ($pending_events as &$ev) {
            $has_booking = !empty($ev['space_name']) || !empty($ev['reservation_start']);
            $has_equipment = intval($ev['equipment_count']) > 0;
            if ($has_booking && $has_equipment) {
                $ev['case_type'] = '活動申請+器材借用';
            } elseif ($has_booking) {
                $ev['case_type'] = '活動申請';
            } elseif ($has_equipment) {
                $ev['case_type'] = '器材借用';
            } else {
                $ev['case_type'] = '一般申請';
            }
        }
        unset($ev);
    }
}
?>
<!DOCTYPE html>
<html lang="zh-TW">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>審核管理 - 輔仁大學課外活動指導組</title>

    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.5/font/bootstrap-icons.css" rel="stylesheet">

    <style>
        :root {
            --primary: #8B1538;
            --sidebar: #4c0f2a;
            --sidebar-hover: #6a1d43;
            --bg: #f4f6fb;
            --card: #ffffff;
            --success: #198754;
            --warning: #f59e0b;
            --danger: #dc3545;
        }
        * { box-sizing: border-box; }
        body {
            margin: 0;
            min-height: 100vh;
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background: var(--bg);
            color: #1f2937;
        }
        .sidebar {
            position: fixed;
            top: 0;
            left: 0;
            width: 260px;
            height: 100vh;
            background: linear-gradient(180deg, var(--primary), var(--sidebar));
            color: white;
            padding: 1.5rem 0.8rem;
            overflow-y: auto;
            box-shadow: 3px 0 15px rgba(0,0,0,0.12);
            z-index: 1200;
        }
        .sidebar .brand { text-align: center; margin-bottom: 1.5rem; }
        .sidebar .brand h4 { margin: 0; font-size: 1.1rem; line-height: 1.4; font-weight: 700; }
        .sidebar .nav-link {
            display: flex;
            align-items: center;
            gap: 0.75rem;
            color: rgba(255,255,255,0.9);
            padding: 0.85rem 1rem;
            margin: 0.2rem 0;
            border-radius: 16px;
            transition: background 0.25s ease, transform 0.15s ease;
            text-decoration: none;
        }
        .sidebar .nav-link:hover,
        .sidebar .nav-link.active {
            background: rgba(255,255,255,0.12);
            color: #ffffff;
            transform: translateX(4px);
        }
        .sidebar .nav-link i { font-size: 1.1rem; }
        .sidebar .sidebar-section { padding: 1rem 0.5rem; margin-top: 1.5rem; border-top: 1px solid rgba(255,255,255,0.12); }
        .dropdown {
            position: relative;
        }
        .dropdown-content {
            display: block;
            max-height: 0;
            overflow: hidden;
            transition: max-height 0.3s ease;
            background-color: rgba(255,255,255,0.1);
            border-radius: 16px;
            margin-top: 0.2rem;
        }
        .dropdown:hover .dropdown-content {
            max-height: 200px;
        }
        .dropdown-content a {
            color: rgba(255,255,255,0.9);
            padding: 0.75rem 1rem;
            text-decoration: none;
            display: block;
            border-radius: 0;
        }
        .dropdown-content a:hover {
            background-color: rgba(255,255,255,0.2);
        }
        .main-content { margin-left: 260px; min-height: 100vh; transition: margin-left 0.25s ease; }
        .top-navbar { background: white; border-bottom: 1px solid #e9ecef; padding: 1rem 2rem; display: flex; justify-content: space-between; align-items: center; position: sticky; top: 0; z-index: 1100; }
        .top-navbar .breadcrumb { margin: 0; background: transparent; padding: 0; }
        .content-wrapper { padding: 1.5rem 2rem 2rem; }
        .card { background: var(--card); border-radius: 18px; box-shadow: 0 10px 30px rgba(15,23,42,0.06); padding: 1.5rem; margin-bottom: 1.5rem; }
        .section-title { display: flex; align-items: center; gap: 0.75rem; font-size: 1.2rem; font-weight: 700; color: var(--primary); margin-bottom: 1rem; }
        .summary-row { display: grid; grid-template-columns: repeat(4, minmax(0, 1fr)); gap: 1.25rem; margin-bottom: 1.5rem; }
        .card-panel { background: var(--card); border-radius: 18px; box-shadow: 0 10px 30px rgba(15,23,42,0.06); padding: 1.5rem; min-height: 150px; display: flex; flex-direction: column; justify-content: space-between; }
        .card-panel .icon-box { width: 50px; height: 50px; border-radius: 14px; display: grid; place-items: center; color: white; font-size: 1.25rem; }
        .card-panel.total .icon-box { background: #6f42c1; }
        .card-panel.pending .icon-box { background: #fd7e14; }
        .card-panel.approved .icon-box { background: var(--success); }
        .card-panel.rejected .icon-box { background: var(--danger); }
        .card-panel .value { font-size: 2rem; font-weight: 700; margin-top: 1rem; }
        .card-panel .label { color: #6b7280; }
        .panel-row { background: var(--card); border-radius: 18px; box-shadow: 0 10px 30px rgba(15,23,42,0.06); padding: 1.5rem; }
        .panel-row h5 { margin-bottom: 1rem; font-weight: 700; color: var(--primary); }
        table { width: 100%; border-collapse: collapse; }
        th, td { padding: 0.85rem 1rem; text-align: left; border-bottom: 1px solid #e5e7eb; }
        th { background: #f3f4f6; color: #374151; font-weight: 600; }
        tbody tr:hover { background: #f9fafb; }
        .status-badge { display: inline-flex; align-items: center; gap: 0.35rem; padding: 0.45rem 0.85rem; border-radius: 999px; font-size: 0.8rem; font-weight: 600; }
        .status-pending { background: #fff3cd; color: #664d03; }
        .status-approved { background: #d1e7dd; color: #0f5132; }
        .status-rejected { background: #f8d7da; color: #842029; }
        .case-tag { display: inline-flex; align-items: center; padding: 0.35rem 0.75rem; border-radius: 999px; font-size: 0.78rem; color: #0f5132; background: #e7f5e6; margin-bottom: 0.5rem; }
        .case-tag.activity { background: #e7f1ff; color: #0c4a9c; }
        .case-tag.activity-equip { background: #fff4e5; color: #7a4a00; }
        .case-tag.equipment { background: #f8e7ff; color: #5f2b7b; }
        .event-link { color: #0d6efd; text-decoration: none; }
        .event-link:hover { text-decoration: underline; }
        .detail-grid { display: grid; grid-template-columns: 2fr 1fr; gap: 1.25rem; }
        .detail-block { background: #f8fafc; border-radius: 16px; padding: 1.25rem; }
        .detail-block h6 { margin-top: 0; font-weight: 700; color: #374151; }
        .detail-block p, .detail-block li { color: #475569; margin: 0.55rem 0; }
        .detail-list { list-style: none; padding-left: 0; }
        .detail-list li::before { content: '\2022'; margin-right: 0.5rem; color: var(--primary); }
        .note-area { width: 100%; min-height: 120px; resize: vertical; padding: 1rem; border: 1px solid #d1d5db; border-radius: 12px; font-size: 0.95rem; }
        .btn {
            padding: 0.5rem 0.95rem;
            border: none;
            border-radius: 6px;
            cursor: pointer;
            font-size: 0.85rem;
            font-weight: 600;
            transition: all 0.25s ease;
            margin-right: 0.35rem;
        }
        .btn-approve {
            background: #198754;
            color: white;
        }
        .btn-approve:hover {
            background: #157347;
            transform: translateY(-2px);
        }
        .btn-reject {
            background: #dc3545;
            color: white;
        }
        .btn-reject:hover {
            background: #c82333;
            transform: translateY(-2px);
        }
        .review-form {
            display: inline-flex;
            gap: 0.35rem;
            align-items: center;
        }
        .review-note {
            padding: 0.35rem 0.6rem;
            border: 1px solid #d1d5db;
            border-radius: 6px;
            font-size: 0.85rem;
        }
        .action-cell {
            display: flex;
            gap: 0.5rem;
            align-items: center;
            flex-wrap: wrap;
        }
        .time-badge {
            background: #f3f4f6;
            padding: 0.35rem 0.6rem;
            border-radius: 6px;
            font-size: 0.85rem;
            color: #6b7280;
        }
        .badge-info { background: #e7f1ff; color: #0f5132; }
        .empty-state { text-align: center; padding: 3rem 1.5rem; border-radius: 18px; background: white; box-shadow: 0 10px 30px rgba(15,23,42,0.06); }
        .empty-state i { font-size: 3rem; color: #c7d2fe; margin-bottom: 1rem; }
        .message { padding: 1rem 1.2rem; border-radius: 14px; margin-bottom: 1.25rem; font-weight: 600; }
        .message.success { background: #d1e7dd; color: #0f5132; }
        .message.error { background: #f8d7da; color: #842029; }
        @media (max-width: 1024px) { .summary-row, .detail-grid { grid-template-columns: 1fr; } }
        @media (max-width: 768px) { .main-content { margin-left: 0; } .top-navbar { flex-direction: column; align-items: flex-start; gap: 1rem; } }
    </style>
</head>
<body>
    <aside class="sidebar">
        <div class="brand">
            <h4>輔仁大學<br>課外活動指導組</h4>
        </div>
        <nav class="nav flex-column">
            <a class="nav-link" href="dashboard.php"><i class="bi bi-house-door"></i> 儀表板</a>
            <a class="nav-link active" href="review.php"><i class="bi bi-clipboard-check"></i> 審核管理</a>
            <a class="nav-link" href="event_mgmt.php"><i class="bi bi-calendar-check"></i> 申請紀錄</a>
            <a class="nav-link" href="equipment_mgmt.php"><i class="bi bi-tools"></i> 器材庫存管理</a>
            <a class="nav-link" href="space_mgmt.php"><i class="bi bi-building"></i> 空間管理</a>
            <a class="nav-link" href="calendar.php"><i class="bi bi-calendar3"></i> 完整行事曆</a>
        </nav>
        <div class="sidebar-section">
            <p class="mb-2">快速操作</p>
            <a class="nav-link" href="../logout.php"><i class="bi bi-box-arrow-right"></i> 登出系統</a>
        </div>
    </aside>

    <main class="main-content">
        <header class="top-navbar">
            <div>
                <ol class="breadcrumb mb-0">
                    <li class="breadcrumb-item"><a href="dashboard.php">首頁</a></li>
                    <li class="breadcrumb-item active" aria-current="page">審核管理</li>
                </ol>
                <h4 class="mt-2 mb-0">審核管理</h4>
            </div>
            <div class="user-card">
                <div class="user-avatar"><?= htmlspecialchars(substr($user_name, 0, 1)) ?></div>
                <div>
                    <div><?= htmlspecialchars($user_name) ?></div>
                    <small class="text-muted">管理員</small>
                </div>
            </div>
        </header>

        <section class="content-wrapper">
            <?php if ($message): ?>
                <div class="message <?= $message_type ?>"><?= htmlspecialchars($message) ?></div>
            <?php endif; ?>

            <div class="summary-row">
                <div class="card-panel total">
                    <div class="d-flex justify-content-between align-items-start">
                        <div>
                            <div class="label">總申請數</div>
                            <div class="value"><?= $total_count ?></div>
                        </div>
                        <div class="icon-box"><i class="bi bi-stack"></i></div>
                    </div>
                </div>
                <div class="card-panel pending">
                    <div class="d-flex justify-content-between align-items-start">
                        <div>
                            <div class="label">待審核</div>
                            <div class="value"><?= $status_counts['pending'] ?></div>
                        </div>
                        <div class="icon-box"><i class="bi bi-clock"></i></div>
                    </div>
                </div>
                <div class="card-panel approved">
                    <div class="d-flex justify-content-between align-items-start">
                        <div>
                            <div class="label">已通過</div>
                            <div class="value"><?= $status_counts['approved'] ?></div>
                        </div>
                        <div class="icon-box"><i class="bi bi-check-circle"></i></div>
                    </div>
                </div>
                <div class="card-panel rejected">
                    <div class="d-flex justify-content-between align-items-start">
                        <div>
                            <div class="label">已駁回</div>
                            <div class="value"><?= $status_counts['rejected'] ?></div>
                        </div>
                        <div class="icon-box"><i class="bi bi-x-circle"></i></div>
                    </div>
                </div>
            </div>

            <?php if ($event_id > 0): ?>
                <div class="card">
                    <div class="section-title"><i class="bi bi-file-earmark-text"></i> 活動詳細申請內容</div>
                    <?php if ($detail_error): ?>
                        <div class="alert alert-warning"><?= htmlspecialchars($detail_error) ?></div>
                    <?php else: ?>
                        <div class="detail-grid">
                            <div class="detail-block">
                                <h6>活動名稱</h6>
                                <p><?= htmlspecialchars($detail_event['event_name']) ?></p>
                                <h6>申請社團</h6>
                                <p><?= htmlspecialchars($detail_event['club_name']) ?></p>
                                <h6>申請人</h6>
                                <p><?= htmlspecialchars($detail_event['applicant_name']) ?> / <?= htmlspecialchars($detail_event['applicant_email']) ?></p>
                                <h6>場地名稱</h6>
                                <p><?= htmlspecialchars($detail_event['space_name'] ?? '尚未指定') ?></p>
                                <h6>活動時間</h6>
                                <p><?= htmlspecialchars($detail_event['reservation_start'] ?? $detail_event['start_time']) ?> 至 <?= htmlspecialchars($detail_event['reservation_end'] ?? $detail_event['end_time']) ?></p>
                            </div>
                            <div class="detail-block">
                                <h6>申請狀態</h6>
                                <span class="status-badge status-<?= htmlspecialchars($detail_event['status']) ?>">
                                    <i class="bi bi-<?= $detail_event['status'] === 'pending' ? 'clock' : ($detail_event['status'] === 'approved' ? 'check-lg' : 'x-lg') ?>"></i>
                                    <?= $detail_event['status'] === 'pending' ? '待審核' : ($detail_event['status'] === 'approved' ? '已通過' : '已駁回') ?>
                                </span>
                                <div style="margin-top:0.75rem;">
                                    <span class="case-tag <?= $detail_event['case_type'] === '活動申請+器材借用' ? 'activity-equip' : ($detail_event['case_type'] === '活動申請' ? 'activity' : ($detail_event['case_type'] === '器材借用' ? 'equipment' : '')) ?>">
                                        <?= htmlspecialchars($detail_event['case_type'] ?? '一般申請') ?>
                                    </span>
                                </div>
                                <h6 class="mt-4">申請備註</h6>
                                <p><?= nl2br(htmlspecialchars($detail_event['description'] ?? '無')) ?></p>
                                <h6 class="mt-4">審核備註</h6>
                                <p><?= nl2br(htmlspecialchars($detail_event['review_note'] ?? '無')) ?></p>
                            </div>
                        </div>

                        <form method="POST" class="mt-4">
                            <input type="hidden" name="event_id" value="<?= $detail_event['event_id'] ?>">
                            <?php if (!empty($detail_equipment)): ?>
                            <div class="detail-block mt-3">
                                <h6>器材需求</h6>
                                <p style="color: #6b7280; font-size: 0.92rem; margin-bottom: 0.8rem;">勾選的器材將會納入核准；若未勾選，該器材將在部分核准時不予核准。</p>
                                <ul class="detail-list">
                                    <?php foreach ($detail_equipment as $item): ?>
                                        <li>
                                            <label style="display: inline-flex; align-items: center; gap: 0.5rem;">
                                                <input type="checkbox" name="approved_equipment[]" value="<?= intval($item['equipment_id']) ?>" checked>
                                                <?= htmlspecialchars($item['name']) ?> × <?= intval($item['quantity']) ?>
                                            </label>
                                        </li>
                                    <?php endforeach; ?>
                                </ul>
                            </div>
                            <?php endif; ?>

                            <?php if (!empty($detail_event['document_path'])): ?>
                            <div class="detail-block mt-3">
                                <h6>上傳檔案</h6>
                            <p><strong>檔案名稱：</strong><?= htmlspecialchars(basename($detail_event['document_path'])) ?></p>
                            <div class="d-flex gap-2">
                                <a href="../document/<?= htmlspecialchars($detail_event['document_path']) ?>" target="_blank" class="btn btn-outline-primary btn-sm">
                                    <i class="bi bi-eye"></i> 檢視檔案
                                </a>
                                <a href="../document/<?= htmlspecialchars($detail_event['document_path']) ?>" download class="btn btn-outline-success btn-sm">
                                    <i class="bi bi-download"></i> 下載檔案
                                </a>
                            </div>
                        </div>
                        <?php endif; ?>

                        <form method="POST" class="mt-4">
                            <input type="hidden" name="event_id" value="<?= $detail_event['event_id'] ?>">
                            <div class="mb-3">
                                <label class="form-label">審核備註（選填）</label>
                                <textarea name="review_note" class="note-area" placeholder="填寫審核結果說明..." rows="4"><?= htmlspecialchars($detail_event['review_note'] ?? '') ?></textarea>
                            </div>
                            <div class="d-flex flex-wrap gap-3">
                                <button type="submit" name="action" value="approve" class="btn btn-success"><i class="bi bi-check-circle"></i> 全部核准</button>
                                <?php if (!empty($detail_equipment)): ?>
                                    <button type="submit" name="action" value="partial_approve" class="btn btn-warning"><i class="bi bi-slash-circle"></i> 部分核准</button>
                                <?php endif; ?>
                                <button type="submit" name="action" value="reject" class="btn btn-danger"><i class="bi bi-x-circle"></i> 駁回</button>
                                <a href="review.php" class="btn btn-primary"><i class="bi bi-arrow-left"></i> 返回列表</a>
                            </div>
                        </form>
                    <?php endif; ?>
                </div>
            <?php else: ?>
                <div class="panel-row">
                    <h5><i class="bi bi-list-ul"></i> 待審核活動列表</h5>
                    <?php if (empty($pending_events)): ?>
                        <div class="empty-state">
                            <i class="bi bi-inbox"></i>
                            <p>目前沒有待審核的申請</p>
                        </div>
                    <?php else: ?>
                        <div style="overflow-x:auto;">
                            <table>
                                <thead>
                                    <tr>
                                        <th>案件類型</th>
                                        <th>活動名稱</th>
                                        <th>申請人</th>
                                        <th>社團</th>
                                        <th>場地</th>
                                        <th>時間</th>
                                        <th>狀態</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($pending_events as $ev): ?>
                                        <tr>
                                            <td>
                                                <span class="case-tag <?= $ev['case_type'] === '活動申請+器材借用' ? 'activity-equip' : ($ev['case_type'] === '活動申請' ? 'activity' : ($ev['case_type'] === '器材借用' ? 'equipment' : '')) ?>">
                                                    <?= htmlspecialchars($ev['case_type']) ?>
                                                </span>
                                            </td>
                                            <td>
                                                <a class="event-link" href="review.php?event_id=<?= intval($ev['event_id']) ?>">
                                                    <strong><?= htmlspecialchars($ev['event_name']) ?></strong>
                                                </a>
                                                <br>
                                                <small style="color: #6b7280;"><?= htmlspecialchars($ev['description'] ?? '') ?></small>
                                            </td>
                                            <td>
                                                <?= htmlspecialchars($ev['applicant_name']) ?>
                                                <br>
                                                <small style="color: #6b7280;"><?= htmlspecialchars($ev['applicant_email'] ?? '') ?></small>
                                            </td>
                                            <td><?= htmlspecialchars($ev['club_name']) ?></td>
                                            <td><?= htmlspecialchars($ev['space_name'] ?? '未指定') ?></td>
                                            <td>
                                                <?php 
                                                if ($ev['start_time']) {
                                                    echo '<span class="time-badge">';
                                                    echo date('Y/m/d<br>H:i', strtotime($ev['start_time']));
                                                    echo '</span>';
                                                }
                                                ?>
                                            </td>
                                            <td>
                                                <span class="status-badge status-<?= htmlspecialchars($ev['status']) ?>">
                                                    <i class="bi bi-<?= $ev['status'] === 'pending' ? 'clock' : ($ev['status'] === 'approved' ? 'check-lg' : 'x-lg') ?>"></i>
                                                    <?= $ev['status'] === 'pending' ? '待審核' : ($ev['status'] === 'approved' ? '已通過' : '已駁回') ?>
                                                </span>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
        </section>
    </main>
</body>
</html>
