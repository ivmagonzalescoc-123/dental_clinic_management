<?php

require_once __DIR__ . '/security.php';

if (!function_exists('profile_role_uses_default_png')) {
    function profile_role_uses_default_png(?string $role = null): bool
    {
        $role = strtolower(trim($role ?? (string)($_SESSION['role'] ?? '')));
        return $role === 'admin';
    }
}

if (!function_exists('profile_role_allows_custom_photo')) {
    function profile_role_allows_custom_photo(?string $role = null): bool
    {
        return !profile_role_uses_default_png($role);
    }
}

if (!function_exists('profile_photo_max_bytes')) {
    function profile_photo_max_bytes(): int
    {
        return 2 * 1024 * 1024;
    }
}

if (!function_exists('profile_photo_allowed_mime_types')) {
    /** @return array<string, string> */
    function profile_photo_allowed_mime_types(): array
    {
        return [
            'image/jpeg' => 'jpg',
            'image/png' => 'png',
            'image/webp' => 'webp',
        ];
    }
}

if (!function_exists('profile_photo_upload_guide_text')) {
    function profile_photo_upload_guide_text(): string
    {
        return 'Accepted formats: JPG, PNG, or WEBP. Maximum file size: 2 MB.';
    }
}

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

if (!function_exists('profile_has_custom_photo')) {
    function profile_has_custom_photo(mysqli $conn): bool
    {
        if (profile_role_uses_default_png()) {
            return false;
        }

        profile_hydrate_session_image($conn);
        $path = trim((string)($_SESSION['profile_image'] ?? ''));
        return $path !== '';
    }
}

if (!function_exists('profile_session_image_url')) {
    function profile_session_image_url(mysqli $conn, string $relativeBase = '../'): string
    {
        if (profile_role_uses_default_png()) {
            return profile_default_image_url($relativeBase);
        }

        if (!profile_has_custom_photo($conn)) {
            return '';
        }

        profile_hydrate_session_image($conn);
        return profile_resolve_image_url($_SESSION['profile_image'] ?? null, $relativeBase);
    }
}

if (!function_exists('profile_render_avatar_markup')) {
    function profile_render_avatar_markup(mysqli $conn, string $relativeBase = '../', array $options = []): string
    {
        $class = (string)($options['class'] ?? 'rounded-circle header-profile-avatar');
        $alt = (string)($options['alt'] ?? 'Profile');
        $iconClass = (string)($options['icon_class'] ?? 'bi bi-person-fill');

        if (profile_role_uses_default_png()) {
            $url = profile_default_image_url($relativeBase);
            return '<img src="' . htmlspecialchars($url, ENT_QUOTES, 'UTF-8') . '" alt="' . htmlspecialchars($alt, ENT_QUOTES, 'UTF-8') . '" class="' . htmlspecialchars($class, ENT_QUOTES, 'UTF-8') . '">';
        }

        if (profile_has_custom_photo($conn)) {
            $url = profile_session_image_url($conn, $relativeBase);
            return '<img src="' . htmlspecialchars($url, ENT_QUOTES, 'UTF-8') . '" alt="' . htmlspecialchars($alt, ENT_QUOTES, 'UTF-8') . '" class="' . htmlspecialchars($class, ENT_QUOTES, 'UTF-8') . '">';
        }

        return '<span class="profile-avatar-icon ' . htmlspecialchars($class, ENT_QUOTES, 'UTF-8') . '" role="img" aria-label="' . htmlspecialchars($alt, ENT_QUOTES, 'UTF-8') . '">' .
            '<i class="' . htmlspecialchars($iconClass, ENT_QUOTES, 'UTF-8') . '" aria-hidden="true"></i>' .
            '</span>';
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
