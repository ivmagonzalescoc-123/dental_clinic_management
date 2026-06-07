<?php
header('Content-Type: application/json');

include '../../../config/database.php';
include '../../../config/security.php';
include_once '../../../config/contact_messages_helpers.php';
include_once '../../../config/workflow_notifications.php';

security_start_session();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Invalid request method.']);
    exit;
}

$input = security_json_input();
$csrfToken = (string)($input['csrf_token'] ?? '');
if (!security_validate_csrf_token($csrfToken)) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Security token mismatch. Please refresh and try again.']);
    exit;
}

$fullName = security_clean_string($input['name'] ?? ($input['full_name'] ?? ''), 150);
$email = security_clean_string($input['email'] ?? '', 150);
$phoneNumber = security_clean_string($input['phone'] ?? ($input['phone_number'] ?? ''), 30);
$message = security_clean_string($input['message'] ?? '', 5000);

if ($fullName === '' || $email === '' || $phoneNumber === '' || $message === '') {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Please complete all fields before sending your message.']);
    exit;
}

if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Please enter a valid email address.']);
    exit;
}

$phoneDigits = preg_replace('/\D+/', '', $phoneNumber);
if (strlen($phoneDigits) < 7 || strlen($phoneDigits) > 15) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Please enter a valid phone number.']);
    exit;
}

contact_messages_ensure_table($conn);

$stmt = $conn->prepare('INSERT INTO contact_messages (full_name, email, phone_number, message, status) VALUES (?, ?, ?, ?, \'new\')');
if (!$stmt) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Unable to save your message right now.']);
    exit;
}

$stmt->bind_param('ssss', $fullName, $email, $phoneNumber, $message);
$ok = $stmt->execute();
$messageId = (int)$stmt->insert_id;
$stmt->close();

if (!$ok) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Unable to save your message right now.']);
    exit;
}

if (function_exists('wf_notify_secretaries')) {
    wf_notify_secretaries(
        $conn,
        'New contact message',
        $fullName . ' sent a message through the public Contact Us form.',
        'secretary.php?page=contact_messages'
    );
}

echo json_encode([
    'success' => true,
    'message' => 'Thank you! Your message has been sent. Our secretary will get back to you soon.',
    'message_id' => $messageId,
]);
