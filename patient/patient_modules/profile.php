<?php
include dirname(dirname(__DIR__)) . '/config/database.php';

$patientId = (int)($_SESSION['patient_id'] ?? 0);
$patient = null;
if ($patientId > 0) {
    $stmt = $conn->prepare('SELECT first_name, last_name, middle_name, email, phone_number, date_of_birth, gender, address, created_at FROM patients WHERE patient_id = ? LIMIT 1');
    if ($stmt) {
        $stmt->bind_param('i', $patientId);
        $stmt->execute();
        $res = $stmt->get_result();
        $patient = $res ? $res->fetch_assoc() : null;
        $stmt->close();
    }
}
?>

<div class="card border-0">
    <div class="card-header d-flex justify-content-between align-items-center">
        <h5 class="card-title mb-0">My Profile</h5>
        <a class="btn btn-primary btn-sm" href="patient.php?page=settings">Change Password</a>
    </div>
    <div class="card-body">
        <?php if (!$patient): ?>
            <div class="alert alert-warning">Unable to load account details.</div>
        <?php else: ?>
            <div class="row g-3">
                <div class="col-md-6"><strong>Name:</strong> <?php echo htmlspecialchars(trim(($patient['first_name'] ?? '') . ' ' . ($patient['middle_name'] ?? '') . ' ' . ($patient['last_name'] ?? ''))); ?></div>
                <div class="col-md-6"><strong>Email:</strong> <?php echo htmlspecialchars((string)($patient['email'] ?? '')); ?></div>
                <div class="col-md-6"><strong>Phone:</strong> <?php echo htmlspecialchars((string)($patient['phone_number'] ?? '')); ?></div>
                <div class="col-md-6"><strong>Date of Birth:</strong> <?php echo htmlspecialchars((string)($patient['date_of_birth'] ?? '')); ?></div>
                <div class="col-md-6"><strong>Gender:</strong> <?php echo htmlspecialchars((string)($patient['gender'] ?? '')); ?></div>
                <div class="col-md-6"><strong>Created:</strong> <?php echo htmlspecialchars((string)($patient['created_at'] ?? '')); ?></div>
                <div class="col-12"><strong>Address:</strong> <?php echo htmlspecialchars((string)($patient['address'] ?? '')); ?></div>
            </div>
        <?php endif; ?>
    </div>
</div>
