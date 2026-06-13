<?php
session_start();


require_once(__DIR__ . "/../DB/db_config.php");
require_once(__DIR__ . "/../includes/FieldCoordinationManager.php");

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'student') {
    header('Location: ../login.php');
    exit();
}

$current_page = 'apply_event';
$message = "";
$message_type = "";
$user_id = $_SESSION['user_id'] ?? null;
$field_coordination_results = [];
$fc_manager = null;

$my_clubs = [];
$current_user_club = "";
$selected_club_id = "";
if ($user_id) {
    $fc_manager = new FieldCoordinationManager($conn);
    $club_sql = "SELECT cm.club_id, c.club_name FROM club_members cm JOIN clubs c ON cm.club_id = c.club_id WHERE cm.user_id = ?";
    $club_stmt = $conn->prepare($club_sql);
    if ($club_stmt) {
        $club_stmt->bind_param("i", $user_id);
        $club_stmt->execute();
        $club_result = $club_stmt->get_result();
        while ($club_row = $club_result->fetch_assoc()) $my_clubs[] = $club_row;
        $club_stmt->close();
    }

    // 決定目前使用的社團（使用者可能同時隸屬多個社團，需明確指定本次申請的主辦社團）
    $requested_club_id = $_POST['club_id'] ?? ($_GET['club_id'] ?? '');
    foreach ($my_clubs as $c) {
        if ($requested_club_id !== '' && $c['club_id'] === $requested_club_id) {
            $selected_club_id  = $c['club_id'];
            $current_user_club = $c['club_name'];
            break;
        }
    }
    if ($selected_club_id === '' && !empty($_SESSION['current_club_id'])) {
        foreach ($my_clubs as $c) {
            if ($c['club_id'] === $_SESSION['current_club_id']) {
                $selected_club_id  = $c['club_id'];
                $current_user_club = $c['club_name'];
                break;
            }
        }
    }
    if ($selected_club_id === '' && !empty($my_clubs)) {
        $selected_club_id  = $my_clubs[0]['club_id'];
        $current_user_club = $my_clubs[0]['club_name'];
    }
    if ($selected_club_id !== '') {
        $field_coordination_results = $fc_manager->getAllApprovedFieldCoordinationForClub($selected_club_id);
        if (empty($field_coordination_results)) $field_coordination_results = [];
    }
}

// 場地清單
$sql_spaces = "SELECT space_id, space_name, capacity FROM spaces WHERE space_status = 'available' ORDER BY space_id";
$result_spaces = $conn->query($sql_spaces);
$venues = $result_spaces ? $result_spaces->fetch_all(MYSQLI_ASSOC) : [];

// 器材清單
$sql_equipment = "SELECT equipment_id, name, code, total_quantity, borrowing_limit FROM equipment WHERE equipment_status = 'available'";
$result_equipment = $conn->query($sql_equipment);
if (!$result_equipment) {
    $result_equipment = $conn->query("SELECT equipment_id, name, code, total_quantity, borrowing_limit FROM equipment");
}
$equipment = [];
if ($result_equipment) {
    foreach ($result_equipment->fetch_all(MYSQLI_ASSOC) as $eq) {
        $equipment[] = [
            'id'              => $eq['equipment_id'],
            'name'            => $eq['name'],
            'code'            => $eq['code'] ?? '',
            'total'           => $eq['total_quantity'],
            'available'       => $eq['total_quantity'],
            'borrowing_limit' => (int)($eq['borrowing_limit'] ?? 0),
        ];
    }
}

// 表單送出時的場次資料（用於還原）
$sessions_data = [['date'=>'','start_time'=>'','end_date'=>'','end_time'=>'','venue_id'=>'']];
$today_date = date('Y-m-d');

// ── Flash 訊息（PRG 後顯示）────────────────────────────────
if (!empty($_SESSION['flash_message'])) {
    $message      = $_SESSION['flash_message'];
    $message_type = $_SESSION['flash_message_type'] ?? 'success';
    unset($_SESSION['flash_message'], $_SESSION['flash_message_type']);
}

// 防重複提交 Token（GET 時生成；POST 時驗證後銷毀）
$form_token_ok = false;
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    $_SESSION['form_submit_token'] = bin2hex(random_bytes(16));
} else {
    $submitted_token = trim($_POST['form_token'] ?? '');
    if (!empty($submitted_token) && !empty($_SESSION['form_submit_token']) &&
        hash_equals((string)$_SESSION['form_submit_token'], (string)$submitted_token)) {
        $form_token_ok = true;
        unset($_SESSION['form_submit_token']);
    } else {
        $message      = "⚠️ 申請已提交，請勿重複送出。如需新增申請，請重新整理此頁面。";
        $message_type = "warning";
    }
}

