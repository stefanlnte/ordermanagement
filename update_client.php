<?php
// update_client.php
// Used to edit client details in the add order form, and by the client
// editor on view_order.php (update_client.php is the single write path
// for a client's name / phone / email across the app).
include 'db.php';

$client_id = $_POST['edit_client_id'] ?? '';
$client_name = trim($_POST['edit_client_name'] ?? '');
$client_phone = trim($_POST['edit_client_phone'] ?? '');
$client_email = trim($_POST['edit_client_email'] ?? '');

if ($client_id && $client_name && $client_phone) {
    // client_email is nullable, so an emptied field is stored as NULL rather
    // than as an empty string — otherwise "has no email" and "has a blank
    // email" become indistinguishable (and the former is what the old
    // dashboard modal produced whenever the field was left untouched).
    if ($client_email === '') {
        $sql = "UPDATE clients SET client_name = ?, client_phone = ?, client_email = NULL WHERE client_id = ?";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("ssi", $client_name, $client_phone, $client_id);
    } else {
        $sql = "UPDATE clients SET client_name = ?, client_phone = ?, client_email = ? WHERE client_id = ?";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("sssi", $client_name, $client_phone, $client_email, $client_id);
    }

    if ($stmt->execute()) {
        echo "Client updated successfully!";
    } else {
        echo "Error updating client: " . $stmt->error;
    }
    $stmt->close();
} else {
    echo "Error updating client: missing or empty data.";
}
