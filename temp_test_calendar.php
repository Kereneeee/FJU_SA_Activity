<?php
require_once(__DIR__ . '/DB/db_config.php');

$selected_year = 2026;
$selected_month = 8;
$month_start = sprintf('%04d-%02d-01 00:00:00', $selected_year, $selected_month);
$month_end = date('Y-m-t 23:59:59', strtotime($month_start));

echo "Month range: $month_start to $month_end\n\n";

$sql_bookings = "SELECT r.space_id, r.start_time, r.end_time, e.event_id, e.event_name, e.club_name, u.name AS user_name, u.email AS user_email, e.status, e.is_field_coordination, fcr.is_approved, fcr.registration_id, fcs.coordination_meeting_date,
       CASE WHEN cc.conflict_id IS NOT NULL THEN 1 ELSE 0 END AS has_conflict
    FROM reservations r
    JOIN events e ON r.event_id = e.event_id
    LEFT JOIN field_coordination_registrations fcr ON e.event_id = fcr.event_id
    LEFT JOIN field_coordination_settings fcs ON fcr.setting_id = fcs.setting_id
    LEFT JOIN coordination_conflicts cc ON fcr.registration_id IN (cc.registration_id_1, cc.registration_id_2)
    LEFT JOIN users u ON e.user_id = u.user_id
    WHERE (r.start_time BETWEEN ? AND ?) OR (r.end_time BETWEEN ? AND ?)
    ORDER BY r.start_time ASC";

$stmt = $conn->prepare($sql_bookings);
if ($stmt) {
    $stmt->bind_param('ssss', $month_start, $month_end, $month_start, $month_end);
    $stmt->execute();
    $result_bookings = $stmt->get_result();
    echo "Results: " . $result_bookings->num_rows . " rows\n\n";
    while ($row = $result_bookings->fetch_assoc()) {
        echo "Event ID: " . $row['event_id'] . " | Name: " . $row['event_name'] . " | Club: " . $row['club_name'] . "\n";
        echo "  Time: " . $row['start_time'] . " - " . $row['end_time'] . "\n";
        echo "  Space: " . $row['space_id'] . " | is_field_coordination: " . $row['is_field_coordination'] . "\n";
        echo "  Status: " . $row['status'] . " | Approved: " . ($row['is_approved'] ?? 'NULL') . "\n\n";
    }
    $stmt->close();
} else {
    echo "Prepare failed: " . $conn->error . "\n";
}

echo "\n=== Direct query for all reservations ===\n";
$sql2 = "SELECT r.reservation_id, r.event_id, r.space_id, r.start_time, r.end_time, e.event_name, e.is_field_coordination
FROM reservations r
JOIN events e ON r.event_id = e.event_id
WHERE MONTH(r.start_time) = 8 AND YEAR(r.start_time) = 2026";
$result2 = $conn->query($sql2);
if ($result2) {
    echo "Results: " . $result2->num_rows . " rows\n";
    while ($row = $result2->fetch_assoc()) {
        echo "Res ID: " . $row['reservation_id'] . " | Event: " . $row['event_name'] . " | Time: " . $row['start_time'] . "\n";
    }
}

$conn->close();
?>
