<?php
include dirname(dirname(__DIR__)) . '/config/database.php';

// Doctor dashboard: basic stats and last 7 days appointments (doctor-scoped)
$userId = $_SESSION['user_id'] ?? 0;
$today = date('Y-m-d');

$upcoming = 0;
$past = 0;
$queueToday = 0;
$appointmentsTotal = 0;

$stmt = $conn->prepare("SELECT COUNT(*) FROM appointments WHERE user_id = ? AND DATE(appointment_date) >= ? AND status IN ('scheduled','confirmed')");
if ($stmt) { $stmt->bind_param('is', $userId, $today); $stmt->execute(); $res = $stmt->get_result(); $upcoming = $res ? (int)$res->fetch_row()[0] : 0; $stmt->close(); }

$stmt = $conn->prepare("SELECT COUNT(*) FROM appointments WHERE user_id = ? AND DATE(appointment_date) < ?");
if ($stmt) { $stmt->bind_param('is', $userId, $today); $stmt->execute(); $res = $stmt->get_result(); $past = $res ? (int)$res->fetch_row()[0] : 0; $stmt->close(); }

$stmt = $conn->prepare("SELECT COUNT(*)
                                                FROM appointments a
                                                LEFT JOIN timeslot t ON t.timeslot_id = a.timeslot_id
                                                WHERE COALESCE(a.user_id, t.doctor_id) = ?
                                                    AND COALESCE(t.slot_date, a.appointment_date) = ?
                                                    AND a.status IN ('confirmed','checked_in','in_progress')");
if ($stmt) { $stmt->bind_param('is', $userId, $today); $stmt->execute(); $res = $stmt->get_result(); $queueToday = $res ? (int)$res->fetch_row()[0] : 0; $stmt->close(); }

$appointmentsTotal = 0;
$stmt = $conn->prepare("SELECT COUNT(*) FROM appointments WHERE user_id = ?");
if ($stmt) { $stmt->bind_param('i', $userId); $stmt->execute(); $res = $stmt->get_result(); $appointmentsTotal = $res ? (int)$res->fetch_row()[0] : 0; $stmt->close(); }

// Last 7 days series
$last7Start = date('Y-m-d', strtotime('-6 days'));
$seriesLabels = [];
$seriesValues = [];
for ($i = 0; $i < 7; $i++) { $d = date('Y-m-d', strtotime($last7Start . ' +' . $i . ' days')); $seriesLabels[] = $d; $seriesValues[] = 0; }

$stmt = $conn->prepare('SELECT DATE(appointment_date) as dt, COUNT(*) AS c FROM appointments WHERE user_id = ? AND DATE(appointment_date) BETWEEN ? AND ? GROUP BY dt ORDER BY dt ASC');
if ($stmt) {
    $stmt->bind_param('iss', $userId, $last7Start, $today);
    $stmt->execute();
    $res = $stmt->get_result();
    $map = [];
    if ($res) while ($r = $res->fetch_assoc()) $map[$r['dt']] = (int)$r['c'];
    foreach ($seriesLabels as $idx => $d) if (isset($map[$d])) $seriesValues[$idx] = $map[$d];
    $stmt->close();
}

// Next 6 appointments
$nextAppts = [];
$stmt = $conn->prepare("SELECT a.appointment_id, a.appointment_date, a.status, p.first_name, p.last_name FROM appointments a LEFT JOIN patients p ON p.patient_id = a.patient_id WHERE a.user_id = ? AND DATE(a.appointment_date) >= ? ORDER BY a.appointment_date ASC LIMIT 6");
if ($stmt) { $stmt->bind_param('is', $userId, $today); $stmt->execute(); $res = $stmt->get_result(); if ($res) while ($r = $res->fetch_assoc()) $nextAppts[] = $r; $stmt->close(); }

?>

<div class="dental-dashboard-shell">
    <div class="dental-dashboard-hero">
        <div>
            <h4 class="mb-1">Doctor Dashboard</h4>
            <p class="small">Track patient flow, upcoming care sessions, and your weekly appointment trend.</p>
        </div>
        <span class="dental-chip">Today: <?php echo date('M d, Y'); ?></span>
    </div>

<div class="row g-3">
    <div class="col-md-4">
        <div class="card dental-kpi-card p-3">
            <h6 class="mb-1">Upcoming Appointments</h6>
            <div class="display-6 fw-bold"><?php echo (int)$upcoming; ?></div>
            <div class="text-muted small">Scheduled & confirmed</div>
        </div>
    </div>
    <div class="col-md-4">
        <div class="card dental-kpi-card p-3">
            <h6 class="mb-1">Past Visits</h6>
            <div class="display-6 fw-bold"><?php echo (int)$past; ?></div>
            <div class="text-muted small">Historical appointments</div>
        </div>
    </div>
    <div class="col-md-4">
        <div class="card dental-kpi-card p-3">
            <h6 class="mb-1">Patients Today</h6>
            <div class="display-6 fw-bold"><?php echo (int)$queueToday; ?></div>
            <div class="text-muted small">Pending / arrived</div>
        </div>
    </div>

    <div class="col-12">
        <div class="card dental-surface-card">
            <div class="card-body">
                <div class="d-flex justify-content-between align-items-center">
                    <h5 class="card-title mb-0">Appointments | Last 7 days</h5>
                    <div class="text-muted small">Total: <?php echo (int)$appointmentsTotal; ?></div>
                </div>
                <div class="dental-chart-frame mt-3">
                    <div id="doctorAppt7Chart" style="min-height:220px;"></div>
                </div>
            </div>
        </div>
    </div>

    <div class="col-12 col-md-6">
        <div class="card dental-surface-card">
            <div class="card-body">
                <h6 class="card-title">Upcoming Appointments</h6>
                <?php if (empty($nextAppts)): ?>
                    <div class="text-muted">No upcoming appointments.</div>
                <?php else: ?>
                    <ul class="list-group list-group-flush">
                        <?php foreach ($nextAppts as $na): ?>
                            <li class="list-group-item d-flex justify-content-between align-items-center">
                                <div>
                                    <div class="fw-semibold"><?php echo htmlspecialchars($na['first_name'] . ' ' . $na['last_name']); ?></div>
                                    <div class="text-muted small"><?php echo date('M d, Y', strtotime($na['appointment_date'])); ?></div>
                                </div>
                                <span class="badge bg-secondary"><?php echo htmlspecialchars($na['status']); ?></span>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>
</div>

<script>
    (function () {
        if (typeof ApexCharts === 'undefined') return;
        const labels = <?php echo json_encode($seriesLabels); ?>;
        const values = <?php echo json_encode($seriesValues); ?>;
        const opts = { chart: { type: 'area', height: 260 }, series: [{ name: 'Appointments', data: values }], xaxis: { categories: labels }, stroke: { curve: 'smooth' }, colors: ['#28a745'] };
        new ApexCharts(document.getElementById('doctorAppt7Chart'), opts).render();
    })();
</script>
