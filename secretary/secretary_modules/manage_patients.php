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

<div class="d-flex justify-content-between align-items-center gap-2 mb-3">
    <div>
        <h5 class="card-title mb-0">Patient Management</h5>
        <p class="text-muted small mb-0 mt-1">View profiles, update account details, manage medical background, and reset login passwords.</p>
    </div>
    <button type="button" class="btn btn-primary btn-sm" data-bs-toggle="modal" data-bs-target="#addPatientModal">
        <i class="bi bi-person-plus"></i> Add Patient
    </button>
</div>

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
                        <button class="nav-link py-1 px-2" id="patient-tab-background" data-bs-toggle="tab" data-bs-target="#patientTabBackground" type="button" role="tab" style="font-size:0.82rem;">Profiling</button>
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
                                <button type="submit" class="btn btn-primary btn-sm" style="font-size:0.82rem;padding:0.3rem 0.8rem;">Save</button>
                            </div>
                        </form>
                    </div>

                    <div class="tab-pane fade" id="patientTabBackground" role="tabpanel">
                        <form id="profilingForm" class="ux-form-shell">
                            <input type="hidden" id="profiling_patient_id" name="patient_id">

                            <!-- Gender -->
                            <div class="mb-2">
                                <label class="form-label fw-bold" style="font-size:0.82rem;color:#1e293b;">Gender</label>
                                <div class="d-flex flex-wrap gap-2">
                                    <div class="form-check m-0"><input class="form-check-input" type="radio" name="pf_gender" id="pf_gender_male" value="Male"><label class="form-check-label fw-semibold" for="pf_gender_male" style="font-size:0.82rem;color:#334155;">Male</label></div>
                                    <div class="form-check m-0"><input class="form-check-input" type="radio" name="pf_gender" id="pf_gender_female" value="Female"><label class="form-check-label fw-semibold" for="pf_gender_female" style="font-size:0.82rem;color:#334155;">Female</label></div>
                                    <div class="form-check m-0"><input class="form-check-input" type="radio" name="pf_gender" id="pf_gender_other" value="Other"><label class="form-check-label fw-semibold" for="pf_gender_other" style="font-size:0.82rem;color:#334155;">Other</label></div>
                                </div>
                            </div>

                            <!-- Race -->
                            <div class="mb-2">
                                <label class="form-label fw-bold" style="font-size:0.82rem;color:#1e293b;">Race</label>
                                <div class="d-flex flex-wrap gap-2">
                                    <div class="form-check m-0"><input class="form-check-input" type="radio" name="pf_race" id="pf_race_asian" value="Asian"><label class="form-check-label fw-semibold" for="pf_race_asian" style="font-size:0.82rem;color:#334155;">Asian</label></div>
                                    <div class="form-check m-0"><input class="form-check-input" type="radio" name="pf_race" id="pf_race_caucasian" value="Caucasian"><label class="form-check-label fw-semibold" for="pf_race_caucasian" style="font-size:0.82rem;color:#334155;">Caucasian</label></div>
                                    <div class="form-check m-0"><input class="form-check-input" type="radio" name="pf_race" id="pf_race_black" value="Black/African American"><label class="form-check-label fw-semibold" for="pf_race_black" style="font-size:0.82rem;color:#334155;">Black</label></div>
                                    <div class="form-check m-0"><input class="form-check-input" type="radio" name="pf_race" id="pf_race_hispanic" value="Hispanic/Latino"><label class="form-check-label fw-semibold" for="pf_race_hispanic" style="font-size:0.82rem;color:#334155;">Hispanic</label></div>
                                    <div class="form-check m-0"><input class="form-check-input" type="radio" name="pf_race" id="pf_race_native" value="Native American"><label class="form-check-label fw-semibold" for="pf_race_native" style="font-size:0.82rem;color:#334155;">Native</label></div>
                                    <div class="form-check m-0"><input class="form-check-input" type="radio" name="pf_race" id="pf_race_pacific" value="Pacific Islander"><label class="form-check-label fw-semibold" for="pf_race_pacific" style="font-size:0.82rem;color:#334155;">Pacific</label></div>
                                    <div class="form-check m-0"><input class="form-check-input" type="radio" name="pf_race" id="pf_race_mixed" value="Mixed"><label class="form-check-label fw-semibold" for="pf_race_mixed" style="font-size:0.82rem;color:#334155;">Mixed</label></div>
                                    <div class="form-check m-0"><input class="form-check-input" type="radio" name="pf_race" id="pf_race_other" value="Other"><label class="form-check-label fw-semibold" for="pf_race_other" style="font-size:0.82rem;color:#334155;">Other</label></div>
                                </div>
                            </div>

                            <!-- Allergies (text) -->
                            <div class="mb-2">
                                <label class="form-label fw-bold" style="font-size:0.82rem;color:#1e293b;">Allergies</label>
                                <input type="text" id="pf_allergies" class="form-control" placeholder="e.g. Penicillin, Latex" style="padding:0.3rem 0.5rem;font-size:0.85rem;">
                            </div>

                            <!-- Smoking -->
                            <div class="mb-2">
                                <label class="form-label fw-bold" style="font-size:0.82rem;color:#1e293b;">Smoking</label>
                                <div class="d-flex flex-wrap gap-2">
                                    <div class="form-check m-0"><input class="form-check-input" type="radio" name="pf_smoking" id="pf_smoking_no" value="Non-smoker"><label class="form-check-label fw-semibold" for="pf_smoking_no" style="font-size:0.82rem;color:#334155;">Non-smoker</label></div>
                                    <div class="form-check m-0"><input class="form-check-input" type="radio" name="pf_smoking" id="pf_smoking_former" value="Former smoker"><label class="form-check-label fw-semibold" for="pf_smoking_former" style="font-size:0.82rem;color:#334155;">Former</label></div>
                                    <div class="form-check m-0"><input class="form-check-input" type="radio" name="pf_smoking" id="pf_smoking_current" value="Current smoker"><label class="form-check-label fw-semibold" for="pf_smoking_current" style="font-size:0.82rem;color:#334155;">Current</label></div>
                                </div>
                            </div>

                            <!-- Alcohol -->
                            <div class="mb-2">
                                <label class="form-label fw-bold" style="font-size:0.82rem;color:#1e293b;">Alcohol</label>
                                <div class="d-flex flex-wrap gap-2">
                                    <div class="form-check m-0"><input class="form-check-input" type="radio" name="pf_alcohol" id="pf_alcohol_no" value="Non-drinker"><label class="form-check-label fw-semibold" for="pf_alcohol_no" style="font-size:0.82rem;color:#334155;">Non-drinker</label></div>
                                    <div class="form-check m-0"><input class="form-check-input" type="radio" name="pf_alcohol" id="pf_alcohol_social" value="Social drinker"><label class="form-check-label fw-semibold" for="pf_alcohol_social" style="font-size:0.82rem;color:#334155;">Social</label></div>
                                    <div class="form-check m-0"><input class="form-check-input" type="radio" name="pf_alcohol" id="pf_alcohol_regular" value="Regular drinker"><label class="form-check-label fw-semibold" for="pf_alcohol_regular" style="font-size:0.82rem;color:#334155;">Regular</label></div>
                                </div>
                            </div>

                            <!-- ASA Class -->
                            <div class="mb-2">
                                <label class="form-label fw-bold" style="font-size:0.82rem;color:#1e293b;">ASA Class <i class="bi bi-info-circle" style="font-size:0.75rem;cursor:help;color:#6c757d;" title="ASA Physical Status Classification:&#10;I — Normal healthy patient&#10;II — Mild systemic disease&#10;III — Severe systemic disease&#10;IV — Severe systemic disease, constant threat to life&#10;V — Moribund, not expected to survive without surgery"></i></label>
                                <div class="d-flex flex-wrap gap-2">
                                    <div class="form-check m-0"><input class="form-check-input" type="radio" name="pf_asa" id="pf_asa_1" value="ASA I"><label class="form-check-label fw-semibold" for="pf_asa_1" style="font-size:0.82rem;color:#334155;">I</label></div>
                                    <div class="form-check m-0"><input class="form-check-input" type="radio" name="pf_asa" id="pf_asa_2" value="ASA II"><label class="form-check-label fw-semibold" for="pf_asa_2" style="font-size:0.82rem;color:#334155;">II</label></div>
                                    <div class="form-check m-0"><input class="form-check-input" type="radio" name="pf_asa" id="pf_asa_3" value="ASA III"><label class="form-check-label fw-semibold" for="pf_asa_3" style="font-size:0.82rem;color:#334155;">III</label></div>
                                    <div class="form-check m-0"><input class="form-check-input" type="radio" name="pf_asa" id="pf_asa_4" value="ASA IV"><label class="form-check-label fw-semibold" for="pf_asa_4" style="font-size:0.82rem;color:#334155;">IV</label></div>
                                    <div class="form-check m-0"><input class="form-check-input" type="radio" name="pf_asa" id="pf_asa_5" value="ASA V"><label class="form-check-label fw-semibold" for="pf_asa_5" style="font-size:0.82rem;color:#334155;">V</label></div>
                                </div>
                            </div>

                            <!-- Dental Anxiety -->
                            <div class="mb-2">
                                <label class="form-label fw-bold" style="font-size:0.82rem;color:#1e293b;">Dental Anxiety</label>
                                <div class="d-flex flex-wrap gap-2">
                                    <div class="form-check m-0"><input class="form-check-input" type="radio" name="pf_dental_anxiety" id="pf_anxiety_none" value="None"><label class="form-check-label fw-semibold" for="pf_anxiety_none" style="font-size:0.82rem;color:#334155;">None</label></div>
                                    <div class="form-check m-0"><input class="form-check-input" type="radio" name="pf_dental_anxiety" id="pf_anxiety_mild" value="Mild"><label class="form-check-label fw-semibold" for="pf_anxiety_mild" style="font-size:0.82rem;color:#334155;">Mild</label></div>
                                    <div class="form-check m-0"><input class="form-check-input" type="radio" name="pf_dental_anxiety" id="pf_anxiety_moderate" value="Moderate"><label class="form-check-label fw-semibold" for="pf_anxiety_moderate" style="font-size:0.82rem;color:#334155;">Moderate</label></div>
                                    <div class="form-check m-0"><input class="form-check-input" type="radio" name="pf_dental_anxiety" id="pf_anxiety_severe" value="Severe"><label class="form-check-label fw-semibold" for="pf_anxiety_severe" style="font-size:0.82rem;color:#334155;">Severe</label></div>
                                </div>
                            </div>

                            <!-- Bleeding Disorder -->
                            <div class="mb-2">
                                <label class="form-label fw-bold" style="font-size:0.82rem;color:#1e293b;">Bleeding Disorder</label>
                                <div class="d-flex flex-wrap gap-2">
                                    <div class="form-check m-0"><input class="form-check-input" type="radio" name="pf_bleeding_disorder" id="pf_bleed_none" value="None"><label class="form-check-label fw-semibold" for="pf_bleed_none" style="font-size:0.82rem;color:#334155;">None</label></div>
                                    <div class="form-check m-0"><input class="form-check-input" type="radio" name="pf_bleeding_disorder" id="pf_bleed_hemophilia" value="Hemophilia"><label class="form-check-label fw-semibold" for="pf_bleed_hemophilia" style="font-size:0.82rem;color:#334155;">Hemophilia</label></div>
                                    <div class="form-check m-0"><input class="form-check-input" type="radio" name="pf_bleeding_disorder" id="pf_bleed_vonw" value="Von Willebrand"><label class="form-check-label fw-semibold" for="pf_bleed_vonw" style="font-size:0.82rem;color:#334155;">Von Willebrand</label></div>
                                    <div class="form-check m-0"><input class="form-check-input" type="radio" name="pf_bleeding_disorder" id="pf_bleed_thrombo" value="Thrombocytopenia"><label class="form-check-label fw-semibold" for="pf_bleed_thrombo" style="font-size:0.82rem;color:#334155;">Thrombocytopenia</label></div>
                                    <div class="form-check m-0"><input class="form-check-input" type="radio" name="pf_bleeding_disorder" id="pf_bleed_other" value="Other"><label class="form-check-label fw-semibold" for="pf_bleed_other" style="font-size:0.82rem;color:#334155;">Other</label></div>
                                </div>
                            </div>

                            <!-- Pregnancy (female only) -->
                            <div id="pf_pregnancy_wrapper" class="mb-2" style="display:none;">
                                <label class="form-label fw-bold" style="font-size:0.82rem;color:#1e293b;">Pregnancy</label>
                                <div class="d-flex flex-wrap gap-2">
                                    <div class="form-check m-0"><input class="form-check-input" type="radio" name="pf_pregnancy_status" id="pf_preg_not" value="Not pregnant"><label class="form-check-label fw-semibold" for="pf_preg_not" style="font-size:0.82rem;color:#334155;">Not pregnant</label></div>
                                    <div class="form-check m-0"><input class="form-check-input" type="radio" name="pf_pregnancy_status" id="pf_preg_yes" value="Pregnant"><label class="form-check-label fw-semibold" for="pf_preg_yes" style="font-size:0.82rem;color:#334155;">Pregnant</label></div>
                                    <div class="form-check m-0"><input class="form-check-input" type="radio" name="pf_pregnancy_status" id="pf_preg_post" value="Postpartum"><label class="form-check-label fw-semibold" for="pf_preg_post" style="font-size:0.82rem;color:#334155;">Postpartum</label></div>
                                    <div class="form-check m-0"><input class="form-check-input" type="radio" name="pf_pregnancy_status" id="pf_preg_nursing" value="Nursing"><label class="form-check-label fw-semibold" for="pf_preg_nursing" style="font-size:0.82rem;color:#334155;">Nursing</label></div>
                                </div>
                            </div>

                            <!-- Textareas -->
                            <div class="mb-2"><label class="form-label fw-bold" style="font-size:0.82rem;color:#1e293b;">Medical History</label><textarea id="pf_medical_history" class="form-control" rows="1" placeholder="Previous surgeries, chronic conditions..." style="padding:0.3rem 0.5rem;font-size:0.85rem;"></textarea></div>
                            <div class="mb-2"><label class="form-label fw-bold" style="font-size:0.82rem;color:#1e293b;">Medications</label><textarea id="pf_medications" class="form-control" rows="1" placeholder="Current medications and dosages..." style="padding:0.3rem 0.5rem;font-size:0.85rem;"></textarea></div>
                            <div class="mb-2"><label class="form-label fw-bold" style="font-size:0.82rem;color:#1e293b;">Previous Treatment</label><textarea id="pf_previous_dental_treatment" class="form-control" rows="1" placeholder="Previous dental procedures..." style="padding:0.3rem 0.5rem;font-size:0.85rem;"></textarea></div>

                            <!-- Conditions (checkboxes) -->
                            <div class="mb-2">
                                <label class="form-label fw-bold" style="font-size:0.82rem;color:#1e293b;">Conditions</label>
                                <div class="d-flex flex-wrap gap-2">
                                    <div class="form-check m-0"><input class="form-check-input" type="checkbox" id="pf_hypertensive" style="width:14px;height:14px;"><label class="form-check-label fw-semibold" for="pf_hypertensive" style="font-size:0.82rem;color:#334155;">Hypertensive</label></div>
                                    <div class="form-check m-0"><input class="form-check-input" type="checkbox" id="pf_diabetic" style="width:14px;height:14px;"><label class="form-check-label fw-semibold" for="pf_diabetic" style="font-size:0.82rem;color:#334155;">Diabetic</label></div>
                                    <div class="form-check m-0"><input class="form-check-input" type="checkbox" id="pf_asthmatic" style="width:14px;height:14px;"><label class="form-check-label fw-semibold" for="pf_asthmatic" style="font-size:0.82rem;color:#334155;">Asthmatic</label></div>
                                    <div class="form-check m-0"><input class="form-check-input" type="checkbox" id="pf_heart_disease" style="width:14px;height:14px;"><label class="form-check-label fw-semibold" for="pf_heart_disease" style="font-size:0.82rem;color:#334155;">Heart Disease</label></div>
                                    <div class="form-check m-0"><input class="form-check-input" type="checkbox" id="pf_hiv_positive" style="width:14px;height:14px;"><label class="form-check-label fw-semibold" for="pf_hiv_positive" style="font-size:0.82rem;color:#334155;">HIV+</label></div>
                                </div>
                            </div>

                            <div class="mb-2"><label class="form-label fw-bold" style="font-size:0.82rem;color:#1e293b;">Notes</label><textarea id="pf_background_notes" class="form-control" rows="1" placeholder="Additional notes..." style="padding:0.3rem 0.5rem;font-size:0.85rem;"></textarea></div>

                            <div class="d-flex justify-content-end mt-1">
                                <button type="submit" class="btn btn-primary btn-sm" style="font-size:0.82rem;padding:0.3rem 0.7rem;">Save</button>
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
                                <form id="setPatientPasswordForm">
                                    <?php
                                    $password_field_id = 'set_patient_password';
                                    $password_field_label = 'New';
                                    $password_field_attrs = 'required minlength="8" autocomplete="new-password" style="padding:0.3rem 0.5rem;font-size:0.85rem;"';
                                    include dirname(dirname(__DIR__)) . '/includes/password_field.php';
                                    $password_field_id = 'set_patient_password_confirm';
                                    $password_field_label = 'Confirm';
                                    $password_field_attrs = 'required minlength="8" autocomplete="new-password" style="padding:0.3rem 0.5rem;font-size:0.85rem;"';
                                    include dirname(dirname(__DIR__)) . '/includes/password_field.php';
                                    ?>
                                    <button type="submit" class="btn btn-primary btn-sm mt-1" style="font-size:0.82rem;padding:0.3rem 0.7rem;">Update</button>
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
                                    <button type="submit" class="btn btn-primary btn-sm" style="font-size:0.82rem;padding:0.3rem 0.7rem;">
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
window.secretaryPatientCsrf = <?php echo json_encode($csrf_token, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP); ?>;
window.activePatientRecord = null;

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

