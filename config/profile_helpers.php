<?php

require_once __DIR__ . '/security.php';

if (!function_exists('profile_default_image_url')) {
    function profile_default_image_url(string $relativeBase = '../'): string
    {
        return rtrim($relativeBase, '/\\') . '/assets/img/profile.png';
    }
}

if (!function_exists('profile_resolve_image_url')) {
    function profile_resolve_image_url(?string $storedPath, string $relativeBase = '../'): string
    {
        $path = trim((string)$storedPath);
        if ($path === '') {
            return profile_default_image_url($relativeBase);
        }
        if (preg_match('#^https?://#i', $path)) {
            return $path;
        }

        $normalized = ltrim(str_replace('\\', '/', $path), '/');
        if (str_contains($normalized, '..')) {
            return profile_default_image_url($relativeBase);
        }

        return rtrim($relativeBase, '/\\') . '/' . $normalized;
    }
}

if (!function_exists('profile_hydrate_session_image')) {
    function profile_hydrate_session_image(mysqli $conn): void
    {
        if (array_key_exists('profile_image', $_SESSION)) {
            return;
        }

        security_ensure_auth_columns($conn);

        $stored = null;
        if (!empty($_SESSION['patient_id'])) {
            $patientId = (int)$_SESSION['patient_id'];
            if ($patientId > 0) {
                $stmt = $conn->prepare('SELECT profile_image FROM patients WHERE patient_id = ? LIMIT 1');
                if ($stmt) {
                    $stmt->bind_param('i', $patientId);
                    $stmt->execute();
                    $row = $stmt->get_result()->fetch_assoc();
                    $stmt->close();
                    $stored = $row['profile_image'] ?? null;
                }
            }
        } elseif (!empty($_SESSION['user_id'])) {
            $userId = (int)$_SESSION['user_id'];
            if ($userId > 0) {
                $stmt = $conn->prepare('SELECT profile_image FROM users WHERE user_id = ? LIMIT 1');
                if ($stmt) {
                    $stmt->bind_param('i', $userId);
                    $stmt->execute();
                    $row = $stmt->get_result()->fetch_assoc();
                    $stmt->close();
                    $stored = $row['profile_image'] ?? null;
                }
            }
        }

        $_SESSION['profile_image'] = $stored;
    }
}

if (!function_exists('profile_session_image_url')) {
    function profile_session_image_url(mysqli $conn, string $relativeBase = '../'): string
    {
        profile_hydrate_session_image($conn);
        return profile_resolve_image_url($_SESSION['profile_image'] ?? null, $relativeBase);
    }
}

if (!function_exists('profile_delete_stored_image')) {
    function profile_delete_stored_image(?string $storedPath): void
    {
        $path = trim((string)$storedPath);
        if ($path === '' || str_contains($path, '..')) {
            return;
        }

        $normalized = ltrim(str_replace('\\', '/', $path), '/');
        if (!str_starts_with($normalized, 'assets/uploads/profile_images/')) {
            return;
        }

        $fullPath = dirname(__DIR__) . '/' . $normalized;
        if (is_file($fullPath)) {
            @unlink($fullPath);
        }
    }
}
