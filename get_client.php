<?php
// get_client.php
// used to fetch clients for the new order form, then used to edit client details
include 'db.php';

$client_id = isset($_GET['client_id']) ? $_GET['client_id'] : 0;

if ($client_id) {
    $sql = "SELECT client_id, client_name, client_phone, client_email FROM clients WHERE client_id = ?";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("i", $client_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $client = $result->fetch_assoc();
    echo json_encode($client);
}
