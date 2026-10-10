<?php
/**
 * Patients page shared by the doctor, secretary and admin portals: a searchable patient
 * list and a tabbed Patient Chart that replaces the list in place (assets/js/doctor-patients.js).
 *
 * Before including, set:
 *   $patientsRole  'doctor' | 'secretary' | 'admin'
 *
 * A doctor sees only the patients they have appointments with; the secretary and admin see
 * every patient, including those with no appointment yet. Staff who can manage patients
 * also get Add Patient and the patient settings modal (assets/js/patient-account.js).
 */
include dirname(__DIR__) . '/config/database.php';
include_once dirname(__DIR__) . '/config/session_check.php';
include_once dirname(__DIR__) . '/config/security.php';
include_once dirname(__DIR__) . '/config/capabilities.php';
include_once dirname(__DIR__) . '/config/odontogram_helpers.php';

$patientsRole = in_array($patientsRole ?? '', ['doctor', 'secretary', 'admin'], true) ? $patientsRole : 'doctor';
check_session([$patientsRole]);

$userId = (int)($_SESSION['user_id'] ?? 0);
$isDoctorView = $patientsRole === 'doctor';
$today = date('Y-m-d');
$pmCsrf = (string)($csrf_token ?? security_generate_csrf_token());
$canManagePatients = capabilities_can($patientsRole === 'admin' ? 'manage_users' : 'patient.manage');
// The ledger shows money, so only the roles that work Billing see it.
$canViewLedger = in_array($patientsRole, ['secretary', 'admin'], true);

if (!function_exists('pm_clean_na')) {
    // The patients table stores "N/A" for blank optional fields.
    function pm_clean_na($value): string
    {
        $s = trim((string)$value);
        return strtoupper($s) === 'N/A' ? '' : $s;
    }
}

// ---- patients ----
$patients = [];
if ($isDoctorView) {
    $pstmt = $conn->prepare(
        'SELECT p.* FROM patients p
         WHERE EXISTS (SELECT 1 FROM appointments a WHERE a.patient_id = p.patient_id AND a.user_id = ?)'
    );
    if ($pstmt) {
        $pstmt->bind_param('i', $userId);
        $pstmt->execute();
        $patientRows = $pstmt->get_result();
    }
} else {
    $patientRows = $conn->query('SELECT * FROM patients ORDER BY created_at DESC');
}

while (!empty($patientRows) && ($row = $patientRows->fetch_assoc())) {
    $pid = (int)$row['patient_id'];
    $photo = trim((string)($row['profile_image'] ?? ''));
    if ($photo === '') {
        $photo = trim((string)($row['google_picture'] ?? ''));
    }
    $age = null;
    if (!empty($row['date_of_birth']) && strtotime((string)$row['date_of_birth'])) {
        $age = (int)(new DateTime((string)$row['date_of_birth']))->diff(new DateTime($today))->y;
    }
    // Whitelisted on purpose: the row also holds password hashes and lockout state.
    $patients[$pid] = [
        'patient_id' => $pid,
        'first_name' => (string)$row['first_name'],
        'middle_name' => pm_clean_na($row['middle_name'] ?? ''),
        'last_name' => (string)$row['last_name'],
        'email' => (string)($row['email'] ?? ''),
        'phone_number' => (string)($row['phone_number'] ?? ''),
        'gender' => pm_clean_na($row['gender'] ?? ''),
        'date_of_birth' => $row['date_of_birth'],
        'address' => pm_clean_na($row['address'] ?? ''),
        'occupation' => pm_clean_na($row['occupation'] ?? ''),
        'created_at' => $row['created_at'] ?? null,
        'last_login_at' => $row['last_login_at'] ?? null,
        'age' => $age,
        'photo' => $photo,
        'alerts' => [],
        'notices' => [],
        'appointments' => [],
    ];
}
if (isset($pstmt) && $pstmt) {
    $pstmt->close();
}

