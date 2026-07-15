<?php
include dirname(dirname(__DIR__)) . '/config/database.php';
include_once dirname(dirname(__DIR__)) . '/config/security.php';
include_once dirname(dirname(__DIR__)) . '/config/capabilities.php';
include_once dirname(dirname(__DIR__)) . '/config/system_logs.php';
include_once dirname(dirname(__DIR__)) . '/config/odontogram_helpers.php';
include_once dirname(dirname(__DIR__)) . '/config/billing_helpers.php';
include_once dirname(dirname(__DIR__)) . '/config/workflow_notifications.php';
$csrf_token = security_generate_csrf_token();
?>

<!-- EHR Queue - Secretary Clinical Record Creation -->
<div class="card border-0 mb-3">
    <div class="card-header">
        <h2 class="card-title mb-0">
            <i class="bi bi-journal-medical"></i> EHR Queue - Pending Clinical Record Creation
        </h2>
    </div>
    <div class="card-body">
        <div class="table-responsive">
            <table class="table table-hover align-middle">
                <thead>
                    <tr>
                        <th>Patient</th>
                        <th>Appointment Date</th>
                        <th>Doctor Photos</th>
                        <th>Doctor Notes</th>
                        <th>Status</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php
                    $recordsQueueSql = "
                        SELECT a.appointment_id, a.patient_id, a.appointment_date, a.status,
                               p.first_name, p.last_name,
                               (SELECT COUNT(*) FROM appointment_media WHERE appointment_id = a.appointment_id) AS photo_count,
                               (SELECT COUNT(*) FROM appointment_doctor_notes WHERE appointment_id = a.appointment_id) AS note_count
                        FROM appointments a
                        LEFT JOIN patients p ON a.patient_id = p.patient_id
                        WHERE a.status = 'awaiting_secretary_records'
                        ORDER BY a.completed_by_doctor_at ASC
                    ";
                    $recordsQueueRes = $conn->query($recordsQueueSql);
                    $hasRecordsQueue = false;
                    
                    if ($recordsQueueRes) {
                        while ($rec = $recordsQueueRes->fetch_assoc()) {
                            $hasRecordsQueue = true;
                            $photoCount = (int)($rec['photo_count'] ?? 0);
                            $noteCount = (int)($rec['note_count'] ?? 0);
                            $patientName = trim(($rec['first_name'] ?? '') . ' ' . ($rec['last_name'] ?? ''));
                    ?>
                    <tr>
                        <td><strong><?php echo htmlspecialchars($patientName); ?></strong></td>
                        <td><?php echo htmlspecialchars($rec['appointment_date']); ?></td>
                        <td>
                            <span class="badge bg-info"><?php echo $photoCount; ?> photo<?php echo $photoCount !== 1 ? 's' : ''; ?></span>
                        </td>
                        <td>
                            <span class="badge bg-secondary"><?php echo $noteCount; ?> note<?php echo $noteCount !== 1 ? 's' : ''; ?></span>
                        </td>
                        <td>
                            <span class="badge bg-warning text-dark">Pending Records</span>
                        </td>
                        <td>
                            <button type="button" class="btn btn-sm btn-primary" onclick="openSecretaryRecordModal(<?php echo $rec['appointment_id']; ?>, <?php echo $rec['patient_id']; ?>)">
                                <i class="bi bi-pencil-square"></i> Create Record
                            </button>
                        </td>
                    </tr>
                    <?php
                        }
                    }
                    
                    if (!$hasRecordsQueue) {
                        echo '<tr><td colspan="6" class="text-center text-muted py-4">No appointments awaiting clinical record creation.</td></tr>';
                    }
                    ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- Secretary Record Creation Modal -->
