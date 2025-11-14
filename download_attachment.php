<?php
include 'db.php';

$id = (int)($_GET['id'] ?? 0);
$stmt = $conn->prepare("SELECT * FROM order_attachments WHERE id = ?");
$stmt->bind_param("i", $id);
$stmt->execute();
$file = $stmt->get_result()->fetch_assoc();

if ($file && file_exists($file['filepath'])) {
    header('Content-Description: File Transfer');
    header('Content-Type: application/octet-stream');
    header('Content-Disposition: attachment; filename="' . basename($file['filename']) . '"');
    header('Content-Length: ' . filesize($file['filepath']));
    readfile($file['filepath']);
    exit;
} else {
    echo "File not found.";
}
