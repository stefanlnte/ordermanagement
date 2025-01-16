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
$limit = 18; // Number of orders per page
$offset = ($page - 1) * $limit;

// Fetch orders with filters and sorting
$order_sql = "SELECT o.*, c.client_name, u.username as assigned_user, cat.category_name, o.delivery_date FROM orders o 
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

// Handle AJAX request for fetching client details
if (isset($_GET['fetch_client_details']) && isset($_GET['client_id'])) {
    $client_id = $_GET['client_id'];
    $client_sql = "SELECT * FROM clients WHERE client_id = ?";
    $stmt = $conn->prepare($client_sql);
    $stmt->bind_param("i", $client_id);
    $stmt->execute();
    $client_result = $stmt->get_result();
    if ($client_result->num_rows > 0) {
        $client = $client_result->fetch_assoc();
        echo json_encode($client);
    } else {
        echo json_encode(['error' => 'Client not found.']);
    }
    $stmt->close();
    $conn->close();
    exit();
}

// Handle AJAX request for searching clients
if (isset($_GET['search_clients']) && isset($_GET['query'])) {
    $query = $_GET['query'];
    $client_sql = "SELECT client_id, client_name FROM clients WHERE client_name LIKE ?";
    $stmt = $conn->prepare($client_sql);
    $search_query = "%" . $query . "%";
    $stmt->bind_param("s", $search_query);
    $stmt->execute();
    $client_result = $stmt->get_result();
    $clients = [];
    while ($row = $client_result->fetch_assoc()) {
        $clients[] = $row;
    }
    echo json_encode($clients);
    $stmt->close();
    $conn->close();
    exit();
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
    <link rel="icon" href="/favicon.ico" type="image/x-icon" />
    <link rel="stylesheet" href="https://code.jquery.com/ui/1.12.1/themes/base/jquery-ui.css">
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="https://code.jquery.com/ui/1.12.1/jquery-ui.js"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/codemirror/5.65.5/codemirror.min.css">

    <!-- CodeMirror JavaScript -->
    <script src="https://cdnjs.cloudflare.com/ajax/libs/codemirror/5.65.5/codemirror.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/codemirror/5.65.5/mode/javascript/javascript.min.js"></script>
    <script>
        document.addEventListener('DOMContentLoaded', function() {
            document.getElementById('orderForm').addEventListener('submit', function(event) {
                event.preventDefault(); // Prevent the default form submission

                fetch('dashboard.php', {
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
</head>

<body>

    <body>
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

            <p>
                <span id="greeting-message"></span>, <?php echo $_SESSION['username']; ?>! Astăzi este <span id="currentdate"></span>.
            </p>
            <div class="button"><a href="logout.php">Deconectare</a> </div>
        </header>
        <div class="container">
            <div class="sidebar">
                <h2>Adaugă Comandă</h2>
                <form id="orderForm" method="post" action="dashboard.php" autocomplete="off">
                    <input type="hidden" name="add_order" value="1">
                    <div class="form-group">
                        <label for="client_search"><strong>Caută Client:</strong></label>
                        <input type="text" id="client_search" name="client_search">
                        <input type="hidden" id="client_id" name="client_id">
                        <button type="button" id="reset_button">Resetează</button>
                        <button type="button" id="edit_button" style="display:none;">Editează</button>
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
                    <div class="form-group button">
                        <input type="submit" value="Adaugă Comandă" style="font-family: Poppins, sans-serif;">
                    </div>
                </form>
            </div>


            <div class="main-content">
                <h2>Comenzi </h2>
                <table>
                    <thead>
                        <div class="filters">
                            <form method="GET" action="dashboard.php">
                                <div class="form-group">
                                    <label for="status_filter">Status:</label>
                                    <select id="status_filter" name="status_filter">
                                        <option value="">Toate</option>
                                        <option value="assigned" <?php if ($status_filter == 'assigned') echo 'selected'; ?>>Atribuit</option>
                                        <option value="completed" <?php if ($status_filter == 'completed') echo 'selected'; ?>>Terminat</option>
                                        <option value="delivered" <?php if ($status_filter == 'delivered') echo 'selected'; ?>>Livrat</option>
                                        <option value="cancelled" <?php if ($status_filter == 'cancelled') echo 'selected'; ?>>Anulat</option>
                                    </select>
                                </div>
                                <div class="form-group">
                                    <label for="assigned_filter">Operator:</label>
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
                                <div class="form-group">
                                    <!-- <label for="category_filter">Categorie</label> -->
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
                                <div class="form-group">
                                    <label for="sort_order">Ordine:</label>
                                    <select id="sort_order" name="sort_order">
                                        <option value="ASC" <?php if ($sort_order == 'ASC') echo 'selected'; ?>>Ascendent</option>
                                        <option value="DESC" <?php if ($sort_order == 'DESC') echo 'selected'; ?>>Descendent</option>
                                    </select>
                                </div>
                                <div><button type="submit">Aplică filtre</button></div>
                                <div><button type="button" onclick="window.location.href='dashboard.php'">Resetează filtre</button></div>
                            </form>
                        </div>
                        <tr>
                            <th>Nr. Comanda</th>
                            <th>Client</th>
                            <th>Info Comandă</th>
                            <th>Din data</th>
                            <th>Data livrare</th>
                            <th>Status</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php
                        if ($orders_result->num_rows > 0) {
                            while ($row = $orders_result->fetch_assoc()) {
                                $order_id = str_pad($row["order_id"], 3, '0', STR_PAD_LEFT);
                                $order_date = formatDateWithoutYearWithDay($row["order_date"]) . ' ' . date('H:i', strtotime($row["order_time"]));
                                //formatRemainingDays is called with delivery_date
                                $due_date = formatRemainingDays($row["due_date"], $row["status"], $row["delivery_date"] ?? null);
                                $status = $row["status"] ?? 'neatribuită';
                                $row_classes = [];

                                if ($status == 'assigned' && $status == 'completed') {
                                    $status = 'atribuită lui ' . $row["assigned_user"];
                                    $row_classes[] = 'order-completed';
                                } elseif ($row["assigned_to"] == $_SESSION['user_id'] && $status != 'completed' && $status != 'delivered') {
                                    $status = 'comanda ta';
                                    $row_classes[] = 'order-current-user';
                                } elseif ($status != "completed" && $status != "delivered") {
                                    $status = 'atribuită lui ' . $row["assigned_user"];
                                    $row_classes[] = 'order-assigned';
                                } elseif ($status == 'completed') {
                                    $status = 'terminată';
                                    $row_classes[] = 'order-completed';
                                } else {
                                    $status = 'livrată';
                                    $row_classes[] = 'order-delivered';
                                }

                                $row_class = implode(' ', $row_classes);

                                echo "<tr class='$row_class' onclick=\"window.location.href='view_order.php?order_id=" . $row["order_id"] . "'\">";
                                echo "<td>" . $order_id . "</td>";
                                echo "<td>" . $row["client_name"] . "</td>";
                                echo "<td>" . $row["order_details"] . "</td>";
                                echo "<td>" . $order_date . "</td>";
                                echo "<td>" . $due_date . "</td>";
                                echo "<td>" . $status . "</td>";
                                echo "</tr>";
                            }
                        } else {
                            echo "<tr><td colspan='6'>Nu există comenzi.</td></tr>";
                        }
                        ?>
                    </tbody>
                </table>
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
                        echo "<a href='dashboard.php?page=1&status_filter=$status_filter&assigned_filter=$assigned_filter&category_filter=$category_filter&sort_order=$sort_order'>Prima</a>";
                    }

                    // Previous page link
                    if ($total_pages > 5 && $page > 1) {
                        echo "<a href='dashboard.php?page=" . ($page - 1) . "&status_filter=$status_filter&assigned_filter=$assigned_filter&category_filter=$category_filter&sort_order=$sort_order'>Înapoi</a>";
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
                        echo "<a href='dashboard.php?page=$i&status_filter=$status_filter&assigned_filter=$assigned_filter&category_filter=$category_filter&sort_order=$sort_order' class='$active'>$i</a>";
                    }

                    // Next page link
                    if ($total_pages > 5 && $page < $total_pages) {
                        echo "<a href='dashboard.php?page=" . ($page + 1) . "&status_filter=$status_filter&assigned_filter=$assigned_filter&category_filter=$category_filter&sort_order=$sort_order'>Înainte</a>";
                    }

                    // Last page link
                    if ($total_pages > 5 && $page < $total_pages) {
                        echo "<a href='dashboard.php?page=$total_pages&status_filter=$status_filter&assigned_filter=$assigned_filter&category_filter=$category_filter&sort_order=$sort_order'>Ultima</a>";
                    }
                    ?>
                </div>
            </div>
        </div>

        <footer>
            <p class="footer">© Color Print</p>
        </footer>


        <!-- Initialize CodeMirror -->

        <script>
            $(document).ready(function() {
                        var editor = CodeMirror.fromTextArea(document.getElementById('order_details'), {
                            extraKeys: {
                                "Enter": function(cm) {
                                    cm.replaceSelection("<br>\n", "end");
                                }
                            }));
        </script>
        <script>
            $(document).ready(function() {
                $("#client_search").autocomplete({
                    source: function(request, response) {
                        $.ajax({
                            url: "dashboard.php",
                            dataType: "json",
                            data: {
                                search_clients: 1,
                                query: request.term
                            },
                            success: function(data) {
                                response($.map(data, function(item) {
                                    return {
                                        label: item.client_name,
                                        value: item.client_id
                                    };
                                }));
                            }
                        });
                    },
                    focus: function(event, ui) {
                        $("#client_search").val(ui.item.label);
                        return false;
                    },
                    select: function(event, ui) {
                        $("#client_search").val(ui.item.label);
                        $("#client_id").val(ui.item.value);
                        fetchClientDetails(ui.item.value);
                        $("#new_client_fields").hide();
                        $("#edit_button").show();
                        $("#save_edit_button").hide();
                        return false;
                    }
                });

                document.getElementById('reset_button').addEventListener('click', function() {
                    document.getElementById('client_search').value = '';
                    document.getElementById('client_id').value = '';
                    document.getElementById('client_name').value = '';
                    document.getElementById('client_phone').value = '';
                    document.getElementById('client_email').value = '';
                    document.getElementById('new_client_fields').style.display = 'block';
                    document.getElementById('edit_button').style.display = 'none';
                    document.getElementById('save_edit_button').style.display = 'none';
                });

                $("#edit_button").click(function() {
                    $("#new_client_fields").show();
                    $("#save_edit_button").show();
                    $(this).hide();
                });

                $("#save_edit_button").click(function() {
                    // Save the edited client details
                    var client_id = $("#client_id").val();
                    var client_name = $("#client_name").val();
                    var client_phone = $("#client_phone").val();
                    var client_email = $("#client_email").val();

                    $.ajax({
                        url: "update_client.php",
                        type: "POST",
                        data: {
                            client_id: client_id,
                            client_name: client_name,
                            client_phone: client_phone,
                            client_email: client_email
                        },
                        success: function(response) {
                            var result = JSON.parse(response);
                            if (result.success) {
                                alert("Client details updated successfully.");
                            } else {
                                alert("Error updating client: " + result.error);
                            }
                            $("#new_client_fields").hide();
                            $("#save_edit_button").hide();
                            $("#edit_button").show();
                        }
                    });
                });

                $("#client_id").change(function() {
                    toggleClientFields();
                    fetchClientDetails(this.value);
                });


            });

            function fetchClientDetails(client_id) {
                $.ajax({
                    url: "dashboard.php",
                    type: "GET",
                    data: {
                        fetch_client_details: 1,
                        client_id: client_id
                    },
                    success: function(data) {
                        var client = JSON.parse(data);
                        if (client.error) {
                            alert(client.error);
                        } else {
                            $("#client_name").val(client.client_name);
                            $("#client_phone").val(client.client_phone);
                            $("#client_email").val(client.client_email);
                            $("#new_client_fields").hide();
                            $("#edit_button").show();
                            $("#save_edit_button").hide();
                        }
                    }
                });
            }

            function toggleClientFields() {
                if ($("#client_id").val()) {
                    $("#new_client_fields").hide();
                } else {
                    $("#new_client_fields").show();
                }
            }
        </script>

    </body>

</html>