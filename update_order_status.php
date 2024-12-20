<?php
include 'db.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $order_id = intval($_POST['order_id']);
    $status = $_POST['status'];
    $delivery_date = isset($_POST['delivery_date']) ? $_POST['delivery_date'] : null;

    if ($status === 'completed') {
        $finished_date = date('Y-m-d');
        $finished_time = date('H:i:s');
        $update_sql = "UPDATE orders SET status = ?, finished_date = ?, finished_time = ? WHERE order_id = ?";
        $stmt = $conn->prepare($update_sql);
        $stmt->bind_param("sssi", $status, $finished_date, $finished_time, $order_id);
    } elseif ($status === 'delivered' && $delivery_date) {
        $update_sql = "UPDATE orders SET status = ?, delivery_date = ? WHERE order_id = ?";
        $stmt = $conn->prepare($update_sql);
        $stmt->bind_param("ssi", $status, $delivery_date, $order_id);
    } else {
        $update_sql = "UPDATE orders SET status = ? WHERE order_id = ?";
        $stmt = $conn->prepare($update_sql);
        $stmt->bind_param("si", $status, $order_id);
    }

    if ($stmt->execute()) {
        echo json_encode(['success' => true, 'message' => 'Order status updated successfully.']);
    } else {
        echo json_encode(['error' => 'Failed to update order status.']);
    }

    $stmt->close();
    $conn->close();
}
?>