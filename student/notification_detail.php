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

function noticeMeta($type) {
    if ($type === 'update' || $type === '更新') {
        return ['label' => '更新', 'class' => 'type-update', 'icon' => 'bi-arrow-repeat'];
    }
    if ($type === 'alert' || $type === '提醒' || $type === '緊急') {
        return ['label' => '提醒', 'class' => 'type-alert', 'icon' => 'bi-exclamation-triangle-fill'];
    }
    return ['label' => '新消息', 'class' => 'type-new', 'icon' => 'bi-megaphone-fill'];
}

$meta = $notice ? noticeMeta($notice['type']) : null;
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
        :root { --primary:#1e4d6b; --bg:#f7f5ef; --line:#e5e7eb; --muted:#6b7280; }
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
        .top-navbar { background:#d5e3ea; border-bottom:1px solid #bdd0d9; padding:1rem 2rem; position:sticky; top:0; z-index:1100; }
        .content-wrapper { padding:1.5rem 2rem 2.5rem; }
        .notice-shell { max-width:960px; margin:0 auto; }
        .btn-back { display:inline-flex; align-items:center; gap:.4rem; color:#1e4d6b; text-decoration:none; font-weight:700; margin-bottom:1rem; }
        .btn-back:hover { text-decoration:underline; }
        .notice-card { background:#fff; border:1px solid rgba(148,163,184,.28); border-radius:14px; box-shadow:0 18px 44px rgba(15,23,42,.08); overflow:hidden; }
        .notice-header { padding:1.5rem 1.7rem 1.2rem; border-bottom:1px solid var(--line); position:relative; }
        .notice-header::before { content:""; position:absolute; left:0; top:0; bottom:0; width:6px; background:#1e4d6b; }
        .notice-header.type-update::before { background:#198754; }
        .notice-header.type-alert::before { background:#dc3545; }
        .notice-kicker { display:flex; align-items:center; gap:.65rem; flex-wrap:wrap; margin-bottom:.75rem; }
        .notice-type { display:inline-flex; align-items:center; gap:.35rem; border-radius:999px; padding:.28rem .75rem; font-size:.78rem; font-weight:800; color:#fff; }
        .type-new .notice-type { background:#0d6efd; }
        .type-update .notice-type { background:#198754; }
        .type-alert .notice-type { background:#dc3545; }
        .notice-time { color:var(--muted); font-size:.88rem; display:inline-flex; align-items:center; gap:.35rem; }
        .notice-title { color:#14394f; font-size:clamp(1.45rem, 2vw, 2rem); font-weight:850; line-height:1.35; margin:0; letter-spacing:0; }
        .notice-body { padding:1.6rem 1.7rem 1.9rem; white-space:pre-line; line-height:1.9; font-size:1rem; color:#263238; }
        .empty-card { background:#fff; border:1px dashed #cbd5e1; border-radius:14px; padding:3rem 1.5rem; text-align:center; color:var(--muted); }
        @media (max-width:768px) {
            .sidebar { position:relative; width:100%; height:auto; box-shadow:none; }
            .main-content { margin-left:0; }
            .top-navbar { padding:1rem; }
            .content-wrapper { padding:1rem; }
            .notice-header, .notice-body { padding-left:1.2rem; padding-right:1.2rem; }
        }
    </style>
</head>
<body>
<?php include(__DIR__ . "/../includes/sidebar.php"); ?>

<main class="main-content">
    <header class="top-navbar">
        <ol class="breadcrumb mb-0">
            <li class="breadcrumb-item"><a href="dashboard.php">首頁</a></li>
            <li class="breadcrumb-item active" aria-current="page">公告詳細資料</li>
        </ol>
        <h4 class="mt-2 mb-0">最新公告</h4>
    </header>

    <section class="content-wrapper">
        <div class="notice-shell">
            <a href="dashboard.php" class="btn-back"><i class="bi bi-arrow-left"></i> 回儀表板</a>

            <?php if (!$notice): ?>
                <div class="empty-card">
                    <i class="bi bi-exclamation-circle display-6 d-block mb-2"></i>
                    找不到這則公告。
                </div>
            <?php else: ?>
                <article class="notice-card">
                    <div class="notice-header <?= htmlspecialchars($meta['class'], ENT_QUOTES, 'UTF-8') ?>">
                        <div class="notice-kicker">
                            <span class="notice-type"><i class="bi <?= htmlspecialchars($meta['icon'], ENT_QUOTES, 'UTF-8') ?>"></i><?= htmlspecialchars($meta['label'], ENT_QUOTES, 'UTF-8') ?></span>
                            <span class="notice-time"><i class="bi bi-clock"></i><?= date('Y/m/d H:i', strtotime($notice['created_at'])) ?></span>
                        </div>
                        <h1 class="notice-title"><?= htmlspecialchars($notice['title'], ENT_QUOTES, 'UTF-8') ?></h1>
                    </div>
                    <div class="notice-body"><?= htmlspecialchars($notice['content'], ENT_QUOTES, 'UTF-8') ?></div>
                </article>
            <?php endif; ?>
        </div>
    </section>
</main>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
