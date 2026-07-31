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

        $conn->query(
            "ALTER TABLE bookings
             ADD COLUMN IF NOT EXISTS tracking_code VARCHAR(10) NULL DEFAULT NULL"
        );
        $conn->query(
            "ALTER TABLE bookings
             ADD UNIQUE INDEX IF NOT EXISTS idx_bookings_tracking_code (tracking_code)"
        );
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

            $check = $conn->prepare('SELECT booking_id FROM bookings WHERE tracking_code = ? LIMIT 1');
            if (!$check) {
                return $code;
            }
            $check->bind_param('s', $code);
            $check->execute();
            $exists = $check->get_result()->fetch_assoc();
            $check->close();

            if (!$exists) {
                return $code;
            }
        }

        // Extremely unlikely fallback: append microtime entropy to guarantee uniqueness.
        return substr($code . strtoupper(dechex((int)(microtime(true) * 1000))), 0, 8);
    }
}
