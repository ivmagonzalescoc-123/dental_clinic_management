<?php
include dirname(dirname(__DIR__)) . '/config/database.php';
include_once dirname(dirname(__DIR__)) . '/config/session_check.php';
include_once dirname(dirname(__DIR__)) . '/config/odontogram_helpers.php';
check_session(['doctor']);

$doctor_id = (int)($_SESSION['user_id'] ?? 0);
$today = date('Y-m-d');

// Every appointment this doctor has with each patient. The page renders the list and the
// Patient Chart from this in the browser, so switching patients never reloads the page.
$sql = "
    SELECT
        p.patient_id, p.first_name, p.last_name, p.middle_name, p.phone_number, p.email,
        p.date_of_birth, p.gender, p.profile_image, p.google_picture,
        a.appointment_id, a.appointment_date, a.status, a.is_online_appointment, a.reason, a.notes,
        (SELECT DATE_FORMAT(s.scheduled_start, '%Y-%m-%d %H:%i') FROM patient_schedules s WHERE s.appointment_id = a.appointment_id ORDER BY s.scheduled_start DESC LIMIT 1) AS start_time,
        (SELECT DATE_FORMAT(s.scheduled_end, '%Y-%m-%d %H:%i') FROM patient_schedules s WHERE s.appointment_id = a.appointment_id ORDER BY s.scheduled_start DESC LIMIT 1) AS end_time,
        (SELECT cr.record_id FROM clinical_records cr WHERE cr.appointment_id = a.appointment_id ORDER BY cr.record_id DESC LIMIT 1) AS record_id,
        (SELECT cr.status FROM clinical_records cr WHERE cr.appointment_id = a.appointment_id ORDER BY cr.record_id DESC LIMIT 1) AS record_status,
        s.service_name
    FROM patients p
    INNER JOIN appointments a ON a.patient_id = p.patient_id AND a.user_id = ?
    LEFT JOIN selected_services ss ON ss.appointment_id = a.appointment_id
    LEFT JOIN services s ON s.service_id = ss.service_id
    ORDER BY a.appointment_date DESC, a.appointment_id DESC
";

$patients = [];
$stmt = $conn->prepare($sql);
if ($stmt) {
    $stmt->bind_param('i', $doctor_id);
    $stmt->execute();
    $result = $stmt->get_result();
    while ($row = $result->fetch_assoc()) {
        $pid = (int)$row['patient_id'];
        if (!isset($patients[$pid])) {
            $photo = trim((string)($row['profile_image'] ?? ''));
            if ($photo === '') {
                $photo = trim((string)($row['google_picture'] ?? ''));
            }
            $age = null;
            if (!empty($row['date_of_birth']) && strtotime((string)$row['date_of_birth'])) {
                $age = (int)(new DateTime((string)$row['date_of_birth']))->diff(new DateTime($today))->y;
            }
            $patients[$pid] = [
                'patient_id' => $pid,
                'first_name' => (string)$row['first_name'],
                'middle_name' => (string)($row['middle_name'] ?? ''),
                'last_name' => (string)$row['last_name'],
                'email' => (string)($row['email'] ?? ''),
                'phone_number' => (string)($row['phone_number'] ?? ''),
                'gender' => (string)($row['gender'] ?? ''),
                'date_of_birth' => $row['date_of_birth'],
                'age' => $age,
                'photo' => $photo,
                'alerts' => [],
                'notices' => [],
                'appointments' => [],
            ];
        }
        $aid = (int)$row['appointment_id'];
        if (!isset($patients[$pid]['appointments'][$aid])) {
            $patients[$pid]['appointments'][$aid] = [
                'appointment_id' => $aid,
                'date' => (string)$row['appointment_date'],
                'status' => (string)$row['status'],
                'is_online' => (int)$row['is_online_appointment'] === 1,
                'reason' => (string)($row['reason'] ?? ''),
                'notes' => (string)($row['notes'] ?? ''),
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
    $stmt->close();
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
    $pstmt = $conn->prepare("SELECT * FROM patient_profiling WHERE record_id IS NULL AND patient_id IN ($placeholders) ORDER BY profiling_id DESC");
    if ($pstmt) {
        $pstmt->bind_param(str_repeat('i', count($ids)), ...$ids);
        $pstmt->execute();
        $pres = $pstmt->get_result();
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
        $pstmt->close();
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

$doctorPatientsConfig = [
    'patients' => array_values($patients),
    'services' => $services,
    'toothLabels' => $toothLabels,
    'today' => $today,
    'csrfToken' => (string)($csrf_token ?? ''),
    'query' => trim((string)($_GET['q'] ?? '')),
    'openPatientId' => (int)($_GET['patient_id'] ?? 0),
];
?>

<div id="dpListView">
<div class="card border-0 dp-page">
    <div class="card-header dp-page__header">
        <div>
            <h2 class="card-title mb-0">My Patients</h2>
            <p class="dp-page__sub mb-0">Patients you have seen or are scheduled to see. Select one to open their chart.</p>
        </div>
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

<!-- Patient Chart: replaces the list in place (no modal) so the doctor gets the full page. -->
<div class="card border-0 dp-page dp-chart" id="dpChartView" aria-labelledby="dpChartName" hidden>
    <div class="dp-chart__header">
        <div class="dp-chart__identity" id="dpChartIdentity"></div>
        <button type="button" class="dp-back" data-dp-back aria-label="Back to My Patients" title="Back to My Patients (Esc)">
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
                    <button type="button" id="dpChartingFsClose" class="btn btn-sm btn-danger odontogram-fs-close" hidden><i class="bi bi-x-lg"></i> Close</button>
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
    </div>
</div>

<script>
(function () {
    var config = <?php echo json_encode($doctorPatientsConfig, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
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
