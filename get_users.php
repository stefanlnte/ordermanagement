<?php
include 'db.php';

$sql = "SELECT username FROM users";
$result = $conn->query($sql);

$users = array();
while ($row = $result->fetch_assoc()) {
    $users[] = $row;
}

$conn->close();

echo json_encode($users);
?>