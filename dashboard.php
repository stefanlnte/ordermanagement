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
    <script src="https://cdnjs.cloudflare.com/ajax/libs/select2/4.0.13/js/select2.min.js"></script>
    <!-- Initialize Select2 lybrary -->
    <script>
        document.addEventListener('DOMContentLoaded', function() {
            // Initialize Select2 on select elements
            $('#status_filter, #assigned_filter, #category_filter, #sort_order, #assigned_to, #category_id').select2({
                dropdownAutoWidth: true,
                width: 'auto'
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
    </script>

    <!-- Search Modal -->
    <script>
        document.addEventListener('DOMContentLoaded', function() {
            const modal = document.getElementById('lookupModal');
            const footerLink = document.getElementById('footerLookupLink');

            footerLink.addEventListener('click', function(e) {
                e.preventDefault(); // nu naviga nicăieri
                modal.style.display = 'block';
                setTimeout(function() {
                    $('#order_lookup').select2('open');
                }, 100);
            });
        });

        document.addEventListener('DOMContentLoaded', function() {
            // Open with button
            const modal = document.getElementById('lookupModal');
            const openBtn = document.getElementById('openLookupBtn');
            const closeX = document.querySelector('.lookup-close'); // matches first working file
            const orderLookup = $('#order_lookup');

            // Initialize Select2 ONCE
            function highlightTerm(text, term) {
                if (!text) return '';
                if (!term) return text;
                const regex = new RegExp('(' + term + ')', 'gi');
                return text.replace(regex, '<span class="highlight">$1</span>');
            }

            $('#order_lookup').select2({
                placeholder: 'Detalii comandă...',
                minimumInputLength: 1,
                allowClear: true,
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
                    const term = $('#order_lookup').data('select2').dropdown.$search.val();
                    return $(`
            <div>
                <div><strong>#${order.id}</strong> – ${highlightTerm(order.client_name, term)}</div>
                <div style="font-size:12px;color:#555;">
                    ${highlightTerm(order.order_details, term)}
                </div>
                <div style="font-size:11px;color:#999;">
                    ${highlightTerm(order.detalii_suplimentare, term)}
                </div>
            </div>
        `);
                },
                templateSelection: function(order) {
                    return order.client_name ? `#${order.id} – ${order.client_name}` : order.text;
                },
                escapeMarkup: function(markup) {
                    return markup;
                } // allow HTML for highlighting
            }).on('select2:select', function(e) {
                var orderId = e.params.data.id;
                if (orderId) {
                    // 👇 grab the hidden return input value
                    var returnUrl = document.querySelector('#lookupForm input[name="return"]').value;
                    window.location.href = 'view_order.php?order_id=' + orderId +
                        (returnUrl ? '&return=' + encodeURIComponent(returnUrl) : '');
                }
            });

            function openModalAndFocus() {
                modal.style.display = 'block';
                // Open the select2 dropdown shortly after showing modal
                setTimeout(function() {
                    orderLookup.select2('open');
                }, 100);
            }

            // Button opens modal
            if (openBtn) {
                openBtn.addEventListener('click', openModalAndFocus);
            }

            // X closes modal
            if (closeX) {
                closeX.addEventListener('click', function() {
                    modal.style.display = 'none';
                });
            }

            // Click outside closes modal
            window.addEventListener('click', function(event) {
                if (event.target === modal) {
                    modal.style.display = 'none';
                }
            });

            // Ctrl+f (or Cmd+f) opens modal + focuses search
            document.addEventListener('keydown', function(e) {
                if ((e.ctrlKey || e.metaKey) && e.key.toLowerCase() === 'f') {
                    e.preventDefault();
                    openModalAndFocus();
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
                event.preventDefault(); // Prevent the default form submission

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
                                    text: 'Se deschide pagina comenzii...',
                                    showConfirmButton: false,
                                    timer: 1500,
                                    timerProgressBar: true,
                                    position: 'center'
                                }).then(() => {
                                    const returnUrl = document.querySelector('input[name="return"]').value;
                                    window.location.href = 'view_order.php?order_id=' + orderId +
                                        (returnUrl ? '&return=' + encodeURIComponent(returnUrl) : '');
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
                mirror: false // Start animation on scroll up as well
            });
        });
    </script>

</head>

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
        <p data-aos="fade-down"
            data-aos-easing="linear"
            data-aos-duration="800">
            <span id="greeting-message"></span>, <?php echo ucwords($_SESSION['username']); ?>! Astăzi este <span id="currentdate"></span>.
        </p>
        <!-- Căutare avansată -->
        <button id="footerLookupLink"
            data-aos="fade-down"
            data-aos-easing="linear"
            data-aos-duration="800">
            <i class="fa-solid fa-magnifying-glass"></i> Căutare avansată (CTRL+F)
        </button>
        <!-- Statistici -->
        <button onclick="window.location.href='statistics.php?return=' + encodeURIComponent(window.location.href)"
            data-aos="fade-down"
            data-aos-easing="linear"
            data-aos-duration="800">
            <i class="fa-solid fa-chart-line"></i> Statistici
        </button>
        <!-- Deconectare -->
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
                <div id="new_client_fields" class="form-group">
                    <div class="flex-container">
                        <div class="form-group">
                            <label for="client_name"><strong>Nume Client:</strong></label>
                            <input placeholder="Prenume și Nume" type="text" id="client_name" name="client_name">
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
                                <label>Ordine:</label>
                                <select id="sort_order" name="sort_order">
                                    <option value="ASC" <?php if ($sort_order == 'ASC') echo 'selected'; ?>>Ascendent</option>
                                    <option value="DESC" <?php if ($sort_order == 'DESC') echo 'selected'; ?>>Descendent</option>
                                </select>
                            </div>

                            <div class="filter-group">
                                <button type="submit">Aplică filtre</button>
                                <button type="button" onclick="window.location.href='dashboard.php'">Resetează filtre</button>
                            </div>
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
                                $status = $row["assigned_user"];
                                $row_classes[] = 'order-completed';
                            } elseif ($row["assigned_to"] == $_SESSION['user_id'] && $status != 'completed' && $status != 'delivered') {
                                $status = '<i class="fas fa-star"></i>';
                                $row_classes[] = 'order-current-user';
                            } elseif ($status != "completed" && $status != "delivered") {
                                $status = $row["assigned_user"];
                                $row_classes[] = 'order-assigned';
                            } elseif ($status == 'completed') {
                                $status = '<i class="fas fa-flag-checkered"></i>';
                                $row_classes[] = 'order-completed';
                            } else {
                                $status = 'livrată';
                                $row_classes[] = 'order-delivered';
                            }

                            $row_class = implode(' ', $row_classes);

                            echo "<tr class='order-row $row_class'
      data-order-id='{$row["order_id"]}'
      onclick=\"window.location.href='view_order.php?order_id={$row["order_id"]}&return=" . urlencode($_SERVER['REQUEST_URI']) . "'\">";
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

    <!-- Floating Notes Button -->
    <div id="notesFab" title="Notes">
        <i class="fa-solid fa-note-sticky"></i>
    </div>
    <!-- Floating Whatsapp Button -->
    <div id="whatsappWidget" class="floating-widget" title="Trimite mesaj pe WhatsApp">
        <i class="fa-brands fa-whatsapp"></i>
    </div>

    <div id="notesModal" class="modal">
        <div class="modal-backdrop"></div>
        <div class="modal-content">
            <header>
                <h4>Notițe</h4>
                <div class="close-btn"><i class="fa-solid fa-circle-xmark"></i></div>
            </header>
            <ul id="notesList"></ul>
            <div class="new-note">
                <textarea id="noteInput" placeholder="Scrie un mesaj…"></textarea>
                <button id="addNoteBtn">Adaugă</button>
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

            tippy('.order-row', {
                allowHTML: true,
                interactive: true,
                theme: 'order-preview',
                placement: 'top',
                maxWidth: 350,
                delay: [200, 0],
                animation: 'shift-away',
                offset: [0, 10],

                onShow(instance) {
                    const reference = instance.reference;
                    const id = reference.getAttribute('data-order-id');

                    // Show loading text immediately
                    instance.setContent("Loading...");

                    // Fetch preview
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

        });
    </script>


    <!-- Lookup Modal -->
    <div id="lookupModal" class="modal">
        <div class="modal-content">
            <span class="lookup-close">&times;</span>
            <h2>Căutare avansată</h2>
            <p style="margin:10px 0; font-size:14px; color:#555;">
                În câmpul de căutare avansată poți introduce <strong>numărul comenzii</strong>,
                <strong>detalii despre comandă (comandă inițială, detalii suplimentare)</strong>
                sau <strong>numele clientului</strong>. Acestă ferestră se poate deschide și apăsând CTRL+F.
            </p>
            <form id="lookupForm">
                <input type="hidden" name="return" value="<?= htmlspecialchars($_SERVER['REQUEST_URI']) ?>">
                <div class="form-group">
                    <select id="order_lookup" style="width:100%;"></select>
                </div>
            </form>
        </div>
    </div>

    <footer>
        <a href="dashboard.php" style="text-decoration: none; color: white;"><i class="fa-solid fa-house"></i> Pagina principală</a>
        <a href="archive.php" style="text-decoration: none; color: white;"><i class="fa-solid fa-box-archive"></i> Arhivă</a>
        <a href="unpaid_orders.php" style="text-decoration: none; color: white;"><i class="fa-solid fa-ban"></i> Comenzi nefacturate</a>
    </footer>

</body>

</html>