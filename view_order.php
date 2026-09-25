<?php
session_start();
error_reporting(E_ALL);
ini_set('display_errors', 1);

include 'db.php';

$order_id = $_GET['order_id'] ?? null;
if (!$order_id) {
    echo "Order ID not provided.";
    exit();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_user'])) {
    $assigned_to = $_POST['assigned_to'];
    $update_sql = "UPDATE orders SET assigned_to = ? WHERE order_id = ?";
    $stmt = $conn->prepare($update_sql);
    $stmt->bind_param("ii", $assigned_to, $order_id);

    if ($stmt->execute()) {
        $_SESSION['flash_success'] = "Reatribuire realizată!";
    } else {
        $_SESSION['flash_error'] = "Eroare la actualizarea utilizatorului: " . $stmt->error;
    }
    $stmt->close();

    $return = $_GET['return'] ?? ($_POST['return'] ?? '');
    $returnParam = $return ? "&return=" . urlencode($return) : '';
    header("Location: view_order.php?order_id=$order_id$returnParam");
    exit();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_due_date'])) {
    $new_due_date = $_POST['new_due_date'];
    $update_due_sql = "UPDATE orders SET due_date = ? WHERE order_id = ?";
    $stmt = $conn->prepare($update_due_sql);
    $stmt->bind_param("si", $new_due_date, $order_id);

    if ($stmt->execute()) {
        $_SESSION['flash_success'] = "Data scadentă a fost actualizată!";
        $return = $_GET['return'] ?? ($_POST['return'] ?? '');
        $returnParam = $return ? "&return=" . urlencode($return) : '';
        header("Location: view_order.php?order_id=$order_id$returnParam");
        exit();
    } else {
        $_SESSION['flash_error'] = "Eroare la actualizarea datei: " . $stmt->error;
        $return = $_GET['return'] ?? ($_POST['return'] ?? '');
        $returnParam = $return ? "&return=" . urlencode($return) : '';
        header("Location: view_order.php?order_id=$order_id$returnParam");
        exit();
    }

    $stmt->close();
}

$order_sql = "SELECT o.*,
                   u.username as assigned_user,
                   cu.username as created_user,
                   c.client_name,
                   c.client_phone,
                   c.client_email
              FROM orders o
              LEFT JOIN users u  ON u.user_id  = o.assigned_to
              LEFT JOIN users cu ON cu.user_id = o.created_by
              LEFT JOIN clients c ON c.client_id = o.client_id
              WHERE o.order_id = ?";
$stmt = $conn->prepare($order_sql);
$stmt->bind_param("i", $order_id);
$stmt->execute();
$order_result = $stmt->get_result();
$order = $order_result->fetch_assoc();
$stmt->close();

if (!$order) {
    echo "Comanda nu a fost găsită.";
    exit();
}

$client_name  = $order['client_name']  ?? 'Unknown';
$client_phone = $order['client_phone'] ?? 'Unknown';
$client_email = $order['client_email'] ?? 'Unknown';

include 'get_operators.php';

$returnUrl = $_GET['return'] ?? 'dashboard.php';
$returnHidden = htmlspecialchars($_GET['return'] ?? '', ENT_QUOTES);
$isEmbedded = isset($_GET['embedded']);
$isCancelled = ($order['status'] === 'cancelled');
$isCompleted = ($order['status'] === 'completed' || $order['status'] === 'delivered');
$isDelivered = ($order['status'] === 'delivered');
$isLocked = $isDelivered || $isCancelled;
$inProgress = !$isCancelled;
$orderIdPad = str_pad((string)$order['order_id'], 3, '0', STR_PAD_LEFT);

