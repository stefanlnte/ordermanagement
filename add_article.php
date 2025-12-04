<?php
include 'db.php';

$order_id  = isset($_POST['order_id']) ? (int)$_POST['order_id'] : 0;
$article_id_raw = $_POST['article_id'] ?? '';
$quantity  = isset($_POST['quantity']) ? (int)$_POST['quantity'] : 1;
$price_raw = $_POST['price'] ?? '';

if ($order_id <= 0 || $quantity <= 0) {
    http_response_code(400);
    echo "Invalid order ID or quantity.";
    exit;
}

$article_id = null;
$price = null;

if (is_numeric($article_id_raw)) {
    // Existing article: look up price
    $article_id = (int)$article_id_raw;
    $stmt = $conn->prepare("SELECT price FROM articles WHERE id = ?");
    $stmt->bind_param('i', $article_id);
    $stmt->execute();
    $stmt->bind_result($price);
    if (!$stmt->fetch()) {
        http_response_code(404);
        echo "Article not found.";
        $stmt->close();
        exit;
    }
    $stmt->close();
} else {
    // New article: require name + price
    $article_name = trim($article_id_raw);
    $article_name = ucfirst(strtolower($article_name));
    $price = is_numeric($price_raw) ? (float)$price_raw : 0;

    if ($article_name === '' || $price <= 0) {
        http_response_code(400);
        echo "New articles require a name and a valid price.";
        exit;
    }

    // Insert into articles
    $stmt = $conn->prepare("INSERT INTO articles (name, price) VALUES (?, ?)");
    $stmt->bind_param('sd', $article_name, $price);
    if (!$stmt->execute()) {
        http_response_code(500);
        echo "Failed to create new article.";
        $stmt->close();
        exit;
    }
    $article_id = $stmt->insert_id;
    $stmt->close();
}

// Insert into order_articles
$stmt = $conn->prepare("
    INSERT INTO order_articles (order_id, article_id, quantity, price_per_unit)
    VALUES (?, ?, ?, ?)
");
$stmt->bind_param('iiid', $order_id, $article_id, $quantity, $price);

if ($stmt->execute()) {
    // echo "Article added successfully.";
    header("Location: " . $_SERVER['HTTP_REFERER']);
} else {
    http_response_code(500);
    echo "Failed to add article to order.";
}

// Recalculate order total
$sum_sql = "SELECT SUM(quantity * price_per_unit) AS total_articles
            FROM order_articles
            WHERE order_id = ?";
$stmt = $conn->prepare($sum_sql);
$stmt->bind_param("i", $order_id);
$stmt->execute();
$result = $stmt->get_result();
$row = $result->fetch_assoc();
$total_articles = $row['total_articles'] ?? 0;
$stmt->close();

// Update orders.total
$update_sql = "UPDATE orders SET total = ? WHERE order_id = ?";
$stmt = $conn->prepare($update_sql);
$stmt->bind_param("di", $total_articles, $order_id);
$stmt->execute();

$stmt->close();
$conn->close();
