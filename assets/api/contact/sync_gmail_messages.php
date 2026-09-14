<?php
header('Content-Type: application/json');

include '../../../config/session_check.php';
check_session(['secretary']);
include '../../../config/database.php';
include '../../../config/security.php';
include_once '../../../config/contact_messages_helpers.php';
include_once '../../../config/gmail_contact_sync.php';

if (strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET')) !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Invalid request method.']);
    exit;
}

$input = security_json_input();
$csrfToken = (string)($input['csrf_token'] ?? '');
if (!security_validate_csrf_token($csrfToken)) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Please refresh the page and try again.']);
    exit;
}

$result = gmail_contact_sync_fetch($conn);

echo json_encode([
    'success' => (bool)($result['success'] ?? false),
    'message' => (string)($result['message'] ?? ''),
    'imported' => (int)($result['imported'] ?? 0),
    'new_count' => contact_messages_count_new($conn),
]);
