<?php
session_start();
include 'db.php';
header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

$userId = $_SESSION['user_id'];
$action = $_REQUEST['action'] ?? '';

switch ($action) {
    case 'fetch':
        $stmt = $conn->prepare("
      SELECT note_id, content, created_at
      FROM notes
      WHERE user_id = ?
      ORDER BY created_at DESC
    ");
        $stmt->bind_param('i', $userId);
        $stmt->execute();
        echo json_encode($stmt->get_result()->fetch_all(MYSQLI_ASSOC));
        break;

    case 'add':
        $content = trim($_POST['content'] ?? '');
        if ($content === '') {
            echo json_encode(['error' => 'Empty content']);
            exit;
        }
        $stmt = $conn->prepare("
      INSERT INTO notes (user_id, content)
      VALUES (?, ?)
    ");
        $stmt->bind_param('is', $userId, $content);
        $stmt->execute();
        echo json_encode([
            'note_id'   => $stmt->insert_id,
            'content'   => $content,
            'created_at' => date('Y-m-d H:i:s')
        ]);
        break;

    case 'delete':
        $noteId = intval($_POST['note_id'] ?? 0);
        $stmt = $conn->prepare("
      DELETE n 
      FROM notes n
      WHERE n.note_id = ? AND n.user_id = ?
    ");
        $stmt->bind_param('ii', $noteId, $userId);
        $stmt->execute();
        echo json_encode(['deleted' => $stmt->affected_rows > 0]);
        break;

    default:
        echo json_encode(['error' => 'Bad action']);
}
