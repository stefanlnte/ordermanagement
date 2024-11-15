<?php
include 'db.php';

$client_id = $_POST['client_id'] ?? '';
$client_name = $_POST['client_name'] ?? '';
$client_email = $_POST['client_email'] ?? '';
$client_phone = $_POST['client_phone'] ?? '';

if ($client_id && $client_name && $client_email && $client_phone) {
    $update_sql = "UPDATE clients SET client_name = ?, client_email = ?, client_phone = ? WHERE client_id = ?";
    $stmt = $conn->prepare($update_sql);
    $stmt->bind_param("sssi", $client_name, $client_email, $client_phone, $client_id);
    if ($stmt->execute()) {
        header("Location: dashboard.php");
        exit();
    } else {
        echo "Error updating client: " . $stmt->error;
    }
    $stmt->close();
}

$conn->close();
?>