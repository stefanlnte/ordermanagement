<?php
/**
 * refresh_check.php
 * ---------------------------------------------------------------------------
 * Server side of the "quiet refresh" (live table updates for multi-user use).
 *
 * Every dashboard client polls this endpoint in the background, sending the
 * view it is *currently showing* (the same query-string params dashboard.php
 * reads: status_filter, assigned_filter, category_filter, client_filter,
 * sort_order, page) together with `sig` — a hash of the table it rendered.
 *
 * The server re-runs the EXACT same filtered query dashboard.php uses and
 * re-hashes the result for THOSE filters. It returns TWO independent
 * signatures so the client can refresh only what actually changed:
 *
 *   - pageSig  : hash of the table rows currently shown, the page count and
 *                the pinned strip. If two ticks differ here, the table view
 *                this user is looking at changed (another user added an
 *                order that their filters SHOW, delivered one, changed a
 *                status, ...) -> client should do a full quietRefresh().
 *   - statsSig : hash of the global stat-card aggregates (overdue/active/
 *                completed/deliver_today/delivered_today). These are
 *                computed over ALL orders, regardless of the user's filters.
 *                If ONLY this differs, the table itself is unchanged (e.g. a
 *                new order that the user's active filters exclude) but the
 *                stat cards are stale -> client refreshes just the banner.
 *
 * The decision about whether the local user is mid-draft in the "Add order"
 * form is made client-side on this response, so a refresh never wipes
 * someone's in-progress form.
 * ---------------------------------------------------------------------------
 */

// Mirror dashboard.php's session setup so auth is shared with the page.
ini_set('session.gc_maxlifetime', 86400 * 30);
ini_set('session.cookie_lifetime', 86400 * 30);
session_set_cookie_params([
    'lifetime' => 86400 * 30,
    'path'     => '/',
    'secure'   => true,
    'httponly' => true,
]);
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

header('Content-Type: application/json; charset=utf-8');

// Must be an authenticated user, just like dashboard.php.
if (empty($_SESSION['username'])) {
    http_response_code(401);
    http_response_code(401);
    echo json_encode([
        'pageSig'  => '',
        'statsSig' => '',
        'stats'    => [],
        'unauthorized' => true,
    ]);
    exit;
}

include 'db.php';

/* ---- Read the client's current view (mirror dashboard.php defaults) ---- */
$status_filter   = $_GET['status_filter']   ?? '';
$assigned_filter = $_GET['assigned_filter'] ?? '';
$category_filter = $_GET['category_filter'] ?? '';
$client_filter   = $_GET['client_filter']   ?? '';
$sort_order      = strtoupper($_GET['sort_order'] ?? 'ASC');
if ($sort_order !== 'ASC' && $sort_order !== 'DESC') {
    $sort_order = 'ASC';
}
$page   = max(1, (int)($_GET['page'] ?? 1));
$limit  = 18;                 // MUST stay in sync with dashboard.php
$offset = ($page - 1) * $limit;

/* ---- Build the SAME WHERE clause as dashboard.php ---------------------- */
$where_sql = ' WHERE 1=1';
$params    = [];
$types     = '';

// Stat-card smart filters — MUST mirror dashboard.php's $smart_status_filters.
$smart_status_filters = [
    'overdue'         => "o.status = 'assigned' AND o.due_date < CURDATE()",
    'deliver_today'   => "o.status = 'assigned' AND o.due_date = CURDATE()",
    'delivered_today' => "o.status = 'delivered' AND DATE(o.delivery_date) = CURDATE()",
];

if (
    $status_filter !== 'delivered'
    && $status_filter !== 'cancelled'
    && !isset($smart_status_filters[$status_filter])
) {
    $where_sql .= " AND o.status NOT IN ('delivered', 'cancelled')";
}
if (isset($smart_status_filters[$status_filter])) {
    $where_sql .= ' AND (' . $smart_status_filters[$status_filter] . ')';
} elseif ($status_filter) {
    $where_sql .= ' AND o.status = ?';
    $params[] = $status_filter;
    $types   .= 's';
}
if ($assigned_filter) {
    $where_sql .= ' AND o.assigned_to = ?';
    $params[] = $assigned_filter;
    $types   .= 'i';
}
if ($category_filter) {
    $where_sql .= ' AND o.category_id = ?';
    $params[] = $category_filter;
    $types   .= 'i';
}
if ($client_filter) {
    $where_sql .= ' AND o.client_id = ?';
    $params[] = $client_filter;
    $types   .= 'i';
}

