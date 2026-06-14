<?php

require_once(__DIR__ . "/../DB/db_config.php");

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    header('Location: ../login.php');
    exit();
}

$current_page = 'admin_accounts';
$user_name = $_SESSION['user_name'] ?? '管理員';
$message = '';
$message_type = '';

function next_admin_student_id(mysqli $conn): int {
    $base = 900000000;
    $res = $conn->query("SELECT MAX(student_id) AS max_id FROM users WHERE role='admin' AND student_id >= {$base}");
    $row = $res ? $res->fetch_assoc() : null;
    $max = isset($row['max_id']) ? intval($row['max_id']) : 0;
    return max($base, $max + 1);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    $name = trim($_POST['name'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $phone = trim($_POST['phone'] ?? '');
    $password = $_POST['password'] ?? '';
    $confirm_password = $_POST['confirm_password'] ?? '';

    if ($action === 'create_admin') {
        if ($name === '' || $email === '' || $password === '') {
            $message = '請填寫姓名、Email 與初始密碼。';
            $message_type = 'danger';
        } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $message = 'Email 格式不正確。';
            $message_type = 'danger';
        } elseif (strlen($password) < 6) {
            $message = '密碼至少需要 6 個字元。';
            $message_type = 'danger';
        } elseif ($password !== $confirm_password) {
            $message = '兩次輸入的密碼不一致。';
            $message_type = 'danger';
        } else {
            $chk = $conn->prepare("SELECT user_id FROM users WHERE email = ?");
            $chk->bind_param("s", $email);
            $chk->execute();
            $exists = $chk->get_result()->num_rows > 0;
            $chk->close();

            if ($exists) {
                $message = '這個 Email 已經被使用。';
                $message_type = 'warning';
            } else {
                $student_id = next_admin_student_id($conn);
                $username = $email;
                $hashed = password_hash($password, PASSWORD_DEFAULT);
                $stmt = $conn->prepare(
                    "INSERT INTO users (name, student_id, email, phone, password, role, username)
                     VALUES (?, ?, ?, ?, ?, 'admin', ?)"
                );
                $stmt->bind_param("sissss", $name, $student_id, $email, $phone, $hashed, $username);
                $ok = $stmt->execute();
                $message = $ok ? '管理員帳號已新增。' : '新增失敗：' . $stmt->error;
                $message_type = $ok ? 'success' : 'danger';
                $stmt->close();
            }
        }
    }

    if ($action === 'update_admin') {
        $target_user_id = intval($_POST['user_id'] ?? 0);
        if ($target_user_id <= 0 || $name === '' || $email === '') {
            $message = '請填寫姓名與 Email。';
            $message_type = 'danger';
        } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $message = 'Email 格式不正確。';
            $message_type = 'danger';
        } else {
            $chk = $conn->prepare("SELECT user_id FROM users WHERE email = ? AND user_id != ?");
            $chk->bind_param("si", $email, $target_user_id);
            $chk->execute();
            $exists = $chk->get_result()->num_rows > 0;
            $chk->close();

            if ($exists) {
                $message = '這個 Email 已經被其他帳號使用。';
                $message_type = 'warning';
            } elseif ($password !== '' && strlen($password) < 6) {
                $message = '新密碼至少需要 6 個字元。';
                $message_type = 'danger';
            } elseif ($password !== '' && $password !== $confirm_password) {
                $message = '兩次輸入的新密碼不一致。';
                $message_type = 'danger';
            } else {
                if ($password !== '') {
                    $hashed = password_hash($password, PASSWORD_DEFAULT);
                    $stmt = $conn->prepare(
                        "UPDATE users SET name=?, email=?, phone=?, username=?, password=? WHERE user_id=? AND role='admin'"
                    );
                    $stmt->bind_param("sssssi", $name, $email, $phone, $email, $hashed, $target_user_id);
                } else {
                    $stmt = $conn->prepare(
                        "UPDATE users SET name=?, email=?, phone=?, username=? WHERE user_id=? AND role='admin'"
                    );
                    $stmt->bind_param("ssssi", $name, $email, $phone, $email, $target_user_id);
                }
                $ok = $stmt->execute();
                $message = $ok ? '管理員資料已更新。' : '更新失敗：' . $stmt->error;
                $message_type = $ok ? 'success' : 'danger';
                $stmt->close();
            }
        }
    }
}

