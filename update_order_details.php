<?php
require 'db.php';

$order_id = (int)($_POST['order_id'] ?? 0);
$detalii = $_POST['detalii_suplimentare'] ?? '';
$avans   = $_POST['avans'] ?? null; // optional

if (!$order_id) {
    http_response_code(400);
    exit('Lipsă order_id');
}

// Build dynamic SQL depending on what fields are provided
$fields = [];
$params = [];
$types  = '';

if ($detalii !== null) {
    $fields[] = "detalii_suplimentare = ?";
    $params[] = $detalii;
    $types   .= 's';
}
if ($avans !== null && $avans !== '') {
    $fields[] = "avans = ?";
    $params[] = $avans;
    $types   .= 'd'; // double
}

if (empty($fields)) {
    http_response_code(400);
    exit('Niciun câmp de actualizat');
}

$sql = "UPDATE orders SET " . implode(', ', $fields) . " WHERE order_id = ?";
$params[] = $order_id;
$types   .= 'i';

$stmt = $conn->prepare($sql);
$stmt->bind_param($types, ...$params);

if ($stmt->execute()) {
    echo 'OK';
} else {
    http_response_code(500);
    echo 'Eroare DB: ' . $stmt->error;
}
