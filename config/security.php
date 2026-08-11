<?php

// Load environment variables
if (!function_exists('env')) {
    require_once __DIR__ . '/env.php';
}
if (!function_exists('app_request_is_https')) {
    require_once __DIR__ . '/app.php';
}

if (!function_exists('security_start_session')) {
    function security_start_session()
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            return;
        }

        $secure = app_request_is_https() || (env('SECURE_COOKIES', false) && !app_is_localhost());
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

if (!function_exists('security_set_generated_password_modal')) {
    function security_set_generated_password_modal($password, $accountLabel, $toastMessage = 'Password generated successfully.', $requireChangeOnLogin = false)
    {
        $_SESSION['temp_password_modal'] = [
            'password' => (string)$password,
            'account_label' => (string)$accountLabel,
            'require_change_on_login' => $requireChangeOnLogin ? 1 : 0,
        ];
        $_SESSION['message'] = (string)$toastMessage;
        $_SESSION['message_type'] = 'success';
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
            $conn->query("ALTER TABLE {$table} ADD COLUMN IF NOT EXISTS profile_image VARCHAR(255) DEFAULT NULL");
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

if (!function_exists('security_sanitize_html_input')) {
    /**
     * Strips markup out of free-text input before it is stored (medical history,
     * clinical notes, comments, etc). The app already escapes on output
     * (htmlspecialchars/escapeHtml) everywhere this data is rendered, so this is a
     * second, independent layer: it guarantees no <script>/<img onerror>/etc. markup
     * ever reaches the database in the first place, which also protects any future
     * consumer (PDF export, email templates, API integrations) that might forget to
     * escape on its own.
     */
    function security_sanitize_html_input($value, $maxLen = 0)
    {
        if (!is_string($value)) {
            return '';
        }

        $value = trim($value);
        // Remove script/style blocks (and their contents) before stripping remaining tags,
        // so "<script>alert(1)</script>" doesn't leave "alert(1)" behind as plain text.
        $value = preg_replace('#<(script|style)[^>]*>.*?</\\1>#is', '', $value);
        $value = strip_tags($value);
        // Neutralize anything strip_tags missed (bare "<" from a malformed tag, etc.)
        // and any stray quotes/ampersands so the stored value can never be re-interpreted
        // as markup even if a future render path forgets to escape it.
        $value = htmlspecialchars($value, ENT_QUOTES, 'UTF-8');

        if ($maxLen > 0) {
            $value = mb_substr($value, 0, $maxLen);
        }

        return $value;
    }
}

if (!function_exists('security_sanitize_html_input_array')) {
    /**
     * Applies security_sanitize_html_input() to every string value in an associative
     * array, leaving non-string values (bools, ints, null) untouched. Useful for
     * sanitizing a whole free-text form payload (e.g. patient profiling data) in one call.
     */
    function security_sanitize_html_input_array(array $data)
    {
        foreach ($data as $key => $value) {
            if (is_string($value)) {
                $data[$key] = security_sanitize_html_input($value);
            }
        }
        return $data;
    }
}
