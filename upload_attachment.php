<?php
include 'db.php';

$orderId = (int)($_POST['order_id'] ?? 0);
if ($orderId <= 0) {
    http_response_code(400);
    echo "Invalid order ID";
    exit;
}

$uploadDir = __DIR__ . "/uploads/orders/$orderId/";
if (!is_dir($uploadDir)) {
    mkdir($uploadDir, 0777, true);
}

if (!empty($_FILES['file']['name'])) {
    $filename = basename($_FILES['file']['name']);
    $targetPath = $uploadDir . $filename;

    if (move_uploaded_file($_FILES['file']['tmp_name'], $targetPath)) {
        $stmt = $conn->prepare("INSERT INTO order_attachments (order_id, filename, filepath) VALUES (?, ?, ?)");
        $stmt->bind_param("iss", $orderId, $filename, $targetPath);
        $stmt->execute();
        echo "File uploaded successfully!";
    } else {
        http_response_code(500);
        echo "Upload failed.";
    }
}
