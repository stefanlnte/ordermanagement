<?php
require 'db.php';

$comanda_id = intval($_POST['comanda_id']);
$cantitate  = intval($_POST['cantitate']);
$pret       = floatval($_POST['pret']);
$articol_id = $_POST['articol_id'];

// Dacă e articol nou (text în loc de ID)
if (!is_numeric($articol_id)) {
    $denumire = trim($articol_id);
    $stmt = $conn->prepare("INSERT INTO articole (denumire, pret) VALUES (?, ?)");
    $stmt->bind_param("sd", $denumire, $pret);
    $stmt->execute();
    $articol_id = $stmt->insert_id;
}

// Inserare în comenzi_articole
$stmt = $conn->prepare("INSERT INTO comenzi_articole (comanda_id, articol_id, cantitate, pret) VALUES (?, ?, ?, ?)");
$stmt->bind_param("iiid", $comanda_id, $articol_id, $cantitate, $pret);
$stmt->execute();

// Returnare listă + total
$res = $conn->query("
    SELECT ca.id, a.denumire, ca.cantitate, ca.pret 
    FROM comenzi_articole ca
    JOIN articole a ON ca.articol_id = a.id
    WHERE ca.comanda_id = $comanda_id
");

$rows = '';
$total = 0;
while ($row = $res->fetch_assoc()) {
    $subtotal = $row['cantitate'] * $row['pret'];
    $total += $subtotal;
    $rows .= "<tr>
                <td>{$row['denumire']}</td>
                <td>{$row['cantitate']}</td>
                <td>{$row['pret']}</td>
                <td><button class='stergeArticol' data-id='{$row['id']}'>x</button></td>
              </tr>";
}

if ($comanda_id <= 0) {
    http_response_code(400);
    exit('Invalid order ID');
}

echo json_encode(['rows' => $rows, 'total' => number_format($total, 2)]);
