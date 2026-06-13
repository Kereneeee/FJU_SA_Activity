<?php


require_once(__DIR__ . "/../DB/db_config.php");
require_once(__DIR__ . "/../includes/FieldCoordinationManager.php");

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'student') {
    header('Location: ../login.php');
    exit();
}

$fc_manager = new FieldCoordinationManager($conn);
$active_field_coordination_setting = $fc_manager->getActiveSettings();
$is_in_field_coordination_registration = $fc_manager->isInRegistrationPeriod();

$current_page = 'calendar';

$selected_year = intval($_GET['year'] ?? date('Y'));
$selected_month = intval($_GET['month'] ?? date('m'));

// 從場協登記紀錄連動跳轉至指定日期（用於直接查看衝突）
$target_date = '';
if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['date'] ?? '')) {
    $target_date = $_GET['date'];
}
// 載入整年資料，讓切換月份時每個月都能顯示正確的登記數量
$month_start = sprintf('%04d-01-01 00:00:00', $selected_year);
$month_end   = sprintf('%04d-12-31 23:59:59', $selected_year);

// 從 DB 動態建立場地分組
$buildings = [];
$_sp_res = $conn->query("SELECT space_id, space_name FROM spaces WHERE space_status='available' ORDER BY space_id");
if ($_sp_res) {
    $_bmap = [];
    $_blabels = ['A'=>'A焯炤館','B'=>'B進修部地下室','C'=>'C仁愛學苑','D'=>'D文開區域','E'=>'E / H 區域','H'=>'E / H 區域'];
    $_border  = ['A焯炤館','B進修部地下室','C仁愛學苑','D文開區域','E / H 區域'];
    while ($_sp = $_sp_res->fetch_assoc()) {
        $_pfx   = mb_substr($_sp['space_name'], 0, 1, 'UTF-8');
        $_bname = $_blabels[$_pfx] ?? $_pfx;
        $_bmap[$_bname][] = ['id' => (int)$_sp['space_id'], 'name' => $_sp['space_name']];
    }
    $_bid = 1;
    foreach ($_border as $_bname) {
        if (!empty($_bmap[$_bname])) $buildings[] = ['id' => $_bid++, 'name' => $_bname, 'rooms' => $_bmap[$_bname]];
    }
    foreach ($_bmap as $_bname => $_rooms) {
        if (!in_array($_bname, $_border)) $buildings[] = ['id' => $_bid++, 'name' => $_bname, 'rooms' => $_rooms];
    }
}

// 檢查是否有直接指定場地
$direct_space_id = intval($_GET['space_id'] ?? 0);
$selectedBuildingId = null;
$selectedRoomId = null;

if ($direct_space_id > 0) {
    // 根據 space_id 找到對應的建築和房間
    foreach ($buildings as $building) {
        foreach ($building['rooms'] as $room) {
            if ($room['id'] == $direct_space_id) {
                $selectedBuildingId = $building['id'];
                $selectedRoomId = $room['id'];
                break 2;
            }
        }
    }
}

$spaces = [];
foreach ($buildings as $building) {
    foreach ($building['rooms'] as $room) {
        $spaces[$room['id']] = $room;
    }
}

$timeSlots = [
    ['id' => '08_09', 'label' => '08:00 - 09:00'],
    ['id' => '09_10', 'label' => '09:00 - 10:00'],
    ['id' => '10_11', 'label' => '10:00 - 11:00'],
    ['id' => '11_12', 'label' => '11:00 - 12:00'],
    ['id' => '12_13_30', 'label' => '12:00 - 13:30'],
    ['id' => '13_40_14_30', 'label' => '13:40 - 14:30'],
    ['id' => '14_40_15_40', 'label' => '14:40 - 15:40'],
    ['id' => '15_50_16_50', 'label' => '15:50 - 16:50'],
    ['id' => '17_00_18_00', 'label' => '17:00 - 18:00'],
];

