<?php
session_start();
ini_set('display_errors', 1);
error_reporting(E_ALL);

require_once(__DIR__ . "/../DB/db_config.php");

if (!isset($_SESSION['student_id'])) {
    header('Location: ../login.php');
    exit();
}

$current_page = 'nominate_officers';
$user_id      = $_SESSION['user_id'] ?? null;
$club_id      = $_SESSION['active_club_id'] ?? '';
$club_name    = $_SESSION['active_club_name'] ?? '';

if (!$user_id || !$club_id) {
    header('Location: dashboard.php');
    exit();
}

// 確認目前身分是社長
$chk = $conn->prepare("SELECT membership_id FROM club_members WHERE user_id=? AND club_id=? AND is_officer=1 AND officer_title='社長' LIMIT 1");
$chk->bind_param("is", $user_id, $club_id);
$chk->execute();
if ($chk->get_result()->num_rows === 0) {
    header('Location: dashboard.php');
    exit();
}
$chk->close();

$message = '';
$message_type = '';

// ── 處理提交 ──────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'nominate') {
        $nominated_id  = intval($_POST['nominated_user_id'] ?? 0);
        $officer_title = trim($_POST['officer_title'] ?? '');

        if (!$nominated_id || !$officer_title) {
            $message = '請選擇成員並填寫職稱。';
            $message_type = 'danger';
        } else {
            // 確認被提名者是此社團成員
            $chk2 = $conn->prepare("SELECT membership_id FROM club_members WHERE user_id=? AND club_id=? LIMIT 1");
            $chk2->bind_param("is", $nominated_id, $club_id);
            $chk2->execute();
            if ($chk2->get_result()->num_rows === 0) {
                $message = '被提名者不是本社團成員。';
                $message_type = 'danger';
            } else {
                // 確認沒有重複的 pending 提名
                $dup = $conn->prepare("SELECT nomination_id FROM officer_nominations WHERE club_id=? AND nominated_user_id=? AND status='pending' LIMIT 1");
                $dup->bind_param("si", $club_id, $nominated_id);
                $dup->execute();
                if ($dup->get_result()->num_rows > 0) {
                    $message = '該成員已有待審核的提名，請等待管理者審核後再提名。';
                    $message_type = 'warning';
                } else {
                    $ins = $conn->prepare("INSERT INTO officer_nominations (club_id, nominated_user_id, nominator_user_id, officer_title) VALUES (?,?,?,?)");
                    $ins->bind_param("siis", $club_id, $nominated_id, $user_id, $officer_title);
                    if ($ins->execute()) {
                        $message = '提名已送出，請等待管理者審核。';
                        $message_type = 'success';
                    } else {
                        $message = '送出失敗：' . $ins->error;
                        $message_type = 'danger';
                    }
                    $ins->close();
                }
                $dup->close();
            }
            $chk2->close();
        }
    }

    if ($action === 'cancel') {
        $nomination_id = intval($_POST['nomination_id'] ?? 0);
        $del = $conn->prepare("DELETE FROM officer_nominations WHERE nomination_id=? AND nominator_user_id=? AND status='pending'");
        $del->bind_param("ii", $nomination_id, $user_id);
        $message      = $del->execute() ? '已撤回提名。' : '撤回失敗。';
        $message_type = $del->execute() ? 'success' : 'danger';
        $del->close();
    }
}

// ── 取得社團非幹部成員（可提名） ────────────────────────────────
$eligible = $conn->prepare(
    "SELECT u.user_id, u.name, u.email, u.student_id
     FROM club_members cm
     JOIN users u ON cm.user_id = u.user_id
     WHERE cm.club_id = ? AND cm.user_id != ?
     ORDER BY cm.is_officer DESC, u.name"
);
$eligible->bind_param("si", $club_id, $user_id);
$eligible->execute();
$eligible_members = $eligible->get_result()->fetch_all(MYSQLI_ASSOC);
$eligible->close();

