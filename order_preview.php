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

<style>
    /* Custom dark gradient theme for Tippy preview */
    .tippy-box[data-theme~='order-preview'] {
        background: linear-gradient(135deg,
                rgba(0, 0, 0, 0.95) 0%,
                /* 95% opaque */
                rgba(60, 60, 60, 0.95) 50%,
                /* 95% opaque */
                rgba(108, 108, 108, 0.95) 100%
                /* 95% opaque */
            );
        color: #fff;
        border: 1px solid rgba(255, 255, 0, 0.5);
        /* subtle yellow border like your UI */
        border-radius: 12px;
        padding: 10px;
        box-shadow: 0 4px 12px rgba(0, 0, 0, 0.6);
        font-family: 'Poppins', sans-serif;
    }

    /* Hide arrow for this theme */
    .tippy-box[data-theme~='order-preview'] .tippy-arrow {
        display: none !important;
    }

    /* Arrow color */
    .tippy-box[data-theme~='order-preview'] .tippy-arrow {
        color: #3c3c3c;
    }

    /* Custom animation for order preview */
    .tippy-box[data-theme~='order-preview'] {
        transition: transform 0.25s ease, opacity 0.25s ease;
        transform-origin: top center;
    }

    .tippy-box[data-state='hidden'] {
        opacity: 0;
        transform: translateY(-6px) scale(0.96);
    }

    .tippy-box[data-state='visible'] {
        opacity: 1;
        transform: translateY(0) scale(1);
    }

    /* Custom animation for order preview */
    .tippy-box[data-theme~='order-preview'] {
        transition: transform 0.25s ease, opacity 0.25s ease;
        transform-origin: top center;
    }

    .tippy-box[data-state='hidden'] {
        opacity: 0;
        transform: translateY(-6px) scale(0.96);
    }

    .tippy-box[data-state='visible'] {
        opacity: 1;
        transform: translateY(0) scale(1);
    }
</style>

<div class="order-preview-content" data-order-id="<?= (int)$order_id ?>" style="font-family:Poppins; font-size:14px; padding:5px; line-height:1.4;">

    <strong id="clientNameText"><?= htmlspecialchars($order['client_name']) ?></strong><br>

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
        <em style="font-size: 12px;">Fără articole</em>
    <?php endif; ?>

    <br>

    <strong class="total-text">Total: <?= number_format($remaining, 2) ?> lei</strong>

    <hr style="border: 0; border-top: 1px solid rgba(255,255,255,0.1); margin: 10px 0;">

    <div class="actions" style="display: flex; flex-wrap: wrap; gap: 5px; justify-content: center;">
        <button id="printBtn" class="action-btn" title="Print Order" style="background: #555; color: white; border: none; padding: 4px 8px; border-radius: 4px; cursor: pointer; font-size: 11px;">
            <i class="fa-solid fa-print"></i> Print
        </button>

        <button id="finishBtn" class="action-btn" title="Mark as Completed" style="background: #2ecc71; color: white; border: none; padding: 4px 8px; border: none; border-radius: 4px; cursor: pointer; font-size: 11px;">
            <i class="fa-solid fa-flag-checkered"></i> Terminată
        </button>

        <button id="deliverBtn" class="action-btn" title="Mark as Delivered" style="background: #3498db; color: white; border: none; padding: 4px 8px; border: none; border-radius: 4px; cursor: pointer; font-size: 11px;">
            <i class="fa-solid fa-truck-ramp-box"></i> Livrată
        </button>
    </div>

    <!--
        No inline <script> here on purpose: this HTML is fetched by dashboard.php
        and injected into a Tippy popup via instance.setContent(), which sets
        innerHTML — browsers never execute <script> tags inserted that way.
        The click handlers for #finishBtn / #deliverBtn / #printBtn are bound
        from dashboard.php's tippy onShow callback, against this same live DOM
        (Tippy appends the popup to document.body, so it's not an iframe —
        real listeners work fine there). Cancel isn't offered here on purpose —
        it stays a full-page-only action on view_order.php.
    -->
</div>