$admins = [];
$res = $conn->query("SELECT user_id, name, email, phone, created_at FROM users WHERE role='admin' ORDER BY created_at DESC, user_id DESC");
if ($res) {
    $admins = $res->fetch_all(MYSQLI_ASSOC);
}
?>
<!DOCTYPE html>
<html lang="zh-TW">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>新增管理員 - 輔仁大學課外活動指導組</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.5/font/bootstrap-icons.css" rel="stylesheet">
    <style>
        :root { --primary:#1e4d6b; --bg:#f7f5ef; --card:#fff; }
        * { box-sizing:border-box; }
        body { margin:0; min-height:100vh; font-family:'Segoe UI',sans-serif; background:var(--bg); color:#1f2937; }
        .main-content { margin-left:260px; min-height:100vh; }
        .top-navbar { background:#d5e3ea; border-bottom:1px solid #bdd0d9; padding:1rem 2rem; display:flex; justify-content:space-between; align-items:center; position:sticky; top:0; z-index:1100; }
        .top-navbar .breadcrumb { margin:0; background:transparent; padding:0; font-size:.85rem; }
        .top-navbar .breadcrumb-item+.breadcrumb-item::before { content:'›'; color:#6b7280; }
        .top-navbar .breadcrumb-item a { color:var(--primary); text-decoration:none; opacity:.8; }
        .top-navbar .breadcrumb-item a:hover { opacity:1; }
        .top-navbar .breadcrumb-item.active { color:#6b7280; }
        .user-avatar { width:38px; height:38px; border-radius:50%; background:var(--primary); color:white; display:inline-flex; align-items:center; justify-content:center; font-weight:800; cursor:pointer; }
        .dashboard-grid { padding:1.5rem 2rem 2rem; }
        .card-panel { background:var(--card); border-radius:18px; box-shadow:0 10px 30px rgba(15,23,42,.06); padding:1.5rem; margin-bottom:1.5rem; border:1px solid rgba(148,163,184,.18); }
        .section-title { display:flex; align-items:center; gap:.55rem; color:var(--primary); font-weight:800; font-size:1.12rem; margin-bottom:1rem; }
        .admin-table { width:100%; border-collapse:collapse; font-size:.9rem; }
        .admin-table th { background:#f0f4f8; color:#374151; padding:.75rem .85rem; border-bottom:2px solid #e5e7eb; white-space:nowrap; }
        .admin-table td { padding:.75rem .85rem; border-bottom:1px solid #eef2f7; vertical-align:middle; }
        .admin-table tr:hover td { background:#fafafa; }
        .admin-pill { display:inline-flex; align-items:center; gap:.35rem; background:#dbeafe; color:#1e40af; border-radius:999px; padding:.2rem .6rem; font-size:.78rem; font-weight:700; }
        .btn-primary-custom { background:var(--primary); border-color:var(--primary); }
        .btn-primary-custom:hover { background:#14394f; border-color:#14394f; }
        .form-text-note { color:#6b7280; font-size:.82rem; }
        @media (max-width:768px) {
            .main-content { margin-left:0; }
            .top-navbar { flex-direction:column; align-items:flex-start; gap:1rem; padding:1rem; }
            .dashboard-grid { padding:1rem; }
        }
    </style>
</head>
<body>
<?php include(__DIR__ . '/../includes/admin_sidebar.php'); ?>

<main class="main-content">
    <?php
    $nav_breadcrumbs = [
        ['label' => '首頁', 'url' => 'dashboard.php'],
        ['label' => '新增管理員'],
    ];
    $nav_title = '新增管理員';
    include __DIR__ . '/../includes/admin_navbar.php';
    ?>

    <section class="dashboard-grid">
        <?php if ($message): ?>
        <div class="alert alert-<?= htmlspecialchars($message_type) ?> alert-dismissible fade show" role="alert">
            <?= htmlspecialchars($message) ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
        </div>
        <?php endif; ?>

        <div class="card-panel">
            <div class="section-title"><i class="bi bi-person-plus-fill"></i> 新增管理員帳號</div>
            <form method="POST" class="row g-3">
                <input type="hidden" name="action" value="create_admin">
                <div class="col-md-4">
                    <label class="form-label fw-semibold">姓名 <span class="text-danger">*</span></label>
                    <input type="text" name="name" class="form-control" required maxlength="50" placeholder="例如：顧北辰">
                </div>
                <div class="col-md-4">
                    <label class="form-label fw-semibold">Email <span class="text-danger">*</span></label>
                    <input type="email" name="email" class="form-control" required maxlength="100" placeholder="example@mail.fju.edu.tw">
                </div>
                <div class="col-md-4">
                    <label class="form-label fw-semibold">電話</label>
                    <input type="text" name="phone" class="form-control" maxlength="20" placeholder="選填">
                </div>
                <div class="col-md-4">
                    <label class="form-label fw-semibold">初始密碼 <span class="text-danger">*</span></label>
                    <input type="password" name="password" class="form-control" required minlength="6">
                    <div class="form-text-note mt-1">至少 6 個字元。</div>
                </div>
                <div class="col-md-4">
                    <label class="form-label fw-semibold">確認密碼 <span class="text-danger">*</span></label>
                    <input type="password" name="confirm_password" class="form-control" required minlength="6">
                </div>
                <div class="col-md-4 d-flex align-items-end">
                    <button type="submit" class="btn btn-primary btn-primary-custom w-100">
                        <i class="bi bi-person-plus me-1"></i> 建立管理員
                    </button>
                </div>
            </form>
        </div>

        <div class="card-panel">
            <div class="section-title"><i class="bi bi-person-gear"></i> 現有管理員</div>
            <?php if (empty($admins)): ?>
                <div class="text-center text-muted py-4">目前尚無管理員帳號。</div>
            <?php else: ?>
            <div style="overflow-x:auto;">
                <table class="admin-table">
                    <thead>
                        <tr>
                            <th>姓名</th>
                            <th>Email</th>
                            <th>電話</th>
                            <th>建立時間</th>
                            <th>操作</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($admins as $admin): ?>
                        <tr>
                            <td>
                                <strong><?= htmlspecialchars($admin['name'], ENT_QUOTES, 'UTF-8') ?></strong>
                                <?php if ((int)$admin['user_id'] === (int)$_SESSION['user_id']): ?>
                                    <span class="admin-pill ms-1"><i class="bi bi-person-check"></i>目前登入</span>
                                <?php endif; ?>
                            </td>
                            <td><?= htmlspecialchars($admin['email'] ?? '', ENT_QUOTES, 'UTF-8') ?></td>
                            <td><?= htmlspecialchars($admin['phone'] ?? '', ENT_QUOTES, 'UTF-8') ?></td>
                            <td><?= !empty($admin['created_at']) ? date('Y/m/d H:i', strtotime($admin['created_at'])) : '-' ?></td>
                            <td>
                                <button type="button"
                                        class="btn btn-sm btn-outline-primary"
                                        data-bs-toggle="modal"
                                        data-bs-target="#editAdminModal"
                                        data-user-id="<?= (int)$admin['user_id'] ?>"
                                        data-name="<?= htmlspecialchars($admin['name'], ENT_QUOTES, 'UTF-8') ?>"
                                        data-email="<?= htmlspecialchars($admin['email'] ?? '', ENT_QUOTES, 'UTF-8') ?>"
                                        data-phone="<?= htmlspecialchars($admin['phone'] ?? '', ENT_QUOTES, 'UTF-8') ?>">
                                    <i class="bi bi-pencil-square"></i> 編輯
                                </button>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php endif; ?>
        </div>
    </section>
</main>

<div class="modal fade" id="editAdminModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-centered">
        <form method="POST" class="modal-content">
            <input type="hidden" name="action" value="update_admin">
            <input type="hidden" name="user_id" id="edit_user_id">
            <div class="modal-header">
                <h5 class="modal-title"><i class="bi bi-pencil-square me-1"></i>編輯管理員</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <div class="row g-3">
                    <div class="col-md-6">
                        <label class="form-label fw-semibold">姓名</label>
                        <input type="text" name="name" id="edit_name" class="form-control" required maxlength="50">
                    </div>
                    <div class="col-md-6">
                        <label class="form-label fw-semibold">Email</label>
                        <input type="email" name="email" id="edit_email" class="form-control" required maxlength="100">
                    </div>
                    <div class="col-md-6">
                        <label class="form-label fw-semibold">電話</label>
                        <input type="text" name="phone" id="edit_phone" class="form-control" maxlength="20">
                    </div>
                    <div class="col-md-6">
                        <label class="form-label fw-semibold">新密碼</label>
                        <input type="password" name="password" id="edit_password" class="form-control" minlength="6" placeholder="不修改請留空">
                    </div>
                    <div class="col-md-6">
                        <label class="form-label fw-semibold">確認新密碼</label>
                        <input type="password" name="confirm_password" id="edit_confirm_password" class="form-control" minlength="6" placeholder="不修改請留空">
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">取消</button>
                <button type="submit" class="btn btn-primary btn-primary-custom">儲存變更</button>
            </div>
        </form>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
const editAdminModal = document.getElementById('editAdminModal');
editAdminModal.addEventListener('show.bs.modal', event => {
    const button = event.relatedTarget;
    document.getElementById('edit_user_id').value = button.getAttribute('data-user-id') || '';
    document.getElementById('edit_name').value = button.getAttribute('data-name') || '';
    document.getElementById('edit_email').value = button.getAttribute('data-email') || '';
    document.getElementById('edit_phone').value = button.getAttribute('data-phone') || '';
    document.getElementById('edit_password').value = '';
    document.getElementById('edit_confirm_password').value = '';
});
</script>
</body>
</html>
