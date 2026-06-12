<?php
// 🟢 核心修正 1：必須把 session_start() 放在最頂端，否則下方檢查登入狀態必定失敗
session_start(); 

ini_set('display_errors', 1);
error_reporting(E_ALL);

require_once(__DIR__ . "/../DB/db_config.php");

if (!isset($_SESSION['student_id'])) {
    header('Location: ../login.php');
    exit();
}

$event_id = $_GET['event_id'] ?? null;
$error = null;
$event_info = null;

if (!$event_id) {
    $error = "缺少活動ID";
} else {
    // 獲取當前使用者ID
    $user_sql = "SELECT user_id FROM users WHERE email = ?";
    $user_stmt = $conn->prepare($user_sql);
    $user_stmt->bind_param("s", $_SESSION['student_id']);
    $user_stmt->execute();
    $user_result = $user_stmt->get_result();
    $user_id = null;

    if ($user_result && $user_result->num_rows > 0) {
        $user_row = $user_result->fetch_assoc();
        $user_id = $user_row['user_id'];
    }
    $user_stmt->close();

    // 獲取活動信息
    $event_sql = "SELECT e.event_id, e.user_id, e.event_name, e.club_name, e.start_time, e.end_time, 
                         e.status as event_status, s.space_name
                  FROM events e
                  LEFT JOIN reservations r ON e.event_id = r.event_id
                  LEFT JOIN spaces s ON r.space_id = s.space_id
                  WHERE e.event_id = ? AND e.user_id = ?";
    
    $event_stmt = $conn->prepare($event_sql);
    $event_stmt->bind_param("ii", $event_id, $user_id);
    $event_stmt->execute();
    $event_result = $event_stmt->get_result();

    if ($event_result && $event_result->num_rows > 0) {
        $event_info = $event_result->fetch_assoc();
    } else {
        $error = "無法找到該活動申請，或您無權修改此申請";
    }
    $event_stmt->close();
}

// 獲取所有可用的器材
$equipment_list = [];
if ($event_info) {
    $borrow_time = $event_info['start_time'];
    $return_time = $event_info['end_time'];

    $sql_equipment = "
        SELECT 
            e.equipment_id, 
            e.name, 
            e.code,
            e.total_quantity,
            e.borrowing_limit,
            (e.total_quantity - COALESCE(SUM(
                CASE 
                    WHEN ev.start_time < ? 
                     AND ev.end_time > ? 
                     AND ev.status = 'approved'
                    THEN eb.quantity 
                    ELSE 0 
                END
            ), 0)) AS available_quantity
        FROM equipment e
        LEFT JOIN equipment_borrow eb ON e.equipment_id = eb.equipment_id
        LEFT JOIN events ev ON eb.event_id = ev.event_id
        WHERE e.equipment_status = 'available'
        GROUP BY e.equipment_id
        ORDER BY e.name";

    $stmt_eq = $conn->prepare($sql_equipment);
    $stmt_eq->bind_param("ss", $return_time, $borrow_time);
    $stmt_eq->execute();
    $result_equipment = $stmt_eq->get_result();
    if ($result_equipment) {
        $equipment_list = $result_equipment->fetch_all(MYSQLI_ASSOC);
    }
    $stmt_eq->close();
}

// 獲取該活動的現有器材申請
$existing_equipment = [];
if ($event_id && $user_id) {
    $existing_sql = "SELECT equipment_id, quantity FROM equipment_borrow WHERE event_id = ?";
    $existing_stmt = $conn->prepare($existing_sql);
    $existing_stmt->bind_param("i", $event_id);
    $existing_stmt->execute();
    $existing_result = $existing_stmt->get_result();
    if ($existing_result) {
        while ($row = $existing_result->fetch_assoc()) {
            $existing_equipment[$row['equipment_id']] = $row['quantity'];
        }
    }
    $existing_stmt->close();
}

