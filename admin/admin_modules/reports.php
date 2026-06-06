<?php
include dirname(dirname(__DIR__)) . '/config/database.php';
include_once dirname(dirname(__DIR__)) . '/config/dashboard_helpers.php';
include_once dirname(dirname(__DIR__)) . '/config/billing_helpers.php';

billing_ensure_tables($conn);

$range = $_GET['range'] ?? '30d';
$startDate = $_GET['start_date'] ?? '';
$endDate = $_GET['end_date'] ?? date('Y-m-d');
$doctorId = isset($_GET['doctor_id']) && $_GET['doctor_id'] !== '' ? (int)$_GET['doctor_id'] : 0;

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

$awaitingRecords = reports_count_appts(
    $conn,
    "SELECT COUNT(*) FROM appointments a WHERE a.status = 'awaiting_secretary_records'{$doctorClause}",
    $doctorId > 0 ? 'i' : '',
    $doctorId > 0 ? [$doctorId] : []
);

$newPatients = dashboard_scalar_int(
    $conn,
    'SELECT COUNT(*) FROM patients WHERE DATE(created_at) BETWEEN ? AND ?',
    'ss',
    [$startDate, $endDate]
);

$patientsSeen = reports_count_appts(
    $conn,
    "SELECT COUNT(DISTINCT a.patient_id) FROM appointments a WHERE a.appointment_date BETWEEN ? AND ? AND a.status IN ('completed','records_complete','in_progress','checked_in'){$doctorClause}",
    $baseTypes,
    $baseParams
);

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
$statusMax = 1;
foreach ($statusBreakdown as $row) {
    $statusMax = max($statusMax, (int)$row['total']);
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
$topServiceMax = 1;
foreach ($topServices as $row) {
    $topServiceMax = max($topServiceMax, (int)$row['bookings']);
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

$completionRate = $totalAppointments > 0 ? round(($completedVisits / $totalAppointments) * 100) : 0;
$periodLabel = date('M d, Y', strtotime($startDate)) . ' – ' . date('M d, Y', strtotime($endDate));

$dailyChartLabels = [];
$dailyCompletedValues = [];
$dailyMap = [];
$dayStart = strtotime($startDate);
$dayEnd = strtotime($endDate);
if ($dayStart && $dayEnd && $dayStart <= $dayEnd) {
    for ($d = $dayStart; $d <= $dayEnd; $d = strtotime('+1 day', $d)) {
        $key = date('Y-m-d', $d);
        $dailyChartLabels[] = date('M d', $d);
        $dailyCompletedValues[] = 0;
        $dailyMap[$key] = count($dailyCompletedValues) - 1;
    }
}
$dailySql = "
    SELECT a.appointment_date AS dt, COUNT(*) AS total
    FROM appointments a
    WHERE a.appointment_date BETWEEN ? AND ?
      AND a.status IN ('completed','records_complete'){$doctorClause}
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
            $dailyCompletedValues[$dailyMap[$dt]] = (int)$row['total'];
        }
    }
    $dailyStmt->close();
}

$chartStatusLabels = [];
$chartStatusValues = [];
foreach ($statusBreakdown as $row) {
    $chartStatusLabels[] = reports_format_status((string)$row['status']);
    $chartStatusValues[] = (int)$row['total'];
}