// ---- appointments (the doctor's own, or every doctor's for staff) ----
$apptSql = "
    SELECT
        a.patient_id, a.appointment_id, a.appointment_date, a.status, a.is_online_appointment, a.reason, a.notes,
        TRIM(CONCAT(COALESCE(d.first_name, ''), ' ', COALESCE(d.last_name, ''))) AS doctor_name,
        (SELECT DATE_FORMAT(s.scheduled_start, '%Y-%m-%d %H:%i') FROM patient_schedules s WHERE s.appointment_id = a.appointment_id ORDER BY s.scheduled_start DESC LIMIT 1) AS start_time,
        (SELECT DATE_FORMAT(s.scheduled_end, '%Y-%m-%d %H:%i') FROM patient_schedules s WHERE s.appointment_id = a.appointment_id ORDER BY s.scheduled_start DESC LIMIT 1) AS end_time,
        (SELECT cr.record_id FROM clinical_records cr WHERE cr.appointment_id = a.appointment_id ORDER BY cr.record_id DESC LIMIT 1) AS record_id,
        (SELECT cr.status FROM clinical_records cr WHERE cr.appointment_id = a.appointment_id ORDER BY cr.record_id DESC LIMIT 1) AS record_status,
        sv.service_name
    FROM appointments a
    LEFT JOIN users d ON d.user_id = a.user_id
    LEFT JOIN selected_services ss ON ss.appointment_id = a.appointment_id
    LEFT JOIN services sv ON sv.service_id = ss.service_id
    " . ($isDoctorView ? 'WHERE a.user_id = ?' : '') . "
    ORDER BY a.appointment_date DESC, a.appointment_id DESC
";
$astmt = $conn->prepare($apptSql);
if ($astmt) {
    if ($isDoctorView) {
        $astmt->bind_param('i', $userId);
    }
    $astmt->execute();
    $ares = $astmt->get_result();
    while ($row = $ares->fetch_assoc()) {
        $pid = (int)$row['patient_id'];
        if (!isset($patients[$pid])) continue;
        $aid = (int)$row['appointment_id'];
        if (!isset($patients[$pid]['appointments'][$aid])) {
            $patients[$pid]['appointments'][$aid] = [
                'appointment_id' => $aid,
                'date' => (string)$row['appointment_date'],
                'status' => (string)$row['status'],
                'is_online' => (int)$row['is_online_appointment'] === 1,
                'reason' => (string)($row['reason'] ?? ''),
                'notes' => (string)($row['notes'] ?? ''),
                'doctor' => (string)($row['doctor_name'] ?? ''),
                'start' => $row['start_time'],
                'end' => $row['end_time'],
                'record_id' => $row['record_id'] !== null ? (int)$row['record_id'] : null,
                'record_status' => (string)($row['record_status'] ?? ''),
                'services' => [],
            ];
        }
        if (!empty($row['service_name'])) {
            $patients[$pid]['appointments'][$aid]['services'][] = (string)$row['service_name'];
        }
    }
    $astmt->close();
}

// Medical alerts come from each patient's latest general profiling (record_id IS NULL).
// Free-text answers like "N/A", "None" or "Not pregnant" are not alerts.
if (!function_exists('dp_is_meaningful')) {
function dp_is_meaningful($value): bool
{
    $s = strtolower(trim((string)$value));
    if ($s === '' || $s === '0') return false;
    if (in_array($s, ['n/a', 'na', 'none', 'no', 'nil', 'normal', 'negative', 'unknown', '-'], true)) return false;
    if (strpos($s, 'n/a') !== false || strpos($s, 'not ') === 0 || strpos($s, 'no ') === 0) return false;
    return true;
}

function dp_is_yes($value): bool
{
    return $value === 1 || $value === '1' || $value === true || strtolower(trim((string)$value)) === 'yes';
}
}

