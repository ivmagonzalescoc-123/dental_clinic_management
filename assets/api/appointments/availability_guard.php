<?php

if (!defined('APPT_BASE_SLOT_MINUTES')) {
    define('APPT_BASE_SLOT_MINUTES', 10);
}

function appt_table_exists(mysqli $conn, $tableName)
{
    // This gets called on nearly every helper invocation below (often dozens/hundreds of
    // times per request when checking a whole date range) — memoize per request instead of
    // re-running SHOW TABLES every single time for the same table name.
    static $cache = [];
    if (isset($cache[$tableName])) {
        return $cache[$tableName];
    }
    $safeName = $conn->real_escape_string($tableName);
    $q = $conn->query("SHOW TABLES LIKE '{$safeName}'");
    return $cache[$tableName] = ($q && $q->num_rows > 0);
}

function appt_ensure_work_type_column(mysqli $conn)
{
    static $checked = false;
    if ($checked) {
        return;
    }
    $checked = true;

    $conn->query(
        "ALTER TABLE users
         ADD COLUMN IF NOT EXISTS work_type ENUM('regular','part_time')
         NOT NULL DEFAULT 'regular'"
    );
}

function appt_ensure_needs_reassignment_column(mysqli $conn)
{
    static $checked = false;
    if ($checked) {
        return;
    }
    $checked = true;

    $conn->query(
        "ALTER TABLE appointments
         ADD COLUMN IF NOT EXISTS needs_reassignment TINYINT(1) NOT NULL DEFAULT 0"
    );
}

function appt_ensure_cancellation_reason_column(mysqli $conn)
{
    static $checked = false;
    if ($checked) {
        return;
    }
    $checked = true;

    $conn->query(
        "ALTER TABLE appointments
         ADD COLUMN IF NOT EXISTS cancellation_reason VARCHAR(500) NULL DEFAULT NULL"
    );
}

function appt_get_doctor_work_type(mysqli $conn, $doctorId)
{
    static $cache = [];
    $doctorId = (int)$doctorId;
    if ($doctorId <= 0) {
        return 'regular';
    }
    if (isset($cache[$doctorId])) {
        return $cache[$doctorId];
    }

    appt_ensure_work_type_column($conn);

    $stmt = $conn->prepare('SELECT role_id, COALESCE(work_type, "regular") AS work_type FROM users WHERE user_id = ? LIMIT 1');
    if (!$stmt) {
        $cache[$doctorId] = 'regular';
        return $cache[$doctorId];
    }
    $stmt->bind_param('i', $doctorId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$row || (int)($row['role_id'] ?? 0) !== 2) {
        $cache[$doctorId] = 'regular';
        return $cache[$doctorId];
    }

    $type = strtolower(trim((string)($row['work_type'] ?? 'regular')));
    $cache[$doctorId] = ($type === 'part_time') ? 'part_time' : 'regular';
    return $cache[$doctorId];
}

function appt_ensure_standard_timeslots_for_doctor_date(mysqli $conn, $doctorId, $date)
{
    $doctorId = (int)$doctorId;
    if ($doctorId <= 0 || !appt_validate_appointment_date($date) || !appt_table_exists($conn, 'timeslot')) {
        return;
    }

    // Respect clinic days and doctor availability.
    if (!appt_doctor_has_schedule_on_date($conn, $doctorId, $date)) {
        return;
    }

    // Do not generate slots when doctor is on approved leave.
    if (appt_has_doctor_leave($conn, $doctorId, $date)) {
        return;
    }

    // 10-minute slots, lunch break 12:00-13:00, clinic hours 09:00-17:00. The last bookable
    // slot must START by 16:30 (4:30 PM cut-off) even though the clinic itself stays open
    // until 17:00 (5:00 PM) — so the afternoon window extends 10 minutes past the cut-off to
    // 16:40 to include that final 16:30-16:40 slot as a valid starting time.
    $windows = [
        ['09:00:00', '12:00:00'],
        ['13:00:00', '16:40:00'],
    ];
    $slotSeconds = APPT_BASE_SLOT_MINUTES * 60;

    // Build the full set of (start,end) pairs this day should have.
    $wantedSlots = [];
    foreach ($windows as $window) {
        $startSec = strtotime($date . ' ' . $window[0]);
        $endSec = strtotime($date . ' ' . $window[1]);
        for ($cur = $startSec; $cur + $slotSeconds <= $endSec; $cur += $slotSeconds) {
            $wantedSlots[] = [date('H:i:s', $cur), date('H:i:s', $cur + $slotSeconds)];
        }
    }
    if (empty($wantedSlots)) {
        return;
    }

    // One query to see what already exists for the whole day, instead of one SELECT per
    // individual slot (up to ~48 round-trips down to 1). Only the missing ones get inserted,
    // in a single multi-row statement instead of one INSERT per slot.
    $existing = [];
    $existStmt = $conn->prepare('SELECT start_time, end_time FROM timeslot WHERE doctor_id = ? AND slot_date = ?');
    if ($existStmt) {
        $existStmt->bind_param('is', $doctorId, $date);
        $existStmt->execute();
        $res = $existStmt->get_result();
        while ($row = $res->fetch_assoc()) {
            $existing[$row['start_time'] . '|' . $row['end_time']] = true;
        }
        $existStmt->close();
    }

    $missing = [];
    foreach ($wantedSlots as [$slotStart, $slotEnd]) {
        if (!isset($existing[$slotStart . '|' . $slotEnd])) {
            $missing[] = [$slotStart, $slotEnd];
        }
    }
    if (empty($missing)) {
        return; // already fully generated for this day — nothing to do
    }

    $placeholders = [];
    $types = '';
    $params = [];
    foreach ($missing as [$slotStart, $slotEnd]) {
        $placeholders[] = "(?, ?, ?, ?, 'available')";
        $types .= 'isss';
        $params[] = $doctorId;
        $params[] = $date;
        $params[] = $slotStart;
        $params[] = $slotEnd;
    }
    $sql = 'INSERT INTO timeslot (doctor_id, slot_date, start_time, end_time, status) VALUES ' . implode(', ', $placeholders);
    $ins = $conn->prepare($sql);
    if ($ins) {
        $ins->bind_param($types, ...$params);
        $ins->execute();
        $ins->close();
    }
}

