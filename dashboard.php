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

// Construim query-ul pentru selectarea comenzilor cu filtre și sortare
$order_sql = "SELECT o.*, c.client_name, u.username as assigned_user, cat.category_name, o.delivery_date FROM orders o 
              JOIN clients c ON o.client_id = c.client_id 
              LEFT JOIN users u ON o.assigned_to = u.user_id 
              LEFT JOIN categories cat ON o.category_id = cat.category_id 
              WHERE 1=1"; // WHERE 1=1 pentru a putea adăuga condiții dinamice

// Inițializăm variabile pentru parametrii și tipurile lor (pentru prepared statements)
$total_params = [];
$total_types = '';
$params = [];
$types = '';

// Excludem comenzile cu status 'delivered' și 'cancelled' în mod implicit
if ($status_filter !== 'delivered' && $status_filter !== 'cancelled') {
    $order_sql .= " AND o.status NOT IN ('delivered', 'cancelled') ";
}

// Adăugăm filtre dinamice în funcție de parametrii primiți
if ($status_filter) {
    $order_sql .= " AND o.status = ?";
    $params[] = $status_filter;
    $types .= 's'; // string
}
if ($assigned_filter) {
    $order_sql .= " AND o.assigned_to = ?";
    $params[] = $assigned_filter;
    $types .= 'i'; // integer
}
if ($category_filter) {
    $order_sql .= " AND o.category_id = ?";
    $params[] = $category_filter;
    $types .= 'i';
}
if ($client_filter) {
    $order_sql .= " AND o.client_id = ?";
    $params[] = $client_filter;
    $types .= 'i';
}

// Adăugăm sortarea și paginarea
// Override sorting when filtering delivered orders
if ($status_filter === 'delivered') {
    $order_sql .= " ORDER BY o.delivery_date $sort_order";
} else {
    $order_sql .= " ORDER BY o.order_id $sort_order";
}

$order_sql .= " LIMIT ? OFFSET ?";
$params[] = $limit;
$params[] = $offset;
$types .= 'ii'; // integer, integer

// Pregătim și executăm query-ul pentru comenzile filtrate
$stmt = $conn->prepare($order_sql);
$stmt->bind_param($types, ...$params);
$stmt->execute();
$orders_result = $stmt->get_result();

// Construim query-ul pentru numărul total de comenzi (pentru paginare)
$total_orders_sql = "SELECT COUNT(*) as total 
FROM orders o
JOIN clients c ON o.client_id = c.client_id
LEFT JOIN users u ON o.assigned_to = u.user_id
LEFT JOIN categories cat ON o.category_id = cat.category_id
WHERE 1=1";

if ($status_filter !== 'delivered' && $status_filter !== 'cancelled') {
    $total_orders_sql .= " AND o.status NOT IN ('delivered', 'cancelled')";
}
if ($status_filter) {
    $total_orders_sql .= " AND o.status = ?";
    $total_params[] = $status_filter;
    $total_types .= 's';
}
if ($assigned_filter) {
    $total_orders_sql .= " AND o.assigned_to = ?";
    $total_params[] = $assigned_filter;
    $total_types .= 'i';
}
if ($category_filter) {
    $total_orders_sql .= " AND o.category_id = ?";
    $total_params[] = $category_filter;
    $total_types .= 'i';
}
if ($client_filter) {
    $total_orders_sql .= " AND o.client_id = ?";
    $total_params[] = $client_filter;
    $total_types .= 'i';
}

// Pregătim și executăm query-ul pentru numărul total de comenzi
$total_stmt = $conn->prepare($total_orders_sql);
if (!empty($total_types)) {
    $total_stmt->bind_param($total_types, ...$total_params);
}

$total_stmt->execute();
$total_result = $total_stmt->get_result();
$total_orders = $total_result->fetch_assoc()['total']; // Extragem numărul total
$total_stmt->close();

// Calculăm numărul total de pagini
$total_pages = ceil($total_orders / $limit);

// --- STATISTICI RAPIDE PENTRU CARDS ---

// 1. Număr comenzi cu Termen Depășit (Strict cele cu status 'assigned' care au depășit data curentă)
$stats_overdue_sql = "SELECT COUNT(*) as total 
                      FROM orders 
                      WHERE status = 'assigned' 
                      AND due_date < CURDATE() ";
$stats_overdue_res = $conn->query($stats_overdue_sql);
$stats_overdue = $stats_overdue_res ? $stats_overdue_res->fetch_assoc()['total'] : 0;

// 2. Număr comenzi În Lucru / Atribuite (active, dar care nu sunt marcate ca finalizate sau livrate)
$stats_active_sql = "SELECT COUNT(*) as total FROM orders WHERE status = 'assigned'";
$stats_active_res = $conn->query($stats_active_sql);
$stats_active = $stats_active_res ? $stats_active_res->fetch_assoc()['total'] : 0;

// 3. Număr comenzi Finalizate (Include toate comenzile gata, chiar dacă au termenul depășit în trecut)
$stats_completed_sql = "SELECT COUNT(*) as total 
                        FROM orders 
                        WHERE status = 'completed'";