// 處理表單提交
if ($_SERVER["REQUEST_METHOD"] == "POST" && !$error) {
    $equipment_selections = $_POST['equipment'] ?? [];
    
    // 驗證至少選擇一個器材
    $has_equipment = false;
    foreach ($equipment_selections as $equip_id => $quantity) {
        if (intval($quantity) > 0) {
            $has_equipment = true;
            break;
        }
    }

    if (!$has_equipment) {
        $error = "請至少選擇一項器材";
    } else {
        try {
            // ===== 第二步：庫存與上限驗證 =====
            $borrow_time = $event_info['start_time'];
            $return_time = $event_info['end_time'];

            $check_sql = "
                SELECT 
                    e.equipment_id, 
                    e.name, 
                    e.borrowing_limit,
                    (e.total_quantity - COALESCE(SUM(
                        CASE 
                            WHEN ev.start_time < ? 
                             AND ev.end_time > ? 
                             AND ev.status = 'approved'
                            THEN eb.quantity 
                            ELSE 0 
                        END
                    ), 0)) AS available_quantity
                FROM equipment e
                LEFT JOIN equipment_borrow eb ON e.equipment_id = eb.equipment_id
                LEFT JOIN events ev ON eb.event_id = ev.event_id
                WHERE e.equipment_id = ?
                GROUP BY e.equipment_id";
            
            $check_stmt = $conn->prepare($check_sql);

            foreach ($equipment_selections as $equip_id => $quantity) {
                $quantity = intval($quantity);
                if ($quantity > 0) {
                    $equip_id = intval($equip_id);
                    
                    $check_stmt->bind_param("ssi", $return_time, $borrow_time, $equip_id);
                    $check_stmt->execute();
                    $check_result = $check_stmt->get_result()->fetch_assoc();

                    if ($check_result) {
                        $available = intval($check_result['available_quantity']);
                        $eq_name = $check_result['name'];

                        if ($quantity > $available) {
                            throw new Exception("「{$eq_name}」剩餘可用庫存不足！目前該時段僅剩 {$available} 件。");
                        }
                    }
                }
            }
            $check_stmt->close();

            // ---- 驗證全數通過，開始進行資料庫操作 ----
            $conn->begin_transaction();

            // 🟢 核心修正：建立新的 event 紀錄（追加申請），而不是更新原活動
            // 新記錄中必須寫入 original_event_id（指向原活動）
            
            $original_event_id = intval($event_id);
            $new_event_name = $event_info['event_name'];
            $new_club_name = $event_info['club_name'];
            $new_start_time = $event_info['start_time'];
            $new_end_time = $event_info['end_time'];
            $new_status = 'pending';
            
            // 插入新的追加申請 event 紀錄
            $insert_event_sql = "INSERT INTO events (user_id, event_name, club_name, description, start_time, end_time, status, original_event_id)
                                 VALUES (?, ?, ?, ?, ?, ?, ?, ?)";
            $insert_event_stmt = $conn->prepare($insert_event_sql);
            if (!$insert_event_stmt) {
                throw new Exception('建立追加申請紀錄失敗：' . $conn->error);
            }

            $description = '追加器材申請';
            $insert_event_stmt->bind_param(
                "isssssi",
                $user_id,
                $new_event_name,
                $new_club_name,
                $description,
                $new_start_time,
                $new_end_time,
                $new_status,
                $original_event_id
            );
            
            if (!$insert_event_stmt->execute()) {
                throw new Exception('建立追加申請紀錄失敗：' . $insert_event_stmt->error);
            }
            
            // 取得新建立的 event_id
            $new_event_id = $conn->insert_id;
            $insert_event_stmt->close();
            
            if ($new_event_id <= 0) {
                throw new Exception('無法獲取新建立的申請 ID');
            }

            // 🟢 核心修正 2：將不可用的 status 欄位完全從 SQL 語法中移除，精準對應你的實體資料表欄位 (event_id, equipment_id, quantity)
            $insert_sql = "INSERT INTO equipment_borrow (event_id, equipment_id, quantity) VALUES (?, ?, ?)";
            $insert_stmt = $conn->prepare($insert_sql);
            if (!$insert_stmt) {
                throw new Exception('建立器材借用記錄失敗：' . $conn->error);
            }

            foreach ($equipment_selections as $equip_id => $quantity) {
                $quantity = intval($quantity);
                if ($quantity > 0) {
                    $equip_id = intval($equip_id);
                    // 僅綁定 3 個參數，與上方 SQL 完美對齊，使用新建立的 event_id
                    $insert_stmt->bind_param("iii", $new_event_id, $equip_id, $quantity);
                    if (!$insert_stmt->execute()) {
                        throw new Exception("器材申請插入失敗: " . $insert_stmt->error);
                    }
                }
            }
            $insert_stmt->close();

            $conn->commit();
            
            header("Location: my_applications.php?success=true");
            exit();

        } catch (Exception $e) {
            $conn->rollback();
            $error = $e->getMessage();
        }
    }
}

