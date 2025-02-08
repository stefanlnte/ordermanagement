<?php
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

// Function to format date without year with day
function formatDateWithoutYearWithDay($dateString)
{
    if ($dateString === null) {
        return 'NULL'; // or any other placeholder you want
    }
    $date = new DateTime($dateString);
    $day = $date->format('d');
    $month = $date->format('m');
    $year = $date->format('Y');
    $daysOfWeek = ['Duminică', 'Luni', 'Marți', 'Miercuri', 'Joi', 'Vineri', 'Sâmbătă'];
    $dayOfWeek = $daysOfWeek[$date->format('w')];
    return $dayOfWeek . ', ' . str_pad(
        $day,
        2,
        '0',
        STR_PAD_LEFT
    ) . '.' . str_pad($month, 2, '0', STR_PAD_LEFT) . '.' . $year;
}

/// Fetch filter values
$status_filter = $_GET['status_filter'] ?? '';
$assigned_filter = $_GET['assigned_filter'] ?? '';
$category_filter = $_GET['category_filter'] ?? '';
$sort_order = $_GET['sort_order'] ?? 'ASC';
$page = $_GET['page'] ?? 1;
$limit = 18; // Number of orders per page
$offset = ($page - 1) * $limit;

// Fetch archived orders with filters and sorting
$archive_sql = "SELECT o.*, c.client_name, u.username as assigned_user, cat.category_name, o.delivery_date 
                FROM archived_orders o 
                JOIN clients c ON o.client_id = c.client_id 
                LEFT JOIN users u ON o.assigned_to = u.user_id 
                LEFT JOIN categories cat ON o.category_id = cat.category_id 
                WHERE 1=1";

$total_params = [];
$total_types = '';
$params = [];
$types = '';

if ($status_filter) {
    $archive_sql .= " AND o.status = ?";
    $params[] = $status_filter;
    $types .= 's';
}
if ($assigned_filter) {
    $archive_sql .= " AND o.assigned_to = ?";
    $params[] = $assigned_filter;
    $types .= 'i';
}
if ($category_filter) {
    $archive_sql .= " AND o.category_id = ?";
    $params[] = $category_filter;
    $types .= 'i';
}

$archive_sql .= " ORDER BY o.order_id $sort_order LIMIT ? OFFSET ?";
$params[] = $limit;
$params[] = $offset;
$types .= 'ii';

$stmt = $conn->prepare($archive_sql);
$stmt->bind_param($types, ...$params);
$stmt->execute();
$archive_result = $stmt->get_result();

// Fetch total number of archived orders for pagination
$total_orders_sql = "SELECT COUNT(*) as total FROM archived_orders WHERE 1=1";

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
$total_result = $total_stmt->get_result();
$total_orders = $total_result->fetch_assoc()['total'];
$total_stmt->close();

// Calculate total pages
$total_pages = ceil($total_orders / $limit);

// Fetch all users for the "assigned to" dropdown
$users_sql = "SELECT user_id, username FROM users";
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
?>

<!DOCTYPE html>
<html>