<div class="modal fade" id="secretaryRecordModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-xl modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header bg-success text-white py-2 px-3">
                <div class="d-flex align-items-center gap-3 me-auto pe-3">
                    <div id="recordPatientAvatar" style="width:40px;height:40px;border-radius:50%;background:rgba(255,255,255,0.2);display:flex;align-items:center;justify-content:center;flex-shrink:0;overflow:hidden;">
                        <i class="bi bi-person-fill" style="font-size:1.2rem;opacity:0.7;"></i>
                    </div>
                    <div>
                        <h6 class="modal-title mb-0" id="secretaryRecordPatientName">Create Clinical Record</h6>
                        <small id="secretaryRecordPatientDetails" class="text-white-50" style="font-size:0.72rem;"></small>
                    </div>
                </div>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body p-0">
                <!-- Tab Navigation -->
                <ul class="nav nav-tabs nav-tabs-sm" id="recordTabs" role="tablist" style="padding: 0 16px; background: #f8f9fa;">
                    <li class="nav-item"><a class="nav-link active py-2 px-3" data-bs-toggle="tab" href="#tabNotes" role="tab"><i class="bi bi-journal-text"></i> Session Notes</a></li>
                    <li class="nav-item"><a class="nav-link py-2 px-3" data-bs-toggle="tab" href="#tabClinical" role="tab"><i class="bi bi-clipboard2-pulse"></i> Clinical Findings</a></li>
                    <li class="nav-item"><a class="nav-link py-2 px-3" data-bs-toggle="tab" href="#tabOdontogram" role="tab"><i class="bi bi-tooth"></i> Charting</a></li>
                    <li class="nav-item"><a class="nav-link py-2 px-3" data-bs-toggle="tab" href="#tabBilling" role="tab"><i class="bi bi-receipt"></i> Billing</a></li>
                </ul>

                <div class="tab-content p-3" style="max-height: calc(80vh - 120px); overflow-y: auto;">
                    <!-- TAB 1: Notes & Photos -->
                    <div class="tab-pane fade show active" id="tabNotes" role="tabpanel">
                        <div class="row g-3">
                            <div class="col-md-7">
                                <label class="form-label fw-semibold small">Doctor's Notes</label>
                                <div id="doctorNotesDisplay" class="border rounded p-2 bg-white" style="min-height: 60px; max-height: 120px; overflow-y: auto; white-space: pre-wrap; font-size: 0.85rem;"></div>
                            </div>
                            <div class="col-md-5">
                                <label class="form-label fw-semibold small">Doctor's Photos</label>
                                <div id="doctorPhotoGallery" class="d-flex flex-wrap gap-1"></div>
                            </div>
                        </div>
                        <div class="mt-3">
                            <label class="form-label fw-semibold small">Clinical Summary</label>
                            <textarea id="recordSummary" class="form-control form-control-sm" rows="2" placeholder="Treatment results, findings, recommendations..." style="font-size: 0.85rem;"></textarea>
                        </div>
                        <div class="row g-2 mt-2">
                            <div class="col-md-6">
                                <label class="form-label fw-semibold small">Prescriptions</label>
                                <div id="prescriptionsBody" style="font-size: 0.85rem;"></div>
                                <button type="button" class="btn btn-sm btn-outline-secondary mt-1 py-0" id="addPrescriptionBtn" style="font-size: 0.78rem;"><i class="bi bi-plus"></i> Add</button>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label fw-semibold small">Follow-up</label>
                                <div class="row g-1">
                                    <div class="col-6"><input type="date" class="form-control form-control-sm" id="followUpDate" style="font-size: 0.8rem;"></div>
                                    <div class="col-3"><input type="number" min="1" class="form-control form-control-sm" id="followUpInterval" placeholder="Mo." style="font-size: 0.8rem;"></div>
                                    <div class="col-3"><input type="number" min="1" class="form-control form-control-sm" id="followUpTotalVisits" placeholder="Visits" style="font-size: 0.8rem;"></div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- TAB 2: Clinical Assessment -->
                    <div class="tab-pane fade" id="tabClinical" role="tabpanel">
                        <form id="secretaryProfilingForm">
                            <div class="row g-2 mb-2">
                                <div class="col-md-6"><label class="form-label fw-semibold small">Case History</label><textarea class="form-control form-control-sm visit-clinical-field" name="case_history" rows="2" placeholder="Patient case history" style="font-size:0.85rem;"></textarea></div>
                                <div class="col-md-6"><label class="form-label fw-semibold small">Chief Complaint</label><textarea class="form-control form-control-sm visit-clinical-field" name="chief_complaint" rows="2" placeholder="Primary reason for visit" style="font-size:0.85rem;"></textarea></div>
                            </div>

                            <h6 class="small text-uppercase text-muted mb-1 mt-2" style="font-size:0.72rem;">Clinical Assessment</h6>
                            <div class="row g-2 mb-2">
                                <div class="col-md-4"><label class="form-label small mb-1">Gingiva Consistency</label><div class="d-flex gap-1 flex-wrap"><div class="form-check form-check-sm m-0"><input type="radio" class="form-check-input visit-clinical-field" name="gingiva_consistency" value="Firm" id="gf"><label class="form-check-label small" for="gf">Firm</label></div><div class="form-check form-check-sm m-0"><input type="radio" class="form-check-input visit-clinical-field" name="gingiva_consistency" value="Hyper plastic" id="gh"><label class="form-check-label small" for="gh">Hyper</label></div><div class="form-check form-check-sm m-0"><input type="radio" class="form-check-input visit-clinical-field" name="gingiva_consistency" value="Smooth" id="gs"><label class="form-check-label small" for="gs">Smooth</label></div></div></div>
                                <div class="col-md-4"><label class="form-label small mb-1">Oral Hygiene</label><div class="d-flex gap-1 flex-wrap"><div class="form-check form-check-sm m-0"><input type="radio" class="form-check-input visit-clinical-field" name="oral_hygiene" value="Good" id="og"><label class="form-check-label small" for="og">Good</label></div><div class="form-check form-check-sm m-0"><input type="radio" class="form-check-input visit-clinical-field" name="oral_hygiene" value="Fair" id="of"><label class="form-check-label small" for="of">Fair</label></div><div class="form-check form-check-sm m-0"><input type="radio" class="form-check-input visit-clinical-field" name="oral_hygiene" value="Bad" id="ob"><label class="form-check-label small" for="ob">Bad</label></div></div></div>
                                <div class="col-md-4"><label class="form-label small mb-1">Gingival Color</label><div class="d-flex gap-1 flex-wrap"><div class="form-check form-check-sm m-0"><input type="radio" class="form-check-input visit-clinical-field" name="gingival_color" value="Pink" id="cp"><label class="form-check-label small" for="cp">Pink</label></div><div class="form-check form-check-sm m-0"><input type="radio" class="form-check-input visit-clinical-field" name="gingival_color" value="Bright red" id="cr"><label class="form-check-label small" for="cr">Red</label></div></div></div>
                                <div class="col-md-4"><label class="form-label small mb-1">Lymph Nodes</label><div class="d-flex gap-1 flex-wrap"><div class="form-check form-check-sm m-0"><input type="radio" class="form-check-input visit-clinical-field" name="lymph_nodes" value="Not palpable" id="ln"><label class="form-check-label small" for="ln">Not palpable</label></div><div class="form-check form-check-sm m-0"><input type="radio" class="form-check-input visit-clinical-field" name="lymph_nodes" value="Palpable" id="lp"><label class="form-check-label small" for="lp">Palpable</label></div></div></div>
                                <div class="col-md-4"><label class="form-label small mb-1">Tongue</label><div class="d-flex gap-1 flex-wrap"><div class="form-check form-check-sm m-0"><input type="radio" class="form-check-input visit-clinical-field" name="tongue" value="Normal" id="tn"><label class="form-check-label small" for="tn">Normal</label></div><div class="form-check form-check-sm m-0"><input type="radio" class="form-check-input visit-clinical-field" name="tongue" value="Coated" id="tc"><label class="form-check-label small" for="tc">Coated</label></div></div></div>
                            </div>

                            <h6 class="small text-uppercase text-muted mb-1 mt-2" style="font-size:0.72rem;">Additional Clinical Findings</h6>
                            <div class="row g-2">
                                <div class="col-md-4"><label class="form-label small mb-1">Occlusion</label><input type="text" class="form-control form-control-sm visit-clinical-field" name="occlusion" placeholder="e.g. Angle Class I" style="font-size:0.82rem;"></div>
                                <div class="col-md-4"><label class="form-label small mb-1">Cl. I Type</label><input type="text" class="form-control form-control-sm visit-clinical-field" name="cl_i_type" placeholder="Normal" style="font-size:0.82rem;"></div>
                                <div class="col-md-4"><label class="form-label small mb-1">Cl.II Division</label><input type="text" class="form-control form-control-sm visit-clinical-field" name="cl_ii_division" style="font-size:0.82rem;"></div>
                                <div class="col-md-3"><label class="form-label small mb-1">Cl.II SubDiv</label><input type="text" class="form-control form-control-sm visit-clinical-field" name="cl_ii_subdivision" style="font-size:0.82rem;"></div>
                                <div class="col-md-3"><label class="form-label small mb-1">Cl.II Type</label><input type="text" class="form-control form-control-sm visit-clinical-field" name="cl_ii_type" style="font-size:0.82rem;"></div>
                                <div class="col-md-3"><label class="form-label small mb-1">Cl.III Div</label><input type="text" class="form-control form-control-sm visit-clinical-field" name="cl_iii_division" style="font-size:0.82rem;"></div>
                                <div class="col-md-3"><label class="form-label small mb-1">Cl.III SubDiv</label><input type="text" class="form-control form-control-sm visit-clinical-field" name="cl_iii_subdivision" style="font-size:0.82rem;"></div>
                                <div class="col-md-6"><label class="form-label small mb-1">Operation if any</label><input type="text" class="form-control form-control-sm visit-clinical-field" name="operation_if_any" style="font-size:0.82rem;"></div>
                                <div class="col-md-3"><label class="form-label small mb-1">Blood Sugar</label><input type="text" class="form-control form-control-sm visit-clinical-field" name="blood_sugar" placeholder="Value" style="font-size:0.82rem;"></div>
                                <div class="col-md-3"><label class="form-label small mb-1">BS Status</label><div class="d-flex gap-2 flex-wrap"><div class="form-check form-check-sm m-0"><input type="checkbox" class="form-check-input visit-clinical-field" name="blood_sugar_normal" id="bsn"><label class="form-check-label small" for="bsn">Normal</label></div><div class="form-check form-check-sm m-0"><input type="checkbox" class="form-check-input visit-clinical-field" name="blood_sugar_high_low" id="bsh"><label class="form-check-label small" for="bsh">High/Low</label></div></div></div>
                                <div class="col-md-3"><label class="form-label small mb-1">Bleeding Time</label><input type="text" class="form-control form-control-sm visit-clinical-field" name="bleeding_time" style="font-size:0.82rem;"></div>
                                <div class="col-md-3"><label class="form-label small mb-1">Clotting Time</label><input type="text" class="form-control form-control-sm visit-clinical-field" name="clotting_time" style="font-size:0.82rem;"></div>
                                <div class="col-md-4"><label class="form-label small mb-1">B.P.</label><input type="text" class="form-control form-control-sm visit-clinical-field" name="blood_pressure" placeholder="e.g. 120/80" style="font-size:0.82rem;"></div>
                                <div class="col-md-4"><label class="form-label small mb-1">Allergy</label><input type="text" class="form-control form-control-sm visit-clinical-field" name="allergy_notes" placeholder="No" style="font-size:0.82rem;"></div>
                                <div class="col-md-4"><label class="form-label small mb-1">Blood Diseases</label><input type="text" class="form-control form-control-sm visit-clinical-field" name="blood_diseases" style="font-size:0.82rem;"></div>
                                <div class="col-md-6"><label class="form-label small mb-1">Radiographic Interpretation</label><textarea class="form-control form-control-sm visit-clinical-field" name="radiographic_interpretation" rows="2" style="font-size:0.82rem;"></textarea></div>
                                <div class="col-md-3"><label class="form-label small mb-1">Fainting</label><input type="text" class="form-control form-control-sm visit-clinical-field" name="fainting" style="font-size:0.82rem;"></div>
                            </div>
                        </form>
                    </div>

                    <!-- TAB 3: Odontogram -->
                    <div class="tab-pane fade" id="tabOdontogram" role="tabpanel">
                        <div class="d-flex align-items-center mb-2 gap-2">
                            <button type="button" id="openSecretaryOdontogramFullscreenBtn" class="btn btn-sm btn-outline-dark"><i class="bi bi-fullscreen"></i> Full Screen</button>
                            <small class="text-muted">Use 2D chart to set status and comments.</small>
                        </div>
                        <div id="secretaryOdontogramViewport" class="odontogram-viewport">
                            <button type="button" id="closeSecretaryOdontogramFullscreenBtn" class="btn btn-sm btn-danger odontogram-fs-close" style="display:none;"><i class="bi bi-x-lg"></i></button>
                            <div id="secretaryOdontogramContainer" class="secretary-odontogram-container">
                                <iframe id="odontogramFrame" src="<?php echo htmlspecialchars(odontogram_embed_src(), ENT_QUOTES, 'UTF-8'); ?>"></iframe>
                            </div>
                        </div>
                    </div>

                    <!-- TAB 4: Billing -->
                    <div class="tab-pane fade" id="tabBilling" role="tabpanel">
                        <div class="row g-3">
                            <div class="col-md-7">
                                <h6 class="small fw-bold mb-2">Services</h6>
                                <div class="table-responsive">
                                    <table class="table table-sm align-middle mb-2">
                                        <thead class="table-light"><tr><th>Service</th><th style="width:120px;">Price</th><th style="width:40px;"></th></tr></thead>
                                        <tbody id="billingServicesBody"><tr id="billingServicesEmptyRow"><td colspan="3" class="text-muted small">No services added.</td></tr></tbody>
                                    </table>
                                </div>
                                <div class="d-flex gap-1 mb-3">
                                    <select id="addServiceSelect" class="form-select form-select-sm" style="max-width:220px; font-size:0.82rem;"><option value="">Add service...</option></select>
                                    <button type="button" class="btn btn-sm btn-outline-primary py-0" id="addBillingServiceBtn" style="font-size:0.78rem;"><i class="bi bi-plus"></i></button>
                                </div>

                                <h6 class="small fw-bold mb-2">Additional Charges</h6>
                                <table class="table table-sm align-middle mb-2">
                                    <thead class="table-light"><tr><th>Description</th><th style="width:120px;">Amount</th><th style="width:40px;"></th></tr></thead>
                                    <tbody id="billingChargesBody"></tbody>
                                    <tfoot><tr><td colspan="3"><button type="button" class="btn btn-sm btn-outline-secondary py-0" id="addBillingChargeBtn" style="font-size:0.78rem;"><i class="bi bi-plus"></i> Add Charge</button></td></tr></tfoot>
                                </table>
                            </div>
                            <div class="col-md-5">
                                <h6 class="small fw-bold mb-2">Discount</h6>
                                <div class="mb-2">
                                    <div class="form-check form-check-sm"><input class="form-check-input billing-discount-type" type="radio" name="billingDiscountType" id="discountNone" value="none" checked><label class="form-check-label small" for="discountNone">None</label></div>
                                    <div class="form-check form-check-sm"><input class="form-check-input billing-discount-type" type="radio" name="billingDiscountType" id="discountSenior" value="senior"><label class="form-check-label small" for="discountSenior">Senior (<?php echo (int) billing_senior_discount_percent(); ?>%)</label></div>
                                    <div class="form-check form-check-sm"><input class="form-check-input billing-discount-type" type="radio" name="billingDiscountType" id="discountPwd" value="pwd"><label class="form-check-label small" for="discountPwd">PWD (<?php echo (int) billing_pwd_discount_percent(); ?>%)</label></div>
                                    <div class="form-check form-check-sm"><input class="form-check-input billing-discount-type" type="radio" name="billingDiscountType" id="discountCustom" value="custom"><label class="form-check-label small" for="discountCustom">Custom</label></div>
                                </div>
                                <div id="customDiscountFields" style="display:none;">
                                    <input type="number" step="0.01" min="0" max="100" class="form-control form-control-sm mb-1" id="customDiscountPercent" value="20" placeholder="%" style="font-size:0.82rem;">
                                    <input type="text" class="form-control form-control-sm" id="discountNotes" maxlength="255" placeholder="Reason" style="font-size:0.82rem;">
                                </div>

                                <div class="p-2 bg-light border rounded mt-2 mb-2" id="billingTotalsSummary">
                                    <div class="d-flex justify-content-between small"><span>Subtotal</span><span id="billingSubtotalDisplay">₱0.00</span></div>
                                    <div class="d-flex justify-content-between small text-success billing-discount-summary-row" id="billingDiscountRow" style="display:none;"><span id="billingDiscountLabel">Discount</span><span id="billingDiscountDisplay">-₱0.00</span></div>
                                    <div class="d-flex justify-content-between fw-bold border-top pt-1 mt-1"><span>Total Due</span><span id="billingTotalDisplay">₱0.00</span></div>
                                </div>

                                <div class="form-check mb-2"><input class="form-check-input" type="checkbox" id="useInstallment"><label class="form-check-label small" for="useInstallment">Installment plan (50% down)</label></div>
                                <div id="installmentOptions" style="display:none;"><input type="number" min="1" max="60" class="form-control form-control-sm" id="installmentTerms" value="6" placeholder="Monthly terms" style="max-width:140px; font-size:0.82rem;"></div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            <div class="modal-footer py-2">
                <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Cancel</button>
                <button type="button" class="btn btn-success btn-sm" id="submitSecretaryRecordBtn"><i class="bi bi-check-lg"></i> Create Record</button>
            </div>
        </div>
    </div>
