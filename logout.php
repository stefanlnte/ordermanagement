<?php
session_start();
include 'db.php';

// Remove the remember me token from the database and cookie
if (isset($_COOKIE['remember_token'])) {
    $remember_token = $_COOKIE['remember_token'];

    // Delete the token from the database
    $token_sql = "DELETE FROM remember_tokens WHERE token = ?";
    $stmt = $conn->prepare($token_sql);
    $stmt->bind_param("s", $remember_token);
    $stmt->execute();
    $stmt->close();

    // Delete the cookie
    setcookie("remember_token", "", time() - 3600, "/", "", false, true);
}

// Destroy the session
session_destroy();
header("Location: login.php");
exit();
?>
