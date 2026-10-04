<?php
require_once __DIR__ . '/../../config/asset_helpers.php';
include dirname(dirname(__DIR__)) . '/config/database.php';
include_once dirname(dirname(__DIR__)) . '/config/dashboard_helpers.php';
include_once dirname(dirname(__DIR__)) . '/config/billing_helpers.php';
include_once dirname(dirname(__DIR__)) . '/config/report_trend_helpers.php';

billing_ensure_tables($conn);

$range = $_GET['range'] ?? '30d';
$startDate = $_GET['start_date'] ?? '';
$endDate = $_GET['end_date'] ?? date('Y-m-d');
$doctorId = isset($_GET['doctor_id']) && $_GET['doctor_id'] !== '' ? (int)$_GET['doctor_id'] : 0;
$reportType = $_GET['report_type'] ?? 'sales';

$today = date('Y-m-d');
if ($range === '7d') {
    $startDate = date('Y-m-d', strtotime('-6 days'));
    $endDate = $today;
} elseif ($range === '30d') {
    $startDate = date('Y-m-d', strtotime('-29 days'));
    $endDate = $today;
} elseif ($range === 'month') {
    $startDate = date('Y-m-01');
    $endDate = $today;
} else {
    $range = 'custom';
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $startDate)) {
        $startDate = date('Y-m-d', strtotime('-29 days'));
    }
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $endDate)) {
        $endDate = $today;
    }
    if (strtotime($startDate) > strtotime($endDate)) {
        [$startDate, $endDate] = [$endDate, $startDate];
    }
}

