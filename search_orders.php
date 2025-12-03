<?php
include 'db.php';

if (isset($_GET['search_orders'])) {
    $q = trim($_GET['q'] ?? '');

    // If no search term, return empty results
    if ($q === '') {
        echo json_encode([]);
        exit();
    }

    $sql = "SELECT o.order_id, o.order_details, o.detalii_suplimentare, c.client_name
            FROM orders o
            JOIN clients c ON o.client_id = c.client_id
            WHERE o.order_id LIKE ? 
               OR o.order_details LIKE ? 
               OR o.detalii_suplimentare LIKE ?
            ORDER BY o.order_id DESC
            LIMIT 10000";

    $like = "%" . $q . "%";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("sss", $like, $like, $like);
    $stmt->execute();
    $result = $stmt->get_result();

    $orders = [];
    while ($row = $result->fetch_assoc()) {
        $orders[] = [
            "id" => $row['order_id'],
            "client_name" => $row['client_name'],
            "order_details" => $row['order_details'],
            "detalii_suplimentare" => $row['detalii_suplimentare'],
            "text" => "#" . $row['order_id'] . " - " . $row['client_name']
        ];
    }

    echo json_encode($orders);
    exit();
}