</div>

<style>
#secretaryRecordModal .secretary-record-patient-name {
    font-size: 1.2rem;
    line-height: 1.35;
    letter-spacing: 0.02em;
    text-shadow: 0 1px 2px rgba(0, 0, 0, 0.2);
}

.nav-tabs-sm .nav-link {
    font-size: 0.82rem;
    font-weight: 500;
    color: #64748b;
    border: none;
    border-bottom: 2px solid transparent;
    padding: 8px 14px;
}
.nav-tabs-sm .nav-link.active {
    color: #16a34a;
    border-bottom-color: #16a34a;
    background: transparent;
}
.nav-tabs-sm .nav-link:hover {
    color: #16a34a;
    background: transparent;
}
#secretaryRecordModal .form-label { margin-bottom: 2px; font-size: 0.82rem; }
#secretaryRecordModal .form-check-sm .form-check-input { width: 16px; height: 16px; }
#secretaryRecordModal .form-check-sm .form-check-label { font-size: 0.8rem; }

.odontogram-viewport {
    position: relative;
    width: 100%;
}

.secretary-odontogram-container {
    width: 100%;
    height: <?php echo (int) odontogram_embed_height_px(); ?>px;
}

.secretary-odontogram-container iframe,
#odontogramFrame {
    display: block;
    width: 100% !important;
    height: 100% !important;
    border: 1px solid #dee2e6;
    border-radius: 4px;
}