// ── 處理表單提交 ──────────────────────────────────────────
if ($_SERVER["REQUEST_METHOD"] == "POST" && $form_token_ok) {
    $club_name          = $current_user_club; // 依使用者實際所屬社團（多重身分時依表單選擇）決定，避免送出他人社團資料
    $event_name         = trim($_POST['event_name'] ?? '');
    $responsible_person = trim($_POST['responsible_person'] ?? '');
    $event_type         = trim($_POST['event_type'] ?? '校內');
    $activity_location  = trim($_POST['activity_location'] ?? '');
    $activity_scale     = trim($_POST['activity_scale'] ?? '一般活動');
    $activity_flags     = isset($_POST['activity_flags']) && is_array($_POST['activity_flags']) ? $_POST['activity_flags'] : [];
    $description        = trim($_POST['description'] ?? '');
    $sessions           = isset($_POST['sessions']) && is_array($_POST['sessions']) ? $_POST['sessions'] : [];

    // 忽略未填寫開始日期的場次（使用者多按了新增場次但未填寫）
    $sessions = array_values(array_filter($sessions, function($sess) {
        return !empty($sess['date']);
    }));

    // 還原場次以便顯示
    $sessions_data = !empty($sessions) ? $sessions : [['date'=>'','start_time'=>'','end_date'=>'','end_time'=>'','venue_id'=>'']];

    $scale_parts = [$activity_scale];
    foreach ($activity_flags as $f) {
        if (in_array($f, ['含酒精活動', '使用火源活動'])) $scale_parts[] = $f;
    }
    $activity_scale_str = implode(',', $scale_parts);

    $errors = [];
    if (empty($event_name))         $errors[] = "請填寫活動名稱";
    if (empty($club_name))          $errors[] = "找不到您所屬的社團資料，請聯絡系統管理員";
    if (empty($responsible_person)) $errors[] = "請填寫活動負責人";
    if ($event_type === '校外' && empty($activity_location)) $errors[] = "校外活動請填寫活動地點";
    if (empty($sessions))           $errors[] = "請至少新增一個場次";

    foreach ($sessions as $i => $sess) {
        $n = $i + 1;
        if (empty($sess['date']))       $errors[] = "場次{$n}：請選擇開始日期";
        if (empty($sess['start_time'])) $errors[] = "場次{$n}：請填寫開始時間";
        if (empty($sess['end_date']))   $errors[] = "場次{$n}：請選擇結束日期";
        if (empty($sess['end_time']))   $errors[] = "場次{$n}：請填寫結束時間";
        if (!empty($sess['date']) && $sess['date'] < $today_date)
            $errors[] = "場次{$n}：開始日期不能為過往日期，請選擇今日或之後的日期";
        if (!empty($sess['end_date']) && $sess['end_date'] < $today_date)
            $errors[] = "場次{$n}：結束日期不能為過往日期，請選擇今日或之後的日期";
        if (!empty($sess['date']) && !empty($sess['end_date']) && $sess['end_date'] < $sess['date'])
            $errors[] = "場次{$n}：結束日期不能早於開始日期";
        if (!empty($sess['date']) && !empty($sess['end_date']) && $sess['date'] === $sess['end_date'] &&
            !empty($sess['start_time']) && !empty($sess['end_time']) && $sess['start_time'] >= $sess['end_time'])
            $errors[] = "場次{$n}：同日結束時間必須晚於開始時間";
        if (!empty($sess['start_time']) && ($sess['start_time'] < '08:30' || $sess['start_time'] > '21:30'))
            $errors[] = "場次{$n}：時間須在 08:30–21:30";
        if (!empty($sess['end_time']) && ($sess['end_time'] < '08:30' || $sess['end_time'] > '21:30'))
            $errors[] = "場次{$n}：時間須在 08:30–21:30";
        if ($event_type === '校內' && empty($sess['venue_id']))
            $errors[] = "場次{$n}：校內活動請選擇場地";
        // 器材借用開始時間不能是過去（防止繞過前端 min 限制）
        if (!empty($sess['borrow_start'])) {
            $bs_raw = trim($sess['borrow_start']);
            if (preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}$/', $bs_raw)) {
                $bs_ts = strtotime(str_replace('T', ' ', $bs_raw) . ':00');
                if ($bs_ts !== false && $bs_ts < time()) {
                    $errors[] = "場次{$n}：器材借用開始時間不能早於現在";
                }
            }
        }
    }

    // 後端防重複：10秒內同帳號同活動名稱同社團視為重複送出
    if (empty($errors)) {
        $dup_stmt = $conn->prepare(
            "SELECT event_id FROM events
             WHERE user_id = ? AND event_name = ? AND club_name = ?
               AND created_at >= DATE_SUB(NOW(), INTERVAL 10 SECOND)
             LIMIT 1"
        );
        $dup_stmt->bind_param('iss', $user_id, $event_name, $club_name);
        $dup_stmt->execute();
        if ($dup_stmt->get_result()->num_rows > 0) {
            $errors[] = "偵測到重複送出，請勿在短時間內重複點擊提交。";
        }
        $dup_stmt->close();
    }

    if (empty($errors)) {
        $conn->begin_transaction();
        try {
            // 企劃書上傳
            $base_dir   = realpath(__DIR__ . DIRECTORY_SEPARATOR . '..');
            $upload_dir = $base_dir . DIRECTORY_SEPARATOR . 'document' . DIRECTORY_SEPARATOR;
            if (!is_dir($upload_dir)) mkdir($upload_dir, 0777, true);
            $proposal_filename = null;
            if (isset($_FILES['proposal_document']) && $_FILES['proposal_document']['error'] == UPLOAD_ERR_OK) {
                $finfo    = finfo_open(FILEINFO_MIME_TYPE);
                $realMime = finfo_file($finfo, $_FILES['proposal_document']['tmp_name']);
                finfo_close($finfo);
                if ($realMime !== 'application/pdf') {
                    throw new Exception("企劃書只允許上傳 PDF 格式。");
                }
                $fn = 'proposal_' . time() . '_' . uniqid() . '.pdf';
                if (move_uploaded_file($_FILES['proposal_document']['tmp_name'], $upload_dir . $fn)) {
                    $proposal_filename = $fn;
                } else {
                    throw new Exception("企劃書上傳失敗，請重試。");
                }
            }

            // 計算整體 start/end（所有場次的最小開始日期時間、最大結束日期時間）
            $event_start = null;
            $event_end   = null;
            foreach ($sessions as $sess) {
                $s = $sess['date']                              . ' ' . $sess['start_time'] . ':00';
                $e = ($sess['end_date'] ?? $sess['date'])       . ' ' . $sess['end_time']   . ':00';
                if ($event_start === null || $s < $event_start) $event_start = $s;
                if ($event_end   === null || $e > $event_end)   $event_end   = $e;
            }

            if (!$user_id) throw new Exception("登入逾時，請重新登入。");

            // 判斷是否在場協大會前（登記期間 + 登記結束到大會之間）
            $before_meeting   = $fc_manager ? $fc_manager->isBeforeCoordinationMeeting() : false;
            $fc_conflict_warn = false;

            // 校內活動：逐場次衝突檢查
            if ($event_type === '校內') {
                foreach ($sessions as $i => $sess) {
                    $vid = intval($sess['venue_id']);
                    if ($vid <= 0) continue;
                    $s = $sess['date']                      . ' ' . $sess['start_time'] . ':00';
                    $e = ($sess['end_date']??$sess['date']) . ' ' . $sess['end_time']   . ':00';
                    $n = $i + 1;

                    if ($before_meeting) {
                        // 場協大會前：確認的預約仍需阻擋
                        $stmt_c = $conn->prepare(
                            "SELECT ev.club_name FROM reservations r JOIN events ev ON r.event_id=ev.event_id
                             WHERE r.space_id=? AND NOT(r.end_time<=? OR r.start_time>=?)
                             AND ev.club_name!=? AND r.is_field_coordination_preliminary=0 LIMIT 1"
                        );
                        $stmt_c->bind_param("isss", $vid, $s, $e, $club_name);
                        $stmt_c->execute();
                        if ($stmt_c->get_result()->num_rows > 0)
                            throw new Exception("場次{$n}：該時段場地已被其他社團預約，請選擇其他時間或場地。");
                        $stmt_c->close();

                        // 場協暫定預約：允許但記錄警告
                        $stmt_fc = $conn->prepare(
                            "SELECT ev.club_name FROM reservations r JOIN events ev ON r.event_id=ev.event_id
                             WHERE r.space_id=? AND NOT(r.end_time<=? OR r.start_time>=?)
                             AND ev.club_name!=? AND r.is_field_coordination_preliminary=1 LIMIT 1"
                        );
                        $stmt_fc->bind_param("isss", $vid, $s, $e, $club_name);
                        $stmt_fc->execute();
                        if ($stmt_fc->get_result()->num_rows > 0) $fc_conflict_warn = true;
                        $stmt_fc->close();
                    } else {
                        // 場協大會後：嚴格阻擋所有衝突
                        $stmt_c = $conn->prepare(
                            "SELECT ev.club_name FROM reservations r JOIN events ev ON r.event_id=ev.event_id
                             WHERE r.space_id=? AND NOT(r.end_time<=? OR r.start_time>=?) AND ev.club_name!=? LIMIT 1"
                        );
                        $stmt_c->bind_param("isss", $vid, $s, $e, $club_name);
                        $stmt_c->execute();
                        if ($stmt_c->get_result()->num_rows > 0)
                            throw new Exception("場次{$n}：該時段場地已被其他社團預約，請選擇其他時間或場地。");
                        $stmt_c->close();
                    }
                }
            }

            // INSERT 活動記錄
            $sql_ev = "INSERT INTO events (user_id,event_name,club_name,description,start_time,end_time,
                        responsible_person,event_type,activity_location,activity_scale,proposal_doc_path,status)
                       VALUES (?,?,?,?,?,?,?,?,?,?,?,'pending')";
            $stmt_ev = $conn->prepare($sql_ev);
            if (!$stmt_ev) {
                // 降級（欄位不存在時）
                $desc_ex = $description . ($description?"\n---\n":"") .
                           "負責人：{$responsible_person}　類型：{$event_type}" .
                           ($activity_location?"\n地點：{$activity_location}":"") .
                           ($activity_scale_str?"\n特殊：{$activity_scale_str}":"");
                $sql_ev2 = "INSERT INTO events (user_id,event_name,club_name,description,start_time,end_time,status) VALUES (?,?,?,?,?,?,'pending')";
                $stmt_ev = $conn->prepare($sql_ev2);
                if (!$stmt_ev) throw new Exception("SQL 準備失敗: " . $conn->error);
                $stmt_ev->bind_param("isssss", $user_id,$event_name,$club_name,$desc_ex,$event_start,$event_end);
            } else {
                $stmt_ev->bind_param("issssssssss",
                    $user_id,$event_name,$club_name,$description,$event_start,$event_end,
                    $responsible_person,$event_type,$activity_location,$activity_scale_str,$proposal_filename
                );
            }
            if (!$stmt_ev->execute()) throw new Exception("活動記錄插入失敗: " . $stmt_ev->error);
            $event_id = $conn->insert_id;
            $stmt_ev->close();

            // ── 器材庫存預先驗證（只計已核准活動）────────────────────────
            $chk = $conn->prepare("
                SELECT (e.total_quantity - COALESCE(SUM(
                    CASE WHEN COALESCE(eb.borrow_start, er.borrow_start) < ?
                          AND COALESCE(eb.borrow_end,   er.borrow_end)   > ?
                          AND (
                              (eb.request_id IS NULL     AND ev.status = 'approved') OR
                              (eb.request_id IS NOT NULL AND er.status = 'approved')
                          )
                         THEN eb.quantity ELSE 0 END
                ), 0)) AS available, e.name
                FROM equipment e
                LEFT JOIN equipment_borrow eb ON e.equipment_id = eb.equipment_id
                LEFT JOIN events ev ON eb.event_id = ev.event_id
                LEFT JOIN equipment_requests er ON eb.request_id = er.request_id
                WHERE e.equipment_id = ?
                GROUP BY e.equipment_id");
            // 把 borrow_start/end 從 YYYY-MM-DDTHH:MM 轉成 YYYY-MM-DD HH:MM:00
            $parseBorrow = function(string $raw, string $fallback): string {
                if ($raw && preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}$/', $raw))
                    return substr($raw, 0, 10) . ' ' . substr($raw, 11) . ':00';
                return $fallback;
            };

            if ($chk) {
                foreach ($sessions as $ci => $csess) {
                    if (empty($csess['equipment']) || !is_array($csess['equipment'])) continue;
                    $cbs = $parseBorrow(trim($csess['borrow_start']??''), $csess['date'].' 09:30:00');
                    $cbe = $parseBorrow(trim($csess['borrow_end']  ??''), ($csess['end_date']??$csess['date']).' 16:30:00');
                    $cn  = $ci + 1;
                    foreach ($csess['equipment'] as $ceid => $cqty) {
                        $cqty = intval($cqty); $ceid = intval($ceid);
                        if ($cqty <= 0) continue;
                        $chk->bind_param("ssi", $cbe, $cbs, $ceid);
                        $chk->execute();
                        $crow = $chk->get_result()->fetch_assoc();
                        $cavail = $crow ? intval($crow['available']) : 0;
                        if ($cqty > $cavail) {
                            throw new Exception("場次{$cn}「{$crow['name']}」庫存不足：申請 {$cqty} 件，該時段僅剩 {$cavail} 件可用。");
                        }
                    }
                }
                $chk->close();
            }

            // INSERT 場地預約（每場次）+ 器材借用（每場次）
            $stmt_r = $conn->prepare("INSERT INTO reservations (event_id,space_id,start_time,end_time) VALUES (?,?,?,?)");
            if (!$stmt_r) throw new Exception("場地預約準備失敗: " . $conn->error);
            $stmt_b = $conn->prepare("INSERT INTO equipment_borrow (event_id,equipment_id,quantity,borrow_start,borrow_end,reservation_id) VALUES (?,?,?,?,?,?)");
            if (!$stmt_b) throw new Exception("器材借用準備失敗: " . $conn->error);
            foreach ($sessions as $i => $sess) {
                $vid = intval($sess['venue_id'] ?? 0);
                $reservation_id = null;
                if ($event_type === '校內' && $vid > 0) {
                    $rs = $sess['date']                      . ' ' . $sess['start_time'] . ':00';
                    $re = ($sess['end_date']??$sess['date']) . ' ' . $sess['end_time']   . ':00';
                    $stmt_r->bind_param("iiss", $event_id, $vid, $rs, $re);
                    if (!$stmt_r->execute()) throw new Exception("場地預約插入失敗: " . $stmt_r->error);
                    $reservation_id = $conn->insert_id;
                }
                // 器材借用（此場次）
                if (!empty($sess['equipment']) && is_array($sess['equipment'])) {
                    $bs  = $parseBorrow(trim($sess['borrow_start']??''), $sess['date'].' 09:30:00');
                    $be  = $parseBorrow(trim($sess['borrow_end']  ??''), ($sess['end_date']??$sess['date']).' 16:30:00');
                    $rid = $reservation_id !== null ? (string)$reservation_id : null;
                    foreach ($sess['equipment'] as $eid => $qty) {
                        $qty = intval($qty); $eid = intval($eid);
                        if ($qty > 0) {
                            $stmt_b->bind_param("iiisss", $event_id, $eid, $qty, $bs, $be, $rid);
                            if (!$stmt_b->execute()) throw new Exception("器材借用插入失敗: " . $stmt_b->error);
                        }
                    }
                }
            }
            $stmt_r->close();
            $stmt_b->close();

            $conn->commit();

            // ── PRG：立即將 302 送給瀏覽器，SMTP 在背景執行 ────────────
            $_SESSION['flash_message']      = "✅ 活動申請已提交成功！申請編號：#" . $event_id
                . "，共 " . count($sessions) . " 個場次。我們將在3個工作天內審核。"
                . ($fc_conflict_warn ? " ⚠️ 注意：部分場次與場協暫定預約時間重疊，若場協大會後確認衝突，請重新選擇時間或場地。" : "");
            $_SESSION['flash_message_type'] = "success";
            $_SESSION['form_submit_token']  = bin2hex(random_bytes(16)); // 為下次申請預先生成
            session_write_close();   // 寫入 session 並釋放鎖

            ignore_user_abort(true); // 即使瀏覽器已斷開也繼續執行（寄信用）
            header('Location: apply_event.php');
            header('Connection: close');
            header('Content-Encoding: none');
            while (ob_get_level() > 0) ob_end_clean();
            header('Content-Length: 0');
            flush();                 // 確保 302 回應先送出

            // 背景寄送通知信（瀏覽器已收到 302 並跳轉，不再等待）
            try {
                require_once __DIR__ . '/../includes/mailer.php';
                $stu_row = $conn->query("SELECT name FROM users WHERE user_id = " . intval($user_id))->fetch_assoc();
                $student_display = $stu_row['name'] ?? '學生';
                $admin_rs = $conn->query("SELECT email, name FROM users WHERE role = 'admin'");
                if ($admin_rs) {
                    while ($adm = $admin_rs->fetch_assoc()) {
                        sendApplicationSubmittedMail($adm['email'], $adm['name'], [
                            'event_id'     => $event_id,
                            'event_name'   => $event_name,
                            'club_name'    => $club_name,
                            'start_time'   => $event_start,
                            'end_time'     => $event_end,
                            'student_name' => $student_display,
                        ]);
                    }
                }
            } catch (\Throwable $mailEx) { /* 靜默忽略 */ }
            exit();

        } catch (Exception $e) {
            $conn->rollback();
            $message      = "❌ 申請失敗：" . $e->getMessage();
            $message_type = "error";
        }
    } else {
        $message      = "❌ " . implode("<br>", $errors);
        $message_type = "error";
    }
}

