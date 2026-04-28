<?php
ini_set('display_errors', 1);
error_reporting(E_ALL);


require_once(__DIR__ . "/../DB/db_config.php");

if (!isset($_SESSION['student_id'])) {
    header('Location: ../login.php');
    exit();
}

$current_page = 'calendar';

$buildings = [
    ['id' => 1, 'name' => '行政大樓', 'rooms' => [
        ['id' => 101, 'name' => '101教室'],
        ['id' => 102, 'name' => '102教室'],
        ['id' => 103, 'name' => '103教室'],
    ]],
    ['id' => 2, 'name' => '教學館', 'rooms' => [
        ['id' => 201, 'name' => '201教室'],
        ['id' => 202, 'name' => '202教室'],
        ['id' => 203, 'name' => '203教室'],
    ]],
    ['id' => 3, 'name' => '綜合大樓', 'rooms' => [
        ['id' => 301, 'name' => '301教室'],
        ['id' => 302, 'name' => '302教室'],
        ['id' => 303, 'name' => '303教室'],
    ]],
];

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

$sampleBookings = [
    '101_2026-04-26' => [
        ['slotId' => '08_09', 'title' => '晨間讀書會', 'organizer' => '王小明', 'status' => 'confirmed'],
        ['slotId' => '10_11', 'title' => '系學會會議', 'organizer' => '李美玲', 'status' => 'confirmed'],
        ['slotId' => '13_40_14_30', 'title' => '課程講座', 'organizer' => '張三', 'status' => 'confirmed'],
    ],
    '201_2026-04-26' => [
        ['slotId' => '11_12', 'title' => '專題報告', 'organizer' => '吳小姐', 'status' => 'confirmed'],
    ],
    '301_2026-04-27' => [
        ['slotId' => '09_10', 'title' => '社團面試', 'organizer' => '陳大華', 'status' => 'confirmed'],
    ],
];
?>
<!DOCTYPE html>
<html lang="zh-TW">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>空間申請 - 輔仁大學課外活動指導組</title>

    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.5/font/bootstrap-icons.css" rel="stylesheet">

    <style>
        :root {
            --primary: #8B1538;
            --sidebar: #4c0f2a;
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
        }
        .sidebar .nav-link:hover,
        .sidebar .nav-link.active {
            background: rgba(255,255,255,0.12);
            color: #ffffff;
            transform: translateX(4px);
        }
        .sidebar .nav-link i { font-size: 1.1rem; }
        .sidebar .sidebar-section { padding: 1rem 0.5rem; margin-top: 1.5rem; border-top: 1px solid rgba(255,255,255,0.12); }
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
    </style>