$current_page = 'my_applications';
?>

<!DOCTYPE html>
<html lang="zh-TW">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>追加申請器材 - 輔仁大學課外活動指導組</title>

    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.5/font/bootstrap-icons.css" rel="stylesheet">

    <style>
        :root {
            --primary: #1e4d6b;
            --sidebar: #14394f;
            --sidebar-hover: #ece8dd;
            --bg: #f7f5ef;
            --card: #ffffff;
            --success: #10b981;
            --warning: #f59e0b;
            --danger: #ef4444;
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
            background: var(--primary);
            color: white;
            padding: 1.5rem 0.8rem;
            overflow-y: hidden;
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
            background: #ece8dd;
            color: #1e4d6b;
            transform: translateX(4px);
        }
        .sidebar .nav-link i { font-size: 1.1rem; }
        .sidebar .sidebar-section {
            padding: 1rem 0.5rem;
            margin-top: 1.5rem;
            border-top: 1px solid rgba(255,255,255,0.12);
        }
        .main-content {
            margin-left: 260px;
            min-height: 100vh;
            transition: margin-left 0.25s ease;
        }
        .top-navbar {
            background: #d5e3ea;
            border-bottom: 1px solid #bdd0d9;
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
        .top-navbar .breadcrumb { font-size: 0.8rem; }
        .top-navbar .breadcrumb-item + .breadcrumb-item::before { content: '›'; font-size: 1rem; color: #c9d0d8; }
        .top-navbar .breadcrumb-item a { color: #1e4d6b; text-decoration: none; opacity: 0.75; }
        .top-navbar .breadcrumb-item a:hover { opacity: 1; }
        .top-navbar .breadcrumb-item.active { color: #6b7280; }
        .content-wrapper {
            padding: 1.5rem 2rem 2rem;
        }
        .form-card {
            background: var(--card);
            border-radius: 18px;
            box-shadow: 0 10px 30px rgba(15,23,42,0.06);
            padding: 2rem;
            margin-bottom: 1.5rem;
        }
        .form-card h3 {
            margin-bottom: 1.5rem;
            font-weight: 700;
            color: var(--primary);
        }
        .form-section {
            margin-bottom: 1.5rem;
        }
        .form-section-title {
            font-weight: 600;
            color: #374151;
            margin-bottom: 1rem;
            padding-bottom: 0.5rem;
            border-bottom: 2px solid var(--primary);
        }
        .form-group {
            margin-bottom: 1rem;
        }
        .form-group label {
            font-weight: 600;
            color: #374151;
            margin-bottom: 0.5rem;
        }
        .form-group input[type="text"],
        .form-group input[type="datetime-local"],
        .form-group select,
        .form-group textarea {
            width: 100%;
            padding: 0.75rem;
            border: 1px solid #d1d5db;
            border-radius: 8px;
            background: #f9fafb;
            cursor: not-allowed;
            color: #6b7280;
        }
        .form-group input:disabled,
        .form-group select:disabled,
        .form-group textarea:disabled {
            background: #f3f4f6;
            opacity: 0.7;
        }
        .equipment-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(250px, 1fr));
            gap: 1rem;
            margin-top: 1rem;
        }
        .equipment-card {
            background: white;
            border: 1px solid #e5e7eb;
            border-radius: 8px;
            padding: 1rem;
            transition: all 0.25s ease;
        }
        .equipment-card:hover {
            border-color: var(--primary);
            box-shadow: 0 2px 8px rgba(30, 77, 107, 0.1);
        }
        .equipment-name {
            font-weight: 600;
            color: #374151;
            margin-bottom: 0.5rem;
        }
        .stock-available { color: #10b981; font-weight: 600; }
        .stock-low { color: #f59e0b; font-weight: 600; }
        .stock-empty { color: #ef4444; font-weight: 600; }
        .counter button:hover:not(:disabled) { background: var(--primary); color: white; border-color: var(--primary); }
        .counter button:disabled { opacity: .45; cursor: not-allowed; }
        .counter input:focus { outline: none; border-color: var(--primary); box-shadow: 0 0 0 2px rgba(30,77,107,0.12); }
        .counter input::-webkit-outer-spin-button, .counter input::-webkit-inner-spin-button { -webkit-appearance: none; }
        .counter input[type=number] { -moz-appearance: textfield; }
        .message {
            padding: 1rem;
            border-radius: 8px;
            margin-bottom: 1.5rem;
        }
        .message.error {
            background: #fee2e2;
            color: #991b1b;
            border: 1px solid #fecaca;
        }
        .message.success {
            background: #dcfce7;
            color: #166534;
            border: 1px solid #bbf7d0;
        }
        .form-actions {
            display: flex;
            gap: 1rem;
            justify-content: flex-end;
            margin-top: 2rem;
        }
        .btn-primary {
            padding: 0.75rem 1.5rem;
            background: var(--primary);
            color: white;
            border: none;
            border-radius: 8px;
            cursor: pointer;
            font-weight: 600;
            transition: all 0.25s ease;
        }
        .btn-primary:hover {
            background: #6a0e2a;
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(30, 77, 107, 0.2);
        }
        .btn-secondary {
            padding: 0.75rem 1.5rem;
            background: white;
            color: #374151;
            border: 1px solid #d1d5db;
            border-radius: 8px;
            cursor: pointer;
            font-weight: 600;
            transition: all 0.25s ease;
        }
        .btn-secondary:hover {
            background: #f9fafb;
        }
        .read-only-value {
            background: #f9fafb;
            padding: 0.75rem;
            border-radius: 8px;
            border: 1px solid #e5e7eb;
            color: #374151;
        }
        @media (max-width: 768px) {
            .main-content { margin-left: 0; }
            .sidebar {
                position: relative;
                width: 100%;
                height: auto;
            }
            .form-card { padding: 1rem; }
            .equipment-grid {
                grid-template-columns: 1fr;
            }
            .form-actions {
                flex-direction: column;
            }
        }
        .btn-outline-secondary-custom {
            display: inline-block;
            text-decoration: none;
            padding: 0.5rem 1rem;
            border: 1px solid #6c757d;
            color: #6c757d;
            border-radius: 6px;
            font-size: 0.875rem;
            transition: all 0.2s;
        }
        .btn-outline-secondary-custom:hover {
            background-color: #6c757d;
            color: white;
        }
    
        /* 提示訊息配色 */
        .alert-success { background: #c8dfe0; border-color: #70a3a7; color: #1a3f42; }
        .alert-warning { background: #ede4e5; border-color: #deb8b9; color: #6b2d2d; }
        .alert-danger  { background: #deb8b9; border-color: #c9979a; color: #5c1f22; }
        .alert-info    { background: #ede4e5; border-color: #c8c0c2; color: #5a3f42; }
    </style>
</head>
<body>
    <?php include(__DIR__ . "/../includes/sidebar.php"); ?>

    <main class="main-content">
        <?php
        $nav_breadcrumbs = [['label'=>'首頁','url'=>'dashboard.php'],['label'=>'我的申請','url'=>'my_applications.php'],['label'=>'追加申請器材']];
        $nav_title = '追加申請器材';
        include __DIR__ . '/../includes/student_navbar.php';
        ?>

        <section class="content-wrapper">
            <?php if ($error): ?>
                <div class="message error">
                    <i class="bi bi-exclamation-circle"></i>
                    <?php echo htmlspecialchars($error); ?>
                </div>
                <a href="my_applications.php" class="btn-secondary">返回申請列表</a>
            <?php elseif (!$event_info): ?>
                <div class="message error">
                    <i class="bi bi-exclamation-circle"></i>
                    無法找到活動信息
                </div>
                <a href="my_applications.php" class="btn-secondary">返回申請列表</a>
            <?php else: ?>
                <div class="form-card">
                    <h3>追加申請器材</h3>
                    <p style="color: #6b7280; margin-bottom: 1.5rem;">以下資訊已固定，您只需選擇要申請的器材並上傳單據</p>

                    <form method="POST">
                        <div class="form-section">
                            <div class="form-section-title">活動信息</div>
                            
                            <div class="form-group">
                                <label>活動名稱</label>
                                <div class="read-only-value">
                                    <?php echo htmlspecialchars($event_info['event_name']); ?>
                                </div>
                            </div>

                            <div class="form-group">
                                <label>社團名稱</label>
                                <div class="read-only-value">
                                    <?php echo htmlspecialchars($event_info['club_name']); ?>
                                </div>
                            </div>

                            <div class="form-group">
                                <label>活動日期與時間</label>
                                <div class="read-only-value">
                                    <?php echo date('Y-m-d H:i', strtotime($event_info['start_time'])); ?> 至 <?php echo date('H:i', strtotime($event_info['end_time'])); ?>
                                </div>
                            </div>

                            <div class="form-group">
                                <label>預訂場地</label>
                                <div class="read-only-value">
                                    <?php echo htmlspecialchars($event_info['space_name'] ?? '未指定'); ?>
                                </div>
                            </div>

                            <div class="form-group">
                                <label>活動狀態</label>
                                <div class="read-only-value">
                                    <?php 
                                    $status_map = ['pending' => '審核中', 'approved' => '已通過', 'rejected' => '已拒絕', 'completed' => '已完成', 'cancelled' => '已取消'];
                                    echo htmlspecialchars($status_map[$event_info['event_status']] ?? '未知');
                                    ?>
                                </div>
                            </div>
                        </div>

                        <div class="form-section">
                            <div class="form-section-title">選擇申請器材</div>
                            
                            <div style="background:#f0f4f8; border-radius:12px; padding:1rem 1.25rem; margin-bottom:1rem;">
                                <div style="font-weight:600; color:#1e4d6b; margin-bottom:0.75rem; font-size:0.93rem;">
                                    <i class="bi bi-clock me-1"></i>選擇器材借用時段
                                    <small style="font-weight:400; color:#6b7280; margin-left:0.5rem;">（可用量將依時段即時更新）</small>
                                </div>
                                <?php
                                $borrow_dt = strtotime($event_info['start_time']);
                                if (date('H:i', $borrow_dt) < '09:30') {
                                    $borrow_dt = strtotime(date('Y-m-d', $borrow_dt) . ' 09:30');
                                }
                                $borrow_h = date('H', $borrow_dt);
                                $borrow_m = date('i', $borrow_dt);

                                $return_dt = strtotime($event_info['end_time']);
                                if (date('H:i', $return_dt) > '16:30') {
                                    $return_dt = strtotime(date('Y-m-d', $return_dt) . ' 16:30');
                                }
                                $return_h = date('H', $return_dt);
                                $return_m = date('i', $return_dt);
                                ?>
                                <div style="display:grid; grid-template-columns:1fr 1.4fr 1fr 1.4fr auto; gap:0.75rem; align-items:flex-end;">
                                    <div>
                                        <label style="font-size:0.83rem; color:#374151; display:block; margin-bottom:0.3rem;">借用日期</label>
                                        <input type="date" id="borrow_date" class="form-control" value="<?= date('Y-m-d', $borrow_dt) ?>" min="<?= date('Y-m-d', $borrow_dt) ?>" max="<?= date('Y-m-d', $return_dt) ?>" required>
                                    </div>
                                    <div>
                                        <label style="font-size:0.83rem; color:#374151; display:block; margin-bottom:0.3rem;">借用時間 <small style="color:#9ca3af;">(09:30–16:30)</small></label>
                                        <div class="time-selects d-flex align-items-center gap-1">
                                            <select id="borrow_hour" class="form-select time-hour" style="width:auto" required>
                                                <option value="">時</option>
                                                <?php for ($h = 9; $h <= 16; $h++): $hh = sprintf('%02d', $h); ?>
                                                    <option value="<?= $hh ?>" <?= $borrow_h === $hh ? 'selected' : '' ?>><?= $hh ?></option>
                                                <?php endfor; ?>
                                            </select>
                                            <span style="padding:0 4px;font-weight:600">:</span>
                                            <select id="borrow_minute" class="form-select time-minute" style="width:auto" required>
                                                <option value="">分</option>
                                                <?php foreach ([0,10,20,30,40,50] as $m): $mm = sprintf('%02d', $m); ?>
                                                    <option value="<?= $mm ?>" <?= $borrow_m === $mm ? 'selected' : '' ?>><?= $mm ?></option>
                                                <?php endforeach; ?>
                                            </select>
                                        </div>
                                        <input type="hidden" id="equip_borrow_time" name="equip_borrow_time" value="<?= htmlspecialchars(date('Y-m-d\TH:i', $borrow_dt)) ?>">
                                    </div>
                                    <div>
                                        <label style="font-size:0.83rem; color:#374151; display:block; margin-bottom:0.3rem;">歸還日期</label>
                                        <input type="date" id="return_date" class="form-control" value="<?= date('Y-m-d', $return_dt) ?>" min="<?= date('Y-m-d', $borrow_dt) ?>" max="<?= date('Y-m-d', $return_dt) ?>" required>
                                    </div>
                                    <div>
                                        <label style="font-size:0.83rem; color:#374151; display:block; margin-bottom:0.3rem;">歸還時間 <small style="color:#9ca3af;">(09:30–16:30)</small></label>
                                        <div class="time-selects d-flex align-items-center gap-1">
                                            <select id="return_hour" class="form-select time-hour" style="width:auto" required>
                                                <option value="">時</option>
                                                <?php for ($h = 9; $h <= 16; $h++): $hh = sprintf('%02d', $h); ?>
                                                    <option value="<?= $hh ?>" <?= $return_h === $hh ? 'selected' : '' ?>><?= $hh ?></option>
                                                <?php endfor; ?>
                                            </select>
                                            <span style="padding:0 4px;font-weight:600">:</span>
                                            <select id="return_minute" class="form-select time-minute" style="width:auto" required>
                                                <option value="">分</option>
                                                <?php foreach ([0,10,20,30,40,50] as $m): $mm = sprintf('%02d', $m); ?>
                                                    <option value="<?= $mm ?>" <?= $return_m === $mm ? 'selected' : '' ?>><?= $mm ?></option>
                                                <?php endforeach; ?>
                                            </select>
                                        </div>
                                        <input type="hidden" id="equip_return_time" name="equip_return_time" value="<?= htmlspecialchars(date('Y-m-d\TH:i', $return_dt)) ?>">
                                    </div>
                                    <button type="button" onclick="queryEquipmentAvailability()"
                                        style="background:#1e4d6b; color:white; border:none; border-radius:8px; padding:0.65rem 1.1rem; font-weight:600; cursor:pointer; white-space:nowrap; transition:background 0.2s;"
                                        onmouseover="this.style.background='#14394f'" onmouseout="this.style.background='#1e4d6b'">
                                        <i class="bi bi-search me-1"></i>查詢可用數量
                                    </button>
                                </div>
                                <div class="alert alert-info" style="margin-top:0.85rem; border-radius:12px; padding:0.95rem 1rem; font-size:0.92rem; line-height:1.5; color:#1e3a5f; background:#dbeafe; border-color:#93c5fd;">
                                    <i class="bi bi-info-circle-fill me-1"></i>
                                    申請器材時請務必填寫器材證持有人，器材證期限為一年，請確認是否過期。
                                </div>
                                <div id="equipTimeWarning" style="display:none; margin-top:0.75rem; padding:0.6rem 0.9rem; background:#f0e8c0; border-radius:8px; color:#6b5a20; font-size:0.87rem;"></div>
                            </div>
                            
                            <div style="position:relative; margin-bottom:1rem;">
                                <input type="text" id="searchEquipmentAdd" class="form-control" placeholder="搜尋器材名稱或編號…" style="border-radius:10px; border:1px solid #e5e7eb;">
                                <i class="bi bi-search" style="position:absolute; right:12px; top:50%; transform:translateY(-50%); color:#9ca3af; pointer-events:none;"></i>
                            </div>
                            
                            <div class="equipment-grid">
                                <?php foreach ($equipment_list as $eq):
                                    $initMax = intval($eq['available_quantity']);
                                    $sc = intval($eq['available_quantity'])>0 ? (intval($eq['available_quantity'])<3?'low':'available') : 'empty';
                                ?>
                                <div class="equipment-card" data-equip-id="<?= $eq['equipment_id'] ?>" data-name="<?= htmlspecialchars($eq['name']) ?>" data-code="<?= htmlspecialchars($eq['code'] ?? '') ?>" data-total="<?= intval($eq['total_quantity']) ?>">
                                    <div class="equipment-header" style="display:flex; align-items:center; gap:0.9rem; margin-bottom:0.85rem;">
                                        <div style="width:46px; height:46px; border-radius:12px; background:#1e4d6b; color:white; display:flex; align-items:center; justify-content:center; font-size:0.9rem; font-weight:700; flex-shrink:0;"><?= htmlspecialchars($eq['code'] ?? $eq['equipment_id']) ?></div>
                                        <div style="display:flex; flex-direction:column; justify-content:center; flex:1; min-width:0;">
                                            <div class="equipment-name" style="text-align:left; font-weight:600; font-size:1rem; margin:0 0 0.15rem;"><?= htmlspecialchars($eq['name']) ?></div>
                                            <div style="color:#9ca3af; font-size:0.78rem; text-align:left;">編號：<?= htmlspecialchars($eq['code'] ?? $eq['equipment_id']) ?></div>
                                        </div>
                                    </div>
                                    <div style="text-align:right; font-size:0.88rem; margin-top:0.5rem;">
                                        <div class="avail-text stock-<?= $sc ?>">剩餘：<span class="avail-qty"><?= intval($eq['available_quantity']) ?></span>/<?= intval($eq['total_quantity']) ?></div>
                                    </div>
                                    <div style="font-size:0.85rem; color:#6b7280; margin-top:0.35rem; text-align:left;">每次建議上限：<?= htmlspecialchars($eq['borrowing_limit'] ?? 0) ?></div>
                                    <div class="counter mt-2" style="display:flex; align-items:center; gap:0.5rem;">
                                        <button type="button" class="btn-minus" onclick="changeQuantity(<?= $eq['equipment_id'] ?>,-1)" <?= intval($eq['available_quantity'])==0?'disabled':'' ?> style="width:32px; height:32px; border:1px solid #d1d5db; background:white; border-radius:6px; cursor:pointer; display:flex; align-items:center; justify-content:center; transition:all 0.2s;">-</button>
                                        <input type="number" id="qty_<?= $eq['equipment_id'] ?>" name="equipment[<?= $eq['equipment_id'] ?>]" value="0" min="0" max="<?= $initMax ?>" oninput="clampQtyInput(this)" onchange="syncQtyButtons(this)" style="width:60px; text-align:center; border:1px solid #d1d5db; border-radius:6px; padding:0.25rem; font-weight:600;">
                                        <button type="button" class="btn-plus" onclick="changeQuantity(<?= $eq['equipment_id'] ?>,1)" <?= intval($eq['available_quantity'])==0?'disabled':'' ?> style="width:32px; height:32px; border:1px solid #d1d5db; background:white; border-radius:6px; cursor:pointer; display:flex; align-items:center; justify-content:center; transition:all 0.2s;">+</button>
                                    </div>
                                </div>
                                <?php endforeach; ?>
                            </div>
                        </div>

                        <div class="form-section">
                            <div class="form-section-title"><i class="bi bi-file-earmark-text"></i> 器材借用單紙本繳交提醒</div>
                        <div style="background: #f9fafb; border: 1px solid #e5e7eb; border-radius: 12px; padding: 1.5rem;">
                            <div style="margin-bottom: 1rem;">
                                <a href="../document/課指組 器材借用申請表115.02.01.docx" class="btn-outline-secondary-custom btn-sm" download>
                                    <i class="bi bi-download"></i> 下載器材借用申請表
                                </a>
                            </div>
                            <div class="alert alert-info" style="border-radius:8px; font-size:0.95rem; margin-bottom:0;">
                                <i class="bi bi-info-circle me-1"></i>請下載並列印表格，填寫完整加蓋社團公章後，於審核時以紙本方式繳交。<strong>無須電子上傳</strong>。
                            </div>
                        </div>
                        </div>

                        <div class="form-actions">
                            <a href="my_applications.php" class="btn-secondary">取消</a>
                            <button type="submit" class="btn-primary">
                                <i class="bi bi-check-circle"></i> 提交申請
                            </button>
                        </div>
                    </form>
                </div>
            <?php endif; ?>
        </section>
    </main>
    <script>
        // ── 器材數量控制 ─────────────────────────────────────────
        function getEffectiveMax(input) {
            return parseInt(input.getAttribute('max'))||0;
        }
        function clampQtyInput(input) {
            const max = getEffectiveMax(input);
            let v = parseInt(input.value);
            if (isNaN(v)||v<0) { input.value=0; return; }
            if (v>max) input.value=max;
        }
        function syncQtyButtons(input) {
            clampQtyInput(input);
            const max=getEffectiveMax(input), avail=parseInt(input.getAttribute('max'))||0, v=parseInt(input.value)||0;
            const card=input.closest('.equipment-card');
            if (card) { card.querySelector('.btn-minus').disabled=(v<=0); card.querySelector('.btn-plus').disabled=(v>=max)||(avail<=0); }
        }
        function changeQuantity(id, delta) {
            const input=document.getElementById('qty_'+id), max=getEffectiveMax(input);
            let v=(parseInt(input.value)||0)+delta;
            if (v<0) v=0; if (v>max) v=max;
            input.value=v; syncQtyButtons(input);
        }

        // ── 查詢器材可用數量 ──────────────────────────────────────
        async function queryEquipmentAvailability() {
            const bt=document.getElementById('equip_borrow_time').value;
            const rt=document.getElementById('equip_return_time').value;
            const wd=document.getElementById('equipTimeWarning');
            wd.style.display='none'; wd.innerHTML='';
            if (!bt||!rt) { alert('請先選擇借用時間與歸還時間。'); return; }
            if (bt>=rt) { alert('歸還時間必須晚於借用時間。'); return; }
            const tm=s=>{const d=new Date(s);return d.getHours()*60+d.getMinutes();};
            const MIN=9*60+30,MAX=16*60+30;
            if (tm(bt)<MIN||tm(bt)>MAX||tm(rt)<MIN||tm(rt)>MAX) {
                wd.innerHTML='<i class="bi bi-exclamation-triangle me-1"></i>器材借還時間須在 09:30–16:30 之間。';
                wd.style.display='block'; return;
            }
            try {
                const res=await fetch(`get_equipment_availability.php?borrow_time=${encodeURIComponent(bt)}&return_time=${encodeURIComponent(rt)}`);
                const data=await res.json();
                document.querySelectorAll('.equipment-card[data-equip-id]').forEach(card=>{
                    const id=card.dataset.equipId, total=parseInt(card.dataset.total)||0;
                    const avail=(data[id]!==undefined)?parseInt(data[id]):total;
                    const qs=card.querySelector('.avail-qty'), td=card.querySelector('.avail-text');
                    const inp=document.getElementById('qty_'+id), bm=card.querySelector('.btn-minus'), bp=card.querySelector('.btn-plus');
                    if (qs) qs.textContent=avail;
                    if (td) td.className='avail-text stock-'+(avail<=0?'empty':avail<=2?'low':'available');
                    if (inp) { inp.setAttribute('max',avail); if (parseInt(inp.value)>avail) inp.value=avail; }
                    if (bm) bm.disabled=!inp||parseInt(inp?.value)<=0;
                    if (bp) bp.disabled=avail<=0;
                });
            } catch(e) { alert('查詢失敗，請稍後再試。'); }
        }

        function syncEquipTime(prefix) {
            const hour = document.getElementById(prefix + '_hour').value;
            const minute = document.getElementById(prefix + '_minute').value;
            const hidden = document.getElementById('equip_' + prefix + '_time');
            const date = document.getElementById(prefix + '_date').value;
            if (hour && minute && date) {
                hidden.value = `${date}T${hour}:${minute}`;
            } else {
                hidden.value = '';
            }
        }

        document.getElementById('borrow_hour').addEventListener('change', () => syncEquipTime('borrow'));
        document.getElementById('borrow_minute').addEventListener('change', () => syncEquipTime('borrow'));
        document.getElementById('return_hour').addEventListener('change', () => syncEquipTime('return'));
        document.getElementById('return_minute').addEventListener('change', () => syncEquipTime('return'));
        document.getElementById('borrow_date').addEventListener('change', () => syncEquipTime('borrow'));
        document.getElementById('return_date').addEventListener('change', () => syncEquipTime('return'));

        syncEquipTime('borrow');
        syncEquipTime('return');

        // ── 搜尋器材名稱或編號 ──────────────────────────────────────
        document.getElementById('searchEquipmentAdd').addEventListener('input', function() {
            const keyword = this.value.toLowerCase();
            document.querySelectorAll('.equipment-card').forEach(card => {
                const name = (card.dataset.name || '').toLowerCase();
                const code = (card.dataset.code || '').toLowerCase();
                card.style.display = (name.includes(keyword) || code.includes(keyword)) ? '' : 'none';
            });
        });

    </script>
</body>
</html>