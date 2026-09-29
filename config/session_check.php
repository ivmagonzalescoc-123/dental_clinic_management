<?php
/**
 * Session Manager - Check user role and redirect if unauthorized
 * Include this file at the top of role-specific pages
 * 
 * Usage in admin/admin.php:
 * include '../config/session_check.php';
 * check_session(['admin']);
 */

require_once __DIR__ . '/security.php';
require_once __DIR__ . '/database.php';
require_once __DIR__ . '/remember_me.php';
security_start_session();

// These portal pages are session-driven and change on every request (sidebar counts,
// active nav state, per-user data). Without this, browsers can heuristically cache a
// response and replay a stale/broken render on later normal or back/forward navigation
// until a hard refresh forces a real fetch.
if (!headers_sent()) {
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    header('Pragma: no-cache');
    header('Expires: 0');
}

if (!defined('SESSION_TIMEOUT_SECONDS')) {
    define('SESSION_TIMEOUT_SECONDS', 30 * 60);
}
if (!defined('SESSION_USER_IDLE_SECONDS')) {
    define('SESSION_USER_IDLE_SECONDS', 60 * 60);
}
if (!defined('SESSION_PATIENT_IDLE_SECONDS')) {
    define('SESSION_PATIENT_IDLE_SECONDS', 15 * 60);
}
if (!defined('SESSION_IDLE_WARNING_GRACE_SECONDS')) {
    define('SESSION_IDLE_WARNING_GRACE_SECONDS', 60);
}

if (!function_exists('get_session_timeout_seconds_for_role')) {
    function get_session_timeout_seconds_for_role(string $role): int
    {
        // Doctor: no timeout
        if ($role === 'doctor') {
            return 0;
        }

        // Secretary: 2 hours idle timeout
        if ($role === 'secretary') {
            return (2 * 60 * 60) + SESSION_IDLE_WARNING_GRACE_SECONDS;
        }

        // Admin and others: keep existing timeout
        $idleLimit = ($role === 'patient') ? SESSION_PATIENT_IDLE_SECONDS : SESSION_USER_IDLE_SECONDS;
        return $idleLimit + SESSION_IDLE_WARNING_GRACE_SECONDS;
    }
}

if (!function_exists('close_session_log_rows')) {
    function close_session_log_rows(mysqli $conn): void
    {
        $userId = isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : 0;
        $userLogId = isset($_SESSION['user_log_id']) ? (int)$_SESSION['user_log_id'] : 0;
        $patientId = isset($_SESSION['patient_id']) ? (int)$_SESSION['patient_id'] : 0;
        $patientLogId = isset($_SESSION['patient_log_id']) ? (int)$_SESSION['patient_log_id'] : 0;

        if ($userId > 0 && $userLogId > 0 && function_exists('table_exists') && table_exists($conn, 'user_logs')) {
            $stmt = $conn->prepare('UPDATE user_logs SET logout_time = NOW() WHERE user_log_id = ? AND user_id = ? AND logout_time IS NULL LIMIT 1');
            if ($stmt) {
                $stmt->bind_param('ii', $userLogId, $userId);
                $stmt->execute();
                $stmt->close();
            }
        }

        if ($patientId > 0 && $patientLogId > 0 && function_exists('table_exists') && table_exists($conn, 'patient_logs')) {
            $stmt = $conn->prepare('UPDATE patient_logs SET logout_time = NOW() WHERE patient_log_id = ? AND patient_id = ? AND logout_time IS NULL LIMIT 1');
            if ($stmt) {
                $stmt->bind_param('ii', $patientLogId, $patientId);
                $stmt->execute();
                $stmt->close();
            }
        }
    }
}

if (!function_exists('touch_session_log_rows')) {
    function touch_session_log_rows(mysqli $conn): void
    {
        $userId = isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : 0;
        $userLogId = isset($_SESSION['user_log_id']) ? (int)$_SESSION['user_log_id'] : 0;
        $patientId = isset($_SESSION['patient_id']) ? (int)$_SESSION['patient_id'] : 0;
        $patientLogId = isset($_SESSION['patient_log_id']) ? (int)$_SESSION['patient_log_id'] : 0;

        if ($userId > 0 && $userLogId > 0 && function_exists('table_exists') && table_exists($conn, 'user_logs')) {
            $stmt = $conn->prepare('UPDATE user_logs SET last_activity = NOW() WHERE user_log_id = ? AND user_id = ? AND logout_time IS NULL LIMIT 1');
            if ($stmt) {
                $stmt->bind_param('ii', $userLogId, $userId);
                $stmt->execute();
                $stmt->close();
            }
        }

        if ($patientId > 0 && $patientLogId > 0 && function_exists('table_exists') && table_exists($conn, 'patient_logs')) {
            $stmt = $conn->prepare('UPDATE patient_logs SET last_activity = NOW() WHERE patient_log_id = ? AND patient_id = ? AND logout_time IS NULL LIMIT 1');
            if ($stmt) {
                $stmt->bind_param('ii', $patientLogId, $patientId);
                $stmt->execute();
                $stmt->close();
            }
        }
    }
}

