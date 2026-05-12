<?php
ini_set('display_errors', 1);
error_reporting(E_ALL);

require_once(__DIR__ . "/../DB/db_config.php");

if (!isset($_SESSION['user_id'])) {
    header('Location: ../login.php');
    exit();
}

$user_name = $_SESSION['user_name'] ?? '管理員';
$user_id = $_SESSION['user_id'];

// 取得可編輯場地清單
$edit_event = null;
$edit_reservation = null;
$edit_error = '';
$spaces = [];
$sql_spaces = "SELECT space_id, space_name FROM spaces ORDER BY space_name";
$result_spaces = $conn->query($sql_spaces);
if ($result_spaces) {
    $spaces = $result_spaces->fetch_all(MYSQLI_ASSOC);
}

// 處理編輯或刪除動作
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'], $_POST['event_id'])) {
    $event_id = intval($_POST['event_id']);
    $action = $_POST['action'];

    if ($action === 'delete') {
        $conn->begin_transaction();
        $ok = true;

        $stmt = $conn->prepare("DELETE FROM equipment_borrow WHERE event_id = ?");
        if ($stmt) {
            $stmt->bind_param("i", $event_id);
            $ok = $ok && $stmt->execute();
            $stmt->close();
        }

        $stmt = $conn->prepare("DELETE FROM reservations WHERE event_id = ?");
        if ($stmt) {
            $stmt->bind_param("i", $event_id);
            $ok = $ok && $stmt->execute();
            $stmt->close();
        }

        $stmt = $conn->prepare("DELETE FROM events WHERE event_id = ?");
        if ($stmt) {
            $stmt->bind_param("i", $event_id);
            $ok = $ok && $stmt->execute();
            $stmt->close();
        }

        if ($ok) {
            $conn->commit();
            header("Location: event_mgmt.php");
            exit;
        } else {
            $conn->rollback();
            $edit_error = '無法刪除活動，請稍後再試。';
        }
    } elseif ($action === 'save') {
        $event_name = trim($_POST['event_name'] ?? '');
        $club_name = trim($_POST['club_name'] ?? '');
        $description = trim($_POST['description'] ?? '');
        $review_note = trim($_POST['review_note'] ?? '');
        $space_id = intval($_POST['space_id'] ?? 0);
        $start_time = trim($_POST['start_time'] ?? '');
        $end_time = trim($_POST['end_time'] ?? '');

        if ($event_name === '' || $club_name === '' || $start_time === '' || $end_time === '') {
            $edit_error = '請填寫活動名稱、社團名稱與時間。';
        } else {
            $conn->begin_transaction();
            $ok = true;

            $stmt = $conn->prepare("UPDATE events SET event_name = ?, club_name = ?, description = ?, review_note = ?, start_time = ?, end_time = ? WHERE event_id = ?");
            if ($stmt) {
                $stmt->bind_param("ssssssi", $event_name, $club_name, $description, $review_note, $start_time, $end_time, $event_id);
                $ok = $ok && $stmt->execute();
                $stmt->close();
            }

            if ($ok) {
                $stmt = $conn->prepare("SELECT reservation_id FROM reservations WHERE event_id = ?");
                if ($stmt) {
                    $stmt->bind_param("i", $event_id);
                    $stmt->execute();
                    $stmt->bind_result($reservation_id);
                    $hasReservation = $stmt->fetch();
                    $stmt->close();
                }

                if ($space_id > 0) {
                    if ($hasReservation) {
                        $stmt = $conn->prepare("UPDATE reservations SET space_id = ?, start_time = ?, end_time = ? WHERE reservation_id = ?");
                        if ($stmt) {
                            $stmt->bind_param("issi", $space_id, $start_time, $end_time, $reservation_id);
                            $ok = $ok && $stmt->execute();
                            $stmt->close();
                        }
                    } else {
                        $stmt = $conn->prepare("INSERT INTO reservations (event_id, space_id, start_time, end_time) VALUES (?, ?, ?, ?)");
                        if ($stmt) {
                            $stmt->bind_param("iiss", $event_id, $space_id, $start_time, $end_time);
                            $ok = $ok && $stmt->execute();
                            $stmt->close();
                        }
                    }
                }
            }

            if ($ok) {
                $conn->commit();
                header("Location: event_mgmt.php");
                exit;
            }

            $conn->rollback();
            $edit_error = '編輯失敗，請檢查資料後再試。';
        }
    }
}