// ── 取得目前待審核提名 ────────────────────────────────────────
$pend = $conn->prepare(
    "SELECT n.nomination_id, n.officer_title, n.created_at, n.status,
            u.name AS nominated_name, u.student_id AS nominated_sid
     FROM officer_nominations n
     JOIN users u ON n.nominated_user_id = u.user_id
     WHERE n.club_id = ? AND n.nominator_user_id = ?
     ORDER BY n.created_at DESC"
);
$pend->bind_param("si", $club_id, $user_id);
$pend->execute();
$my_nominations = $pend->get_result()->fetch_all(MYSQLI_ASSOC);
$pend->close();

$student_name = $_SESSION['student_name'] ?? '社長';
?>
<!DOCTYPE html>
<html lang="zh-TW">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>幹部提名 - 輔仁大學課外活動指導組</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.5/font/bootstrap-icons.css" rel="stylesheet">
    <style>
        :root { --primary: #1e4d6b; --sidebar: #14394f; --bg: #f7f5ef; }
        * { box-sizing: border-box; }
        body { margin: 0; font-family: 'Segoe UI', sans-serif; background: var(--bg); color: #1f2937; }
        .sidebar {
            position: fixed; top: 0; left: 0; width: 260px; height: 100vh;
            background: linear-gradient(180deg, var(--primary), var(--sidebar));
            color: white; padding: 1.5rem 0.8rem; overflow-y: auto;
            box-shadow: 3px 0 15px rgba(0,0,0,.12); z-index: 1200;
        }
        .sidebar .brand { text-align: center; margin-bottom: 1.5rem; }
        .sidebar .brand h4 { margin: 0; font-size: 1.1rem; line-height: 1.4; font-weight: 700; }
        .sidebar .nav-link {
            display: flex; align-items: center; gap: .75rem;
            color: rgba(255,255,255,.9); padding: .85rem 1rem; margin: .2rem 0;
            border-radius: 16px; transition: background .25s, transform .15s; text-decoration: none;
        }
        .sidebar .nav-link:hover,
        .sidebar .nav-link.active { background: rgba(255,255,255,.15); color: #fff; transform: translateX(4px); }
        .sidebar .nav-link i { font-size: 1.1rem; }
        .sidebar .sidebar-section { padding: 1rem .5rem; margin-top: 1.5rem; border-top: 1px solid rgba(255,255,255,.12); }
        .main-content { margin-left: 260px; min-height: 100vh; }
        .top-navbar {
            background: white; border-bottom: 1px solid #e9ecef;
            padding: 1rem 2rem; display: flex; justify-content: space-between; align-items: center;
            position: sticky; top: 0; z-index: 1100;
        }
        .content-wrapper { padding: 1.5rem 2rem 3rem; max-width: 860px; }
        .panel { background: white; border-radius: 18px; box-shadow: 0 6px 24px rgba(15,23,42,.06); margin-bottom: 1.5rem; overflow: hidden; }
        .panel-header { padding: 1.1rem 1.5rem; border-bottom: 1px solid #e9ecef; font-weight: 700; color: var(--primary); display: flex; align-items: center; gap: .5rem; }
        .panel-body { padding: 1.25rem 1.5rem; }
        .nom-row { display: flex; align-items: center; justify-content: space-between; padding: .75rem 0; border-bottom: 1px solid #f0f0f0; }
        .nom-row:last-child { border-bottom: none; }
        .badge-pending  { background: #fff3cd; color: #856404; padding: .2rem .65rem; border-radius: 999px; font-size: .78rem; font-weight: 600; }
        .badge-approved { background: #d1e7dd; color: #0f5132; padding: .2rem .65rem; border-radius: 999px; font-size: .78rem; font-weight: 600; }
        .badge-rejected { background: #f8d7da; color: #842029; padding: .2rem .65rem; border-radius: 999px; font-size: .78rem; font-weight: 600; }
    </style>
</head>
<body>
<?php include(__DIR__ . "/../includes/sidebar.php"); ?>

<main class="main-content">
    <header class="top-navbar">
        <div>
            <ol class="breadcrumb mb-0" style="font-size:.85rem;">
                <li class="breadcrumb-item"><a href="dashboard.php">首頁</a></li>
                <li class="breadcrumb-item active">幹部提名</li>
            </ol>
            <h4 class="mt-1 mb-0">幹部提名 — <?= htmlspecialchars($club_name) ?></h4>
        </div>
    </header>

    <section class="content-wrapper">

        <?php if ($message): ?>
        <div class="alert alert-<?= $message_type ?> alert-dismissible fade show">
            <?= htmlspecialchars($message) ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
        <?php endif; ?>

        <!-- 提名表單 -->
        <div class="panel">
            <div class="panel-header"><i class="bi bi-person-plus"></i> 提名幹部</div>
            <div class="panel-body">
                <p class="text-muted" style="font-size:.9rem;">提名社團成員擔任幹部，提交後由課外活動指導組審核後生效。</p>
                <form method="POST">
                    <input type="hidden" name="action" value="nominate">
                    <div class="row g-3 align-items-end">
                        <div class="col-md-5">
                            <label class="form-label fw-semibold">選擇成員 <span class="text-danger">*</span></label>
                            <select name="nominated_user_id" class="form-select" required>
                                <option value="">— 選擇要提名的成員 —</option>
                                <?php foreach ($eligible_members as $em): ?>
                                <option value="<?= $em['user_id'] ?>">
                                    <?= htmlspecialchars($em['name']) ?>（<?= htmlspecialchars($em['student_id']) ?>）
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label fw-semibold">幹部職稱 <span class="text-danger">*</span></label>
                            <input type="text" name="officer_title" class="form-control"
                                   placeholder="例：器材幹部、活動幹部…" required maxlength="50">
                        </div>
                        <div class="col-md-3">
                            <button type="submit" class="btn btn-primary w-100">
                                <i class="bi bi-send"></i> 送出提名
                            </button>
                        </div>
                    </div>
                </form>
            </div>
        </div>

        <!-- 我的提名紀錄 -->
        <div class="panel">
            <div class="panel-header"><i class="bi bi-clock-history"></i> 我的提名紀錄</div>
            <div class="panel-body">
                <?php if (empty($my_nominations)): ?>
                    <p class="text-muted mb-0">目前尚無提名紀錄。</p>
                <?php else: ?>
                    <?php foreach ($my_nominations as $n): ?>
                    <div class="nom-row">
                        <div>
                            <span style="font-weight:600;"><?= htmlspecialchars($n['nominated_name']) ?></span>
                            <span style="color:#9ca3af;font-size:.82rem;margin-left:.5rem;">學號 <?= htmlspecialchars($n['nominated_sid']) ?></span>
                            <br>
                            <span style="color:#6b7280;font-size:.85rem;">提名職稱：<?= htmlspecialchars($n['officer_title']) ?></span>
                            <span style="color:#d1d5db;margin:0 .4rem;">·</span>
                            <span style="color:#9ca3af;font-size:.82rem;"><?= date('Y/m/d H:i', strtotime($n['created_at'])) ?></span>
                        </div>
                        <div class="d-flex align-items-center gap-2">
                            <?php if ($n['status'] === 'pending'): ?>
                                <span class="badge-pending"><i class="bi bi-hourglass-split"></i> 待審核</span>
                                <form method="POST" onsubmit="return confirm('確定撤回此提名？')">
                                    <input type="hidden" name="action" value="cancel">
                                    <input type="hidden" name="nomination_id" value="<?= $n['nomination_id'] ?>">
                                    <button type="submit" class="btn btn-outline-secondary btn-sm">撤回</button>
                                </form>
                            <?php elseif ($n['status'] === 'approved'): ?>
                                <span class="badge-approved"><i class="bi bi-check-circle"></i> 已核准</span>
                            <?php else: ?>
                                <span class="badge-rejected"><i class="bi bi-x-circle"></i> 已駁回</span>
                            <?php endif; ?>
                        </div>
                    </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>

    </section>
</main>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
