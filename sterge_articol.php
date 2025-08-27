<?php
require 'db.php';

$id = intval($_POST['id']);

// Ștergem articolul din comenzi_articole
$stmt = $conn->prepare("DELETE FROM comenzi_articole WHERE id = ?");
$stmt->bind_param("i", $id);
$stmt->execute();

// Returnăm lista actualizată + total
$comanda_id = intval($_POST['comanda_id']);
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

echo json_encode([
    'rows' => $rows,
    'total' => number_format($total, 2)
]);
