<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

include 'db.php';

$order_id = $_GET['id'] ?? 0;
if (!$order_id) {
    echo "Invalid order ID";
    exit;
}

/* Theme map mirrors .heavy-row accents in styles.css */
$theme_map = [
    'yellow'  => '#ffde00', /* În lucru */
    'cyan'    => '#00aeef', /* Atribuită */
    'magenta' => '#ff0000', /* Întârziată / Urgent */
    'green'   => '#00c371', /* Finalizată */
    'key'     => '#a0aec0', /* Livrată */
];

/* Same accents at ~25% alpha — powers the .stat-card-style hover glow */
$accent_soft_map = [
    'yellow'  => 'rgba(255, 222, 0, 0.25)',
    'cyan'    => 'rgba(0, 174, 239, 0.25)',
    'magenta' => 'rgba(255, 68, 68, 0.25)',
    'green'   => 'rgba(0, 195, 113, 0.25)',
    'key'     => 'rgba(160, 174, 192, 0.25)',
];

/* script.js forwards the hovered <tr>'s theme-* class here so each
   preview is tinted with its own row color. */
$theme = strtolower(preg_replace('/[^a-z]/', '', (string)($_GET['theme'] ?? 'yellow')));
if (!isset($theme_map[$theme])) {
    $theme = 'yellow';
}
$accent      = $theme_map[$theme];
$accent_soft = $accent_soft_map[$theme];

/* ============================
   FETCH ORDER + CLIENT DETAILS
   ============================ */
$sql = "SELECT 
            o.order_id,
            o.status,
            o.detalii_suplimentare,
            o.avans,
            u.username AS assigned_user,
            cu.username AS created_user,
            c.client_name,
            c.client_phone,
            c.client_email
        FROM orders o
        LEFT JOIN users u  ON u.user_id  = o.assigned_to
        LEFT JOIN users cu ON cu.user_id = o.created_by
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

/* ============================
   STATUS-DRIVEN ACTION BUTTONS
   ============================ */
/* "Terminată" / "Livrată" make no sense on an order that already has
   that status, so they are omitted server-side (script.js already
   guards its querySelector('#finishBtn'/'#deliverBtn') lookups).
   Status values mirror dashboard.php: 'completed', 'delivered'. */
$order_status = strtolower(trim((string)($order['status'] ?? '')));
$is_completed = ($order_status === 'completed');
$is_delivered = ($order_status === 'delivered');
?>

