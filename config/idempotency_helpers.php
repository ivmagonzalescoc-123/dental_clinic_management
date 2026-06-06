<?php
/**
 * Lightweight idempotency without database schema changes.
 * Stores completed responses and in-flight locks in the PHP session.
 */

if (!function_exists('idempotency_normalize_key')) {
    function idempotency_normalize_key($key): ?string
    {
        $key = trim((string)$key);
        if ($key === '' || strlen($key) > 128) {
            return null;
        }
        if (!preg_match('/^[a-zA-Z0-9._-]+$/', $key)) {
            return null;
        }
        return $key;
    }
}

if (!function_exists('idempotency_prune_store')) {
    function idempotency_prune_store(): void
    {
        if (empty($_SESSION['idempotency_store']) || !is_array($_SESSION['idempotency_store'])) {
            $_SESSION['idempotency_store'] = [];
            return;
        }

        $now = time();
        foreach ($_SESSION['idempotency_store'] as $scope => $entries) {
            if (!is_array($entries)) {
                unset($_SESSION['idempotency_store'][$scope]);
                continue;
            }
            foreach ($entries as $key => $entry) {
                $expires = (int)($entry['expires'] ?? 0);
                if ($expires > 0 && $expires < $now) {
                    unset($_SESSION['idempotency_store'][$scope][$key]);
                }
            }
            if (empty($_SESSION['idempotency_store'][$scope])) {
                unset($_SESSION['idempotency_store'][$scope]);
            }
        }
    }
}

if (!function_exists('idempotency_get_cached_response')) {
    /**
     * @return array|null Decoded API response payload
     */
    function idempotency_get_cached_response(string $scope, ?string $key): ?array
    {
        $key = idempotency_normalize_key($key);
        if ($key === null) {
            return null;
        }

        idempotency_prune_store();

        $entry = $_SESSION['idempotency_store'][$scope][$key] ?? null;
        if (!is_array($entry)) {
            return null;
        }

        if (($entry['status'] ?? '') === 'processing') {
            $started = (int)($entry['started_at'] ?? 0);
            if ($started > 0 && (time() - $started) < 120) {
                return [
                    'success' => false,
                    'message' => 'This request is already being processed. Please wait.',
                    'code' => 'duplicate_in_flight',
                ];
            }
            unset($_SESSION['idempotency_store'][$scope][$key]);
            return null;
        }

        if (($entry['status'] ?? '') === 'completed' && isset($entry['response']) && is_array($entry['response'])) {
            $response = $entry['response'];
            $response['idempotent_replay'] = true;
            return $response;
        }

        return null;
    }
}

if (!function_exists('idempotency_mark_processing')) {
    function idempotency_mark_processing(string $scope, ?string $key, int $ttl = 3600): bool
    {
        $key = idempotency_normalize_key($key);
        if ($key === null) {
            return true;
        }

        idempotency_prune_store();

        $existing = $_SESSION['idempotency_store'][$scope][$key] ?? null;
        if (is_array($existing)) {
            if (($existing['status'] ?? '') === 'completed') {
                return false;
            }
            if (($existing['status'] ?? '') === 'processing') {
                $started = (int)($existing['started_at'] ?? 0);
                if ($started > 0 && (time() - $started) < 120) {
                    return false;
                }
            }
        }

        if (!isset($_SESSION['idempotency_store'][$scope]) || !is_array($_SESSION['idempotency_store'][$scope])) {
            $_SESSION['idempotency_store'][$scope] = [];
        }

        $_SESSION['idempotency_store'][$scope][$key] = [
            'status' => 'processing',
            'started_at' => time(),
            'expires' => time() + max(300, $ttl),
        ];

        return true;
    }
}

if (!function_exists('idempotency_store_response')) {
    function idempotency_store_response(string $scope, ?string $key, array $response, int $ttl = 3600): void
    {
        $key = idempotency_normalize_key($key);
        if ($key === null) {
            return;
        }

        if (!isset($_SESSION['idempotency_store'][$scope]) || !is_array($_SESSION['idempotency_store'][$scope])) {
            $_SESSION['idempotency_store'][$scope] = [];
        }

        $_SESSION['idempotency_store'][$scope][$key] = [
            'status' => 'completed',
            'response' => $response,
            'expires' => time() + max(300, $ttl),
        ];
    }
}

if (!function_exists('idempotency_clear_processing')) {
    function idempotency_clear_processing(string $scope, ?string $key): void
    {
        $key = idempotency_normalize_key($key);
        if ($key === null) {
            return;
        }

        if (isset($_SESSION['idempotency_store'][$scope][$key])) {
            $entry = $_SESSION['idempotency_store'][$scope][$key];
            if (is_array($entry) && ($entry['status'] ?? '') === 'processing') {
                unset($_SESSION['idempotency_store'][$scope][$key]);
            }
        }
    }
}

if (!function_exists('idempotency_handle_request')) {
    /**
     * Returns cached response to echo immediately, or null to continue processing.
     *
     * @return array|null
     */
    function idempotency_handle_request(string $scope, ?string $key): ?array
    {
        $cached = idempotency_get_cached_response($scope, $key);
        if ($cached !== null) {
            return $cached;
        }

        if (!idempotency_mark_processing($scope, $key)) {
            return [
                'success' => false,
                'message' => 'This request is already being processed. Please wait.',
                'code' => 'duplicate_in_flight',
            ];
        }

        return null;
    }
}

if (!function_exists('billing_find_recent_duplicate_payment')) {
    /**
     * Detect likely duplicate payment using existing payments rows (no schema change).
     */
    function billing_find_recent_duplicate_payment(mysqli $conn, int $billingId, float $amount, string $method, int $recordedBy, int $windowSeconds = 45): ?array
    {
        if ($billingId <= 0 || $amount <= 0) {
            return null;
        }

        $stmt = $conn->prepare('
            SELECT payment_id, amount, payment_method, paid_at
            FROM payments
            WHERE billing_id = ?
              AND recorded_by = ?
              AND ABS(amount - ?) < 0.01
              AND payment_method = ?
              AND paid_at >= DATE_SUB(NOW(), INTERVAL ? SECOND)
            ORDER BY payment_id DESC
            LIMIT 1
        ');
        if (!$stmt) {
            return null;
        }

        $windowSeconds = max(5, min(300, $windowSeconds));
        $stmt->bind_param('iidsi', $billingId, $recordedBy, $amount, $method, $windowSeconds);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        return $row ?: null;
    }
}
