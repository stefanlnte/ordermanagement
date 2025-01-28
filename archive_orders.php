<?php
include 'db.php';

// Start a transaction
$conn->begin_transaction();

try {
    // Check if there are any orders with order_id greater than 2000
    $check_order_id_sql = "SELECT COUNT(*) as count FROM orders WHERE order_id > 2000";
    $result = $conn->query($check_order_id_sql);
    if (!$result) {
        throw new Exception("Check order_id failed: " . $conn->error);
    }
    $row = $result->fetch_assoc();
    if ($row['count'] == 0) {
        throw new Exception("No orders with order_id greater than 2000 found.");
    }

    // Archive only delivered and cancelled orders with order_id greater than 2000
    $insert_archive_sql = "INSERT INTO archived_orders (
        order_id, client_id, order_details, order_date, order_time, due_date, due_time, 
        category_id, avans, total, assigned_to, status, delivery_date
    ) SELECT 
        order_id, client_id, order_details, order_date, order_time, due_date, due_time, 
        category_id, avans, total, assigned_to, status, delivery_date
    FROM orders
    WHERE status IN ('delivered', 'cancelled') AND order_id > 2000";
    if (!$conn->query($insert_archive_sql)) {
        throw new Exception("Archive insert failed: " . $conn->error);
    }

    // Delete archived orders from the `orders` table
    $delete_archived_sql = "DELETE FROM orders WHERE status IN ('delivered', 'cancelled') AND order_id > 2000";
    if (!$conn->query($delete_archived_sql)) {
        throw new Exception("Delete archived orders failed: " . $conn->error);
    }

    // Fetch all remaining orders and renumber them
    $select_remaining_orders_sql = "SELECT order_id FROM orders ORDER BY order_id";
    $result = $conn->query($select_remaining_orders_sql);
    if (!$result) {
        throw new Exception("Select remaining orders failed: " . $conn->error);
    }

    $order_id = 1; // Start renumbering from 1
    while ($row = $result->fetch_assoc()) {
        $update_order_id_sql = "UPDATE orders SET order_id = $order_id WHERE order_id = " . $row['order_id'];
        if (!$conn->query($update_order_id_sql)) {
            throw new Exception("Renumbering orders failed: " . $conn->error);
        }
        $order_id++;
    }

    // Reset AUTO_INCREMENT to start after the highest order_id
    $reset_auto_increment_sql = "ALTER TABLE orders AUTO_INCREMENT = $order_id";
    if (!$conn->query($reset_auto_increment_sql)) {
        throw new Exception("Reset AUTO_INCREMENT failed: " . $conn->error);
    }

    // Commit the transaction
    $conn->commit();
    echo "Delivered and cancelled orders with order_id greater than 2000 archived successfully.<br>";
    echo "Remaining orders renumbered successfully.<br>";
} catch (Exception $e) {
    // Rollback the transaction in case of error
    $conn->rollback();
    echo "Transaction failed: " . $e->getMessage() . "<br>";
} finally {
    // Close the connection
    $conn->close();
}
