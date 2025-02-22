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

// Function to validate remember token
function validateRememberToken($conn, $remember_token)
{
    $token_sql = "SELECT u.user_id, u.username 
                  FROM users u
                  INNER JOIN remember_tokens t ON u.user_id = t.user_id
                  WHERE t.token = ?";
    $stmt = $conn->prepare($token_sql);
    if ($stmt) {
        $stmt->bind_param("s", $remember_token);
        $stmt->execute();
        $result = $stmt->get_result();

        if ($result->num_rows > 0) {
            return $result->fetch_assoc();
        }
        $stmt->close();
    }
    return false;
}

// Check if the user is already logged in via session
if (!isset($_SESSION['username'])) {
    // Check if there is a "remember_token" cookie
    if (isset($_COOKIE['remember_token'])) {
        $remember_token = $_COOKIE['remember_token'];
        $user = validateRememberToken($conn, $remember_token);

        if ($user) {
            $_SESSION['user_id'] = $user['user_id'];
            $_SESSION['username'] = $user['username'];
        } else {
            // Invalid token, clear the cookie
            setcookie("remember_token", "", time() - 3600, "/", "", true, true);
        }
    }

    // If neither session nor cookie is valid, redirect to login
    if (!isset($_SESSION['username'])) {
        header("Location: login.php");
        exit();
    }
}

// Fetch filter values
$status_filter = $_GET['status_filter'] ?? '';
$assigned_filter = $_GET['assigned_filter'] ?? '';
$category_filter = $_GET['category_filter'] ?? '';
$sort_order = $_GET['sort_order'] ?? 'ASC';
$page = $_GET['page'] ?? 1;
$limit = 20; // Number of orders per page
$offset = ($page - 1) * $limit;

// Fetch orders with filters and sorting
$order_sql = "SELECT o.order_id, o.*, c.client_name, u.username as assigned_user, cat.category_name, o.delivery_date FROM orders o 
              JOIN clients c ON o.client_id = c.client_id 
              LEFT JOIN users u ON o.assigned_to = u.user_id 
              LEFT JOIN categories cat ON o.category_id = cat.category_id 
              WHERE 1=1";

$total_params = [];
$total_types = '';
$params = [];
$types = '';

// Exclude orders with status 'delivered' and 'cancelled' by default
if ($status_filter !== 'delivered' && $status_filter !== 'cancelled') {
    $order_sql .= " AND o.status NOT IN ('delivered', 'cancelled') ";
}

if ($status_filter) {
    $order_sql .= " AND o.status = ?";
    $params[] = $status_filter;
    $types .= 's';
}
if ($assigned_filter) {
    $order_sql .= " AND o.assigned_to = ?";
    $params[] = $assigned_filter;
    $types .= 'i';
}
if ($category_filter) {
    $order_sql .= " AND o.category_id = ?";
    $params[] = $category_filter;
    $types .= 'i';
}

$order_sql .= " ORDER BY o.order_id $sort_order LIMIT ? OFFSET ?";
$params[] = $limit;
$params[] = $offset;
$types .= 'ii';

$stmt = $conn->prepare($order_sql);
$stmt->bind_param($types, ...$params);
$stmt->execute();
$orders_result = $stmt->get_result();

// Fetch total number of orders for pagination
$total_orders_sql = "SELECT COUNT(*) as total FROM orders WHERE 1=1";

// Exclude orders with status 'delivered' and 'cancelled' by default in the total count
if ($status_filter !== 'delivered' && $status_filter !== 'cancelled') {
    $total_orders_sql .= " AND status NOT IN ('delivered', 'cancelled')";
}

if ($status_filter) {
    $total_orders_sql .= " AND status = ?";
    $total_params[] = $status_filter;
    $total_types .= 's';
}
if ($assigned_filter) {
    $total_orders_sql .= " AND assigned_to = ?";
    $total_params[] = $assigned_filter;
    $total_types .= 'i';
}
if ($category_filter) {
    $total_orders_sql .= " AND category_id = ?";
    $total_params[] = $category_filter;
    $total_types .= 'i';
}

