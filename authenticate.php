<?php
session_start();
include 'db.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = $_POST['username'];
    $password = $_POST['password'];

    $login_sql = "SELECT user_id, username, password FROM users WHERE username = ?";
    $stmt = $conn->prepare($login_sql);
    $stmt->bind_param("s", $username);
    $stmt->execute();
    $result = $stmt->get_result();
    $user = $result->fetch_assoc();

    if ($user && password_verify($password, $user['password'])) {
        $_SESSION['username'] = $user['username'];
        $_SESSION['user_id'] = $user['user_id']; // Set the user_id in the session
        header("Location: dashboard.php");
        exit();
    } else {
        echo "Invalid username or password.";
    }
    $stmt->close();
}
$conn->close();
?>