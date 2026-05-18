<?php
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
    $event_sql = "SELECT e.event_id, e.event_name, e.club_name, e.start_time, e.end_time, 
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
$sql_equipment = "SELECT equipment_id, name, total_quantity, available_quantity FROM equipment WHERE equipment_status = 'available' ORDER BY name";
$result_equipment = $conn->query($sql_equipment);
$equipment_list = [];
if ($result_equipment) {
    $equipment_list = $result_equipment->fetch_all(MYSQLI_ASSOC);
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
        // 調用 API 提交
        try {
            $conn->begin_transaction();

            // 刪除現有的器材申請
            $delete_sql = "DELETE FROM equipment_borrow WHERE event_id = ?";
            $delete_stmt = $conn->prepare($delete_sql);
            $delete_stmt->bind_param("i", $event_id);
            $delete_stmt->execute();
            $delete_stmt->close();

            // 插入新的器材申請
            // 嘗試使用新的字段，如果失敗則使用舊字段
            $insert_sql = "INSERT INTO equipment_borrow (event_id, equipment_id, quantity, status, created_at) VALUES (?, ?, ?, 'pending', NOW())";
            $insert_stmt = $conn->prepare($insert_sql);

            if (!$insert_stmt) {
                // 如果新字段不存在，使用舊的 INSERT 語句
                $insert_sql = "INSERT INTO equipment_borrow (event_id, equipment_id, quantity) VALUES (?, ?, ?)";
                $insert_stmt = $conn->prepare($insert_sql);
                $use_new_fields = false;
            } else {
                $use_new_fields = true;
            }

            foreach ($equipment_selections as $equip_id => $quantity) {
                $quantity = intval($quantity);
                if ($quantity > 0) {
                    $equip_id = intval($equip_id);
                    
                    if ($use_new_fields) {
                        $insert_stmt->bind_param("iii", $event_id, $equip_id, $quantity);
                    } else {
                        $insert_stmt->bind_param("iii", $event_id, $equip_id, $quantity);
                    }
                    
                    if (!$insert_stmt->execute()) {
                        throw new Exception("器材申請插入失敗: " . $insert_stmt->error);
                    }
                }
            }
            $insert_stmt->close();

            // 提交事務
            $conn->commit();
            
            // 重定向回我的申請頁面
            header("Location: my_applications.php?success=true");
            exit();

        } catch (Exception $e) {
            $conn->rollback();
            $error = "提交失敗：" . $e->getMessage();
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
            --primary: #8B1538;
            --sidebar: #4c0f2a;
            --sidebar-hover: #6a1d43;
            --bg: #f4f6fb;
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
        .equipment-section {
            background: #f9fafb;
            border: 1px solid #e5e7eb;
            border-radius: 12px;
            padding: 1.5rem;
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
            box-shadow: 0 2px 8px rgba(139, 21, 56, 0.1);
        }
        .equipment-name {
            font-weight: 600;
            color: #374151;
            margin-bottom: 0.5rem;
        }
        .equipment-available {
            font-size: 0.85rem;
            color: #6b7280;
            margin-bottom: 0.75rem;
        }
        .equipment-input-group {
            display: flex;
            gap: 0.5rem;
            align-items: center;
        }
        .equipment-input-group input {
            width: 80px;
            padding: 0.5rem;
            border: 1px solid #d1d5db;
            border-radius: 6px;
            text-align: center;
        }
        .equipment-input-group span {
            color: #6b7280;
            font-size: 0.9rem;
        }
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
            box-shadow: 0 4px 12px rgba(139, 21, 56, 0.2);
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
    </style>
</head>
<body>
    <?php include(__DIR__ . "/../includes/sidebar.php"); ?>

    <main class="main-content">
        <header class="top-navbar">
            <div>
                <ol class="breadcrumb mb-0">
                    <li class="breadcrumb-item"><a href="dashboard.php">首頁</a></li>
                    <li class="breadcrumb-item"><a href="my_applications.php">我的申請</a></li>
                    <li class="breadcrumb-item active" aria-current="page">追加申請器材</li>
                </ol>
                <h4 class="mt-2 mb-0">追加申請器材</h4>
            </div>
        </header>

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
                    <p style="color: #6b7280; margin-bottom: 1.5rem;">以下資訊已固定，您只需選擇要申請的器材</p>

                    <form method="POST">
                        <!-- 固定的活動信息 -->
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
                                    $status_map = ['pending' => '審核中', 'approved' => '已通過', 'rejected' => '已拒絕', 'completed' => '已完成'];
                                    echo htmlspecialchars($status_map[$event_info['event_status']] ?? '未知');
                                    ?>
                                </div>
                            </div>
                        </div>

                        <!-- 器材選擇 -->
                        <div class="form-section">
                            <div class="form-section-title">選擇申請器材</div>
                            
                            <div class="equipment-section">
                                <?php if (empty($equipment_list)): ?>
                                    <p style="color: #6b7280;">目前沒有可用的器材</p>
                                <?php else: ?>
                                    <div class="equipment-grid">
                                        <?php foreach ($equipment_list as $eq): ?>
                                        <div class="equipment-card">
                                            <div class="equipment-name"><?php echo htmlspecialchars($eq['name']); ?></div>
                                            <div class="equipment-available">
                                                可用數量：<strong><?php echo intval($eq['available_quantity']); ?>/<?php echo intval($eq['total_quantity']); ?> 件</strong>
                                            </div>
                                            <div class="equipment-input-group">
                                                <input type="number" 
                                                       name="equipment[<?php echo $eq['equipment_id']; ?>]" 
                                                       value="<?php echo $existing_equipment[$eq['equipment_id']] ?? 0; ?>"
                                                       min="0" 
                                                       max="<?php echo intval($eq['available_quantity']); ?>"
                                                       placeholder="輸入數量">
                                                <span>件</span>
                                            </div>
                                        </div>
                                        <?php endforeach; ?>
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>

                        <!-- 表單操作按鈕 -->
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
</body>
</html>
