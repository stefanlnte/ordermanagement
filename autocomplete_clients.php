<?php
include 'db.php';

$query = $_GET['query'];
$sql = "SELECT name FROM clients WHERE name LIKE ?";
$stmt = $conn->prepare($sql);
$search = "%$query%";
$stmt->bind_param("s", $search);
$stmt->execute();
$result = $stmt->get_result();

$clients = array();
while ($row = $result->fetch_assoc()) {
    $clients[] = $row;
}

$stmt->close();
$conn->close();

echo json_encode($clients);
?>