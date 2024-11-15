<?php
include 'db.php'; // Include the database connection file

$order_id = $_POST['order_id'] ?? null;
$detalii_suplimentare = $_POST['detalii_suplimentare'] ?? null;
$total = $_POST['total'] ?? null;

if ($order_id && $detalii_suplimentare !== null && $total !== null) {
    echo "Received order_id: $order_id, detalii_suplimentare: $detalii_suplimentare, total: $total\n";
    $update_sql = "UPDATE orders SET detalii_suplimentare = ?, total = ? WHERE order_id = ?";
    $stmt = $conn->prepare($update_sql);
    $stmt->bind_param("sdi", $detalii_suplimentare, $total, $order_id);
    if ($stmt->execute()) {
        echo "Order details updated successfully.";
    } else {
        echo "Error updating order details: " . $stmt->error;
    }
    $stmt->close();
} else {
    echo "Invalid request.";
}
?>