if (!function_exists('check_session')) {
    function check_session($allowed_roles = []) {
    // Check if user is logged in (or can be restored from a "Remember me" cookie)
    if (!isset($_SESSION['user_id']) && !isset($_SESSION['patient_id'])
        && !(isset($GLOBALS['conn']) && $GLOBALS['conn'] instanceof mysqli && remember_me_try_login($GLOBALS['conn']))) {
        header('Location: ../index.php');
        exit;
    }

    // Get current user role
    $user_role = $_SESSION['role'] ?? '';

    // Check if user role is allowed
    if (!in_array($user_role, $allowed_roles)) {
        if (isset($GLOBALS['conn']) && $GLOBALS['conn'] instanceof mysqli) {
            close_session_log_rows($GLOBALS['conn']);
        }
        session_destroy();
        header('Location: ../index.php');
        exit;
    }

    // Force password change for staff only when admin generated a temporary reset password.
    if (!empty($_SESSION['must_change_password']) && $user_role !== 'patient') {
        $requested = $_GET['page'] ?? '';
        if ($requested !== 'settings') {
            if ($user_role === 'admin') {
                header('Location: admin.php?page=settings&force_password_change=1');
            } elseif ($user_role === 'doctor') {
                header('Location: doctor.php?page=settings&force_password_change=1');
            } elseif ($user_role === 'secretary') {
                header('Location: secretary.php?page=settings&force_password_change=1');
            }
            exit;
        }
    }

    // Check idle timeout only for roles that enforce timeout.
    $timeoutSeconds = get_session_timeout_seconds_for_role((string)$user_role);
    if ($timeoutSeconds > 0 && isset($_SESSION['last_activity']) && (time() - $_SESSION['last_activity'] > $timeoutSeconds)) {
        if (isset($GLOBALS['conn']) && $GLOBALS['conn'] instanceof mysqli) {
            close_session_log_rows($GLOBALS['conn']);
        }
        // Session went stale while the browser was closed: "Remember me" logs back in.
        $_SESSION = [];
        $restored = isset($GLOBALS['conn']) && $GLOBALS['conn'] instanceof mysqli && remember_me_try_login($GLOBALS['conn']);
        if (!$restored || !in_array($_SESSION['role'] ?? '', $allowed_roles)) {
            session_destroy();
            header('Location: ../index.php?timeout=1');
            exit;
        }
        $user_role = $_SESSION['role'];
        $timeoutSeconds = get_session_timeout_seconds_for_role((string)$user_role);
    }

    // Update last activity time
    $_SESSION['last_activity'] = time();
    if (isset($GLOBALS['conn']) && $GLOBALS['conn'] instanceof mysqli) {
        touch_session_log_rows($GLOBALS['conn']);
    }

    // Slide the session cookie's own expiration forward on every active request.
    // security_start_session() sets a fixed lifetime (SESSION_LIFETIME, default
    // 30 min) once when the cookie is first issued at login; without renewing it
    // here, the browser cookie itself expires ~30 min after login regardless of
    // activity, even for roles (like doctor) whose idle-timeout check above is
    // intentionally disabled. Renewing it each request makes the cookie a true
    // sliding window that only expires after real inactivity.
    if (!headers_sent() && session_id() !== '') {
        $slideSeconds = $timeoutSeconds > 0 ? $timeoutSeconds : (int)env('SESSION_GC_MAXLIFETIME', 21600);
        $cookieParams = session_get_cookie_params();
        setcookie(session_name(), session_id(), [
            'expires' => time() + $slideSeconds,
            'path' => $cookieParams['path'],
            'domain' => $cookieParams['domain'],
            'secure' => $cookieParams['secure'],
            'httponly' => $cookieParams['httponly'],
            'samesite' => $cookieParams['samesite'] ?: 'Lax',
        ]);
    }
}
}

if (!function_exists('logout')) {
    function logout() {
        if (isset($GLOBALS['conn']) && $GLOBALS['conn'] instanceof mysqli) {
            close_session_log_rows($GLOBALS['conn']);
        }
        session_destroy();
        header('Location: ../index.php');
        exit;
    }
}

if (!function_exists('get_user_info')) {
    function get_user_info() {
        return [
            'user_id' => $_SESSION['user_id'] ?? $_SESSION['patient_id'] ?? null,
            'email' => $_SESSION['email'] ?? '',
            'first_name' => $_SESSION['first_name'] ?? '',
            'last_name' => $_SESSION['last_name'] ?? '',
            'role' => $_SESSION['role'] ?? '',
            'user_type' => $_SESSION['user_type'] ?? ''
        ];
    }
}
