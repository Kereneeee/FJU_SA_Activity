<?php
ini_set('display_errors', 1);
error_reporting(E_ALL);

require_once(__DIR__ . "/../DB/db_config.php");
require_once(__DIR__ . "/../includes/FieldCoordinationManager.php");

if (!isset($_SESSION['student_id'])) {
    header('Location: ../login.php');
    exit();
}

$current_page = 'field_coord_records';
$fc_manager = new FieldCoordinationManager($conn);
$user_id = $_SESSION['user_id'] ?? null;

// 取得使用者所屬社團
$club_sql = "SELECT cm.club_id, c.club_name 
             FROM club_members cm
             JOIN clubs c ON cm.club_id = c.club_id
             WHERE cm.user_id = ?";
$club_stmt = $conn->prepare($club_sql);
$club_stmt->bind_param("i", $user_id);
$club_stmt->execute();
$club_result = $club_stmt->get_result();
$clubs = [];
while ($row = $club_result->fetch_assoc()) {
    $clubs[$row['club_id']] = $row['club_name'];
}
$club_stmt->close();

// 取得所有場協記錄
$records = [];
if (!empty($clubs)) {
    $club_ids = array_keys($clubs);
    $placeholders = implode(',', array_fill(0, count($club_ids), '?'));
    
    $records_sql = "SELECT fcr.*, e.event_name, e.club_name, e.start_time, e.end_time, e.description, e.status,
                           fcs.academic_year, fcs.semester, fcs.registration_start_date, fcs.registration_end_date, fcs.coordination_meeting_date
                    FROM field_coordination_registrations fcr
                    JOIN events e ON fcr.event_id = e.event_id
                    JOIN field_coordination_settings fcs ON fcr.setting_id = fcs.setting_id
                    WHERE fcr.club_id IN ($placeholders)
                    ORDER BY fcs.academic_year DESC, fcs.semester DESC, e.start_time DESC";
    
    $records_stmt = $conn->prepare($records_sql);
    $records_stmt->bind_param(str_repeat('i', count($club_ids)), ...$club_ids);
    $records_stmt->execute();
    $records_result = $records_stmt->get_result();
    
    while ($row = $records_result->fetch_assoc()) {
        $records[] = $row;
    }
    $records_stmt->close();
}
?>

