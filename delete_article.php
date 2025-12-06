<?php
include 'db.php';

$id = isset($_POST['id']) ? (int)$_POST['id'] : 0;
if ($id <= 0) {
    http_response_code(400);
    echo "Invalid ID";
    exit;
}

// Mai întâi aflăm order_id pentru articolul respectiv
$stmt = $conn->prepare("SELECT order_id FROM order_articles WHERE id = ?");
$stmt->bind_param('i', $id);
$stmt->execute();
$result = $stmt->get_result();
$row = $result->fetch_assoc();
$order_id = $row['order_id'] ?? 0;
$stmt->close();

if ($order_id <= 0) {
    http_response_code(404);
    echo "Order not found for this article.";
    exit;
}

// Ștergem articolul
$stmt = $conn->prepare("DELETE FROM order_articles WHERE id = ?");
$stmt->bind_param('i', $id);
if ($stmt->execute()) {
    // Recalculăm totalul articolelor rămase
    $sum_sql = "SELECT SUM(quantity * price_per_unit) AS total_articles
                FROM order_articles
                WHERE order_id = ?";
    $stmt_sum = $conn->prepare($sum_sql);
    $stmt_sum->bind_param("i", $order_id);
    $stmt_sum->execute();
    $result_sum = $stmt_sum->get_result();
    $row_sum = $result_sum->fetch_assoc();
    $total_articles = (float)($row_sum['total_articles'] ?? 0);
    $stmt_sum->close();

    // Fetch current avans
    $stmt_av = $conn->prepare("SELECT COALESCE(avans,0) AS avans FROM orders WHERE order_id = ?");
    $stmt_av->bind_param("i", $order_id);
    $stmt_av->execute();
    $res_av = $stmt_av->get_result();
    $row_av = $res_av->fetch_assoc();
    $current_avans = (float)($row_av['avans'] ?? 0);
    $stmt_av->close();

    $new_total = $total_articles - $current_avans;
    if ($new_total < 0) $new_total = 0;

    // Actualizăm orders.total
    $update_sql = "UPDATE orders SET total = ? WHERE order_id = ?";
    $stmt_update = $conn->prepare($update_sql);
    $stmt_update->bind_param("di", $new_total, $order_id);
    $stmt_update->execute();
    $stmt_update->close();

    echo "Deleted and total updated";
} else {
    http_response_code(500);
    echo "Failed to delete article.";
}

$stmt->close();
$conn->close();
