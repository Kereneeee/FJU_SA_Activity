<?php
ini_set('display_errors', 1);
error_reporting(E_ALL);

require_once(__DIR__ . "/../DB/db_config.php");

if (!isset($_SESSION['user_id'])) {
    header('Location: ../login.php');
    exit();
}

$user_name = $_SESSION['user_name'] ?? '管理員';

$selected_year = intval($_GET['year'] ?? date('Y'));
$selected_month = intval($_GET['month'] ?? date('m'));
$month_start = sprintf('%04d-%02d-01 00:00:00', $selected_year, $selected_month);
$month_end = date('Y-m-t 23:59:59', strtotime($month_start));

$buildings = [
    [
        'id' => 1,
        'name' => 'A焯炤館',
        'rooms' => [
            ['id' => 1, 'name' => 'A焯炤館'],
            ['id' => 2, 'name' => 'A焯炤館－四音'],
            ['id' => 3, 'name' => 'A焯炤館－四康'],
            ['id' => 4, 'name' => 'A焯炤館－地下演講廳'],
            ['id' => 5, 'name' => 'A焯炤館－旋律廣場－冷氣損壞'],
            ['id' => 6, 'name' => 'A焯炤館－夢幻電影院'],
            ['id' => 7, 'name' => 'A焯炤館－鏡鏡屋'],
        ],
    ],
    [
        'id' => 2,
        'name' => 'B進修部地下室',
        'rooms' => [
            ['id' => 8, 'name' => 'B進修部地下室教室（一）ES002'],
            ['id' => 9, 'name' => 'B進修部地下室教室（二）ES003'],
            ['id' => 10, 'name' => 'B進修部地下室教室（三）ES004'],
            ['id' => 11, 'name' => 'B進修部地下室教室（四）ES005'],
            ['id' => 12, 'name' => 'B進修部地下室教室（五）ES006'],
            ['id' => 13, 'name' => 'B進修部地下室演講廳'],
        ],
    ],
    [
        'id' => 3,
        'name' => 'C仁愛學苑',
        'rooms' => [
            ['id' => 14, 'name' => 'C仁愛學苑－一樓半空間'],
            ['id' => 15, 'name' => 'C仁愛學苑－二樓半空間'],
            ['id' => 16, 'name' => 'C仁愛學苑－三樓半空間'],
        ],
    ],
    [
        'id' => 4,
        'name' => 'D文開區域',
        'rooms' => [
            ['id' => 17, 'name' => 'D文開地下舞蹈空間中間'],
            ['id' => 18, 'name' => 'D文開地下舞蹈空間右側（軟墊）'],
            ['id' => 19, 'name' => 'D文開地下舞蹈空間左側'],
            ['id' => 20, 'name' => 'D真善美聖廣場'],
        ],
    ],
    [
        'id' => 5,
        'name' => 'E / H 區域',
        'rooms' => [
            ['id' => 21, 'name' => 'E課指組204會議室'],
            ['id' => 22, 'name' => 'H校門口左側（AB）'],
            ['id' => 23, 'name' => 'H校門口左側（CD）'],
        ],
    ],
];

$direct_space_id = intval($_GET['space_id'] ?? 0);
$selectedBuildingId = null;
$selectedRoomId = null;

