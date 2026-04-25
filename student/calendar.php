<!DOCTYPE html>
<html lang="zh-TW">
<head>
    <meta charset="UTF-8">
    <title>場地預約行事曆</title>
    <style>
        /* 莫蘭迪配色樣式 */
        body { font-family: "Microsoft JhengHei", sans-serif; background-color: #f4f1de; padding: 20px; }
        .container { max-width: 1000px; margin: auto; background: white; padding: 25px; border-radius: 15px; box-shadow: 0 4px 15px rgba(0,0,0,0.1); }
        
        .calendar-header { display: grid; grid-template-columns: repeat(7, 1fr); text-align: center; font-weight: bold; color: #6d6875; margin: 20px 0 10px; }
        .calendar-grid { display: grid; grid-template-columns: repeat(7, 1fr); gap: 8px; }
        
        .day { min-height: 100px; border: 1px solid #eee; padding: 8px; cursor: pointer; border-radius: 8px; background: #fafaf9; position: relative; }
        .day:hover { background-color: #c9c9b6; }
        
        /* 六日標示紅色 [新需求] */
        .weekend { background-color: #fff1f1; }
        .weekend .date-num { color: #d62828; font-weight: bold; }
        
        /* 國定放假日標示 */
        .holiday {background-color: #fff1f1; }
        .holiday .date-num { color: #d62828; font-weight: bold; }
        /* .holiday::after {color: #d62828; position: absolute; top: 5px; right: 5px; } */

        .event-bar { font-size: 11px; background: #b0c4b1; color: white; padding: 2px 4px; border-radius: 3px; margin-top: 2px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
        
        #detailSection { margin-top: 30px; border-top: 3px solid #6b705c; padding-top: 20px; display: none; }
        table { width: 100%; border-collapse: collapse; margin-top: 10px; }
        th, td { border: 1px solid #ddd; padding: 12px; text-align: center; }
        th { background: #b0c4b1; color: white; }
        
        /* 器材借用標籤 */
        .badge { font-size: 10px; padding: 2px 6px; border-radius: 4px; }
    </style>
</head>
<body>

<div class="container">
    <h2 style="text-align:center; color: #6b705c;">活動行事曆</h2>
    
    <div style="text-align:center; margin-bottom:20px;">
        <select id="monthSelector" style="padding: 10px; font-size: 16px; border-radius: 5px;"></select>
    </div>

    <div class="calendar-header">
        <div style="color:red">日</div><div>一</div><div>二</div><div>三</div><div>四</div><div>五</div><div style="color:red">六</div>
    </div>
    <div id="calendarGrid" class="calendar-grid"></div>

    <div id="detailSection">
        <h3 id="selectedDateText" style="color: #6b705c;"></h3>
        <table>
            <thead>
                <tr><th>活動名稱</th><th>借用空間</th><th>時段</th><th>借用人</th><th>備註</th></tr>
            </thead>
            <tbody id="detailBody"></tbody>
        </table>
    </div>
</div>

<script>
// 精確對照 PDF 的放假日
const holidays = [
    "2026-02-16", "2026-02-17", "2026-02-18", "2026-02-19", "2026-02-20", // 春節系列
    "2026-02-28", // 和平紀念日
    "2026-04-02", "2026-04-03", "2026-04-04", "2026-04-05", "2026-04-06", "2026-04-07", // 清明/彈性放假
    "2026-05-01", // 勞動節
    "2026-06-19", // 端午節
    "2026-09-25", // 中秋節
    "2026-10-09", "2026-10-10", // 國慶日
    "2026-12-07", // 校慶補假
    "2026-12-25", // 行憲紀念日
    "2027-01-01"  // 元旦
];

const months = [
    { y: 2026, m: 2, label: "114-2 (2026/02)" }, { y: 2026, m: 3, label: "114-2 (2026/03)" },
    { y: 2026, m: 4, label: "114-2 (2026/04)" }, { y: 2026, m: 5, label: "114-2 (2026/05)" },
    { y: 2026, m: 6, label: "114-2 (2026/06)" }, { y: 2026, m: 7, label: "114-2 (2026/07)" },
    { y: 2026, m: 8, label: "115-1 (2026/08)" }, { y: 2026, m: 9, label: "115-1 (2026/09)" },
    { y: 2026, m: 10, label: "115-1 (2026/10)" }, { y: 2026, m: 11, label: "115-1 (2026/11)" },
    { y: 2026, m: 12, label: "115-1 (2026/12)" }, { y: 2027, m: 1, label: "115-1 (2027/01)" }
];

const monthSelector = document.getElementById('monthSelector');
const grid = document.getElementById('calendarGrid');

function init() {
    months.forEach((m, idx) => {
        let opt = document.createElement('option');
        opt.value = idx;
        opt.textContent = m.label;
        monthSelector.appendChild(opt);
    });
    render();
}

async function render() {
    grid.innerHTML = '';
    const info = months[monthSelector.value];
    
    // 串接 API
    let events = [];
    try {
        const res = await fetch(`../api/get_calendar.php?year=${info.y}&month=${info.m}`);
        events = await res.json();
    } catch(e) { console.error("資料讀取失敗"); }

    const firstDay = new Date(info.y, info.m - 1, 1).getDay();
    const daysInMonth = new Date(info.y, info.m, 0).getDate();

    // 填充月初空白
    for (let i = 0; i < firstDay; i++) grid.appendChild(document.createElement('div'));

    for (let d = 1; d <= daysInMonth; d++) {
        const dateObj = new Date(info.y, info.m - 1, d);
        const dayOfWeek = dateObj.getDay(); // 0 是週日, 6 是週六
        const dateStr = `${info.y}-${String(info.m).padStart(2, '0')}-${String(d).padStart(2, '0')}`;
        
        const dayDiv = document.createElement('div');
        dayDiv.className = 'day';
        
        // 標示六日
        if (dayOfWeek === 0 || dayOfWeek === 6) dayDiv.classList.add('weekend');
        // 標示 PDF 放假日
        if (holidays.includes(dateStr)) dayDiv.classList.add('holiday');

        dayDiv.innerHTML = `<div class="date-num">${d}</div>`;

        // 過濾該日活動
        const todayEvents = events.filter(e => e.start_time.startsWith(dateStr));
        todayEvents.forEach(e => {
            let bar = document.createElement('div');
            bar.className = 'event-bar';
            bar.textContent = e.event_name;
            dayDiv.appendChild(bar);
        });

        dayDiv.onclick = () => showDetails(dateStr, todayEvents);
        grid.appendChild(dayDiv);
    }
}

function showDetails(date, dayEvents) {
    document.getElementById('detailSection').style.display = 'block';
    document.getElementById('selectedDateText').textContent = date + " 活動詳情";
    const body = document.getElementById('detailBody');
    body.innerHTML = '';

    if (dayEvents.length === 0) {
        body.innerHTML = '<tr><td colspan="5">當日無活動預約</td></tr>';
    } else {
        dayEvents.forEach(e => {
            const startTime = e.start_time ? e.start_time.split(' ')[1].substring(0,5) : '';
            const endTime = e.end_time ? e.end_time.split(' ')[1].substring(0,5) : '';
            
            // 判斷是活動還是器材借用
            if (e.type === 'equipment') {
                body.innerHTML += `<tr>
                    <td>${e.event_name} <span class="badge bg-warning text-dark">器材借用</span></td>
                    <td>${e.equipment_name || '-'}</td>
                    <td>${startTime} - ${endTime}</td>
                    <td>${e.user_name}</td>
                    <td>${e.description || ''}</td>
                </tr>`;
            } else {
                body.innerHTML += `<tr>
                    <td>${e.event_name}</td>
                    <td>${e.space_name || '未指定'}</td>
                    <td>${startTime} - ${endTime}</td>
                    <td>${e.user_name}</td>
                    <td>${e.description || ''}</td>
                </tr>`;
            }
        });
    }
}

monthSelector.onchange = render;
init();
</script>
</body>
</html>