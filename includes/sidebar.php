<?php
// 側邊欄組件 - 可重用於所有頁面
// 參數: $current_page - 當前頁面名稱，用於高亮active鏈接
$current_page = $current_page ?? 'dashboard';
?>

<?php
// 動態計算 logout 的相對根目錄路徑，支援 root、student、admin 等位置
$scriptDir = dirname($_SERVER['SCRIPT_NAME']);
$baseDir = basename($scriptDir);
if ($baseDir === 'student' || $baseDir === 'admin') {
    $scriptDir = dirname($scriptDir);
}
$logoutHref = rtrim($scriptDir, '/\\') . '/logout.php';
if ($logoutHref === '') {
    $logoutHref = '/logout.php';
}
?>

<aside class="sidebar">
    <div class="brand">
        <h4>輔仁大學<br>課外活動指導組</h4>
    </div>
    <nav class="nav flex-column">
        <a class="nav-link <?php echo ($current_page === 'dashboard') ? 'active' : ''; ?>" href="dashboard.php">
            <i class="bi bi-house-door"></i> 儀表板
        </a>
        <a class="nav-link <?php echo ($current_page === 'apply_event') ? 'active' : ''; ?>" href="apply_event.php">
            <i class="bi bi-calendar-plus"></i> 活動申請
        </a>
        <a class="nav-link <?php echo ($current_page === 'calendar') ? 'active' : ''; ?>" href="calendar.php">
            <i class="bi bi-calendar-check"></i> 空間申請
        </a>
        <a class="nav-link <?php echo ($current_page === 'equipment') ? 'active' : ''; ?>" href="equipment.php">
            <i class="bi bi-tools"></i> 器材狀態
        </a>
        <a class="nav-link <?php echo ($current_page === 'field_coord') ? 'active' : ''; ?>" href="field_coord.php">
            <i class="bi bi-people"></i> 場地協調
        </a>
        <a class="nav-link <?php echo ($current_page === 'my_applications') ? 'active' : ''; ?>" href="my_applications.php">
            <i class="bi bi-card-list"></i> 我的申請
        </a>
        <a class="nav-link <?php echo ($current_page === 'edit_application') ? 'active' : ''; ?>" href="edit_application.php">
            <i class="bi bi-pencil-square"></i> 編輯申請
        </a>
    </nav>
    <div class="sidebar-section">
        <p class="mb-2">快捷操作</p>
        <a class="nav-link" href="<?php echo htmlspecialchars($logoutHref, ENT_QUOTES, 'UTF-8'); ?>">
            <i class="bi bi-box-arrow-right"></i> 登出系統
        </a>
    </div>
</aside>