.odontogram-viewport:fullscreen,
.odontogram-viewport:-webkit-full-screen {
    width: 100vw;
    height: 100vh;
    background: #0b0f19;
    padding: 0;
}

.odontogram-viewport:fullscreen .secretary-odontogram-container,
.odontogram-viewport:-webkit-full-screen .secretary-odontogram-container {
    height: 100% !important;
}

.odontogram-fs-close {
    position: absolute;
    top: 10px;
    right: 10px;
    z-index: 30;
}

@media (max-width: 768px) {
    .secretary-odontogram-container {
        height: 520px;
    }
}
@media (max-width: 480px) {
    .secretary-odontogram-container {
        height: 440px;
    }
}
</style>

<script>
window.currentRecordAppointmentId = null;
window.currentRecordPatientId = null;
window.secretaryOdontogramData = {};
window.SECRETARY_ODONTOGRAM_IFRAME_HEIGHT = <?php echo (int) odontogram_embed_height_px(); ?>;
window.BILLING_SENIOR_DISCOUNT_PERCENT = <?php echo json_encode((float) billing_senior_discount_percent()); ?>;
window.BILLING_PWD_DISCOUNT_PERCENT = <?php echo json_encode((float) billing_pwd_discount_percent()); ?>;

window.addEventListener('message', function(ev) {
    if (ev.data && ev.data.type === 'odontogram_update' && ev.data.toothState) {
        secretaryOdontogramData = ev.data.toothState;
    }
});

document.getElementById('useInstallment')?.addEventListener('change', function() {
    const opts = document.getElementById('installmentOptions');
    if (opts) opts.style.display = this.checked ? 'block' : 'none';
});

