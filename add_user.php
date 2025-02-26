<?php
include 'db.php';

$username = 'user';
$password = 'parola';
$role = 'OPERATOR';

// Hash the password
$hashed_password = password_hash($password, PASSWORD_DEFAULT);

// Insert the user into the database
$sql = "INSERT INTO users (username, password, role) VALUES (?, ?, ?)";
$stmt = $conn->prepare($sql);
$stmt->bind_param("sss", $username, $hashed_password, $role);

if ($stmt->execute()) {
    echo "User added successfully.";
} else {
    echo "Error: " . $stmt->error;
}

$stmt->close();
$conn->close();
?>
