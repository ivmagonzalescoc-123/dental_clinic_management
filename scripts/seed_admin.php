<?php
/**
 * Admin Account Seeding Script
 * 
 * Creates or updates admin account from environment variables
 * Usage: php scripts/seed_admin.php
 * 
 * This is useful for:
 * - Initial deployment setup
 * - Resetting admin credentials
 * - Batch deployments with environment variables
 */

require_once __DIR__ . '/../config/env.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/security.php';

// Get admin credentials from environment
$admin_email = env('ADMIN_EMAIL', 'admin@dentalclinic.com');
$admin_first_name = env('ADMIN_FIRST_NAME', 'Admin');
$admin_last_name = env('ADMIN_LAST_NAME', 'User');
$admin_password = env('ADMIN_PASSWORD', '');

// Validation
if (!$admin_password || strlen($admin_password) < 8) {
    die("ERROR: ADMIN_PASSWORD must be set in .env and at least 8 characters.\n");
}

if (!filter_var($admin_email, FILTER_VALIDATE_EMAIL)) {
    die("ERROR: ADMIN_EMAIL must be a valid email address.\n");
}

// Check password policy
$policy_errors = security_password_policy_errors($admin_password);
if (!empty($policy_errors)) {
    die("ERROR: Admin password does not meet policy requirements:\n" . 
        implode("\n", $policy_errors) . "\n");
}

echo "=== Admin Account Seeding ===\n";
echo "Email: $admin_email\n";
echo "Name: $admin_first_name $admin_last_name\n\n";

// Ensure admin role exists
$admin_role_id = 1; // Assuming admin role_id is 1
$role_check = $conn->query("SELECT role_id FROM roles WHERE role_id = $admin_role_id LIMIT 1");
if (!$role_check || $role_check->num_rows === 0) {
    echo "⚠️  Warning: Admin role (ID=1) not found. Creating it...\n";
    $conn->query("INSERT IGNORE INTO roles (role_id, role_name) VALUES (1, 'admin')");
}

// Check if admin exists
$email_escaped = $conn->real_escape_string($admin_email);
$result = $conn->query("SELECT user_id, password_hash FROM users WHERE email = '$email_escaped' LIMIT 1");

if ($result && $result->num_rows > 0) {
    $admin = $result->fetch_assoc();
    $admin_id = $admin['user_id'];
    $old_hash = $admin['password_hash'];
    
    // Update existing admin
    $new_hash = security_hash_password($admin_password);
    $first_name_escaped = $conn->real_escape_string($admin_first_name);
    $last_name_escaped = $conn->real_escape_string($admin_last_name);
    
    $update_sql = "UPDATE users SET 
        password_hash = '$new_hash',
        first_name = '$first_name_escaped',
        last_name = '$last_name_escaped',
        password_updated_at = NOW(),
        must_change_password = 0,
        is_active = 1,
        failed_login_attempts = 0,
        locked_until = NULL
        WHERE user_id = $admin_id";
    
    if ($conn->query($update_sql)) {
        echo "✓ Updated existing admin account (ID: $admin_id)\n";
        echo "✓ Password reset successfully\n";
        echo "✓ Account unlocked and activated\n";
    } else {
        die("ERROR: Failed to update admin account: " . $conn->error . "\n");
    }
} else {
    // Create new admin
    $password_hash = security_hash_password($admin_password);
    $first_name_escaped = $conn->real_escape_string($admin_first_name);
    $last_name_escaped = $conn->real_escape_string($admin_last_name);
    
    $insert_sql = "INSERT INTO users 
        (first_name, last_name, email, password_hash, role_id, is_active, created_at, must_change_password)
        VALUES 
        ('$first_name_escaped', '$last_name_escaped', '$email_escaped', '$password_hash', 1, 1, NOW(), 0)";
    
    if ($conn->query($insert_sql)) {
        $admin_id = $conn->insert_id;
        echo "✓ Created new admin account (ID: $admin_id)\n";
        echo "✓ User ID: $admin_id\n";
    } else {
        die("ERROR: Failed to create admin account: " . $conn->error . "\n");
    }
}

echo "\n=== Seeding Complete ===\n";
echo "Admin Login:\n";
echo "  Email: $admin_email\n";
echo "  Password: " . str_repeat('*', strlen($admin_password)) . "\n";
echo "\nYou can now login to the admin panel.\n";
echo "URL: " . env('APP_URL', 'http://localhost/capstone_dental') . "/admin/admin.php\n";

$conn->close();
?>
