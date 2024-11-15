<?php
include 'db.php';

// Delete delivered orders
$delete_delivered_sql = "DELETE FROM orders WHERE status = 'delivered'";
if ($conn->query($delete_delivered_sql) === TRUE) {
    echo "Delivered orders deleted successfully.";
} else {
    echo "Error deleting delivered orders: " . $conn->error;
}

// Check the highest order number
$max_order_sql = "SELECT MAX(order_id) as max_order_id FROM orders";
$max_order_result = $conn->query($max_order_sql);
$max_order_row = $max_order_result->fetch_assoc();
$max_order_id = $max_order_row['max_order_id'];

if ($max_order_id >= 200) {
    // Reset order numbers
    $reset_order_sql = "SET @count = 0;
                        UPDATE orders SET order_id = @count:= @count + 1 WHERE status != 'delivered';
                        ALTER TABLE orders AUTO_INCREMENT = 1;";
    if ($conn->multi_query($reset_order_sql) === TRUE) {
        echo "Order numbers reset successfully.";
    } else {
        echo "Error resetting order numbers: " . $conn->error;
    }
}

$conn->close();
?>