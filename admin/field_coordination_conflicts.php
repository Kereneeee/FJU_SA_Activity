<?php

require_once(__DIR__ . "/../DB/db_config.php");
require_once(__DIR__ . "/../includes/FieldCoordinationManager.php");

// 檢查管理員權限
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    header('Location: ../login.php');
    exit();
}

$current_page = 'field_coordination_mgmt';
$fc_manager = new FieldCoordinationManager($conn);
$setting_id = intval($_GET['setting_id'] ?? 0);
$message = '';
$message_type = '';

if ($setting_id <= 0) {
    header('Location: field_coordination_mgmt.php');
    exit();
}

// 取得設定詳情
$settings_sql = "SELECT * FROM field_coordination_settings WHERE setting_id = ?";
$stmt = $conn->prepare($settings_sql);
$stmt->bind_param("i", $setting_id);
$stmt->execute();
$setting = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$setting) {
    header('Location: field_coordination_mgmt.php');
    exit();
}

// 處理衝突解決
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['resolve_conflict'])) {
    $conflict_id = intval($_POST['conflict_id'] ?? 0);
    $resolution_note = trim($_POST['resolution_note'] ?? '');

    if ($conflict_id > 0) {
        if ($fc_manager->markConflictResolved($conflict_id, $resolution_note)) {
            $message_type = 'success';
            $message = '✅ 衝突已標記為已解決';
        } else {
            $message_type = 'error';
            $message = '❌ 標記失敗';
        }
    }
}

// 取得衝突列表
$conflicts = $fc_manager->getConflictsBySettingId($setting_id);
?>

