<?php
require_once(__DIR__ . "/../DB/db_config.php");
require_once(__DIR__ . "/../includes/functions.php");

checkLogin();
if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'admin') {
    header('Location: ../login.php');
    exit;
}

$user_name = $_SESSION['user_name'] ?? '管理員';
$current_page = 'review';

// 場地清單（供編輯表單使用）
$spaces = [];
$rs = $conn->query("SELECT space_id, space_name FROM spaces ORDER BY space_id");
if ($rs) $spaces = $rs->fetch_all(MYSQLI_ASSOC);

// 處理刪除 / 編輯 POST
$flash = '';
$flash_type = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['mgmt_action'], $_POST['event_id'])) {
    $mgmt_id  = intval($_POST['event_id']);
    $mgmt_act = $_POST['mgmt_action'];

    if ($mgmt_act === 'delete') {
        $conn->begin_transaction();
        $ok = true;
        foreach (['equipment_borrow', 'reservations'] as $tbl) {
            $s = $conn->prepare("DELETE FROM {$tbl} WHERE event_id = ?");
            $s->bind_param('i', $mgmt_id); $ok = $ok && $s->execute(); $s->close();
        }
        $s = $conn->prepare("DELETE FROM events WHERE event_id = ?");
        $s->bind_param('i', $mgmt_id); $ok = $ok && $s->execute(); $s->close();
        if ($ok) { $conn->commit(); $flash = '申請已刪除。'; $flash_type = 'success'; }
        else     { $conn->rollback(); $flash = '刪除失敗，請稍後再試。'; $flash_type = 'danger'; }
        $redirect_status = $_POST['redirect_status'] ?? 'all';
        header("Location: review.php?status={$redirect_status}&flash=" . urlencode($flash) . "&flash_type={$flash_type}");
        exit;

    } elseif ($mgmt_act === 'save') {
        $ev_name  = trim($_POST['event_name']  ?? '');
        $cl_name  = trim($_POST['club_name']   ?? '');
        $desc     = trim($_POST['description'] ?? '');
        $rv_note  = trim($_POST['review_note'] ?? '');
        $space_id = intval($_POST['space_id']  ?? 0);
        $st       = trim($_POST['start_time']  ?? '');
        $et       = trim($_POST['end_time']    ?? '');
        if ($ev_name === '' || $cl_name === '' || $st === '' || $et === '') {
            $flash = '請填寫活動名稱、社團名稱與時間。'; $flash_type = 'danger';
        } else {
            $conn->begin_transaction(); $ok = true;
            $s = $conn->prepare("UPDATE events SET event_name=?, club_name=?, description=?, review_note=?, start_time=?, end_time=? WHERE event_id=?");
            $s->bind_param('ssssssi', $ev_name, $cl_name, $desc, $rv_note, $st, $et, $mgmt_id);
            $ok = $ok && $s->execute(); $s->close();
            if ($ok && $space_id > 0) {
                $s = $conn->prepare("SELECT reservation_id FROM reservations WHERE event_id = ? LIMIT 1");
                $s->bind_param('i', $mgmt_id); $s->execute(); $res = $s->get_result(); $s->close();
                if ($row = $res->fetch_assoc()) {
                    $rid = $row['reservation_id'];
                    $s = $conn->prepare("UPDATE reservations SET space_id=?, start_time=?, end_time=? WHERE reservation_id=?");
                    $s->bind_param('issi', $space_id, $st, $et, $rid); $ok = $ok && $s->execute(); $s->close();
                } else {
                    $s = $conn->prepare("INSERT INTO reservations (event_id, space_id, start_time, end_time) VALUES (?,?,?,?)");
                    $s->bind_param('iiss', $mgmt_id, $space_id, $st, $et); $ok = $ok && $s->execute(); $s->close();
                }
            }
            if ($ok) { $conn->commit(); $flash = '變更已儲存。'; $flash_type = 'success'; }
            else     { $conn->rollback(); $flash = '儲存失敗，請稍後再試。'; $flash_type = 'danger'; }
        }
        header("Location: review.php?event_id={$mgmt_id}&flash=" . urlencode($flash) . "&flash_type={$flash_type}");
        exit;
    }
}