$stats_completed_res = $conn->query($stats_completed_sql);
$stats_completed = $stats_completed_res ? $stats_completed_res->fetch_assoc()['total'] : 0;

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
    <link rel="stylesheet" type="text/css" href="styles.css">
    <link rel="stylesheet" type="text/css" href="style.css">
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
    <!-- Initialize Select2 lybrary -->
    <script>
        document.addEventListener('DOMContentLoaded', function() {
            // Initialize Select2 on select elements
            $(document).ready(function() {
                $('#status_filter, #assigned_filter, #category_filter, #assigned_to, #category_id').select2({
                    dropdownAutoWidth: true,
                    width: 'auto'
                });
            });

            $(function() {
                $('#noteReceiver').select2({
                    dropdownParent: $('#notesModal'),
                    width: '200px',
                    placeholder: "Alege colegul",
                    allowClear: true
                });
            });

            $('#client_filter').select2({
                dropdownAutoWidth: true,
                width: 'auto',
                placeholder: 'Toți',
                allowClear: true,
                ajax: {
                    url: 'fetch_clients.php',
                    dataType: 'json',
                    delay: 250,
                    data: function(params) {
                        return {
                            search_clients: 1,
                            q: params.term
                        };
                    },
                    processResults: function(data) {
                        return {
                            results: data
                        };
                    },
                    cache: true
                },
                templateResult: formatClient,
                templateSelection: formatClientSelection
            });

            $('#client_id').select2({
                dropdownAutoWidth: true,
                width: 'auto',
                placeholder: 'Nume sau telefon client',
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
                    $('#new_client_fields').addClass('collapsed');
                    $('#edit_client_button').show();
                } else {
                    $('#new_client_fields').removeClass('collapsed');
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
                        Toast.fire({
                            icon: 'success',
                            title: 'Client actualizat!'
                        });
                        $('#editClientModal').css('display', 'none');
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

        // Delay Select2 close to allow exit animation to play
        $(document).on('select2:closing', function(e) {
            var $dropdown = $('.select2-dropdown');

            if (!$dropdown.hasClass('is-closing')) {
                e.preventDefault(); // Prevent immediate removal
                $dropdown.addClass('is-closing');

                setTimeout(function() {
                    var $target = $(e.target);
                    $target.select2('close'); // Complete close action
                }, 750); // 750ms matches the exit animation duration
            } else {
                // Reset class once fully closed for future opens
                $dropdown.removeClass('is-closing');
            }
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

    <script>
        document.addEventListener('DOMContentLoaded', function() {
            const sliderPanel = document.getElementById('orderSliderPanel');
            const sliderBackdrop = document.getElementById('orderSliderBackdrop');
            const sliderIframe = document.getElementById('orderSliderIframe');
            const closeSliderBtn = document.getElementById('closeOrderSlider');
            const sliderTitle = sliderPanel.querySelector('.order-slider-header h3');

            // --- 1. SLIDER LOGIC FOR ORDERS ---
            function openOrderSlider(orderId) {
                if (sliderTitle) {
                    sliderTitle.innerHTML = '<i class="fa-solid fa-file-invoice"></i> Detalii Comandă';
                }
                sliderBackdrop.style.display = 'block';
                sliderIframe.src = 'view_order.php?order_id=' + orderId + '&embedded=1';

                setTimeout(() => {
                    sliderPanel.classList.add('open');
                    sliderBackdrop.classList.add('open');
                }, 10);

                document.body.style.overflow = 'hidden';
            }

            // --- NEW: SLIDER LOGIC FOR STATISTICS ---
            function openStatsSlider() {
                if (sliderTitle) {
                    sliderTitle.innerHTML = '<i class="fa-solid fa-chart-line"></i> Statistici Comenzi';
                }
                sliderBackdrop.style.display = 'block';
                sliderIframe.src = 'statistics.php?embedded=1';

                setTimeout(() => {
                    sliderPanel.classList.add('open');
                    sliderBackdrop.classList.add('open');
                }, 10);

                document.body.style.overflow = 'hidden';
            }

            // Expose globally so other scripts / inline HTML can access them
            window.openOrderSlider = openOrderSlider;
            window.openStatsSlider = openStatsSlider;

            function closeOrderSlider() {
                sliderPanel.classList.remove('open');
                sliderBackdrop.classList.remove('open');
                document.body.style.overflow = '';

                setTimeout(() => {
                    sliderIframe.src = '';
                    sliderBackdrop.style.display = 'none';

                    // Trigger the quiet refresh instead of a full page reload
                    quietRefresh();
                }, 1400);
            }

            closeSliderBtn.addEventListener('click', closeOrderSlider);
            sliderBackdrop.addEventListener('click', closeOrderSlider);

            document.addEventListener('keydown', function(e) {
                // 1. Existing ESC key logic
                if (e.key === 'Escape' && sliderPanel.classList.contains('open')) {
                    closeOrderSlider();
                }

                // 2. Intercept Ctrl+P (or Cmd+P on Mac) when the slider is open
                if ((e.ctrlKey || e.metaKey) && e.key.toLowerCase() === 'p') {
                    if (sliderPanel.classList.contains('open')) {
                        e.preventDefault(); // Stop browser from printing the main dashboard page

                        try {
                            const iframeWindow = sliderIframe.contentWindow;
                            const iframeDoc = iframeWindow.document;

                            // Adjust the CSS selector below (#printBtn or .print-button) to match your button in view_order.php
                            const printButton = iframeDoc.querySelector('#printBtn') || iframeDoc.querySelector('.print-button');

                            if (printButton) {
                                printButton.click(); // Trigger the exact button click logic
                            } else {
                                iframeWindow.print(); // Fallback to direct iframe window print
                            }
                        } catch (err) {
                            console.error("Could not trigger iframe print:", err);
                        }
                    }
                }
            });

            // --- 2. EVENT BINDING ---
            // Grouped into a function so it can be re-run after fetching new HTML
            function bindOrderClickEvents() {
                document.querySelectorAll('.order-row').forEach(row => {
                    row.removeAttribute('onclick');
                    row.style.cursor = 'pointer';

                    row.addEventListener('click', function(e) {
                        e.preventDefault();
                        const orderId = this.getAttribute('data-order-id');
                        if (orderId) openOrderSlider(orderId);
                    });
                });

                document.querySelectorAll('.pinned-section a').forEach(link => {
                    link.addEventListener('click', function(e) {
                        e.preventDefault();
                        const href = this.getAttribute('href');
                        const urlParams = new URLSearchParams(href.split('?')[1]);
                        const orderId = urlParams.get('order_id');
                        if (orderId) openOrderSlider(orderId);
                    });
                });
            }

            // Bind events initially on page load
            bindOrderClickEvents();

            // --- 3. QUIET REFRESH (AJAX) ---
            // Shared by two callers: the slider-close refresh below (same URL, and it
            // SHOULD reset the "add order" sidebar form), and the filters/sort/pagination
            // script further down the page (a NEW url from the address bar's point of
            // view, and it should NOT reset the sidebar form — someone could be mid-way
            // through drafting a new order while just browsing/filtering the table).
            let refreshInProgress = false;

            function quietRefresh(targetUrl, {
                resetForm = true
            } = {}) {
                if (refreshInProgress) return Promise.resolve();
                refreshInProgress = true;
                const url = targetUrl || window.location.href;

                return fetch(url)
                    .then(response => response.text())
                    .then(html => {
                        const parser = new DOMParser();
                        const doc = parser.parseFromString(html, 'text/html');

                        // Runs FIRST, unconditionally — nothing below can ever block this again
                        if (resetForm) resetOrderForm();

                        const sectionsToUpdate = ['.pinned-section', '.main-content tbody', '.pagination'];

                        let rowDirections = new Map();
                        try {
                            rowDirections = computeRowDirections(
                                document.querySelector('.main-content tbody'),
                                doc.querySelector('.main-content tbody')
                            );
                        } catch (err) {
                            console.error('Row direction calc failed:', err);
                        }

                        try {
                            animateStatCards(document.querySelector('.stats-banner'), doc.querySelector('.stats-banner'));
                        } catch (err) {
                            console.error('Stat animation failed:', err);
                        }

                        const applyUpdate = () => {
                            sectionsToUpdate.forEach(selector => {
                                const currentSection = document.querySelector(selector);
                                const updatedSection = doc.querySelector(selector);
                                if (currentSection && updatedSection) {
                                    currentSection.innerHTML = updatedSection.innerHTML;
                                }
                            });

                            // Sweep-highlight rows that changed rank (e.g. after a sort
                            // change) using the .row-moved-up/-down CSS that already existed
                            // but was never actually applied to any element.
                            rowDirections.forEach((direction, orderId) => {
                                const row = document.querySelector(`.main-content tbody tr[data-order-id="${orderId}"]`);
                                if (row) {
                                    row.classList.add(direction === 'up' ? 'row-moved-up' : 'row-moved-down');
                                    setTimeout(() => row.classList.remove('row-moved-up', 'row-moved-down'), 750);
                                }
                            });

                            tagElementsForTransition();
                            bindOrderClickEvents();
                            if (typeof window.initTippy === 'function') window.initTippy();
                            // Pagination links live inside the section we just replaced, so
                            // any listeners on the old <a> tags are gone — rebind if present.
                            if (typeof window.bindPaginationClickEvents === 'function') window.bindPaginationClickEvents();
                        };

                        try {
                            if (document.startViewTransition) {
                                tagElementsForTransition();
                                const transition = document.startViewTransition(applyUpdate);
                                transition.finished
                                    .catch(() => {}) // an interrupted transition isn't an error worth surfacing
                                    .finally(() => {
                                        clearTransitionNames();
                                        refreshInProgress = false;
                                    });
                            } else {
                                applyUpdate();
                                refreshInProgress = false;
                            }
                        } catch (err) {
                            console.error('View transition failed, applying update directly:', err);
                            applyUpdate();
                            refreshInProgress = false;
                        }

                        // Keep the address bar (and reload/back-button behavior) in sync
                        // with whatever is now on screen.
                        if (url !== window.location.href) {
                            history.pushState({
                                quietNav: true
                            }, '', url);
                        }
                    })
                    .catch(error => {
                        console.error('Eroare la quiet refresh:', error);
                        refreshInProgress = false;
                    });
            }

            // Expose globally so the filters/sort/pagination script (further down the
            // page) can trigger the same refresh instead of a full navigation.
            window.quietRefresh = quietRefresh;

            // Back/forward after a quiet filter/sort/page change should re-render too —
            // pushState alone only updates the address bar, not the page content.
            window.addEventListener('popstate', function() {
                quietRefresh(window.location.href, {
                    resetForm: false
                });
            });

            function resetOrderForm() {
                const orderForm = document.getElementById('orderForm');
                if (orderForm) {
                    // 1. Reset standard form fields (restores native <select> elements to their defaults)
                    orderForm.reset();

                    if (typeof jQuery !== 'undefined') {
                        // 2. Clear the AJAX client search completely
                        if ($('#client_id').length) {
                            $('#client_id').val(null).trigger('change');
                        }

                        // 3. Sync the Select2 UI for Date and Operator to reflect the native form reset
                        $('#datePickerSelect, #assigned_to').trigger('change');
                    }
                }
            }

            function tagElementsForTransition() {
                document.querySelectorAll('.main-content tbody tr.order-row[data-order-id]').forEach(row => {
                    row.style.viewTransitionName = `order-row-${row.dataset.orderId}`;
                });
            }

            function clearTransitionNames() {
                document.querySelectorAll('[style*="view-transition-name"]').forEach(el => {
                    el.style.viewTransitionName = '';
                });
            }

            function computeRowDirections(currentSection, updatedSection) {
                const directions = new Map();
                if (!currentSection || !updatedSection) return directions;

                const oldIds = Array.from(currentSection.querySelectorAll('tr.order-row[data-order-id]')).map(r => r.dataset.orderId);
                const newIds = Array.from(updatedSection.querySelectorAll('tr.order-row[data-order-id]')).map(r => r.dataset.orderId);

                oldIds.forEach((id, oldIndex) => {
                    const newIndex = newIds.indexOf(id);
                    if (newIndex === -1) return;
                    if (newIndex < oldIndex) directions.set(id, 'up');
                    else if (newIndex > oldIndex) directions.set(id, 'down');
                });

                return directions;
            }

            // --- Stat cards: odometer-style digit roll ---

            function animateStatCards(currentSection, updatedSection) {
                if (!currentSection || !updatedSection) return;
                const keys = ['card-overdue', 'card-active', 'card-completed'];

                keys.forEach(key => {
                    const numberEl = currentSection.querySelector(`.${key} h3`);
                    const newNumberEl = updatedSection.querySelector(`.${key} h3`);
                    if (!numberEl || !newNumberEl) return;

                    const oldValue = parseInt(numberEl.dataset.value ?? numberEl.textContent, 10) || 0;
                    const newValue = parseInt(newNumberEl.textContent, 10) || 0;
                    numberEl.dataset.value = newValue; // canonical value, independent of DOM structure

                    if (oldValue === newValue) return; // untouched — no animation

                    const cardEl = numberEl.closest('.stat-card');
                    const direction = newValue > oldValue ? 'up' : 'down';

                    cardEl.classList.add(`stat-${direction}`);
                    setTimeout(() => cardEl.classList.remove('stat-up', 'stat-down'), 700);
                    rollOdometer(numberEl, oldValue, newValue, direction);
                });
            }

            function rollOdometer(container, oldValue, newValue, direction) {
                const maxLen = Math.max(String(oldValue).length, String(newValue).length);
                const oldDigits = String(oldValue).padStart(maxLen, '0').split('');
                const newDigits = String(newValue).padStart(maxLen, '0').split('');

                container.innerHTML = '';

                newDigits.forEach((newDigit, i) => {
                    const oldDigit = oldDigits[i];
                    const slot = document.createElement('span');
                    slot.className = 'digit-slot';

                    if (oldDigit === newDigit) {
                        slot.innerHTML = `<span class="digit-inner">${newDigit}</span>`;
                    } else {
                        slot.innerHTML = `
                <span class="digit-inner digit-out digit-${direction}">${oldDigit}</span>
                <span class="digit-inner digit-in digit-${direction}">${newDigit}</span>
            `;
                        setTimeout(() => {
                            slot.innerHTML = `<span class="digit-inner">${newDigit}</span>`; // settle for clean future reads
                        }, 420);
                    }
                    container.appendChild(slot);
                });
            }
        });
    </script>

    <style>
        /* Ascunde scrollbar-ul */
        html,
        body,
        #orderSliderPanel,
        .order-slider-body,
        #orderSliderIframe {
            -ms-overflow-style: none !important;
            /* IE and Edge */
            scrollbar-width: none !important;
            /* Firefox */
        }

        html::-webkit-scrollbar,
        body::-webkit-scrollbar,
        #orderSliderPanel::-webkit-scrollbar,
        .order-slider-body::-webkit-scrollbar,
        #orderSliderIframe::-webkit-scrollbar {
            display: none !important;
            /* Chrome, Safari and Opera */
        }

        /* Replace ::view-transition-group(*) with this: */
        ::view-transition-group(:not(root)) {
            animation-duration: 420ms;
            animation-timing-function: cubic-bezier(0.34, 1.56, 0.64, 1);
        }

        /* Keep the root transition instantaneous/smooth without elastic bounce */
        ::view-transition-group(root) {
            animation-duration: 250ms;
            animation-timing-function: ease-out;
        }

        /* row motion */
        .order-row.row-moved-up {
            --tint: rgba(64, 145, 235, 0.28);
        }

        .order-row.row-moved-down {
            --tint: rgba(235, 155, 64, 0.28);
        }

        /* 3. Build the sweep as an overlay element */
        /* Target the cells (td) inside the animated rows */
        .order-row.row-moved-up td::after,
        .order-row.row-moved-down td::after {
            content: "";
            position: absolute;
            top: 0;
            left: 0;

            /* Make the overlay cover the full cell */
            width: 100%;
            height: 100%;

            background: linear-gradient(90deg, transparent, var(--tint), transparent);
            pointer-events: none;

            transform: translateX(-100%);
            animation: gpu-row-sweep 300ms ease-in forwards;
        }

        /* 4. Animate ONLY GPU-friendly properties (transform and opacity) */
        @keyframes gpu-row-sweep {
            0% {
                transform: translateX(-100%);
                opacity: 0;
            }

            100% {
                /* Sweep finishes completely off-screen to the right */
                transform: translateX(100%);
                opacity: 0;
            }
        }

        /* Filters toolbar: subtle cue while a quiet (AJAX) refresh is in flight */
        .filters.is-loading {
            opacity: 0.6;
            pointer-events: none;
            transition: opacity 150ms ease;
        }

        /* stat card odometer */
        .stat-card h3 {
            display: inline-flex;
        }

        .digit-slot {
            position: relative;
            display: inline-block;
            width: 0.62em;
            height: 1.1em;
            overflow: hidden;
            vertical-align: bottom;
        }

        .digit-inner {
            position: absolute;
            inset: 0;
            text-align: center;
        }

        .digit-out.digit-up {
            animation: roll-out-up 400ms cubic-bezier(.3, 0, .2, 1) forwards;
        }

        .digit-in.digit-up {
            animation: roll-in-up 400ms cubic-bezier(.3, 0, .2, 1) forwards;
        }

        .digit-out.digit-down {
            animation: roll-out-down 400ms cubic-bezier(.3, 0, .2, 1) forwards;
        }

        .digit-in.digit-down {
            animation: roll-in-down 400ms cubic-bezier(.3, 0, .2, 1) forwards;
        }

        @keyframes roll-out-up {
            to {
                transform: translateY(-100%);
                opacity: 0;
            }
        }

        @keyframes roll-in-up {
            from {
                transform: translateY(100%);
                opacity: 0;
            }

            to {
                transform: translateY(0);
                opacity: 1;
            }
        }

        @keyframes roll-out-down {
            to {
                transform: translateY(100%);
                opacity: 0;
            }
        }

        @keyframes roll-in-down {
            from {
                transform: translateY(-100%);
                opacity: 0;
            }

            to {
                transform: translateY(0);
                opacity: 1;
            }
        }

        /* Animated expand/collapse for the "new client" fields.
           Uses the grid-rows trick instead of display:none so the
           height itself can transition (display can't be animated). */
        #new_client_fields.collapsible {
            display: grid;
            grid-template-rows: 1fr;
            opacity: 1;
            transition:
                grid-template-rows 600ms cubic-bezier(0.4, 0, 0.2, 1),
                opacity 260ms ease,
                margin 600ms cubic-bezier(0.4, 0, 0.2, 1);
        }

        #new_client_fields.collapsible.collapsed {
            grid-template-rows: 0fr;
            opacity: 0;
            margin-top: 0;
            margin-bottom: 0;
            pointer-events: none;
        }

        #new_client_fields .collapsible-inner {
            overflow: hidden;
            min-height: 0;
        }

        @media (prefers-reduced-motion: reduce) {
            #new_client_fields.collapsible {
                transition: none;
            }
        }

        /* Prevent floating widgets from flashing during View Transitions */
        #notesFab {
            view-transition-name: notes-widget;
        }

        #whatsappWidget {
            view-transition-name: whatsapp-widget;
        }

        #header {
            view-transition-name: main-header;
        }
    </style>

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

        /* Remove scrollbar (except header order search dropdown) */
        .select2-container--default .select2-results {
            overflow-y: hidden !important;
            /* Remove vertical scrollbar */
            max-width: 100% !important;
            /* Ensure dropdown is wide enough */
        }

        .select2-dropdown.header-order-search .select2-results {
            overflow-y: auto !important;
        }

        .select2-container--default .select2-results__options {
            max-width: 100% !important;
            /* Ensure options are wide enough */
        }

        /* Animate Select2 dropdown opening */
        /* Animate Select2 dropdown opening */
        .select2-container--open .select2-dropdown {
            animation: select2DropdownOpen 0.75s cubic-bezier(0.16, 1, 0.3, 1) forwards;
            transform-origin: top center;
        }

        /* Animate Select2 dropdown closing */
        .select2-dropdown.is-closing {
            animation: select2DropdownClose 0.75s cubic-bezier(0.16, 1, 0.3, 1) forwards !important;
        }

        @keyframes select2DropdownOpen {
            0% {
                opacity: 0;
                transform: translateY(-8px) scaleY(0.96);
            }

            100% {
                opacity: 1;
                transform: translateY(0) scaleY(1);
            }
        }

        @keyframes select2DropdownClose {
            0% {
                opacity: 1;
                transform: translateY(0) scaleY(1);
            }

            100% {
                opacity: 0;
                transform: translateY(-8px) scaleY(0.96);
            }
        }
    </style>

    <!-- Sweet alert -->
    <style>
        /* Butoane */
        .swal2-styled.swal2-confirm {
            background: yellow !important;
            /* gold */
            color: #000 !important;
            border: none !important;
            border-radius: 4px;
            font-weight: 600;
        }

        .swal2-styled.swal2-cancel {
            background: #555 !important;
            /* gri neutru */
            color: #fff !important;
            border: none !important;
            border-radius: 4px;
        }

        /* Acțiuni */
        .swal2-actions {
            gap: 10px;
        }

        /* Buton de închidere */
        .swal2-popup .swal2-close {
            color: #555;
        }
    </style>

    <!-- Script for adding new order -->
    <script>
        document.addEventListener('DOMContentLoaded', function() {
            document.getElementById('orderForm').addEventListener('submit', function(event) {
                event.preventDefault(); // Prevent default form submission

                fetch('dashboard.php', {
                        method: 'POST',
                        body: new FormData(this)
                    })
                    .then(response => response.text())
                    .then(data => {
                        if (data.includes('Comanda a fost adăugată cu succes! 🚀 🚀 🚀 ')) {
                            Toast.fire({
                                icon: 'success',
                                title: 'Comanda a fost adăugată!'
                            });
                            this.reset();

                            const match = data.match(/order_id=(\d+)/);
                            const orderId = match ? match[1] : null;

                            if (orderId) {
                                Swal.fire({
                                    icon: 'success',
                                    title: 'Comanda a fost adăugată!',
                                    text: 'Se deschide panoul comenzii...',
                                    showConfirmButton: false,
                                    timer: 1000,
                                    timerProgressBar: true,
                                    position: 'center'
                                }).then(() => {
                                    if (typeof window.openOrderSlider === 'function') {
                                        window.openOrderSlider(orderId);
                                    } else {
                                        // Fallback if slider function is unavailable
                                        const returnUrl = document.querySelector('input[name="return"]').value;
                                        window.location.href = 'view_order.php?order_id=' + orderId +
                                            (returnUrl ? '&return=' + encodeURIComponent(returnUrl) : '');
                                    }
                                });
                            }
                        } else {
                            showAlert({
                                icon: 'error',
                                title: 'Eroare',
                                text: 'Nu s-a putut adăuga comanda: ' + data
                            });
                        }
                    })
                    .catch(error => {
                        console.error('Error:', error);
                        showAlert({
                            icon: 'error',
                            title: 'Eroare de rețea',
                            text: 'A apărut o problemă la procesarea cererii.'
                        });
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
                once: true,
                mirror: false // Start animation on scroll up as well
            });
        });
    </script>

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
                        <select id="order_lookup" style="width:100%;"></select>
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

    <script>
        $(function() {
            var orderLookup = $('#order_lookup');
            if (!orderLookup.length) return;

            function highlightTerm(text, term) {
                if (!text) return '';
                if (!term) return text;
                var escaped = term.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
                var regex = new RegExp('(' + escaped + ')', 'gi');
                return text.replace(regex, '<span class="highlight">$1</span>');
            }

            function getSearchTerm() {
                var s2 = orderLookup.data('select2');
                if (s2 && s2.dropdown && s2.dropdown.$search) {
                    return s2.dropdown.$search.val() || '';
                }
                return '';
            }

            orderLookup.select2({
                placeholder: 'Căutare comandă (nr. comenzii, client, telefon, detalii comandă)...',
                minimumInputLength: 1,
                allowClear: true,
                dropdownParent: $('body'),
                dropdownCssClass: 'header-order-search',
                width: '100%',
                ajax: {
                    url: 'search_orders.php',
                    dataType: 'json',
                    delay: 250,
                    data: function(params) {
                        return {
                            search_orders: 1,
                            q: params.term
                        };
                    },
                    processResults: function(data) {
                        return {
                            results: data
                        };
                    },
                    cache: true
                },
                templateResult: function(order) {
                    if (!order.id) return order.text;
                    var term = getSearchTerm();
                    var phoneLine = order.client_phone ?
                        '<div style="font-size:12px;color:#666;"><i class="fa-solid fa-phone" style="font-size:10px;"></i> ' + highlightTerm(order.client_phone, term) + '</div>' :
                        '';
                    return $(
                        '<div>' +
                        '<div><strong>#' + order.id + '</strong> – ' + highlightTerm(order.client_name, term) + '</div>' +
                        phoneLine +
                        '<div style="font-size:12px;color:#555;">' + highlightTerm(order.order_details, term) + '</div>' +
                        '<div style="font-size:11px;color:#999;">' + highlightTerm(order.detalii_suplimentare, term) + '</div>' +
                        '</div>'
                    );
                },
                templateSelection: function(order) {
                    return order.client_name ? '#' + order.id + ' – ' + order.client_name : order.text;
                },
                escapeMarkup: function(markup) {
                    return markup;
                }
            }).on('select2:select', function(e) {
                var orderId = e.params.data.id;
                if (!orderId) return;

                if (typeof window.openOrderSlider === 'function') {
                    window.openOrderSlider(orderId);
                } else {
                    var returnInput = document.querySelector('#lookupForm input[name="return"]');
                    var returnUrl = returnInput ? returnInput.value : '';
                    window.location.href = 'view_order.php?order_id=' + orderId +
                        (returnUrl ? '&return=' + encodeURIComponent(returnUrl) : '');
                }

                orderLookup.val(null).trigger('change');
            });

            document.addEventListener('keydown', function(e) {
                if ((e.ctrlKey || e.metaKey) && e.key.toLowerCase() === 'f') {
                    e.preventDefault();
                    orderLookup.select2('open');
                }
            });
        });
    </script>

    <div class="image-container" style="width: 100%; height: 300px; position: relative; overflow: hidden;">
        <video autoplay muted loop playsinline
            style="width: 100%; height: 100%; object-fit: cover; display: block; position: relative; z-index: 1;">
            <source src="https://color-print.ro/magazincp/header.mp4" type="video/mp4">
        </video>

        <div class="image-overlay"></div>

        <object data-aos="zoom-in"
            data-aos-easing="linear"
            data-aos-duration="800"
            type="image/svg+xml"
            data="https://color-print.ro/magazincp/comenzi.svg"
            style="width: 50%; height: 50%; position: absolute; top: 25%; left: 25%; z-index: 2; object-fit: contain;">
        </object>
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
    </div>


    <div class="pinned-section" data-aos="fade-in">
        <?php if ($pinned_result && $pinned_result->num_rows > 0): ?>
            <h2 style="margin-left:20px;">📌 Comenzi urgente</h2>
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
            <h2>Adaugă Comandă</h2>
            <form id="orderForm" method="post" action="dashboard.php?<?= htmlspecialchars($_SERVER['QUERY_STRING']) ?>" autocomplete="off">
                <input type="hidden" name="return"
                    value="<?= htmlspecialchars($_SERVER['QUERY_STRING'] ? 'dashboard.php?' . $_SERVER['QUERY_STRING'] : 'dashboard.php') ?>">
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
                <div id="new_client_fields" class="form-group collapsible">
                    <div class="collapsible-inner">
                        <div class="flex-container">
                            <div class="form-group">
                                <label for="client_name"><strong>Nume Client:</strong></label>
                                <input required placeholder="Prenume și Nume" type="text" id="client_name" name="client_name">
                            </div>
                            <div class="form-group">
                                <label for="client_phone"><strong>Telefon Client:</strong></label>
                                <input required placeholder="07XXXXXXXX" type="text" id="client_phone" name="client_phone" pattern="0[0-9]{9}" title="Numărul de telefon trebuie să conțină exact 10 cifre și să înceapă cu 0">
                            </div>
                            <div class="form-group">
                                <label for="client_email">Email Client:</label>
                                <input placeholder="colorprint_roman@yahoo.com" type="email" id="client_email" name="client_email">
                            </div>
                        </div>
                        <button type="button" id="save_edit_button" style="display:none;">Salvează Modificările</button>
                    </div>
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
                    <input placeholder="50% din total" type="number" id="avans" name="avans" max="9999" step="0.01">
                </div>

                <div class="form-group">
                    <label for="datePickerSelect"><strong>Data Livrare:</strong></label>
                    <select id="datePickerSelect" name="due_date"></select>
                </div>

                <div class="form-group" style="display: none;">
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
                        // Exclude Nicolas and Adrian
                        $users_sql = "SELECT user_id, username FROM users WHERE user_id NOT IN (3, 4)";
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
            <table>
                <thead>
                    <div class="filters" style="margin-bottom: 20px;">
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
                                    // Exclude Nicolas and Adrian
                                    $users_sql = "SELECT user_id, username FROM users WHERE user_id NOT IN (3, 4)";
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
                                <label>Client:</label>
                                <select id="client_filter" name="client_filter" style="width: 200px;">
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
                                <button type="submit" style="display: none">Aplică filtre</button>
                                <button type="button" id="resetFiltersBtn">Resetează filtre</button>
                            </div>
                        </form>
                    </div>
                    <tr style="display: none">
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

                            $due_date_display = $is_overdue ? "<span class='text-magenta' style='font-weight: 800;'>$due_date</span>" : $due_date;

                            // 3. Randarea rândului cu clasele noi
                            echo "<tr class='order-row heavy-row $theme_class' data-order-id='{$row["order_id"]}' onclick=\"window.location.href='view_order.php?order_id={$row["order_id"]}&return=" . urlencode($_SERVER['REQUEST_URI']) . "'\">";

                            echo "<td><strong>#$order_id</strong></td>";
                            echo "<td><span class='client-text'>" . htmlspecialchars($row["client_name"]) . "</span></td>";
                            echo "<td><span class='details-text'>" . htmlspecialchars($row["order_details"]) . "</span></td>";
                            echo "<td><div class='date-badge'><i class='fa-regular fa-calendar'></i> $order_date</div></td>";
                            echo "<td><div class='date-badge'><i class='fa-regular fa-clock'></i> $due_date_display</div></td>";
                            echo "<td><strong>" . htmlspecialchars($row["assigned_user"]) . "</strong></td>";

                            // Heavy styling pe pilula de status
                            echo "<td><span class='heavy-pill'>" . $status_content . "</span></td>";

                            echo "</tr>";
                        }
                    } else {
                        echo "<tr><td colspan='7' style='text-align: center; padding: 3rem; background: #fff; border-radius: 12px;'>Nu există comenzi.</td></tr>";
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

    <!-- Floating notes button -->
    <button id="notesFab" class="notes-fab">
        <i class="fa fa-users"></i>
    </button>

    <!-- Notes Modal -->
    <div id="notesModal">
        <div class="notes-modal-content">

            <div class="notes-header">
                <h4><i class="fa-solid fa-note-sticky"></i> Notițe colegi</h4>
                <button class="notes-close-btn" id="notesClose">&times;</button>
            </div>

            <div class="notes-body">

                <label for="noteReceiver">Trimite către:</label>
                <select id="noteReceiver" style="width: 200px;">
                    <option value="">Alege colegul</option>
                    <?php
                    $uid = $_SESSION['user_id'];

                    $users = $conn->query("
    SELECT user_id, username 
    FROM users 
    WHERE user_id NOT IN ($uid, 3, 4)
    ORDER BY username
");
                    while ($u = $users->fetch_assoc()) {
                        echo "<option value='{$u['user_id']}'>{$u['username']}</option>";
                    }
                    ?>
                </select>

                <div class="notes-list">
                    <ul id="notesList"></ul>
                </div>

                <div class="notes-input">
                    <textarea id="noteText" placeholder="Scrie o notiță pentru colegul tău..." rows="6" cols="60"></textarea>
                    <button id="sendNoteBtn">Trimite</button>
                </div>

            </div>
        </div>
    </div>


    <!-- Floating Whatsapp Button -->
    <div id="whatsappWidget" class="floating-widget" title="Trimite mesaj pe WhatsApp">
        <i class="fa-brands fa-whatsapp"></i>
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

    <script>
        window.addEventListener('load', function() {

            const apiUrl = 'notes_api.php';

            const $fab = $('#notesFab');
            const $modal = $('#notesModal');
            const $close = $('#notesClose');
            const $list = $('#notesList');
            const $text = $('#noteText');
            const $receiver = $('#noteReceiver');
            const $send = $('#sendNoteBtn');

            let unreadNotificationShown = false;

            /* -----------------------------------------
               SHOW SWEETALERT NOTIFICATION FOR UNREAD
            ----------------------------------------- */
            function showUnreadNotification(count) {
                Swal.fire({
                    title: 'Mesaje necitite',
                    html: `<b>${count}</b> notițe noi de la colegi`,
                    icon: 'info',
                    showConfirmButton: true,
                    confirmButtonText: 'OK',
                    allowOutsideClick: false,
                    allowEscapeKey: false,
                    backdrop: true
                });
            }

            /* -----------------------------------------
               OPEN MODAL
            ----------------------------------------- */
            $fab.on('click', function() {
                $modal.show();
                loadNotes();

                // Mark all as read
                $.post(apiUrl, {
                    action: 'mark_read'
                });

                // Remove unread alert + close notification
                $fab.removeClass('unread-alert');
                unreadNotificationShown = false;
                Swal.close();
            });

            /* -----------------------------------------
               CLOSE MODAL
            ----------------------------------------- */
            $close.on('click', () => $modal.hide());

            $(window).on('click', function(e) {
                if (e.target === $modal[0]) {
                    $modal.hide();
                }
            });
            /* -----------------------------------------
               SEND NOTE + SWEETALERT
            ----------------------------------------- */
            $send.on('click', function() {
                const content = $text.val().trim();
                const receiverId = $receiver.val();

                if (!receiverId) {
                    Swal.fire('Atenție', 'Alege colegul căruia vrei să îi trimiți notița.', 'warning');
                    return;
                }
                if (!content) return;

                $.post(apiUrl, {
                    action: 'add',
                    content: content,
                    receiver_id: receiverId
                }).done(function(res) {
                    if (res.error) {
                        Swal.fire('Eroare', res.error, 'error');
                        return;
                    }

                    // Golește textarea
                    $text.val('');

                    // Reîncarcă lista
                    loadNotes();

                    // 🔔 AICI apare SweetAlert-ul tău
                    Swal.fire({
                        icon: 'success',
                        title: 'Notiță trimisă!',
                        text: 'Mesajul a fost trimis colegului tău.',
                        timer: 1800,
                        showConfirmButton: false,
                        didOpen: () => {
                            document.querySelector('.swal2-container').style.zIndex = '99999';
                        }
                    });
                });
            });

            /* -----------------------------------------
               LOAD NOTES
            ----------------------------------------- */
            function loadNotes() {
                $.getJSON(apiUrl, {
                    action: 'fetch'
                }).done(function(notes) {

                    $list.empty();

                    if (!Array.isArray(notes) || notes.length === 0) {
                        $list.append('<li>Nu ai notițe.</li>');
                        return;
                    }

                    notes.forEach(function(n) {
                        const li = $('<li></li>');
                        if (parseInt(n.is_read) === 0) li.addClass('unread');

                        const time = n.created_at || '';
                        const sender = n.sender_name || 'Necunoscut';

                        li.html(`
    <div class="note-text">
        <strong>${sender}</strong>
        <span class="note-time">${time}</span><br>
        ${$('<div>').text(n.content).html()}
    </div>
    <span class="delete-note" data-id="${n.note_id}">
        <i class="fa-solid fa-trash"></i>
    </span>
`);
                        $list.append(li);


                    });
                });
            }

            /* -----------------------------------------
               DELETE NOTE (instant + fade-out)
            ----------------------------------------- */
            $(document).on('click', '.delete-note', function() {
                const id = $(this).data('id');
                const li = $(this).closest('li');

                // Fade-out animation
                li.addClass('note-fade-out');

                // Remove from DOM after animation
                setTimeout(() => li.remove(), 350);

                // Delete from DB
                $.post(apiUrl, {
                    action: 'delete',
                    note_id: id
                });
            });

            /* -----------------------------------------
               CHECK UNREAD
            ----------------------------------------- */
            function checkUnread() {
                $.getJSON(apiUrl, {
                    action: 'unread_count'
                }).done(function(res) {
                    const unread = parseInt(res.unread || 0);

                    if (unread > 0) {

                        // FAB flashing
                        $fab.addClass('unread-alert');

                        // Show notification only once
                        if (!unreadNotificationShown) {
                            showUnreadNotification(unread);
                            unreadNotificationShown = true;
                        }

                    } else {
                        // No unread → stop flashing + reset
                        $fab.removeClass('unread-alert');
                        unreadNotificationShown = false;
                    }
                });
            }

            setInterval(checkUnread, 2000);
            checkUnread();

        });
    </script>

    <!-- Whatsapp widget logic -->
    <script>
        document.addEventListener('DOMContentLoaded', function() {

            const widget = document.getElementById('whatsappWidget');
            const modal = document.getElementById('whatsappModal');
            const closeBtn = document.querySelector('.whatsapp-close-btn');
            const sendBtn = document.getElementById('sendWhatsappBtn');

            widget.addEventListener('click', () => {
                modal.style.display = 'flex'; // match Notes modal behavior
            });

            closeBtn.addEventListener('click', () => {
                modal.style.display = 'none';
            });

            // Send button logic
            sendBtn.addEventListener('click', () => {
                const dropdownPrefix = document.getElementById('countryPrefixSelect').value;
                let manualPrefix = document.getElementById('manualPrefix').value.trim();
                let number = document.getElementById('whatsappNumber').value.trim();

                manualPrefix = manualPrefix.replace(/\D/g, '');
                number = number.replace(/\D/g, '');

                if (number.length < 5) {
                    Swal.fire("Eroare", "Numărul introdus nu este valid.", "error");
                    return;
                }

                const prefix = manualPrefix !== "" ? manualPrefix : dropdownPrefix;
                const fullNumber = prefix + number;

                window.open(`https://wa.me/${fullNumber}`, "_blank");
            });

        });

        document.addEventListener('DOMContentLoaded', function() {

            const dropdown = document.getElementById('countryPrefixSelect');
            const manual = document.getElementById('manualPrefix');

            function updatePrefixUI() {
                if (manual.value.trim() !== "") {
                    // Manual prefix is active
                    manual.classList.add("prefix-active");
                    manual.classList.remove("prefix-inactive");

                    dropdown.classList.add("prefix-inactive");
                    dropdown.classList.remove("prefix-active");
                } else {
                    // Dropdown is active
                    dropdown.classList.add("prefix-active");
                    dropdown.classList.remove("prefix-inactive");

                    manual.classList.add("prefix-inactive");
                    manual.classList.remove("prefix-active");
                }
            }

            // Trigger UI update on input
            manual.addEventListener('input', updatePrefixUI);
            dropdown.addEventListener('change', updatePrefixUI);

            // Initial state
            updatePrefixUI();
        });
    </script>

    <script>
        // Simple alert
        function showAlert({
            title = 'Notificare',
            text = '',
            icon = 'info',
            timer = null
        } = {}) {
            return Swal.fire({
                icon,
                title,
                text,
                timer,
                timerProgressBar: !!timer,
                confirmButtonText: 'OK'
            });
        }

        const Toast = Swal.mixin({
            toast: true,
            position: 'center',
            showConfirmButton: false,
            timer: 2500,
            timerProgressBar: true
        });
    </script>

    <!-- TIPPY -->
    <script>
        document.addEventListener("DOMContentLoaded", function() {

            // Wrap Tippy init in a global function
            window.initTippy = function() {
                tippy('.order-row', {
                    allowHTML: true,
                    interactive: true,
                    theme: 'order-preview',
                    placement: 'top',
                    maxWidth: 350,
                    delay: [200, 0],
                    animation: 'shift-away',
                    offset: [0, 10],
                    boundary: 'window', // Keeps the tooltip strictly within the viewport
                    appendTo: document.body,

                    onShow(instance) {
                        const reference = instance.reference;
                        const id = reference.getAttribute('data-order-id');

                        // Fixed min-height container prevents vertical jumping on load
                        instance.setContent('<div style="min-height: 50px; display: flex; align-items: center; justify-content: center;">Loading...</div>');

                        fetch('order_preview.php?id=' + id)
                            .then(res => res.text())
                            .then(html => {
                                instance.setContent(html);
                            })
                            .catch(() => {
                                instance.setContent("Eroare la încărcare");
                            });
                    }
                });
            };

            // Initialize on first load
            window.initTippy();
        });
    </script>

    <!-- Filters sort script -->
    <script>
        document.addEventListener("DOMContentLoaded", function() {

            // Wait for Select2 to finish initializing
            setTimeout(() => {
                const arrows = document.querySelectorAll(".sort-arrows .arrow");
                const hiddenInput = document.getElementById("sort_order");
                const filtersWrapper = document.querySelector(".filters");
                const filterForm = document.querySelector(".filters form");
                const resetBtn = document.getElementById("resetFiltersBtn");

                // Highlight active arrow on load
                arrows.forEach(a => {
                    if (a.dataset.value === hiddenInput.value) {
                        a.classList.add("active");
                    }
                });

                // Reads the filter form's current state into a dashboard.php URL.
                // overrides lets a caller add/replace/remove a param (e.g. page).
                function buildFilterUrl(overrides = {}) {
                    const formData = new FormData(filterForm);
                    const params = new URLSearchParams();

                    for (const [key, value] of formData.entries()) {
                        if (value !== '') params.append(key, value);
                    }

                    Object.entries(overrides).forEach(([key, value]) => {
                        if (value === null || value === '') {
                            params.delete(key);
                        } else {
                            params.set(key, value);
                        }
                    });

                    const query = params.toString();
                    return 'dashboard.php' + (query ? '?' + query : '');
                }

                // Runs the quiet refresh against a given URL, with a small loading
                // cue on the toolbar itself since a network round-trip — even a fast
                // one — isn't literally instant.
                function goQuietly(url) {

                    if (filtersWrapper) filtersWrapper.classList.add('is-loading');

                    window.quietRefresh(url, {
                        resetForm: false
                    }).finally(() => {
                        if (filtersWrapper) filtersWrapper.classList.remove('is-loading');

                        // 4. Afișăm succesul când a terminat, apoi îl închidem
                        Swal.fire({
                            toast: 'true',
                            icon: 'success',
                            title: 'Filtru actualizat',
                            position: 'center',
                            width: 'auto',
                            showConfirmButton: false,
                            timer: 750,
                            backdrop: false
                        });

                    });
                }

                arrows.forEach(arrow => {
                    arrow.addEventListener("click", function() {
                        hiddenInput.value = this.dataset.value;

                        arrows.forEach(a => a.classList.remove("active"));
                        this.classList.add("active");

                        goQuietly(buildFilterUrl());
                    });
                });

                // "Aplică filtre" — stays type="submit" so a real GET submission is
                // still the fallback if JS fails to load for any reason.
                filterForm.addEventListener("submit", function(e) {
                    e.preventDefault();
                    goQuietly(buildFilterUrl());
                });

                // Apply filters automatically on Select2 user selections or clears
                $('#status_filter, #assigned_filter, #client_filter').on('select2:select select2:clear', function() {
                    goQuietly(buildFilterUrl());
                });

                // "Resetează filtre" — clear the visible controls, then navigate quietly
                if (resetBtn) {
                    resetBtn.addEventListener("click", function(e) {
                        e.preventDefault();

                        $('#status_filter, #assigned_filter').val('').trigger('change');
                        $('#client_filter').val(null).trigger('change');

                        hiddenInput.value = 'ASC';
                        arrows.forEach(a => a.classList.remove("active"));
                        arrows.forEach(a => {
                            if (a.dataset.value === 'ASC') a.classList.add("active");
                        });

                        goQuietly('dashboard.php');
                    });
                }

                // Pagination links live inside the AJAX-swapped .pagination block, so
                // they're recreated on every refresh — rebind after each one via the
                // hook quietRefresh() calls (window.bindPaginationClickEvents).
                function bindPaginationClickEvents() {
                    document.querySelectorAll('.pagination a').forEach(link => {
                        link.addEventListener('click', function(e) {
                            e.preventDefault();
                            goQuietly(this.getAttribute('href'));
                        });
                    });
                }
                window.bindPaginationClickEvents = bindPaginationClickEvents;
                bindPaginationClickEvents();

            }, 200); // ← gives Select2 time to initialize
        });
    </script>

    <!-- Script for using required in add order form -->

    <script>
        // Call this after Select2 is initialized
        function syncClientRequiredState() {
            const hasClient = !!$('#client_id').val(); // Select2 value
            if (hasClient) {
                // A client is selected: remove required so browser won't block submit
                $('#client_name, #client_phone').prop('required', false);
                // Hide new-client fields for clarity (animated via CSS class)
                $('#new_client_fields').addClass('collapsed');
            } else {
                // No client selected: enforce required again
                $('#client_name, #client_phone').prop('required', true);
                $('#new_client_fields').removeClass('collapsed');
            }
        }

        // Run on page load
        $(document).ready(function() {
            syncClientRequiredState();
            // Update when Select2 changes or is cleared
            $('#client_id').on('select2:select select2:unselect change', syncClientRequiredState);
        });
    </script>

    <footer>
        <a href="dashboard.php" style="text-decoration: none; color: white;"><i class="fa-solid fa-house"></i> Pagina principală</a>
        <a href="archive.php" style="text-decoration: none; color: white;"><i class="fa-solid fa-box-archive"></i> Arhivă</a>
        <a href="unpaid_orders.php" style="text-decoration: none; color: white;"><i class="fa-solid fa-ban"></i> Comenzi nefacturate</a>
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