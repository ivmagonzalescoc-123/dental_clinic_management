<?php
/**
 * Side-by-side comparison of two date ranges for the admin Clinic Reports
 * (admin/admin_modules/reports.php → "Compare" button, assets/api/reports/compare.php).
 *
 * Each report type is a list of sections; a section is a list of label => value. The
 * figures use the same definitions as the report page itself, so a comparison of the
 * period shown on the page gives the numbers shown on the page.
 */

include_once __DIR__ . '/report_trend_helpers.php';
include_once __DIR__ . '/dashboard_helpers.php';

if (!function_exists('report_compare_period')) {

    function report_compare_types(): array
    {
        return [
            'sales' => 'Sales Reports',
            'appointments' => 'Appointment Reports',
            'demographic' => 'Demographic Reports',
            'trend' => 'Trend & Predictive',
        ];
    }

    /**
     * Runs a query whose only placeholders are one "BETWEEN ? AND ?" date pair, plus the
     * doctor filter where the SQL contains {doctor} (an "AND a.user_id = ?" on alias a).
     */
    function report_compare_query(mysqli $conn, string $sql, string $start, string $end, int $doctorId = 0): array
    {
        $types = 'ss';
        $params = [$start, $end];
        if (strpos($sql, '{doctor}') !== false) {
            $sql = str_replace('{doctor}', $doctorId > 0 ? ' AND a.user_id = ?' : '', $sql);
            if ($doctorId > 0) {
                $types .= 'i';
                $params[] = $doctorId;
            }
        }
        $stmt = $conn->prepare($sql);
        if (!$stmt) {
            return [];
        }
        $stmt->bind_param($types, ...$params);
        $stmt->execute();
        $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();
        return $rows;
    }

    /** label => [value, format] from rows of (label, value). */
    function report_compare_pairs(array $rows, string $format, ?callable $labelFn = null): array
    {
        $out = [];
        foreach ($rows as $row) {
            $row = array_values($row);
            $label = trim((string)($row[0] ?? ''));
            if ($label === '') {
                $label = 'Not specified';
            }
            if ($labelFn) {
                $label = $labelFn($label);
            }
            $out[$label] = [$format === 'money' ? round((float)$row[1], 2) : (int)$row[1], $format];
        }
        return $out;
    }

    /**
     * One period's figures: section title => label => [value, format].
     * Formats: money, int, pct (a whole-number percentage; null when there is nothing to divide by).
     */
    function report_compare_period(mysqli $conn, string $type, string $start, string $end, int $doctorId = 0): array
    {
        $q = function (string $sql, int $doc = -1) use ($conn, $start, $end, $doctorId) {
            return report_compare_query($conn, $sql, $start, $end, $doc < 0 ? $doctorId : $doc);
        };
        $one = function (string $sql, int $doc = -1) use ($q) {
            $rows = $q($sql, $doc);
            return $rows ? array_values($rows[0]) : [];
        };

        $appts = $one("
            SELECT COUNT(*),
                   COALESCE(SUM(a.status IN ('completed','records_complete')), 0),
                   COALESCE(SUM(a.status = 'no_show'), 0),
                   COALESCE(SUM(a.status = 'cancelled'), 0),
                   COUNT(DISTINCT CASE WHEN a.status IN ('completed','records_complete','in_progress','arrived','checked_in') THEN a.patient_id END)
            FROM appointments a
            WHERE a.appointment_date BETWEEN ? AND ?{doctor}
        ");
        [$total, $completed, $noShows, $cancelled, $patientsSeen] = array_map('intval', $appts + [0, 0, 0, 0, 0]);
        $newPatients = (int)($one('SELECT COUNT(*) FROM patients WHERE DATE(created_at) BETWEEN ? AND ?')[0] ?? 0);

        if ($type === 'sales') {
            $bill = $one("
                SELECT COALESCE(SUM(b.total_amount), 0), COALESCE(SUM(b.amount_paid), 0), COALESCE(SUM(b.balance), 0), COUNT(*)
                FROM billing b
                INNER JOIN appointments a ON a.appointment_id = b.appointment_id
                WHERE a.appointment_date BETWEEN ? AND ?{doctor}
            ") + [0, 0, 0, 0];
            $billed = round((float)$bill[0], 2);
            $collected = round((float)$bill[1], 2);

            return [
                'Summary' => [
                    'Total Billed' => [$billed, 'money'],
                    'Collected' => [$collected, 'money'],
                    'Outstanding' => [round((float)$bill[2], 2), 'money'],
                    'Collection Rate' => [$billed > 0 ? (int)round($collected / $billed * 100) : null, 'pct'],
                    'Total Invoices' => [(int)$bill[3], 'int'],
                    'Avg Revenue / Patient' => [$patientsSeen > 0 ? round($collected / $patientsSeen, 2) : 0.0, 'money'],
                    'Avg Revenue / Visit' => [$completed > 0 ? round($collected / $completed, 2) : 0.0, 'money'],
                ],
                'Revenue by Service' => report_compare_pairs($q("
                    SELECT s.service_name, COALESCE(SUM(bli.amount), 0) AS revenue
                    FROM billing_line_items bli
                    INNER JOIN appointments a ON a.appointment_id = bli.appointment_id
                    INNER JOIN services s ON s.service_id = bli.service_id
                    WHERE a.appointment_date BETWEEN ? AND ?{doctor}
                    GROUP BY s.service_id, s.service_name
                    ORDER BY revenue DESC
                "), 'money'),
                'Payment Methods' => report_compare_pairs($q("
                    SELECT p.payment_method, COALESCE(SUM(p.amount), 0) AS total
                    FROM payments p
                    INNER JOIN billing b ON b.billing_id = p.billing_id
                    INNER JOIN appointments a ON a.appointment_id = b.appointment_id
                    WHERE a.appointment_date BETWEEN ? AND ?{doctor}
                    GROUP BY p.payment_method
                    ORDER BY total DESC
                "), 'money', fn($m) => ucwords(str_replace('_', ' ', $m))),
            ];
        }

        if ($type === 'appointments') {
            return [
                'Summary' => [
                    'Total Appointments' => [$total, 'int'],
                    'Completed Visits' => [$completed, 'int'],
                    'No-Shows' => [$noShows, 'int'],
                    'Cancelled' => [$cancelled, 'int'],
                    'Completion Rate' => [trend_rate($completed, $total), 'pct'],
                    'Patients Seen' => [$patientsSeen, 'int'],
                    'New Patients' => [$newPatients, 'int'],
                ],
                'Status Breakdown' => report_compare_pairs($q("
                    SELECT a.status, COUNT(*) AS total
                    FROM appointments a
                    WHERE a.appointment_date BETWEEN ? AND ?{doctor}
                    GROUP BY a.status
                    ORDER BY total DESC
                "), 'int', 'dashboard_format_status_label'),
                'Services Booked' => report_compare_pairs($q("
                    SELECT s.service_name, COUNT(ss.selected_service_id) AS bookings
                    FROM selected_services ss
                    INNER JOIN appointments a ON a.appointment_id = ss.appointment_id
                    INNER JOIN services s ON s.service_id = ss.service_id
                    WHERE a.appointment_date BETWEEN ? AND ?{doctor}
                    GROUP BY s.service_id, s.service_name
                    ORDER BY bookings DESC
                "), 'int'),
                'Completed Visits by Doctor' => report_compare_pairs($q("
                    SELECT CONCAT(u.first_name, ' ', u.last_name) AS doctor, COUNT(*) AS visits
                    FROM appointments a
                    INNER JOIN users u ON u.user_id = a.user_id
                    WHERE a.appointment_date BETWEEN ? AND ? AND a.status IN ('completed','records_complete'){doctor}
                    GROUP BY u.user_id, u.first_name, u.last_name
                    ORDER BY visits DESC
                "), 'int'),
            ];
        }

        if ($type === 'demographic') {
            $age = "TIMESTAMPDIFF(YEAR, p.date_of_birth, CURDATE())";
            return [
                'Summary' => [
                    'Patients Seen' => [$patientsSeen, 'int'],
                    'New Patients' => [$newPatients, 'int'],
                ],
                'Age Distribution' => report_compare_pairs($q("
                    SELECT CASE
                               WHEN $age < 13 THEN '0-12'
                               WHEN $age BETWEEN 13 AND 17 THEN '13-17'
                               WHEN $age BETWEEN 18 AND 25 THEN '18-25'
                               WHEN $age BETWEEN 26 AND 35 THEN '26-35'
                               WHEN $age BETWEEN 36 AND 50 THEN '36-50'
                               WHEN $age BETWEEN 51 AND 65 THEN '51-65'
                               ELSE '65+'
                           END AS age_group,
                           COUNT(DISTINCT p.patient_id) AS patient_count
                    FROM patients p
                    INNER JOIN appointments a ON a.patient_id = p.patient_id
                    WHERE a.appointment_date BETWEEN ? AND ? AND p.date_of_birth IS NOT NULL{doctor}
                    GROUP BY age_group
                    ORDER BY MIN($age)
                "), 'int'),
                'Gender Distribution' => report_compare_pairs($q("
                    SELECT COALESCE(p.gender, 'Not Specified') AS gender, COUNT(DISTINCT p.patient_id) AS patient_count
                    FROM patients p
                    INNER JOIN appointments a ON a.patient_id = p.patient_id
                    WHERE a.appointment_date BETWEEN ? AND ?{doctor}
                    GROUP BY p.gender
                    ORDER BY patient_count DESC
                "), 'int'),
                // Like the report page, booking days and hours cover every doctor.
                'Bookings by Day' => report_compare_pairs($q("
                    SELECT DAYNAME(a.appointment_date) AS day_name, COUNT(*) AS appointments
                    FROM appointments a
                    WHERE a.appointment_date BETWEEN ? AND ?
                    GROUP BY DAYOFWEEK(a.appointment_date), day_name
                    ORDER BY DAYOFWEEK(a.appointment_date)
                "), 'int'),
                'Bookings by Hour' => report_compare_pairs($q("
                    SELECT HOUR(ts.start_time) AS hour_of_day, COUNT(*) AS appointments
                    FROM appointments a
                    INNER JOIN timeslot ts ON ts.timeslot_id = a.timeslot_id
                    WHERE a.appointment_date BETWEEN ? AND ?
                    GROUP BY hour_of_day
                    ORDER BY hour_of_day
                "), 'int', fn($h) => date('g A', mktime((int)$h, 0))),
            ];
        }

        // Trend: appointment outcomes for the whole clinic, as on the Trend & Predictive tab.
        $visitList = trend_sql_list(trend_visit_statuses());
        $out = $one("
            SELECT COALESCE(SUM(a.status <> 'rescheduled'), 0),
                   COALESCE(SUM(a.status IN ($visitList)), 0),
                   COALESCE(SUM(a.status = 'cancelled'), 0),
                   COALESCE(SUM(a.status = 'no_show'), 0),
                   COALESCE(SUM(a.status = 'rescheduled'), 0)
            FROM appointments a
            WHERE a.appointment_date BETWEEN ? AND ?
        ");
        [$booked, $visits, $tCancelled, $tNoShow, $rescheduled] = array_map('intval', $out + [0, 0, 0, 0, 0]);

        // "New" = the patient's first real appointment falls inside the period.
        $pat = $one("
            SELECT COUNT(DISTINCT CASE WHEN f.first_date = a.appointment_date THEN a.patient_id END),
                   COUNT(DISTINCT a.patient_id)
            FROM appointments a
            INNER JOIN (
                SELECT patient_id, MIN(appointment_date) AS first_date
                FROM appointments
                WHERE status NOT IN ('cancelled', 'rescheduled')
                GROUP BY patient_id
            ) f ON f.patient_id = a.patient_id
            WHERE a.status NOT IN ('cancelled', 'rescheduled') AND a.appointment_date BETWEEN ? AND ?
        ") + [0, 0];
        $newP = (int)$pat[0];
        $returningP = max(0, (int)$pat[1] - $newP);
        $resolved = $visits + $tCancelled + $tNoShow;

        return [
            'Summary' => [
                'Appointments Booked' => [$booked, 'int'],
                'Visits' => [$visits, 'int'],
                'Cancelled' => [$tCancelled, 'int'],
                'No-Shows' => [$tNoShow, 'int'],
                'Rescheduled' => [$rescheduled, 'int'],
                'Completion Rate' => [trend_rate($visits, $resolved), 'pct'],
                'No-Show Rate' => [trend_rate($tNoShow, $resolved), 'pct'],
                'Cancellation Rate' => [trend_rate($tCancelled, $resolved), 'pct'],
                'New Patients' => [$newP, 'int'],
                'Returning Patients' => [$returningP, 'int'],
                'Returning Patient Rate' => [trend_rate($returningP, $newP + $returningP), 'pct'],
            ],
            'Service Demand' => report_compare_pairs($q("
                SELECT s.service_name, COUNT(*) AS bookings
                FROM selected_services ss
                INNER JOIN appointments a ON a.appointment_id = ss.appointment_id
                INNER JOIN services s ON s.service_id = ss.service_id
                WHERE a.status <> 'rescheduled' AND a.appointment_date BETWEEN ? AND ?
                GROUP BY s.service_id, s.service_name
                ORDER BY bookings DESC
            "), 'int'),
        ];
    }

    /**
     * Both periods side by side. Change = Period B − Period A (percentage points for
     * rates); % change is relative to Period A and null when A is zero or it is a rate.
     *
     * @return array<int, array{title:string, rows:array<int, array{label:string, format:string, a:mixed, b:mixed, change:mixed, pct_change:mixed}>}>
     */
    function report_compare_build(mysqli $conn, string $type, array $a, array $b, int $doctorId = 0): array
    {
        $periodA = report_compare_period($conn, $type, $a[0], $a[1], $doctorId);
        $periodB = report_compare_period($conn, $type, $b[0], $b[1], $doctorId);

        $sections = [];
        foreach ($periodA as $title => $rowsA) {
            $rowsB = $periodB[$title] ?? [];
            $rows = [];
            foreach (array_keys($rowsA + $rowsB) as $label) {
                $format = ($rowsA[$label] ?? $rowsB[$label])[1];
                // A service, status, etc. that only appears in one period counts as 0 in the other.
                $missing = $format === 'pct' ? null : 0;
                $va = array_key_exists($label, $rowsA) ? $rowsA[$label][0] : $missing;
                $vb = array_key_exists($label, $rowsB) ? $rowsB[$label][0] : $missing;
                $change = ($va === null || $vb === null) ? null : round($vb - $va, 2);
                $pct = ($change === null || $format === 'pct' || (float)$va == 0.0) ? null : round($change / $va * 100, 1);
                $rows[] = ['label' => (string)$label, 'format' => $format, 'a' => $va, 'b' => $vb, 'change' => $change, 'pct_change' => $pct];
            }
            $sections[] = ['title' => $title, 'rows' => $rows];
        }
        return $sections;
    }
}