if ($direct_space_id > 0) {
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

$bookings = [];
$sql_bookings = "SELECT r.space_id, r.start_time, r.end_time, e.event_name, e.club_name, u.name AS user_name, e.status
    FROM reservations r
    JOIN events e ON r.event_id = e.event_id
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
        $bookings[$key][] = [
            'start_time' => $row['start_time'],
            'end_time' => $row['end_time'],
            'event_name' => $row['event_name'],
            'club_name' => $row['club_name'],
            'organizer' => $row['user_name'],
            'status' => $row['status'],
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
    <title>完整行事曆 - 輔仁大學課外活動指導組</title>

    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.5/font/bootstrap-icons.css" rel="stylesheet">

    <style>
        :root {
            --primary: #8B1538;
            --sidebar: #4c0f2a;
            --sidebar-hover: #6a1d43;
            --bg: #f4f6fb;
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
            background: linear-gradient(180deg, var(--primary), var(--sidebar));
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
            text-decoration: none;
        }
        .sidebar .nav-link:hover,
        .sidebar .nav-link.active {
            background: rgba(255,255,255,0.12);
            color: #ffffff;
            transform: translateX(4px);
        }
        .sidebar .nav-link i { font-size: 1.1rem; }
        .sidebar .sidebar-section { padding: 1rem 0.5rem; margin-top: 1.5rem; border-top: 1px solid rgba(255,255,255,0.12); }
        .dropdown {
            position: relative;
        }
        .dropdown-content {
            display: block;
            max-height: 0;
            overflow: hidden;
            transition: max-height 0.3s ease;
            background-color: rgba(255,255,255,0.1);
            border-radius: 16px;
            margin-top: 0.2rem;
        }
        .dropdown:hover .dropdown-content {
            max-height: 200px;
        }
        .dropdown-content a {
            color: rgba(255,255,255,0.9);
            padding: 0.75rem 1rem;
            text-decoration: none;
            display: block;
            border-radius: 0;
        }
        .dropdown-content a:hover {
            background-color: rgba(255,255,255,0.2);
        }
        .main-content { margin-left: 260px; min-height: 100vh; transition: margin-left 0.25s ease; }
        .top-navbar { background: white; border-bottom: 1px solid #e9ecef; padding: 1rem 2rem; display: flex; justify-content: space-between; align-items: center; position: sticky; top: 0; z-index: 1100; }
        .top-navbar .breadcrumb { margin: 0; background: transparent; padding: 0; }
        .content-wrapper { padding: 1.5rem 2rem 2rem; }
        .card { background: var(--card); border-radius: 18px; box-shadow: 0 10px 30px rgba(15,23,42,0.06); padding: 1.5rem; margin-bottom: 1.5rem; }
        .card h3 { margin-bottom: 1rem; font-weight: 700; color: var(--primary); display: flex; align-items: center; gap: 0.5rem; }
        .filter-row { display: grid; grid-template-columns: repeat(3, minmax(200px, 1fr)); gap: 1rem; align-items: end; margin-bottom: 1rem; }
        .filter-row .form-label { font-weight: 600; color: #374151; margin-bottom: 0.4rem; }
        .calendar-grid { display: grid; grid-template-columns: repeat(7, 1fr); gap: 8px; margin-bottom: 1rem; }
        .day-card { min-height: 98px; border: 2px solid #e5e7eb; border-radius: 14px; padding: 10px; background: white; cursor: pointer; transition: all 0.25s ease; display: flex; flex-direction: column; justify-content: space-between; }
        .day-card:hover { border-color: var(--primary); box-shadow: 0 4px 12px rgba(139,21,56,0.1); }
        .day-card.selected { border-color: var(--primary); background: rgba(139,21,56,0.08); }
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
        .badge-status.confirmed { background: #d1e7dd; color: #0f5132; }
        .badge-status.pending { background: #fff3cd; color: #664d03; }
        .btn-action { padding: 0.55rem 1rem; border: none; border-radius: 10px; cursor: pointer; font-weight: 700; transition: all 0.25s ease; }
        .btn-action.primary { background: var(--primary); color: white; }
        .btn-action.primary:hover { background: #5a0f29; }
        .btn-action.secondary { background: #e5e7eb; color: #1f2937; }
        .btn-action.secondary:hover { background: #d1d5db; }
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
    </style>
</head>
<body>
    <aside class="sidebar">
        <div class="brand">
            <h4>輔仁大學<br>課外活動指導組</h4>
        </div>
        <nav class="nav flex-column">
            <a class="nav-link" href="dashboard.php"><i class="bi bi-house-door"></i> 儀表板</a>
            <a class="nav-link" href="review.php"><i class="bi bi-clipboard-check"></i> 審核管理</a>
            <a class="nav-link" href="event_mgmt.php"><i class="bi bi-calendar-check"></i> 申請紀錄</a>
            <a class="nav-link" href="equipment_mgmt.php"><i class="bi bi-tools"></i> 器材庫存管理</a>
            <a class="nav-link" href="space_mgmt.php"><i class="bi bi-building"></i> 空間管理</a>
            <a class="nav-link active" href="calendar.php"><i class="bi bi-calendar3"></i> 完整行事曆</a>
        </nav>
        <div class="sidebar-section">
            <p class="mb-2">快捷操作</p>
            <a class="nav-link" href="../logout.php"><i class="bi bi-box-arrow-right"></i> 登出系統</a>
        </div>
    </aside>

    <main class="main-content">
        <header class="top-navbar">
            <div>
                <ol class="breadcrumb mb-0">
                    <li class="breadcrumb-item"><a href="dashboard.php">首頁</a></li>
                    <li class="breadcrumb-item active" aria-current="page">完整行事曆</li>
                </ol>
                <h4 class="mt-2 mb-0">完整行事曆</h4>
            </div>
            <div class="user-card">
                <div class="user-avatar"><?= htmlspecialchars(substr($user_name, 0, 1)) ?></div>
                <div>
                    <div><?= htmlspecialchars($user_name) ?></div>
                    <small class="text-muted">管理員</small>
                </div>
            </div>
        </header>

        <section class="content-wrapper">
            <div class="card">
                <h3><i class="bi bi-search"></i> 查詢教室</h3>
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
                    <span>綠色=空閒</span>
                    <span>黃色=部分預約</span>
                    <span>紅色=滿額</span>
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
        const buildings = <?php echo json_encode($buildings); ?>;
        const timeSlots = <?php echo json_encode($timeSlots); ?>;
        const bookings = <?php echo json_encode($bookings); ?>;

        let selectedBuildingId = <?php echo $selectedBuildingId ? $selectedBuildingId : 'null'; ?>;
        let selectedRoomId = <?php echo $selectedRoomId ? $selectedRoomId : 'null'; ?>;
        let selectedDate = null;

        function initPage() {
            const buildingSelect = document.getElementById('buildingSelect');
            const roomSelect = document.getElementById('roomSelect');
            const monthSelector = document.getElementById('monthSelector');
            const filterRow = document.querySelector('.filter-row');
            const searchButton = document.getElementById('searchButton');

            if (selectedRoomId !== null) {
                filterRow.style.display = 'none';
                searchButton.style.display = 'none';
                renderCalendar();
                return;
            }

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
                const date = new Date(today.getFullYear(), i, 1);
                option.textContent = `${date.getFullYear()}年${i + 1}月`;
                if (i === today.getMonth()) option.selected = true;
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

            timeSlots.forEach(slot => {
                const row = document.createElement('div');
                row.className = 'slot-row' + (roomBookings.some(b => b.start_time.includes(slot.label.split(' - ')[0])) ? ' booked' : '');
                const label = document.createElement('div');
                label.className = 'slot-label';
                label.textContent = slot.label;
                row.appendChild(label);

                const status = document.createElement('div');
                status.className = 'slot-meta';
                const matching = roomBookings.filter(b => b.start_time.includes(slot.label.split(' - ')[0]));
                if (matching.length > 0) {
                    status.innerHTML = `<span class="badge-status confirmed">${matching.length} 筆預約</span>`;
                } else {
                    status.innerHTML = '<span class="badge-status pending">可預約</span>';
                }
                row.appendChild(status);
                slotList.appendChild(row);
            });

            if (roomBookings.length === 0) {
                bookingDetails.innerHTML = '<div class="booked-card"><div class="booked-left"><div class="booked-label">今日尚無預約</div></div></div>';
                return;
            }

            roomBookings.forEach(booking => {
                const card = document.createElement('div');
                card.className = 'booked-card';
                card.innerHTML = `
                    <div class="booked-left">
                        <div class="booked-label">${booking.event_name}</div>
                        <div>${booking.club_name}</div>
                        <div>${booking.organizer}</div>
                    </div>
                    <div style="text-align:right;">
                        <div>${booking.start_time.slice(11,16)} - ${booking.end_time.slice(11,16)}</div>
                        <div class="badge-status confirmed">${booking.status}</div>
                    </div>
                `;
                bookingDetails.appendChild(card);
            });
        }

        initPage();
    </script>
</body>
</html>
