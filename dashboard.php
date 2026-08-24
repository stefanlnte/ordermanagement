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


// Preluăm valorile filtrelor din query string (GET), cu fallback la valori implicite
$status_filter = $_GET['status_filter'] ?? '';       // Filtru pentru statusul comenzii
$assigned_filter = $_GET['assigned_filter'] ?? '';   // Filtru pentru utilizatorul asignat
$category_filter = $_GET['category_filter'] ?? '';   // Filtru pentru categorie
$sort_order = $_GET['sort_order'] ?? 'ASC';          // Ordinea de sortare (ASC/DESC)
$client_filter = $_GET['client_filter'] ?? '';       // Filtru pentru client
$page = $_GET['page'] ?? 1;                          // Pagina curentă pentru paginare
$limit = 18;                                         // Număr de comenzi pe pagină
$offset = ($page - 1) * $limit;                      // Offset-ul pentru paginare

// Construim clauza WHERE (comună pentru query-ul de date și cel de numărare)
$where_sql = " WHERE 1=1"; // WHERE 1=1 pentru a putea adăuga condiții dinamice

// Inițializăm variabile pentru parametrii și tipurile lor (pentru prepared statements)
$params = [];
$types = '';

// Excludem comenzile cu status 'delivered' și 'cancelled' în mod implicit
if ($status_filter !== 'delivered' && $status_filter !== 'cancelled') {
    $where_sql .= " AND o.status NOT IN ('delivered', 'cancelled') ";
}

// Adăugăm filtre dinamice în funcție de parametrii primiți
if ($status_filter) {
    $where_sql .= " AND o.status = ?";
    $params[] = $status_filter;
    $types .= 's'; // string
}
if ($assigned_filter) {
    $where_sql .= " AND o.assigned_to = ?";
    $params[] = $assigned_filter;
    $types .= 'i'; // integer
}
if ($category_filter) {
    $where_sql .= " AND o.category_id = ?";
    $params[] = $category_filter;
    $types .= 'i';
}
if ($client_filter) {
    $where_sql .= " AND o.client_id = ?";
    $params[] = $client_filter;
    $types .= 'i';
}

// Query-ul pentru selectarea comenzilor cu filtre și sortare
$order_sql = "SELECT o.*, c.client_name, u.username as assigned_user, cat.category_name, o.delivery_date FROM orders o
              JOIN clients c ON o.client_id = c.client_id
              LEFT JOIN users u ON o.assigned_to = u.user_id
              LEFT JOIN categories cat ON o.category_id = cat.category_id"
    . $where_sql;

// Adăugăm sortarea și paginarea
// Override sorting when filtering delivered orders
if ($status_filter === 'delivered') {
    $order_sql .= " ORDER BY o.delivery_date $sort_order";
} else {
    $order_sql .= " ORDER BY o.order_id $sort_order";
}

$order_sql .= " LIMIT ? OFFSET ?";
$data_params = array_merge($params, [$limit, $offset]);
$data_types = $types . 'ii'; // integer, integer

// Pregătim și executăm query-ul pentru comenzile filtrate
$stmt = $conn->prepare($order_sql);
$stmt->bind_param($data_types, ...$data_params);
$stmt->execute();
$orders_result = $stmt->get_result();

// Numărul total de comenzi (pentru paginare) — în loc de SQL_CALC_FOUND_ROWS
// (care forțează numărarea tuturor rândurilor ce se potrivesc, indiferent de
// LIMIT, și nu poate folosi eficient un index "covering"), rulăm un COUNT(*)
// separat cu aceleași condiții WHERE, dar fără JOIN-urile inutile (nu avem
// nevoie de coloane din clients/users/categories doar ca să numărăm rândurile).
// Pe tabele mari, cu index pe o.status/coloanele filtrate, varianta asta este
// de regulă mai rapidă decât SQL_CALC_FOUND_ROWS.
$count_sql = "SELECT COUNT(*) AS total FROM orders o" . $where_sql;
$count_stmt = $conn->prepare($count_sql);
if ($types !== '') {
    $count_stmt->bind_param($types, ...$params);
}
$count_stmt->execute();
$total_orders = (int) $count_stmt->get_result()->fetch_assoc()['total'];
$count_stmt->close();

