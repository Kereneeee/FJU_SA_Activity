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
// 計算應用程式在網址中的根資料夾（例如 /FJU_SA_Activity）
$appRoot = '/' . basename(dirname(__DIR__));
require_once(__DIR__ . '/student_permissions.php');

$student_can_apply = false;
if (isset($_SESSION['role']) && $_SESSION['role'] === 'student' && !empty($_SESSION['user_id'])) {
    global $conn;
    if (isset($conn) && $conn instanceof mysqli) {
        $membership = student_sync_active_membership_session($conn, (int)$_SESSION['user_id'], $_SESSION['current_club_id'] ?? ($_SESSION['active_club_id'] ?? null));
        $student_can_apply = student_can_apply_with_membership($membership);
    }
}
?>

<style>
    .sidebar .nav-link.locked-link {
        opacity: 0.58;
        cursor: not-allowed;
    }
    .sidebar .nav-link.locked-link:hover {
        transform: none;
    }
</style>

<aside class="sidebar">
    <div class="brand">
        <h4>輔仁大學<br>課外活動指導組</h4>
    </div>
    <nav class="nav flex-column">
        <a class="nav-link <?php echo ($current_page === 'dashboard') ? 'active' : ''; ?>" href="dashboard.php">
            <i class="bi bi-house-door"></i> 儀表板
        </a>
        <?php if ($student_can_apply || (isset($_SESSION['role']) && $_SESSION['role'] === 'admin')): ?>
            <a class="nav-link <?php echo ($current_page === 'apply_event') ? 'active' : ''; ?>" href="apply_event.php">
                <i class="bi bi-calendar-plus"></i> 場地和器材申請
            </a>
        <?php else: ?>
            <a class="nav-link locked-link" href="dashboard.php?locked=1" title="非社團幹部不允許使用">
                <i class="bi bi-lock"></i> 場地和器材申請
            </a>
        <?php endif; ?>
        <a class="nav-link <?php echo ($current_page === 'calendar') ? 'active' : ''; ?>" href="calendar.php">
            <i class="bi bi-calendar-check"></i> 空間日曆
        </a>
        <a class="nav-link <?php echo ($current_page === 'equipment') ? 'active' : ''; ?>" href="equipment.php">
            <i class="bi bi-tools"></i> 器材狀態
        </a>
        <?php if ($student_can_apply || (isset($_SESSION['role']) && $_SESSION['role'] === 'admin')): ?>
            <a class="nav-link <?php echo ($current_page === 'field_coord') ? 'active' : ''; ?>" href="<?= htmlspecialchars($appRoot . '/student/field_coord.php', ENT_QUOTES, 'UTF-8') ?>">
                <i class="bi bi-people"></i> 場地協調
            </a>
            <a class="nav-link <?php echo ($current_page === 'field_coord_records') ? 'active' : ''; ?>" href="<?= htmlspecialchars($appRoot . '/student/field_coordination_records.php', ENT_QUOTES, 'UTF-8') ?>">
                <i class="bi bi-clock-history"></i> 場協登記記錄
            </a>
        <?php else: ?>
            <a class="nav-link locked-link" href="dashboard.php?locked=1" title="非社團幹部不允許使用">
                <i class="bi bi-lock"></i> 場地協調
            </a>
            <a class="nav-link locked-link" href="dashboard.php?locked=1" title="非社團幹部不允許使用">
                <i class="bi bi-lock"></i> 場協登記記錄
            </a>
        <?php endif; ?>
        <?php if (isset($_SESSION['role']) && $_SESSION['role'] === 'admin'): ?>
        <?php $baseHref = rtrim($scriptDir, '/\\'); ?>
        <a class="nav-link <?php echo ($current_page === 'field_coordination_mgmt') ? 'active' : ''; ?>" href="<?= htmlspecialchars($appRoot . '/admin/field_coordination_mgmt.php', ENT_QUOTES, 'UTF-8') ?>">
            <i class="bi bi-people-fill"></i> 場協設定管理
        </a>
        <a class="nav-link <?php echo ($current_page === 'field_coordination_import') ? 'active' : ''; ?>" href="<?= htmlspecialchars($appRoot . '/admin/field_coordination_import.php', ENT_QUOTES, 'UTF-8') ?>">
            <i class="bi bi-cloud-upload"></i> 場協結果匯入
        </a>
        <?php endif; ?>
        <?php if ($student_can_apply || (isset($_SESSION['role']) && $_SESSION['role'] === 'admin')): ?>
            <a class="nav-link <?php echo ($current_page === 'my_applications') ? 'active' : ''; ?>" href="my_applications.php">
                <i class="bi bi-card-list"></i> 我的申請
            </a>
        <?php else: ?>
            <a class="nav-link locked-link" href="dashboard.php?locked=1" title="非社團幹部不允許使用">
                <i class="bi bi-lock"></i> 我的申請
            </a>
        <?php endif; ?>
        <?php
        // 有效期內的社長可以提名該學年度幹部。
        $is_president = false;
        if (!empty($_SESSION['user_id']) && !empty($_SESSION['active_club_id'])) {
            global $conn;
            if (isset($conn) && $conn instanceof mysqli) {
                $president_membership = student_get_active_membership($conn, (int)$_SESSION['user_id'], $_SESSION['active_club_id']);
                $is_president = student_can_nominate_officers($president_membership);
            }
        }
        if ($is_president): ?>
        <a class="nav-link <?php echo ($current_page === 'nominate_officers') ? 'active' : ''; ?>" href="nominate_officers.php">
            <i class="bi bi-person-check"></i> 幹部提名
        </a>
        <?php endif; ?>
    </nav>
    <div class="sidebar-section">
        <p class="mb-2">快捷操作</p>
        <a class="nav-link" href="<?php echo htmlspecialchars($logoutHref, ENT_QUOTES, 'UTF-8'); ?>">
            <i class="bi bi-box-arrow-right"></i> 登出系統
        </a>
    </div>
</aside>
