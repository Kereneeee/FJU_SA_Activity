<?php
ini_set('display_errors', 1);
error_reporting(E_ALL);

require_once(__DIR__ . "/../DB/db_config.php");

if (!isset($_SESSION['user_id'])) {
    header('Location: ../login.php');
    exit();
}

$user_name    = $_SESSION['user_name'] ?? '管理員';
$current_page = 'user_mgmt';
$message      = '';
$message_type = '';

// ── 身分對應函式 ──────────────────────────────────────────
function role_to_fields(string $role, string $custom_title): array {
    switch ($role) {
        case '社長':   return [1, '社長'];
        case '副社長': return [1, '副社長'];
        case '幹部':   return [1, $custom_title];
        default:       return [0, ''];
    }
}

function get_role_label(array $m): string {
    if (!$m['is_officer']) return '一般成員';
    if ($m['officer_title'] === '社長')   return '社長';
    if ($m['officer_title'] === '副社長') return '副社長';
    return '幹部';
}

// ── 處理 POST 動作 ──────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    $action = $_POST['action'];

    // 更新社團身分（is_officer + officer_title）
    if ($action === 'update_membership') {
        $membership_id             = intval($_POST['membership_id'] ?? 0);
        $role                      = trim($_POST['role'] ?? '一般成員');
        $custom_title              = trim($_POST['officer_title'] ?? '');
        $officer_confirmation_date = trim($_POST['officer_confirmation_date'] ?? '');

        [$is_officer, $officer_title] = role_to_fields($role, $custom_title);

        if ($membership_id > 0) {
            $confirm_date = $officer_confirmation_date ?: null;
            $stmt = $conn->prepare(
                "UPDATE club_members SET is_officer = ?, officer_title = ?, officer_confirmation_date = ? WHERE membership_id = ?"
            );
            $stmt->bind_param("issi", $is_officer, $officer_title, $confirm_date, $membership_id);
            if ($stmt->execute()) {
                $message      = '身分已更新。';
                $message_type = 'success';
            } else {
                $message      = '更新失敗：' . $stmt->error;
                $message_type = 'danger';
            }
            $stmt->close();
        }
    }

    // 新增使用者加入社團
    if ($action === 'add_membership') {
        $target_user_id = intval($_POST['target_user_id'] ?? 0);
        $club_id        = trim($_POST['club_id'] ?? '');
        $role           = trim($_POST['role'] ?? '一般成員');
        $custom_title   = trim($_POST['officer_title'] ?? '');
        $join_date      = trim($_POST['join_date'] ?? '') ?: date('Y-m-d');
        [$is_officer, $officer_title] = role_to_fields($role, $custom_title);

        if ($target_user_id > 0 && $club_id !== '') {
            // 確認沒有重複
            $check = $conn->prepare("SELECT membership_id FROM club_members WHERE user_id = ? AND club_id = ?");
            $check->bind_param("is", $target_user_id, $club_id);
            $check->execute();
            if ($check->get_result()->num_rows > 0) {
                $message      = '該使用者已是此社團成員。';
                $message_type = 'warning';
            } else {
                $stmt = $conn->prepare(
                    "INSERT INTO club_members (user_id, club_id, is_officer, officer_title, join_date) VALUES (?, ?, ?, ?, ?)"
                );
                $stmt->bind_param("isiis", $target_user_id, $club_id, $is_officer, $officer_title, $join_date);
                if ($stmt->execute()) {
                    $message      = '已加入社團。';
                    $message_type = 'success';
                } else {
                    $message      = '新增失敗：' . $stmt->error;
                    $message_type = 'danger';
                }
                $stmt->close();
            }
            $check->close();
        }
    }

    // 移除社團成員資格
    if ($action === 'remove_membership') {
        $membership_id = intval($_POST['membership_id'] ?? 0);
        if ($membership_id > 0) {
            $stmt = $conn->prepare("DELETE FROM club_members WHERE membership_id = ?");
            $stmt->bind_param("i", $membership_id);
            if ($stmt->execute()) {
                $message      = '已移除社團成員資格。';
                $message_type = 'success';
            } else {
                $message      = '移除失敗：' . $stmt->error;
                $message_type = 'danger';
            }
            $stmt->close();
        }
    }

    header("Location: user_mgmt.php" . ($message ? '?msg=' . urlencode($message) . '&type=' . $message_type : ''));
    exit();
}

