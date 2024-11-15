<?php
include 'db.php';

$response = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $client_id = $_POST['client_id'] ?? null;
    $order_details = $_POST['order_details'] ?? '';
    $avans = $_POST['avans'] ?? 0;
    $total = $_POST['total'] ?? 0;
    $due_date = $_POST['due_date'] ?? '';
    $due_time = $_POST['due_time'] ?? '';
    $category_id = $_POST['category_id'] ?? 0;
    $assigned_to = $_POST['assigned_to'] ?? null;

    // Check if client exists
    if ($client_id) {
        $client_check_sql = "SELECT client_id FROM clients WHERE client_id = ?";
        $stmt = $conn->prepare($client_check_sql);
        $stmt->bind_param("i", $client_id);
        $stmt->execute();
        $client_check_result = $stmt->get_result();

        if ($client_check_result->num_rows == 0) {
            // Client does not exist, create a new client
            $new_client_name = $_POST['client_name'] ?? 'Unknown Client';
            $new_client_email = $_POST['client_email'] ?? 'unknown@example.com';
            $new_client_phone = $_POST['client_phone'] ?? '0000000000';

            $insert_client_sql = "INSERT INTO clients (client_name, client_email, client_phone) VALUES (?, ?, ?)";
            $stmt = $conn->prepare($insert_client_sql);
            $stmt->bind_param("sss", $new_client_name, $new_client_email, $new_client_phone);

            if ($stmt->execute()) {
                $client_id = $stmt->insert_id;
            } else {
                $response['error'] = "Error creating new client: " . $stmt->error;
                echo json_encode($response);
                exit();
            }
        }
    } else {
        // No client ID provided, create a new client
        $new_client_name = $_POST['client_name'] ?? 'Unknown Client';
        $new_client_email = $_POST['client_email'] ?? 'unknown@example.com';
        $new_client_phone = $_POST['client_phone'] ?? '0000000000';

        $insert_client_sql = "INSERT INTO clients (client_name, client_email, client_phone) VALUES (?, ?, ?)";
        $stmt = $conn->prepare($insert_client_sql);
        $stmt->bind_param("sss", $new_client_name, $new_client_email, $new_client_phone);

        if ($stmt->execute()) {
            $client_id = $stmt->insert_id;
        } else {
            $response['error'] = "Error creating new client: " . $stmt->error;
            echo json_encode($response);
            exit();
        }
    }

    // Insert new order
    $order_date = date('Y-m-d'); // Current date
    $order_time = date('H:i:s'); // Current time
    $status = 'assigned'; // Set status to 'assigned' by default
    $order_sql = "INSERT INTO orders (client_id, order_details, avans, total, due_date, due_time, category_id, order_date, order_time, status, assigned_to) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
    $stmt = $conn->prepare($order_sql);
    $stmt->bind_param("issdssssssi", $client_id, $order_details, $avans, $total, $due_date, $due_time, $category_id, $order_date, $order_time, $status, $assigned_to);
    if ($stmt->execute()) {
        $response['success'] = true;
    } else {
        $response['error'] = "Error creating new order: " . $stmt->error;
    }
    $stmt->close();
} else {
    $response['error'] = "Invalid request method.";
}

$conn->close();
echo json_encode($response);
?>