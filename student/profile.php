<?php
ini_set('display_errors', 1);
error_reporting(E_ALL);

session_start();
require_once(__DIR__ . "/../DB/db_config.php");

// 檢查登入
if (!isset($_SESSION['student_id'])) {
    header('Location: ../login.php');
    exit();
}

$student_name = $_SESSION['student_name'] ?? '學生';
$student_id = $_SESSION['student_id'];
$user_id = $_SESSION['user_id'] ?? null;

$success_msg = "";
$error_msg = "";

// 獲取用戶基本信息
$sql = "SELECT u.* FROM users u WHERE u.email = ?";

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
$sql = "SELECT cm.*, c.club_name, c.founded_date, c.advisor
        FROM club_members cm
        JOIN clubs c ON cm.club_id = c.club_id
        WHERE cm.user_id = ?
        ORDER BY cm.join_date DESC";

$stmt = $conn->prepare($sql);
if (!$stmt) {
    die("SQL 準備失敗: " . $conn->error);
}

$stmt->bind_param("i", $user_id);
$stmt->execute();
$result = $stmt->get_result();

while ($row = $result->fetch_assoc()) {
    $user_clubs[] = $row;
}

// 處理身分切換
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    if ($_POST['action'] === 'switch_club' && isset($_POST['club_id'])) {
        $new_club_id = intval($_POST['club_id']);
        
        // 檢查用戶是否屬於該社團
        $check_sql = "SELECT club_id FROM club_members WHERE user_id = ? AND club_id = ?";
        $check_stmt = $conn->prepare($check_sql);
        if ($check_stmt) {
            $check_stmt->bind_param("ii", $user_id, $new_club_id);
            $check_stmt->execute();
            
            if ($check_stmt->get_result()->num_rows > 0) {
                // 嘗試更新，如果不存在則插入
                $update_sql = "UPDATE user_current_role SET current_club_id = ? WHERE user_id = ?";
                $update_stmt = $conn->prepare($update_sql);
                if ($update_stmt) {
                    $update_stmt->bind_param("ii", $new_club_id, $user_id);
                    
                    if ($update_stmt->execute()) {
                        // 檢查是否真的更新了記錄
                        if ($conn->affected_rows == 0) {
                            // 沒有記錄被更新，插入新記錄
                            $insert_sql = "INSERT INTO user_current_role (user_id, current_club_id) VALUES (?, ?)";
                            $insert_stmt = $conn->prepare($insert_sql);
                            if ($insert_stmt) {
                                $insert_stmt->bind_param("ii", $user_id, $new_club_id);
                                $insert_stmt->execute();
                            }
                        }
                        $_SESSION['current_club_id'] = $new_club_id;
                        $current_club_id = $new_club_id;
                        $success_msg = "已切換身分";
                    }
                }
            }
        }
    }
    
    // 更新密碼
    if ($_POST['action'] === 'change_password') {
        $old_password = $_POST['old_password'] ?? '';
        $new_password = $_POST['new_password'] ?? '';
        $confirm_password = $_POST['confirm_password'] ?? '';
        
        if (empty($old_password) || empty($new_password) || empty($confirm_password)) {
            $error_msg = "請填寫所有欄位";
        } elseif ($new_password !== $confirm_password) {
            $error_msg = "新密碼不相符";
        } elseif ($user['password'] !== $old_password) {
            $error_msg = "原密碼不正確";
        } else {
            $update_sql = "UPDATE users SET password = ? WHERE id = ?";
            $update_stmt = $conn->prepare($update_sql);
            if ($update_stmt) {
                $update_stmt->bind_param("si", $new_password, $user_id);
                
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
}

// 獲取當前社團幹部身分確認狀態
$current_club_id = null;
$officer_status = null;

// 先從 session 或 user_current_role 表獲取當前社團
if (isset($_SESSION['current_club_id'])) {
    $current_club_id = $_SESSION['current_club_id'];
} else {
    // 從資料庫查詢預設社團
    $role_sql = "SELECT current_club_id FROM user_current_role WHERE user_id = ?";
    $role_stmt = $conn->prepare($role_sql);
    if ($role_stmt) {
        $role_stmt->bind_param("i", $user_id);
        $role_stmt->execute();
        $role_result = $role_stmt->get_result();
        if ($role_row = $role_result->fetch_assoc()) {
            $current_club_id = $role_row['current_club_id'];
        }
    }
}

// 如果還是沒有，就用第一個社團
if (!$current_club_id && !empty($user_clubs)) {
    $current_club_id = $user_clubs[0]['club_id'];
}

// 獲取當前社團的幹部身分
if ($current_club_id) {
    $officer_sql = "SELECT is_officer, officer_title, officer_confirmation_date, join_date
                    FROM club_members
                    WHERE user_id = ? AND club_id = ?";
    $officer_stmt = $conn->prepare($officer_sql);
    if ($officer_stmt) {
        $officer_stmt->bind_param("ii", $user_id, $current_club_id);
        $officer_stmt->execute();
        $officer_status = $officer_stmt->get_result()->fetch_assoc();
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
            --primary: #8B1538;
            --sidebar: #4c0f2a;
        }

        body {
            background: #f4f6fb;
            margin: 0;
            padding: 0;
        }

        .navbar-custom {
            background: white;
            border-bottom: 1px solid #e9ecef;
            padding: 1rem 2rem;
            box-shadow: 0 2px 4px rgba(0,0,0,0.05);
        }

        .navbar-custom a {
            color: var(--primary);
            text-decoration: none;
            margin-right: 1rem;
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
            background: linear-gradient(135deg, var(--primary), #a61e4d);
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
    </style>
</head>
<body>
    <nav class="navbar-custom">
        <a href="dashboard.php"><i class="bi bi-house-door"></i> 回到儀表板</a>
        <span class="text-muted">個人檔案</span>
    </nav>

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
                    <?php echo htmlspecialchars(substr($student_name, 0, 1)); ?>
                </div>
                <div class="profile-info">
                    <h2><?php echo htmlspecialchars($student_name); ?></h2>
                    <p><i class="bi bi-person-badge"></i> 學號：<?php echo htmlspecialchars($student_id); ?></p>
                    <p><i class="bi bi-calendar-event"></i> 加入時間：<?php echo date('Y 年 m 月 d 日', strtotime($user['created_at'])); ?></p>
                </div>
            </div>

            <div class="profile-body">
                <!-- 基本資訊 -->
                <div class="info-section">
                    <h5>基本資訊</h5>
                    <div class="info-row">
                        <div class="info-item">
                            <div class="info-label">系級</div>
                            <div class="info-value"><?php echo htmlspecialchars($user['department'] ?? '未填寫'); ?> <?php echo htmlspecialchars($user['grade'] ?? ''); ?></div>
                        </div>
                        <div class="info-item">
                            <div class="info-label">信箱</div>
                            <div class="info-value" style="font-size: 0.95rem;"><?php echo htmlspecialchars($student_id); ?></div>
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
                                        <?php echo htmlspecialchars($club['club_name']); ?>
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
                        ?>
                        <div class="info-row">
                            <div class="info-item">
                                <div class="info-label">身分</div>
                                <div class="info-value">
                                    <?php if ($officer_status['is_officer']): ?>
                                        <span class="officer-badge">
                                            <i class="bi bi-star"></i>
                                            <?php echo htmlspecialchars($officer_status['officer_title'] ?? '幹部'); ?>
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
                                <div class="info-label">入社時間</div>
                                <div class="info-value"><?php echo date('Y年m月d日', strtotime($officer_status['join_date'])); ?></div>
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

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
