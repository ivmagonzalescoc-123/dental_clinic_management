<?php
include dirname(dirname(__DIR__)) . '/config/database.php';
include dirname(dirname(__DIR__)) . '/config/security.php';
include dirname(dirname(__DIR__)) . '/config/capabilities.php';

security_start_session();
capabilities_require_web('patient.manage', 'admin.php?page=dashboard');
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

function admin_patient_full_name(array $patient): string
{
    return trim(
        (string)($patient['first_name'] ?? '') . ' '
        . (($patient['middle_name'] ?? '') !== '' ? $patient['middle_name'] . ' ' : '')
        . (string)($patient['last_name'] ?? '')
    );
}

function admin_patient_format_datetime(?string $value): string
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

<div class="d-flex justify-content-between align-items-center gap-2 mb-3">
    <div>
        <h5 class="card-title mb-0">Patient Management</h5>
    </div>
    <button type="button" class="btn btn-primary btn-sm" data-bs-toggle="modal" data-bs-target="#addPatientModal">
        <i class="bi bi-person-plus"></i> Add Patient
    </button>
</div>

<div class="unified-table-search-toolbar mb-3">
    <div class="unified-table-search-wrap">
        <i class="bi bi-search unified-table-search-icon" aria-hidden="true"></i>
        <input type="search" id="patientLiveSearch" class="form-control unified-table-search-input"
            placeholder="Search patients by name, email, or phone..." aria-label="Search patients" autocomplete="off">
        <button type="button" class="unified-table-search-clear" id="patientLiveSearchClear" aria-label="Clear search" style="display:none;">&times;</button>
    </div>
    <small class="text-muted" id="patientLiveSearchStatus"></small>
</div>

