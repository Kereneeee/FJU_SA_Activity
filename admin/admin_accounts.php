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

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    $name = trim($_POST['name'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $phone = trim($_POST['phone'] ?? '');
    $password = $_POST['password'] ?? '';
    $confirm_password = $_POST['confirm_password'] ?? '';

    if ($action === 'promote_admin') {
        $target_user_id = intval($_POST['target_user_id'] ?? 0);
        if ($target_user_id <= 0) {
            $message = '請選擇一個已註冊的一般帳號。';
            $message_type = 'danger';
        } else {
            $stmt = $conn->prepare("UPDATE users SET role='admin' WHERE user_id=? AND role='student'");
            $stmt->bind_param("i", $target_user_id);
            $stmt->execute();
            $ok = $stmt->affected_rows > 0;
            $message = $ok ? '已將該帳號加入管理員。' : '加入失敗，該帳號可能已經是管理員。';
            $message_type = $ok ? 'success' : 'warning';
            $stmt->close();
        }
    }

    if ($action === 'remove_admin') {
        $target_user_id = intval($_POST['target_user_id'] ?? 0);
        if ($target_user_id === (int)$_SESSION['user_id']) {
            $message = '無法移除自己的管理員權限。';
            $message_type = 'danger';
        } else {
            $stmt = $conn->prepare("UPDATE users SET role='student' WHERE user_id=? AND role='admin'");
            $stmt->bind_param("i", $target_user_id);
            $stmt->execute();
            $ok = $stmt->affected_rows > 0;
            $message = $ok ? '已移除該帳號的管理員權限，改為一般社員帳號。' : '移除失敗，該帳號可能已不是管理員。';
            $message_type = $ok ? 'success' : 'warning';
            $stmt->close();
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

$candidate_users = [];
$res_candidates = $conn->query("SELECT user_id, name, email, student_id FROM users WHERE role='student' ORDER BY name, email");
if ($res_candidates) {
    $candidate_users = $res_candidates->fetch_all(MYSQLI_ASSOC);
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
        .sidebar { position:fixed; top:0; left:0; width:260px; height:100vh; background:var(--primary); color:white; padding:1.5rem .8rem; overflow-y:hidden; box-shadow:3px 0 15px rgba(0,0,0,.12); z-index:1200; }
        .sidebar .brand { text-align:center; margin-bottom:1.5rem; }
        .sidebar .brand h4 { margin:0; font-size:1.1rem; line-height:1.4; font-weight:700; }
        .sidebar .nav-link { display:flex; align-items:center; gap:.75rem; color:rgba(255,255,255,.9); padding:.85rem 1rem; margin:.2rem 0; border-radius:16px; transition:background .25s,transform .15s; text-decoration:none; }
        .sidebar .nav-link:hover,.sidebar .nav-link.active { background:#ece8dd; color:var(--primary); transform:translateX(4px); }
        .sidebar .nav-link i { font-size:1.1rem; }
        .sidebar .sidebar-section { padding:1rem .5rem; margin-top:1.5rem; border-top:1px solid rgba(255,255,255,.12); }
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
            .sidebar { position:relative; width:100%; height:auto; box-shadow:none; }
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
                <input type="hidden" name="action" value="promote_admin">
                <div class="col-md-8">
                    <label class="form-label fw-semibold">選擇已註冊帳號 <span class="text-danger">*</span></label>
                    <select name="target_user_id" class="form-select" required>
                        <option value="">請選擇要加入管理員的帳號</option>
                        <?php foreach ($candidate_users as $candidate): ?>
                        <option value="<?= (int)$candidate['user_id'] ?>">
                            <?= htmlspecialchars($candidate['name'], ENT_QUOTES, 'UTF-8') ?>
                            ／<?= htmlspecialchars($candidate['email'] ?? '', ENT_QUOTES, 'UTF-8') ?>
                            <?php if (!empty($candidate['student_id'])): ?>
                            ／<?= htmlspecialchars((string)$candidate['student_id'], ENT_QUOTES, 'UTF-8') ?>
                            <?php endif; ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                    <div class="form-text-note mt-1">請先請對方完成一般帳號註冊，再由管理員在這裡加入管理員權限。</div>
                </div>
                <div class="col-md-4 d-flex align-items-end">
                    <?php if (empty($candidate_users)): ?>
                    <button type="button" class="btn btn-secondary w-100" disabled>目前沒有可加入的帳號</button>
                    <?php else: ?>
                    <button type="submit" class="btn btn-primary btn-primary-custom w-100"
                            onclick="return confirm('確定要將此帳號加入管理員嗎？');">
                        <i class="bi bi-person-plus me-1"></i> 加入管理員
                    </button>
                    <?php endif; ?>
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
                                <?php if ((int)$admin['user_id'] !== (int)$_SESSION['user_id']): ?>
                                <form method="POST" class="d-inline" onsubmit="return confirm('確定要移除「<?= htmlspecialchars($admin['name'], ENT_QUOTES, 'UTF-8') ?>」的管理員權限嗎？移除後該帳號將變回一般社員帳號。');">
                                    <input type="hidden" name="action" value="remove_admin">
                                    <input type="hidden" name="target_user_id" value="<?= (int)$admin['user_id'] ?>">
                                    <button type="submit" class="btn btn-sm btn-outline-danger">
                                        <i class="bi bi-person-dash"></i> 移除
                                    </button>
                                </form>
                                <?php endif; ?>
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