if (isset($_GET['msg'])) {
    $message      = htmlspecialchars($_GET['msg']);
    $message_type = htmlspecialchars($_GET['type'] ?? 'info');
}

// ── 搜尋 ──────────────────────────────────────────────────────
$search      = trim($_GET['search'] ?? '');
$focus_user  = intval($_GET['user_id'] ?? 0);

// 取得所有學生使用者
$where = "WHERE u.role = 'student'";
$params = [];
$types  = '';
if ($search !== '') {
    $where   .= " AND (u.name LIKE ? OR u.email LIKE ? OR u.student_id LIKE ?)";
    $like     = "%{$search}%";
    $params   = [$like, $like, $like];
    $types    = 'sss';
}

$sql_users = "SELECT u.user_id, u.name, u.email, u.student_id, u.phone, u.role
              FROM users u $where ORDER BY u.user_id ASC";

if ($types) {
    $stmt_u = $conn->prepare($sql_users);
    $stmt_u->bind_param($types, ...$params);
    $stmt_u->execute();
    $result_u = $stmt_u->get_result();
} else {
    $result_u = $conn->query($sql_users);
}
$users = $result_u ? $result_u->fetch_all(MYSQLI_ASSOC) : [];

// 取得所有社團（供新增社團成員使用）
$all_clubs = $conn->query("SELECT club_id, club_name FROM clubs ORDER BY club_id") ->fetch_all(MYSQLI_ASSOC);

