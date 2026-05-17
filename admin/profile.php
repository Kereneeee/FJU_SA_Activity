<?php
ini_set('display_errors', 1);
error_reporting(E_ALL);

require_once(__DIR__ . "/../DB/db_config.php");

// 檢查登入
if (!isset($_SESSION['user_id'])) {
    header('Location: ../login.php');
    exit();
}

$user_name = $_SESSION['user_name'] ?? '管理員';
$user_id = $_SESSION['user_id'];

$success_msg = "";
$error_msg = "";

// 獲取用戶基本信息
$sql = "SELECT * FROM users WHERE user_id = ?";
$stmt = $conn->prepare($sql);
if (!$stmt) {
    die("SQL 準備失敗: " . $conn->error);
}

$stmt->bind_param("i", $user_id);
$stmt->execute();
$result = $stmt->get_result();
$user = $result->fetch_assoc();

if (!$user) {
    die("找不到用戶資訊");
}

// 處理密碼修改
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
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
            $update_sql = "UPDATE users SET password = ? WHERE user_id = ?";
            $update_stmt = $conn->prepare($update_sql);
            if ($update_stmt) {
                $update_stmt->bind_param("si", $new_password, $user_id);
                
                if ($update_stmt->execute()) {
                    $success_msg = "密碼已更新";
                    // 更新本地記錄
                    $user['password'] = $new_password;
                } else {
                    $error_msg = "密碼更新失敗";
                }
            } else {
                $error_msg = "SQL 準備失敗: " . $conn->error;
            }
        }
    }
}

$stmt->close();
?>

<!DOCTYPE html>
<html lang="zh-TW">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>個人檔案 - 輔仁大學課外活動指導組</title>

    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.5/font/bootstrap-icons.css" rel="stylesheet">

    <style>
        :root {
            --primary: #8B1538;
            --sidebar: #4c0f2a;
            --bg: #f4f6fb;
        }

        body {
            background: var(--bg);
            margin: 0;
            padding: 0;
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            color: #1f2937;
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

        .admin-badge {
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
                    <?php echo htmlspecialchars(substr($user_name, 0, 1)); ?>
                </div>
                <div class="profile-info">
                    <h2><?php echo htmlspecialchars($user_name); ?></h2>
                    <p><i class="bi bi-shield-check"></i> 管理員帳號</p>
                    <p>
                        <i class="bi bi-briefcase"></i> 身分：
                        <span class="admin-badge">
                            <i class="bi bi-star"></i>
                            系統管理員
                        </span>
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

                <!-- 帳號創建時間 -->
                <div class="info-section">
                    <h5>帳號資訊</h5>
                    <div class="info-item">
                        <div class="info-label">帳號建立於</div>
                        <div class="info-value"><?php echo $user['created_at'] ? date('Y年m月d日', strtotime($user['created_at'])) : '未記錄'; ?></div>
                    </div>
                </div>

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

                            <button type="submit" class="btn btn-primary-custom">
                                <i class="bi bi-check-lg"></i> 更新密碼
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
