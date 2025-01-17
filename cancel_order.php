<?php
include 'db.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $order_id = intval($_POST['order_id']);
    $status = 'cancelled';

    $update_sql = "UPDATE orders SET status = ? WHERE order_id = ?";
    $stmt = $conn->prepare($update_sql);
    $stmt->bind_param("si", $status, $order_id);

    if ($stmt->execute()) {
        echo "Comanda a fost anulată cu succes ❌❌❌";
    } else {
        echo json_encode(['error' => 'Failed to cancel order.']);
    }

    $stmt->close();
    $conn->close();
}
