<?php
include_once dirname(dirname(dirname(__DIR__))) . '/config/session_check.php';
include dirname(dirname(dirname(__DIR__))) . '/config/database.php';
header('Content-Type: application/json');

$user_id = $_SESSION['user_id'] ?? null;
if (!$user_id) {
    http_response_code(403);
    echo json_encode(['success'=>false,'message'=>'Forbidden']);
    exit;
}

$stmt = $conn->prepare('SELECT notification_id, title, body, link, is_read, created_at FROM notifications WHERE user_id = ? ORDER BY created_at DESC LIMIT 50');
$stmt->bind_param('i', $user_id);
$stmt->execute();
$res = $stmt->get_result();
$rows = [];
while ($r = $res->fetch_assoc()) $rows[] = $r;
$stmt->close();

// unread count
$uc = $conn->prepare('SELECT COUNT(*) AS cnt FROM notifications WHERE user_id = ? AND is_read = 0');
$uc->bind_param('i', $user_id);
$uc->execute();
$ucr = $uc->get_result()->fetch_assoc();
$uc->close();

echo json_encode(['success'=>true,'notifications'=>$rows,'unread'=>(int)($ucr['cnt']??0)]);

?>