// 2. 撈取該月份內所有狀態為 'CHARGEABLE' 的特殊日期
$special_dates = [];
$sql_special = "SELECT title, date_type, start_date, end_date, is_full_day, start_time, end_time, status_type 
                FROM special_dates 
                WHERE status_type = 'CHARGEABLE'
                  AND (
                    (date_type = 'SINGLE' AND start_date BETWEEN ? AND ?)
                    OR
                    (date_type = 'RANGE' AND NOT (start_date > ? OR end_date < ?))
                  )";

$stmt_special = $conn->prepare($sql_special);
if ($stmt_special) {
    $pure_start = explode(' ', $month_start)[0];
    $pure_end = explode(' ', $month_end)[0];
    $stmt_special->bind_param('ssss', $pure_start, $pure_end, $pure_start, $pure_end);
    $stmt_special->execute();
    $result_special = $stmt_special->get_result();
    while ($row = $result_special->fetch_assoc()) {
        $special_dates[] = $row;
    }
    $stmt_special->close();
}

$special_dates_json = json_encode($special_dates);

$bookings = [];
$sql_bookings = "SELECT r.space_id, r.start_time, r.end_time, e.event_id, e.event_name, e.club_name, u.name AS user_name, u.email AS user_email, e.status, e.is_field_coordination, fcr.is_approved, fcr.registration_id, fcs.coordination_meeting_date,
       CASE WHEN fcr.is_approved IS NULL AND EXISTS (
            SELECT 1 FROM field_coordination_registrations fcr2
            JOIN events e2 ON fcr2.event_id = e2.event_id
            JOIN reservations r2 ON e2.event_id = r2.event_id
            WHERE fcr2.setting_id = fcr.setting_id
              AND fcr2.registration_id != fcr.registration_id
              AND r2.space_id = r.space_id
              AND r2.start_time < r.end_time
              AND r.start_time < r2.end_time
       ) THEN 1 ELSE 0 END AS has_conflict
    FROM reservations r
    JOIN events e ON r.event_id = e.event_id
    LEFT JOIN field_coordination_registrations fcr ON e.event_id = fcr.event_id
    LEFT JOIN field_coordination_settings fcs ON fcr.setting_id = fcs.setting_id
    LEFT JOIN users u ON e.user_id = u.user_id
    WHERE (r.start_time BETWEEN ? AND ?) OR (r.end_time BETWEEN ? AND ?)
    ORDER BY r.start_time ASC";
