<?php
/**
 * Session Manager - Check user role and redirect if unauthorized
 * Include this file at the top of role-specific pages
 * 
 * Usage in admin/admin.php:
 * include '../config/session_check.php';
 * check_session(['admin']);
 */

session_start();

function check_session($allowed_roles = []) {
    // Check if user is logged in
    if (!isset($_SESSION['user_id']) && !isset($_SESSION['patient_id'])) {
        header('Location: ../index.php');
        exit;
    }

    // Get current user role
    $user_role = $_SESSION['role'] ?? '';

    // Check if user role is allowed
    if (!in_array($user_role, $allowed_roles)) {
        session_destroy();
        header('Location: ../index.php');
        exit;
    }

    // Optional: Check session timeout (30 minutes)
    $timeout = 30 * 60; // 30 minutes in seconds
    if (isset($_SESSION['last_activity']) && (time() - $_SESSION['last_activity'] > $timeout)) {
        session_destroy();
        header('Location: ../index.php?timeout=1');
        exit;
    }

    // Update last activity time
    $_SESSION['last_activity'] = time();
}

function logout() {
    session_destroy();
    header('Location: ../index.php');
    exit;
}

function get_user_info() {
    return [
        'user_id' => $_SESSION['user_id'] ?? $_SESSION['patient_id'] ?? null,
        'username' => $_SESSION['username'] ?? '',
        'first_name' => $_SESSION['first_name'] ?? '',
        'last_name' => $_SESSION['last_name'] ?? '',
        'email' => $_SESSION['email'] ?? '',
        'role' => $_SESSION['role'] ?? '',
        'user_type' => $_SESSION['user_type'] ?? ''
    ];
}

?>
