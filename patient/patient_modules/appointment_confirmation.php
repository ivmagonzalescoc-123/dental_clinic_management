<?php
include dirname(dirname(__DIR__)) . '/config/database.php';
include_once dirname(dirname(__DIR__)) . '/config/booking_helpers.php';

$appointment_id = isset($_GET['appointment_id']) ? (int)$_GET['appointment_id'] : 0;
$queue_number = isset($_GET['queue_number']) ? trim($_GET['queue_number']) : null;
$appointment = null;
if ($appointment_id > 0) {
    $stmt = $conn->prepare('SELECT a.*, p.first_name AS p_first, p.last_name AS p_last, u.first_name AS d_first, u.last_name AS d_last FROM appointments a LEFT JOIN patients p ON a.patient_id = p.patient_id LEFT JOIN users u ON a.user_id = u.user_id WHERE a.appointment_id = ?');
    if ($stmt) {
        $stmt->bind_param('i', $appointment_id);
        $stmt->execute();
        $res = $stmt->get_result();
        $appointment = $res ? $res->fetch_assoc() : null;
        $stmt->close();
        if ($appointment) {
            $appointment['reference_code'] = appointment_assign_reference($conn, (int)$appointment['appointment_id']);
        }
    }
}
?>

<h5 class="card-title mb-0">Appointment Confirmation</h5>

    <?php if (!$appointment): ?>
            <div class="alert alert-warning">Appointment not found.</div>
        <?php else: ?>
            <p><strong>Reference No.:</strong> <?php echo htmlspecialchars((string)$appointment['reference_code'] ?: '—'); ?></p>
            <p><strong>Patient:</strong> <?php echo htmlspecialchars($appointment['p_first'] . ' ' . $appointment['p_last']); ?></p>
            <p><strong>Doctor:</strong> <?php echo htmlspecialchars($appointment['d_first'] . ' ' . $appointment['d_last']); ?></p>
            <p><strong>Date:</strong> <?php echo htmlspecialchars($appointment['appointment_date']); ?></p>
            <p><strong>Reason:</strong> <?php echo htmlspecialchars($appointment['reason']); ?></p>
            <p><strong>Status:</strong> <?php echo htmlspecialchars($appointment['status']); ?></p>
            <?php if ($queue_number): ?>
                <div class="alert alert-info">You have been checked-in. Queue Number: <strong><?php echo htmlspecialchars($queue_number); ?></strong></div>
            <?php endif; ?>
            <a href="patient.php?page=appointments" class="btn btn-secondary">Back to Appointments</a>
        <?php endif; ?>

