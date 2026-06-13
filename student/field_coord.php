<?php

require_once(__DIR__ . "/../DB/db_config.php");
require_once(__DIR__ . "/../includes/FieldCoordinationManager.php");

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'student') {
    header('Location: ../login.php');
    exit();
}

// 設置當前頁面用於側邊欄高亮
$current_page = 'field_coord';

// 初始化場協管理器
$fc_manager = new FieldCoordinationManager($conn);
$active_setting = $fc_manager->getActiveSettings();
$is_in_registration_period = $fc_manager->isInRegistrationPeriod();
$has_meeting_passed = $fc_manager->hasCoordinationMeetingPassed();
$latest_setting = $fc_manager->getLatestSetting();
$is_closed = !$active_setting && $latest_setting && $latest_setting['status'] === 'inactive'
             && strtotime($latest_setting['coordination_meeting_date']) > time();

// 場次日期選擇限制：只能選在可借用期間內（協調大會之後的開放期間）
$borrow_min = ($active_setting && !empty($active_setting['borrow_start_date'])) ? date('Y-m-d', strtotime($active_setting['borrow_start_date'])) : '';
$borrow_max = ($active_setting && !empty($active_setting['borrow_end_date']))   ? date('Y-m-d', strtotime($active_setting['borrow_end_date']))   : '';

$message = '';
$message_type = '';
$spaces = [];
// 從 DB 動態建立場地分組
$buildings = [];
$spaces    = [];
$_sp_res = $conn->query("SELECT space_id, space_name, capacity FROM spaces WHERE space_status='available' ORDER BY space_id");
if ($_sp_res) {
    $_bmap = [];
    $_blabels = ['A'=>'A焯炤館','B'=>'B進修部地下室','C'=>'C仁愛學苑','D'=>'D文開區域','E'=>'E / H 區域','H'=>'E / H 區域'];
    $_border  = ['A焯炤館','B進修部地下室','C仁愛學苑','D文開區域','E / H 區域'];
    while ($_sp = $_sp_res->fetch_assoc()) {
        $_pfx   = mb_substr($_sp['space_name'], 0, 1, 'UTF-8');
        $_bname = $_blabels[$_pfx] ?? $_pfx;
        $_room  = ['space_id' => (int)$_sp['space_id'], 'space_name' => $_sp['space_name'], 'capacity' => (int)$_sp['capacity']];
        $_bmap[$_bname][] = $_room;
        $spaces[$_sp['space_id']] = $_room;
    }
    $_bid = 1;
    foreach ($_border as $_bname) {
        if (!empty($_bmap[$_bname])) $buildings[] = ['id' => $_bid++, 'name' => $_bname, 'rooms' => $_bmap[$_bname]];
    }
    foreach ($_bmap as $_bname => $_rooms) {
        if (!in_array($_bname, $_border)) $buildings[] = ['id' => $_bid++, 'name' => $_bname, 'rooms' => $_rooms];
    }
}

// $_SESSION['user_id'] 可能是 email，統一轉成整數 user_id
$_raw = isset($_SESSION['user_id']) ? $_SESSION['user_id'] : null;
if ($_raw !== null && is_numeric($_raw)) {
    $user_id = (int)$_raw;
} elseif ($_raw !== null) {
    $_s = $conn->prepare("SELECT user_id FROM users WHERE email = ? LIMIT 1");
    $_s->bind_param("s", $_raw); $_s->execute();
    $_r = $_s->get_result()->fetch_assoc(); $_s->close();
    $user_id = $_r ? (int)$_r['user_id'] : null;
} else {
    $user_id = null;
}
$student_id_value = null;
if ($user_id) {
    $student_id_value = $fc_manager->getStudentIdByUserId($user_id);
    if (!$student_id_value) $student_id_value = $user_id;
}

// 取得使用者隸屬的所有社團（可能同時擁有多重身分）
$my_clubs = [];
if ($user_id) {
    $club_stmt2 = $conn->prepare("SELECT cm.club_id, c.club_name FROM club_members cm JOIN clubs c ON cm.club_id = c.club_id WHERE cm.user_id = ?");
    if ($club_stmt2) {
        $club_stmt2->bind_param("i", $user_id);
        $club_stmt2->execute();
        $club_result2 = $club_stmt2->get_result();
        while ($club_row2 = $club_result2->fetch_assoc()) $my_clubs[] = $club_row2;
        $club_stmt2->close();
    }
}

// 決定本次登記的主辦社團（多重身分時依表單選擇，並驗證屬於自己）
$selected_club_id = '';
$selected_club_name = '';
$requested_club_id = $_POST['club_id'] ?? ($_GET['club_id'] ?? '');
foreach ($my_clubs as $c) {
    if ($requested_club_id !== '' && $c['club_id'] === $requested_club_id) {
        $selected_club_id   = $c['club_id'];
        $selected_club_name = $c['club_name'];
        break;
    }
}
if ($selected_club_id === '' && !empty($_SESSION['current_club_id'])) {
    foreach ($my_clubs as $c) {
        if ($c['club_id'] === $_SESSION['current_club_id']) {
            $selected_club_id   = $c['club_id'];
            $selected_club_name = $c['club_name'];
            break;
        }
    }
}
if ($selected_club_id === '' && !empty($my_clubs)) {
    $selected_club_id   = $my_clubs[0]['club_id'];
    $selected_club_name = $my_clubs[0]['club_name'];
}

