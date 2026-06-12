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
$message = '';
$message_type = '';

// 處理建立新的場協設定
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['create_setting'])) {
    $academic_year = intval($_POST['academic_year'] ?? 0);
    $semester = intval($_POST['semester'] ?? 0);
    $start_date = trim($_POST['start_date'] ?? '');
    $end_date = trim($_POST['end_date'] ?? '');
    $meeting_date = trim($_POST['meeting_date'] ?? '');
    $borrow_start_date = trim($_POST['borrow_start_date'] ?? '');
    $borrow_end_date = trim($_POST['borrow_end_date'] ?? '');
    $borrow_start_time = trim($_POST['borrow_start_time'] ?? '');
    $borrow_end_time = trim($_POST['borrow_end_time'] ?? '');
    $description = trim($_POST['description'] ?? '');

    $errors = [];
    if ($academic_year <= 0) $errors[] = '請填寫正確的民國學年，例如 115';
    if ($semester !== 1 && $semester !== 2) $errors[] = '請選擇有效的學期 (上學期/下學期)';
    if (empty($start_date)) $errors[] = '請選擇登記開始日期';
    if (empty($end_date)) $errors[] = '請選擇登記結束日期';
    if (empty($meeting_date)) $errors[] = '請選擇協調大會日期';
    if (empty($borrow_start_date)) $errors[] = '請選擇借用開始日期';
    if (empty($borrow_end_date)) $errors[] = '請選擇借用結束日期';
    if (empty($borrow_start_time)) $errors[] = '請選擇借用最早時段';
    if (empty($borrow_end_time)) $errors[] = '請選擇借用最晚時段';
    if (!empty($borrow_start_date) && !empty($borrow_end_date) && $borrow_start_date > $borrow_end_date) {
        $errors[] = '借用開始日期不得晚於借用結束日期';
    }
    if (!empty($borrow_start_time) && !empty($borrow_end_time) && $borrow_start_time >= $borrow_end_time) {
        $errors[] = '借用最早時段必須早於借用最晚時段';
    }

    if (empty($errors)) {
        $admin_student_id = $fc_manager->getStudentIdByUserId($_SESSION['user_id']);
        if (!$admin_student_id) {
            $admin_student_id = $_SESSION['user_id'];
        }

        $start_datetime = $start_date . ' 00:00:00';
        $end_datetime = $end_date . ' 23:59:59';
        $meeting_datetime = $meeting_date . ' 14:00:00';
        $borrow_start_datetime = $borrow_start_date . ' 00:00:00';
        $borrow_end_datetime = $borrow_end_date . ' 23:59:59';

        $setting_id = $fc_manager->createFieldCoordinationSetting(
            $academic_year, $semester, $start_datetime, $end_datetime, $meeting_datetime,
            $borrow_start_datetime, $borrow_end_datetime, $borrow_start_time, $borrow_end_time,
            $description, $admin_student_id
        );

        if ($setting_id) {
            $message_type = 'success';
            $message = '✅ 場協設定已建立，設定ID：#' . $setting_id;
            $_POST = [];
        } else {
            $message_type = 'error';
            $message = '❌ 場協設定建立失敗';
        }
    } else {
        $message_type = 'error';
        $message = '❌ ' . implode('<br>', $errors);
    }
}

// 處理更新設定
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_setting'])) {
    $setting_id = intval($_POST['setting_id'] ?? 0);
    $start_date = trim($_POST['start_date'] ?? '');
    $end_date = trim($_POST['end_date'] ?? '');
    $meeting_date = trim($_POST['meeting_date'] ?? '');
    $borrow_start_date = trim($_POST['borrow_start_date'] ?? '');
    $borrow_end_date = trim($_POST['borrow_end_date'] ?? '');
    $borrow_start_time = trim($_POST['borrow_start_time'] ?? '');
    $borrow_end_time = trim($_POST['borrow_end_time'] ?? '');
    $status = trim($_POST['status'] ?? 'active');
    $description = trim($_POST['description'] ?? '');

    if ($setting_id > 0) {
        $start_datetime = $start_date . ' 00:00:00';
        $end_datetime = $end_date . ' 23:59:59';
        $meeting_datetime = $meeting_date . ' 14:00:00';
        $borrow_start_datetime = $borrow_start_date . ' 00:00:00';
        $borrow_end_datetime = $borrow_end_date . ' 23:59:59';

        if ($fc_manager->updateFieldCoordinationSetting($setting_id, $start_datetime, $end_datetime, $meeting_datetime,
                $borrow_start_datetime, $borrow_end_datetime, $borrow_start_time, $borrow_end_time, $status, $description)) {
            $message_type = 'success';
            $message = '✅ 場協設定已更新';
        } else {
            $message_type = 'error';
            $message = '❌ 場協設定更新失敗';
        }
    }
}

