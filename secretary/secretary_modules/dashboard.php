<?php
include dirname(dirname(__DIR__)) . '/config/database.php';
include_once dirname(dirname(__DIR__)) . '/config/dashboard_helpers.php';
include_once dirname(dirname(__DIR__)) . '/config/billing_helpers.php';

billing_ensure_tables($conn);

$today = date('Y-m-d');

$arrivedToday = dashboard_scalar_int(
    $conn,
    "SELECT COUNT(*) FROM appointments WHERE appointment_date = ? AND status IN ('checked_in','in_progress')",
    's',
    [$today]
);
$pendingToday = dashboard_scalar_int(
    $conn,
    "SELECT COUNT(*) FROM appointments WHERE appointment_date = ? AND status IN ('scheduled','confirmed')",
    's',
    [$today]
);
$appointmentsToday = dashboard_scalar_int(
    $conn,
    'SELECT COUNT(*) FROM appointments WHERE appointment_date = ?',
    's',
    [$today]
);
$recordsQueue = dashboard_scalar_int(
    $conn,
    "SELECT COUNT(*) FROM appointments WHERE status = 'awaiting_secretary_records'"
);
$unpaidBills = dashboard_scalar_int(
    $conn,
    "SELECT COUNT(*) FROM billing WHERE balance > 0"
);

[, $seriesValues, $chartLabels] = dashboard_last7_series($conn);

$todayAppointments = [];
$stmt = $conn->prepare("
    SELECT a.appointment_id, a.appointment_date, a.status, a.reason,
           t.start_time, p.first_name, p.last_name,
           d.first_name AS doctor_first_name, d.last_name AS doctor_last_name
    FROM appointments a
    LEFT JOIN timeslot t ON t.timeslot_id = a.timeslot_id
    LEFT JOIN patients p ON p.patient_id = a.patient_id
    LEFT JOIN users d ON d.user_id = a.user_id
    WHERE a.appointment_date = ?
    ORDER BY COALESCE(t.start_time, '23:59:59'), a.created_at ASC
    LIMIT 8
");
if ($stmt) {
    $stmt->bind_param('s', $today);
    $stmt->execute();
    $res = $stmt->get_result();
    if ($res) {
        while ($r = $res->fetch_assoc()) {
            $todayAppointments[] = $r;
        }
    }
    $stmt->close();
}
?>

<div class="dental-dashboard-shell">
    <div class="dental-dashboard-hero">
        <div>
            <h4 class="mb-0">Secretary Dashboard</h4>
        </div>
        <span class="dental-chip">Today: <?php echo date('M d, Y'); ?></span>
    </div>

    <div class="row g-3">
        <div class="col-md-6 col-xl-4 col-xxl-2">
            <div class="card dental-kpi-card p-3 h-100">
                <h6 class="mb-1">Arrived Today</h6>
                <div class="display-6 fw-bold"><?php echo (int)$arrivedToday; ?></div>
            </div>
        </div>
        <div class="col-md-6 col-xl-4 col-xxl-2">
            <div class="card dental-kpi-card p-3 h-100">
                <h6 class="mb-1">Pending Today</h6>
                <div class="display-6 fw-bold"><?php echo (int)$pendingToday; ?></div>
            </div>
        </div>
        <div class="col-md-6 col-xl-4 col-xxl-2">
            <div class="card dental-kpi-card p-3 h-100">
                <h6 class="mb-1">Records Queue</h6>
                <div class="display-6 fw-bold"><?php echo (int)$recordsQueue; ?></div>
            </div>
        </div>
        <div class="col-md-6 col-xl-4 col-xxl-2">
            <div class="card dental-kpi-card p-3 h-100">
                <h6 class="mb-1">Unpaid Bills</h6>
                <div class="display-6 fw-bold"><?php echo (int)$unpaidBills; ?></div>
            </div>
        </div>
        <div class="col-md-6 col-xl-4 col-xxl-2">
            <div class="card dental-kpi-card p-3 h-100">
                <h6 class="mb-1">Appointments Today</h6>
                <div class="display-6 fw-bold"><?php echo (int)$appointmentsToday; ?></div>
            </div>
        </div>

        <div class="col-12 col-xxl-7">
            <div class="card dental-surface-card">
                <div class="card-body">
                    <div class="d-flex justify-content-between align-items-center">
                        <h5 class="card-title mb-0">Today's Schedule</h5>
                        <a class="small" href="secretary.php?page=appointments">View all</a>
                    </div>
                    <?php if (empty($todayAppointments)): ?>
                        <div class="alert alert-info mb-0 mt-3">No appointments scheduled for today.</div>
                    <?php else: ?>
                        <div class="table-responsive mt-3">
                            <table class="table table-sm table-hover align-middle mb-0">
                                <thead>
                                    <tr>
                                        <th style="width:90px;">Time</th>
                                        <th>Patient</th>
                                        <th>Doctor</th>
                                        <th style="width:130px;">Status</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($todayAppointments as $a): ?>
                                        <?php
                                        $patient = trim(($a['first_name'] ?? '') . ' ' . ($a['last_name'] ?? ''));
                                        $doctor = trim(($a['doctor_first_name'] ?? '') . ' ' . ($a['doctor_last_name'] ?? ''));
                                        $status = (string)($a['status'] ?? 'scheduled');
                                        $timeLabel = dashboard_format_time($a['start_time'] ?? null);
                                        ?>
                                        <tr>
                                            <td class="text-muted"><?php echo $timeLabel !== '' ? htmlspecialchars($timeLabel) : '—'; ?></td>
                                            <td>
                                                <div class="fw-semibold"><?php echo htmlspecialchars($patient !== '' ? $patient : 'Unknown'); ?></div>
                                                <?php if (!empty($a['reason'])): ?>
                                                    <div class="text-muted small"><?php echo htmlspecialchars((string)$a['reason']); ?></div>
                                                <?php endif; ?>
                                            </td>
                                            <td><?php echo htmlspecialchars($doctor !== '' ? $doctor : '—'); ?></td>
                                            <td>
                                                <span class="badge bg-<?php echo dashboard_appt_status_badge_class($status); ?>">
                                                    <?php echo htmlspecialchars(dashboard_format_status_label($status)); ?>
                                                </span>
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

        <div class="col-12 col-xxl-5">
            <div class="card dental-surface-card">
                <div class="card-body">
                    <h5 class="card-title mb-0">Appointments | Last 7 days</h5>
                    <div class="dental-chart-frame mt-3">
                        <div id="secAppt7Chart" style="min-height:220px;"></div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<?php dashboard_render_appt_chart_script('secAppt7Chart', $chartLabels, $seriesValues, '#ff7f50', 'area'); ?>
