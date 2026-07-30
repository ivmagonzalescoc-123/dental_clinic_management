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
