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

// ── 身分對應函式 ──────────────────────────────────────────────
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

// ── 目前檢視的社團 ─────────────────────────────────────────────
$club_id = trim($_GET['club_id'] ?? '');

// ── 處理 POST ────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    $action = $_POST['action'];
    $back_club = trim($_POST['back_club'] ?? $club_id);

    // 更新身分
    if ($action === 'update_membership') {
        $membership_id             = intval($_POST['membership_id'] ?? 0);
        $role                      = trim($_POST['role'] ?? '一般成員');
        $custom_title              = trim($_POST['officer_title'] ?? '');
        $officer_confirmation_date = trim($_POST['officer_confirmation_date'] ?? '');
        [$is_officer, $officer_title] = role_to_fields($role, $custom_title);
        $confirm_date = $officer_confirmation_date ?: null;

        $stmt = $conn->prepare(
            "UPDATE club_members SET is_officer=?, officer_title=?, officer_confirmation_date=? WHERE membership_id=?"
        );
        $stmt->bind_param("issi", $is_officer, $officer_title, $confirm_date, $membership_id);
        $ok           = $stmt->execute();
        $message      = $ok ? '身分已更新。' : '更新失敗：' . $stmt->error;
        $message_type = $ok ? 'success' : 'danger';
        $stmt->close();
    }

    // 新增成員
    if ($action === 'add_membership') {
        $target_user_id = intval($_POST['target_user_id'] ?? 0);
        $add_club_id    = trim($_POST['club_id_add'] ?? $back_club);
        $role           = trim($_POST['role'] ?? '一般成員');
        $custom_title   = trim($_POST['officer_title'] ?? '');
        $join_date      = trim($_POST['join_date'] ?? '') ?: date('Y-m-d');
        [$is_officer, $officer_title] = role_to_fields($role, $custom_title);

        $check = $conn->prepare("SELECT membership_id FROM club_members WHERE user_id=? AND club_id=?");
        $check->bind_param("is", $target_user_id, $add_club_id);
        $check->execute();
        if ($check->get_result()->num_rows > 0) {
            $message = '該使用者已是此社團成員。';
            $message_type = 'warning';
        } else {
            $stmt = $conn->prepare(
                "INSERT INTO club_members (user_id, club_id, is_officer, officer_title, join_date) VALUES (?,?,?,?,?)"
            );
            $stmt->bind_param("isiss", $target_user_id, $add_club_id, $is_officer, $officer_title, $join_date);
            $ok           = $stmt->execute();
            $message      = $ok ? '已加入社團。' : '新增失敗：' . $stmt->error;
            $message_type = $ok ? 'success' : 'danger';
            $stmt->close();
        }
        $check->close();
        $back_club = $add_club_id;
    }

    // 移除成員
    if ($action === 'remove_membership') {
        $membership_id = intval($_POST['membership_id'] ?? 0);
        $stmt = $conn->prepare("DELETE FROM club_members WHERE membership_id=?");
        $stmt->bind_param("i", $membership_id);
        $ok           = $stmt->execute();
        $message      = $ok ? '已移除成員資格。' : '移除失敗：' . $stmt->error;
        $message_type = $ok ? 'success' : 'danger';
        $stmt->close();
    }

    // 審核提名 — 核准
    if ($action === 'approve_nomination') {
        $nomination_id = intval($_POST['nomination_id'] ?? 0);

        // 取得提名資料
        $ns = $conn->prepare("SELECT * FROM officer_nominations WHERE nomination_id=? AND status='pending'");
        $ns->bind_param("i", $nomination_id);
        $ns->execute();
        $nom = $ns->get_result()->fetch_assoc();
        $ns->close();

        if ($nom) {
            // 確認被提名者是社團成員，若不是先加入
            $cm_chk = $conn->prepare("SELECT membership_id FROM club_members WHERE user_id=? AND club_id=?");
            $cm_chk->bind_param("is", $nom['nominated_user_id'], $nom['club_id']);
            $cm_chk->execute();
            $cm_row = $cm_chk->get_result()->fetch_assoc();
            $cm_chk->close();

            $is_officer    = $nom['officer_title'] !== '' ? 1 : 0;
            $officer_title = $nom['officer_title'];

            if ($cm_row) {
                $up = $conn->prepare("UPDATE club_members SET is_officer=?, officer_title=?, officer_confirmation_date=CURDATE() WHERE membership_id=?");
                $up->bind_param("isi", $is_officer, $officer_title, $cm_row['membership_id']);
                $up->execute();
                $up->close();
            } else {
                $ins = $conn->prepare("INSERT INTO club_members (user_id, club_id, is_officer, officer_title, join_date, officer_confirmation_date) VALUES (?,?,?,?,CURDATE(),CURDATE())");
                $ins->bind_param("isis", $nom['nominated_user_id'], $nom['club_id'], $is_officer, $officer_title);
                $ins->execute();
                $ins->close();
            }

            // 更新提名狀態
            $upn = $conn->prepare("UPDATE officer_nominations SET status='approved', reviewed_at=NOW() WHERE nomination_id=?");
            $upn->bind_param("i", $nomination_id);
            $upn->execute();
            $upn->close();

            $message      = '已核准，幹部身分生效。';
            $message_type = 'success';
            $back_club    = $nom['club_id'];
        } else {
            $message = '找不到此提名。';
            $message_type = 'danger';
        }
    }

    // 審核提名 — 駁回
    if ($action === 'reject_nomination') {
        $nomination_id = intval($_POST['nomination_id'] ?? 0);
        $review_note   = trim($_POST['review_note'] ?? '');

        $ns = $conn->prepare("SELECT club_id FROM officer_nominations WHERE nomination_id=? AND status='pending'");
        $ns->bind_param("i", $nomination_id);
        $ns->execute();
        $nom_row = $ns->get_result()->fetch_assoc();
        $ns->close();

        if ($nom_row) {
            $upn = $conn->prepare("UPDATE officer_nominations SET status='rejected', review_note=?, reviewed_at=NOW() WHERE nomination_id=?");
            $upn->bind_param("si", $review_note, $nomination_id);
            $upn->execute();
            $upn->close();
            $message      = '已駁回提名。';
            $message_type = 'success';
            $back_club    = $nom_row['club_id'];
        } else {
            $message = '找不到此提名。';
            $message_type = 'danger';
        }
    }

    // 編輯提名職稱（僅限 pending）
    if ($action === 'edit_nomination') {
        $nomination_id = intval($_POST['nomination_id'] ?? 0);
        $new_title     = trim($_POST['officer_title'] ?? '');

        $ns = $conn->prepare("SELECT club_id FROM officer_nominations WHERE nomination_id=? AND status='pending'");
        $ns->bind_param("i", $nomination_id);
        $ns->execute();
        $nom_row = $ns->get_result()->fetch_assoc();
        $ns->close();

        if ($nom_row) {
            $upn = $conn->prepare("UPDATE officer_nominations SET officer_title=? WHERE nomination_id=?");
            $upn->bind_param("si", $new_title, $nomination_id);
            $ok           = $upn->execute();
            $message      = $ok ? '提名職稱已更新。' : '更新失敗：' . $upn->error;
            $message_type = $ok ? 'success' : 'danger';
            $back_club    = $nom_row['club_id'];
            $upn->close();
        } else {
            $message      = '找不到此提名或已完成審核。';
            $message_type = 'danger';
        }
    }

    // 刪除提名（直接移除 pending 記錄）
    if ($action === 'delete_nomination') {
        $nomination_id = intval($_POST['nomination_id'] ?? 0);

        $ns = $conn->prepare("SELECT club_id FROM officer_nominations WHERE nomination_id=? AND status='pending'");
        $ns->bind_param("i", $nomination_id);
        $ns->execute();
        $nom_row = $ns->get_result()->fetch_assoc();
        $ns->close();

        if ($nom_row) {
            $del = $conn->prepare("DELETE FROM officer_nominations WHERE nomination_id=? AND status='pending'");
            $del->bind_param("i", $nomination_id);
            $ok           = $del->execute();
            $message      = $ok ? '提名已刪除。' : '刪除失敗：' . $del->error;
            $message_type = $ok ? 'success' : 'danger';
            $back_club    = $nom_row['club_id'];
            $del->close();
        } else {
            $message      = '找不到此提名或已完成審核。';
            $message_type = 'danger';
        }
    }

    $qs = $back_club ? '?club_id=' . urlencode($back_club) . '&' : '?';
    header("Location: user_mgmt.php{$qs}msg=" . urlencode($message) . '&type=' . $message_type);
    exit();
}

