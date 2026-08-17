<?php
/**
 * display_helpers.php
 *
 * Shared helpers for rendering nullable DB values as human-readable
 * placeholders in the UI, WITHOUT changing the underlying data.
 * Use these at the point a value is echoed/rendered, not when it's stored.
 */

if (!function_exists('dv')) {
    /**
     * Generic "display value" fallback for any nullable scalar.
     * Empty string, null, and whitespace-only all count as "no value".
     */
    function dv($value, string $fallback = 'N/A'): string
    {
        if ($value === null) return $fallback;
        $str = trim((string)$value);
        return $str === '' ? $fallback : htmlspecialchars($str);
    }
}

if (!function_exists('dv_date')) {
    /**
     * Display a nullable date/datetime column, or a fallback label when
     * the event hasn't happened yet (e.g. "Not yet completed").
     */
    function dv_date($value, string $format = 'M j, Y g:i A', string $fallback = 'Not yet set'): string
    {
        if ($value === null || trim((string)$value) === '' || $value === '0000-00-00' || $value === '0000-00-00 00:00:00') {
            return $fallback;
        }
        $ts = strtotime((string)$value);
        return $ts ? date($format, $ts) : $fallback;
    }
}

if (!function_exists('dv_name')) {
    /**
     * Display a nullable "assigned person" style value (e.g. reviewed_by,
     * processed_by joined to a name), with a status-appropriate fallback.
     */
    function dv_name($value, string $fallback = 'Unassigned'): string
    {
        return dv($value, $fallback);
    }
}