<!DOCTYPE html>
<html lang="zh-TW">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>場協登記記錄 - 輔仁大學課外活動指導組</title>

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
        body {
            margin: 0;
            min-height: 100vh;
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background: var(--bg);
            color: #1f2937;
        }
        .sidebar {
            position: fixed;
            top: 0;
            left: 0;
            width: 260px;
            height: 100vh;
            background: var(--primary);
            color: white;
            padding: 1.5rem 0.8rem;
            overflow-y: auto;
            box-shadow: 3px 0 15px rgba(0,0,0,0.12);
            z-index: 1200;
        }
        .main-content {
            margin-left: 260px;
            min-height: 100vh;
        }
        .top-navbar {
            background: #d5e3ea;
            border-bottom: 1px solid #bdd0d9;
            padding: 1rem 2rem;
            position: sticky;
            top: 0;
            z-index: 1100;
        }
        .content-wrapper {
            padding: 1.5rem 2rem 2rem;
        }
        .card {
            background: var(--card);
            border-radius: 18px;
            box-shadow: 0 10px 30px rgba(15,23,42,0.06);
            padding: 1.5rem;
            margin-bottom: 1.5rem;
        }
        .card h3 {
            margin-bottom: 1rem;
            font-weight: 700;
            color: var(--primary);
        }
        .record-item {
            border: 1px solid #e5e7eb;
            border-radius: 12px;
            padding: 1rem;
            margin-bottom: 1rem;
        }
        .record-item.pending {
            background: #fef3c7;
            border-color: #fcd34d;
        }
        .record-item.approved {
            background: #dcfce7;
            border-color: #86efac;
        }
        .record-item.rejected {
            background: #fee2e2;
            border-color: #fca5a5;
        }
        .status-badge {
            display: inline-block;
            padding: 0.4rem 0.8rem;
            border-radius: 8px;
            font-size: 0.85rem;
            font-weight: 600;
        }
        .status-pending { background: #f0e8c0; color: #6b5a20; }
        .status-approved { background: #70a3a7; color: #1a3f42; }
        .status-rejected { background: #c9979a; color: #5c1f22; }
        @media (max-width: 1100px) {
            .main-content { margin-left: 0; }
        }
    
        /* 提示訊息配色 */
        .alert-success { background: #c8dfe0; border-color: #70a3a7; color: #1a3f42; }
        .alert-warning { background: #ede4e5; border-color: #deb8b9; color: #6b2d2d; }
        .alert-danger  { background: #deb8b9; border-color: #c9979a; color: #5c1f22; }
        .alert-info    { background: #ede4e5; border-color: #c8c0c2; color: #5a3f42; }
        .top-navbar .breadcrumb { font-size: 0.8rem; }
        .top-navbar .breadcrumb-item + .breadcrumb-item::before { content: '›'; font-size: 1rem; color: #c9d0d8; }
        .top-navbar .breadcrumb-item a { color: #1e4d6b; text-decoration: none; opacity: 0.75; }
        .top-navbar .breadcrumb-item a:hover { opacity: 1; }
        .top-navbar .breadcrumb-item.active { color: #6b7280; }
    </style>
</head>
<body>
    <?php include(__DIR__ . "/../includes/sidebar.php"); ?>

    <main class="main-content">
        <header class="top-navbar">
            <div>
                <ol class="breadcrumb mb-0">
                    <li class="breadcrumb-item"><a href="dashboard.php">首頁</a></li>
                    <li class="breadcrumb-item active" aria-current="page">場協登記記錄</li>
                </ol>
                <h4 class="mt-2 mb-0">場協登記記錄</h4>
            </div>
        </header>

        <section class="content-wrapper">
            <div class="card">
                <h3><i class="bi bi-clock-history"></i> 場協登記歷史</h3>
                <p class="text-muted">查看您的社團提交的所有場協登記申請及其審核狀態。</p>
            </div>

            <?php if (empty($records)): ?>
            <div class="card">
                <div class="text-center py-4">
                    <i class="bi bi-inbox" style="font-size: 3rem; color: #ccc;"></i>
                    <p class="mt-3 text-muted">暫無場協登記記錄</p>
                </div>
            </div>
            <?php else: ?>
                <?php 
                // 按學年學期分組
                $grouped_records = [];
                foreach ($records as $record) {
                    $key = $record['academic_year'] . '-' . $record['semester'];
                    if (!isset($grouped_records[$key])) {
                        $grouped_records[$key] = [
                            'year' => $record['academic_year'],
                            'semester' => $record['semester'],
                            'meeting_date' => $record['coordination_meeting_date'],
                            'items' => []
                        ];
                    }
                    $grouped_records[$key]['items'][] = $record;
                }
                ?>

                <?php foreach ($grouped_records as $group): ?>
                <div class="card">
                    <h5 class="mb-3">
                        <strong><?= htmlspecialchars($group['year'], ENT_QUOTES, 'UTF-8') ?></strong> <?= $group['semester'] == 1 ? '上學期' : '下學期' ?>
                        <small class="text-muted">(協調大會：<?= date('Y-m-d H:i', strtotime($group['meeting_date'])) ?>)</small>
                    </h5>
                    
                    <?php foreach ($group['items'] as $record): ?>
                    <div class="record-item <?= $record['is_approved'] === 1 ? 'approved' : ($record['is_approved'] === 0 ? 'rejected' : 'pending') ?>">
                        <div class="d-flex justify-content-between align-items-start">
                            <div style="flex: 1;">
                                <h6 class="mb-2">
                                    <?= htmlspecialchars($record['event_name'], ENT_QUOTES, 'UTF-8') ?>
                                    <span class="status-badge status-<?= $record['is_approved'] === 1 ? 'approved' : ($record['is_approved'] === 0 ? 'rejected' : 'pending') ?>">
                                        <?= $record['is_approved'] === 1 ? '已批准' : ($record['is_approved'] === 0 ? '被拒绝' : '待定') ?>
                                    </span>
                                </h6>
                                <p class="mb-1"><small><strong>社團：</strong><?= htmlspecialchars($record['club_name'], ENT_QUOTES, 'UTF-8') ?></small></p>
                                <p class="mb-1"><small><strong>時間：</strong><?= date('Y-m-d H:i', strtotime($record['start_time'])) ?> ~ <?= date('H:i', strtotime($record['end_time'])) ?></small></p>
                                <p class="mb-1"><small><strong>事件狀態：</strong><?= htmlspecialchars($record['status'], ENT_QUOTES, 'UTF-8') ?></small></p>
                                
                                <?php if ($record['approval_note']): ?>
                                <p class="mb-0 mt-2">
                                    <small><strong>備註：</strong><br><?= htmlspecialchars($record['approval_note'], ENT_QUOTES, 'UTF-8') ?></small>
                                </p>
                                <?php endif; ?>

                                <?php if (!empty($record['description'])): ?>
                                <p class="mb-0 mt-2">
                                    <small><strong>說明：</strong><br><?= htmlspecialchars($record['description'], ENT_QUOTES, 'UTF-8') ?></small>
                                </p>
                                <?php endif; ?>
                            </div>
                            <div style="margin-left: 1rem;">
                                <a href="calendar.php" class="btn btn-sm btn-outline-primary">
                                    <i class="bi bi-calendar3"></i> 查看日曆
                                </a>
                            </div>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </section>
    </main>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