/* ---- Visible rows for this page (ids + status drive the content hash) -- */
/* Mirror dashboard.php's INNER JOIN clients so the hash matches the rows the
   table actually renders (orders with a missing client are excluded there). */
$page_sql = 'SELECT o.order_id, o.status FROM orders o
             JOIN clients c ON o.client_id = c.client_id' . $where_sql;
if ($status_filter === 'delivered' || $status_filter === 'delivered_today') {
    // Same special sort dashboard.php uses for the delivered views.
    $page_sql .= ' ORDER BY o.delivery_date ' . $sort_order;
} else {
    $page_sql .= ' ORDER BY o.order_id ' . $sort_order;
}
$page_sql .= ' LIMIT ? OFFSET ?';

$stmt        = $conn->prepare($page_sql);
$data_types  = $types . 'ii';
$data_params = array_merge($params, [$limit, $offset]);
$stmt->bind_param($data_types, ...$data_params);
$stmt->execute();
$rows = $stmt->get_result();
$stmt->close();

$sig_parts = [];
while ($row = $rows->fetch_assoc()) {
    $sig_parts[] = $row['order_id'] . ':' . $row['status'];
}

/* ---- Total count (drives the pagination block, also swapped) ----------- */
/* Same INNER JOIN clients as dashboard.php, so the count matches the pages
   the table can actually fill (no phantom pages from orphaned orders). */
$count_sql = 'SELECT COUNT(*) AS total FROM orders o
              JOIN clients c ON o.client_id = c.client_id' . $where_sql;
$total_orders = 0;
$count_stmt = $conn->prepare($count_sql);
if ($types !== '') {
    $count_stmt->bind_param($types, ...$params);
}
$count_stmt->execute();
$total_orders = (int) $count_stmt->get_result()->fetch_assoc()['total'];
$count_stmt->close();
$total_pages = max(1, (int) ceil($total_orders / $limit));
$sig_parts[] = 'pages:' . $total_pages;

/* ---- Pinned strip (the other section quietRefresh() swaps) ------------- */
$pinned_ids = [];
$pinned_sql = "SELECT order_id FROM orders
               WHERE is_pinned = 1
               ORDER BY due_date ASC
               LIMIT 10";
if ($pinned_res = $conn->query($pinned_sql)) {
    while ($p = $pinned_res->fetch_assoc()) {
        $pinned_ids[] = $p['order_id'];
    }
}
$sig_parts[] = 'pinned:' . implode(',', $pinned_ids);

/* ---- STAT CARDS (global aggregates over ALL orders, filter-independent) -- */
/* Must match the $stats_sql aggregate in dashboard.php exactly.             */
$stats_sql = "SELECT
                SUM(status = 'assigned' AND due_date < CURDATE())             AS overdue,
                SUM(status = 'assigned')                                      AS active,
                SUM(status = 'completed')                                     AS completed,
                SUM(status = 'assigned' AND due_date = CURDATE())             AS deliver_today,
                SUM(status = 'delivered' AND DATE(delivery_date) = CURDATE()) AS delivered_today,
                SUM(status = 'delivered')                                     AS delivered_total,
                SUM(status = 'cancelled')                                     AS cancelled_total
              FROM orders";
$stats_row = [];
if ($stats_res = $conn->query($stats_sql)) {
    $stats_row = $stats_res->fetch_assoc() ?: [];
}
$stats = [
    'overdue'         => (int)($stats_row['overdue']         ?? 0),
    'active'          => (int)($stats_row['active']          ?? 0),
    'completed'       => (int)($stats_row['completed']       ?? 0),
    'deliver_today'   => (int)($stats_row['deliver_today']   ?? 0),
    'delivered_today' => (int)($stats_row['delivered_today'] ?? 0),
    'delivered_total' => (int)($stats_row['delivered_total'] ?? 0),
    'cancelled_total' => (int)($stats_row['cancelled_total'] ?? 0),
];

$conn->close();

// pageSig  -> governs the table / pagination / pinned strip (the existing
//             sections quietRefresh() swaps). Does NOT include the stats.
$page_sig = hash('sha256', implode('|', $sig_parts));
// statsSig -> governs the stat-card banner only. Computed from the 5 values
//             the dashboard actually displays.
$stats_sig = hash('sha256', implode(':', [
    $stats['overdue'],
    $stats['active'],
    $stats['completed'],
    $stats['deliver_today'],
    $stats['delivered_today'],
]));

echo json_encode([
    'pageSig'  => $page_sig,
    'statsSig' => $stats_sig,
    'stats'    => $stats,
]);
