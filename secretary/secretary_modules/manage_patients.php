<?php
include dirname(dirname(__DIR__)) . '/config/database.php';
include dirname(dirname(__DIR__)) . '/config/security.php';
include dirname(dirname(__DIR__)) . '/config/capabilities.php';

security_start_session();
capabilities_require_web('patient.manage', 'secretary.php?page=dashboard');
$csrf_token = security_generate_csrf_token();

$search = trim((string)($_GET['search'] ?? ''));
$patients = [];

if ($search !== '') {
    $like = '%' . $search . '%';
    $stmt = $conn->prepare(
        'SELECT patient_id, first_name, middle_name, last_name, phone_number, email, date_of_birth, gender, address, profile_image, created_at, last_login_at
         FROM patients
         WHERE first_name LIKE ? OR middle_name LIKE ? OR last_name LIKE ? OR email LIKE ? OR phone_number LIKE ?
         ORDER BY created_at DESC'
    );
    if ($stmt) {
        $stmt->bind_param('sssss', $like, $like, $like, $like, $like);
        $stmt->execute();
        $res = $stmt->get_result();
        while ($res && ($row = $res->fetch_assoc())) {
            $patients[] = $row;
        }
        $stmt->close();
    }
} else {
    $res = $conn->query(
        'SELECT patient_id, first_name, middle_name, last_name, phone_number, email, date_of_birth, gender, address, profile_image, created_at, last_login_at
         FROM patients
         ORDER BY created_at DESC'
    );
    if ($res) {
        while ($row = $res->fetch_assoc()) {
            $patients[] = $row;
        }
    }
}

function secretary_patient_full_name(array $patient): string
{
    return trim(
        (string)($patient['first_name'] ?? '') . ' '
        . (($patient['middle_name'] ?? '') !== '' ? $patient['middle_name'] . ' ' : '')
        . (string)($patient['last_name'] ?? '')
    );
}

function secretary_patient_format_datetime(?string $value): string
{
    $value = trim((string)$value);
    if ($value === '') {
        return '—';
    }
    $ts = strtotime($value);
    return $ts ? date('M j, Y g:i A', $ts) : $value;
}
?>

<?php if (isset($_SESSION['message'])): ?>
    <div class="alert alert-<?php echo htmlspecialchars((string)($_SESSION['message_type'] ?? 'info'), ENT_QUOTES, 'UTF-8'); ?> alert-dismissible fade show" role="alert">
        <?php echo htmlspecialchars((string)$_SESSION['message'], ENT_QUOTES, 'UTF-8'); ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
    </div>
    <?php unset($_SESSION['message'], $_SESSION['message_type']); ?>
<?php endif; ?>