<!DOCTYPE html>
<html lang="zh-TW">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>衝突管理 - 轔仁大學課外活動指導組</title>

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
        html { overflow-y: scroll; }
        body.modal-open { padding-right: 0 !important; }

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
            overflow-y: hidden;
            box-shadow: 3px 0 15px rgba(0,0,0,0.12);
            z-index: 1200;
        }
        .sidebar .brand { text-align: center; margin-bottom: 1.5rem; }
        .sidebar .brand h4 { margin: 0; font-size: 1.1rem; line-height: 1.4; font-weight: 700; }
        .sidebar .nav-link {
            display: flex;
            align-items: center;
            gap: 0.75rem;
            color: rgba(255,255,255,0.9);
            padding: 0.85rem 1rem;
            margin: 0.2rem 0;
            border-radius: 16px;
            transition: background 0.25s ease, transform 0.15s ease;
            text-decoration: none;
        }
        .sidebar .nav-link:hover,
        .sidebar .nav-link.active {
            background: #ece8dd;
            color: #1e4d6b;
            transform: translateX(4px);
        }
        .sidebar .nav-link i { font-size: 1.1rem; }
        .sidebar .sidebar-section {
            padding: 1rem 0.5rem;
            margin-top: 1.5rem;
            border-top: 1px solid rgba(255,255,255,0.12);
        }
        .main-content {
            margin-left: 260px;
            min-height: 100vh;
        }
        .top-navbar {
            background: #d5e3ea;
            border-bottom: 1px solid #bdd0d9;
            padding: 1rem 2rem;
            display: flex;
            justify-content: space-between;
            align-items: center;
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
        .conflict-card {
            border: 1px solid #fee2e2;
            border-left: 4px solid #ef4444;
            border-radius: 8px;
            padding: 1rem;
            margin-bottom: 1rem;
        }
        .conflict-card.resolved {
            border-left-color: #10b981;
            background: #f0fdf4;
        }
        .status-badge {
            display: inline-block;
            padding: 0.4rem 0.8rem;
            border-radius: 12px;
            font-size: 0.85rem;
            font-weight: 600;
        }
        .status-unresolved { background: #fee2e2; color: #7f1d1d; }
        .status-resolved { background: #d1fae5; color: #065f46; }
        @media (max-width: 768px) {
            .main-content { margin-left: 0; }
            .sidebar { position: relative; width: 100%; height: auto; box-shadow: none; }
        }
    
        /* 提示訊息配色 */
        .alert-success { background: #c8dfe0; border-color: #70a3a7; color: #1a3f42; }
        .alert-warning { background: #ede4e5; border-color: #deb8b9; color: #6b2d2d; }
        .alert-danger  { background: #deb8b9; border-color: #c9979a; color: #5c1f22; }
        .alert-info    { background: #ede4e5; border-color: #c8c0c2; color: #5a3f42; }
    </style>
</head>
<body>
    <?php include(__DIR__ . "/../includes/admin_sidebar.php"); ?>

    <main class="main-content">
        <header class="top-navbar">
            <div>
                <ol class="breadcrumb mb-0">
                    <li class="breadcrumb-item"><a href="dashboard.php">首頁</a></li>
                    <li class="breadcrumb-item"><a href="field_coordination_mgmt.php">場協登記管理</a></li>
                    <li class="breadcrumb-item active" aria-current="page">衝突管理</li>
                </ol>
                <h4 class="mt-2 mb-0">衝突管理</h4>
            </div>
        </header>

        <section class="content-wrapper">
            <div class="card">
                <h3>設定詳情</h3>
                <div class="row">
                    <div class="col-md-6">
                        <p class="mb-1"><strong>學年學期：</strong><?= htmlspecialchars($setting['academic_year'], ENT_QUOTES, 'UTF-8') ?> <?= $setting['semester'] == 1 ? '上學期' : '下學期' ?></p>
                    </div>
                    <div class="col-md-6">
                        <p><strong>登記期間：</strong><?= date('Y-m-d', strtotime($setting['registration_start_date'])) ?> 至 <?= date('Y-m-d', strtotime($setting['registration_end_date'])) ?></p>
                    </div>
                    <div class="col-md-6">
                        <p><strong>協調大會：</strong><?= date('Y-m-d H:i', strtotime($setting['coordination_meeting_date'])) ?></p>
                    </div>
                </div>
            </div>

            <?php if (!empty($message)): ?>
            <div class="card" style="border-left: 5px solid <?= $message_type === 'success' ? '#10b981' : '#ef4444'; ?>;">
                <p class="text-muted mb-0"><?= $message ?></p>
            </div>
            <?php endif; ?>

            <div class="card">
                <h3><i class="bi bi-exclamation-triangle"></i> 場協登記衝突列表</h3>
                <p class="text-muted">共檢測到 <?= count($conflicts) ?> 個衝突</p>

                <?php if (empty($conflicts)): ?>
                <div class="alert alert-success">
                    <i class="bi bi-check-circle"></i> 暫無衝突，所有場協登記都已協調。
                </div>
                <?php else: ?>
                    <?php foreach ($conflicts as $conflict): ?>
                    <div class="conflict-card <?= $conflict['status'] === 'resolved' ? 'resolved' : '' ?>">
                        <div class="d-flex justify-content-between align-items-start">
                            <div>
                                <h5 class="mb-2">
                                    <span class="status-badge status-<?= $conflict['status'] ?>">
                                        <?= $conflict['status'] === 'unresolved' ? '未解決' : '已解決' ?>
                                    </span>
                                </h5>
                                <p class="mb-1"><strong>場地：</strong><?= htmlspecialchars($conflict['space_name'], ENT_QUOTES, 'UTF-8') ?></p>
                                <p class="mb-1"><strong>衝突時間：</strong><?= date('Y-m-d H:i', strtotime($conflict['conflict_start_time'])) ?> ~ <?= date('H:i', strtotime($conflict['conflict_end_time'])) ?></p>
                                <p class="mb-1"><strong>社團1：</strong><?= htmlspecialchars($conflict['club_name_1'], ENT_QUOTES, 'UTF-8') ?> - <?= htmlspecialchars($conflict['event_name_1'], ENT_QUOTES, 'UTF-8') ?></p>
                                <p class="mb-1"><strong>社團2：</strong><?= htmlspecialchars($conflict['club_name_2'], ENT_QUOTES, 'UTF-8') ?> - <?= htmlspecialchars($conflict['event_name_2'], ENT_QUOTES, 'UTF-8') ?></p>
                                <?php if ($conflict['resolution_note']): ?>
                                <p class="mb-0 mt-2"><strong>解決方案：</strong><br><?= htmlspecialchars($conflict['resolution_note'], ENT_QUOTES, 'UTF-8') ?></p>
                                <?php endif; ?>
                            </div>
                            <?php if ($conflict['status'] === 'unresolved'): ?>
                            <button class="btn btn-sm btn-outline-primary" data-bs-toggle="modal" data-bs-target="#resolveModal<?= $conflict['conflict_id'] ?>">
                                <i class="bi bi-check"></i> 標記已解決
                            </button>

                            <!-- 解決模態框 -->
                            <div class="modal fade" id="resolveModal<?= $conflict['conflict_id'] ?>" tabindex="-1">
                                <div class="modal-dialog">
                                    <div class="modal-content">
                                        <div class="modal-header">
                                            <h5 class="modal-title">標記衝突為已解決</h5>
                                            <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                                        </div>
                                        <form method="post">
                                            <div class="modal-body">
                                                <input type="hidden" name="resolve_conflict" value="1">
                                                <input type="hidden" name="conflict_id" value="<?= $conflict['conflict_id'] ?>">
                                                
                                                <p class="mb-3">
                                                    <strong>衝突：</strong><?= htmlspecialchars($conflict['club_name_1'], ENT_QUOTES, 'UTF-8') ?> vs <?= htmlspecialchars($conflict['club_name_2'], ENT_QUOTES, 'UTF-8') ?><br>
                                                    <small class="text-muted"><?= htmlspecialchars($conflict['space_name'], ENT_QUOTES, 'UTF-8') ?> @ <?= date('Y-m-d H:i', strtotime($conflict['conflict_start_time'])) ?></small>
                                                </p>

                                                <div class="mb-3">
                                                    <label class="form-label">解決方案說明 *</label>
                                                    <textarea name="resolution_note" class="form-control" rows="4" placeholder="例如：已安排社團A使用此場地，社團B改用E廳" required></textarea>
                                                </div>
                                            </div>
                                            <div class="modal-footer">
                                                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">取消</button>
                                                <button type="submit" class="btn btn-primary">標記已解決</button>
                                            </div>
                                        </form>
                                    </div>
                                </div>
                            </div>
                            <?php endif; ?>
                        </div>
                    </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </section>
    </main>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
