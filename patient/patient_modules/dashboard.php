<?php
include dirname(dirname(__DIR__)) . '/config/database.php';
include_once dirname(dirname(__DIR__)) . '/config/dashboard_helpers.php';
include_once dirname(dirname(__DIR__)) . '/config/billing_helpers.php';

billing_ensure_tables($conn);

$patientId = (int)($_SESSION['patient_id'] ?? 0);
if ($patientId <= 0 && !empty($_SESSION['user_id'])) {
    $stmt = $conn->prepare('SELECT patient_id FROM patients WHERE user_id = ? LIMIT 1');
    if ($stmt) {
        $uid = (int)$_SESSION['user_id'];
        $stmt->bind_param('i', $uid);
        $stmt->execute();
        $r = $stmt->get_result();
        $row = $r ? $r->fetch_assoc() : null;
        $patientId = (int)($row['patient_id'] ?? 0);
        $stmt->close();
    }
}

$today = date('Y-m-d');
$upcoming = 0;
$past = 0;
$outstandingBalance = 0.0;
$unpaidBills = 0;
$nextAppt = null;
$upcomingList = [];

if ($patientId > 0) {
    $activeStatuses = "'scheduled','confirmed','arrived','checked_in','in_progress','rescheduled'";
    $upcoming = dashboard_scalar_int(
        $conn,
        "SELECT COUNT(*) FROM appointments WHERE patient_id = ? AND appointment_date >= ? AND status IN ($activeStatuses)",
        'is',
        [$patientId, $today]
    );
    $past = dashboard_scalar_int(
        $conn,
        "SELECT COUNT(*) FROM appointments WHERE patient_id = ? AND status IN ('completed','records_complete')",
        'i',
        [$patientId]
    );

    $stmt = $conn->prepare("
        SELECT a.appointment_id, a.appointment_date, a.status, t.start_time
        FROM appointments a
        LEFT JOIN timeslot t ON t.timeslot_id = a.timeslot_id
        WHERE a.patient_id = ? AND a.appointment_date >= ? AND a.status IN ($activeStatuses)
        ORDER BY a.appointment_date ASC, COALESCE(t.start_time, '23:59:59') ASC
        LIMIT 5
    ");
    if ($stmt) {
        $stmt->bind_param('is', $patientId, $today);
        $stmt->execute();
        $res = $stmt->get_result();
        if ($res) {
            while ($row = $res->fetch_assoc()) {
                $upcomingList[] = $row;
            }
            $nextAppt = $upcomingList[0] ?? null;
        }
        $stmt->close();
    }

    $balStmt = $conn->prepare("
        SELECT COALESCE(SUM(b.balance), 0) AS total_balance, COUNT(*) AS open_bills
        FROM billing b
        INNER JOIN appointments a ON a.appointment_id = b.appointment_id
        WHERE a.patient_id = ? AND b.balance > 0
    ");
    if ($balStmt) {
        $balStmt->bind_param('i', $patientId);
        $balStmt->execute();
        $balRes = $balStmt->get_result();
        if ($balRes && ($balRow = $balRes->fetch_assoc())) {
            $outstandingBalance = (float)($balRow['total_balance'] ?? 0);
            $unpaidBills = (int)($balRow['open_bills'] ?? 0);
        }
        $balStmt->close();
    }
}

[, $seriesValues, $chartLabels] = dashboard_last7_series($conn);
if ($patientId > 0) {
    $last7Start = date('Y-m-d', strtotime('-6 days'));
    $seriesValues = array_fill(0, 7, 0);
    $chartLabels = [];
    for ($i = 0; $i < 7; $i++) {
        $d = date('Y-m-d', strtotime($last7Start . ' +' . $i . ' days'));
        $chartLabels[] = date('M d', strtotime($d));
    }
    $stmt = $conn->prepare('
        SELECT appointment_date AS dt, COUNT(*) AS c
        FROM appointments
        WHERE patient_id = ? AND appointment_date BETWEEN ? AND ?
        GROUP BY appointment_date
    ');
    if ($stmt) {
        $stmt->bind_param('iss', $patientId, $last7Start, $today);
        $stmt->execute();
        $res = $stmt->get_result();
        $map = [];
        if ($res) {
            while ($r = $res->fetch_assoc()) {
                $map[$r['dt']] = (int)$r['c'];
            }
        }
        for ($i = 0; $i < 7; $i++) {
            $d = date('Y-m-d', strtotime($last7Start . ' +' . $i . ' days'));
            if (isset($map[$d])) {
                $seriesValues[$i] = $map[$d];
            }
        }
        $stmt->close();
    }
}
?>

<div class="dental-dashboard-shell">
    <div class="dental-dashboard-hero">
        <div>
            <h4 class="mb-0">Patient Dashboard</h4>
        </div>
        <span class="dental-chip">Care Overview</span>
    </div>

    <div class="row g-3">
        <div class="col-md-6 col-xl-3">
            <?php dashboard_render_kpi_card('Upcoming Appointments', (string)(int)$upcoming, 'bi-calendar-check'); ?>
        </div>
        <div class="col-md-6 col-xl-3">
            <?php dashboard_render_kpi_card('Visit History', (string)(int)$past, 'bi-journal-medical', 'teal'); ?>
        </div>
        <div class="col-md-6 col-xl-3">
            <?php dashboard_render_kpi_card('Outstanding Balance', '₱' . number_format($outstandingBalance, 2), 'bi-wallet2', 'amber'); ?>
        </div>
        <div class="col-md-6 col-xl-3">
            <?php dashboard_render_kpi_card('Next Appointment', $nextAppt ? date('M d, Y', strtotime($nextAppt['appointment_date'])) : 'None', 'bi-calendar-event', 'violet'); ?>
        </div>

        <div class="col-12 col-lg-7">
            <div class="card dental-surface-card">
                <div class="card-body">
                    <h5 class="card-title mb-0">Upcoming Appointments</h5>
                    <?php if (empty($upcomingList)): ?>
                        <div class="text-muted mt-3">No upcoming appointments. <a href="patient.php?page=appointments">Book one now</a>.</div>
                    <?php else: ?>
                        <ul class="list-group list-group-flush mt-3">
                            <?php foreach ($upcomingList as $appt): ?>
                                <?php $status = (string)($appt['status'] ?? 'scheduled'); ?>
                                <li class="list-group-item d-flex justify-content-between align-items-center px-0">
                                    <div>
                                        <div class="fw-semibold"><?php echo date('M d, Y', strtotime($appt['appointment_date'])); ?></div>
                                        <div class="text-muted small">
                                            <?php
                                            $timeLabel = dashboard_format_time($appt['start_time'] ?? null);
                                            echo $timeLabel !== '' ? htmlspecialchars($timeLabel) : 'Time to be confirmed';
                                            ?>
                                        </div>
                                    </div>
                                    <span class="badge bg-<?php echo dashboard_appt_status_badge_class($status); ?>">
                                        <?php echo htmlspecialchars(dashboard_format_status_label($status)); ?>
                                    </span>
                                </li>
                            <?php endforeach; ?>
                        </ul>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <div class="col-12 col-lg-5">
            <div class="card dental-surface-card">
                <div class="card-body">
                    <h5 class="card-title mb-0">Your Visits | Last 7 days</h5>
                    <div class="dental-chart-frame mt-3">
                        <div id="patientAppt7Chart" style="min-height:220px;"></div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<?php dashboard_render_appt_chart_script('patientAppt7Chart', $chartLabels, $seriesValues, '#0b6fbf', 'area'); ?>
