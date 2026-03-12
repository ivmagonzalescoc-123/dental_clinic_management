<?php
include dirname(dirname(__DIR__)) . '/config/database.php';

$userId = (int)($_SESSION['user_id'] ?? 0);
$user = null;
if ($userId > 0) {
    $stmt = $conn->prepare('SELECT first_name, last_name, middle_name, email, phone_number, date_of_birth, gender, address, created_at FROM users WHERE user_id = ? LIMIT 1');
    if ($stmt) {
        $stmt->bind_param('i', $userId);
        $stmt->execute();
        $res = $stmt->get_result();
        $user = $res ? $res->fetch_assoc() : null;
        $stmt->close();
    }
}
?>

<div class="card border-0">
    <div class="card-header d-flex justify-content-between align-items-center">
        <h5 class="card-title mb-0">My Profile</h5>
        <a class="btn btn-primary btn-sm" href="doctor.php?page=settings">Change Password</a>
    </div>
    <div class="card-body">
        <?php if (!$user): ?>
            <div class="alert alert-warning">Unable to load account details.</div>
        <?php else: ?>
            <div class="row g-3">
                <div class="col-md-6"><strong>Name:</strong> <?php echo htmlspecialchars(trim(($user['first_name'] ?? '') . ' ' . ($user['middle_name'] ?? '') . ' ' . ($user['last_name'] ?? ''))); ?></div>
                <div class="col-md-6"><strong>Email:</strong> <?php echo htmlspecialchars((string)($user['email'] ?? '')); ?></div>
                <div class="col-md-6"><strong>Phone:</strong> <?php echo htmlspecialchars((string)($user['phone_number'] ?? '')); ?></div>
                <div class="col-md-6"><strong>Date of Birth:</strong> <?php echo htmlspecialchars((string)($user['date_of_birth'] ?? '')); ?></div>
                <div class="col-md-6"><strong>Gender:</strong> <?php echo htmlspecialchars((string)($user['gender'] ?? '')); ?></div>
                <div class="col-md-6"><strong>Created:</strong> <?php echo htmlspecialchars((string)($user['created_at'] ?? '')); ?></div>
                <div class="col-12"><strong>Address:</strong> <?php echo htmlspecialchars((string)($user['address'] ?? '')); ?></div>
            </div>
        <?php endif; ?>
    </div>
</div>
