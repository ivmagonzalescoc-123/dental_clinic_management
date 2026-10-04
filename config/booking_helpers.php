<?php

if (!function_exists('bookings_ensure_decline_reason_column')) {
    function bookings_ensure_decline_reason_column(mysqli $conn)
    {
        static $checked = false;
        if ($checked) {
            return;
        }
        $checked = true;

        $conn->query(
            "ALTER TABLE bookings
             ADD COLUMN IF NOT EXISTS decline_reason VARCHAR(500) NULL DEFAULT NULL"
        );
    }
}

if (!function_exists('bookings_ensure_tracking_code_column')) {
    // The auto-increment booking_id is sequential and trivially guessable/enumerable
    // via the public track_booking.php endpoint. tracking_code is a random,
    // unguessable public reference number shown to guests instead; booking_id stays
    // the internal primary key used everywhere on the staff side, unchanged.
    function bookings_ensure_tracking_code_column(mysqli $conn)
    {
        static $checked = false;
        if ($checked) {
            return;
        }
        $checked = true;

        // Checked first: "ADD ... IF NOT EXISTS" is MariaDB-only and fails on MySQL 8.
        $col = $conn->query("SHOW COLUMNS FROM bookings LIKE 'tracking_code'");
        if ($col && $col->num_rows === 0) {
            $conn->query("ALTER TABLE bookings ADD COLUMN tracking_code VARCHAR(10) NULL DEFAULT NULL");
        }
        $idx = $conn->query("SHOW INDEX FROM bookings WHERE Key_name = 'idx_bookings_tracking_code'");
        if ($idx && $idx->num_rows === 0) {
            $conn->query("ALTER TABLE bookings ADD UNIQUE INDEX idx_bookings_tracking_code (tracking_code)");
        }
    }
}

if (!function_exists('bookings_generate_tracking_code')) {
    /**
     * 8-char alphanumeric code, unambiguous charset (no 0/O/1/I/L), ~1e12 combinations
     * (32^8) so brute-forcing it is impractical, while still short enough to type/read.
     */
    function bookings_generate_tracking_code(mysqli $conn): string
    {
        bookings_ensure_tracking_code_column($conn);

        $alphabet = 'ABCDEFGHJKMNPQRSTUVWXYZ23456789';
        $alphabetLen = strlen($alphabet);

        for ($attempt = 0; $attempt < 10; $attempt++) {
            $code = '';
            for ($i = 0; $i < 8; $i++) {
                $code .= $alphabet[random_int(0, $alphabetLen - 1)];
            }

            // Unique across bookings and appointments, so a reference always means one thing.
            if (!reference_code_in_use($conn, $code)) {
                return $code;
            }
        }

        // Extremely unlikely fallback: append microtime entropy to guarantee uniqueness.
        return substr($code . strtoupper(dechex((int)(microtime(true) * 1000))), 0, 8);
    }
}

if (!function_exists('appointments_ensure_reference_column')) {
    /**
     * Every appointment gets the same kind of public reference number guests get for a
     * booking (8 characters, see bookings_generate_tracking_code()). An appointment made
     * from a guest booking reuses that booking's code, so the patient keeps one number.
     * The sequential appointment_id stays the internal key and is never shown to people.
     *
     * Also backfills existing appointments once, so older records have a reference too.
     */
    function appointments_ensure_reference_column(mysqli $conn): void
    {
        static $checked = false;
        if ($checked) {
            return;
        }
        $checked = true;
        bookings_ensure_tracking_code_column($conn);

        // Checked first instead of "ADD COLUMN IF NOT EXISTS": that syntax is MariaDB-only
        // and fails on MySQL 8, while this works on both.
        $col = $conn->query("SHOW COLUMNS FROM appointments LIKE 'reference_code'");
        if ($col && $col->num_rows === 0) {
            $conn->query("ALTER TABLE appointments ADD COLUMN reference_code VARCHAR(10) NULL DEFAULT NULL");
        }
        $idx = $conn->query("SHOW INDEX FROM appointments WHERE Key_name = 'idx_appointments_reference_code'");
        if ($idx && $idx->num_rows === 0) {
            $conn->query("ALTER TABLE appointments ADD UNIQUE INDEX idx_appointments_reference_code (reference_code)");
        }

        $missing = $conn->query('SELECT COUNT(*) AS c FROM appointments WHERE reference_code IS NULL');
        if (!$missing || (int)$missing->fetch_assoc()['c'] === 0) {
            return;
        }
        // Converted bookings: keep the code the guest already has (newest appointment per booking).
        $conn->query(
            "UPDATE appointments a
             INNER JOIN bookings b ON b.booking_id = a.booking_id
             INNER JOIN (SELECT booking_id, MAX(appointment_id) AS latest FROM appointments WHERE booking_id IS NOT NULL GROUP BY booking_id) l
                     ON l.latest = a.appointment_id
             SET a.reference_code = b.tracking_code
             WHERE a.reference_code IS NULL AND b.tracking_code IS NOT NULL AND b.tracking_code <> ''"
        );
        $rest = $conn->query('SELECT appointment_id FROM appointments WHERE reference_code IS NULL ORDER BY appointment_id');
        while ($rest && ($row = $rest->fetch_assoc())) {
            appointment_assign_reference($conn, (int)$row['appointment_id']);
        }
    }
}

