<?php
// Required variables before including:
//   $nav_breadcrumbs : array of ['label'=>'...', 'url'=>'...'] — last item shown as active
//   $nav_title       : string — page title shown below breadcrumb
$_nav_name = $student_name ?? ($_SESSION['student_name'] ?? '學生');
$_nav_no   = $_SESSION['student_no'] ?? ($_SESSION['student_id'] ?? '');
?>
<style>
    .top-navbar .user-card { display: flex; align-items: center; gap: 0.85rem; cursor: pointer; }
    .top-navbar .user-card .user-avatar { width: 44px; height: 44px; border-radius: 50%; background: var(--primary, #1e4d6b); color: white; display: grid; place-items: center; font-weight: 700; font-size: 1.1rem; flex-shrink: 0; }
</style>
<header class="top-navbar">
    <div>
        <ol class="breadcrumb mb-0">
            <?php foreach ($nav_breadcrumbs as $i => $crumb): ?>
                <?php if ($i === count($nav_breadcrumbs) - 1): ?>
                    <li class="breadcrumb-item active" aria-current="page"><?= htmlspecialchars($crumb['label']) ?></li>
                <?php else: ?>
                    <li class="breadcrumb-item"><a href="<?= htmlspecialchars($crumb['url']) ?>"><?= htmlspecialchars($crumb['label']) ?></a></li>
                <?php endif; ?>
            <?php endforeach; ?>
        </ol>
        <h4 class="mt-2 mb-0"><?= htmlspecialchars($nav_title) ?></h4>
    </div>
    <div class="user-card" onclick="location.href='profile.php'" title="點擊查看個人檔案">
        <div class="user-avatar"><?= htmlspecialchars(mb_substr($_nav_name, 0, 1)) ?></div>
        <div>
            <div class="fw-bold"><?= htmlspecialchars($_nav_name) ?></div>
            <?php if ($_nav_no): ?>
                <small class="text-muted">學號：<?= htmlspecialchars($_nav_no) ?></small>
            <?php endif; ?>
        </div>
    </div>
</header>
