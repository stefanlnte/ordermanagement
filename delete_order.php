<?php
include 'db.php';

$order_id = $_POST['order_id'] ?? null;

if ($order_id) {
    // Update the order status to 'livrata'
    $update_order_sql = "UPDATE orders SET status = 'delivered' WHERE order_id = ?";
    $stmt = $conn->prepare($update_order_sql);
    $stmt->bind_param("i", $order_id);
    if ($stmt->execute()) {
        echo "Comanda a fost livrată cu succes 💰💰💰";
    } else {
        echo "Error updating order status: " . $stmt->error;
    }
    $stmt->close();
} else {
    echo "Order ID not provided.";
}

$conn->close();
?>