function addPrescriptionRow(rx = {}) {
    const body = document.getElementById('prescriptionsBody');
    if (!body) return;
    const wrap = document.createElement('div');
    wrap.className = 'row g-2 mb-2 prescription-row';
    wrap.innerHTML = `
        <div class="col-md-3"><input type="text" class="form-control form-control-sm rx-med" value="${rx.medicine_name || ''}" placeholder="Medicine"></div>
        <div class="col-md-2"><input type="text" class="form-control form-control-sm rx-dosage" value="${rx.dosage || ''}" placeholder="Dosage"></div>
        <div class="col-md-2"><input type="text" class="form-control form-control-sm rx-brand" value="${rx.medicine_brand || ''}" placeholder="Brand"></div>
        <div class="col-md-2"><input type="text" class="form-control form-control-sm rx-duration" value="${rx.duration || ''}" placeholder="Duration"></div>
        <div class="col-md-2"><input type="text" class="form-control form-control-sm rx-notes" value="${rx.notes || ''}" placeholder="Notes"></div>
        <div class="col-md-1"><button type="button" class="btn btn-sm btn-outline-danger" onclick="this.closest('.prescription-row').remove()"><i class="bi bi-trash"></i></button></div>
    `;
    body.appendChild(wrap);
}

document.getElementById('addPrescriptionBtn')?.addEventListener('click', () => addPrescriptionRow());

function postOdontogramToSecretaryFrame(data) {
    const frame = document.getElementById('odontogramFrame');
    if (!frame || !frame.contentWindow) return;
    frame.contentWindow.postMessage({ type: 'load_odontogram', toothState: data || {}, readonly: false }, '*');
}

function getSecretaryOdontogramFullscreenElement() {
    return document.fullscreenElement || document.webkitFullscreenElement || document.msFullscreenElement || null;
}

function isSecretaryOdontogramFullscreen() {
    const viewport = document.getElementById('secretaryOdontogramViewport');
    return !!viewport && getSecretaryOdontogramFullscreenElement() === viewport;
}

function requestSecretaryOdontogramFullscreen() {
    const viewport = document.getElementById('secretaryOdontogramViewport');
    if (!viewport) return;
    const requestFn = viewport.requestFullscreen || viewport.webkitRequestFullscreen || viewport.msRequestFullscreen;
    if (requestFn) requestFn.call(viewport);
}

function exitSecretaryOdontogramFullscreen() {
    const exitFn = document.exitFullscreen || document.webkitExitFullscreen || document.msExitFullscreen;
    if (exitFn) exitFn.call(document);
}

function notifySecretaryOdontogramResize() {
    const frame = document.getElementById('odontogramFrame');
    try {
        if (frame?.contentWindow) {
            frame.contentWindow.postMessage({ type: 'odontogram_resize' }, '*');
        }
    } catch (e) {}
}

function syncSecretaryOdontogramFullscreenUI() {
    const closeBtn = document.getElementById('closeSecretaryOdontogramFullscreenBtn');
    const openBtn = document.getElementById('openSecretaryOdontogramFullscreenBtn');
    const container = document.getElementById('secretaryOdontogramContainer');
    const active = isSecretaryOdontogramFullscreen();

    if (closeBtn) closeBtn.style.display = active ? '' : 'none';
    if (openBtn) openBtn.textContent = active ? 'Exit Full Screen' : 'Full Screen';
    if (container) {
        container.style.height = active ? '100%' : (SECRETARY_ODONTOGRAM_IFRAME_HEIGHT + 'px');
    }
    setTimeout(notifySecretaryOdontogramResize, 50);
}

document.getElementById('openSecretaryOdontogramFullscreenBtn')?.addEventListener('click', function () {
    if (isSecretaryOdontogramFullscreen()) {
        exitSecretaryOdontogramFullscreen();
        return;
    }
    requestSecretaryOdontogramFullscreen();
});

document.getElementById('closeSecretaryOdontogramFullscreenBtn')?.addEventListener('click', function () {
    exitSecretaryOdontogramFullscreen();
});

document.addEventListener('fullscreenchange', syncSecretaryOdontogramFullscreenUI);
document.addEventListener('webkitfullscreenchange', syncSecretaryOdontogramFullscreenUI);
document.addEventListener('keydown', function (e) {
    if (e.key === 'Escape' && isSecretaryOdontogramFullscreen()) {
        exitSecretaryOdontogramFullscreen();
    }
});

document.getElementById('secretaryRecordModal')?.addEventListener('hidden.bs.modal', function () {
    if (isSecretaryOdontogramFullscreen()) {
        exitSecretaryOdontogramFullscreen();
    }
});

syncSecretaryOdontogramFullscreenUI();

function getAppRoot() {
    const parts = window.location.pathname.split('/').filter(Boolean);
    return parts.length ? ('/' + parts[0]) : '';
}

