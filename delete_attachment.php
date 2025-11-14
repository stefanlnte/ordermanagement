<?php
include 'db.php';

$id = (int)($_POST['id'] ?? 0);
if ($id <= 0) {
    http_response_code(400);
    echo "Invalid attachment ID";
    exit;
}

$stmt = $conn->prepare("SELECT * FROM order_attachments WHERE id = ?");
$stmt->bind_param("i", $id);
$stmt->execute();
$file = $stmt->get_result()->fetch_assoc();

if ($file) {
    // Delete file from disk
    if (file_exists($file['filepath'])) {
        unlink($file['filepath']);
    }

    // Delete record from DB
    $stmt = $conn->prepare("DELETE FROM order_attachments WHERE id = ?");
    $stmt->bind_param("i", $id);
    $stmt->execute();

    echo "Fișier șters cu succes.";
} else {
    http_response_code(404);
    echo "Fișierul nu a fost găsit.";
}
