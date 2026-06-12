<?php
// Required variables before including:
//   $nav_breadcrumbs : array of ['label'=>'...', 'url'=>'...'] — last item shown as active (no url needed)
//   $nav_title       : string — page title shown below breadcrumb
// Optional:
//   $nav_extra       : string — extra HTML inserted before the avatar (e.g. academic-year badge)
$_nav_user = $user_name ?? ($_SESSION['user_name'] ?? '管理員');
?>
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
    <div class="d-flex align-items-center gap-2">
        <?= $nav_extra ?? '' ?>
        <div class="user-avatar" onclick="location.href='profile.php'" style="cursor:pointer;">
            <?= htmlspecialchars(mb_substr($_nav_user, 0, 1)) ?>
        </div>
        <small class="text-muted"><?= htmlspecialchars($_nav_user) ?></small>
    </div>
</header>
