<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

include 'db.php';

$order_id = $_GET['id'] ?? 0;
if (!$order_id) {
    echo "Invalid order ID";
    exit;
}

/* ============================
   FETCH ORDER + CLIENT DETAILS
   ============================ */
$sql = "SELECT 
            o.order_id,
            o.detalii_suplimentare,
            o.avans,
            c.client_name,
            c.client_phone,
            c.client_email
        FROM orders o
        JOIN clients c ON o.client_id = c.client_id
        WHERE o.order_id = ?";

$stmt = $conn->prepare($sql);
if (!$stmt) {
    echo "SQL ERROR (order): " . $conn->error;
    exit;
}
$stmt->bind_param("i", $order_id);
$stmt->execute();
$order = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$order) {
    echo "Order not found";
    exit;
}

/* ============================
   FETCH ORDER ARTICLES (correct schema)
   ============================ */
$articles_sql = "SELECT 
                    oa.id,
                    a.name,
                    oa.quantity,
                    oa.price_per_unit
                 FROM order_articles oa
                 JOIN articles a ON oa.article_id = a.id
                 WHERE oa.order_id = ?";

$stmt = $conn->prepare($articles_sql);
if (!$stmt) {
    echo "SQL ERROR (articles): " . $conn->error;
    exit;
}
$stmt->bind_param("i", $order_id);
$stmt->execute();
$articles = $stmt->get_result();
$stmt->close();

/* ============================
   CALCULATE TOTAL
   ============================ */
$total = 0;
foreach ($articles as $a) {
    $total += $a['quantity'] * $a['price_per_unit'];
}
$remaining = $total - ($order['avans'] ?? 0);

/* ============================
   SANITIZE PHONE FOR WHATSAPP
   ============================ */
$clean_phone = preg_replace('/[^0-9]/', '', $order['client_phone']);

// If number starts with 0 → convert to 40xxxxxxxxx
if (preg_match('/^0\d{9}$/', $clean_phone)) {
    $clean_phone = '4' . $clean_phone;
}

// If number starts with 7 → assume Romanian mobile → add 40
if (preg_match('/^7\d{8}$/', $clean_phone)) {
    $clean_phone = '40' . $clean_phone;
}
?>

<div style="font-family:Poppins; font-size:14px; padding:5px; line-height:1.4;">

    <strong><?= htmlspecialchars($order['client_name']) ?></strong><br>

    📞 <?= htmlspecialchars($order['client_phone']) ?>
    &nbsp;•&nbsp;
    <a href="https://wa.me/<?= $clean_phone ?>"
        target="_blank"
        style="color:#25D366; font-weight:bold;">
        WhatsApp
    </a>
    <br>
    <br>

    <strong>Articole:</strong><br>
    <?php if ($articles->num_rows > 0): ?>
        <ul style="margin:6px 0 0 18px; padding:0;">
            <?php foreach ($articles as $a): ?>
                <li>
                    <?= htmlspecialchars($a['name']) ?>
                    (<?= $a['quantity'] ?> × <?= $a['price_per_unit'] ?> lei)
                </li>
            <?php endforeach; ?>
        </ul>
    <?php else: ?>
        <em>Fără articole</em>
    <?php endif; ?>

    <br>

    <strong>Total:</strong> <?= number_format($remaining, 2) ?> lei
</div>