<?php
include dirname(dirname(__DIR__)) . '/config/database.php';
include_once dirname(dirname(__DIR__)) . '/config/dashboard_helpers.php';
include_once dirname(dirname(__DIR__)) . '/config/billing_helpers.php';

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

$monthlyTrends = [];
$monthTrendSql = "
    SELECT DATE_FORMAT(a.appointment_date, '%Y-%m') AS month_key,
           DATE_FORMAT(a.appointment_date, '%b %Y') AS month_label,
           COUNT(*) AS total_appointments,
           SUM(CASE WHEN a.status IN ('completed','records_complete') THEN 1 ELSE 0 END) AS completed,
           COUNT(DISTINCT a.patient_id) AS unique_patients
    FROM appointments a
    WHERE a.appointment_date >= DATE_SUB(CURDATE(), INTERVAL 12 MONTH)
    GROUP BY month_key, month_label
    ORDER BY month_key ASC
";
$monthTrendRes = $conn->query($monthTrendSql);
if ($monthTrendRes) {
    while ($row = $monthTrendRes->fetch_assoc()) {
        $monthlyTrends[] = $row;
    }
}

$serviceTrends = [];
$svcTrendSql = "
    SELECT s.service_name,
           DATE_FORMAT(a.appointment_date, '%Y-%m') AS month_key,
           DATE_FORMAT(a.appointment_date, '%b %Y') AS month_label,
           COUNT(*) AS bookings
    FROM selected_services ss
    INNER JOIN appointments a ON a.appointment_id = ss.appointment_id
    INNER JOIN services s ON s.service_id = ss.service_id
    WHERE a.appointment_date >= DATE_SUB(CURDATE(), INTERVAL 6 MONTH)
    GROUP BY s.service_id, s.service_name, month_key, month_label
    ORDER BY month_key ASC, bookings DESC