function getEquipmentIcon($id) {
    return [1=>'mic-fill',2=>'speaker-fill',3=>'chair',4=>'table'][$id] ?? 'tools';
}

// 表單還原值
$fv = [
    'club_name'          => htmlspecialchars($current_user_club ?? '', ENT_QUOTES, 'UTF-8'),
    'event_name'         => htmlspecialchars($_POST['event_name'] ?? '', ENT_QUOTES, 'UTF-8'),
    'responsible_person' => htmlspecialchars($_POST['responsible_person'] ?? '', ENT_QUOTES, 'UTF-8'),
    'event_type'         => $_POST['event_type'] ?? '校內',
    'activity_location'  => htmlspecialchars($_POST['activity_location'] ?? '', ENT_QUOTES, 'UTF-8'),
    'activity_scale'     => $_POST['activity_scale'] ?? '一般活動',
    'activity_flags'     => isset($_POST['activity_flags']) && is_array($_POST['activity_flags']) ? $_POST['activity_flags'] : [],
    'description'        => htmlspecialchars($_POST['description'] ?? '', ENT_QUOTES, 'UTF-8'),
];

// 場地選項給 JS 用（PHP 7.3 相容，不使用箭頭函式）
$venues_for_js = [];
foreach ($venues as $v) {
    $venues_for_js[] = ['id' => $v['space_id'], 'name' => $v['space_name']];
}
?>
<!DOCTYPE html>
<html lang="zh-TW">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>活動申請 - 輔仁大學課外活動指導組</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.5/font/bootstrap-icons.css" rel="stylesheet">
    <style>
        :root { --primary:#1e4d6b; --sidebar:#14394f; --bg:#f7f5ef; --card:#ffffff; --success:#10b981; --warning:#f59e0b; --danger:#ef4444; }
        * { box-sizing:border-box; }
        body { margin:0; min-height:100vh; font-family:'Segoe UI',Tahoma,Geneva,Verdana,sans-serif; background:var(--bg); color:#1f2937; }
        .sidebar { position:fixed; top:0; left:0; width:260px; height:100vh; background:var(--primary); color:white; padding:1.5rem 0.8rem; overflow-y:hidden; box-shadow:3px 0 15px rgba(0,0,0,0.12); z-index:1200; }
        .sidebar .brand { text-align:center; margin-bottom:1.5rem; }
        .sidebar .brand h4 { margin:0; font-size:1.1rem; line-height:1.4; font-weight:700; }
        .sidebar .nav-link { display:flex; align-items:center; gap:0.75rem; color:rgba(255,255,255,0.9); padding:0.85rem 1rem; margin:0.2rem 0; border-radius:16px; transition:background 0.25s,transform 0.15s; }
        .sidebar .nav-link:hover, .sidebar .nav-link.active { background:#ece8dd; color:#1e4d6b; transform:translateX(4px); }
        .sidebar .nav-link i { font-size:1.1rem; }
        .sidebar .sidebar-section { padding:1rem 0.5rem; margin-top:1.5rem; border-top:1px solid rgba(255,255,255,0.12); }
        .main-content { margin-left:260px; min-height:100vh; }
        .top-navbar { background:#d5e3ea; border-bottom:1px solid #bdd0d9; padding:1rem 2rem; display:flex; justify-content:space-between; align-items:center; position:sticky; top:0; z-index:1100; }
        .top-navbar .breadcrumb { margin:0; background:transparent; padding:0; font-size:0.8rem; }
        .top-navbar .breadcrumb-item+.breadcrumb-item::before { content:'›'; font-size:1rem; color:#c9d0d8; }
        .top-navbar .breadcrumb-item a { color:#1e4d6b; text-decoration:none; opacity:.75; }
        .top-navbar .breadcrumb-item a:hover { opacity:1; }
        .top-navbar .breadcrumb-item.active { color:#6b7280; }
        .content-wrapper { padding:1.5rem 2rem 2rem; }
        .card { background:var(--card); border-radius:18px; box-shadow:0 10px 30px rgba(15,23,42,0.06); padding:1.5rem; margin-bottom:1.5rem; }
        .card h3 { margin-bottom:1rem; font-weight:700; color:var(--primary); display:flex; align-items:center; gap:0.5rem; }
        .form-section { background:#f8fafc; border-radius:12px; padding:1.25rem 1.5rem; margin-bottom:1rem; }
        .form-control { width:100%; padding:0.65rem 0.9rem; border:1px solid #e5e7eb; border-radius:8px; font-size:0.93rem; }
        .form-control:focus { outline:none; border-color:var(--primary); box-shadow:0 0 0 3px rgba(30,77,107,0.1); }
        /* 活動類型 Radio */
        .type-radio-group { display:flex; gap:0.75rem; flex-wrap:wrap; margin-top:0.5rem; }
        .type-radio-label { display:flex; align-items:center; gap:0.55rem; border:2px solid #e5e7eb; border-radius:10px; padding:0.6rem 1.1rem; cursor:pointer; font-weight:600; font-size:0.9rem; color:#374151; transition:all 0.2s; background:white; }
        .type-radio-label:has(input:checked) { border-color:var(--primary); background:rgba(30,77,107,0.06); color:var(--primary); }
        .type-radio-label input { display:none; }
        /* 場次 */
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
        /* 特殊旗標 */
        .flag-options { display:flex; gap:0.75rem; flex-wrap:wrap; margin-top:0.6rem; }
        .flag-label { display:flex; align-items:center; gap:0.5rem; border:2px solid #e5e7eb; border-radius:10px; padding:0.55rem 1.1rem; cursor:pointer; font-size:0.88rem; color:#374151; transition:all 0.2s; background:white; }
        .flag-label:has(input:checked) { border-color:#f59e0b; background:#fffbf0; color:#92400e; }
        .flag-label input { display:none; }
        /* 器材 */
        .equipment-grid { display:grid; grid-template-columns:repeat(auto-fit,minmax(280px,1fr)); gap:1rem; }
        .equipment-card { border:1px solid #e5e7eb; border-radius:12px; padding:1rem; background:white; }
        .equipment-header { display:flex; justify-content:space-between; align-items:flex-start; margin-bottom:0.75rem; }
        .equipment-name { font-weight:600; font-size:0.95rem; }
        .stock-available { color:var(--success); font-weight:600; }
        .stock-low { color:var(--warning); font-weight:600; }
        .stock-empty { color:var(--danger); font-weight:600; }
        .counter { display:flex; align-items:center; gap:0.5rem; }
        .counter button { width:32px; height:32px; border:1px solid #d1d5db; background:white; border-radius:6px; cursor:pointer; display:flex; align-items:center; justify-content:center; transition:all 0.2s; }
        .counter button:hover:not(:disabled) { background:var(--primary); color:white; border-color:var(--primary); }
        .counter button:disabled { opacity:.45; cursor:not-allowed; }
        .counter input { width:60px; text-align:center; border:1px solid #d1d5db; border-radius:6px; padding:0.25rem; font-weight:600; }
        .counter input:focus { outline:none; border-color:var(--primary); box-shadow:0 0 0 2px rgba(30,77,107,0.12); }
        .counter input::-webkit-outer-spin-button, .counter input::-webkit-inner-spin-button { -webkit-appearance:none; }
        .counter input[type=number] { -moz-appearance:textfield; }
        /* 訊息 */
        .message { padding:1rem 1.25rem; border-radius:12px; margin-bottom:1.5rem; font-weight:600; }
        .message.success { background:#d1e7dd; color:#0f5132; border:1px solid #a3cfbb; }
        .message.error { background:#f8d7da; color:#721c24; border:1px solid #f1aeb5; }
        .alert-info { background:#dbeafe; border-color:#93c5fd; color:#1e3a5f; }
        .alert-warning { background:#fef3c7; border-color:#fbbf24; color:#78350f; }
        .notice-box { display:flex; align-items:start; gap:0.65rem; background:#f0f6fb; border:1px solid #bcd3e5; border-radius:10px; padding:0.85rem 1rem; font-size:0.88rem; color:#1e4d6b; line-height:1.6; }
        .btn-submit { background:var(--primary); color:white; border:none; padding:0.9rem 3rem; border-radius:12px; font-weight:600; font-size:1.05rem; cursor:pointer; transition:all 0.25s; display:block; margin:1.75rem auto 0; box-shadow:0 4px 15px rgba(30,77,107,0.2); }
        .btn-submit:hover { background:var(--sidebar); transform:translateY(-2px); box-shadow:0 6px 20px rgba(30,77,107,0.3); }
        .btn-submit:disabled { cursor:not-allowed !important; opacity:.75 !important; transform:none !important; box-shadow:0 4px 15px rgba(30,77,107,.2) !important; }
        .submit-spinner { display:inline-block; width:1em; height:1em; border:2px solid rgba(255,255,255,.4); border-top-color:white; border-radius:50%; animation:submit-spin .65s linear infinite; vertical-align:-.2em; margin-right:.4em; }
        @keyframes submit-spin { to { transform:rotate(360deg); } }
        @media (max-width:960px) { .session-fields { grid-template-columns:1fr 1fr 1fr; } .session-fields .session-field:nth-child(4), .session-fields .session-field:nth-child(5) { grid-column:1/-1; } }
        @media (max-width:600px) { .session-fields { grid-template-columns:1fr; } }
        @media (max-width:1100px) { .main-content { margin-left:0; } .equipment-grid { grid-template-columns:1fr; } }
        @media (max-width:768px) { .top-navbar { flex-direction:column; align-items:flex-start; gap:1rem; padding:1rem; } .sidebar { position:relative; width:100%; height:auto; } }
    </style>
</head>
<body>
<?php include(__DIR__ . "/../includes/sidebar.php"); ?>

<main class="main-content">
    <header class="top-navbar">
        <div>
            <ol class="breadcrumb mb-0">
                <li class="breadcrumb-item"><a href="dashboard.php">首頁</a></li>
                <li class="breadcrumb-item active">活動申請</li>
            </ol>
            <h4 class="mt-2 mb-0">新增活動申請</h4>
        </div>
    </header>

    <section class="content-wrapper">

        <?php if ($message): ?>
        <div class="message <?= $message_type ?>"><?= $message ?></div>
        <?php endif; ?>

        <?php if (!empty($field_coordination_results)): ?>
        <div class="card">
            <h3><i class="bi bi-check-circle"></i> 場協登記結果選擇</h3>
            <p class="text-muted mb-3">社團有以下已核准的場協結果，點擊任一項可自動帶入場次資訊。</p>
            <div style="display:grid; grid-template-columns:repeat(auto-fit,minmax(260px,1fr)); gap:1rem;">
                <?php foreach ($field_coordination_results as $idx => $fc): ?>
                <div class="field-coord-card" onclick="loadFCData(<?= $idx ?>)"
                     style="cursor:pointer; border:2px solid #e5e7eb; border-radius:12px; padding:1rem; transition:all 0.2s; background:white;" id="fc_card_<?= $idx ?>">
                    <div style="display:flex; justify-content:space-between; align-items:start;">
                        <div>
                            <div style="font-weight:700; color:#1f2937; font-size:1rem;"><?= htmlspecialchars($fc['event_name'],ENT_QUOTES,'UTF-8') ?></div>
                            <div style="font-size:0.85rem; color:#6b7280;">民國 <?= $fc['academic_year'] ?> <?= $fc['semester']==1?'上':'下' ?>學期</div>
                        </div>
                        <input type="radio" name="field_coord_selection" value="<?= $idx ?>">
                    </div>
                    <hr style="margin:0.6rem 0; border:none; border-top:1px solid #e5e7eb;">
                    <div style="font-size:0.87rem; color:#374151;">
                        <div><i class="bi bi-calendar-event me-1"></i><?= date('Y-m-d', strtotime($fc['start_time'])) ?></div>
                        <div><i class="bi bi-clock me-1"></i><?= date('H:i', strtotime($fc['start_time'])) ?> – <?= date('H:i', strtotime($fc['end_time'])) ?></div>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
        <?php endif; ?>

        <form method="POST" id="applicationForm" enctype="multipart/form-data">
            <input type="hidden" name="form_token" value="<?= htmlspecialchars($_SESSION['form_submit_token'] ?? '', ENT_QUOTES, 'UTF-8') ?>">

            <!-- ① 基本資訊 -->
            <div class="card">
                <h3><i class="bi bi-info-circle"></i> 基本資訊</h3>
                <div class="form-section">
                    <div class="row g-3 mb-3">
                        <div class="col-md-6">
                            <label class="form-label fw-semibold">社團名稱 <span class="text-danger">*</span></label>
                            <?php if (count($my_clubs) > 1): ?>
                            <select class="form-control" id="club_id_select" onchange="location.href='apply_event.php?club_id='+encodeURIComponent(this.value)">
                                <?php foreach ($my_clubs as $c): ?>
                                <option value="<?= htmlspecialchars($c['club_id'], ENT_QUOTES, 'UTF-8') ?>" <?= $c['club_id']===$selected_club_id?'selected':'' ?>><?= htmlspecialchars($c['club_name'], ENT_QUOTES, 'UTF-8') ?></option>
                                <?php endforeach; ?>
                            </select>
                            <small class="text-muted">您隸屬多個社團，請選擇本次申請的主辦社團</small>
                            <?php else: ?>
                            <input type="text" class="form-control" value="<?= $fv['club_name'] ?>" readonly>
                            <?php endif; ?>
                            <input type="hidden" name="club_id" value="<?= htmlspecialchars($selected_club_id, ENT_QUOTES, 'UTF-8') ?>">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label fw-semibold">活動名稱 <span class="text-danger">*</span></label>
                            <input type="text" name="event_name" class="form-control" id="event_name" value="<?= $fv['event_name'] ?>" required placeholder="活動名稱">
                        </div>
                    </div>
                    <input type="hidden" name="event_type" value="校內">
                    <div class="row g-3 mb-3">
                        <div class="col-md-6">
                            <label class="form-label fw-semibold">活動負責人 <span class="text-danger">*</span></label>
                            <input type="text" name="responsible_person" class="form-control" value="<?= $fv['responsible_person'] ?>" required placeholder="例：社長 王小明">
                        </div>
                    </div>
                    <div class="row g-3 mb-3">
                        <div class="col-12">
                            <label class="form-label fw-semibold">活動類型</label>
                            <div style="display:flex; gap:1rem; flex-wrap:wrap; align-items:center;">
                                <div style="display:flex; gap:0.5rem; flex-wrap:wrap;">
                                    <label id="lbl_scale_normal" style="display:inline-flex; align-items:center; gap:0.3rem; border:1.5px solid #cbd5e1; border-radius:7px; padding:0.35rem 0.85rem; cursor:pointer; font-size:0.85rem; font-weight:600; background:white; transition:all 0.15s; user-select:none;">
                                        <input type="radio" name="activity_scale" value="一般活動" id="scale_normal" <?= ($fv['activity_scale']??'一般活動')!=='大型活動'?'checked':'' ?> onchange="updateDeadlineReminder()" style="display:none;">
                                        一般活動
                                    </label>
                                    <label id="lbl_scale_large" style="display:inline-flex; align-items:center; gap:0.3rem; border:1.5px solid #cbd5e1; border-radius:7px; padding:0.35rem 0.85rem; cursor:pointer; font-size:0.85rem; font-weight:600; background:white; transition:all 0.15s; user-select:none;">
                                        <input type="radio" name="activity_scale" value="大型活動" id="scale_large" <?= ($fv['activity_scale']??'')=='大型活動'?'checked':'' ?> onchange="updateDeadlineReminder()" style="display:none;">
                                        大型活動
                                    </label>
                                </div>
                                <div style="width:1px; height:1.6rem; background:#e5e7eb;"></div>
                                <div style="display:flex; gap:0.5rem; flex-wrap:wrap;">
                                    <label id="lbl_alcohol" style="display:inline-flex; align-items:center; gap:0.3rem; border:1.5px solid #cbd5e1; border-radius:7px; padding:0.35rem 0.85rem; cursor:pointer; font-size:0.85rem; background:white; transition:all 0.15s; user-select:none;">
                                        <input type="checkbox" name="activity_flags[]" value="含酒精活動" id="flag_alcohol" <?= in_array('含酒精活動',$fv['activity_flags'])?'checked':'' ?> onchange="updateFlagWarning()" style="display:none;">
                                        🍺 含酒精
                                    </label>
                                    <label id="lbl_fire" style="display:inline-flex; align-items:center; gap:0.3rem; border:1.5px solid #cbd5e1; border-radius:7px; padding:0.35rem 0.85rem; cursor:pointer; font-size:0.85rem; background:white; transition:all 0.15s; user-select:none;">
                                        <input type="checkbox" name="activity_flags[]" value="使用火源活動" id="flag_fire" <?= in_array('使用火源活動',$fv['activity_flags'])?'checked':'' ?> onchange="updateFlagWarning()" style="display:none;">
                                        🔥 火源
                                    </label>
                                </div>
                            </div>
                            <div id="deadline_reminder" class="notice-box mt-2" style="padding:0.55rem 0.85rem; font-size:0.86rem; margin-bottom:0;">
                                <i class="bi bi-clock" style="font-size:0.95rem; flex-shrink:0;"></i>
                                <span id="deadline_text"></span>
                            </div>
                            <div id="flag_warning" class="alert alert-warning mt-2" style="display:none; border-radius:8px; font-size:0.85rem; margin-bottom:0; padding:0.55rem 0.85rem;"></div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- ② 企畫書上傳 -->
            <div class="card">
                <h3><i class="bi bi-file-earmark-arrow-up"></i> 企畫書上傳</h3>
                <div class="form-section">
                    <div class="row g-4">
                        <div class="col-md-6">
                            <h5 class="fw-bold mb-1"><i class="bi bi-download me-1"></i>紙本三單下載</h5>
                            <p class="text-muted small mb-3">請下載後填寫並親自繳交至課指組（紙本流程）。</p>
                            <div class="d-flex flex-column gap-2">
                                <a href="../document/活動申請表(黃單)1141120.docx" class="btn btn-outline-secondary btn-sm" download><i class="bi bi-file-earmark-word me-1"></i>下載活動申請表（黃單）</a>
                                <a href="../document/例行活動場地核定登記表.docx" class="btn btn-outline-secondary btn-sm" download><i class="bi bi-file-earmark-word me-1"></i>下載場地核定登記表</a>
                                <a href="../document/課指組 器材借用申請表115.02.01.docx" class="btn btn-outline-secondary btn-sm" download><i class="bi bi-file-earmark-word me-1"></i>下載器材借用申請表</a>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <h5 class="fw-bold mb-1"><i class="bi bi-upload me-1"></i>活動企劃書上傳</h5>
                            <p class="text-muted small mb-3">可上傳活動企劃書供審核參考（選填，PDF / Word）。</p>
                            <input type="file" name="proposal_document" class="form-control" accept=".pdf,.doc,.docx">
                            <div class="alert alert-info mt-2" style="border-radius:8px; font-size:0.85rem; margin-bottom:0;">
                                <i class="bi bi-info-circle me-1"></i>三單仍需紙本繳交，<strong>無須電子上傳</strong>。
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- ③ 活動場次（條列式） -->
            <div class="card">
                <h3><i class="bi bi-calendar-week"></i> 活動場次安排</h3>
                <p class="text-muted mb-3" style="font-size:0.92rem;">
                    每個場次可設定不同日期、時間與場地。例如：每週二晚上定期練習可新增多筆場次。
                    <br><span style="color:#6b7280;">校內活動請每場次選擇場地；校外活動場地可不選。</span>
                </p>
                <div class="form-section">
                    <div id="sessions_container">
                        <?php foreach ($sessions_data as $si => $sess): ?>
                        <div class="session-row" data-idx="<?= $si ?>">
                            <div class="session-header">
                                <span class="session-label"><i class="bi bi-calendar3"></i> 場次 <?= $si+1 ?></span>
                                <button type="button" class="btn-remove-session" onclick="removeSession(this)" style="display:<?= count($sessions_data)>1?'inline-flex':'none' ?>;">
                                    <i class="bi bi-trash"></i> 刪除
                                </button>
                            </div>
                            <div class="session-fields">
                                <div class="session-field">
                                    <label>開始日期 *</label>
                                    <input type="date" name="sessions[<?= $si ?>][date]" class="form-control" value="<?= htmlspecialchars($sess['date']??'',ENT_QUOTES,'UTF-8') ?>" min="<?= $today_date ?>" required>
                                </div>
                                <div class="session-field">
                                    <label>開始時間 * <small style="color:#9ca3af;">(08:30–21:30)</small></label>
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
                                    <input type="date" name="sessions[<?= $si ?>][end_date]" class="form-control" value="<?= htmlspecialchars($sess['end_date']??$sess['date']??'',ENT_QUOTES,'UTF-8') ?>" min="<?= $today_date ?>" required>
                                </div>
                                <div class="session-field">
                                    <label>結束時間 * <small style="color:#9ca3af;">(08:30–21:30)</small></label>
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
                                    <label>場地 <?= $fv['event_type']==='校內'?'*':'（選填）' ?></label>
                                    <select name="sessions[<?= $si ?>][venue_id]" class="form-control">
                                        <option value="">-- 選擇場地 --</option>
                                        <?php foreach ($venues as $v): ?>
                                        <option value="<?= $v['space_id'] ?>" <?= ($sess['venue_id']??'')==$v['space_id']?'selected':'' ?>><?= htmlspecialchars($v['space_name'],ENT_QUOTES,'UTF-8') ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                            </div>
                            <div style="margin-top:0.75rem;">
                                <button type="button" class="btn-equip-toggle"
                                        onclick="toggleSessionEquip(this)"
                                        style="background:none;border:1.5px dashed #1e4d6b;color:#1e4d6b;border-radius:8px;padding:0.35rem 0.85rem;cursor:pointer;font-size:0.83rem;font-weight:600;transition:all 0.2s;">
                                    <i class="bi bi-tools me-1"></i> 此場次新增器材借用
                                </button>
                                <div class="session-equip-panel" style="display:none;margin-top:0.75rem;padding:0.85rem;background:#f8fafc;border:1px solid #e5e7eb;border-radius:10px;">
                                    <?php
                                        $initD  = $sess['date'] ?? '';
                                        $initBS = $sess['borrow_start'] ?? ($initD ? $initD.'T09:30' : '');
                                        $initBE = $sess['borrow_end']   ?? ($initD ? $initD.'T16:30' : '');
                                        $bsH = $initBS ? substr($initBS,11,2) : '09'; $bsM = $initBS ? substr($initBS,14,2) : '30';
                                        $beH = $initBE ? substr($initBE,11,2) : '16'; $beM = $initBE ? substr($initBE,14,2) : '30';
                                        $bsD = $initBS ? substr($initBS,0,10) : $initD;
                                        $beD = $initBE ? substr($initBE,0,10) : $initD;
                                    ?>
                                    <div style="background:#f0f4f8;border-radius:10px;padding:0.75rem 1rem;margin-bottom:0.85rem;">
                                        <div style="font-size:0.83rem;font-weight:600;color:#1e4d6b;margin-bottom:0.5rem;">
                                            <i class="bi bi-clock me-1"></i>選擇器材借用時段
                                            <small style="font-weight:400;color:#6b7280;margin-left:0.4rem;">（可用量將依時段更新）</small>
                                        </div>
                                        <div style="display:grid;grid-template-columns:1fr 1.4fr 1fr 1.4fr auto;gap:0.6rem;align-items:flex-end;">
                                            <div>
                                                <label style="font-size:0.78rem;color:#374151;display:block;margin-bottom:0.2rem;">借用日期</label>
                                                <input type="date" class="form-control sess-borrow-date" value="<?= htmlspecialchars($bsD,ENT_QUOTES,'UTF-8') ?>" style="font-size:0.85rem;">
                                            </div>
                                            <div>
                                                <label style="font-size:0.78rem;color:#374151;display:block;margin-bottom:0.2rem;">借用時間 <small style="color:#9ca3af;">(09:30–16:30)</small></label>
                                                <div class="d-flex align-items-center gap-1">
                                                    <select class="form-select sess-borrow-h" style="width:auto;font-size:0.85rem;">
                                                        <?php for($h=9;$h<=16;$h++){$hh=sprintf('%02d',$h);echo "<option value=\"$hh\"".($bsH===$hh?' selected':'').">$hh</option>";}?>
                                                    </select>
                                                    <span style="padding:0 3px;font-weight:600">:</span>
                                                    <select class="form-select sess-borrow-m" style="width:auto;font-size:0.85rem;">
                                                        <?php foreach([0,10,20,30,40,50] as $m){$mm=sprintf('%02d',$m);echo "<option value=\"$mm\"".($bsM===$mm?' selected':'').">$mm</option>";}?>
                                                    </select>
                                                </div>
                                            </div>
                                            <div>
                                                <label style="font-size:0.78rem;color:#374151;display:block;margin-bottom:0.2rem;">歸還日期</label>
                                                <input type="date" class="form-control sess-return-date" value="<?= htmlspecialchars($beD,ENT_QUOTES,'UTF-8') ?>" style="font-size:0.85rem;">
                                            </div>
                                            <div>
                                                <label style="font-size:0.78rem;color:#374151;display:block;margin-bottom:0.2rem;">歸還時間 <small style="color:#9ca3af;">(09:30–16:30)</small></label>
                                                <div class="d-flex align-items-center gap-1">
                                                    <select class="form-select sess-return-h" style="width:auto;font-size:0.85rem;">
                                                        <?php for($h=9;$h<=16;$h++){$hh=sprintf('%02d',$h);echo "<option value=\"$hh\"".($beH===$hh?' selected':'').">$hh</option>";}?>
                                                    </select>
                                                    <span style="padding:0 3px;font-weight:600">:</span>
                                                    <select class="form-select sess-return-m" style="width:auto;font-size:0.85rem;">
                                                        <?php foreach([0,10,20,30,40,50] as $m){$mm=sprintf('%02d',$m);echo "<option value=\"$mm\"".($beM===$mm?' selected':'').">$mm</option>";}?>
                                                    </select>
                                                </div>
                                            </div>
                                            <button type="button" onclick="querySessionEquip(this)"
                                                style="background:#1e4d6b;color:white;border:none;border-radius:8px;padding:0.55rem 0.9rem;font-weight:600;cursor:pointer;white-space:nowrap;font-size:0.85rem;transition:background 0.2s;"
                                                onmouseover="this.style.background='#14394f'" onmouseout="this.style.background='#1e4d6b'">
                                                <i class="bi bi-search me-1"></i>查詢可用數量
                                            </button>
                                        </div>
                                        <input type="hidden" class="sess-bs-hidden" name="sessions[<?= $si ?>][borrow_start]" value="<?= htmlspecialchars($initBS,ENT_QUOTES,'UTF-8') ?>">
                                        <input type="hidden" class="sess-be-hidden" name="sessions[<?= $si ?>][borrow_end]"   value="<?= htmlspecialchars($initBE,ENT_QUOTES,'UTF-8') ?>">
                                    </div>
                                    <div class="equipment-grid">
                                        <?php foreach ($equipment as $item):
                                            $qty0 = intval($sess['equipment'][$item['id']] ?? 0);
                                            $sc   = $item['available'] > 0 ? ($item['available'] < 3 ? 'low' : 'available') : 'empty';
                                        ?>
                                        <div class="equipment-card">
                                            <div style="display:flex;align-items:center;gap:0.9rem;margin-bottom:0.85rem;">
                                                <div style="width:46px;height:46px;border-radius:12px;background:#1e4d6b;color:white;display:flex;align-items:center;justify-content:center;font-size:0.9rem;font-weight:700;flex-shrink:0;"><?= htmlspecialchars($item['code'],ENT_QUOTES,'UTF-8') ?></div>
                                                <div style="flex:1;min-width:0;">
                                                    <div class="equipment-name"><?= htmlspecialchars($item['name'],ENT_QUOTES,'UTF-8') ?></div>
                                                    <div style="color:#9ca3af;font-size:0.78rem;">編號：<?= htmlspecialchars($item['code'],ENT_QUOTES,'UTF-8') ?></div>
                                                </div>
                                            </div>
                                            <div style="text-align:right;font-size:0.88rem;">
                                                <div class="stock-<?= $sc ?>">剩餘：<span class="avail-qty"><?= $item['available'] ?></span>/<?= $item['total'] ?></div>
                                            </div>
                                            <div style="font-size:0.85rem;color:#6b7280;margin-top:0.35rem;">每次建議上限：<?= $item['borrowing_limit'] ?: '無限制' ?></div>
                                            <div class="counter mt-1">
                                                <button type="button" class="btn-minus" onclick="sessQty(this,-1)" <?= $qty0<=0?'disabled':'' ?>>-</button>
                                                <input type="number" name="sessions[<?= $si ?>][equipment][<?= $item['id'] ?>]" value="<?= $qty0 ?>" min="0" max="<?= $item['available'] ?>" data-total="<?= $item['total'] ?>" oninput="sessClamped(this)" onchange="sessSync(this)">
                                                <button type="button" class="btn-plus" onclick="sessQty(this,1)" <?= $item['available']<=0?'disabled':'' ?>>+</button>
                                            </div>
                                        </div>
                                        <?php endforeach; ?>
                                    </div>
                                </div>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                    <button type="button" class="btn-add-session" onclick="addSession()">
                        <i class="bi bi-plus-circle"></i> 新增場次
                    </button>
                </div>
            </div>

            <!-- ④ 器材借用（已移入各場次卡片） -->
            <div class="card" style="display:none;">
                <h3><i class="bi bi-tools"></i> 器材借用</h3>
                <div class="form-section">
                    <div style="background:#f0f4f8; border-radius:12px; padding:1rem 1.25rem; margin-bottom:1rem;">
                        <div style="font-weight:600; color:#1e4d6b; margin-bottom:0.75rem; font-size:0.93rem;">
                            <i class="bi bi-clock me-1"></i>選擇器材借用時段
                            <small style="font-weight:400; color:#6b7280; margin-left:0.5rem;">（可用量將依時段即時更新）</small>
                        </div>
                        <div style="display:grid; grid-template-columns:1fr 1.5fr 1fr 1.5fr auto; gap:0.75rem; align-items:flex-end;">
                            <div>
                                <label style="font-size:0.83rem; color:#374151; display:block; margin-bottom:0.3rem;">借用日期</label>
                                <input type="date" id="borrow_date" class="form-control" value="<?= date('Y-m-d') ?>" required>
                            </div>
                            <div>
                                <label style="font-size:0.83rem; color:#374151; display:block; margin-bottom:0.3rem;">借用時間 <small style="color:#9ca3af;">(09:30–16:30)</small></label>
                                <div class="d-flex align-items-center gap-1">
                                    <select id="borrow_hour" class="form-select" style="width:auto" required>
                                        <option value="">時</option>
                                        <?php for ($h = 9; $h <= 16; $h++): $hh = sprintf('%02d', $h); ?>
                                            <option value="<?= $hh ?>" <?= $hh === '09' ? 'selected' : '' ?>><?= $hh ?></option>
                                        <?php endfor; ?>
                                    </select>
                                    <span style="padding:0 4px;font-weight:600">:</span>
                                    <select id="borrow_minute" class="form-select" style="width:auto" required>
                                        <option value="">分</option>
                                        <?php foreach ([0,10,20,30,40,50] as $m): $mm = sprintf('%02d', $m); ?>
                                            <option value="<?= $mm ?>" <?= $mm === '30' ? 'selected' : '' ?>><?= $mm ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <input type="hidden" id="equip_borrow_time" name="equip_borrow_time" value="<?= date('Y-m-d') ?>T09:30">
                            </div>
                            <div>
                                <label style="font-size:0.83rem; color:#374151; display:block; margin-bottom:0.3rem;">歸還日期</label>
                                <input type="date" id="return_date" class="form-control" value="<?= date('Y-m-d') ?>" required>
                            </div>
                            <div>
                                <label style="font-size:0.83rem; color:#374151; display:block; margin-bottom:0.3rem;">歸還時間 <small style="color:#9ca3af;">(09:30–16:30)</small></label>
                                <div class="d-flex align-items-center gap-1">
                                    <select id="return_hour" class="form-select" style="width:auto" required>
                                        <option value="">時</option>
                                        <?php for ($h = 9; $h <= 16; $h++): $hh = sprintf('%02d', $h); ?>
                                            <option value="<?= $hh ?>" <?= $hh === '16' ? 'selected' : '' ?>><?= $hh ?></option>
                                        <?php endfor; ?>
                                    </select>
                                    <span style="padding:0 4px;font-weight:600">:</span>
                                    <select id="return_minute" class="form-select" style="width:auto" required>
                                        <option value="">分</option>
                                        <?php foreach ([0,10,20,30,40,50] as $m): $mm = sprintf('%02d', $m); ?>
                                            <option value="<?= $mm ?>" <?= $mm === '30' ? 'selected' : '' ?>><?= $mm ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <input type="hidden" id="equip_return_time" name="equip_return_time" value="<?= date('Y-m-d') ?>T16:30">
                            </div>
                            <button type="button" onclick="queryEquipmentAvailability()"
                                style="background:#1e4d6b; color:white; border:none; border-radius:8px; padding:0.65rem 1.1rem; font-weight:600; cursor:pointer; white-space:nowrap; transition:background 0.2s;"
                                onmouseover="this.style.background='#14394f'" onmouseout="this.style.background='#1e4d6b'">
                                <i class="bi bi-search me-1"></i>查詢可用數量
                            </button>
                        </div>
                        <div class="alert alert-info" style="margin-top:0.85rem; border-radius:12px; padding:0.95rem 1rem; font-size:0.92rem; line-height:1.5; color:#1e3a5f; background:#dbeafe; border-color:#93c5fd;">
                            <i class="bi bi-info-circle-fill me-1"></i>
                            申請器材時請務必填寫器材證持有人，器材證期限為一年，請確認是否過期。
                        </div>
                        <div id="equipTimeWarning" style="display:none; margin-top:0.75rem; padding:0.6rem 0.9rem; background:#f0e8c0; border-radius:8px; color:#6b5a20; font-size:0.87rem;"></div>
                    </div>
                    <div style="position:relative; margin-bottom:1rem;">
                        <input type="text" id="searchEquipmentApply" class="form-control" placeholder="搜尋器材名稱或編號…" style="border-radius:10px; border:1px solid #e5e7eb;">
                        <i class="bi bi-search" style="position:absolute; right:12px; top:50%; transform:translateY(-50%); color:#9ca3af; pointer-events:none;"></i>
                    </div>
                    <div class="equipment-grid">
                        <?php foreach ($equipment as $item):
                            $initMax = $item['available'];
                            $sc = $item['available']>0 ? ($item['available']<3?'low':'available') : 'empty';
                        ?>
                        <div class="equipment-card" data-equip-id="<?= $item['id'] ?>" data-name="<?= htmlspecialchars($item['name']) ?>" data-code="<?= htmlspecialchars($item['code']) ?>" data-total="<?= $item['total'] ?>">
                            <div class="equipment-header" style="display:flex; align-items:center; gap:0.9rem; margin-bottom:0.85rem;">
                                <div style="width:46px; height:46px; border-radius:12px; background:#1e4d6b; color:white; display:flex; align-items:center; justify-content:center; font-size:0.9rem; font-weight:700; flex-shrink:0;"><?= htmlspecialchars($item['code']) ?></div>
                                <div style="display:flex; flex-direction:column; justify-content:center; flex:1; min-width:0;">
                                    <div class="equipment-name" style="text-align:left; font-weight:600; font-size:1rem; margin:0 0 0.15rem;"><?= htmlspecialchars($item['name']) ?></div>
                                    <div style="color:#9ca3af; font-size:0.78rem; text-align:left;">編號：<?= htmlspecialchars($item['code']) ?></div>
                                </div>
                            </div>
                            <div style="text-align:right; font-size:0.88rem; margin-top:0.5rem;">
                                <div class="avail-text stock-<?= $sc ?>">剩餘：<span class="avail-qty"><?= $item['available'] ?></span>/<?= $item['total'] ?></div>
                            </div>
                            <div style="font-size:0.85rem; color:#6b7280; margin-top:0.35rem; text-align:left;">每次建議上限：<?= htmlspecialchars($item['borrowing_limit']) ?></div>
                            <div class="counter mt-1">
                                <button type="button" class="btn-minus" onclick="changeQuantity(<?= $item['id'] ?>,-1)" <?= $item['available']==0?'disabled':'' ?>>-</button>
                                <input type="number" id="qty_<?= $item['id'] ?>" name="equipment[<?= $item['id'] ?>]" value="0" min="0" max="<?= $initMax ?>" oninput="clampQtyInput(this)" onchange="syncQtyButtons(this)">
                                <button type="button" class="btn-plus" onclick="changeQuantity(<?= $item['id'] ?>,1)" <?= $item['available']==0?'disabled':'' ?>>+</button>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>

            <button type="button" id="submitBtn" class="btn-submit"><i class="bi bi-send me-1"></i> 提交申請</button>
        </form>
    </section>
</main>

<script>
// ── 器材清單（for 場次器材 UI）────────────────────────────
const EQUIPMENT_LIST = <?= json_encode(array_values($equipment)) ?>;
function escHtmlApply(s){ return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;'); }

// ── 場協資料 ───────────────────────────────────────────────
const fcResults = <?= json_encode($field_coordination_results) ?>;
function loadFCData(idx) {
    const fc = fcResults[idx];
    if (!fc) return;
    document.querySelectorAll('.field-coord-card').forEach(c => { c.style.borderColor='#e5e7eb'; c.style.background='white'; });
    const card = document.getElementById('fc_card_' + idx);
    card.style.borderColor = '#1e4d6b';
    card.style.background  = 'rgba(30,77,107,0.05)';
    card.querySelector('input[type="radio"]').checked = true;
    document.getElementById('event_name').value = fc.event_name || '';
    // 帶入第一個場次
    const s = fc.start_time ? fc.start_time.split(' ') : ['',''];
    const e = fc.end_time   ? fc.end_time.split(' ')   : ['',''];
    const rows = document.querySelectorAll('.session-row');
    if (rows.length > 0) {
        rows[0].querySelector('[name*="[date]"]').value       = s[0] || '';
        setSessionTimeField(rows[0], 'start_time', s[1] ? s[1].substring(0,5) : '');
        setSessionTimeField(rows[0], 'end_time',   e[1] ? e[1].substring(0,5) : '');
        if (fc.space_id) {
            const sel = rows[0].querySelector('[name*="[venue_id]"]');
            if (sel) sel.value = fc.space_id;
        }
    }
}

// ── 活動類型切換（目前固定為校內，場地一律必填） ───────────
function toggleEventType() {
    document.querySelectorAll('.session-field:last-child label').forEach(lbl => {
        lbl.textContent = '場地 *';
    });
}

// ── 送件期限提示 ─────────────────────────────────────────
function updateDeadlineReminder() {
    const isLarge   = document.getElementById('scale_large').checked;
    const isAlcohol = document.getElementById('flag_alcohol').checked;
    const isFire    = document.getElementById('flag_fire').checked;
    const need30    = isLarge || isAlcohol || isFire;
    document.getElementById('deadline_text').innerHTML = need30
        ? '請於活動前 <strong>30 天</strong> 完成送件。紙本三單須親自繳交至課指組。'
        : '一般活動請於活動前 <strong>7 天</strong> 完成送件。紙本三單須親自繳交至課指組。';
    // 更新選中樣式
    document.getElementById('lbl_scale_normal').style.cssText += isLarge
        ? ';border-color:#cbd5e1;background:white;color:#374151;'
        : ';border-color:#1e4d6b;background:rgba(30,77,107,0.07);color:#1e4d6b;';
    document.getElementById('lbl_scale_large').style.cssText += isLarge
        ? ';border-color:#1e4d6b;background:rgba(30,77,107,0.07);color:#1e4d6b;'
        : ';border-color:#cbd5e1;background:white;color:#374151;';
}

// ── 特殊旗標警告 ─────────────────────────────────────────
function updateFlagWarning() {
    const alcohol = document.getElementById('flag_alcohol').checked;
    const fire    = document.getElementById('flag_fire').checked;
    const w       = document.getElementById('flag_warning');
    const msgs    = [];
    if (alcohol) msgs.push('含酒精活動需額外審核，請確認符合相關法規。');
    if (fire)    msgs.push('使用火源活動需額外審核，請備有安全措施。');
    w.innerHTML     = msgs.map(m=>`<div><i class="bi bi-exclamation-triangle-fill me-1"></i>${m}</div>`).join('');
    w.style.display = msgs.length ? 'block' : 'none';
    // 更新 checkbox 樣式
    ['lbl_alcohol','lbl_fire'].forEach(id => {
        const lbl = document.getElementById(id);
        const cb  = lbl ? lbl.querySelector('input[type=checkbox]') : null;
        if (lbl && cb) {
            lbl.style.borderColor  = cb.checked ? '#f59e0b' : '#cbd5e1';
            lbl.style.background   = cb.checked ? '#fffbf0' : 'white';
            lbl.style.color        = cb.checked ? '#92400e' : '#374151';
        }
    });
    updateDeadlineReminder();
}

// ── 場次管理 ─────────────────────────────────────────────
const venueOptions = <?= json_encode($venues_for_js) ?>;
const todayDateString = <?= json_encode($today_date) ?>;
let sessionCount   = <?= count($sessions_data) ?>;

function applySessionDateLimits() {
    document.querySelectorAll('.session-row input[type="date"]').forEach(input => {
        input.min = todayDateString;
    });
}

function validateSessionDateInput(input) {
    if (!input || !input.value) return true;
    if (input.value < todayDateString) {
        alert('不能申請過往日期，請選擇今日或之後的日期。');
        input.value = '';
        return false;
    }
    return true;
}

function buildTimeSelects(fieldName, value) {
    const h = value ? value.substring(0, 2) : '';
    const m = value ? value.substring(3, 5) : '';
    let hoursOpts = '<option value="">時</option>';
    for (let hr = 8; hr <= 21; hr++) {
        const hh = String(hr).padStart(2, '0');
        hoursOpts += `<option value="${hh}"${h===hh?' selected':''}>${hh}</option>`;
    }
    let minsOpts = '<option value="">分</option>';
    [0,10,20,30,40,50].forEach(function(mn) {
        const mm = String(mn).padStart(2, '0');
        minsOpts += `<option value="${mm}"${m===mm?' selected':''}>${mm}</option>`;
    });
    return `<div class="time-selects d-flex align-items-center gap-1">` +
        `<select class="form-select time-hour" style="width:auto">${hoursOpts}</select>` +
        `<span style="padding:0 4px;font-weight:600">:</span>` +
        `<select class="form-select time-minute" style="width:auto">${minsOpts}</select>` +
        `</div>` +
        `<input type="hidden" name="${fieldName}" class="time-value" value="${value||''}">`;
}

function buildVenueOptions(selectedId) {
    let html = '<option value="">-- 選擇場地 --</option>';
    venueOptions.forEach(v => {
        const sel = (selectedId && String(v.id) === String(selectedId)) ? 'selected' : '';
        html += `<option value="${v.id}" ${sel}>${escHtml(v.name)}</option>`;
    });
    return html;
}
function escHtml(str) {
    return String(str).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
}

// ── 場次器材時段選擇器 HTML 產生器 ──────────────────────────
function buildSessEquipTimePicker(idx, initDate) {
    const hOpts = def => [9,10,11,12,13,14,15,16].map(h => {
        const hh = String(h).padStart(2,'0');
        return `<option value="${hh}"${h===def?' selected':''}>${hh}</option>`;
    }).join('');
    const mOpts = def => [0,10,20,30,40,50].map(m => {
        const mm = String(m).padStart(2,'0');
        return `<option value="${mm}"${m===def?' selected':''}>${mm}</option>`;
    }).join('');
    const d = initDate || '';
    return `
    <div style="background:#f0f4f8;border-radius:10px;padding:0.75rem 1rem;margin-bottom:0.85rem;">
        <div style="font-size:0.83rem;font-weight:600;color:#1e4d6b;margin-bottom:0.5rem;">
            <i class="bi bi-clock me-1"></i>選擇器材借用時段
            <small style="font-weight:400;color:#6b7280;margin-left:0.4rem;">（可用量將依時段更新）</small>
        </div>
        <div style="display:grid;grid-template-columns:1fr 1.4fr 1fr 1.4fr auto;gap:0.6rem;align-items:flex-end;">
            <div>
                <label style="font-size:0.78rem;color:#374151;display:block;margin-bottom:0.2rem;">借用日期</label>
                <input type="date" class="form-control sess-borrow-date" value="${d}" style="font-size:0.85rem;">
            </div>
            <div>
                <label style="font-size:0.78rem;color:#374151;display:block;margin-bottom:0.2rem;">借用時間 <small style="color:#9ca3af;">(09:30–16:30)</small></label>
                <div class="d-flex align-items-center gap-1">
                    <select class="form-select sess-borrow-h" style="width:auto;font-size:0.85rem;">${hOpts(9)}</select>
                    <span style="padding:0 3px;font-weight:600">:</span>
                    <select class="form-select sess-borrow-m" style="width:auto;font-size:0.85rem;">${mOpts(30)}</select>
                </div>
            </div>
            <div>
                <label style="font-size:0.78rem;color:#374151;display:block;margin-bottom:0.2rem;">歸還日期</label>
                <input type="date" class="form-control sess-return-date" value="${d}" style="font-size:0.85rem;">
            </div>
            <div>
                <label style="font-size:0.78rem;color:#374151;display:block;margin-bottom:0.2rem;">歸還時間 <small style="color:#9ca3af;">(09:30–16:30)</small></label>
                <div class="d-flex align-items-center gap-1">
                    <select class="form-select sess-return-h" style="width:auto;font-size:0.85rem;">${hOpts(16)}</select>
                    <span style="padding:0 3px;font-weight:600">:</span>
                    <select class="form-select sess-return-m" style="width:auto;font-size:0.85rem;">${mOpts(30)}</select>
                </div>
            </div>
            <button type="button" onclick="querySessionEquip(this)"
                style="background:#1e4d6b;color:white;border:none;border-radius:8px;padding:0.55rem 0.9rem;font-weight:600;cursor:pointer;white-space:nowrap;font-size:0.85rem;transition:background 0.2s;"
                onmouseover="this.style.background='#14394f'" onmouseout="this.style.background='#1e4d6b'">
                <i class="bi bi-search me-1"></i>查詢可用數量
            </button>
        </div>
        <input type="hidden" class="sess-bs-hidden" name="sessions[${idx}][borrow_start]" value="${d?d+'T09:30':''}">
        <input type="hidden" class="sess-be-hidden" name="sessions[${idx}][borrow_end]"   value="${d?d+'T16:30':''}">
    </div>`;
}

function syncSessEquipTime(row) {
    const bd = row.querySelector('.sess-borrow-date')?.value;
    const bh = row.querySelector('.sess-borrow-h')?.value;
    const bm = row.querySelector('.sess-borrow-m')?.value;
    const rd = row.querySelector('.sess-return-date')?.value;
    const rh = row.querySelector('.sess-return-h')?.value;
    const rm = row.querySelector('.sess-return-m')?.value;
    const bsH = row.querySelector('.sess-bs-hidden');
    const beH = row.querySelector('.sess-be-hidden');
    if (bsH) bsH.value = (bd && bh && bm) ? `${bd}T${bh}:${bm}` : '';
    if (beH) beH.value = (rd && rh && rm) ? `${rd}T${rh}:${rm}` : '';
}

async function querySessionEquip(btn) {
    const row   = btn.closest('.session-row');
    const panel = row.querySelector('.session-equip-panel');
    syncSessEquipTime(row);
    const bt = row.querySelector('.sess-bs-hidden')?.value;
    const rt = row.querySelector('.sess-be-hidden')?.value;
    if (!bt || !rt) { alert('請先填寫借用日期與時間。'); return; }
    if (bt >= rt)   { alert('歸還時間必須晚於借用時間。'); return; }
    const tm = s => { const d = new Date(s); return d.getHours()*60+d.getMinutes(); };
    if (tm(bt)<9*60+30||tm(bt)>16*60+30||tm(rt)<9*60+30||tm(rt)>16*60+30) {
        alert('器材借還時間須在 09:30–16:30 之間。'); return;
    }
    const origTxt = btn.innerHTML;
    btn.disabled = true; btn.innerHTML = '<span class="submit-spinner"></span>查詢中…';
    try {
        const res  = await fetch(`get_equipment_availability.php?borrow_time=${encodeURIComponent(bt)}&return_time=${encodeURIComponent(rt)}`);
        const data = await res.json();
        panel.querySelectorAll('.equipment-card').forEach(card => {
            const inp = card.querySelector('input[type="number"]');
            if (!inp) return;
            const m = inp.name.match(/\[equipment\]\[(\d+)\]/);
            if (!m) return;
            const id    = m[1];
            const total = parseInt(inp.getAttribute('data-total')) || 0;
            const avail = data[id] !== undefined ? parseInt(data[id]) : total;
            inp.setAttribute('max', avail);
            if (parseInt(inp.value) > avail) { inp.value = avail; sessSync(inp); }
            const aqEl = card.querySelector('.avail-qty');
            if (aqEl) aqEl.textContent = avail;
            const stDiv = card.querySelector('.stock-available,.stock-low,.stock-empty');
            if (stDiv) stDiv.className = 'stock-' + (avail<=0?'empty':avail<3?'low':'available');
            const plus = inp.nextElementSibling;
            if (plus) plus.disabled = avail<=0;
        });
    } catch(e) { alert('查詢失敗，請稍後再試。'); }
    finally { btn.disabled = false; btn.innerHTML = origTxt; }
}

function sessClamped(inp) {
    const max = parseInt(inp.getAttribute('max')) || 0;
    let v = parseInt(inp.value);
    if (isNaN(v) || v < 0) inp.value = 0;
    else if (v > max) inp.value = max;
}
function sessSync(inp) {
    sessClamped(inp);
    const v   = parseInt(inp.value) || 0;
    const max = parseInt(inp.getAttribute('max')) || 0;
    const minus = inp.previousElementSibling;
    const plus  = inp.nextElementSibling;
    if (minus) minus.disabled = v <= 0;
    if (plus)  plus.disabled  = v >= max || max <= 0;
}
function sessQty(btn, delta) {
    const inp = btn.parentElement.querySelector('input[type="number"]');
    const max = parseInt(inp.getAttribute('max')) || 0;
    let v = (parseInt(inp.value) || 0) + delta;
    if (v < 0) v = 0; if (v > max) v = max;
    inp.value = v;
    sessSync(inp);
}

function toggleSessionEquip(btn) {
    const panel = btn.nextElementSibling;
    const open  = panel.style.display === 'none';
    panel.style.display   = open ? 'block' : 'none';
    btn.innerHTML         = open
        ? '<i class="bi bi-tools me-1"></i> 收起器材借用'
        : '<i class="bi bi-tools me-1"></i> 此場次新增器材借用';
    btn.style.borderStyle = open ? 'solid'   : 'dashed';
    btn.style.background  = open ? 'rgba(30,77,107,0.08)' : 'none';
    if (open) {
        const row    = btn.closest('.session-row');
        const sDate  = row.querySelector('input[name$="[date]"]')?.value    || '';
        const eDate  = row.querySelector('input[name$="[end_date]"]')?.value || sDate;
        const bdEl   = panel.querySelector('.sess-borrow-date');
        const rdEl   = panel.querySelector('.sess-return-date');
        if (bdEl && !bdEl.value && sDate) bdEl.value = sDate;
        if (rdEl && !rdEl.value)          rdEl.value = eDate || sDate;
        syncSessEquipTime(row);
    }
}

function addSession(date, startTime, endDate, endTime, venueId) {
    const idx  = sessionCount;
    sessionCount++;
    const html = `
        <div class="session-row" data-idx="${idx}">
            <div class="session-header">
                <span class="session-label"><i class="bi bi-calendar3"></i> 場次</span>
                <button type="button" class="btn-remove-session" onclick="removeSession(this)">
                    <i class="bi bi-trash"></i> 刪除
                </button>
            </div>
            <div class="session-fields">
                <div class="session-field">
                    <label>開始日期 *</label>
                    <input type="date" name="sessions[${idx}][date]" class="form-control" value="${date||''}" min="${todayDateString}" required>
                </div>
                <div class="session-field">
                    <label>開始時間 * <small style="color:#9ca3af;">(08:30–21:30)</small></label>
                    ${buildTimeSelects('sessions['+idx+'][start_time]', startTime||'')}
                </div>
                <div class="session-field">
                    <label>結束日期 *</label>
                    <input type="date" name="sessions[${idx}][end_date]" class="form-control" value="${endDate||date||''}" min="${todayDateString}" required>
                </div>
                <div class="session-field">
                    <label>結束時間 * <small style="color:#9ca3af;">(08:30–21:30)</small></label>
                    ${buildTimeSelects('sessions['+idx+'][end_time]', endTime||'')}
                </div>
                <div class="session-field">
                    <label>場地 *</label>
                    <select name="sessions[${idx}][venue_id]" class="form-control">
                        ${buildVenueOptions(venueId)}
                    </select>
                </div>
            </div>
            <div style="margin-top:0.75rem;">
                <button type="button" class="btn-equip-toggle"
                        onclick="toggleSessionEquip(this)"
                        style="background:none;border:1.5px dashed #1e4d6b;color:#1e4d6b;border-radius:8px;padding:0.35rem 0.85rem;cursor:pointer;font-size:0.83rem;font-weight:600;transition:all 0.2s;">
                    <i class="bi bi-tools me-1"></i> 此場次新增器材借用
                </button>
                <div class="session-equip-panel" style="display:none;margin-top:0.75rem;padding:0.85rem;background:#f8fafc;border:1px solid #e5e7eb;border-radius:10px;">
                    ${buildSessEquipTimePicker(idx, date||'')}
                    <div class="equipment-grid">
                        ${EQUIPMENT_LIST.map(eq => {
                            const sc  = eq.available > 0 ? (eq.available < 3 ? 'low' : 'available') : 'empty';
                            const dis = eq.available === 0 ? 'disabled' : '';
                            return `
                        <div class="equipment-card">
                            <div style="display:flex;align-items:center;gap:0.9rem;margin-bottom:0.85rem;">
                                <div style="width:46px;height:46px;border-radius:12px;background:#1e4d6b;color:white;display:flex;align-items:center;justify-content:center;font-size:0.9rem;font-weight:700;flex-shrink:0;">${escHtmlApply(eq.code)}</div>
                                <div style="flex:1;min-width:0;">
                                    <div class="equipment-name">${escHtmlApply(eq.name)}</div>
                                    <div style="color:#9ca3af;font-size:0.78rem;">編號：${escHtmlApply(eq.code)}</div>
                                </div>
                            </div>
                            <div style="text-align:right;font-size:0.88rem;">
                                <div class="stock-${sc}">剩餘：<span class="avail-qty">${eq.available}</span>/${eq.total}</div>
                            </div>
                            <div style="font-size:0.85rem;color:#6b7280;margin-top:0.35rem;">每次建議上限：${eq.borrowing_limit||'無限制'}</div>
                            <div class="counter mt-1">
                                <button type="button" class="btn-minus" onclick="sessQty(this,-1)" disabled>-</button>
                                <input type="number" name="sessions[${idx}][equipment][${eq.id}]" value="0" min="0" max="${eq.available}" data-total="${eq.total}" oninput="sessClamped(this)" onchange="sessSync(this)">
                                <button type="button" class="btn-plus" onclick="sessQty(this,1)" ${dis}>+</button>
                            </div>
                        </div>`;
                        }).join('')}
                    </div>
                </div>
            </div>
        </div>`;
    document.getElementById('sessions_container').insertAdjacentHTML('beforeend', html);
    renumberSessions();
}

function removeSession(btn) {
    const rows = document.querySelectorAll('.session-row');
    if (rows.length <= 1) { alert('至少需要保留一個場次！'); return; }
    btn.closest('.session-row').remove();
    renumberSessions();
}

function renumberSessions() {
    const rows = document.querySelectorAll('.session-row');
    rows.forEach((row, i) => {
        row.dataset.idx = i;
        row.querySelector('.session-label').innerHTML = `<i class="bi bi-calendar3"></i> 場次 ${i + 1}`;
        row.querySelectorAll('input[name], select[name]').forEach(el => {
            el.name = el.name.replace(/sessions\[\d+\]/, `sessions[${i}]`);
        });
        const removeBtn = row.querySelector('.btn-remove-session');
        if (removeBtn) removeBtn.style.display = rows.length > 1 ? 'inline-flex' : 'none';
    });
    sessionCount = rows.length;
}



// ── 時間選單同步 hidden input ────────────────────────────
function syncTimeValue(selectEl) {
    const sf = selectEl.closest('.session-field');
    if (!sf) return;
    const h = sf.querySelector('.time-hour').value;
    const m = sf.querySelector('.time-minute').value;
    const hidden = sf.querySelector('.time-value');
    if (hidden) hidden.value = (h && m) ? h + ':' + m : '';
}

function setSessionTimeField(row, fieldKey, value) {
    const hidden = row.querySelector('[name*="[' + fieldKey + ']"]');
    if (!hidden) return;
    hidden.value = value || '';
    const sf = hidden.closest('.session-field');
    if (sf && value && value.length >= 5) {
        const hourSel = sf.querySelector('.time-hour');
        const minSel  = sf.querySelector('.time-minute');
        if (hourSel) hourSel.value = value.substring(0, 2);
        if (minSel)  minSel.value  = value.substring(3, 5);
    }
}

document.getElementById('sessions_container').addEventListener('change', function(e) {
    if (e.target && (e.target.classList.contains('time-hour') || e.target.classList.contains('time-minute'))) {
        syncTimeValue(e.target);
    }
    if (e.target && e.target.matches('.session-row input[type="date"]')) {
        validateSessionDateInput(e.target);
    }
    if (e.target && e.target.matches('.sess-borrow-date,.sess-borrow-h,.sess-borrow-m,.sess-return-date,.sess-return-h,.sess-return-m')) {
        syncSessEquipTime(e.target.closest('.session-row'));
    }
    // 場次開始日期變動 → 同步更新借用日期
    if (e.target && e.target.matches('input[name$="[date]"]')) {
        const row = e.target.closest('.session-row');
        const bd  = row.querySelector('.sess-borrow-date');
        if (bd) { bd.value = e.target.value; syncSessEquipTime(row); }
    }
    // 場次結束日期變動 → 同步更新歸還日期
    if (e.target && e.target.matches('input[name$="[end_date]"]')) {
        const row = e.target.closest('.session-row');
        const rd  = row.querySelector('.sess-return-date');
        if (rd) { rd.value = e.target.value; syncSessEquipTime(row); }
    }
});

// 初始化各場次器材時段 hidden input（PHP 渲染場次）
document.querySelectorAll('.session-row').forEach(row => syncSessEquipTime(row));

// ── 表單送出驗證 ─────────────────────────────────────────
// 改用 button click（非 type=submit），避免瀏覽器原生 required 驗證
// 在我們移除空白場次之前就先擋下表單送出。
document.getElementById('submitBtn').addEventListener('click', function() {
    const form = document.getElementById('applicationForm');

    // 移除未填寫開始日期的場次（多按了新增場次但未填寫）
    document.querySelectorAll('.session-row').forEach(row => {
        const di = row.querySelector('[name*="[date]"]');
        if (!di.value) row.remove();
    });
    renumberSessions();

    // 觸發瀏覽器原生必填驗證（活動名稱、負責人、剩餘場次的必填欄位等）
    if (!form.reportValidity()) return;

    const rows = document.querySelectorAll('.session-row');
    if (rows.length === 0) { alert('請至少新增一個場次！'); return; }

    for (let i=0; i<rows.length; i++) {
        const n   = i+1;
        const di  = rows[i].querySelector('[name*="[date]"]');
        const sti = rows[i].querySelector('[name*="[start_time]"]');
        const edi = rows[i].querySelector('[name*="[end_date]"]');
        const eti = rows[i].querySelector('[name*="[end_time]"]');
        const vsl = rows[i].querySelector('[name*="[venue_id]"]');

        if (!di.value)  { alert(`場次${n}：請選擇開始日期！`);   di.focus();  return; }
        if (!validateSessionDateInput(di)) { di.focus(); return; }
        if (!sti.value) { alert(`場次${n}：請填寫開始時間！`);   sti.focus(); return; }
        if (!edi.value) { alert(`場次${n}：請選擇結束日期！`);   edi.focus(); return; }
        if (!validateSessionDateInput(edi)) { edi.focus(); return; }
        if (!eti.value) { alert(`場次${n}：請填寫結束時間！`);   eti.focus(); return; }
        if (edi.value < di.value) { alert(`場次${n}：結束日期不能早於開始日期！`); return; }
        if (edi.value===di.value && sti.value>=eti.value) { alert(`場次${n}：同日結束時間必須晚於開始時間！`); return; }
        if (sti.value<'08:30'||sti.value>'21:30'||eti.value<'08:30'||eti.value>'21:30') {
            alert(`場次${n}：時間須在 08:30–21:30！`); return;
        }
        if (vsl && !vsl.value) { alert(`場次${n}：請選擇場地！`); vsl.focus(); return; }
    }

    // ── 驗證全部通過 → 禁用送出按鈕並送出表單 ──────────────────
    this.disabled = true;
    this.innerHTML = '<span class="submit-spinner"></span>送出中，請稍候…';
    form.submit();
});

// 初始化
(function(){
    applySessionDateLimits();
    updateDeadlineReminder();
    updateFlagWarning();
    toggleEventType();
})();
</script>
</body>
</html>
