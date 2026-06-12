<?php
/**
 * Reset admin password (CLI only).
 *
 * Usage:
 *   php scripts/reset_admin_password.php "NewPassword123!"
 *   php scripts/reset_admin_password.php --email admin@dentalclinic.com "NewPassword123!"
 *   php scripts/reset_admin_password.php --generate
 *   php scripts/reset_admin_password.php --list
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    die('This script can only be run from the command line.');
}

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/security.php';
require_once __DIR__ . '/../config/app.php';

$email = null;
$password = null;
$generate = false;
$listOnly = false;
$args = array_slice($argv, 1);

foreach ($args as $arg) {
    if ($arg === '--generate') {
        $generate = true;
        continue;
    }
    if ($arg === '--list') {
        $listOnly = true;
        continue;
    }
    if (strpos($arg, '--email=') === 0) {
        $email = substr($arg, 8);
        continue;
    }
    if ($arg === '--help' || $arg === '-h') {
        echo "Reset admin password\n\n";
        echo "Usage:\n";
        echo "  php scripts/reset_admin_password.php \"NewPassword123!\"\n";
        echo "  php scripts/reset_admin_password.php --email admin@dentalclinic.com \"NewPassword123!\"\n";
        echo "  php scripts/reset_admin_password.php --generate\n";
        echo "  php scripts/reset_admin_password.php --list\n";
        exit(0);
    }
    if ($password === null && $arg !== '') {
        $password = $arg;
    }
}

function list_admin_accounts(mysqli $conn): array
{
    // role_id 1 = admin in this project (see seed_admin.php / capstone_dental.sql)
    $sql = "SELECT user_id, email, first_name, last_name, is_active
            FROM users
            WHERE role_id = 1
            ORDER BY user_id ASC";
    $result = $conn->query($sql);
    if (!$result) {
        fwrite(STDERR, "ERROR: Could not list admin accounts: {$conn->error}\n");
        exit(1);
    }

    $admins = [];
    while ($row = $result->fetch_assoc()) {
        $admins[] = $row;
    }
    return $admins;
}

$admins = list_admin_accounts($conn);

if ($listOnly) {
    if (empty($admins)) {
        echo "No admin accounts found.\n";
        exit(1);
    }

    echo "Admin accounts:\n";
    foreach ($admins as $admin) {
        $status = ((int)$admin['is_active'] === 1) ? 'active' : 'inactive';
        echo "  ID {$admin['user_id']}: {$admin['email']} ({$admin['first_name']} {$admin['last_name']}, {$status})\n";
    }
    exit(0);
}

if (empty($admins)) {
    fwrite(STDERR, "ERROR: No admin account found. Create one with: php scripts/seed_admin.php\n");
    exit(1);
}

$target = null;
if ($email !== null) {
    foreach ($admins as $admin) {
        if (strcasecmp((string)$admin['email'], $email) === 0) {
            $target = $admin;
            break;
        }
    }
    if ($target === null) {
        fwrite(STDERR, "ERROR: No admin found with email: {$email}\n");
        exit(1);
    }
} elseif (count($admins) === 1) {
    $target = $admins[0];
} else {
    fwrite(STDERR, "Multiple admin accounts found. Use --email or --list:\n");
    foreach ($admins as $admin) {
        fwrite(STDERR, "  - {$admin['email']}\n");
    }
    exit(1);
}

if ($generate) {
    $password = security_generate_default_password();
}

if ($password === null || $password === '') {
    fwrite(STDERR, "ERROR: Provide a password or use --generate.\n");
    fwrite(STDERR, "Example: php scripts/reset_admin_password.php \"NewPassword123!\"\n");
    exit(1);
}

$policy_errors = security_password_policy_errors($password);
if (!empty($policy_errors)) {
    fwrite(STDERR, "ERROR: Password does not meet policy:\n");
    foreach ($policy_errors as $error) {
        fwrite(STDERR, "  - {$error}\n");
    }
    exit(1);
}

$user_id = (int)$target['user_id'];
$hash = security_hash_password($password);

$stmt = $conn->prepare(
    'UPDATE users
     SET password_hash = ?,
         password_updated_at = NOW(),
         must_change_password = 0,
         is_active = 1,
         failed_login_attempts = 0,
         locked_until = NULL
     WHERE user_id = ?'
);

if (!$stmt) {
    fwrite(STDERR, "ERROR: Prepare failed: {$conn->error}\n");
    exit(1);
}

$stmt->bind_param('si', $hash, $user_id);
if (!$stmt->execute()) {
    fwrite(STDERR, "ERROR: Failed to reset password: {$stmt->error}\n");
    exit(1);
}

echo "Admin password reset successfully.\n";
echo "  User ID: {$user_id}\n";
echo "  Email:   {$target['email']}\n";
echo "  Login:   " . app_base_url() . "/index.php\n";

if ($generate) {
    echo "  Password: {$password}\n";
    echo "\nSave this password now. It will not be shown again.\n";
} else {
    echo "  Password: " . str_repeat('*', strlen($password)) . "\n";
}

$stmt->close();
$conn->close();