<style>
    /*
     * Dynamic theming via CSS custom properties.
     *
     * --preview-accent       → left border, status dot, icon tints
     * --preview-accent-soft  → hover glow (same accent @ ~25% alpha),
     *                          mirroring the .card-* glow shadows on .stat-card
     *
     * Set it via any of:
     *   1. Inline on .order-preview-content (PHP sets this from ?theme=)
     *   2. Class theme-* on the tippy box (script.js copies it off the hovered <tr>)
     *   3. JS: element.style.setProperty('--preview-accent', '#00aeef')
     */

    /* ============================================================
       BOX — clones .stat-card from styles.css:
       dark elegant gradient, 14px radius, hairline border,
       deep drop shadow, accent left-bar, floating hover lift.
       ============================================================ */
    .tippy-box[data-theme~='order-preview'] {
        --preview-accent: #ffde00;
        /* default: yellow / În lucru */
        --preview-accent-soft: rgba(255, 222, 0, 0.25);

        background: linear-gradient(135deg, #000000 0%, #3c3c3c 50%, #6c6c6c 100%);
        color: #fff;
        border: 1px solid rgba(255, 255, 255, 0.05);
        border-left: 4px solid var(--preview-accent);
        border-radius: 14px;
        padding: 0;
        box-shadow: 0 10px 25px rgba(0, 0, 0, 0.3);
        font-family: 'Poppins', sans-serif;
        overflow: hidden;
        /* Only animate visual effects — NOT layout properties like width,
           otherwise the box visibly slides sideways when the "Loading..."
           placeholder is swapped for the real (wider) HTML. */
        transition:
            transform 0.3s cubic-bezier(0.25, 0.8, 0.25, 1),
            opacity 0.3s cubic-bezier(0.25, 0.8, 0.25, 1),
            border-color 0.3s cubic-bezier(0.25, 0.8, 0.25, 1),
            box-shadow 0.3s cubic-bezier(0.25, 0.8, 0.25, 1);
        transform-origin: top center;
    }

    /* Theme tokens — only override the variables.
       Colors mirror the .heavy-row accents AND the .card-* glow tints
       used by .stat-card in styles.css. */
    .tippy-box[data-theme~='order-preview'].theme-yellow,
    .tippy-box[data-theme~='order-preview'][data-theme~='theme-yellow'],
    .order-preview-content.theme-yellow {
        --preview-accent: #ffde00;
        --preview-accent-soft: rgba(255, 222, 0, 0.25);
    }

    .tippy-box[data-theme~='order-preview'].theme-cyan,
    .tippy-box[data-theme~='order-preview'][data-theme~='theme-cyan'],
    .order-preview-content.theme-cyan {
        --preview-accent: #00aeef;
        --preview-accent-soft: rgba(0, 174, 239, 0.25);
    }

    .tippy-box[data-theme~='order-preview'].theme-magenta,
    .tippy-box[data-theme~='order-preview'][data-theme~='theme-magenta'],
    .order-preview-content.theme-magenta {
        --preview-accent: #ff0000;
        --preview-accent-soft: rgba(255, 68, 68, 0.25);
    }

    .tippy-box[data-theme~='order-preview'].theme-green,
    .tippy-box[data-theme~='order-preview'][data-theme~='theme-green'],
    .order-preview-content.theme-green {
        --preview-accent: #00c371;
        --preview-accent-soft: rgba(0, 195, 113, 0.25);
    }

    .tippy-box[data-theme~='order-preview'].theme-key,
    .tippy-box[data-theme~='order-preview'][data-theme~='theme-key'],
    .order-preview-content.theme-key {
        --preview-accent: #a0aec0;
        --preview-accent-soft: rgba(160, 174, 192, 0.25);
    }

    /* Lift content theme class up onto the tippy box border */
    .tippy-box[data-theme~='order-preview']:has(.order-preview-content.theme-yellow) {
        --preview-accent: #ffde00;
        --preview-accent-soft: rgba(255, 222, 0, 0.25);
    }

    .tippy-box[data-theme~='order-preview']:has(.order-preview-content.theme-cyan) {
        --preview-accent: #00aeef;
        --preview-accent-soft: rgba(0, 174, 239, 0.25);
    }

    .tippy-box[data-theme~='order-preview']:has(.order-preview-content.theme-magenta) {
        --preview-accent: #ff0000;
        --preview-accent-soft: rgba(255, 68, 68, 0.25);
    }

    .tippy-box[data-theme~='order-preview']:has(.order-preview-content.theme-green) {
        --preview-accent: #00c371;
        --preview-accent-soft: rgba(0, 195, 113, 0.25);
    }

    .tippy-box[data-theme~='order-preview']:has(.order-preview-content.theme-key) {
        --preview-accent: #a0aec0;
        --preview-accent-soft: rgba(160, 174, 192, 0.25);
    }

    .tippy-box[data-theme~='order-preview'] .tippy-arrow {
        display: none !important;
    }

    .tippy-box[data-state='hidden'] {
        opacity: 0;
        transform: translateY(-6px) scale(0.96);
    }

    .tippy-box[data-state='visible'] {
        opacity: 1;
        transform: translateY(0) scale(1);
    }

    /* Efect de plutire la hover — exact ca pe .stat-card:
       lift + brighter hairline border + glow în culoarea accentului */
    .tippy-box[data-theme~='order-preview']:hover {
        opacity: 1;
        border-color: rgba(255, 255, 255, 0.15);
        border-left-color: var(--preview-accent);
        box-shadow:
            0 15px 30px var(--preview-accent-soft),
            0 10px 25px rgba(0, 0, 0, 0.3);
    }

    /* ============================================================
       CONTENT — typography mirrors .stat-info h3 / .stat-info p
       ============================================================ */
    .order-preview-content {
        --preview-accent: #ffde00;
        --preview-accent-soft: rgba(255, 222, 0, 0.25);

        font-family: 'Poppins', sans-serif;
        font-size: 14px;
        padding: 22px 25px;
        /* same as .stat-card */
        line-height: 1.45;
        color: #fff;
    }

    /* Client name — like the big white number on a stat card,
       preceded by a live status dot in the row's color */
    .order-preview-content #clientNameText {
        display: flex;
        align-items: center;
        gap: 9px;
        font-size: 1.05rem;
        font-weight: 800;
        color: #ffffff;
        letter-spacing: 0.2px;
        line-height: 1.1;
    }

    .order-preview-content #clientNameText::before {
        content: '';
        flex: 0 0 auto;
        width: 10px;
        height: 10px;
        border-radius: 50%;
        background: var(--preview-accent);
        box-shadow: 0 0 8px var(--preview-accent-soft);
    }

    .order-preview-content .contact-row {
        display: flex;
        align-items: center;
        gap: 8px;
        margin-top: 6px;
        color: #aaaaaa;
        font-size: 0.85rem;
    }

    /* WhatsApp icon button — circular .stat-icon treatment,
       using the same green tints as .hero-action-whatsapp */
    .order-preview-content .wa-link {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        width: 34px;
        height: 34px;
        border-radius: 50%;
        background: rgba(37, 211, 102, 0.16);
        color: #7dffad;
        text-decoration: none;
        transition:
            background 0.3s ease,
            color 0.3s ease,
            transform 0.3s ease,
            box-shadow 0.3s ease;
        box-shadow: 0 0 0 1px rgba(37, 211, 102, 0.3);
    }

    .order-preview-content .wa-link:hover {
        background: rgba(37, 211, 102, 0.32);
        color: #7dffad;
        box-shadow:
            0 8px 16px rgba(37, 211, 102, 0.25),
            0 0 0 1px rgba(37, 211, 102, 0.45);
    }

    .order-preview-content .wa-link i {
        font-size: 1.15rem;
        line-height: 1;
    }

    .order-preview-content .section-label {
        display: block;
        margin-top: 14px;
        margin-bottom: 4px;
        color: #aaaaaa;
        /* same secondary grey as .stat-info p */
        font-size: 0.8rem;
        font-weight: 700;
        text-transform: uppercase;
        letter-spacing: 1px;
    }

    .order-preview-content ul {
        margin: 4px 0 0 0;
        padding: 0 0 0 18px;
        color: #e8e8e8;
    }

    .order-preview-content ul li {
        margin-bottom: 3px;
    }

    .order-preview-content .empty-articles {
        font-size: 12px;
        color: #888;
        font-style: italic;
    }

    .order-preview-content .total-text {
        display: block;
        margin-top: 12px;
        font-size: 1.15rem;
        font-weight: 800;
        color: #ffffff;
    }

    .order-preview-content hr {
        border: 0;
        border-top: 1px solid rgba(255, 255, 255, 0.08);
        margin: 14px 0;
    }

    .order-preview-content .actions {
        display: flex;
        flex-wrap: wrap;
        gap: 8px;
        justify-content: center;
    }

    .order-preview-content .action-btn {
        border: none;
        padding: 6px 12px;
        border-radius: 8px;
        cursor: pointer;
        font-size: 11px;
        font-family: 'Poppins', sans-serif;
        font-weight: 600;
        color: white;
        transition: transform 0.2s ease, filter 0.2s ease;
    }

    .order-preview-content .action-btn:hover {
        filter: brightness(1.1);
    }

    .order-preview-content #printBtn {
        background: #555;
    }

    .order-preview-content #finishBtn {
        background: #2ecc71;
    }

    .order-preview-content #deliverBtn {
        background: #3498db;
    }
