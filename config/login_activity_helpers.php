<?php

if (!function_exists('login_activity_ensure_columns')) {
    function login_activity_ensure_columns(mysqli $conn)
    {
        static $checked = false;
        if ($checked) {
            return;
        }
        $checked = true;

        $conn->query("ALTER TABLE patient_logs ADD COLUMN IF NOT EXISTS user_agent VARCHAR(255) NULL DEFAULT NULL");
        $conn->query("ALTER TABLE user_logs ADD COLUMN IF NOT EXISTS user_agent VARCHAR(255) NULL DEFAULT NULL");
    }
}

// Lightweight user-agent sniffing — no external library. Good enough to tell
// desktop vs. mobile vs. tablet and a friendly OS/browser label for display.
if (!function_exists('login_activity_parse_user_agent')) {
    function login_activity_parse_user_agent(?string $userAgent): array
    {
        $ua = (string)$userAgent;

        if ($ua === '') {
            return [
                'device_type' => 'unknown',
                'icon' => 'bi-question-circle',
                'os' => 'Unknown device',
                'browser' => '',
                'label' => 'Unknown device',
            ];
        }

        $isTablet = (bool)preg_match('/iPad|Android(?!.*Mobile)|Tablet/i', $ua);
        $isMobile = !$isTablet && (bool)preg_match('/Mobi|iPhone|Android|Windows Phone/i', $ua);

        if ($isTablet) {
            $deviceType = 'tablet';
            $icon = 'bi-tablet';
        } elseif ($isMobile) {
            $deviceType = 'mobile';
            $icon = 'bi-phone';
        } else {
            $deviceType = 'desktop';
            $icon = 'bi-display';
        }

        $os = 'Unknown OS';
        if (preg_match('/Windows NT/i', $ua)) {
            $os = 'Windows';
        } elseif (preg_match('/Mac OS X/i', $ua)) {
            $os = 'macOS';
        } elseif (preg_match('/iPhone|iPad|iPod/i', $ua)) {
            $os = 'iOS';
        } elseif (preg_match('/Android/i', $ua)) {
            $os = 'Android';
        } elseif (preg_match('/Linux/i', $ua)) {
            $os = 'Linux';
        }

        $browser = 'Unknown browser';
        if (preg_match('/Edg\//i', $ua)) {
            $browser = 'Edge';
        } elseif (preg_match('/OPR\//i', $ua)) {
            $browser = 'Opera';
        } elseif (preg_match('/Chrome\//i', $ua)) {
            $browser = 'Chrome';
        } elseif (preg_match('/CriOS\//i', $ua)) {
            $browser = 'Chrome';
        } elseif (preg_match('/Firefox\//i', $ua)) {
            $browser = 'Firefox';
        } elseif (preg_match('/Safari\//i', $ua)) {
            $browser = 'Safari';
        }

        return [
            'device_type' => $deviceType,
            'icon' => $icon,
            'os' => $os,
            'browser' => $browser,
            'label' => trim($os . ' · ' . $browser, ' ·'),
        ];
    }
}

if (!function_exists('login_activity_get_logs')) {
    /**
     * @param string $table 'patient_logs' or 'user_logs'
     * @param string $idColumn 'patient_id' or 'user_id'
     */
    function login_activity_get_logs(mysqli $conn, string $table, string $idColumn, int $idValue, int $limit = 8): array
    {
        $allowedTables = ['patient_logs' => 'patient_id', 'user_logs' => 'user_id'];
        if (!isset($allowedTables[$table]) || $allowedTables[$table] !== $idColumn) {
            return [];
        }

        login_activity_ensure_columns($conn);

        $logIdCol = $table === 'patient_logs' ? 'patient_log_id' : 'user_log_id';
        $sql = "SELECT {$logIdCol} AS log_id, login_time, last_activity, logout_time, ip_address, user_agent
                FROM {$table}
                WHERE {$idColumn} = ?
                ORDER BY {$logIdCol} DESC
                LIMIT ?";
        $stmt = $conn->prepare($sql);
        if (!$stmt) {
            return [];
        }
        $stmt->bind_param('ii', $idValue, $limit);
        $stmt->execute();
        $res = $stmt->get_result();
        $rows = [];
        while ($row = $res->fetch_assoc()) {
            $rows[] = $row;
        }
        $stmt->close();
        return $rows;
    }
}
