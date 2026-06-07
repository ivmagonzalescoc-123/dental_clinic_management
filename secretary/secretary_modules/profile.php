<?php
include dirname(dirname(__DIR__)) . '/config/database.php';
include_once dirname(dirname(__DIR__)) . '/config/profile_helpers.php';
profile_hydrate_session_image($conn);
$profile_reload_url = 'secretary.php?page=profile';

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
        <a class="btn btn-primary btn-sm" href="secretary.php?page=settings">Change Password</a>
    </div>
    <div class="card-body">
        <?php if (!$user): ?>
            <div class="alert alert-warning">Unable to load account details.</div>
        <?php else: ?>
            <div id="profileAlert"></div>
            <?php include dirname(dirname(__DIR__)) . '/includes/profile_photo_upload.php'; ?>
            <form id="profileForm" class="row g-3" novalidate>
                <div class="col-md-4">
                    <label class="form-label">First Name</label>
                    <input type="text" class="form-control" id="firstName" value="<?php echo htmlspecialchars((string)($user['first_name'] ?? '')); ?>" required>
                </div>
                <div class="col-md-4">
                    <label class="form-label">Middle Name</label>
                    <input type="text" class="form-control" id="middleName" value="<?php echo htmlspecialchars((string)($user['middle_name'] ?? '')); ?>">
                </div>
                <div class="col-md-4">
                    <label class="form-label">Last Name</label>
                    <input type="text" class="form-control" id="lastName" value="<?php echo htmlspecialchars((string)($user['last_name'] ?? '')); ?>" required>
                </div>
                <div class="col-md-6">
                    <label class="form-label">Email</label>
                    <input type="email" class="form-control" id="email" value="<?php echo htmlspecialchars((string)($user['email'] ?? '')); ?>" required>
                </div>
                <div class="col-md-6">
                    <label class="form-label">Phone</label>
                    <input type="text" class="form-control" id="phoneNumber" value="<?php echo htmlspecialchars((string)($user['phone_number'] ?? '')); ?>">
                </div>
                <div class="col-md-6">
                    <label class="form-label">Date of Birth</label>
                    <input type="date" class="form-control" id="dateOfBirth" value="<?php echo htmlspecialchars((string)($user['date_of_birth'] ?? '')); ?>">
                </div>
                <div class="col-md-6">
                    <label class="form-label">Gender</label>
                    <select class="form-select" id="gender">
                        <option value="">Select gender</option>
                        <option value="male" <?php echo strtolower((string)($user['gender'] ?? '')) === 'male' ? 'selected' : ''; ?>>Male</option>
                        <option value="female" <?php echo strtolower((string)($user['gender'] ?? '')) === 'female' ? 'selected' : ''; ?>>Female</option>
                        <option value="other" <?php echo strtolower((string)($user['gender'] ?? '')) === 'other' ? 'selected' : ''; ?>>Other</option>
                    </select>
                </div>
                <div class="col-12">
                    <label class="form-label">Address</label>
                    <textarea class="form-control" id="address" rows="2"><?php echo htmlspecialchars((string)($user['address'] ?? '')); ?></textarea>
                </div>
                <div class="col-12 d-flex align-items-center justify-content-between">
                    <small class="text-muted">Account created: <?php echo htmlspecialchars((string)($user['created_at'] ?? '')); ?></small>
                    <button type="submit" class="btn btn-primary" id="saveProfileBtn">Save Changes</button>
                </div>
            </form>
        <?php endif; ?>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function () {
    const form = document.getElementById('profileForm');
    if (!form) return;

    const alertBox = document.getElementById('profileAlert');
    const submitBtn = document.getElementById('saveProfileBtn');

    function showAlert(message, type) {
        if (typeof window.showAppToast === 'function') {
            window.showAppToast(String(message || ''), type || 'info');
        }
        alertBox.innerHTML = '<div class="alert alert-' + type + '">' + message + '</div>';
    }

    form.addEventListener('submit', async function (e) {
        e.preventDefault();
        submitBtn.disabled = true;
        submitBtn.textContent = 'Saving...';

        const payload = {
            csrf_token: <?php echo json_encode($csrf_token ?? ''); ?>,
            first_name: document.getElementById('firstName').value.trim(),
            middle_name: document.getElementById('middleName').value.trim(),
            last_name: document.getElementById('lastName').value.trim(),
            email: document.getElementById('email').value.trim(),
            phone_number: document.getElementById('phoneNumber').value.trim(),
            date_of_birth: document.getElementById('dateOfBirth').value,
            gender: document.getElementById('gender').value,
            address: document.getElementById('address').value.trim()
        };

        try {
            const resp = await fetch('../assets/api/auth/update_profile_api.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify(payload)
            });
            const data = await resp.json();
            if (!resp.ok || !data.success) {
                showAlert(data.message || 'Unable to update profile.', 'danger');
            } else {
                showAlert('Profile updated successfully. Reloading...', 'success');
                setTimeout(function () {
                    window.location.href = 'secretary.php?page=profile';
                }, 700);
            }
        } catch (err) {
            showAlert('Unable to update profile right now. Please try again.', 'danger');
        } finally {
            submitBtn.disabled = false;
            submitBtn.textContent = 'Save Changes';
        }
    });
});
</script>
