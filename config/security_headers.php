<?php
/**
 * HTTP security headers, sent from PHP so they work even when the host ignores
 * .htaccess "Header" directives (e.g. InfinityFree). Loaded by security.php and
 * database.php, which between them are included by every page and API endpoint.
 */

if (!function_exists('app_request_is_https')) {
    require_once __DIR__ . '/app.php';
}

if (!function_exists('security_send_headers')) {
    function security_send_headers(): void
    {
        static $sent = false;
        if ($sent || PHP_SAPI === 'cli' || headers_sent()) {
            return;
        }
        $sent = true;

        // Hide "X-Powered-By: PHP/x.y" (technology disclosure).
        header_remove('X-Powered-By');

        header('X-Content-Type-Options: nosniff');
        header('X-Frame-Options: SAMEORIGIN');
        header('Referrer-Policy: strict-origin-when-cross-origin');
        header('Permissions-Policy: camera=(), microphone=(), geolocation=(), payment=()');

        // HSTS only over HTTPS on the live host; sending it on localhost would pin
        // the browser to https://localhost.
        if (app_request_is_https() && !app_is_localhost()) {
            header('Strict-Transport-Security: max-age=31536000');
        }

        // The pages rely on inline <script>/<style> and onclick="" handlers, so
        // 'unsafe-inline' is required. External hosts: Google Fonts, Google
        // Sign-In, Cloudflare Turnstile, Tailwind CDN (landing page), Google Maps embed.
        $csp = [
            "default-src 'self'",
            "script-src 'self' 'unsafe-inline' https://accounts.google.com https://challenges.cloudflare.com https://cdn.tailwindcss.com",
            "style-src 'self' 'unsafe-inline' https://fonts.googleapis.com https://accounts.google.com",
            "font-src 'self' data: https://fonts.gstatic.com",
            "img-src 'self' data: blob: https:",
            "media-src 'self' data: blob:",
            "connect-src 'self' blob: data: https://accounts.google.com https://challenges.cloudflare.com",
            "frame-src 'self' https://accounts.google.com https://challenges.cloudflare.com https://maps.google.com https://www.google.com",
            "frame-ancestors 'self'",
            "object-src 'none'",
            "base-uri 'self'",
        ];
        header('Content-Security-Policy: ' . implode('; ', $csp));
    }
}

security_send_headers();