// 取得所有設定
$all_settings = $fc_manager->getAllFieldCoordinationSettings();
$active_setting = $fc_manager->getActiveSettings();
?>

<!DOCTYPE html>
<html lang="zh-TW">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>場協登記管理 - 轔仁大學課外活動指導組</title>

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
        .sidebar .brand {
            text-align: center;
            margin-bottom: 1.5rem;
        }
        .sidebar .brand h4 {
            margin: 0;
            font-size: 1.1rem;
            line-height: 1.4;
            font-weight: 700;
        }
        .sidebar .nav-link {
            display: flex;
            align-items: center;
            gap: 0.75rem;
            color: rgba(255,255,255,0.9);
            padding: 0.85rem 1rem;
            margin: 0.2rem 0;
            border-radius: 16px;
            transition: background 0.25s ease, transform 0.15s ease;
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
            transition: margin-left 0.25s ease;
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
        .status-badge {
            display: inline-block;
            padding: 0.4rem 0.8rem;
            border-radius: 12px;
            font-size: 0.85rem;
            font-weight: 600;
        }
        .status-active { background: #d1fae5; color: #065f46; }
        .status-inactive { background: #fee2e2; color: #7f1d1d; }
        .btn-sm-custom {
            padding: 0.4rem 0.8rem;
            font-size: 0.85rem;
            border-radius: 8px;
            text-decoration: none;
        }
        .table-responsive {
            border-radius: 12px;
            overflow: hidden;
        }
        @media (max-width: 768px) {
            .main-content { margin-left: 0; }
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
                    <li class="breadcrumb-item active" aria-current="page">場協登記管理</li>
                </ol>
                <h4 class="mt-2 mb-0">場協登記管理</h4>
            </div>
        </header>

        <section class="content-wrapper">
            <?php if (!empty($message)): ?>
            <div class="card" style="border-left: 5px solid <?= $message_type === 'success' ? '#10b981' : '#ef4444'; ?>;">
                <p class="text-muted mb-0"><?= $message ?></p>
            </div>
            <?php endif; ?>

            <!-- 目前啟用設定 -->
            <?php if ($active_setting): ?>
            <div class="card" style="border: 2px solid var(--primary);">
                <div class="d-flex justify-content-between align-items-start">
                    <div>
                        <h3><i class="bi bi-check-circle-fill"></i> 目前啟用設定</h3>
                        <p class="mb-1"><strong>學年學期：</strong><?= htmlspecialchars($active_setting['academic_year'], ENT_QUOTES, 'UTF-8') ?> <?= $active_setting['semester'] == 1 ? '上學期' : '下學期' ?></p>
                        <p class="mb-1"><strong>登記期間：</strong><?= date('Y-m-d H:i', strtotime($active_setting['registration_start_date'])) ?> 至 <?= date('Y-m-d H:i', strtotime($active_setting['registration_end_date'])) ?></p>
                        <p class="mb-0"><strong>協調大會：</strong><?= date('Y-m-d H:i', strtotime($active_setting['coordination_meeting_date'])) ?></p>
                    </div>
                    <span class="status-badge status-active">啟用中</span>
                </div>
            </div>
            <?php endif; ?>

            <!-- 建立新設定 -->
            <div class="card">
                <h3><i class="bi bi-plus-circle"></i> 建立新的場協設定</h3>
                <form method="post">
                    <input type="hidden" name="create_setting" value="1">
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label" for="academic_year">學年 *</label>
                            <input type="text" id="academic_year" name="academic_year" class="form-control" list="academic_year_suggestions" placeholder="例：114" required>
                            <datalist id="academic_year_suggestions">
                                <option value="114">
                                <option value="115">
                                <option value="116">
                                <option value="117">
                                <option value="118">
                            </datalist>
                            <div class="form-text text-muted">可選 114、115、116 等民國學年，或手動輸入其他有效數字。</div>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label" for="semester">學期 *</label>
                            <select id="semester" name="semester" class="form-control" required>
                                <option value="">請選擇</option>
                                <option value="1">上學期（8/1 - 1/31）</option>
                                <option value="2">下學期（2/1 - 7/31）</option>
                            </select>
                            <div class="form-text text-muted">建議登記期間設在寒假 / 暑假，協調大會安排在新學期開學後。例：114-2 可在 2026 年 1/20-2/22 登記，3/3 舉行協調大會。</div>
                        </div>
                    </div>
                    <div class="row g-3 mt-3">
                        <div class="col-md-4">
                            <label class="form-label" for="start_date">登記開始日期 *</label>
                            <input type="date" id="start_date" name="start_date" class="form-control" required>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label" for="end_date">登記結束日期 *</label>
                            <input type="date" id="end_date" name="end_date" class="form-control" required>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label" for="meeting_date">協調大會日期 *</label>
                            <input type="date" id="meeting_date" name="meeting_date" class="form-control" required>
                        </div>
                    </div>
                    <div class="row g-3 mt-3">
                        <div class="col-md-3">
                            <label class="form-label" for="borrow_start_date">借用開始日期 *</label>
                            <input type="date" id="borrow_start_date" name="borrow_start_date" class="form-control" required>
                        </div>
                        <div class="col-md-3">
                            <label class="form-label" for="borrow_end_date">借用結束日期 *</label>
                            <input type="date" id="borrow_end_date" name="borrow_end_date" class="form-control" required>
                        </div>
                        <div class="col-md-3">
                            <label class="form-label" for="borrow_start_time">借用最早時段 *</label>
                            <input type="time" id="borrow_start_time" name="borrow_start_time" class="form-control" required>
                        </div>
                        <div class="col-md-3">
                            <label class="form-label" for="borrow_end_time">借用最晚時段 *</label>
                            <input type="time" id="borrow_end_time" name="borrow_end_time" class="form-control" required>
                        </div>
                    </div>
                    <div class="mt-3">
                        <label class="form-label" for="description">說明</label>
                        <textarea id="description" name="description" class="form-control" rows="3" placeholder="可選的說明資訊"></textarea>
                    </div>
                    <div class="mt-4">
                        <button type="submit" class="btn btn-primary">建立設定</button>
                    </div>
                </form>
            </div>

            <!-- 所有設定列表 -->
            <div class="card">
                <h3><i class="bi bi-list-ul"></i> 所有場協設定</h3>
                <div class="table-responsive">
                    <table class="table table-hover mb-0">
                        <thead style="background: #f8f9fa;">
                            <tr>
                                <th>學年學期</th>
                                <th>登記期間</th>
                                <th>協調大會</th>
                                <th>狀態</th>
                                <th>操作</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($all_settings as $setting): ?>
                            <tr>
                                <td>
                                    <strong><?= htmlspecialchars($setting['academic_year'], ENT_QUOTES, 'UTF-8') ?></strong><br>
                                    <small class="text-muted"><?= $setting['semester'] == 1 ? '上學期' : '下學期' ?></small>
                                </td>
                                <td>
                                    <?= date('Y-m-d', strtotime($setting['registration_start_date'])) ?><br>
                                    至<br>
                                    <?= date('Y-m-d', strtotime($setting['registration_end_date'])) ?>
                                </td>
                                <td><?= date('Y-m-d H:i', strtotime($setting['coordination_meeting_date'])) ?></td>
                                <td>
                                    <span class="status-badge <?= $setting['status'] === 'active' ? 'status-active' : 'status-inactive' ?>">
                                        <?= $setting['status'] === 'active' ? '啟用' : '禁用' ?>
                                    </span>
                                </td>
                                <td>
                                    <button class="btn btn-sm btn-outline-primary btn-sm-custom" data-bs-toggle="modal" data-bs-target="#editModal<?= $setting['setting_id'] ?>">
                                        <i class="bi bi-pencil"></i> 編輯
                                    </button>
                                    <a href="field_coordination_conflicts.php?setting_id=<?= $setting['setting_id'] ?>" class="btn btn-sm btn-outline-info btn-sm-custom">
                                        <i class="bi bi-exclamation-triangle"></i> 衝突管理
                                    </a>
                                </td>
                            </tr>

                            <!-- 編輯模態框 -->
                            <div class="modal fade" id="editModal<?= $setting['setting_id'] ?>" tabindex="-1">
                                <div class="modal-dialog">
                                    <div class="modal-content">
                                        <div class="modal-header">
                                            <h5 class="modal-title">編輯場協設定</h5>
                                            <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                                        </div>
                                        <form method="post">
                                            <div class="modal-body">
                                                <input type="hidden" name="update_setting" value="1">
                                                <input type="hidden" name="setting_id" value="<?= $setting['setting_id'] ?>">
                                                
                                                <div class="mb-3">
                                                    <label class="form-label">學年學期</label>
                                                    <p class="form-control-plaintext"><?= htmlspecialchars($setting['academic_year'], ENT_QUOTES, 'UTF-8') ?> <?= $setting['semester'] == 1 ? '上學期' : '下學期' ?></p>
                                                </div>

                                                <div class="mb-3">
                                                    <label class="form-label">登記開始日期 *</label>
                                                    <input type="date" name="start_date" class="form-control" 
                                                           value="<?= date('Y-m-d', strtotime($setting['registration_start_date'])) ?>" required>
                                                </div>

                                                <div class="mb-3">
                                                    <label class="form-label">登記結束日期 *</label>
                                                    <input type="date" name="end_date" class="form-control"
                                                           value="<?= date('Y-m-d', strtotime($setting['registration_end_date'])) ?>" required>
                                                </div>

                                                <div class="mb-3">
                                                    <label class="form-label">協調大會日期 *</label>
                                                    <input type="date" name="meeting_date" class="form-control"
                                                           value="<?= date('Y-m-d', strtotime($setting['coordination_meeting_date'])) ?>" required>
                                                </div>

                                                <div class="row g-3">
                                                    <div class="col-md-6">
                                                        <label class="form-label">借用開始日期 *</label>
                                                        <input type="date" name="borrow_start_date" class="form-control"
                                                               value="<?= $setting['borrow_start_date'] ? date('Y-m-d', strtotime($setting['borrow_start_date'])) : '' ?>" required>
                                                    </div>
                                                    <div class="col-md-6">
                                                        <label class="form-label">借用結束日期 *</label>
                                                        <input type="date" name="borrow_end_date" class="form-control"
                                                               value="<?= $setting['borrow_end_date'] ? date('Y-m-d', strtotime($setting['borrow_end_date'])) : '' ?>" required>
                                                    </div>
                                                </div>

                                                <div class="row g-3 mt-3">
                                                    <div class="col-md-6">
                                                        <label class="form-label">借用最早時段 *</label>
                                                        <input type="time" name="borrow_start_time" class="form-control"
                                                               value="<?= htmlspecialchars($setting['borrow_start_time'] ?? '', ENT_QUOTES, 'UTF-8') ?>" required>
                                                    </div>
                                                    <div class="col-md-6">
                                                        <label class="form-label">借用最晚時段 *</label>
                                                        <input type="time" name="borrow_end_time" class="form-control"
                                                               value="<?= htmlspecialchars($setting['borrow_end_time'] ?? '', ENT_QUOTES, 'UTF-8') ?>" required>
                                                    </div>
                                                </div>

                                                <div class="mb-3">
                                                    <label class="form-label">狀態 *</label>
                                                    <select name="status" class="form-control" required>
                                                        <option value="active" <?= $setting['status'] === 'active' ? 'selected' : '' ?>>啟用</option>
                                                        <option value="inactive" <?= $setting['status'] === 'inactive' ? 'selected' : '' ?>>禁用</option>
                                                    </select>
                                                </div>

                                                <div class="mb-3">
                                                    <label class="form-label">說明</label>
                                                    <textarea name="description" class="form-control" rows="3"><?= htmlspecialchars($setting['description'] ?? '', ENT_QUOTES, 'UTF-8') ?></textarea>
                                                </div>
                                            </div>
                                            <div class="modal-footer">
                                                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">取消</button>
                                                <button type="submit" class="btn btn-primary">儲存更改</button>
                                            </div>
                                        </form>
                                    </div>
                                </div>
                            </div>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <?php if (empty($all_settings)): ?>
                <p class="text-muted text-center mt-3">暫無場協設定</p>
                <?php endif; ?>
            </div>
        </section>
    </main>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
