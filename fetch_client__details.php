<?php
include 'db.php';

$client_id = $_GET['client_id'] ?? '';

$response = ['success' => false];

if ($client_id) {
    $client_sql = "SELECT * FROM clients WHERE client_id = ?";
    $stmt = $conn->prepare($client_sql);
    $stmt->bind_param("i", $client_id);
    $stmt->execute();
    $client_result = $stmt->get_result();
    if ($client_result->num_rows > 0) {
        $client = $client_result->fetch_assoc();
        $response['success'] = true;
        $response['client_id'] = $client['client_id'];
        $response['client_name'] = $client['client_name'];
        $response['client_email'] = $client['client_email'];
        $response['client_phone'] = $client['client_phone'];
    } else {
        $response['message'] = 'Client not found.';
    }
    $stmt->close();
}

$conn->close();
echo json_encode($response);
?>