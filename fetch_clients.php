<?php
include 'db.php'; // Include your database connection file

// Get the search term from the AJAX request
$searchTerm = $_GET['q'];

// Prepare the SQL query to fetch clients based on the search term (name OR phone)
$query = "SELECT client_id, client_name, client_phone 
          FROM clients 
          WHERE client_name LIKE ? OR client_phone LIKE ?";

$stmt = $conn->prepare($query);
$searchWildcard = "%" . $searchTerm . "%";
$stmt->bind_param("ss", $searchWildcard, $searchWildcard);
$stmt->execute();
$result = $stmt->get_result();

// Prepare an array to hold the client data
$clients = [];

// Fetch the results and populate the array
while ($row = $result->fetch_assoc()) {
    $clients[] = [
        'id' => $row['client_id'],
        'client_name' => $row['client_name'],
        'client_phone' => $row['client_phone'],
        // Show both name + phone in the dropdown text for clarity
        'text' => $row['client_name'] . " (" . $row['client_phone'] . ")"
    ];
}

// Close the statement and connection
$stmt->close();
$conn->close();

// Return the clients as JSON
header('Content-Type: application/json');
echo json_encode($clients);
