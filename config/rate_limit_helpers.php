<?php

if (!function_exists('rate_limit_ensure_table')) {
    function rate_limit_ensure_table(mysqli $conn): void
    {
        static $checked = false;
        if ($checked) {
            return;
        }
        $checked = true;

        $conn->query("CREATE TABLE IF NOT EXISTS rate_limit_hits (
            hit_id INT AUTO_INCREMENT PRIMARY KEY,
            bucket VARCHAR(50) NOT NULL,
            identifier VARCHAR(150) NOT NULL,
            created_at DATETIME NOT NULL,
            KEY idx_rate_limit_lookup (bucket, identifier, created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");
    }
}

if (!function_exists('rate_limit_client_ip')) {
    function rate_limit_client_ip(): string
    {
        return (string)($_SERVER['REMOTE_ADDR'] ?? 'unknown');
    }
}

if (!function_exists('rate_limit_check')) {
    /**
     * Sliding-window rate limiter backed by the database, so it holds across PHP
     * processes/workers (unlike session- or in-memory-based limiting). Returns true
     * and records the hit when the identifier is still under its limit for the given
     * window; returns false (and does NOT record a new hit) once the limit is reached,
     * so callers should stop the request there.
     *
     * $bucket    short label for what's being limited, e.g. 'contact_message_ip'
     * $identifier the thing being limited, e.g. an IP address or email address
     */
    function rate_limit_check(mysqli $conn, string $bucket, string $identifier, int $maxAttempts, int $windowSeconds): bool
    {
        rate_limit_ensure_table($conn);

        $identifier = mb_strtolower(trim($identifier));
        $identifier = mb_substr($identifier, 0, 150);
        if ($identifier === '') {
            $identifier = 'unknown';
        }

        // Opportunistic cleanup so the table doesn't grow unbounded; cheap no-op most requests.
        $conn->query("DELETE FROM rate_limit_hits WHERE created_at < DATE_SUB(NOW(), INTERVAL 1 DAY)");

        $windowStart = date('Y-m-d H:i:s', time() - $windowSeconds);
        $stmt = $conn->prepare('SELECT COUNT(*) AS c FROM rate_limit_hits WHERE bucket = ? AND identifier = ? AND created_at >= ?');
        if (!$stmt) {
            // Fail open — a broken rate-limit query should never block legitimate traffic.
            return true;
        }
        $stmt->bind_param('sss', $bucket, $identifier, $windowStart);
        $stmt->execute();
        $res = $stmt->get_result();
        $row = $res ? $res->fetch_assoc() : null;
        $count = (int)($row['c'] ?? 0);
        $stmt->close();

        if ($count >= $maxAttempts) {
            return false;
        }

        $now = date('Y-m-d H:i:s');
        $insert = $conn->prepare('INSERT INTO rate_limit_hits (bucket, identifier, created_at) VALUES (?, ?, ?)');
        if ($insert) {
            $insert->bind_param('sss', $bucket, $identifier, $now);
            $insert->execute();
            $insert->close();
        }

        return true;
    }
}

if (!function_exists('rate_limit_reject')) {
    /** Emits the standard 429 JSON body and exits — call after rate_limit_check() returns false. */
    function rate_limit_reject(string $message = 'Too many requests. Please try again later.'): void
    {
        http_response_code(429);
        echo json_encode(['success' => false, 'message' => $message]);
        exit;
    }
}
