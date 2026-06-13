<?php


require_once(__DIR__ . "/../DB/db_config.php");

// 檢查登入
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'student') {
    header('Location: ../login.php');
    exit();
}

$student_name = $_SESSION['student_name'] ?? '學生';
$student_id = $_SESSION['student_id'];
$user_id = $_SESSION['user_id'] ?? null;

$success_msg = "";
$error_msg = "";

// 獲取用戶基本信息 
// [修改點]：fjusa 的 users 表主鍵是 user_id，且欄位僅有 user_id, name, email, phone, password, role, created_at[cite: 2]
$sql = "SELECT * FROM users WHERE email = ?";

$stmt = $conn->prepare($sql);
if (!$stmt) {
    die("SQL 準備失敗: " . $conn->error);
}

$stmt->bind_param("s", $student_id);
$stmt->execute();
$result = $stmt->get_result();
$user = $result->fetch_assoc();

if (!$user) {
    die("找不到用戶資訊");
}

$user_id = $user['user_id'];

// 獲取用戶所屬的所有社團
$user_clubs = array();
$sql = "SELECT cm.*, c.club_name 
        FROM club_members cm 
        JOIN clubs c ON cm.club_id = c.club_id 
        WHERE cm.user_id = ?";
$stmt = $conn->prepare($sql);
$stmt->bind_param("i", $user_id);
$stmt->execute();
$result = $stmt->get_result();
while ($row = $result->fetch_assoc()) {
    $user_clubs[] = $row;
}

// 2. 決定目前的 current_club_id
if (isset($_SESSION['current_club_id'])) {
    $current_club_id = $_SESSION['current_club_id'];
} elseif (!empty($user_clubs)) {
    // 如果 Session 沒值，預設為第一個社團
    $current_club_id = $user_clubs[0]['club_id'];
    $_SESSION['current_club_id'] = $current_club_id;
}


// 3. 處理身分切換
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    if ($_POST['action'] === 'switch_club' && isset($_POST['club_id'])) {
        $new_club_id = $_POST['club_id']; 
        
        // 【修正】除了驗證權限，順便把 c.club_name, cm.is_officer, cm.officer_title 撈出來
        $check_sql = "SELECT cm.club_id, c.club_name, cm.is_officer, cm.officer_title 
                      FROM club_members cm
                      JOIN clubs c ON cm.club_id = c.club_id
                      WHERE cm.user_id = ? AND cm.club_id = ?";
                      
        $check_stmt = $conn->prepare($check_sql);
        if ($check_stmt) {
            $check_stmt->bind_param("is", $user_id, $new_club_id);
            $check_stmt->execute();
            $check_result = $check_stmt->get_result();
            
            if ($check_row = $check_result->fetch_assoc()) {
                // 1. 保留原本 profile.php 自用的 current_club_id
                $_SESSION['current_club_id'] = $new_club_id;
                $current_club_id = $new_club_id;
                
                // 2. 【核心修復】同步更新 dashboard.php 所需的身分 Session
                if ($check_row['is_officer'] == 1) {
                    // 如果是幹部，寫入對應的社團名稱與職稱
                    $_SESSION['active_club_id']   = $check_row['club_id'];
                    $_SESSION['active_club_name'] = $check_row['club_name'];
                    $_SESSION['active_position']  = $check_row['officer_title'] ?: '幹部';
                } else {
                    // 如果切換過去只是一般成員，要把幹部權限洗掉，以免保有上一個社團的幹部權限
                    $_SESSION['active_club_id']   = $check_row['club_id'];
                    $_SESSION['active_club_name'] = $check_row['club_name'];
                    $_SESSION['active_position']  = null; 
                }
                
                // 重新載入頁面以更新資料，防止 POST 重複提交
                header("Location: " . $_SERVER['PHP_SELF']);
                exit();
            }
        }
    }
}

// 4. 最後：根據目前的 current_club_id 抓取要在「目前身分」顯示的資料
$officer_status = null;
foreach ($user_clubs as $club) {
    if ($club['club_id'] == $current_club_id) {
        $officer_status = $club;
        break;
    }
}
    
    // 更新密碼
    if (isset($_POST['action']) && $_POST['action'] === 'change_password') {
        $old_password = $_POST['old_password'] ?? '';
        $new_password = $_POST['new_password'] ?? '';
        $confirm_password = $_POST['confirm_password'] ?? '';
        
        if (empty($old_password) || empty($new_password) || empty($confirm_password)) {
            $error_msg = "請填寫所有欄位";
        } elseif ($new_password !== $confirm_password) {
            $error_msg = "新密碼不相符";
        } elseif (!password_verify($old_password, $user['password']) && $old_password !== $user['password']) {
            $error_msg = "原密碼不正確";
        } else {
            $hashed = password_hash($new_password, PASSWORD_DEFAULT);
            $update_sql = "UPDATE users SET password = ? WHERE user_id = ?";
            $update_stmt = $conn->prepare($update_sql);
            if ($update_stmt) {
                $update_stmt->bind_param("si", $hashed, $user_id);
                
                if ($update_stmt->execute()) {
                    $success_msg = "密碼已更新";
                } else {
                    $error_msg = "密碼更新失敗";
                }
            } else {
                $error_msg = "SQL 準備失敗: " . $conn->error;
            }
        }
    }