$doctors = [];
$doctorRes = $conn->query("
    SELECT u.user_id, u.first_name, u.last_name
    FROM users u
    INNER JOIN role r ON r.role_id = u.role_id 
    WHERE r.role_name = 'doctor' 
    ORDER BY u.first_name ASC
");
if ($doctorRes) {
    while ($row = $doctorRes->fetch_assoc()) {
        $doctors[] = $row;
    }
}

function reports_count_appts(mysqli $conn, string $sql, string $types, array $params): int
{
    return dashboard_scalar_int($conn, $sql, $types, $params);
}

function reports_format_status(string $status): string
{
    return dashboard_format_status_label($status);
}

$doctorClause = $doctorId > 0 ? ' AND a.user_id = ?' : '';
$baseTypes = 'ss';
$baseParams = [$startDate, $endDate];
if ($doctorId > 0) {
    $baseTypes .= 'i';
    $baseParams[] = $doctorId;
}

$totalAppointments = reports_count_appts(
    $conn,
    "SELECT COUNT(*) FROM appointments a WHERE a.appointment_date BETWEEN ? AND ?{$doctorClause}",
    $baseTypes,
    $baseParams
);

$completedVisits = reports_count_appts(
    $conn,
    "SELECT COUNT(*) FROM appointments a WHERE a.appointment_date BETWEEN ? AND ? AND a.status IN ('completed','records_complete'){$doctorClause}",
    $baseTypes,
    $baseParams
);

$noShows = reports_count_appts(
    $conn,
    "SELECT COUNT(*) FROM appointments a WHERE a.appointment_date BETWEEN ? AND ? AND a.status = 'no_show'{$doctorClause}",
    $baseTypes,
    $baseParams
);

$cancelled = reports_count_appts(
    $conn,
    "SELECT COUNT(*) FROM appointments a WHERE a.appointment_date BETWEEN ? AND ? AND a.status = 'cancelled'{$doctorClause}",
    $baseTypes,
    $baseParams
);

$newPatients = dashboard_scalar_int(
    $conn,
    'SELECT COUNT(*) FROM patients WHERE DATE(created_at) BETWEEN ? AND ?',
    'ss',
    [$startDate, $endDate]
);

$patientsSeen = reports_count_appts(
    $conn,
    "SELECT COUNT(DISTINCT a.patient_id) FROM appointments a WHERE a.appointment_date BETWEEN ? AND ? AND a.status IN ('completed','records_complete','in_progress','arrived','checked_in'){$doctorClause}",
    $baseTypes,
    $baseParams
);

$completionRate = $totalAppointments > 0 ? round(($completedVisits / $totalAppointments) * 100) : 0;
$periodLabel = date('M d, Y', strtotime($startDate)) . ' – ' . date('M d, Y', strtotime($endDate));

$totalBilled = 0.0;
$totalCollected = 0.0;
$totalOutstanding = 0.0;
$billingCount = 0;

if (table_exists($conn, 'billing')) {
    $billSql = "
        SELECT COALESCE(SUM(b.total_amount), 0) AS billed,
               COALESCE(SUM(b.amount_paid), 0) AS collected,
               COALESCE(SUM(b.balance), 0) AS outstanding,
               COUNT(*) AS invoice_count
        FROM billing b
        INNER JOIN appointments a ON a.appointment_id = b.appointment_id
        WHERE a.appointment_date BETWEEN ? AND ?{$doctorClause}
    ";
    $billStmt = $conn->prepare($billSql);
    if ($billStmt) {
        $bind = [$baseTypes];
        foreach ($baseParams as $k => $v) {
            $bind[] = &$baseParams[$k];
        }
        call_user_func_array([$billStmt, 'bind_param'], $bind);
        $billStmt->execute();
        $billRow = $billStmt->get_result()->fetch_assoc();
        $billStmt->close();
        if ($billRow) {
            $totalBilled = (float)($billRow['billed'] ?? 0);
            $totalCollected = (float)($billRow['collected'] ?? 0);
            $totalOutstanding = (float)($billRow['outstanding'] ?? 0);
            $billingCount = (int)($billRow['invoice_count'] ?? 0);
        }
    }
}

$serviceRevenue = [];
if (table_exists($conn, 'billing_line_items')) {
    $svcRevSql = "
        SELECT s.service_name, 
               COUNT(DISTINCT a.appointment_id) AS bookings,
               COALESCE(SUM(bli.amount), 0) AS revenue
        FROM billing_line_items bli
        INNER JOIN appointments a ON a.appointment_id = bli.appointment_id
        INNER JOIN services s ON s.service_id = bli.service_id
        WHERE a.appointment_date BETWEEN ? AND ?{$doctorClause}
        GROUP BY s.service_id, s.service_name
        ORDER BY revenue DESC
        LIMIT 10
    ";
    $svcRevStmt = $conn->prepare($svcRevSql);
    if ($svcRevStmt) {
        $bind = [$baseTypes];
        foreach ($baseParams as $k => $v) {
            $bind[] = &$baseParams[$k];
        }
        call_user_func_array([$svcRevStmt, 'bind_param'], $bind);
        $svcRevStmt->execute();
        $res = $svcRevStmt->get_result();
        while ($row = $res->fetch_assoc()) {
            $serviceRevenue[] = $row;
        }
        $svcRevStmt->close();
    }
}

$paymentMethods = [];
if (table_exists($conn, 'payments')) {
    $paySql = "
        SELECT p.payment_method, 
               COUNT(*) AS count,
               COALESCE(SUM(p.amount), 0) AS total
        FROM payments p
        INNER JOIN billing b ON b.billing_id = p.billing_id
        INNER JOIN appointments a ON a.appointment_id = b.appointment_id
        WHERE a.appointment_date BETWEEN ? AND ?{$doctorClause}
        GROUP BY p.payment_method
        ORDER BY total DESC
    ";
    $payStmt = $conn->prepare($paySql);
    if ($payStmt) {
        $bind = [$baseTypes];
        foreach ($baseParams as $k => $v) {
            $bind[] = &$baseParams[$k];
        }
        call_user_func_array([$payStmt, 'bind_param'], $bind);
        $payStmt->execute();
        $res = $payStmt->get_result();
        while ($row = $res->fetch_assoc()) {
            $paymentMethods[] = $row;
        }
        $payStmt->close();
    }
}

$dailyRevenue = [];
$dailyRevLabels = [];
$dailyRevValues = [];
if (table_exists($conn, 'billing')) {
    $dailyRevSql = "
        SELECT a.appointment_date AS dt,
               COALESCE(SUM(b.total_amount), 0) AS revenue,
               COALESCE(SUM(b.amount_paid), 0) AS collected
        FROM appointments a
        LEFT JOIN billing b ON b.appointment_id = a.appointment_id
        WHERE a.appointment_date BETWEEN ? AND ?{$doctorClause}
        GROUP BY a.appointment_date
        ORDER BY a.appointment_date ASC
    ";
    $dailyRevStmt = $conn->prepare($dailyRevSql);
    if ($dailyRevStmt) {
        $bind = [$baseTypes];
        foreach ($baseParams as $k => $v) {
            $bind[] = &$baseParams[$k];
        }
        call_user_func_array([$dailyRevStmt, 'bind_param'], $bind);
        $dailyRevStmt->execute();
        $res = $dailyRevStmt->get_result();
        while ($row = $res->fetch_assoc()) {
            $dailyRevLabels[] = date('M d', strtotime($row['dt']));
            $dailyRevValues[] = (float)$row['collected'];
        }
        $dailyRevStmt->close();
    }
}

$ageGroups = [];
$ageSql = "
    SELECT 
        CASE 
            WHEN TIMESTAMPDIFF(YEAR, p.date_of_birth, CURDATE()) < 13 THEN '0-12'
            WHEN TIMESTAMPDIFF(YEAR, p.date_of_birth, CURDATE()) BETWEEN 13 AND 17 THEN '13-17'
            WHEN TIMESTAMPDIFF(YEAR, p.date_of_birth, CURDATE()) BETWEEN 18 AND 25 THEN '18-25'
            WHEN TIMESTAMPDIFF(YEAR, p.date_of_birth, CURDATE()) BETWEEN 26 AND 35 THEN '26-35'
            WHEN TIMESTAMPDIFF(YEAR, p.date_of_birth, CURDATE()) BETWEEN 36 AND 50 THEN '36-50'
            WHEN TIMESTAMPDIFF(YEAR, p.date_of_birth, CURDATE()) BETWEEN 51 AND 65 THEN '51-65'
            ELSE '65+'
        END AS age_group,
        COUNT(DISTINCT p.patient_id) AS patient_count
    FROM patients p
    INNER JOIN appointments a ON a.patient_id = p.patient_id
    WHERE a.appointment_date BETWEEN ? AND ?
      AND p.date_of_birth IS NOT NULL
      {$doctorClause}
    GROUP BY age_group
    ORDER BY MIN(TIMESTAMPDIFF(YEAR, p.date_of_birth, CURDATE()))
";
$ageStmt = $conn->prepare($ageSql);
if ($ageStmt) {
    $bind = [$baseTypes];
    foreach ($baseParams as $k => $v) {
        $bind[] = &$baseParams[$k];
    }
    call_user_func_array([$ageStmt, 'bind_param'], $bind);
    $ageStmt->execute();
    $res = $ageStmt->get_result();
    while ($row = $res->fetch_assoc()) {
        $ageGroups[] = $row;
    }
    $ageStmt->close();
}

$genderDist = [];
$genderSql = "
    SELECT COALESCE(p.gender, 'Not Specified') AS gender,
           COUNT(DISTINCT p.patient_id) AS patient_count
    FROM patients p
    INNER JOIN appointments a ON a.patient_id = p.patient_id
    WHERE a.appointment_date BETWEEN ? AND ?{$doctorClause}
    GROUP BY p.gender
    ORDER BY patient_count DESC
";
$genderStmt = $conn->prepare($genderSql);
if ($genderStmt) {
    $bind = [$baseTypes];
    foreach ($baseParams as $k => $v) {
        $bind[] = &$baseParams[$k];
    }
    call_user_func_array([$genderStmt, 'bind_param'], $bind);
    $genderStmt->execute();
    $res = $genderStmt->get_result();
    while ($row = $res->fetch_assoc()) {
        $genderDist[] = $row;
    }
    $genderStmt->close();
}

$topServices = [];
$svcSql = "
    SELECT s.service_name, COUNT(ss.selected_service_id) AS bookings
    FROM selected_services ss
    INNER JOIN appointments a ON a.appointment_id = ss.appointment_id
    INNER JOIN services s ON s.service_id = ss.service_id
    WHERE a.appointment_date BETWEEN ? AND ?{$doctorClause}
    GROUP BY s.service_id, s.service_name
    ORDER BY bookings DESC
    LIMIT 8
";
$svcStmt = $conn->prepare($svcSql);
if ($svcStmt) {
    $bind = [$baseTypes];
    foreach ($baseParams as $k => $v) {
        $bind[] = &$baseParams[$k];
    }
    call_user_func_array([$svcStmt, 'bind_param'], $bind);
    $svcStmt->execute();
    $res = $svcStmt->get_result();
    while ($row = $res->fetch_assoc()) {
        $topServices[] = $row;
    }
    $svcStmt->close();
}

// ---- Trend & Predictive (appointments only; loaded only for that tab) ----
// Forecasts use complete months; the current month is shown as "pace so far".
$trend = [
    'months' => [], 'labels' => [], 'booked' => [], 'visits' => [], 'cancelled' => [], 'no_show' => [], 'rescheduled' => [],
    'new_patients' => [], 'returning_patients' => [],
    'forecast' => null, 'next_label' => '', 'next_short' => '', 'next_booked' => 0, 'next_clinic_days' => 0,
    'month_so_far' => 0, 'month_booked_ahead' => 0, 'month_label' => '', 'last_month_total' => 0,
    'rates' => ['completion' => null, 'no_show' => null, 'cancellation' => null, 'returning' => null],
    'services' => [], 'service_series' => [], 'service_labels' => [],
    'peak_days' => [], 'peak_hours' => [],
];
if ($reportType === 'trend') {
    $today = new DateTimeImmutable('today');
    $trendKeys = trend_complete_month_keys(12, $today);
    $windowStart = $trendKeys[0] . '-01';
    $thisMonthStart = $today->modify('first day of this month')->format('Y-m-d');
    $nextMonth = $today->modify('first day of next month');
    $nextMonthStart = $nextMonth->format('Y-m-d');
    $nextMonthEnd = $nextMonth->modify('last day of this month')->format('Y-m-d');
    $visitList = trend_sql_list(trend_visit_statuses());
    $openList = trend_sql_list(trend_open_statuses());

    // Monthly outcomes for the last 12 complete months. "Booked" leaves out rescheduled
    // rows (their replacement appointment is counted instead).
    $byMonth = [];
    $monthlyStmt = $conn->prepare("
        SELECT DATE_FORMAT(appointment_date, '%Y-%m') AS month_key,
               SUM(status <> 'rescheduled') AS booked,
               SUM(status IN ($visitList)) AS visits,
               SUM(status = 'cancelled') AS cancelled,
               SUM(status = 'no_show') AS no_show,
               SUM(status = 'rescheduled') AS rescheduled
        FROM appointments
        WHERE appointment_date >= ? AND appointment_date < ?
        GROUP BY month_key
    ");
    if ($monthlyStmt) {
        $monthlyStmt->bind_param('ss', $windowStart, $thisMonthStart);
        $monthlyStmt->execute();
        $res = $monthlyStmt->get_result();
        while ($row = $res->fetch_assoc()) {
            $byMonth[$row['month_key']] = $row;
        }
        $monthlyStmt->close();
    }
    foreach ($trendKeys as $k) {
        $trend['months'][] = $k;
        $trend['labels'][] = date('M Y', strtotime($k . '-01'));
        foreach (['booked', 'visits', 'cancelled', 'no_show', 'rescheduled'] as $f) {
            $trend[$f][] = (int)($byMonth[$k][$f] ?? 0);
        }
    }
    $trend['forecast'] = trend_forecast($trend['booked']);
    $trend['last_month_total'] = (int)end($trend['booked']);

    // New vs returning patients per month: "new" = the month of their first real visit.
    $patientMonths = [];
    $patientStmt = $conn->prepare("
        SELECT DATE_FORMAT(a.appointment_date, '%Y-%m') AS month_key,
               COUNT(DISTINCT CASE WHEN f.first_month = DATE_FORMAT(a.appointment_date, '%Y-%m') THEN a.patient_id END) AS new_patients,
               COUNT(DISTINCT CASE WHEN f.first_month < DATE_FORMAT(a.appointment_date, '%Y-%m') THEN a.patient_id END) AS returning_patients
        FROM appointments a
        INNER JOIN (
            SELECT patient_id, DATE_FORMAT(MIN(appointment_date), '%Y-%m') AS first_month
            FROM appointments
            WHERE status NOT IN ('cancelled', 'rescheduled')
            GROUP BY patient_id
        ) f ON f.patient_id = a.patient_id
        WHERE a.status NOT IN ('cancelled', 'rescheduled')
          AND a.appointment_date >= ? AND a.appointment_date < ?
        GROUP BY month_key
    ");
    if ($patientStmt) {
        $patientStmt->bind_param('ss', $windowStart, $thisMonthStart);
        $patientStmt->execute();
        $res = $patientStmt->get_result();
        while ($row = $res->fetch_assoc()) {
            $patientMonths[$row['month_key']] = $row;
        }
        $patientStmt->close();
    }
    foreach ($trendKeys as $k) {
        $trend['new_patients'][] = (int)($patientMonths[$k]['new_patients'] ?? 0);
        $trend['returning_patients'][] = (int)($patientMonths[$k]['returning_patients'] ?? 0);
    }

    // This month so far + what's already on the books (this month and next).
    $paceStmt = $conn->prepare("
        SELECT
            SUM(appointment_date >= ? AND appointment_date <= CURDATE() AND status <> 'rescheduled') AS so_far,
            SUM(appointment_date > CURDATE() AND appointment_date < ? AND status IN ($openList)) AS ahead,
            SUM(appointment_date >= ? AND appointment_date <= ? AND status IN ($openList)) AS next_booked
        FROM appointments
        WHERE appointment_date >= ? AND appointment_date <= ?
    ");
    if ($paceStmt) {
        $paceStmt->bind_param('ssssss', $thisMonthStart, $nextMonthStart, $nextMonthStart, $nextMonthEnd, $thisMonthStart, $nextMonthEnd);
        $paceStmt->execute();
        $pace = $paceStmt->get_result()->fetch_assoc() ?: [];
        $paceStmt->close();
        $trend['month_so_far'] = (int)($pace['so_far'] ?? 0);
        $trend['month_booked_ahead'] = (int)($pace['ahead'] ?? 0);
        $trend['next_booked'] = (int)($pace['next_booked'] ?? 0);
    }
    $trend['month_label'] = $today->format('F Y');
    $trend['next_label'] = $nextMonth->format('F Y');
    $trend['next_short'] = $nextMonth->format('M Y');
    $trend['next_clinic_days'] = trend_clinic_days_in_month($nextMonth);
    // Never forecast below what's already booked for next month.
    if ($trend['forecast'] && $trend['next_booked'] > $trend['forecast']['value']) {
        $trend['forecast']['value'] = $trend['next_booked'];
        $trend['forecast']['low'] = max($trend['forecast']['low'], $trend['next_booked']);
        $trend['forecast']['high'] = max($trend['forecast']['high'], $trend['next_booked']);
    }

    // Outcome rates over the last 3 complete months.
    $recentVisits = array_sum(array_slice($trend['visits'], -3));
    $recentCancelled = array_sum(array_slice($trend['cancelled'], -3));
    $recentNoShow = array_sum(array_slice($trend['no_show'], -3));
    $resolved = $recentVisits + $recentCancelled + $recentNoShow;
    $trend['rates']['completion'] = trend_rate($recentVisits, $resolved);
    $trend['rates']['no_show'] = trend_rate($recentNoShow, $resolved);
    $trend['rates']['cancellation'] = trend_rate($recentCancelled, $resolved);
    $recentNew = array_sum(array_slice($trend['new_patients'], -3));
    $recentReturning = array_sum(array_slice($trend['returning_patients'], -3));
    $trend['rates']['returning'] = trend_rate($recentReturning, $recentNew + $recentReturning);

    // Service demand: bookings per service over the last 6 complete months, plus what's
    // already booked for next month.
    $svcKeys = array_slice($trendKeys, -6);
    $svcStart = $svcKeys[0] . '-01';
    $svcMonths = [];
    $svcNames = [];
    $svcStmt = $conn->prepare("
        SELECT s.service_id, s.service_name,
               DATE_FORMAT(a.appointment_date, '%Y-%m') AS month_key,
               COUNT(*) AS bookings
        FROM selected_services ss
        INNER JOIN appointments a ON a.appointment_id = ss.appointment_id
        INNER JOIN services s ON s.service_id = ss.service_id
        WHERE a.status <> 'rescheduled' AND a.appointment_date >= ? AND a.appointment_date < ?
        GROUP BY s.service_id, s.service_name, month_key
    ");
    if ($svcStmt) {
        $svcStmt->bind_param('ss', $svcStart, $thisMonthStart);
        $svcStmt->execute();
        $res = $svcStmt->get_result();
        while ($row = $res->fetch_assoc()) {
            $sid = (int)$row['service_id'];
            $svcNames[$sid] = (string)$row['service_name'];
            $svcMonths[$sid][$row['month_key']] = (int)$row['bookings'];
        }
        $svcStmt->close();
    }
    $svcBookedNext = [];
    $svcNextStmt = $conn->prepare("
        SELECT ss.service_id, COUNT(*) AS bookings
        FROM selected_services ss
        INNER JOIN appointments a ON a.appointment_id = ss.appointment_id
        WHERE a.status IN ($openList) AND a.appointment_date >= ? AND a.appointment_date <= ?
        GROUP BY ss.service_id
    ");
    if ($svcNextStmt) {
        $svcNextStmt->bind_param('ss', $nextMonthStart, $nextMonthEnd);
        $svcNextStmt->execute();
        $res = $svcNextStmt->get_result();
        while ($row = $res->fetch_assoc()) {
            $svcBookedNext[(int)$row['service_id']] = (int)$row['bookings'];
        }
        $svcNextStmt->close();
    }
    foreach ($svcNames as $sid => $name) {
        $series = trend_fill($svcKeys, $svcMonths[$sid] ?? []);
        $total = array_sum($series);
        if ($total < 2) continue; // too few bookings to say anything
        $f = trend_forecast($series);
        $booked = $svcBookedNext[$sid] ?? 0;
        $trend['services'][] = [
            'name' => $name,
            'series' => $series,
            'total' => $total,
            'last' => (int)end($series),
            'forecast' => max($f ? $f['value'] : 0, $booked),
            'booked' => $booked,
            'direction' => trend_direction($series),
        ];
    }
    usort($trend['services'], fn($a, $b) => [$b['forecast'], $b['total']] <=> [$a['forecast'], $a['total']]);
    // Chart: the 4 busiest services (colours stay distinct and readable).
    foreach (array_slice($trend['services'], 0, 4) as $svc) {
        $trend['service_series'][] = ['name' => $svc['name'], 'data' => $svc['series']];
    }
    $trend['service_labels'] = array_map(fn($k) => date('M Y', strtotime($k . '-01')), $svcKeys);

    // Busiest days / hours over the last 3 complete months of real visits.
    $peakStart = $trendKeys[count($trendKeys) - 3] . '-01';
    $dayStmt = $conn->prepare("
        SELECT DAYNAME(appointment_date) AS day_name, COUNT(*) AS visits
        FROM appointments
        WHERE status IN ($visitList) AND appointment_date >= ? AND appointment_date < ?
        GROUP BY DAYOFWEEK(appointment_date), day_name
        ORDER BY visits DESC
    ");
    if ($dayStmt) {
        $dayStmt->bind_param('ss', $peakStart, $thisMonthStart);
        $dayStmt->execute();
        $trend['peak_days'] = $dayStmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $dayStmt->close();
    }
    $hourStmt = $conn->prepare("
        SELECT HOUR(ts.start_time) AS hour_of_day, COUNT(*) AS visits
        FROM appointments a
        INNER JOIN timeslot ts ON ts.timeslot_id = a.timeslot_id
        WHERE a.status IN ($visitList) AND a.appointment_date >= ? AND a.appointment_date < ?
        GROUP BY hour_of_day
        ORDER BY visits DESC
        LIMIT 3
    ");
    if ($hourStmt) {
        $hourStmt->bind_param('ss', $peakStart, $thisMonthStart);
        $hourStmt->execute();
        $trend['peak_hours'] = $hourStmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $hourStmt->close();
    }
}

$peakDays = [];
$peakSql = "
    SELECT DAYNAME(a.appointment_date) AS day_name,
           COUNT(*) AS appointments
    FROM appointments a
    WHERE a.appointment_date BETWEEN ? AND ?
    GROUP BY DAYOFWEEK(a.appointment_date), day_name
    ORDER BY appointments DESC
";
$peakStmt = $conn->prepare($peakSql);
if ($peakStmt) {
    $peakStmt->bind_param('ss', $startDate, $endDate);
    $peakStmt->execute();
    $res = $peakStmt->get_result();
    while ($row = $res->fetch_assoc()) {
        $peakDays[] = $row;
    }
    $peakStmt->close();
}

$peakHours = [];
$peakHourSql = "
    SELECT HOUR(ts.start_time) AS hour_of_day,
           COUNT(*) AS appointments
    FROM appointments a
    INNER JOIN timeslot ts ON ts.timeslot_id = a.timeslot_id
    WHERE a.appointment_date BETWEEN ? AND ?
    GROUP BY hour_of_day
    ORDER BY appointments DESC
    LIMIT 5
";
$peakHourStmt = $conn->prepare($peakHourSql);
if ($peakHourStmt) {
    $peakHourStmt->bind_param('ss', $startDate, $endDate);
    $peakHourStmt->execute();
    $res = $peakHourStmt->get_result();
    while ($row = $res->fetch_assoc()) {
        $peakHours[] = $row;
    }
    $peakHourStmt->close();
}

$recentVisits = [];
$recentSql = "
    SELECT a.appointment_date, a.status,
           p.first_name AS patient_first, p.last_name AS patient_last,
           d.first_name AS doctor_first, d.last_name AS doctor_last,
           (SELECT GROUP_CONCAT(s.service_name ORDER BY s.service_name SEPARATOR ', ')
            FROM selected_services ss
            INNER JOIN services s ON s.service_id = ss.service_id
            WHERE ss.appointment_id = a.appointment_id
            LIMIT 3) AS services
    FROM appointments a
    LEFT JOIN patients p ON p.patient_id = a.patient_id
    LEFT JOIN users d ON d.user_id = a.user_id
    WHERE a.appointment_date BETWEEN ? AND ?
      AND a.status IN ('completed','records_complete'){$doctorClause}
    ORDER BY a.appointment_date DESC, a.appointment_id DESC
    LIMIT 12
";
$recentStmt = $conn->prepare($recentSql);
if ($recentStmt) {
    $bind = [$baseTypes];
    foreach ($baseParams as $k => $v) {
        $bind[] = &$baseParams[$k];
    }
    call_user_func_array([$recentStmt, 'bind_param'], $bind);
    $recentStmt->execute();
    $res = $recentStmt->get_result();
    while ($row = $res->fetch_assoc()) {
        $recentVisits[] = $row;
    }
    $recentStmt->close();
}

$exportQuery = http_build_query([
    'range' => $range,
    'start_date' => $startDate,
    'end_date' => $endDate,
    'doctor_id' => $doctorId > 0 ? $doctorId : '',
]);
$birExportUrl = '../assets/api/reports/export_bir.php?' . $exportQuery;

$reportRangeNav = [
    '7d' => 'Last 7 days',
    '30d' => 'Last 30 days',
    'month' => 'This month',
    'custom' => 'Custom dates',
];

$reportTypes = [
    'sales' => ['icon' => 'bi-currency-dollar', 'label' => 'Sales Reports'],
    'appointments' => ['icon' => 'bi-calendar-check', 'label' => 'Appointment Reports'],
    'demographic' => ['icon' => 'bi-people', 'label' => 'Demographic Reports'],
    'trend' => ['icon' => 'bi-graph-up', 'label' => 'Trend & Predictive'],
];

$chartServiceLabels = [];
$chartServiceValues = [];
foreach (array_slice($topServices, 0, 5) as $row) {
    $chartServiceLabels[] = (string)$row['service_name'];
    $chartServiceValues[] = (int)$row['bookings'];
}

$chartStatusLabels = [];
$chartStatusValues = [];
$statusBreakdown = [];
$statusSql = "
    SELECT a.status, COUNT(*) AS total
    FROM appointments a
    WHERE a.appointment_date BETWEEN ? AND ?{$doctorClause}
    GROUP BY a.status
    ORDER BY total DESC
";
$statusStmt = $conn->prepare($statusSql);
if ($statusStmt) {
    $bind = [$baseTypes];
    foreach ($baseParams as $k => $v) {
        $bind[] = &$baseParams[$k];
    }
    call_user_func_array([$statusStmt, 'bind_param'], $bind);
    $statusStmt->execute();
    $res = $statusStmt->get_result();
    while ($row = $res->fetch_assoc()) {
        $statusBreakdown[] = $row;
    }
    $statusStmt->close();
}
foreach ($statusBreakdown as $row) {
    $chartStatusLabels[] = reports_format_status((string)$row['status']);
    $chartStatusValues[] = (int)$row['total'];
}

$dailyChartLabels = [];
$dailyBookedValues = [];
$dailyCompletedValues = [];
$dailyMap = [];
$dayStart = strtotime($startDate);
$dayEnd = strtotime($endDate);
if ($dayStart && $dayEnd && $dayStart <= $dayEnd) {
    for ($d = $dayStart; $d <= $dayEnd; $d = strtotime('+1 day', $d)) {
        $key = date('Y-m-d', $d);
        $dailyChartLabels[] = date('M d', $d);
        $dailyBookedValues[] = 0;
        $dailyCompletedValues[] = 0;
        $dailyMap[$key] = count($dailyChartLabels) - 1;
    }
}
$dailySql = "
    SELECT a.appointment_date AS dt,
           COUNT(*) AS total,
           SUM(CASE WHEN a.status IN ('completed','records_complete') THEN 1 ELSE 0 END) AS completed
    FROM appointments a
    WHERE a.appointment_date BETWEEN ? AND ?{$doctorClause}
    GROUP BY a.appointment_date
    ORDER BY a.appointment_date ASC
";
$dailyStmt = $conn->prepare($dailySql);
if ($dailyStmt) {
    $bind = [$baseTypes];
    foreach ($baseParams as $k => $v) {
        $bind[] = &$baseParams[$k];
    }
    call_user_func_array([$dailyStmt, 'bind_param'], $bind);
    $dailyStmt->execute();
    $dailyRes = $dailyStmt->get_result();
    while ($row = $dailyRes->fetch_assoc()) {
        $dt = (string)$row['dt'];
        if (isset($dailyMap[$dt])) {
            $dailyBookedValues[$dailyMap[$dt]] = (int)$row['total'];
            $dailyCompletedValues[$dailyMap[$dt]] = (int)$row['completed'];
        }
    }
    $dailyStmt->close();
}

$ageLabels = [];
$ageValues = [];
foreach ($ageGroups as $row) {
    $ageLabels[] = (string)$row['age_group'];
    $ageValues[] = (int)$row['patient_count'];
}

$genderLabels = [];
$genderValues = [];
foreach ($genderDist as $row) {
    $genderLabels[] = (string)$row['gender'];
    $genderValues[] = (int)$row['patient_count'];
}

$svcRevLabels = [];
$svcRevValues = [];
foreach (array_slice($serviceRevenue, 0, 8) as $row) {
    $svcRevLabels[] = (string)$row['service_name'];
    $svcRevValues[] = (float)$row['revenue'];
}

$payMethodLabels = [];
$payMethodValues = [];
foreach ($paymentMethods as $row) {
    $payMethodLabels[] = (string)$row['payment_method'];
    $payMethodValues[] = (float)$row['total'];
}

$peakDayLabels = [];
$peakDayValues = [];
foreach ($peakDays as $row) {
    $peakDayLabels[] = (string)$row['day_name'];
    $peakDayValues[] = (int)$row['appointments'];
}

$peakHourLabels = [];
$peakHourValues = [];
foreach ($peakHours as $row) {
    $h = (int)$row['hour_of_day'];
    $peakHourLabels[] = date('g A', mktime($h, 0));
    $peakHourValues[] = (int)$row['appointments'];
}

$avgRevenuePerPatient = $patientsSeen > 0 ? round($totalCollected / $patientsSeen, 2) : 0;
$avgRevenuePerAppointment = $completedVisits > 0 ? round($totalCollected / $completedVisits, 2) : 0;
$collectionRate = $totalBilled > 0 ? round(($totalCollected / $totalBilled) * 100) : 0;

$doctorActivity = [];
$docSql = "
    SELECT u.first_name, u.last_name,
           COUNT(a.appointment_id) AS total_visits,
           SUM(CASE WHEN a.status IN ('completed','records_complete') THEN 1 ELSE 0 END) AS completed_visits,
           SUM(CASE WHEN a.status = 'no_show' THEN 1 ELSE 0 END) AS no_shows
    FROM users u
    INNER JOIN role r ON r.role_id = u.role_id
    LEFT JOIN appointments a ON a.user_id = u.user_id AND a.appointment_date BETWEEN ? AND ?
    WHERE r.role_name = 'doctor'
";
if ($doctorId > 0) {
    $docSql .= ' AND u.user_id = ?';
}
$docSql .= "
    GROUP BY u.user_id, u.first_name, u.last_name
    ORDER BY completed_visits DESC, total_visits DESC
";
$docStmt = $conn->prepare($docSql);
if ($docStmt) {
    if ($doctorId > 0) {
        $docStmt->bind_param('ssi', $startDate, $endDate, $doctorId);
    } else {
        $docStmt->bind_param('ss', $startDate, $endDate);
    }
    $docStmt->execute();
    $res = $docStmt->get_result();
    while ($row = $res->fetch_assoc()) {
        $doctorActivity[] = $row;
    }
    $docStmt->close();
}

$birSalesPreview = [];
if (table_exists($conn, 'billing')) {
    $birSql = "
        SELECT b.billing_id, b.total_amount, b.amount_paid, b.balance, b.status,
               a.appointment_date,
               p.first_name AS patient_first, p.last_name AS patient_last,
               inv.invoice_number
        FROM billing b
        INNER JOIN appointments a ON a.appointment_id = b.appointment_id
        INNER JOIN patients p ON p.patient_id = a.patient_id
        LEFT JOIN invoices inv ON inv.billing_id = b.billing_id
        WHERE a.appointment_date BETWEEN ? AND ?{$doctorClause}
        ORDER BY a.appointment_date DESC
        LIMIT 15
    ";
    $birStmt = $conn->prepare($birSql);
    if ($birStmt) {
        $bind = [$baseTypes];
        foreach ($baseParams as $k => $v) {
            $bind[] = &$baseParams[$k];
        }
        call_user_func_array([$birStmt, 'bind_param'], $bind);
        $birStmt->execute();
        $birRes = $birStmt->get_result();
        while ($row = $birRes->fetch_assoc()) {
            $birSalesPreview[] = $row;
        }
        $birStmt->close();
    }
}

$reportNavQuery = array_merge(
    ['page' => 'reports', 'report_type' => $reportType],
    $doctorId > 0 ? ['doctor_id' => $doctorId] : []
);
?>

<div class="dental-dashboard-shell clinic-reports-shell" id="clinicReportPrintArea">
    <div class="dental-dashboard-hero module-page-header">
        <div>
            <h4 class="mb-0">
                <span class="module-page-header__crumb">Clinic Reports</span> <span class="module-page-header__sep">&gt;</span>
                <?php echo htmlspecialchars($reportTypes[$reportType]['label'] ?? 'Sales Reports'); ?>
            </h4>
        </div>
        <div class="d-flex flex-wrap align-items-center gap-2">
            <span class="dental-chip"><?php echo htmlspecialchars($periodLabel); ?></span>
            <button type="button" class="btn btn-sm btn-secondary clinic-report-no-print clinic-report-print-btn" onclick="window.print()">
                <i class="bi bi-printer"></i> Print
            </button>
        </div>
    </div>

    <?php
    $printMetaDoctor = 'All doctors';
    if ($doctorId > 0) {
        foreach ($doctors as $doc) {
            if ((int)$doc['user_id'] === $doctorId) {
                $printMetaDoctor = trim($doc['first_name'] . ' ' . $doc['last_name']);
                break;
            }
        }
    }
    ?>
    <div class="clinic-report-print-meta">
        <strong><?php echo htmlspecialchars(function_exists('email_clinic_name') ? email_clinic_name() : "Azucena's Dental Clinic"); ?></strong>
        — <?php echo htmlspecialchars($reportTypes[$reportType]['label'] ?? 'Report'); ?>
        &nbsp;|&nbsp; Period: <?php echo htmlspecialchars($periodLabel); ?>
        &nbsp;|&nbsp; Doctor: <?php echo htmlspecialchars($printMetaDoctor); ?>
        &nbsp;|&nbsp; Generated: <?php echo date('Y-m-d H:i'); ?>
    </div>

    <nav class="module-section-nav clinic-report-no-print" aria-label="Report types">
        <div class="module-section-nav__track">
            <ul class="nav nav-pills mb-0">
                <?php foreach ($reportTypes as $key => $info): ?>
                    <li class="nav-item">
                        <a class="nav-link <?php echo $reportType === $key ? 'active' : ''; ?>" href="<?php echo htmlspecialchars('admin.php?' . http_build_query(array_merge(['page' => 'reports', 'report_type' => $key, 'range' => $range], $doctorId > 0 ? ['doctor_id' => $doctorId] : []))); ?>">
                            <i class="bi <?php echo $info['icon']; ?>"></i> <?php echo $info['label']; ?>
                        </a>
                    </li>
                <?php endforeach; ?>
            </ul>
        </div>
    </nav>

    <form method="GET" action="admin.php" class="clinic-reports-filter card border-0 mb-3">
        <div class="card-body">
            <input type="hidden" name="page" value="reports">
            <input type="hidden" name="report_type" value="<?php echo htmlspecialchars($reportType); ?>">
            <div class="row g-2 align-items-end">
                <div class="col-md-2">
                    <label class="form-label small mb-1">Period</label>
                    <select name="range" class="form-select form-select-sm" id="reportRangeSelect">
                        <?php foreach ($reportRangeNav as $rangeKey => $rangeLabel): ?>
                            <option value="<?php echo $rangeKey; ?>" <?php echo $range === $rangeKey ? 'selected' : ''; ?>><?php echo htmlspecialchars($rangeLabel); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-2 report-custom-date" style="<?php echo $range !== 'custom' ? 'display:none' : ''; ?>">
                    <label class="form-label small mb-1">From</label>
                    <input type="date" name="start_date" class="form-control form-control-sm" value="<?php echo htmlspecialchars($startDate); ?>">
                </div>
                <div class="col-md-2 report-custom-date" style="<?php echo $range !== 'custom' ? 'display:none' : ''; ?>">
                    <label class="form-label small mb-1">To</label>
                    <input type="date" name="end_date" class="form-control form-control-sm" value="<?php echo htmlspecialchars($endDate); ?>">
                </div>
                <div class="col-md-3">
                    <label class="form-label small mb-1">Doctor</label>
                    <select name="doctor_id" class="form-select form-select-sm">
                        <option value="">All doctors</option>
                        <?php foreach ($doctors as $doc): ?>
                            <option value="<?php echo (int)$doc['user_id']; ?>" <?php echo $doctorId === (int)$doc['user_id'] ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars(trim($doc['first_name'] . ' ' . $doc['last_name'])); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-3 d-flex gap-2">
                    <button type="submit" class="btn btn-primary btn-sm w-100"><i class="bi bi-funnel"></i> Apply</button>
                    <a href="admin.php?page=reports&report_type=<?php echo htmlspecialchars($reportType); ?>" class="btn btn-outline-secondary btn-sm w-100">Reset</a>
                </div>
            </div>
        </div>
    </form>

    <?php if ($reportType === 'sales'): ?>
    <div class="row g-3 mb-3">
        <div class="col-6 col-xl-3">
            <div class="card dental-kpi-card p-3 h-100">
                <div class="dental-kpi-icon dental-kpi-icon--teal"><i class="bi bi-cash-stack"></i></div>
                <div class="dental-kpi-label">Total Billed</div>
                <div class="dental-kpi-value">₱<?php echo number_format($totalBilled, 2); ?></div>
            </div>
        </div>
        <div class="col-6 col-xl-3">
            <div class="card dental-kpi-card p-3 h-100">
                <div class="dental-kpi-icon dental-kpi-icon--green"><i class="bi bi-check-circle"></i></div>
                <div class="dental-kpi-label">Collected</div>
                <div class="dental-kpi-value text-success">₱<?php echo number_format($totalCollected, 2); ?></div>
            </div>
        </div>
        <div class="col-6 col-xl-3">
            <div class="card dental-kpi-card p-3 h-100">
                <div class="dental-kpi-icon dental-kpi-icon--amber"><i class="bi bi-exclamation-circle"></i></div>
                <div class="dental-kpi-label">Outstanding</div>
                <div class="dental-kpi-value <?php echo $totalOutstanding > 0 ? 'text-danger' : ''; ?>">₱<?php echo number_format($totalOutstanding, 2); ?></div>
            </div>
        </div>
        <div class="col-6 col-xl-3">
            <div class="card dental-kpi-card p-3 h-100">
                <div class="dental-kpi-icon dental-kpi-icon--teal"><i class="bi bi-graph-up"></i></div>
                <div class="dental-kpi-label">Collection Rate</div>
                <div class="dental-kpi-value"><?php echo $collectionRate; ?>%</div>
            </div>
        </div>
    </div>

    <p class="dental-section-eyebrow">Averages</p>
    <div class="row g-3 mb-3">
        <div class="col-6 col-md-4">
            <div class="card dental-kpi-card p-3 h-100">
                <div class="dental-kpi-icon dental-kpi-icon--teal"><i class="bi bi-person"></i></div>
                <div class="dental-kpi-label">Avg Revenue / Patient</div>
                <div class="dental-kpi-value">₱<?php echo number_format($avgRevenuePerPatient, 2); ?></div>
            </div>
        </div>
        <div class="col-6 col-md-4">
            <div class="card dental-kpi-card p-3 h-100">
                <div class="dental-kpi-icon dental-kpi-icon--teal"><i class="bi bi-calendar-check"></i></div>
                <div class="dental-kpi-label">Avg Revenue / Visit</div>
                <div class="dental-kpi-value">₱<?php echo number_format($avgRevenuePerAppointment, 2); ?></div>
            </div>
        </div>
        <div class="col-6 col-md-4">
            <div class="card dental-kpi-card p-3 h-100">
                <div class="dental-kpi-icon dental-kpi-icon--teal"><i class="bi bi-receipt"></i></div>
                <div class="dental-kpi-label">Total Invoices</div>
                <div class="dental-kpi-value"><?php echo (int)$billingCount; ?></div>
            </div>
        </div>
    </div>

    <p class="dental-section-eyebrow">Revenue Breakdown</p>

    <div class="row g-3 mb-3">
        <div class="col-lg-8">
            <div class="card dental-surface-card dental-chart-card h-100">
                <div class="card-body">
                    <div class="dental-chart-card__head">
                        <h5 class="card-title mb-0">Daily Revenue Trend</h5>
                        <button type="button" class="dental-chart-expand-btn" data-chart-expand="salesDailyRevChart" aria-label="Enlarge chart">
                            <i class="bi bi-arrows-fullscreen"></i>
                        </button>
                    </div>
                    <div class="dental-chart-frame">
                        <div id="salesDailyRevChart" class="clinic-simple-chart"></div>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-lg-4">
            <div class="card dental-surface-card dental-chart-card h-100">
                <div class="card-body">
                    <div class="dental-chart-card__head">
                        <h5 class="card-title mb-0">Payment Methods</h5>
                        <button type="button" class="dental-chart-expand-btn" data-chart-expand="salesPayMethodChart" aria-label="Enlarge chart">
                            <i class="bi bi-arrows-fullscreen"></i>
                        </button>
                    </div>
                    <div class="dental-chart-frame">
                        <div id="salesPayMethodChart" class="clinic-simple-chart"></div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="row g-3 mb-3">
        <div class="col-lg-6">
            <div class="card dental-surface-card dental-chart-card h-100">
                <div class="card-body">
                    <div class="dental-chart-card__head">
                        <h5 class="card-title mb-0">Revenue by Service</h5>
                        <button type="button" class="dental-chart-expand-btn" data-chart-expand="salesSvcRevChart" aria-label="Enlarge chart">
                            <i class="bi bi-arrows-fullscreen"></i>
                        </button>
                    </div>
                    <div class="dental-chart-frame">
                        <div id="salesSvcRevChart" class="clinic-simple-chart"></div>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-lg-6">
            <div class="card dental-surface-card dental-chart-card h-100">
                <div class="card-body">
                    <div class="dental-chart-card__head">
                        <h5 class="card-title mb-0">Revenue Share by Service</h5>
                        <button type="button" class="dental-chart-expand-btn" data-chart-expand="salesSvcShareChart" aria-label="Enlarge chart">
                            <i class="bi bi-arrows-fullscreen"></i>
                        </button>
                    </div>
                    <div class="dental-chart-frame">
                        <div id="salesSvcShareChart" class="clinic-simple-chart"></div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <?php if (!empty($birSalesPreview)): ?>
    <div class="col-12 mb-3">
        <div class="card dental-surface-card">
            <div class="card-body">
                <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
                    <h5 class="card-title mb-0">BIR Sales Preview</h5>
                    <a href="<?php echo htmlspecialchars($birExportUrl); ?>" download class="btn btn-sm btn-outline-success clinic-report-no-print">
                        <i class="bi bi-file-earmark-spreadsheet"></i> Download CSV
                    </a>
                </div>
                <div class="table-responsive">
                    <table class="table table-sm table-hover align-middle mb-0">
                        <thead>
                            <tr>
                                <th>Invoice</th>
                                <th>Service Date</th>
                                <th>Patient</th>
                                <th class="text-end">Billed</th>
                                <th class="text-end">Collected</th>
                                <th class="text-end">Balance</th>
                                <th>Status</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($birSalesPreview as $row): ?>
                                <?php
                                $inv = $row['invoice_number'] ?: ('INV-' . str_pad((string)$row['billing_id'], 6, '0', STR_PAD_LEFT));
                                $patient = trim(($row['patient_first'] ?? '') . ' ' . ($row['patient_last'] ?? ''));
                                ?>
                                <tr>
                                    <td class="fw-semibold"><?php echo htmlspecialchars($inv); ?></td>
                                    <td><?php echo htmlspecialchars($row['appointment_date']); ?></td>
                                    <td><?php echo htmlspecialchars($patient); ?></td>
                                    <td class="text-end">₱<?php echo number_format((float)$row['total_amount'], 2); ?></td>
                                    <td class="text-end text-success">₱<?php echo number_format((float)$row['amount_paid'], 2); ?></td>
                                    <td class="text-end">₱<?php echo number_format((float)$row['balance'], 2); ?></td>
                                    <td><span class="badge bg-secondary"><?php echo htmlspecialchars($row['status']); ?></span></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <?php elseif ($reportType === 'appointments'): ?>
    <div class="row g-3 mb-3">
        <div class="col-6 col-xl-3">
            <div class="card dental-kpi-card p-3 h-100">
                <div class="dental-kpi-icon dental-kpi-icon--teal"><i class="bi bi-calendar3"></i></div>
                <div class="dental-kpi-label">Total Appointments</div>
                <div class="dental-kpi-value"><?php echo (int)$totalAppointments; ?></div>
            </div>
        </div>
        <div class="col-6 col-xl-3">
            <div class="card dental-kpi-card p-3 h-100">
                <div class="dental-kpi-icon dental-kpi-icon--green"><i class="bi bi-check-circle"></i></div>
                <div class="dental-kpi-label">Completed Visits</div>
                <div class="dental-kpi-value text-success"><?php echo (int)$completedVisits; ?></div>
            </div>
        </div>
        <div class="col-6 col-xl-3">
            <div class="card dental-kpi-card p-3 h-100">
                <div class="dental-kpi-icon dental-kpi-icon--red"><i class="bi bi-x-circle"></i></div>
                <div class="dental-kpi-label">No-Shows</div>
                <div class="dental-kpi-value <?php echo $noShows > 0 ? 'text-danger' : ''; ?>"><?php echo (int)$noShows; ?></div>
            </div>
        </div>
        <div class="col-6 col-xl-3">
            <div class="card dental-kpi-card p-3 h-100">
                <div class="dental-kpi-icon dental-kpi-icon--teal"><i class="bi bi-graph-up"></i></div>
                <div class="dental-kpi-label">Completion Rate</div>
                <div class="dental-kpi-value"><?php echo $completionRate; ?>%</div>
            </div>
        </div>
    </div>

    <p class="dental-section-eyebrow">Overview</p>

    <div class="row g-3 mb-3">
        <div class="col-lg-4">
            <div class="card dental-surface-card dental-chart-card h-100">
                <div class="card-body">
                    <div class="dental-chart-card__head">
                        <h5 class="card-title mb-0">Appointments Overview</h5>
                        <button type="button" class="dental-chart-expand-btn" data-chart-expand="reportDailyChart" aria-label="Enlarge chart">
                            <i class="bi bi-arrows-fullscreen"></i>
                        </button>
                    </div>
                    <div class="dental-chart-frame">
                        <div id="reportDailyChart" class="clinic-simple-chart"></div>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-lg-4">
            <div class="card dental-surface-card dental-chart-card h-100">
                <div class="card-body">
                    <div class="dental-chart-card__head">
                        <h5 class="card-title mb-0">Status Breakdown</h5>
                        <button type="button" class="dental-chart-expand-btn" data-chart-expand="reportStatusChart" aria-label="Enlarge chart">
                            <i class="bi bi-arrows-fullscreen"></i>
                        </button>
                    </div>
                    <div class="dental-chart-frame">
                        <div id="reportStatusChart" class="clinic-simple-chart"></div>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-lg-4">
            <div class="card dental-surface-card dental-chart-card h-100">
                <div class="card-body">
                    <div class="dental-chart-card__head">
                        <h5 class="card-title mb-0">Top Services</h5>
                        <button type="button" class="dental-chart-expand-btn" data-chart-expand="reportServicesChart" aria-label="Enlarge chart">
                            <i class="bi bi-arrows-fullscreen"></i>
                        </button>
                    </div>
                    <div class="dental-chart-frame">
                        <div id="reportServicesChart" class="clinic-simple-chart"></div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <p class="dental-section-eyebrow">Doctor Performance</p>
    <div class="row g-3 mb-3">
        <div class="col-12">
            <div class="card dental-surface-card">
                <div class="card-body">
                    <h5 class="card-title mb-3">Doctor Activity</h5>
                    <div class="table-responsive">
                        <table class="table table-sm table-hover align-middle mb-0">
                            <thead>
                                <tr>
                                    <th>Doctor</th>
                                    <th class="text-end">Total Appointments</th>
                                    <th class="text-end">Completed</th>
                                    <th class="text-end">No-Shows</th>
                                    <th class="text-end">Completion</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (empty($doctorActivity)): ?>
                                    <tr><td colspan="5" class="text-muted text-center py-3">No doctor data for this period.</td></tr>
                                <?php else: ?>
                                    <?php foreach ($doctorActivity as $doc): ?>
                                        <?php
                                        $total = (int)$doc['total_visits'];
                                        $done = (int)$doc['completed_visits'];
                                        $rate = $total > 0 ? round(($done / $total) * 100) : 0;
                                        ?>
                                        <tr>
                                            <td class="fw-semibold"><?php echo htmlspecialchars(trim($doc['first_name'] . ' ' . $doc['last_name'])); ?></td>
                                            <td class="text-end"><?php echo $total; ?></td>
                                            <td class="text-end text-success fw-semibold"><?php echo $done; ?></td>
                                            <td class="text-end <?php echo (int)$doc['no_shows'] > 0 ? 'text-danger' : ''; ?>"><?php echo (int)$doc['no_shows']; ?></td>
                                            <td class="text-end"><?php echo (int)$rate; ?>%</td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <p class="dental-section-eyebrow">Recent Activity</p>
    <div class="row g-3">
        <div class="col-12">
            <div class="card dental-surface-card">
                <div class="card-body">
                    <h5 class="card-title mb-3">Recent Completed Visits</h5>
                    <div class="table-responsive">
                        <table class="table table-sm table-hover align-middle mb-0">
                            <thead>
                                <tr>
                                    <th>Date</th>
                                    <th>Patient</th>
                                    <th>Doctor</th>
                                    <th>Services</th>
                                    <th>Status</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (empty($recentVisits)): ?>
                                    <tr><td colspan="5" class="text-muted text-center py-3">No completed visits in this period.</td></tr>
                                <?php else: ?>
                                    <?php foreach ($recentVisits as $visit): ?>
                                        <?php $st = (string)($visit['status'] ?? 'completed'); ?>
                                        <tr>
                                            <td><?php echo date('M d, Y', strtotime($visit['appointment_date'])); ?></td>
                                            <td><?php echo htmlspecialchars(trim(($visit['patient_first'] ?? '') . ' ' . ($visit['patient_last'] ?? ''))); ?></td>
                                            <td><?php echo htmlspecialchars(trim(($visit['doctor_first'] ?? '') . ' ' . ($visit['doctor_last'] ?? ''))); ?></td>
                                            <td class="small"><?php echo htmlspecialchars($visit['services'] ?? '—'); ?></td>
                                            <td>
                                                <span class="badge bg-<?php echo dashboard_appt_status_badge_class($st); ?>">
                                                    <?php echo htmlspecialchars(reports_format_status($st)); ?>
                                                </span>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <?php elseif ($reportType === 'demographic'): ?>
    <div class="row g-3 mb-3">
        <div class="col-6 col-xl-3">
            <div class="card dental-kpi-card p-3 h-100">
                <div class="dental-kpi-icon dental-kpi-icon--teal"><i class="bi bi-people"></i></div>
                <div class="dental-kpi-label">Total Patients Seen</div>
                <div class="dental-kpi-value"><?php echo (int)$patientsSeen; ?></div>
            </div>
        </div>
        <div class="col-6 col-xl-3">
            <div class="card dental-kpi-card p-3 h-100">
                <div class="dental-kpi-icon dental-kpi-icon--green"><i class="bi bi-person-plus"></i></div>
                <div class="dental-kpi-label">New Patients</div>
                <div class="dental-kpi-value"><?php echo (int)$newPatients; ?></div>
            </div>
        </div>
        <div class="col-6 col-xl-3">
            <div class="card dental-kpi-card p-3 h-100">
                <div class="dental-kpi-icon dental-kpi-icon--teal"><i class="bi bi-calendar3"></i></div>
                <div class="dental-kpi-label">Total Appointments</div>
                <div class="dental-kpi-value"><?php echo (int)$totalAppointments; ?></div>
            </div>
        </div>
        <div class="col-6 col-xl-3">
            <div class="card dental-kpi-card p-3 h-100">
                <div class="dental-kpi-icon dental-kpi-icon--teal"><i class="bi bi-graph-up"></i></div>
                <div class="dental-kpi-label">Completion Rate</div>
                <div class="dental-kpi-value"><?php echo $completionRate; ?>%</div>
            </div>
        </div>
    </div>

    <p class="dental-section-eyebrow">Patient Insights</p>

    <div class="row g-3 mb-3">
        <div class="col-lg-6">
            <div class="card dental-surface-card dental-chart-card h-100">
                <div class="card-body">
                    <div class="dental-chart-card__head">
                        <h5 class="card-title mb-0">Age Distribution</h5>
                        <button type="button" class="dental-chart-expand-btn" data-chart-expand="demoAgeChart" aria-label="Enlarge chart">
                            <i class="bi bi-arrows-fullscreen"></i>
                        </button>
                    </div>
                    <div class="dental-chart-frame">
                        <div id="demoAgeChart" class="clinic-simple-chart"></div>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-lg-6">
            <div class="card dental-surface-card dental-chart-card h-100">
                <div class="card-body">
                    <div class="dental-chart-card__head">
                        <h5 class="card-title mb-0">Gender Distribution</h5>
                        <button type="button" class="dental-chart-expand-btn" data-chart-expand="demoGenderChart" aria-label="Enlarge chart">
                            <i class="bi bi-arrows-fullscreen"></i>
                        </button>
                    </div>
                    <div class="dental-chart-frame">
                        <div id="demoGenderChart" class="clinic-simple-chart"></div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="row g-3 mb-3">
        <div class="col-lg-6">
            <div class="card dental-surface-card dental-chart-card h-100">
                <div class="card-body">
                    <div class="dental-chart-card__head">
                        <h5 class="card-title mb-0">Peak Booking Days</h5>
                        <button type="button" class="dental-chart-expand-btn" data-chart-expand="demoPeakDaysChart" aria-label="Enlarge chart">
                            <i class="bi bi-arrows-fullscreen"></i>
                        </button>
                    </div>
                    <div class="dental-chart-frame">
                        <div id="demoPeakDaysChart" class="clinic-simple-chart"></div>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-lg-6">
            <div class="card dental-surface-card dental-chart-card h-100">
                <div class="card-body">
                    <div class="dental-chart-card__head">
                        <h5 class="card-title mb-0">Peak Booking Hours</h5>
                        <button type="button" class="dental-chart-expand-btn" data-chart-expand="demoPeakHoursChart" aria-label="Enlarge chart">
                            <i class="bi bi-arrows-fullscreen"></i>
                        </button>
                    </div>
                    <div class="dental-chart-frame">
                        <div id="demoPeakHoursChart" class="clinic-simple-chart"></div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <?php elseif ($reportType === 'trend'):
        $fc = $trend['forecast'];
        $monthProjected = $trend['month_so_far'] + $trend['month_booked_ahead'];
        $perClinicDay = ($fc && $trend['next_clinic_days'] > 0) ? $fc['value'] / $trend['next_clinic_days'] : null;
        $hasHistory = array_sum($trend['booked']) > 0;
        $dirMeta = [
            'rising' => ['bi-arrow-up-right', 'text-success', 'Rising'],
            'falling' => ['bi-arrow-down-right', 'text-danger', 'Falling'],
            'stable' => ['bi-dash-lg', 'text-muted', 'Steady'],
            'new' => ['bi-stars', 'text-primary', 'New'],
        ];
        $peakHourLabel = function ($h) { return date('g A', mktime((int)$h, 0)); };
    ?>
    <p class="text-muted small mb-3">
        Based on appointments only. Forecasts use complete months (the current month is still in progress, so it's shown as "so far").
    </p>

    <div class="row g-3 mb-3">
        <div class="col-6 col-lg-3">
            <div class="card dental-kpi-card p-3 h-100">
                <div class="dental-kpi-icon dental-kpi-icon--teal"><i class="bi bi-graph-up-arrow"></i></div>
                <div class="dental-kpi-label">Forecast for <?php echo htmlspecialchars($trend['next_label']); ?></div>
                <?php if ($fc): ?>
                    <div class="dental-kpi-value text-primary"><?php echo (int)$fc['value']; ?> <small>appointments</small></div>
                    <div class="small text-muted">Likely <?php echo (int)$fc['low']; ?>–<?php echo (int)$fc['high']; ?> · <?php echo (int)$trend['next_booked']; ?> already booked</div>
                <?php else: ?>
                    <div class="dental-kpi-value text-muted">—</div>
                    <div class="small text-muted">Needs at least one complete month of appointments.</div>
                <?php endif; ?>
            </div>
        </div>
        <div class="col-6 col-lg-3">
            <div class="card dental-kpi-card p-3 h-100">
                <div class="dental-kpi-icon dental-kpi-icon--teal"><i class="bi bi-calendar-week"></i></div>
                <div class="dental-kpi-label"><?php echo htmlspecialchars($trend['month_label']); ?> so far</div>
                <div class="dental-kpi-value"><?php echo (int)$trend['month_so_far']; ?> <small>appointments</small></div>
                <div class="small text-muted">
                    + <?php echo (int)$trend['month_booked_ahead']; ?> booked ahead = <?php echo (int)$monthProjected; ?>
                    · last month <?php echo (int)$trend['last_month_total']; ?>
                </div>
            </div>
        </div>
        <div class="col-6 col-lg-3">
            <div class="card dental-kpi-card p-3 h-100">
                <div class="dental-kpi-icon dental-kpi-icon--teal"><i class="bi bi-check2-circle"></i></div>
                <div class="dental-kpi-label">Visits completed (3 months)</div>
                <div class="dental-kpi-value"><?php echo $trend['rates']['completion'] === null ? '—' : (int)$trend['rates']['completion'] . '%'; ?></div>
                <div class="small text-muted">
                    No-show <?php echo $trend['rates']['no_show'] === null ? '—' : (int)$trend['rates']['no_show'] . '%'; ?>
                    · Cancelled <?php echo $trend['rates']['cancellation'] === null ? '—' : (int)$trend['rates']['cancellation'] . '%'; ?>
                </div>
            </div>
        </div>
        <div class="col-6 col-lg-3">
            <div class="card dental-kpi-card p-3 h-100">
                <div class="dental-kpi-icon dental-kpi-icon--teal"><i class="bi bi-arrow-repeat"></i></div>
                <div class="dental-kpi-label">Returning patients (3 months)</div>
                <div class="dental-kpi-value"><?php echo $trend['rates']['returning'] === null ? '—' : (int)$trend['rates']['returning'] . '%'; ?></div>
                <div class="small text-muted">of patients seen had visited before</div>
            </div>
        </div>
    </div>

    <p class="dental-section-eyebrow">Forecast</p>
    <div class="row g-3 mb-3">
        <div class="col-lg-8">
            <div class="card dental-surface-card dental-chart-card h-100">
                <div class="card-body">
                    <div class="dental-chart-card__head">
                        <h5 class="card-title mb-0">Monthly appointments &amp; forecast</h5>
                        <button type="button" class="dental-chart-expand-btn" data-chart-expand="trendMonthlyChart" aria-label="Enlarge chart">
                            <i class="bi bi-arrows-fullscreen"></i>
                        </button>
                    </div>
                    <div class="dental-chart-frame">
                        <div id="trendMonthlyChart" class="clinic-simple-chart"></div>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-lg-4">
            <div class="card dental-surface-card h-100">
                <div class="card-body">
                    <h5 class="card-title mb-3"><i class="bi bi-people text-primary"></i> Scheduling &amp; capacity</h5>
                    <ul class="list-unstyled mb-0 small">
                        <li class="mb-2 pb-2 border-bottom">
                            <strong>Expected per clinic day:</strong>
                            <?php echo $perClinicDay === null ? '—' : number_format($perClinicDay, 1); ?>
                            <span class="text-muted">(<?php echo (int)$trend['next_clinic_days']; ?> clinic days in <?php echo htmlspecialchars($trend['next_label']); ?>, Mon–Sat)</span>
                        </li>
                        <li class="mb-2 pb-2 border-bottom">
                            <strong>Busiest day:</strong>
                            <?php if ($trend['peak_days']): ?>
                                <?php echo htmlspecialchars($trend['peak_days'][0]['day_name']); ?>
                                <span class="text-muted">(<?php echo (int)$trend['peak_days'][0]['visits']; ?> visits in 3 months)</span>
                            <?php else: ?>
                                <span class="text-muted">Not enough visits yet</span>
                            <?php endif; ?>
                        </li>
                        <li class="mb-2 pb-2 border-bottom">
                            <strong>Quietest day:</strong>
                            <?php if (count($trend['peak_days']) > 1): $q = end($trend['peak_days']); ?>
                                <?php echo htmlspecialchars($q['day_name']); ?>
                                <span class="text-muted">(<?php echo (int)$q['visits']; ?> visits)</span>
                            <?php else: ?>
                                <span class="text-muted">Not enough visits yet</span>
                            <?php endif; ?>
                        </li>
                        <li>
                            <strong>Peak hours:</strong>
                            <?php if ($trend['peak_hours']): ?>
                                <?php echo htmlspecialchars(implode(', ', array_map(fn($r) => $peakHourLabel($r['hour_of_day']), $trend['peak_hours']))); ?>
                            <?php else: ?>
                                <span class="text-muted">Not enough visits yet</span>
                            <?php endif; ?>
                        </li>
                    </ul>
                </div>
            </div>
        </div>
    </div>

    <p class="dental-section-eyebrow">Appointment outcomes</p>
    <div class="row g-3 mb-3">
        <div class="col-lg-7">
            <div class="card dental-surface-card dental-chart-card h-100">
                <div class="card-body">
                    <div class="dental-chart-card__head">
                        <h5 class="card-title mb-0">Completed, cancelled &amp; no-show</h5>
                        <button type="button" class="dental-chart-expand-btn" data-chart-expand="trendOutcomeChart" aria-label="Enlarge chart">
                            <i class="bi bi-arrows-fullscreen"></i>
                        </button>
                    </div>
                    <div class="dental-chart-frame">
                        <div id="trendOutcomeChart" class="clinic-simple-chart"></div>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-lg-5">
            <div class="card dental-surface-card dental-chart-card h-100">
                <div class="card-body">
                    <div class="dental-chart-card__head">
                        <h5 class="card-title mb-0">New vs returning patients</h5>
                        <button type="button" class="dental-chart-expand-btn" data-chart-expand="trendPatientsChart" aria-label="Enlarge chart">
                            <i class="bi bi-arrows-fullscreen"></i>
                        </button>
                    </div>
                    <div class="dental-chart-frame">
                        <div id="trendPatientsChart" class="clinic-simple-chart"></div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <p class="dental-section-eyebrow">Service demand</p>
    <div class="row g-3 mb-3">
        <div class="col-lg-5">
            <div class="card dental-surface-card h-100">
                <div class="card-body">
                    <h5 class="card-title mb-3">Service demand forecast</h5>
                    <?php if (empty($trend['services'])): ?>
                        <p class="text-muted mb-0">Not enough booked services yet (a service needs at least 2 bookings in the last 6 months).</p>
                    <?php else: ?>
                        <div class="table-responsive">
                            <table class="table table-sm align-middle mb-0">
                                <thead>
                                    <tr>
                                        <th>Service</th>
                                        <th class="text-end" title="Bookings last month">Last month</th>
                                        <th class="text-end" title="Estimated bookings next month">Next month</th>
                                        <th class="text-end" title="Already booked for next month">Booked</th>
                                        <th class="text-end">Trend</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach (array_slice($trend['services'], 0, 8) as $svc):
                                        $dm = $dirMeta[$svc['direction']['label']] ?? $dirMeta['stable'];
                                        $chg = $svc['direction']['change'];
                                    ?>
                                        <tr>
                                            <td class="fw-semibold small"><?php echo htmlspecialchars($svc['name']); ?></td>
                                            <td class="text-end"><?php echo (int)$svc['last']; ?></td>
                                            <td class="text-end fw-bold"><?php echo (int)$svc['forecast']; ?></td>
                                            <td class="text-end"><?php echo (int)$svc['booked']; ?></td>
                                            <td class="text-end small <?php echo $dm[1]; ?>" style="white-space:nowrap;">
                                                <i class="bi <?php echo $dm[0]; ?>" aria-hidden="true"></i>
                                                <?php echo $dm[2]; ?><?php if ($chg !== null && $svc['direction']['label'] !== 'stable' && $svc['direction']['base'] >= 3): // % from tiny counts (1 -> 3 = +200%) misleads ?> <?php echo ($chg > 0 ? '+' : '') . (int)round($chg); ?>%<?php endif; ?>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                        <p class="text-muted small mt-2 mb-0">Trend compares the last 3 months with the 3 before.</p>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        <div class="col-lg-7">
            <div class="card dental-surface-card dental-chart-card h-100">
                <div class="card-body">
                    <div class="dental-chart-card__head">
                        <h5 class="card-title mb-0">Top services by month (6 months)</h5>
                        <button type="button" class="dental-chart-expand-btn" data-chart-expand="trendSvcChart" aria-label="Enlarge chart">
                            <i class="bi bi-arrows-fullscreen"></i>
                        </button>
                    </div>
                    <div class="dental-chart-frame">
                        <div id="trendSvcChart" class="clinic-simple-chart"></div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <p class="dental-section-eyebrow">Recommendations</p>
    <div class="row g-3 mb-3">
        <div class="col-12">
            <div class="card dental-surface-card">
                <div class="card-body">
                    <h5 class="card-title mb-3"><i class="bi bi-lightbulb text-warning"></i> What the appointments suggest</h5>
                    <?php
                    $tips = [];
                    if ($fc && $trend['last_month_total'] > 0) {
                        $delta = ($fc['value'] - $trend['last_month_total']) / $trend['last_month_total'];
                        if ($delta >= 0.10) {
                            $tips[] = ['bi-graph-up-arrow', 'Expect a busier ' . $trend['next_label'] . ': about ' . $fc['value'] . ' appointments vs ' . $trend['last_month_total'] . ' last month. Open extra slots on ' . ($trend['peak_days'][0]['day_name'] ?? 'your busiest days') . '.'];
                        } elseif ($delta <= -0.10) {
                            $tips[] = ['bi-graph-down-arrow', $trend['next_label'] . ' looks quieter (about ' . $fc['value'] . ' vs ' . $trend['last_month_total'] . ' last month). A good time for recall reminders or follow-ups on pending treatment plans.'];
                        }
                    }
                    if ($trend['rates']['no_show'] !== null && $trend['rates']['no_show'] >= 10) {
                        $tips[] = ['bi-bell', $trend['rates']['no_show'] . '% of appointments were no-shows in the last 3 months. Confirm appointments the day before.'];
                    }
                    if ($trend['rates']['cancellation'] !== null && $trend['rates']['cancellation'] >= 15) {
                        $tips[] = ['bi-calendar-x', $trend['rates']['cancellation'] . '% were cancelled. Keep a short waitlist to refill cancelled slots.'];
                    }
                    $rising = array_values(array_filter($trend['services'], fn($svc) => $svc['direction']['label'] === 'rising'));
                    if ($rising) {
                        $parts = array_map(fn($svc) => $svc['name'] . ' (about ' . $svc['forecast'] . ')', array_slice($rising, 0, 3));
                        $tips[] = ['bi-arrow-up-right', 'Demand is rising for ' . implode(', ', $parts) . '. Plan chair time for these next month.'];
                    }
                    $falling = array_values(array_filter($trend['services'], fn($svc) => $svc['direction']['label'] === 'falling'));
                    if ($falling) {
                        $tips[] = ['bi-arrow-down-right', 'Fewer bookings lately for ' . implode(', ', array_map(fn($svc) => $svc['name'], array_slice($falling, 0, 3))) . '. Worth mentioning to patients whose treatment plans include them.'];
                    }
                    if (count($trend['peak_days']) > 1) {
                        $q = end($trend['peak_days']);
                        $tips[] = ['bi-calendar-check', $q['day_name'] . ' is the quietest day. Suggest it to patients who are flexible, to ease ' . $trend['peak_days'][0]['day_name'] . '.'];
                    }
                    if ($trend['rates']['returning'] !== null && $trend['rates']['returning'] < 40) {
                        $tips[] = ['bi-arrow-repeat', 'Only ' . $trend['rates']['returning'] . '% of patients were returning. Recall reminders for check-ups and cleanings can bring more back.'];
                    }
                    ?>
                    <?php if (!$hasHistory): ?>
                        <p class="text-muted mb-0">No complete months of appointments yet. Recommendations appear once there's at least a month of history.</p>
                    <?php elseif (!$tips): ?>
                        <p class="text-muted mb-0">Appointments look steady. Nothing needs attention right now.</p>
                    <?php else: ?>
                        <ul class="list-unstyled mb-0">
                            <?php foreach (array_slice($tips, 0, 6) as $i => $tip): ?>
                                <li class="d-flex gap-2 <?php echo $i < min(6, count($tips)) - 1 ? 'mb-2 pb-2 border-bottom' : ''; ?>">
                                    <i class="bi <?php echo $tip[0]; ?> text-primary mt-1" aria-hidden="true"></i>
                                    <span><?php echo htmlspecialchars($tip[1]); ?></span>
                                </li>
                            <?php endforeach; ?>
                        </ul>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

    <?php endif; ?>
</div>

<div class="modal fade" id="adminChartExpandModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-xl modal-dialog-centered">
        <div class="modal-content dental-chart-modal">
            <div class="modal-header">
                <h5 class="modal-title" id="adminChartExpandTitle">Chart</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <div id="adminChartExpandHost" class="dental-chart-expand-host"></div>
            </div>
        </div>
    </div>
</div>

<script src="<?= asset_url('../assets/js/admin-charts.js') ?>"></script>
<script>
(function () {
    var reportType = <?php echo json_encode($reportType); ?>;
    var dailyLabels = <?php echo json_encode($dailyChartLabels); ?>;
    var dailyBooked = <?php echo json_encode($dailyBookedValues); ?>;
    var dailyCompleted = <?php echo json_encode($dailyCompletedValues); ?>;
    var statusLabels = <?php echo json_encode($chartStatusLabels); ?>;
    var statusValues = <?php echo json_encode($chartStatusValues); ?>;
    var serviceLabels = <?php echo json_encode($chartServiceLabels); ?>;
    var serviceValues = <?php echo json_encode($chartServiceValues); ?>;
    var dailyRevLabels = <?php echo json_encode($dailyRevLabels); ?>;
    var dailyRevValues = <?php echo json_encode($dailyRevValues); ?>;
    var payMethodLabels = <?php echo json_encode($payMethodLabels); ?>;
    var payMethodValues = <?php echo json_encode($payMethodValues); ?>;
    var svcRevLabels = <?php echo json_encode($svcRevLabels); ?>;
    var svcRevValues = <?php echo json_encode($svcRevValues); ?>;
    var ageLabels = <?php echo json_encode($ageLabels); ?>;
    var ageValues = <?php echo json_encode($ageValues); ?>;
    var genderLabels = <?php echo json_encode($genderLabels); ?>;
    var genderValues = <?php echo json_encode($genderValues); ?>;
    var peakDayLabels = <?php echo json_encode($peakDayLabels); ?>;
    var peakDayValues = <?php echo json_encode($peakDayValues); ?>;
    var peakHourLabels = <?php echo json_encode($peakHourLabels); ?>;
    var peakHourValues = <?php echo json_encode($peakHourValues); ?>;
    var trendData = <?php echo json_encode([
        'labels' => $trend['labels'],
        'booked' => $trend['booked'],
        'visits' => $trend['visits'],
        'cancelled' => $trend['cancelled'],
        'no_show' => $trend['no_show'],
        'rescheduled' => $trend['rescheduled'],
        'new_patients' => $trend['new_patients'],
        'returning_patients' => $trend['returning_patients'],
        'forecast' => $trend['forecast'],
        'nextLabel' => $trend['next_short'],
        'serviceLabels' => $trend['service_labels'],
        'serviceSeries' => $trend['service_series'],
    ]); ?>;
    var totalCollected = <?php echo json_encode(round($totalCollected, 2)); ?>;
    var totalOutstanding = <?php echo json_encode(round($totalOutstanding, 2)); ?>;

    var chartColors = ['#0ea5e9', '#6366f1', '#10b981', '#f59e0b', '#ef4444', '#8b5cf6', '#ec4899', '#3a9fc0'];

    function renderCharts() {
        if (typeof AdminCharts === 'undefined' || typeof ApexCharts === 'undefined') return;

        if (reportType === 'sales') {
            renderSalesCharts();
        } else if (reportType === 'demographic') {
            renderDemographicCharts();
        } else if (reportType === 'trend') {
            renderTrendCharts();
        }

        renderCommonCharts();
        AdminCharts.bindExpandButtons();
    }

    function renderSalesCharts() {
        try {
            if (dailyRevLabels.length && dailyRevValues.some(function(v) { return v > 0; })) {
                var el = document.getElementById('salesDailyRevChart');
                if (el) {
                    el.innerHTML = '';
                    var isDark = document.documentElement.getAttribute('data-theme') === 'dark';
                    var opts = {
                        chart: { type: 'area', height: 260, toolbar: { show: false }, fontFamily: 'inherit', foreColor: isDark ? '#94a3b8' : '#64748b' },
                        series: [{ name: 'Revenue', data: dailyRevValues }],
                        xaxis: { categories: dailyRevLabels, labels: { rotate: -45, style: { fontSize: '11px' } }, axisBorder: { show: false }, axisTicks: { show: false } },
                        yaxis: { min: 0, forceNiceScale: true, labels: { formatter: AdminCharts.formatCompactMoney } },
                        colors: ['#10b981'],
                        fill: { type: 'gradient', gradient: { shadeIntensity: 1, opacityFrom: 0.4, opacityTo: 0.1 } },
                        stroke: { curve: 'smooth', width: 2 },
                        dataLabels: { enabled: false },
                        legend: { show: false },
                        grid: { borderColor: isDark ? '#2f3d52' : '#e8eef5', strokeDashArray: 4 },
                        tooltip: { theme: isDark ? 'dark' : 'light', y: { formatter: AdminCharts.formatMoney } }
                    };
                    var chart = new ApexCharts(el, opts);
                    chart.render();
                    AdminCharts._registry = AdminCharts._registry || {};
                    AdminCharts._registry['salesDailyRevChart'] = { options: opts, title: 'Daily Revenue Trend', chart: chart };
                }
            } else {
                AdminCharts.renderNoData('salesDailyRevChart', 'revenue data');
            }
        } catch(e) { console.error('Sales daily rev chart:', e); }

        try {
            if (payMethodLabels.length) {
                AdminCharts.mount('salesPayMethodChart', AdminCharts.buildDonutOptions(payMethodLabels, payMethodValues, { money: true }), 'Payment Methods');
            } else {
                AdminCharts.renderNoData('salesPayMethodChart', 'payment data');
            }
        } catch(e) { console.error('Pay method chart:', e); }

        try {
            if (svcRevLabels.length) {
                AdminCharts.mount('salesSvcRevChart', AdminCharts.buildHorizontalBarOptions(svcRevLabels, svcRevValues, 'Revenue', { money: true }), 'Revenue by Service');
            } else {
                AdminCharts.renderNoData('salesSvcRevChart', 'service revenue');
            }
        } catch(e) { console.error('Svc rev chart:', e); }

        try {
            if (svcRevLabels.length) {
                AdminCharts.mount('salesSvcShareChart', AdminCharts.buildDonutOptions(svcRevLabels, svcRevValues, { money: true }), 'Revenue Share by Service');
            } else {
                AdminCharts.renderNoData('salesSvcShareChart', 'service revenue');
            }
        } catch(e) { console.error('Svc share chart:', e); }
    }

    function renderDemographicCharts() {
        try {
            if (ageLabels.length) {
                AdminCharts.mount('demoAgeChart', AdminCharts.buildHorizontalBarOptions(ageLabels, ageValues, 'Patients'), 'Age Distribution');
            } else {
                AdminCharts.renderNoData('demoAgeChart', 'age data');
            }
        } catch(e) { console.error('Age chart:', e); }

        try {
            if (genderLabels.length) {
                AdminCharts.mount('demoGenderChart', AdminCharts.buildDonutOptions(genderLabels, genderValues), 'Gender Distribution');
            } else {
                AdminCharts.renderNoData('demoGenderChart', 'gender data');
            }
        } catch(e) { console.error('Gender chart:', e); }

        try {
            if (peakDayLabels.length) {
                var el = document.getElementById('demoPeakDaysChart');
                if (el) {
                    el.innerHTML = '';
                    var isDark = document.documentElement.getAttribute('data-theme') === 'dark';
                    var opts = {
                        chart: { type: 'bar', height: 240, toolbar: { show: false }, fontFamily: 'inherit', foreColor: isDark ? '#94a3b8' : '#64748b' },
                        series: [{ name: 'Appointments', data: peakDayValues }],
                        xaxis: { categories: peakDayLabels, labels: { style: { fontSize: '11px' } } },
                        yaxis: AdminCharts.countAxis(peakDayValues),
                        tooltip: { y: { formatter: AdminCharts.formatCount } },
                        colors: ['#6366f1'],
                        plotOptions: { bar: { borderRadius: 6, columnWidth: '60%' } },
                        dataLabels: { enabled: false },
                        legend: { show: false },
                        grid: { borderColor: isDark ? '#2f3d52' : '#e8eef5', strokeDashArray: 4 }
                    };
                    var chart = new ApexCharts(el, opts);
                    chart.render();
                    AdminCharts._registry = AdminCharts._registry || {};
                    AdminCharts._registry['demoPeakDaysChart'] = { options: opts, title: 'Peak Booking Days', chart: chart };
                }
            } else {
                AdminCharts.renderNoData('demoPeakDaysChart', 'day data');
            }
        } catch(e) { console.error('Peak days chart:', e); }

        try {
            if (peakHourLabels.length) {
                var el = document.getElementById('demoPeakHoursChart');
                if (el) {
                    el.innerHTML = '';
                    var isDark = document.documentElement.getAttribute('data-theme') === 'dark';
                    var opts = {
                        chart: { type: 'bar', height: 240, toolbar: { show: false }, fontFamily: 'inherit', foreColor: isDark ? '#94a3b8' : '#64748b' },
                        series: [{ name: 'Appointments', data: peakHourValues }],
                        xaxis: { categories: peakHourLabels, labels: { style: { fontSize: '11px' } } },
                        yaxis: AdminCharts.countAxis(peakHourValues),
                        tooltip: { y: { formatter: AdminCharts.formatCount } },
                        colors: ['#f59e0b'],
                        plotOptions: { bar: { borderRadius: 6, columnWidth: '60%' } },
                        dataLabels: { enabled: false },
                        legend: { show: false },
                        grid: { borderColor: isDark ? '#2f3d52' : '#e8eef5', strokeDashArray: 4 }
                    };
                    var chart = new ApexCharts(el, opts);
                    chart.render();
                    AdminCharts._registry = AdminCharts._registry || {};
                    AdminCharts._registry['demoPeakHoursChart'] = { options: opts, title: 'Peak Booking Hours', chart: chart };
                }
            } else {
                AdminCharts.renderNoData('demoPeakHoursChart', 'hour data');
            }
        } catch(e) { console.error('Peak hours chart:', e); }
    }

    function renderTrendCharts() {
        var isDark = document.documentElement.getAttribute('data-theme') === 'dark';
        // Palettes validated for colour-blind separation on light and dark surfaces
        // (dataviz validate_palette.js). Outcome colours are status colours: kept visits
        // use the series blue, cancelled = serious (orange), no-show = critical (red).
        var P = isDark
            ? { blue: '#3987e5', aqua: '#199e70', magenta: '#d55181', violet: '#9085e9' }
            : { blue: '#2a78d6', aqua: '#1baf7a', magenta: '#e87ba4', violet: '#4a3aa7' };
        var STATUS = { serious: '#ec835a', critical: '#d03b3b' };
        var ink = isDark ? '#94a3b8' : '#64748b';
        var legendInk = isDark ? '#cbd5e1' : '#475569';
        var gridColor = isDark ? '#2f3d52' : '#e8eef5';
        var intLabel = AdminCharts.formatCount;
        function surfaceOf(el) {
            var card = el.closest('.card');
            var bg = card ? getComputedStyle(card).backgroundColor : '';
            return bg && bg !== 'rgba(0, 0, 0, 0)' ? bg : (isDark ? '#1e293b' : '#ffffff');
        }
        function base(type, height) {
            return {
                chart: { type: type, height: height, toolbar: { show: false }, fontFamily: 'inherit', foreColor: ink },
                dataLabels: { enabled: false },
                legend: { position: 'top', horizontalAlign: 'left', fontSize: '12px', labels: { colors: legendInk } },
                grid: { borderColor: gridColor, strokeDashArray: 4 },
                tooltip: { shared: true, intersect: false, y: { formatter: intLabel } }
            };
        }
        function mount(id, title, opts) {
            var el = document.getElementById(id);
            if (!el) return;
            el.innerHTML = '';
            var chart = new ApexCharts(el, opts);
            chart.render();
            AdminCharts._registry = AdminCharts._registry || {};
            AdminCharts._registry[id] = { options: opts, title: title, chart: chart };
        }
        var anyData = function (arr) { return arr.some(function (v) { return v > 0; }); };

        // 1. Monthly appointments + dashed forecast point for next month.
        try {
            if (trendData.labels.length && anyData(trendData.booked)) {
                var n = trendData.booked.length;
                var cats = trendData.labels.concat(trendData.forecast ? [trendData.nextLabel + ' (forecast)'] : []);
                var series = [
                    { name: 'Booked', data: trendData.booked.concat(trendData.forecast ? [null] : []) },
                    { name: 'Completed visits', data: trendData.visits.concat(trendData.forecast ? [null] : []) }
                ];
                var colors = [P.blue, P.aqua];
                var widths = [2, 2], dashes = [0, 0];
                if (trendData.forecast) {
                    var fc = new Array(n + 1).fill(null);
                    fc[n - 1] = trendData.booked[n - 1];
                    fc[n] = trendData.forecast.value;
                    series.push({ name: 'Forecast', data: fc });
                    colors.push(P.blue); widths.push(2); dashes.push(6);
                }
                var o = base('line', 300);
                o.series = series;
                o.yaxis = AdminCharts.countAxis(series);
                o.colors = colors;
                o.stroke = { curve: 'straight', width: widths, dashArray: dashes };
                o.markers = { size: 4, strokeWidth: 2, strokeColors: surfaceOf(document.getElementById('trendMonthlyChart')), hover: { size: 6 } };
                o.xaxis = { categories: cats, labels: { rotate: -45, style: { fontSize: '11px' } } };
                o.tooltip.y = {
                    formatter: function (v, ctx) {
                        if (v == null) return '';
                        if (ctx && ctx.seriesIndex === 2 && ctx.dataPointIndex === n && trendData.forecast) {
                            return Math.round(v) + ' (likely ' + trendData.forecast.low + '–' + trendData.forecast.high + ')';
                        }
                        return intLabel(v);
                    }
                };
                mount('trendMonthlyChart', 'Monthly appointments & forecast', o);
            } else {
                AdminCharts.renderNoData('trendMonthlyChart', 'monthly appointments');
            }
        } catch (e) { console.error('Monthly trend chart:', e); }

        // 2. Outcomes per month (stacked). Rescheduled shown in the tooltip only: those
        // appointments were replaced by a new booking, not lost.
        try {
            if (anyData(trendData.visits) || anyData(trendData.cancelled) || anyData(trendData.no_show)) {
                var el2 = document.getElementById('trendOutcomeChart');
                var o2 = base('bar', 300);
                o2.chart.stacked = true;
                o2.series = [
                    { name: 'Completed', data: trendData.visits },
                    { name: 'Cancelled', data: trendData.cancelled },
                    { name: 'No-show', data: trendData.no_show }
                ];
                o2.yaxis = AdminCharts.countAxis(o2.series, { stacked: true });
                o2.colors = [P.blue, STATUS.serious, STATUS.critical];
                o2.plotOptions = { bar: { columnWidth: '55%', borderRadius: 4, borderRadiusApplication: 'end', borderRadiusWhenStacked: 'last' } };
                o2.stroke = { show: true, width: 2, colors: [surfaceOf(el2)] };
                o2.xaxis = { categories: trendData.labels, labels: { rotate: -45, style: { fontSize: '11px' } } };
                o2.tooltip = {
                    shared: true, intersect: false,
                    custom: function (opts) {
                        var i = opts.dataPointIndex;
                        var rows = [
                            ['Completed', trendData.visits[i], P.blue],
                            ['Cancelled', trendData.cancelled[i], STATUS.serious],
                            ['No-show', trendData.no_show[i], STATUS.critical]
                        ].map(function (r) {
                            return '<div style="display:flex;align-items:center;gap:6px;"><span style="width:10px;height:10px;border-radius:2px;background:' + r[2] + '"></span>' + r[0] + ': <strong>' + r[1] + '</strong></div>';
                        }).join('');
                        return '<div style="padding:8px 10px;font-size:12px;">' +
                            '<div style="font-weight:600;margin-bottom:4px;">' + trendData.labels[i] + '</div>' + rows +
                            '<div style="color:' + ink + ';margin-top:4px;">Rescheduled: ' + trendData.rescheduled[i] + '</div></div>';
                    }
                };
                mount('trendOutcomeChart', 'Completed, cancelled & no-show', o2);
            } else {
                AdminCharts.renderNoData('trendOutcomeChart', 'appointment outcomes');
            }
        } catch (e) { console.error('Outcome chart:', e); }

        // 3. New vs returning patients (stacked).
        try {
            if (anyData(trendData.new_patients) || anyData(trendData.returning_patients)) {
                var el3 = document.getElementById('trendPatientsChart');
                var o3 = base('bar', 300);
                o3.chart.stacked = true;
                o3.series = [
                    { name: 'New', data: trendData.new_patients },
                    { name: 'Returning', data: trendData.returning_patients }
                ];
                o3.yaxis = AdminCharts.countAxis(o3.series, { stacked: true });
                o3.colors = [P.blue, P.magenta];
                o3.plotOptions = { bar: { columnWidth: '55%', borderRadius: 4, borderRadiusApplication: 'end', borderRadiusWhenStacked: 'last' } };
                o3.stroke = { show: true, width: 2, colors: [surfaceOf(el3)] };
                o3.xaxis = { categories: trendData.labels, labels: { rotate: -45, style: { fontSize: '11px' } } };
                mount('trendPatientsChart', 'New vs returning patients', o3);
            } else {
                AdminCharts.renderNoData('trendPatientsChart', 'patient visits');
            }
        } catch (e) { console.error('Patients chart:', e); }

        // 4. Top services by month.
        try {
            if (trendData.serviceLabels.length && trendData.serviceSeries.length) {
                var o4 = base('line', 300);
                o4.series = trendData.serviceSeries.map(function (s) { return { name: s.name, data: s.data }; });
                o4.yaxis = AdminCharts.countAxis(o4.series);
                o4.colors = [P.blue, P.magenta, P.violet, P.aqua];
                o4.stroke = { curve: 'straight', width: 2 };
                o4.markers = { size: 4, strokeWidth: 2, strokeColors: surfaceOf(document.getElementById('trendSvcChart')), hover: { size: 6 } };
                o4.xaxis = { categories: trendData.serviceLabels, labels: { style: { fontSize: '11px' } } };
                mount('trendSvcChart', 'Top services by month (6 months)', o4);
            } else {
                AdminCharts.renderNoData('trendSvcChart', 'service bookings');
            }
        } catch (e) { console.error('Service trend chart:', e); }
    }

    function renderCommonCharts() {
        try {
            if (dailyLabels.length && (dailyBooked.some(function(v) { return v > 0; }) || dailyCompleted.some(function(v) { return v > 0; }))) {
                var el = document.getElementById('reportDailyChart');
                if (el) {
                    el.innerHTML = '';
                    var isDark = document.documentElement.getAttribute('data-theme') === 'dark';
                    var opts = {
                        chart: { type: 'area', height: 240, toolbar: { show: false }, fontFamily: 'inherit', foreColor: isDark ? '#94a3b8' : '#64748b' },
                        series: [
                            { name: 'Booked', data: dailyBooked },
                            { name: 'Completed', data: dailyCompleted }
                        ],
                        xaxis: { categories: dailyLabels, labels: { rotate: -45, style: { fontSize: '11px' } }, axisBorder: { show: false }, axisTicks: { show: false } },
                        yaxis: AdminCharts.countAxis([{ data: dailyBooked }, { data: dailyCompleted }]),
                        colors: ['#94a3b8', '#1b7aa3'],
                        fill: { type: 'gradient', gradient: { shadeIntensity: 1, opacityFrom: 0.35, opacityTo: 0.05 } },
                        stroke: { curve: 'smooth', width: 3 },
                        markers: { size: 4, strokeWidth: 0, hover: { size: 6 } },
                        dataLabels: { enabled: false },
                        legend: { position: 'top', horizontalAlign: 'left', fontSize: '12px', labels: { colors: isDark ? '#cbd5e1' : '#475569' } },
                        grid: { borderColor: isDark ? '#2f3d52' : '#e8eef5', strokeDashArray: 4 },
                        tooltip: { shared: true, intersect: false, y: { formatter: AdminCharts.formatCount } }
                    };
                    var chart = new ApexCharts(el, opts);
                    chart.render();
                    AdminCharts._registry = AdminCharts._registry || {};
                    AdminCharts._registry['reportDailyChart'] = { options: opts, title: 'Appointments Overview', chart: chart };
                }
            } else if (document.getElementById('reportDailyChart')) {
                AdminCharts.renderNoData('reportDailyChart', 'visit data');
            }
        } catch(e) { console.error('Daily chart:', e); }

        try {
            if (statusLabels.length) {
                AdminCharts.mount('reportStatusChart', AdminCharts.buildDonutOptions(statusLabels, statusValues), 'Appointment Outcomes');
            } else if (document.getElementById('reportStatusChart')) {
                AdminCharts.renderNoData('reportStatusChart', 'appointment outcomes');
            }
        } catch(e) { console.error('Status chart:', e); }

        try {
            if (serviceLabels.length) {
                AdminCharts.mount('reportServicesChart', AdminCharts.buildHorizontalBarOptions(serviceLabels, serviceValues, 'Bookings'), 'Top Services');
            } else if (document.getElementById('reportServicesChart')) {
                AdminCharts.renderNoData('reportServicesChart', 'service bookings');
            }
        } catch(e) { console.error('Services chart:', e); }
    }

    var rangeSelect = document.getElementById('reportRangeSelect');
    if (rangeSelect) {
        rangeSelect.addEventListener('change', function() {
            var customDates = document.querySelectorAll('.report-custom-date');
            customDates.forEach(function(el) {
                el.style.display = rangeSelect.value === 'custom' ? '' : 'none';
            });
        });
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', renderCharts);
    } else {
        renderCharts();
    }
})();
</script>

<style>
.clinic-reports-filter {
    background: #f8fafc;
    border-radius: 12px;
}
.clinic-reports-filter .card-body {
    padding: 0.85rem 1rem;
}
.clinic-reports-filter .form-label {
    color: #64748b;
    font-weight: 600;
}
.clinic-reports-filter .form-select,
.clinic-reports-filter .form-control {
    border-radius: 0.75rem;
    border: 1px solid #e2e8f0;
    font-size: 13px;
}
.clinic-reports-filter .form-select:focus,
.clinic-reports-filter .form-control:focus {
    border-color: #1b7aa3;
    background-color: #fff;
    box-shadow: none;
}
.dental-kpi-card {
    border-radius: 14px;
    border: 1px solid #e9eef2;
    background: #fff;
    box-shadow: 0 1px 2px rgba(15, 23, 42, 0.04);
    transition: box-shadow 0.18s ease, transform 0.18s ease;
}
.dental-kpi-card:hover {
    box-shadow: 0 8px 20px rgba(15, 23, 42, 0.07);
    transform: translateY(-1px);
}
.dental-kpi-icon {
    width: 38px;
    height: 38px;
    border-radius: 10px;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    font-size: 1.05rem;
    margin-bottom: 10px;
}
.dental-kpi-icon--teal { background: var(--primary-soft, #eff8fb); color: var(--primary-color, #1b7aa3); }
.dental-kpi-icon--green { background: #dcfce7; color: #16a34a; }
.dental-kpi-icon--red { background: #fee2e2; color: #dc3545; }
.dental-kpi-icon--amber { background: #fef3c7; color: #b45309; }
.dental-kpi-label {
    font-size: 0.72rem;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: 0.04em;
    color: #94a3b8;
    margin-bottom: 3px;
}
.dental-kpi-value {
    font-size: 1.7rem;
    font-weight: 800;
    color: #0f172a;
    line-height: 1.2;
}
.dental-kpi-value small {
    font-size: 0.85rem;
    font-weight: 600;
    color: #94a3b8;
}
/* Phones: stat cards sit 2-per-row (col-6), so shrink the big figures to fit
   a half-width card, and let an odd card left alone at the end span the row. */
@media (max-width: 767.98px) {
    .row > .col-6:last-child:nth-child(odd):has(> .dental-kpi-card) {
        flex: 0 0 100%;
        max-width: 100%;
    }
    .dental-kpi-card.p-3 {
        padding: 0.8rem !important;
    }
    .dental-kpi-value {
        font-size: 1.2rem;
        overflow-wrap: anywhere;
    }
}
.dental-section-eyebrow {
    font-size: 0.72rem;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: 0.06em;
    color: #94a3b8;
    margin: 0 0 0.6rem 2px;
}
.dental-chart-card {
    border-radius: 12px;
    border: 1px solid #e2e8f0;
}
.dental-chart-card__head {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 12px;
}
.dental-chart-card__head h5 {
    font-size: 14px;
    font-weight: 600;
    color: #1e293b;
}
.dental-chart-expand-btn {
    background: none;
    border: 1px solid #e2e8f0;
    border-radius: 6px;
    padding: 4px 8px;
    color: #64748b;
    cursor: pointer;
}
.dental-chart-frame {
    min-height: 240px;
}
.clinic-report-bar-item {
    margin-bottom: 12px;
}
.clinic-report-bar-track {
    height: 8px;
    background: #e2e8f0;
    border-radius: 4px;
    overflow: hidden;
}
.clinic-report-bar-fill {
    height: 100%;
    background: var(--primary-color);
    border-radius: 4px;
    transition: width 0.6s ease;
}
.clinic-report-bar-fill.is-service {
    background: linear-gradient(90deg, #6366f1, #8b5cf6);
}
.badge {
    font-weight: 500;
    font-size: 11px;
    padding: 4px 8px;
    border-radius: 6px;
}
.table th {
    font-size: 12px;
    font-weight: 600;
    color: #64748b;
    text-transform: uppercase;
    letter-spacing: 0.5px;
    border-bottom: 2px solid #e2e8f0;
}
.table td {
    font-size: 13px;
    vertical-align: middle;
}
.dental-chart-print-table { display: none; }
.clinic-report-print-meta { display: none; }

/* Compact, document-style print output — no cards/icons/shadows, dense tables,
   everything scoped to the report area so on-screen styling is untouched. */
@media print {
    #clinicReportPrintArea.clinic-reports-shell {
        padding: 0;
        font-size: 11px;
        color: #000;
    }
    .clinic-report-no-print { display: none !important; }

    #clinicReportPrintArea .module-page-header h4 { font-size: 15px; margin-bottom: 2px; }
    #clinicReportPrintArea .dental-chip {
        font-size: 10px;
        padding: 1px 6px;
        border: 1px solid #94a3b8;
        background: none !important;
        color: #000;
    }

    #clinicReportPrintArea .clinic-report-print-meta {
        display: block;
        font-size: 10px;
        color: #334155;
        margin: 0 0 8px;
    }

    /* Flatten every card: no border/shadow/radius, tight spacing. */
    #clinicReportPrintArea .card {
        border: none !important;
        box-shadow: none !important;
        border-radius: 0 !important;
        break-inside: avoid;
        margin-bottom: 4px !important;
    }
    #clinicReportPrintArea .card-body { padding: 2px 0 !important; }
    #clinicReportPrintArea .row { row-gap: 0 !important; }
    #clinicReportPrintArea .row > [class*="col-"] { padding: 1px 4px !important; }

    /* Force any row of KPI tiles into a fixed 4-per-row grid (ignore the
       screen breakpoint classes) so tiles are sized to the row, not stretched
       full-width with mostly-empty space. Tables are untouched — full width. */
    #clinicReportPrintArea .row:has(> [class*="col-"] > .dental-kpi-card) {
        display: flex !important;
        flex-wrap: wrap !important;
    }
    #clinicReportPrintArea .row:has(> [class*="col-"] > .dental-kpi-card) > [class*="col-"] {
        flex: 0 0 25% !important;
        max-width: 25% !important;
    }

    /* KPI cards become one-line "Label: Value" strips instead of padded tiles. */
    #clinicReportPrintArea .dental-kpi-icon { display: none !important; }
    #clinicReportPrintArea .dental-kpi-card {
        display: flex;
        align-items: baseline;
        gap: 4px;
        border: 1px solid #cbd5e1 !important;
        padding: 2px 6px !important;
        width: 100%;
        box-sizing: border-box;
    }
    #clinicReportPrintArea .dental-kpi-label {
        font-size: 9px;
        margin-bottom: 0;
        color: #475569;
        white-space: nowrap;
    }
    #clinicReportPrintArea .dental-kpi-label::after { content: ":"; }
    #clinicReportPrintArea .dental-kpi-value { font-size: 12px; line-height: 1.1; color: #000; }
    #clinicReportPrintArea .dental-kpi-value small { font-size: 9px; }

    #clinicReportPrintArea .dental-section-eyebrow {
        font-size: 9.5px;
        margin: 8px 0 3px;
        color: #000;
        border-bottom: 1px solid #94a3b8;
        padding-bottom: 2px;
    }

    /* Charts are replaced by their data table (see admin-charts.js renderPrintTables). */
    #clinicReportPrintArea .dental-chart-expand-btn,
    #clinicReportPrintArea .clinic-simple-chart { display: none !important; }
    #clinicReportPrintArea .dental-chart-card__head { margin-bottom: 2px; }
    #clinicReportPrintArea .dental-chart-card__head h5,
    #clinicReportPrintArea .card-title { font-size: 11px; font-weight: 700; }
    #clinicReportPrintArea .dental-chart-print-table { display: block !important; }
    #clinicReportPrintArea .dental-chart-print-table table { font-size: 9.5px; margin-bottom: 0; }

    /* A card holding a long table (many rows) must NOT be forced to stay in
       one block — that clips it when it's taller than a page. Let it flow
       across pages instead; only individual rows/table headers stay intact. */
    #clinicReportPrintArea .card:has(table) { break-inside: auto !important; }
    #clinicReportPrintArea table { break-inside: auto; }
    #clinicReportPrintArea tr,
    #clinicReportPrintArea .dental-kpi-card { break-inside: avoid; }
    #clinicReportPrintArea thead { display: table-header-group; }

    /* Dense, direct-to-read data tables. */
    #clinicReportPrintArea .table-responsive { overflow: visible !important; }
    #clinicReportPrintArea .table { font-size: 10px; margin-bottom: 0; }
    #clinicReportPrintArea .table th,
    #clinicReportPrintArea .table td { padding: 3px 5px !important; }
    #clinicReportPrintArea .table th {
        font-size: 9px;
        border-bottom: 1px solid #000;
    }
    #clinicReportPrintArea .badge {
        border: 1px solid #94a3b8;
        background: none !important;
        color: #000 !important;
        padding: 1px 5px;
        font-size: 9px;
    }
}</style>

<div class="ai-chat-wrapper" id="aiChatWrapper">
    <button type="button" class="ai-chat-fab" id="aiChatFab" title="AI Analytics Assistant">
        <i class="bi bi-robot"></i>
    </button>

    <div class="ai-chat-panel" id="aiChatPanel">
        <div class="ai-chat-header">
            <div class="ai-chat-header-left">
                <div class="ai-chat-header-icon"><i class="bi bi-robot"></i></div>
                <div class="ai-chat-header-text">
                    <h6>Analytics Assistant</h6>
                    <span class="ai-chat-subtitle"><span class="ai-chat-status-dot"></span><span id="aiProviderLabel">Powered by Groq AI</span></span>
                </div>
            </div>
            <button type="button" class="ai-chat-close" id="aiChatClose">&times;</button>
        </div>
        <div class="ai-chat-messages" id="aiChatMessages">
            <div class="ai-chat-msg bot">
                Hi! I'm your clinic analytics assistant. Ask me anything about your data, for example:
                <br><br>
                <em>"Top services this month"</em><br>
                <em>"How many appointments today?"</em><br>
                <em>"Total payments made today"</em><br>
                <em>"Which doctor is busiest?"</em><br>
                <em>"Patient age demographics"</em><br>
                <em>"Outstanding balance"</em>
            </div>
        </div>
        <div class="ai-chat-input-wrap">
            <textarea class="ai-chat-input" id="aiChatInput" rows="1" placeholder="Ask about your clinic data..."></textarea>
            <button type="button" class="ai-chat-send" id="aiChatSend"><i class="bi bi-send-fill"></i></button>
        </div>
    </div>
</div>

<style>
.ai-chat-wrapper {
    position: fixed;
    bottom: 24px;
    right: 24px;
    z-index: 1050;
    width: 360px;
    max-width: calc(100vw - 40px);
    display: flex;
    flex-direction: column;
    align-items: flex-end;
}
.ai-chat-fab {
    width: 54px;
    height: 54px;
    border-radius: 16px;
    background: linear-gradient(145deg, #1b7aa3, #17698c);
    color: #fff;
    border: none;
    box-shadow: 0 4px 18px rgba(27, 122, 163,0.38), 0 2px 6px rgba(0,0,0,0.12);
    cursor: pointer;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 22px;
    transition: transform 0.3s cubic-bezier(0.34,1.5,0.64,1), opacity 0.22s ease;
    position: relative;
    z-index: 1;
}
.ai-chat-fab.hidden {
    opacity: 0;
    transform: scale(0);
    pointer-events: none;
}
.ai-chat-panel {
    position: absolute;
    bottom: 0;
    right: 0;
    width: 100%;
    height: 460px;
    background: #fff;
    border-radius: 18px;
    box-shadow: 0 16px 48px rgba(15,23,42,0.18), 0 4px 14px rgba(15,23,42,0.08);
    border: 1px solid #e2e8f0;
    overflow: hidden;
    display: flex;
    flex-direction: column;
    transform: scale(0.06);
    transform-origin: bottom right;
    opacity: 0;
    pointer-events: none;
    transition: transform 0.38s cubic-bezier(0.34, 1.4, 0.64, 1), opacity 0.22s ease;
}
.ai-chat-panel.open {
    transform: scale(1);
    opacity: 1;
    pointer-events: all;
}
.ai-chat-header {
    background: #fff;
    border-bottom: 1.5px solid #f0f4f8;
    padding: 13px 16px;
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 10px;
    flex-shrink: 0;
}
.ai-chat-header-left {
    display: flex;
    align-items: center;
    gap: 11px;
    min-width: 0;
}
.ai-chat-header-icon {
    width: 38px;
    height: 38px;
    border-radius: 11px;
    background: linear-gradient(135deg, #1b7aa3 0%, #17698c 100%);
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 17px;
    flex-shrink: 0;
    color: #fff;
    box-shadow: 0 3px 10px rgba(27, 122, 163,0.28);
}
.ai-chat-header-text h6 {
    margin: 0 0 2px;
    font-size: 14px;
    font-weight: 700;
    color: #0f172a;
    line-height: 1.25;
}
.ai-chat-subtitle {
    font-size: 11px;
    color: #64748b;
    line-height: 1.2;
    display: flex;
    align-items: center;
    gap: 5px;
}
.ai-chat-status-dot {
    width: 7px;
    height: 7px;
    border-radius: 50%;
    background: #10b981;
    flex-shrink: 0;
    box-shadow: 0 0 0 2px rgba(16,185,129,0.2);
}
.ai-chat-close {
    width: 30px;
    height: 30px;
    border-radius: 8px;
    background: #f1f5f9;
    border: none;
    color: #64748b;
    font-size: 18px;
    cursor: pointer;
    display: flex;
    align-items: center;
    justify-content: center;
    transition: background 0.18s, color 0.18s;
    flex-shrink: 0;
    line-height: 1;
}
.ai-chat-close:hover { background: #e2e8f0; color: #1e293b; }
.ai-chat-messages {
    flex: 1;
    min-height: 0;
    overflow-y: auto;
    padding: 14px;
    display: flex;
    flex-direction: column;
    gap: 10px;
    background: #f8fafc;
}
.ai-chat-msg {
    max-width: 88%;
    padding: 10px 14px;
    border-radius: 14px;
    font-size: 13px;
    line-height: 1.55;
    word-break: break-word;
}
.ai-chat-msg.user {
    align-self: flex-end;
    background: linear-gradient(135deg, #1b7aa3, #17698c);
    color: #fff;
    border-bottom-right-radius: 4px;
    box-shadow: 0 2px 8px rgba(27, 122, 163,0.22);
}
.ai-chat-msg.bot {
    align-self: flex-start;
    background: #fff;
    color: #1e293b;
    border: 1px solid #e8ecf1;
    border-bottom-left-radius: 4px;
    box-shadow: 0 1px 4px rgba(0,0,0,0.05);
}
.ai-chat-msg.bot table {
    width: 100%;
    border-collapse: collapse;
    margin-top: 8px;
    font-size: 12px;
}
.ai-chat-msg.bot th, .ai-chat-msg.bot td {
    padding: 5px 8px;
    border: 1px solid #e8ecf1;
    text-align: left;
}
.ai-chat-msg.bot th { background: #f1f5f9; font-weight: 600; color: #334155; }
.ai-chat-msg.error {
    align-self: flex-start;
    background: #fff7ed;
    color: #9a3412;
    border: 1px solid #fed7aa;
    border-bottom-left-radius: 4px;
    font-size: 13px;
}
.ai-chat-typing {
    display: flex;
    gap: 4px;
    padding: 12px 14px;
}
.ai-chat-typing span {
    width: 7px;
    height: 7px;
    border-radius: 50%;
    background: #94a3b8;
    animation: aiTyping 1.2s infinite ease-in-out;
}
.ai-chat-typing span:nth-child(2) { animation-delay: 0.15s; }
.ai-chat-typing span:nth-child(3) { animation-delay: 0.3s; }
@keyframes aiTyping {
    0%,60%,100% { transform: translateY(0); opacity: 0.4; }
    30% { transform: translateY(-5px); opacity: 1; }
}
.ai-chat-input-wrap {
    padding: 10px 12px;
    border-top: 1.5px solid #f0f4f8;
    display: flex;
    gap: 8px;
    align-items: flex-end;
    background: #fff;
    flex-shrink: 0;
}
.ai-chat-input {
    flex: 1;
    border: 1px solid #e2e8f0;
    border-radius: 10px;
    padding: 9px 12px;
    font-size: 13px;
    outline: none;
    resize: none;
    font-family: inherit;
    min-height: 38px;
    max-height: 70px;
    line-height: 1.4;
    transition: border-color 0.2s, box-shadow 0.2s;
    color: #1e293b;
    background: #f8fafc;
}
.ai-chat-input:focus {
    border-color: #1b7aa3;
    background: #fff;
    box-shadow: none;
}
.ai-chat-send {
    width: 38px;
    height: 38px;
    border-radius: 10px;
    background: linear-gradient(135deg, #1b7aa3, #17698c);
    color: #fff;
    border: none;
    cursor: pointer;
    display: flex;
    align-items: center;
    justify-content: center;
    flex-shrink: 0;
    transition: opacity 0.2s, transform 0.2s;
    font-size: 15px;
    box-shadow: 0 2px 8px rgba(27, 122, 163,0.25);
}
.ai-chat-send:hover { opacity: 0.88; transform: scale(1.06); }
.ai-chat-send:disabled { opacity: 0.35; cursor: not-allowed; transform: none; }
.ai-metric {
    font-size: 28px;
    font-weight: 800;
    color: #1b7aa3;
    line-height: 1.1;
    margin-bottom: 4px;
}
.ai-kv-list, .ai-row-list {
    margin: 0 0 6px;
    padding: 0;
    list-style: none;
}
.ai-kv-list li {
    display: flex;
    justify-content: space-between;
    align-items: baseline;
    gap: 8px;
    padding: 5px 0;
    border-bottom: 1px solid #f0f4f8;
    font-size: 12.5px;
}
.ai-kv-list li:last-child { border-bottom: none; }
.ai-kv-label { color: #64748b; font-size: 11.5px; }
.ai-kv-val { font-weight: 600; color: #1e293b; }
.ai-row-list { padding-left: 0; }
.ai-row-list li {
    font-size: 12.5px;
    color: #1e293b;
    padding: 5px 0;
    border-bottom: 1px solid #f0f4f8;
    list-style: none;
}
.ai-row-list li::before {
    content: counter(ai-row) ".";
    counter-increment: ai-row;
    color: #94a3b8;
    margin-right: 5px;
    font-size: 11px;
}
.ai-row-list { counter-reset: ai-row; }
.ai-row-list li:last-child { border-bottom: none; }
.ai-dot { color: #cbd5e1; margin: 0 2px; }
.ai-more { color: #94a3b8; font-style: italic; font-size: 11.5px; }
.ai-answer-text {
    margin: 6px 0 0;
    font-size: 12.5px;
    color: #475569;
    line-height: 1.55;
}
</style>

<script>
(function () {
    var fab = document.getElementById('aiChatFab');
    var panel = document.getElementById('aiChatPanel');
    var closeBtn = document.getElementById('aiChatClose');
    var messages = document.getElementById('aiChatMessages');
    var input = document.getElementById('aiChatInput');
    var sendBtn = document.getElementById('aiChatSend');
    var sending = false;

    if (!fab || !panel) return;

    fab.addEventListener('click', function () {
        panel.classList.add('open');
        fab.classList.add('hidden');
        setTimeout(function () { input.focus(); }, 350);
    });

    closeBtn.addEventListener('click', function () {
        panel.classList.remove('open');
        fab.classList.remove('hidden');
    });

    input.addEventListener('keydown', function (e) {
        if (e.key === 'Enter' && !e.shiftKey) {
            e.preventDefault();
            sendMessage();
        }
    });

    input.addEventListener('input', function () {
        input.style.height = 'auto';
        input.style.height = Math.min(input.scrollHeight, 80) + 'px';
    });

    sendBtn.addEventListener('click', sendMessage);

    function addMessage(text, type) {
        var div = document.createElement('div');
        div.className = 'ai-chat-msg ' + type;
        if (type === 'bot') {
            div.innerHTML = text;
        } else {
            div.textContent = text;
        }
        messages.appendChild(div);
        messages.scrollTop = messages.scrollHeight;
        return div;
    }

    function addTyping() {
        var div = document.createElement('div');
        div.className = 'ai-chat-msg bot';
        div.id = 'aiChatTyping';
        div.innerHTML = '<div class="ai-chat-typing"><span></span><span></span><span></span></div>';
        messages.appendChild(div);
        messages.scrollTop = messages.scrollHeight;
        return div;
    }

    function removeTyping() {
        var el = document.getElementById('aiChatTyping');
        if (el) el.remove();
    }

    function escHtml(s) {
        var d = document.createElement('div');
        d.textContent = s;
        return d.innerHTML;
    }

    function renderResponse(data) {
        var rows = data.rows || [];
        var answer = data.answer || '';
        var html = '';
        
        // Update AI provider label dynamically
        var providerLabel = document.getElementById('aiProviderLabel');
        if (providerLabel && data.provider) {
            var providerNames = {
                'gemma': 'Gemma 2B (Ollama)',
                'groq': 'Groq AI',
                'unknown': 'AI Assistant'
            };
            var label = providerNames[data.provider] || (data.provider.charAt(0).toUpperCase() + data.provider.slice(1) + ' AI');
            if (data.fallback_used) {
                label += ' (via ' + (data.fallback_provider || 'fallback') + ')';
            }
            providerLabel.textContent = 'Powered by ' + label;
        }

        if (answer) {
            // Escaped first, so the only markup is the <strong>/<br> added here.
            html += '<p class="ai-answer-text">' + escHtml(answer).replace(/\*\*(.+?)\*\*/g, '<strong>$1</strong>').replace(/\n/g, '<br>') + '</p>';
        }

        if (rows.length === 0 && !answer) {
            html += '<p class="ai-answer-text"><i class="bi bi-info-circle"></i> No records found for this query.</p>';
            return html;
        }

        if (rows.length === 0) {
            return html;
        }

        if (rows.length === 1 && Object.keys(rows[0]).length <= 2) {
            // skip — value already stated in answer
        } else if (rows.length === 1) {
            html += '<ul class="ai-kv-list">';
            Object.entries(rows[0]).forEach(function (kv) {
                html += '<li><span class="ai-kv-label">' + escHtml(kv[0]) + '</span>'
                      + '<span class="ai-kv-val">' + escHtml(String(kv[1] ?? '—')) + '</span></li>';
            });
            html += '</ul>';
        } else if (rows.length > 1) {
            var keys = Object.keys(rows[0]);
            html += '<ol class="ai-row-list">';
            rows.slice(0, 8).forEach(function (row) {
                var parts = keys.map(function (k) { return escHtml(String(row[k] ?? '')); });
                html += '<li>' + parts.join('<span class="ai-dot"> · </span>') + '</li>';
            });
            if (data.row_count > 8) {
                html += '<li class="ai-more">+' + escHtml(String(data.row_count - 8)) + ' more</li>';
            }
            html += '</ol>';
        }

        return html || escHtml(answer || 'No data found.');
    }

    function sendMessage() {
        var text = input.value.trim();
        if (!text || sending) return;

        sending = true;
        sendBtn.disabled = true;
        addMessage(text, 'user');
        input.value = '';
        input.style.height = 'auto';
        addTyping();

        fetch('admin.php?action=ai_chat', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ message: text })
        })
        .then(function (r) {
            var status = r.status;
            return r.text().then(function (raw) {
                var data;
                try { data = JSON.parse(raw); } catch (e) {
                    throw new Error('The system is currently busy. Please try again in a moment.');
                }
                data.__status = status;
                return data;
            });
        })
        .then(function (data) {
            removeTyping();
            if (data.error) {
                var msg = data.error;
                addMessage(escHtml(msg), 'error');
            } else {
                addMessage(renderResponse(data), 'bot');
            }
            sending = false;
            sendBtn.disabled = false;
            input.focus();
        })
        .catch(function (err) {
            removeTyping();
            addMessage(escHtml('The system is currently busy. Please try again in a moment.'), 'error');
            sending = false;
            sendBtn.disabled = false;
        });
    }
})();
</script>
