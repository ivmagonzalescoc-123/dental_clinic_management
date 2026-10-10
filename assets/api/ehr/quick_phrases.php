<?php
/**
 * The signed-in doctor's own quick phrases for the Summary step of a session.
 *   GET                                   -> every list
 *   POST action=add, field_key, phrase    -> adds one
 *   POST action=delete, phrase_id         -> removes one
 */
include_once dirname(dirname(dirname(__DIR__))) . '/config/session_check.php';
include dirname(dirname(dirname(__DIR__))) . '/config/database.php';
include_once dirname(dirname(dirname(__DIR__))) . '/config/security.php';
include_once dirname(dirname(dirname(__DIR__))) . '/config/quick_phrase_helpers.php';
header('Content-Type: application/json');

check_session(['doctor']);
$doctorId = (int)($_SESSION['user_id'] ?? 0);

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    echo json_encode(['success' => true, 'phrases' => quick_phrases_list($conn, $doctorId)]);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
    exit;
}

if (!security_validate_csrf_token($_POST['csrf_token'] ?? '')) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Please refresh the page and try again.']);
    exit;
}

$action = (string)($_POST['action'] ?? '');
if ($action === 'add') {
    [$added, $problem] = quick_phrases_add($conn, $doctorId, (string)($_POST['field_key'] ?? ''), (string)($_POST['phrase'] ?? ''));
    echo json_encode($added ? ['success' => true, 'phrase' => $added] : ['success' => false, 'message' => $problem]);
    exit;
}
if ($action === 'delete') {
    $deleted = quick_phrases_delete($conn, $doctorId, (int)($_POST['phrase_id'] ?? 0));
    echo json_encode($deleted ? ['success' => true] : ['success' => false, 'message' => 'That phrase was already removed.']);
    exit;
}

http_response_code(400);
echo json_encode(['success' => false, 'message' => 'Unknown action']);
