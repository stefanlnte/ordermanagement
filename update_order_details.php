<?php
require 'db.php';

$order_id = (int)($_POST['order_id'] ?? 0);
$detalii = $_POST['detalii_suplimentare'] ?? '';

if (!$order_id) {
    http_response_code(400);
    exit('Lipsă order_id');
}

$sql = "UPDATE orders SET detalii_suplimentare = ? WHERE order_id = ?";
$stmt = $conn->prepare($sql);
$stmt->bind_param("si", $detalii, $order_id);
if ($stmt->execute()) {
    echo 'OK';
} else {
    http_response_code(500);
    echo 'Eroare DB: ' . $stmt->error;
}
