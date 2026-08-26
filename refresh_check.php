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
 * re-hashes the result for THOSE filters. Two outcomes:
 *
 *   - hash unchanged -> nothing the user is currently looking at has changed
 *                       (e.g. a new order that their active filters exclude)
 *                       -> { "changed": false }
 *   - hash differs   -> the rows behind this user's current view changed
 *                       (another user added an order, delivered one, changed
 *                       a status, ...) -> { "changed": true, "sig": <new> }
 *
 * This is what lets the client call its own quietRefresh() in response to
 * OTHER users' actions. The decision about whether the local user is
 * mid-draft in the "Add order" form is made client-side on this response, so
 * a refresh never wipes someone's in-progress form.
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
    echo json_encode(['changed' => false, 'sig' => '', 'unauthorized' => true]);
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

if ($status_filter !== 'delivered' && $status_filter !== 'cancelled') {
    $where_sql .= " AND o.status NOT IN ('delivered', 'cancelled')";
}
if ($status_filter) {
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
$page_sql = 'SELECT o.order_id, o.status FROM orders o' . $where_sql;
if ($status_filter === 'delivered') {
    // Same special sort dashboard.php uses for the delivered view.
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
$count_sql = 'SELECT COUNT(*) AS total FROM orders o' . $where_sql;
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

$conn->close();

$new_sig = hash('sha256', implode('|', $sig_parts));

$client_sig = isset($_GET['sig']) && is_string($_GET['sig']) ? $_GET['sig'] : '';
$changed    = ($client_sig !== '' && $client_sig !== $new_sig);

echo json_encode([
    'changed' => $changed,
    'sig'     => $new_sig,
]);
