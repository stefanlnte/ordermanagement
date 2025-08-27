<?php
include 'db.php';
header('Content-Type: application/json');

$order_id = isset($_GET['order_id']) ? (int)$_GET['order_id'] : 0;
if ($order_id <= 0) {
    echo json_encode([]);
    exit;
}

$sql = "SELECT 
  oa.id AS id,          -- needed for delete
  a.name,
  oa.quantity,
  oa.price_per_unit
FROM order_articles oa
JOIN articles a ON oa.article_id = a.id
WHERE oa.order_id = ?";
$stmt = $conn->prepare($sql);
$stmt->bind_param('i', $order_id);
$stmt->execute();
$result = $stmt->get_result();

$rows = [];
while ($row = $result->fetch_assoc()) {
    $row['quantity'] = (int)$row['quantity'];
    $row['price_per_unit'] = (float)$row['price_per_unit'];
    $rows[] = $row;
}
echo json_encode($rows);