// 取得各使用者的社團成員資料
$user_ids     = array_column($users, 'user_id');
$memberships  = [];
if (!empty($user_ids)) {
    $in = implode(',', array_map('intval', $user_ids));
    $res = $conn->query(
        "SELECT cm.*, c.club_name
         FROM club_members cm
         JOIN clubs c ON cm.club_id = c.club_id
         WHERE cm.user_id IN ($in)
         ORDER BY cm.user_id, cm.club_id"
    );
    if ($res) {
        while ($row = $res->fetch_assoc()) {
            $memberships[$row['user_id']][] = $row;
        }
    }
}
?>
<!DOCTYPE html>
<html lang="zh-TW">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>身分權限管理 - 輔仁大學課外活動指導組</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.5/font/bootstrap-icons.css" rel="stylesheet">
    <style>
        :root {
            --primary: #8B1538;
            --sidebar: #4c0f2a;
            --bg: #f4f6fb;
        }
        * { box-sizing: border-box; }
        body { margin: 0; font-family: 'Segoe UI', sans-serif; background: var(--bg); }

        /* ── sidebar (copied from existing admin pages) ── */
        .sidebar {
            position: fixed; top: 0; left: 0;
            width: 260px; height: 100vh;
            background: linear-gradient(180deg, var(--primary), var(--sidebar));
            color: white; padding: 1.5rem 0.8rem;
            overflow-y: auto; box-shadow: 3px 0 15px rgba(0,0,0,.12); z-index: 1200;
        }
        .sidebar .brand { text-align: center; margin-bottom: 1.5rem; }
        .sidebar .brand h4 { margin: 0; font-size: 1.1rem; line-height: 1.4; font-weight: 700; }
        .sidebar .nav-link {
            display: flex; align-items: center; gap: .75rem;
            color: rgba(255,255,255,.9); padding: .85rem 1rem; margin: .2rem 0;
            border-radius: 16px; transition: background .25s, transform .15s; text-decoration: none;
        }
        .sidebar .nav-link:hover,
        .sidebar .nav-link.active { background: rgba(255,255,255,.12); color: #fff; transform: translateX(4px); }
        .sidebar .nav-link i { font-size: 1.1rem; }
        .sidebar .sidebar-section { padding: 1rem .5rem; margin-top: 1.5rem; border-top: 1px solid rgba(255,255,255,.12); }

        .main-content { margin-left: 260px; min-height: 100vh; }
        .top-navbar {
            background: white; border-bottom: 1px solid #e9ecef;
            padding: 1rem 2rem; display: flex; justify-content: space-between;
            align-items: center; position: sticky; top: 0; z-index: 1100;
        }
        .content-wrapper { padding: 1.5rem 2rem 3rem; }

        /* ── cards ── */
        .panel { background: white; border-radius: 18px; box-shadow: 0 6px 24px rgba(15,23,42,.06); margin-bottom: 1.5rem; }
        .panel-header { padding: 1.25rem 1.5rem; border-bottom: 1px solid #e9ecef; display: flex; align-items: center; gap: .6rem; font-weight: 700; color: var(--primary); }
        .panel-body { padding: 1.25rem 1.5rem; }

        /* ── user rows ── */
        .user-row { border: 1px solid #e9ecef; border-radius: 14px; margin-bottom: .75rem; overflow: hidden; }
        .user-row-header {
            display: flex; align-items: center; gap: 1rem; padding: .9rem 1.2rem;
            cursor: pointer; background: white; transition: background .2s;
        }
        .user-row-header:hover { background: #fafafa; }
        .user-avatar {
            width: 42px; height: 42px; border-radius: 50%; background: var(--primary);
            color: white; display: grid; place-items: center; font-weight: 700; font-size: 1rem; flex-shrink: 0;
        }
        .user-meta { flex: 1; min-width: 0; }
        .user-name { font-weight: 600; color: #1f2937; }
        .user-sub { font-size: .82rem; color: #6b7280; }
        .toggle-icon { transition: transform .3s; color: #9ca3af; }
        .user-row.open .toggle-icon { transform: rotate(180deg); }

        .user-detail { display: none; padding: 1rem 1.2rem 1.2rem; border-top: 1px solid #f3f4f6; background: #fafafa; }
        .user-row.open .user-detail { display: block; }

        /* ── membership table ── */
        .mem-table { width: 100%; border-collapse: collapse; font-size: .88rem; }
        .mem-table th { background: #f3f4f6; color: #374151; font-weight: 600; padding: .6rem .9rem; text-align: left; }
        .mem-table td { padding: .55rem .9rem; border-bottom: 1px solid #e9ecef; vertical-align: middle; }
        .mem-table tr:last-child td { border-bottom: none; }

        .badge-officer { background: #d1e7dd; color: #0f5132; padding: .25rem .65rem; border-radius: 999px; font-size: .78rem; font-weight: 600; }
        .badge-member  { background: #e2e3e5; color: #383d41; padding: .25rem .65rem; border-radius: 999px; font-size: .78rem; font-weight: 600; }

        /* inline edit form */
        .edit-form { display: none; background: #f0f4ff; border-radius: 10px; padding: 1rem; margin-top: .5rem; }
        .edit-form.show { display: block; }
        .edit-form .row { margin: 0 -.4rem; }
        .edit-form .col { padding: 0 .4rem; }
        .form-control-sm, .form-select-sm { font-size: .85rem; }
        .btn-xs { padding: .25rem .6rem; font-size: .8rem; }

        /* add-membership section */
        .add-mem-section { background: #fffbea; border: 1px dashed #fbbf24; border-radius: 10px; padding: 1rem; margin-top: 1rem; }
        .add-mem-section h6 { color: #92400e; margin-bottom: .75rem; font-size: .9rem; }

        .search-bar { max-width: 420px; }
    </style>
</head>
<body>
<?php include(__DIR__ . '/../includes/admin_sidebar.php'); ?>

<main class="main-content">
    <header class="top-navbar">
        <div>
            <ol class="breadcrumb mb-0" style="font-size:.85rem;">
                <li class="breadcrumb-item"><a href="dashboard.php">首頁</a></li>
                <li class="breadcrumb-item active">身分權限管理</li>
            </ol>
            <h4 class="mt-1 mb-0">身分權限管理</h4>
        </div>
        <div class="d-flex align-items-center gap-2">
            <div class="user-avatar" style="cursor:pointer;" onclick="location.href='profile.php'">
                <?= htmlspecialchars(substr($user_name, 0, 1)) ?>
            </div>
            <small class="text-muted"><?= htmlspecialchars($user_name) ?></small>
        </div>
    </header>

    <section class="content-wrapper">

        <?php if ($message): ?>
        <div class="alert alert-<?= htmlspecialchars($message_type) ?> alert-dismissible fade show" role="alert">
            <?= $message ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
        <?php endif; ?>

        <!-- 搜尋 -->
        <div class="panel">
            <div class="panel-body">
                <form method="GET" class="d-flex gap-2 align-items-center">
                    <div class="input-group search-bar">
                        <span class="input-group-text"><i class="bi bi-search"></i></span>
                        <input type="text" name="search" class="form-control"
                               placeholder="搜尋姓名、信箱、學號…"
                               value="<?= htmlspecialchars($search) ?>">
                    </div>
                    <button type="submit" class="btn btn-primary btn-sm">搜尋</button>
                    <?php if ($search): ?>
                        <a href="user_mgmt.php" class="btn btn-outline-secondary btn-sm">清除</a>
                    <?php endif; ?>
                    <span class="text-muted ms-2" style="font-size:.85rem;">共 <?= count($users) ?> 位使用者</span>
                </form>
            </div>
        </div>

        <!-- 使用者列表 -->
        <div class="panel">
            <div class="panel-header">
                <i class="bi bi-people"></i> 使用者列表
            </div>
            <div class="panel-body">
                <?php if (empty($users)): ?>
                    <p class="text-muted text-center py-3">查無使用者</p>
                <?php else: ?>
                    <?php foreach ($users as $u): ?>
                    <?php
                        $uid   = $u['user_id'];
                        $mems  = $memberships[$uid] ?? [];
                        $open  = ($focus_user === $uid) ? ' open' : '';
                    ?>
                    <div class="user-row<?= $open ?>" id="user-<?= $uid ?>">
                        <!-- 標題列：點擊展開 -->
                        <div class="user-row-header" onclick="toggleUser(<?= $uid ?>)">
                            <div class="user-avatar">
                                <?= htmlspecialchars(mb_substr($u['name'], 0, 1)) ?>
                            </div>
                            <div class="user-meta">
                                <div class="user-name"><?= htmlspecialchars($u['name']) ?></div>
                                <div class="user-sub">
                                    學號：<?= htmlspecialchars($u['student_id']) ?>
                                    &nbsp;·&nbsp;<?= htmlspecialchars($u['email']) ?>
                                    <?php if (!empty($mems)): ?>
                                        &nbsp;·&nbsp;<span style="color:#6366f1;">社團 <?= count($mems) ?> 個</span>
                                    <?php else: ?>
                                        &nbsp;·&nbsp;<span style="color:#9ca3af;">尚無社團</span>
                                    <?php endif; ?>
                                </div>
                            </div>
                            <i class="bi bi-chevron-down toggle-icon"></i>
                        </div>

                        <!-- 展開區塊 -->
                        <div class="user-detail">
                            <!-- 社團成員身分列表 -->
                            <?php if (!empty($mems)): ?>
                            <table class="mem-table mb-3">
                                <thead>
                                    <tr>
                                        <th>社團</th>
                                        <th>加入日期</th>
                                        <th>身分</th>
                                        <th>職稱</th>
                                        <th>確認日期</th>
                                        <th>操作</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($mems as $m): ?>
                                    <tr>
                                        <td><strong><?= htmlspecialchars($m['club_name']) ?></strong>
                                            <br><small class="text-muted"><?= htmlspecialchars($m['club_id']) ?></small>
                                        </td>
                                        <td><?= htmlspecialchars($m['join_date'] ?? '—') ?></td>
                                        <td>
                                            <span class="<?= $m['is_officer'] ? 'badge-officer' : 'badge-member' ?>">
                                                <?= $m['is_officer'] ? '幹部' : '一般成員' ?>
                                            </span>
                                        </td>
                                        <td><?= htmlspecialchars($m['officer_title'] ?: '—') ?></td>
                                        <td><?= htmlspecialchars($m['officer_confirmation_date'] ?? '—') ?></td>
                                        <td>
                                            <button class="btn btn-outline-primary btn-xs"
                                                    onclick="toggleEdit('edit-<?= $m['membership_id'] ?>')">
                                                <i class="bi bi-pencil"></i> 編輯
                                            </button>
                                            <form method="POST" style="display:inline;"
                                                  onsubmit="return confirm('確定移除 <?= htmlspecialchars($u['name']) ?> 的 <?= htmlspecialchars($m['club_name']) ?> 成員資格？')">
                                                <input type="hidden" name="action" value="remove_membership">
                                                <input type="hidden" name="membership_id" value="<?= $m['membership_id'] ?>">
                                                <button type="submit" class="btn btn-outline-danger btn-xs">
                                                    <i class="bi bi-trash"></i>
                                                </button>
                                            </form>
                                        </td>
                                    </tr>
                                    <!-- 內嵌編輯表單 -->
                                    <tr>
                                        <td colspan="6" style="padding: 0 .9rem .5rem;">
                                            <div class="edit-form" id="edit-<?= $m['membership_id'] ?>">
                                                <form method="POST">
                                                    <input type="hidden" name="action" value="update_membership">
                                                    <input type="hidden" name="membership_id" value="<?= $m['membership_id'] ?>">
                                                    <?php
                                                        $cur_role = get_role_label($m);
                                                        $title_readonly = ($cur_role !== '幹部') ? 'readonly' : '';
                                                        $title_val = ($cur_role === '幹部') ? htmlspecialchars($m['officer_title'] ?? '') : '';
                                                    ?>
                                                    <div class="row g-2 align-items-end">
                                                        <div class="col-auto">
                                                            <label class="form-label mb-1" style="font-size:.8rem;">身分</label>
                                                            <select name="role" class="form-select form-select-sm" onchange="handleRoleChange(this)">
                                                                <option value="一般成員" <?= $cur_role === '一般成員' ? 'selected' : '' ?>>一般成員</option>
                                                                <option value="幹部"    <?= $cur_role === '幹部'    ? 'selected' : '' ?>>幹部</option>
                                                                <option value="副社長"  <?= $cur_role === '副社長'  ? 'selected' : '' ?>>副社長</option>
                                                                <option value="社長"    <?= $cur_role === '社長'    ? 'selected' : '' ?>>社長</option>
                                                            </select>
                                                        </div>
                                                        <div class="col">
                                                            <label class="form-label mb-1" style="font-size:.8rem;">職稱（幹部可手動填寫）</label>
                                                            <input type="text" name="officer_title" class="form-control form-control-sm"
                                                                   placeholder="例：器材幹部、活動幹部…"
                                                                   value="<?= $title_val ?>"
                                                                   <?= $title_readonly ?>
                                                                   style="<?= $title_readonly ? 'background:#f3f4f6;color:#6b7280;' : '' ?>">
                                                        </div>
                                                        <div class="col-auto">
                                                            <label class="form-label mb-1" style="font-size:.8rem;">確認日期</label>
                                                            <input type="date" name="officer_confirmation_date" class="form-control form-control-sm"
                                                                   value="<?= htmlspecialchars($m['officer_confirmation_date'] ?? '') ?>">
                                                        </div>
                                                        <div class="col-auto d-flex gap-1">
                                                            <button type="submit" class="btn btn-success btn-xs">
                                                                <i class="bi bi-check-lg"></i> 儲存
                                                            </button>
                                                            <button type="button" class="btn btn-secondary btn-xs"
                                                                    onclick="toggleEdit('edit-<?= $m['membership_id'] ?>')">
                                                                取消
                                                            </button>
                                                        </div>
                                                    </div>
                                                </form>
                                            </div>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                            <?php else: ?>
                                <p class="text-muted mb-2" style="font-size:.88rem;">此使用者尚未加入任何社團。</p>
                            <?php endif; ?>

                            <!-- 新增社團 -->
                            <div class="add-mem-section">
                                <h6><i class="bi bi-plus-circle"></i> 新增社團成員資格</h6>
                                <form method="POST">
                                    <input type="hidden" name="action" value="add_membership">
                                    <input type="hidden" name="target_user_id" value="<?= $uid ?>">
                                    <div class="row g-2 align-items-end">
                                        <div class="col-md-3">
                                            <label class="form-label mb-1" style="font-size:.8rem;">社團</label>
                                            <select name="club_id" class="form-select form-select-sm" required>
                                                <option value="">— 選擇社團 —</option>
                                                <?php foreach ($all_clubs as $cl): ?>
                                                <option value="<?= htmlspecialchars($cl['club_id']) ?>">
                                                    <?= htmlspecialchars($cl['club_id']) ?> <?= htmlspecialchars($cl['club_name']) ?>
                                                </option>
                                                <?php endforeach; ?>
                                            </select>
                                        </div>
                                        <div class="col-md-2">
                                            <label class="form-label mb-1" style="font-size:.8rem;">身分</label>
                                            <select name="role" class="form-select form-select-sm" onchange="handleRoleChange(this)">
                                                <option value="一般成員">一般成員</option>
                                                <option value="幹部">幹部</option>
                                                <option value="副社長">副社長</option>
                                                <option value="社長">社長</option>
                                            </select>
                                        </div>
                                        <div class="col-md-3">
                                            <label class="form-label mb-1" style="font-size:.8rem;">職稱（幹部可手動填寫）</label>
                                            <input type="text" name="officer_title" class="form-control form-control-sm"
                                                   placeholder="例：器材幹部、活動幹部…"
                                                   readonly
                                                   style="background:#f3f4f6;color:#6b7280;">
                                        </div>
                                        <div class="col-md-2">
                                            <label class="form-label mb-1" style="font-size:.8rem;">加入日期</label>
                                            <input type="date" name="join_date" class="form-control form-control-sm"
                                                   value="<?= date('Y-m-d') ?>">
                                        </div>
                                        <div class="col-md-2">
                                            <button type="submit" class="btn btn-warning btn-sm w-100">
                                                <i class="bi bi-plus"></i> 加入
                                            </button>
                                        </div>
                                    </div>
                                </form>
                            </div>
                        </div><!-- /user-detail -->
                    </div><!-- /user-row -->
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>

    </section>
</main>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
function toggleUser(uid) {
    document.getElementById('user-' + uid).classList.toggle('open');
}

function toggleEdit(id) {
    document.getElementById(id).classList.toggle('show');
}

function handleRoleChange(select) {
    const form       = select.closest('form');
    const titleInput = form.querySelector('input[name="officer_title"]');
    const role       = select.value;

    if (role === '社長') {
        titleInput.value    = '社長';
        titleInput.readOnly = true;
        titleInput.style.background = '#f3f4f6';
        titleInput.style.color      = '#6b7280';
    } else if (role === '副社長') {
        titleInput.value    = '副社長';
        titleInput.readOnly = true;
        titleInput.style.background = '#f3f4f6';
        titleInput.style.color      = '#6b7280';
    } else if (role === '幹部') {
        titleInput.value    = '';
        titleInput.readOnly = false;
        titleInput.style.background = '';
        titleInput.style.color      = '';
        titleInput.placeholder      = '例：器材幹部、活動幹部…';
        titleInput.focus();
    } else {
        titleInput.value    = '';
        titleInput.readOnly = true;
        titleInput.style.background = '#f3f4f6';
        titleInput.style.color      = '#6b7280';
    }
}

<?php if ($focus_user > 0): ?>
document.getElementById('user-<?= $focus_user ?>')?.scrollIntoView({ behavior: 'smooth', block: 'start' });
<?php endif; ?>
</script>
</body>
</html>
