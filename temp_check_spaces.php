<?php
require_once(__DIR__ . '/DB/db_config.php');

echo "=== All spaces ===\n";
$result = $conn->query("SELECT space_id, space_name FROM spaces WHERE space_status='available' ORDER BY space_id");
while ($row = $result->fetch_assoc()) {
    echo "Space ID " . $row['space_id'] . ": " . $row['space_name'] . "\n";
}

echo "\n=== Space with reservations in August ===\n";
$result2 = $conn->query("SELECT DISTINCT r.space_id, s.space_name FROM reservations r JOIN spaces s ON r.space_id = s.space_id WHERE MONTH(r.start_time) = 8 AND YEAR(r.start_time) = 2026");
while ($row = $result2->fetch_assoc()) {
    echo "Space ID " . $row['space_id'] . ": " . $row['space_name'] . "\n";
}

$conn->close();
?>
