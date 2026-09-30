<?php
/**
 * Analytics Chat Endpoint (legacy URL).
 *
 * The admin chat widget posts to admin.php?action=ai_chat. This older endpoint used to
 * carry its own copy of the chatbot without the guard rails; it now keeps its admin-only
 * check and runs the one hardened implementation (admin/admin_modules/ai_chat_handler.php,
 * guard rails in config/ai_chat_guard.php), so there's no second, unguarded path to the DB.
 */
error_reporting(0);
ini_set('display_errors', '0');

header('Content-Type: application/json');

require_once dirname(dirname(__DIR__)) . '/config/env.php';
require_once dirname(dirname(__DIR__)) . '/config/security.php';
require_once dirname(dirname(__DIR__)) . '/config/database.php';

security_start_session();

// Admin only
if (empty($_SESSION['user_id']) || ($_SESSION['role'] ?? '') !== 'admin') {
    http_response_code(403);
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

require dirname(dirname(__DIR__)) . '/admin/admin_modules/ai_chat_handler.php';