if (!empty($patients)) {
    $ids = array_keys($patients);
    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $prstmt = $conn->prepare("SELECT * FROM patient_profiling WHERE record_id IS NULL AND patient_id IN ($placeholders) ORDER BY profiling_id DESC");
    if ($prstmt) {
        $prstmt->bind_param(str_repeat('i', count($ids)), ...$ids);
        $prstmt->execute();
        $pres = $prstmt->get_result();
        $seen = [];
        while ($prof = $pres->fetch_assoc()) {
            $pid = (int)$prof['patient_id'];
            if (isset($seen[$pid])) continue;
            $seen[$pid] = true;

            $alerts = [];
            if (dp_is_meaningful($prof['allergies'] ?? '')) $alerts[] = 'Allergy: ' . trim((string)$prof['allergies']);
            if (dp_is_yes($prof['is_hypertensive'] ?? 0)) $alerts[] = 'Hypertensive';
            if (dp_is_yes($prof['is_diabetic'] ?? 0)) $alerts[] = 'Diabetic';
            if (dp_is_yes($prof['is_asthmatic'] ?? 0)) $alerts[] = 'Asthmatic';
            if (dp_is_yes($prof['has_heart_disease'] ?? 0)) $alerts[] = 'Heart disease';
            if (dp_is_yes($prof['is_hiv_positive'] ?? 0)) $alerts[] = 'HIV positive';
            $bleeding = $prof['bleeding_disorder'] ?? '';
            if (dp_is_yes($bleeding) || dp_is_meaningful($bleeding)) $alerts[] = 'Bleeding disorder';
            $isMale = strtolower(trim($patients[$pid]['gender'])) === 'male';
            if (!$isMale && dp_is_meaningful($prof['pregnancy_status'] ?? '')) $alerts[] = 'Pregnancy: ' . trim((string)$prof['pregnancy_status']);

            $notices = [];
            if (dp_is_meaningful($prof['medications'] ?? '')) $notices[] = 'On medication';
            if (dp_is_meaningful($prof['dental_anxiety'] ?? '')) $notices[] = 'Dental anxiety';

            $patients[$pid]['alerts'] = $alerts;
            $patients[$pid]['notices'] = $notices;
        }
        $prstmt->close();
    }
}

foreach ($patients as &$patient) {
    $patient['appointments'] = array_values($patient['appointments']);
}
unset($patient);

$services = [];
$sres = $conn->query('SELECT service_id, service_name, initial_price FROM services WHERE is_active = 1 ORDER BY service_name ASC');
if ($sres) {
    while ($svc = $sres->fetch_assoc()) {
        $services[] = ['service_id' => (int)$svc['service_id'], 'name' => (string)$svc['service_name'], 'price' => (float)$svc['initial_price']];
    }
}

$toothLabels = [];
$tres = $conn->query('SELECT code, label FROM tooth_status_type');
if ($tres) {
    while ($t = $tres->fetch_assoc()) {
        $toothLabels[(string)$t['code']] = (string)$t['label'];
    }
}

$patientsConfig = [
    'role' => $patientsRole,
    'canManage' => $canManagePatients,
    'canViewLedger' => $canViewLedger,
    'patients' => array_values($patients),
    'services' => $services,
    'toothLabels' => $toothLabels,
    'today' => $today,
    'csrfToken' => $pmCsrf,
    'query' => trim((string)($_GET['q'] ?? '')),
    'openPatientId' => (int)($_GET['patient_id'] ?? 0),
];

$pmTitle = $isDoctorView ? 'My Patients' : 'Patients';
$pmSubtitle = $isDoctorView
    ? 'Patients you have seen or are scheduled to see. Select one to open their chart.'
    : 'Every registered patient. Select one to open their chart.';
$pmCsrfAttr = htmlspecialchars($pmCsrf, ENT_QUOTES, 'UTF-8');
?>

