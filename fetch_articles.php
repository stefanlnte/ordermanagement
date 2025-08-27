<?php
header('Content-Type: application/json');
include 'db.php';
$result = $conn->query("SELECT id, name, price FROM articles");
echo json_encode($result->fetch_all(MYSQLI_ASSOC));