if (isset($_GET['msg'])) {
    $message      = htmlspecialchars($_GET['msg']);
    $message_type = htmlspecialchars($_GET['type'] ?? 'info');
}

// ── 社團清單（含統計） ──────────────────────────────────────────
$search_club = trim($_GET['search'] ?? '');
$club_where  = $search_club ? "WHERE c.club_name LIKE '%" . $conn->real_escape_string($search_club) . "%' OR c.club_id LIKE '%" . $conn->real_escape_string($search_club) . "%'" : '';

$all_clubs = $conn->query(
    "SELECT c.club_id, c.club_name,
            COUNT(cm.membership_id) AS member_count,
            SUM(cm.is_officer)      AS officer_count,
            COALESCE((SELECT COUNT(*) FROM officer_nominations n
                      WHERE n.club_id = c.club_id AND n.status = 'pending'), 0) AS pending_nominations
     FROM clubs c
     LEFT JOIN club_members cm ON c.club_id = cm.club_id
     $club_where
     GROUP BY c.club_id
     ORDER BY c.club_id"
)->fetch_all(MYSQLI_ASSOC);

// ── 若有選定社團，取得成員列表 ─────────────────────────────────
$club_info   = null;
$members     = [];
$all_users   = [];

if ($club_id !== '') {
    $stmt_ci = $conn->prepare("SELECT club_id, club_name FROM clubs WHERE club_id=?");
    $stmt_ci->bind_param("s", $club_id);
    $stmt_ci->execute();
    $club_info = $stmt_ci->get_result()->fetch_assoc();
    $stmt_ci->close();

    if ($club_info) {
        $res_m = $conn->prepare(
            "SELECT cm.*, u.name AS user_name, u.email, u.student_id
             FROM club_members cm
             JOIN users u ON cm.user_id = u.user_id
             WHERE cm.club_id = ?
             ORDER BY cm.is_officer DESC, cm.officer_title, u.name"
        );
        $res_m->bind_param("s", $club_id);
        $res_m->execute();
        $members = $res_m->get_result()->fetch_all(MYSQLI_ASSOC);
        $res_m->close();
    }

    // 取得待審核提名
    $nominations = [];
    if ($club_info) {
        $res_n = $conn->prepare(
            "SELECT n.*, u.name AS nominated_name, u.student_id AS nominated_sid,
                    nu.name AS nominator_name
             FROM officer_nominations n
             JOIN users u  ON n.nominated_user_id = u.user_id
             JOIN users nu ON n.nominator_user_id  = nu.user_id
             WHERE n.club_id = ? AND n.status = 'pending'
             ORDER BY n.created_at ASC"
        );
        $res_n->bind_param("s", $club_id);
        $res_n->execute();
        $nominations = $res_n->get_result()->fetch_all(MYSQLI_ASSOC);
        $res_n->close();
    }

    // 取得所有學生（供新增選用）
    $member_ids = array_column($members, 'user_id');
    $exclude    = empty($member_ids) ? '' : 'AND user_id NOT IN (' . implode(',', array_map('intval', $member_ids)) . ')';
    $res_users  = $conn->query(
        "SELECT user_id, name, email, student_id FROM users WHERE role='student' $exclude ORDER BY name"
    );
    $all_users  = $res_users ? $res_users->fetch_all(MYSQLI_ASSOC) : [];
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
            --primary: #1e4d6b;
            --sidebar: #14394f;
            --sidebar-hover: #ece8dd;
            --bg: #f7f5ef;
            --card: #ffffff;
        }
        * { box-sizing: border-box; }
        body { margin: 0; font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; background: var(--bg); color: #1f2937; }

        /* ── sidebar ── */
        .sidebar {
            position: fixed; top: 0; left: 0;
            width: 260px; height: 100vh;
            background: var(--primary);
            color: white; padding: 1.5rem 0.8rem;
            overflow-y: hidden; box-shadow: 3px 0 15px rgba(0,0,0,.12); z-index: 1200;
        }
        .sidebar .brand { text-align: center; margin-bottom: 1.5rem; }
        .sidebar .brand h4 { margin: 0; font-size: 1.1rem; line-height: 1.4; font-weight: 700; }
        .sidebar .nav-link {
            display: flex; align-items: center; gap: .75rem;
            color: rgba(255,255,255,.9); padding: .85rem 1rem; margin: .2rem 0;
            border-radius: 16px; transition: background .25s ease, transform .15s ease; text-decoration: none;
        }
        .sidebar .nav-link:hover,
        .sidebar .nav-link.active { background: #ece8dd; color: #1e4d6b; transform: translateX(4px); }
        .sidebar .nav-link i { font-size: 1.1rem; }
        .sidebar .sidebar-section { padding: 1rem .5rem; margin-top: 1.5rem; border-top: 1px solid rgba(255,255,255,.12); }

        /* ── layout ── */
        .main-content { margin-left: 260px; min-height: 100vh; }
        .top-navbar {
            background: white; border-bottom: 1px solid #e9ecef;
            padding: 1rem 2rem; display: flex; justify-content: space-between;
            align-items: center; position: sticky; top: 0; z-index: 1100;
        }
        .content-wrapper { padding: 1.5rem 2rem 3rem; }

        /* ── panel ── */
        .panel { background: white; border-radius: 18px; box-shadow: 0 6px 24px rgba(15,23,42,.06); margin-bottom: 1.5rem; overflow: hidden; }
        .panel-header {
            padding: 1.1rem 1.5rem; border-bottom: 1px solid #e9ecef;
            display: flex; align-items: center; justify-content: space-between;
            font-weight: 700; color: var(--primary); font-size: 1rem;
        }
        .panel-header .left { display: flex; align-items: center; gap: .6rem; }
        .panel-body { padding: 1.25rem 1.5rem; }

        /* ── club grid ── */
        .club-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(240px, 1fr)); gap: 1rem; }
        .club-card {
            border: 1.5px solid #e5e7eb; border-radius: 14px; padding: 1.1rem 1.25rem;
            background: white; cursor: pointer;
            transition: border-color .2s, box-shadow .2s, transform .15s;
            text-decoration: none; color: inherit; display: block;
        }
        .club-card:hover { border-color: var(--primary); box-shadow: 0 4px 16px rgba(30,77,107,.1); transform: translateY(-2px); color: inherit; }
        .club-card .club-id   { font-size: .78rem; color: #9ca3af; margin-bottom: .2rem; }
        .club-card .club-name { font-weight: 700; font-size: 1rem; color: #1f2937; margin-bottom: .75rem; line-height: 1.3; }
        .club-card .club-stats { display: flex; gap: .75rem; }
        .club-stat { font-size: .8rem; }
        .club-stat .num { font-weight: 700; font-size: 1.1rem; }
        .club-stat.officers .num { color: var(--primary); }
        .club-stat.members  .num { color: #6b7280; }

        /* ── member table ── */
        .mem-table { width: 100%; border-collapse: collapse; font-size: .9rem; }
        .mem-table th { background: #f3f4f6; color: #374151; font-weight: 600; padding: .65rem 1rem; text-align: left; white-space: nowrap; }
        .mem-table td { padding: .6rem 1rem; border-bottom: 1px solid #f0f0f0; vertical-align: middle; }
        .mem-table tbody tr:last-child td { border-bottom: none; }
        .mem-table tbody tr:hover td { background: #fafafa; }

        /* ── role badges ── */
        .role-badge { display: inline-flex; align-items: center; gap: .3rem; padding: .25rem .7rem; border-radius: 999px; font-size: .78rem; font-weight: 600; white-space: nowrap; }
        .role-president  { background: #fef3c7; color: #92400e; border: 1px solid #fde68a; }
        .role-vp         { background: #e0e7ff; color: #3730a3; border: 1px solid #c7d2fe; }
        .role-officer    { background: #d1fae5; color: #065f46; border: 1px solid #a7f3d0; }
        .role-member     { background: #f3f4f6; color: #6b7280; border: 1px solid #e5e7eb; }

        /* ── inline edit ── */
        .edit-row td { background: #f0f7ff !important; }
        .edit-form-inline { display: flex; gap: .5rem; align-items: center; flex-wrap: wrap; }
        .form-select-xs, .form-control-xs {
            font-size: .82rem; padding: .25rem .5rem;
            border: 1px solid #d1d5db; border-radius: 6px;
            background: white;
        }
        .form-select-xs { cursor: pointer; }
        .btn-xs { padding: .25rem .6rem; font-size: .8rem; border-radius: 6px; border: none; cursor: pointer; font-weight: 600; }
        .btn-save   { background: var(--primary); color: white; }
        .btn-cancel { background: #e5e7eb; color: #374151; }
        .btn-remove { background: #fee2e2; color: #991b1b; }
        .btn-edit   { background: #e0f2fe; color: #0369a1; }
        .btn-xs:hover { opacity: .85; }

        /* ── add member ── */
        .add-section { background: #f8fafc; border-radius: 12px; padding: 1.25rem; border: 1px solid #e9ecef; }
        .add-section h6 { font-weight: 700; color: var(--primary); margin-bottom: 1rem; display: flex; align-items: center; gap: .4rem; }

        /* ── user avatar ── */
        .user-avatar-sm {
            width: 32px; height: 32px; border-radius: 50%;
            background: var(--primary); color: white;
            display: inline-flex; align-items: center; justify-content: center;
            font-weight: 700; font-size: .85rem; flex-shrink: 0;
        }

        .search-wrap { max-width: 360px; }
        .back-link { color: var(--primary); text-decoration: none; font-weight: 600; font-size: .9rem; }
        .back-link:hover { text-decoration: underline; }

        /* ── nomination review ── */
        .nom-card {
            border: 1.5px solid #fde68a; border-radius: 12px; background: #fffbeb;
            padding: 1rem 1.25rem; margin-bottom: .75rem;
        }
        .nom-card:last-child { margin-bottom: 0; }
        .nom-card .nom-meta { font-size: .82rem; color: #92400e; margin-bottom: .6rem; }
        .nom-card .nom-title { font-weight: 700; font-size: 1rem; color: #1f2937; }
        .reject-form { display: none; margin-top: .75rem; padding-top: .75rem; border-top: 1px solid #fde68a; }
        .reject-form.show { display: block; }
        .nom-badge { display: inline-flex; align-items: center; gap: .3rem; padding: .2rem .65rem; border-radius: 999px; font-size: .78rem; font-weight: 600; background: #fef3c7; color: #92400e; border: 1px solid #fde68a; }

        /* ── 待審核提名小角標（社團卡片） ── */
        .pending-dot {
            display: inline-flex; align-items: center; gap: .25rem;
            background: #f59e0b; color: white;
            border-radius: 999px; padding: .15rem .55rem;
            font-size: .72rem; font-weight: 700; margin-left: auto;
        }

        /* ── 編輯提名 inline 表單（藍色調） ── */
        .edit-nom-form {
            display: none; margin-top: .75rem; padding-top: .75rem;
            border-top: 1px solid #bfdbfe;
        }
        .edit-nom-form.show { display: block; }
    </style>
</head>
<body>
<?php include(__DIR__ . '/../includes/admin_sidebar.php'); ?>

<main class="main-content">
    <header class="top-navbar">
        <div>
            <ol class="breadcrumb mb-0" style="font-size:.85rem;">
                <li class="breadcrumb-item"><a href="dashboard.php">首頁</a></li>
                <?php if ($club_info): ?>
                    <li class="breadcrumb-item"><a href="user_mgmt.php">身分權限管理</a></li>
                    <li class="breadcrumb-item active"><?= htmlspecialchars($club_info['club_name']) ?></li>
                <?php else: ?>
                    <li class="breadcrumb-item active">身分權限管理</li>
                <?php endif; ?>
            </ol>
            <h4 class="mt-1 mb-0">
                <?= $club_info ? htmlspecialchars($club_info['club_name']) . ' 幹部管理' : '身分權限管理' ?>
            </h4>
        </div>
        <div class="d-flex align-items-center gap-2">
            <div class="user-avatar-sm" style="width:38px;height:38px;font-size:1rem;cursor:pointer;" onclick="location.href='profile.php'">
                <?= htmlspecialchars(mb_substr($user_name, 0, 1)) ?>
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

        <?php if (!$club_info): ?>
        <!-- ════════════════════════════════════════════════
             社團列表頁
        ════════════════════════════════════════════════ -->
        <div class="panel">
            <div class="panel-header">
                <span class="left"><i class="bi bi-people-fill"></i> 社團列表</span>
                <form method="GET" class="d-flex gap-2 align-items-center">
                    <div class="input-group" style="width:260px;">
                        <span class="input-group-text"><i class="bi bi-search"></i></span>
                        <input type="text" name="search" class="form-control form-control-sm"
                               placeholder="搜尋社團名稱或代號…"
                               value="<?= htmlspecialchars($search_club) ?>">
                    </div>
                    <button type="submit" class="btn btn-primary btn-sm">搜尋</button>
                    <?php if ($search_club): ?>
                        <a href="user_mgmt.php" class="btn btn-outline-secondary btn-sm">清除</a>
                    <?php endif; ?>
                </form>
            </div>
            <div class="panel-body">
                <p class="text-muted mb-3" style="font-size:.85rem;">共 <?= count($all_clubs) ?> 個社團，點擊社團進入幹部名單管理。</p>
                <div class="club-grid">
                    <?php foreach ($all_clubs as $c): ?>
                    <a href="user_mgmt.php?club_id=<?= urlencode($c['club_id']) ?>" class="club-card">
                        <div class="d-flex align-items-start">
                            <div style="flex:1;min-width:0;">
                                <div class="club-id"><?= htmlspecialchars($c['club_id']) ?></div>
                                <div class="club-name"><?= htmlspecialchars($c['club_name']) ?></div>
                            </div>
                            <?php if ($c['pending_nominations'] > 0): ?>
                            <span class="pending-dot flex-shrink-0 ms-1">
                                <i class="bi bi-hourglass-split" style="font-size:.7rem;"></i>
                                <?= intval($c['pending_nominations']) ?> 待審
                            </span>
                            <?php endif; ?>
                        </div>
                        <div class="club-stats">
                            <div class="club-stat officers">
                                <div class="num"><?= intval($c['officer_count']) ?></div>
                                <div style="color:#6b7280;font-size:.75rem;">幹部</div>
                            </div>
                            <div class="club-stat members">
                                <div class="num"><?= intval($c['member_count']) ?></div>
                                <div style="color:#6b7280;font-size:.75rem;">總成員</div>
                            </div>
                        </div>
                    </a>
                    <?php endforeach; ?>
                    <?php if (empty($all_clubs)): ?>
                        <p class="text-muted">查無符合的社團。</p>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <?php else: ?>
        <!-- ════════════════════════════════════════════════
             社團幹部管理頁
        ════════════════════════════════════════════════ -->

        <!-- 返回 + 社團資訊 -->
        <div class="d-flex align-items-center gap-3 mb-3">
            <a href="user_mgmt.php" class="back-link"><i class="bi bi-arrow-left"></i> 返回社團列表</a>
            <span style="color:#d1d5db;">|</span>
            <span style="font-size:.85rem;color:#6b7280;"><?= htmlspecialchars($club_info['club_id']) ?></span>
        </div>

        <!-- ── 待審核提名 ─────────────────────────────────────── -->
        <?php if (!empty($nominations)): ?>
        <div class="panel" style="border: 2px solid #fde68a;">
            <div class="panel-header" style="background:#fffbeb;">
                <span class="left" style="color:#92400e;">
                    <i class="bi bi-hourglass-split"></i>
                    待審核幹部提名
                    <span style="background:#f59e0b;color:white;border-radius:999px;padding:.1rem .55rem;font-size:.78rem;margin-left:.4rem;"><?= count($nominations) ?></span>
                </span>
                <small style="color:#a16207;font-weight:400;">由社長提交，審核後生效為正式幹部</small>
            </div>
            <div class="panel-body">
                <?php foreach ($nominations as $n): ?>
                <div class="nom-card">
                    <div class="d-flex align-items-start justify-content-between flex-wrap gap-2">
                        <div>
                            <div class="nom-meta">
                                由 <strong><?= htmlspecialchars($n['nominator_name']) ?></strong>（社長）提名
                                &nbsp;·&nbsp;
                                <?= date('Y/m/d H:i', strtotime($n['created_at'])) ?>
                            </div>
                            <div class="nom-title">
                                <span class="nom-badge"><i class="bi bi-person"></i> <?= htmlspecialchars($n['nominated_name']) ?></span>
                                <span style="color:#6b7280;margin:0 .4rem;">→</span>
                                <span style="font-weight:700;color:var(--primary);"><?= htmlspecialchars($n['officer_title']) ?></span>
                                <span style="color:#9ca3af;font-size:.82rem;margin-left:.4rem;">學號 <?= htmlspecialchars($n['nominated_sid']) ?></span>
                            </div>
                        </div>
                        <div class="d-flex gap-2 align-items-center flex-wrap">
                            <!-- 核准 -->
                            <form method="POST" onsubmit="return confirm('確定核准此提名？')">
                                <input type="hidden" name="action" value="approve_nomination">
                                <input type="hidden" name="nomination_id" value="<?= $n['nomination_id'] ?>">
                                <input type="hidden" name="back_club" value="<?= htmlspecialchars($club_id) ?>">
                                <button type="submit" class="btn btn-success btn-sm">
                                    <i class="bi bi-check-circle"></i> 核准
                                </button>
                            </form>
                            <!-- 編輯職稱 -->
                            <button type="button" class="btn btn-outline-primary btn-sm"
                                    onclick="toggleNomEdit(<?= $n['nomination_id'] ?>)">
                                <i class="bi bi-pencil"></i> 編輯
                            </button>
                            <!-- 駁回（展開填原因） -->
                            <button type="button" class="btn btn-outline-danger btn-sm"
                                    onclick="document.getElementById('reject-<?= $n['nomination_id'] ?>').classList.toggle('show');document.getElementById('edit-<?= $n['nomination_id'] ?>').classList.remove('show')">
                                <i class="bi bi-x-circle"></i> 駁回
                            </button>
                            <!-- 刪除提名 -->
                            <form method="POST" style="display:inline;"
                                  onsubmit="return confirm('確定刪除此提名紀錄？刪除後社長不會看到駁回原因。')">
                                <input type="hidden" name="action" value="delete_nomination">
                                <input type="hidden" name="nomination_id" value="<?= $n['nomination_id'] ?>">
                                <input type="hidden" name="back_club" value="<?= htmlspecialchars($club_id) ?>">
                                <button type="submit" class="btn btn-outline-secondary btn-sm" title="刪除此提名">
                                    <i class="bi bi-trash"></i>
                                </button>
                            </form>
                        </div>
                    </div>
                    <!-- 編輯職稱表單 -->
                    <div id="edit-<?= $n['nomination_id'] ?>" class="edit-nom-form">
                        <form method="POST">
                            <input type="hidden" name="action" value="edit_nomination">
                            <input type="hidden" name="nomination_id" value="<?= $n['nomination_id'] ?>">
                            <input type="hidden" name="back_club" value="<?= htmlspecialchars($club_id) ?>">
                            <div class="d-flex gap-2 align-items-center flex-wrap">
                                <label class="fw-semibold" style="font-size:.85rem;white-space:nowrap;">幹部職稱</label>
                                <input type="text" name="officer_title" class="form-control form-control-sm"
                                       value="<?= htmlspecialchars($n['officer_title']) ?>"
                                       placeholder="例：器材幹部、活動幹部…" style="max-width:240px;" required maxlength="50">
                                <button type="submit" class="btn btn-primary btn-sm">儲存</button>
                                <button type="button" class="btn btn-secondary btn-sm"
                                        onclick="document.getElementById('edit-<?= $n['nomination_id'] ?>').classList.remove('show')">
                                    取消
                                </button>
                            </div>
                        </form>
                    </div>
                    <!-- 駁回表單 -->
                    <div id="reject-<?= $n['nomination_id'] ?>" class="reject-form">
                        <form method="POST">
                            <input type="hidden" name="action" value="reject_nomination">
                            <input type="hidden" name="nomination_id" value="<?= $n['nomination_id'] ?>">
                            <input type="hidden" name="back_club" value="<?= htmlspecialchars($club_id) ?>">
                            <div class="d-flex gap-2 align-items-center">
                                <input type="text" name="review_note" class="form-control form-control-sm"
                                       placeholder="駁回原因（選填）" style="max-width:320px;">
                                <button type="submit" class="btn btn-danger btn-sm">確認駁回</button>
                                <button type="button" class="btn btn-secondary btn-sm"
                                        onclick="document.getElementById('reject-<?= $n['nomination_id'] ?>').classList.remove('show')">
                                    取消
                                </button>
                            </div>
                        </form>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
        <?php endif; ?>

        <!-- 成員名單表格 -->
        <div class="panel">
            <div class="panel-header">
                <span class="left"><i class="bi bi-person-badge"></i> 成員名單（共 <?= count($members) ?> 人）</span>
            </div>
            <div style="overflow-x:auto;">
                <?php if (empty($members)): ?>
                    <div class="panel-body text-muted">此社團目前沒有任何成員。</div>
                <?php else: ?>
                <table class="mem-table">
                    <thead>
                        <tr>
                            <th>成員</th>
                            <th>學號</th>
                            <th>身分</th>
                            <th>職稱</th>
                            <th>確認日期</th>
                            <th style="text-align:center;">操作</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($members as $m): ?>
                        <?php
                        $role_label = get_role_label($m);
                        if ($role_label === '社長')      { $badge_class='role-president'; $icon='bi-star-fill'; }
                        elseif ($role_label === '副社長') { $badge_class='role-vp';        $icon='bi-star-half'; }
                        elseif ($role_label === '幹部')   { $badge_class='role-officer';   $icon='bi-shield-check'; }
                        else                             { $badge_class='role-member';    $icon='bi-person'; }
                        ?>
                        <tr>
                            <td>
                                <div class="d-flex align-items-center gap-2">
                                    <div class="user-avatar-sm"><?= htmlspecialchars(mb_substr($m['user_name'], 0, 1)) ?></div>
                                    <div>
                                        <div style="font-weight:600;"><?= htmlspecialchars($m['user_name']) ?></div>
                                        <div style="font-size:.78rem;color:#9ca3af;"><?= htmlspecialchars($m['email']) ?></div>
                                    </div>
                                </div>
                            </td>
                            <td style="color:#6b7280;"><?= htmlspecialchars($m['student_id']) ?></td>
                            <td>
                                <span class="role-badge <?= $badge_class ?>">
                                    <i class="bi <?= $icon ?>"></i> <?= $role_label ?>
                                </span>
                            </td>
                            <td><?= htmlspecialchars($m['officer_title'] ?: '—') ?></td>
                            <td style="color:#6b7280;font-size:.85rem;"><?= htmlspecialchars($m['officer_confirmation_date'] ?? '—') ?></td>
                            <td style="text-align:center;white-space:nowrap;">
                                <button class="btn btn-sm btn-outline-primary me-1"
                                        data-mid="<?= $m['membership_id'] ?>"
                                        data-name="<?= htmlspecialchars($m['user_name']) ?>"
                                        data-role="<?= htmlspecialchars($role_label) ?>"
                                        data-title="<?= htmlspecialchars($m['officer_title'] ?? '') ?>"
                                        data-date="<?= htmlspecialchars($m['officer_confirmation_date'] ?? '') ?>"
                                        onclick="openEditModal(this.dataset.mid,this.dataset.name,this.dataset.role,this.dataset.title,this.dataset.date)">
                                    <i class="bi bi-pencil"></i> 編輯
                                </button>
                                <form method="POST" style="display:inline;"
                                      onsubmit="return confirm('確定移除 <?= addslashes(htmlspecialchars($m['user_name'])) ?> 的成員資格？')">
                                    <input type="hidden" name="action" value="remove_membership">
                                    <input type="hidden" name="membership_id" value="<?= $m['membership_id'] ?>">
                                    <input type="hidden" name="back_club" value="<?= htmlspecialchars($club_id) ?>">
                                    <button type="submit" class="btn btn-sm btn-outline-danger">
                                        <i class="bi bi-trash"></i>
                                    </button>
                                </form>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
                <?php endif; ?>
            </div>
        </div>

        <!-- 編輯身分 Modal -->
        <div class="modal fade" id="editModal" tabindex="-1" aria-hidden="true">
            <div class="modal-dialog modal-dialog-centered" style="max-width:440px;">
                <div class="modal-content" style="border-radius:16px;overflow:hidden;">
                    <div class="modal-header" style="background:var(--primary);color:white;border:none;">
                        <h5 class="modal-title mb-0"><i class="bi bi-pencil-square me-2"></i>編輯成員身分</h5>
                        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                    </div>
                    <form method="POST">
                        <input type="hidden" name="action" value="update_membership">
                        <input type="hidden" name="back_club" value="<?= htmlspecialchars($club_id) ?>">
                        <input type="hidden" name="membership_id" id="modal_membership_id">

                        <div class="modal-body p-4">
                            <!-- 成員資訊 -->
                            <div class="d-flex align-items-center gap-3 mb-4 p-3"
                                 style="background:#f8fafc;border-radius:10px;border:1px solid #e9ecef;">
                                <div class="user-avatar-sm" style="width:44px;height:44px;font-size:1.1rem;" id="modal_avatar"></div>
                                <div>
                                    <div style="font-weight:700;font-size:1rem;" id="modal_name"></div>
                                    <div style="font-size:.82rem;color:#9ca3af;">正在編輯此成員的身分設定</div>
                                </div>
                            </div>

                            <!-- 身分選擇 -->
                            <div class="mb-3">
                                <label class="form-label fw-semibold">身分</label>
                                <select name="role" id="modal_role" class="form-select" onchange="modalRoleChange()">
                                    <option value="一般成員">一般成員</option>
                                    <option value="幹部">幹部</option>
                                    <option value="副社長">副社長</option>
                                    <option value="社長">社長</option>
                                </select>
                            </div>

                            <!-- 職稱（幹部才顯示） -->
                            <div class="mb-3" id="modal_title_wrap">
                                <label class="form-label fw-semibold">幹部職稱 <span class="text-danger">*</span></label>
                                <input type="text" name="officer_title" id="modal_officer_title"
                                       class="form-control" placeholder="例：器材幹部、活動幹部…" maxlength="50">
                                <div class="form-text">選擇「幹部」時請填寫具體職稱；其他身分自動帶入。</div>
                            </div>

                            <!-- 確認日期 -->
                            <div class="mb-1">
                                <label class="form-label fw-semibold">確認日期</label>
                                <input type="date" name="officer_confirmation_date" id="modal_confirm_date"
                                       class="form-control">
                            </div>
                        </div>

                        <div class="modal-footer" style="border:none;padding:1rem 1.5rem 1.5rem;">
                            <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">取消</button>
                            <button type="submit" class="btn btn-primary px-4">
                                <i class="bi bi-check-lg me-1"></i>儲存變更
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </div>

        <!-- 新增成員 -->
        <div class="panel">
            <div class="panel-header">
                <span class="left"><i class="bi bi-person-plus"></i> 新增成員</span>
            </div>
            <div class="panel-body">
                <?php if (empty($all_users)): ?>
                    <p class="text-muted mb-0" style="font-size:.9rem;">目前所有學生已全數加入此社團。</p>
                <?php else: ?>
                <form method="POST">
                    <input type="hidden" name="action" value="add_membership">
                    <input type="hidden" name="club_id_add" value="<?= htmlspecialchars($club_id) ?>">
                    <input type="hidden" name="back_club" value="<?= htmlspecialchars($club_id) ?>">
                    <div class="row g-3 align-items-end">
                        <div class="col-md-4">
                            <label class="form-label fw-semibold" style="font-size:.85rem;">選擇學生</label>
                            <select name="target_user_id" class="form-select form-select-sm" required>
                                <option value="">— 選擇學生 —</option>
                                <?php foreach ($all_users as $u): ?>
                                <option value="<?= $u['user_id'] ?>">
                                    <?= htmlspecialchars($u['name']) ?>（<?= htmlspecialchars($u['student_id']) ?>）
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-2">
                            <label class="form-label fw-semibold" style="font-size:.85rem;">身分</label>
                            <select name="role" class="form-select form-select-sm" onchange="handleRoleChange(this)">
                                <option value="一般成員">一般成員</option>
                                <option value="幹部">幹部</option>
                                <option value="副社長">副社長</option>
                                <option value="社長">社長</option>
                            </select>
                        </div>
                        <div class="col-md-3">
                            <label class="form-label fw-semibold" style="font-size:.85rem;">職稱</label>
                            <input type="text" name="officer_title" class="form-control form-control-sm"
                                   placeholder="例：器材幹部、活動幹部…"
                                   readonly style="background:#f3f4f6;color:#6b7280;">
                        </div>
                        <div class="col-md-2">
                            <label class="form-label fw-semibold" style="font-size:.85rem;">加入日期</label>
                            <input type="date" name="join_date" class="form-control form-control-sm"
                                   value="<?= date('Y-m-d') ?>">
                        </div>
                        <div class="col-md-1">
                            <button type="submit" class="btn btn-primary btn-sm w-100">加入</button>
                        </div>
                    </div>
                </form>
                <?php endif; ?>
            </div>
        </div>

        <?php endif; ?>
    </section>
</main>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
function toggleNomEdit(nomId) {
    const editEl   = document.getElementById('edit-'   + nomId);
    const rejectEl = document.getElementById('reject-' + nomId);
    rejectEl.classList.remove('show');
    editEl.classList.toggle('show');
}

function openEditModal(membershipId, name, role, title, confirmDate) {
    document.getElementById('modal_membership_id').value  = membershipId;
    document.getElementById('modal_name').textContent     = name;
    document.getElementById('modal_avatar').textContent   = name.charAt(0);
    document.getElementById('modal_role').value           = role;
    document.getElementById('modal_confirm_date').value   = confirmDate || '';

    modalRoleChange(title);

    bootstrap.Modal.getOrCreateInstance(document.getElementById('editModal')).show();
}

// 新增成員表單：角色切換時自動帶入職稱
function handleRoleChange(sel) {
    const titleInput = sel.closest('form').querySelector('[name="officer_title"]');
    const role = sel.value;
    const AUTO = { '社長': '社長', '副社長': '副社長' };

    if (role === '幹部') {
        titleInput.readOnly = false;
        titleInput.style.background = '';
        titleInput.style.color = '';
        titleInput.value = '';
        titleInput.placeholder = '例：器材幹部、活動幹部…';
    } else if (AUTO[role]) {
        titleInput.readOnly = true;
        titleInput.style.background = '#f3f4f6';
        titleInput.style.color = '#6b7280';
        titleInput.value = AUTO[role];
    } else {
        // 一般成員
        titleInput.readOnly = true;
        titleInput.style.background = '#f3f4f6';
        titleInput.style.color = '#6b7280';
        titleInput.value = '';
    }
}

// 編輯成員 Modal：角色切換時自動帶入職稱
function modalRoleChange(forceTitle) {
    const role       = document.getElementById('modal_role').value;
    const titleWrap  = document.getElementById('modal_title_wrap');
    const titleInput = document.getElementById('modal_officer_title');
    const AUTO = { '社長': '社長', '副社長': '副社長' };

    if (role === '幹部') {
        titleWrap.style.display = '';
        titleInput.readOnly = false;
        titleInput.style.background = '';
        titleInput.style.color = '';
        titleInput.value = (forceTitle !== undefined) ? forceTitle : '';
    } else if (AUTO[role]) {
        titleWrap.style.display = '';
        titleInput.readOnly = true;
        titleInput.style.background = '#f3f4f6';
        titleInput.style.color = '#6b7280';
        titleInput.value = AUTO[role];
    } else {
        // 一般成員：隱藏職稱欄
        titleWrap.style.display = 'none';
        titleInput.value = '';
        titleInput.readOnly = true;
    }
}
</script>
</body>
</html>
