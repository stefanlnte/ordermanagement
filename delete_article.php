<?php
include 'db.php';

$id = isset($_POST['id']) ? (int)$_POST['id'] : 0;
if ($id <= 0) {
    http_response_code(400);
    echo "Invalid ID";
    exit;
}

$stmt = $conn->prepare("DELETE FROM order_articles WHERE id = ?");
$stmt->bind_param('i', $id);
if ($stmt->execute()) {
    echo "Deleted";
} else {
    http_response_code(500);
    echo "Failed";
}
$stmt->close();
$conn->close();