// Calculăm numărul total de pagini
$total_pages = ceil($total_orders / $limit);

// --- STATISTICI RAPIDE PENTRU CARDS ---
// Un singur query cu agregare condițională.
$stats_sql = "SELECT
                SUM(status = 'assigned' AND due_date < CURDATE())              AS overdue,
                SUM(status = 'assigned')                                       AS active,
                SUM(status = 'completed')                                      AS completed,
                SUM(status = 'assigned' AND due_date = CURDATE())              AS deliver_today,
                SUM(status = 'delivered' AND DATE(delivery_date) = CURDATE())  AS delivered_today,
                SUM(status = 'delivered')                                      AS delivered_total,
                SUM(status = 'cancelled')                                      AS cancelled_total
              FROM orders";
$stats_res = $conn->query($stats_sql);
$stats_row = $stats_res ? $stats_res->fetch_assoc() : [];
$stats_overdue    = (int)($stats_row['overdue'] ?? 0);
$stats_active     = (int)($stats_row['active'] ?? 0);
$stats_completed  = (int)($stats_row['completed'] ?? 0);
$stats_deliver_today = (int)($stats_row['deliver_today'] ?? 0);
$stats_delivered_today = (int)($stats_row['delivered_today'] ?? 0);
$stats_delivered_total = (int)($stats_row['delivered_total'] ?? 0);
$stats_cancelled_total = (int)($stats_row['cancelled_total'] ?? 0);

