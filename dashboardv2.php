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

$pinned_sql = "SELECT o.order_id, o.due_date, o.assigned_to, u.username AS operator, c.client_name
               FROM orders o
               LEFT JOIN users u ON o.assigned_to = u.user_id
               JOIN clients c ON o.client_id = c.client_id
               WHERE o.is_pinned = 1
               ORDER BY o.due_date ASC
               LIMIT 10";

$pinned_result = $conn->query($pinned_sql);

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
    $created_by = $_SESSION['user_id'];

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
        $avans = (float)($_POST['avans'] ?? 0);
        $total = (float)($_POST['total'] ?? 0);
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
        $created_by = $_SESSION['user_id'];
        $assigned_to = $_POST['assigned_to'];

        // Update your order SQL query
        $order_sql = "INSERT INTO orders 
              (client_id, order_details, due_date, due_time, category_id, avans, total, assigned_to, created_by) 
              VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)";
        $stmt = $conn->prepare($order_sql);
        $stmt->bind_param("issssddii", $client_id, $order_details, $due_date, $due_time, $category_id, $avans, $total, $assigned_to, $created_by);
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
    // Set the time zone to Romania's time zone
    date_default_timezone_set('Europe/Bucharest');

    if ($status === 'delivered' && $deliveryDate) {
        $deliveryDateObj = new DateTime($deliveryDate);
        return formatDateWithoutYearWithDay($deliveryDateObj->format('Y-m-d'));
    }

    $currentDate = new DateTime();
    $dueDateObj = DateTime::createFromFormat('Y-m-d', $dueDate);

    // Calculate the difference between dates (only considering the date part, not time)
    $currentDay = $currentDate->format('Y-m-d');
    $dueDay = $dueDateObj->format('Y-m-d');

    $currentDayTimestamp = strtotime($currentDay);
    $dueDayTimestamp = strtotime($dueDay);

    // Cast to integer to remove decimal precision
    $daysDiff = intval(($dueDayTimestamp - $currentDayTimestamp) / 86400);

    // Get the time difference
    $timeDiff = $currentDate->diff($dueDateObj)->format('%H:%I');

    if ($daysDiff === 0) {
        return "Astăzi";
    } elseif ($daysDiff === 1) {
        return "Mâine";
    } elseif ($daysDiff > 1) {
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
    <!-- Include Font Awesome -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <!-- Include AOS CSS -->
    <link href="https://unpkg.com/aos@2.3.1/dist/aos.css" rel="stylesheet">
    <script src="https://unpkg.com/aos@2.3.1/dist/aos.js"></script>
    <!-- Include Select2 CSS -->
    <link href="https://cdnjs.cloudflare.com/ajax/libs/select2/4.0.13/css/select2.min.css" rel="stylesheet" />
    <!-- Include jQuery -->
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="https://code.jquery.com/ui/1.12.1/jquery-ui.js"></script>
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

    <!-- Date picker -->
    <script>
        document.addEventListener('DOMContentLoaded', function() {
            const select = document.getElementById('datePickerSelect');
            const today = new Date();

            const daysToGenerate = 90; // only 90 days ahead

            let daysAdded = 0;
            let i = 0;

            while (daysAdded < daysToGenerate) {
                const date = new Date();
                date.setDate(today.getDate() + i);

                // Skip Sundays (getDay() === 0 means Sunday)
                if (date.getDay() !== 0) {
                    const year = date.getFullYear();
                    const month = String(date.getMonth() + 1).padStart(2, '0');
                    const day = String(date.getDate()).padStart(2, '0');

                    const label = date.toLocaleDateString('ro-RO', {
                        weekday: 'short',
                        year: 'numeric',
                        month: 'short',
                        day: 'numeric'
                    });

                    const option = new Option(label, `${year}-${month}-${day}`);

                    if (daysAdded === 0) {
                        option.selected = true;
                    }

                    select.add(option);
                    daysAdded++;
                }

                i++;
            }

            // Optional: Select2 styling
            if (typeof $ !== 'undefined' && $.fn.select2) {
                $(select).select2({
                    placeholder: "Alege o dată",
                    dropdownAutoWidth: true,
                    width: 'auto'
                });
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
                duration: 800, // Adjust animation duration here
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
    <canvas id="autumnLeaves"></canvas>
    <header id="header">
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
        <p data-aos="fade-down"
            data-aos-easing="linear"
            data-aos-duration="800">
            <span id="greeting-message"></span>, <?php echo ucwords($_SESSION['username']); ?>! Astăzi este <span id="currentdate"></span>.
        </p>
        <button data-aos="fade-down"
            data-aos-easing="linear"
            data-aos-duration="800" onclick="window.location.href='logout.php'">
            <i class="fa-solid fa-right-from-bracket"></i> Deconectare
        </button>
    </header>

    <div class="image-container" style="width: 100%; height: 300px; position: relative;">
        <img src="https://color-print.ro/magazincp/header.webp"
            alt="Main Image"
            style="width: 100%; height: 100%; object-fit: cover; display: block; position: relative; z-index: 1;">
        <div class="image-overlay"></div>
        <object data-aos="zoom-in"
            data-aos-easing="linear"
            data-aos-duration="800"
            type="image/svg+xml" data="https://color-print.ro/magazincp/comenzi.svg"
            style="width: 50%; height: 50%; position: absolute; top: 25%; left: 25%; z-index: 2; object-fit: contain;">
        </object>
    </div>

    <div class="pinned-section" data-aos="fade-in">
        <?php if ($pinned_result && $pinned_result->num_rows > 0): ?>
            <h2 style="margin-left:20px;">📌 Comenzi urgente</h2>
            <div class="pinned-feed">
                <?php while ($pin = $pinned_result->fetch_assoc()): ?>
                    <a href="view_order.php?order_id=<?= $pin['order_id']; ?>">
                        <div class="card pinned-card">
                            <div class="card-header">
                                Comanda #<?= $pin['order_id']; ?>
                            </div>
                            <div class="card-body">
                                <p><strong>Operator:</strong> <?= htmlspecialchars($pin['operator']); ?></p>
                                <p><strong>Client:</strong> <?= htmlspecialchars($pin['client_name']); ?></p>
                            </div>
                        </div>
                    </a>
                <?php endwhile; ?>
            </div>
        <?php endif; ?>
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
                            <input placeholder="Nume complet" type="text" id="client_name" name="client_name">
                        </div>
                        <div class="form-group">
                            <label for="client_phone"><strong>Telefon Client:</strong></label>
                            <input placeholder="07XXXXXXXX" type="text" id="client_phone" name="client_phone" pattern="0[0-9]{9}" title="Numărul de telefon trebuie să conțină exact 10 cifre și să înceapă cu 0">
                        </div>
                        <div class="form-group">
                            <label for="client_email">Email Client:</label>
                            <input placeholder="colorprint_roman@yahoo.com" type="email" id="client_email" name="client_email">
                        </div>
                    </div>
                    <button type="button" id="save_edit_button" style="display:none;">Salvează Modificările</button>
                </div>

                <div class="form-group">
                    <label for="order_details"><strong>Info Comandă:</strong></label>
                    <textarea id="order_details"
                        name="order_details"
                        rows="4"
                        cols="50"
                        required
                        placeholder="Introdu detaliile comenzii"></textarea>
                </div>

                <div class="form-group">
                    <label for="avans">Avans:</label>
                    <input placeholder="Cât se poate" type="number" id="avans" name="avans" max="9999" step="0.01">
                </div>

                <div class="form-group">
                    <label for="datePickerSelect"><strong>Data Livrare:</strong></label>
                    <select id="datePickerSelect" name="due_date"></select>
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
                        $users_sql = "SELECT user_id, username FROM users WHERE user_id != 4";
                        $users_result = $conn->query($users_sql);

                        if ($users_result->num_rows > 0) {
                            while ($user = $users_result->fetch_assoc()) {
                                $selected = ($assigned_filter == $user['user_id']) ? 'selected' : '';
                                echo "<option value='" . $user['user_id'] . "' $selected>" . $user['username'] . "</option>";
                            }
                        }
                        ?>
                    </select>
                </div>
                <div class="form-group">
                    <button class="button" type="submit">
                        <i class="fa-solid fa-circle-plus"></i> Adaugă Comandă
                    </button>
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
            <form method="GET" action="dashboardv2.php">
                <div class="filters">
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
                            $users_sql = "SELECT user_id, username FROM users WHERE user_id != 4";
                            $users_result = $conn->query($users_sql);

                            if ($users_result->num_rows > 0) {
                                while ($user = $users_result->fetch_assoc()) {
                                    $selected = ($assigned_filter == $user['user_id']) ? 'selected' : '';
                                    echo "<option value='" . $user['user_id'] . "' $selected>" . $user['username'] . "</option>";
                                }
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

    <!-- Floating Notes Button -->
    <div id="notesFab" title="Notes">
        <i class="fa-solid fa-note-sticky"></i>
    </div>

    <div id="notesModal" class="modal">
        <div class="modal-backdrop"></div>
        <div class="modal-content">
            <header>
                <h4>Notițe</h4>
                <button class="close-btn"><i class="fa-solid fa-circle-xmark"></i></button>
            </header>
            <ul id="notesList"></ul>
            <div class="new-note">
                <textarea id="noteInput" placeholder="Scrie un mesaj…"></textarea>
                <button id="addNoteBtn">Adaugă</button>
            </div>
        </div>
    </div>

    <script>
        $(function() {
            const api = 'notes_api.php';
            const $modal = $('#notesModal');
            const $backdrop = $modal.find('.modal-backdrop');
            const $notesList = $('#notesList');
            const $input = $('#noteInput');

            // Open modal & load notes
            $('#notesFab').click(function() {
                loadNotes();
                $modal.show();
            });

            // Close modal
            $backdrop.add($modal.find('.close-btn')).click(function() {
                $modal.hide();
            });

            // Fetch & render
            function loadNotes() {
                $.getJSON(api, {
                        action: 'fetch'
                    })
                    .done(notes => {
                        $notesList.empty();
                        if (!notes.length) {
                            $notesList.append('<li>Nici o notiță.</li>');
                        } else {
                            notes.forEach(n => {
                                $notesList.append(`
              <li data-id="${n.note_id}">
                <span>${n.content}</span>
                <i class="fa-solid fa-trash delete-note"></i>
              </li>`);
                            });
                        }
                    });
            }

            // Add note
            $('#addNoteBtn').click(function() {
                let text = $input.val().trim();
                if (!text) return;
                $.post(api, {
                        action: 'add',
                        content: text
                    })
                    .done(() => {
                        $input.val('');
                        loadNotes();
                    });
            });

            // Delete note (event delegation)
            $notesList.on('click', '.delete-note', function() {
                let $li = $(this).closest('li');
                let id = $li.data('id');
                $.post(api, {
                        action: 'delete',
                        note_id: id
                    })
                    .done(resp => {
                        if (resp.deleted) $li.slideUp(200, () => $li.remove());
                    });
            });
        });
    </script>

    <footer>
        <p style="font-size: larger;">© Color Print</p>
        <a href="dashboard.php" style="text-decoration: none; color: white;"><i class="fa-solid fa-house"></i> Dashboard</a>
        <a href="archive.php" style="text-decoration: none; color: white;"><i class="fa-solid fa-box-archive"></i> Arhivă</a>
        <a href="unpaid_orders.php" style="text-decoration: none; color: white;"><i class="fa-solid fa-ban"></i> Comenzi nefacturate</a>
        <div id="versionToggle" style="position: fixed; bottom: 20px; right: 30px; background: #333; padding: 10px; border-radius: 5px; cursor: pointer;">
            <button onclick="toggleVersion()">Schimbă la <?php echo (basename($_SERVER['PHP_SELF']) === 'dashboardv2.php') ? 'V1' : 'V2'; ?></button>
        </div>
    </footer>

    <script>
        document.addEventListener("DOMContentLoaded", () => {
            const canvas = document.getElementById("autumnLeaves");
            const ctx = canvas.getContext("2d");
            let W = 0,
                H = 0;

            function resize() {
                W = canvas.width = window.innerWidth;
                H = canvas.height = window.innerHeight;
            }
            window.addEventListener("resize", resize);
            resize();

            // Replace with your own PNG URLs
            const SPRITES = [
                "https://color-print.ro/magazincp/leaf1.png",
                "https://color-print.ro/magazincp/leaf2.png",
                "https://color-print.ro/magazincp/leaf3.png"
            ];

            const images = [];
            let loaded = 0;
            SPRITES.forEach(src => {
                const img = new Image();
                img.crossOrigin = "anonymous";
                img.onload = () => {
                    loaded++;
                };
                img.src = src;
                images.push(img);
            });

            const LEAF_COUNT = 10;
            const leaves = [];

            function rand(min, max) {
                return Math.random() * (max - min) + min;
            }

            class Leaf {
                constructor() {
                    this.reset(true);
                }
                reset(initial = false) {
                    this.img = images[Math.floor(rand(0, images.length))] || images[0];
                    this.size = rand(28, 56);
                    this.x = rand(0, W);
                    this.y = initial ? rand(-H, 0) : -this.size;
                    this.speedY = rand(0.6, 1.1);
                    this.baseDrift = rand(-0.15, 0.25);
                    this.angle = rand(0, Math.PI * 2);
                    this.spin = rand(-0.02, 0.02);
                    this.swayAmp = rand(10, 28);
                    this.swayFreq = rand(0.6, 1.2);
                    this.time = rand(0, 1000);
                    this.flip = Math.random() < 0.5 ? -1 : 1;
                }
                update(dt) {
                    this.time += dt;
                    const wind = Math.sin(this.time * 0.00025) * 0.25;
                    const sway = Math.sin(this.time * 0.002 * this.swayFreq) * this.swayAmp;

                    this.x += this.baseDrift + wind + (sway * 0.01);
                    this.y += this.speedY;
                    this.angle += this.spin;

                    if (this.y > H + this.size || this.x < -this.size || this.x > W + this.size) {
                        this.reset(false);
                        this.y = -this.size;
                    }
                }
                draw() {
                    if (!this.img || !this.img.complete) return;
                    ctx.save();
                    ctx.translate(this.x, this.y);
                    ctx.rotate(this.angle);
                    const w = this.size * this.flip;
                    const h = this.size;
                    ctx.drawImage(this.img, -w / 2, -h / 2, w, h);
                    ctx.restore();
                }
            }

            function start() {
                leaves.length = 0;
                for (let i = 0; i < LEAF_COUNT; i++) leaves.push(new Leaf());

                let last = performance.now();

                function tick(now) {
                    const dt = now - last;
                    last = now;
                    ctx.clearRect(0, 0, W, H);
                    for (const leaf of leaves) {
                        leaf.update(dt);
                        leaf.draw();
                    }
                    requestAnimationFrame(tick);
                }
                requestAnimationFrame(tick);
            }

            const waitForImages = setInterval(() => {
                if (loaded > 0) {
                    clearInterval(waitForImages);
                    start();
                }
            }, 50);
        });
    </script>

</body>


</html>