<?php
require_once(__DIR__ . '/DB/db_config.php');

echo "=== Field Coordination Registrations ===\n";
$result = $conn->query("SELECT registration_id, event_id, club_id, club_name, is_approved, created_at FROM field_coordination_registrations LIMIT 20");
if ($result && $result->num_rows > 0) {
    while ($row = $result->fetch_assoc()) {
        echo json_encode($row) . "\n";
    }
} else {
    echo "No records found\n";
}

echo "\n=== Events with is_field_coordination=1 ===\n";
$result2 = $conn->query("SELECT event_id, event_name, club_name, is_field_coordination, status, start_time, end_time FROM events WHERE is_field_coordination=1 LIMIT 20");
if ($result2 && $result2->num_rows > 0) {
    while ($row = $result2->fetch_assoc()) {
        echo json_encode($row) . "\n";
    }
} else {
    echo "No records found\n";
}

echo "\n=== Reservations count ===\n";
$result3 = $conn->query("SELECT COUNT(*) as cnt, COUNT(DISTINCT event_id) as events FROM reservations");
if ($result3) {
    $row = $result3->fetch_assoc();
    echo "Total reservations: " . $row['cnt'] . " (across " . $row['events'] . " events)\n";
}

echo "\n=== Field coordination registrations by club ===\n";
$result4 = $conn->query("SELECT club_name, COUNT(*) as cnt FROM field_coordination_registrations GROUP BY club_name");
if ($result4 && $result4->num_rows > 0) {
    while ($row = $result4->fetch_assoc()) {
        echo $row['club_name'] . ": " . $row['cnt'] . "\n";
    }
}

$conn->close();
?>
