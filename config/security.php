<?php

// Load environment variables
if (!function_exists('env')) {
    require_once __DIR__ . '/env.php';
}

if (!function_exists('security_start_session')) {
    function security_start_session()
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            return;
        }

        $secure = env('SECURE_COOKIES', false) || (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off');
        $params = session_get_cookie_params();
        session_set_cookie_params([
            'lifetime' => (int)env('SESSION_LIFETIME', 1800),
            'path' => env('SESSION_PATH', '/'),
            'domain' => env('SESSION_DOMAIN', ''),
            'secure' => $secure,
            'httponly' => true,
            'samesite' => 'Lax',
        ]);

        ini_set('session.use_only_cookies', '1');
        ini_set('session.use_strict_mode', '1');
        session_start();
    }
}

if (!function_exists('security_generate_csrf_token')) {
    function security_generate_csrf_token()
    {
        security_start_session();
        if (empty($_SESSION['csrf_token'])) {
            $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        }
        return $_SESSION['csrf_token'];
    }
}

if (!function_exists('security_validate_csrf_token')) {
    function security_validate_csrf_token($token)
    {
        security_start_session();
        $sessionToken = $_SESSION['csrf_token'] ?? '';
        return is_string($token) && $sessionToken !== '' && hash_equals($sessionToken, $token);
    }
}

if (!function_exists('security_password_policy_errors')) {
    function security_password_policy_errors($password)
    {
        $errors = [];
        if (!is_string($password) || strlen($password) < 8) {
            $errors[] = 'Password must be at least 8 characters.';
        }
        if (!preg_match('/[A-Z]/', (string)$password)) {
            $errors[] = 'Password must include at least one uppercase letter.';
        }
        if (!preg_match('/[a-z]/', (string)$password)) {
            $errors[] = 'Password must include at least one lowercase letter.';
        }
        if (!preg_match('/\d/', (string)$password)) {
            $errors[] = 'Password must include at least one number.';
        }
        if (!preg_match('/[^A-Za-z0-9]/', (string)$password)) {
            $errors[] = 'Password must include at least one special character.';
        }
        return $errors;
    }
}

if (!function_exists('security_generate_default_password')) {
    function security_generate_default_password()
    {
        $upper = 'ABCDEFGHJKLMNPQRSTUVWXYZ';
        $lower = 'abcdefghijkmnopqrstuvwxyz';
        $digits = '23456789';
        $special = '@#$%&*!?';
        $all = $upper . $lower . $digits . $special;

        $password = '';
        $password .= $upper[random_int(0, strlen($upper) - 1)];
        $password .= $lower[random_int(0, strlen($lower) - 1)];
        $password .= $digits[random_int(0, strlen($digits) - 1)];
        $password .= $special[random_int(0, strlen($special) - 1)];

        for ($i = 0; $i < 8; $i++) {
            $password .= $all[random_int(0, strlen($all) - 1)];
        }

        return str_shuffle($password);
    }
}

if (!function_exists('security_hash_password')) {
    function security_hash_password($password)
    {
        return password_hash($password, PASSWORD_BCRYPT);
    }
}

if (!function_exists('security_needs_rehash')) {
    function security_needs_rehash($hash)
    {
        return is_string($hash) && password_needs_rehash($hash, PASSWORD_BCRYPT);
    }
}

if (!function_exists('security_is_password_hash')) {
    function security_is_password_hash($value)
    {
        if (!is_string($value) || $value === '') {
            return false;
        }
        return password_get_info($value)['algo'] !== null;
    }
}

if (!function_exists('security_ensure_auth_columns')) {
    function security_ensure_auth_columns(mysqli $conn)
    {
        static $done = false;
        if ($done) {
            return;
        }
        $done = true;

        $targets = [
            'users' => ['user_id'],
            'patients' => ['patient_id'],
        ];

        foreach ($targets as $table => $pk) {
            $conn->query("ALTER TABLE {$table} ADD COLUMN IF NOT EXISTS must_change_password TINYINT(1) NOT NULL DEFAULT 0");
            $conn->query("ALTER TABLE {$table} ADD COLUMN IF NOT EXISTS password_updated_at DATETIME DEFAULT NULL");
            $conn->query("ALTER TABLE {$table} ADD COLUMN IF NOT EXISTS failed_login_attempts INT NOT NULL DEFAULT 0");
            $conn->query("ALTER TABLE {$table} ADD COLUMN IF NOT EXISTS locked_until DATETIME DEFAULT NULL");
            $conn->query("ALTER TABLE {$table} ADD COLUMN IF NOT EXISTS last_login_at DATETIME DEFAULT NULL");
        }
    }
}

if (!function_exists('security_ensure_work_type_column')) {
    function security_ensure_work_type_column(mysqli $conn)
    {
        static $done = false;
        if ($done) {
            return;
        }
        $done = true;

        $conn->query(
            "ALTER TABLE users
             ADD COLUMN IF NOT EXISTS work_type ENUM('regular','part_time')
             NOT NULL DEFAULT 'regular'"
        );
    }
}

if (!function_exists('security_ensure_doctor_work_type_column')) {
    function security_ensure_doctor_work_type_column(mysqli $conn)
    {
        security_ensure_work_type_column($conn);
    }
}

if (!function_exists('security_json_input')) {
    function security_json_input()
    {
        $raw = file_get_contents('php://input');
        if (!is_string($raw) || trim($raw) === '') {
            return [];
        }
        $data = json_decode($raw, true);
        return is_array($data) ? $data : [];
    }
}

if (!function_exists('security_clean_string')) {
    function security_clean_string($value, $maxLen = 255)
    {
        $value = is_string($value) ? trim($value) : '';
        if ($maxLen > 0) {
            $value = mb_substr($value, 0, $maxLen);
        }
        return $value;
    }
}
