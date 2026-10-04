<?php
include dirname(dirname(__DIR__)) . '/config/database.php';
include_once dirname(dirname(__DIR__)) . '/config/odontogram_helpers.php';
include_once dirname(dirname(__DIR__)) . '/config/booking_helpers.php';
appointments_ensure_reference_column($conn);

$patient_id = (int)($_SESSION['patient_id'] ?? 0);
$records = [];

if ($patient_id > 0) {
    $sql = "SELECT
                a.appointment_id,
                a.reference_code,
                a.appointment_date,
                a.reason,
                a.status AS appointment_status,
                u.first_name AS doctor_first_name,
                u.last_name AS doctor_last_name,
                cr.record_id,
                cr.follow_up_date,
                cr.status AS record_status,
                cr.submitted_at,
                (SELECT GROUP_CONCAT(DISTINCT sv.service_name ORDER BY sv.service_name SEPARATOR ', ')
                   FROM selected_services ss JOIN services sv ON sv.service_id = ss.service_id
                  WHERE ss.appointment_id = a.appointment_id) AS services
            FROM appointments a
            LEFT JOIN users u ON u.user_id = a.user_id
            INNER JOIN clinical_records cr ON cr.appointment_id = a.appointment_id
            WHERE a.patient_id = ?
            ORDER BY a.appointment_date DESC, a.appointment_id DESC
            LIMIT 100";
    $stmt = $conn->prepare($sql);
    if ($stmt) {
        $stmt->bind_param('i', $patient_id);
        $stmt->execute();
        $res = $stmt->get_result();
        while ($row = $res->fetch_assoc()) {
            $doctor = trim((string)($row['doctor_first_name'] ?? '') . ' ' . (string)($row['doctor_last_name'] ?? ''));
            $records[] = [
                'appointment_id' => (int)$row['appointment_id'],
                'record_id' => (int)$row['record_id'],
                'reference' => (string)($row['reference_code'] ?? ''),
                'date' => (string)($row['appointment_date'] ?? ''),
                'reason' => (string)($row['reason'] ?? ''),
                'services' => (string)($row['services'] ?? ''),
                'doctor' => $doctor !== '' ? $doctor : 'To be assigned',
                'status' => strtolower((string)($row['record_status'] ?: $row['appointment_status'])),
                'follow_up' => (string)($row['follow_up_date'] ?? ''),
            ];
        }
        $stmt->close();
    }
}

$clinicRecordsConfig = [
    'records' => $records,
    'odontogramSrc' => odontogram_embed_src(),
    'odontogramHeight' => (int)odontogram_embed_height_px(),
    'clinicName' => "Azucena’s Dental Clinic",
    'clinicAddress' => '2/F Metz Arcade Bldg., Capt. Vicente Roa cor. Agudo St., Cagayan de Oro City',
];
?>

<!-- Visit list. Selecting a visit replaces it with the full record (no modal), the same
     way the staff Patients page opens a patient's chart. -->
<div id="crListView">
<div class="card border-0 dp-page">
    <div class="card-header dp-page__header">
        <div>
            <h2 class="card-title mb-0">Clinic Records</h2>
            <p class="dp-page__sub mb-0">Your completed visits. Select one to see the full record.</p>
        </div>
        <span class="cr-count"><?php echo count($records); ?> visit<?php echo count($records) === 1 ? '' : 's'; ?></span>
    </div>
    <?php if ($records): ?>
    <div class="card-body dp-toolbar">
        <div class="unified-table-search-wrap module-search-inline dp-toolbar__search">
            <i class="bi bi-search unified-table-search-icon" aria-hidden="true"></i>
            <input type="search" id="crSearch" class="form-control unified-table-search-input" placeholder="Search by date, service, doctor, or reference no..." aria-label="Search clinic records" autocomplete="off">
        </div>
    </div>
    <?php endif; ?>
    <div class="card-body pt-0">
        <div class="dp-list" id="crList" aria-live="polite"></div>
        <div class="dp-empty" id="crEmpty" hidden></div>
    </div>
</div>
</div>