<div id="dpListView">
<div class="card border-0 dp-page">
    <div class="card-header dp-page__header">
        <div>
            <h2 class="card-title mb-0"><?php echo htmlspecialchars($pmTitle, ENT_QUOTES, 'UTF-8'); ?></h2>
            <p class="dp-page__sub mb-0"><?php echo htmlspecialchars($pmSubtitle, ENT_QUOTES, 'UTF-8'); ?></p>
        </div>
        <?php if ($canManagePatients): ?>
        <button type="button" class="btn btn-primary dp-add-btn" data-pa-add>
            <i class="bi bi-person-plus" aria-hidden="true"></i> <span>Add Patient</span>
        </button>
        <?php endif; ?>
    </div>

    <div class="card-body dp-toolbar">
        <div class="unified-table-search-wrap module-search-inline dp-toolbar__search">
            <i class="bi bi-search unified-table-search-icon" aria-hidden="true"></i>
            <input type="search" id="dpSearch" class="form-control unified-table-search-input" placeholder="Search by name, phone, or email..." aria-label="Search patients" autocomplete="off">
        </div>
        <div class="dp-chips" id="dpChips" role="group" aria-label="Filter patients">
            <button type="button" class="dp-chip-btn is-active" data-dp-filter="all">All <span class="dp-chip-btn__count" data-dp-count="all">0</span></button>
            <button type="button" class="dp-chip-btn" data-dp-filter="upcoming"><i class="bi bi-calendar-event"></i> Upcoming <span class="dp-chip-btn__count" data-dp-count="upcoming">0</span></button>
            <button type="button" class="dp-chip-btn" data-dp-filter="month"><i class="bi bi-clock-history"></i> Seen this month <span class="dp-chip-btn__count" data-dp-count="month">0</span></button>
            <button type="button" class="dp-chip-btn dp-chip-btn--alert" data-dp-filter="alerts"><i class="bi bi-exclamation-triangle"></i> Medical alerts <span class="dp-chip-btn__count" data-dp-count="alerts">0</span></button>
        </div>
        <select id="dpSort" class="form-select form-select-sm dp-toolbar__sort" aria-label="Sort patients">
            <option value="recent">Recently seen</option>
            <option value="next">Next appointment</option>
            <?php if (!$isDoctorView): ?><option value="newest">Newest registered</option><?php endif; ?>
            <option value="name_asc">Name A–Z</option>
            <option value="name_desc">Name Z–A</option>
            <option value="visits">Most visits</option>
        </select>
    </div>

    <div class="card-body pt-0">
        <div class="dp-list" id="dpList" aria-live="polite"></div>
        <div class="dp-empty" id="dpEmpty" hidden></div>
    </div>
</div>
</div>

<!-- Patient Chart: replaces the list in place (no modal) so the chart gets the full page. -->
<div class="card border-0 dp-page dp-chart" id="dpChartView" aria-labelledby="dpChartName" hidden>
    <div class="dp-chart__header">
        <div class="dp-chart__identity" id="dpChartIdentity"></div>
        <button type="button" class="dp-back" data-dp-back aria-label="Back to <?php echo htmlspecialchars($pmTitle, ENT_QUOTES, 'UTF-8'); ?>" title="Back to <?php echo htmlspecialchars($pmTitle, ENT_QUOTES, 'UTF-8'); ?> (Esc)">
            <span class="dp-back__icon" aria-hidden="true"><i class="bi bi-arrow-left"></i></span>
            <span class="dp-back__text">Back</span>
        </button>
    </div>
    <div class="dp-chart__tabs" role="tablist" aria-label="Patient chart sections">
        <button type="button" class="dp-tab is-active" role="tab" aria-selected="true" data-dp-tab="overview"><i class="bi bi-grid-1x2"></i> Overview</button>
        <button type="button" class="dp-tab" role="tab" aria-selected="false" data-dp-tab="appointments"><i class="bi bi-calendar3"></i> Appointments</button>
        <button type="button" class="dp-tab" role="tab" aria-selected="false" data-dp-tab="records"><i class="bi bi-journal-medical"></i> Medical Records</button>
        <button type="button" class="dp-tab" role="tab" aria-selected="false" data-dp-tab="charting"><i class="bi bi-grid-3x3-gap"></i> Charting</button>
        <button type="button" class="dp-tab" role="tab" aria-selected="false" data-dp-tab="plan"><i class="bi bi-list-check"></i> Treatments</button>
        <button type="button" class="dp-tab" role="tab" aria-selected="false" data-dp-tab="profiling"><i class="bi bi-person-vcard"></i> Profiling</button>
        <button type="button" class="dp-tab" role="tab" aria-selected="false" data-dp-tab="photos"><i class="bi bi-images"></i> Photos</button>
        <?php if ($canViewLedger): ?>
        <button type="button" class="dp-tab" role="tab" aria-selected="false" data-dp-tab="ledger"><i class="bi bi-journal-text"></i> Ledger</button>
        <?php endif; ?>
    </div>
    <div class="dp-chart__body">
        <section class="dp-pane is-active" data-dp-pane="overview" role="tabpanel"></section>
        <section class="dp-pane" data-dp-pane="appointments" role="tabpanel"></section>
        <section class="dp-pane" data-dp-pane="records" role="tabpanel"></section>
        <!-- Charting: the read-only odontogram (2D chart + 3D preview) for one visit at a
             time. Static markup so the iframe loads once and is reused across patients. -->
        <section class="dp-pane" data-dp-pane="charting" role="tabpanel">
            <div class="dp-charting">
                <div class="dp-charting__bar">
                    <div class="dp-charting__dates" id="dpChartingDates" role="group" aria-label="Choose a visit"></div>
                    <button type="button" class="dp-link dp-charting__fs" id="dpChartingFullscreen"><i class="bi bi-arrows-fullscreen"></i> <span>Full screen</span></button>
                </div>
                <div class="dp-charting__note" id="dpChartingNote"></div>
                <div class="odontogram-viewport dp-charting__viewport" id="dpChartingViewport">
                    <button type="button" id="dpChartingFsClose" class="odontogram-fs-close" aria-label="Exit full screen" title="Exit full screen" hidden><i class="bi bi-x-lg" aria-hidden="true"></i></button>
                    <div class="dp-charting__frame" style="height:<?php echo (int)odontogram_embed_height_px(); ?>px;">
                        <iframe id="dpChartingFrame" data-src="<?php echo htmlspecialchars(odontogram_embed_src(), ENT_QUOTES, 'UTF-8'); ?>" title="Dental chart (read-only)"></iframe>
                    </div>
                    <?php odontogram_render_rotate_prompt(); ?>
                </div>
            </div>
        </section>
        <section class="dp-pane" data-dp-pane="plan" role="tabpanel"><div class="tp-host" id="dpTreatmentPlan"></div></section>
        <section class="dp-pane" data-dp-pane="profiling" role="tabpanel"></section>
        <section class="dp-pane" data-dp-pane="photos" role="tabpanel"></section>
        <?php if ($canViewLedger): ?>
        <section class="dp-pane" data-dp-pane="ledger" role="tabpanel"></section>
        <?php endif; ?>
    </div>