</style>

<div class="order-preview-content theme-<?= htmlspecialchars($theme) ?>"
    data-order-id="<?= (int)$order_id ?>"
    data-row-theme="<?= htmlspecialchars($theme) ?>"
    data-client-phone="<?= htmlspecialchars($order['client_phone'] ?? '', ENT_QUOTES) ?>"
    data-client-name="<?= htmlspecialchars($order['client_name'] ?? '', ENT_QUOTES) ?>"
    data-assigned-to="<?= htmlspecialchars($order['assigned_user'] ?? '', ENT_QUOTES) ?>"
    data-boss="<?= htmlspecialchars($order['created_user'] ?? '', ENT_QUOTES) ?>"
    style="--preview-accent: <?= htmlspecialchars($accent) ?>; --preview-accent-soft: <?= htmlspecialchars($accent_soft) ?>;">

    <strong id="clientNameText"><?= htmlspecialchars($order['client_name']) ?></strong>

    <div class="contact-row">
        <span><?= htmlspecialchars($order['client_phone']) ?></span>
        <a href="https://wa.me/<?= $clean_phone ?>"
            target="_blank"
            rel="noopener"
            class="wa-link"
            title="Deschide conversația în WhatsApp"
            aria-label="Trimite mesaj pe WhatsApp">
            <i class="fa-brands fa-whatsapp"></i>
        </a>
    </div>

    <span class="section-label">Articole</span>
    <?php if ($articles->num_rows > 0): ?>
        <ul>
            <?php foreach ($articles as $a): ?>
                <li>
                    <?= htmlspecialchars($a['name']) ?>
                    (<?= $a['quantity'] ?> × <?= $a['price_per_unit'] ?> lei)
                </li>
            <?php endforeach; ?>
        </ul>
    <?php else: ?>
        <em class="empty-articles">Fără articole</em>
    <?php endif; ?>

    <strong class="total-text">Total: <?= number_format($remaining, 2) ?> lei</strong>

    <hr>

    <div class="actions">
        <button id="printBtn" class="action-btn" title="Print Order">
            <i class="fa-solid fa-print"></i> Print
        </button>

        <?php if (!$is_completed && !$is_delivered): ?>
            <button id="finishBtn" class="action-btn" title="Mark as Completed">
                <i class="fa-solid fa-flag-checkered"></i> Terminată
            </button>
        <?php endif; ?>

        <?php if (!$is_delivered): ?>
            <button id="deliverBtn" class="action-btn" title="Mark as Delivered">
                <i class="fa-solid fa-truck-ramp-box"></i> Livrată
            </button>
        <?php endif; ?>
    </div>

    <!--
        CSS variable theming (dynamic — follows the hovered <tr>)
        ----------------------------------------------------------
        --preview-accent       → status dot, left border, icon tints
        --preview-accent-soft  → stat-card-style hover glow

        How it flows:
          • script.js reads the hovered row's theme-* class and appends
            &theme=<name> to the order_preview.php request, then mirrors
            the same class onto the tippy box so the color applies
            instantly, even while the content is still loading.
          • PHP: ?theme=yellow|cyan|magenta|green|key → inline style + class
          • Manual: el.style.setProperty('--preview-accent', '#00aeef')

        Colors match .heavy-row left borders in styles.css:
          yellow  #ffde00  În lucru
          cyan    #00aeef  Atribuită
          magenta #ff0000  Întârziată / Urgent
          green   #00c371  Finalizată
          key     #a0aec0  Livrată
    -->
</div>