function setRadioValue(name, value) {
    const radios = document.querySelectorAll('input[name="' + name + '"]');
    radios.forEach(function(r) { r.checked = (r.value === value); });
}

function getRadioValue(name) {
    const checked = document.querySelector('input[name="' + name + '"]:checked');
    return checked ? checked.value : '';
}

function fillProfilingForm(profiling, patientGender) {
    const pf = profiling || {};
    setRadioValue('pf_gender', patientGender || '');
    setRadioValue('pf_race', pf.race || '');
    document.getElementById('pf_medical_history').value = pf.medical_history || '';
    document.getElementById('pf_medications').value = pf.medications || '';
    document.getElementById('pf_allergies').value = pf.allergies || '';
    setRadioValue('pf_smoking', pf.smoking || '');
    setRadioValue('pf_alcohol', pf.alcohol || '');
    document.getElementById('pf_hypertensive').checked = !!pf.hypertensive;
    document.getElementById('pf_diabetic').checked = !!pf.diabetic;
    document.getElementById('pf_asthmatic').checked = !!pf.asthmatic;
    document.getElementById('pf_heart_disease').checked = !!pf.heart_disease;
    document.getElementById('pf_hiv_positive').checked = !!pf.hiv_positive;
    setRadioValue('pf_bleeding_disorder', pf.bleeding_disorder || '');
    setRadioValue('pf_asa', pf.asa || '');
    setRadioValue('pf_pregnancy_status', pf.pregnancy_status || '');
    document.getElementById('pf_previous_dental_treatment').value = pf.previous_dental_treatment || '';
    setRadioValue('pf_dental_anxiety', pf.dental_anxiety || '');
    document.getElementById('pf_background_notes').value = pf.background_notes || '';
    updatePregnancyVisibility(patientGender);
}