<div class="card">
    <div class="card-header d-flex justify-content-between align-items-center gap-2">
        <div>
            <h5 class="card-title mb-0">Patient Management</h5>
            <p class="text-muted small mb-0 mt-1">View profiles, update account details, manage medical background, and reset login passwords.</p>
        </div>
        <button type="button" class="btn btn-primary btn-sm" data-bs-toggle="modal" data-bs-target="#addPatientModal">
            <i class="bi bi-person-plus"></i> Add Patient
        </button>
    </div>

    <div class="card-body border-bottom py-2">
        <form method="GET" action="secretary.php" class="module-search-toolbar module-search-toolbar--bare" style="margin:0;">
            <input type="hidden" name="page" value="manage_patients">
            <div class="unified-table-search-wrap module-search-inline">
                <i class="bi bi-search unified-table-search-icon" aria-hidden="true"></i>
                <input type="search" class="form-control unified-table-search-input" name="search" placeholder="Search name, email, or phone..." value="<?php echo htmlspecialchars($search, ENT_QUOTES, 'UTF-8'); ?>" aria-label="Search patients">
            </div>
            <?php if ($search !== ''): ?>
                <a href="secretary.php?page=manage_patients" class="btn btn-link btn-sm text-muted text-decoration-none">Clear</a>
            <?php endif; ?>
        </form>
    </div>

    <div class="card-body">
        <div class="table-responsive history-table-wrap">
            <table class="table table-hover datatable" id="patientsTable" data-skip-table-search="1">
                <thead>
                    <tr>
                        <th>ID</th>
                        <th>Patient</th>
                        <th>Contact</th>
                        <th>DOB</th>
                        <th>Gender</th>
                        <th>Registered</th>
                        <th class="text-end">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($patients)): ?>
                        <tr>
                            <td colspan="7" class="text-center text-muted py-4">
                                <?php echo $search !== '' ? 'No patients match your search.' : 'No patients found.'; ?>
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($patients as $p): ?>
                            <?php $patientJson = htmlspecialchars(json_encode($p, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP), ENT_QUOTES, 'UTF-8'); ?>
                            <tr>
                                <td><?php echo (int)$p['patient_id']; ?></td>
                                <td>
                                    <div class="fw-semibold"><?php echo htmlspecialchars(secretary_patient_full_name($p), ENT_QUOTES, 'UTF-8'); ?></div>
                                    <div class="small text-muted"><?php echo htmlspecialchars((string)($p['email'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></div>
                                </td>
                                <td><?php echo htmlspecialchars((string)($p['phone_number'] ?? '—'), ENT_QUOTES, 'UTF-8'); ?></td>
                                <td><?php echo htmlspecialchars((string)($p['date_of_birth'] ?? '—'), ENT_QUOTES, 'UTF-8'); ?></td>
                                <td><?php echo htmlspecialchars((string)($p['gender'] ?? '—'), ENT_QUOTES, 'UTF-8'); ?></td>
                                <td><?php echo htmlspecialchars(secretary_patient_format_datetime($p['created_at'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></td>
                                <td class="text-end">
                                    <button type="button" class="btn btn-sm btn-primary" title="View and manage patient" onclick="openPatientProfileModal(<?php echo $patientJson; ?>, 'profile')">
                                        <i class="bi bi-person-lines-fill"></i> Manage
                                    </button>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- Add Patient Modal -->
<div class="modal fade" id="addPatientModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-scrollable modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header patient-profile-modal__header">
                <h5 class="modal-title mb-0"><i class="bi bi-person-plus" aria-hidden="true"></i> Add Patient</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <form id="addPatientForm" class="ux-form-shell">
                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token, ENT_QUOTES, 'UTF-8'); ?>">
                    <div class="ux-form-grid">
                        <div>
                            <label class="form-label">First Name *</label>
                            <input type="text" name="first_name" class="form-control" required>
                        </div>
                        <div>
                            <label class="form-label">Middle Name</label>
                            <input type="text" name="middle_name" class="form-control">
                        </div>
                        <div>
                            <label class="form-label">Last Name *</label>
                            <input type="text" name="last_name" class="form-control" required>
                        </div>
                        <div>
                            <label class="form-label">Email *</label>
                            <input type="email" name="email" class="form-control" required>
                            <div class="form-text">Login credentials will be sent to this address.</div>
                        </div>
                        <div>
                            <label class="form-label">Phone</label>
                            <input type="tel" name="phone_number" class="form-control compact-control">
                        </div>
                        <div>
                            <label class="form-label">Date of Birth</label>
                            <input type="date" name="date_of_birth" class="form-control compact-control">
                        </div>
                        <div>
                            <label class="form-label">Gender</label>
                            <select name="gender" class="form-select">
                                <option value="">Unspecified</option>
                                <option value="Male">Male</option>
                                <option value="Female">Female</option>
                                <option value="Other">Other</option>
                            </select>
                        </div>
                        <div class="form-span-2">
                            <label class="form-label">Address</label>
                            <textarea name="address" class="form-control" rows="2"></textarea>
                        </div>
                        <div class="form-span-2">
                            <label class="form-label">Password</label>
                            <input type="text" class="form-control" value="Random password emailed to patient" readonly>
                            <div class="form-text">A secure random password is generated and emailed to the patient.</div>
                        </div>
                    </div>
                    <input type="hidden" name="added_by" value="<?php echo htmlspecialchars((string)($_SESSION['user_id'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>">
                </form>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                <button type="submit" form="addPatientForm" class="btn btn-primary">Create Patient</button>
            </div>
        </div>
    </div>
</div>

<!-- Patient Profile Modal -->
<div class="modal fade" id="patientProfileModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-xl modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header patient-profile-modal__header">
                <div class="d-flex align-items-center gap-3 flex-grow-1 min-w-0">
                    <div id="patientProfileModalAvatar" class="patient-profile-modal__avatar" aria-hidden="true"></div>
                    <h5 class="modal-title mb-0" id="patientProfileModalTitle">Patient Profile</h5>
                </div>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body pt-2">
                <ul class="nav nav-tabs" id="patientProfileTabs" role="tablist">
                    <li class="nav-item" role="presentation">
                        <button class="nav-link active" id="patient-tab-profile" data-bs-toggle="tab" data-bs-target="#patientTabProfile" type="button" role="tab">Profile</button>
                    </li>
                    <li class="nav-item" role="presentation">
                        <button class="nav-link" id="patient-tab-background" data-bs-toggle="tab" data-bs-target="#patientTabBackground" type="button" role="tab">Medical Background</button>
                    </li>
                    <li class="nav-item" role="presentation">
                        <button class="nav-link" id="patient-tab-account" data-bs-toggle="tab" data-bs-target="#patientTabAccount" type="button" role="tab">Account &amp; Password</button>
                    </li>
                </ul>

                <div class="tab-content pt-3">
                    <div class="tab-pane fade show active" id="patientTabProfile" role="tabpanel">
                        <form method="POST" action="../assets/api/patients/update_patient.php" class="ux-form-shell">
                            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token, ENT_QUOTES, 'UTF-8'); ?>">
                            <input type="hidden" name="patient_id" id="edit_patient_id">
                            <div class="ux-form-grid">
                                <div>
                                    <label class="form-label">First Name *</label>
                                    <input type="text" class="form-control" name="first_name" id="edit_patient_first_name" required>
                                </div>
                                <div>
                                    <label class="form-label">Middle Name</label>
                                    <input type="text" class="form-control" name="middle_name" id="edit_patient_middle_name">
                                </div>
                                <div>
                                    <label class="form-label">Last Name *</label>
                                    <input type="text" class="form-control" name="last_name" id="edit_patient_last_name" required>
                                </div>
                                <div>
                                    <label class="form-label">Email *</label>
                                    <input type="email" class="form-control" name="email" id="edit_patient_email" required>
                                </div>
                                <div>
                                    <label class="form-label">Phone Number</label>
                                    <input type="tel" class="form-control" name="phone_number" id="edit_patient_phone_number">
                                </div>
                                <div>
                                    <label class="form-label">Date of Birth *</label>
                                    <input type="date" class="form-control" name="date_of_birth" id="edit_patient_date_of_birth" required>
                                </div>
                                <div>
                                    <label class="form-label">Gender</label>
                                    <select class="form-select" name="gender" id="edit_patient_gender">
                                        <option value="">Unspecified</option>
                                        <option value="Male">Male</option>
                                        <option value="Female">Female</option>
                                        <option value="Other">Other</option>
                                    </select>
                                </div>
                                <div class="form-span-2">
                                    <label class="form-label">Address</label>
                                    <textarea class="form-control" name="address" id="edit_patient_address" rows="2"></textarea>
                                </div>
                            </div>
                            <div class="d-flex justify-content-end gap-2 mt-3">
                                <button type="submit" class="btn btn-primary">Save Profile</button>
                            </div>
                        </form>
                    </div>

                    <div class="tab-pane fade" id="patientTabBackground" role="tabpanel">
                        <p class="text-muted small">Background medical information only.</p>
                        <form id="profilingForm" class="ux-form-shell">
                            <input type="hidden" id="profiling_patient_id" name="patient_id">
                            <div class="ux-form-grid">
                                <div><label class="form-label">Race</label><input type="text" id="pf_race" class="form-control"></div>
                                <div><label class="form-label">Occupation</label><input type="text" id="pf_occupation" class="form-control"></div>
                                <div class="form-span-2"><label class="form-label">Medical History</label><textarea id="pf_medical_history" class="form-control" rows="2"></textarea></div>
                                <div class="form-span-2"><label class="form-label">Medications</label><textarea id="pf_medications" class="form-control" rows="2"></textarea></div>
                                <div><label class="form-label">Allergies</label><input type="text" id="pf_allergies" class="form-control"></div>
                                <div><label class="form-label">Smoking</label><input type="text" id="pf_smoking" class="form-control" placeholder="e.g. Non-smoker"></div>
                                <div><label class="form-label">Alcohol</label><input type="text" id="pf_alcohol" class="form-control"></div>
                                <div><div class="form-check mt-4"><input class="form-check-input" type="checkbox" id="pf_hypertensive"><label class="form-check-label" for="pf_hypertensive">Hypertensive</label></div></div>
                                <div><div class="form-check mt-4"><input class="form-check-input" type="checkbox" id="pf_diabetic"><label class="form-check-label" for="pf_diabetic">Diabetic</label></div></div>
                                <div><div class="form-check mt-4"><input class="form-check-input" type="checkbox" id="pf_asthmatic"><label class="form-check-label" for="pf_asthmatic">Asthmatic</label></div></div>
                                <div><div class="form-check mt-4"><input class="form-check-input" type="checkbox" id="pf_heart_disease"><label class="form-check-label" for="pf_heart_disease">Heart Disease</label></div></div>
                                <div><div class="form-check mt-4"><input class="form-check-input" type="checkbox" id="pf_hiv_positive"><label class="form-check-label" for="pf_hiv_positive">HIV Positive</label></div></div>
                                <div><label class="form-label">Bleeding Disorder</label><input type="text" id="pf_bleeding_disorder" class="form-control"></div>
                                <div><label class="form-label">ASA Classification</label><input type="text" id="pf_asa" class="form-control"></div>
                                <div><label class="form-label">Pregnancy Status</label><input type="text" id="pf_pregnancy_status" class="form-control"></div>
                                <div class="form-span-2"><label class="form-label">Previous Dental Treatment</label><textarea id="pf_previous_dental_treatment" class="form-control" rows="2"></textarea></div>
                                <div><label class="form-label">Dental Anxiety</label><input type="text" id="pf_dental_anxiety" class="form-control"></div>
                                <div><label class="form-label">Emergency Contact</label><input type="text" id="pf_emergency_contact" class="form-control"></div>
                                <div><label class="form-label">Emergency Phone</label><input type="text" id="pf_emergency_phone" class="form-control"></div>
                                <div class="form-span-2"><label class="form-label">Additional Notes</label><textarea id="pf_background_notes" class="form-control" rows="2"></textarea></div>
                            </div>
                            <div class="d-flex justify-content-end gap-2 mt-3">
                                <button type="submit" class="btn btn-primary">Save Medical Background</button>
                            </div>
                        </form>
                    </div>

                    <div class="tab-pane fade" id="patientTabAccount" role="tabpanel">
                        <div class="row g-3 mb-4">
                            <div class="col-md-4">
                                <div class="p-3 rounded border bg-light">
                                    <div class="small text-muted">Mobile Number</div>
                                    <div class="fw-semibold" id="account_patient_phone">—</div>
                                </div>
                            </div>
                            <div class="col-md-4">
                                <div class="p-3 rounded border bg-light">
                                    <div class="small text-muted">Login Email</div>
                                    <div class="fw-semibold" id="account_patient_email">—</div>
                                </div>
                            </div>
                            <div class="col-md-4">
                                <div class="p-3 rounded border bg-light">
                                    <div class="small text-muted">Last Login</div>
                                    <div class="fw-semibold" id="account_patient_last_login">—</div>
                                </div>
                            </div>
                        </div>

                        <div class="patient-account-password-grid">
                        <div class="card border h-100">
                            <div class="card-body">
                                <h6 class="card-title">Set New Password</h6>
                                <p class="text-muted small">Choose a custom password.</p>
                                <form id="setPatientPasswordForm" class="ux-form-shell narrow">
                                    <?php
                                    $password_field_id = 'set_patient_password';
                                    $password_field_label = 'New Password';
                                    $password_field_attrs = 'required minlength="8" autocomplete="new-password"';
                                    include dirname(dirname(__DIR__)) . '/includes/password_field.php';
                                    $password_field_id = 'set_patient_password_confirm';
                                    $password_field_label = 'Confirm Password';
                                    $password_field_attrs = 'required minlength="8" autocomplete="new-password"';
                                    include dirname(dirname(__DIR__)) . '/includes/password_field.php';
                                    ?>
                                    <button type="submit" class="btn btn-primary">Update Password</button>
                                </form>
                            </div>
                        </div>

                        <div class="card border h-100">
                            <div class="card-body">
                                <h6 class="card-title">Generate Temporary Password</h6>
                                <p class="text-muted small mb-3">Creates a secure random password you can copy and share with the patient.</p>
                                <form method="POST" action="../assets/api/patients/reset_patient_password.php" id="resetPatientPasswordForm" data-confirm="Generate a new temporary password for this patient?" data-confirm-title="Reset Password" data-confirm-ok="Generate">
                                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token, ENT_QUOTES, 'UTF-8'); ?>">
                                    <input type="hidden" name="patient_id" id="reset_patient_id" value="">
                                    <button type="submit" class="btn btn-primary">
                                        <i class="bi bi-key"></i> Generate Temporary Password
                                    </button>
                                </form>
                            </div>
                        </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<div class="modal fade" id="tempPasswordModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header patient-profile-modal__header">
                <h5 class="modal-title mb-0">Temporary Password</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <p class="mb-2 fw-semibold" id="tempPasswordAccountLabel"></p>
                <p class="text-muted small mb-3" id="tempPasswordHint">Copy this password and share it securely with the patient.</p>
                <label class="form-label" for="tempPasswordValue">Password</label>
                <div class="input-group">
                    <input type="text" class="form-control font-monospace" id="tempPasswordValue" readonly autocomplete="off">
                    <button type="button" class="btn btn-outline-primary" id="tempPasswordCopyBtn">Copy</button>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-primary" data-bs-dismiss="modal">Done</button>
            </div>
        </div>
    </div>
</div>

<?php if (!empty($_SESSION['temp_password_modal'])):
    $tempPasswordModal = $_SESSION['temp_password_modal'];
    unset($_SESSION['temp_password_modal']);
?>
<script>
document.addEventListener('DOMContentLoaded', function () {
    const modalEl = document.getElementById('tempPasswordModal');
    const input = document.getElementById('tempPasswordValue');
    const labelEl = document.getElementById('tempPasswordAccountLabel');
    const copyBtn = document.getElementById('tempPasswordCopyBtn');
    if (!modalEl || !input || !labelEl) return;

    labelEl.textContent = <?php echo json_encode('Account: ' . ($tempPasswordModal['account_label'] ?? ''), JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP); ?>;
    input.value = <?php echo json_encode((string)($tempPasswordModal['password'] ?? ''), JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP); ?>;

    function copyPassword() {
        const value = input.value || '';
        if (!value) return;
        const done = function () {
            notifySecretaryPatients('Password copied to clipboard.', 'success');
        };
        if (navigator.clipboard && navigator.clipboard.writeText) {
            navigator.clipboard.writeText(value).then(done).catch(function () {
                input.focus();
                input.select();
                document.execCommand('copy');
                done();
            });
        } else {
            input.focus();
            input.select();
            document.execCommand('copy');
            done();
        }
    }

    copyBtn?.addEventListener('click', copyPassword);
    input.addEventListener('click', function () { input.select(); });
    bootstrap.Modal.getOrCreateInstance(modalEl).show();
});
</script>
<?php endif; ?>

<script>
const secretaryPatientCsrf = <?php echo json_encode($csrf_token, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP); ?>;
let activePatientRecord = null;

function notifySecretaryPatients(message, type) {
    if (typeof window.portalNotify === 'function') {
        window.portalNotify(String(message || ''), type || 'info');
        return;
    }
    if (typeof window.showAppToast === 'function') {
        window.showAppToast(String(message || ''), type || 'info');
        return;
    }
    if (typeof window.showToast === 'function') {
        window.showToast(String(message || ''), type || 'info');
    }
}

function patientDisplayName(patient) {
    return [patient.first_name, patient.middle_name, patient.last_name].filter(Boolean).join(' ').replace(/\s+/g, ' ').trim();
}

function formatDateTime(value) {
    if (!value) return '—';
    const date = new Date(String(value).replace(' ', 'T'));
    if (Number.isNaN(date.getTime())) return value;
    return date.toLocaleString(undefined, { year: 'numeric', month: 'short', day: 'numeric', hour: 'numeric', minute: '2-digit' });
}

function escapeHtml(value) {
    return String(value || '')
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;');
}

function renderPatientModalAvatar(patient) {
    const wrap = document.getElementById('patientProfileModalAvatar');
    if (!wrap) return;

    const name = patientDisplayName(patient);
    const imagePath = String(patient.profile_image || '').trim();
    if (imagePath !== '') {
        const url = /^https?:\/\//i.test(imagePath)
            ? imagePath
            : '../' + imagePath.replace(/^\/+/, '');
        wrap.innerHTML = '<img src="' + escapeHtml(url) + '" alt="' + escapeHtml(name) + '" class="patient-profile-modal__avatar-img">';
        return;
    }

    wrap.innerHTML = '<span class="profile-avatar-icon patient-profile-modal__avatar-icon" role="img" aria-label="' + escapeHtml(name) + '"><i class="bi bi-person-fill" aria-hidden="true"></i></span>';
}

function fillProfilingForm(profiling) {
    const pf = profiling || {};
    document.getElementById('pf_race').value = pf.race || '';
    document.getElementById('pf_occupation').value = pf.occupation || '';
    document.getElementById('pf_medical_history').value = pf.medical_history || '';
    document.getElementById('pf_medications').value = pf.medications || '';
    document.getElementById('pf_allergies').value = pf.allergies || '';
    document.getElementById('pf_smoking').value = pf.smoking || '';
    document.getElementById('pf_alcohol').value = pf.alcohol || '';
    document.getElementById('pf_hypertensive').checked = !!pf.hypertensive;
    document.getElementById('pf_diabetic').checked = !!pf.diabetic;
    document.getElementById('pf_asthmatic').checked = !!pf.asthmatic;
    document.getElementById('pf_heart_disease').checked = !!pf.heart_disease;
    document.getElementById('pf_hiv_positive').checked = !!pf.hiv_positive;
    document.getElementById('pf_bleeding_disorder').value = pf.bleeding_disorder || '';
    document.getElementById('pf_asa').value = pf.asa || '';
    document.getElementById('pf_pregnancy_status').value = pf.pregnancy_status || '';
    document.getElementById('pf_previous_dental_treatment').value = pf.previous_dental_treatment || '';
    document.getElementById('pf_dental_anxiety').value = pf.dental_anxiety || '';
    document.getElementById('pf_emergency_contact').value = pf.emergency_contact || '';
    document.getElementById('pf_emergency_phone').value = pf.emergency_phone || '';
    document.getElementById('pf_background_notes').value = pf.background_notes || '';
}

function fillProfileForm(patient) {
    document.getElementById('edit_patient_id').value = patient.patient_id || '';
    document.getElementById('edit_patient_first_name').value = patient.first_name || '';
    document.getElementById('edit_patient_middle_name').value = patient.middle_name || '';
    document.getElementById('edit_patient_last_name').value = patient.last_name || '';
    document.getElementById('edit_patient_email').value = patient.email || '';
    document.getElementById('edit_patient_phone_number').value = patient.phone_number || '';
    document.getElementById('edit_patient_date_of_birth').value = patient.date_of_birth || '';
    document.getElementById('edit_patient_gender').value = patient.gender || '';
    document.getElementById('edit_patient_address').value = patient.address || '';
    document.getElementById('profiling_patient_id').value = patient.patient_id || '';
    document.getElementById('reset_patient_id').value = patient.patient_id || '';
    document.getElementById('account_patient_phone').textContent = patient.phone_number || '—';
    document.getElementById('account_patient_email').textContent = patient.email || '—';
    document.getElementById('account_patient_last_login').textContent = formatDateTime(patient.last_login_at);
}

function openPatientProfileModal(patient, activeTab) {
    activePatientRecord = Object.assign({}, patient);
    const modalEl = document.getElementById('patientProfileModal');
    const modal = bootstrap.Modal.getOrCreateInstance(modalEl);
    document.getElementById('patientProfileModalTitle').textContent = patientDisplayName(patient);
    renderPatientModalAvatar(patient);
    fillProfileForm(patient);

    fetch('../assets/api/patients/get_profiling.php?patient_id=' + encodeURIComponent(patient.patient_id))
        .then(function (response) { return response.json(); })
        .then(function (data) {
            if (data.success) {
                if (data.patient) {
                    activePatientRecord = Object.assign({}, activePatientRecord, data.patient);
                    fillProfileForm(activePatientRecord);
                    renderPatientModalAvatar(activePatientRecord);
                    document.getElementById('patientProfileModalTitle').textContent = patientDisplayName(activePatientRecord);
                }
                fillProfilingForm(data.profiling || null);
            }
        })
        .catch(function (err) {
            console.warn('Failed to load patient profile details', err);
        })
        .finally(function () {
            const tabId = activeTab === 'background'
                ? 'patient-tab-background'
                : (activeTab === 'account' ? 'patient-tab-account' : 'patient-tab-profile');
            const tabTrigger = document.getElementById(tabId);
            if (tabTrigger) {
                bootstrap.Tab.getOrCreateInstance(tabTrigger).show();
            }
            modal.show();
        });
}

document.getElementById('profilingForm').addEventListener('submit', function (event) {
    event.preventDefault();
    const patientId = parseInt(document.getElementById('profiling_patient_id').value, 10);
    const profiling = {
        race: document.getElementById('pf_race').value.trim(),
        occupation: document.getElementById('pf_occupation').value.trim(),
        medical_history: document.getElementById('pf_medical_history').value.trim(),
        medications: document.getElementById('pf_medications').value.trim(),
        allergies: document.getElementById('pf_allergies').value.trim(),
        smoking: document.getElementById('pf_smoking').value.trim(),
        alcohol: document.getElementById('pf_alcohol').value.trim(),
        hypertensive: document.getElementById('pf_hypertensive').checked,
        diabetic: document.getElementById('pf_diabetic').checked,
        asthmatic: document.getElementById('pf_asthmatic').checked,
        heart_disease: document.getElementById('pf_heart_disease').checked,
        hiv_positive: document.getElementById('pf_hiv_positive').checked,
        bleeding_disorder: document.getElementById('pf_bleeding_disorder').value.trim(),
        asa: document.getElementById('pf_asa').value.trim(),
        pregnancy_status: document.getElementById('pf_pregnancy_status').value.trim(),
        previous_dental_treatment: document.getElementById('pf_previous_dental_treatment').value.trim(),
        dental_anxiety: document.getElementById('pf_dental_anxiety').value.trim(),
        emergency_contact: document.getElementById('pf_emergency_contact').value.trim(),
        emergency_phone: document.getElementById('pf_emergency_phone').value.trim(),
        background_notes: document.getElementById('pf_background_notes').value.trim()
    };

    fetch('../assets/api/patients/save_profiling.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ patient_id: patientId, profiling: profiling })
    })
        .then(function (response) { return response.json(); })
        .then(function (result) {
            if (result.success) {
                notifySecretaryPatients('Medical background saved successfully.', 'success');
            } else {
                notifySecretaryPatients(result.message || 'Unable to save medical background.', 'danger');
            }
        })
        .catch(function () {
            notifySecretaryPatients('Unable to save medical background.', 'danger');
        });
});

document.getElementById('setPatientPasswordForm').addEventListener('submit', function (event) {
    event.preventDefault();
    if (!activePatientRecord || !activePatientRecord.patient_id) {
        notifySecretaryPatients('Select a patient first.', 'warning');
        return;
    }

    const newPassword = document.getElementById('set_patient_password').value;
    const confirmPassword = document.getElementById('set_patient_password_confirm').value;

    fetch('../assets/api/patients/set_patient_password.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({
            csrf_token: secretaryPatientCsrf,
            patient_id: activePatientRecord.patient_id,
            new_password: newPassword,
            confirm_password: confirmPassword
        })
    })
        .then(function (response) { return response.json(); })
        .then(function (result) {
            if (result.success) {
                notifySecretaryPatients(result.message || 'Password updated.', 'success');
                document.getElementById('setPatientPasswordForm').reset();
            } else {
                notifySecretaryPatients(result.message || 'Unable to update password.', 'danger');
            }
        })
        .catch(function () {
            notifySecretaryPatients('Unable to update password.', 'danger');
        });
});

document.getElementById('addPatientForm').addEventListener('submit', function (event) {
    event.preventDefault();
    const form = event.target;
    const data = new FormData(form);

    fetch('../assets/api/patients/create_walkin.php', {
        method: 'POST',
        body: data
    })
        .then(function (response) { return response.json(); })
        .then(function (result) {
            if (result.success) {
                const tone = result.email_sent === false ? 'warning' : 'success';
                const message = result.already_exists
                    ? (result.message || 'Patient already exists.')
                    : (result.message || 'Patient account created successfully.');
                notifySecretaryPatients(message, tone);
                bootstrap.Modal.getInstance(document.getElementById('addPatientModal'))?.hide();
                if (!result.already_exists) {
                    setTimeout(function () { location.reload(); }, 1200);
                }
            } else {
                notifySecretaryPatients(result.message || 'Unable to create patient.', 'danger');
            }
        })
        .catch(function () {
            notifySecretaryPatients('Unable to create patient.', 'danger');
        });
});
</script>
