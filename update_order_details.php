<?php
require 'db.php';

$order_id = (int)($_POST['order_id'] ?? 0);
$detalii  = array_key_exists('detalii_suplimentare', $_POST) ? $_POST['detalii_suplimentare'] : null;
$has_avans = array_key_exists('avans', $_POST);
$avans_val = $has_avans ? (float)$_POST['avans'] : null;

if (!$order_id) {
    http_response_code(400);
    exit('Lipsă order_id');
}

// Build SET parts dynamically, but always set total
$sets   = [];
$params = [];
$types  = '';

if (!is_null($detalii)) {
    $sets[]  = "detalii_suplimentare = ?";
    $params[] = $detalii;
    $types   .= 's';
}

if ($has_avans) {
    // We set avans explicitly if provided (including 0)
    $sets[]   = "avans = ?";
    $params[] = $avans_val;
    $types   .= 'd';
}

// total = sum(order_articles) - avans
// If avans not provided in this call, use current avans from orders
$sets[] = "total = (SELECT COALESCE(SUM(quantity * price_per_unit), 0) FROM order_articles oa WHERE oa.order_id = orders.order_id)"
    . ($has_avans ? " - ?" : " - COALESCE(avans, 0)");
if ($has_avans) {
    $params[] = $avans_val;
    $types   .= 'd';
}

if (empty($sets)) {
    http_response_code(400);
    exit('Niciun câmp de actualizat');
}

$sql = "UPDATE orders SET " . implode(', ', $sets) . " WHERE order_id = ?";
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