$stmt = $conn->prepare($sql_bookings);
if ($stmt) {
    $stmt->bind_param('ssss', $month_start, $month_end, $month_start, $month_end);
    $stmt->execute();
    $result_bookings = $stmt->get_result();
    while ($row = $result_bookings->fetch_assoc()) {
        $date = date('Y-m-d', strtotime($row['start_time']));
        $key = $row['space_id'] . '_' . $date;
        if (!isset($bookings[$key])) {
            $bookings[$key] = [];
        }

        $status = $row['status'];
        if (intval($row['is_field_coordination']) === 1) {
            if ($row['is_approved'] === '1' || $row['is_approved'] === 1) {
                $status = 'approved';
            } elseif ($row['is_approved'] === '0' || $row['is_approved'] === 0) {
                $status = 'rejected';
            } else {
                $meeting_passed = $row['coordination_meeting_date'] && strtotime($row['coordination_meeting_date']) < time();
                $status = $meeting_passed ? 'pending' : 'pending';
            }
        }

        $bookings[$key][] = [
            'start_time' => $row['start_time'],
            'end_time' => $row['end_time'],
            'event_name' => $row['event_name'],
            'club_name' => $row['club_name'],
            'organizer' => $row['user_name'],
            'user_email' => $row['user_email'],
            'status' => $status,
            'is_field_coordination' => intval($row['is_field_coordination']) === 1,
            'is_approved' => $row['is_approved'],
            'has_conflict' => intval($row['has_conflict']) === 1,
            'coordination_meeting_date' => $row['coordination_meeting_date'],
        ];
    }
    $stmt->close();
}
?>
<!DOCTYPE html>
<html lang="zh-TW">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>空間日曆 - 輔仁大學課外活動指導組</title>

    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.5/font/bootstrap-icons.css" rel="stylesheet">

    <style>
        :root {
            --primary: #1e4d6b;
            --sidebar: #14394f;
            --bg: #f7f5ef;
            --card: #ffffff;
            --success: #10b981;
            --warning: #f59e0b;
            --danger: #ef4444;
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
        }
        .sidebar .nav-link:hover,
        .sidebar .nav-link.active {
            background: #ece8dd;
            color: #1e4d6b;
            transform: translateX(4px);
        }
        .sidebar .nav-link i { font-size: 1.1rem; }
        .sidebar .sidebar-section { padding: 1rem 0.5rem; margin-top: 1.5rem; border-top: 1px solid rgba(255,255,255,0.12); }
        .main-content { margin-left: 260px; min-height: 100vh; transition: margin-left 0.25s ease; }
        .top-navbar { background: #d5e3ea; border-bottom: 1px solid #bdd0d9; padding: 1rem 2rem; display: flex; justify-content: space-between; align-items: center; position: sticky; top: 0; z-index: 1100; }
        .top-navbar .breadcrumb { margin: 0; background: transparent; padding: 0; }
        .top-navbar .breadcrumb { font-size: 0.8rem; }
        .top-navbar .breadcrumb-item + .breadcrumb-item::before { content: '›'; font-size: 1rem; color: #c9d0d8; }
        .top-navbar .breadcrumb-item a { color: #1e4d6b; text-decoration: none; opacity: 0.75; }
        .top-navbar .breadcrumb-item a:hover { opacity: 1; }
        .top-navbar .breadcrumb-item.active { color: #6b7280; }
        .content-wrapper { padding: 1.5rem 2rem 2rem; }
        .card { background: var(--card); border-radius: 18px; box-shadow: 0 10px 30px rgba(15,23,42,0.06); padding: 1.5rem; margin-bottom: 1.5rem; }
        .card h3 { margin-bottom: 1rem; font-weight: 700; color: var(--primary); display: flex; align-items: center; gap: 0.5rem; }
        .filter-row { display: grid; grid-template-columns: repeat(3, minmax(200px, 1fr)) auto; gap: 1rem; align-items: end; margin-bottom: 0; }
        .filter-row .form-label { font-weight: 600; color: #374151; margin-bottom: 0.4rem; }
        .query-card { padding: 1rem 1.5rem; }
        .query-card h3 { margin-bottom: 0.5rem; flex-wrap: wrap; }
        .query-card .text-muted { margin-bottom: 0.6rem; font-size: 0.85rem; }
        .query-card .alert-warning { padding: 0.6rem 1rem; margin-bottom: 0.75rem; font-size: 0.85rem; }
        .calendar-grid { display: grid; grid-template-columns: repeat(7, 1fr); gap: 8px; margin-bottom: 1rem; }
        .day-card { min-height: 98px; border: 2px solid #e5e7eb; border-radius: 14px; padding: 10px; background: white; cursor: pointer; transition: all 0.25s ease; display: flex; flex-direction: column; justify-content: space-between; }
        .day-card:hover { border-color: var(--primary); box-shadow: 0 4px 12px rgba(30,77,107,0.1); }
        .day-card.selected { border-color: var(--primary); background: rgba(30,77,107,0.08); }
        .day-card.other-month { opacity: 0.35; cursor: default; }
        .day-number { font-size: 1.1rem; font-weight: 700; }
        .day-status { font-size: 0.82rem; color: #374151; margin-top: 6px; }
        .day-status.free { color: var(--success); }
        .day-status.partial { color: var(--warning); }
        .day-status.full { color: var(--danger); }
        .schedule-panel { display: none; }
        .schedule-panel.active { display: block; }
        .slot-row { display: grid; grid-template-columns: 1fr 1fr auto; gap: 0.8rem; align-items: center; padding: 0.85rem; background: #f8fafc; border-radius: 10px; border: 1px solid #e5e7eb; margin-bottom: 0.8rem; }
        .slot-row.booked { background: #fef2f2; }
        .slot-label { font-weight: 600; color: #1f2937; }
        .slot-meta { display: flex; gap: 0.5rem; align-items: center; flex-wrap: wrap; }
        .badge-status { display: inline-flex; align-items: center; gap: 0.3rem; padding: 0.25rem 0.6rem; border-radius: 999px; font-size: 0.8rem; font-weight: 700; }
        .badge-status.confirmed, .badge-status.approved { background: #d1e7dd; color: #0f5132; }
        .badge-status.pending { background: #fff3cd; color: #664d03; }
        .badge-status.rejected { background: #f8d7da; color: #842029; }
        .btn-action { padding: 0.55rem 1rem; border: none; border-radius: 10px; cursor: pointer; font-weight: 700; transition: all 0.25s ease; }
        .btn-action.primary { background: var(--primary); color: white; }
        .btn-action.primary:hover { background: #14394f; }
        .btn-action.secondary { background: #e5e7eb; color: #1f2937; }
        .btn-action.secondary:hover { background: #d1d5db; }
        .btn-action.danger { background: var(--danger); color: white; }
        .booked-list { margin-top: 1rem; }
        .booking-table-wrap { overflow-x: auto; border-radius: 10px; border: 1px solid #e5e7eb; }
        .booking-table { width: 100%; min-width: 640px; border-collapse: collapse; font-size: 0.88rem; }
        .booking-table thead tr { background: #f3f4f6; }
        .booking-table th { padding: 0.65rem 0.9rem; text-align: left; font-weight: 700; color: #374151; border-bottom: 2px solid #e5e7eb; white-space: nowrap; }
        .booking-table td { padding: 0.6rem 0.9rem; border-bottom: 1px solid #f0f0f0; vertical-align: middle; color: #1f2937; }
        .booking-table tbody tr:last-child td { border-bottom: none; }
        .booking-table tbody tr:hover { background: #f9fafb; }
        .booking-table .col-time { white-space: nowrap; font-weight: 600; color: #1e40af; }
        .booking-table a { color: #1d6fa4; text-decoration: none; }
        .booking-table a:hover { text-decoration: underline; }
        .tag-fc { display: inline-block; font-size: .72rem; background: #dbeafe; color: #1e40af; padding: .1rem .4rem; border-radius: 4px; margin-left: .4rem; vertical-align: middle; }
        .tag-conflict { display: inline-block; font-size: .72rem; background: #fed7aa; color: #7c2d12; padding: .1rem .4rem; border-radius: 4px; margin-left: .4rem; vertical-align: middle; }
        .schedule-overlay { display: none; position: fixed; inset: 0; background: rgba(0,0,0,0.5); z-index: 2000; align-items: center; justify-content: center; }
        .schedule-overlay.show { display: flex; }
        .schedule-dialog { background: #fff; border-radius: 16px; padding: 2rem; box-shadow: 0 20px 60px rgba(0,0,0,0.3); }
        .schedule-dialog .modal-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 1.2rem; }
        .schedule-dialog .modal-title { margin: 0; font-size: 1.4rem; font-weight: 700; color: var(--primary); }
        .schedule-dialog .modal-close { background: none; border: none; font-size: 1.5rem; cursor: pointer; color: #6b7280; }
        @media (max-width: 1100px) { .main-content { margin-left: 0; } }
        @media (max-width: 768px) {
            .filter-row { grid-template-columns: 1fr; }
            .calendar-grid { grid-template-columns: repeat(2, 1fr); }
            .slot-row { grid-template-columns: 1fr; }
        }
    
        .holiday-red {
            color: #ff4d4f !important; /* 讓數字變紅 */
            font-weight: bold;         /* 稍微加粗讓學生更明顯辨識 */
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
        <?php
        $nav_breadcrumbs = [['label'=>'首頁','url'=>'dashboard.php'],['label'=>'空間日曆']];
        $nav_title = '空間日曆';
        include __DIR__ . '/../includes/student_navbar.php';
        ?>

        <section class="content-wrapper">
            <div class="card query-card">
                <h3 style="display:flex; align-items:baseline; gap:0.6rem; flex-wrap:wrap;">
                    <span><i class="bi bi-search"></i> 查詢教室</span>
                    <small class="text-muted" style="font-size:0.8rem; font-weight:400;">此行事曆包含場地申請與場協登記結果。若場協大會已過，已核准的場協結果會在此顯示為正式借用記錄。</small>
                </h3>
                <?php if ($is_in_field_coordination_registration && $active_field_coordination_setting): ?>
                <div class="alert alert-warning" style="border-radius: 12px;">
                    <strong>目前為場協登記期間</strong>，系統允許同一時段的場協登記衝突，但最終場地分配仍以協調結果為準。
                    登記期限：<?= date('Y-m-d', strtotime($active_field_coordination_setting['registration_start_date'])) ?> ～ <?= date('Y-m-d', strtotime($active_field_coordination_setting['registration_end_date'])) ?>
                </div>
                <?php endif; ?>
                <div class="filter-row">
                    <div>
                        <label class="form-label" for="buildingSelect">大樓</label>
                        <select id="buildingSelect" class="form-control">
                            <option value="">請選擇大樓</option>
                        </select>
                    </div>
                    <div>
                        <label class="form-label" for="roomSelect">教室</label>
                        <select id="roomSelect" class="form-control">
                            <option value="">請先選大樓</option>
                        </select>
                    </div>
                    <div>
                        <label class="form-label" for="monthSelector">月份</label>
                        <select id="monthSelector" class="form-control"></select>
                    </div>
                    <div>
                        <button id="searchButton" class="btn btn-primary" style="white-space:nowrap;">顯示選擇教室行事曆</button>
                    </div>
                </div>
            </div>

            <div id="calendarSection" class="card" style="display: none;">
                <h3><i class="bi bi-calendar2"></i> <span id="calendarTitle">請選擇教室與月份</span></h3>
                <div class="calendar-grid" id="calendarGrid"></div>
                <div style="display:flex; gap:1rem; flex-wrap:wrap; color:#6b7280; font-size:0.9rem; margin-top:0.5rem;">
                    <p><span>綠色=空閒</span>
                    <span>黃色=部分預約</span>
                    <span>紅色=滿額</span></p><br>
                    <p>><span class="holiday-red">紅字日期</span> = 週末或收費特殊日期</p>
                </div>
            </div>

        </section>

        <!-- 日期預約詳情 popup -->
        <div id="scheduleMdl" class="schedule-overlay" onclick="closeScheduleModal(event)">
            <div class="schedule-dialog" style="width:min(900px,95vw);max-height:85vh;display:flex;flex-direction:column;" onclick="event.stopPropagation()">
                <div class="modal-header" style="flex-shrink:0;">
                    <h5 class="modal-title" id="scheduleMdlTitle">預約時段</h5>
                    <button class="modal-close" onclick="closeScheduleModal()">&times;</button>
                </div>
                <div id="scheduleMdlBody" style="overflow-y:auto;overflow-x:auto;"></div>
            </div>
        </div>
    </main>

    <script>
       // 在 script 標籤最上方接收後端 JSON
        const specialDates = <?php echo $special_dates_json; ?>;
        const buildings = <?php echo json_encode($buildings); ?>;
        const timeSlots = <?php echo json_encode($timeSlots); ?>;
        const bookings = <?php echo json_encode($bookings); ?>;
        const initialYear = <?php echo $selected_year; ?>;
        const initialMonth = <?php echo $selected_month - 1; ?>;
        let targetDate = <?php echo json_encode($target_date); ?>;
        let selectedBuildingId = <?php echo $selectedBuildingId ? $selectedBuildingId : 'null'; ?>;
        let selectedRoomId = <?php echo $selectedRoomId ? $selectedRoomId : 'null'; ?>;
        let selectedDate = null;
        let selectedSlot = null;
        /**
         * 檢查特定日期是否屬於資料庫中的收費特殊日期
         * @param {string} dateStr - 格式為 'YYYY-MM-DD'
         * @returns {boolean}
         */
        function normalizeDateValue(dateStr) {
            return dateStr.trim().split(' ')[0];
        }

        function isChargeableDate(dateStr) {
            const currentStr = normalizeDateValue(dateStr);

            for (let config of specialDates) {
                if (config.date_type === 'SINGLE') {
                    if (normalizeDateValue(config.start_date) === currentStr) {
                        return true;
                    }
                } else if (config.date_type === 'RANGE') {
                    const target = new Date(`${currentStr}T00:00:00`).getTime();
                    const start = new Date(`${normalizeDateValue(config.start_date)}T00:00:00`).getTime();
                    const end = new Date(`${normalizeDateValue(config.end_date)}T23:59:59`).getTime();

                    if (target >= start && target <= end) {
                        if (parseInt(config.is_full_day, 10) === 0) {
                            continue;
                        }
                        return true;
                    }
                }
            }
            return false;
        }

        function buildCalendarUrl(roomId, month) {
            const monthValue = parseInt(month, 10);
            const monthNumber = monthValue + 1;
            let url = `calendar.php?year=${initialYear}&month=${monthNumber}`;
            if (roomId !== null) {
                url += `&space_id=${roomId}`;
            }
            return url;
        }
        
        function initPage() {
            const buildingSelect = document.getElementById('buildingSelect');
            const roomSelect = document.getElementById('roomSelect');
            const monthSelector = document.getElementById('monthSelector');
            const filterRow = document.querySelector('.filter-row');
            const searchButton = document.getElementById('searchButton');

            // 月份選單
            for (let i = 0; i < 12; i++) {
                const option = document.createElement('option');
                option.value = i;
                const date = new Date(initialYear, i, 1);
                option.textContent = `${date.getFullYear()}年${i + 1}月`;
                if (i === initialMonth) option.selected = true;
                monthSelector.appendChild(option);
            }

            // 大樓選單
            buildings.forEach(building => {
                const option = document.createElement('option');
                option.value = building.id;
                option.textContent = building.name;
                buildingSelect.appendChild(option);
            });

            // 預設選擇：若有指定場地（例如從場協登記結果連結進來）就帶入該大樓/教室，
            // 否則預設第一個大樓第一個教室；無論哪種情況都保留切換介面可操作
            if (selectedBuildingId === null) selectedBuildingId = buildings[0].id;
            if (selectedRoomId === null) selectedRoomId = buildings[0].rooms[0].id;

            fillRoomOptions(selectedBuildingId);
            buildingSelect.value = selectedBuildingId;
            roomSelect.value = selectedRoomId;

            filterRow.style.display = 'grid';
            searchButton.style.display = 'block';

            buildingSelect.addEventListener('change', () => {
                selectedBuildingId = buildingSelect.value ? parseInt(buildingSelect.value) : null;
                if (selectedBuildingId !== null) {
                    fillRoomOptions(selectedBuildingId);
                    selectedRoomId = roomSelect.value ? parseInt(roomSelect.value) : null;
                    renderCalendar();
                } else {
                    roomSelect.innerHTML = '<option value="">請先選大樓</option>';
                    selectedRoomId = null;
                    hideCalendar();
                }
            });

            roomSelect.addEventListener('change', () => {
                selectedRoomId = roomSelect.value ? parseInt(roomSelect.value) : null;
                if (selectedRoomId !== null) {
                    renderCalendar();
                } else {
                    hideCalendar();
                }
            });

            monthSelector.addEventListener('change', () => {
                if (selectedRoomId !== null) {
                    renderCalendar();
                }
            });

            document.getElementById('searchButton').addEventListener('click', () => {
                selectedRoomId = roomSelect.value ? parseInt(roomSelect.value) : null;
                selectedBuildingId = buildingSelect.value ? parseInt(buildingSelect.value) : null;
                if (selectedRoomId !== null) {
                    renderCalendar();
                }
            });

            renderCalendar();
        }

        function fillRoomOptions(buildingId) {
            const roomSelect = document.getElementById('roomSelect');
            roomSelect.innerHTML = '';
            const building = buildings.find(b => b.id === parseInt(buildingId));
            building.rooms.forEach(room => {
                const option = document.createElement('option');
                option.value = room.id;
                option.textContent = room.name;
                roomSelect.appendChild(option);
            });
        }

        function getRoomName(roomId) {
            for (const building of buildings) {
                const room = building.rooms.find(r => r.id === parseInt(roomId));
                if (room) return room.name;
            }
            return '未知教室';
        }

        function renderCalendar() {
            if (selectedRoomId === null) {
                hideCalendar();
                return;
            }
            const calendarSection = document.getElementById('calendarSection');
            const calendarGrid = document.getElementById('calendarGrid');
            const calendarTitle = document.getElementById('calendarTitle');
            const monthSelector = document.getElementById('monthSelector');
            const selectedRoomName = getRoomName(selectedRoomId);
            const monthValue = parseInt(monthSelector.value, 10);
            const monthLabel = `${initialYear}年${monthValue + 1}月`;

            calendarTitle.textContent = `${selectedRoomName} ${monthLabel}行事曆`;
            calendarSection.style.display = 'block';
            selectedDate = null;
            document.getElementById('scheduleMdl').classList.remove('show');

            const year = initialYear;
            const month = parseInt(monthSelector.value);
            const firstDay = new Date(year, month, 1);
            const startDay = new Date(firstDay);
            startDay.setDate(startDay.getDate() - firstDay.getDay());

            calendarGrid.innerHTML = '';
            let targetCellDate = null;
            for (let i = 0; i < 42; i++) {
                const date = new Date(startDay);
                date.setDate(startDay.getDate() + i);
                const dateStr = formatDateKey(date);
                const cell = document.createElement('div');
                cell.className = 'day-card';
                if (date.getMonth() !== month) cell.classList.add('other-month');
                if (targetDate && dateStr === targetDate && date.getMonth() === month) {
                    cell.classList.add('selected');
                    targetCellDate = new Date(date);
                }

                const dayNumber = document.createElement('div');
                dayNumber.className = 'day-number';
                dayNumber.textContent = date.getDate();
                const dayOfWeek = date.getDay(); // 0 是週日，6 是週六
                if (dayOfWeek === 0 || dayOfWeek === 6 || isChargeableDate(dateStr)) {
                    dayNumber.style.color = '#ff4d4f'; // 讓數字變紅色
                    dayNumber.style.fontWeight = 'bold';
                }
                cell.appendChild(dayNumber);

                const stats = document.createElement('div');
                stats.className = 'day-status';
                const count = getBookingCount(selectedRoomId, dateStr);
                const capacity = timeSlots.length;
                if (count === 0) {
                    stats.textContent = '尚未預約';
                    stats.classList.add('free');
                } else if (count < capacity) {
                    stats.textContent = `已預約 ${count} / ${capacity}`;
                    stats.classList.add('partial');
                } else {
                    stats.textContent = '時段已滿';
                    stats.classList.add('full');
                }
                cell.appendChild(stats);

                if (date.getMonth() === month) {
                    cell.addEventListener('click', () => selectDate(date));
                }
                calendarGrid.appendChild(cell);
            }

            if (targetCellDate) {
                selectDate(targetCellDate);
                targetDate = null; // 僅在初次載入時自動跳轉，切換月份後不再強制跳轉
                const cells = calendarGrid.querySelectorAll('.day-card.selected');
                if (cells.length) cells[0].scrollIntoView({ behavior: 'smooth', block: 'center' });
            }
        }

        function formatDateKey(date) {
            return `${date.getFullYear()}-${String(date.getMonth() + 1).padStart(2, '0')}-${String(date.getDate()).padStart(2, '0')}`;
        }

        function hideCalendar() {
            document.getElementById('calendarSection').style.display = 'none';
            document.getElementById('scheduleMdl').classList.remove('show');
        }

        // 9 個固定時段的起/止（分鐘），用於判斷某預約佔了幾個時段
        const SLOT_RANGES = [
            [8*60,      9*60     ],  // 08:00-09:00
            [9*60,      10*60    ],  // 09:00-10:00
            [10*60,     11*60    ],  // 10:00-11:00
            [11*60,     12*60    ],  // 11:00-12:00
            [12*60,     13*60+30 ],  // 12:00-13:30
            [13*60+40,  14*60+30 ],  // 13:40-14:30
            [14*60+40,  15*60+40 ],  // 14:40-15:40
            [15*60+50,  16*60+50 ],  // 15:50-16:50
            [17*60,     18*60    ],  // 17:00-18:00
        ];
        function dateTimeToMins(dtStr) {
            const d = new Date(dtStr);
            return d.getHours() * 60 + d.getMinutes();
        }
        function getBookingCount(roomId, dateStr) {
            const key = `${roomId}_${dateStr}`;
            const items = bookings[key];
            if (!items || items.length === 0) return 0;
            const occupied = new Set();
            items.forEach(item => {
                const rs = dateTimeToMins(item.start_time);
                const re = dateTimeToMins(item.end_time);
                SLOT_RANGES.forEach((slot, idx) => {
                    if (rs < slot[1] && re > slot[0]) occupied.add(idx);
                });
            });
            return occupied.size;
        }

        function getRoomBookings(roomId, dateStr) {
            const key = `${roomId}_${dateStr}`;
            return bookings[key] ? bookings[key] : [];
        }

        function selectDate(date) {
            selectedDate = date;
            const dateStr = formatDateKey(date);
            const selectedRoomName = getRoomName(selectedRoomId);
            document.getElementById('scheduleMdlTitle').textContent = `${selectedRoomName} ${dateStr} 預約時段`;
            renderSchedule(dateStr);
            document.getElementById('scheduleMdl').classList.add('show');
        }

        function closeScheduleModal(e) {
            if (e && e.target !== document.getElementById('scheduleMdl')) return;
            document.getElementById('scheduleMdl').classList.remove('show');
        }

        function renderSchedule(dateStr) {
            const body = document.getElementById('scheduleMdlBody');
            const roomBookings = getRoomBookings(selectedRoomId, dateStr);

            body.innerHTML = '';

            if (roomBookings.length === 0) {
                body.innerHTML = '<p class="text-muted" style="padding:1rem 0;">該日期尚未有場地登記，代表目前可用。</p>';
                return;
            }

            const statusMap = { approved: '已核准', pending: '待審核', rejected: '已駁回' };
            const statusClass = { approved: 'approved', pending: 'pending', rejected: 'rejected' };

            let rows = '';
            roomBookings.forEach(item => {
                const timeStr = `${new Date(item.start_time).toLocaleTimeString('zh-TW',{hour:'2-digit',minute:'2-digit'})} - ${new Date(item.end_time).toLocaleTimeString('zh-TW',{hour:'2-digit',minute:'2-digit'})}`;
                const statusLabel = statusMap[item.status] || item.status;
                const fcTag = item.is_field_coordination && item.status === 'pending'
                    ? '<span class="tag-fc">場協待確認</span>' : '';
                const conflictTag = item.has_conflict && item.is_field_coordination
                    ? '<span class="tag-conflict"><i class="bi bi-exclamation-triangle"></i> 有衝突</span>' : '';
                rows += `
                <tr>
                    <td class="col-time">${timeStr}</td>
                    <td>${item.event_name}${fcTag}${conflictTag}</td>
                    <td>${item.club_name}</td>
                    <td>${item.organizer || '—'}</td>
                    <td>${item.user_email ? `<a href="mailto:${item.user_email}">${item.user_email}</a>` : '—'}</td>
                    <td><span class="badge-status ${statusClass[item.status] || ''}">${statusLabel}</span></td>
                </tr>`;
            });

            body.innerHTML = `
                <div style="display:flex; align-items:center; justify-content:space-between; margin-bottom:0.6rem;">
                    <span style="font-weight:600; color:#374151;">共 ${roomBookings.length} 筆登記</span>
                </div>
                <div class="booking-table-wrap">
                    <table class="booking-table">
                        <thead>
                            <tr>
                                <th>時間</th>
                                <th>目的</th>
                                <th>社團</th>
                                <th>申請人</th>
                                <th>聯絡信箱</th>
                                <th>核准狀態</th>
                            </tr>
                        </thead>
                        <tbody>${rows}</tbody>
                    </table>
                </div>`;
        }

        window.addEventListener('DOMContentLoaded', () => {
            initPage();
        });
        document.addEventListener('keydown', e => {
            if (e.key === 'Escape') document.getElementById('scheduleMdl').classList.remove('show');
        });
    </script>
</body>
</html>