// 場次資料（用於表單還原）
$sessions_data = [['date'=>'','start_time'=>'','end_date'=>'','end_time'=>'','space_id'=>'']];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['register_spaces'])) {
    if (!$is_in_registration_period && $has_meeting_passed) {
        $message_type = 'error';
        $message = '❌ 場地協調大會已結束。此後場地申請採用先到先得制，請改用常規場地申請功能。';
    } elseif (!$active_setting) {
        $message_type = 'error';
        $message = '❌ 目前不在場協登記期間。請等待下一個場協登記期間。';
    } else {
        $event_name           = trim($_POST['event_name'] ?? '');
        $club_id              = $selected_club_id;   // 依使用者實際所屬社團（多重身分時依表單選擇）決定，避免登記到他人社團
        $club_name            = $selected_club_name;
        $responsible_person   = trim($_POST['responsible_person'] ?? '');
        $description          = trim($_POST['description'] ?? '');
        $sessions             = isset($_POST['sessions']) && is_array($_POST['sessions']) ? $_POST['sessions'] : [];
        $acknowledged_conflicts = isset($_POST['acknowledged_conflicts']) ? intval($_POST['acknowledged_conflicts']) : 0;

        // 還原場次以便顯示
        $sessions_data = !empty($sessions) ? $sessions : [['date'=>'','start_time'=>'','end_date'=>'','end_time'=>'','space_id'=>'']];

        $errors = [];
        if (empty($event_name))         $errors[] = '請填寫活動名稱';
        if (empty($club_name))          $errors[] = '找不到您所屬的社團資料，請聯絡系統管理員';
        if (empty($responsible_person)) $errors[] = '請填寫活動負責人';
        if (empty($sessions))           $errors[] = '請至少新增一個場次';

        foreach ($sessions as $i => $sess) {
            $n = $i + 1;
            if (empty($sess['date']))       $errors[] = "場次{$n}：請選擇開始日期";
            if (empty($sess['start_time'])) $errors[] = "場次{$n}：請填寫開始時間";
            if (empty($sess['end_date']))   $errors[] = "場次{$n}：請選擇結束日期";
            if (empty($sess['end_time']))   $errors[] = "場次{$n}：請填寫結束時間";
            if (empty($sess['space_id']))   $errors[] = "場次{$n}：請選擇場地";
            if (!empty($sess['date']) && !empty($sess['end_date']) && $sess['end_date'] < $sess['date'])
                $errors[] = "場次{$n}：結束日期不能早於開始日期";
            if (!empty($sess['date']) && !empty($sess['end_date']) && $sess['date'] === $sess['end_date'] &&
                !empty($sess['start_time']) && !empty($sess['end_time']) && $sess['start_time'] >= $sess['end_time'])
                $errors[] = "場次{$n}：同日結束時間必須晚於開始時間";
        }

        // 可借用期間驗證
        if (empty($errors) && $active_setting &&
            !empty($active_setting['borrow_start_date']) && !empty($active_setting['borrow_end_date']) &&
            !empty($active_setting['borrow_start_time']) && !empty($active_setting['borrow_end_time'])) {
            $borrow_start_dt   = new DateTime($active_setting['borrow_start_date']);
            $borrow_end_dt     = new DateTime($active_setting['borrow_end_date']);
            $borrow_start_time = date('H:i:s', strtotime($active_setting['borrow_start_time']));
            $borrow_end_time   = date('H:i:s', strtotime($active_setting['borrow_end_time']));
            $borrow_date_range = date('Y-m-d', strtotime($active_setting['borrow_start_date'])) . ' ～ ' . date('Y-m-d', strtotime($active_setting['borrow_end_date']));

            foreach ($sessions as $i => $sess) {
                $n = $i + 1;
                $occ_start = new DateTime($sess['date'] . ' ' . $sess['start_time']);
                $occ_end   = new DateTime(($sess['end_date'] ?? $sess['date']) . ' ' . $sess['end_time']);
                if ($occ_start < $borrow_start_dt || $occ_end > $borrow_end_dt) {
                    $errors[] = "場次{$n}：日期必須在可借用期間 {$borrow_date_range}";
                    break;
                }
                if (date('H:i:s', strtotime($sess['start_time'])) < $borrow_start_time ||
                    date('H:i:s', strtotime($sess['end_time']))   > $borrow_end_time) {
                    $errors[] = "場次{$n}：時段必須在可借用時間 {$borrow_start_time} ～ {$borrow_end_time}";
                    break;
                }
            }
        }

        // 逐場次衝突檢測
        $conflicts_detected = [];
        if (empty($errors) && $is_in_registration_period) {
            foreach ($sessions as $i => $sess) {
                $s = $sess['date'] . ' ' . $sess['start_time'] . ':00';
                $e = ($sess['end_date'] ?? $sess['date']) . ' ' . $sess['end_time'] . ':00';
                $sess_conflicts = $fc_manager->detectFieldCoordinationConflicts(
                    $active_setting['setting_id'],
                    [intval($sess['space_id'])],
                    $s, $e
                );
                foreach ($sess_conflicts as $c) {
                    $c['session_index'] = $i + 1;
                    $conflicts_detected[] = $c;
                }
            }
        }

        if (!empty($conflicts_detected) && !$acknowledged_conflicts) {
            $_SESSION['pending_conflicts'] = $conflicts_detected;
            $_SESSION['pending_form_data'] = $_POST;
            $message_type = 'warning';
            $conflict_text = '';
            foreach ($conflicts_detected as $conflict) {
                $si_label = isset($conflict['session_index']) ? "（場次{$conflict['session_index']}）" : '';
                $conflict_text .= '<br>- ' . htmlspecialchars($conflict['conflicting_club'], ENT_QUOTES, 'UTF-8') .
                    ' 的 "' . htmlspecialchars($conflict['conflicting_event'], ENT_QUOTES, 'UTF-8') .
                    '" (' . htmlspecialchars($conflict['conflicting_time'], ENT_QUOTES, 'UTF-8') . ')' . $si_label;
            }
            $message = '⚠️ 檢測到場地衝突，請確認是否繼續提交：' . $conflict_text;
        } elseif (empty($errors)) {
            // 計算整體 start/end
            $event_start = null;
            $event_end   = null;
            foreach ($sessions as $sess) {
                $s = $sess['date'] . ' ' . $sess['start_time'] . ':00';
                $e = ($sess['end_date'] ?? $sess['date']) . ' ' . $sess['end_time'] . ':00';
                if ($event_start === null || $s < $event_start) $event_start = $s;
                if ($event_end   === null || $e > $event_end)   $event_end   = $e;
            }

            $conn->begin_transaction();
            try {
                $stmt_event = $conn->prepare(
                    "INSERT INTO events (user_id, event_name, club_name, description, start_time, end_time, status, is_field_coordination, field_coordination_setting_id, responsible_person)
                     VALUES (?, ?, ?, ?, ?, ?, 'pending', 1, ?, ?)"
                );
                if (!$user_id) throw new Exception('尚未取得使用者識別碼，請重新登入。');

                $description_lines = ['場地協調'];
                if (!empty($description))       $description_lines[] = "備註：{$description}";
                $full_description = implode("\n", $description_lines);

                $setting_id = $active_setting['setting_id'];
                $stmt_event->bind_param('isssssis', $user_id, $event_name, $club_name, $full_description, $event_start, $event_end, $setting_id, $responsible_person);
                if (!$stmt_event->execute()) throw new Exception('建立活動記錄失敗：' . $stmt_event->error);
                $event_id = $conn->insert_id;
                $stmt_event->close();

                $stmt_reserve = $conn->prepare(
                    "INSERT INTO reservations (event_id, space_id, start_time, end_time, is_field_coordination_preliminary)
                     VALUES (?, ?, ?, ?, 1)"
                );
                foreach ($sessions as $sess) {
                    $space_id  = intval($sess['space_id']);
                    $res_start = $sess['date'] . ' ' . $sess['start_time'] . ':00';
                    $res_end   = ($sess['end_date'] ?? $sess['date']) . ' ' . $sess['end_time'] . ':00';
                    $stmt_reserve->bind_param('iiss', $event_id, $space_id, $res_start, $res_end);
                    if (!$stmt_reserve->execute()) throw new Exception('場地登記失敗：' . $stmt_reserve->error);
                }
                $stmt_reserve->close();

                $fc_manager->createFieldCoordinationRegistration(
                    $active_setting['setting_id'], $event_id, $student_id_value, $club_id, $club_name
                );

                $conn->commit();
                // 取第一場次的年月與場地，供日曆連結直接跳轉到該大樓教室的月份
                $first_sess_date = !empty($sessions[0]['date']) ? $sessions[0]['date'] : date('Y-m-d');
                $cal_year  = date('Y', strtotime($first_sess_date));
                $cal_month = date('n', strtotime($first_sess_date));
                $cal_space_id = intval($sessions[0]['space_id']);
                $message_type = 'success';
                $message = '✅ 場地協調登記已送出，申請編號：#' . $event_id . '，共 ' . count($sessions) . ' 個場次。';
                if (!empty($conflicts_detected))
                    $message .= ' 該申請包含 ' . count($conflicts_detected) . ' 個場地衝突，將於協調大會時協調。';
                $message .= ' <a href="field_coordination_records.php" class="fw-semibold">→ 查看登記紀錄</a>'
                          . ' <a href="calendar.php?year=' . $cal_year . '&month=' . $cal_month . '&space_id=' . $cal_space_id . '" class="fw-semibold ms-2">→ 查看行事曆</a>';
                unset($_SESSION['pending_conflicts'], $_SESSION['pending_form_data']);
                $_POST = [];
                $sessions_data = [['date'=>'','start_time'=>'','end_date'=>'','end_time'=>'','space_id'=>'']];
            } catch (Exception $e) {
                $conn->rollback();
                $message_type = 'error';
                $message = '❌ 登記失敗：' . $e->getMessage();
            }
        } else {
            $message_type = 'error';
            $message = '❌ ' . implode('<br>', $errors);
        }
    }
}

