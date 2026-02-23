<?php
session_start();
require_once 'db.php';

header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['error' => 'Not logged in']);
    exit;
}

$action = $_POST['action'] ?? $_GET['action'] ?? '';

switch ($action) {

    // -------------------------------------------------
    // ADD NOTE (sender → receiver)
    // -------------------------------------------------
    case 'add':
        $content = trim($_POST['content'] ?? '');
        $receiver_id = intval($_POST['receiver_id'] ?? 0);
        $sender_id = intval($_SESSION['user_id']);

        if ($content === '') {
            echo json_encode(['error' => 'Empty content']);
            exit;
        }

        if ($receiver_id === 0) {
            echo json_encode(['error' => 'Missing receiver']);
            exit;
        }

        $stmt = $conn->prepare("
            INSERT INTO notes (
                user_id, recipient_id,
                sender_id, receiver_id,
                content, is_read, created_at
            )
            VALUES (?, ?, ?, ?, ?, 0, NOW())
        ");
        // user_id = sender, recipient_id = receiver (for backward compatibility)
        $stmt->bind_param("iiiis", $sender_id, $receiver_id, $sender_id, $receiver_id, $content);
        $stmt->execute();

        echo json_encode(['added' => true]);
        exit;


        // -------------------------------------------------
        // FETCH NOTES FOR LOGGED-IN USER
        // -------------------------------------------------
    case 'fetch':
        $uid = intval($_SESSION['user_id']);

        $sql = "
            SELECT 
                n.note_id,
                n.sender_id,
                n.receiver_id,
                n.content,
                n.is_read,
                n.created_at,
                u.username AS sender_name
            FROM notes n
            JOIN users u ON n.sender_id = u.user_id
            WHERE n.receiver_id = ?
            ORDER BY n.created_at DESC
        ";

        $stmt = $conn->prepare($sql);
        $stmt->bind_param("i", $uid);
        $stmt->execute();
        $result = $stmt->get_result();

        $notes = [];
        while ($row = $result->fetch_assoc()) {
            $notes[] = $row;
        }

        echo json_encode($notes);
        exit;


        // -------------------------------------------------
        // COUNT UNREAD NOTES
        // -------------------------------------------------
    case 'unread_count':
        $uid = intval($_SESSION['user_id']);

        $stmt = $conn->prepare("
            SELECT COUNT(*) AS cnt
            FROM notes
            WHERE receiver_id = ? AND is_read = 0
        ");
        $stmt->bind_param("i", $uid);
        $stmt->execute();
        $res = $stmt->get_result()->fetch_assoc();

        echo json_encode(['unread' => intval($res['cnt'] ?? 0)]);
        exit;


        // -------------------------------------------------
        // MARK ALL NOTES AS READ
        // -------------------------------------------------
    case 'mark_read':
        $uid = intval($_SESSION['user_id']);

        $stmt = $conn->prepare("
            UPDATE notes
            SET is_read = 1
            WHERE receiver_id = ?
        ");
        $stmt->bind_param("i", $uid);
        $stmt->execute();

        echo json_encode(['ok' => true]);
        exit;

    case 'delete':
        $note_id = intval($_POST['note_id'] ?? 0);
        $uid = $_SESSION['user_id'];

        if ($note_id === 0) {
            echo json_encode(['error' => 'Missing note_id']);
            exit;
        }

        // User can delete ONLY notes received by them
        $stmt = $conn->prepare("
        DELETE FROM notes 
        WHERE note_id = ? AND receiver_id = ?
    ");
        $stmt->bind_param("ii", $note_id, $uid);
        $stmt->execute();

        echo json_encode(['deleted' => true]);
        exit;


        // -------------------------------------------------
        // DEFAULT
        // -------------------------------------------------
    default:
        echo json_encode(['error' => 'Invalid action']);
        exit;
}
