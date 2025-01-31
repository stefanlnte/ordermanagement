<?php
session_start();
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

include 'db.php';

// Check if the request is an AJAX request
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['ajax'])) {
    if (isset($_POST['delete_order'])) {
        $order_id = $_POST['order_id'];

        $delete_order_sql = "DELETE FROM unpaid_orders WHERE order_id = ?";
        $stmt = $conn->prepare($delete_order_sql);
        $stmt->bind_param("i", $order_id);
        if ($stmt->execute()) {
            echo "Comanda a fost ștearsă cu succes!";
        } else {
            echo "Error deleting order: " . $stmt->error;
        }
        $stmt->close();
        exit; // Exit to prevent further HTML output
    }
}

// Fetch all users for the "created by" dropdown
$users_sql = "SELECT user_id, username FROM users";
$users_result = $conn->query($users_sql);
$users = [];
if ($users_result->num_rows > 0) {
    while ($row = $users_result->fetch_assoc()) {
        $users[] = $row;
    }
}

// Fetch all clients for the "client" dropdown
$clients_sql = "SELECT client_id, client_name FROM clients";
$clients_result = $conn->query($clients_sql);
$clients = [];
if ($clients_result->num_rows > 0) {
    while ($row = $clients_result->fetch_assoc()) {
        $clients[] = $row;
    }
}

// Handle form submission for adding an unpaid order
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_unpaid_order'])) {
    $client_id = $_POST['client_id'];
    $order_details = $_POST['order_details'];
    $created_by = $_SESSION['user_id'];

    $insert_order_sql = "INSERT INTO unpaid_orders (client_id, order_details, created_by) VALUES (?, ?, ?)";
    $stmt = $conn->prepare($insert_order_sql);
    $stmt->bind_param("iss", $client_id, $order_details, $created_by); // Corrected to "iss"
    if ($stmt->execute()) {
        // Redirect to prevent form resubmission
        header("Location: unpaid_orders.php");
        exit; // Exit to prevent further HTML output
    } else {
        echo "Error adding new unpaid order: " . $stmt->error;
    }
    $stmt->close();
}

// Fetch unpaid orders
$unpaid_orders_sql = "SELECT uo.*, c.client_name, us.username as creator_name FROM unpaid_orders uo 
                      JOIN clients c ON uo.client_id = c.client_id 
                      JOIN users us ON uo.created_by = us.user_id";
$unpaid_orders_result = $conn->query($unpaid_orders_sql);
$unpaid_orders = [];
if ($unpaid_orders_result->num_rows > 0) {
    while ($row = $unpaid_orders_result->fetch_assoc()) {
        $unpaid_orders[] = $row;
    }
}
?>

<!DOCTYPE html>
<html>

