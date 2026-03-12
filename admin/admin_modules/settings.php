<?php
$forceChange = isset($_GET['force_password_change']) ? 1 : 0;
?>

<div class="card border-0">
    <div class="card-header d-flex justify-content-between align-items-center">
        <h5 class="card-title mb-0">Account Settings</h5>
        <button class="btn btn-primary btn-sm" id="openPasswordModalBtn" type="button">Change Password</button>
    </div>
    <div class="card-body">
        <?php if ($forceChange): ?>
            <div class="alert alert-warning">For security, you must change your default password before using other pages.</div>
        <?php endif; ?>
        <p class="text-muted mb-0">Update your password anytime. Password must meet policy requirements.</p>
        <div id="passwordChangeAlert" class="mt-3"></div>
    </div>
</div>

<div class="modal fade" id="changePasswordModal" tabindex="-1" aria-hidden="true" data-bs-backdrop="static" data-bs-keyboard="false">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Change Password</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <form id="passwordChangeForm" autocomplete="off">
                    <div class="mb-3">
                        <label class="form-label">Current Password</label>
                        <input type="password" class="form-control" id="currentPassword" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">New Password</label>
                        <input type="password" class="form-control" id="newPassword" required minlength="8">
                        <small class="text-muted">Minimum 8 chars with uppercase, lowercase, number, and special character.</small>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Confirm New Password</label>
                        <input type="password" class="form-control" id="confirmPassword" required minlength="8">
                    </div>
                    <button type="submit" class="btn btn-primary">Update Password</button>
                </form>
            </div>
        </div>
    </div>
</div>

<script>
(function () {
    const forceChange = <?php echo $forceChange ? 'true' : 'false'; ?>;
    const csrfToken = <?php echo json_encode($csrf_token ?? ''); ?>;
    const modalEl = document.getElementById('changePasswordModal');
    const form = document.getElementById('passwordChangeForm');
    const openBtn = document.getElementById('openPasswordModalBtn');
    const alertBox = document.getElementById('passwordChangeAlert');
    if (!modalEl || !form || !window.bootstrap) return;

    const modal = new bootstrap.Modal(modalEl, { backdrop: forceChange ? 'static' : true, keyboard: !forceChange });

    function showAlert(message, type) {
        alertBox.innerHTML = '<div class="alert alert-' + type + '">' + message + '</div>';
    }

    openBtn.addEventListener('click', function () { modal.show(); });
    if (forceChange) modal.show();

    form.addEventListener('submit', async function (e) {
        e.preventDefault();
        const currentPassword = document.getElementById('currentPassword').value;
        const newPassword = document.getElementById('newPassword').value;
        const confirmPassword = document.getElementById('confirmPassword').value;

        const strongPass = /^(?=.*[a-z])(?=.*[A-Z])(?=.*\d)(?=.*[^A-Za-z0-9]).{8,}$/;
        if (!strongPass.test(newPassword)) {
            showAlert('Password must be at least 8 characters with uppercase, lowercase, number, and special character.', 'danger');
            return;
        }
        if (newPassword !== confirmPassword) {
            showAlert('New password and confirmation do not match.', 'danger');
            return;
        }

        try {
            const resp = await fetch('../assets/api/auth/change_password_api.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    current_password: currentPassword,
                    new_password: newPassword,
                    confirm_password: confirmPassword,
                    csrf_token: csrfToken,
                }),
            });
            const data = await resp.json();
            if (data.success) {
                showAlert('Password updated successfully.', 'success');
                modal.hide();
                setTimeout(function () { window.location.href = 'admin.php?page=dashboard'; }, 700);
            } else {
                showAlert(data.message || 'Failed to update password.', 'danger');
            }
        } catch (err) {
            showAlert('Unable to update password right now. Please try again.', 'danger');
        }
    });
})();
</script>
