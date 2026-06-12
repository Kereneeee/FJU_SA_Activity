<?php
// 場協結果匯入／衝突協調共用函式

function csv_q($s) {
    return '"' . str_replace('"', '""', $s !== null ? $s : '') . '"';
}

function fc_bind_array($stmt, $types, $values) {
    $args = array($types);
    foreach ($values as &$v) { $args[] = &$v; }
    call_user_func_array(array($stmt, 'bind_param'), $args);
}

// 解析 edit_session POST 資料 → [['reservation_id'=>, 'space_id'=>, 'start_time'=>, 'end_time'=>], ...]
function parseEditSessions($raw) {
    $out = [];
    foreach ($raw as $s) {
        if (empty($s['start_time']) || empty($s['end_time']) || empty($s['date'])) continue;
        $end_date = !empty($s['end_date']) ? $s['end_date'] : $s['date'];
        $out[] = [
            'reservation_id' => intval($s['reservation_id']),
            'space_id'       => intval($s['space_id']),
            'start_time'     => $s['date']    . ' ' . $s['start_time'] . ':00',
            'end_time'       => $end_date . ' ' . $s['end_time']   . ':00',
        ];
    }
    return $out;
}

// 取得某場協設定下的登記、場次明細、可用場地，並偵測衝突分組
function fc_import_load_data($conn, $selected_setting_id) {
    // 登記清單
    $registrations = [];
    if ($selected_setting_id) {
        $sql = "SELECT
                    fcr.registration_id, fcr.club_name, fcr.is_approved, fcr.approval_note,
                    e.event_id, e.event_name, e.start_time, e.end_time,
                    e.responsible_person, e.description
                FROM field_coordination_registrations fcr
                JOIN events e ON fcr.event_id = e.event_id
                WHERE fcr.setting_id = ?
                ORDER BY e.start_time ASC, fcr.club_name ASC";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("i", $selected_setting_id);
        $stmt->execute();
        $res = $stmt->get_result();
        while ($row = $res->fetch_assoc()) { $registrations[] = $row; }
        $stmt->close();
    }

    // 場次明細（用於衝突偵測與顯示）
    $reg_sessions = []; // [registration_id => [['space_id'=>, 'space_name'=>, 'start_time'=>, 'end_time'=>], ...]]
    if ($selected_setting_id && !empty($registrations)) {
        $sql_sess = "SELECT res.reservation_id, res.space_id, res.start_time, res.end_time,
                            s.space_name, fcr.registration_id
                     FROM field_coordination_registrations fcr
                     JOIN events e ON fcr.event_id = e.event_id
                     JOIN reservations res ON e.event_id = res.event_id
                     LEFT JOIN spaces s ON res.space_id = s.space_id
                     WHERE fcr.setting_id = ?
                     ORDER BY fcr.registration_id, res.start_time";
        $stmt_s = $conn->prepare($sql_sess);
        $stmt_s->bind_param("i", $selected_setting_id);
        $stmt_s->execute();
        $res_s = $stmt_s->get_result();
        while ($row_s = $res_s->fetch_assoc()) {
            $reg_sessions[$row_s['registration_id']][] = $row_s;
        }
        $stmt_s->close();
    }

    // 所有可用場地（編輯場次用）
    $all_spaces = [];
    $sp_res = $conn->query("SELECT space_id, space_name FROM spaces WHERE space_status='available' ORDER BY space_id");
    if ($sp_res) { while ($sp = $sp_res->fetch_assoc()) $all_spaces[] = $sp; }

    // 偵測衝突（BFS 分組，以場次為單位精確比對）
    $adj = [];
    foreach ($registrations as $r) { $adj[$r['registration_id']] = []; }
    $reg_ids = array_column($registrations, 'registration_id');

    for ($i = 0; $i < count($reg_ids); $i++) {
        for ($j = $i + 1; $j < count($reg_ids); $j++) {
            $a_rid = $reg_ids[$i];
            $b_rid = $reg_ids[$j];
            $a_sessions = $reg_sessions[$a_rid] ?? [];
            $b_sessions = $reg_sessions[$b_rid] ?? [];
            $found = false;
            foreach ($a_sessions as $sa) {
                foreach ($b_sessions as $sb) {
                    if ($sa['space_id'] == $sb['space_id'] &&
                        $sa['start_time'] < $sb['end_time'] &&
                        $sb['start_time'] < $sa['end_time']) {
                        $found = true;
                        break 2;
                    }
                }
            }
            if ($found) {
                $adj[$a_rid][] = $b_rid;
                $adj[$b_rid][] = $a_rid;
            }
        }
    }

    // BFS 找連通分量（衝突組）
    $visited        = [];
    $conflict_groups = [];   // [ [reg_id, ...], ... ]
    $reg_map        = [];
    foreach ($registrations as $r) { $reg_map[$r['registration_id']] = $r; }

    foreach ($registrations as $r) {
        $rid = $r['registration_id'];
        if (isset($visited[$rid]) || empty($adj[$rid])) continue;
        $group = [];
        $queue = [$rid];
        $visited[$rid] = true;
        while (!empty($queue)) {
            $cur = array_shift($queue);
            $group[] = $cur;
            foreach ($adj[$cur] as $nb) {
                if (!isset($visited[$nb])) { $visited[$nb] = true; $queue[] = $nb; }
            }
        }
        if (count($group) > 1) { $conflict_groups[] = $group; }
    }

    $all_conflicting = [];
    foreach ($conflict_groups as $g) { foreach ($g as $id) { $all_conflicting[] = $id; } }

    $clean_regs = array_values(array_filter($registrations, function($r) use ($all_conflicting) {
        return !in_array($r['registration_id'], $all_conflicting);
    }));

    // 統計
    $stat = ['pending' => 0, 'approved' => 0, 'rejected' => 0, 'conflict' => count($all_conflicting)];
    foreach ($registrations as $r) {
        if ($r['is_approved'] === null)         $stat['pending']++;
        elseif ((int)$r['is_approved'] === 1)   $stat['approved']++;
        else                                    $stat['rejected']++;
    }

    return [
        'registrations'    => $registrations,
        'reg_sessions'     => $reg_sessions,
        'all_spaces'       => $all_spaces,
        'conflict_groups'  => $conflict_groups,
        'all_conflicting'  => $all_conflicting,
        'clean_regs'       => $clean_regs,
        'reg_map'          => $reg_map,
        'stat'             => $stat,
    ];
}
