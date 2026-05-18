<?php
require_once(__DIR__ . "/../DB/db_config.php");

header('Content-Type: application/json');

$borrow_time = $_GET['borrow_time'] ?? '';
$return_time = $_GET['return_time'] ?? '';

if (!$borrow_time || !$return_time) {
    echo json_encode([]);
    exit;
}

$sql = "
SELECT 
    e.equipment_id,
    e.total_quantity,

    COALESCE(SUM(
        CASE
            WHEN ev.start_time < ?
             AND ev.end_time > ?
             AND ev.status IN ('pending', 'approved')
            THEN eb.quantity
            ELSE 0
        END
    ), 0) AS borrowed_qty

FROM equipment e

LEFT JOIN equipment_borrow eb
    ON e.equipment_id = eb.equipment_id

LEFT JOIN events ev
    ON eb.event_id = ev.event_id

WHERE e.status = 'available'

GROUP BY e.equipment_id
";

$stmt = $conn->prepare($sql);

$stmt->bind_param(
    "ss",
    $return_time,
    $borrow_time
);

$stmt->execute();

$result = $stmt->get_result();

$data = [];

while ($row = $result->fetch_assoc()) {

    $available =
        $row['total_quantity']
        - $row['borrowed_qty'];

    $data[$row['equipment_id']] =
        max(0, $available);
}

echo json_encode($data);