</div>

<?php if ($canManagePatients): ?>
<!-- Add Patient -->
<div class="modal fade pa-modal" id="paAddModal" tabindex="-1" aria-labelledby="paAddTitle" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-centered modal-dialog-scrollable modal-fullscreen-sm-down">
        <div class="modal-content">
            <div class="pa-modal__head">
                <span class="pa-modal__icon" aria-hidden="true"><i class="bi bi-person-plus"></i></span>
                <div class="pa-modal__heading">
                    <h5 class="pa-modal__title" id="paAddTitle">Add Patient</h5>
                    <p class="pa-modal__sub">Creates a patient portal account. A temporary password is emailed to the patient.</p>
                </div>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <form id="paAddForm" class="pa-modal__body" novalidate autocomplete="off" data-no-ajax>
                <input type="hidden" name="csrf_token" value="<?php echo $pmCsrfAttr; ?>">
                <div class="pa-section">
                    <h6 class="pa-section__title"><i class="bi bi-person"></i> Personal details</h6>
                    <div class="pa-grid">
                        <label class="pa-field"><span class="pa-field__label">First name <em>*</em></span><input type="text" name="first_name" class="form-control" required maxlength="100"></label>
                        <label class="pa-field"><span class="pa-field__label">Middle name</span><input type="text" name="middle_name" class="form-control" maxlength="100"></label>
                        <label class="pa-field"><span class="pa-field__label">Last name <em>*</em></span><input type="text" name="last_name" class="form-control" required maxlength="100"></label>
                        <label class="pa-field"><span class="pa-field__label">Birthdate</span><input type="date" name="date_of_birth" class="form-control" max="<?php echo $today; ?>"></label>
                        <label class="pa-field"><span class="pa-field__label">Gender</span>
                            <select name="gender" class="form-select">
                                <option value="">Unspecified</option>
                                <option value="Male">Male</option>
                                <option value="Female">Female</option>
                                <option value="Other">Other</option>
                            </select>
                        </label>
                    </div>
                </div>
                <div class="pa-section">
                    <h6 class="pa-section__title"><i class="bi bi-telephone"></i> Contact</h6>
                    <div class="pa-grid">
                        <label class="pa-field"><span class="pa-field__label">Email <em>*</em></span><input type="email" name="email" class="form-control" required maxlength="150" placeholder="name@example.com"></label>
                        <label class="pa-field"><span class="pa-field__label">Phone</span><input type="tel" name="phone_number" class="form-control" maxlength="30" placeholder="09XX XXX XXXX"></label>
                        <label class="pa-field pa-field--wide"><span class="pa-field__label">Address</span><input type="text" name="address" class="form-control" maxlength="500"></label>
                    </div>
                </div>
                <div class="pa-alert" id="paAddAlert" role="alert" hidden></div>
            </form>
            <div class="pa-modal__foot">
                <button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancel</button>
                <button type="submit" form="paAddForm" class="btn btn-primary" id="paAddSubmit"><i class="bi bi-person-plus"></i> Create patient</button>
            </div>
        </div>
    </div>