$chartServiceLabels = [];
$chartServiceValues = [];
foreach (array_slice($topServices, 0, 5) as $row) {
    $chartServiceLabels[] = (string)$row['service_name'];
    $chartServiceValues[] = (int)$row['bookings'];
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

$exportQuery = http_build_query([
    'range' => $range,
    'start_date' => $startDate,
    'end_date' => $endDate,
    'doctor_id' => $doctorId > 0 ? $doctorId : '',
]);
$birExportUrl = '../assets/api/reports/export_bir.php?' . $exportQuery;
?>

<div class="dental-dashboard-shell clinic-reports-shell" id="clinicReportPrintArea">
    <div class="dental-dashboard-hero">
        <div>
            <h4 class="mb-0">Clinic Report</h4>
        </div>
        <div class="d-flex flex-wrap align-items-center gap-2">
            <span class="dental-chip"><?php echo htmlspecialchars($periodLabel); ?></span>
            <a href="<?php echo htmlspecialchars($birExportUrl); ?>" class="btn btn-sm btn-success clinic-report-no-print">
                <i class="bi bi-download"></i> Export BIR CSV
            </a>
            <button type="button" class="btn btn-sm btn-outline-secondary clinic-report-no-print" onclick="window.print()">
                <i class="bi bi-printer"></i> Print
            </button>
        </div>
    </div>

    <form method="GET" action="admin.php" class="clinic-reports-filter card border-0 mb-3">
        <div class="card-body">
            <input type="hidden" name="page" value="reports">
            <div class="row g-2 align-items-end">
                <div class="col-md-3">
                    <label class="form-label small mb-1">Period</label>
                    <select name="range" class="form-select form-select-sm" id="reportRangeSelect">
                        <option value="7d" <?php echo $range === '7d' ? 'selected' : ''; ?>>Last 7 days</option>
                        <option value="30d" <?php echo $range === '30d' ? 'selected' : ''; ?>>Last 30 days</option>
                        <option value="month" <?php echo $range === 'month' ? 'selected' : ''; ?>>This month</option>
                        <option value="custom" <?php echo $range === 'custom' ? 'selected' : ''; ?>>Custom dates</option>
                    </select>
                </div>
                <div class="col-md-2 report-custom-date" style="<?php echo $range === 'custom' ? '' : 'display:none;'; ?>">
                    <label class="form-label small mb-1">From</label>
                    <input type="date" name="start_date" class="form-control form-control-sm" value="<?php echo htmlspecialchars($startDate); ?>">
                </div>
                <div class="col-md-2 report-custom-date" style="<?php echo $range === 'custom' ? '' : 'display:none;'; ?>">
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
                <div class="col-md-2 d-flex gap-2">
                    <button type="submit" class="btn btn-primary btn-sm w-100"><i class="bi bi-funnel"></i> Apply</button>
                    <a href="admin.php?page=reports" class="btn btn-outline-secondary btn-sm w-100">Reset</a>
                </div>
            </div>
        </div>
    </form>

    <div class="row g-3 mb-3">
        <div class="col-md-6 col-xl-3">
            <div class="card dental-kpi-card p-3 h-100">
                <h6 class="mb-1">Patients Seen</h6>
                <div class="display-6 fw-bold"><?php echo (int)$patientsSeen; ?></div>
            </div>
        </div>
        <div class="col-md-6 col-xl-3">
            <div class="card dental-kpi-card p-3 h-100">
                <h6 class="mb-1">Completed Visits</h6>
                <div class="display-6 fw-bold"><?php echo (int)$completedVisits; ?></div>
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
                <h6 class="mb-1">Needs Attention</h6>
                <div class="display-6 fw-bold"><?php echo (int)($noShows + $cancelled + $awaitingRecords); ?></div>
            </div>
        </div>
    </div>

    <?php if ($billingCount > 0): ?>
    <div class="row g-3 mb-3">
        <div class="col-md-4">
            <div class="card dental-kpi-card p-3 h-100">
                <h6 class="mb-1">Total Billed</h6>
                <div class="display-6 fw-bold">₱<?php echo number_format($totalBilled, 2); ?></div>
            </div>
        </div>
        <div class="col-md-4">
            <div class="card dental-kpi-card p-3 h-100">
                <h6 class="mb-1">Collected</h6>
                <div class="display-6 fw-bold text-success">₱<?php echo number_format($totalCollected, 2); ?></div>
            </div>
        </div>
        <div class="col-md-4">
            <div class="card dental-kpi-card p-3 h-100">
                <h6 class="mb-1">Outstanding</h6>
                <div class="display-6 fw-bold <?php echo $totalOutstanding > 0 ? 'text-danger' : ''; ?>">₱<?php echo number_format($totalOutstanding, 2); ?></div>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <div class="row g-3 mb-3">
        <div class="col-lg-4">
            <div class="card dental-surface-card h-100">
                <div class="card-body">
                    <h5 class="card-title mb-2">Completed Visits per Day</h5>
                    <div id="reportDailyChart" class="clinic-simple-chart"></div>
                </div>
            </div>
        </div>
        <div class="col-lg-4">
            <div class="card dental-surface-card h-100">
                <div class="card-body">
                    <h5 class="card-title mb-2">Appointment Outcomes</h5>
                    <div id="reportStatusChart" class="clinic-simple-chart"></div>
                </div>
            </div>
        </div>
        <div class="col-lg-4">
            <div class="card dental-surface-card h-100">
                <div class="card-body">
                    <h5 class="card-title mb-2">Top Services Booked</h5>
                    <div id="reportServicesChart" class="clinic-simple-chart"></div>
                </div>
            </div>
        </div>
        <?php if ($billingCount > 0): ?>
        <div class="col-lg-6">
            <div class="card dental-surface-card h-100">
                <div class="card-body">
                    <h5 class="card-title mb-2">Collections vs Outstanding</h5>
                    <div id="reportCollectionsChart" class="clinic-simple-chart"></div>
                </div>
            </div>
        </div>
        <?php endif; ?>
    </div>

    <div class="row g-3">
        <div class="col-lg-6">
            <div class="card dental-surface-card h-100">
                <div class="card-body">
                    <h5 class="card-title mb-3">Where Appointments Stand</h5>
                    <?php if (empty($statusBreakdown)): ?>
                        <p class="text-muted mb-0">No appointments in this period.</p>
                    <?php else: ?>
                        <ul class="clinic-report-bar-list list-unstyled mb-0">
                            <?php foreach ($statusBreakdown as $row): ?>
                                <?php
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
                    <h5 class="card-title mb-3">Most Booked Services</h5>
                    <?php if (empty($topServices)): ?>
                        <p class="text-muted mb-0">No service bookings in this period.</p>
                    <?php else: ?>
                        <ul class="clinic-report-bar-list list-unstyled mb-0">
                            <?php foreach ($topServices as $row): ?>
                                <?php
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

        <?php if (!empty($birSalesPreview)): ?>
        <div class="col-12">
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
</div>

<script>
document.getElementById('reportRangeSelect')?.addEventListener('change', function () {
    document.querySelectorAll('.report-custom-date').forEach(function (el) {
        el.style.display = this.value === 'custom' ? '' : 'none';
    }.bind(this));
});

(function () {
    const dailyLabels = <?php echo json_encode($dailyChartLabels); ?>;
    const dailyValues = <?php echo json_encode($dailyCompletedValues); ?>;
    const statusLabels = <?php echo json_encode($chartStatusLabels); ?>;
    const statusValues = <?php echo json_encode($chartStatusValues); ?>;
    const serviceLabels = <?php echo json_encode($chartServiceLabels); ?>;
    const serviceValues = <?php echo json_encode($chartServiceValues); ?>;
    const totalCollected = <?php echo json_encode(round($totalCollected, 2)); ?>;
    const totalOutstanding = <?php echo json_encode(round($totalOutstanding, 2)); ?>;

    function renderNoData(id, label) {
        const el = document.getElementById(id);
        if (!el) return;
        el.innerHTML = '<div class="text-muted small text-center py-5">No ' + label + ' for this period.</div>';
    }

    function renderCharts() {
        if (typeof ApexCharts === 'undefined') return;

        if (dailyLabels.length && dailyValues.some(v => v > 0)) {
            new ApexCharts(document.getElementById('reportDailyChart'), {
                chart: { type: 'bar', height: 240, toolbar: { show: false }, fontFamily: 'inherit' },
                series: [{ name: 'Completed visits', data: dailyValues }],
                xaxis: { categories: dailyLabels, labels: { rotate: -45, style: { fontSize: '11px' } } },
                plotOptions: { bar: { borderRadius: 4, columnWidth: '55%' } },
                dataLabels: { enabled: false },
                colors: ['#1173c1'],
                grid: { strokeDashArray: 4 },
                yaxis: { min: 0, forceNiceScale: true, labels: { formatter: v => Math.round(v) } }
            }).render();
        } else {
            renderNoData('reportDailyChart', 'completed visits');
        }

        if (statusLabels.length) {
            new ApexCharts(document.getElementById('reportStatusChart'), {
                chart: { type: 'donut', height: 240, toolbar: { show: false }, fontFamily: 'inherit' },
                series: statusValues,
                labels: statusLabels,
                legend: { position: 'bottom', fontSize: '12px' },
                dataLabels: { enabled: true, formatter: (val) => Math.round(val) + '%' },
                colors: ['#1173c1', '#3d8bfd', '#20c997', '#ffc107', '#dc3545', '#6c757d'],
                stroke: { width: 1 }
            }).render();
        } else {
            renderNoData('reportStatusChart', 'appointment outcomes');
        }

        if (serviceLabels.length) {
            new ApexCharts(document.getElementById('reportServicesChart'), {
                chart: { type: 'bar', height: 240, toolbar: { show: false }, fontFamily: 'inherit' },
                series: [{ name: 'Bookings', data: serviceValues }],
                xaxis: { categories: serviceLabels, labels: { style: { fontSize: '11px' } } },
                plotOptions: { bar: { horizontal: true, borderRadius: 4, barHeight: '60%' } },
                dataLabels: { enabled: true, style: { fontSize: '11px' } },
                colors: ['#3d8bfd'],
                grid: { strokeDashArray: 4 }
            }).render();
        } else {
            renderNoData('reportServicesChart', 'service bookings');
        }

        const collectionsEl = document.getElementById('reportCollectionsChart');
        if (collectionsEl && (totalCollected > 0 || totalOutstanding > 0)) {
            new ApexCharts(collectionsEl, {
                chart: { type: 'bar', height: 240, toolbar: { show: false }, fontFamily: 'inherit' },
                series: [{ name: 'Amount (PHP)', data: [totalCollected, totalOutstanding] }],
                xaxis: { categories: ['Collected', 'Outstanding'] },
                plotOptions: { bar: { borderRadius: 6, columnWidth: '45%', distributed: true } },
                dataLabels: {
                    enabled: true,
                    formatter: (val) => '₱' + Number(val).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 })
                },
                colors: ['#198754', '#dc3545'],
                legend: { show: false },
                yaxis: { labels: { formatter: (val) => '₱' + Number(val).toLocaleString() } }
            }).render();
        } else if (collectionsEl) {
            renderNoData('reportCollectionsChart', 'billing amounts');
        }
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', renderCharts);
    } else {
        renderCharts();
    }
})();
</script>