<head>
    <title>Archive Orders</title>
    <link rel="stylesheet" type="text/css" href="styles.css">
    <link rel="stylesheet" type="text/css" href="style.css">
    <link rel="icon" type="image/png" href="https://color-print.ro/magazincp/favicon.png" />
    <link rel="stylesheet" href="https://code.jquery.com/ui/1.12.1/themes/base/jquery-ui.css">
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="https://code.jquery.com/ui/1.12.1/jquery-ui.js"></script>
    <!-- Include Select2 CSS -->
    <link href="https://cdnjs.cloudflare.com/ajax/libs/select2/4.0.13/css/select2.min.css" rel="stylesheet" />
    <!-- Include Select2 JavaScript -->
    <script src="https://cdnjs.cloudflare.com/ajax/libs/select2/4.0.13/js/select2.min.js"></script>
    <script>
        document.addEventListener('DOMContentLoaded', function() {
            // Initialize Select2 on select elements
            $('#status_filter, #assigned_filter, #category_filter, #sort_order').select2({
                dropdownAutoWidth: true,
                width: 'auto'
            });
        });
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
    <header id="header">
        <button href="javascript:void(0);" onclick="window.history.back();"> &#8592; Înapoi la panou comenzi</button>
    </header>
    <h1 style="text-align: center;">Arhivă</h1>
    <div class="container" style="min-height: 100vh;">
        <div class="main-content">
            <table>
                <thead>
                    <div class="filters">
                        <form method="GET" action="archive.php">
                            <div class="form-group">
                                <label for="status_filter">Status:</label>
                                <select id="status_filter" name="status_filter">
                                    <option value="">Toate</option>
                                    <option value="delivered" <?php if ($status_filter == 'delivered') echo 'selected'; ?>>Livrat</option>
                                    <option value="cancelled" <?php if ($status_filter == 'cancelled') echo 'selected'; ?>>Anulat</option>
                                </select>
                            </div>
                            <div class="form-group">
                                <label for="assigned_filter">Operator:</label>
                                <select id="assigned_filter" name="assigned_filter">
                                    <option value="">Toți</option>
                                    <?php
                                    foreach ($users as $user) {
                                        $selected = ($assigned_filter == $user['user_id']) ? 'selected' : '';
                                        echo "<option value='" . $user['user_id'] . "' $selected>" . $user['username'] . "</option>";
                                    }
                                    ?>
                                </select>
                            </div>
                            <div class="form-group">
                                <label for="category_filter">Categorie:</label>
                                <select id="category_filter" name="category_filter">
                                    <option value="">Toate</option>
                                    <?php
                                    foreach ($categories as $category) {
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
                            <div><button type="button" onclick="window.location.href='archive.php'">Resetează filtre</button></div>
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
                    if ($archive_result->num_rows > 0) {
                        while ($row = $archive_result->fetch_assoc()) {
                            $order_id = str_pad($row["order_id"], 3, '0', STR_PAD_LEFT);
                            $order_date = formatDateWithoutYearWithDay($row["order_date"]) . ' ' . date('H:i', strtotime($row["order_time"]));
                            $due_date = formatDateWithoutYearWithDay($row["delivery_date"]); // No need to check for NULL here, handled in the function
                            $status = $row["status"];
                            echo "<tr>";
                            echo "<td>" . $order_id . "</td>";
                            echo "<td>" . $row["client_name"] . "</td>";
                            echo "<td>" . $row["order_details"] . "</td>";
                            echo "<td>" . $order_date . "</td>";
                            echo "<td>" . $due_date . "</td>"; // This will now correctly display 'NULL' if delivery_date is NULL
                            echo "<td>" . $status . "</td>";
                            echo "</tr>";
                        }
                    } else {
                        echo "<tr><td colspan='6'>Nu există comenzi arhivate.</td></tr>";
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
                if ($total_pages > 1 && $page > 1) {
                    echo "<a href='archive.php?page=1&status_filter=$status_filter&assigned_filter=$assigned_filter&category_filter=$category_filter&sort_order=$sort_order'>Prima</a>";
                }

                // Previous page link
                if ($page > 1) {
                    echo "<a href='archive.php?page=" . ($page - 1) . "&status_filter=$status_filter&assigned_filter=$assigned_filter&category_filter=$category_filter&sort_order=$sort_order'>Înapoi</a>";
                }

                // Define the number of pages to show before and after the current page
                $window_size = 2; // This means 2 pages before and 2 pages after the current page

                // Calculate the start and end page numbers
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

                // Display page numbers within the window
                for ($i = $start; $i <= $end; $i++) {
                    $active = ($i == $page) ? 'active' : '';
                    echo "<a href='archive.php?page=$i&status_filter=$status_filter&assigned_filter=$assigned_filter&category_filter=$category_filter&sort_order=$sort_order' class='$active'>$i</a>";
                }

                // Next page link
                if ($page < $total_pages) {
                    echo "<a href='archive.php?page=" . ($page + 1) . "&status_filter=$status_filter&assigned_filter=$assigned_filter&category_filter=$category_filter&sort_order=$sort_order'>Înainte</a>";
                }

                // Last page link
                if ($total_pages > 1 && $page < $total_pages) {
                    echo "<a href='archive.php?page=$total_pages&status_filter=$status_filter&assigned_filter=$assigned_filter&category_filter=$category_filter&sort_order=$sort_order'>Ultima</a>";
                }
                ?>
            </div>
        </div>
    </div>
    <footer>
        <p>© Color Print</p>
        <a href="archive.php" style="text-decoration: none; color: white;">Arhivă</a>
        <a href="unpaid_orders.php" style="text-decoration: none; color: white;">Comenzi nefacturate</a>
    </footer>
</body>

</html>

<?php
$conn->close();
?>