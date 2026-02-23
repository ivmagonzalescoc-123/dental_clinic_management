<?php
include dirname(dirname(__DIR__)) . '/config/database.php';
include_once dirname(dirname(__DIR__)) . '/config/session_check.php';
check_session(['doctor']);

$doctor_id = $_SESSION['user_id'] ?? 0;

$records = [];
$sql = "
    SELECT cr.record_id, cr.appointment_id, cr.status, cr.submitted_at, a.appointment_date, p.first_name, p.last_name
    FROM clinical_records cr
    INNER JOIN appointments a ON cr.appointment_id = a.appointment_id
    INNER JOIN patients p ON cr.patient_id = p.patient_id
    WHERE cr.doctor_id = ? OR a.user_id = ?
    ORDER BY COALESCE(cr.submitted_at, a.appointment_date) DESC
";
$stmt = $conn->prepare($sql);
if ($stmt) {
    $stmt->bind_param('ii', $doctor_id, $doctor_id);
    $stmt->execute();
    $res = $stmt->get_result();
    while ($r = $res->fetch_assoc()) $records[] = $r;
    $stmt->close();
}
?>

<div class="card">
    <div class="card-body">
        <h4>Clinical Records</h4>
        <p class="text-muted">List of clinical records you created or are assigned to.</p>

        <div class="table-responsive">
            <table class="table table-hover datatable">
                <thead>
                    <tr>
                        <th>Record #</th>
                        <th>Appointment</th>
                        <th>Patient</th>
                        <th>Submitted At</th>
                        <th>Status</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($records)): ?>
                        <tr><td colspan="6" class="text-center text-muted">No clinical records found.</td></tr>
                    <?php else: ?>
                        <?php foreach ($records as $rec): ?>
                            <tr>
                                <td>#<?php echo htmlspecialchars($rec['record_id']); ?></td>
                                <td><?php echo htmlspecialchars($rec['appointment_date']); ?> (ID <?php echo (int)$rec['appointment_id']; ?>)</td>
                                <td><?php echo htmlspecialchars($rec['first_name'] . ' ' . $rec['last_name']); ?></td>
                                <td><?php echo htmlspecialchars($rec['submitted_at'] ?? '-'); ?></td>
                                <td><?php echo htmlspecialchars(ucfirst($rec['status'] ?? 'in_progress')); ?></td>
                                <td>
                                    <a class="btn btn-sm btn-primary" href="doctor.php?page=profiling&appointment_id=<?php echo (int)$rec['appointment_id']; ?>">Open Profiling</a>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>
