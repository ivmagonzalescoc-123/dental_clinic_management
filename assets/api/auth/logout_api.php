<?php
header('Content-Type: application/json');

session_start();

// Clear all session variables
$_SESSION = array();

// Destroy the session
if (session_id() != "") {
    setcookie(session_name(), '', time() - 2592000, '/');
}
session_destroy();

// Return success
http_response_code(200);
echo json_encode(['success' => true, 'message' => 'Logged out successfully']);
exit;
?>
