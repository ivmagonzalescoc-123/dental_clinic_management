<?php
header('Content-Type: application/json');
header('Cache-Control: no-store');

include '../../../config/database.php';
include '../../../config/security.php';
include_once '../../../config/captcha_helpers.php';

security_start_session();

if (!captcha_math_fallback_enabled()) {
    http_response_code(404);
    echo json_encode(['success' => false, 'message' => 'Not available']);
    exit;
}

echo json_encode(['success' => true, 'question' => captcha_math_new()]);
