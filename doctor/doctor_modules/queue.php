<?php
include dirname(dirname(__DIR__)) . '/config/database.php';

// Get logged-in doctor ID
$doctor_id = $_SESSION['user_id'] ?? 0;
if ($doctor_id <= 0) {
    echo '<div class="alert alert-danger">You must be logged in to view this page.</div>';
    exit;
}

$today = date('Y-m-d');

// Fetch queue - only checked-in patients for today for this doctor
$queue = [];

// Check if `is_emergency` exists on check_in_queue so we can surface a badge
$hasEmergencyCol = false;
$colRes = $conn->query("SHOW COLUMNS FROM check_in_queue LIKE 'is_emergency'");
if ($colRes && $colRes->num_rows) $hasEmergencyCol = true;

if ($hasEmergencyCol) {
    $queue_sql = "
    SELECT
        a.appointment_id,
        a.patient_id,
        a.reason,
        a.status AS appointment_status,
        p.first_name,
        p.last_name,
        p.email,
        p.phone_number,
            u.first_name AS owner_first, u.last_name AS owner_last,
        NULL as appointment_time,
        q.checkin_id,
        q.que_number,
        q.checkin_time,
        q.status AS queue_status,
        q.is_emergency,
        a.user_id AS owner_id
    FROM check_in_queue q
    INNER JOIN appointments a ON q.appointment_id = a.appointment_id
    INNER JOIN patients p ON a.patient_id = p.patient_id
        LEFT JOIN users u ON a.user_id = u.user_id
    WHERE a.appointment_date = ?
    AND q.status IN ('waiting', 'called', 'in_progress')
    ORDER BY q.is_emergency DESC, q.que_number ASC
    ";
} else {
    $queue_sql = "
    SELECT
        a.appointment_id,
        a.patient_id,
        a.reason,
        a.status AS appointment_status,
        p.first_name,
        p.last_name,
        p.email,
        p.phone_number,
            u.first_name AS owner_first, u.last_name AS owner_last,
        NULL as appointment_time,
        q.checkin_id,
        q.que_number,
        q.checkin_time,
        q.status AS queue_status,
        a.user_id AS owner_id
    FROM check_in_queue q
    INNER JOIN appointments a ON q.appointment_id = a.appointment_id
    INNER JOIN patients p ON a.patient_id = p.patient_id
        LEFT JOIN users u ON a.user_id = u.user_id
    WHERE a.appointment_date = ?
    AND q.status IN ('waiting', 'called', 'in_progress')
    ORDER BY q.que_number ASC
    ";
}

$stmt = $conn->prepare($queue_sql);
if ($stmt) {
    // bind today's appointment date
    $stmt->bind_param('s', $today);
    $stmt->execute();
    $result = $stmt->get_result();
    while ($row = $result->fetch_assoc()) {
        $queue[] = $row;
    }
    $stmt->close();
}
?>

<div class="mb-3">
    <h4>Patient Queue</h4>
    <p class="text-muted">Patients checked-in for today. You can claim patients assigned to others.</p>
</div>

<div class="card">
    <div class="card-body">
        <?php if (empty($queue)): ?>
            <div class="alert alert-info">
                <i class="bi bi-info-circle"></i> No patients in queue yet.
            </div>
        <?php else: ?>
            <div id="doctorQueueList">
                <div class="list-group">
                <?php foreach ($queue as $patient): ?>
                    <div class="list-group-item" data-appointment-id="<?php echo (int)$patient['appointment_id']; ?>">
                        <div class="d-flex justify-content-between align-items-start">
                            <div>
                                <h5 class="mb-1">
                                    <span class="badge bg-primary"><?php echo htmlspecialchars($patient['que_number']); ?></span>
                                    <?php if (!empty($patient['is_emergency'])): ?>
                                        <span class="badge bg-danger ms-1">EMERGENCY</span>
                                    <?php endif; ?>
                                    <?php echo htmlspecialchars($patient['first_name'] . ' ' . $patient['last_name']); ?>
                                </h5>
                                <p class="mb-1 small text-muted">
                                    <i class="bi bi-telephone"></i> <?php echo htmlspecialchars($patient['phone_number']); ?>
                                </p>
                                <?php if (isset($patient['owner_first']) && $patient['owner_first']): ?>
                                    <?php $ownerName = htmlspecialchars(trim($patient['owner_first'] . ' ' . ($patient['owner_last'] ?? ''))); ?>
                                    <?php if ((int)$patient['owner_id'] !== (int)$doctor_id): ?>
                                        <p class="mb-1 small text-muted">Assigned to: <?php echo $ownerName; ?></p>
                                    <?php endif; ?>
                                <?php endif; ?>
                                <p class="mb-0 small text-muted">
                                    <i class="bi bi-clock"></i> <?php echo htmlspecialchars($patient['appointment_time']); ?> | 
                                    Reason: <?php echo htmlspecialchars($patient['reason']); ?>
                                </p>
                            </div>
                            <div class="btn-group-vertical ms-2">
                                <button type="button" class="btn btn-sm btn-danger mb-1" onclick="declinePatientAjax(<?php echo (int)$patient['appointment_id']; ?>)">Decline</button>
                                <button type="button" class="btn btn-sm btn-success" onclick="acceptPatientAjax(<?php echo (int)$patient['appointment_id']; ?>)">Accept</button>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
                </div>
            </div>
        <?php endif; ?>
    </div>
</div>
