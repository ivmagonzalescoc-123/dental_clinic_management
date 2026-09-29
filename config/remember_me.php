<?php
/**
 * "Remember me" persistent login.
 *
 * Cookie holds "selector:validator". Only a SHA-256 hash of the validator is stored,
 * so a leaked database row can't be replayed as a cookie. The validator is rotated on
 * every auto-login, and a selector presented with the wrong validator (a sign the
 * cookie was stolen and already used) revokes every remembered device for that account.
 *
 * The in-page idle monitor still logs out through logout_api.php, which revokes the
 * token, so remember-me only restores sessions ended by closing the browser.
 */

if (!function_exists('env')) {
    require_once __DIR__ . '/env.php';
}
if (!function_exists('app_request_is_https')) {
    require_once __DIR__ . '/app.php';
}
require_once __DIR__ . '/system_logs.php';

if (!defined('REMEMBER_ME_COOKIE')) {
    define('REMEMBER_ME_COOKIE', 'dental_remember');
}
if (!defined('REMEMBER_ME_DAYS')) {
    define('REMEMBER_ME_DAYS', 30);
}

if (!function_exists('remember_me_ensure_table')) {
    function remember_me_ensure_table(mysqli $conn): bool
    {
        static $ready = null;
        if ($ready !== null) {
            return $ready;
        }
        $ready = (bool)$conn->query(
            "CREATE TABLE IF NOT EXISTS auth_remember_tokens (
                token_id INT(11) NOT NULL AUTO_INCREMENT,
                account_type VARCHAR(10) NOT NULL,
                account_id INT(11) NOT NULL,
                selector CHAR(24) NOT NULL,
                validator_hash CHAR(64) NOT NULL,
                expires_at DATETIME NOT NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                user_agent VARCHAR(255) DEFAULT NULL,
                PRIMARY KEY (token_id),
                UNIQUE KEY uq_remember_selector (selector),
                KEY idx_remember_account (account_type, account_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci"
        );
        return $ready;
    }
}

if (!function_exists('remember_me_set_cookie')) {
    function remember_me_set_cookie(string $value, int $expires): void
    {
        if (headers_sent()) {
            return;
        }
        setcookie(REMEMBER_ME_COOKIE, $value, [
            'expires' => $expires,
            'path' => (string)env('SESSION_PATH', '/'),
            'domain' => (string)env('SESSION_DOMAIN', ''),
            'secure' => app_request_is_https(),
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
        if ($value === '') {
            unset($_COOKIE[REMEMBER_ME_COOKIE]);
        } else {
            $_COOKIE[REMEMBER_ME_COOKIE] = $value;
        }
    }
}

if (!function_exists('remember_me_read_cookie')) {
    /** @return array{0:string,1:string}|null [selector, validator] */
    function remember_me_read_cookie(): ?array
    {
        $raw = (string)($_COOKIE[REMEMBER_ME_COOKIE] ?? '');
        if (!preg_match('/^([a-f0-9]{24}):([a-f0-9]{64})$/', $raw, $m)) {
            return null;
        }
        return [$m[1], $m[2]];
    }
}

if (!function_exists('remember_me_issue')) {
    /** Create a token for the account and send the cookie. $accountType is 'user' or 'patient'. */
    function remember_me_issue(mysqli $conn, string $accountType, int $accountId): void
    {
        if ($accountId <= 0 || !remember_me_ensure_table($conn)) {
            return;
        }

        // Opportunistic cleanup of expired tokens.
        $conn->query('DELETE FROM auth_remember_tokens WHERE expires_at < NOW()');

        $selector = bin2hex(random_bytes(12));
        $validator = bin2hex(random_bytes(32));
        $hash = hash('sha256', $validator);
        $expires = time() + REMEMBER_ME_DAYS * 86400;
        $expiresAt = date('Y-m-d H:i:s', $expires);
        $userAgent = substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255);

        $stmt = $conn->prepare('INSERT INTO auth_remember_tokens (account_type, account_id, selector, validator_hash, expires_at, user_agent) VALUES (?, ?, ?, ?, ?, ?)');
        if (!$stmt) {
            return;
        }
        $stmt->bind_param('sissss', $accountType, $accountId, $selector, $hash, $expiresAt, $userAgent);
        $ok = $stmt->execute();
        $stmt->close();

        if ($ok) {
            remember_me_set_cookie($selector . ':' . $validator, $expires);
        }
    }
}

if (!function_exists('remember_me_forget_current')) {
    /** Revoke this browser's token and clear the cookie (explicit logout). */
    function remember_me_forget_current(mysqli $conn): void
    {
        $parts = remember_me_read_cookie();
        if ($parts !== null && remember_me_ensure_table($conn)) {
            $stmt = $conn->prepare('DELETE FROM auth_remember_tokens WHERE selector = ?');
            if ($stmt) {
                $stmt->bind_param('s', $parts[0]);
                $stmt->execute();
                $stmt->close();
            }
        }
        if (isset($_COOKIE[REMEMBER_ME_COOKIE])) {
            remember_me_set_cookie('', time() - 3600);
        }
    }
}

if (!function_exists('remember_me_forget_all')) {
    /** Revoke every remembered device for an account (password changed/reset). */
    function remember_me_forget_all(mysqli $conn, string $accountType, int $accountId): void
    {
        if ($accountId <= 0 || !remember_me_ensure_table($conn)) {
            return;
        }
        $stmt = $conn->prepare('DELETE FROM auth_remember_tokens WHERE account_type = ? AND account_id = ?');
        if ($stmt) {
            $stmt->bind_param('si', $accountType, $accountId);
            $stmt->execute();
            $stmt->close();
        }
    }
}

if (!function_exists('remember_me_reset_after_password_change')) {
    /** Own password change: sign out other remembered devices but keep this browser remembered. */
    function remember_me_reset_after_password_change(mysqli $conn, string $accountType, int $accountId): void
    {
        $keepThisDevice = remember_me_read_cookie() !== null;
        remember_me_forget_all($conn, $accountType, $accountId);
        if ($keepThisDevice) {
            remember_me_issue($conn, $accountType, $accountId);
        }
    }
}

if (!function_exists('remember_me_record_login_row')) {
    function remember_me_record_login_row(mysqli $conn, string $accountType, int $accountId): ?int
    {
        $table = $accountType === 'patient' ? 'patient_logs' : 'user_logs';
        $idCol = $accountType === 'patient' ? 'patient_id' : 'user_id';
        if (!function_exists('table_exists') || !table_exists($conn, $table)) {
            return null;
        }
        if (function_exists('login_activity_ensure_columns')) {
            login_activity_ensure_columns($conn);
        }
        $ipAddress = (string)($_SERVER['REMOTE_ADDR'] ?? '');
        $userAgent = substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255);
        $stmt = $conn->prepare("INSERT INTO {$table} ({$idCol}, login_time, last_activity, ip_address, user_agent) VALUES (?, NOW(), NOW(), ?, ?)");
        if (!$stmt) {
            return null;
        }
        $stmt->bind_param('iss', $accountId, $ipAddress, $userAgent);
        $ok = $stmt->execute();
        $insertId = $ok ? (int)$conn->insert_id : 0;
        $stmt->close();
        return $insertId > 0 ? $insertId : null;
    }
}

if (!function_exists('remember_me_try_login')) {
    /**
     * If there is no logged-in session but a valid remember-me cookie, log the account
     * back in (same session keys as login_api.php) and rotate the token.
     * Requires an active PHP session. Returns true when a session was restored.
     */
    function remember_me_try_login(mysqli $conn): bool
    {
        if (isset($_SESSION['user_id']) || isset($_SESSION['patient_id'])) {
            return false;
        }
        if (!isset($_COOKIE[REMEMBER_ME_COOKIE])) {
            return false;
        }
        $parts = remember_me_read_cookie();
        if ($parts === null || !remember_me_ensure_table($conn)) {
            remember_me_set_cookie('', time() - 3600);
            return false;
        }
        [$selector, $validator] = $parts;

        $stmt = $conn->prepare('SELECT token_id, account_type, account_id, validator_hash, expires_at FROM auth_remember_tokens WHERE selector = ? LIMIT 1');
        if (!$stmt) {
            return false;
        }
        $stmt->bind_param('s', $selector);
        $stmt->execute();
        $res = $stmt->get_result();
        $token = $res ? $res->fetch_assoc() : null;
        $stmt->close();

        if (!$token) {
            remember_me_set_cookie('', time() - 3600);
            return false;
        }

        $accountType = (string)$token['account_type'];
        $accountId = (int)$token['account_id'];

        if (!hash_equals((string)$token['validator_hash'], hash('sha256', $validator))) {
            // Right selector, wrong validator: the token was likely stolen and replayed.
            remember_me_forget_all($conn, $accountType, $accountId);
            remember_me_set_cookie('', time() - 3600);
            return false;
        }

        // Single-use: delete now, a fresh token is issued below on success.
        $del = $conn->prepare('DELETE FROM auth_remember_tokens WHERE token_id = ?');
        if ($del) {
            $tokenId = (int)$token['token_id'];
            $del->bind_param('i', $tokenId);
            $del->execute();
            $del->close();
        }

        if (strtotime((string)$token['expires_at']) < time()) {
            remember_me_set_cookie('', time() - 3600);
            return false;
        }

        if ($accountType === 'user') {
            $q = $conn->prepare('SELECT u.user_id, u.first_name, u.last_name, u.email, u.is_active, u.locked_until,
                                        u.must_change_password, u.profile_image, r.role_name
                                 FROM users u LEFT JOIN role r ON r.role_id = u.role_id
                                 WHERE u.user_id = ? LIMIT 1');
        } else {
            $q = $conn->prepare('SELECT patient_id, first_name, last_name, email, locked_until, profile_image
                                 FROM patients WHERE patient_id = ? LIMIT 1');
        }
        if (!$q) {
            return false;
        }
        $q->bind_param('i', $accountId);
        $q->execute();
        $qr = $q->get_result();
        $account = $qr ? $qr->fetch_assoc() : null;
        $q->close();

        $locked = !empty($account['locked_until']) && strtotime((string)$account['locked_until']) > time();
        $inactive = $accountType === 'user' && (int)($account['is_active'] ?? 0) !== 1;
        if (!$account || $locked || $inactive) {
            remember_me_forget_all($conn, $accountType, $accountId);
            remember_me_set_cookie('', time() - 3600);
            return false;
        }

        session_regenerate_id(true);
        $_SESSION = [];
        $_SESSION['email'] = $account['email'];
        $_SESSION['first_name'] = $account['first_name'];
        $_SESSION['last_name'] = $account['last_name'];
        $_SESSION['profile_image'] = $account['profile_image'] ?? null;
        $_SESSION['last_activity'] = time();

        if ($accountType === 'user') {
            $_SESSION['user_id'] = $accountId;
            $_SESSION['role'] = $account['role_name'] ?? 'admin';
            $_SESSION['user_type'] = 'user';
            $_SESSION['must_change_password'] = (int)($account['must_change_password'] ?? 0) === 1;
            $logId = remember_me_record_login_row($conn, 'user', $accountId);
            if ($logId !== null) {
                $_SESSION['user_log_id'] = $logId;
            }
        } else {
            $_SESSION['patient_id'] = $accountId;
            $_SESSION['role'] = 'patient';
            $_SESSION['user_type'] = 'patient';
            $_SESSION['must_change_password'] = false;
            $logId = remember_me_record_login_row($conn, 'patient', $accountId);
            if ($logId !== null) {
                $_SESSION['patient_log_id'] = $logId;
            }
        }

        if (function_exists('log_system_event')) {
            $table = $accountType === 'user' ? 'users' : 'patients';
            $label = $accountType === 'user' ? 'User' : 'Patient';
            log_system_event($conn, 'auth.login', $table, $accountId, $label . ' login restored via Remember me', $accountType === 'user' ? $accountId : null);
        }

        remember_me_issue($conn, $accountType, $accountId);
        return true;
    }
}