// Flash 訊息（GET 帶入）
$flash      = $_GET['flash']      ?? '';
$flash_type = $_GET['flash_type'] ?? 'info';

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
        "SELECT e.*, u.name AS applicant_name, u.email AS applicant_email,
                oe.event_name AS original_event_name, oe.club_name AS original_club_name,
                oe.description AS original_description
         FROM events e
         JOIN users u ON e.user_id = u.user_id
         LEFT JOIN events oe ON e.original_event_id = oe.event_id
         WHERE e.event_id = ?"
    );
    $stmt->bind_param('i', $event_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $detail_event = $result ? $result->fetch_assoc() : null;
    $stmt->close();

    // 取得所有場次（含場地名稱）
    $detail_sessions = [];
    if ($detail_event) {
        $stmt_s = $conn->prepare(
            "SELECT r.reservation_id, r.start_time, r.end_time, s.space_name
             FROM reservations r
             LEFT JOIN spaces s ON r.space_id = s.space_id
             WHERE r.event_id = ?
             ORDER BY r.start_time"
        );
        $stmt_s->bind_param('i', $event_id);
        $stmt_s->execute();
        $res_s = $stmt_s->get_result();
        if ($res_s) $detail_sessions = $res_s->fetch_all(MYSQLI_ASSOC);
        $stmt_s->close();
    }

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
        $has_activity = trim($detail_event['event_name']) !== '' || trim($detail_event['club_name']) !== '';
        $has_equipment = !empty($detail_equipment);
        $has_original_event = !empty($detail_event['original_event_id']);

        // 🟢 【修改】新的分類邏輯：根據 original_event_id 和器材相關欄位判斷案件類型
        if ($has_original_event) {
            // 【器材申請】標籤：status = 'pending' AND original_event_id IS NOT NULL
            $detail_event['case_type'] = '器材申請';
        } elseif ($has_activity && $has_equipment) {
            // 【活動+器材申請】標籤：status = 'pending' AND original_event_id IS NULL AND 有器材相關欄位
            $detail_event['case_type'] = '活動+器材申請';
        } elseif ($has_activity) {
            // 【活動申請】標籤：status = 'pending' AND original_event_id IS NULL AND 沒有器材相關欄位
            $detail_event['case_type'] = '活動申請';
        } else {
            $detail_event['case_type'] = '一般申請';
        }
    }
}

// 狀態篩選
$allowed_statuses = ['all', 'pending', 'approved', 'rejected', 'cancelled'];
$status_filter = in_array($_GET['status'] ?? '', $allowed_statuses) ? $_GET['status'] : 'all';

