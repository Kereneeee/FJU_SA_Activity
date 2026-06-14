<?php

require_once(__DIR__ . "/../DB/db_config.php");

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'student') {
    header('Location: ../login.php');
    exit();
}

$current_page = 'dashboard';
$notice_id = intval($_GET['id'] ?? 0);
$notice = null;

if ($notice_id > 0) {
    $stmt = $conn->prepare("SELECT id, title, content, type, created_at FROM notifications WHERE id = ?");
    if ($stmt) {
        $stmt->bind_param("i", $notice_id);
        $stmt->execute();
        $notice = $stmt->get_result()->fetch_assoc();
        $stmt->close();
    }
}

function noticeBadge($type) {
    if ($type === 'update' || $type === '更新') {
        return ['label' => '更新', 'class' => 'badge-update'];
    }
    if ($type === 'alert' || $type === '提醒' || $type === '緊急') {
        return ['label' => '提醒', 'class' => 'badge-alert'];
    }
    return ['label' => '新消息', 'class' => 'badge-new'];
}

$badge = $notice ? noticeBadge($notice['type']) : null;
?>
<!DOCTYPE html>
<html lang="zh-TW">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>公告詳細資料 - 輔仁大學課外活動指導組</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.5/font/bootstrap-icons.css" rel="stylesheet">
    <style>
        :root { --primary:#1e4d6b; --bg:#f7f5ef; }
        * { box-sizing:border-box; }
        body { margin:0; min-height:100vh; font-family:'Segoe UI',sans-serif; background:var(--bg); color:#1f2937; }
        .sidebar { position:fixed; top:0; left:0; width:260px; height:100vh; background:var(--primary); color:white; padding:1.5rem .8rem; overflow-y:hidden; box-shadow:3px 0 15px rgba(0,0,0,.12); z-index:1200; }
        .sidebar .brand { text-align:center; margin-bottom:1.5rem; }
        .sidebar .brand h4 { margin:0; font-size:1.1rem; line-height:1.4; font-weight:700; }
        .sidebar .nav-link { display:flex; align-items:center; gap:.75rem; color:rgba(255,255,255,.9); padding:.85rem 1rem; margin:.2rem 0; border-radius:16px; transition:background .25s,transform .15s; text-decoration:none; }
        .sidebar .nav-link:hover,.sidebar .nav-link.active { background:#ece8dd; color:#1e4d6b; transform:translateX(4px); }
        .sidebar .nav-link i { font-size:1.1rem; }
        .sidebar .sidebar-section { padding:1rem .5rem; margin-top:1.5rem; border-top:1px solid rgba(255,255,255,.12); }
        .main-content { margin-left:260px; min-height:100vh; }
        .top-navbar { background:#d5e3ea; border-bottom:1px solid #bdd0d9; padding:1rem 2rem; display:flex; justify-content:space-between; align-items:center; position:sticky; top:0; z-index:1100; }
        .content-wrapper { padding:1.5rem 2rem; }
        .notice-card { background:#fff; border-radius:14px; box-shadow:0 10px 30px rgba(15,23,42,.06); padding:1.5rem; max-width:920px; }
        .notice-title { color:#1e4d6b; font-weight:800; margin-bottom:.75rem; }
        .notice-meta { display:flex; gap:.75rem; align-items:center; flex-wrap:wrap; color:#6b7280; font-size:.88rem; margin-bottom:1.25rem; }
        .notice-body { white-space:pre-line; line-height:1.85; font-size:1rem; color:#263238; }
        .badge-notice { border-radius:999px; padding:.25rem .7rem; font-size:.78rem; font-weight:700; color:#fff; }
        .badge-new { background:#0d6efd; }
        .badge-update { background:#198754; }
        .badge-alert { background:#dc3545; }
        .btn-back { display:inline-flex; align-items:center; gap:.35rem; color:#1e4d6b; text-decoration:none; font-weight:700; margin-bottom:1rem; }
        .btn-back:hover { text-decoration:underline; }
        @media (max-width:768px) {
            .sidebar { position:relative; width:100%; height:auto; box-shadow:none; }
            .main-content { margin-left:0; }
            .top-navbar { padding:1rem; }
            .content-wrapper { padding:1rem; }
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
                <li class="breadcrumb-item active" aria-current="page">公告詳細資料</li>
            </ol>
            <h4 class="mt-2 mb-0">最新公告</h4>
        </div>
    </header>

    <section class="content-wrapper">
        <a href="dashboard.php" class="btn-back"><i class="bi bi-arrow-left"></i> 回儀表板</a>

        <?php if (!$notice): ?>
            <div class="notice-card text-center text-muted py-5">
                <i class="bi bi-exclamation-circle display-6 d-block mb-2"></i>
                找不到這則公告。
            </div>
        <?php else: ?>
            <article class="notice-card">
                <h2 class="notice-title"><?= htmlspecialchars($notice['title'], ENT_QUOTES, 'UTF-8') ?></h2>
                <div class="notice-meta">
                    <span class="badge-notice <?= htmlspecialchars($badge['class'], ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars($badge['label'], ENT_QUOTES, 'UTF-8') ?></span>
                    <span><i class="bi bi-clock me-1"></i><?= date('Y/m/d H:i', strtotime($notice['created_at'])) ?></span>
                </div>
                <div class="notice-body"><?= htmlspecialchars($notice['content'], ENT_QUOTES, 'UTF-8') ?></div>
            </article>
        <?php endif; ?>
    </section>
</main>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
