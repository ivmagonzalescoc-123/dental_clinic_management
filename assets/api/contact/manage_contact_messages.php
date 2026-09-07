<?php
header('Content-Type: application/json');

include '../../../config/session_check.php';
check_session(['secretary']);
include '../../../config/database.php';
include '../../../config/security.php';
include_once '../../../config/contact_messages_helpers.php';

contact_messages_ensure_table($conn);

$method = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'));

if ($method === 'GET') {
    $status = strtolower(trim((string)($_GET['status'] ?? 'all')));
    $allowedStatuses = ['all', 'new', 'read', 'archived'];
    if (!in_array($status, $allowedStatuses, true)) {
        $status = 'all';
    }

    $sql = 'SELECT message_id, full_name, email, phone_number, message, status, read_by, read_at, created_at
            FROM contact_messages';
    if ($status !== 'all') {
        $sql .= ' WHERE status = ?';
    }
    $sql .= ' ORDER BY created_at DESC, message_id DESC LIMIT 200';

    $rows = [];
    if ($status === 'all') {
        $res = $conn->query($sql);
        if ($res) {
            while ($row = $res->fetch_assoc()) {
                $rows[] = $row;
            }
        }
    } else {
        $stmt = $conn->prepare($sql);
        if ($stmt) {
            $stmt->bind_param('s', $status);
            $stmt->execute();
            $res = $stmt->get_result();
            while ($row = $res->fetch_assoc()) {
                $rows[] = $row;
            }
            $stmt->close();
        }
    }

    echo json_encode([
        'success' => true,
        'messages' => $rows,
        'new_count' => contact_messages_count_new($conn),
    ]);
    exit;
}

if ($method !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Invalid request method.']);
    exit;
}

$input = security_json_input();
$csrfToken = (string)($input['csrf_token'] ?? '');
if (!security_validate_csrf_token($csrfToken)) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Please refresh the page and try again..']);
    exit;
}

$action = strtolower(trim((string)($input['action'] ?? '')));
$messageId = (int)($input['message_id'] ?? 0);
$userId = (int)($_SESSION['user_id'] ?? 0);

if ($messageId <= 0) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Invalid message selected.']);
    exit;
}

if ($action === 'mark_read') {
    $stmt = $conn->prepare("UPDATE contact_messages SET status = 'read', read_by = ?, read_at = NOW() WHERE message_id = ? AND status = 'new' LIMIT 1");
    if (!$stmt) {
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => 'Unable to update message.']);
        exit;
    }
    $stmt->bind_param('ii', $userId, $messageId);
    $stmt->execute();
    $stmt->close();

    echo json_encode(['success' => true, 'message' => 'Message marked as read.', 'new_count' => contact_messages_count_new($conn)]);
    exit;
}

if ($action === 'archive') {
    $stmt = $conn->prepare("UPDATE contact_messages SET status = 'archived', read_by = COALESCE(read_by, ?), read_at = COALESCE(read_at, NOW()) WHERE message_id = ? LIMIT 1");
    if (!$stmt) {
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => 'Unable to archive message.']);
        exit;
    }
    $stmt->bind_param('ii', $userId, $messageId);
    $stmt->execute();
    $stmt->close();

    echo json_encode(['success' => true, 'message' => 'Message archived.', 'new_count' => contact_messages_count_new($conn)]);
    exit;
}

http_response_code(400);
echo json_encode(['success' => false, 'message' => 'Unsupported action.']);