function updatePregnancyVisibility(gender) {
    const wrapper = document.getElementById('pf_pregnancy_wrapper');
    if (!wrapper) return;
    const isFemale = gender === 'Female';
    wrapper.style.display = isFemale ? '' : 'none';
    if (!isFemale) {
        document.getElementById('pf_pregnancy_status').value = '';
    }
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
    document.getElementById('profiling_patient_id').value = patient.patient_id || '';
    document.getElementById('reset_patient_id').value = patient.patient_id || '';
    document.getElementById('account_patient_phone').textContent = patient.phone_number || '—';
    document.getElementById('account_patient_email').textContent = patient.email || '—';
    document.getElementById('account_patient_last_login').textContent = formatDateTime(patient.last_login_at);
}

function openPatientProfileModal(patient, activeTab) {
    activePatientRecord = Object.assign({}, patient);
    const modalEl = document.getElementById('patientProfileModal');
    if (!modalEl) return;
    if (typeof bootstrap === 'undefined' || !bootstrap.Modal) return;
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
                fillProfilingForm(data.profiling || null, activePatientRecord.gender || '');
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
            if (tabTrigger && typeof bootstrap !== 'undefined' && bootstrap.Tab) {
                bootstrap.Tab.getOrCreateInstance(tabTrigger).show();
            }
            modal.show();
        });
}

