<?php
// Start the session to access session variables
session_start();

// Include the database connection file
include 'db.php';

// Check if there is a "remember_token" cookie set
if (isset($_COOKIE['remember_token'])) {
    // Retrieve the value of the "remember_token" cookie
    $remember_token = $_COOKIE['remember_token'];

    // Prepare a SQL query to find the token associated with the user in the database
    $token_sql = "SELECT user_id FROM remember_tokens WHERE token = ?";
    $stmt = $conn->prepare($token_sql);

    // Check if the prepared statement was successful
    if ($stmt) {
        // Bind the remember_token to the prepared statement
        $stmt->bind_param("s", $remember_token);

        // Execute the prepared statement
        $stmt->execute();

        // Get the result of the query
        $result = $stmt->get_result();

        // Check if the token exists in the database
        if ($result->num_rows > 0) {
            // Fetch the user_id associated with the token
            $row = $result->fetch_assoc();
            $user_id = $row['user_id'];

            // Prepare a SQL query to delete the specific token from the database
            $delete_sql = "DELETE FROM remember_tokens WHERE token = ?";
            $delete_stmt = $conn->prepare($delete_sql);

            // Check if the prepared statement was successful
            if ($delete_stmt) {
                // Bind the remember_token to the prepared statement
                $delete_stmt->bind_param("s", $remember_token);

                // Execute the prepared statement to delete the token
                $delete_stmt->execute();

                // Close the delete statement
                $delete_stmt->close();

                // Delete the "remember_token" cookie by setting its expiration time to one hour ago
                setcookie("remember_token", "", time() - 3600, "/", "", true, true);
            } else {
                // Die with an error message if the delete statement preparation fails
                die("Database error: " . $conn->error);
            }
        } else {
            // Output a debugging message if the token is not found in the database
            echo "Invalid remember token.<br>";
        }

        // Close the initial select statement
        $stmt->close();
    } else {
        // Die with an error message if the select statement preparation fails
        die("Database error: " . $conn->error);
    }
} else {
    // Output a debugging message if no "remember_token" cookie is found
    echo "No remember token cookie found.<br>";
}

// Destroy the current session
session_destroy();

// Redirect the user to the login page
header("Location: login.php");

// Exit the script to prevent further execution
exit();
?>