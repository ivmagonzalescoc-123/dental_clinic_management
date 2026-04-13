<?php
include dirname(dirname(__DIR__)) . '/config/database.php';

$patientId = $_SESSION['user_patient_id'] ?? null;
if (!$patientId) {
    // try to map from users table
    $stmt = $conn->prepare('SELECT patient_id FROM patients WHERE user_id = ? LIMIT 1');
    if ($stmt) { $stmt->bind_param('i', $_SESSION['user_id']); $stmt->execute(); $r = $stmt->get_result(); $row = $r ? $r->fetch_assoc() : null; $patientId = $row['patient_id'] ?? null; $stmt->close(); }
}

$today = date('Y-m-d');
$upcoming = 0; $past = 0; $nextAppt = null;
if ($patientId) {
    $stmt = $conn->prepare('SELECT COUNT(*) FROM appointments WHERE patient_id = ? AND DATE(appointment_date) >= ?');
    if ($stmt) { $stmt->bind_param('is', $patientId, $today); $stmt->execute(); $r = $stmt->get_result(); $upcoming = $r ? (int)$r->fetch_row()[0] : 0; $stmt->close(); }
    $stmt = $conn->prepare('SELECT COUNT(*) FROM appointments WHERE patient_id = ? AND DATE(appointment_date) < ?');
    if ($stmt) { $stmt->bind_param('is', $patientId, $today); $stmt->execute(); $r = $stmt->get_result(); $past = $r ? (int)$r->fetch_row()[0] : 0; $stmt->close(); }
    $stmt = $conn->prepare('SELECT appointment_id, appointment_date, status FROM appointments WHERE patient_id = ? AND DATE(appointment_date) >= ? ORDER BY appointment_date ASC LIMIT 1');
    if ($stmt) { $stmt->bind_param('is', $patientId, $today); $stmt->execute(); $r = $stmt->get_result(); $nextAppt = $r ? $r->fetch_assoc() : null; $stmt->close(); }
}
?>

<div class="dental-dashboard-shell">
    <div class="dental-dashboard-hero">
        <div>
            <h4 class="mb-1">Patient Dashboard</h4>
            <p class="small">View your treatment timeline and upcoming appointments at a glance.</p>
        </div>
        <span class="dental-chip">Care Overview</span>
    </div>

    <div class="row g-3">
        <div class="col-md-4">
            <div class="card dental-kpi-card p-3 h-100">
                <h6 class="mb-1">Upcoming Appointments</h6>
                <div class="display-6 fw-bold"><?php echo (int)$upcoming; ?></div>
                <div class="text-muted small">Scheduled and upcoming visits</div>
            </div>
        </div>
        <div class="col-md-4">
            <div class="card dental-kpi-card p-3 h-100">
                <h6 class="mb-1">Past Visits</h6>
                <div class="display-6 fw-bold"><?php echo (int)$past; ?></div>
                <div class="text-muted small">Completed appointment history</div>
            </div>
        </div>
        <div class="col-md-4">
            <div class="card dental-kpi-card p-3 h-100">
                <h6 class="mb-1">Next Appointment</h6>
                <div class="fw-semibold"><?php echo $nextAppt ? date('M d, Y', strtotime($nextAppt['appointment_date'])) : 'None'; ?></div>
                <div class="text-muted small"><?php echo $nextAppt ? htmlspecialchars($nextAppt['status']) : 'No active booking'; ?></div>
            </div>
        </div>

    </div>
</div>
