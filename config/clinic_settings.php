<?php
/**
 * Clinic-wide switches kept in the clinic_settings table (setting_key => setting_value),
 * and the "past visit entry" access rule built on one of them.
 */

if (!function_exists('clinic_settings_ensure_table')) {
    function clinic_settings_ensure_table(mysqli $conn): void
    {
        static $done = false;
        if ($done) return;
        $done = true;
        $conn->query("
            CREATE TABLE IF NOT EXISTS clinic_settings (
                setting_key VARCHAR(100) NOT NULL,
                setting_value TEXT NULL,
                description VARCHAR(255) DEFAULT 'N/A',
                updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (setting_key)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
        ");
    }
}

if (!function_exists('clinic_setting_get')) {
    function clinic_setting_get(mysqli $conn, string $key, ?string $default = null): ?string
    {
        clinic_settings_ensure_table($conn);
        $stmt = $conn->prepare('SELECT setting_value FROM clinic_settings WHERE setting_key = ? LIMIT 1');
        if (!$stmt) return $default;
        $stmt->bind_param('s', $key);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        return $row && $row['setting_value'] !== null ? (string)$row['setting_value'] : $default;
    }
}

if (!function_exists('clinic_setting_set')) {
    function clinic_setting_set(mysqli $conn, string $key, string $value, string $description = 'N/A'): bool
    {
        clinic_settings_ensure_table($conn);
        $stmt = $conn->prepare('
            INSERT INTO clinic_settings (setting_key, setting_value, description) VALUES (?, ?, ?)
            ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value), description = VALUES(description)
        ');
        if (!$stmt) return false;
        $stmt->bind_param('sss', $key, $value, $description);
        $ok = $stmt->execute();
        $stmt->close();
        return (bool)$ok;
    }
}

if (!function_exists('past_visits_entry_enabled')) {
    /** Whether the admin has opened past visit entry to the secretary. */
    function past_visits_entry_enabled(mysqli $conn): bool
    {
        return clinic_setting_get($conn, 'past_visit_entry_enabled', '0') === '1';
    }
}

if (!function_exists('past_visits_allowed')) {
    /**
     * Who may encode a visit from the old paper records: the admin always, the secretary
     * only while the admin has switched access on (admin Settings), nobody else.
     */
    function past_visits_allowed(mysqli $conn, ?string $role = null): bool
    {
        $role = strtolower((string)($role ?? ($_SESSION['role'] ?? '')));
        if ($role === 'admin') return true;
        return $role === 'secretary' && past_visits_entry_enabled($conn);
    }
}

if (!function_exists('appointments_ensure_historical_column')) {
    /**
     * appointments.is_historical marks a visit encoded from paper records after the fact,
     * as opposed to one that went through booking, arrival and the EHR queue.
     */
    function appointments_ensure_historical_column(mysqli $conn): void
    {
        static $checked = false;
        if ($checked) return;
        $checked = true;
        // Checked first instead of "ADD COLUMN IF NOT EXISTS": that syntax is MariaDB-only.
        $col = $conn->query("SHOW COLUMNS FROM appointments LIKE 'is_historical'");
        if ($col && $col->num_rows === 0) {
            $conn->query('ALTER TABLE appointments ADD COLUMN is_historical TINYINT(1) NOT NULL DEFAULT 0');
        }
    }
}