function appt_ensure_doctor_leaves_table(mysqli $conn)
{
    static $checked = false;
    if ($checked) {
        return;
    }
    $checked = true;

    if (appt_table_exists($conn, 'doctor_leaves')) {
        return;
    }

    $sql = "
        CREATE TABLE IF NOT EXISTS doctor_leaves (
            leave_id INT(11) NOT NULL AUTO_INCREMENT,
            user_id INT(11) NOT NULL,
            leave_start_date DATE NOT NULL,
            leave_end_date DATE NOT NULL,
            reason VARCHAR(255) DEFAULT NULL,
            status ENUM('pending','approved','rejected') NOT NULL DEFAULT 'approved',
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (leave_id),
            KEY idx_doctor_leave_dates (user_id, leave_start_date, leave_end_date),
            CONSTRAINT fk_doctor_leaves_user FOREIGN KEY (user_id) REFERENCES users(user_id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
    ";
    $conn->query($sql);
}

function appt_validate_appointment_date($date)
{
    if (!is_string($date) || trim($date) === '') {
        return false;
    }
    $dt = DateTime::createFromFormat('Y-m-d', trim($date));
    return $dt && $dt->format('Y-m-d') === trim($date);
}

function appt_validate_reason($reason)
{
    $reason = is_string($reason) ? trim($reason) : '';
    if ($reason !== '' && mb_strlen($reason) > 500) {
        return [false, 'Reason must be 500 characters or less.'];
    }
    if ($reason === '') {
        $reason = 'N/A';
    }
    return [true, $reason];
}

function appt_validate_notes($notes)
{
    $notes = is_string($notes) ? trim($notes) : '';
    if (mb_strlen($notes) > 1000) {
        return [false, 'Notes must be 1000 characters or less.'];
    }
    return [true, $notes];
}

function appt_has_doctor_leave(mysqli $conn, $doctorId, $date)
{
    if (!appt_validate_appointment_date($date)) {
        return false;
    }

    appt_ensure_doctor_leaves_table($conn);

    if (!appt_table_exists($conn, 'doctor_leaves')) {
        return false;
    }

    $stmt = $conn->prepare(
        "SELECT 1
         FROM doctor_leaves
         WHERE user_id = ?
           AND ? BETWEEN leave_start_date AND leave_end_date
           AND status = 'approved'
         LIMIT 1"
    );
    if (!$stmt) {
        return false;
    }

    $stmt->bind_param('is', $doctorId, $date);
    $stmt->execute();
    $res = $stmt->get_result();
    $row = $res ? $res->fetch_assoc() : null;
    $stmt->close();

    return !empty($row);
}

function appt_doctor_has_schedule_on_date(mysqli $conn, $doctorId, $date)
{
    if (!appt_validate_appointment_date($date)) {
        return false;
    }

    $weekday = (int)date('w', strtotime($date)); // 0..6

    // Clinic is open Monday-Saturday only.
    if ($weekday === 0) {
        return false;
    }

    $doctorWorkType = appt_get_doctor_work_type($conn, $doctorId);
    if ($doctorWorkType === 'regular') {
        return true;
    }

    if (appt_table_exists($conn, 'doctor_availability')) {
        $stmt = $conn->prepare('SELECT 1 FROM doctor_availability WHERE user_id = ? AND weekday = ? LIMIT 1');
        if ($stmt) {
            $stmt->bind_param('ii', $doctorId, $weekday);
            $stmt->execute();
            $res = $stmt->get_result();
            $row = $res ? $res->fetch_assoc() : null;
            $stmt->close();
            if (!empty($row)) {
                return true;
            }
        }
    }

    return false;
}

/**
 * Batched equivalent of appt_has_doctor_leave() + appt_doctor_has_schedule_on_date() for
 * checking a whole date range at once (e.g. "is this doctor bookable on each of the next 30
 * days?") — fetches leave ranges and part-time weekday availability ONCE instead of running
 * 2+ queries per day in a loop. Returns a closure that does the actual per-date check purely
 * in PHP (no further queries), so a 30/90-day range check costs a small constant number of
 * queries instead of scaling linearly with the number of days.
 *
 * @return callable(string $date): array{0: bool, 1: string} same shape as appt_assert_doctor_bookable()
 */
function appt_build_bookable_checker(mysqli $conn, int $doctorId): callable
{
    $leaveRanges = [];
    if (appt_table_exists($conn, 'doctor_leaves')) {
        appt_ensure_doctor_leaves_table($conn);
        $stmt = $conn->prepare("SELECT leave_start_date, leave_end_date FROM doctor_leaves WHERE user_id = ? AND status = 'approved'");
        if ($stmt) {
            $stmt->bind_param('i', $doctorId);
            $stmt->execute();
            $res = $stmt->get_result();
            while ($row = $res->fetch_assoc()) {
                $leaveRanges[] = [$row['leave_start_date'], $row['leave_end_date']];
            }
            $stmt->close();
        }
    }

    $workType = appt_get_doctor_work_type($conn, $doctorId);
    $availableWeekdays = null; // null = regular (every Mon-Sat), array = specific weekdays only
    if ($workType !== 'regular' && appt_table_exists($conn, 'doctor_availability')) {
        $availableWeekdays = [];
        $stmt = $conn->prepare('SELECT DISTINCT weekday FROM doctor_availability WHERE user_id = ?');
        if ($stmt) {
            $stmt->bind_param('i', $doctorId);
            $stmt->execute();
            $res = $stmt->get_result();
            while ($row = $res->fetch_assoc()) {
                $availableWeekdays[(int)$row['weekday']] = true;
            }
            $stmt->close();
        }
    }

    return function (string $date) use ($leaveRanges, $availableWeekdays): array {
        if (!appt_validate_appointment_date($date)) {
            return [false, 'Invalid appointment date format.'];
        }

        foreach ($leaveRanges as [$start, $end]) {
            if ($date >= $start && $date <= $end) {
                return [false, 'Doctor is on approved leave on the selected date.'];
            }
        }

        $weekday = (int)date('w', strtotime($date));
        if ($weekday === 0) {
            return [false, 'Doctor has no active schedule on the selected date.'];
        }
        if ($availableWeekdays !== null && !isset($availableWeekdays[$weekday])) {
            return [false, 'Doctor has no active schedule on the selected date.'];
        }

        return [true, 'ok'];
    };
}

function appt_doctor_has_open_timeslot(mysqli $conn, $doctorId, $date)
{
    if (!appt_validate_appointment_date($date) || !appt_table_exists($conn, 'timeslot')) {
        return false;
    }

    appt_ensure_standard_timeslots_for_doctor_date($conn, $doctorId, $date);

    $stmt = $conn->prepare(
        "SELECT 1 FROM timeslot
         WHERE doctor_id = ?
           AND slot_date = ?
           AND status = 'available'
         LIMIT 1"
    );
    if (!$stmt) {
        return false;
    }

    $stmt->bind_param('is', $doctorId, $date);
    $stmt->execute();
    $res = $stmt->get_result();
    $row = $res ? $res->fetch_assoc() : null;
    $stmt->close();

    return !empty($row);
}

function appt_assert_doctor_bookable(mysqli $conn, $doctorId, $date)
{
    if (!appt_validate_appointment_date($date)) {
        return [false, 'Invalid appointment date format.'];
    }

    if (appt_has_doctor_leave($conn, $doctorId, $date)) {
        return [false, 'Doctor is on approved leave on the selected date.'];
    }

    if (!appt_doctor_has_schedule_on_date($conn, $doctorId, $date)) {
        return [false, 'Doctor has no active schedule on the selected date.'];
    }

    return [true, 'ok'];
}
