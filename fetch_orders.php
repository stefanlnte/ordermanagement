<?php
include 'db.php';

$response = array('success' => false);

// Fetch unassigned and assigned orders
$in_progress_orders_sql = "SELECT o.*, c.name AS client_name FROM orders o 
                           JOIN clients c ON o.client_exist = c.name 
                           WHERE o.status IN ('UNASSIGNED', 'IN PROGRESS')";
$in_progress_orders_result = $conn->query($in_progress_orders_sql);

if (!$in_progress_orders_result) {
    die("Error executing query: " . $conn->error);
}

$in_progress_orders = array();
while ($order = $in_progress_orders_result->fetch_assoc()) {
    $in_progress_orders[] = $order;
}

// Fetch completed orders
$completed_orders_sql = "SELECT o.*, c.name AS client_name FROM orders o 
                         JOIN clients c ON o.client_exist = c.name 
                         WHERE o.status='FINISHED'";
$completed_orders_result = $conn->query($completed_orders_sql);

if (!$completed_orders_result) {
    die("Error executing query: " . $conn->error);
}

$completed_orders = array();
while ($order = $completed_orders_result->fetch_assoc()) {
    $completed_orders[] = $order;
}

$response['success'] = true;
$response['in_progress_orders'] = $in_progress_orders;
$response['completed_orders'] = $completed_orders;

$conn->close();
echo json_encode($response);
?>