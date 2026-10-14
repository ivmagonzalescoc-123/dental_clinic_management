<?php
/**
 * Doctor schedule changes: working hours for a date range on chosen weekdays
 * ("Oct 20 – Dec 31, Mon/Wed/Fri, 1 PM – 5 PM"). Whole days off are not a schedule change:
 * they are leave (doctor_leaves), which the admin approves and which tells booked patients.
 * (The table still allows kind 'off' and it is honoured if present, but no form creates it.)
 *
 * A doctor's own change applies straight away (no admin approval). A change the secretary
 * asks for waits until the doctor approves it. Approved changes override the doctor's usual
 * week (regular: Mon–Sat; part-time: the weekdays in doctor_availability); when two cover
 * the same day, the newer one wins. Booking reads all of this through
 * assets/api/appointments/availability_guard.php, so the time slots follow automatically.
 *
 */

if (!function_exists('dsc_ensure_table')) {

    /** Clinic hours a schedule can use: 9 AM – 5 PM, lunch 12–1, last start 4:30 PM. */
    function dsc_clinic_windows(): array
    {
        return [['09:00:00', '12:00:00'], ['13:00:00', '16:40:00']];
    }

    /** Times offered in the forms: every 30 minutes from 9:00 to 17:00. */
    function dsc_time_options(): array
    {
        $out = [];
        for ($m = 9 * 60; $m <= 17 * 60; $m += 30) {
            $out[] = sprintf('%02d:%02d:00', intdiv($m, 60), $m % 60);
        }
        return $out;
    }

    function dsc_ensure_table(mysqli $conn): void
    {
        static $done = false;
        if ($done) return;
        $done = true;
        $conn->query("
            CREATE TABLE IF NOT EXISTS doctor_schedule_changes (
                change_id INT NOT NULL AUTO_INCREMENT,
                user_id INT NOT NULL,
                start_date DATE NOT NULL,
                end_date DATE NOT NULL,
                weekdays VARCHAR(20) NOT NULL DEFAULT '',
                kind ENUM('hours','off') NOT NULL DEFAULT 'hours',
                start_time TIME NULL DEFAULT NULL,
                end_time TIME NULL DEFAULT NULL,
                note VARCHAR(255) NULL DEFAULT NULL,
                status ENUM('pending','approved','declined','cancelled') NOT NULL DEFAULT 'approved',
                requested_by INT NULL DEFAULT NULL,
                requested_role VARCHAR(20) NOT NULL DEFAULT 'doctor',
                decided_at DATETIME NULL DEFAULT NULL,
                created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (change_id),
                KEY idx_dsc_doctor_dates (user_id, status, start_date, end_date),
                CONSTRAINT fk_dsc_doctor FOREIGN KEY (user_id) REFERENCES users (user_id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
        ");
    }

    /** Weekdays (1 = Mon … 6 = Sat) a change covers; empty means every clinic day. */
    function dsc_weekdays(array $change): array
    {
        $days = array_filter(array_map('intval', explode(',', (string)($change['weekdays'] ?? ''))), function ($d) {
            return $d >= 1 && $d <= 6;
        });
        return $days ? array_values(array_unique($days)) : [1, 2, 3, 4, 5, 6];
    }

    function dsc_change_applies(array $change, string $date): bool
    {
        if ($date < (string)$change['start_date'] || $date > (string)$change['end_date']) return false;
        return in_array((int)date('w', strtotime($date)), dsc_weekdays($change), true);
    }

    /**
     * Working hours on a date, or null when the doctor doesn't work that day.
     * @param string $workType 'regular' | 'part_time'
     * @param array  $availability weekday => ['start' => 'H:i:s', 'end' => 'H:i:s'] (part-time week)
     * @param array  $changes approved changes for this doctor
     * @return array{start:string,end:string,change:?array}|null
     */
    function dsc_hours_for_date(string $workType, array $availability, array $changes, string $date): ?array
    {
        $weekday = (int)date('w', strtotime($date));
        if ($weekday === 0) return null; // clinic closed on Sundays

        $winner = null;
        foreach ($changes as $change) {
            if (dsc_change_applies($change, $date) && ($winner === null || (int)$change['change_id'] > (int)$winner['change_id'])) {
                $winner = $change;
            }
        }
        if ($winner !== null) {
            if ($winner['kind'] === 'off') return null;
            return ['start' => (string)$winner['start_time'], 'end' => (string)$winner['end_time'], 'change' => $winner];
        }

        if ($workType === 'part_time') {
            if (empty($availability[$weekday])) return null;
            return ['start' => (string)$availability[$weekday]['start'], 'end' => (string)$availability[$weekday]['end'], 'change' => null];
        }
        return ['start' => '09:00:00', 'end' => '17:00:00', 'change' => null];
    }

    /** Approved changes, oldest first, for one doctor or (doctorId 0) all doctors keyed by doctor. */
    function dsc_load_changes(mysqli $conn, int $doctorId = 0, ?string $from = null, ?string $to = null, array $statuses = ['approved']): array
    {
        dsc_ensure_table($conn);
        $where = [];
        $types = '';
        $params = [];
        if ($doctorId > 0) { $where[] = 'c.user_id = ?'; $types .= 'i'; $params[] = $doctorId; }
        if ($from !== null) { $where[] = 'c.end_date >= ?'; $types .= 's'; $params[] = $from; }
        if ($to !== null) { $where[] = 'c.start_date <= ?'; $types .= 's'; $params[] = $to; }
        $statuses = array_values(array_intersect($statuses, ['pending', 'approved', 'declined', 'cancelled']));
        if ($statuses) {
            $where[] = 'c.status IN (' . implode(',', array_fill(0, count($statuses), '?')) . ')';
            $types .= str_repeat('s', count($statuses));
            array_push($params, ...$statuses);
        }
        $sql = "SELECT c.*, CONCAT(r.first_name, ' ', r.last_name) AS requested_by_name, CONCAT(d.first_name, ' ', d.last_name) AS doctor_name
                FROM doctor_schedule_changes c
                LEFT JOIN users r ON r.user_id = c.requested_by
                LEFT JOIN users d ON d.user_id = c.user_id"
            . ($where ? ' WHERE ' . implode(' AND ', $where) : '')
            . ' ORDER BY c.change_id ASC';
        $stmt = $conn->prepare($sql);
        if (!$stmt) return [];
        if ($types !== '') $stmt->bind_param($types, ...$params);
        $stmt->execute();
        $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();
        if ($doctorId > 0) return $rows;
        $byDoctor = [];
        foreach ($rows as $row) $byDoctor[(int)$row['user_id']][] = $row;
        return $byDoctor;
    }

    /** Everything needed to work out a doctor's hours, loaded once per request. */
    function dsc_doctor_context(mysqli $conn, int $doctorId, bool $refresh = false): array
    {
        static $cache = [];
        if (!$refresh && isset($cache[$doctorId])) return $cache[$doctorId];

        $workType = 'regular';
        $stmt = $conn->prepare("SELECT COALESCE(work_type, 'regular') AS work_type FROM users WHERE user_id = ? LIMIT 1");
        if ($stmt) {
            $stmt->bind_param('i', $doctorId);
            $stmt->execute();
            $row = $stmt->get_result()->fetch_assoc();
            $stmt->close();
            $workType = ($row && $row['work_type'] === 'part_time') ? 'part_time' : 'regular';
        }
        $availability = [];
        $res = $conn->query('SHOW TABLES LIKE "doctor_availability"');
        if ($res && $res->num_rows > 0) {
            $stmt = $conn->prepare("SELECT weekday, start_time, end_time FROM doctor_availability WHERE user_id = ? AND status = 'active'");
            if ($stmt) {
                $stmt->bind_param('i', $doctorId);
                $stmt->execute();
                foreach ($stmt->get_result()->fetch_all(MYSQLI_ASSOC) as $a) {
                    $availability[(int)$a['weekday']] = ['start' => (string)$a['start_time'], 'end' => (string)$a['end_time']];
                }
                $stmt->close();
            }
        }
        return $cache[$doctorId] = [
            'work_type' => $workType,
            'availability' => $availability,
            'changes' => dsc_load_changes($conn, $doctorId),
        ];
    }

    function dsc_hours_on(mysqli $conn, int $doctorId, string $date): ?array
    {
        $ctx = dsc_doctor_context($conn, $doctorId);
        return dsc_hours_for_date($ctx['work_type'], $ctx['availability'], $ctx['changes'], $date);
    }

    /** The clinic windows cut down to these hours (the time-slot ranges for that day). */
    function dsc_slot_windows(string $start, string $end): array
    {
        $out = [];
        foreach (dsc_clinic_windows() as [$ws, $we]) {
            $s = max($ws, $start);
            $e = min($we, $end);
            if ($s < $e) $out[] = [$s, $e];
        }
        return $out;
    }

    // ---------- labels ----------

    function dsc_time_label(string $time): string
    {
        $ts = strtotime($time);
        return $ts === false ? '' : date((int)date('i', $ts) === 0 ? 'g A' : 'g:i A', $ts);
    }

    function dsc_days_label(array $change): string
    {
        $days = dsc_weekdays($change);
        if (count($days) === 6) return 'Mon – Sat';
        $names = [1 => 'Mon', 2 => 'Tue', 3 => 'Wed', 4 => 'Thu', 5 => 'Fri', 6 => 'Sat'];
        sort($days);
        return implode(', ', array_map(function ($d) use ($names) { return $names[$d]; }, $days));
    }

    function dsc_dates_label(array $change): string
    {
        $s = strtotime((string)$change['start_date']);
        $e = strtotime((string)$change['end_date']);
        if ($s === $e) return date('M j, Y', $s);
        return date('Y', $s) === date('Y', $e) ? date('M j', $s) . ' – ' . date('M j, Y', $e) : date('M j, Y', $s) . ' – ' . date('M j, Y', $e);
    }

    function dsc_what_label(array $change): string
    {
        return $change['kind'] === 'off'
            ? 'Not available'
            : dsc_time_label((string)$change['start_time']) . ' – ' . dsc_time_label((string)$change['end_time']);
    }

    function dsc_summary(array $change): string
    {
        return dsc_dates_label($change) . ' · ' . dsc_days_label($change) . ' · ' . dsc_what_label($change);
    }

    // ---------- create / decide ----------

    /**
     * Checks a change from a form. Returns [values, error]; error is null when it's fine.
     */
    function dsc_validate(array $in): array
    {
        $start = trim((string)($in['start_date'] ?? ''));
        $end = trim((string)($in['end_date'] ?? ''));
        if ($end === '') $end = $start;
        // Days off go through a leave request (admin approval, booked patients are told).
        if (($in['kind'] ?? 'hours') === 'off') {
            return [null, 'For a whole day off, file a leave request instead.'];
        }
        $kind = 'hours';
        $startTime = trim((string)($in['start_time'] ?? ''));
        $endTime = trim((string)($in['end_time'] ?? ''));
        $note = mb_substr(trim((string)($in['note'] ?? '')), 0, 255);
        $days = array_values(array_unique(array_filter(array_map('intval', (array)($in['weekdays'] ?? [])), function ($d) {
            return $d >= 1 && $d <= 6;
        })));
        sort($days);

        $isDate = function ($v) {
            $d = DateTime::createFromFormat('!Y-m-d', $v);
            return $d && $d->format('Y-m-d') === $v;
        };
        if (!$isDate($start) || !$isDate($end)) return [null, 'Choose the dates.'];
        if ($start < date('Y-m-d')) return [null, 'The change cannot start in the past.'];
        if ($end < $start) return [null, 'The end date must be on or after the start date.'];
        if ((strtotime($end) - strtotime($start)) / 86400 > 366) return [null, 'Keep a change to one year or less.'];

        // At least one chosen weekday must fall inside the dates.
        $covers = false;
        for ($t = strtotime($start), $i = 0; $t <= strtotime($end) && $i < 7; $t = strtotime('+1 day', $t), $i++) {
            $w = (int)date('w', $t);
            if ($w !== 0 && (!$days || in_array($w, $days, true))) { $covers = true; break; }
        }
        if (!$covers) return [null, 'None of the chosen days fall within those dates (the clinic is closed on Sundays).'];

        $options = dsc_time_options();
        if (!in_array($startTime, $options, true) || !in_array($endTime, $options, true)) {
            return [null, 'Choose the start and end time.'];
        }
        if ($endTime <= $startTime) return [null, 'The end time must be after the start time.'];
        if (!dsc_slot_windows($startTime, $endTime)) return [null, 'Those hours fall inside the lunch break. Choose other hours.'];

        return [[
            'start_date' => $start,
            'end_date' => $end,
            'weekdays' => implode(',', $days),
            'kind' => $kind,
            'start_time' => $startTime,
            'end_time' => $endTime,
            'note' => $note !== '' ? $note : null,
        ], null];
    }

    /** Saves a change. A doctor's own change is approved at once; anyone else's is pending. */
    function dsc_create(mysqli $conn, int $doctorId, array $values, int $requestedBy, string $role): int
    {
        dsc_ensure_table($conn);
        $status = $role === 'doctor' ? 'approved' : 'pending';
        $decidedAt = $status === 'approved' ? date('Y-m-d H:i:s') : null;
        $stmt = $conn->prepare('INSERT INTO doctor_schedule_changes (user_id, start_date, end_date, weekdays, kind, start_time, end_time, note, status, requested_by, requested_role, decided_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
        if (!$stmt) return 0;
        $stmt->bind_param(
            'issssssssiss',
            $doctorId, $values['start_date'], $values['end_date'], $values['weekdays'], $values['kind'],
            $values['start_time'], $values['end_time'], $values['note'], $status, $requestedBy, $role, $decidedAt
        );
        $ok = $stmt->execute();
        $id = $ok ? (int)$conn->insert_id : 0;
        $stmt->close();
        if ($id > 0) dsc_doctor_context($conn, $doctorId, true);
        return $id;
    }

    function dsc_get(mysqli $conn, int $changeId): ?array
    {
        dsc_ensure_table($conn);
        $stmt = $conn->prepare('SELECT * FROM doctor_schedule_changes WHERE change_id = ? LIMIT 1');
        if (!$stmt) return null;
        $stmt->bind_param('i', $changeId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        return $row ?: null;
    }

    function dsc_set_status(mysqli $conn, int $changeId, string $from, string $to): bool
    {
        $stmt = $conn->prepare('UPDATE doctor_schedule_changes SET status = ?, decided_at = NOW() WHERE change_id = ? AND status = ?');
        if (!$stmt) return false;
        $stmt->bind_param('sis', $to, $changeId, $from);
        $stmt->execute();
        $ok = $stmt->affected_rows > 0;
        $stmt->close();
        return $ok;
    }

    /**
     * Upcoming booked appointments that no longer fit the doctor's hours (after a change is
     * approved or removed). They are flagged for the secretary to move (needs_reassignment,
     * the same flag approved leave uses), not cancelled, and each newly flagged patient gets
     * an in-app notice (no email: the email goes out when the secretary sets the new time).
     * Returns how many need moving.
     */
    function dsc_flag_conflicts(mysqli $conn, int $doctorId, string $from, string $to): int
    {
        $from = max($from, date('Y-m-d'));
        if ($to < $from) return 0;
        $conn->query('ALTER TABLE appointments ADD COLUMN IF NOT EXISTS needs_reassignment TINYINT(1) NOT NULL DEFAULT 0');
        dsc_doctor_context($conn, $doctorId, true);

        $stmt = $conn->prepare("
            SELECT a.appointment_id, a.patient_id, a.appointment_date, a.needs_reassignment, t.start_time, t.end_time
            FROM appointments a
            INNER JOIN timeslot t ON t.timeslot_id = a.timeslot_id
            WHERE a.user_id = ? AND a.appointment_date BETWEEN ? AND ?
              AND a.status IN ('scheduled', 'confirmed')
        ");
        if (!$stmt) return 0;
        $stmt->bind_param('iss', $doctorId, $from, $to);
        $stmt->execute();
        $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();

        $flag = [];
        foreach ($rows as $row) {
            $hours = dsc_hours_on($conn, $doctorId, (string)$row['appointment_date']);
            $fits = false;
            if ($hours) {
                foreach (dsc_slot_windows($hours['start'], $hours['end']) as [$s, $e]) {
                    if ($row['start_time'] >= $s && $row['end_time'] <= $e) { $fits = true; break; }
                }
            }
            if (!$fits) $flag[] = $row;
        }
        if ($flag) {
            $ids = array_map(function ($r) { return (int)$r['appointment_id']; }, $flag);
            $conn->query('UPDATE appointments SET needs_reassignment = 1 WHERE appointment_id IN (' . implode(',', $ids) . ')');

            include_once __DIR__ . '/workflow_notifications.php';
            $doctorName = '';
            $dStmt = $conn->prepare('SELECT first_name, last_name FROM users WHERE user_id = ? LIMIT 1');
            if ($dStmt) {
                $dStmt->bind_param('i', $doctorId);
                $dStmt->execute();
                $dRow = $dStmt->get_result()->fetch_assoc();
                $dStmt->close();
                $doctorName = $dRow ? trim($dRow['first_name'] . ' ' . $dRow['last_name']) : '';
            }
            foreach ($flag as $r) {
                // Already flagged earlier: the patient was told then.
                if ((int)$r['needs_reassignment'] === 1 || !function_exists('wf_notify_appointment_needs_new_time')) continue;
                wf_notify_appointment_needs_new_time($conn, (int)$r['patient_id'], (string)$r['appointment_date'], (string)$r['start_time'], $doctorName);
            }
        }
        return count($flag);
    }
}