$total_stmt = $conn->prepare($total_orders_sql);
if (!empty($total_types)) {
    $total_stmt->bind_param($total_types, ...$total_params);
}

$total_stmt->execute();
$total_stmt->execute();
$total_result = $total_stmt->get_result();
$total_orders = $total_result->fetch_assoc()['total'];
$total_stmt->close();

$total_pages = ceil($total_orders / $limit);

// Handle form submission for adding an order
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_order'])) {
    $client_id = $_POST['client_id'];
    $order_details = $_POST['order_details'];
    $due_date = $_POST['due_date'];
    $due_time = $_POST['due_time'];
    $category_id = $_POST['category_id'];
    $avans = $_POST['avans'];
    $total = $_POST['total'];
    $assigned_to = $_POST['assigned_to'];

    // Check if client exists or create a new client
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_order'])) {
        $client_id = $_POST['client_id'];
        $client_name = $_POST['client_name'];
        $client_email = $_POST['client_email'];
        $client_phone = $_POST['client_phone'];
        $order_details = $_POST['order_details'];
        $due_date = $_POST['due_date'];
        $due_time = $_POST['due_time'];
        $category_id = $_POST['category_id'];
        $avans = $_POST['avans'];
        $total = $_POST['total'];
        $assigned_to = $_POST['assigned_to'];

        // Debugging: Check if client ID is set
        if (empty($client_id)) {
            echo "Client ID is empty. Creating a new client.<br>";
        } else {
            echo "Client ID is set: $client_id<br>";
        }

        // Check if client exists or create a new client
        if (empty($client_id)) {
            $insert_client_sql = "INSERT INTO clients (client_name, client_email, client_phone) VALUES (?, ?, ?)";
            $stmt = $conn->prepare($insert_client_sql);
            $stmt->bind_param("sss", $client_name, $client_email, $client_phone);

            if ($stmt->execute()) {
                $client_id = $stmt->insert_id;
                echo "New client created with ID: $client_id<br>";
            } else {
                echo "Error creating new client: " . $stmt->error;
                exit();
            }
            $stmt->close();
        } else {
            $client_check_sql = "SELECT client_id FROM clients WHERE client_id = ?";
            $stmt = $conn->prepare($client_check_sql);
            $stmt->bind_param("i", $client_id);
            $stmt->execute();
            $client_check_result = $stmt->get_result();

            if ($client_check_result->num_rows == 0) {
                echo "Error: Client does not exist.";
                exit();
            }
            $stmt->close();
        }

        // Insert new order
        $order_sql = "INSERT INTO orders (client_id, order_details, due_date, due_time, category_id, avans, total, assigned_to) VALUES (?, ?, ?, ?, ?, ?, ?, ?)";
        $stmt = $conn->prepare($order_sql);
        $stmt->bind_param("issssdii", $client_id, $order_details, $due_date, $due_time, $category_id, $avans, $total, $assigned_to);
        if ($stmt->execute()) {
            $last_order_id = $stmt->insert_id; // Get the last inserted order ID
            echo "Comanda a fost adăugată cu succes! 🚀 🚀 🚀 ";
            echo "<script>document.getElementById('orderForm').reset();</script>";
            echo "<script>window.location.href='view_order.php?order_id=" . $last_order_id . "';</script>";
            exit();
        } else {
            echo "Error adding new order: " . $stmt->error;
        }

        $stmt->close();
    }
}

// Fetch all users for the "assigned to" dropdown
$users_sql = "SELECT user_id, username FROM users WHERE role = 'operator'";
$users_result = $conn->query($users_sql);
$users = [];
if ($users_result->num_rows > 0) {
    while ($row = $users_result->fetch_assoc()) {
        $users[] = $row;
    }
}

// Fetch categories
$categories_sql = "SELECT * FROM categories";
$categories_result = $conn->query($categories_sql);

// Store categories in an array for JavaScript
$categories = [];
if ($categories_result->num_rows > 0) {
    while ($row = $categories_result->fetch_assoc()) {
        $categories[] = $row;
    }
}