</head>
<body>
    <?php include(__DIR__ . "/../includes/sidebar.php"); ?>

    <main class="main-content">
        <header class="top-navbar">
            <div>
                <ol class="breadcrumb mb-0">
                    <li class="breadcrumb-item"><a href="dashboard.php">首頁</a></li>
                    <li class="breadcrumb-item active" aria-current="page">空間申請</li>
                </ol>
                <h4 class="mt-2 mb-0">空間申請</h4>
            </div>
        </header>

        <section class="content-wrapper">
            <div class="card">
                <h3><i class="bi bi-search"></i> 查詢教室</h3>
                <div class="filter-row">
                    <div>
                        <label class="form-label" for="buildingSelect">大樓</label>
                        <select id="buildingSelect" class="form-control"></select>
                    </div>
                    <div>
                        <label class="form-label" for="roomSelect">教室</label>
                        <select id="roomSelect" class="form-control"></select>
                    </div>
                    <div>
                        <label class="form-label" for="monthSelector">月份</label>
                        <select id="monthSelector" class="form-control"></select>
                    </div>
                </div>
                <button id="searchButton" class="btn btn-primary" style="margin-top: 0.5rem;">顯示該教室月行事曆</button>
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

    <div id="bookingModal" class="modal-backdrop">
        <div class="modal-dialog">
            <div class="modal-header">
                <h2 class="modal-title">預約教室</h2>
                <button class="modal-close" onclick="closeBookingModal()">×</button>
            </div>
            <form id="bookingForm" onsubmit="handleBooking(event)">
                <div class="form-group">
                    <label>教室</label>
                    <input type="text" id="modalRoomName" class="form-control" readonly>
                </div>
                <div class="form-group">
                    <label>日期</label>
                    <input type="text" id="modalBookingDate" class="form-control" readonly>
                </div>
                <div class="form-group">
                    <label>時段</label>
                    <input type="text" id="modalBookingSlot" class="form-control" readonly>
                </div>
                <div class="form-group">
                    <label for="eventName">活動名稱 *</label>
                    <input type="text" id="eventName" class="form-control" required>
                </div>
                <div class="form-group">
                    <label for="expectedAttendees">預計人數</label>
                    <input type="number" id="expectedAttendees" class="form-control" min="1" placeholder="請輸入人數">
                </div>
                <div class="modal-actions" style="display:flex; gap:1rem;">
                    <button type="button" class="btn btn-secondary" onclick="closeBookingModal()">取消</button>
                    <button type="submit" class="btn btn-primary">提交預約</button>
                </div>
            </form>
        </div>
    </div>

    <script>
        const buildings = <?php echo json_encode($buildings); ?>;
        const timeSlots = <?php echo json_encode($timeSlots); ?>;
        const bookings = <?php echo json_encode($sampleBookings); ?>;

        let selectedBuildingId = null;
        let selectedRoomId = null;
        let selectedDate = null;
        let selectedSlot = null;

        function initPage() {
            const buildingSelect = document.getElementById('buildingSelect');
            const roomSelect = document.getElementById('roomSelect');
            const monthSelector = document.getElementById('monthSelector');

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
                selectedBuildingId = parseInt(buildingSelect.value);
                fillRoomOptions(selectedBuildingId);
                selectedRoomId = parseInt(roomSelect.value);
            });

            roomSelect.addEventListener('change', () => {
                selectedRoomId = parseInt(roomSelect.value);
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
                selectedRoomId = parseInt(roomSelect.value);
                selectedBuildingId = parseInt(buildingSelect.value);
                renderCalendar();
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
                if (room) return `${building.name} ${room.name}`;
            }
            return '未知教室';
        }

        function renderCalendar() {
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
                const bookedItem = roomBookings.find(b => b.slotId === slot.id);
                const row = document.createElement('div');
                row.className = 'slot-row' + (bookedItem ? ' booked' : '');

                const label = document.createElement('div');
                label.className = 'slot-label';
                label.textContent = slot.label;
                row.appendChild(label);

                const meta = document.createElement('div');
                meta.className = 'slot-meta';
                if (bookedItem) {
                    const status = document.createElement('span');
                    status.className = `badge-status ${bookedItem.status}`;
                    status.textContent = bookedItem.status === 'confirmed' ? '已確認' : '待審核';
                    meta.appendChild(status);

                    const title = document.createElement('span');
                    title.textContent = `${bookedItem.title}（${bookedItem.organizer}）`;
                    meta.appendChild(title);
                } else {
                    const free = document.createElement('span');
                    free.textContent = '可預約';
                    free.style.color = '#10b981';
                    meta.appendChild(free);
                }
                row.appendChild(meta);

                const action = document.createElement('div');
                if (bookedItem) {
                    const cancelBtn = document.createElement('button');
                    cancelBtn.className = 'btn-action danger';
                    cancelBtn.textContent = '取消預約';
                    cancelBtn.addEventListener('click', () => cancelBooking(dateStr, slot.id));
                    action.appendChild(cancelBtn);
                } else {
                    const bookBtn = document.createElement('button');
                    bookBtn.className = 'btn-action primary';
                    bookBtn.textContent = '預約';
                    bookBtn.addEventListener('click', () => openBookingModal(dateStr, slot.id));
                    action.appendChild(bookBtn);
                }
                row.appendChild(action);
                slotList.appendChild(row);
            });

            if (roomBookings.length > 0) {
                bookingDetails.innerHTML = '<h4 style="margin-bottom:0.8rem; color:#374151;">當日已預約列表</h4>';
                roomBookings.forEach(item => {
                    const card = document.createElement('div');
                    card.className = 'booked-card';
                    card.innerHTML = `
                        <div class="booked-left">
                            <div class="booking-time" style="font-weight:700;">${timeSlots.find(s => s.id === item.slotId)?.label || item.slotId}</div>
                            <div class="booking-title">${item.title}</div>
                            <div class="booking-organizer">申請人：${item.organizer}</div>
                        </div>
                        <div style="display:flex; flex-direction:column; gap:0.5rem; justify-content:center; align-items:flex-end;">
                            <span class="badge-status ${item.status}">${item.status === 'confirmed' ? '已確認' : '待審核'}</span>
                            <button class="btn-action secondary" onclick="cancelBooking('${dateStr}', '${item.slotId}')">取消</button>
                        </div>
                    `;
                    bookingDetails.appendChild(card);
                });
            }
        }

        function openBookingModal(dateStr, slotId) {
            selectedSlot = slotId;
            const roomName = getRoomName(selectedRoomId);
            const dateDisplay = new Date(dateStr).toLocaleDateString('zh-TW', { year:'numeric', month:'2-digit', day:'2-digit' });
            const slotLabel = timeSlots.find(s => s.id === slotId).label;

            document.getElementById('modalRoomName').value = roomName;
            document.getElementById('modalBookingDate').value = dateDisplay;
            document.getElementById('modalBookingSlot').value = slotLabel;
            document.getElementById('bookingModal').classList.add('show');
        }

        function closeBookingModal() {
            document.getElementById('bookingModal').classList.remove('show');
            document.getElementById('bookingForm').reset();
        }

        function handleBooking(event) {
            event.preventDefault();
            const title = document.getElementById('eventName').value.trim();
            const attendees = document.getElementById('expectedAttendees').value.trim();
            if (!title) return;

            const dateStr = formatDateKey(selectedDate);
            const key = `${selectedRoomId}_${dateStr}`;
            if (!bookings[key]) bookings[key] = [];
            bookings[key].push({ slotId: selectedSlot, title, organizer: '目前使用者', status: 'pending' });

            closeBookingModal();
            renderSchedule(dateStr);
            renderCalendar();
            alert('✅ 已提交預約申請，待管理員審核。');
        }

        function cancelBooking(dateStr, slotId) {
            if (!confirm('確定要取消此時段預約嗎？')) return;
            const key = `${selectedRoomId}_${dateStr}`;
            if (!bookings[key]) return;
            bookings[key] = bookings[key].filter(item => item.slotId !== slotId);
            renderSchedule(dateStr);
            renderCalendar();
            alert('✅ 已取消預約。');
        }

        window.addEventListener('DOMContentLoaded', () => {
            initPage();
            document.getElementById('bookingModal').addEventListener('click', function(event) {
                if (event.target === this) closeBookingModal();
            });
        });
    </script>
</body>
</html>