if (!function_exists('reference_code_in_use')) {
    function reference_code_in_use(mysqli $conn, string $code): bool
    {
        foreach (['SELECT 1 FROM bookings WHERE tracking_code = ? LIMIT 1', 'SELECT 1 FROM appointments WHERE reference_code = ? LIMIT 1'] as $sql) {
            $st = $conn->prepare($sql);
            if (!$st) continue;
            $st->bind_param('s', $code);
            $st->execute();
            $hit = (bool)$st->get_result()->fetch_row();
            $st->close();
            if ($hit) return true;
        }
        return false;
    }
}

if (!function_exists('appointment_assign_reference')) {
    /**
     * Returns the appointment's reference number, creating it if it has none yet. Call
     * right after inserting an appointment. Reuses the guest booking's code when the
     * appointment came from a booking that no other appointment has claimed.
     */
    function appointment_assign_reference(mysqli $conn, int $appointmentId): string
    {
        if ($appointmentId <= 0) return '';
        appointments_ensure_reference_column($conn);

        $st = $conn->prepare(
            'SELECT a.reference_code, b.tracking_code
             FROM appointments a LEFT JOIN bookings b ON b.booking_id = a.booking_id
             WHERE a.appointment_id = ? LIMIT 1'
        );
        if (!$st) return '';
        $st->bind_param('i', $appointmentId);
        $st->execute();
        $row = $st->get_result()->fetch_assoc();
        $st->close();
        if (!$row) return '';
        if (!empty($row['reference_code'])) return (string)$row['reference_code'];

        $code = '';
        $bookingCode = (string)($row['tracking_code'] ?? '');
        if ($bookingCode !== '') {
            $taken = $conn->prepare('SELECT 1 FROM appointments WHERE reference_code = ? LIMIT 1');
            $taken->bind_param('s', $bookingCode);
            $taken->execute();
            $code = $taken->get_result()->fetch_row() ? '' : $bookingCode;
            $taken->close();
        }
        if ($code === '') {
            $alphabet = 'ABCDEFGHJKMNPQRSTUVWXYZ23456789';
            for ($attempt = 0; $attempt < 10; $attempt++) {
                $candidate = '';
                for ($i = 0; $i < 8; $i++) $candidate .= $alphabet[random_int(0, strlen($alphabet) - 1)];
                if (!reference_code_in_use($conn, $candidate)) { $code = $candidate; break; }
            }
            if ($code === '') return '';
        }

        $up = $conn->prepare('UPDATE appointments SET reference_code = ? WHERE appointment_id = ? AND reference_code IS NULL');
        if ($up) {
            $up->bind_param('si', $code, $appointmentId);
            $up->execute();
            $up->close();
        }
        return $code;
    }
}

if (!function_exists('appointment_reference_label')) {
    /** "Ref K7MP3QXA" for display; falls back to an empty string when there is none. */
    function appointment_reference_label(?string $code): string
    {
        $code = trim((string)$code);
        return $code === '' ? '' : 'Ref ' . $code;
    }
}
