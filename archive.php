<?php
include 'db.php';

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
</head>

<body>
    <header>
        <h1 style="text-align: center;">Arhivă</h1>
        <div style="margin-left: 10px;" class="button"><a href="dashboard.php">Înapoi la comenzi</a></div>
    </header>
    <div class="container" style="min-height: 100vh;">
        <div class="main-content">
            <h2>Comenzi Arhivate</h2>
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
        <p class="footer">© Color Print</p>
    </footer>
</body>

</html>

<?php
$conn->close();
?>