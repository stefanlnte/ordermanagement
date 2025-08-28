<?php
// update_default_price.php
header('Content-Type: application/json');

require 'db.php'; // must define $conn (mysqli)

$article_id = isset($_POST['article_id']) ? (int)$_POST['article_id'] : 0;
$price_raw  = $_POST['price'] ?? '';
$price      = filter_var($price_raw, FILTER_VALIDATE_FLOAT);

if ($article_id <= 0) {
    echo json_encode(['success' => false, 'error' => 'ID articol invalid.']);
    exit;
}
if ($price === false) {
    echo json_encode(['success' => false, 'error' => 'Preț invalid.']);
    exit;
}

// Update default price in the catalog table.
// Table name and column names must match your schema.
// From your code, fetch_articles.php likely reads from `articles` with columns `id`, `name`, `price`.
$sql = "UPDATE articles SET price = ? WHERE id = ?";
$stmt = $conn->prepare($sql);
if (!$stmt) {
    echo json_encode(['success' => false, 'error' => 'Eroare pregătire interogare.']);
    exit;
}

$stmt->bind_param('di', $price, $article_id);
$ok = $stmt->execute();
$stmt->close();

echo json_encode(['success' => (bool)$ok]);