$rawDue = $order['due_date'] ?? null;
$dueDateIso = null;
if ($rawDue) {
    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $rawDue)) {
        $normalized = $rawDue . ' 18:00:00';
    } else {
        $normalized = $rawDue;
    }
    try {
        $tz = new DateTimeZone(date_default_timezone_get());
        $dt = new DateTimeImmutable($normalized, $tz);
        $dueDateIso = $dt->format(DateTime::ATOM);
    } catch (Exception $e) {
        error_log('Invalid due_date: ' . $rawDue . ' — ' . $e->getMessage());
        $dueDateIso = null;
    }
}
$serverNowIso = (new DateTimeImmutable('now', new DateTimeZone(date_default_timezone_get())))->format(DateTime::ATOM);

$countryCode = "+4";
$waNumber = $countryCode . preg_replace('/\D/', '', $client_phone);
$waLink = "https://wa.me/" . urlencode($waNumber);

$stmt = $conn->prepare("
    SELECT oa.id, a.name, oa.quantity, oa.price_per_unit
    FROM order_articles oa
    JOIN articles a ON oa.article_id = a.id
    WHERE oa.order_id = ?
");
$stmt->bind_param('i', $order_id);
$stmt->execute();
$articles_result = $stmt->get_result();
$article_rows = [];
$hasRows = false;
$subtotal = 0;
if ($articles_result) {
    while ($row = $articles_result->fetch_assoc()) {
        $hasRows = true;
        $subtotal += $row['quantity'] * $row['price_per_unit'];
        $article_rows[] = $row;
    }
}
$stmt->close();

$stmt = $conn->prepare("SELECT * FROM order_attachments WHERE order_id = ?");
$stmt->bind_param("i", $order_id);
$stmt->execute();
$attachments_result = $stmt->get_result();
$attachments = [];
while ($row = $attachments_result->fetch_assoc()) {
    $attachments[] = $row;
}
$stmt->close();

$stepAssignedDone = $inProgress;
$stepCompletedDone = $isCompleted;
$stepDeliveredDone = $isDelivered;
?>
<!DOCTYPE html>
<html class="view-order<?= $isEmbedded ? ' is-embedded' : '' ?>" lang="ro">
<head>
    <title>Comanda #<?= htmlspecialchars($orderIdPad) ?></title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <link rel="stylesheet" type="text/css" href="styles.css">
    <link rel="stylesheet" type="text/css" href="view_order.css">
    <link rel="icon" type="image/png" href="https://color-print.ro/magazincp/favicon.png" />
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.min.css">
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/select2/4.0.13/css/select2.min.css" rel="stylesheet" />
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/dropzone/5.9.3/min/dropzone.min.css">
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/dropzone/5.9.3/min/dropzone.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/select2/4.0.13/js/select2.min.js"></script>
    <style>
        .swal2-styled.swal2-confirm { background: #ffed00 !important; color: #141414 !important; border: none !important; font-weight: 600; }
        .swal2-styled.swal2-cancel { background: #555 !important; color: #fff !important; border: none !important; }
        .select2-container--default .select2-selection--single {
            background: #fffcf6; border: 1px solid rgba(26,24,20,.12); border-radius: 8px; height: 40px;
        }
        .select2-container--default .select2-selection--single .select2-selection__rendered {
            line-height: 40px; padding-left: 12px; color: #1a1814;
        }
        .select2-container--default .select2-selection--single .select2-selection__arrow { height: 38px; }
        .select2-container--default .select2-results__option--highlighted[aria-selected] {
            background-color: #ffed00; color: #141414;
        }
    </style>
</head>
<body class="view-order<?= $isEmbedded ? ' is-embedded' : '' ?>">

<div class="vo-shell no-print">
    <header class="vo-header">
        <p class="vo-kicker">Comanda</p>
        <div class="vo-title-row">
            <div>
                <h2>Comanda nr. <strong class="order_id_large">#<?= htmlspecialchars($orderIdPad) ?></strong></h2>
                <div class="vo-badges">
                    <div id="achitatContainer-<?= (int)$order['order_id'] ?>">
                        <?php if ((int)$order['is_achitat'] === 1): ?>
                            <h2 class="achitatBadge">Achitată</h2>
                        <?php endif; ?>
                    </div>
                    <?php if ((int)$order['is_pinned'] === 1): ?>
                        <span class="vo-chip vo-chip-muted">Fixată</span>
                    <?php endif; ?>
                </div>
                <!-- SLA countdown: moved up into the header's title column -->
                <div id="slaContainer" class="vo-sla">
                    <div id="slaBadge" aria-hidden="true"></div>
                    <div id="slaTimer" aria-live="polite">—</div>
                </div>
            </div>
            <!-- Print (id/class kept for the Ctrl+P handlers): moved out of the footer into the header's top-right corner -->
            <button type="button" id="printBtn" class="vo-btn vo-btn-yellow print-button" onclick="printOrder()"><i class="fa-solid fa-print"></i> Print</button>
        </div>

        <ol class="vo-stepper status-stepper" aria-label="Status comandă">
            <li class="vo-step is-done">
                <div id="step-created-circle" class="vo-step-circle"><i class="fa-solid fa-check"></i></div>
                <span>Creată</span>
            </li>
            <li class="vo-step<?= $stepAssignedDone ? ' is-done' : '' ?><?= !$isCompleted && $inProgress ? ' is-current' : '' ?>">
                <div id="step-inprogress-circle" class="vo-step-circle">
                    <?= $stepAssignedDone ? '<i class="fa-solid fa-hammer"></i>' : '2' ?>
                </div>
                <span>În lucru</span>
            </li>
            <li class="vo-step<?= $stepCompletedDone ? ' is-done' : '' ?><?= $order['status'] === 'completed' ? ' is-current' : '' ?>">
                <div id="step-completed-circle" class="vo-step-circle">
                    <?= $stepCompletedDone ? '<i class="fa-solid fa-flag"></i>' : '3' ?>
                </div>
                <span>Terminată</span>
            </li>
            <li class="vo-step<?= $stepDeliveredDone ? ' is-done' : '' ?><?= $isDelivered ? ' is-current' : '' ?>">
                <div id="step-delivered-circle" class="vo-step-circle">
                    <?= $stepDeliveredDone ? '<i class="fa-solid fa-truck"></i>' : '4' ?>
                </div>
                <span>Livrată</span>
            </li>
        </ol>
    </header>

    <div class="vo-body">
        <div class="vo-grid-2">
            <section class="vo-card">
                <p class="vo-label">Client</p>
                <p class="vo-name"><?= htmlspecialchars($client_name) ?></p>
                <div class="vo-phone">
                    <span><?= htmlspecialchars($client_phone) ?></span>
                    <a href="<?= htmlspecialchars($waLink) ?>" target="_blank" rel="noreferrer" class="whatsapp-icon" aria-label="WhatsApp">
                        <i class="fab fa-whatsapp"></i>
                    </a>
                </div>
                <?php if (!empty($client_email) && $client_email !== 'Unknown'): ?>
                    <p><?= htmlspecialchars($client_email) ?></p>
                <?php endif; ?>
                <!-- Șabloane (WhatsApp templates): moved here from the footer -->
                <div id="templateMsgWidget" title="Trimite mesaj">
                    <i class="fa-brands fa-whatsapp"></i>
                    <span>Șabloane</span>
                </div>
            </section>
            <section class="vo-card">
                <p class="vo-label">Termen</p>
                <p class="vo-name"><?= date('d-m-Y', strtotime($order['due_date'])) ?> · 18:00</p>
                <p>Înregistrată <?= date('d-m-Y', strtotime($order['order_date'])) ?></p>
                <p>Operator <strong><?= htmlspecialchars(ucwords($order['assigned_user'] ?? '')) ?></strong>
                    · creată de <?= htmlspecialchars(ucwords($order['created_user'] ?? '')) ?></p>
            </section>
        </div>

        <section class="vo-card">
            <div class="vo-card-head">
                <h3>Detalii comandă</h3>
                <?php if (!$isLocked): ?>
                    <button type="button" class="vo-btn" onclick="editOrderDetails()"><i class="fa-solid fa-pen-to-square"></i> Editează</button>
                    <button type="button" class="vo-btn vo-btn-ink" onclick="saveOrderDetails()" style="display:none;"><i class="fa-solid fa-floppy-disk"></i> Salvează</button>
                <?php endif; ?>
            </div>
            <p class="vo-details-text" id="order_details_text"><?= nl2br(htmlspecialchars($order['order_details'])) ?></p>
            <p class="vo-label" style="margin-top:12px">Detalii suplimentare</p>
            <p class="vo-details-text" id="detalii_suplimentare_text"><?= nl2br(htmlspecialchars($order['detalii_suplimentare'] ?? '')) ?></p>
            <textarea id="detalii_suplimentare_edit" style="display:none;" rows="5"><?= htmlspecialchars($order['detalii_suplimentare'] ?? '') ?></textarea>
        </section>

        <section class="vo-card">
            <h3>Bon</h3>
            <table id="bonTable">
                <thead>
                    <tr>
                        <th>Articol</th>
                        <th>Cant</th>
                        <th>Preț</th>
                        <th class="no-print">Șterge</th>
                    </tr>
                </thead>
                <tbody id="bonTableBody">
                <?php foreach ($article_rows as $row): ?>
                    <tr data-id="<?= (int)$row['id'] ?>">
                        <td><?= htmlspecialchars($row['name'], ENT_QUOTES, 'UTF-8') ?></td>
                        <td><?= (int)$row['quantity'] ?></td>
                        <td><?= number_format((float)$row['price_per_unit'], 2) ?></td>
                        <td class="no-print"><button type="button" class="removeArticle">✖</button></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            <p id="emptyNote" class="empty-note" <?= $hasRows ? 'style="display:none;"' : '' ?>>
                Bonul e gol — adaugă primul articol.
            </p>

            <div class="add-article-form">
                <form id="addArticleForm" method="post" action="add_article.php">
                    <input type="hidden" name="return" value="<?= $returnHidden ?>">
                    <select id="articleSelect" name="article_id" style="width:100%">
                        <option value="" disabled selected>Caută sau adaugă articol</option>
                    </select>
                    <div class="vo-price-row">
                        <input type="text" id="price" name="price" placeholder="Preț">
                        <button type="button" id="updateDefaultPriceBtn" title="Actualizează prețul implicit"><i class="fa-solid fa-pencil"></i></button>
                    </div>
                    <input required type="number" id="quantity" name="quantity" min="1" value="" placeholder="Cant">
                    <input type="hidden" name="order_id" value="<?= (int)$order_id ?>">
                    <button type="submit"><i class="fa-solid fa-circle-plus"></i> Adaugă</button>
                </form>
            </div>

            <div class="vo-totals">
                <div>
                    <span>Avans</span>
                    <span><span id="avans_text"><?= htmlspecialchars($order['avans']) ?></span> lei</span>
                </div>
                <input type="number" id="avans_edit" style="display:none;" value="<?= htmlspecialchars($order['avans']) ?>" step="0.01">
                <div class="vo-due" id="totalWrapper">
                    <span>De achitat</span>
                    <span id="totalPrice">0.00</span>
                </div>
            </div>
        </section>

        <section class="vo-card">
            <div class="vo-card-head">
                <h3>Atașamente</h3>
            </div>
            <div class="attachments-section">
                <form action="upload_attachment.php" class="dropzone" id="orderDropzone">
                    <input type="hidden" name="order_id" value="<?= (int)$order_id ?>">
                    <input type="hidden" name="return" value="<?= $returnHidden ?>">
                </form>
            </div>
            <ul id="attachmentsList">
                <?php foreach ($attachments as $row): ?>
                    <li id="attachment-<?= (int)$row['id'] ?>">
                        <a href="download_attachment.php?id=<?= (int)$row['id'] ?>"><?= htmlspecialchars($row['filename']) ?></a>
                        <button class="deleteAttachment" data-id="<?= (int)$row['id'] ?>" type="button">
                            <i class="fa fa-trash"></i>
                        </button>
                    </li>
                <?php endforeach; ?>
            </ul>
        </section>

        <?php if (!$isLocked): ?>
        <section class="vo-card vo-options">
            <div class="vo-grid-2">
                <form method="post" action="view_order.php?order_id=<?= (int)$order['order_id'] ?>">
                    <input type="hidden" name="return" value="<?= $returnHidden ?>">
                    <label for="assigned_to">Atribuie operatorului</label>
                    <select id="assigned_to" name="assigned_to">
                        <?php foreach ($operators as $user):
                            $selected = ((int)$order['assigned_to'] === (int)$user['user_id']) ? 'selected' : '';
                            echo "<option value='" . (int)$user['user_id'] . "' $selected>" . htmlspecialchars($user['username']) . "</option>";
                        endforeach; ?>
                    </select>
                    <button type="submit" name="update_user"><i class="fa-solid fa-people-arrows"></i> Reatribuire</button>
                </form>
                <form method="post" action="view_order.php?order_id=<?= (int)$order['order_id'] ?>">
                    <input type="hidden" name="return" value="<?= $returnHidden ?>">
                    <label for="new_due_date_select">Extinde termenul</label>
                    <select id="new_due_date_select" name="new_due_date"></select>
                    <button type="submit" name="update_due_date"><i class="fa-solid fa-clock-rotate-left"></i> Actualizează data</button>
                </form>
            </div>
        </section>
        <?php endif; ?>
    </div>

    <footer class="vo-footer">
        <div class="vo-footer-row">
            <?php if ((int)$order['is_pinned'] === 1): ?>
                <button type="button" class="vo-btn" onclick="togglePin(<?= (int)$order['order_id'] ?>, 0)"><i class="fa-solid fa-thumbtack"></i> Anulează pin</button>
            <?php else: ?>
                <button type="button" class="vo-btn" onclick="togglePin(<?= (int)$order['order_id'] ?>, 1)"><i class="fa-solid fa-thumbtack"></i> Fixează</button>
            <?php endif; ?>
            <button type="button" id="toggleComandaLucruButton" class="vo-btn" onclick="toggleComandaLucru()"><i class="fa-solid fa-spinner"></i> În lucru</button>
        </div>
        <div class="vo-footer-row">
            <?php if (!$isLocked): ?>
                <button
                    type="button"
                    id="toggleAchitatButton"
                    class="vo-btn vo-btn-ink"
                    data-order-id="<?= (int)$order['order_id'] ?>"
                    data-current-state="<?= (int)$order['is_achitat'] ?>">
                    <?= (int)$order['is_achitat']
                        ? '<i class="fa-solid fa-ban"></i> Neachitat'
                        : '<i class="fa-solid fa-sack-dollar"></i> Achitată' ?>
                </button>
            <?php endif; ?>
            <?php if ($order['status'] != 'completed' && $order['status'] != 'delivered' && $order['status'] != 'cancelled'): ?>
                <button type="button" id="finishButton" class="vo-btn vo-btn-ink" onclick="finishOrder()"><i class="fa-solid fa-flag"></i> Termină</button>
            <?php endif; ?>
            <?php if ($order['status'] != 'delivered' && $order['status'] != 'cancelled'): ?>
                <button type="button" id="deliverButton" class="vo-btn vo-btn-yellow" onclick="deliverOrder()"><i class="fa-solid fa-truck"></i> Livrare</button>
            <?php endif; ?>
            <button type="button" id="cancelButton" class="vo-btn vo-btn-danger" onclick="cancelOrder()" <?php if ($order['status'] == 'cancelled') echo 'style="display:none;"'; ?>><i class="fa-solid fa-ban"></i> Anulează</button>
        </div>
    </footer>
</div>

<!-- Thermal ticket: hidden on screen, used by window.print() -->
<div id="printArea">
    <h2>Comanda nr. <strong class="order_id_large"><?php echo (int)$order['order_id']; ?></strong></h2>
    <?php if ((int)$order['is_achitat'] === 1): ?>
        <h2 class="achitatBadge">Comandă achitată</h2>
    <?php endif; ?>
    <p><strong>Din data: </strong><?php echo date('d-m-Y', strtotime($order['order_date'])); ?></p>
    <p><strong>Termen: </strong><?php echo date('d-m-Y', strtotime($order['due_date'])); ?></p>
    <p><strong>Operator: </strong><?php echo htmlspecialchars(ucwords($order['assigned_user'] ?? '')); ?></p>
    <p><strong>Creată de: </strong><?php echo htmlspecialchars(ucwords($order['created_user'] ?? '')); ?></p>
    <p><strong>Nume client: </strong><?php echo htmlspecialchars($client_name); ?></p>
    <p><strong>Contact client: </strong><?php echo htmlspecialchars($client_phone); ?></p>
    <p><strong>Comanda initiala: </strong><br><?php echo nl2br(htmlspecialchars($order['order_details'])); ?></p>
    <p><strong>Detalii suplimentare: </strong><br><?php echo nl2br(htmlspecialchars($order['detalii_suplimentare'] ?? '')); ?></p>
    <?php if ($hasRows): ?>
        <table id="printBonTable">
            <thead>
                <tr><th>Articole</th><th>Cant</th><th>Preț</th></tr>
            </thead>
            <tbody id="printBonBody">
            <?php foreach ($article_rows as $row): ?>
                <tr>
                    <td><?= htmlspecialchars($row['name'], ENT_QUOTES, 'UTF-8') ?></td>
                    <td><?= (int)$row['quantity'] ?></td>
                    <td><?= number_format((float)$row['price_per_unit'], 2) ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    <?php else: ?>
        <table id="printBonTable" style="display:none;">
            <thead>
                <tr><th>Articole</th><th>Cant</th><th>Preț</th></tr>
            </thead>
            <tbody id="printBonBody"></tbody>
        </table>
    <?php endif; ?>
    <p><strong>Avans: </strong><span id="printAvans"><?php echo htmlspecialchars($order['avans']); ?></span> lei</p>
    <p><strong>Sumă de achitat:</strong> <span id="printTotal"><?= number_format(max(0, $subtotal - (float)$order['avans']), 2) ?></span> lei</p>
    <p><img src="comenzi.svg" alt="Color Print" height="48"></p>
    <div class="contact-info small-text">
        <p>Str. Roman Mușat, Nr. 21, Roman</p>
        <p>(lângă Biblioteca Municipală și Farm. 32)</p>
        <p>0753 581 170</p>
        <p>colorprint_roman@yahoo.com</p>
        <p>Program: Luni - Vineri: 08:00 – 18:00</p>
        <p>Sâmbătă: 09:00 – 12:00 Duminică: ÎNCHIS</p>
        <p>-------------VĂ MULŢUMIM!------------</p>
    </div>
</div>

<div id="templateMsgModal" class="modal">
    <div class="whatsapp-modal">
        <div class="whatsapp-header">
            <h4><i class="fa-brands fa-whatsapp"></i> Mesaj Template</h4>
            <button class="whatsapp-close-btn" id="closeTemplateMsg" type="button">&times;</button>
        </div>
        <div class="whatsapp-body">
            <div class="form-group">
                <label for="templateSelect">Alege șablon:</label>
                <select id="templateSelect" class="form-control">
                    <option value="">— Selectează —</option>
                    <option value="Bună ziua {{client}}, comanda dvs. #{{order}} este terminată. Vă așteptăm la Color Print pentru ridicarea comenzii.">Comandă terminată</option>
                    <option value="Bună ziua {{client}}, comanda dvs. #{{order}} este pregătită pentru ridicare. Vă așteptăm la Color Print.">Reminder: Comandă pregătită pentru ridicare</option>
                    <option value="Bună ziua {{client}}, comanda dumneavoastră #{{order}} este pregătită pentru ridicare la Color Print. Vă rugăm să o ridicați cât mai curând. Vă mulțumim.">Reminder: Comandă neridicată 2</option>
                    <option value="Bună ziua, înainte să începem comanda dvs. #{{order}}, vă rugăm să analizați cu atenție simularea grafică.

După confirmarea acestora prin transmiterea bunului de tipar (BT), vom considera că toate informațiile (dimensiuni, grafici, texte, culori, poziționări) au fost verificate și aprobate de Dumneavoastră.

După primirea bunului de tipar, firma noastră este absolvită de orice responsabilitate privind eventuale erori sau neconcordanțe care nu au fost semnalate anterior.

Orice greșeală omisă sau nerevizuită de client înainte de aprobare intră exclusiv în sarcina acestuia.

Vă mulțumim pentru colaborare și încredere!">Confirmare bun de tipar</option>
                    <option value="Bună ziua {{client}}, comanda dvs. #{{order}} este în lucru. Vă anunțăm imediat ce este gata.">Comandă în lucru</option>
                    <option value="Bună ziua {{client}}, comanda dvs. #{{order}} necesită puțin timp suplimentar. Revenim cu un mesaj imediat ce este gata.">Comandă întârziată</option>
                    <option value="Bună ziua {{client}}, o parte din comanda dvs. #{{order}} este gata. Vă anunțăm imediat ce finalizăm și restul.">Comandă finalizată parțial</option>
                </select>
            </div>
            <div class="form-group text-center">
                <label for="templateMessage">Mesaj</label>
                <textarea id="templateMessage" rows="5" placeholder="Selectează un șablon sau scrie text" style="max-width:500px;width:100%;"></textarea>
            </div>
            <button type="button" id="sendTemplateMsgBtn">
                <i class="fa-brands fa-whatsapp"></i> Trimite mesaj
            </button>
        </div>
    </div>
</div>

<div id="viewOrderDataBridge"
    data-order-id="<?= (int)$order_id ?>"
    data-assigned-to="<?= htmlspecialchars($order['assigned_user'] ?? '', ENT_QUOTES) ?>"
    data-client-name="<?= htmlspecialchars($client_name ?? '', ENT_QUOTES) ?>"
    data-boss="<?= htmlspecialchars($order['created_user'] ?? '', ENT_QUOTES) ?>"
    data-client-phone="<?= htmlspecialchars($client_phone ?? '', ENT_QUOTES) ?>"
    data-wa-link="<?= htmlspecialchars($waLink ?? '', ENT_QUOTES) ?>"
    data-due-date-iso="<?= htmlspecialchars($dueDateIso ?? '', ENT_QUOTES) ?>"
    data-server-now-iso="<?= htmlspecialchars($serverNowIso ?? '', ENT_QUOTES) ?>"
    data-order-completed="<?= $isCompleted ? '1' : '0' ?>"
    data-order-delivered="<?= $order['status'] === 'delivered' ? '1' : '0' ?>"
    <?php if (!empty($_SESSION['flash_success'])):
        echo 'data-flash-success="' . htmlspecialchars($_SESSION['flash_success'], ENT_QUOTES) . '"';
        unset($_SESSION['flash_success']);
    endif; ?>
    <?php if (!empty($_SESSION['flash_error'])):
        echo 'data-flash-error="' . htmlspecialchars($_SESSION['flash_error'], ENT_QUOTES) . '"';
        unset($_SESSION['flash_error']);
    endif; ?>
    style="display:none;"></div>

<script src="script.js"></script>
</body>
</html>