// --- LISTA DE OPERATORI (filtrare users) ---
// Același query se executa de 3 ori (dropdown "Atribuie", filtru "Operator", dropdown "Trimite către").
// Extras într-un include partajat, folosit și de view_order.php.
include 'get_operators.php';

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

        // Check if client exists or create a new client
        if (empty($client_id)) {
            // Verifică dacă telefonul există deja
            $check_phone_sql = "SELECT client_id FROM clients WHERE client_phone = ?";
            $stmt = $conn->prepare($check_phone_sql);
            $stmt->bind_param("s", $client_phone);
            $stmt->execute();
            $check_result = $stmt->get_result();

            if ($check_result->num_rows > 0) {
                // Telefon deja înregistrat → preia clientul existent
                $existing_client = $check_result->fetch_assoc();
                $client_id = $existing_client['client_id'];

                echo "Clientul cu acest număr de telefon există deja. Comanda va fi asociată cu clientul existent (ID: $client_id).<br>";
            } else {
                // Telefon nou → inserează clientul
                $insert_client_sql = "INSERT INTO clients (client_name, client_email, client_phone) VALUES (?, ?, ?)";
                $stmt = $conn->prepare($insert_client_sql);
                $stmt->bind_param("sss", $client_name, $client_email, $client_phone);

                if ($stmt->execute()) {
                    $client_id = $stmt->insert_id;
                    echo "Client nou creat cu ID: $client_id<br>";
                } else {
                    echo "Eroare la crearea clientului: " . $stmt->error;
                    exit();
                }
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
            echo "<script>window.location.href='view_order.php?order_id=" . $last_order_id .
                "&return=" . urlencode($_SERVER['REQUEST_URI']) . "';</script>";
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
    <link rel="icon" type="image/png" href="https://color-print.ro/magazincp/favicon.png" />
    <!-- SweetAlert2 -->
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.min.css">
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
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
    <!-- Include TIPPY -->
    <link rel="stylesheet" href="https://unpkg.com/tippy.js@6/themes/light-border.css" />
    <script src="https://unpkg.com/@popperjs/core@2"></script>
    <script src="https://unpkg.com/tippy.js@6"></script>
    <!-- Include Select2 JavaScript -->
    <script src="https://cdnjs.cloudflare.com/ajax/libs/select2/4.0.13/js/select2.full.min.js"></script>
    <link rel="stylesheet" type="text/css" href="styles.css">

    <!-- Dashboard front-end logic (Select2 init, order slider, quiet AJAX
         refresh, header search, hero greeting, WhatsApp widget, order
         preview tooltips, filters/sort/pagination, etc). Must load after
         the libraries above since it depends on $, Swal, tippy and AOS. -->
    <script src="script.js"></script>
</head>

<body>
    <header id="header">
        <div class="header-inner">
            <div class="header-search">
                <form id="lookupForm">
                    <input type="hidden" name="return" value="<?= htmlspecialchars($_SERVER['REQUEST_URI']) ?>">
                    <div class="header-search-wrap" data-aos="fade-down"
                        data-aos-easing="linear"
                        data-aos-duration="800">
                        <i class="fa-solid fa-magnifying-glass header-search-icon" aria-hidden="true"></i>
                        <input type="text"
                            id="order_lookup"
                            class="order-lookup-input"
                            autocomplete="off"
                            spellcheck="false"
                            placeholder="Căutare comandă (nr. comenzii, client, telefon, detalii comandă)...">
                    </div>
                </form>
            </div>
            <div class="header-actions">
                <button onclick="openStatsSlider()"
                    data-aos="fade-down"
                    data-aos-easing="linear"
                    data-aos-duration="800">
                    <i class="fa-solid fa-chart-line"></i> Statistici
                </button>
                <button data-aos="fade-down"
                    data-aos-easing="linear"
                    data-aos-duration="800" onclick="window.location.href='logout.php'">
                    <i class="fa-solid fa-right-from-bracket"></i> Deconectare
                </button>
            </div>
        </div>
    </header>


    <div class="image-container hero-video-container">
        <video autoplay muted loop playsinline class="hero-video">
            <source src="https://color-print.ro/magazincp/header.mp4" type="video/mp4">
        </video>

        <div class="image-overlay"></div>

        <!-- Wrapper-ul care gestionează EXCLUSIV centrarea absolută -->
        <div class="hero-logo-wrapper">

            <!-- Logo-ul care gestionează EXCLUSIV animația AOS -->
            <object data-aos="zoom-in"
                data-aos-easing="linear"
                data-aos-duration="800"
                type="image/svg+xml"
                data="https://color-print.ro/magazincp/comenzi.svg"
                class="hero-logo-object">
            </object>

        </div>

        <!-- Container greeting -->
        <div class="hero-greeting-overlay" data-aos="fade-up" data-aos-easing="linear" data-aos-duration="800">
            <!-- Icon va fi injectat de JS -->
            <div class="hero-greeting-icon" id="heroGreetingIcon"></div>

            <div class="hero-greeting-copy">
                <p class="hero-greeting-text">
                    <!-- Mesajul va fi injectat de JS -->
                    <span id="heroGreetingWord"></span>,
                    <!-- PHP doar pentru numele utilizatorului -->
                    <span class="hero-greeting-name"><?= htmlspecialchars(ucfirst($_SESSION['username'])); ?></span>
                    <span class="hero-wave">👋</span>
                </p>
                <p class="hero-greeting-sub">
                    <span id="heroGreetingDate"></span> ·
                    <span id="heroGreetingClock"></span>
                </p>
            </div>
        </div>

        <!-- Hero quick actions: WhatsApp -->
        <div class="hero-actions-overlay" aria-label="Acțiuni rapide">
            <button id="whatsappWidget" class="hero-action-btn hero-action-whatsapp" type="button" title="Trimite mesaj pe WhatsApp" aria-label="Deschide WhatsApp Sender">
                <span class="hero-action-icon">
                    <i class="fa-brands fa-whatsapp"></i>
                </span>
                <span class="hero-action-copy">
                    <strong>WhatsApp</strong>
                    <small>Sender</small>
                </span>
            </button>
        </div>
    </div>
    </div>



    <!-- Banner Statistici Rapide -->
    <div class="stats-banner" data-aos="fade-down" data-aos-duration="800">
        <!-- Card Termen Depășit -->
        <div class="stat-card card-overdue">
            <div class="stat-icon"><i class="fa-solid fa-triangle-exclamation"></i></div>
            <div class="stat-info">
                <h3><?= $stats_overdue; ?></h3>
                <p>Termen Depășit</p>
            </div>
        </div>

        <!-- Card În Lucru -->
        <div class="stat-card card-active">
            <div class="stat-icon"><i class="fa-solid fa-person-digging"></i></div>
            <div class="stat-info">
                <h3><?= $stats_active; ?></h3>
                <p>În lucru / Atribuite</p>
            </div>
        </div>

        <!-- Card Finalizate -->
        <div class="stat-card card-completed">
            <div class="stat-icon"><i class="fa-solid fa-circle-check"></i></div>
            <div class="stat-info">
                <h3><?= $stats_completed; ?></h3>
                <p>Finalizate</p>
            </div>
        </div>

        <!-- Card De livrat azi (finalizate cu delivery_date = azi) -->
        <div class="stat-card card-deliver-today">
            <div class="stat-icon"><i class="fa-solid fa-truck-fast"></i></div>
            <div class="stat-info">
                <h3><?= $stats_deliver_today; ?></h3>
                <p>De livrat azi</p>
            </div>
        </div>

        <!-- Card Livrate azi -->
        <div class="stat-card card-delivered-today">
            <div class="stat-icon"><i class="fa-solid fa-flag-checkered"></i></div>
            <div class="stat-info">
                <h3><?= $stats_delivered_today; ?></h3>
                <p>Livrate azi</p>
            </div>
        </div>
    </div>


    <div class="pinned-section" data-aos="fade-in">
        <?php if ($pinned_result && $pinned_result->num_rows > 0): ?>
            <h2 class="pinned-section-heading">📌 Comenzi urgente</h2>
            <div class="pinned-feed">
                <?php while ($pin = $pinned_result->fetch_assoc()): ?>
                    <a href="view_order.php?order_id=<?= $pin['order_id']; ?>&return=<?= urlencode($_SERVER['REQUEST_URI']); ?>">
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
            <h2><i class="fa-solid fa-receipt"></i> Adaugă Comandă</h2>
            <form id="orderForm" method="post" action="dashboard.php?<?= htmlspecialchars($_SERVER['QUERY_STRING']) ?>" autocomplete="off">
                <input type="hidden" name="return"
                    value="<?= htmlspecialchars($_SERVER['QUERY_STRING'] ? 'dashboard.php?' . $_SERVER['QUERY_STRING'] : 'dashboard.php') ?>">
                <input type="hidden" name="add_order" value="1">

                <div class="form-group">
                    <label for="client_id"><i class="fa-solid fa-magnifying-glass"></i> <strong>Caută client:</strong></label>
                    <select id="client_id" name="client_id">
                        <option value="">Caută</option>
                    </select>
                    <div id="edit_client_button" class="button">
                        <button type="button" id="editClientTrigger">Editează client</button>
                    </div>
                </div>

                <div id="new_client_fields" class="form-group collapsible">
                    <div class="collapsible-inner">
                        <div class="flex-container">
                            <div class="form-group">
                                <label for="client_name"><i class="fa-solid fa-user"></i> <strong>Nume Client:</strong></label>
                                <input required placeholder="Prenume și Nume" type="text" id="client_name" name="client_name">
                            </div>
                            <div class="form-group">
                                <label for="client_phone"><i class="fa-solid fa-phone"></i> <strong>Telefon Client:</strong></label>
                                <input required placeholder="07XXXXXXXX" type="text" id="client_phone" name="client_phone" pattern="0[0-9]{9}" title="Numărul de telefon trebuie să conțină exact 10 cifre și să înceapă cu 0">
                            </div>
                            <div class="form-group">
                                <label for="client_email"><i class="fa-solid fa-envelope"></i> Email Client:</label>
                                <input placeholder="colorprint_roman@yahoo.com" type="email" id="client_email" name="client_email">
                            </div>
                        </div>
                        <button type="button" id="save_edit_button">Salvează Modificările</button>
                    </div>
                </div>

                <div class="form-group">
                    <label for="order_details"><i class="fa-solid fa-circle-info"></i> <strong>Info Comandă:</strong></label>
                    <textarea id="order_details"
                        name="order_details"
                        rows="4"
                        cols="50"
                        required
                        placeholder="Introdu detaliile comenzii"></textarea>
                </div>

                <div class="form-group">
                    <label for="avans"><i class="fa-solid fa-coins"></i> Avans:</label>
                    <input placeholder="50% din total" type="number" id="avans" name="avans" max="9999" step="0.01">
                </div>

                <div class="form-group">
                    <label for="datePickerSelect"><i class="fa-solid fa-calendar-days"></i> <strong>Data Livrare:</strong></label>
                    <select id="datePickerSelect" name="due_date"></select>
                </div>

                <div class="form-group form-group--hidden">
                    <label for="category_id"><i class="fa-solid fa-layer-group"></i> Categorie:</label>
                    <select id="category_id" name="category_id">
                        <?php
                        foreach ($categories as $category) {
                            echo "<option value='" . $category["category_id"] . "'>" . $category["category_name"] . "</option>";
                        }
                        ?>
                    </select>
                </div>

                <div class="form-group">
                    <label for="assigned_to"><i class="fa-solid fa-user-check"></i> Atribuie comanda lui:</label>
                    <select required id="assigned_to" name="assigned_to">
                        <option value="" disabled hidden selected>Operator</option>
                        <?php
                        foreach ($operators as $user) {
                            echo "<option value='" . $user['user_id'] . "'>" . $user['username'] . "</option>";
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
        <div id="editClientModal" class="modal">
            <div class="modal-content">
                <span class="close">&times;</span>
                <h2>Editează detalii</h2>
                <form id="editClientForm">
                    <input type="hidden" name="return" value="<?= htmlspecialchars($_SERVER['REQUEST_URI']) ?>">
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
                        <button type="submit">Salvează Modificări</button>
                    </div>
                </form>
            </div>
        </div>
        <div class="main-content" data-aos="slide-up">
            <div class="filters">
                <form method="GET" action="dashboard.php">
                    <input type="hidden" name="return" value="<?= htmlspecialchars($_SERVER['REQUEST_URI']) ?>">

                    <div class="filter-group">
                        <label>Status:</label>
                        <select id="status_filter" name="status_filter">
                            <option value="">Active</option>
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
                            foreach ($operators as $user) {
                                $selected = ($assigned_filter == $user['user_id']) ? 'selected' : '';
                                echo "<option value='" . $user['user_id'] . "' $selected>" . $user['username'] . "</option>";
                            }
                            ?>
                        </select>
                    </div>

                    <div class="filter-group">
                        <label>Client:</label>
                        <select id="client_filter" name="client_filter">
                            <option value="">Toți</option>
                        </select>
                    </div>

                    <div class="filter-group">
                        <label>Sortare</label>
                        <div class="sort-arrows">
                            <i class="fa-solid fa-arrow-up arrow" data-value="ASC"></i>
                            <i class="fa-solid fa-arrow-down arrow" data-value="DESC"></i>
                            <input type="hidden" id="sort_order" name="sort_order" value="<?php echo $sort_order; ?>">
                        </div>
                    </div>

                    <div>
                        <button type="submit" class="hidden-submit">Aplică filtre</button>
                        <button type="button" id="resetFiltersBtn">Resetează filtre</button>
                    </div>
                </form>
            </div>

            <div class="table-responsive">
                <table>
                    <thead>
                        <tr class="table-head-row--hidden">
                            <th>Nr. Comanda</th>
                            <th>Client</th>
                            <th>Info Comandă</th>
                            <th>Din data</th>
                            <th>Dată livrare</th>
                            <th>Operator</th>
                            <th>Status</th>
                        </tr>
                    </thead>
                    <tbody class="styled-table-body">
                        <?php
                        if ($orders_result->num_rows > 0) {
                            while ($row = $orders_result->fetch_assoc()) {
                                $order_id = str_pad($row["order_id"], 3, '0', STR_PAD_LEFT);
                                $order_date = formatDateWithoutYearWithDay($row["order_date"]) . ' ' . date('H:i', strtotime($row["order_time"]));
                                $due_date = formatRemainingDays($row["due_date"], $row["status"], $row["delivery_date"] ?? null);
                                $status_db = $row["status"] ?? 'neatribuită';

                                // 1. Calculăm dacă termenul este depășit
                                $is_overdue = false;
                                if (!empty($row['due_date'])) {
                                    $clean_date = date('Y-m-d', strtotime($row['due_date']));
                                    $deadline_timestamp = strtotime($clean_date . ' 23:59:59');

                                    if (time() > $deadline_timestamp && $status_db !== 'completed' && $status_db !== 'delivered') {
                                        $is_overdue = true;
                                    }
                                }

                                // 2. Heavy Theme Logic (CMYK Print Inspired)
                                $theme_class = 'theme-default';
                                $status_content = '';

                                if ($is_overdue) {
                                    $theme_class = 'theme-magenta'; // Urgent/Overdue
                                    $icon = ($row["assigned_to"] == $_SESSION['user_id']) ? '<i class="fas fa-star"></i>' : '<i class="fa-solid fa-person-digging"></i>';
                                    $status_content = $icon . ' Întârziată';
                                } elseif ($row["assigned_to"] == $_SESSION['user_id'] && $status_db != 'completed' && $status_db != 'delivered') {
                                    $status_content = '<i class="fas fa-star"></i> În lucru';
                                    $theme_class = 'theme-yellow'; // Current User
                                } elseif ($status_db != "completed" && $status_db != "delivered") {
                                    $status_content = '<i class="fa-solid fa-person-digging"></i> Atribuită';
                                    $theme_class = 'theme-cyan'; // Assigned
                                } elseif ($status_db == 'completed') {
                                    $status_content = '<i class="fas fa-flag-checkered"></i> Finalizată';
                                    $theme_class = 'theme-green'; // Completed
                                } else {
                                    $status_content = 'Livrată';
                                    $theme_class = 'theme-key'; // Delivered (Grey/Black)
                                }

                                $due_date_display = $is_overdue ? "<span class='text-magenta'>$due_date</span>" : $due_date;

                                // 3. Randarea rândului cu clasele noi
                                echo "<tr class='order-row heavy-row $theme_class' data-order-id='{$row["order_id"]}' onclick=\"window.location.href='view_order.php?order_id={$row["order_id"]}&return=" . urlencode($_SERVER['REQUEST_URI']) . "'\">";

                                echo "<td data-label='Nr. Comanda'><strong>#$order_id</strong></td>";
                                echo "<td data-label='Client'><span class='client-text'>" . htmlspecialchars($row["client_name"]) . "</span></td>";
                                echo "<td data-label='Info Comandă'><span class='details-text'>" . htmlspecialchars($row["order_details"]) . "</span></td>";
                                echo "<td data-label='Din data'><div class='date-badge'><i class='fa-regular fa-calendar'></i> $order_date</div></td>";
                                echo "<td data-label='Dată livrare'><div class='date-badge'><i class='fa-regular fa-clock'></i> $due_date_display</div></td>";
                                echo "<td data-label='Operator'><strong>" . htmlspecialchars($row["assigned_user"]) . "</strong></td>";

                                // Heavy styling pe pilula de status
                                echo "<td data-label='Status'><span class='heavy-pill'>" . $status_content . "</span></td>";

                                echo "</tr>";
                            }
                        } else {
                            echo "<tr><td colspan='7' class='empty-state-cell'>Nu există comenzi.</td></tr>";
                        }
                        ?>
                    </tbody>
                </table>
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
    </div>


    <div id="whatsappModal" class="modal">
        <div class="modal-content whatsapp-modal">

            <header class="whatsapp-header">
                <h4><i class="fa-brands fa-whatsapp"></i> WhatsApp Sender</h4>
                <button class="whatsapp-close-btn"><i class="fa-solid fa-circle-xmark"></i></button>
            </header>

            <div class="whatsapp-body">

                <label>Prefix țară</label>
                <div class="prefix-row">
                    <select id="countryPrefixSelect">
                        <option value="40" selected>🇷🇴 România (+40)</option>
                        <option value="39">🇮🇹 Italia (+39)</option>
                        <option value="34">🇪🇸 Spania (+34)</option>
                        <option value="44">🇬🇧 UK (+44)</option>
                        <option value="49">🇩🇪 Germania (+49)</option>
                        <option>Manual</option>
                    </select>

                    <input type="text" id="manualPrefix" placeholder="+40">
                </div>

                <label>Număr telefon</label>
                <input type="text" id="whatsappNumber" placeholder="Ex: 723456789">

                <button id="sendWhatsappBtn" class="btn-primary">
                    <svg width="18" height="18" viewBox="0 0 32 32" fill="white">
                        <path d="M16.04 2.003c-7.732 0-14 6.268-14 14 0 2.47.646 4.883 1.873 7.01L2 30l7.17-1.844A13.94 13.94 0 0 0 16.04 30c7.732 0 14-6.268 14-14s-6.268-14-14-14zm0 25.5c-2.27 0-4.49-.61-6.43-1.77l-.46-.27-4.25 1.09 1.13-4.14-.3-.48A11.46 11.46 0 0 1 4.54 16c0-6.33 5.17-11.5 11.5-11.5S27.54 9.67 27.54 16 22.37 27.5 16.04 27.5zm6.36-8.63c-.35-.18-2.06-1.02-2.38-1.14-.32-.12-.55-.18-.78.18-.23.35-.9 1.14-1.1 1.37-.2.23-.4.26-.75.09-.35-.18-1.48-.55-2.82-1.76-1.04-.93-1.74-2.08-1.94-2.43-.2-.35-.02-.54.15-.71.15-.15.35-.4.52-.6.17-.2.23-.35.35-.58.12-.23.06-.43-.03-.6-.09-.18-.78-1.88-1.07-2.57-.28-.69-.57-.6-.78-.61-.2-.01-.43-.01-.66-.01-.23 0-.6.09-.91.43-.32.35-1.2 1.17-1.2 2.85 0 1.68 1.23 3.3 1.4 3.53.17.23 2.42 3.7 5.86 5.18 3.44 1.48 3.44.99 4.06.93.62-.06 2.06-.84 2.35-1.65.29-.81.29-1.51.2-1.65-.09-.14-.32-.23-.66-.4z" />
                    </svg>
                    Trimite pe WhatsApp
                </button>

            </div>
        </div>
    </div>

    <footer class="dashboard-footer">
        <!-- Left Side: Logo & System Status -->
        <div class="footer-brand">
            <a href="dashboard.php" class="footer-logo-wrapper">
                <object
                    type="image/svg+xml"
                    data="https://color-print.ro/magazincp/comenzi.svg"
                    class="footer-logo-object">
                </object>
            </a>
            <span class="operator-context">
                <i class="fa-solid fa-circle-user operator-icon"></i>
                <span class="operator-label">Operator:</span>
                <span class="operator-name">
                    <?php
                    // Safely output the logged-in user's name, or default to 'Necunoscut' (Unknown)
                    echo isset($_SESSION['username']) ? htmlspecialchars($_SESSION['username']) : 'Necunoscut';
                    ?>
                </span>
            </span>
        </div>

        <!-- Right Side: Action Links -->
        <div class="footer-links">
            <a href="archive.php" class="footer-link">
                <i class="fa-solid fa-box-archive"></i> Arhivă
            </a>
            <a href="unpaid_orders.php" class="footer-link">
                <i class="fa-solid fa-ban"></i> Comenzi nefacturate
            </a>
        </div>
    </footer>

    <!-- Off-Canvas Order Slider Panel & Backdrop -->
    <div id="orderSliderBackdrop"></div>
    <div id="orderSliderPanel">
        <div class="order-slider-header">
            <h3><i class="fa-solid fa-file-invoice"></i> Detalii Comandă</h3>
            <button class="order-slider-close" id="closeOrderSlider">&times;</button>
        </div>
        <div class="order-slider-body">
            <iframe id="orderSliderIframe" src=""></iframe>
        </div>
    </div>

</body>

</html>