<!-- One visit's record. -->
<div class="card border-0 dp-page dp-chart" id="crRecordView" aria-labelledby="crRecordTitle" hidden>
    <div class="dp-chart__header">
        <div class="dp-chart__identity">
            <span class="cr-date-badge" id="crRecordDate" aria-hidden="true"></span>
            <div class="dp-chart__who">
                <h3 class="dp-chart__name" id="crRecordTitle">Clinic Record</h3>
                <div class="dp-chart__meta" id="crRecordMeta"></div>
                <div class="dp-chart__flags" id="crRecordChips"></div>
            </div>
        </div>
        <div class="cr-actions">
            <button type="button" class="dp-back cr-print" id="crPrint" title="Print this record">
                <span class="dp-back__icon" aria-hidden="true"><i class="bi bi-printer"></i></span>
                <span class="dp-back__text">Print</span>
            </button>
            <button type="button" class="dp-back" data-cr-back aria-label="Back to Clinic Records" title="Back to Clinic Records (Esc)">
                <span class="dp-back__icon" aria-hidden="true"><i class="bi bi-arrow-left"></i></span>
                <span class="dp-back__text">Back</span>
            </button>
        </div>
    </div>
    <div class="dp-chart__tabs" role="tablist" aria-label="Record sections">
        <button type="button" class="dp-tab is-active" role="tab" aria-selected="true" data-cr-tab="summary"><i class="bi bi-grid-1x2"></i> Summary</button>
        <button type="button" class="dp-tab" role="tab" aria-selected="false" data-cr-tab="chart"><i class="bi bi-grid-3x3-gap"></i> Dental Chart</button>
        <button type="button" class="dp-tab" role="tab" aria-selected="false" data-cr-tab="photos"><i class="bi bi-images"></i> Photos</button>
        <button type="button" class="dp-tab" role="tab" aria-selected="false" data-cr-tab="health"><i class="bi bi-heart-pulse"></i> Health Background</button>
    </div>
    <div class="dp-chart__body">
        <section class="dp-pane is-active" data-cr-pane="summary" role="tabpanel"></section>
        <!-- The chart iframe loads once and is reused for every visit. -->
        <section class="dp-pane" data-cr-pane="chart" role="tabpanel">
            <div class="dp-charting__note" id="crChartNote"></div>
            <div class="dp-charting__frame" style="height:<?php echo (int)odontogram_embed_height_px(); ?>px;">
                <iframe id="crChartFrame" data-src="<?php echo htmlspecialchars(odontogram_embed_src(), ENT_QUOTES, 'UTF-8'); ?>" title="Dental chart (read-only)"></iframe>
            </div>
            <div id="crToothNotes"></div>
        </section>
        <section class="dp-pane" data-cr-pane="photos" role="tabpanel"><div class="dp-card"><div id="crPhotos" class="row g-2"></div></div></section>
        <section class="dp-pane" data-cr-pane="health" role="tabpanel"><div class="dp-card" id="crHealth"></div></section>
    </div>
</div>

<style>
.cr-count { display: inline-flex; align-items: center; padding: 5px 12px; border-radius: 999px; background: #eff8fb; color: #17698c; font-size: 0.8rem; font-weight: 700; white-space: nowrap; }
.cr-row { grid-template-columns: 64px minmax(0, 1fr) 190px 120px 18px; }
.cr-row__date, .cr-date-badge {
    display: flex; flex-direction: column; align-items: center; justify-content: center; border-radius: 12px;
    background: #eff8fb; color: #17698c; line-height: 1.1; text-align: center;
}
.cr-row__date { width: 56px; height: 56px; }
.cr-row__date strong, .cr-date-badge strong { font-size: 1.15rem; }
.cr-row__date small, .cr-date-badge small { font-size: 0.68rem; font-weight: 700; text-transform: uppercase; letter-spacing: .04em; }
.cr-date-badge { flex: 0 0 64px; height: 64px; background: #1b7aa3; color: #fff; }
/* #main small is forced grey site-wide (!important); the badge sits on teal, so keep it white. */
#main #crRecordView .cr-date-badge strong, #main #crRecordView .cr-date-badge small { color: #fff !important; }
#main #crRecordView .cr-date-badge small { opacity: .85; }
.cr-actions { display: flex; gap: 8px; margin-left: auto; flex: 0 0 auto; }
.cr-summary { display: grid; grid-template-columns: minmax(0, 1.4fr) minmax(0, 1fr); gap: 14px; align-items: start; }
.cr-summary__side { display: flex; flex-direction: column; gap: 14px; min-width: 0; }
.cr-summary .dp-card { min-width: 0; }
.cr-field + .cr-field { margin-top: 10px; padding-top: 10px; border-top: 1px dashed #e5eaf0; }
.cr-field__label { font-size: 0.72rem; font-weight: 700; text-transform: uppercase; letter-spacing: .04em; color: #94a3b8; margin-bottom: 2px; }
.cr-field__value { color: #0f172a; white-space: pre-line; overflow-wrap: anywhere; font-size: 0.92rem; }
.cr-field__value.is-empty { color: #94a3b8; font-style: italic; }
.cr-services { list-style: none; margin: 0; padding: 0; }
.cr-services li { display: flex; justify-content: space-between; gap: 12px; padding: 8px 0; border-bottom: 1px dashed #e5eaf0; font-size: 0.9rem; }
.cr-services li:last-child { border-bottom: 0; }
.cr-services__price { font-weight: 700; white-space: nowrap; }
.cr-footnote { font-size: 0.75rem; color: #94a3b8; text-align: right; margin-top: 12px; }
#crPhotos .media-gallery-thumb-img { height: 130px; }
[data-cr-pane="chart"] .dp-charting__frame { margin-bottom: 12px; }
@media (max-width: 991.98px) {
    .cr-summary { grid-template-columns: minmax(0, 1fr); }
    .cr-row { grid-template-columns: 56px minmax(0, 1fr) 18px; }
    .cr-row .dp-row__stat { grid-column: 2 / 3; flex-direction: row; flex-wrap: wrap; gap: 0 6px; align-items: baseline; }
    .cr-row .dp-row__chev { grid-row: 1; grid-column: 3; }
}
@media (max-width: 575.98px) {
    .cr-actions .dp-back { padding: 4px; }
    .cr-actions .dp-back__text { display: none; }
    .cr-date-badge { flex-basis: 52px; height: 52px; }
}
</style>

<script>
(function () {
    var config = <?php echo json_encode($clinicRecordsConfig, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
    function boot() { if (window.PatientClinicRecords) window.PatientClinicRecords.init(config); }
    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', boot); else boot();
})();
</script>