";
$svcTrendRes = $conn->query($svcTrendSql);
if ($svcTrendRes) {
    while ($row = $svcTrendRes->fetch_assoc()) {
        $serviceTrends[] = $row;
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

$monthlyTrendLabels = [];
$monthlyTrendAppts = [];
$monthlyTrendCompleted = [];
foreach ($monthlyTrends as $row) {
    $monthlyTrendLabels[] = (string)$row['month_label'];
    $monthlyTrendAppts[] = (int)$row['total_appointments'];
    $monthlyTrendCompleted[] = (int)$row['completed'];
}

$serviceTrendSeries = [];
$svcTrendMap = [];
foreach ($serviceTrends as $row) {
    $svcName = (string)$row['service_name'];
    $monthKey = (string)$row['month_key'];
    if (!isset($svcTrendMap[$svcName])) {
        $svcTrendMap[$svcName] = [];
    }
    $svcTrendMap[$svcName][$monthKey] = (int)$row['bookings'];
}
$allTrendMonths = array_unique(array_column($serviceTrends, 'month_key'));
sort($allTrendMonths);
$topTrendServices = array_slice(array_keys($svcTrendMap), 0, 5);
foreach ($topTrendServices as $svcName) {
    $data = [];
    foreach ($allTrendMonths as $m) {
        $data[] = $svcTrendMap[$svcName][$m] ?? 0;
    }
    $serviceTrendSeries[] = ['name' => $svcName, 'data' => $data];
}
$serviceTrendMonthLabels = [];
foreach ($allTrendMonths as $m) {
    $serviceTrendMonthLabels[] = date('M Y', strtotime($m . '-01'));
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

$nextMonthPredicted = 0;
if (count($monthlyTrends) >= 2) {
    $recentMonths = array_slice($monthlyTrends, -3);
    $avgGrowth = 0;
    for ($i = 1; $i < count($recentMonths); $i++) {
        $prev = (int)$recentMonths[$i - 1]['total_appointments'];
        $curr = (int)$recentMonths[$i]['total_appointments'];
        if ($prev > 0) {
            $avgGrowth += ($curr - $prev) / $prev;
        }
    }
    $avgGrowth = $avgGrowth / max(1, count($recentMonths) - 1);
    $lastMonth = (int)end($monthlyTrends)['total_appointments'];
    $nextMonthPredicted = max(0, round($lastMonth * (1 + $avgGrowth)));
}

$topServicesPredicted = [];
if (!empty($serviceTrendSeries)) {
    foreach ($serviceTrendSeries as $series) {
        $data = $series['data'];
        if (count($data) >= 2) {
            $recent = array_slice($data, -2);
            $growth = $recent[0] > 0 ? ($recent[1] - $recent[0]) / $recent[0] : 0;
            $topServicesPredicted[] = [
                'service' => $series['name'],
                'current' => end($data),
                'predicted' => max(0, round(end($data) * (1 + $growth))),
                'growth' => round($growth * 100, 1),
            ];
        }
    }
    usort($topServicesPredicted, fn($a, $b) => $b['predicted'] <=> $a['predicted']);
}

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
    <div class="dental-dashboard-hero">
        <div>
            <h4 class="mb-0">Clinic Reports</h4>
        </div>
        <div class="d-flex flex-wrap align-items-center gap-2">
            <span class="dental-chip"><?php echo htmlspecialchars($periodLabel); ?></span>
            <?php if ($reportType === 'sales'): ?>
            <a href="<?php echo htmlspecialchars($birExportUrl); ?>" class="btn btn-sm btn-success clinic-report-no-print">
                <i class="bi bi-download"></i> Export BIR CSV
            </a>
            <?php endif; ?>
            <button type="button" class="btn btn-sm btn-secondary clinic-report-no-print clinic-report-print-btn" onclick="window.print()">
                <i class="bi bi-printer"></i> Print
            </button>
        </div>
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
        <div class="col-md-6 col-xl-3">
            <div class="card dental-kpi-card p-3 h-100">
                <h6 class="mb-1">Total Billed</h6>
                <div class="display-6 fw-bold">₱<?php echo number_format($totalBilled, 2); ?></div>
            </div>
        </div>
        <div class="col-md-6 col-xl-3">
            <div class="card dental-kpi-card p-3 h-100">
                <h6 class="mb-1">Collected</h6>
                <div class="display-6 fw-bold text-success">₱<?php echo number_format($totalCollected, 2); ?></div>
            </div>
        </div>
        <div class="col-md-6 col-xl-3">
            <div class="card dental-kpi-card p-3 h-100">
                <h6 class="mb-1">Outstanding</h6>
                <div class="display-6 fw-bold <?php echo $totalOutstanding > 0 ? 'text-danger' : ''; ?>">₱<?php echo number_format($totalOutstanding, 2); ?></div>
            </div>
        </div>
        <div class="col-md-6 col-xl-3">
            <div class="card dental-kpi-card p-3 h-100">
                <h6 class="mb-1">Collection Rate</h6>
                <div class="display-6 fw-bold"><?php echo $collectionRate; ?>%</div>
            </div>
        </div>
    </div>

    <div class="row g-3 mb-3">
        <div class="col-md-4">
            <div class="card dental-kpi-card p-3 h-100">
                <h6 class="mb-1">Avg Revenue / Patient</h6>
                <div class="display-6 fw-bold">₱<?php echo number_format($avgRevenuePerPatient, 2); ?></div>
            </div>
        </div>
        <div class="col-md-4">
            <div class="card dental-kpi-card p-3 h-100">
                <h6 class="mb-1">Avg Revenue / Visit</h6>
                <div class="display-6 fw-bold">₱<?php echo number_format($avgRevenuePerAppointment, 2); ?></div>
            </div>
        </div>
        <div class="col-md-4">
            <div class="card dental-kpi-card p-3 h-100">
                <h6 class="mb-1">Total Invoices</h6>
                <div class="display-6 fw-bold"><?php echo (int)$billingCount; ?></div>
            </div>
        </div>
    </div>

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
            <div class="card dental-surface-card h-100">
                <div class="card-body">
                    <h5 class="card-title mb-3">Service Revenue Breakdown</h5>
                    <?php if (empty($serviceRevenue)): ?>
                        <p class="text-muted mb-0">No billing data for this period.</p>
                    <?php else: ?>
                        <div class="table-responsive">
                            <table class="table table-sm table-hover align-middle mb-0">
                                <thead>
                                    <tr>
                                        <th>Service</th>
                                        <th class="text-end">Bookings</th>
                                        <th class="text-end">Revenue</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($serviceRevenue as $row): ?>
                                        <tr>
                                            <td class="fw-semibold"><?php echo htmlspecialchars($row['service_name']); ?></td>
                                            <td class="text-end"><?php echo (int)$row['bookings']; ?></td>
                                            <td class="text-end text-success">₱<?php echo number_format((float)$row['revenue'], 2); ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php endif; ?>
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
                    <a href="<?php echo htmlspecialchars($birExportUrl); ?>" class="btn btn-sm btn-outline-success clinic-report-no-print">
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

    <?php elseif ($reportType === 'demographic'): ?>
    <div class="row g-3 mb-3">
        <div class="col-md-6 col-xl-3">
            <div class="card dental-kpi-card p-3 h-100">
                <h6 class="mb-1">Total Patients Seen</h6>
                <div class="display-6 fw-bold"><?php echo (int)$patientsSeen; ?></div>
            </div>
        </div>
        <div class="col-md-6 col-xl-3">
            <div class="card dental-kpi-card p-3 h-100">
                <h6 class="mb-1">New Patients</h6>
                <div class="display-6 fw-bold"><?php echo (int)$newPatients; ?></div>
            </div>
        </div>
        <div class="col-md-6 col-xl-3">
            <div class="card dental-kpi-card p-3 h-100">
                <h6 class="mb-1">Total Appointments</h6>
                <div class="display-6 fw-bold"><?php echo (int)$totalAppointments; ?></div>
            </div>
        </div>
        <div class="col-md-6 col-xl-3">
            <div class="card dental-kpi-card p-3 h-100">
                <h6 class="mb-1">Completion Rate</h6>
                <div class="display-6 fw-bold"><?php echo $completionRate; ?>%</div>
            </div>
        </div>
    </div>

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

    <div class="row g-3 mb-3">
        <div class="col-lg-6">
            <div class="card dental-surface-card h-100">
                <div class="card-body">
                    <h5 class="card-title mb-3">Appointment Outcomes</h5>
                    <?php if (empty($statusBreakdown)): ?>
                        <p class="text-muted mb-0">No appointments in this period.</p>
                    <?php else: ?>
                        <ul class="clinic-report-bar-list list-unstyled mb-0">
                            <?php
                            $statusMax = 1;
                            foreach ($statusBreakdown as $row) {
                                $statusMax = max($statusMax, (int)$row['total']);
                            }
                            foreach ($statusBreakdown as $row):
                                $count = (int)$row['total'];
                                $pct = (int)round(($count / $statusMax) * 100);
                                $status = (string)$row['status'];
                            ?>
                                <li class="clinic-report-bar-item">
                                    <div class="d-flex justify-content-between align-items-center mb-1">
                                        <span class="badge bg-<?php echo dashboard_appt_status_badge_class($status); ?>">
                                            <?php echo htmlspecialchars(reports_format_status($status)); ?>
                                        </span>
                                        <strong><?php echo $count; ?></strong>
                                    </div>
                                    <div class="clinic-report-bar-track">
                                        <div class="clinic-report-bar-fill" style="width: <?php echo max(4, $pct); ?>%;"></div>
                                    </div>
                                </li>
                            <?php endforeach; ?>
                        </ul>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        <div class="col-lg-6">
            <div class="card dental-surface-card h-100">
                <div class="card-body">
                    <h5 class="card-title mb-3">Top Services by Bookings</h5>
                    <?php if (empty($topServices)): ?>
                        <p class="text-muted mb-0">No service bookings in this period.</p>
                    <?php else: ?>
                        <ul class="clinic-report-bar-list list-unstyled mb-0">
                            <?php
                            $topServiceMax = 1;
                            foreach ($topServices as $row) {
                                $topServiceMax = max($topServiceMax, (int)$row['bookings']);
                            }
                            foreach ($topServices as $row):
                                $count = (int)$row['bookings'];
                                $pct = (int)round(($count / $topServiceMax) * 100);
                            ?>
                                <li class="clinic-report-bar-item">
                                    <div class="d-flex justify-content-between align-items-center mb-1">
                                        <span class="fw-semibold"><?php echo htmlspecialchars($row['service_name']); ?></span>
                                        <strong><?php echo $count; ?></strong>
                                    </div>
                                    <div class="clinic-report-bar-track">
                                        <div class="clinic-report-bar-fill is-service" style="width: <?php echo max(4, $pct); ?>%;"></div>
                                    </div>
                                </li>
                            <?php endforeach; ?>
                        </ul>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

    <?php elseif ($reportType === 'trend'): ?>
    <div class="row g-3 mb-3">
        <div class="col-md-4">
            <div class="card dental-kpi-card p-3 h-100 border-primary">
                <h6 class="mb-1"><i class="bi bi-graph-up-arrow text-primary"></i> Predicted Next Month</h6>
                <div class="display-6 fw-bold text-primary"><?php echo $nextMonthPredicted; ?></div>
                <small class="text-muted">appointments forecast</small>
            </div>
        </div>
        <div class="col-md-4">
            <div class="card dental-kpi-card p-3 h-100">
                <h6 class="mb-1">12-Month Total</h6>
                <div class="display-6 fw-bold"><?php echo array_sum($monthlyTrendAppts); ?></div>
                <small class="text-muted">appointments</small>
            </div>
        </div>
        <div class="col-md-4">
            <div class="card dental-kpi-card p-3 h-100">
                <h6 class="mb-1">Avg Monthly Appointments</h6>
                <div class="display-6 fw-bold"><?php echo count($monthlyTrendAppts) > 0 ? round(array_sum($monthlyTrendAppts) / count($monthlyTrendAppts)) : 0; ?></div>
                <small class="text-muted">per month</small>
            </div>
        </div>
    </div>

    <div class="row g-3 mb-3">
        <div class="col-lg-8">
            <div class="card dental-surface-card dental-chart-card h-100">
                <div class="card-body">
                    <div class="dental-chart-card__head">
                        <h5 class="card-title mb-0">Monthly Appointment Trend (12 Months)</h5>
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
                    <h5 class="card-title mb-3">Service Demand Forecast</h5>
                    <?php if (empty($topServicesPredicted)): ?>
                        <p class="text-muted mb-0">Not enough data for predictions.</p>
                    <?php else: ?>
                        <div class="table-responsive">
                            <table class="table table-sm align-middle mb-0">
                                <thead>
                                    <tr>
                                        <th>Service</th>
                                        <th class="text-end">Now</th>
                                        <th class="text-end">Predicted</th>
                                        <th class="text-end">Trend</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach (array_slice($topServicesPredicted, 0, 8) as $pred): ?>
                                        <tr>
                                            <td class="fw-semibold small"><?php echo htmlspecialchars($pred['service']); ?></td>
                                            <td class="text-end"><?php echo $pred['current']; ?></td>
                                            <td class="text-end fw-bold"><?php echo $pred['predicted']; ?></td>
                                            <td class="text-end">
                                                <?php if ($pred['growth'] > 0): ?>
                                                    <span class="text-success"><i class="bi bi-arrow-up"></i> <?php echo $pred['growth']; ?>%</span>
                                                <?php elseif ($pred['growth'] < 0): ?>
                                                    <span class="text-danger"><i class="bi bi-arrow-down"></i> <?php echo abs($pred['growth']); ?>%</span>
                                                <?php else: ?>
                                                    <span class="text-muted">0%</span>
                                                <?php endif; ?>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

    <div class="row g-3 mb-3">
        <div class="col-lg-12">
            <div class="card dental-surface-card dental-chart-card h-100">
                <div class="card-body">
                    <div class="dental-chart-card__head">
                        <h5 class="card-title mb-0">Service Trends (6 Months)</h5>
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

    <div class="row g-3 mb-3">
        <div class="col-lg-6">
            <div class="card dental-surface-card dental-chart-card h-100">
                <div class="card-body">
                    <div class="dental-chart-card__head">
                        <h5 class="card-title mb-0">Peak Days Analysis</h5>
                        <button type="button" class="dental-chart-expand-btn" data-chart-expand="trendPeakDaysChart" aria-label="Enlarge chart">
                            <i class="bi bi-arrows-fullscreen"></i>
                        </button>
                    </div>
                    <div class="dental-chart-frame">
                        <div id="trendPeakDaysChart" class="clinic-simple-chart"></div>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-lg-6">
            <div class="card dental-surface-card dental-chart-card h-100">
                <div class="card-body">
                    <div class="dental-chart-card__head">
                        <h5 class="card-title mb-0">Peak Hours Analysis</h5>
                        <button type="button" class="dental-chart-expand-btn" data-chart-expand="trendPeakHoursChart" aria-label="Enlarge chart">
                            <i class="bi bi-arrows-fullscreen"></i>
                        </button>
                    </div>
                    <div class="dental-chart-frame">
                        <div id="trendPeakHoursChart" class="clinic-simple-chart"></div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="row g-3 mb-3">
        <div class="col-lg-6">
            <div class="card dental-surface-card h-100">
                <div class="card-body">
                    <h5 class="card-title mb-3"><i class="bi bi-lightbulb text-warning"></i> Data-Driven Insights</h5>
                    <ul class="list-unstyled mb-0">
                        <?php if (!empty($peakDays)): ?>
                        <li class="mb-2 pb-2 border-bottom">
                            <strong>Busiest Day:</strong> <?php echo htmlspecialchars($peakDays[0]['day_name']); ?>
                            (<?php echo (int)$peakDays[0]['appointments']; ?> appointments)
                        </li>
                        <?php endif; ?>
                        <?php if (!empty($peakHours)): ?>
                        <li class="mb-2 pb-2 border-bottom">
                            <strong>Peak Hour:</strong> <?php echo htmlspecialchars($peakHourLabels[0] ?? ''); ?>
                            (<?php echo (int)$peakHourValues[0]; ?> appointments)
                        </li>
                        <?php endif; ?>
                        <?php if (!empty($topServices)): ?>
                        <li class="mb-2 pb-2 border-bottom">
                            <strong>Most In-Demand Service:</strong> <?php echo htmlspecialchars($topServices[0]['service_name']); ?>
                            (<?php echo (int)$topServices[0]['bookings']; ?> bookings)
                        </li>
                        <?php endif; ?>
                        <?php if ($nextMonthPredicted > 0): ?>
                        <li class="mb-2 pb-2 border-bottom">
                            <strong>Next Month Forecast:</strong> ~<?php echo $nextMonthPredicted; ?> appointments
                            (based on recent growth trend)
                        </li>
                        <?php endif; ?>
                        <li>
                            <strong>Recommendation:</strong>
                            <?php if (!empty($peakDays) && !empty($peakHours)): ?>
                                Consider <?php echo $peakHours[0]['appointments'] > 5 ? 'extending' : 'maintaining'; ?> staff coverage on <?php echo htmlspecialchars($peakDays[0]['day_name']); ?>s during <?php echo htmlspecialchars($peakHourLabels[0] ?? ''); ?>.
                            <?php else: ?>
                                Collect more data to generate actionable insights.
                            <?php endif; ?>
                        </li>
                    </ul>
                </div>
            </div>
        </div>
        <div class="col-lg-6">
            <div class="card dental-surface-card h-100">
                <div class="card-body">
                    <h5 class="card-title mb-3"><i class="bi bi-box-seam text-info"></i> Material Preparation Guide</h5>
                    <?php if (empty($topServicesPredicted)): ?>
                        <p class="text-muted mb-0">Not enough data for material recommendations.</p>
                    <?php else: ?>
                        <div class="table-responsive">
                            <table class="table table-sm align-middle mb-0">
                                <thead>
                                    <tr>
                                        <th>Service Trending Up</th>
                                        <th>Prepare Materials</th>
                                        <th>Priority</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php
                                    $materialMap = [
                                        'Teeth Cleaning' => 'Scaler tips, polishing paste, fluoride',
                                        'Root Canal Treatment' => 'Files, gutta percha, sealer',
                                        'Crown Placement' => 'Impression material, temporary crowns',
                                        'Filling' => 'Composite resin, bonding agent, etchant',
                                        'Extraction' => 'Forceps, elevators, sutures',
                                        'Whitening' => 'Whitening gel, LED light, sensitivity paste',
                                        'Denture Fitting' => 'Impression trays, acrylic, articulator',
                                        'Implant Consultation' => 'CT scan referral, implant catalog',
                                    ];
                                    $priority = 1;
                                    foreach (array_slice($topServicesPredicted, 0, 5) as $pred):
                                        if ($pred['growth'] > 0):
                                            $materials = $materialMap[$pred['service']] ?? 'General supplies';
                                    ?>
                                        <tr>
                                            <td class="fw-semibold">
                                                <?php echo htmlspecialchars($pred['service']); ?>
                                                <br><small class="text-success">+<?php echo $pred['growth']; ?>%</small>
                                            </td>
                                            <td class="small"><?php echo htmlspecialchars($materials); ?></td>
                                            <td>
                                                <?php if ($priority <= 2): ?>
                                                    <span class="badge bg-danger">High</span>
                                                <?php elseif ($priority <= 3): ?>
                                                    <span class="badge bg-warning text-dark">Medium</span>
                                                <?php else: ?>
                                                    <span class="badge bg-secondary">Low</span>
                                                <?php endif; ?>
                                            </td>
                                        </tr>
                                    <?php
                                            $priority++;
                                        endif;
                                    endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

    <?php endif; ?>

    <?php if ($reportType !== 'trend'): ?>
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
    <?php endif; ?>

    <div class="col-12 mb-3">
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

<script src="../assets/js/admin-charts.js?v=20260702-reports"></script>
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
    var monthlyTrendLabels = <?php echo json_encode($monthlyTrendLabels); ?>;
    var monthlyTrendAppts = <?php echo json_encode($monthlyTrendAppts); ?>;
    var monthlyTrendCompleted = <?php echo json_encode($monthlyTrendCompleted); ?>;
    var serviceTrendLabels = <?php echo json_encode($serviceTrendMonthLabels); ?>;
    var serviceTrendSeries = <?php echo json_encode($serviceTrendSeries); ?>;
    var totalCollected = <?php echo json_encode(round($totalCollected, 2)); ?>;
    var totalOutstanding = <?php echo json_encode(round($totalOutstanding, 2)); ?>;

    var chartColors = ['#0ea5e9', '#6366f1', '#10b981', '#f59e0b', '#ef4444', '#8b5cf6', '#ec4899', '#14b8a6'];

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
                    var opts = {
                        chart: { type: 'area', height: 260, toolbar: { show: false }, fontFamily: 'inherit', foreColor: '#64748b' },
                        series: [{ name: 'Revenue', data: dailyRevValues }],
                        xaxis: { categories: dailyRevLabels, labels: { rotate: -45, style: { fontSize: '11px' } }, axisBorder: { show: false }, axisTicks: { show: false } },
                        yaxis: { min: 0, labels: { formatter: function(v) { return '₱' + v.toLocaleString(); } } },
                        colors: ['#10b981'],
                        fill: { type: 'gradient', gradient: { shadeIntensity: 1, opacityFrom: 0.4, opacityTo: 0.1 } },
                        stroke: { curve: 'smooth', width: 2 },
                        dataLabels: { enabled: false },
                        legend: { show: false },
                        grid: { borderColor: '#e8eef5', strokeDashArray: 4 },
                        tooltip: { theme: 'light', y: { formatter: function(v) { return '₱' + v.toLocaleString(); } } }
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
                AdminCharts.mount('salesPayMethodChart', AdminCharts.buildDonutOptions(payMethodLabels, payMethodValues), 'Payment Methods');
            } else {
                AdminCharts.renderNoData('salesPayMethodChart', 'payment data');
            }
        } catch(e) { console.error('Pay method chart:', e); }

        try {
            if (svcRevLabels.length) {
                AdminCharts.mount('salesSvcRevChart', AdminCharts.buildHorizontalBarOptions(svcRevLabels, svcRevValues, 'Revenue'), 'Revenue by Service');
            } else {
                AdminCharts.renderNoData('salesSvcRevChart', 'service revenue');
            }
        } catch(e) { console.error('Svc rev chart:', e); }
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
                    var opts = {
                        chart: { type: 'bar', height: 240, toolbar: { show: false }, fontFamily: 'inherit', foreColor: '#64748b' },
                        series: [{ name: 'Appointments', data: peakDayValues }],
                        xaxis: { categories: peakDayLabels, labels: { style: { fontSize: '11px' } } },
                        yaxis: { min: 0, forceNiceScale: true },
                        colors: ['#6366f1'],
                        plotOptions: { bar: { borderRadius: 6, columnWidth: '60%' } },
                        dataLabels: { enabled: false },
                        legend: { show: false },
                        grid: { borderColor: '#e8eef5', strokeDashArray: 4 }
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
                    var opts = {
                        chart: { type: 'bar', height: 240, toolbar: { show: false }, fontFamily: 'inherit', foreColor: '#64748b' },
                        series: [{ name: 'Appointments', data: peakHourValues }],
                        xaxis: { categories: peakHourLabels, labels: { style: { fontSize: '11px' } } },
                        yaxis: { min: 0, forceNiceScale: true },
                        colors: ['#f59e0b'],
                        plotOptions: { bar: { borderRadius: 6, columnWidth: '60%' } },
                        dataLabels: { enabled: false },
                        legend: { show: false },
                        grid: { borderColor: '#e8eef5', strokeDashArray: 4 }
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
        try {
            if (monthlyTrendLabels.length) {
                var el = document.getElementById('trendMonthlyChart');
                if (el) {
                    el.innerHTML = '';
                    var opts = {
                        chart: { type: 'line', height: 280, toolbar: { show: false }, fontFamily: 'inherit', foreColor: '#64748b' },
                        series: [
                            { name: 'Total Appointments', data: monthlyTrendAppts },
                            { name: 'Completed', data: monthlyTrendCompleted }
                        ],
                        xaxis: { categories: monthlyTrendLabels, labels: { style: { fontSize: '11px' } } },
                        yaxis: { min: 0, forceNiceScale: true },
                        colors: ['#1173c1', '#10b981'],
                        stroke: { curve: 'smooth', width: 2 },
                        fill: { type: 'solid', opacity: 0 },
                        dataLabels: { enabled: false },
                        legend: { position: 'top', horizontalAlign: 'left', fontSize: '12px' },
                        grid: { borderColor: '#e8eef5', strokeDashArray: 4 },
                        markers: { size: 4, hover: { size: 6 } }
                    };
                    var chart = new ApexCharts(el, opts);
                    chart.render();
                    AdminCharts._registry = AdminCharts._registry || {};
                    AdminCharts._registry['trendMonthlyChart'] = { options: opts, title: 'Monthly Appointment Trend (12 Months)', chart: chart };
                }
            } else {
                AdminCharts.renderNoData('trendMonthlyChart', 'monthly data');
            }
        } catch(e) { console.error('Monthly trend chart:', e); }

        try {
            if (serviceTrendLabels.length && serviceTrendSeries.length) {
                var el = document.getElementById('trendSvcChart');
                if (el) {
                    el.innerHTML = '';
                    var seriesData = serviceTrendSeries.map(function(s, i) {
                        return { name: s.name, data: s.data };
                    });
                    var opts = {
                        chart: { type: 'line', height: 300, toolbar: { show: false }, fontFamily: 'inherit', foreColor: '#64748b' },
                        series: seriesData,
                        xaxis: { categories: serviceTrendLabels, labels: { style: { fontSize: '11px' } } },
                        yaxis: { min: 0, forceNiceScale: true },
                        colors: chartColors,
                        stroke: { curve: 'smooth', width: 2 },
                        fill: { type: 'solid', opacity: 0 },
                        dataLabels: { enabled: false },
                        legend: { position: 'top', horizontalAlign: 'left', fontSize: '12px' },
                        grid: { borderColor: '#e8eef5', strokeDashArray: 4 },
                        markers: { size: 3, hover: { size: 5 } }
                    };
                    var chart = new ApexCharts(el, opts);
                    chart.render();
                    AdminCharts._registry = AdminCharts._registry || {};
                    AdminCharts._registry['trendSvcChart'] = { options: opts, title: 'Service Trends (6 Months)', chart: chart };
                }
            } else {
                AdminCharts.renderNoData('trendSvcChart', 'service trend data');
            }
        } catch(e) { console.error('Service trend chart:', e); }

        try {
            if (peakDayLabels.length) {
                var el = document.getElementById('trendPeakDaysChart');
                if (el) {
                    el.innerHTML = '';
                    var opts = {
                        chart: { type: 'bar', height: 240, toolbar: { show: false }, fontFamily: 'inherit', foreColor: '#64748b' },
                        series: [{ name: 'Appointments', data: peakDayValues }],
                        xaxis: { categories: peakDayLabels, labels: { style: { fontSize: '11px' } } },
                        yaxis: { min: 0, forceNiceScale: true },
                        colors: ['#6366f1'],
                        plotOptions: { bar: { borderRadius: 6, columnWidth: '60%' } },
                        dataLabels: { enabled: false },
                        legend: { show: false },
                        grid: { borderColor: '#e8eef5', strokeDashArray: 4 }
                    };
                    var chart = new ApexCharts(el, opts);
                    chart.render();
                    AdminCharts._registry = AdminCharts._registry || {};
                    AdminCharts._registry['trendPeakDaysChart'] = { options: opts, title: 'Peak Days Analysis', chart: chart };
                }
            } else {
                AdminCharts.renderNoData('trendPeakDaysChart', 'day data');
            }
        } catch(e) { console.error('Trend peak days:', e); }

        try {
            if (peakHourLabels.length) {
                var el = document.getElementById('trendPeakHoursChart');
                if (el) {
                    el.innerHTML = '';
                    var opts = {
                        chart: { type: 'bar', height: 240, toolbar: { show: false }, fontFamily: 'inherit', foreColor: '#64748b' },
                        series: [{ name: 'Appointments', data: peakHourValues }],
                        xaxis: { categories: peakHourLabels, labels: { style: { fontSize: '11px' } } },
                        yaxis: { min: 0, forceNiceScale: true },
                        colors: ['#f59e0b'],
                        plotOptions: { bar: { borderRadius: 6, columnWidth: '60%' } },
                        dataLabels: { enabled: false },
                        legend: { show: false },
                        grid: { borderColor: '#e8eef5', strokeDashArray: 4 }
                    };
                    var chart = new ApexCharts(el, opts);
                    chart.render();
                    AdminCharts._registry = AdminCharts._registry || {};
                    AdminCharts._registry['trendPeakHoursChart'] = { options: opts, title: 'Peak Hours Analysis', chart: chart };
                }
            } else {
                AdminCharts.renderNoData('trendPeakHoursChart', 'hour data');
            }
        } catch(e) { console.error('Trend peak hours:', e); }
    }

    function renderCommonCharts() {
        try {
            if (dailyLabels.length && (dailyBooked.some(function(v) { return v > 0; }) || dailyCompleted.some(function(v) { return v > 0; }))) {
                var el = document.getElementById('reportDailyChart');
                if (el) {
                    el.innerHTML = '';
                    var opts = {
                        chart: { type: 'bar', height: 240, toolbar: { show: false }, fontFamily: 'inherit', foreColor: '#64748b' },
                        series: [
                            { name: 'Booked', data: dailyBooked },
                            { name: 'Completed', data: dailyCompleted }
                        ],
                        xaxis: { categories: dailyLabels, labels: { rotate: -45, style: { fontSize: '11px' } }, axisBorder: { show: false }, axisTicks: { show: false } },
                        yaxis: { min: 0, forceNiceScale: true },
                        colors: ['#94a3b8', '#0d9488'],
                        plotOptions: { bar: { borderRadius: 6, columnWidth: '55%' } },
                        dataLabels: { enabled: false },
                        legend: { position: 'top', horizontalAlign: 'left', fontSize: '12px' },
                        grid: { borderColor: '#e8eef5', strokeDashArray: 4 }
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
.clinic-reports-filter .form-select,
.clinic-reports-filter .form-control {
    border-radius: 8px;
    border: 1px solid #e2e8f0;
    font-size: 13px;
}
.clinic-reports-filter .form-select:focus,
.clinic-reports-filter .form-control:focus {
    border-color: var(--primary-color);
    box-shadow: 0 0 0 3px rgba(17, 115, 193, 0.1);
}
.dental-kpi-card {
    border-radius: 12px;
    border: 1px solid #e2e8f0;
    background: #fff;
}
.dental-kpi-card h6 {
    color: #64748b;
    font-size: 13px;
    font-weight: 500;
}
.dental-kpi-card .display-6 {
    font-size: 1.8rem;
    color: #1e293b;
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
@media print {
    .clinic-report-no-print { display: none !important; }
    .clinic-reports-shell { padding: 0; }
    .card { break-inside: avoid; border: 1px solid #dee2e6 !important; }
}
</style>

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
                    <span class="ai-chat-subtitle"><span class="ai-chat-status-dot"></span>Powered by Groq AI</span>
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
    background: linear-gradient(145deg, #1a80d9, #0a4f8e);
    color: #fff;
    border: none;
    box-shadow: 0 4px 18px rgba(17,115,193,0.38), 0 2px 6px rgba(0,0,0,0.12);
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
    background: linear-gradient(135deg, #1a80d9 0%, #0a4f8e 100%);
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 17px;
    flex-shrink: 0;
    color: #fff;
    box-shadow: 0 3px 10px rgba(17,115,193,0.28);
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
    background: linear-gradient(135deg, #1a80d9, #0a4f8e);
    color: #fff;
    border-bottom-right-radius: 4px;
    box-shadow: 0 2px 8px rgba(17,115,193,0.22);
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
    border: 1.5px solid #e2e8f0;
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
    border-color: #1a80d9;
    background: #fff;
    box-shadow: 0 0 0 3px rgba(26,128,217,0.1);
}
.ai-chat-send {
    width: 38px;
    height: 38px;
    border-radius: 10px;
    background: linear-gradient(135deg, #1a80d9, #0a4f8e);
    color: #fff;
    border: none;
    cursor: pointer;
    display: flex;
    align-items: center;
    justify-content: center;
    flex-shrink: 0;
    transition: opacity 0.2s, transform 0.2s;
    font-size: 15px;
    box-shadow: 0 2px 8px rgba(17,115,193,0.25);
}
.ai-chat-send:hover { opacity: 0.88; transform: scale(1.06); }
.ai-chat-send:disabled { opacity: 0.35; cursor: not-allowed; transform: none; }
.ai-metric {
    font-size: 28px;
    font-weight: 800;
    color: #1a80d9;
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

        if (answer) {
            html += '<p class="ai-answer-text">' + escHtml(answer).replace(/\n/g, '<br>') + '</p>';
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
