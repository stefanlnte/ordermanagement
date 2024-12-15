<?php
// Set session cookie lifetime to 30 days
ini_set('session.gc_maxlifetime', 86400 * 30);
ini_set('session.cookie_lifetime', 86400 * 30);

session_set_cookie_params([
    'lifetime' => 86400 * 30,  // 30 days
    'path' => '/',
    'secure' => true,     // Set to true for HTTPS
    'httponly' => true,    // Helps prevent XSS attacks
]);

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
include 'db.php';

// Check if there is a "remember_token" cookie
if (isset($_COOKIE['remember_token'])) {
    $remember_token = $_COOKIE['remember_token'];

    // Query the database for the token associated with the user
    $token_sql = "SELECT u.user_id, u.username 
                  FROM users u
                  INNER JOIN remember_tokens t ON u.user_id = t.user_id
                  WHERE t.token = ?";
    $stmt = $conn->prepare($token_sql);
    if ($stmt) {
        $stmt->bind_param("s", $remember_token);
        $stmt->execute();
        $result = $stmt->get_result();

        // If a valid token is found, automatically log the user in
        if ($result->num_rows > 0) {
            $user = $result->fetch_assoc();
            $_SESSION['user_id'] = $user['user_id'];
            $_SESSION['username'] = $user['username'];
            echo "User logged in via remember token: " . $user['username'] . "<br>"; // Debugging statement
        } else {
            echo "Invalid remember token.<br>"; // Debugging statement
        }
        $stmt->close();
    } else {
        die("Database error: " . $conn->error);
    }
} else {
    echo "No remember token cookie found.<br>"; // Debugging statement
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = $_POST['username'];
    $password = $_POST['password'];
    $remember_me = isset($_POST['remember_me']) ? true : false;

    // Query to find user by username
    $login_sql = "SELECT user_id, username, password FROM users WHERE username = ?";
    $stmt = $conn->prepare($login_sql);
    if ($stmt) {
        $stmt->bind_param("s", $username);
        $stmt->execute();
        $result = $stmt->get_result();
        $user = $result->fetch_assoc();

        if ($user && password_verify($password, $user['password'])) {
            $_SESSION['username'] = $user['username'];
            $_SESSION['user_id'] = $user['user_id'];
            echo "User logged in: " . $user['username'] . "<br>"; // Debugging statement

            // If "Remember Me" is checked, create a persistent cookie
            if ($remember_me) {
                $remember_token = bin2hex(random_bytes(32));
                $token_sql = "INSERT INTO remember_tokens (user_id, token) VALUES (?, ?)";
                $stmt = $conn->prepare($token_sql);
                $stmt->bind_param("is", $user['user_id'], $remember_token);
                if ($stmt->execute()) {
                    setcookie("remember_token", $remember_token, time() + 86400 * 30, "/", "", true, true);
                    echo "Remember token set: " . $remember_token . "<br>"; // Debugging statement
                } else {
                    die("Database error: " . $stmt->error);
                }
            }

            header("Location: dashboard.php");
            exit();
        } else {
            echo "Invalid username or password.<br>"; // Debugging statement
        }
        $stmt->close();
    } else {
        die("Database error: " . $conn->error);
    }
}
$conn->close();
?>