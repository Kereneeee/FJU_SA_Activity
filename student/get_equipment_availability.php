<?php
require_once(__DIR__ . "/../DB/db_config.php");

header('Content-Type: application/json');

$borrow_time = $_GET['borrow_time'] ?? '';
$return_time = $_GET['return_time'] ?? '';

if (!$borrow_time || !$return_time) {
    echo json_encode([]);
    exit;
}

/*
假設你的借用表：

equipment_borrow_records
------------------------
equipment_id
quantity
borrow_time
return_time
status

status:
approved
borrowed
returned
*/

$sql = "
SELECT 
    e.equipment_id,
    e.total_quantity,
    COALESCE(SUM(r.quantity), 0) AS borrowed_qty
FROM equipment e
LEFT JOIN equipment_borrow_records r
    ON e.equipment_id = r.equipment_id
    AND r.status IN ('approved', 'borrowed')

    -- 時間重疊判斷
    AND (
        r.borrow_time < ?
        AND r.return_time > ?
    )

GROUP BY e.equipment_id
";

$stmt = $conn->prepare($sql);
$stmt->bind_param("ss", $return_time, $borrow_time);
$stmt->execute();

$result = $stmt->get_result();

$data = [];

while ($row = $result->fetch_assoc()) {

    $available =
        $row['total_quantity'] - $row['borrowed_qty'];

    $data[$row['equipment_id']] = max(0, $available);
}

echo json_encode($data);