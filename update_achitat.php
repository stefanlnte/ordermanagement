<?php
include 'db.php';

$order_id = $_POST['order_id'] ?? null;
$is_achitat = $_POST['is_achitat'] ?? null;

if ($order_id !== null && $is_achitat !== null) {
    $stmt = $conn->prepare("UPDATE orders SET is_achitat = ? WHERE order_id = ?");
    $stmt->bind_param("ii", $is_achitat, $order_id);
    if ($stmt->execute()) {
        echo "OK";
    } else {
        echo "Error: " . $stmt->error;
    }
    $stmt->close();
}