<div id="patientDefaultTableView">
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
                        <?php
                            $patientAvatar = '';
                            if (!empty($p['profile_image'])) {
                                $imgPath = $p['profile_image'];
                                $imgUrl = (strpos($imgPath, 'http') === 0) ? $imgPath : '../' . ltrim($imgPath, '/');
                                $patientAvatar = '<img src="' . htmlspecialchars($imgUrl) . '" alt="" style="width:36px;height:36px;border-radius:50%;object-fit:cover;" class="me-2">';
                            } else {
                                $patientAvatar = '<span class="me-2 d-inline-flex align-items-center justify-content-center rounded-circle bg-secondary text-white" style="width:36px;height:36px;font-size:0.9rem;"><i class="bi bi-person-fill"></i></span>';
                            }
                        ?>
                        <tr>
                            <td><?php echo (int)$p['patient_id']; ?></td>
                            <td>
                                <?php echo $patientAvatar; ?>
                                <div class="d-inline-block align-middle">
                                    <div class="fw-semibold"><?php echo htmlspecialchars(admin_patient_full_name($p), ENT_QUOTES, 'UTF-8'); ?></div>
                                    <div class="small text-muted"><?php echo htmlspecialchars((string)($p['email'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></div>
                                </div>
                            </td>
                            <td><?php echo htmlspecialchars((string)($p['phone_number'] ?? '—'), ENT_QUOTES, 'UTF-8'); ?></td>
                            <td><?php echo htmlspecialchars((string)($p['date_of_birth'] ?? '—'), ENT_QUOTES, 'UTF-8'); ?></td>
                            <td><?php echo htmlspecialchars((string)($p['gender'] ?? '—'), ENT_QUOTES, 'UTF-8'); ?></td>
                            <td><?php echo htmlspecialchars(admin_patient_format_datetime($p['created_at'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></td>
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

<div id="patientSearchResultsView" style="display:none;">
    <div class="table-responsive history-table-wrap">
        <table class="table table-hover">
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
            <tbody id="patientSearchResultsBody"></tbody>
        </table>
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
                <form id="addPatientForm" class="ux-form-shell" method="POST" action="#">
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
                        </div>
                        <div>
                            <label class="form-label">Phone</label>
                            <input type="tel" name="phone_number" class="form-control compact-control">
                        </div>
                        <div>
                            <label class="form-label">Date of Birth</label>
                            <input type="date" name="date_of_birth" class="form-control">
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
                        </div>
                    </div>
                    <input type="hidden" name="added_by" value="<?php echo htmlspecialchars((string)($_SESSION['user_id'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>">
                </form>
            </div>
            <div class="modal-footer">
                <button type="submit" form="addPatientForm" class="btn btn-primary">Create Patient</button>
            </div>
        </div>
    </div>
</div>

<!-- Patient Profile Modal -->
<div class="modal fade" id="patientProfileModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header patient-profile-modal__header py-2 px-3">
                <div class="d-flex align-items-center gap-2 flex-grow-1 min-w-0">
                    <div id="patientProfileModalAvatar" class="patient-profile-modal__avatar" aria-hidden="true"></div>
                    <h5 class="modal-title mb-0" id="patientProfileModalTitle" style="font-size:0.9rem;">Patient Profile</h5>
                </div>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body py-2 px-3">
                <ul class="nav nav-tabs nav-tabs-sm" id="patientProfileTabs" role="tablist">
                    <li class="nav-item" role="presentation">
                        <button class="nav-link active py-1 px-2" id="patient-tab-profile" data-bs-toggle="tab" data-bs-target="#patientTabProfile" type="button" role="tab" style="font-size:0.82rem;">Profile</button>
                    </li>
                    <li class="nav-item" role="presentation">
                        <button class="nav-link py-1 px-2" id="patient-tab-account" data-bs-toggle="tab" data-bs-target="#patientTabAccount" type="button" role="tab" style="font-size:0.82rem;">Account</button>
                    </li>
                </ul>

                <div class="tab-content pt-2">
                    <div class="tab-pane fade show active" id="patientTabProfile" role="tabpanel">
                        <form id="editPatientForm" class="ux-form-shell" method="POST" action="#">
                            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token, ENT_QUOTES, 'UTF-8'); ?>">
                            <input type="hidden" name="patient_id" id="edit_patient_id">
                            <input type="hidden" name="gender" id="edit_patient_gender_sync">
                            <div style="display:grid;grid-template-columns:1fr 1fr;gap:0.35rem 0.5rem;">
                                <div>
                                    <label class="form-label fw-bold" style="font-size:0.82rem;color:#1e293b;">First Name *</label>
                                    <input type="text" class="form-control" name="first_name" id="edit_patient_first_name" required style="padding:0.3rem 0.5rem;font-size:0.85rem;">
                                </div>
                                <div>
                                    <label class="form-label fw-bold" style="font-size:0.82rem;color:#1e293b;">Middle Name</label>
                                    <input type="text" class="form-control" name="middle_name" id="edit_patient_middle_name" style="padding:0.3rem 0.5rem;font-size:0.85rem;">
                                </div>
                                <div>
                                    <label class="form-label fw-bold" style="font-size:0.82rem;color:#1e293b;">Last Name *</label>
                                    <input type="text" class="form-control" name="last_name" id="edit_patient_last_name" required style="padding:0.3rem 0.5rem;font-size:0.85rem;">
                                </div>
                                <div>
                                    <label class="form-label fw-bold" style="font-size:0.82rem;color:#1e293b;">Email *</label>
                                    <input type="email" class="form-control" name="email" id="edit_patient_email" required style="padding:0.3rem 0.5rem;font-size:0.85rem;">
                                </div>
                                <div>
                                    <label class="form-label fw-bold" style="font-size:0.82rem;color:#1e293b;">Phone</label>
                                    <input type="tel" class="form-control" name="phone_number" id="edit_patient_phone_number" style="padding:0.3rem 0.5rem;font-size:0.85rem;">
                                </div>
                                <div>
                                    <label class="form-label fw-bold" style="font-size:0.82rem;color:#1e293b;">Birthdate *</label>
                                    <input type="date" class="form-control" name="date_of_birth" id="edit_patient_date_of_birth" required style="padding:0.3rem 0.5rem;font-size:0.85rem;">
                                </div>
                                <div>
                                    <label class="form-label fw-bold" style="font-size:0.82rem;color:#1e293b;">Address</label>
                                    <input type="text" class="form-control" name="address" id="edit_patient_address" style="padding:0.3rem 0.5rem;font-size:0.85rem;">
                                </div>
                                <div>
                                    <label class="form-label fw-bold" style="font-size:0.82rem;color:#1e293b;">Occupation</label>
                                    <input type="text" class="form-control" name="occupation" id="edit_patient_occupation" placeholder="e.g. Teacher, Engineer" style="padding:0.3rem 0.5rem;font-size:0.85rem;">
                                </div>
                            </div>
                            <div id="editPatientAlert" class="mt-1"></div>
                            <div class="d-flex justify-content-end mt-1">
                                <button type="submit" class="btn btn-primary btn-sm">Save</button>
                            </div>
                        </form>
                    </div>

                    <div class="tab-pane fade" id="patientTabAccount" role="tabpanel">
                        <div class="d-flex gap-2 mb-2">
                            <div class="flex-fill p-2 rounded border bg-light">
                                <div style="font-size:0.75rem;color:#64748b;">Phone</div>
                                <div class="fw-semibold" style="font-size:0.82rem;" id="account_patient_phone">—</div>
                            </div>
                            <div class="flex-fill p-2 rounded border bg-light">
                                <div style="font-size:0.75rem;color:#64748b;">Email</div>
                                <div class="fw-semibold" style="font-size:0.82rem;" id="account_patient_email">—</div>
                            </div>
                            <div class="flex-fill p-2 rounded border bg-light">
                                <div style="font-size:0.75rem;color:#64748b;">Last Login</div>
                                <div class="fw-semibold" style="font-size:0.82rem;" id="account_patient_last_login">—</div>
                            </div>
                        </div>

                        <div style="display:grid;grid-template-columns:1fr 1fr;gap:0.5rem;">
                        <div class="card border">
                            <div class="card-body py-2 px-3">
                                <h6 class="mb-1" style="font-size:0.82rem;font-weight:600;">Set Password</h6>
                                <form id="setPatientPasswordForm" novalidate action="javascript:void(0);" onsubmit="return false;">
                                    <?php
                                    $passwordPolicyPattern = '(?=.*[a-z])(?=.*[A-Z])(?=.*\d)(?=.*[^A-Za-z0-9]).{8,}';
                                    $passwordPolicyTitle = 'At least 8 characters, including an uppercase letter, a lowercase letter, a number, and a special character.';
                                    $password_field_id = 'set_patient_password';
                                    $password_field_label = 'New';
                                    $password_field_attrs = 'required minlength="8" pattern="' . $passwordPolicyPattern . '" title="' . $passwordPolicyTitle . '" autocomplete="new-password" style="padding:0.3rem 0.5rem;font-size:0.85rem;"';
                                    $password_field_after = '<div class="form-text" style="font-size:0.7rem;">' . htmlspecialchars($passwordPolicyTitle, ENT_QUOTES, 'UTF-8') . '</div>';
                                    include dirname(dirname(__DIR__)) . '/includes/password_field.php';
                                    $password_field_id = 'set_patient_password_confirm';
                                    $password_field_label = 'Confirm';
                                    $password_field_attrs = 'required minlength="8" autocomplete="new-password" style="padding:0.3rem 0.5rem;font-size:0.85rem;"';
                                    $password_field_after = '';
                                    include dirname(dirname(__DIR__)) . '/includes/password_field.php';
                                    ?>
                                    <div id="setPatientPasswordAlert" class="mt-1"></div>
                                    <button type="submit" class="btn btn-primary btn-sm mt-1">Update</button>
                                </form>
                            </div>
                        </div>

                        <div class="card border">
                            <div class="card-body py-2 px-3">
                                <h6 class="mb-1" style="font-size:0.82rem;font-weight:600;">Temp Password</h6>
                                <p class="mb-1" style="font-size:0.72rem;color:#64748b;">Generate random password for patient.</p>
                                <form method="POST" action="../assets/api/patients/reset_patient_password.php" id="resetPatientPasswordForm" data-confirm="Generate a new temporary password?" data-confirm-title="Reset Password" data-confirm-ok="Generate">
                                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token, ENT_QUOTES, 'UTF-8'); ?>">
                                    <input type="hidden" name="patient_id" id="reset_patient_id" value="">
                                    <button type="submit" class="btn btn-primary btn-sm">
                                        <i class="bi bi-key"></i> Generate
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
            notifyAdminPatients('Password copied to clipboard.', 'success');
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
window.adminPatientCsrf = <?php echo json_encode($csrf_token, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP); ?>;
window.activePatientRecord = null;

function notifyAdminPatients(message, type) {
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
    return [patient.first_name, patient.middle_name, patient.last_name]
        .filter(function (v) { return v && String(v).trim() && String(v).trim().toUpperCase() !== 'N/A'; })
        .join(' ').replace(/\s+/g, ' ').trim();
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

function fillProfileForm(patient) {
    document.getElementById('edit_patient_id').value = patient.patient_id || '';
    document.getElementById('edit_patient_first_name').value = patient.first_name || '';
    document.getElementById('edit_patient_middle_name').value = patient.middle_name || '';
    document.getElementById('edit_patient_last_name').value = patient.last_name || '';
    document.getElementById('edit_patient_email').value = patient.email || '';
    document.getElementById('edit_patient_phone_number').value = patient.phone_number || '';
    document.getElementById('edit_patient_date_of_birth').value = patient.date_of_birth || '';
    document.getElementById('edit_patient_gender_sync').value = patient.gender || '';
    document.getElementById('edit_patient_address').value = patient.address || '';
    document.getElementById('edit_patient_occupation').value = patient.occupation || '';
    document.getElementById('reset_patient_id').value = patient.patient_id || '';
    document.getElementById('account_patient_phone').textContent = patient.phone_number || '—';
    document.getElementById('account_patient_email').textContent = patient.email || '—';
    document.getElementById('account_patient_last_login').textContent = formatDateTime(patient.last_login_at);
}

// ----- Live patient search (debounced, hits assets/api/patients/search_patients.php) -----
// Renders into a separate plain table instead of the #patientsTable above, which is
// owned/paginated by simpleDatatables — mutating its DOM directly would not work
// (it manages rows internally) and the app also auto-removes any search toolbar it
// finds sitting next to a datatable to avoid duplicate search UIs.
(function () {
    const input = document.getElementById('patientLiveSearch');
    const clearBtn = document.getElementById('patientLiveSearchClear');
    const statusEl = document.getElementById('patientLiveSearchStatus');
    const defaultView = document.getElementById('patientDefaultTableView');
    const resultsView = document.getElementById('patientSearchResultsView');
    const resultsBody = document.getElementById('patientSearchResultsBody');
    if (!input || !defaultView || !resultsView || !resultsBody) return;

    let currentResults = [];

    function renderPatientRow(p, index) {
        const fullName = patientDisplayName(p) || '—';
        let avatarHtml = '';
        if (p.profile_image) {
            const imgUrl = (p.profile_image.startsWith('http')) ? p.profile_image : '../' + p.profile_image.replace(/^\/+/, '');
            avatarHtml = '<img src="' + escapeHtml(imgUrl) + '" alt="" style="width:36px;height:36px;border-radius:50%;object-fit:cover;" class="me-2">';
        } else {
            avatarHtml = '<span class="me-2 d-inline-flex align-items-center justify-content-center rounded-circle bg-secondary text-white" style="width:36px;height:36px;font-size:0.9rem;"><i class="bi bi-person-fill"></i></span>';
        }
        return '<tr>'
            + '<td>' + (parseInt(p.patient_id, 10) || 0) + '</td>'
            + '<td>' + avatarHtml + '<div class="d-inline-block align-middle"><div class="fw-semibold">' + escapeHtml(fullName) + '</div><div class="small text-muted">' + escapeHtml(p.email || '') + '</div></div></td>'
            + '<td>' + escapeHtml(p.phone_number || '—') + '</td>'
            + '<td>' + escapeHtml(p.date_of_birth || '—') + '</td>'
            + '<td>' + escapeHtml(p.gender || '—') + '</td>'
            + '<td>' + escapeHtml(formatDateTime(p.created_at)) + '</td>'
            + '<td class="text-end"><button type="button" class="btn btn-sm btn-primary patient-live-search-manage-btn" title="View and manage patient" data-index="' + index + '"><i class="bi bi-person-lines-fill"></i> Manage</button></td>'
            + '</tr>';
    }

    resultsBody.addEventListener('click', function (event) {
        const btn = event.target.closest('.patient-live-search-manage-btn');
        if (!btn) return;
        const idx = parseInt(btn.getAttribute('data-index'), 10);
        const patient = currentResults[idx];
        if (patient) openPatientProfileModal(patient, 'profile');
    });

    function showResultsView() {
        defaultView.style.display = 'none';
        resultsView.style.display = '';
    }

    function showDefaultView() {
        resultsView.style.display = 'none';
        defaultView.style.display = '';
    }

    function runSearch(query) {
        statusEl.textContent = 'Searching...';
        showResultsView();
        DentalUI.showSkeleton(resultsBody, 5);
        fetch('../assets/api/patients/search_patients.php?q=' + encodeURIComponent(query))
            .then(function (r) { return r.json(); })
            .then(function (data) {
                if (!data.success) {
                    statusEl.textContent = '';
                    return;
                }
                currentResults = data.patients || [];
                showResultsView();
                if (currentResults.length === 0) {
                    resultsBody.innerHTML = '<tr><td colspan="7" class="text-center text-muted py-4">No patients match your search.</td></tr>';
                } else {
                    resultsBody.innerHTML = currentResults.map(renderPatientRow).join('');
                }
                statusEl.textContent = currentResults.length + ' result' + (currentResults.length === 1 ? '' : 's');
            })
            .catch(function () {
                statusEl.textContent = 'Search failed. Please try again.';
            });
    }

    const debouncedSearch = (typeof DentalUI !== 'undefined' && DentalUI.debounce)
        ? DentalUI.debounce(runSearch, 300)
        : function (q) { runSearch(q); };

    input.addEventListener('input', function () {
        const q = input.value.trim();
        clearBtn.style.display = q ? '' : 'none';
        if (!q) {
            currentResults = [];
            showDefaultView();
            statusEl.textContent = '';
            return;
        }
        debouncedSearch(q);
    });

    clearBtn.addEventListener('click', function () {
        input.value = '';
        clearBtn.style.display = 'none';
        currentResults = [];
        showDefaultView();
        statusEl.textContent = '';
        input.focus();
    });
})();

function openPatientProfileModal(patient, activeTab) {
    activePatientRecord = Object.assign({}, patient);
    const modalEl = document.getElementById('patientProfileModal');
    if (!modalEl) return;
    if (typeof bootstrap === 'undefined' || !bootstrap.Modal) return;
    const modal = bootstrap.Modal.getOrCreateInstance(modalEl);
    document.getElementById('patientProfileModalTitle').textContent = patientDisplayName(patient);
    renderPatientModalAvatar(patient);
    fillProfileForm(patient);

    const tabId = activeTab === 'account' ? 'patient-tab-account' : 'patient-tab-profile';
    const tabTrigger = document.getElementById(tabId);
    if (tabTrigger && typeof bootstrap !== 'undefined' && bootstrap.Tab) {
        bootstrap.Tab.getOrCreateInstance(tabTrigger).show();
    }
    modal.show();
}

// Edit patient profile via AJAX
document.getElementById('editPatientForm').addEventListener('submit', function (event) {
    event.preventDefault();
    const form = event.target;
    const data = new FormData(form);
    const alertEl = document.getElementById('editPatientAlert');

    fetch('../assets/api/patients/update_patient.php', {
        method: 'POST',
        body: data
    })
        .then(function (response) { return response.json(); })
        .then(function (result) {
            if (result.success) {
                notifyAdminPatients(result.message || 'Patient updated successfully.', 'success');
                // Update active patient record
                if (activePatientRecord) {
                    activePatientRecord.first_name = data.get('first_name');
                    activePatientRecord.middle_name = data.get('middle_name');
                    activePatientRecord.last_name = data.get('last_name');
                    activePatientRecord.email = data.get('email');
                    activePatientRecord.phone_number = data.get('phone_number');
                    activePatientRecord.date_of_birth = data.get('date_of_birth');
                    activePatientRecord.gender = data.get('gender');
                    activePatientRecord.address = data.get('address');
                    activePatientRecord.occupation = data.get('occupation');
                }
                if (alertEl) alertEl.innerHTML = '';
            } else {
                if (alertEl) {
                    alertEl.innerHTML = '<div class="alert alert-danger py-2 px-3 mb-0 small">' + (result.message || 'Unable to update patient.') + '</div>';
                }
                notifyAdminPatients(result.message || 'Unable to update patient.', 'danger');
            }
        })
        .catch(function () {
            notifyAdminPatients('Unable to update patient.', 'danger');
        });
});

document.getElementById('setPatientPasswordForm').addEventListener('submit', function (event) {
    event.preventDefault();
    const form = event.target;
    const alertEl = document.getElementById('setPatientPasswordAlert');
    if (alertEl) alertEl.innerHTML = '';

    if (!activePatientRecord || !activePatientRecord.patient_id) {
        notifyAdminPatients('Select a patient first.', 'warning');
        return;
    }

    if (!form.checkValidity()) {
        form.reportValidity();
        return;
    }

    const newPassword = document.getElementById('set_patient_password').value;
    const confirmPassword = document.getElementById('set_patient_password_confirm').value;

    if (newPassword !== confirmPassword) {
        if (alertEl) alertEl.innerHTML = '<div class="alert alert-danger py-2 px-3 mb-0 small">Passwords do not match.</div>';
        notifyAdminPatients('Passwords do not match.', 'danger');
        return;
    }

    fetch('../assets/api/patients/set_patient_password.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({
            csrf_token: adminPatientCsrf,
            patient_id: activePatientRecord.patient_id,
            new_password: newPassword,
            confirm_password: confirmPassword
        })
    })
        .then(function (response) { return response.json(); })
        .then(function (result) {
            if (result.success) {
                notifyAdminPatients(result.message || 'Password updated.', 'success');
                document.getElementById('setPatientPasswordForm').reset();
            } else {
                if (alertEl) alertEl.innerHTML = '<div class="alert alert-danger py-2 px-3 mb-0 small">' + (result.message || 'Unable to update password.') + '</div>';
                notifyAdminPatients(result.message || 'Unable to update password.', 'danger');
            }
        })
        .catch(function () {
            if (alertEl) alertEl.innerHTML = '<div class="alert alert-danger py-2 px-3 mb-0 small">Unable to update password.</div>';
            notifyAdminPatients('Unable to update password.', 'danger');
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
                notifyAdminPatients(message, tone);
                if (typeof bootstrap !== 'undefined' && bootstrap.Modal) {
                    bootstrap.Modal.getInstance(document.getElementById('addPatientModal'))?.hide();
                }
                if (!result.already_exists) {
                    notifyAdminPatients('Patient created. Refresh the list to see changes.', 'info');
                }
            } else {
                notifyAdminPatients(result.message || 'Unable to create patient.', 'danger');
            }
        })
        .catch(function () {
            notifyAdminPatients('Unable to create patient.', 'danger');
        });
});
</script>