document.getElementById('profilingForm').addEventListener('submit', function (event) {
    event.preventDefault();
    const patientId = parseInt(document.getElementById('profiling_patient_id').value, 10);
    const gender = getRadioValue('pf_gender');
    const profiling = {
        race: getRadioValue('pf_race'),
        medical_history: document.getElementById('pf_medical_history').value.trim(),
        medications: document.getElementById('pf_medications').value.trim(),
        allergies: document.getElementById('pf_allergies').value.trim(),
        smoking: getRadioValue('pf_smoking'),
        alcohol: getRadioValue('pf_alcohol'),
        hypertensive: document.getElementById('pf_hypertensive').checked,
        diabetic: document.getElementById('pf_diabetic').checked,
        asthmatic: document.getElementById('pf_asthmatic').checked,
        heart_disease: document.getElementById('pf_heart_disease').checked,
        hiv_positive: document.getElementById('pf_hiv_positive').checked,
        bleeding_disorder: getRadioValue('pf_bleeding_disorder'),
        asa: getRadioValue('pf_asa'),
        pregnancy_status: getRadioValue('pf_pregnancy_status'),
        previous_dental_treatment: document.getElementById('pf_previous_dental_treatment').value.trim(),
        dental_anxiety: getRadioValue('pf_dental_anxiety'),
        background_notes: document.getElementById('pf_background_notes').value.trim()
    };

    // Save profiling data
    fetch('../assets/api/patients/save_profiling.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ patient_id: patientId, profiling: profiling })
    })
        .then(function (response) { return response.json(); })
        .then(function (result) {
            if (result.success) {
                // Also save gender to patients table
                const genderFd = new FormData();
                genderFd.append('patient_id', patientId);
                genderFd.append('gender', gender);
                genderFd.append('csrf_token', window.secretaryPatientCsrf);
                return fetch('../assets/api/patients/update_patient.php', { method: 'POST', body: genderFd });
            } else {
                throw new Error(result.message || 'Unable to save patient profiling.');
            }
        })
        .then(function (response) { return response ? response.json() : null; })
        .then(function (genderResult) {
            if (genderResult && genderResult.success) {
                notifySecretaryPatients('Patient profiling saved successfully.', 'success');
            } else {
                notifySecretaryPatients('Profiling saved but gender update failed.', 'warning');
            }
        })
        .catch(function (err) {
            notifySecretaryPatients(err.message || 'Unable to save patient profiling.', 'danger');
        });
});

// Gender change handler - show/hide pregnancy field
document.querySelectorAll('input[name="pf_gender"]').forEach(function(radio) {
    radio.addEventListener('change', function() {
        updatePregnancyVisibility(this.value);
    });
});

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
                notifySecretaryPatients(result.message || 'Patient updated successfully.', 'success');
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
                notifySecretaryPatients(result.message || 'Unable to update patient.', 'danger');
            }
        })
        .catch(function () {
            notifySecretaryPatients('Unable to update patient.', 'danger');
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
                if (typeof bootstrap !== 'undefined' && bootstrap.Modal) {
                    bootstrap.Modal.getInstance(document.getElementById('addPatientModal'))?.hide();
                }
                if (!result.already_exists) {
                    notifySecretaryPatients('Patient created. Refresh the list to see changes.', 'info');
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
