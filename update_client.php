<?php
include 'db.php';

$response = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $client_id = $_POST['client_id'];
    $client_name = $_POST['client_name'];
    $client_phone = $_POST['client_phone'];
    $client_email = $_POST['client_email'];

    $update_client_sql = "UPDATE clients SET client_name = ?, client_phone = ?, client_email = ? WHERE client_id = ?";
    $stmt = $conn->prepare($update_client_sql);
    $stmt->bind_param("sssi", $client_name, $client_phone, $client_email, $client_id);

    if ($stmt->execute()) {
        $response['success'] = true;
    } else {
        $response['error'] = "Error updating client: " . $stmt->error;
    }

    $stmt->close();
} else {
    $response['error'] = "Invalid request method.";
}

$conn->close();
echo json_encode($response);
?>