<?php
// Set session cookie lifetime to 24 hours (86400 seconds)
ini_set('session.gc_maxlifetime', 86400);  // 24 hours
ini_set('session.cookie_lifetime', 86400); // 24 hours

// Set session cookie parameters (e.g., secure, httponly)
session_set_cookie_params([
    'lifetime' => 86400,  // 24 hours
    'path' => '/',
    'secure' => true,     // Set to true for HTTPS
]);

// Start the session
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