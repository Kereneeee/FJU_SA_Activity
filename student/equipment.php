<?php
ini_set('display_errors', 1);
error_reporting(E_ALL);

require_once(__DIR__ . "/../DB/db_config.php");

if (!isset($_SESSION['student_id'])) {
    header('Location: ../login.php');
    exit();
}

$message = "";
$message_type = "";

// 取得器材資料
$sql = "SELECT * FROM equipment WHERE status = 'available'";
$result_equipment = $conn->query($sql);

$equipment = [];

if ($result_equipment) {
    $equipment_list = $result_equipment->fetch_all(MYSQLI_ASSOC);

    foreach ($equipment_list as $eq) {
        $equipment[] = [
            'equipment_id' => $eq['equipment_id'],
            'name' => $eq['name'],
            'description' => $eq['description'],
            'borrowing_limit' => $eq['borrowing_limit'],
            'total_quantity' => $eq['total_quantity'],
            'available_quantity' => $eq['total_quantity'],
            'code' => $eq['code']
        ];
    }
}

// 設置當前頁面用於側邊欄高亮
$current_page = 'equipment';
?>

<!DOCTYPE html>
<html lang="zh-TW">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>器材狀態 - 輔仁大學課外活動指導組</title>

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
        .top-navbar .breadcrumb {
            margin: 0;
            background: transparent;
            padding: 0;
        }
        .top-navbar .breadcrumb { font-size: 0.8rem; }
        .top-navbar .breadcrumb-item + .breadcrumb-item::before { content: '›'; font-size: 1rem; color: #c9d0d8; }
        .top-navbar .breadcrumb-item a { color: #1e4d6b; text-decoration: none; opacity: 0.75; }
        .top-navbar .breadcrumb-item a:hover { opacity: 1; }
        .top-navbar .breadcrumb-item.active { color: #6b7280; }
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
        .equipment-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
            gap: 1.5rem;
        }
        .equipment-card {
            background: white;
            border: 1px solid #e5e7eb;
            border-radius: 12px;
            padding: 1.5rem;
            transition: box-shadow 0.25s ease, transform 0.15s ease;
        }
        .equipment-card:hover {
            box-shadow: 0 8px 25px rgba(0,0,0,0.1);
            transform: translateY(-2px);
        }
        .equipment-header {
            display: flex;
            align-items: center;
            gap: 1rem;
            margin-bottom: 1rem;
        }
        .equipment-icon {
            width: 50px;
            height: 50px;
            border-radius: 12px;
            background: var(--primary);
            color: white;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.5rem;
            flex-shrink: 0; 
        }
        .equipment-info h4 {
            margin: 0 0 0.25rem;
            font-weight: 600;
        }
        .equipment-info .status {
            color: #6b7280;
            font-size: 0.9rem;
        }
        .equipment-details {
            margin-bottom: 1rem;
        }
        .equipment-details p {
            margin: 0.25rem 0;
            color: #6b7280;
        }
        .quantity-controls {
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-top: 1rem;
        }
        .counter {
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }
        .counter button {
            width: 32px;
            height: 32px;
            border: 1px solid #d1d5db;
            background: white;
            border-radius: 6px;
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
        }
        .counter button:hover {
            background: #f9fafb;
        }
        .counter input {
            width: 60px;
            text-align: center;
            border: 1px solid #d1d5db;
            border-radius: 6px;
            padding: 0.25rem;
        }
        .btn-submit {
            background: var(--primary);
            color: white;
            border: none;
            padding: 0.75rem 2rem;
            border-radius: 8px;
            font-weight: 600;
            cursor: pointer;
            transition: background 0.25s ease;
            width: 100%;
        }
        .btn-submit:hover {
            background: var(--sidebar);
        }
        @media (max-width: 1100px) {
            .equipment-grid { grid-template-columns: 1fr; }
            .main-content { margin-left: 0; }
        }
        @media (max-width: 768px) {
        .top-navbar {
                flex-direction: column;
                align-items: flex-start;
                gap: 1rem;
                padding: 1rem;
            }
            .sidebar {
                position: relative;
                width: 100%;
                height: auto;
                box-shadow: none;
            }
        }
    
        /* 提示訊息配色 */
        .alert-success { background: #c8dfe0; border-color: #70a3a7; color: #1a3f42; }
        .alert-warning { background: #ede4e5; border-color: #deb8b9; color: #6b2d2d; }
        .alert-danger  { background: #deb8b9; border-color: #c9979a; color: #5c1f22; }
        .alert-info    { background: #ede4e5; border-color: #c8c0c2; color: #5a3f42; }
    </style>
</head>

<body>
    <?php include(__DIR__ . "/../includes/sidebar.php"); ?>

    <main class="main-content">
        <header class="top-navbar">
            <div>
                <ol class="breadcrumb mb-0">
                    <li class="breadcrumb-item"><a href="dashboard.php">首頁</a></li>
                    <li class="breadcrumb-item active" aria-current="page">器材狀態</li>
                </ol>
                <h4 class="mt-2 mb-0">器材狀態</h4>
            </div>
        </header>

        <section class="content-wrapper">
            <div class="card">
                <h3>可借用器材</h3>
            <div class="mb-4 position-relative">
                <input 
                    type="text"
                    id="searchEquipment"
                    class="form-control pe-5"
                    placeholder="搜尋器材編號或名稱..."
                >
                <i class="bi bi-search position-absolute top-50 end-0 translate-middle-y me-3 text-muted"></i>
            </div>
            <div class="row mb-4">
                <div class="col-md-6">
                    <label class="form-label">借用時間</label>
                    <input type="datetime-local" id="borrow_time" class="form-control">
                </div>

                <div class="col-md-6">
                    <label class="form-label">歸還時間</label>
                    <input type="datetime-local" id="return_time" class="form-control">
                </div>
            </div>
                <form id="equipmentForm">
                    <div class="equipment-grid">
                    <?php foreach ($equipment as $item): ?>
                        <div class="equipment-card"
                            data-equipment-id="<?= $item['equipment_id'] ?>"
                            data-name="<?= htmlspecialchars($item['name']) ?>"
                            data-code="<?= htmlspecialchars($item['code']) ?>">
                            <div class="equipment-header">
                                <div class="equipment-icon">
                                    <?= htmlspecialchars($item['code']) ?>
                                </div>
                                <div class="equipment-info">
                                    <h4><?= htmlspecialchars($item['name']) ?></h4>
                                    <div class="status">
                                        剩餘:
                                        <span class="available-qty">
                                            <?= $item['total_quantity'] ?>
                                        </span>
                                    </div>
                                </div>
                            </div>

                            <div class="equipment-details">
                                <p><?= htmlspecialchars($item['description']) ?></p>
                                <p>上限: <?= $item['borrowing_limit'] > 0 ? $item['borrowing_limit'] : '不限' ?></p>
                            </div>

                            <?php
                            $maxBorrow = ($item['borrowing_limit'] > 0)
                                ? min($item['available_quantity'], $item['borrowing_limit'])
                                : $item['available_quantity'];
                            ?>

                            
                        </div>
                        <?php endforeach; ?>
                    </div>

                </form>
            </div>
        </section>
    </main>
<script>
async function updateAvailability() {

    const borrowTime =
        document.getElementById('borrow_time').value;

    const returnTime =
        document.getElementById('return_time').value;

    if (!borrowTime || !returnTime) {
        return;
    }

    const response = await fetch(
        `get_equipment_availability.php?borrow_time=${borrowTime}&return_time=${returnTime}`
    );

    const data = await response.json();

    document.querySelectorAll('.equipment-card')
        .forEach(card => {

        const equipmentId =
            card.dataset.equipmentId;

        const qtySpan =
            card.querySelector('.available-qty');

        if (data[equipmentId] !== undefined) {
            qtySpan.textContent = data[equipmentId];
        }
    });
}

document.getElementById('borrow_time')
    .addEventListener('change', updateAvailability);

document.getElementById('return_time')
    .addEventListener('change', updateAvailability);
// 搜尋器材
document.getElementById('searchEquipment')
    .addEventListener('input', function () {

    const keyword =
        this.value.toLowerCase();

    document.querySelectorAll('.equipment-card')
        .forEach(card => {

        const name =
            card.dataset.name.toLowerCase();

        const code =
            card.dataset.code.toLowerCase();

        const match =
            name.includes(keyword) ||
            code.includes(keyword);

        card.style.display =
            match ? '' : 'none';
    });
});
</script>
</body>
</html>
