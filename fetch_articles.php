<?php
header('Content-Type: application/json');
include 'db.php';

// Get the search term from the query string
$term = isset($_GET['term']) ? $_GET['term'] : '';

// Prepare SQL with optional filtering
$sql = "SELECT id, name, price FROM articles WHERE name LIKE CONCAT('%', ?, '%') ORDER BY name ASC";
$stmt = $conn->prepare($sql);
$stmt->bind_param("s", $term);
$stmt->execute();

$result = $stmt->get_result();
$articles = [];

while ($row = $result->fetch_assoc()) {
    $articles[] = $row;
}

echo json_encode($articles);
