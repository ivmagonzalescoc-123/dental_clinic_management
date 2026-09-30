<?php
header('Content-Type: application/json');

include '../../../config/session_check.php';
check_session(['secretary', 'admin']); // admin: read-only (GET)
include '../../../config/database.php';
include '../../../config/security.php';
include_once '../../../config/contact_messages_helpers.php';

contact_messages_ensure_table($conn);

$method = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'));

// Messages come from the landing-page form ('form', the default) or are imported from Gmail.
// Anything that is not 'gmail' counts as a landing-page message.
function contact_source_where(string $source): string
{
    if ($source === 'gmail') return "source = 'gmail'";
    if ($source === 'form') return "source <> 'gmail'";
    return '1=1';
}

function contact_new_counts_by_source(mysqli $conn): array
{
    $counts = ['form' => 0, 'gmail' => 0];
    $res = $conn->query("SELECT IF(source = 'gmail', 'gmail', 'form') AS src, COUNT(*) AS c FROM contact_messages WHERE status = 'new' GROUP BY src");
    if ($res) {
        while ($row = $res->fetch_assoc()) {
            $counts[$row['src']] = (int)$row['c'];
        }
    }
    return $counts;
}

if ($method === 'GET') {
    $status = strtolower(trim((string)($_GET['status'] ?? 'all')));
    $allowedStatuses = ['all', 'new', 'read', 'archived'];
    if (!in_array($status, $allowedStatuses, true)) {
        $status = 'all';
    }

    $source = strtolower(trim((string)($_GET['source'] ?? '')));
    if (!in_array($source, ['form', 'gmail'], true)) {
        $source = '';
    }

    $sql = 'SELECT message_id, full_name, email, phone_number, message, status, source, read_by, read_at, created_at
            FROM contact_messages WHERE ' . contact_source_where($source);
    if ($status !== 'all') {
        $sql .= ' AND status = ?';
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
        'new_counts' => contact_new_counts_by_source($conn),
    ]);
    exit;
}

if ($method !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Invalid request method.']);
    exit;
}

if (($_SESSION['role'] ?? '') !== 'secretary') {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'You have view-only access to contact messages.']);
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

if ($action === 'mark_all_read') {
    $source = strtolower(trim((string)($input['source'] ?? '')));
    if (!in_array($source, ['form', 'gmail'], true)) {
        $source = '';
    }
    $stmt = $conn->prepare("UPDATE contact_messages SET status = 'read', read_by = ?, read_at = NOW() WHERE status = 'new' AND " . contact_source_where($source));
    if (!$stmt) {
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => 'Unable to update messages.']);
        exit;
    }
    $stmt->bind_param('i', $userId);
    $stmt->execute();
    $updated = $stmt->affected_rows;
    $stmt->close();

    echo json_encode([
        'success' => true,
        'message' => $updated === 1 ? '1 message marked as read.' : $updated . ' messages marked as read.',
        'updated' => $updated,
        'new_count' => contact_messages_count_new($conn),
        'new_counts' => contact_new_counts_by_source($conn),
    ]);
    exit;
}

if ($action === 'archive_selected') {
    $ids = array_values(array_unique(array_filter(array_map('intval', (array)($input['message_ids'] ?? [])), fn($id) => $id > 0)));
    if (!$ids) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Select at least one message.']);
        exit;
    }
    if (count($ids) > 500) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Archive at most 500 messages at a time.']);
        exit;
    }
    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $stmt = $conn->prepare("UPDATE contact_messages SET status = 'archived', read_by = COALESCE(read_by, ?), read_at = COALESCE(read_at, NOW()) WHERE status <> 'archived' AND message_id IN ($placeholders)");
    if (!$stmt) {
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => 'Unable to archive messages.']);
        exit;
    }
    $stmt->bind_param('i' . str_repeat('i', count($ids)), $userId, ...$ids);
    $stmt->execute();
    $updated = $stmt->affected_rows;
    $stmt->close();

    echo json_encode([
        'success' => true,
        'message' => $updated === 1 ? '1 message archived.' : $updated . ' messages archived.',
        'updated' => $updated,
        'new_count' => contact_messages_count_new($conn),
        'new_counts' => contact_new_counts_by_source($conn),
    ]);
    exit;
}

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

    echo json_encode(['success' => true, 'message' => 'Message marked as read.', 'new_count' => contact_messages_count_new($conn), 'new_counts' => contact_new_counts_by_source($conn)]);
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

    echo json_encode(['success' => true, 'message' => 'Message archived.', 'new_count' => contact_messages_count_new($conn), 'new_counts' => contact_new_counts_by_source($conn)]);
    exit;
}

http_response_code(400);
echo json_encode(['success' => false, 'message' => 'Unsupported action.']);