<head>
    <title>Comenzi Neplate</title>
    <link rel="stylesheet" type="text/css" href="styles.css">
    <link rel="stylesheet" href="https://code.jquery.com/ui/1.12.1/themes/base/jquery-ui.css">
    <link rel="stylesheet" type="text/css" href="styles.css">
    <link rel="stylesheet" type="text/css" href="style.css">
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="https://code.jquery.com/ui/1.12.1/jquery-ui.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/select2/4.0.13/js/select2.min.js"></script>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/select2/4.0.13/css/select2.min.css" rel="stylesheet" />
    <script>
        document.addEventListener('DOMContentLoaded', function() {
            $('#client_id').select2({
                dropdownAutoWidth: true,
                width: 'auto'
            });
        });

        function deleteOrder(orderId) {
            if (confirm("Sigur doriți să ștergeți această comandă?")) {
                $.ajax({
                    url: 'unpaid_orders.php',
                    type: 'POST',
                    data: {
                        ajax: 1,
                        delete_order: 1,
                        order_id: orderId
                    },
                    success: function(response) {
                        alert(response);
                        location.reload();
                    },
                    error: function(xhr, status, error) {
                        alert("An error occurred: " + error);
                    }
                });
            }
        }
    </script>
    <style>
        /* Yellow theme for Select2 */
        .select2-container--default .select2-selection--single {
            background-color: #fff;
            border: 1px solid #a9a9a9;
            /* Dark grey color for border */
            border-radius: 4px;
            /* Rounded border */
            box-shadow: 0 2px 5px rgba(0, 0, 0, 0.1);
            font-size: 16px;
            /* Increase font size for better visibility */
        }

        .select2-container--default .select2-selection--single .select2-selection__rendered {
            color: #333;
            padding-left: 5px;
            font-size: 14px;
            /* Adjust font size for the selected item */
            text-align: left;
            /* Align text to the left */
        }

        .select2-container--default .select2-selection--single .select2-selection__arrow {
            background-color: #fff;
            /* White background for the arrow */
            border: none;
            /* Remove border around the arrow */
            border-radius: 0 4px 4px 0;
            /* Rounded right side */
        }

        .select2-container--default .select2-selection--single .select2-selection__arrow b {
            border-color: #a9a9a9 transparent transparent transparent;
            /* Dark grey arrow */
            border-width: 5px 4px 0 4px;
        }

        .select2-container--default .select2-results__option {
            padding: 12px;
            color: #333;
            font-size: 14px;
            /* Adjust font size for the dropdown options */
            white-space: nowrap;
            /* Prevent text from wrapping */
            text-align: left;
            /* Align text to the left */
        }

        .select2-container--default .select2-results__option--highlighted[aria-selected] {
            background-color: #FFFF00;
            /* Yellow color */
            color: #000;
            text-align: left;
            /* Align text to the left */
        }

        .select2-container--default .select2-search--dropdown .select2-search__field {
            border: 1px solid #a9a9a9;
            /* Dark grey color */
            outline: none;
            padding: 8px;
            border-radius: 4px;
            /* Rounded border */
            width: 100%;
            box-sizing: border-box;
            font-size: 14px;
            /* Adjust font size for the search field */
            text-align: left;
            /* Align text to the left */
        }

        .select2-container--default .select2-search--dropdown .select2-search__field:focus {
            border-color: #708090;
            /* Light grey color for focus */
            box-shadow: 0 0 5px rgba(169, 169, 169, 0.5);
        }

        .select2-container--default .select2-selection--multiple .select2-selection__choice {
            background-color: #FFFF00;
            /* Yellow color */
            border: 1px solid #a9a9a9;
            /* Dark grey color */
            color: #000;
            padding: 5px 10px;
            border-radius: 4px;
            /* Rounded border */
            margin-top: 5px;
            margin-right: 5px;
            white-space: nowrap;
            /* Prevent text from wrapping */
            font-size: 14px;
            /* Adjust font size for multiple selection choices */
            text-align: left;
            /* Align text to the left */
        }

        .select2-container--default .select2-selection--multiple .select2-selection__choice__remove {
            color: #000;
            font-weight: bold;
            margin-right: 5px;
        }

        /* Remove scrollbar */
        .select2-container--default .select2-results {
            overflow-y: hidden !important;
            /* Remove vertical scrollbar */
            max-width: 100% !important;
            /* Ensure dropdown is wide enough */
        }

        .select2-container--default .select2-results__options {
            max-width: 100% !important;
            /* Ensure options are wide enough */
        }
    </style>

</head>

<body>
    <header>
        <h1 style="text-align: center;">Comenzi nefacturate</h1>
        <div style="margin-left: 10px; text-align:center; margin-bottom:25px;" class="button"><a href="dashboard.php">Înapoi la comenzi</a></div>
    </header>
    <div class="container">
        <div class="sidebar">
            <h2>Adaugă Comandă</h2>
            <form method="post" action="unpaid_orders.php">
                <input type="hidden" name="add_unpaid_order" value="1">
                <div class="form-group">
                    <label for="client_id"><strong>Client:</strong></label>
                    <select id="client_id" name="client_id">
                        <option value="">Selectați clientul</option>
                        <?php foreach ($clients as $client): ?>
                            <option value="<?php echo $client['client_id']; ?>"><?php echo $client['client_name']; ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label for="order_details"><strong>Detalii Comandă:</strong></label>
                    <textarea id="order_details" name="order_details" rows="4" cols="50" required></textarea>
                </div>

                <div class="form-group">
                    <input class="button" type="submit" value="Adaugă Comandă" style="font-family: Poppins, sans-serif;">
                </div>
            </form>
        </div>
        <div class="main-content" style="height: 100vh;">
            <h2>Comenzi</h2>
            <table>
                <thead>
                    <tr>
                        <th>Nr. Comanda</th>
                        <th>Client</th>
                        <th>Detalii Comandă</th>
                        <th>Creată Pe</th>
                        <th>Creată De</th>
                        <th>Șterge</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($unpaid_orders as $order): ?>
                        <tr>
                            <td><?php echo str_pad($order["order_id"], 3, '0', STR_PAD_LEFT); ?></td>
                            <td><?php echo $order["client_name"]; ?></td>
                            <td><?php echo $order["order_details"]; ?></td>
                            <td><?php echo date('d.m.Y H:i', strtotime($order["order_date"])); ?></td>
                            <td><?php echo $order["creator_name"]; ?></td>
                            <td><a href="#" onclick="deleteOrder(<?php echo $order['order_id']; ?>); return false;">Șterge</a></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
    <footer>
        <p>© Color Print</p>
    </footer>
</body>

</html>