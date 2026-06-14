<?php
$current_page = $current_page ?? '';

$_badge_pending_events = 0;
$_badge_pending_nominations = 0;
$_badge_pending_field_imports = 0;

if (isset($conn)) {
    $r = $conn->query("SELECT COUNT(*) AS c FROM events WHERE status = 'pending'");
    if ($r) $_badge_pending_events = (int)$r->fetch_assoc()['c'];

    $r = $conn->query("SELECT COUNT(*) AS c FROM officer_nominations WHERE status = 'pending'");
    if ($r) $_badge_pending_nominations = (int)$r->fetch_assoc()['c'];

    $r = $conn->query(
        "SELECT COUNT(DISTINCT fcr.registration_id) AS c
         FROM field_coordination_registrations fcr
         JOIN field_coordination_settings fcs ON fcs.setting_id = fcr.setting_id
         JOIN events e ON e.event_id = fcr.event_id
         LEFT JOIN reservations r1 ON r1.event_id = e.event_id
         WHERE fcs.coordination_meeting_date <= NOW()
           AND (
             fcr.is_approved IS NULL
             OR EXISTS (
               SELECT 1
               FROM field_coordination_registrations fcr2
               JOIN events e2 ON e2.event_id = fcr2.event_id
               JOIN reservations r2 ON r2.event_id = e2.event_id
               WHERE fcr2.setting_id = fcr.setting_id
                 AND fcr2.registration_id != fcr.registration_id
                 AND r1.space_id IS NOT NULL
                 AND r2.space_id = r1.space_id
                 AND r2.start_time < r1.end_time
                 AND r1.start_time < r2.end_time
             )
           )"
    );
    if ($r) $_badge_pending_field_imports = (int)$r->fetch_assoc()['c'];
}
?>
<style>
.sidebar-badge {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    min-width: 20px;
    height: 20px;
    padding: 0 5px;
    border-radius: 999px;
    font-size: 11px;
    font-weight: 700;
    line-height: 1;
    margin-left: auto;
    flex-shrink: 0;
}
.sidebar-badge.red    { background: #ef4444; color: #fff; }
.sidebar-badge.yellow { background: #f59e0b; color: #fff; }
</style>

<div class="sidebar">
    <div class="brand">
        <h4>輔仁大學<br>課外活動指導組</h4>
    </div>
    <nav class="nav flex-column">
        <a class="nav-link <?= $current_page === 'dashboard' ? 'active' : '' ?>" href="dashboard.php">
            <i class="bi bi-speedometer2"></i> 儀表板
        </a>

        <a class="nav-link <?= $current_page === 'review' ? 'active' : '' ?>" href="review.php">
            <i class="bi bi-clipboard-check"></i> 審核管理
            <?php if ($_badge_pending_events > 0): ?>
                <span class="sidebar-badge red"><?= $_badge_pending_events ?></span>
            <?php endif; ?>
        </a>

        <a class="nav-link <?= $current_page === 'equipment_mgmt' ? 'active' : '' ?>" href="equipment_mgmt.php">
            <i class="bi bi-tools"></i> 器材庫存管理
        </a>

        <a class="nav-link <?= $current_page === 'space_mgmt' ? 'active' : '' ?>" href="space_mgmt.php">
            <i class="bi bi-building"></i> 場地管理
        </a>

        <a class="nav-link <?= $current_page === 'field_coordination_mgmt' ? 'active' : '' ?>" href="field_coordination_mgmt.php">
            <i class="bi bi-people-fill"></i> 場協登記管理
        </a>

        <a class="nav-link <?= $current_page === 'field_coordination_import' ? 'active' : '' ?>" href="field_coordination_import.php">
            <i class="bi bi-cloud-upload"></i> 場協結果匯入
            <?php if ($_badge_pending_field_imports > 0): ?>
                <span class="sidebar-badge red"><?= $_badge_pending_field_imports ?></span>
            <?php endif; ?>
        </a>

        <a class="nav-link <?= $current_page === 'calendar' ? 'active' : '' ?>" href="calendar.php">
            <i class="bi bi-calendar3"></i> 場地月曆
        </a>

        <a class="nav-link <?= $current_page === 'user_mgmt' ? 'active' : '' ?>" href="user_mgmt.php">
            <i class="bi bi-person-gear"></i> 身分權限管理
            <?php if ($_badge_pending_nominations > 0): ?>
                <span class="sidebar-badge red"><?= $_badge_pending_nominations ?></span>
            <?php endif; ?>
        </a>
    </nav>
    <div class="sidebar-section">
        <p class="mb-2">系統操作</p>
        <a class="nav-link" href="../logout.php"><i class="bi bi-box-arrow-right"></i> 登出系統</a>
    </div>
</div>
