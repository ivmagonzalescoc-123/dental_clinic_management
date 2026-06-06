<?php
include dirname(dirname(__DIR__)) . '/config/database.php';
include_once dirname(dirname(__DIR__)) . '/config/dashboard_helpers.php';

$userId = (int)($_SESSION['user_id'] ?? 0);
$today = date('Y-m-d');

$upcoming = 0;
$past = 0;
$queueToday = 0;
$inProgressToday = 0;
$appointmentsTotal = 0;

$doctorScope = "
    FROM appointments a
    LEFT JOIN timeslot t ON t.timeslot_id = a.timeslot_id
    WHERE COALESCE(a.user_id, t.doctor_id) = ?
";

if ($userId > 0) {
    $upcoming = dashboard_scalar_int(
        $conn,
        "SELECT COUNT(*) $doctorScope AND COALESCE(t.slot_date, a.appointment_date) >= ? AND a.status IN ('scheduled','confirmed','checked_in','in_progress','rescheduled')",
        'is',
        [$userId, $today]
    );
    $past = dashboard_scalar_int(
        $conn,
        "SELECT COUNT(*) $doctorScope AND (COALESCE(t.slot_date, a.appointment_date) < ? OR a.status IN ('completed','records_complete','cancelled','no_show','awaiting_secretary_records'))",
        'is',
        [$userId, $today]
    );
    $queueToday = dashboard_scalar_int(
        $conn,
        "SELECT COUNT(*) $doctorScope AND COALESCE(t.slot_date, a.appointment_date) = ? AND a.status IN ('confirmed','checked_in','in_progress')",
        'is',
        [$userId, $today]
    );
    $inProgressToday = dashboard_scalar_int(
        $conn,
        "SELECT COUNT(*) $doctorScope AND COALESCE(t.slot_date, a.appointment_date) = ? AND a.status = 'in_progress'",
        'is',
        [$userId, $today]
    );
    $appointmentsTotal = dashboard_scalar_int(
        $conn,
        'SELECT COUNT(*) FROM appointments WHERE user_id = ?',
        'i',
        [$userId]
    );
}

[, $seriesValues, $chartLabels] = dashboard_last7_series($conn, $userId > 0 ? $userId : null);

$nextAppts = [];
if ($userId > 0) {
    $stmt = $conn->prepare("
        SELECT a.appointment_id, COALESCE(t.slot_date, a.appointment_date) AS appointment_date,
               a.status, t.start_time, p.first_name, p.last_name
        FROM appointments a
        LEFT JOIN timeslot t ON t.timeslot_id = a.timeslot_id
        LEFT JOIN patients p ON p.patient_id = a.patient_id
        WHERE COALESCE(a.user_id, t.doctor_id) = ?
          AND COALESCE(t.slot_date, a.appointment_date) >= ?
          AND a.status IN ('scheduled','confirmed','checked_in','in_progress','rescheduled')
        ORDER BY appointment_date ASC, COALESCE(t.start_time, '23:59:59') ASC
        LIMIT 6
    ");
    if ($stmt) {
        $stmt->bind_param('is', $userId, $today);
        $stmt->execute();
        $res = $stmt->get_result();
        if ($res) {
            while ($r = $res->fetch_assoc()) {
                $nextAppts[] = $r;
            }
        }
        $stmt->close();
    }
}
?>

<div class="dental-dashboard-shell">
    <div class="dental-dashboard-hero">
        <div>
            <h4 class="mb-0">Doctor Dashboard</h4>
        </div>
        <span class="dental-chip">Today: <?php echo date('M d, Y'); ?></span>
    </div>

    <div class="row g-3">
        <div class="col-md-6 col-xl-3">
            <div class="card dental-kpi-card p-3 h-100">
                <h6 class="mb-1">Upcoming</h6>
                <div class="display-6 fw-bold"><?php echo (int)$upcoming; ?></div>
            </div>
        </div>
        <div class="col-md-6 col-xl-3">
            <div class="card dental-kpi-card p-3 h-100">
                <h6 class="mb-1">Patients Today</h6>
                <div class="display-6 fw-bold"><?php echo (int)$queueToday; ?></div>
            </div>
        </div>
        <div class="col-md-6 col-xl-3">
            <div class="card dental-kpi-card p-3 h-100">
                <h6 class="mb-1">Visit History</h6>
                <div class="display-6 fw-bold"><?php echo (int)$past; ?></div>
            </div>
        </div>
        <div class="col-md-6 col-xl-3">
            <div class="card dental-kpi-card p-3 h-100">
                <h6 class="mb-1">All Appointments</h6>
                <div class="display-6 fw-bold"><?php echo (int)$appointmentsTotal; ?></div>
            </div>
        </div>

        <div class="col-12">
            <div class="card dental-surface-card">
                <div class="card-body">
                    <h5 class="card-title mb-0">Appointments | Last 7 days</h5>
                    <div class="dental-chart-frame mt-3">
                        <div id="doctorAppt7Chart" style="min-height:220px;"></div>
                    </div>
                </div>
            </div>
        </div>

        <div class="col-12">
            <div class="card dental-surface-card">
                <div class="card-body">
                    <div class="d-flex justify-content-between align-items-center">
                        <h6 class="card-title mb-0">Upcoming Appointments</h6>
                        <a class="small" href="doctor.php?page=appointments">View all</a>
                    </div>
                    <?php if (empty($nextAppts)): ?>
                        <div class="text-muted mt-3">No upcoming appointments.</div>
                    <?php else: ?>
                        <ul class="list-group list-group-flush mt-3">
                            <?php foreach ($nextAppts as $na): ?>
                                <?php $status = (string)($na['status'] ?? 'scheduled'); ?>
                                <li class="list-group-item d-flex justify-content-between align-items-center px-0">
                                    <div>
                                        <div class="fw-semibold"><?php echo htmlspecialchars(trim(($na['first_name'] ?? '') . ' ' . ($na['last_name'] ?? ''))); ?></div>
                                        <div class="text-muted small">
                                            <?php echo date('M d, Y', strtotime($na['appointment_date'])); ?>
                                            <?php
                                            $timeLabel = dashboard_format_time($na['start_time'] ?? null);
                                            if ($timeLabel !== '') {
                                                echo ' • ' . htmlspecialchars($timeLabel);
                                            }
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
    </div>
</div>

<?php dashboard_render_appt_chart_script('doctorAppt7Chart', $chartLabels, $seriesValues, '#28a745', 'area'); ?>
