<?php
include_once dirname(dirname(dirname(__DIR__))) . '/config/session_check.php';
include dirname(dirname(dirname(__DIR__))) . '/config/database.php';
header('Content-Type: application/json');

$user_id = $_SESSION['user_id'] ?? null;
$nid = isset($_POST['notification_id']) ? (int)$_POST['notification_id'] : 0;
if (!$user_id) {
    http_response_code(403);
    echo json_encode(['success'=>false,'message'=>'Forbidden']);
    exit;
}

if ($nid <= 0) {
    echo json_encode(['success'=>false,'message'=>'Missing notification_id']);
    exit;
}

$stmt = $conn->prepare('UPDATE notifications SET is_read = 1 WHERE notification_id = ? AND user_id = ?');
$stmt->bind_param('ii', $nid, $user_id);
$stmt->execute();
$stmt->close();

echo json_encode(['success'=>true]);

?>