?>

<!DOCTYPE html>
<html lang="zh-TW">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>個人檔案 - 課外活動指導組</title>

    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.5/font/bootstrap-icons.css" rel="stylesheet">

    <style>
        :root {
            --primary: #1e4d6b;
            --sidebar: #14394f;
            --bg: #f7f5ef;
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
        .content-wrapper {
            padding: 1.5rem 2rem 2rem;
        }
        @media (max-width: 1100px) {
            .main-content { margin-left: 0; }
        }
        @media (max-width: 768px) {
            .sidebar {
                position: relative;
                width: 100%;
                height: auto;
                box-shadow: none;
            }
        }

        .profile-container {
            max-width: 900px;
            margin: 2rem auto;
            padding: 0 1rem;
        }

        .profile-card {
            background: white;
            border-radius: 18px;
            box-shadow: 0 10px 30px rgba(15,23,42,0.06);
            overflow: hidden;
            margin-bottom: 2rem;
        }

        .profile-header {
            background: var(--primary);
            color: white;
            padding: 2rem;
            display: flex;
            align-items: center;
            gap: 2rem;
        }

        .avatar-large {
            width: 100px;
            height: 100px;
            border-radius: 50%;
            background: rgba(255,255,255,0.2);
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 2.5rem;
            font-weight: 700;
        }

        .profile-info h2 {
            margin: 0 0 0.5rem;
            font-size: 1.8rem;
        }

        .profile-info p {
            margin: 0.25rem 0;
            opacity: 0.9;
        }

        .profile-body {
            padding: 2rem;
        }

        .info-section {
            margin-bottom: 2rem;
        }

        .info-section h5 {
            font-weight: 700;
            color: var(--primary);
            margin-bottom: 1rem;
            padding-bottom: 0.5rem;
            border-bottom: 2px solid var(--primary);
        }

        .info-row {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 1.5rem;
            margin-bottom: 1rem;
        }

        .info-item {
            background: #f8f9fa;
            padding: 1rem;
            border-radius: 12px;
        }

        .info-label {
            font-size: 0.85rem;
            color: #6b7280;
            margin-bottom: 0.35rem;
        }

        .info-value {
            font-size: 1.1rem;
            font-weight: 600;
            color: #1f2937;
        }

        .club-switcher {
            background: linear-gradient(135deg, #f8f9fa, #e9ecef);
            padding: 1.5rem;
            border-radius: 12px;
            margin-bottom: 2rem;
        }

        .club-switcher h6 {
            margin-bottom: 1rem;
            font-weight: 700;
        }

        .club-buttons {
            display: flex;
            gap: 0.75rem;
            flex-wrap: wrap;
        }

        .club-btn {
            padding: 0.75rem 1.25rem;
            border: 2px solid transparent;
            border-radius: 999px;
            background: white;
            cursor: pointer;
            transition: all 0.3s ease;
            font-weight: 600;
            font-size: 0.9rem;
        }

        .club-btn:hover {
            border-color: var(--primary);
            color: var(--primary);
        }

        .club-btn.active {
            background: var(--primary);
            color: white;
            border-color: var(--primary);
        }

        .officer-badge {
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            background: #d1e7dd;
            color: #0f5132;
            padding: 0.5rem 1rem;
            border-radius: 999px;
            font-size: 0.85rem;
            font-weight: 600;
        }

        .officer-badge.pending {
            background: #fff3cd;
            color: #664d03;
        }

        .officer-badge.non-officer {
            background: #e2e3e5;
            color: #383d41;
        }

        .form-section {
            background: #f8f9fa;
            padding: 1.5rem;
            border-radius: 12px;
            margin-top: 1.5rem;
        }

        .btn-custom {
            padding: 0.75rem 1.5rem;
            border-radius: 999px;
            font-weight: 600;
            border: none;
            transition: all 0.3s ease;
        }

        .btn-primary-custom {
            background: var(--primary);
            color: white;
        }

        .btn-primary-custom:hover {
            background: #6a1230;
            transform: translateY(-2px);
        }

        .alert-custom {
            border-radius: 12px;
            border: none;
        }

        @media (max-width: 768px) {
            .profile-header {
                flex-direction: column;
                text-align: center;
            }

            .info-row {
                grid-template-columns: 1fr;
            }

            .profile-container {
                margin: 1rem auto;
            }
        }
    
        /* 提示訊息配色 */
        .alert-success { background: #c8dfe0; border-color: #70a3a7; color: #1a3f42; }
        .alert-warning { background: #ede4e5; border-color: #deb8b9; color: #6b2d2d; }
        .alert-danger  { background: #deb8b9; border-color: #c9979a; color: #5c1f22; }
        .alert-info    { background: #ede4e5; border-color: #c8c0c2; color: #5a3f42; }
        .top-navbar .breadcrumb { font-size: 0.8rem; }
        .top-navbar .breadcrumb-item + .breadcrumb-item::before { content: '›'; font-size: 1rem; color: #c9d0d8; }
        .top-navbar .breadcrumb-item a { color: #1e4d6b; text-decoration: none; opacity: 0.75; }
        .top-navbar .breadcrumb-item a:hover { opacity: 1; }
        .top-navbar .breadcrumb-item.active { color: #6b7280; }
    </style>
</head>
<body>
    <?php include(__DIR__ . "/../includes/sidebar.php"); ?>

    <main class="main-content">
        <?php
        $nav_breadcrumbs = [['label'=>'首頁','url'=>'dashboard.php'],['label'=>'個人檔案']];
        $nav_title = '個人檔案';
        include __DIR__ . '/../includes/student_navbar.php';
        ?>

    <section class="content-wrapper">
    <div class="profile-container">
        <?php if ($success_msg): ?>
            <div class="alert alert-success alert-custom">
                <i class="bi bi-check-circle"></i> <?php echo htmlspecialchars($success_msg); ?>
            </div>
        <?php endif; ?>

        <?php if ($error_msg): ?>
            <div class="alert alert-danger alert-custom">
                <i class="bi bi-exclamation-circle"></i> <?php echo htmlspecialchars($error_msg); ?>
            </div>
        <?php endif; ?>

        <!-- 個人檔案卡片 -->
        <div class="profile-card">
            <div class="profile-header">
                <div class="avatar-large">
                    <?php echo htmlspecialchars(mb_substr($student_name, 0, 1)); ?>
                </div>
                <div class="profile-info">
                    <h2><?php echo htmlspecialchars($student_name); ?></h2>
                    <p><i class="bi bi-person-badge"></i> 學號：<?php echo htmlspecialchars($user['student_id'] ?? '—'); ?></p>
                    
                    <!-- 修改此處：顯示目前的社團名稱與職稱 -->
                    <p>
                        <i class="bi bi-briefcase"></i> 目前身分：
                        <?php 
                        if ($officer_status && $current_club_id) {
                            // 取得社團名稱
                            echo htmlspecialchars($officer_status['club_name'] ?? ''); 
                            echo " ";
                            // 判斷是否為幹部，若是則顯示職稱，否則顯示一般成員
                            echo htmlspecialchars($officer_status['is_officer'] ? ($officer_status['officer_title'] ?: '幹部') : '一般成員');
                        } else {
                            echo "尚未選擇社團";
                        }
                        ?>
                    </p>
                </div>
            </div>

            <div class="profile-body">
                <!-- 基本資訊 -->
                <div class="info-section">
                    <h5>基本資訊</h5>
                    <div class="info-row">                        
                        <div class="info-item">
                            <div class="info-label">信箱</div>
                            <div class="info-value" style="font-size: 0.95rem;"><?php echo htmlspecialchars($user['email'] ?? '未填寫'); ?></div>
                        </div>
                        <div class="info-item">
                            <div class="info-label">電話</div>
                            <div class="info-value"><?php echo htmlspecialchars($user['phone'] ?? '未填寫'); ?></div>
                        </div>
                    </div>
                </div>

                <!-- 社團身分切換 -->
                <?php if (!empty($user_clubs)): ?>
                <div class="info-section">
                    <h5>社團身分</h5>
                    
                    <div class="club-switcher">
                        <h6><i class="bi bi-people"></i> 選擇身分</h6>
                        <div class="club-buttons">
                            <?php foreach ($user_clubs as $club): ?>
                                <form method="POST" style="display: inline;">
                                    <input type="hidden" name="action" value="switch_club">
                                    <input type="hidden" name="club_id" value="<?php echo $club['club_id']; ?>">
                                    <button type="submit" class="club-btn <?php echo ($current_club_id == $club['club_id']) ? 'active' : ''; ?>">
                                        <i class="bi bi-shield-check"></i> 
                                        <?php 
                                        // 顯示格式：[社團名稱] [職稱/成員]
                                        echo htmlspecialchars($club['club_name']); 
                                        echo " ";
                                        echo htmlspecialchars($club['is_officer'] ? ($club['officer_title'] ?: '幹部') : '一般成員');
                                        ?>
                                    </button>
                                </form>
                            <?php endforeach; ?>
                        </div>
                    </div>

                    <!-- 當前身分詳細資訊 -->
                    <?php if ($officer_status && $current_club_id): ?>
                        <?php 
                        $join_date = strtotime($officer_status['join_date']);
                        $today = time();
                        $days_diff = floor(($today - $join_date) / (60 * 60 * 24));
                        $years_diff = floor($days_diff / 365);
                        
                        // 檢查是否需要重新確認
                        $needs_reconfirm = false;
                        if ($officer_status['officer_confirmation_date']) {
                            $confirm_date = strtotime($officer_status['officer_confirmation_date']);
                            $days_since_confirm = floor(($today - $confirm_date) / (60 * 60 * 24));
                            $needs_reconfirm = ($days_since_confirm >= 365);
                        }

                        // 此身份有效學年度：以幹部確認日期為準（若無則用入社時間），民國學年度＝西元年-1911
                        $acad_year_date = $officer_status['officer_confirmation_date'] ?: $officer_status['join_date'];
                        $valid_academic_year = (int)date('Y', strtotime($acad_year_date)) - 1911;
                        ?>
                        <div class="info-row">
                            <div class="info-item">
                                <div class="info-label">身分</div>
                                <div class="info-value">
                                    <?php if ($officer_status['is_officer']): ?>
                                        <span class="officer-badge">
                                            <i class="bi bi-star"></i>
                                            <?php echo htmlspecialchars($officer_status['officer_title'] ?: '社團幹部'); ?>
                                        </span>
                                    <?php else: ?>
                                        <span class="officer-badge non-officer">
                                            <i class="bi bi-person"></i>
                                            一般成員
                                        </span>
                                    <?php endif; ?>
                                </div>
                            </div>
                            <div class="info-item">
                                <div class="info-label">此身份有效學年度</div>
                                <div class="info-value"><?php echo $valid_academic_year; ?>學年度</div>
                            </div>
                        </div>

                        <?php if ($officer_status['is_officer']): ?>
                            <div class="info-item">
                                <div class="info-label">
                                    <i class="bi bi-info-circle"></i>
                                    身分確認狀態
                                </div>
                                <div style="margin-top: 0.5rem;">
                                    <?php if ($needs_reconfirm): ?>
                                        <span class="officer-badge pending">
                                            需要重新確認身分
                                        </span>
                                        <p style="font-size: 0.85rem; color: #664d03; margin-top: 0.5rem;">
                                            <i class="bi bi-exclamation-triangle"></i>
                                            您的幹部身分已超過一年未確認，請聯絡課指組重新確認。
                                        </p>
                                    <?php else: ?>
                                        <span class="officer-badge" style="background: #d1e7dd; color: #0f5132;">
                                            <i class="bi bi-check-circle"></i>
                                            身分有效
                                        </span>
                                        <p style="font-size: 0.85rem; color: #0f5132; margin-top: 0.5rem;">
                                            確認於：<?php echo date('Y年m月d日', strtotime($officer_status['officer_confirmation_date'])); ?>
                                        </p>
                                    <?php endif; ?>
                                </div>
                            </div>
                        <?php else: ?>
                            <div class="info-item">
                                <div class="info-label">
                                    <i class="bi bi-info-circle"></i>
                                    功能限制
                                </div>
                                <p style="font-size: 0.9rem; color: #6b7280; margin-top: 0.5rem;">
                                    <i class="bi bi-lock"></i>
                                    一般成員只能查看場地租借情況，無法申請活動、器材和場地協助。
                                </p>
                            </div>
                        <?php endif; ?>
                    <?php endif; ?>
                </div>
                <?php endif; ?>

                <!-- 密碼修改 -->
                <div class="info-section">
                    <h5>帳號安全</h5>
                    <div class="form-section">
                        <h6>修改密碼</h6>
                        <form method="POST">
                            <input type="hidden" name="action" value="change_password">
                            
                            <div class="mb-3">
                                <label class="form-label">原密碼</label>
                                <input type="password" name="old_password" class="form-control" required>
                            </div>

                            <div class="mb-3">
                                <label class="form-label">新密碼</label>
                                <input type="password" name="new_password" class="form-control" required>
                            </div>

                            <div class="mb-3">
                                <label class="form-label">確認新密碼</label>
                                <input type="password" name="confirm_password" class="form-control" required>
                            </div>

                            <button type="submit" class="btn btn-custom btn-primary-custom">
                                <i class="bi bi-lock"></i> 更新密碼
                            </button>
                        </form>
                    </div>
                </div>
            </div>
        </div>
    </div>
    </section>
    </main>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>