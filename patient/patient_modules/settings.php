<div class="card border-0 shadow-sm">
    <div class="card-header">
        <h5 class="card-title mb-0">Account Settings</h5>
    </div>
    <div class="card-body">
        <p class="text-muted">Update your password anytime. Password must meet policy requirements.</p>
        <div id="passwordChangeAlert" class="mt-3"></div>
        <form id="passwordChangeForm" class="mt-3" autocomplete="off">
            <div class="mb-3">
                <label class="form-label">Current Password</label>
                <input type="password" class="form-control" id="currentPassword" required>
            </div>
            <div class="mb-3">
                <label class="form-label">New Password</label>
                <input type="password" class="form-control" id="newPassword" required minlength="8" pattern="(?=.*[a-z])(?=.*[A-Z])(?=.*\d)(?=.*[^A-Za-z0-9]).{8,}">
                <div id="passwordPolicyHints" class="small mt-2 d-none">
                    <div id="rule-length" class="text-danger">- At least 8 characters</div>
                    <div id="rule-upper" class="text-danger">- At least one uppercase letter (A-Z)</div>
                    <div id="rule-lower" class="text-danger">- At least one lowercase letter (a-z)</div>
                    <div id="rule-number" class="text-danger">- At least one number (0-9)</div>
                    <div id="rule-special" class="text-danger">- At least one special character</div>
                </div>
            </div>
            <div class="mb-3">
                <label class="form-label">Confirm New Password</label>
                <input type="password" class="form-control" id="confirmPassword" required minlength="8">
            </div>
            <button type="submit" class="btn btn-primary"><i class="bi bi-shield-lock me-1"></i>Update Password</button>
        </form>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    const form = document.getElementById('passwordChangeForm');
    const csrfToken = <?php echo json_encode($csrf_token ?? ''); ?>;
    const newPasswordInput = document.getElementById('newPassword');
    const passwordPolicyHints = document.getElementById('passwordPolicyHints');
    let isEditingNewPassword = false;
    if (!form) return;

    function showAlert(message, type) {
        if (typeof window.showToast === 'function') {
            window.showToast(String(message || ''), type || 'info');
        }
    }

    function setRuleState(id, passed) {
        const el = document.getElementById(id);
        if (!el) return;
        el.classList.toggle('d-none', passed);
    }

    function updatePasswordHints(password) {
        const value = String(password || '');
        const passLength = value.length >= 8;
        const passUpper = /[A-Z]/.test(value);
        const passLower = /[a-z]/.test(value);
        const passNumber = /\d/.test(value);
        const passSpecial = /[^A-Za-z0-9]/.test(value);

        setRuleState('rule-length', passLength);
        setRuleState('rule-upper', passUpper);
        setRuleState('rule-lower', passLower);
        setRuleState('rule-number', passNumber);
        setRuleState('rule-special', passSpecial);

        if (passwordPolicyHints) {
            const allPassed = passLength && passUpper && passLower && passNumber && passSpecial;
            passwordPolicyHints.classList.toggle('d-none', !isEditingNewPassword || allPassed);
        }
    }

    if (typeof window.initPasswordToggles === 'function') {
        window.initPasswordToggles(form);
    }

    if (newPasswordInput) {
        newPasswordInput.addEventListener('focus', function () {
            isEditingNewPassword = true;
            updatePasswordHints(this.value);
        });
        newPasswordInput.addEventListener('input', function () {
            updatePasswordHints(this.value);
        });
        newPasswordInput.addEventListener('blur', function () {
            isEditingNewPassword = false;
            if (passwordPolicyHints) {
                passwordPolicyHints.classList.add('d-none');
            }
        });
        updatePasswordHints(newPasswordInput.value);
    }

    form.addEventListener('submit', async function(e) {
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
                form.reset();
                setTimeout(function() {
                    window.location.href = 'patient.php?page=dashboard';
                }, 700);
            } else {
                showAlert(data.message || 'Failed to update password.', 'danger');
            }
        } catch (err) {
            showAlert('Unable to update password right now. Please try again.', 'danger');
        }
    });
});
</script>
