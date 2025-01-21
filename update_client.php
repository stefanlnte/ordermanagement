<?php
// update_client.php
// Used to edit client details in the add order form
include 'db.php';

$client_id = $_POST['edit_client_id'];
$client_name = $_POST['edit_client_name'];
$client_phone = $_POST['edit_client_phone'];
$client_email = $_POST['edit_client_email'];

if ($client_id) {
    $sql = "UPDATE clients SET client_name = ?, client_phone = ?, client_email = ? WHERE client_id = ?";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("sssi", $client_name, $client_phone, $client_email, $client_id);
    if ($stmt->execute()) {
        echo "Client updated successfully!";
    } else {
        echo "Error updating client: " . $stmt->error;
    }
}
