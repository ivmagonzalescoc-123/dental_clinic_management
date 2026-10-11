<?php
/**
 * Admin switch: lets the secretary encode past visits from paper records, or closes it
 * again once the encoding is done. Read by past_visits_allowed(). Switching it on asks
 * for the admin's password.
 */
include_once dirname(dirname(dirname(__DIR__))) . '/config/session_check.php';
include_once dirname(dirname(dirname(__DIR__))) . '/config/security.php';
include_once dirname(dirname(dirname(__DIR__))) . '/config/clinic_settings.php';
include_once dirname(dirname(dirname(__DIR__))) . '/config/system_logs.php';
include_once dirname(dirname(dirname(__DIR__))) . '/config/rate_limit_helpers.php';
header('Content-Type: application/json');

if (($_SESSION['role'] ?? '') !== 'admin' || (int)($_SESSION['user_id'] ?? 0) <= 0) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
    exit;
}

$input = security_json_input();
if (!security_validate_csrf_token($input['csrf_token'] ?? '')) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Please refresh the page and try again.']);
    exit;
}

$enabled = !empty($input['enabled']);

// Opening access needs the admin's own password again, so a session left open on a shared
// computer cannot be used to switch it on. Closing it needs nothing extra.
if ($enabled) {
    $adminId = (int)$_SESSION['user_id'];
    $password = (string)($input['password'] ?? '');
    if ($password === '') {
        echo json_encode(['success' => false, 'message' => 'Enter your password to confirm.', 'code' => 'password_required']);
        exit;
    }
    if (!rate_limit_check($conn, 'past_visit_access_confirm', 'user:' . $adminId, 10, 15 * 60)) {
        rate_limit_reject('Too many attempts. Please wait a few minutes and try again.');
    }
    $stmt = $conn->prepare('SELECT password_hash FROM users WHERE user_id = ? LIMIT 1');
    $stmt->bind_param('i', $adminId);
    $stmt->execute();
    $stored = (string)($stmt->get_result()->fetch_assoc()['password_hash'] ?? '');
    $stmt->close();
    $passwordOk = security_is_password_hash($stored) ? password_verify($password, $stored) : ($stored !== '' && hash_equals($stored, $password));
    if (!$passwordOk) {
        log_system_event($conn, 'settings.past_visit_entry_denied', 'clinic_settings', null, 'Wrong password when switching on past visit entry');
        echo json_encode(['success' => false, 'message' => 'That password is incorrect.', 'code' => 'password_incorrect']);
        exit;
    }
}

if (!clinic_setting_set($conn, 'past_visit_entry_enabled', $enabled ? '1' : '0', 'Lets the secretary encode past visits from paper records')) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Could not save the setting. Please try again.']);
    exit;
}

log_system_event(
    $conn,
    $enabled ? 'settings.past_visit_entry_enabled' : 'settings.past_visit_entry_disabled',
    'clinic_settings',
    null,
    'Past visit entry for the secretary switched ' . ($enabled ? 'on' : 'off')
);

echo json_encode(['success' => true, 'enabled' => $enabled]);
