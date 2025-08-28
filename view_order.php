<?php
session_start();
error_reporting(E_ALL);
ini_set('display_errors', 1);

include 'db.php'; // Include the database connection file

$order_id = $_GET['order_id'] ?? null;
if (!$order_id) {
    echo "Order ID not provided.";
    exit();
}

// Handle form submission for updating the attributed user
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_user'])) {
    $assigned_to = $_POST['assigned_to'];
    $update_sql = "UPDATE orders SET assigned_to = ? WHERE order_id = ?";
    $stmt = $conn->prepare($update_sql);
    $stmt->bind_param("ii", $assigned_to, $order_id);
    if ($stmt->execute()) {
        header("Location: view_order.php?order_id=$order_id");
        exit();
    } else {
        echo "Error updating user: " . $stmt->error;
    }
    $stmt->close();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_due_date'])) {
    $new_due_date = $_POST['new_due_date'];
    $update_due_sql = "UPDATE orders SET due_date = ? WHERE order_id = ?";
    $stmt = $conn->prepare($update_due_sql);
    $stmt->bind_param("si", $new_due_date, $order_id);
    if ($stmt->execute()) {
        echo "<script>
        alert('Data scadentă a fost actualizată cu succes!');
        window.location.href = 'view_order.php?order_id={$order_id}';
    </script>";
        exit;
    } else {
        echo "Error updating due date: " . $stmt->error;
    }
    $stmt->close();
}

// Fetch order details including assigned_to, created_by, and status
$order_sql = "SELECT o.*, 
                   u.username as assigned_user,
                   cu.username as created_user 
              FROM orders o 
              LEFT JOIN users u ON o.assigned_to = u.user_id 
              LEFT JOIN users cu ON o.created_by = cu.user_id 
              WHERE o.order_id = ?";
$stmt = $conn->prepare($order_sql);
$stmt->bind_param("i", $order_id);
$stmt->execute();
$order_result = $stmt->get_result();
$order = $order_result->fetch_assoc();
$stmt->close();

// Fetch client details
$client_sql = "SELECT client_name, client_phone, client_email FROM clients WHERE client_id = ?";
$stmt = $conn->prepare($client_sql);
$stmt->bind_param("i", $order['client_id']);
$stmt->execute();
$client_result = $stmt->get_result();
$client_row = $client_result->fetch_assoc();
$client_name = $client_row['client_name'] ?? 'Unknown';
$client_phone = $client_row['client_phone'] ?? 'Unknown';
$client_email = $client_row['client_email'] ?? 'Unknown';
$stmt->close();

// Fetch all users for the "assigned to" dropdown
$users_sql = "SELECT user_id, username FROM users WHERE role = 'operator'";
$users_result = $conn->query($users_sql);
$users = [];
if ($users_result->num_rows > 0) {
    while ($row = $users_result->fetch_assoc()) {
        $users[] = $row;
    }
}
?>

<!DOCTYPE html>
<html>

