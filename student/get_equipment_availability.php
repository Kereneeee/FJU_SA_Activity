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
            WHEN COALESCE(eb.borrow_start, er.borrow_start) < ?
             AND COALESCE(eb.borrow_end,   er.borrow_end)   > ?
             AND (
                 (eb.request_id IS NULL     AND ev.status = 'approved') OR
                 (eb.request_id IS NOT NULL AND er.status = 'approved')
             )
            THEN eb.quantity
            ELSE 0
        END
    ), 0) AS borrowed_qty

FROM equipment e

LEFT JOIN equipment_borrow eb
    ON e.equipment_id = eb.equipment_id

LEFT JOIN events ev
    ON eb.event_id = ev.event_id

LEFT JOIN equipment_requests er
    ON eb.request_id = er.request_id

WHERE e.equipment_status = 'available'

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