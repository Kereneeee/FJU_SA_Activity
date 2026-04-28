<?php
ini_set('display_errors', 1);
error_reporting(E_ALL);

session_start();
require_once(__DIR__ . "/../DB/db_config.php");

if (!isset($_SESSION['student_id'])) {
    header('Location: ../login.php');
    exit();
}

// 設置當前頁面用於側邊欄高亮
$current_page = 'equipment';
?>

<!DOCTYPE html>
<html lang="zh-TW">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>器材借用 - 輔仁大學課外活動指導組</title>

    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.5/font/bootstrap-icons.css" rel="stylesheet">

    <style>
        :root {
            --primary: #8B1538;
            --sidebar: #4c0f2a;
            --sidebar-hover: #6a1d43;
            --bg: #f4f6fb;
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
            background: linear-gradient(180deg, var(--primary), var(--sidebar));
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
            background: rgba(255,255,255,0.12);
            color: #ffffff;
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
            background: white;
            border-bottom: 1px solid #e9ecef;
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
    </style>
</head>
<body>
    <?php include(__DIR__ . "/../includes/sidebar.php"); ?>

    <main class="main-content">
        <header class="top-navbar">
            <div>
                <ol class="breadcrumb mb-0">
                    <li class="breadcrumb-item"><a href="dashboard.php">首頁</a></li>
                    <li class="breadcrumb-item active" aria-current="page">器材借用</li>
                </ol>
                <h4 class="mt-2 mb-0">器材借用</h4>
            </div>
        </header>

        <section class="content-wrapper">
            <div class="card">
                <h3>可借用器材</h3>
                <p class="text-muted">請選擇您需要的器材及數量，系統將自動計算可用庫存。</p>

                <form id="equipmentForm">
                    <div class="equipment-grid">
                        <div class="equipment-card">
                            <div class="equipment-header">
                                <div class="equipment-icon">
                                    <i class="bi bi-mic"></i>
                                </div>
                                <div class="equipment-info">
                                    <h4>麥克風</h4>
                                    <div class="status">剩餘: 8 個</div>
                                </div>
                            </div>
                            <div class="equipment-details">
                                <p><strong>規格:</strong> 無線手持麥克風</p>
                                <p><strong>借用期間:</strong> 每次借用最多7天</p>
                                <p><strong>注意事項:</strong> 需歸還電池</p>
                            </div>
                            <div class="quantity-controls">
                                <span>借用數量:</span>
                                <div class="counter">
                                    <button type="button" onclick="changeQty('mic', -1)">-</button>
                                    <input id="qty_mic" name="qty_mic" value="0" readonly>
                                    <button type="button" onclick="changeQty('mic', 1)">+</button>
                                </div>
                            </div>
                        </div>

                        <div class="equipment-card">
                            <div class="equipment-header">
                                <div class="equipment-icon">
                                    <i class="bi bi-projector"></i>
                                </div>
                                <div class="equipment-info">
                                    <h4>投影機</h4>
                                    <div class="status">剩餘: 3 台</div>
                                </div>
                            </div>
                            <div class="equipment-details">
                                <p><strong>規格:</strong> 3000流明 LED投影機</p>
                                <p><strong>配件:</strong> 含遙控器、電源線</p>
                                <p><strong>注意事項:</strong> 需專業人員安裝</p>
                            </div>
                            <div class="quantity-controls">
                                <span>借用數量:</span>
                                <div class="counter">
                                    <button type="button" onclick="changeQty('projector', -1)">-</button>
                                    <input id="qty_projector" name="qty_projector" value="0" readonly>
                                    <button type="button" onclick="changeQty('projector', 1)">+</button>
                                </div>
                            </div>
                        </div>

                        <div class="equipment-card">
                            <div class="equipment-header">
                                <div class="equipment-icon">
                                    <i class="bi bi-speaker"></i>
                                </div>
                                <div class="equipment-info">
                                    <h4>音響設備</h4>
                                    <div class="status">剩餘: 2 組</div>
                                </div>
                            </div>
                            <div class="equipment-details">
                                <p><strong>規格:</strong> 2.1聲道音響系統</p>
                                <p><strong>配件:</strong> 含音源線、電源線</p>
                                <p><strong>注意事項:</strong> 音量請勿超過80%</p>
                            </div>
                            <div class="quantity-controls">
                                <span>借用數量:</span>
                                <div class="counter">
                                    <button type="button" onclick="changeQty('speaker', -1)">-</button>
                                    <input id="qty_speaker" name="qty_speaker" value="0" readonly>
                                    <button type="button" onclick="changeQty('speaker', 1)">+</button>
                                </div>
                            </div>
                        </div>

                        <div class="equipment-card">
                            <div class="equipment-header">
                                <div class="equipment-icon">
                                    <i class="bi bi-table"></i>
                                </div>
                                <div class="equipment-info">
                                    <h4>桌椅</h4>
                                    <div class="status">剩餘: 50 組</div>
                                </div>
                            </div>
                            <div class="equipment-details">
                                <p><strong>規格:</strong> 折疊桌椅一組</p>
                                <p><strong>數量:</strong> 每組含1桌4椅</p>
                                <p><strong>注意事項:</strong> 請小心搬運</p>
                            </div>
                            <div class="quantity-controls">
                                <span>借用數量:</span>
                                <div class="counter">
                                    <button type="button" onclick="changeQty('table_chair', -1)">-</button>
                                    <input id="qty_table_chair" name="qty_table_chair" value="0" readonly>
                                    <button type="button" onclick="changeQty('table_chair', 1)">+</button>
                                </div>
                            </div>
                        </div>

                        <div class="equipment-card">
                            <div class="equipment-header">
                                <div class="equipment-icon">
                                    <i class="bi bi-plug"></i>
                                </div>
                                <div class="equipment-info">
                                    <h4>延長線</h4>
                                    <div class="status">剩餘: 15 條</div>
                                </div>
                            </div>
                            <div class="equipment-details">
                                <p><strong>規格:</strong> 5米延長線，4孔</p>
                                <p><strong>額定電流:</strong> 10A</p>
                                <p><strong>注意事項:</strong> 請勿超載使用</p>
                            </div>
                            <div class="quantity-controls">
                                <span>借用數量:</span>
                                <div class="counter">
                                    <button type="button" onclick="changeQty('extension', -1)">-</button>
                                    <input id="qty_extension" name="qty_extension" value="0" readonly>
                                    <button type="button" onclick="changeQty('extension', 1)">+</button>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div style="margin-top: 2rem; text-align: center;">
                        <button type="submit" class="btn-submit">提交借用申請</button>
                    </div>
                </form>
            </div>
        </section>
    </main>

    <script>
        // 器材數量控制
        const stocks = {
            mic: 8,
            projector: 3,
            speaker: 2,
            table_chair: 50,
            extension: 15
        };

        function changeQty(item, delta) {
            const input = document.getElementById("qty_" + item);
            let value = parseInt(input.value) + delta;

            const max = stocks[item];

            if (value < 0) value = 0;
            if (value > max) value = max;

            input.value = value;
        }

        // 表單提交處理
        document.getElementById('equipmentForm').addEventListener('submit', function(e) {
            e.preventDefault();

            // 檢查是否至少選擇了一項器材
            const inputs = document.querySelectorAll('input[name^="qty_"]');
            let hasSelection = false;

            inputs.forEach(input => {
                if (parseInt(input.value) > 0) {
                    hasSelection = true;
                }
            });

            if (!hasSelection) {
                alert('請至少選擇一件器材！');
                return;
            }

            // 這裡可以添加實際的提交邏輯
            alert('器材借用申請已提交！');
        });
    </script>
</body>
</html>