$pending_events = [];
if ($event_id === 0) {
    $where_status = $status_filter !== 'all'
        ? "WHERE e.status = '" . $conn->real_escape_string($status_filter) . "'"
        : '';

    $sql_pending =
        "SELECT e.*, u.name AS applicant_name, u.email AS applicant_email,
                COALESCE(ec.equipment_count, 0) AS equipment_count,
                COALESCE(rc.session_count, 0) AS session_count,
                oe.event_name AS original_event_name, oe.club_name AS original_club_name
         FROM events e
         JOIN users u ON e.user_id = u.user_id
         LEFT JOIN (
             SELECT event_id, COUNT(*) AS equipment_count
             FROM equipment_borrow
             GROUP BY event_id
         ) ec ON ec.event_id = e.event_id
         LEFT JOIN (
             SELECT event_id, COUNT(*) AS session_count
             FROM reservations
             GROUP BY event_id
         ) rc ON rc.event_id = e.event_id
         LEFT JOIN events oe ON e.original_event_id = oe.event_id
         {$where_status}
         ORDER BY e.created_at DESC";
    $result_pending = $conn->query($sql_pending);
    if ($result_pending) {
        $pending_events = $result_pending->fetch_all(MYSQLI_ASSOC);
        foreach ($pending_events as &$ev) {
            $has_activity = (trim($ev['event_name']) !== '' || trim($ev['club_name']) !== '');
            $has_equipment = intval($ev['equipment_count']) > 0;
            $has_original_event = !empty($ev['original_event_id']);

            if ($has_original_event) {
                $ev['case_type'] = '器材申請';
            } elseif ($has_activity && $has_equipment) {
                $ev['case_type'] = '活動+器材申請';
            } elseif ($has_activity) {
                $ev['case_type'] = '活動申請';
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
            --primary: #1e4d6b;
            --sidebar: #14394f;
            --sidebar-hover: #ece8dd;
            --bg: #f7f5ef;
            --card: #ffffff;
            --success: #198754;
            --warning: #f59e0b;
            --danger: #dc3545;
        }
        * { box-sizing: border-box; }
        html { overflow-y: scroll; }
        body.modal-open { padding-right: 0 !important; }

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
            background: var(--primary);
            color: white;
            padding: 1.5rem 0.8rem;
            overflow-y: hidden;
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
            background: #ece8dd;
            color: #1e4d6b;
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
        .top-navbar { background: #d5e3ea; border-bottom: 1px solid #bdd0d9; padding: 1rem 2rem; display: flex; justify-content: space-between; align-items: center; position: sticky; top: 0; z-index: 1100; }
        .top-navbar .breadcrumb { margin: 0; background: transparent; padding: 0; }
                .top-navbar .breadcrumb { font-size: 0.8rem; }
        .top-navbar .breadcrumb-item + .breadcrumb-item::before { content: '›'; font-size: 1rem; color: #c9d0d8; }
        .top-navbar .breadcrumb-item a { color: #1e4d6b; text-decoration: none; opacity: 0.75; }
        .top-navbar .breadcrumb-item a:hover { opacity: 1; }
        .top-navbar .breadcrumb-item.active { color: #6b7280; }
        .user-avatar { width: 38px; height: 38px; border-radius: 50%; background: var(--primary); color: white; display: inline-flex; align-items: center; justify-content: center; font-weight: 700; font-size: 1rem; cursor: pointer; flex-shrink: 0; }
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
        .status-pending   { background: #f0e8c0; color: #6b5a20; }
        .status-approved  { background: #70a3a7; color: #1a3f42; }
        .status-rejected  { background: #c9979a; color: #5c1f22; }
        .status-cancelled { background: #e5e7eb; color: #4b5563; }
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
        @media (max-width: 768px) { .main-content { margin-left: 0; }        .top-navbar { flex-direction: column; align-items: flex-start; gap: 1rem; } }
    
        /* 提示訊息配色 */
        .alert-success { background: #c8dfe0; border-color: #70a3a7; color: #1a3f42; }
        .alert-warning { background: #ede4e5; border-color: #deb8b9; color: #6b2d2d; }
        .alert-danger  { background: #deb8b9; border-color: #c9979a; color: #5c1f22; }
        .alert-info    { background: #ede4e5; border-color: #c8c0c2; color: #5a3f42; }
    </style>
</head>
<body>
    <?php
    $current_page = $current_page ?? 'review';
    include __DIR__ . '/../includes/admin_sidebar.php';
    ?>

    <main class="main-content">
        <header class="top-navbar">
            <div>
                <ol class="breadcrumb mb-0">
                    <li class="breadcrumb-item"><a href="dashboard.php">首頁</a></li>
                    <li class="breadcrumb-item active" aria-current="page">審核管理</li>
                </ol>
                <h4 class="mt-2 mb-0">審核管理</h4>
            </div>
            <div class="d-flex align-items-center gap-2">
                <div class="user-avatar" onclick="location.href='profile.php'">
                    <?= htmlspecialchars(substr($user_name, 0, 1)) ?>
                </div>
                <small class="text-muted"><?= htmlspecialchars($user_name) ?></small>
            </div>
        </header>

        <section class="content-wrapper">
            <?php if (!empty($flash)): ?>
                <div class="alert alert-<?= htmlspecialchars($flash_type) ?> alert-dismissible fade show" role="alert">
                    <?= htmlspecialchars($flash) ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
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
                            <!-- 左欄：活動基本資訊 -->
                            <div class="detail-block">
                                <h6>活動名稱</h6>
                                <p><?= htmlspecialchars($detail_event['event_name']) ?: '（器材申請）' ?></p>
                                <h6>申請社團</h6>
                                <p><?= htmlspecialchars($detail_event['club_name']) ?: '（器材申請）' ?></p>
                                <h6>申請人</h6>
                                <p><?= htmlspecialchars($detail_event['applicant_name']) ?> / <?= htmlspecialchars($detail_event['applicant_email']) ?></p>
                                <?php if (!empty($detail_event['responsible_person'])): ?>
                                <h6>活動負責人</h6>
                                <p><?= htmlspecialchars($detail_event['responsible_person']) ?></p>
                                <?php endif; ?>
                                <h6>活動類型</h6>
                                <p>
                                    <?php
                                    $etype = $detail_event['event_type'] ?? '';
                                    if ($etype === '校內') {
                                        echo '<span style="background:#e7f1ff;color:#0c4a9c;border-radius:6px;padding:0.2rem 0.65rem;font-size:0.86rem;font-weight:600;"><i class="bi bi-building me-1"></i>校內活動</span>';
                                    } elseif ($etype === '校外') {
                                        echo '<span style="background:#d1fae5;color:#065f46;border-radius:6px;padding:0.2rem 0.65rem;font-size:0.86rem;font-weight:600;"><i class="bi bi-tree me-1"></i>校外活動</span>';
                                        if (!empty($detail_event['activity_location'])) {
                                            echo '<br><small style="color:#6b7280;margin-top:0.3rem;display:block;">📍 ' . htmlspecialchars($detail_event['activity_location']) . '</small>';
                                        }
                                    } else {
                                        echo htmlspecialchars($etype ?: '未指定');
                                    }
                                    ?>
                                </p>
                                <?php
                                $scale_str = $detail_event['activity_scale'] ?? '';
                                if ($scale_str):
                                    $scale_parts = array_map('trim', explode(',', $scale_str));
                                ?>
                                <h6>活動規模 / 特殊性質</h6>
                                <p style="display:flex;gap:0.4rem;flex-wrap:wrap;">
                                    <?php foreach ($scale_parts as $sp):
                                        if (empty($sp)) continue;
                                        $sc = $sp === '大型活動' ? '#fef3c7;color:#78350f' : ($sp === '含酒精活動' ? '#fff7ed;color:#c2410c' : ($sp === '使用火源活動' ? '#fee2e2;color:#991b1b' : '#f0f9ff;color:#0369a1'));
                                        echo '<span style="background:' . $sc . ';border-radius:6px;padding:0.2rem 0.6rem;font-size:0.83rem;font-weight:600;">' . htmlspecialchars($sp) . '</span>';
                                    endforeach; ?>
                                </p>
                                <?php endif; ?>
                            </div>

                            <!-- 右欄：申請狀態 / 備註 -->
                            <div class="detail-block">
                                <h6>申請狀態</h6>
                                <span class="status-badge status-<?= htmlspecialchars($detail_event['status']) ?>">
                                    <i class="bi bi-<?= $detail_event['status'] === 'pending' ? 'clock' : ($detail_event['status'] === 'approved' ? 'check-lg' : 'x-lg') ?>"></i>
                                    <?= $detail_event['status'] === 'pending' ? '待審核' : ($detail_event['status'] === 'approved' ? '已通過' : '已駁回') ?>
                                </span>
                                <div style="margin-top:0.75rem;">
                                    <span class="case-tag <?= $detail_event['case_type'] === '活動+器材申請' ? 'activity-equip' : ($detail_event['case_type'] === '活動申請' ? 'activity' : ($detail_event['case_type'] === '器材申請' ? 'equipment' : '')) ?>">
                                        <?= htmlspecialchars($detail_event['case_type'] ?? '一般申請') ?>
                                    </span>
                                </div>
                                <h6 class="mt-4">活動說明</h6>
                                <p><?= nl2br(htmlspecialchars($detail_event['description'] ?? '')) ?: '<span style="color:#9ca3af;">（無）</span>' ?></p>
                                <h6 class="mt-4">申請時間</h6>
                                <p><?= !empty($detail_event['created_at']) ? date('Y/m/d H:i:s', strtotime($detail_event['created_at'])) : '<span style="color:#9ca3af;">—</span>' ?></p>
                                <?php if (!empty($detail_event['reviewed_at'])): ?>
                                <h6 class="mt-3">審核時間</h6>
                                <p><?= date('Y/m/d H:i:s', strtotime($detail_event['reviewed_at'])) ?></p>
                                <?php endif; ?>
                                <h6 class="mt-4">審核備註</h6>
                                <p><?= nl2br(htmlspecialchars($detail_event['review_note'] ?? '')) ?: '<span style="color:#9ca3af;">（無）</span>' ?></p>
                            </div>
                        </div>

                        <?php if (!empty($detail_event['original_event_id'])): ?>
                        <div class="detail-block mt-3">
                            <h6>原活動資訊（器材補充申請）</h6>
                            <p><strong>活動名稱：</strong><?= htmlspecialchars($detail_event['original_event_name'] ?? '未知') ?></p>
                            <p><strong>申請社團：</strong><?= htmlspecialchars($detail_event['original_club_name'] ?? '未知') ?></p>
                            <?php if (!empty($detail_event['original_description'])): ?>
                            <p><strong>原說明：</strong><?= nl2br(htmlspecialchars($detail_event['original_description'])) ?></p>
                            <?php endif; ?>
                        </div>
                        <?php endif; ?>

                        <!-- 場次列表 -->
                        <?php if (!empty($detail_sessions)): ?>
                        <div class="detail-block mt-3">
                            <h6><i class="bi bi-calendar-week me-1"></i>活動場次（共 <?= count($detail_sessions) ?> 場）</h6>
                            <div style="overflow-x:auto;">
                                <table style="width:100%;border-collapse:collapse;font-size:0.9rem;">
                                    <thead>
                                        <tr style="background:#f3f4f6;">
                                            <th style="padding:0.5rem 0.75rem;border-bottom:1px solid #e5e7eb;color:#374151;">#</th>
                                            <th style="padding:0.5rem 0.75rem;border-bottom:1px solid #e5e7eb;color:#374151;">開始時間</th>
                                            <th style="padding:0.5rem 0.75rem;border-bottom:1px solid #e5e7eb;color:#374151;">結束時間</th>
                                            <th style="padding:0.5rem 0.75rem;border-bottom:1px solid #e5e7eb;color:#374151;">場地</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($detail_sessions as $si => $sess): ?>
                                        <tr style="border-bottom:1px solid #f0f0f0;">
                                            <td style="padding:0.5rem 0.75rem;color:#6b7280;"><?= $si+1 ?></td>
                                            <td style="padding:0.5rem 0.75rem;"><?= htmlspecialchars($sess['start_time']) ?></td>
                                            <td style="padding:0.5rem 0.75rem;"><?= htmlspecialchars($sess['end_time']) ?></td>
                                            <td style="padding:0.5rem 0.75rem;"><?= htmlspecialchars($sess['space_name'] ?? '（校外 / 未指定）') ?></td>
                                        </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                        <?php elseif (!empty($detail_event['start_time'])): ?>
                        <div class="detail-block mt-3">
                            <h6><i class="bi bi-clock me-1"></i>活動時間</h6>
                            <p><?= htmlspecialchars($detail_event['start_time']) ?> 至 <?= htmlspecialchars($detail_event['end_time']) ?></p>
                        </div>
                        <?php endif; ?>

                        <?php if (!empty($detail_equipment)): ?>
                        <div class="detail-block mt-3">
                            <h6><i class="bi bi-tools me-1"></i>器材需求</h6>
                            <ul class="detail-list">
                                <?php foreach ($detail_equipment as $item): ?>
                                    <li><?= htmlspecialchars($item['name']) ?> × <?= intval($item['quantity']) ?></li>
                                <?php endforeach; ?>
                            </ul>
                        </div>
                        <?php endif; ?>

                        <?php
                        // 定義所有可能的檔案欄位與對應顯示的名稱
                        $files_to_display = [
                            ['path' => $detail_event['proposal_doc_path'] ?? null, 'label' => '活動企劃書'],
                            ['path' => $detail_event['document_path'] ?? null, 'label' => '活動申請單 (黃單)'],
                            ['path' => $detail_event['venue_doc_path'] ?? null, 'label' => '場地申請單'],
                            ['path' => $detail_event['equipment_doc_path'] ?? null, 'label' => '器材借用單']
                        ];
                        // 篩選出真正有路徑、有上傳的檔案
                        $active_files = array_filter($files_to_display, function($f) {
                            return !empty($f['path']);
                        });
                        ?>

                        <?php if (!empty($active_files)): ?>
                        <div class="detail-block mt-3">
                            <h6>上傳檔案清單</h6>
                            <div class="table-responsive">
                                <table class="table table-sm table-borderless align-middle mb-0">
                                    <tbody>
                                        <?php foreach ($active_files as $file): ?>
                                            <tr style="border-bottom: 1px dashed #e5e7eb;">
                                                <td style="padding: 0.6rem 0;">
                                                    <span class="badge bg-secondary me-2" style="font-size: 0.8rem;"><?= htmlspecialchars($file['label']) ?></span>
                                                    <span style="font-size: 0.9rem; color: #475569;"><?= htmlspecialchars(basename($file['path'])) ?></span>
                                                </td>
                                                <td style="text-align: right; padding: 0.6rem 0;">
                                                    <div class="d-inline-flex gap-2">
                                                        <a href="../document/<?= htmlspecialchars($file['path']) ?>" 
                                                           target="_blank" 
                                                           class="btn btn-outline-primary btn-sm m-0">
                                                            <i class="bi bi-box-arrow-up-right"></i> 檢視
                                                        </a>
                                                        <a href="../document/<?= htmlspecialchars($file['path']) ?>" download class="btn btn-outline-success btn-sm m-0">
                                                            <i class="bi bi-download"></i> 下載
                                                        </a>
                                                    </div>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                        <?php endif; ?>

                        <div class="detail-block mt-3">
                            <input type="hidden" id="reviewEventId" value="<?= $detail_event['event_id'] ?>">
                            <div class="mb-3">
                                <label class="form-label">審核備註（選填）</label>
                                <textarea id="reviewNote" class="note-area" placeholder="填寫審核結果說明..." rows="4"><?= htmlspecialchars($detail_event['review_note'] ?? '') ?></textarea>
                            </div>
                            <div id="reviewResult" class="mb-3"></div>
                            <div class="d-flex flex-wrap gap-3">
                                <?php if ($detail_event['status'] === 'pending'): ?>
                                <button type="button" onclick="submitReview('approved')" class="btn btn-success"><i class="bi bi-check-circle"></i> 全部核准</button>
                                <button type="button" onclick="submitReview('rejected')" class="btn btn-danger"><i class="bi bi-x-circle"></i> 駁回</button>
                                <?php else: ?>
                                <div class="alert alert-info mb-0 py-2">此申請已完成審核（<?= $detail_event['status'] === 'approved' ? '已核准' : '已駁回' ?>）</div>
                                <?php endif; ?>
                                <button type="button" class="btn btn-warning" data-bs-toggle="modal" data-bs-target="#editModal"><i class="bi bi-pencil"></i> 編輯</button>
                                <form method="POST" style="display:inline;" onsubmit="return confirm('確定要刪除此申請？此操作無法復原。');">
                                    <input type="hidden" name="mgmt_action" value="delete">
                                    <input type="hidden" name="event_id" value="<?= $detail_event['event_id'] ?>">
                                    <input type="hidden" name="redirect_status" value="all">
                                    <button type="submit" class="btn btn-outline-danger"><i class="bi bi-trash"></i> 刪除</button>
                                </form>
                                <a href="review.php" class="btn btn-primary"><i class="bi bi-arrow-left"></i> 返回列表</a>
                            </div>
                        </div>
                    <?php endif; ?>
                </div>
            <?php else: ?>
                <div class="panel-row">
                    <div>
                        <h5><i class="bi bi-list-ul"></i> 申請管理列表</h5>
                        <div style="margin-bottom: 1.5rem; display: flex; gap: 0.75rem; flex-wrap: wrap;">
                            <a href="review.php?status=all" class="btn btn-sm <?= $status_filter === 'all' ? 'btn-primary' : 'btn-outline-secondary' ?>">
                                <i class="bi bi-grid"></i> 全部
                                <span class="badge bg-secondary ms-1"><?= $total_count ?></span>
                            </a>
                            <a href="review.php?status=pending" class="btn btn-sm <?= $status_filter === 'pending' ? 'btn-warning text-dark' : 'btn-outline-warning' ?>">
                                <i class="bi bi-clock"></i> 待審核
                                <span class="badge bg-warning text-dark ms-1"><?= $status_counts['pending'] ?? 0 ?></span>
                            </a>
                            <a href="review.php?status=approved" class="btn btn-sm <?= $status_filter === 'approved' ? 'btn-success' : 'btn-outline-success' ?>">
                                <i class="bi bi-check-circle"></i> 已通過
                                <span class="badge bg-success ms-1"><?= $status_counts['approved'] ?? 0 ?></span>
                            </a>
                            <a href="review.php?status=rejected" class="btn btn-sm <?= $status_filter === 'rejected' ? 'btn-danger' : 'btn-outline-danger' ?>">
                                <i class="bi bi-x-circle"></i> 已駁回
                                <span class="badge bg-danger ms-1"><?= $status_counts['rejected'] ?? 0 ?></span>
                            </a>
                        </div>
                    </div>
                    <?php if (empty($pending_events)): ?>
                        <div class="empty-state">
                            <i class="bi bi-inbox"></i>
                            <p>此分類目前沒有申請</p>
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
                                        <th>活動類型</th>
                                        <th>申請時間</th>
                                        <th>活動時間 / 場次</th>
                                        <th>審核時間</th>
                                        <th>狀態</th>
                                        <th>操作</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($pending_events as $ev): ?>
                                        <tr>
                                            <td>
                                                <span class="case-tag <?= $ev['case_type'] === '活動+器材申請' ? 'activity-equip' : ($ev['case_type'] === '活動申請' ? 'activity' : ($ev['case_type'] === '器材申請' ? 'equipment' : '')) ?>">
                                                    <?= htmlspecialchars($ev['case_type']) ?>
                                                </span>
                                            </td>
                                            <td>
                                                <a class="event-link" href="review.php?event_id=<?= intval($ev['event_id']) ?>">
                                                    <strong>
                                                        <?= htmlspecialchars($ev['event_name'] ?: ($ev['original_event_name'] ? '[器材申請] ' . $ev['original_event_name'] : '器材申請')) ?>
                                                    </strong>
                                                </a>
                                                <?php if (!empty($ev['activity_scale']) && $ev['activity_scale'] !== '一般活動'): ?>
                                                <br><small style="color:#92400e;"><?= htmlspecialchars($ev['activity_scale']) ?></small>
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <?= htmlspecialchars($ev['applicant_name']) ?>
                                                <br>
                                                <small style="color: #6b7280;"><?= htmlspecialchars($ev['applicant_email'] ?? '') ?></small>
                                            </td>
                                            <td><?= htmlspecialchars($ev['club_name']) ?></td>
                                            <td>
                                                <?php
                                                $et = $ev['event_type'] ?? '';
                                                if ($et === '校外') {
                                                    echo '<span style="background:#d1fae5;color:#065f46;border-radius:5px;padding:0.15rem 0.5rem;font-size:0.8rem;font-weight:600;">校外</span>';
                                                } elseif ($et === '校內') {
                                                    echo '<span style="background:#e7f1ff;color:#0c4a9c;border-radius:5px;padding:0.15rem 0.5rem;font-size:0.8rem;font-weight:600;">校內</span>';
                                                } else {
                                                    echo '—';
                                                }
                                                ?>
                                            </td>
                                            <td>
                                                <?php if (!empty($ev['created_at'])): ?>
                                                <span class="time-badge"><?= date('Y/m/d', strtotime($ev['created_at'])) ?></span>
                                                <br><small style="color:#6b7280;"><?= date('H:i:s', strtotime($ev['created_at'])) ?></small>
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <?php
                                                if ($ev['start_time']) {
                                                    echo '<span class="time-badge">' . date('Y/m/d', strtotime($ev['start_time'])) . '</span>';
                                                }
                                                $sc = intval($ev['session_count'] ?? 0);
                                                if ($sc > 0) {
                                                    echo '<br><small style="color:#6b7280;">' . $sc . ' 場次</small>';
                                                }
                                                ?>
                                            </td>
                                            <td>
                                                <?php if (!empty($ev['reviewed_at'])): ?>
                                                <span class="time-badge"><?= date('Y/m/d', strtotime($ev['reviewed_at'])) ?></span>
                                                <br><small style="color:#6b7280;"><?= date('H:i:s', strtotime($ev['reviewed_at'])) ?></small>
                                                <?php else: ?>
                                                <small style="color:#9ca3af;">—</small>
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <?php
                                                $status_map = [
                                                    'pending'   => ['label' => '待審核', 'icon' => 'clock'],
                                                    'approved'  => ['label' => '已通過', 'icon' => 'check-lg'],
                                                    'rejected'  => ['label' => '已駁回', 'icon' => 'x-lg'],
                                                    'cancelled' => ['label' => '已取消', 'icon' => 'slash-circle'],
                                                ];
                                                $si = $status_map[$ev['status']] ?? ['label' => $ev['status'], 'icon' => 'question'];
                                                ?>
                                                <span class="status-badge status-<?= htmlspecialchars($ev['status']) ?>">
                                                    <i class="bi bi-<?= $si['icon'] ?>"></i>
                                                    <?= $si['label'] ?>
                                                </span>
                                            </td>
                                            <td>
                                                <div style="display:flex;gap:0.4rem;flex-wrap:wrap;">
                                                    <a href="review.php?event_id=<?= intval($ev['event_id']) ?>" class="btn btn-sm btn-outline-primary m-0"><i class="bi bi-eye"></i> 查看</a>
                                                    <form method="POST" onsubmit="return confirm('確定要刪除此申請？此操作無法復原。');">
                                                        <input type="hidden" name="mgmt_action" value="delete">
                                                        <input type="hidden" name="event_id" value="<?= intval($ev['event_id']) ?>">
                                                        <input type="hidden" name="redirect_status" value="<?= htmlspecialchars($status_filter) ?>">
                                                        <button type="submit" class="btn btn-sm btn-outline-danger m-0"><i class="bi bi-trash"></i> 刪除</button>
                                                    </form>
                                                </div>
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
    <?php if ($detail_event): ?>
    <div class="modal fade" id="editModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-lg modal-dialog-centered">
            <div class="modal-content" style="border-radius:16px;overflow:hidden;">
                <div class="modal-header" style="background:var(--primary);color:white;">
                    <h5 class="modal-title"><i class="bi bi-pencil-square me-2"></i>編輯申請資料</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <form method="POST">
                    <input type="hidden" name="mgmt_action" value="save">
                    <input type="hidden" name="event_id" value="<?= $detail_event['event_id'] ?>">
                    <div class="modal-body">
                        <div class="row g-3">
                            <div class="col-md-6">
                                <label class="form-label fw-semibold">活動名稱</label>
                                <input type="text" name="event_name" class="form-control" value="<?= htmlspecialchars($detail_event['event_name']) ?>" required>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label fw-semibold">社團名稱</label>
                                <input type="text" name="club_name" class="form-control" value="<?= htmlspecialchars($detail_event['club_name']) ?>" required>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label fw-semibold">開始時間</label>
                                <input type="datetime-local" name="start_time" class="form-control" value="<?= htmlspecialchars(date('Y-m-d\TH:i', strtotime($detail_event['start_time']))) ?>" required>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label fw-semibold">結束時間</label>
                                <input type="datetime-local" name="end_time" class="form-control" value="<?= htmlspecialchars(date('Y-m-d\TH:i', strtotime($detail_event['end_time']))) ?>" required>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label fw-semibold">場地（第一場次）</label>
                                <select name="space_id" class="form-select">
                                    <option value="0">不指定 / 保持不變</option>
                                    <?php foreach ($spaces as $sp): ?>
                                    <option value="<?= intval($sp['space_id']) ?>"><?= htmlspecialchars($sp['space_name']) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-12">
                                <label class="form-label fw-semibold">活動說明</label>
                                <textarea name="description" class="form-control" rows="3"><?= htmlspecialchars($detail_event['description'] ?? '') ?></textarea>
                            </div>
                            <div class="col-12">
                                <label class="form-label fw-semibold">審核備註</label>
                                <textarea name="review_note" class="form-control" rows="2"><?= htmlspecialchars($detail_event['review_note'] ?? '') ?></textarea>
                            </div>
                        </div>
                    </div>
                    <div class="modal-footer bg-light">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">取消</button>
                        <button type="submit" class="btn btn-primary"><i class="bi bi-save me-1"></i>儲存變更</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <div class="modal fade" id="pdfPreviewModal" tabindex="-1" aria-labelledby="pdfPreviewModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-xl modal-dialog-centered" style="max-width: 85%;">
            <div class="modal-content" style="border-radius: 16px; overflow: hidden;">
                <div class="modal-header" style="background-color: var(--primary); color: white;">
                    <h5 class="modal-title" id="pdfPreviewModalLabel"><i class="bi bi-file-pdf"></i> 申請附件預覽</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body p-0" style="height: 75vh; background-color: #f4f6fb;">
                    <iframe id="pdfPreviewFrame" src="" width="100%" height="100%" style="border: none;"></iframe>
                </div>
                <div class="modal-footer bg-light">
                    <button type="button" class="btn btn-secondary m-0" data-bs-dismiss="modal">關閉視窗</button>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>

    <script>
    function submitReview(action) {
        const event_id = parseInt(document.getElementById('reviewEventId').value);
        const note     = document.getElementById('reviewNote').value;
        const resultDiv = document.getElementById('reviewResult');
        const label    = action === 'approved' ? '核准' : '駁回';

        if (!confirm('確定要' + label + '此申請？')) return;

        resultDiv.innerHTML = '<div class="alert alert-info py-2"><i class="bi bi-hourglass-split me-1"></i>處理中...</div>';

        fetch('../api/review_action.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ event_id: event_id, action: action, note: note })
        })
        .then(r => r.json())
        .then(data => {
            if (data.success) {
                resultDiv.innerHTML = '<div class="alert alert-success py-2"><i class="bi bi-check-circle me-1"></i>審核完成！頁面將自動重新整理...</div>';
                setTimeout(() => location.reload(), 1500);
            } else {
                resultDiv.innerHTML = '<div class="alert alert-danger py-2"><i class="bi bi-x-circle me-1"></i>錯誤：' + (data.message || '未知錯誤') + '</div>';
            }
        })
        .catch(() => {
            resultDiv.innerHTML = '<div class="alert alert-danger py-2">網路錯誤，請稍後再試。</div>';
        });
    }

    document.addEventListener('DOMContentLoaded', function () {
        const previewModal = document.getElementById('pdfPreviewModal');
        const previewFrame = document.getElementById('pdfPreviewFrame');

        if (previewModal) {
            // 當 Modal 準備顯示時觸發
            previewModal.addEventListener('show.bs.modal', function (event) {
                // 取得點擊的那個按鈕
                const button = event.relatedTarget;
                // 從按鈕的 data-filepath 屬性中取得 PDF 檔案路徑
                const filePath = button.getAttribute('data-filepath');
                
                // 將路徑賦值給 iframe 的 src，開始載入 PDF
                if (filePath) {
                    previewFrame.src = filePath;
                }
            });

            // 當 Modal 完全關閉時觸發
            previewModal.addEventListener('hidden.bs.modal', function () {
                // 清空 src，停止背後 PDF 的載入或播放，節省瀏覽器記憶體
                previewFrame.src = '';
            });
        }
    });
    </script>
</body>
</html>