function resolveMediaUrl(filePath) {
    const path = String(filePath || '').replace(/^\/+/, '');
    if (!path) return '';
    if (/^https?:\/\//i.test(path)) return path;
    const base = window.MEDIA_BASE_URL || '';
    return base + '/' + path;
}

window.recordBillingCatalog = [];
window.recordBillingServiceIds = new Set();

async function openSecretaryRecordModal(appointmentId, patientId) {
    currentRecordAppointmentId = appointmentId;
    currentRecordPatientId = patientId;

    const patientNameEl = document.getElementById('secretaryRecordPatientName');
    const patientDetailsEl = document.getElementById('secretaryRecordPatientDetails');
    const patientAvatarEl = document.getElementById('recordPatientAvatar');
    if (patientNameEl) {
        patientNameEl.textContent = 'Loading patient...';
    }
    if (patientDetailsEl) patientDetailsEl.textContent = '';
    if (patientAvatarEl) patientAvatarEl.innerHTML = '<i class="bi bi-person-fill" style="font-size:1.2rem;opacity:0.7;"></i>';
    
    try {
        // Fetch patient details
        const patientRes = await fetch('../assets/api/patients/get_profiling.php?patient_id=' + patientId);
        const patientData = await patientRes.json().catch(() => null);

        // Fetch doctor media and notes
        const res = await fetch('../assets/api/appointments/get_doctor_media.php?appointment_id=' + appointmentId);
        const data = await res.json();
        
        if (!data.success) {
            if (patientNameEl) patientNameEl.textContent = '';
            if (patientDetailsEl) patientDetailsEl.textContent = '';
            notifySecretary('Unable to load appointment data. Please try again.', 'danger');
            return;
        }

        // Populate patient header
        if (patientNameEl) {
            const patientName = String(data.appointment?.patient_name || '').trim();
            patientNameEl.textContent = patientName || 'Create Clinical Record';
        }

        if (patientData && patientData.success && patientData.patient) {
            const p = patientData.patient;
            const details = [p.email, p.phone_number, p.address].filter(Boolean);
            if (patientDetailsEl) {
                patientDetailsEl.textContent = details.join(' | ');
            }
            if (patientAvatarEl && p.profile_image) {
                const imgUrl = /^https?:\/\//i.test(p.profile_image) ? p.profile_image : '../' + p.profile_image.replace(/^\/+/, '');
                patientAvatarEl.innerHTML = '<img src="' + escapeHtml(imgUrl) + '" alt="" style="width:100%;height:100%;object-fit:cover;">';
            }
        }
        
        // Display photos
        const photoContainer = document.getElementById('doctorPhotoGallery');
        if (typeof renderMediaGallery === 'function') {
            renderMediaGallery(photoContainer, data.photos || [], function (photo) {
                return resolveMediaUrl(photo.file_path);
            });
        } else if (photoContainer) {
            photoContainer.innerHTML = '<p class="text-muted">No photos uploaded</p>';
        }
        
        // Display notes
        const notesDisplay = document.getElementById('doctorNotesDisplay');
        if (data.notes && data.notes.length > 0) {
            notesDisplay.textContent = data.notes.map(n => n.note_text).join('\n---\n');
        } else {
            notesDisplay.textContent = 'No clinical notes provided by doctor';
        }
        
        secretaryOdontogramData = data.odontogram || {};
        const odontoFrame = document.getElementById('odontogramFrame');
        if (odontoFrame) {
            odontoFrame.onload = function() { postOdontogramToSecretaryFrame(secretaryOdontogramData); };
            postOdontogramToSecretaryFrame(secretaryOdontogramData);
        }

        document.getElementById('recordSummary').value = data.summary || '';
        document.getElementById('followUpDate').value = data.follow_up_date || '';
        const tp = data.treatment_plan || {};
        document.getElementById('followUpInterval').value = tp.follow_up_interval_months || '';
        document.getElementById('followUpTotalVisits').value = tp.follow_up_total_visits || '';

        const rxBody = document.getElementById('prescriptionsBody');
        rxBody.innerHTML = '';
        if (data.prescriptions && data.prescriptions.length) {
            data.prescriptions.forEach(rx => addPrescriptionRow(rx));
        }

        recordBillingCatalog = data.available_services || [];
        populateAddServiceSelect();

        const servicesBody = document.getElementById('billingServicesBody');
        servicesBody.innerHTML = '';
        recordBillingServiceIds.clear();
        const packageServices = (data.selected_services && data.selected_services.length)
            ? data.selected_services
            : (data.billing_items || []).filter(item => (item.service_id || item.item_type === 'service'));
        packageServices.forEach(item => {
            if (item.service_id) {
                addBillingServiceRow(item.service_id, item.description, item.amount);
            }
        });
        updateBillingServicesEmptyRow();

        const chargesBody = document.getElementById('billingChargesBody');
        chargesBody.innerHTML = '';
        const extraCharges = (data.extra_charges && data.extra_charges.length)
            ? data.extra_charges
            : (data.billing_items || []).filter(item => !item.service_id && item.item_type !== 'service');
        extraCharges.forEach(item => addBillingChargeRow(item.description || '', item.amount || ''));

        document.getElementById('useInstallment').checked = false;
        document.getElementById('installmentOptions').style.display = 'none';
        document.getElementById('installmentTerms').value = '6';
        resetBillingDiscountFields();
        updateBillingTotals();

        // Pre-fill visit clinical exam from this appointment's clinical record
        const visitClinical = data.existing_visit_clinical || data.existing_profiling || {};
        Object.keys(visitClinical).forEach(key => {
            const fields = document.querySelectorAll('#secretaryProfilingForm [name="' + key + '"]');
            if (!fields.length) return;
            const field = fields[0];
            if (field.type === 'radio') {
                fields.forEach(function(radio) {
                    radio.checked = visitClinical[key] === radio.value;
                });
            } else if (field.type === 'checkbox') {
                field.checked = visitClinical[key] === true || visitClinical[key] === 1;
            } else {
                field.value = visitClinical[key] || '';
            }
        });

        if (typeof initToggleableClinicalRadios === 'function') {
            initToggleableClinicalRadios(document.getElementById('secretaryProfilingForm'));
        }
        
        const modal = new bootstrap.Modal(document.getElementById('secretaryRecordModal'));
        modal.show();
    } catch (e) {
        console.error(e);
        notifySecretary('Unable to load appointment. Please try again.', 'danger');
    }
}

window.openSecretaryRecordModal = openSecretaryRecordModal;

function escapeBillingHtml(value) {
    return String(value ?? '')
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;');
}

function formatBillingAmount(amount) {
    const num = parseFloat(amount);
    return Number.isFinite(num) ? num.toFixed(2) : '0.00';
}

function getBillingSubtotal() {
    let subtotal = 0;
    document.querySelectorAll('#billingServicesBody tr[data-service-id]').forEach(tr => {
        subtotal += parseFloat(tr.querySelector('.billing-service-amount')?.value || '0') || 0;
    });
    document.querySelectorAll('#billingChargesBody tr').forEach(tr => {
        subtotal += parseFloat(tr.querySelector('.billing-charge-amt')?.value || '0') || 0;
    });
    return Math.max(0, subtotal);
}

function getSelectedBillingDiscount() {
    const type = document.querySelector('input[name="billingDiscountType"]:checked')?.value || 'none';
    let percent = 0;
    if (type === 'senior') {
        percent = BILLING_SENIOR_DISCOUNT_PERCENT;
    } else if (type === 'pwd') {
        percent = BILLING_PWD_DISCOUNT_PERCENT;
    } else if (type === 'custom') {
        percent = parseFloat(document.getElementById('customDiscountPercent')?.value || '0') || 0;
        percent = Math.max(0, Math.min(100, percent));
    }
    return { type, percent };
}

function updateBillingTotals() {
    const subtotal = getBillingSubtotal();
    const discount = getSelectedBillingDiscount();
    const discountAmount = (discount.type === 'none' || discount.percent <= 0)
        ? 0
        : Math.round(subtotal * (discount.percent / 100) * 100) / 100;
    const total = Math.max(0, Math.round((subtotal - discountAmount) * 100) / 100);

    const subtotalEl = document.getElementById('billingSubtotalDisplay');
    const discountRow = document.getElementById('billingDiscountRow');
    const discountLabel = document.getElementById('billingDiscountLabel');
    const discountDisplay = document.getElementById('billingDiscountDisplay');
    const totalEl = document.getElementById('billingTotalDisplay');
    const customFields = document.getElementById('customDiscountFields');

    if (subtotalEl) subtotalEl.textContent = '₱' + formatBillingAmount(subtotal);
    if (totalEl) totalEl.textContent = '₱' + formatBillingAmount(total);
    if (customFields) customFields.style.display = discount.type === 'custom' ? '' : 'none';

    if (discountRow && discountLabel && discountDisplay) {
        if (discountAmount > 0) {
            let label = 'Discount';
            if (discount.type === 'senior') label = 'Senior Citizen Discount (' + discount.percent + '%)';
            else if (discount.type === 'pwd') label = 'PWD Discount (' + discount.percent + '%)';
            else if (discount.type === 'custom') label = 'Custom Discount (' + discount.percent + '%)';
            discountLabel.textContent = label;
            discountDisplay.textContent = '-₱' + formatBillingAmount(discountAmount);
            discountRow.style.display = '';
        } else {
            discountRow.style.display = 'none';
        }
    }
}

function resetBillingDiscountFields() {
    const none = document.getElementById('discountNone');
    if (none) none.checked = true;
    const customPercent = document.getElementById('customDiscountPercent');
    if (customPercent) customPercent.value = '20';
    const notes = document.getElementById('discountNotes');
    if (notes) notes.value = '';
    updateBillingTotals();
}

document.querySelectorAll('.billing-discount-type').forEach(el => {
    el.addEventListener('change', updateBillingTotals);
});
document.getElementById('customDiscountPercent')?.addEventListener('input', updateBillingTotals);
document.getElementById('billingChargesBody')?.addEventListener('input', function(ev) {
    if (ev.target?.classList?.contains('billing-charge-amt')) {
        updateBillingTotals();
    }
});

document.getElementById('billingChargesBody')?.addEventListener('click', function(ev) {
    const btn = ev.target.closest('.btn-outline-danger');
    if (!btn || !this.contains(btn)) return;
    const row = btn.closest('tr');
    if (row) {
        row.remove();
        updateBillingTotals();
    }
});

function populateAddServiceSelect() {
    const select = document.getElementById('addServiceSelect');
    if (!select) return;
    select.innerHTML = '<option value="">Add service from price list...</option>';
    recordBillingCatalog.forEach(svc => {
        const sid = parseInt(svc.service_id, 10);
        if (!sid || recordBillingServiceIds.has(sid)) return;
        const opt = document.createElement('option');
        opt.value = String(sid);
        opt.textContent = svc.description + ' — ₱' + formatBillingAmount(svc.amount);
        opt.dataset.description = svc.description;
        opt.dataset.amount = svc.amount;
        select.appendChild(opt);
    });
}

function updateBillingServicesEmptyRow() {
    const tbody = document.getElementById('billingServicesBody');
    const emptyRow = document.getElementById('billingServicesEmptyRow');
    if (!tbody) return;
    if (recordBillingServiceIds.size > 0) {
        if (emptyRow) emptyRow.remove();
        return;
    }
    if (!tbody.querySelector('#billingServicesEmptyRow')) {
        const tr = document.createElement('tr');
        tr.id = 'billingServicesEmptyRow';
        tr.innerHTML = '<td colspan="3" class="text-muted small">No package services added.</td>';
        tbody.appendChild(tr);
    }
}

function addBillingServiceRow(serviceId, desc, amount) {
    const sid = parseInt(serviceId, 10);
    if (!sid || recordBillingServiceIds.has(sid)) return;

    const tbody = document.getElementById('billingServicesBody');
    const tr = document.createElement('tr');
    tr.dataset.serviceId = String(sid);
    tr.innerHTML = `
        <td>
            <span class="billing-service-name">${escapeBillingHtml(desc)}</span>
            <span class="badge bg-light text-muted border ms-1">Package</span>
        </td>
        <td>
            <span class="billing-service-price">₱${formatBillingAmount(amount)}</span>
            <input type="hidden" class="billing-service-amount" value="${formatBillingAmount(amount)}">
        </td>
        <td>
            <button type="button" class="btn btn-sm btn-outline-danger billing-remove-service" title="Remove service">
                <i class="bi bi-trash"></i>
            </button>
        </td>
    `;
    tr.querySelector('.billing-remove-service')?.addEventListener('click', () => {
        recordBillingServiceIds.delete(sid);
        tr.remove();
        updateBillingServicesEmptyRow();
        populateAddServiceSelect();
        updateBillingTotals();
    });
    tbody.appendChild(tr);
    recordBillingServiceIds.add(sid);
    updateBillingServicesEmptyRow();
    populateAddServiceSelect();
    updateBillingTotals();
}

function addBillingChargeRow(desc = '', amount = '') {
    const tbody = document.getElementById('billingChargesBody');
    const tr = document.createElement('tr');
    tr.innerHTML = `
        <td><input type="text" class="form-control form-control-sm billing-charge-desc" value="${escapeBillingHtml(desc)}" placeholder="E.g. X-ray fee, lab fee"></td>
        <td><input type="number" step="0.01" min="0" class="form-control form-control-sm billing-charge-amt" value="${amount !== '' ? escapeBillingHtml(amount) : ''}" placeholder="0.00"></td>
        <td>
            <button type="button" class="btn btn-sm btn-outline-danger" onclick="this.closest('tr').remove()">
                <i class="bi bi-trash"></i>
            </button>
        </td>
    `;
    tbody.appendChild(tr);
    updateBillingTotals();
}

document.getElementById('addBillingServiceBtn')?.addEventListener('click', () => {
    const select = document.getElementById('addServiceSelect');
    if (!select || !select.value) {
        notifySecretary('Select a service from the price list first.', 'warning');
        return;
    }
    const opt = select.options[select.selectedIndex];
    addBillingServiceRow(select.value, opt.dataset.description || opt.textContent, opt.dataset.amount || 0);
    select.value = '';
});

document.getElementById('addBillingChargeBtn')?.addEventListener('click', () => {
    addBillingChargeRow();
});

window.secretaryRecordSubmitting = false;
window.secretaryRecordIdempotencyKey = null;

document.getElementById('submitSecretaryRecordBtn')?.addEventListener('click', async function() {
    if (secretaryRecordSubmitting) return;
    secretaryRecordSubmitting = true;

    if (!secretaryRecordIdempotencyKey) {
        secretaryRecordIdempotencyKey = (window.Idempotency && Idempotency.newKey('record')) || ('record-' + Date.now() + '-' + Math.random().toString(36).slice(2));
    }
    
    try {
        // Collect visit clinical exam data for this appointment record
        const visitClinicalData = typeof collectVisitClinicalData === 'function'
            ? collectVisitClinicalData('#secretaryProfilingForm')
            : {};
        if (typeof collectVisitClinicalData !== 'function') {
            document.querySelectorAll('#secretaryProfilingForm .visit-clinical-field').forEach(field => {
                if (field.type === 'checkbox') {
                    visitClinicalData[field.name] = field.checked;
                } else if (field.type === 'radio') {
                    if (field.checked) {
                        visitClinicalData[field.name] = field.value;
                    }
                } else {
                    visitClinicalData[field.name] = field.value || '';
                }
            });
        }
        
        let odontogramData = secretaryOdontogramData || {};
        try {
            const odontoFrame = document.getElementById('odontogramFrame');
            if (odontoFrame && odontoFrame.contentWindow && typeof odontoFrame.contentWindow.getOdontogramData === 'function') {
                odontogramData = odontoFrame.contentWindow.getOdontogramData() || odontogramData;
            }
        } catch (e) {
            console.warn('Could not get odontogram data:', e);
        }

        const billingItems = [];
        document.querySelectorAll('#billingServicesBody tr[data-service-id]').forEach(tr => {
            const serviceId = parseInt(tr.dataset.serviceId, 10);
            const desc = tr.querySelector('.billing-service-name')?.textContent?.trim() || '';
            const amt = parseFloat(tr.querySelector('.billing-service-amount')?.value || '0') || 0;
            if (serviceId > 0) {
                billingItems.push({
                    service_id: serviceId,
                    description: desc,
                    amount: amt,
                    item_type: 'service'
                });
            }
        });
        document.querySelectorAll('#billingChargesBody tr').forEach(tr => {
            const desc = tr.querySelector('.billing-charge-desc')?.value || '';
            const amt = tr.querySelector('.billing-charge-amt')?.value || '';
            if (desc.trim() !== '' || parseFloat(amt) > 0) {
                billingItems.push({
                    description: desc,
                    amount: parseFloat(amt) || 0,
                    item_type: 'extra'
                });
            }
        });

        const prescriptions = [];
        document.querySelectorAll('#prescriptionsBody .prescription-row').forEach(row => {
            const med = row.querySelector('.rx-med')?.value?.trim() || '';
            if (!med) return;
            prescriptions.push({
                medicine_name: med,
                dosage: row.querySelector('.rx-dosage')?.value || '',
                medicine_brand: row.querySelector('.rx-brand')?.value || '',
                duration: row.querySelector('.rx-duration')?.value || '',
                notes: row.querySelector('.rx-notes')?.value || ''
            });
        });

        const fd = new FormData();
        fd.append('appointment_id', currentRecordAppointmentId);
        fd.append('patient_id', currentRecordPatientId);
        fd.append('visit_clinical', JSON.stringify(visitClinicalData));
        fd.append('odontogram', JSON.stringify(odontogramData));
        fd.append('billing_items', JSON.stringify(billingItems));
        const selectedDiscount = getSelectedBillingDiscount();
        fd.append('discount_type', selectedDiscount.type);
        fd.append('discount_percent', String(selectedDiscount.percent));
        fd.append('discount_notes', document.getElementById('discountNotes')?.value || '');
        fd.append('prescriptions', JSON.stringify(prescriptions));
        fd.append('summary', document.getElementById('recordSummary')?.value || '');
        fd.append('follow_up_date', document.getElementById('followUpDate')?.value || '');
        fd.append('follow_up_interval_months', document.getElementById('followUpInterval')?.value || '0');
        fd.append('follow_up_total_visits', document.getElementById('followUpTotalVisits')?.value || '0');
        fd.append('use_installment', document.getElementById('useInstallment')?.checked ? '1' : '0');
        fd.append('installment_terms', document.getElementById('installmentTerms')?.value || '0');
        fd.append('csrf_token', <?php echo json_encode($csrf_token); ?>);
        fd.append('idempotency_key', secretaryRecordIdempotencyKey);
        
        const res = await fetch('../assets/api/appointments/create_secretary_record.php', {
            method: 'POST',
            body: fd
        });

        const responseText = await res.text();
        let json;
        try {
            json = JSON.parse(responseText);
        } catch (parseErr) {
            console.error('create_secretary_record non-JSON response:', responseText);
            notifySecretary('The system is currently busy. Please try again in a moment.', 'danger');
            secretaryRecordSubmitting = false;
            return;
        }
        if (!json.success) {
            notifySecretary(json.message || 'The system encountered an issue. Please try again.', 'danger');
            secretaryRecordSubmitting = false;
            return;
        }
        
        notifySecretary(json.already_processed || json.idempotent_replay ? 'Clinical record was already submitted.' : 'Clinical record created successfully!', 'success');
        bootstrap.Modal.getInstance(document.getElementById('secretaryRecordModal')).hide();
        
        // Reload page to update queue
        setTimeout(() => location.reload(), 1000);
    } catch (e) {
        console.error(e);
        notifySecretary('The system is currently busy. Please try again in a moment.', 'danger');
        secretaryRecordSubmitting = false;
    }
});
</script>

<script>
window.CURRENT_ROLE = '<?php echo $_SESSION['role'] ?? ''; ?>';
window.CURRENT_USER = <?php echo (int)($_SESSION['user_id'] ?? 0); ?>;
window.MEDIA_BASE_URL = '<?php echo htmlspecialchars(rtrim(dirname(dirname($_SERVER['SCRIPT_NAME'])), '/')); ?>';

function notifySecretary(message, type) {
    window.showToast(String(message || ''), type || 'info');
}
</script>
