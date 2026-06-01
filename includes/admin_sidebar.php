<?php
$current_page = $current_page ?? '';
?>
<div class="sidebar">
    <div class="brand">
        <h4>輔仁大學<br>課外活動指導組</h4>
    </div>
    <nav class="nav flex-column">
        <a class="nav-link <?= $current_page === 'dashboard' ? 'active' : '' ?>" href="dashboard.php"><i class="bi bi-speedometer2"></i> 儀表板</a>
        <a class="nav-link <?= $current_page === 'review' ? 'active' : '' ?>" href="review.php"><i class="bi bi-clipboard-check"></i> 審核管理</a>
        <a class="nav-link <?= $current_page === 'event_mgmt' ? 'active' : '' ?>" href="event_mgmt.php"><i class="bi bi-calendar-check"></i> 申請紀錄</a>
        <a class="nav-link <?= $current_page === 'equipment_mgmt' ? 'active' : '' ?>" href="equipment_mgmt.php"><i class="bi bi-tools"></i> 器材庫存管理</a>
        <a class="nav-link <?= $current_page === 'space_mgmt' ? 'active' : '' ?>" href="space_mgmt.php"><i class="bi bi-building"></i> 空間管理</a>
        <a class="nav-link <?= $current_page === 'field_coordination_mgmt' ? 'active' : '' ?>" href="field_coordination_mgmt.php"><i class="bi bi-people-fill"></i> 場協登記管理</a>
        <a class="nav-link <?= $current_page === 'field_coordination_import' ? 'active' : '' ?>" href="field_coordination_import.php"><i class="bi bi-cloud-upload"></i> 場協結果匯入</a>
        <a class="nav-link <?= $current_page === 'calendar' ? 'active' : '' ?>" href="calendar.php"><i class="bi bi-calendar3"></i> 完整行事曆</a>
        <a class="nav-link <?= $current_page === 'user_mgmt' ? 'active' : '' ?>" href="user_mgmt.php"><i class="bi bi-person-gear"></i> 身分權限管理</a>
    </nav>
    <div class="sidebar-section">
        <p class="mb-2">快捷操作</p>
        <a class="nav-link" href="../logout.php"><i class="bi bi-box-arrow-right"></i> 登出系統</a>
    </div>
</div>