// PHP functions for formatting dates
function formatDateWithoutYearWithDay($dateString)
{
    $date = new DateTime($dateString);
    $day = $date->format('d');
    $month = $date->format('m');
    $year = $date->format('Y');
    $daysOfWeek = ['Duminică', 'Luni', 'Marți', 'Miercuri', 'Joi', 'Vineri', 'Sâmbătă'];
    $dayOfWeek = $daysOfWeek[$date->format('w')];
    return $dayOfWeek . ', ' . str_pad($day, 2, '0', STR_PAD_LEFT) . '.' . str_pad($month, 2, '0', STR_PAD_LEFT) . '.' . $year;
}


// updated to show the delivery date if the order is marked as delivered in data livrare
function formatRemainingDays($dueDate, $status, $deliveryDate = null)
{
    if ($status === 'delivered' && $deliveryDate) {
        $deliveryDateObj = new DateTime($deliveryDate);
        return formatDateWithoutYearWithDay($deliveryDateObj->format('Y-m-d'));
    }

    $currentDate = new DateTime();
    $dueDateObj = new DateTime($dueDate);
    $interval = $currentDate->diff($dueDateObj);
    $daysDiff = (int)$interval->format('%r%a');

    if ($daysDiff === 0) {
        return "Astăzi";
    } elseif ($daysDiff > 0) {
        return "$daysDiff zile rămase";
    } else {
        return "Termen depășit cu " . abs($daysDiff) . " zile";
    }
}
?>

<!DOCTYPE html>
<html>