if (isset($_GET['edit_id'])) {
    $edit_id = intval($_GET['edit_id']);
    $stmt = $conn->prepare(
        "SELECT e.*, r.space_id, r.start_time AS reservation_start, r.end_time AS reservation_end
         FROM events e
         LEFT JOIN reservations r ON e.event_id = r.event_id
         WHERE e.event_id = ?"
    );
    if ($stmt) {
        $stmt->bind_param('i', $edit_id);
        $stmt->execute();
        $result = $stmt->get_result();
        if ($result) {
            $edit_event = $result->fetch_assoc();
        }
        $stmt->close();
    }
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
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>活動管理 - 輔仁大學課外活動指導組</title>

    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.5/font/bootstrap-icons.css" rel="stylesheet">

    <style>
        :root {
            --primary: #8B1538;
            --sidebar: #4c0f2a;
            --sidebar-hover: #6a1d43;
            --bg: #f4f6fb;
            --card: #ffffff;
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
        .sidebar .brand {
            text-align: center;
            margin-bottom: 1.5rem;
        }
        .sidebar .brand h4 {
            margin: 0;
            font-size: 1.1rem;
            line-height: 1.4;
            font-weight: 700;
        }
        .sidebar .nav-link {
            display: flex;
            align-items: center;
            gap: 0.75rem;
            color: rgba(255,255,255,0.9);
            padding: 0.85rem 1rem;
            margin: 0.2rem 0;
            border-radius: 16px;
            transition: background 0.25s ease, transform 0.15s ease;
        }
        .sidebar .nav-link:hover,
        .sidebar .nav-link.active {
            background: rgba(255,255,255,0.12);
            color: #ffffff;
            transform: translateX(4px);
        }
        .sidebar .nav-link i { font-size: 1.1rem; }
        .sidebar .sidebar-section {
            padding: 1rem 0.5rem;
            margin-top: 1.5rem;
            border-top: 1px solid rgba(255,255,255,0.12);
        }
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
        .main-content {
            margin-left: 260px;
            min-height: 100vh;
            transition: margin-left 0.25s ease;
        }
        .top-navbar {
            background: white;
            border-bottom: 1px solid #e9ecef;
            padding: 1rem 2rem;
            display: flex;
            justify-content: space-between;
            align-items: center;
            position: sticky;
            top: 0;
            z-index: 1100;
        }
        .top-navbar .breadcrumb {
            margin: 0;
            background: transparent;
            padding: 0;
        }
        .dashboard-grid {
            padding: 1.5rem 2rem 2rem;
        }
        .summary-row {
            display: grid;
            grid-template-columns: repeat(4, minmax(0, 1fr));
            gap: 1.25rem;
            margin-bottom: 1.5rem;
        }
        .card-panel {
            background: var(--card);
            border-radius: 18px;
            box-shadow: 0 10px 30px rgba(15,23,42,0.06);
            padding: 1.5rem;
            min-height: 150px;
            display: flex;
            flex-direction: column;
            justify-content: space-between;
        }
        .card-panel .icon-box {
            width: 50px;
            height: 50px;
            border-radius: 14px;
            display: grid;
            place-items: center;
            color: white;
            font-size: 1.25rem;
        }
        .card-panel.total .icon-box { background: #6f42c1; }
        .card-panel.pending .icon-box { background: #fd7e14; }
        .card-panel.approved .icon-box { background: #198754; }
        .card-panel.rejected .icon-box { background: #dc3545; }
        .card-panel .value {
            font-size: 2rem;
            font-weight: 700;
            margin-top: 1rem;
        }
        .card-panel .label { color: #6b7280; }
        .panel-row {
            background: var(--card);
            border-radius: 18px;
            box-shadow: 0 10px 30px rgba(15,23,42,0.06);
            padding: 1.5rem;
        }
        .panel-row h5 {
            margin-bottom: 1rem;
            font-weight: 700;
            color: var(--primary);
        }
        table { width: 100%; border-collapse: collapse; }
        th, td { padding: 0.85rem 1rem; text-align: left; border-bottom: 1px solid #e5e7eb; }
        th { background: #f3f4f6; color: #374151; font-weight: 600; }
        tbody tr:hover { background: #f9fafb; }
        .status-badge {
            display: inline-flex;
            align-items: center;
            gap: 0.35rem;
            padding: 0.45rem 0.85rem;
            border-radius: 999px;
            font-size: 0.8rem;
            font-weight: 600;
        }
        .status-pending { background: #fff3cd; color: #664d03; }
        .event-link { color: #0d6efd; text-decoration: none; }
        .event-link:hover { text-decoration: underline; }
        .status-approved { background: #d1e7dd; color: #0f5132; }
        .status-rejected { background: #f8d7da; color: #842029; }
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
        .btn-outline-primary {
            background: transparent;
            color: #0d6efd;
            border: 1px solid #0d6efd;
        }
        .btn-outline-primary:hover {
            background: #0d6efd;
            color: white;
        }
        .btn-outline-danger {
            background: transparent;
            color: #dc3545;
            border: 1px solid #dc3545;
        }
        .btn-outline-danger:hover {
            background: #dc3545;
            color: white;
        }
        .btn-sm {
            padding: 0.35rem 0.7rem;
            font-size: 0.78rem;
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
        @media (max-width: 1100px) {
            .summary-row { grid-template-columns: repeat(2, 1fr); }
            .main-content { margin-left: 0; }
        }
        @media (max-width: 768px) {
            .top-navbar { flex-direction: column; align-items: flex-start; gap: 1rem; padding: 1rem; }
            .sidebar { position: relative; width: 100%; height: auto; box-shadow: none; }
            .summary-row { grid-template-columns: 1fr; }
        }
    </style>
</head>
<body>
    <aside class="sidebar">
        <div class="brand">
            <h4>輔仁大學<br>課外活動指導組</h4>
        </div>
        <nav class="nav flex-column">
            <a class="nav-link" href="dashboard.php"><i class="bi bi-house-door"></i> 儀表板</a>
            <a class="nav-link" href="review.php"><i class="bi bi-clipboard-check"></i> 審核管理</a>
            <a class="nav-link active" href="event_mgmt.php"><i class="bi bi-calendar-check"></i> 申請紀錄</a>
            <a class="nav-link" href="equipment_mgmt.php"><i class="bi bi-tools"></i> 器材庫存管理</a>
            <a class="nav-link" href="space_mgmt.php"><i class="bi bi-building"></i> 空間管理</a>
            <a class="nav-link" href="calendar.php"><i class="bi bi-calendar3"></i> 完整行事曆</a>
        </nav>
        <div class="sidebar-section">
            <p class="mb-2">快捷操作</p>
            <a class="nav-link" href="../logout.php"><i class="bi bi-box-arrow-right"></i> 登出系統</a>
        </div>
    </aside>

    <main class="main-content">
        <header class="top-navbar">
            <div>
                <ol class="breadcrumb mb-0">
                    <li class="breadcrumb-item"><a href="dashboard.php">首頁</a></li>
                    <li class="breadcrumb-item active" aria-current="page">活動管理</li>
                </ol>
                <h4 class="mt-2 mb-0">活動管理</h4>
            </div>
            <div class="d-flex align-items-center gap-3">
                <span class="text-muted"><?php echo htmlspecialchars($user_name); ?></span>
                <div class="user-avatar" style="width: 40px; height: 40px; border-radius: 50%; background: var(--primary); color: white; display: flex; align-items: center; justify-content: center; font-weight: 700;">
                    <?php echo substr($user_name, 0, 1); ?>
                </div>
            </div>
        </header>

        <section class="dashboard-grid">
            <!-- 統計卡片 -->
            <div class="summary-row">
                <div class="card-panel total">
                    <div class="d-flex justify-content-between align-items-start">
                        <div>
                            <div class="label">總申請件數</div>
                            <div class="value"><?php echo $total_count; ?></div>
                        </div>
                        <div class="icon-box"><i class="bi bi-calendar2"></i></div>
                    </div>
                </div>
                <div class="card-panel pending">
                    <div class="d-flex justify-content-between align-items-start">
                        <div>
                            <div class="label">待審核</div>
                            <div class="value"><?php echo $pending_count; ?></div>
                        </div>
                        <div class="icon-box"><i class="bi bi-clock"></i></div>
                    </div>
                </div>
                <div class="card-panel approved">
                    <div class="d-flex justify-content-between align-items-start">
                        <div>
                            <div class="label">已通過</div>
                            <div class="value"><?php echo $approved_count; ?></div>
                        </div>
                        <div class="icon-box"><i class="bi bi-check-circle"></i></div>
                    </div>
                </div>
                <div class="card-panel rejected">
                    <div class="d-flex justify-content-between align-items-start">
                        <div>
                            <div class="label">已駁回</div>
                            <div class="value"><?php echo $rejected_count; ?></div>
                        </div>
                        <div class="icon-box"><i class="bi bi-x-circle"></i></div>
                    </div>
                </div>
            </div>

            <?php if (!empty($edit_event)): ?>
            <div class="panel-row">
                <h5><i class="bi bi-pencil-square"></i> 編輯活動申請</h5>
                <?php if ($edit_error): ?>
                    <div class="alert alert-danger"><?= htmlspecialchars($edit_error) ?></div>
                <?php endif; ?>
                <form method="POST" class="mb-4">
                    <input type="hidden" name="action" value="save">
                    <input type="hidden" name="event_id" value="<?= intval($edit_event['event_id']) ?>">
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label">活動名稱</label>
                            <input type="text" name="event_name" class="form-control" value="<?= htmlspecialchars($edit_event['event_name']) ?>">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">社團名稱</label>
                            <input type="text" name="club_name" class="form-control" value="<?= htmlspecialchars($edit_event['club_name']) ?>">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">開始時間</label>
                            <input type="datetime-local" name="start_time" class="form-control" value="<?= htmlspecialchars(date('Y-m-d\TH:i', strtotime($edit_event['reservation_start'] ?? $edit_event['start_time']))) ?>">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">結束時間</label>
                            <input type="datetime-local" name="end_time" class="form-control" value="<?= htmlspecialchars(date('Y-m-d\TH:i', strtotime($edit_event['reservation_end'] ?? $edit_event['end_time']))) ?>">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">場地</label>
                            <select name="space_id" class="form-select">
                                <option value="0">不指定場地</option>
                                <?php foreach ($spaces as $space): ?>
                                    <option value="<?= intval($space['space_id']) ?>" <?= intval($edit_event['space_id'] ?? 0) === intval($space['space_id']) ? 'selected' : '' ?>>
                                        <?= htmlspecialchars($space['space_name']) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-12">
                            <label class="form-label">申請內容</label>
                            <textarea name="description" class="form-control" rows="3"><?= htmlspecialchars($edit_event['description'] ?? '') ?></textarea>
                        </div>
                        <div class="col-12">
                            <label class="form-label">審核備註</label>
                            <textarea name="review_note" class="form-control" rows="2"><?= htmlspecialchars($edit_event['review_note'] ?? '') ?></textarea>
                        </div>
                    </div>
                    <div class="mt-3 d-flex gap-2">
                        <button type="submit" class="btn btn-outline-primary btn-sm"><i class="bi bi-save"></i> 儲存變更</button>
                        <a href="event_mgmt.php" class="btn btn-outline-secondary btn-sm"><i class="bi bi-x-circle"></i> 取消</a>
                    </div>
                </form>
            </div>
            <?php endif; ?>

            <!-- 活動列表 -->
            <div class="panel-row">
                <h5><i class="bi bi-list-ul"></i> 活動申請列表</h5>
                <div style="overflow-x: auto;">
                    <table>
                        <thead>
                            <tr>
                                <th>活動名稱</th>
                                <th>申請人</th>
                                <th>社團</th>
                                <th>場地</th>
                                <th>時間</th>
                                <th>狀態</th>
                                <th>操作</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($events as $event): ?>
                            <tr>
                                <td>
                                    <a href="review.php?event_id=<?php echo intval($event['event_id']); ?>" class="event-link">
                                        <strong><?php echo htmlspecialchars($event['event_name']); ?></strong>
                                    </a>
                                    <br>
                                    <small style="color: #6b7280;"><?php echo htmlspecialchars($event['description'] ?? ''); ?></small>
                                </td>
                                <td>
                                    <?php echo htmlspecialchars($event['applicant_name']); ?>
                                    <br>
                                    <small style="color: #6b7280;"><?php echo htmlspecialchars($event['applicant_email']); ?></small>
                                </td>
                                <td><?php echo htmlspecialchars($event['club_name']); ?></td>
                                <td><?php echo htmlspecialchars($event['space_name'] ?? '未指定'); ?></td>
                                <td>
                                    <?php 
                                    if ($event['start_time']) {
                                        echo '<span class="time-badge">';
                                        echo date('Y/m/d<br>H:i', strtotime($event['start_time']));
                                        echo '</span>';
                                    }
                                    ?>
                                </td>
                                <td>
                                    <span class="status-badge status-<?php echo $event['status']; ?>">
                                        <i class="bi bi-<?php 
                                        if ($event['status'] === 'pending') echo 'clock';
                                        elseif ($event['status'] === 'approved') echo 'check-lg';
                                        else echo 'x-lg';
                                        ?>"></i>
                                        <?php 
                                        $status_text = ['pending' => '待審核', 'approved' => '已通過', 'rejected' => '已駁回'];
                                        echo $status_text[$event['status']] ?? $event['status'];
                                        ?>
                                    </span>
                                </td>
                                <td>
                                    <div class="action-cell">
                                        <a href="event_mgmt.php?edit_id=<?php echo intval($event['event_id']); ?>" class="btn btn-outline-primary btn-sm" title="編輯申請"><i class="bi bi-pencil"></i> 編輯</a>
                                        <form method="POST" style="display:inline-flex;" onsubmit="return confirm('確定要刪除此活動申請嗎？');">
                                            <input type="hidden" name="event_id" value="<?php echo intval($event['event_id']); ?>">
                                            <button type="submit" name="action" value="delete" class="btn btn-outline-danger btn-sm" title="刪除申請"><i class="bi bi-trash"></i> 刪除</button>
                                        </form>
                                    </div>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                            
                            <?php if (empty($events)): ?>
                            <tr>
                                <td colspan="7" style="text-align: center; color: #999; padding: 30px;">
                                    <i class="bi bi-inbox"></i> 目前沒有活動申請記錄
                                </td>
                            </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </section>
    </main>

</body>
</html>