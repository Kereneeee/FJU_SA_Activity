<?php
ini_set('display_errors', 1);
error_reporting(E_ALL);

require_once(__DIR__ . "/../DB/db_config.php");

if (!isset($_SESSION['student_id'])) {
    header('Location: ../login.php');
    exit();
}

// 取得所有器材資料
$sql = "SELECT * FROM equipment WHERE equipment_status = 'available'";
$result_equipment = $conn->query($sql);
if (!$result_equipment) {
    die("查詢錯誤: " . $conn->error);
}

$equipment = [];
if ($result_equipment) {
    foreach ($result_equipment->fetch_all(MYSQLI_ASSOC) as $eq) {
        $equipment[] = [
            'equipment_id'    => $eq['equipment_id'],
            'name'            => $eq['name'],
            'description'     => $eq['description'] ?? '',
            'borrowing_limit' => $eq['borrowing_limit'] ?? 0,
            'total_quantity'  => $eq['total_quantity'],
            'code'            => $eq['code'] ?? ''
        ];
    }
}

$current_page = 'equipment';
?>
<!DOCTYPE html>
<html lang="zh-TW">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>器材庫存查詢 - 輔仁大學課外活動指導組</title>

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

        /* ── sidebar ── */
        .sidebar {
            position: fixed; top: 0; left: 0;
            width: 260px; height: 100vh;
            background: var(--primary);
            color: white;
            padding: 1.5rem 0.8rem;
            overflow-y: auto;
            box-shadow: 3px 0 15px rgba(0,0,0,0.12);
            z-index: 1200;
        }
        .sidebar .brand { text-align: center; margin-bottom: 1.5rem; }
        .sidebar .brand h4 { margin: 0; font-size: 1.1rem; line-height: 1.4; font-weight: 700; }
        .sidebar .nav-link {
            display: flex; align-items: center; gap: 0.75rem;
            color: rgba(255,255,255,0.9);
            padding: 0.85rem 1rem; margin: 0.2rem 0;
            border-radius: 16px;
            transition: background 0.25s ease, transform 0.15s ease;
        }
        .sidebar .nav-link:hover,
        .sidebar .nav-link.active { background: #ece8dd; color: #1e4d6b; transform: translateX(4px); }
        .sidebar .nav-link i { font-size: 1.1rem; }
        .sidebar .sidebar-section {
            padding: 1rem 0.5rem; margin-top: 1.5rem;
            border-top: 1px solid rgba(255,255,255,0.12);
        }

        /* ── layout ── */
        .main-content { margin-left: 260px; min-height: 100vh; }
        .top-navbar {
            background: #d5e3ea;
            border-bottom: 1px solid #bdd0d9;
            padding: 1rem 2rem;
            display: flex; justify-content: space-between; align-items: center;
            position: sticky; top: 0; z-index: 1100;
        }
        .top-navbar .breadcrumb { margin: 0; background: transparent; padding: 0; font-size: 0.8rem; }
        .top-navbar .breadcrumb-item + .breadcrumb-item::before { content: '›'; font-size: 1rem; color: #c9d0d8; }
        .top-navbar .breadcrumb-item a { color: #1e4d6b; text-decoration: none; opacity: 0.75; }
        .top-navbar .breadcrumb-item a:hover { opacity: 1; }
        .top-navbar .breadcrumb-item.active { color: #6b7280; }
        .content-wrapper { padding: 1.5rem 2rem 2rem; }

        /* ── card ── */
        .card {
            background: var(--card);
            border-radius: 18px;
            box-shadow: 0 10px 30px rgba(15,23,42,0.06);
            padding: 1.5rem;
            margin-bottom: 1.5rem;
        }
        .card-title {
            font-weight: 700; color: var(--primary);
            display: flex; align-items: center; gap: 0.5rem;
            margin-bottom: 1.1rem; font-size: 1.05rem;
        }

        /* ── 時段選擇 ── */
        .time-picker-row {
            display: grid;
            grid-template-columns: 1fr 1fr auto;
            gap: 1rem;
            align-items: flex-end;
        }
        .time-picker-row label { font-weight: 600; font-size: 0.9rem; color: #374151; display: block; margin-bottom: 0.4rem; }
        .time-picker-row .form-control { border-radius: 10px; border: 1px solid #d1d5db; }
        .time-picker-row .form-control:focus { border-color: var(--primary); box-shadow: 0 0 0 3px rgba(30,77,107,0.1); }
        .btn-query {
            background: var(--primary); color: white;
            border: none; border-radius: 10px;
            padding: 0.65rem 1.4rem;
            font-weight: 600; cursor: pointer;
            white-space: nowrap;
            transition: background 0.2s;
            display: flex; align-items: center; gap: 0.4rem;
        }
        .btn-query:hover { background: var(--sidebar); }
        .time-hint {
            font-size: 0.8rem; color: #6b7280; margin-top: 0.6rem;
        }
        .time-hint i { color: var(--primary); }

        /* ── 提示橫幅 ── */
        .apply-banner {
            background: #dce9ea; border: 1px solid #b0cdd3; border-radius: 12px;
            padding: 0.9rem 1.2rem;
            display: flex; align-items: center; justify-content: space-between;
            gap: 1rem; flex-wrap: wrap; margin-bottom: 1.2rem;
        }
        .apply-banner span { color: #1a4a4f; font-size: 0.92rem; }
        .btn-apply {
            background: #1e4d6b; color: white;
            border: none; border-radius: 8px;
            padding: 0.5rem 1.1rem;
            font-weight: 600; font-size: 0.88rem;
            text-decoration: none; white-space: nowrap;
            transition: background 0.2s;
        }
        .btn-apply:hover { background: #14394f; color: white; }

        /* ── 搜尋 ── */
        .search-wrap { position: relative; margin-bottom: 1.2rem; }
        .search-wrap input { padding-right: 2.5rem; border-radius: 10px; border: 1px solid #e5e7eb; }
        .search-wrap i { position: absolute; right: 12px; top: 50%; transform: translateY(-50%); color: #9ca3af; pointer-events: none; }

        /* ── 器材卡片 ── */
        .equipment-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(270px, 1fr));
            gap: 1.2rem;
        }
        .equipment-card {
            background: white; border: 1px solid #e5e7eb; border-radius: 14px;
            padding: 1.25rem;
            transition: box-shadow 0.25s ease, transform 0.15s ease;
            position: relative;
        }
        .equipment-card:hover { box-shadow: 0 6px 20px rgba(0,0,0,0.09); transform: translateY(-2px); }
        .eq-header { display: flex; align-items: center; gap: 0.9rem; margin-bottom: 0.85rem; }
        .eq-icon {
            width: 46px; height: 46px; border-radius: 12px;
            background: var(--primary); color: white;
            display: flex; align-items: center; justify-content: center;
            font-size: 0.9rem; font-weight: 700; flex-shrink: 0;
        }
        .eq-name { font-weight: 600; font-size: 1rem; margin: 0 0 0.15rem; }
        .eq-code { color: #9ca3af; font-size: 0.78rem; }
        .eq-desc { font-size: 0.85rem; color: #6b7280; margin-bottom: 0.8rem; min-height: 1.5rem; }
        .eq-meta { display: flex; gap: 0.6rem; flex-wrap: wrap; }
        .meta-tag {
            display: flex; align-items: center; gap: 0.25rem;
            background: #f3f4f6; border-radius: 6px;
            padding: 0.28rem 0.6rem; font-size: 0.82rem; color: #374151;
        }
        .meta-tag i { color: var(--primary); font-size: 0.85rem; }

        /* 可用數量徽章（時段查詢後出現） */
        .avail-badge {
            display: none;
            margin-top: 0.75rem;
            padding: 0.5rem 0.75rem;
            border-radius: 8px;
            font-size: 0.88rem;
            font-weight: 600;
            text-align: center;
        }
        .avail-badge.avail-ok   { background: #c8dfe0; color: #1a3f42; }
        .avail-badge.avail-low  { background: #f0e8c0; color: #6b5a20; }
        .avail-badge.avail-none { background: #deb8b9; color: #5c1f22; }

        /* ── 提示訊息配色 ── */
        .alert-success { background: #c8dfe0; border-color: #70a3a7; color: #1a3f42; }
        .alert-warning { background: #ede4e5; border-color: #deb8b9; color: #6b2d2d; }
        .alert-danger  { background: #deb8b9; border-color: #c9979a; color: #5c1f22; }
        .alert-info    { background: #ede4e5; border-color: #c8c0c2; color: #5a3f42; }

        /* loading 狀態 */
        .querying .avail-badge { display: block; background: #f3f4f6; color: #9ca3af; }

        @media (max-width: 1100px) {
            .equipment-grid { grid-template-columns: 1fr; }
            .main-content { margin-left: 0; }
            .time-picker-row { grid-template-columns: 1fr 1fr; }
            .time-picker-row .btn-query { grid-column: 1/-1; justify-content: center; }
        }
        @media (max-width: 768px) {
            .top-navbar { flex-direction: column; align-items: flex-start; gap: 1rem; padding: 1rem; }
            .sidebar { position: relative; width: 100%; height: auto; box-shadow: none; }
            .time-picker-row { grid-template-columns: 1fr; }
        }
    </style>
</head>
<body>
    <?php include(__DIR__ . "/../includes/sidebar.php"); ?>

    <main class="main-content">
        <header class="top-navbar">
            <div>
                <ol class="breadcrumb mb-0">
                    <li class="breadcrumb-item"><a href="dashboard.php">首頁</a></li>
                    <li class="breadcrumb-item active" aria-current="page">器材查詢</li>
                </ol>
                <h4 class="mt-2 mb-0">器材庫存查詢</h4>
            </div>
        </header>

        <section class="content-wrapper">

            <!-- 時段查詢 -->
            <div class="card">
                <div class="card-title">
                    <i class="bi bi-calendar-range"></i> 查詢時段可用庫存
                </div>
                <div class="time-picker-row">
                    <div>
                        <label>借用時間 <small style="color:#9ca3af;">(09:30–16:30)</small></label>
                        <input type="datetime-local" id="borrow_time" class="form-control">
                    </div>
                    <div>
                        <label>歸還時間 <small style="color:#9ca3af;">(09:30–16:30)</small></label>
                        <input type="datetime-local" id="return_time" class="form-control">
                    </div>
                    <button class="btn-query" onclick="queryAvailability()">
                        <i class="bi bi-search"></i> 查詢
                    </button>
                </div>
                <div class="time-hint mt-2">
                    <i class="bi bi-info-circle"></i>
                    選擇時段後點「查詢」，即可看到該時段各器材可借數量。
                    週三另設 <strong>17:00–19:00</strong> 進修部時段（須事先確認）。
                </div>
            </div>

            <!-- 借用提示 -->
            <div class="apply-banner">
                <span>
                    <i class="bi bi-lightbulb-fill me-1" style="color:#1e4d6b;"></i>
                    確認器材可用後，請至<strong>活動申請頁面</strong>同步填寫器材借用需求。
                </span>
                <a href="apply_event.php" class="btn-apply">
                    <i class="bi bi-send me-1"></i> 前往活動申請
                </a>
            </div>

            <!-- 器材清單 -->
            <div class="card">
                <div class="card-title">
                    <i class="bi bi-tools"></i> 器材清單
                </div>

                <div class="search-wrap">
                    <input type="text" id="searchEquipment" class="form-control" placeholder="搜尋器材名稱或編號…">
                    <i class="bi bi-search"></i>
                </div>

                <div class="equipment-grid" id="equipmentGrid">
                    <?php foreach ($equipment as $item): ?>
                    <div class="equipment-card"
                         data-equipment-id="<?= $item['equipment_id'] ?>"
                         data-name="<?= htmlspecialchars($item['name']) ?>"
                         data-code="<?= htmlspecialchars($item['code']) ?>"
                         data-total="<?= (int)$item['total_quantity'] ?>">

                        <div class="eq-header">
                            <div class="eq-icon"><?= htmlspecialchars($item['code']) ?></div>
                            <div>
                                <div class="eq-name"><?= htmlspecialchars($item['name']) ?></div>
                                <div class="eq-code">編號：<?= htmlspecialchars($item['code']) ?></div>
                            </div>
                        </div>

                        <div class="eq-desc">
                            <?= $item['description'] ? htmlspecialchars($item['description']) : '<span style="color:#d1d5db;">－</span>' ?>
                        </div>

                        <div class="eq-meta">
                            <span class="meta-tag">
                                <i class="bi bi-boxes"></i> 庫存總量：<?= (int)$item['total_quantity'] ?>
                            </span>
                            <span class="meta-tag">
                                <i class="bi bi-clipboard-check"></i>
                                <?php if ($item['borrowing_limit'] > 0): ?>
                                    每次建議上限：<?= (int)$item['borrowing_limit'] ?>
                                <?php else: ?>
                                    每次建議上限：不限
                                <?php endif; ?>
                            </span>
                        </div>

                        <!-- 時段可用量（查詢後顯示） -->
                        <div class="avail-badge" id="avail_<?= $item['equipment_id'] ?>">
                            查詢中…
                        </div>

                    </div>
                    <?php endforeach; ?>

                    <?php if (empty($equipment)): ?>
                    <div class="text-center text-muted py-4" style="grid-column:1/-1;">
                        <i class="bi bi-inbox fs-2 d-block mb-2"></i>目前沒有可查詢的器材
                    </div>
                    <?php endif; ?>
                </div>
            </div>

        </section>
    </main>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
    // ── 時段查詢 ──────────────────────────────────────────────
    async function queryAvailability() {
        const borrowTime = document.getElementById('borrow_time').value;
        const returnTime = document.getElementById('return_time').value;

        if (!borrowTime || !returnTime) {
            alert('請先選擇借用時間與歸還時間。');
            return;
        }
        if (borrowTime >= returnTime) {
            alert('歸還時間必須晚於借用時間。');
            return;
        }

        // 顯示 loading 狀態
        document.querySelectorAll('.avail-badge').forEach(b => {
            b.className = 'avail-badge avail-ok';
            b.style.display = 'block';
            b.textContent = '查詢中…';
        });

        try {
            const res = await fetch(
                `get_equipment_availability.php?borrow_time=${encodeURIComponent(borrowTime)}&return_time=${encodeURIComponent(returnTime)}`
            );
            const data = await res.json();

            document.querySelectorAll('.equipment-card').forEach(card => {
                const id    = card.dataset.equipmentId;
                const total = parseInt(card.dataset.total) || 0;
                const badge = document.getElementById('avail_' + id);
                if (!badge) return;

                const avail = (data[id] !== undefined) ? data[id] : total;

                badge.style.display = 'block';
                if (avail <= 0) {
                    badge.className = 'avail-badge avail-none';
                    badge.textContent = '此時段：已全部借出';
                } else if (avail <= 2) {
                    badge.className = 'avail-badge avail-low';
                    badge.textContent = `此時段：剩餘 ${avail} 件（快借完了）`;
                } else {
                    badge.className = 'avail-badge avail-ok';
                    badge.textContent = `此時段：可借 ${avail} 件`;
                }
            });
        } catch (e) {
            document.querySelectorAll('.avail-badge').forEach(b => {
                b.className = 'avail-badge avail-none';
                b.textContent = '查詢失敗，請重試';
            });
        }
    }

    // ── 時間限制 09:30–16:30 ──────────────────────────────────
    function clampEquipmentTime(inputId) {
        const input = document.getElementById(inputId);
        if (!input) return;
        function clamp() {
            if (!input.value) return;
            const dt = new Date(input.value);
            const totalMin = dt.getHours() * 60 + dt.getMinutes();
            if (totalMin < 9 * 60 + 30) dt.setHours(9, 30, 0, 0);
            else if (totalMin > 16 * 60 + 30) dt.setHours(16, 30, 0, 0);
            else return;
            const pad = n => String(n).padStart(2, '0');
            input.value = `${dt.getFullYear()}-${pad(dt.getMonth()+1)}-${pad(dt.getDate())}T${pad(dt.getHours())}:${pad(dt.getMinutes())}`;
        }
        input.addEventListener('change', clamp);
        input.addEventListener('blur', clamp);
    }
    clampEquipmentTime('borrow_time');
    clampEquipmentTime('return_time');

    // ── 搜尋器材 ─────────────────────────────────────────────
    document.getElementById('searchEquipment').addEventListener('input', function () {
        const keyword = this.value.toLowerCase();
        document.querySelectorAll('.equipment-card').forEach(card => {
            const name = card.dataset.name.toLowerCase();
            const code = card.dataset.code.toLowerCase();
            card.style.display = (name.includes(keyword) || code.includes(keyword)) ? '' : 'none';
        });
    });
    </script>
</body>
</html>