<head>
    <title>View Order</title>
    <link rel="stylesheet" type="text/css" href="style.css">
    <link rel="stylesheet" type="text/css" href="styles.css">
    <link rel="icon" type="image/png" href="https://color-print.ro/magazincp/favicon.png" />
    <!-- Include Font Awesome -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <!-- Include Select2 CSS -->
    <link href="https://cdnjs.cloudflare.com/ajax/libs/select2/4.0.13/css/select2.min.css" rel="stylesheet" />

    <!-- Include jQuery -->
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>

    <!-- Include Select2 JavaScript -->
    <script src="https://cdnjs.cloudflare.com/ajax/libs/select2/4.0.13/js/select2.min.js"></script>
    <!-- CodeMirror JavaScript -->
    <script src="https://cdnjs.cloudflare.com/ajax/libs/codemirror/5.65.5/codemirror.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/codemirror/5.65.5/mode/javascript/javascript.min.js"></script>
    <script>
        const currentOrderId = <?= (int)$_GET['order_id'] ?>;
    </script>

    <script>
        function editOrderDetails() {
            // Ascunde textul afișat
            const suplText = document.getElementById('detalii_suplimentare_text');
            if (suplText) suplText.style.display = 'none';

            // Arată zona de editare
            const suplEdit = document.getElementById('detalii_suplimentare_edit');
            if (suplEdit) suplEdit.style.display = 'block';

            // Butoane
            const btnEdit = document.querySelector('button[onclick="editOrderDetails()"]');
            const btnSave = document.querySelector('button[onclick="saveOrderDetails()"]');
            if (btnEdit) btnEdit.style.display = 'none';
            if (btnSave) btnSave.style.display = 'inline';
        }

        function saveOrderDetails() {
            const suplEdit = document.getElementById('detalii_suplimentare_edit');
            const detaliiSuplimentare = suplEdit ? suplEdit.value : '';
            const orderId = <?php echo (int)$order['order_id']; ?>;

            var xhr = new XMLHttpRequest();
            xhr.open('POST', 'update_order_details.php', true);
            xhr.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded');
            xhr.onreadystatechange = function() {
                if (xhr.readyState === 4) {
                    if (xhr.status === 200) {
                        const suplText = document.getElementById('detalii_suplimentare_text');
                        if (suplText) {
                            suplText.innerText = detaliiSuplimentare;
                            suplText.style.display = 'block';
                        }
                        if (suplEdit) suplEdit.style.display = 'none';

                        const btnEdit = document.querySelector('button[onclick="editOrderDetails()"]');
                        const btnSave = document.querySelector('button[onclick="saveOrderDetails()"]');
                        if (btnEdit) btnEdit.style.display = 'inline';
                        if (btnSave) btnSave.style.display = 'none';
                    } else {
                        alert('Eroare la salvare: ' + (xhr.responseText || xhr.status));
                    }
                }
            };
            xhr.send(
                'order_id=' + encodeURIComponent(orderId) +
                '&detalii_suplimentare=' + encodeURIComponent(detaliiSuplimentare)
            );
        }

        function togglePin(orderId, pinState) {
            var xhr = new XMLHttpRequest();
            xhr.open('POST', 'toggle_pin.php', true);
            xhr.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded');
            xhr.onreadystatechange = function() {
                if (xhr.readyState == 4 && xhr.status == 200) {
                    location.reload();
                }
            };
            xhr.send('order_id=' + orderId + '&is_pinned=' + pinState);
        }

        function finishOrder() {
            var orderId = <?php echo $order['order_id']; ?>;
            var clientPhone = '<?php echo $client_phone; ?>';
            var xhr = new XMLHttpRequest();
            xhr.open('POST', 'update_order_status.php', true);
            xhr.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded');
            xhr.onreadystatechange = function() {
                if (xhr.readyState == 4 && xhr.status == 200) {
                    sendSMS(clientPhone, orderId);
                    // Remove the button from the DOM
                    var button = document.getElementById('finishButton');
                    if (button) {
                        console.log('Butonul a fost gasit si va fi sters.');
                        button.parentNode.removeChild(button);
                    } else {
                        console.log('Butonul nu a fost gasit.');
                    }
                } else if (xhr.readyState == 4) {
                    console.error('Cererea a esuat cu status:', xhr.status);
                    // Opțional: Afisează un mesaj de eroare pentru utilizator
                    showAlert('Eroare la finalizarea comenzii');
                }
            };
            xhr.onerror = function() {
                console.error('Eroare la cererea AJAX');
                // Opțional: Afisează un mesaj de eroare pentru utilizator
                showAlert('Eroare la finalizarea comenzii');
            };
            xhr.send('order_id=' + orderId + '&status=completed');
        }

        function showAlert(message) {
            return new Promise((resolve) => {
                alert(message);
                resolve();
            });
        }

        function sendSMS(clientPhone, orderId) {
            var xhr = new XMLHttpRequest();
            xhr.open('POST', 'send_sms.php', true);
            xhr.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded');
            xhr.onreadystatechange = function() {
                if (xhr.readyState == 4 && xhr.status == 200) {
                    showAlert('Good job. Felicitări pentru terminarea comenzii. 🎉').then(() => {
                        console.log('SMS SENT')
                    });
                }
            };
            xhr.send('to=' + clientPhone + '&order_id=' + orderId);
        }

        function deliverOrder() {
            var orderId = <?php echo $order['order_id']; ?>;
            var currentDate = new Date().toISOString().slice(0, 19).replace('T', ' '); // Format: YYYY-MM-DD HH:MM:SS

            var xhr = new XMLHttpRequest();
            xhr.open('POST', 'update_order_status.php', true);
            xhr.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded');
            xhr.onreadystatechange = function() {
                if (xhr.readyState == 4 && xhr.status == 200) {
                    // Display the alert message first
                    showAlert(xhr.responseText).then(() => {
                        console.log('Comanda Livrata');
                    });
                } else if (xhr.readyState == 4) {
                    // Display an error message if the request was unsuccessful
                    showAlert('Eroare');
                }
            };
            xhr.send('order_id=' + orderId + '&status=delivered&delivery_date=' + encodeURIComponent(currentDate));
            // Remove the button from the DOM
            var button = document.getElementById('deliverButton');
            button.parentNode.removeChild(button);
        }

        function cancelOrder() {
            var orderId = <?php echo $order['order_id']; ?>;

            // Add confirmation prompt
            if (!confirm("Sigur doriți să anulați comanda cu ID-ul " + orderId + "?")) {
                return; // Exit the function if the user clicks "Cancel"
            }

            var xhr = new XMLHttpRequest();
            xhr.open('POST', 'cancel_order.php', true);
            xhr.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded');
            xhr.onreadystatechange = function() {
                if (xhr.readyState == 4 && xhr.status == 200) {
                    // Display the alert message first
                    showAlert(xhr.responseText).then(() => {
                        console.log('Comanda Anulată');
                        // Optionally, redirect or refresh the page
                        window.location.reload();
                    });
                } else if (xhr.readyState == 4) {
                    // Display an error message if the request was unsuccessful
                    showAlert('Eroare la anularea comenzii');
                }
            };
            xhr.send('order_id=' + orderId);
        }


        function printOrder() {
            window.print();
        }

        // Fills the articles for orders 
        function loadOrderArticles(orderId) {
            $.getJSON(`fetch_order_articles.php?order_id=${orderId}`, function(data) {
                const tbody = $('#bonTableBody');
                tbody.empty();
                let total = 0;

                data.forEach(row => {
                    const qty = Number(row.quantity);
                    const unit = Number(row.price_per_unit);
                    total += qty * unit;

                    tbody.append(`
        <tr data-id="${row.id}">
          <td>${row.name}</td>
          <td>${qty}</td>
          <td>${unit.toFixed(2)}</td>
          <td><button type="button" class="removeArticle">✖</button></td>
        </tr>
      `);
                });

                const avans = parseFloat("<?= $order['avans'] ?>") || 0;
                const totalMinusAvans = total - avans;
                $('#totalPrice').text(totalMinusAvans.toFixed(2) + ' lei');
            });
        }

        $('#addArticleForm').on('submit', function(e) {
            e.preventDefault();
            $.post('add_article.php', $(this).serialize(), function() {
                loadOrderArticles(currentOrderId); // refresh the table
            });
        });

        $(document).ready(function() {
            loadOrderArticles(currentOrderId);
        });

        $('#articleSelect').on('select2:select', function(e) {
            const data = e.params.data;
            if (data.price !== undefined) {
                $('#price').val(data.price); // existing
            } else {
                $('#price').val(''); // new entry → blank so you can type
            }
        });

        $(document).on('submit', '#addArticleForm', function(e) {
            e.preventDefault();

            const form = this;
            $.ajax({
                url: $(form).attr('action'),
                method: 'POST',
                data: $(form).serialize(),
                success: function(resp) {
                    // Optional: check JSON response if you return JSON
                    loadOrderArticles(currentOrderId);
                    // Reset for the next add (optional)
                    $('#articleSelect').val(null).trigger('change');
                    $('#quantity').val(1);
                    $('#price').val('');
                },
                error: function(xhr) {
                    alert(xhr.responseText || 'Eroare la adăugarea articolului.');
                }
            });
        });

        $(document).on('click', '.removeArticle', function() {
            const row = $(this).closest('tr');
            const id = row.data('id');

            if (!id) {
                alert('Missing row id; cannot delete.');
                return;
            }
            if (!confirm('Șterge acest articol?')) return;

            $.post('delete_article.php', {
                id
            }, function() {
                loadOrderArticles(currentOrderId);
            }).fail(function(xhr) {
                alert(xhr.responseText || 'Could not delete article.');
            });
        });
    </script>

    <script>
        window.addEventListener('beforeprint', () => {
            const table = document.getElementById('bonTable');
            const hasRows = table.querySelectorAll('tbody tr').length > 0;
            if (!hasRows) {
                table.classList.add('no-print');
            } else {
                table.classList.remove('no-print');
            }
        });
    </script>



    <!-- Select2 date picker -->
    <script>
        $(document).ready(function() {
            const select = document.getElementById('new_due_date_select');
            const today = new Date();
            const daysToGenerate = 365; // sau cât ai nevoie

            for (let i = 0; i < daysToGenerate; i++) {
                const d = new Date();
                d.setDate(today.getDate() + i);

                const year = d.getFullYear();
                const month = String(d.getMonth() + 1).padStart(2, '0');
                const day = String(d.getDate()).padStart(2, '0');

                const label = d.toLocaleDateString('ro-RO', {
                    weekday: 'short',
                    day: 'numeric',
                    month: 'short',
                    year: 'numeric'
                });

                const option = new Option(label, `${year}-${month}-${day}`);
                if (i === 0) option.selected = true;
                select.add(option);
            }

            // Activează Select2 cu același look ca restul
            $('#new_due_date_select').select2({
                dropdownAutoWidth: true,
                width: 'auto',
                placeholder: "Selectează data"
            });
        });
    </script>

    <!-- Funcție buton comanda achitată -->
    <script>
        function toggleAchitat(orderId, currentState) {
            var newState = currentState === 1 ? 0 : 1;

            var xhr = new XMLHttpRequest();
            xhr.open('POST', 'update_achitat.php', true);
            xhr.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded');
            xhr.onreadystatechange = function() {
                if (xhr.readyState === 4 && xhr.status === 200) {
                    location.reload(); // refresh to see permanent state
                }
            };
            xhr.send('order_id=' + orderId + '&is_achitat=' + newState);
        }
    </script>
    <!-- Funcție buton comandă în lucru -->
    <script>
        function toggleComandaLucru() {
            var comandaLucruElement = document.getElementById('comandaLucruElement');
            if (comandaLucruElement) {
                comandaLucruElement.parentNode.removeChild(comandaLucruElement);
            } else {
                var h2Element = document.createElement('h2');
                h2Element.id = 'comandaLucruElement';
                h2Element.textContent = 'Comandă în lucru';
                document.querySelector('h2').insertAdjacentElement('afterend', h2Element);
            }
        }
    </script>

    <!-- Initialize Select2 lybrary -->
    <script>
        $(document).ready(function() {
            // Initialize Select2 on select elements
            $('#status_filter, #assigned_filter, #category_filter, #sort_order, #assigned_to, #category_id').select2({
                dropdownAutoWidth: true,
                width: 'auto'
            });
        });
        // Init for add article
        $(document).ready(function() {
            $('#articleSelect').select2({
                tags: true, // allow new entries
                ajax: {
                    url: 'fetch_articles.php',
                    dataType: 'json',
                    processResults: function(data) {
                        return {
                            results: data.map(item => ({
                                id: item.id, // numeric for existing items
                                text: `${item.name} (${item.price} lei)`,
                                price: item.price
                            }))
                        };
                    }
                }
            });

            // Autofill price for existing items (clear if none)
            $('#articleSelect').on('select2:select', function(e) {
                const data = e.params.data;
                if (data.price !== undefined) {
                    $('#price').val(data.price);
                } else {
                    $('#price').val('');
                }
            });
        });
    </script>

    <!-- Function to edit prices -->
    <script>
        document.addEventListener('DOMContentLoaded', function() {
            const btn = document.getElementById('updateDefaultPriceBtn');
            const priceInput = document.getElementById('price');
            const articleSelect = document.getElementById('articleSelect');

            if (!btn) return; // no button found

            btn.addEventListener('click', function() {
                const articleId = articleSelect.value;
                const newPrice = priceInput.value.trim();

                // Don’t update if it’s a new typed‑in tag or no selection
                if (!articleId || isNaN(Number(articleId))) {
                    alert('Selectează un articol existent înainte de a actualiza prețul implicit.');
                    return;
                }

                if (newPrice === '' || isNaN(Number(newPrice))) {
                    alert('Introdu un preț numeric valid.');
                    return;
                }

                fetch('update_default_price.php', {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/x-www-form-urlencoded'
                        },
                        body: 'article_id=' + encodeURIComponent(articleId) +
                            '&price=' + encodeURIComponent(newPrice)
                    })
                    .then(res => res.json())
                    .then(data => {
                        if (data && data.success) {
                            alert('Prețul implicit a fost actualizat.');
                            // Optional: refresh Select2 display text with the new price
                            const selected = $('#articleSelect').select2('data')[0];
                            if (selected && selected.name) {
                                selected.price = parseFloat(newPrice);
                                selected.text = `${selected.name} (${selected.price.toFixed(2)} lei)`;
                                $('#articleSelect').trigger('change.select2');
                            }
                        } else {
                            alert(data && data.error ? data.error : 'Eroare la actualizarea prețului.');
                        }
                    })
                    .catch(() => alert('Eroare de rețea la actualizarea prețului.'));
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
            padding-left: 12px;
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

    <!-- Stil pentru adauga aricol -->
    <style>
        /* Make column widths predictable */
        #bonTable {
            border-collapse: collapse;
            table-layout: fixed;
            width: auto;
            /* allow the 4th column to extend beyond 80mm */
            vertical-align: middle;
            text-align: left;
            max-width: none;
        }

        #bonTable th,
        #bonTable td {
            padding: 0;
            /* keep the first 3 columns totaling exactly 80mm */
            white-space: nowrap;
            vertical-align: middle;
            text-align: left;
        }

        /* Qty column: >= 4 characters wide */
        #bonTable thead th:nth-child(2),
        #bonTable tbody td:nth-child(2) {
            width: 4.5ch;
            /* room for 4 chars comfortably */
            text-align: center;
        }

        /* Price column: >= 4 characters wide */
        #bonTable thead th:nth-child(3),
        #bonTable tbody td:nth-child(3) {
            width: 4.5ch;
            /* room for 4 chars comfortably */
            text-align: center;
        }

        #bonTableBody>tr>td:nth-child(4) {
            text-align: center;
            /* horizontal centering */
            vertical-align: middle;
            /* vertical centering (works for table cells) */
            padding: 3px;
        }

        #bonTable>thead>tr>th:nth-child(4) {
            padding: 3px;
        }

        #bonTableBody>tr>td:nth-child(4)>button {
            display: block;
            margin: auto;
        }

        /* Article column takes the remainder so 1+2+3 = 80mm total */
        #bonTable thead th:nth-child(1),
        #bonTable tbody td:nth-child(1) {
            width: calc(80mm - 9ch);
            /* 9ch = 4.5ch + 4.5ch for Qty+Price */
            overflow: hidden;
            text-overflow: ellipsis;
        }

        /* 4th column (delete) – no fixed width; sits after the 80mm block */
        #bonTable thead th:nth-child(4),
        #bonTable tbody td:nth-child(4) {
            width: auto;
            text-align: left;
        }

        .removeArticle {
            font-size: 1em;
            font-weight: bold;
            color: #fff;
            background: linear-gradient(135deg, #e74c3c, #c0392b);
            padding: 2px 6px;
            cursor: pointer;
            transition: transform 0.15s ease, background 0.3s ease;
        }

        .removeArticle:hover {
            background: linear-gradient(135deg, #ff6f61, #e74c3c);
            transform: scale(1.05);
        }

        body>div:nth-child(3)>div.no-print.add-article-form {
            margin: 25px !important;
        }

        /* Material Design Gray & Yellow Theme */
        .add-article-form {
            background: linear-gradient(135deg, #1a1a1aff, gray);
            /* light gray background */
            padding: 20px;
            border-radius: 8px;
            box-shadow: 0 2px 6px yellow;
            max-width: 400px;
            margin-left: 10px !important;
        }

        .add-article-form form {
            display: flex;
            flex-direction: column;
            gap: 12px;
        }

        .add-article-form select,
        .add-article-form input[type="text"],
        .add-article-form input[type="number"] {
            padding: 10px;
            border: none;
            border-radius: 4px;
            background-color: #e0e0e0;
            /* medium gray */
            font-size: 14px;
            transition: background-color 0.3s ease, box-shadow 0.3s ease;
        }

        .add-article-form select:focus,
        .add-article-form input:focus {
            background-color: #fffde7;
            /* pale yellow focus */
            box-shadow: 0 0 0 2px yellow;
            /* vibrant yellow outline */
            outline: none;
        }

        .add-article-form button {
            padding: 12px;
            background-color: yellow;
            /* material yellow */
            color: #212121;
            /* dark gray text */
            border: none;
            border-radius: 4px;
            font-weight: bold;
            letter-spacing: 0.5px;
            text-transform: uppercase;
            cursor: pointer;
            transition: 200 ms;
        }

        .add-article-form button:hover {
            background-color: MediumSeaGreen;
            color: yellow;
            /* darker yellow on hover */
            transform: translateY(-2px);
            transition: 200 ms;
        }

        .add-article-form button:active {
            background-color: gold;
            /* press effect */
            transform: translateY(0);
        }

        #bonTable>thead>tr>th:nth-child(4) {
            border: none;
        }

        /* Hide delete controls when printing */
        @media print {

            #bonTable td:nth-child(4),
            #bonTable th:nth-child(4),
            #bonTable .removeArticle,
            #bonTable .no-print {
                display: none !important;
                visibility: hidden !important;
            }
        }
    </style>

    <!-- Print styles -->
    <style>
        @media print {
            .no-print {
                display: none !important;
                visibility: hidden !important;
            }

            html,
            body {
                overflow: hidden !important;
                position: relative !important;
                line-height: 1.1;
                font-size: 13px;
            }

            p {
                margin-top: 0;
                margin-bottom: 5px;
                line-height: 1.1;
            }

            /* Remove box shadow and other non-print styles */
            header {
                display: none !important;
                visibility: hidden !important;
            }

            /* Add these new styles to target the specific elements */
            .order-options {
                display: none !important;
                visibility: hidden !important;
            }

            /* Reset background colors to prevent shadows */
            .order-options * {
                background-color: transparent !important;
            }
        }

        p {
            margin-top: 0;
            margin-bottom: 10px;
        }
    </style>

    <!-- Whatsapp Icon -->
    <style>
        .whatsapp-icon {
            display: inline-flex;
            justify-content: center;
            align-items: center;
            background-color: #25D366;
            /* Verde oficial WhatsApp */
            color: white;
            border-radius: 50%;
            /* Face iconița rotundă */
            width: 32px;
            /* Dimensiune */
            height: 32px;
            font-size: 18px;
            text-decoration: none;
        }

        .whatsapp-icon:hover {
            background-color: #20b85d;
            /* Ușor mai închis la hover */
        }
    </style>
</head>

<body>
    <header class="no-print" id="header">
        <?php if ($order['is_pinned'] == 1): ?>
            <button class="no-print" onclick="togglePin(<?= $order['order_id'] ?>, 0)">Unpin Order 📌</button>
        <?php else: ?>
            <button class="no-print" onclick="togglePin(<?= $order['order_id'] ?>, 1)">Pin Order 📌</button>
        <?php endif; ?>
        <?php if ($order['status'] != 'completed' && $order['status'] != 'delivered' && $order['status'] != 'cancelled'): ?>
            <button id="finishButton" class="no-print" onclick="finishOrder()">Comanda a fost terminată</button>
        <?php endif; ?>

        <?php if ($order['status'] != 'delivered' && $order['status'] != 'cancelled'): ?>
            <button id="deliverButton" class="no-print" onclick="deliverOrder()">Comanda a fost Livrată</button>
        <?php endif; ?>

        <button id="cancelButton" class="no-print" onclick="cancelOrder()" <?php if ($order['status'] == 'cancelled') echo 'style="display:none;"'; ?>>Anulează Comanda</button>
        <br>
        <button class="no-print" href="javascript:void(0);" onclick="window.history.back();"> &#8592; Înapoi la panou comenzi</button>
    </header>
    <div class="order-options">
        <h1 style="font-size: larger;">Opțiuni suplimentare</h1>
        <?php if ($order['status'] != 'delivered' && $order['status'] != 'cancelled')  ?>
        <form method="post" action="view_order.php?order_id=<?php echo $order['order_id']; ?>">
            <div class="form-group">
                <label for="new_due_date_select">Extinde data scadentă:</label>
                <select id="new_due_date_select" name="new_due_date"></select>
                <button type="submit" name="update_due_date">Actualizează data</button>
            </div>
        </form>

        <form method="post" action="view_order.php?order_id=<?php echo $order['order_id']; ?>">
            <div class="form-group no-print">
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
                <button type="submit" name="update_user" class="no-print">Realocare strategică</button>
            </div>
        </form>
        <?php if ($order['status'] != 'livrata') ?>
        <div class="no-print">
            <button class="no-print" onclick="editOrderDetails()">Edit</button>
            <button class="no-print" onclick="saveOrderDetails()" style="display:none;">Salvează modificările</button>
            <button
                id="toggleAchitatButton"
                class="no-print"
                onclick="toggleAchitat(<?= $order['order_id'] ?>, <?= (int)$order['is_achitat'] ?>)">
                <?= $order['is_achitat'] ? 'Marchează ca neachitat' : 'Comandă achitată' ?>
            </button>
            <button id="toggleComandaLucruButton" class="no-print" onclick="toggleComandaLucru()">Comandă în lucru</button>
            <button class="no-print" onclick="printOrder()">Print Order</button><br>
        </div>
    </div>
    <div style="min-height: 100vh;">
        <h2>Comanda nr. <strong class=order_id_large> <?php echo $order['order_id']; ?></strong></h2>
        <?php if ($order['is_achitat'] == 1): ?>
            <h2>Comandă achitată</h2>
        <?php endif; ?>
        <p><strong>Din data: </strong><?php echo date('d-m-Y', strtotime($order['order_date'])); ?></p>
        <p><strong>Scadentă: </strong><?php echo date('d-m-Y', strtotime($order['due_date'])); ?></p>
        <p><strong>Operator: </strong><?php echo ucwords($order['assigned_user']); ?></p>
        <p><strong>Responsabil: </strong><?php echo ucwords($order['created_user']); ?></p>
        <p><strong>Nume client: </strong><?php echo $client_name; ?></p>
        <?php
        $countryCode = "+4";
        $waNumber = $countryCode . preg_replace('/\D/', '', $client_phone); // Remove non-digits
        $waLink = "https://wa.me/" . urlencode($waNumber);
        ?>
        <p><strong>Contact client: </strong>
            <?php echo htmlspecialchars($client_phone); ?>
            <a href="<?php echo $waLink; ?>" target="_blank" class="no-print whatsapp-icon">
                <i class="fab fa-whatsapp"></i>
            </a>
        </p>
        <p><strong>Comanda initiala: </strong><br><span id="order_details_text"><?php echo nl2br(htmlspecialchars($order['order_details'])); ?></span></p>
        <p><strong>Detalii suplimentare: </strong><br><span id="detalii_suplimentare_text"><?php echo nl2br(htmlspecialchars($order['detalii_suplimentare'])); ?></span></p>
        <textarea id="detalii_suplimentare_edit" style="display:none;"><?php echo $order['detalii_suplimentare']; ?></textarea>

        <!-- Articole -->
        <?php
        $stmt = $conn->prepare("
    SELECT a.name, oa.quantity, oa.price_per_unit
    FROM order_articles oa
    JOIN articles a ON oa.article_id = a.id
    WHERE oa.order_id = ?
");
        $stmt->bind_param('i', $order_id);
        $stmt->execute();
        $result = $stmt->get_result();

        echo '<table id="bonTable">
    <thead>
        <tr>
            <th>Articole</th>
            <th>Cant</th>
            <th>Preț</th>
            <th>Șterge</th>
        </tr>
    </thead>
    <tbody id="bonTableBody">';

        $hasRows = false;
        $total = 0;

        if ($result) {
            while ($row = $result->fetch_assoc()) {
                $hasRows = true;
                $total += $row['quantity'] * $row['price_per_unit'];
                echo '<tr>';
                echo '<td>' . htmlspecialchars($row['name'], ENT_QUOTES, 'UTF-8') . '</td>';
                echo '<td>' . (int)$row['quantity'] . '</td>';
                echo '<td>' . number_format((float)$row['price_per_unit'], 2) . '</td>';
                echo '</tr>';
            }
        }

        if (!$hasRows) {
            echo '<tr><td colspan="3" style="text-align:center;">Nu există articole</td></tr>';
        }

        echo '</tbody></table>';

        $stmt->close();
        ?>

        <!-- Add article form -->
        <div class="no-print add-article-form">
            <form id="addArticleForm" method="post" action="add_article.php">
                <select id="articleSelect" name="article_id" style="width: 300px;">
                    <option value="" disabled selected>Caută sau adaugă articol</option>
                </select>

                <div style="display:inline-flex; align-items:center; gap:5px;">
                    <input type="text" id="price" name="price" placeholder="Preț" style="width:80px;">
                    <button type="button" id="updateDefaultPriceBtn" title="Actualizează prețul implicit">✏️</button>
                </div>

                <input type="number" id="quantity" name="quantity" min="1" value="" placeholder="Cantitate">
                <input type="hidden" name="order_id" value="<?= (int)$order_id ?>">
                <button type="submit">Adaugă Articol</button>
            </form>
        </div>
        <br>
        <p>Avans: <?php echo $order['avans']; ?> lei</p>
        <div id="totalWrapper">
            <strong>Total:</strong> <span id="totalPrice">0.00</span>
        </div>
        <input type="number" id="total_edit" style="display:none;" value="<?php echo $order['total']; ?>" step="0.01">
        <?php
        $rest_de_plata = $order['total'] - $order['avans'];
        if ($rest_de_plata > 0) {
            echo "<p>Rest de Plata: $rest_de_plata lei</p>";
        }
        ?>
        <br>
        <div>
            <svg height="80px" clip-rule="evenodd" fill-rule="evenodd" image-rendering="optimizeQuality" shape-rendering="geometricPrecision" text-rendering="geometricPrecision" version="1.1" viewBox="0 0 386 148.1" xml:space="preserve" xmlns="http://www.w3.org/2000/svg">
                <defs>
                    <style>
                        .fil0,
                        .fil1 {
                            fill: #373435
                        }

                        .fil1 {
                            fill-rule: nonzero
                        }
                    </style>
                </defs>
                <g transform="matrix(8.1831 0 0 8.1831 -1033 -1535.6)">
                    <path class="fil0" d="m148.35 188.2 0.01 5.13 2.33 1.91 0.01-5.38h3.73c1.11 0 1.29 1.62-0.12 1.62l-3.21 0.01c0.24 0.42 1.82 1.79 2.33 1.78 1.95-0.03 3.56 0.24 4.05-1.61 0.35-1.33 0.19-2.85-0.74-3.43-0.27-0.17-0.92-0.34-1.76-0.35l-6.63-0.03z" />
                    <path class="fil0" d="m150.7 195.8-2.34-1.92-3.36-0.01c-0.4 0-0.67 0.02-0.68-0.39l-0.02-3.16c0-0.3 0.32-0.46 0.57-0.46l2.89 0.01 0.01-2.02-3.7-0.01c-1.26 0-1.85 0.5-1.86 1.58l-0.02 4.49c-0.01 1.64 1.04 1.91 2.96 1.9z" />
                    <path class="fil1" d="m127.11 197.27h2.9c0.24 0 0.36 0.12 0.36 0.36s-0.12 0.36-0.36 0.36h-2.9v3h3.02c0.23 0 0.35 0.12 0.35 0.36s-0.12 0.36-0.35 0.36h-3.02c-0.26 0-0.45-0.07-0.59-0.21-0.14-0.13-0.21-0.33-0.21-0.59v-2.84c0-0.26 0.07-0.46 0.21-0.59 0.14-0.14 0.33-0.21 0.59-0.21zm4.56 1.5c0-0.23 0.07-0.41 0.19-0.53 0.13-0.13 0.31-0.2 0.54-0.2h2.23c0.23 0 0.41 0.07 0.54 0.2 0.12 0.12 0.19 0.3 0.19 0.53v2.2c0 0.24-0.07 0.42-0.19 0.55-0.13 0.13-0.31 0.19-0.54 0.19h-2.23c-0.23 0-0.41-0.06-0.54-0.19-0.12-0.13-0.19-0.31-0.19-0.55zm0.73-0.07v2.35h2.23v-2.35zm4.67-0.7h0.02c0.23 0 0.35 0.12 0.35 0.36v2.69h2.12c0.22 0 0.33 0.11 0.33 0.33s-0.11 0.33-0.33 0.33h-2.49c-0.24 0-0.36-0.12-0.36-0.36v-2.99c0-0.24 0.12-0.36 0.36-0.36zm3.6 0.77c0-0.23 0.07-0.41 0.19-0.53 0.13-0.13 0.31-0.2 0.54-0.2h2.22c0.24 0 0.42 0.07 0.55 0.2 0.12 0.12 0.18 0.3 0.18 0.53v2.2c0 0.24-0.06 0.42-0.18 0.55-0.13 0.13-0.31 0.19-0.55 0.19h-2.22c-0.23 0-0.41-0.06-0.54-0.19-0.12-0.13-0.19-0.31-0.19-0.55zm0.73-0.07v2.35h2.22v-2.35zm4.31-0.31c0-0.23 0.12-0.35 0.36-0.35h1.88c0.36 0 0.64 0.1 0.84 0.29s0.3 0.46 0.3 0.8c0 0.29-0.07 0.53-0.21 0.72s-0.33 0.32-0.57 0.39l0.87 1.19c0.06 0.07 0.07 0.14 0.04 0.19-0.02 0.06-0.09 0.09-0.18 0.09h-0.24c-0.1 0-0.18-0.02-0.25-0.06-0.06-0.03-0.13-0.11-0.22-0.22l-0.81-1.12h-0.28c-0.22 0-0.33-0.11-0.33-0.33 0-0.21 0.11-0.32 0.33-0.32h0.63c0.15 0 0.27-0.04 0.36-0.13 0.08-0.08 0.13-0.21 0.13-0.37 0-0.18-0.03-0.3-0.1-0.37-0.06-0.06-0.19-0.09-0.39-0.09h-1.43v2.69c0 0.24-0.12 0.36-0.37 0.36-0.24 0-0.36-0.12-0.36-0.36zm12.78 0c0-0.23 0.12-0.35 0.36-0.35h1.88c0.36 0 0.64 0.1 0.84 0.29 0.19 0.19 0.29 0.46 0.29 0.8 0 0.29-0.07 0.53-0.2 0.72-0.14 0.19-0.33 0.32-0.58 0.39l0.88 1.19c0.06 0.07 0.07 0.14 0.04 0.19-0.03 0.06-0.09 0.09-0.19 0.09h-0.23c-0.1 0-0.19-0.02-0.25-0.06a0.747 0.747 0 0 1-0.22-0.22l-0.82-1.12h-0.27c-0.22 0-0.33-0.11-0.33-0.33 0-0.21 0.11-0.32 0.33-0.32h0.62c0.16 0 0.28-0.04 0.36-0.13 0.09-0.08 0.13-0.21 0.13-0.37 0-0.18-0.03-0.3-0.09-0.37-0.06-0.06-0.2-0.09-0.4-0.09h-1.42v2.69c0 0.24-0.12 0.36-0.37 0.36-0.24 0-0.36-0.12-0.36-0.36zm4.93-0.38h0.01c0.24 0 0.36 0.12 0.36 0.35v3.03c0 0.24-0.13 0.36-0.37 0.36s-0.36-0.12-0.36-0.36v-3.03c0-0.23 0.12-0.35 0.36-0.35zm1.99 0.04h0.23c0.1 0 0.18 0.04 0.25 0.1l2.36 2.43v-2.21c0-0.24 0.13-0.36 0.37-0.36s0.36 0.12 0.36 0.36v3.12c0 0.18-0.09 0.26-0.26 0.26s-0.32-0.06-0.44-0.19l-2.96-3.05a0.332 0.332 0 0 1-0.12-0.25c0-0.14 0.07-0.21 0.21-0.21zm-0.02 1.18 0.44 0.44c0.07 0.07 0.1 0.16 0.1 0.25v1.46c0 0.25-0.12 0.37-0.36 0.37s-0.37-0.12-0.37-0.37v-2.08c0-0.1 0.03-0.15 0.07-0.15 0.03 0 0.07 0.03 0.12 0.08zm4.44-0.86c0-0.22 0.11-0.33 0.33-0.33h3.04c0.22 0 0.33 0.11 0.33 0.34 0 0.22-0.11 0.32-0.33 0.32h-3.04c-0.22 0-0.33-0.11-0.33-0.33zm1.48 3.02v-1.92c0-0.24 0.12-0.35 0.36-0.35h0.02c0.23 0 0.35 0.11 0.35 0.35v1.92c0 0.24-0.12 0.36-0.37 0.36-0.24 0-0.36-0.12-0.36-0.36zm-17.94-4.12h2.49c0.45 0 0.8 0.12 1.04 0.35 0.24 0.24 0.36 0.58 0.36 1.03 0 0.46-0.12 0.81-0.36 1.06-0.24 0.24-0.59 0.37-1.04 0.37h-1.23c-0.23 0-0.35-0.13-0.35-0.37s0.12-0.35 0.35-0.35h1.15c0.26 0 0.44-0.05 0.54-0.15 0.09-0.1 0.14-0.28 0.14-0.54 0-0.13-0.01-0.24-0.03-0.33s-0.05-0.16-0.11-0.21a0.422 0.422 0 0 0-0.21-0.11c-0.08-0.02-0.19-0.03-0.33-0.03h-2.01v3.38c0 0.25-0.14 0.38-0.42 0.38-0.25 0-0.38-0.13-0.38-0.38v-3.7c0-0.27 0.13-0.4 0.4-0.4zm-17.74 6.72c0 0.22-0.08 0.42-0.23 0.57-0.16 0.16-0.35 0.24-0.58 0.24-0.14 0-0.28-0.04-0.4-0.11v0.94h-0.41v-1.64c0-0.23 0.08-0.42 0.23-0.58 0.16-0.16 0.35-0.23 0.58-0.23s0.42 0.07 0.58 0.23c0.15 0.16 0.23 0.35 0.23 0.58zm-0.41 0c0-0.11-0.04-0.21-0.11-0.29a0.391 0.391 0 0 0-0.29-0.11c-0.11 0-0.2 0.03-0.28 0.11a0.4 0.4 0 0 0 0 0.57 0.4 0.4 0 0 0 0.57 0 0.41 0.41 0 0 0 0.11-0.28zm2.58 0.76h-0.44l-0.08-0.23c-0.16 0.19-0.37 0.28-0.62 0.28-0.23 0-0.42-0.08-0.58-0.24a0.74 0.74 0 0 1-0.23-0.57c0-0.17 0.04-0.33 0.14-0.47s0.23-0.23 0.39-0.29c0.1-0.04 0.19-0.05 0.28-0.05 0.17 0 0.33 0.04 0.47 0.14s0.23 0.23 0.29 0.39zm-0.74-0.76a0.39 0.39 0 0 0-0.17-0.33 0.39 0.39 0 0 0-0.23-0.07c-0.14 0-0.25 0.05-0.33 0.17a0.39 0.39 0 0 0-0.07 0.23c0 0.14 0.05 0.25 0.17 0.33 0.07 0.05 0.15 0.07 0.23 0.07 0.11 0 0.2-0.04 0.28-0.12 0.08-0.07 0.12-0.17 0.12-0.28zm2.19 0.3c0 0.09-0.04 0.18-0.1 0.26-0.13 0.16-0.31 0.24-0.55 0.23-0.1 0-0.2-0.02-0.32-0.06a0.718 0.718 0 0 1-0.27-0.16l0.23-0.29c0.11 0.1 0.22 0.15 0.35 0.15h0.01c0.05 0 0.09 0 0.13-0.02 0.06-0.03 0.08-0.06 0.08-0.11v-0.01c0-0.04-0.04-0.08-0.09-0.1-0.02 0-0.07-0.01-0.14-0.03-0.1-0.01-0.17-0.04-0.24-0.06a0.425 0.425 0 0 1-0.27-0.42c0-0.19 0.09-0.33 0.28-0.43 0.08-0.04 0.17-0.06 0.27-0.06 0.1-0.01 0.21 0.01 0.32 0.05 0.12 0.04 0.21 0.1 0.26 0.16l-0.27 0.25a0.333 0.333 0 0 0-0.23-0.11c-0.13 0-0.19 0.04-0.19 0.13v0.01c0 0.04 0.05 0.07 0.15 0.1 0.01 0 0.08 0.01 0.2 0.04 0.26 0.05 0.39 0.2 0.39 0.47zm0.66-1.46c0 0.07-0.03 0.12-0.07 0.17-0.05 0.05-0.11 0.07-0.18 0.07a0.22 0.22 0 0 1-0.17-0.07 0.22 0.22 0 0 1-0.07-0.17c0-0.07 0.02-0.13 0.07-0.18 0.05-0.04 0.1-0.07 0.17-0.07s0.13 0.03 0.17 0.07c0.05 0.05 0.08 0.11 0.08 0.18zm-0.04 1.92h-0.41v-1.57h0.41zm1.84-0.76a0.8 0.8 0 0 1-0.82 0.81c-0.22 0-0.42-0.08-0.57-0.24a0.763 0.763 0 0 1-0.24-0.57v-0.81h0.41v0.81c0 0.11 0.04 0.2 0.12 0.28a0.4 0.4 0 0 0 0.57 0c0.08-0.08 0.12-0.17 0.12-0.28v-0.81h0.41zm1.84 0.76h-0.41v-0.76a0.4 0.4 0 0 0-0.12-0.29 0.436 0.436 0 0 0-0.29-0.11c-0.11 0-0.2 0.03-0.28 0.11s-0.12 0.17-0.12 0.29v0.76h-0.41v-0.76c0-0.23 0.08-0.42 0.24-0.58 0.15-0.16 0.35-0.23 0.57-0.23 0.23 0 0.42 0.07 0.58 0.23s0.24 0.35 0.24 0.58zm1.83-0.77c0 0.04 0 0.09-0.01 0.13h-1.18c0.02 0.08 0.07 0.15 0.14 0.2a0.407 0.407 0 0 0 0.55-0.06l0.25 0.33c-0.16 0.14-0.34 0.22-0.56 0.22-0.23 0-0.42-0.08-0.58-0.24a0.793 0.793 0 0 1-0.23-0.57c0-0.23 0.08-0.42 0.23-0.58 0.16-0.16 0.35-0.23 0.58-0.23s0.42 0.07 0.58 0.23c0.15 0.16 0.23 0.35 0.23 0.57zm-0.46-0.18a0.365 0.365 0 0 0-0.35-0.21c-0.16 0-0.28 0.07-0.35 0.21zm3.63 0.19c0 0.22-0.08 0.42-0.23 0.57-0.16 0.16-0.35 0.24-0.58 0.24-0.14 0-0.28-0.04-0.4-0.11v0.94h-0.41v-1.64c0-0.23 0.08-0.42 0.23-0.58 0.16-0.16 0.35-0.23 0.58-0.23s0.42 0.07 0.58 0.23c0.15 0.16 0.23 0.35 0.23 0.58zm-0.41 0c0-0.11-0.04-0.21-0.11-0.29a0.391 0.391 0 0 0-0.29-0.11c-0.11 0-0.21 0.03-0.28 0.11a0.4 0.4 0 0 0 0 0.57c0.07 0.08 0.17 0.12 0.28 0.12a0.4 0.4 0 0 0 0.29-0.12 0.41 0.41 0 0 0 0.11-0.28zm2.25-0.01c0 0.04 0 0.09-0.01 0.13h-1.19a0.426 0.426 0 0 0 0.39 0.28c0.12 0 0.22-0.05 0.3-0.14l0.25 0.33c-0.15 0.14-0.34 0.22-0.55 0.22a0.77 0.77 0 0 1-0.58-0.24 0.763 0.763 0 0 1-0.24-0.57c0-0.23 0.08-0.42 0.24-0.58s0.35-0.23 0.58-0.23c0.22 0 0.42 0.07 0.57 0.23 0.16 0.16 0.24 0.35 0.24 0.57zm-0.46-0.18a0.375 0.375 0 0 0-0.35-0.21 0.38 0.38 0 0 0-0.36 0.21zm2.3 0.95h-0.41v-0.76a0.4 0.4 0 0 0-0.12-0.29 0.41 0.41 0 0 0-0.28-0.11c-0.12 0-0.21 0.03-0.29 0.11s-0.12 0.17-0.12 0.29v0.76h-0.41v-0.76c0-0.23 0.08-0.42 0.24-0.58s0.35-0.23 0.58-0.23c0.22 0 0.41 0.07 0.57 0.23s0.24 0.35 0.24 0.58zm1.02 0c-0.22 0-0.42-0.08-0.57-0.23a0.785 0.785 0 0 1-0.24-0.58v-1.59h0.41v0.83h0.4v0.35h-0.4v0.41c0 0.11 0.04 0.21 0.12 0.29 0.08 0.07 0.17 0.11 0.28 0.11zm1.03-1.17c-0.12 0-0.21 0.04-0.29 0.12s-0.12 0.17-0.12 0.29v0.76h-0.41v-0.76c0-0.23 0.08-0.42 0.24-0.58s0.35-0.23 0.58-0.23zm1.83 0.41a0.8 0.8 0 0 1-0.82 0.81c-0.22 0-0.42-0.08-0.57-0.24a0.763 0.763 0 0 1-0.24-0.57v-0.81h0.41v0.81c0 0.11 0.04 0.2 0.12 0.28 0.07 0.08 0.17 0.12 0.28 0.12a0.4 0.4 0 0 0 0.29-0.12c0.08-0.08 0.12-0.17 0.12-0.28v-0.81h0.41zm3.16 0c0 0.22-0.07 0.42-0.23 0.57-0.16 0.16-0.35 0.24-0.58 0.24-0.14 0-0.28-0.04-0.4-0.11v0.94h-0.41v-1.64c0-0.23 0.08-0.42 0.23-0.58 0.16-0.16 0.35-0.23 0.58-0.23s0.42 0.07 0.58 0.23 0.23 0.35 0.23 0.58zm-0.41 0c0-0.11-0.03-0.21-0.11-0.29a0.391 0.391 0 0 0-0.29-0.11c-0.11 0-0.2 0.03-0.28 0.11a0.4 0.4 0 0 0 0 0.57 0.4 0.4 0 0 0 0.57 0 0.37 0.37 0 0 0 0.11-0.28zm1.44-0.41a0.4 0.4 0 0 0-0.29 0.12c-0.08 0.08-0.12 0.17-0.12 0.29v0.76h-0.41v-0.76c0-0.23 0.08-0.42 0.24-0.58s0.35-0.23 0.58-0.23zm0.65-0.75c0 0.07-0.02 0.12-0.07 0.17s-0.11 0.07-0.17 0.07c-0.07 0-0.13-0.02-0.18-0.07a0.22 0.22 0 0 1-0.07-0.17c0-0.07 0.02-0.13 0.07-0.18 0.05-0.04 0.11-0.07 0.18-0.07 0.06 0 0.12 0.03 0.17 0.07 0.05 0.05 0.07 0.11 0.07 0.18zm-0.04 1.92h-0.41v-1.57h0.41zm1.84 0h-0.41v-0.76a0.4 0.4 0 0 0-0.12-0.29 0.41 0.41 0 0 0-0.28-0.11 0.391 0.391 0 0 0-0.41 0.4v0.76h-0.41v-0.76c0-0.23 0.08-0.42 0.24-0.58s0.35-0.23 0.58-0.23c0.22 0 0.42 0.07 0.57 0.23 0.16 0.16 0.24 0.35 0.24 0.58zm1.03 0c-0.23 0-0.42-0.08-0.58-0.23a0.785 0.785 0 0 1-0.24-0.58v-1.59h0.41v0.83h0.41v0.35h-0.41v0.41c0 0.11 0.04 0.21 0.12 0.29 0.08 0.07 0.17 0.11 0.29 0.11z" />
                </g>
            </svg>
        </div>
        <div class="contact-info small-text">
            <p>Str. Roman Mușat, Nr. 21, Roman</p>
            <p>(lângă Biblioteca Municipală și Farm. 32)</p>
            <p>&#x1F57B 0753 581 170 </p>
            <p>&#9993 colorprint_roman@yahoo.com</p>
            <p>Program: Luni - Vineri: 08:00 – 18:00</p>
            <p>Sâmbătă: 09:00 – 12:00 Duminică: ÎNCHIS</p>
            <p>-------------VĂ MULŢUMIM!------------</p>

        </div>
    </div>
    <br><br>
    <footer class="no-print">
        <p style="font-size: larger;">© Color Print</p>
        <a href="dashboard.php" style="text-decoration: none; color: white;">Dashboard</a>
        <a href="archive.php" style="text-decoration: none; color: white;">Arhivă</a>
        <a href="unpaid_orders.php" style="text-decoration: none; color: white;">Comenzi nefacturate</a>
    </footer>

</body>

</html>