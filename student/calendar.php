<?php


require_once(__DIR__ . "/../DB/db_config.php");
require_once(__DIR__ . "/../includes/FieldCoordinationManager.php");

if (!isset($_SESSION['student_id'])) {
    header('Location: ../login.php');
    exit();
}

$fc_manager = new FieldCoordinationManager($conn);
$active_field_coordination_setting = $fc_manager->getActiveSettings();
$is_in_field_coordination_registration = $fc_manager->isInRegistrationPeriod();

$current_page = 'calendar';

$selected_year = intval($_GET['year'] ?? date('Y'));
$selected_month = intval($_GET['month'] ?? date('m'));
$month_start = sprintf('%04d-%02d-01 00:00:00', $selected_year, $selected_month);
$month_end = date('Y-m-t 23:59:59', strtotime($month_start));

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
$sql_bookings = "SELECT r.space_id, r.start_time, r.end_time, e.event_name, e.club_name, u.name AS user_name, u.email AS user_email, e.status, e.is_field_coordination, fcr.is_approved, fcs.coordination_meeting_date
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
            overflow-y: auto;
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
        .filter-row { display: grid; grid-template-columns: repeat(3, minmax(200px, 1fr)); gap: 1rem; align-items: end; margin-bottom: 1rem; }
        .filter-row .form-label { font-weight: 600; color: #374151; margin-bottom: 0.4rem; }
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
        .booked-card { display: grid; grid-template-columns: 1fr auto; gap: 0.8rem; background: #ffffff; border: 1px solid #e5e7eb; border-radius: 12px; padding: 1rem; margin-bottom: 0.8rem; }
        .booked-card .booked-left { display: grid; gap: 0.3rem; }
        .booked-label { font-size: 0.9rem; color: #6b7280; }
        .modal-backdrop { display: none; position: fixed; inset: 0; background: rgba(0,0,0,0.5); z-index: 2000; align-items: center; justify-content: center; }
        .modal-backdrop.show { display: flex; }
        .modal-dialog { background: white; border-radius: 16px; padding: 2rem; width: min(520px, 90%); box-shadow: 0 20px 60px rgba(0,0,0,0.3); }
        .modal-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 1.2rem; }
        .modal-title { margin: 0; font-size: 1.4rem; font-weight: 700; color: var(--primary); }
        .modal-close { background: none; border: none; font-size: 1.5rem; cursor: pointer; color: #6b7280; }
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
        <header class="top-navbar">
            <div>
                <ol class="breadcrumb mb-0">
                    <li class="breadcrumb-item"><a href="dashboard.php">首頁</a></li>
                    <li class="breadcrumb-item active" aria-current="page">空間日曆</li>
                </ol>
                <h4 class="mt-2 mb-0">空間日曆</h4>
            </div>
        </header>

        <section class="content-wrapper">
            <div class="card">
                <h3><i class="bi bi-search"></i> 查詢教室</h3>
                <p class="text-muted">此行事曆包含場地申請與場協登記結果。若場協大會已過，已核准的場協結果會在此顯示為正式借用記錄。</p>
                <?php if ($is_in_field_coordination_registration && $active_field_coordination_setting): ?>
                <div class="alert alert-warning" style="border-radius: 12px;">
                    <strong>目前為場協登記期間</strong>，系統允許同一時段的場協登記衝突，但最終場地分配仍以協調結果為準。
                    <br>登記期限：<?= date('Y-m-d', strtotime($active_field_coordination_setting['registration_start_date'])) ?> ～ <?= date('Y-m-d', strtotime($active_field_coordination_setting['registration_end_date'])) ?>
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
                </div>
                <button id="searchButton" class="btn btn-primary" style="margin-top: 0.5rem;">顯示選擇教室行事曆</button>
            </div>

            <div id="calendarSection" class="card" style="display: none;">
                <h3><i class="bi bi-calendar2"></i> <span id="calendarTitle">教室月行事曆</span></h3>
                <div class="calendar-grid" id="calendarGrid"></div>
                <div style="display:flex; gap:1rem; flex-wrap:wrap; color:#6b7280; font-size:0.9rem; margin-top:0.5rem;">
                    <p><span>綠色=空閒</span>
                    <span>黃色=部分預約</span>
                    <span>紅色=滿額</span></p><br>
                    <p>><span class="holiday-red">紅字日期</span> = 週末或收費特殊日期</p>
                </div>
            </div>

            <div id="scheduleSection" class="card schedule-panel">
                <h3><i class="bi bi-clock"></i> <span id="scheduleTitle">請先選擇日期</span></h3>
                <div id="slotList"></div>
                <div id="bookingDetails" class="booked-list"></div>
            </div>
        </section>
    </main>

    <script>
       // 在 script 標籤最上方接收後端 JSON
        const specialDates = <?php echo $special_dates_json; ?>;
        const buildings = <?php echo json_encode($buildings); ?>;
        const timeSlots = <?php echo json_encode($timeSlots); ?>;
        const bookings = <?php echo json_encode($bookings); ?>;
        const initialYear = <?php echo $selected_year; ?>;
        const initialMonth = <?php echo $selected_month - 1; ?>;

        console.log("從資料庫接到的特殊日期：", specialDates);
        
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

            // 如果有直接指定場地，隱藏選擇介面
            if (selectedRoomId !== null) {
                filterRow.style.display = 'none';
                searchButton.style.display = 'none';
                renderCalendar();
                return;
            }

            // 顯示選擇介面
            filterRow.style.display = 'grid';
            searchButton.style.display = 'block';

            buildings.forEach(building => {
                const option = document.createElement('option');
                option.value = building.id;
                option.textContent = building.name;
                buildingSelect.appendChild(option);
            });

            fillRoomOptions(buildings[0].id);
            buildingSelect.value = buildings[0].id;
            selectedBuildingId = buildings[0].id;
            selectedRoomId = buildings[0].rooms[0].id;

            buildingSelect.addEventListener('change', () => {
                selectedBuildingId = buildingSelect.value ? parseInt(buildingSelect.value) : null;
                if (selectedBuildingId !== null) {
                    fillRoomOptions(selectedBuildingId);
                } else {
                    roomSelect.innerHTML = '<option value="">請先選大樓</option>';
                }
                selectedRoomId = null;
                hideCalendar();
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

            const today = new Date();
            for (let i = 0; i < 12; i++) {
                const option = document.createElement('option');
                option.value = i;
                // 這裡年份改用後端帶過來的 initialYear 比較精準
                const date = new Date(initialYear, i, 1); 
                option.textContent = `${date.getFullYear()}年${i + 1}月`;
                
                // 👉 【修正這行】改用 initialMonth 判斷，這樣切換月份才不會跳走
                if (i === initialMonth) option.selected = true; 
                
                monthSelector.appendChild(option);
            }

            document.getElementById('searchButton').addEventListener('click', () => {
                selectedRoomId = roomSelect.value ? parseInt(roomSelect.value) : null;
                selectedBuildingId = buildingSelect.value ? parseInt(buildingSelect.value) : null;
                if (selectedRoomId !== null) {
                    renderCalendar();
                }
            });

            hideCalendar();
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
                if (room) return `${building.name} ${room.name}`;
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

            calendarTitle.textContent = `${selectedRoomName} 月行事曆`;
            calendarSection.style.display = 'block';
            document.getElementById('scheduleSection').classList.add('active');
            selectedDate = null;
            document.getElementById('scheduleTitle').textContent = '請選擇日期查看時段';
            document.getElementById('slotList').innerHTML = '';
            document.getElementById('bookingDetails').innerHTML = '';

            const year = new Date().getFullYear();
            const month = parseInt(monthSelector.value);
            const firstDay = new Date(year, month, 1);
            const startDay = new Date(firstDay);
            startDay.setDate(startDay.getDate() - firstDay.getDay());

            calendarGrid.innerHTML = '';
            for (let i = 0; i < 42; i++) {
                const date = new Date(startDay);
                date.setDate(startDay.getDate() + i);
                const dateStr = formatDateKey(date);
                const cell = document.createElement('div');
                cell.className = 'day-card';
                if (date.getMonth() !== month) cell.classList.add('other-month');

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
        }

        function formatDateKey(date) {
            return `${date.getFullYear()}-${String(date.getMonth() + 1).padStart(2, '0')}-${String(date.getDate()).padStart(2, '0')}`;
        }

        function hideCalendar() {
            document.getElementById('calendarSection').style.display = 'none';
            document.getElementById('scheduleSection').classList.remove('active');
            document.getElementById('scheduleTitle').textContent = '請先選擇教室查看行事曆';
            document.getElementById('slotList').innerHTML = '';
            document.getElementById('bookingDetails').innerHTML = '';
        }

        function getBookingCount(roomId, dateStr) {
            const key = `${roomId}_${dateStr}`;
            return bookings[key] ? bookings[key].length : 0;
        }

        function getRoomBookings(roomId, dateStr) {
            const key = `${roomId}_${dateStr}`;
            return bookings[key] ? bookings[key] : [];
        }

        function selectDate(date) {
            selectedDate = date;
            const dateStr = formatDateKey(date);
            const selectedRoomName = getRoomName(selectedRoomId);
            document.getElementById('scheduleTitle').textContent = `${selectedRoomName} ${dateStr} 預約時段`;
            renderSchedule(dateStr);
        }

        function renderSchedule(dateStr) {
            const slotList = document.getElementById('slotList');
            const bookingDetails = document.getElementById('bookingDetails');
            const roomBookings = getRoomBookings(selectedRoomId, dateStr);

            slotList.innerHTML = '';
            bookingDetails.innerHTML = '';

            const summary = document.createElement('div');
            summary.className = 'slot-row';
            summary.innerHTML = `<div class="slot-label">今日共 ${roomBookings.length} 筆登記</div>`;
            slotList.appendChild(summary);

            if (roomBookings.length === 0) {
                bookingDetails.innerHTML = '<p class="text-muted">該日期尚未有場地登記，代表目前可用。</p>';
                return;
            }

            bookingDetails.innerHTML = '<h4 style="margin-bottom:0.8rem; color:#374151;">當日登記清單</h4>';
            roomBookings.forEach(item => {
                const card = document.createElement('div');
                card.className = 'booked-card';
                let statusLabel = '待審核';
                if (item.status === 'approved') {
                    statusLabel = '已核准';
                } else if (item.status === 'rejected') {
                    statusLabel = '已駁回';
                }
                const fcTag = item.is_field_coordination && item.status === 'pending'
                    ? '<span style="font-size:.72rem;background:#dbeafe;color:#1e40af;padding:.1rem .4rem;border-radius:4px;margin-left:.4rem;">場協待確認</span>' : '';
                card.innerHTML = `
                    <div class="booked-left">
                        <div class="booking-time" style="font-weight:700;">${new Date(item.start_time).toLocaleTimeString('zh-TW', { hour:'2-digit', minute:'2-digit' })} - ${new Date(item.end_time).toLocaleTimeString('zh-TW', { hour:'2-digit', minute:'2-digit' })}</div>
                        <div class="booking-title">${item.event_name}${fcTag}</div>
                        <div class="booking-club">社團：${item.club_name}</div>
                        <div class="booking-organizer">申請人：${item.organizer}</div>
                        <div class="booking-email">聯絡信箱：${item.user_email ? item.user_email : '無'}</div>
                    </div>
                    <div style="display:flex; flex-direction:column; gap:0.5rem; justify-content:center; align-items:flex-end;">
                        <span class="badge-status ${item.status}">${statusLabel}</span>
                    </div>
                `;
                bookingDetails.appendChild(card);
            });
        }

        window.addEventListener('DOMContentLoaded', () => {
            initPage();
        });
    </script>
</body>
</html>
