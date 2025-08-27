<?php
include 'db.php';
$order_id = $_POST['order_id'];
$is_pinned = $_POST['is_pinned'];
$stmt = $conn->prepare("UPDATE orders SET is_pinned = ? WHERE order_id = ?");
$stmt->bind_param("ii", $is_pinned, $order_id);
$stmt->execute();
echo "success";