<head>
    <title>Dashboard Utilizator</title>
    <link rel="stylesheet" type="text/css" href="styles.css">
    <link rel="stylesheet" type="text/css" href="style.css">
    <link rel="icon" type="image/png" href="https://color-print.ro/magazincp/favicon.png" />
    <link rel="stylesheet" href="https://code.jquery.com/ui/1.12.1/themes/base/jquery-ui.css">
    <link href="https://unpkg.com/aos@2.3.1/dist/aos.css" rel="stylesheet">
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="https://code.jquery.com/ui/1.12.1/jquery-ui.js"></script>
    <script src="https://unpkg.com/aos@2.3.1/dist/aos.js"></script>
    <!-- Include Select2 CSS -->
    <link href="https://cdnjs.cloudflare.com/ajax/libs/select2/4.0.13/css/select2.min.css" rel="stylesheet" />
    <!-- Include jQuery -->
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <!-- Include Select2 JavaScript -->
    <script src="https://cdnjs.cloudflare.com/ajax/libs/select2/4.0.13/js/select2.min.js"></script>
    <!-- Initialize Select2 lybrary -->
    <script>
        document.addEventListener('DOMContentLoaded', function() {
            // Initialize Select2 on select elements
            $('#status_filter, #assigned_filter, #category_filter, #sort_order, #assigned_to, #category_id').select2({
                dropdownAutoWidth: true,
                width: 'auto'
            });

            $('#client_id').select2({
                dropdownAutoWidth: true,
                width: 'auto',
                placeholder: 'Nume client',
                allowClear: true,
                ajax: {
                    url: 'fetch_clients.php', // Update the URL to point to fetch_clients.php
                    dataType: 'json',
                    delay: 250,
                    data: function(params) {
                        return {
                            search_clients: 1,
                            q: params.term // search term
                        };
                    },
                    processResults: function(data) {
                        return {
                            results: data
                        };
                    },
                    cache: true
                },
                templateResult: formatClient, // custom formatting function for results
                templateSelection: formatClientSelection // custom formatting function for selected item
            });

            // Custom formatting function for results
            function formatClient(client) {
                if (!client.id) {
                    return client.text;
                }

                var $client = $(
                    '<div class="select2-result-client">' +
                    '<span style="font-weight: bold;">' + client.client_name + '</span>' +
                    '<div style="font-style: normal;">' + client.client_phone + '</div>' +
                    '</div>'
                );

                return $client;
            }

            // Custom formatting function for selected item
            function formatClientSelection(client) {
                if (!client.id) {
                    return client.text;
                }

                return client.client_name;
            }

            // Function to toggle visibility of new client fields based on client selection
            function toggleClientFieldsVisibility() {
                var clientId = $('#client_id').val();
                if (clientId) {
                    $('#new_client_fields').hide();
                    $('#edit_client_button').show();
                } else {
                    $('#new_client_fields').show();
                    $('#edit_client_button').hide();
                }
            }

            // Listen for changes on the client_id select element
            $('#client_id').on('change', toggleClientFieldsVisibility);

            // Initial check to set the visibility based on the current selection
            toggleClientFieldsVisibility();

            // Function to open the edit modal
            function openEditModal(clientId) {
                $('#editClientModal').css('display', 'block');
                // Fetch client details and populate the form
                fetch('get_client.php?client_id=' + clientId)
                    .then(response => response.json())
                    .then(data => {
                        $('#edit_client_id').val(data.client_id);
                        $('#edit_client_name').val(data.client_name);
                        $('#edit_client_phone').val(data.client_phone);
                        $('#edit_client_email').val(data.client_email);
                    })
                    .catch(error => console.error('Error:', error));
            }

            // Close the modal when the user clicks on <span> (x)
            $('.close').on('click', function() {
                $('#editClientModal').css('display', 'none');
            });

            // Close the modal when the user clicks anywhere outside of the modal
            window.onclick = function(event) {
                if (event.target.id === 'editClientModal') {
                    $('#editClientModal').css('display', 'none');
                }
            };

            // Handle edit form submission
            $('#editClientForm').on('submit', function(event) {
                event.preventDefault();
                var formData = new FormData(this);
                fetch('update_client.php', {
                        method: 'POST',
                        body: formData
                    })
                    .then(response => response.text())
                    .then(data => {
                        alert('Client actualizat cu succes! 👍');
                        $('#editClientModal').css('display', 'none');
                        // Refresh the client dropdown
                        $('#client_id').trigger('change');
                    })
                    .catch(error => console.error('Error:', error));
            });

            // Add event listener for the edit button
            $('#edit_client_button').on('click', function() {
                var clientId = $('#client_id').val();
                if (clientId) {
                    openEditModal(clientId);
                }
            });
        });
    </script>

    <!-- Function for background animation -->
    <script>
        // Function for background animation with cubes
        document.addEventListener("DOMContentLoaded", function() {
            const numCubes = 20; // Number of cubes to generate
            const colors = ["gray", "yellow"]; // Array with two colors
            const backgroundContainer = document.createElement("div");
            backgroundContainer.classList.add("background-container");
            document.body.prepend(backgroundContainer);

            function createCube() {
                let cube = document.createElement("div");
                cube.classList.add("cube");
                let size = Math.random() * 10 + 5; // Random size between 5px and 15px
                cube.style.width = `${size}px`;
                cube.style.height = `${size}px`;

                // Randomly assign a color (grey or yellow)
                cube.style.backgroundColor = colors[Math.floor(Math.random() * colors.length)];

                cube.style.animationDuration = `${Math.random() * 8 + 8}s`; // Random duration (8 to 16 seconds)
                cube.style.animationDelay = `${Math.random() * 4}s`; // Random delay (up to 4 seconds)
                backgroundContainer.appendChild(cube);

                function setRandomPosition() {
                    cube.style.left = `${Math.random() * 100}vw`; // Random horizontal position
                    cube.style.top = `${Math.random() * 100}vh`; // Random vertical position
                }

                setRandomPosition(); // Set initial position
                cube.addEventListener("animationiteration", setRandomPosition); // Change position on each loop
            }

            for (let i = 0; i < numCubes; i++) {
                createCube();
            }
        });
    </script>

    <!-- Script to toggle between V1 and V2 -->
    <script>
        function toggleVersion() {
            const currentUrl = window.location.href;
            const isV2 = /dashboardv2\.php/.test(currentUrl);

            if (isV2) {
                window.location.href = currentUrl.replace('dashboardv2.php', 'dashboard.php');
            } else {
                window.location.href = currentUrl.replace('dashboard.php', 'dashboardv2.php');
            }
        }
    </script>

    <!-- Script for adding new order -->
    <script>
        document.addEventListener('DOMContentLoaded', function() {
            document.getElementById('orderForm').addEventListener('submit', function(event) {
                event.preventDefault(); // Prevent the default form submission

                fetch('dashboardv2.php', {
                        method: 'POST',
                        body: new FormData(this)
                    })
                    .then(response => response.text())
                    .then(data => {
                        if (data.includes('Comanda a fost adăugată cu succes! 🚀 🚀 🚀 ')) {
                            alert('Comanda a fost adăugată cu succes! 🚀 🚀 🚀 ');
                            this.reset(); // Reset the form after successful submission
                            // Assuming you want to navigate to view_order.php after reset
                            const orderId = data.match(/order_id=(\d+)/)[1];
                            window.location.href = 'view_order.php?order_id=' + orderId;
                        } else {
                            alert('Error adding new order: ' + data);
                        }
                    })
                    .catch(error => {
                        console.error('Error:', error);
                        alert('An error occurred while processing your request.');
                    });
            });
        });
    </script>

    <!-- AOS CSS init -->
    <script>
        document.addEventListener('DOMContentLoaded', function() {
            // Init AOS
            AOS.init({
                duration: 600, // Adjust animation duration here
                mirror: false // Start animation on scroll up as well
            });
        });
    </script>

    <!-- Custom CSS for Select2 golden theme -->
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
    <header id="header" data-aos="slide-down">
        <script>
            document.addEventListener('DOMContentLoaded', function() {
                var currentDate = new Date();
                var options = {
                    weekday: 'long',
                    year: 'numeric',
                    month: 'long',
                    day: 'numeric'
                };
                var formattedDate = currentDate.toLocaleDateString('ro-RO', options);
                document.getElementById('currentdate').textContent = formattedDate;

                // Determinarea mesajului de întâmpinare
                var currentHour = currentDate.getHours();
                var greetingMessage = "";

                if (currentHour < 12) {
                    greetingMessage = "Bună dimineața ☕";
                } else if (currentHour >= 12 && currentHour < 14) {
                    greetingMessage = "Poftă bună 🍕";
                } else {
                    greetingMessage = "Bună ziua ⚡";
                }

                // Actualizarea doar a mesajului de întâmpinare
                document.getElementById('greeting-message').textContent = greetingMessage;
            });
        </script>
        <p>
            <span id="greeting-message"></span>, <?php echo ucwords($_SESSION['username']); ?>! Astăzi este <span id="currentdate"></span>.
        </p>
        <div class="button"><a href="logout.php">Deconectare</a> </div>
    </header>

    <div class="image-container" style="width: 100%; height: 300px; position: relative;">
        <img src="https://color-print.ro/magazincp/header.webp"
            alt="Main Image"
            style="width: 100%; height: 100%; object-fit: cover; display: block; position: relative; z-index: 1;">
        <div class="image-overlay"></div>
        <object data-aos="fade-left"
            data-aos-anchor="#example-anchor"
            data-aos-offset="500"
            data-aos-duration="1000" type="image/svg+xml" data="https://color-print.ro/magazincp/comenzi.svg"
            style="width: 50%; height: 50%; position: absolute; top: 25%; left: 25%; z-index: 2; object-fit: contain;">
        </object>
    </div>


    <div class="container">
        <div class="sidebar" data-aos="slide-right">
            <h2>Adaugă Comandă</h2>
            <form id="orderForm" method="post" action="dashboardv2.php" autocomplete="off">
                <input type="hidden" name="add_order" value="1">
                <div class="form-group">

                    <label for="client_id"><strong>Caută client:</strong></label>
                    <select id="client_id" name="client_id" style="width: 70%; margin-right: 10px;">
                        <option value="">Caută</option>
                    </select>
                    <div id="edit_client_button" class="button" style="display:none; margin-top:10px;">
                        <button type="button">Editează client</button>

                    </div>
                </div>
                <div id="new_client_fields" class="form-group">
                    <div class="flex-container">
                        <div class="form-group">
                            <label for="client_name"><strong>Nume Client:</strong></label>
                            <input type="text" id="client_name" name="client_name">
                        </div>
                        <div class="form-group">
                            <label for="client_phone"><strong>Telefon Client:</strong></label>
                            <input type="text" id="client_phone" name="client_phone" pattern="0[0-9]{9}" title="Numărul de telefon trebuie să conțină exact 10 cifre și să înceapă cu 0">
                        </div>
                        <div class="form-group">
                            <label for="client_email">Email Client:</label>
                            <input type="email" id="client_email" name="client_email">
                        </div>
                    </div>
                    <button type="button" id="save_edit_button" style="display:none;">Salvează Modificările</button>
                </div>

                <div class="form-group">
                    <label for="order_details"><strong>Info Comandă:</strong></label>
                    <textarea id="order_details" name="order_details" rows="4" cols="50"></textarea>
                </div>

                <div class="form-group">
                    <label for="avans">Avans:</label>
                    <input type="number" id="avans" name="avans" max="9999" step="0.01">
                </div>

                <div class="form-group">
                    <label for="total">Total:</label>
                    <input type="number" id="total" name="total" max="9999" step="0.01">
                </div>

                <div class="form-group">
                    <label for="due_date">Data Livrare:</label>
                    <input type="date" id="due_date" name="due_date" required>
                </div>

                <div class="form-group">
                    <label for="due_time">Ora Livrare:</label>
                    <input type="time" id="due_time" name="due_time">
                </div>

                <div class="form-group">
                    <label for="category_id">Categorie:</label>
                    <select id="category_id" name="category_id">
                        <?php
                        foreach ($categories as $category) {
                            echo "<option value='" . $category["category_id"] . "'>" . $category["category_name"] . "</option>";
                        }
                        ?>
                    </select>
                </div>

                <div class="form-group">
                    <label for="assigned_to">Atribuie comanda lui:</label>
                    <select id="assigned_to" name="assigned_to">
                        <?php
                        foreach ($users as $user) {
                            echo "<option value='" . $user["user_id"] . "'>" . $user["username"] . "</option>";
                        }
                        ?>
                    </select>
                </div>
                <div class="form-group">
                    <input class="button" type="submit" value="Adaugă Comandă" style="font-family: Poppins, sans-serif;">
                </div>
            </form>
        </div>

        <!-- Add this modal HTML in your main HTML file -->
        <div id="editClientModal" class="modal" style="display: none;">
            <div class="modal-content">
                <span class="close">&times;</span>
                <h2>Editează detalii</h2>
                <form id="editClientForm">
                    <input type="hidden" id="edit_client_id" name="edit_client_id">
                    <div class="form-group">
                        <label for="edit_client_name">Nume Client:</label>
                        <input type="text" id="edit_client_name" name="edit_client_name">
                    </div>
                    <div class="form-group">
                        <label for="edit_client_phone">Telefon Client:</label>
                        <input type="text" id="edit_client_phone" name="edit_client_phone" pattern="0[0-9]{9}" title="Numărul de telefon trebuie să conțină exact 10 cifre și să înceapă cu 0">
                    </div>
                    <div class="form-group">
                        <label for="edit_client_email">Email Client:</label>
                        <input type="email" id="edit_client_email" name="edit_client_email">
                    </div>
                    <div class="form-group button">
                        <input type="submit" value="Salvează Modificări">
                    </div>
                </form>
            </div>
        </div>

        <div class="main-content">
            <div class="filters">
                <form method="GET" action="dashboardv2.php">
                    <div class="filter-group">
                        <label>Status:</label>
                        <select id="status_filter" name="status_filter">
                            <option value="">Toate</option>
                            <option value="assigned" <?php if ($status_filter == 'assigned') echo 'selected'; ?>>Atribuit</option>
                            <option value="completed" <?php if ($status_filter == 'completed') echo 'selected'; ?>>Terminat</option>
                            <option value="delivered" <?php if ($status_filter == 'delivered') echo 'selected'; ?>>Livrat</option>
                            <option value="cancelled" <?php if ($status_filter == 'cancelled') echo 'selected'; ?>>Anulat</option>
                        </select>
                    </div>

                    <div class="filter-group">
                        <label>Operator:</label>
                        <select id="assigned_filter" name="assigned_filter">
                            <option value="">Toți</option>
                            <?php
                            $users_sql = "SELECT user_id, username FROM users";
                            $users_result = $conn->query($users_sql);
                            while ($user = $users_result->fetch_assoc()) {
                                $selected = ($assigned_filter == $user['user_id']) ? 'selected' : '';
                                echo "<option value='" . $user['user_id'] . "' $selected>" . $user['username'] . "</option>";
                            }
                            ?>
                        </select>
                    </div>

                    <div class="filter-group">
                        <label>Categorie:</label>
                        <select id="category_filter" name="category_filter">
                            <option value="">Toate</option>
                            <?php
                            $categories_sql = "SELECT category_id, category_name FROM categories";
                            $categories_result = $conn->query($categories_sql);
                            while ($category = $categories_result->fetch_assoc()) {
                                $selected = ($category_filter == $category['category_id']) ? 'selected' : '';
                                echo "<option value='" . $category['category_id'] . "' $selected>" . $category['category_name'] . "</option>";
                            }
                            ?>
                        </select>
                    </div>

                    <div class="filter-group">
                        <label>Ordine:</label>
                        <select id="sort_order" name="sort_order">
                            <option value="ASC" <?php if ($sort_order == 'ASC') echo 'selected'; ?>>Ascendent</option>
                            <option value="DESC" <?php if ($sort_order == 'DESC') echo 'selected'; ?>>Descendent</option>
                        </select>
                    </div>

                    <div class="filter-group">
                        <button type="submit">Aplică filtre</button>
                        <button type="button" onclick="window.location.href='dashboardv2.php'">Resetează filtre</button>
                    </div>
                </form>
            </div>
            <div class="order-grid" data-aos="slide-up">
                <?php
                if ($orders_result->num_rows > 0) {
                    while ($row = $orders_result->fetch_assoc()) {
                        $order_id = str_pad($row["order_id"], 3, '0', STR_PAD_LEFT);
                        $order_date = formatDateWithoutYearWithDay($row["order_date"]) . ' ' . date('H:i', strtotime($row["order_time"]));
                        $due_date = formatRemainingDays($row["due_date"], $row["status"], $row["delivery_date"] ?? null);
                        $status = $row["status"] ?? 'neatribuită';

                        $row_classes = [];

                        if ($status == 'assigned') {
                            $row_classes[] = 'assigned';
                        } elseif (
                            $status == 'completed'
                        ) {
                            $row_classes[] = 'completed';
                        } elseif (
                            $status == 'delivered'
                        ) {
                            $row_classes[] = 'delivered';
                        } elseif (
                            $status == 'cancelled'
                        ) {
                            $row_classes[] = 'cancelled';
                        }

                        if ($row["assigned_to"] == $_SESSION['user_id'] && $status != 'completed' && $status != 'delivered' && $status != 'cancelled') {
                            $row_classes[] = 'current-user';
                        }

                        $row_class = implode(' ', $row_classes);

                        $operator_name = $row['assigned_user'] ?? '';
                ?>

                        <div class="order-card <?php echo $row_class; ?>" onclick="window.location.href='view_order.php?order_id=<?php echo $row["order_id"]; ?>'">
                            <?php
                            // Define an array to translate status values to the desired labels
                            $status_translations = [
                                "assigned" => "Atribuită",
                                "completed" => "Terminată",
                                "delivered" => "Livrată",
                                "cancelled" => "Anulată"
                            ];

                            // Fetch the translated status using isset() for safe array access
                            $translated_status = isset($status_translations[$status]) ? $status_translations[$status] : ucfirst($status);
                            ?>
                            <div>
                                <h3>Comanda <?php echo $order_id; ?></h3>
                                <p><strong>Client:</strong> <?php echo $row["client_name"]; ?></p>
                                <p><strong>Detalii:</strong> <?php echo $row["order_details"]; ?></p>
                                <div class="order-date">
                                    <p><strong>Din:</strong> <?php echo $order_date; ?></p>
                                    <p><strong>Livrare:</strong> <?php echo $due_date; ?></p>
                                </div>
                                <div class="order-status">
                                    <p>Stare: <?php echo $translated_status; ?></p>
                                    <?php if (!empty($operator_name)): ?>
                                        <p>Operator: <?php echo ucwords($operator_name); ?></p>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>

                <?php
                    }
                } else {
                    echo "<div class='order-card'><p>Nu există comenzi.</p></div>";
                }
                ?>
            </div>
            <div class="pagination">
                <?php
                // Ensure all variables are set and have valid values
                $total_pages = isset($total_pages) ? (int)$total_pages : 1;
                $page = isset($page) ? (int)$page : 1;
                $status_filter = isset($status_filter) ? urlencode($status_filter) : '';
                $assigned_filter = isset($assigned_filter) ? urlencode($assigned_filter) : '';
                $category_filter = isset($category_filter) ? urlencode($category_filter) : '';
                $sort_order = isset($sort_order) ? urlencode($sort_order) : '';

                // First page link
                if ($total_pages > 5 && $page > 1) {
                    echo "<a href='dashboardv2.php?page=1&status_filter=$status_filter&assigned_filter=$assigned_filter&category_filter=$category_filter&sort_order=$sort_order'>Prima</a>";
                }

                // Previous page link
                if ($total_pages > 5 && $page > 1) {
                    echo "<a href='dashboardv2.php?page=" . ($page - 1) . "&status_filter=$status_filter&assigned_filter=$assigned_filter&category_filter=$category_filter&sort_order=$sort_order'>Înapoi</a>";
                }

                // Define the number of pages to show before and after the current page
                $window_size = 2; // This means 2 pages before and 2 pages after the current page

                // Calculate the start and end page numbers
                $start = 1;
                $end = $total_pages;

                if ($total_pages > 5) {
                    $start = max(1, $page - $window_size);
                    $end = min($total_pages, $page + $window_size);

                    // Ensure there's always a minimum of 5 pages shown if possible
                    if ($end - $start + 1 < 5) {
                        if ($start == 1) {
                            $end = min($total_pages, $start + 4);
                        } else {
                            $start = max(1, $end - 4);
                        }
                    }
                }

                // Display page numbers within the window
                for ($i = $start; $i <= $end; $i++) {
                    $active = ($i == $page) ? 'active' : '';
                    echo "<a href='dashboardv2.php?page=$i&status_filter=$status_filter&assigned_filter=$assigned_filter&category_filter=$category_filter&sort_order=$sort_order' class='$active'>$i</a>";
                }

                // Next page link
                if ($total_pages > 5 && $page < $total_pages) {
                    echo "<a href='dashboardv2.php?page=" . ($page + 1) . "&status_filter=$status_filter&assigned_filter=$assigned_filter&category_filter=$category_filter&sort_order=$sort_order'>Înainte</a>";
                }

                // Last page link
                if ($total_pages > 5 && $page < $total_pages) {
                    echo "<a href='dashboardv2.php?page=$total_pages&status_filter=$status_filter&assigned_filter=$assigned_filter&category_filter=$category_filter&sort_order=$sort_order'>Ultima</a>";
                }
                ?>
            </div>
        </div>
    </div>
    <footer>
        <p>© Color Print</p>
        <a href="archive.php" style="text-decoration: none; color: white;">Arhivă</a>
        <a href="unpaid_orders.php" style="text-decoration: none; color: white;">Comenzi nefacturate</a>
        <div id="versionToggle" style="position: fixed; bottom: 20px; right: 30px; background: #333; padding: 10px; border-radius: 5px; cursor: pointer;">
            <button onclick="toggleVersion()">Schimbă la <?php echo (basename($_SERVER['PHP_SELF']) === 'dashboardv2.php') ? 'V1' : 'V2'; ?></button>
        </div>
    </footer>

</body>


</html>