// 場地資料給 JS 用（按大樓分組）
$spaces_for_js = [];
foreach ($buildings as $building) {
    $grp = ['label' => $building['name'], 'options' => []];
    foreach ($building['rooms'] as $r) {
        $grp['options'][] = ['id' => $r['space_id'], 'name' => $r['space_name']];
    }
    $spaces_for_js[] = $grp;
}
?>

<!DOCTYPE html>
<html lang="zh-TW">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>場地協調 - 輔仁大學課外活動指導組</title>

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
        .conflict-track {
            display: flex;
            gap: 1rem;
            overflow-x: auto;
            padding: 0.25rem 0 1rem;
            margin-bottom: 1rem;
            scroll-snap-type: x proximity;
        }
        .conflict-track-item {
            flex: 0 0 260px;
            scroll-snap-align: start;
            background: white;
            border: 1px solid #fde68a;
            border-radius: 10px;
            padding: 0.85rem 1rem;
            box-shadow: 0 2px 8px rgba(15,23,42,0.05);
        }
        .conflict-track-club {
            font-weight: 700;
            color: #92400e;
            margin-bottom: 0.35rem;
        }
        .conflict-track-event {
            margin-bottom: 0.5rem;
            color: #1f2937;
        }
        .conflict-track-meta {
            font-size: 0.85rem;
            color: #6b7280;
            margin-bottom: 0.15rem;
        }
        .service-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
            gap: 1.5rem;
        }
        .service-card {
            background: white;
            border: 1px solid #e5e7eb;
            border-radius: 12px;
            padding: 1.5rem;
            transition: box-shadow 0.25s ease, transform 0.15s ease;
            text-align: center;
        }
        .service-card:hover {
            box-shadow: 0 8px 25px rgba(0,0,0,0.1);
            transform: translateY(-2px);
        }
        .service-icon {
            width: 60px;
            height: 60px;
            border-radius: 50%;
            background: var(--primary);
            color: white;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.8rem;
            margin: 0 auto 1rem;
        }
        .service-card h4 {
            margin-bottom: 0.5rem;
            font-weight: 600;
        }
        .service-card p {
            color: #6b7280;
            margin-bottom: 1rem;
        }
        .btn-service {
            background: var(--primary);
            color: white;
            border: none;
            padding: 0.5rem 1.5rem;
            border-radius: 8px;
            font-weight: 600;
            cursor: pointer;
            transition: background 0.25s ease;
        }
        .btn-service:hover {
            background: var(--sidebar);
        }
        .contact-info {
            background: #f8f9fa;
            border-radius: 12px;
            padding: 1.5rem;
            margin-top: 1rem;
        }
        .contact-item {
            display: flex;
            align-items: center;
            gap: 0.75rem;
            margin-bottom: 0.75rem;
        }
        .contact-item:last-child {
            margin-bottom: 0;
        }
        .contact-item i {
            color: var(--primary);
            font-size: 1.2rem;
        }
        @media (max-width: 1100px) {
            .service-grid { grid-template-columns: 1fr; }
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
        /* 場次卡片 */
        .session-row { border:1px solid #e2e8f0; border-radius:14px; padding:1.1rem 1.25rem; margin-bottom:0.85rem; background:white; transition:box-shadow 0.2s,border-color 0.2s; }
        .session-row:hover { box-shadow:0 4px 14px rgba(30,77,107,0.09); border-color:#c7d6df; }
        .session-header { display:flex; justify-content:space-between; align-items:center; margin-bottom:0.8rem; }
        .session-label { font-weight:700; color:var(--primary); font-size:0.92rem; display:flex; align-items:center; gap:0.4rem; }
        .btn-remove-session { background:none; border:1px solid #fca5a5; color:#ef4444; border-radius:6px; padding:0.2rem 0.55rem; cursor:pointer; font-size:0.8rem; transition:all 0.2s; display:inline-flex; align-items:center; gap:0.3rem; }
        .btn-remove-session:hover { background:#fee2e2; }
        .session-fields { display:grid; grid-template-columns:1.2fr 1fr 1.2fr 1fr 2fr; gap:0.65rem; align-items:end; }
        .session-field label { display:block; font-size:0.8rem; color:#6b7280; margin-bottom:0.25rem; font-weight:500; }
        .btn-add-session { display:flex; align-items:center; justify-content:center; gap:0.5rem; width:100%; border:2px dashed #c8d6df; border-radius:12px; padding:0.75rem; background:white; color:var(--primary); font-weight:600; font-size:0.92rem; cursor:pointer; transition:all 0.2s; margin-top:0.25rem; }
        .btn-add-session:hover { border-color:var(--primary); background:rgba(30,77,107,0.04); }
        @media (max-width:960px) { .session-fields { grid-template-columns:1fr 1fr 1fr; } .session-fields .session-field:nth-child(4), .session-fields .session-field:nth-child(5) { grid-column:1/-1; } }
        @media (max-width:600px) { .session-fields { grid-template-columns:1fr; } }
    </style>
</head>
<body>
    <?php include(__DIR__ . "/../includes/sidebar.php"); ?>

    <main class="main-content">
        <?php
        $nav_breadcrumbs = [['label'=>'首頁','url'=>'dashboard.php'],['label'=>'場地協調']];
        $nav_title = '場地協調';
        include __DIR__ . '/../includes/student_navbar.php';
        ?>

        <section class="content-wrapper">
            <!-- 狀態 + 日曆按鈕 合併為一列 -->
            <div style="margin-bottom:1rem; background:white; border-radius:14px; padding:.75rem 1.1rem; box-shadow:0 2px 8px rgba(15,23,42,.06);">
                <div style="display:flex; align-items:center; justify-content:space-between; flex-wrap:wrap; gap:.5rem;">
                    <div style="display:flex; align-items:center; gap:.75rem; flex-wrap:wrap;">
                        <?php if (!$active_setting): ?>
                            <span style="display:inline-flex;align-items:center;gap:.35rem;font-size:.83rem;font-weight:600;color:#92400e;background:#fef3c7;padding:.25rem .7rem;border-radius:20px;"><i class="bi bi-clock"></i> 登記未開放</span>
                        <?php elseif ($has_meeting_passed): ?>
                            <span style="display:inline-flex;align-items:center;gap:.35rem;font-size:.83rem;font-weight:600;color:#6b7280;background:#f3f4f6;padding:.25rem .7rem;border-radius:20px;"><i class="bi bi-check2-all"></i> 場協已結束</span>
                            <span style="font-size:.82rem;color:#9ca3af;">大會：<?= date('Y-m-d', strtotime($active_setting['coordination_meeting_date'])) ?></span>
                        <?php else: ?>
                            <span style="display:inline-flex;align-items:center;gap:.35rem;font-size:.83rem;font-weight:600;color:#065f46;background:#d1fae5;padding:.25rem .7rem;border-radius:20px;"><i class="bi bi-check-circle-fill"></i> 登記開放中</span>
                            <span style="font-size:.82rem;color:#6b7280;">開放登記時間 <?= date('m/d', strtotime($active_setting['registration_start_date'])) ?> ~ <?= date('m/d', strtotime($active_setting['registration_end_date'])) ?></span>
                            <span style="font-size:.82rem;color:#6b7280;">・協調大會 <?= date('m/d H:i', strtotime($active_setting['coordination_meeting_date'])) ?></span>
                        <?php endif; ?>
                    </div>
                    <a href="calendar.php" style="display:inline-flex;align-items:center;gap:.35rem;font-size:.83rem;font-weight:600;color:#1e4d6b;background:#e8f0f5;padding:.3rem .85rem;border-radius:20px;text-decoration:none;" onmouseover="this.style.background='#d5e3ea'" onmouseout="this.style.background='#e8f0f5'">
                        <i class="bi bi-calendar3"></i> 查看場地日曆
                    </a>
                </div>
                <?php if ($active_setting && !$has_meeting_passed && $borrow_min && $borrow_max): ?>
                <div style="font-size:.82rem;color:#6b7280;margin-top:.5rem;padding-top:.5rem;border-top:1px solid #f1f5f9;line-height:1.6;">
                    <i class="bi bi-info-circle"></i>
                    目前場地協調是登記下學期 <?= date('m/d', strtotime($borrow_min)) ?> 到 <?= date('m/d', strtotime($borrow_max)) ?> 的場地，
                    若有衝突，將於 <?= date('m/d', strtotime($active_setting['coordination_meeting_date'])) ?> 的場協大會現場協調，或可自行寄送 email 給衝突方事先協商。
                </div>
                <?php endif; ?>
            </div>

            <?php if (!empty($message)): ?>
            <div class="card" style="border-left: 5px solid <?= $message_type === 'success' ? '#10b981' : ($message_type === 'warning' ? '#f59e0b' : '#ef4444'); ?>;">
                <h3><?= $message_type === 'success' ? '登記成功' : ($message_type === 'warning' ? '衝突提示' : '錯誤提醒') ?></h3>
                <p class="text-muted mb-0"><?= $message ?></p>
            </div>
            <?php endif; ?>

            <!-- 顯示待確認的衝突 -->
            <?php if (isset($_SESSION['pending_conflicts']) && !empty($_SESSION['pending_conflicts'])): ?>
            <div class="card" style="border: 2px solid #f59e0b; background: #fffbf0;">
                <h3><i class="bi bi-exclamation-triangle"></i> 請確認衝突</h3>
                <p class="text-muted">您的場地申請與以下活動存在時間衝突，請左右滑動查看所有衝突項目，並確認是否繼續提交：</p>
                <div class="conflict-track">
                    <?php foreach ($_SESSION['pending_conflicts'] as $conflict): ?>
                    <div class="conflict-track-item">
                        <div class="conflict-track-club"><i class="bi bi-people-fill"></i> <?= htmlspecialchars($conflict['conflicting_club'], ENT_QUOTES, 'UTF-8') ?></div>
                        <div class="conflict-track-event"><?= htmlspecialchars($conflict['conflicting_event'], ENT_QUOTES, 'UTF-8') ?></div>
                        <div class="conflict-track-meta"><i class="bi bi-geo-alt"></i> <?= htmlspecialchars($spaces[$conflict['space_id']]['space_name'] ?? '未知', ENT_QUOTES, 'UTF-8') ?></div>
                        <div class="conflict-track-meta"><i class="bi bi-clock"></i> <?= htmlspecialchars($conflict['conflicting_time'], ENT_QUOTES, 'UTF-8') ?></div>
                    </div>
                    <?php endforeach; ?>
                </div>
                <form method="post" id="conflict_form">
                    <input type="hidden" name="acknowledged_conflicts" value="1">
                    <?php foreach ($_SESSION['pending_form_data'] as $key => $value): ?>
                        <?php if ($key === 'sessions' && is_array($value)): ?>
                            <?php foreach ($value as $si => $sess): ?>
                                <?php if (is_array($sess)): ?>
                                    <?php foreach ($sess as $field => $fval): ?>
                                    <input type="hidden" name="sessions[<?= $si ?>][<?= htmlspecialchars($field, ENT_QUOTES, 'UTF-8') ?>]" value="<?= htmlspecialchars($fval, ENT_QUOTES, 'UTF-8') ?>">
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            <?php endforeach; ?>
                        <?php elseif (is_array($value)): ?>
                            <?php foreach ($value as $v): ?>
                            <input type="hidden" name="<?= htmlspecialchars($key, ENT_QUOTES, 'UTF-8') ?>[]" value="<?= htmlspecialchars($v, ENT_QUOTES, 'UTF-8') ?>">
                            <?php endforeach; ?>
                        <?php else: ?>
                            <input type="hidden" name="<?= htmlspecialchars($key, ENT_QUOTES, 'UTF-8') ?>" value="<?= htmlspecialchars($value, ENT_QUOTES, 'UTF-8') ?>">
                        <?php endif; ?>
                    <?php endforeach; ?>
                    <div class="d-flex gap-2">
                        <button type="submit" class="btn btn-warning">繼續提交（確認衝突）</button>
                        <button type="button" class="btn btn-secondary" onclick="location.reload();">取消修改</button>
                    </div>
                </form>
            </div>
            <?php endif; ?>

            <div class="card">
                <h3><i class="bi bi-grid-1x2"></i> 場地協調登記</h3>
                <?php if (!$active_setting || $has_meeting_passed): ?>
                <div class="alert alert-warning">
                    <i class="bi bi-info-circle"></i>
                    <?php if ($is_closed): ?>
                    場協結果整理中，請等待 <?= date('n月j日', strtotime($latest_setting['coordination_meeting_date'])) ?> 的場協大會。如有問題可聯絡課指組老師。
                    <?php elseif (!$active_setting): ?>
                    目前不在場協登記期間，表單已禁用。
                    <?php else: ?>
                    場地協調大會已結束，場地申請已恢復至先到先得制。
                    <?php endif; ?>
                </div>
                <?php endif; ?>
                <form id="reg_form" method="post" <?php if (!$active_setting || $has_meeting_passed) echo 'style="opacity: 0.5; pointer-events: none;"'; ?>>
                    <input type="hidden" name="register_spaces" value="1">
                    <input type="hidden" name="club_id" value="<?= htmlspecialchars($selected_club_id, ENT_QUOTES, 'UTF-8') ?>">
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label" for="club_name">主辦社團 *</label>
                            <input id="club_name" class="form-control" value="<?= htmlspecialchars($selected_club_name, ENT_QUOTES, 'UTF-8') ?>" readonly>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label" for="event_name">活動名稱 *</label>
                            <input id="event_name" name="event_name" class="form-control" value="<?= htmlspecialchars($_SESSION['pending_form_data']['event_name'] ?? $_POST['event_name'] ?? '', ENT_QUOTES, 'UTF-8') ?>" required>
                        </div>
                    </div>
                    <div class="row g-3 mt-0">
                        <div class="col-md-6">
                            <label class="form-label" for="responsible_person">活動負責人 *</label>
                            <input id="responsible_person" name="responsible_person" class="form-control" value="<?= htmlspecialchars($_SESSION['pending_form_data']['responsible_person'] ?? $_POST['responsible_person'] ?? '', ENT_QUOTES, 'UTF-8') ?>" placeholder="負責人姓名（例：社長 王小明）" required>
                        </div>
                    </div>
                    <!-- 活動場次 -->
                    <div class="mt-4">
                        <label class="form-label fw-semibold">活動場次 <span class="text-danger">*</span></label>
                        <p class="text-muted" style="font-size:0.9rem; margin-bottom:0.75rem;">每個場次可設定不同日期、時間與場地。若有固定週次練習，請逐一新增場次。</p>
                        <div id="sessions_container">
                            <?php foreach ($sessions_data as $si => $sess): ?>
                            <div class="session-row" data-idx="<?= $si ?>">
                                <div class="session-header">
                                    <span class="session-label"><i class="bi bi-calendar3"></i> 場次 <?= $si + 1 ?></span>
                                    <button type="button" class="btn-remove-session" onclick="removeSession(this)" style="display:<?= count($sessions_data) > 1 ? 'inline-flex' : 'none' ?>;">
                                        <i class="bi bi-trash"></i> 刪除
                                    </button>
                                </div>
                                <div class="session-fields">
                                    <div class="session-field">
                                        <label>開始日期 *</label>
                                        <input type="date" name="sessions[<?= $si ?>][date]" class="form-control" value="<?= htmlspecialchars($sess['date'] ?? '', ENT_QUOTES, 'UTF-8') ?>" <?= $borrow_min ? 'min="'.$borrow_min.'"' : '' ?> <?= $borrow_max ? 'max="'.$borrow_max.'"' : '' ?> required>
                                    </div>
                                    <div class="session-field">
                                        <label>開始時間 *</label>
                                        <?php $sv=$sess['start_time']??''; $sh=$sv?substr($sv,0,2):''; $sm=$sv?substr($sv,3,2):''; ?>
                                        <div class="time-selects d-flex align-items-center gap-1">
                                            <select class="form-select time-hour" style="width:auto">
                                                <option value="">時</option>
                                                <?php for($h=8;$h<=21;$h++){$hh=sprintf('%02d',$h);echo "<option value=\"$hh\"".($sh===$hh?' selected':'').">$hh</option>";}?>
                                            </select>
                                            <span style="padding:0 4px;font-weight:600">:</span>
                                            <select class="form-select time-minute" style="width:auto">
                                                <option value="">分</option>
                                                <?php foreach([0,10,20,30,40,50] as $m){$mm=sprintf('%02d',$m);echo "<option value=\"$mm\"".($sm===$mm?' selected':'').">$mm</option>";}?>
                                            </select>
                                        </div>
                                        <input type="hidden" name="sessions[<?= $si ?>][start_time]" class="time-value" value="<?= htmlspecialchars($sv,ENT_QUOTES,'UTF-8') ?>">
                                    </div>
                                    <div class="session-field">
                                        <label>結束日期 *</label>
                                        <input type="date" name="sessions[<?= $si ?>][end_date]" class="form-control" value="<?= htmlspecialchars($sess['end_date'] ?? $sess['date'] ?? '', ENT_QUOTES, 'UTF-8') ?>" <?= $borrow_min ? 'min="'.$borrow_min.'"' : '' ?> <?= $borrow_max ? 'max="'.$borrow_max.'"' : '' ?> required>
                                    </div>
                                    <div class="session-field">
                                        <label>結束時間 *</label>
                                        <?php $ev=$sess['end_time']??''; $eh=$ev?substr($ev,0,2):''; $em=$ev?substr($ev,3,2):''; ?>
                                        <div class="time-selects d-flex align-items-center gap-1">
                                            <select class="form-select time-hour" style="width:auto">
                                                <option value="">時</option>
                                                <?php for($h=8;$h<=21;$h++){$hh=sprintf('%02d',$h);echo "<option value=\"$hh\"".($eh===$hh?' selected':'').">$hh</option>";}?>
                                            </select>
                                            <span style="padding:0 4px;font-weight:600">:</span>
                                            <select class="form-select time-minute" style="width:auto">
                                                <option value="">分</option>
                                                <?php foreach([0,10,20,30,40,50] as $m){$mm=sprintf('%02d',$m);echo "<option value=\"$mm\"".($em===$mm?' selected':'').">$mm</option>";}?>
                                            </select>
                                        </div>
                                        <input type="hidden" name="sessions[<?= $si ?>][end_time]" class="time-value" value="<?= htmlspecialchars($ev,ENT_QUOTES,'UTF-8') ?>">
                                    </div>
                                    <div class="session-field">
                                        <label>場地 *</label>
                                        <select name="sessions[<?= $si ?>][space_id]" class="form-control" required>
                                            <option value="">-- 選擇場地 --</option>
                                            <?php foreach ($buildings as $building): ?>
                                            <optgroup label="<?= htmlspecialchars($building['name'], ENT_QUOTES, 'UTF-8') ?>">
                                                <?php foreach ($building['rooms'] as $r): ?>
                                                <option value="<?= $r['space_id'] ?>" <?= ($sess['space_id'] ?? '') == $r['space_id'] ? 'selected' : '' ?>><?= htmlspecialchars($r['space_name'], ENT_QUOTES, 'UTF-8') ?></option>
                                                <?php endforeach; ?>
                                            </optgroup>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                </div>
                            </div>
                            <?php endforeach; ?>
                        </div>
                        <button type="button" class="btn-add-session" onclick="addSession()">
                            <i class="bi bi-plus-circle"></i> 新增場次
                        </button>
                    </div>
                    <div class="mt-3">
                        <label class="form-label" for="description">備註</label>
                        <textarea id="description" name="description" class="form-control" rows="3"><?= htmlspecialchars($_SESSION['pending_form_data']['description'] ?? $_POST['description'] ?? '', ENT_QUOTES, 'UTF-8') ?></textarea>
                    </div>
                    <div class="mt-4 d-flex flex-wrap gap-2">
                        <button type="submit" class="btn btn-primary" <?php if (!$active_setting || $has_meeting_passed) echo 'disabled'; ?>>提交批次登記</button>
                        <button type="button" class="btn btn-secondary" onclick="location.href='calendar.php'">先查看日曆</button>
                    </div>
                    <p class="mt-3 text-muted" style="font-size:0.95rem;">* 若您是社團幹部，系統會自動帶入您目前身份對應的社團。</p>
                </form>
            </div>

            <div class="card">
                <h3>聯絡資訊</h3>
                <div class="contact-info">
                    <div class="contact-item">
                        <i class="bi bi-telephone"></i>
                        <div>
                            <strong>輔仁大學課外活動指導組 張秉倪輔導老師</strong><br>
                            電話：(02) 2905-2233 轉 3085
                        </div>
                    </div>
                    <div class="contact-item">
                        <i class="bi bi-envelope"></i>
                        <div>
                            <strong>電子郵件</strong><br>
                            163341@mail.fju.edu.tw
                        </div>
                    </div>
                    <div class="contact-item">
                        <i class="bi bi-clock"></i>
                        <div>
                            <strong>服務時間</strong><br>
                            週一至週五 08:00-16:30
                        </div>
                    </div>
                    <div class="contact-item">
                        <i class="bi bi-geo-alt"></i>
                        <div>
                            <strong>辦公室位置</strong><br>
                            輔仁大學 課外活動指導組(法籃旁)
                        </div>
                    </div>
                </div>
            </div>
        </section>
    </main>

    <script>
    // ── 場地資料（按大樓分組）────────────────────────────────
    var spacesForJs = <?= json_encode($spaces_for_js, JSON_UNESCAPED_UNICODE) ?>;
    var sessionCount = <?= count($sessions_data) ?>;
    var BORROW_MIN = <?= json_encode($borrow_min) ?>;
    var BORROW_MAX = <?= json_encode($borrow_max) ?>;

    function escHtml(str) {
        return String(str)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;');
    }

    function buildTimeSelects(fieldName, value) {
        var h = value ? value.substring(0, 2) : '';
        var m = value ? value.substring(3, 5) : '';
        var hoursOpts = '<option value="">時</option>';
        for (var hr = 8; hr <= 21; hr++) {
            var hh = String(hr).padStart(2, '0');
            hoursOpts += '<option value="' + hh + '"' + (h === hh ? ' selected' : '') + '>' + hh + '</option>';
        }
        var minsOpts = '<option value="">分</option>';
        [0,10,20,30,40,50].forEach(function(mn) {
            var mm = String(mn).padStart(2, '0');
            minsOpts += '<option value="' + mm + '"' + (m === mm ? ' selected' : '') + '>' + mm + '</option>';
        });
        return '<div class="time-selects d-flex align-items-center gap-1">'
            + '<select class="form-select time-hour" style="width:auto">' + hoursOpts + '</select>'
            + '<span style="padding:0 4px;font-weight:600">:</span>'
            + '<select class="form-select time-minute" style="width:auto">' + minsOpts + '</select>'
            + '</div>'
            + '<input type="hidden" name="' + fieldName + '" class="time-value" value="' + (value || '') + '">';
    }

    function syncTimeValue(selectEl) {
        var sf = selectEl.closest('.session-field');
        if (!sf) return;
        var h = sf.querySelector('.time-hour').value;
        var m = sf.querySelector('.time-minute').value;
        var hidden = sf.querySelector('.time-value');
        if (hidden) hidden.value = (h && m) ? h + ':' + m : '';
    }

    // ── 依時段限制可選分鐘（08:30–21:30，08時只能選30/40/50，21時只能選00/10/20/30）──
    function applyMinuteRestriction(timeSelectsDiv) {
        var hourSel = timeSelectsDiv.querySelector('.time-hour');
        var minSel  = timeSelectsDiv.querySelector('.time-minute');
        if (!hourSel || !minSel) return;
        var disabledMins = [];
        if (hourSel.value === '08') disabledMins = ['00','10','20'];
        else if (hourSel.value === '21') disabledMins = ['40','50'];
        Array.prototype.forEach.call(minSel.options, function(opt) {
            if (opt.value === '') return;
            opt.disabled = disabledMins.indexOf(opt.value) !== -1;
        });
        if (minSel.value && disabledMins.indexOf(minSel.value) !== -1) minSel.value = '';
    }

    document.addEventListener('change', function(e) {
        if (e.target && e.target.classList.contains('time-hour')) {
            applyMinuteRestriction(e.target.closest('.time-selects'));
        }
        if (e.target && (e.target.classList.contains('time-hour') || e.target.classList.contains('time-minute'))) {
            syncTimeValue(e.target);
        }
    });

    document.querySelectorAll('#sessions_container .time-selects').forEach(applyMinuteRestriction);

    function buildSpaceOptions(selectedId) {
        var html = '<option value="">-- 選擇場地 --</option>';
        spacesForJs.forEach(function(grp) {
            html += '<optgroup label="' + escHtml(grp.label) + '">';
            grp.options.forEach(function(opt) {
                var sel = (selectedId && String(opt.id) === String(selectedId)) ? ' selected' : '';
                html += '<option value="' + opt.id + '"' + sel + '>' + escHtml(opt.name) + '</option>';
            });
            html += '</optgroup>';
        });
        return html;
    }

    // ── 場次新增 / 刪除 / 重新編號 ──────────────────────────
    function addSession() {
        var idx = sessionCount;
        sessionCount++;
        var dateMinMax = (BORROW_MIN ? ' min="' + BORROW_MIN + '"' : '') + (BORROW_MAX ? ' max="' + BORROW_MAX + '"' : '');
        var html = '<div class="session-row" data-idx="' + idx + '">'
            + '<div class="session-header">'
            +   '<span class="session-label"><i class="bi bi-calendar3"></i> 場次</span>'
            +   '<button type="button" class="btn-remove-session" onclick="removeSession(this)">'
            +     '<i class="bi bi-trash"></i> 刪除'
            +   '</button>'
            + '</div>'
            + '<div class="session-fields">'
            +   '<div class="session-field">'
            +     '<label>開始日期 *</label>'
            +     '<input type="date" name="sessions[' + idx + '][date]" class="form-control"' + dateMinMax + ' required>'
            +   '</div>'
            +   '<div class="session-field">'
            +     '<label>開始時間 *</label>'
            +     buildTimeSelects('sessions[' + idx + '][start_time]', '')
            +   '</div>'
            +   '<div class="session-field">'
            +     '<label>結束日期 *</label>'
            +     '<input type="date" name="sessions[' + idx + '][end_date]" class="form-control"' + dateMinMax + ' required>'
            +   '</div>'
            +   '<div class="session-field">'
            +     '<label>結束時間 *</label>'
            +     buildTimeSelects('sessions[' + idx + '][end_time]', '')
            +   '</div>'
            +   '<div class="session-field">'
            +     '<label>場地 *</label>'
            +     '<select name="sessions[' + idx + '][space_id]" class="form-control" required>'
            +       buildSpaceOptions('')
            +     '</select>'
            +   '</div>'
            + '</div>'
            + '</div>';
        document.getElementById('sessions_container').insertAdjacentHTML('beforeend', html);
        renumberSessions();
        document.querySelectorAll('#sessions_container .time-selects').forEach(applyMinuteRestriction);
    }

    function removeSession(btn) {
        var rows = document.querySelectorAll('.session-row');
        if (rows.length <= 1) { alert('至少需要保留一個場次！'); return; }
        btn.closest('.session-row').remove();
        renumberSessions();
    }

    function renumberSessions() {
        var rows = document.querySelectorAll('.session-row');
        rows.forEach(function(row, i) {
            row.dataset.idx = i;
            row.querySelector('.session-label').innerHTML = '<i class="bi bi-calendar3"></i> 場次 ' + (i + 1);
            row.querySelectorAll('input[name], select[name]').forEach(function(el) {
                el.name = el.name.replace(/sessions\[\d+\]/, 'sessions[' + i + ']');
            });
            var removeBtn = row.querySelector('.btn-remove-session');
            if (removeBtn) removeBtn.style.display = rows.length > 1 ? 'inline-flex' : 'none';
        });
        sessionCount = rows.length;
    }

    // ── 開始日期自動同步至結束日期 ───────────────────────────
    document.getElementById('sessions_container').addEventListener('change', function(e) {
        if (!e.target) return;
        if (e.target.name && e.target.name.indexOf('[date]') !== -1) {
            // 當開始日期變更時，若結束日期為空則自動填入相同日期
            var row = e.target.closest('.session-row');
            if (row) {
                var endDateInput = row.querySelector('[name*="[end_date]"]');
                if (endDateInput && !endDateInput.value) {
                    endDateInput.value = e.target.value;
                }
            }
        }
    });

    // ── 表單提交驗證 ─────────────────────────────────────────
    var regForm = document.getElementById('reg_form');
    if (regForm) {
        regForm.addEventListener('submit', function(e) {
            var rows = document.querySelectorAll('.session-row');
            if (rows.length === 0) {
                e.preventDefault(); alert('請至少新增一個場次！'); return;
            }
            for (var i = 0; i < rows.length; i++) {
                var n   = i + 1;
                var di  = rows[i].querySelector('[name*="[date]"]');
                var sti = rows[i].querySelector('[name*="[start_time]"]');
                var edi = rows[i].querySelector('[name*="[end_date]"]');
                var eti = rows[i].querySelector('[name*="[end_time]"]');
                var spi = rows[i].querySelector('[name*="[space_id]"]');
                if (!di  || !di.value)  { e.preventDefault(); alert('場次' + n + '：請選擇開始日期！');  di.focus();  return; }
                if (!sti || !sti.value) { e.preventDefault(); alert('場次' + n + '：請填寫開始時間！'); sti.focus(); return; }
                if (!edi || !edi.value) { e.preventDefault(); alert('場次' + n + '：請選擇結束日期！');  edi.focus(); return; }
                if (!eti || !eti.value) { e.preventDefault(); alert('場次' + n + '：請填寫結束時間！'); eti.focus(); return; }
                if (edi.value < di.value) {
                    e.preventDefault(); alert('場次' + n + '：結束日期不能早於開始日期！'); return;
                }
                if (edi.value === di.value && sti.value >= eti.value) {
                    e.preventDefault(); alert('場次' + n + '：同日結束時間必須晚於開始時間！'); return;
                }
                if (spi && !spi.value) { e.preventDefault(); alert('場次' + n + '：請選擇場地！'); spi.focus(); return; }
            }
        });
    }
    </script>
</body>
</html>