</div>

<!-- Patient settings: edit details and manage the portal account -->
<div class="modal fade pa-modal" id="paSettingsModal" tabindex="-1" aria-labelledby="paSettingsTitle" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-centered modal-dialog-scrollable modal-fullscreen-sm-down">
        <div class="modal-content">
            <div class="pa-modal__head">
                <span class="pa-modal__avatar" id="paSettingsAvatar" aria-hidden="true"></span>
                <div class="pa-modal__heading">
                    <h5 class="pa-modal__title" id="paSettingsTitle">Patient settings</h5>
                    <p class="pa-modal__sub" id="paSettingsSub"></p>
                </div>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="pa-tabs" role="tablist" aria-label="Patient settings sections">
                <button type="button" class="dp-tab is-active" role="tab" aria-selected="true" data-pa-tab="details"><i class="bi bi-person-lines-fill"></i> Details</button>
                <button type="button" class="dp-tab" role="tab" aria-selected="false" data-pa-tab="account"><i class="bi bi-shield-lock"></i> Account</button>
            </div>
            <div class="pa-modal__body">
                <section class="pa-pane is-active" data-pa-pane="details" role="tabpanel">
                    <form id="paEditForm" novalidate autocomplete="off" data-no-ajax>
                        <input type="hidden" name="csrf_token" value="<?php echo $pmCsrfAttr; ?>">
                        <input type="hidden" name="patient_id">
                        <div class="pa-section">
                            <h6 class="pa-section__title"><i class="bi bi-person"></i> Personal details</h6>
                            <div class="pa-grid">
                                <label class="pa-field"><span class="pa-field__label">First name <em>*</em></span><input type="text" name="first_name" class="form-control" required maxlength="100"></label>
                                <label class="pa-field"><span class="pa-field__label">Middle name</span><input type="text" name="middle_name" class="form-control" maxlength="100"></label>
                                <label class="pa-field"><span class="pa-field__label">Last name <em>*</em></span><input type="text" name="last_name" class="form-control" required maxlength="100"></label>
                                <label class="pa-field"><span class="pa-field__label">Birthdate <em>*</em></span><input type="date" name="date_of_birth" class="form-control" required max="<?php echo $today; ?>"></label>
                                <label class="pa-field"><span class="pa-field__label">Gender</span>
                                    <select name="gender" class="form-select">
                                        <option value="">Unspecified</option>
                                        <option value="Male">Male</option>
                                        <option value="Female">Female</option>
                                        <option value="Other">Other</option>
                                    </select>
                                </label>
                                <label class="pa-field"><span class="pa-field__label">Occupation</span><input type="text" name="occupation" class="form-control" maxlength="150" placeholder="e.g. Teacher"></label>
                            </div>
                        </div>
                        <div class="pa-section">
                            <h6 class="pa-section__title"><i class="bi bi-telephone"></i> Contact</h6>
                            <div class="pa-grid">
                                <label class="pa-field"><span class="pa-field__label">Email <em>*</em></span><input type="email" name="email" class="form-control" required maxlength="150"></label>
                                <label class="pa-field"><span class="pa-field__label">Phone</span><input type="tel" name="phone_number" class="form-control" maxlength="30"></label>
                                <label class="pa-field pa-field--wide"><span class="pa-field__label">Address</span><input type="text" name="address" class="form-control" maxlength="500"></label>
                            </div>
                        </div>
                        <div class="pa-alert" id="paEditAlert" role="alert" hidden></div>
                        <div class="pa-actions">
                            <button type="submit" class="btn btn-primary" id="paEditSubmit"><i class="bi bi-check2"></i> Save changes</button>
                        </div>
                    </form>
                </section>

                <section class="pa-pane" data-pa-pane="account" role="tabpanel">
                    <dl class="pa-facts">
                        <div><dt>Login email</dt><dd id="paFactEmail">—</dd></div>
                        <div><dt>Registered</dt><dd id="paFactCreated">—</dd></div>
                        <div><dt>Last login</dt><dd id="paFactLogin">—</dd></div>
                    </dl>
                    <div class="pa-account">
                        <form id="paPasswordForm" class="pa-card" novalidate autocomplete="off" data-no-ajax>
                            <h6 class="pa-card__title"><i class="bi bi-key"></i> Set a new password</h6>
                            <p class="pa-card__text">At least 8 characters with an uppercase letter, a lowercase letter, a number and a symbol.</p>
                            <div class="pa-field"><label class="pa-field__label" for="paNewPassword">New password</label>
                                <div class="password-toggle-wrap password-toggle-wrap--bootstrap">
                                    <input type="password" id="paNewPassword" class="form-control password-toggle-input--bootstrap" required minlength="8" autocomplete="new-password">
                                    <button type="button" class="password-toggle-btn password-toggle-btn--auth" data-password-toggle-btn="1" data-password-target="paNewPassword" aria-label="Show password" aria-pressed="false"><svg class="password-toggle-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M2.036 12.322a1.012 1.012 0 010-.639C3.423 7.51 7.36 4.5 12 4.5c4.638 0 8.573 3.007 9.963 7.178.07.207.07.431 0 .639C20.577 16.49 16.64 19.5 12 19.5c-4.638 0-8.573-3.007-9.963-7.178z"/><path stroke-linecap="round" stroke-linejoin="round" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/></svg></button>
                                </div>
                            </div>
                            <div class="pa-field"><label class="pa-field__label" for="paConfirmPassword">Confirm password</label>
                                <div class="password-toggle-wrap password-toggle-wrap--bootstrap">
                                    <input type="password" id="paConfirmPassword" class="form-control password-toggle-input--bootstrap" required minlength="8" autocomplete="new-password">
                                    <button type="button" class="password-toggle-btn password-toggle-btn--auth" data-password-toggle-btn="1" data-password-target="paConfirmPassword" aria-label="Show password" aria-pressed="false"><svg class="password-toggle-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M2.036 12.322a1.012 1.012 0 010-.639C3.423 7.51 7.36 4.5 12 4.5c4.638 0 8.573 3.007 9.963 7.178.07.207.07.431 0 .639C20.577 16.49 16.64 19.5 12 19.5c-4.638 0-8.573-3.007-9.963-7.178z"/><path stroke-linecap="round" stroke-linejoin="round" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/></svg></button>
                                </div>
                            </div>
                            <div class="pa-alert" id="paPasswordAlert" role="alert" hidden></div>
                            <button type="submit" class="btn btn-primary" id="paPasswordSubmit">Update password</button>
                        </form>
                        <div class="pa-card">
                            <h6 class="pa-card__title"><i class="bi bi-shuffle"></i> Temporary password</h6>
                            <p class="pa-card__text">Generate a random password to give the patient. Their other sessions are signed out.</p>
                            <div class="pa-temp" id="paTempResult" hidden>
                                <input type="text" class="form-control font-monospace" id="paTempValue" readonly aria-label="Temporary password">
                                <button type="button" class="btn btn-outline-primary" id="paTempCopy"><i class="bi bi-clipboard"></i> Copy</button>
                            </div>
                            <div class="pa-alert" id="paTempAlert" role="alert" hidden></div>
                            <button type="button" class="btn btn-outline-primary" id="paTempGenerate"><i class="bi bi-key"></i> Generate</button>
                        </div>
                    </div>
                </section>
            </div>
        </div>
    </div>
</div>
<?php endif; ?>

<script>
(function () {
    var config = <?php echo json_encode($patientsConfig, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
    function boot() {
        if (window.DoctorPatients) window.DoctorPatients.init(config);
    }
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', boot);
    } else {
        boot();
    }
})();
</script>
