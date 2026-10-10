<?php
include dirname(dirname(__DIR__)) . '/config/database.php';
include_once dirname(dirname(__DIR__)) . '/config/security.php';
include_once dirname(dirname(__DIR__)) . '/config/capabilities.php';
include_once dirname(dirname(__DIR__)) . '/config/system_logs.php';
include_once dirname(dirname(__DIR__)) . '/config/odontogram_helpers.php';
include_once dirname(dirname(__DIR__)) . '/config/billing_helpers.php';
include_once dirname(dirname(__DIR__)) . '/config/workflow_notifications.php';
include_once dirname(dirname(__DIR__)) . '/config/booking_helpers.php';
$csrf_token = security_generate_csrf_token();
appointments_ensure_reference_column($conn);

// Visits the doctor has finished that still need the secretary's clinical record + billing.
$ehrQueue = [];
$ehrRes = $conn->query("
    SELECT a.appointment_id, a.patient_id, a.appointment_date, a.reference_code, a.completed_by_doctor_at,
           p.first_name, p.last_name, p.phone_number, p.profile_image, p.google_picture,
           u.first_name AS doctor_first, u.last_name AS doctor_last,
           t.start_time, t.end_time,
           (SELECT COUNT(*) FROM appointment_media WHERE appointment_id = a.appointment_id) AS photo_count,
           (SELECT COUNT(*) FROM appointment_doctor_notes WHERE appointment_id = a.appointment_id) AS note_count,
           (SELECT GROUP_CONCAT(DISTINCT s.service_name ORDER BY s.service_name SEPARATOR ', ')
              FROM selected_services ss JOIN services s ON s.service_id = ss.service_id
             WHERE ss.appointment_id = a.appointment_id) AS services
    FROM appointments a
    LEFT JOIN patients p ON p.patient_id = a.patient_id
    LEFT JOIN users u ON u.user_id = a.user_id
    LEFT JOIN timeslot t ON t.timeslot_id = a.timeslot_id
    WHERE a.status = 'awaiting_secretary_records'
    ORDER BY a.completed_by_doctor_at ASC, a.appointment_id ASC
");
if ($ehrRes) {
    while ($row = $ehrRes->fetch_assoc()) $ehrQueue[] = $row;
}

if (!function_exists('ehr_queue_time')) {
    function ehr_queue_time(?string $t): string
    {
        return $t ? date('g:i A', strtotime($t)) : '';
    }
}
if (!function_exists('ehr_queue_waiting')) {
    /** "12 min ago", "3 h ago", "2 days ago" since the doctor finished the visit. */
    function ehr_queue_waiting(?string $doneAt): string
    {
        if (!$doneAt) return '';
        $mins = max(0, (int)floor((time() - strtotime($doneAt)) / 60));
        if ($mins < 60) return $mins . ' min ago';
        if ($mins < 1440) return floor($mins / 60) . ' h ago';
        $days = (int)floor($mins / 1440);
        return $days . ' day' . ($days === 1 ? '' : 's') . ' ago';
    }
}
?>

<!-- EHR Queue: list of visits waiting for the secretary's record. Opening one replaces the
     list in place (same pattern as Patients), with Back to return. -->
<div id="ehrListView">
<div class="card border-0 dp-page">
    <div class="card-header dp-page__header">
        <div>
            <h2 class="card-title mb-0">EHR Queue <span class="badge rounded-pill text-bg-light border align-middle" style="font-size:0.75rem;"><?php echo count($ehrQueue); ?></span></h2>
            <p class="dp-page__sub mb-0">Visits the doctor has finished. Open one to complete the clinical record and billing.</p>
        </div>
    </div>

    <?php if ($ehrQueue): ?>
    <div class="card-body dp-toolbar">
        <div class="unified-table-search-wrap module-search-inline dp-toolbar__search">
            <i class="bi bi-search unified-table-search-icon" aria-hidden="true"></i>
            <input type="search" id="ehrQueueSearch" class="form-control unified-table-search-input" placeholder="Search by patient, reference, doctor, or service..." aria-label="Search EHR queue" autocomplete="off">
        </div>
    </div>
    <?php endif; ?>

    <div class="card-body pt-0">
        <div class="dp-list" id="ehrQueueList">
            <?php foreach ($ehrQueue as $rec):
                $patientName = trim(($rec['first_name'] ?? '') . ' ' . ($rec['last_name'] ?? '')) ?: 'Unknown patient';
                $initials = strtoupper(substr((string)($rec['first_name'] ?? ''), 0, 1) . substr((string)($rec['last_name'] ?? ''), 0, 1)) ?: '?';
                $photo = trim((string)($rec['profile_image'] ?? '')) ?: trim((string)($rec['google_picture'] ?? ''));
                if ($photo !== '' && !preg_match('#^https?://#i', $photo)) $photo = '../' . ltrim($photo, '/');
                $doctorName = trim(($rec['doctor_first'] ?? '') . ' ' . ($rec['doctor_last'] ?? ''));
                $reference = appointment_reference_label($rec['reference_code'] ?? null);
                $timeRange = trim(ehr_queue_time($rec['start_time']) . ($rec['end_time'] ? ' – ' . ehr_queue_time($rec['end_time']) : ''), ' –');
                $photoCount = (int)$rec['photo_count'];
                $noteCount = (int)$rec['note_count'];
                $sub = array_filter([$reference, (string)($rec['phone_number'] ?? '')]);
                $search = strtolower(implode(' ', [$patientName, $reference, $doctorName, (string)$rec['services'], (string)$rec['phone_number']]));
            ?>
            <button type="button" class="dp-row" data-ehr-open data-appointment-id="<?php echo (int)$rec['appointment_id']; ?>" data-patient-id="<?php echo (int)$rec['patient_id']; ?>" data-search="<?php echo htmlspecialchars($search, ENT_QUOTES, 'UTF-8'); ?>">
                <span class="dp-avatar"><?php echo htmlspecialchars($initials); ?><?php if ($photo !== ''): ?><img src="<?php echo htmlspecialchars($photo, ENT_QUOTES, 'UTF-8'); ?>" alt="" loading="lazy" onerror="this.remove()"><?php endif; ?></span>
                <span class="dp-row__main">
                    <span class="dp-row__name"><?php echo htmlspecialchars($patientName); ?></span>
                    <span class="dp-row__sub"><?php echo htmlspecialchars(implode(' · ', $sub)) ?: '&nbsp;'; ?></span>
                    <span class="dp-row__flags">
                        <span class="dp-flag dp-flag--notice"><i class="bi bi-hourglass-split"></i>Records pending<?php $w = ehr_queue_waiting($rec['completed_by_doctor_at']); echo $w !== '' ? ' · done ' . htmlspecialchars($w) : ''; ?></span>
                        <?php if ($noteCount > 0): ?><span class="dp-flag dp-flag--ok"><i class="bi bi-journal-text"></i><?php echo $noteCount; ?> doctor note<?php echo $noteCount === 1 ? '' : 's'; ?></span><?php endif; ?>
                    </span>
                </span>
                <span class="dp-row__stat"><small class="dp-row__label">Visit</small><strong><?php echo htmlspecialchars(date('M j, Y', strtotime($rec['appointment_date']))); ?></strong><small><?php echo htmlspecialchars($timeRange ?: '—'); ?></small></span>
                <span class="dp-row__stat"><small class="dp-row__label">Doctor</small><strong><?php echo htmlspecialchars($doctorName !== '' ? 'Dr. ' . $doctorName : '—'); ?></strong><small title="<?php echo htmlspecialchars((string)$rec['services'], ENT_QUOTES, 'UTF-8'); ?>"><?php echo htmlspecialchars((string)($rec['services'] ?: 'No services')); ?></small></span>
                <span class="dp-row__stat dp-row__stat--count"><small class="dp-row__label">Photos</small><strong><?php echo $photoCount; ?></strong></span>
                <i class="bi bi-chevron-right dp-row__chev" aria-hidden="true"></i>
            </button>
            <?php endforeach; ?>
        </div>
        <div class="dp-empty" id="ehrQueueEmpty" <?php echo $ehrQueue ? 'hidden' : ''; ?>>
            <div class="dp-note"><i class="bi <?php echo $ehrQueue ? 'bi-search' : 'bi-inbox'; ?>"></i><span id="ehrQueueEmptyText"><?php echo $ehrQueue ? 'No visits match your search.' : 'No visits are waiting for records. Visits appear here once the doctor finishes them.'; ?></span></div>
        </div>
    </div>
</div>
</div>

<!-- Create Record: replaces the queue in place (no modal) so the record gets the full page. -->
<div class="card border-0 dp-page dp-chart" id="ehrRecordView" hidden>
            <div class="dp-chart__header">
                <div class="dp-chart__identity">
                    <span class="dp-avatar dp-avatar--lg" id="recordPatientAvatar"><i class="bi bi-person-fill"></i></span>
                    <div class="dp-chart__who">
                        <h2 class="dp-chart__name" id="secretaryRecordPatientName">Create Clinical Record</h2>
                        <div class="dp-chart__meta"><span class="text-muted">Create clinical record</span><span id="secretaryRecordPatientDetails"></span></div>
                    </div>
                </div>
                <button type="button" class="dp-back" data-ehr-back aria-label="Back to EHR Queue" title="Back to EHR Queue (Esc)">
                    <span class="dp-back__icon" aria-hidden="true"><i class="bi bi-arrow-left"></i></span>
                    <span class="dp-back__text">Back</span>
                </button>
            </div>
            <div>
                <!-- Step Indicator / Tab Navigation -->
                <ul class="nav ehr-stepper" id="recordTabs" role="tablist">
                    <li class="ehr-stepper__item">
                        <a class="ehr-stepper__link is-active" data-bs-toggle="tab" href="#tabClinical" role="tab" data-step-index="1">
                            <span class="ehr-stepper__circle"><i class="bi bi-clipboard2-pulse"></i></span>
                            <span class="ehr-stepper__label">Clinical Findings</span>
                        </a>
                    </li>
                    <li class="ehr-stepper__track"><span class="ehr-stepper__track-fill"></span></li>
                    <li class="ehr-stepper__item">
                        <a class="ehr-stepper__link" data-bs-toggle="tab" href="#tabOdontogram" role="tab" data-step-index="2">
                            <span class="ehr-stepper__circle"><i class="bi bi-grid-3x3-gap"></i></span>
                            <span class="ehr-stepper__label">Charting</span>
                        </a>
                    </li>
                    <li class="ehr-stepper__track"><span class="ehr-stepper__track-fill"></span></li>
                    <li class="ehr-stepper__item">
                        <a class="ehr-stepper__link" data-bs-toggle="tab" href="#tabNotes" role="tab" data-step-index="3">
                            <span class="ehr-stepper__circle"><i class="bi bi-journal-text"></i></span>
                            <span class="ehr-stepper__label">Visit Summary</span>
                        </a>
                    </li>
                    <li class="ehr-stepper__track"><span class="ehr-stepper__track-fill"></span></li>
                    <li class="ehr-stepper__item">
                        <a class="ehr-stepper__link" data-bs-toggle="tab" href="#tabBilling" role="tab" data-step-index="4">
                            <span class="ehr-stepper__circle"><i class="bi bi-receipt"></i></span>
                            <span class="ehr-stepper__label">Billing</span>
                        </a>
                    </li>
                </ul>

                <div class="tab-content p-3">
                    <!-- Visit Summary -->
                    <div class="tab-pane fade" id="tabNotes" role="tabpanel">
                        <div class="row g-3">
                            <div class="col-md-7">
                                <div class="pf-section">
                                    <div class="pf-section__title"><i class="bi bi-journal-check"></i> Visit Wrap-up</div>
                                    <div class="pf-field">
                                        <label>Doctor's Notes</label>
                                        <div id="doctorNotesDisplay" class="border rounded p-2 bg-white" style="min-height: 60px; max-height: 120px; overflow-y: auto; white-space: pre-wrap; font-size: 0.85rem;"></div>
                                    </div>
                                    <div class="pf-field">
                                        <label>Clinical Summary</label>
                                        <textarea id="recordSummary" class="form-control form-control-sm" rows="2" placeholder="Treatment results, findings, recommendations..." style="font-size: 0.85rem;"></textarea>
                                    </div>
                                </div>

                                <div class="pf-section mb-0">
                                    <div class="pf-section__title"><i class="bi bi-chat-square-text"></i> Reason for Visit</div>
                                    <div class="pf-grid-2">
                                        <div class="pf-field">
                                            <label>Chief Complaint</label>
                                            <textarea class="form-control form-control-sm visit-clinical-field" name="chief_complaint" rows="2" placeholder="Primary reason for visit, in the patient's words" style="font-size:0.85rem;"></textarea>
                                        </div>
                                        <div class="pf-field">
                                            <label>Case History</label>
                                            <textarea class="form-control form-control-sm visit-clinical-field" name="case_history" rows="2" placeholder="Relevant past dental/medical history" style="font-size:0.85rem;"></textarea>
                                        </div>
                                    </div>
                                </div>
                            </div>
                            <div class="col-md-5">
                                <div class="pf-section">
                                    <div class="pf-section__title"><i class="bi bi-images"></i> Doctor's Photos</div>
                                    <div id="doctorPhotoGallery" class="d-flex flex-wrap gap-1"></div>
                                </div>

                                <div class="pf-section">
                                    <div class="pf-section__title"><i class="bi bi-capsule"></i> Prescriptions</div>
                                    <div id="prescriptionsBody" style="font-size: 0.85rem;"></div>
                                    <button type="button" class="btn btn-sm btn-outline-secondary mt-1 py-0" id="addPrescriptionBtn" style="font-size: 0.78rem;"><i class="bi bi-plus"></i> Add</button>
                                    <button type="button" class="btn btn-sm btn-outline-primary mt-1 py-0" id="printPrescriptionsBtn" style="font-size: 0.78rem;" disabled><i class="bi bi-printer"></i> Print</button>
                                </div>

                                <div class="pf-section mb-0">
                                    <div class="pf-section__title"><i class="bi bi-calendar-plus"></i> Follow-up</div>
                                    <div class="row g-1">
                                        <div class="col-6"><input type="date" class="form-control form-control-sm" id="followUpDate" style="font-size: 0.8rem;" title="First follow-up visit date"></div>
                                        <div class="col-3"><input type="number" min="1" class="form-control form-control-sm" id="followUpInterval" placeholder="Mo." style="font-size: 0.8rem;" title="Repeat every N months"></div>
                                        <div class="col-3"><input type="number" min="1" class="form-control form-control-sm" id="followUpTotalVisits" placeholder="Visits" style="font-size: 0.8rem;" title="Total number of visits to auto-book"></div>
                                    </div>
                                    <div class="small mt-1" id="followUpRequirementNote" hidden></div>
                                </div>
                            </div>
                        </div>

                        <!-- Treatment plan belongs with the visit wrap-up: what was done this visit and
                             what is planned next. Billing only charges the services actually done. -->
                        <div class="pf-section mt-3 mb-0">
                            <div id="secretaryTreatmentPlan"></div>
                            <small class="text-muted d-block mt-2">Add or edit planned treatments and record the patient's decision (Accept / Decline) after discussing the estimate. Only work the doctor marks done is billed in the next step.</small>
                        </div>
                    </div>

                    <!-- Clinical Assessment -->
                    <div class="tab-pane fade show active" id="tabClinical" role="tabpanel">
                        <div class="pf-section">
                            <div class="pf-section__title"><i class="bi bi-diagram-3"></i> Occlusal Classification</div>
                            <div class="pf-grid-3">
                                <div class="pf-field"><label>Occlusion</label><input type="text" class="form-control form-control-sm visit-clinical-field" name="occlusion" placeholder="e.g. Angle Class I" style="font-size:0.82rem;"></div>
                                <div class="pf-field"><label>Cl. I Type</label><input type="text" class="form-control form-control-sm visit-clinical-field" name="cl_i_type" placeholder="Normal" style="font-size:0.82rem;"></div>
                                <div class="pf-field"><label>Cl. II Division</label><input type="text" class="form-control form-control-sm visit-clinical-field" name="cl_ii_division" style="font-size:0.82rem;"></div>
                                <div class="pf-field"><label>Cl. II SubDivision</label><input type="text" class="form-control form-control-sm visit-clinical-field" name="cl_ii_subdivision" style="font-size:0.82rem;"></div>
                                <div class="pf-field"><label>Cl. II Type</label><input type="text" class="form-control form-control-sm visit-clinical-field" name="cl_ii_type" style="font-size:0.82rem;"></div>
                                <div class="pf-field"><label>Cl. III Division</label><input type="text" class="form-control form-control-sm visit-clinical-field" name="cl_iii_division" style="font-size:0.82rem;"></div>
                                <div class="pf-field"><label>Cl. III SubDivision</label><input type="text" class="form-control form-control-sm visit-clinical-field" name="cl_iii_subdivision" style="font-size:0.82rem;"></div>
                                <div class="pf-field pf-span-2"><label>Operation if any</label><input type="text" class="form-control form-control-sm visit-clinical-field" name="operation_if_any" style="font-size:0.82rem;"></div>
                            </div>
                        </div>

                        <div class="pf-section">
                            <div class="pf-section__title"><i class="bi bi-clipboard2-pulse"></i> Clinical Assessment <span class="badge bg-secondary fw-normal" style="font-size:0.65rem;">Optional</span></div>
                            <div class="pf-grid-3">
                                <div class="pf-field">
                                    <label>Gingiva Consistency</label>
                                    <div class="pf-pill-group">
                                        <input class="pf-check visit-clinical-field" type="radio" name="gingiva_consistency" value="Firm" id="gf"><label class="pf-pill" for="gf">Firm</label>
                                        <input class="pf-check visit-clinical-field" type="radio" name="gingiva_consistency" value="Hyper plastic" id="gh"><label class="pf-pill" for="gh">Hyper</label>
                                        <input class="pf-check visit-clinical-field" type="radio" name="gingiva_consistency" value="Smooth" id="gs"><label class="pf-pill" for="gs">Smooth</label>
                                    </div>
                                </div>
                                <div class="pf-field">
                                    <label>Oral Hygiene</label>
                                    <div class="pf-pill-group">
                                        <input class="pf-check visit-clinical-field" type="radio" name="oral_hygiene" value="Good" id="og"><label class="pf-pill" for="og">Good</label>
                                        <input class="pf-check visit-clinical-field" type="radio" name="oral_hygiene" value="Fair" id="of"><label class="pf-pill" for="of">Fair</label>
                                        <input class="pf-check visit-clinical-field" type="radio" name="oral_hygiene" value="Bad" id="ob"><label class="pf-pill" for="ob">Bad</label>
                                    </div>
                                </div>
                                <div class="pf-field">
                                    <label>Gingival Color</label>
                                    <div class="pf-pill-group">
                                        <input class="pf-check visit-clinical-field" type="radio" name="gingival_color" value="Pink" id="cp"><label class="pf-pill" for="cp">Pink</label>
                                        <input class="pf-check visit-clinical-field" type="radio" name="gingival_color" value="Bright red" id="cr"><label class="pf-pill" for="cr">Red</label>
                                    </div>
                                </div>
                                <div class="pf-field">
                                    <label>Lymph Nodes</label>
                                    <div class="pf-pill-group">
                                        <input class="pf-check visit-clinical-field" type="radio" name="lymph_nodes" value="Not palpable" id="ln"><label class="pf-pill" for="ln">Not palpable</label>
                                        <input class="pf-check visit-clinical-field" type="radio" name="lymph_nodes" value="Palpable" id="lp"><label class="pf-pill" for="lp">Palpable</label>
                                    </div>
                                </div>
                                <div class="pf-field">
                                    <label>Tongue</label>
                                    <div class="pf-pill-group">
                                        <input class="pf-check visit-clinical-field" type="radio" name="tongue" value="Normal" id="tn"><label class="pf-pill" for="tn">Normal</label>
                                        <input class="pf-check visit-clinical-field" type="radio" name="tongue" value="Coated" id="tc"><label class="pf-pill" for="tc">Coated</label>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <div class="pf-section">
                            <div class="pf-section__title"><i class="bi bi-heart-pulse"></i> Vitals &amp; Lab Findings <span class="badge bg-secondary fw-normal" style="font-size:0.65rem;">Optional</span></div>
                            <div class="pf-grid-3">
                                <div class="pf-field"><label>Blood Sugar</label><input type="text" class="form-control form-control-sm visit-clinical-field" name="blood_sugar" placeholder="Value" style="font-size:0.82rem;"></div>
                                <div class="pf-field">
                                    <label>BS Status</label>
                                    <div class="pf-pill-group">
                                        <input class="pf-check visit-clinical-field" type="checkbox" name="blood_sugar_normal" id="bsn"><label class="pf-pill" for="bsn">Normal</label>
                                        <input class="pf-check visit-clinical-field" type="checkbox" name="blood_sugar_high_low" id="bsh"><label class="pf-pill" for="bsh">High/Low</label>
                                    </div>
                                </div>
                                <div class="pf-field"><label>B.P.</label><input type="text" class="form-control form-control-sm visit-clinical-field" name="blood_pressure" placeholder="e.g. 120/80" style="font-size:0.82rem;"></div>
                                <div class="pf-field"><label>Bleeding Time</label><input type="text" class="form-control form-control-sm visit-clinical-field" name="bleeding_time" style="font-size:0.82rem;"></div>
                                <div class="pf-field"><label>Clotting Time</label><input type="text" class="form-control form-control-sm visit-clinical-field" name="clotting_time" style="font-size:0.82rem;"></div>
                                <div class="pf-field"><label>Fainting</label><input type="text" class="form-control form-control-sm visit-clinical-field" name="fainting" style="font-size:0.82rem;"></div>
                                <div class="pf-field"><label>Allergy</label><input type="text" class="form-control form-control-sm visit-clinical-field" name="allergy_notes" placeholder="No" style="font-size:0.82rem;"></div>
                                <div class="pf-field pf-span-2"><label>Blood Diseases</label><input type="text" class="form-control form-control-sm visit-clinical-field" name="blood_diseases" style="font-size:0.82rem;"></div>
                            </div>
                        </div>

                        <div class="pf-section mb-0">
                            <div class="pf-section__title"><i class="bi bi-file-earmark-medical"></i> Radiographic Interpretation</div>
                            <textarea class="form-control form-control-sm visit-clinical-field" name="radiographic_interpretation" rows="2" style="font-size:0.82rem;"></textarea>
                        </div>
                    </div>

                    <!-- Odontogram / Charting -->
                    <div class="tab-pane fade" id="tabOdontogram" role="tabpanel">
                        <div class="d-flex align-items-center mb-2 gap-2">
                            <button type="button" id="openSecretaryOdontogramFullscreenBtn" class="btn btn-sm btn-outline-dark"><i class="bi bi-fullscreen"></i> Full Screen</button>
                            <small class="text-muted">Use 2D chart to set status and comments.</small>
                        </div>
                        <div id="secretaryOdontogramViewport" class="odontogram-viewport">
                            <button type="button" id="closeSecretaryOdontogramFullscreenBtn" class="odontogram-fs-close" aria-label="Exit full screen" title="Exit full screen" style="display:none;"><i class="bi bi-x-lg" aria-hidden="true"></i></button>
                            <div id="secretaryOdontogramContainer" class="secretary-odontogram-container">
                                <iframe id="odontogramFrame" src="<?php echo htmlspecialchars(odontogram_embed_src(), ENT_QUOTES, 'UTF-8'); ?>"></iframe>
                            </div>
                            <?php odontogram_render_rotate_prompt(); ?>
                        </div>
                    </div>

                    <!-- Billing -->
                    <div class="tab-pane fade" id="tabBilling" role="tabpanel">
                        <div id="outstandingInstallmentBanner" class="alert alert-warning py-2 px-3 mb-3" style="display:none; font-size:0.85rem;">
                            <i class="bi bi-exclamation-triangle-fill"></i> <span id="outstandingInstallmentBannerText"></span>
                            <div id="installmentPlanBreakdown" class="inst-breakdown" hidden></div>
                        </div>
                        <div class="row g-3">
                            <div class="col-md-7">
                                <div class="pf-section">
                                    <div class="pf-section__title"><i class="bi bi-clipboard2-check"></i> Services</div>
                                    <div class="table-responsive">
                                        <table class="table table-sm align-middle mb-2">
                                            <thead class="table-light"><tr><th>Service</th><th style="width:120px;">Price</th></tr></thead>
                                            <tbody id="billingServicesBody"><tr id="billingServicesEmptyRow"><td colspan="2" class="text-muted small">No services added.</td></tr></tbody>
                                        </table>
                                    </div>
                                </div>

                                <div class="pf-section mb-0">
                                    <div class="pf-section__title"><i class="bi bi-receipt-cutoff"></i> Additional Charges</div>
                                    <table class="table table-sm align-middle mb-0">
                                        <thead class="table-light"><tr><th>Description</th><th style="width:120px;">Amount</th><th style="width:40px;"></th></tr></thead>
                                        <tbody id="billingChargesBody"></tbody>
                                        <tfoot><tr><td colspan="3"><button type="button" class="btn btn-sm btn-outline-secondary py-0" id="addBillingChargeBtn" style="font-size:0.78rem;"><i class="bi bi-plus"></i> Add Charge</button></td></tr></tfoot>
                                    </table>
                                </div>
                            </div>
                            <div class="col-md-5">
                                <div class="pf-section">
                                    <div class="pf-section__title"><i class="bi bi-percent"></i> Discount</div>
                                    <div class="pf-pill-group mb-2">
                                        <input class="pf-check billing-discount-type" type="radio" name="billingDiscountType" id="discountNone" value="none" checked><label class="pf-pill" for="discountNone">None</label>
                                        <input class="pf-check billing-discount-type" type="radio" name="billingDiscountType" id="discountSenior" value="senior"><label class="pf-pill" for="discountSenior">Senior (<?php echo (int) billing_senior_discount_percent(); ?>%)</label>
                                        <input class="pf-check billing-discount-type" type="radio" name="billingDiscountType" id="discountPwd" value="pwd"><label class="pf-pill" for="discountPwd">PWD (<?php echo (int) billing_pwd_discount_percent(); ?>%)</label>
                                        <input class="pf-check billing-discount-type" type="radio" name="billingDiscountType" id="discountCustom" value="custom"><label class="pf-pill" for="discountCustom">Custom</label>
                                    </div>
                                    <div id="customDiscountFields" style="display:none;">
                                        <input type="number" step="0.01" min="0" max="100" class="form-control form-control-sm mb-1" id="customDiscountPercent" value="20" placeholder="%" style="font-size:0.82rem;">
                                        <input type="text" class="form-control form-control-sm" id="discountNotes" maxlength="255" placeholder="Reason" style="font-size:0.82rem;">
                                    </div>
                                </div>

                                <div class="pf-section">
                                    <div class="pf-section__title"><i class="bi bi-calculator"></i> Totals</div>
                                    <div class="p-2 bg-light border rounded" id="billingTotalsSummary">
                                        <div class="d-flex justify-content-between small"><span>Subtotal</span><span id="billingSubtotalDisplay">₱0.00</span></div>
                                        <div class="d-flex justify-content-between small text-success billing-discount-summary-row d-none" id="billingDiscountRow"><span id="billingDiscountLabel">Discount</span><span id="billingDiscountDisplay">-₱0.00</span></div>
                                        <div class="d-flex justify-content-between fw-bold border-top pt-1 mt-1"><span>Total Due</span><span id="billingTotalDisplay">₱0.00</span></div>
                                    </div>
                                </div>

                                <div class="pf-section">
                                    <div class="pf-section__title"><i class="bi bi-calendar-week"></i> Installment Plan</div>
                                    <div class="d-flex align-items-center flex-wrap gap-2 mb-1">
                                        <div class="form-check mb-0"><input class="form-check-input" type="checkbox" id="useInstallment"><label class="form-check-label small" for="useInstallment">Installment plan</label></div>
                                    </div>
                                    <div id="installmentOptions" class="row g-1 mb-1" style="display:none;">
                                        <div class="col-6">
                                            <label class="small text-muted mb-0" for="installmentDownPayment">Down payment (₱)</label>
                                            <input type="number" min="0" step="0.01" class="form-control form-control-sm" id="installmentDownPayment" placeholder="e.g. 10000" style="font-size:0.82rem;">
                                        </div>
                                        <div class="col-6">
                                            <label class="small text-muted mb-0" for="installmentTerms">Monthly terms</label>
                                            <input type="number" min="1" max="60" class="form-control form-control-sm" id="installmentTerms" value="6" placeholder="Terms" style="font-size:0.82rem;">
                                        </div>
                                    </div>
                                    <div class="small text-muted mb-0" id="installmentDownPaymentNotice" style="display:none;">Patient pays today: ₱0.00</div>
                                </div>

                                <div class="pf-section mb-0">
                                    <div class="pf-section__title"><i class="bi bi-cash-coin"></i> Payment</div>
                                    <div class="form-check mb-2"><input class="form-check-input" type="checkbox" id="recordPaymentNow"><label class="form-check-label small fw-bold" for="recordPaymentNow">Record a payment now</label></div>
                                    <div id="paymentFields" style="display:none;">
                                        <div class="mb-2">
                                            <label class="form-label small mb-1">Amount Tendered</label>
                                            <input type="number" step="0.01" min="0" class="form-control form-control-sm" id="paymentAmountTendered" placeholder="0.00" style="font-size:0.82rem;">
                                        </div>
                                        <div class="mb-2">
                                            <label class="form-label small mb-1">Payment Method</label>
                                            <select class="form-select form-select-sm" id="paymentMethod" style="font-size:0.82rem;">
                                                <option value="cash">Cash</option>
                                                <option value="gcash">GCash</option>
                                                <option value="card">Card</option>
                                                <option value="bank">Bank Transfer</option>
                                            </select>
                                        </div>
                                        <div class="mb-2">
                                            <label class="form-label small mb-1">OR Number <span class="text-danger">*</span></label>
                                            <input type="text" class="form-control form-control-sm" id="paymentOrNumber" maxlength="50" placeholder="Official receipt number" style="font-size:0.82rem;">
                                        </div>
                                        <div class="mb-2">
                                            <label class="form-label small mb-1">Notes</label>
                                            <input type="text" class="form-control form-control-sm" id="paymentNotes" placeholder="Optional" style="font-size:0.82rem;">
                                        </div>
                                        <div class="p-2 bg-light border rounded small">
                                            <div class="d-flex justify-content-between"><span>Amount Applied</span><span id="paymentAmountAppliedDisplay">₱0.00</span></div>
                                            <div class="d-flex justify-content-between"><span>Change</span><span id="paymentChangeDisplay">₱0.00</span></div>
                                        </div>
                                        <small class="text-muted d-block mt-1">A partial payment must be at least 50% of the Total Due, or leave this unchecked to bill later.</small>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            <div class="ehr-record-footer">
                <button type="button" class="btn btn-outline-secondary btn-sm" id="recordBackBtn" style="display:none;"><i class="bi bi-arrow-left"></i> Previous</button>
                <button type="button" class="btn btn-success btn-sm" id="recordNextBtn">Next <i class="bi bi-arrow-right"></i></button>
                <button type="button" class="btn btn-success btn-sm" id="submitSecretaryRecordBtn" style="display:none;"><i class="bi bi-check-lg"></i> Create Record</button>
            </div>
</div>

<style>
/* Create Record view (in place of the queue) */
#ehrRecordView #recordTabs.ehr-stepper { border-top: 1px solid #e5e9f0; }
#ehrRecordView #secretaryRecordPatientDetails:empty { display: none; }
/* Step buttons stay reachable at the bottom of the screen on long steps. */
.ehr-record-footer {
    position: sticky;
    bottom: 0;
    z-index: 5;
    display: flex;
    justify-content: flex-end;
    gap: 8px;
    padding: 10px 16px;
    background: #fff;
    border-top: 1px solid #e5e9f0;
    border-radius: 0 0 var(--bs-card-border-radius, 12px) var(--bs-card-border-radius, 12px);
}

/* EHR create-record step indicator */
#recordTabs.ehr-stepper {
    display: flex;
    align-items: flex-start;
    list-style: none;
    margin: 0;
    padding: 14px 20px 12px;
    background: #f8f9fa;
    border-bottom: 1px solid #e5e9f0;
}
.ehr-stepper__item {
    flex: 0 0 auto;
}
.ehr-stepper__link {
    display: flex;
    flex-direction: column;
    align-items: center;
    gap: 4px;
    width: 96px;
    text-decoration: none;
    cursor: pointer;
    background: transparent;
    border: none;
}
.ehr-stepper__circle {
    display: flex;
    align-items: center;
    justify-content: center;
    width: 34px;
    height: 34px;
    border-radius: 50%;
    background: #eef1f6;
    color: #94a3b8;
    border: 2px solid #dde3ee;
    font-size: 0.9rem;
    transition: background 0.2s ease, border-color 0.2s ease, color 0.2s ease;
}
.ehr-stepper__label {
    font-size: 0.68rem;
    font-weight: 600;
    color: #94a3b8;
    text-align: center;
    line-height: 1.2;
    transition: color 0.2s ease;
}
.ehr-stepper__link.is-active .ehr-stepper__circle {
    background: #16a34a;
    border-color: #16a34a;
    color: #fff;
    box-shadow: 0 0 0 4px rgba(22, 163, 74, 0.15);
}
.ehr-stepper__link.is-active .ehr-stepper__label {
    color: #16a34a;
}
.ehr-stepper__link.is-done .ehr-stepper__circle {
    background: #d1f3e0;
    border-color: #16a34a;
    color: #16a34a;
}
.ehr-stepper__link.is-done .ehr-stepper__label {
    color: #16a34a;
}
.ehr-stepper__track {
    flex: 1 1 auto;
    height: 2px;
    background: #dde3ee;
    margin: 17px 2px 0;
    border-radius: 2px;
    overflow: hidden;
    list-style: none;
}
.ehr-stepper__track-fill {
    display: block;
    height: 100%;
    width: 0%;
    background: #16a34a;
    transition: width 0.25s ease;
}
#ehrRecordView .form-label { margin-bottom: 2px; font-size: 0.82rem; }
/* Installment plan breakdown inside the Billing notice. */
.inst-breakdown { margin-top: 10px; padding-top: 10px; border-top: 1px solid rgba(0, 0, 0, 0.1); color: #0f172a; }
.inst-breakdown__title { font-weight: 700; font-size: 0.8rem; margin-bottom: 6px; }
.inst-breakdown__cols { display: grid; grid-template-columns: minmax(0, 1fr) minmax(0, 1.2fr); gap: 16px; }
.inst-breakdown__line { display: flex; justify-content: space-between; gap: 12px; font-size: 0.8rem; padding: 2px 0; }
.inst-breakdown__line.is-strong { font-weight: 700; border-top: 1px dashed rgba(0, 0, 0, 0.15); margin-top: 2px; padding-top: 4px; }
.inst-breakdown__terms { width: 100%; font-size: 0.78rem; border-collapse: collapse; background: rgba(255, 255, 255, 0.6); }
.inst-breakdown__terms th, .inst-breakdown__terms td { padding: 3px 8px; text-align: left; border-bottom: 1px solid rgba(0, 0, 0, 0.08); }
.inst-breakdown__terms th { font-weight: 700; }
.inst-breakdown__terms tr.is-next td { font-weight: 700; background: rgba(255, 255, 255, 0.9); }
@media (max-width: 767.98px) { .inst-breakdown__cols { grid-template-columns: minmax(0, 1fr); } }
#ehrRecordView .form-check-sm .form-check-input { width: 16px; height: 16px; }
#ehrRecordView .form-check-sm .form-check-label { font-size: 0.8rem; }

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

@media (max-width: 768px) {
    .secretary-odontogram-container {
        height: 520px;
    }
}
@media (max-width: 480px) {
    .secretary-odontogram-container {
        height: 440px;
    }
}/* Create Record tabs (Clinical Findings / Visit Summary / Billing): grouped sections +
   pill selectors, mirroring the profiling step's decluttered layout. Charting is left
   untouched — it's its own dedicated full-screen odontogram tool. */
#ehrRecordView .pf-section {
    background: #f8fafc;
    border: 1px solid #e6ebf1;
    border-radius: 10px;
    padding: 0.85rem 1rem;
    margin-bottom: 0.85rem;
}
#ehrRecordView .pf-section:last-child { margin-bottom: 0; }
#ehrRecordView .pf-section__title {
    display: flex;
    align-items: center;
    gap: 0.4rem;
    font-size: 0.72rem;
    font-weight: 800;
    text-transform: uppercase;
    letter-spacing: 0.04em;
    color: #16a34a;
    margin-bottom: 0.65rem;
}
#ehrRecordView .pf-grid-2 {
    display: grid;
    grid-template-columns: 1fr;
    gap: 0.65rem 1rem;
}
#ehrRecordView .pf-grid-3 {
    display: grid;
    grid-template-columns: 1fr;
    gap: 0.65rem 1rem;
}
@media (min-width: 768px) {
    #ehrRecordView .pf-grid-2 { grid-template-columns: 1fr 1fr; }
    #ehrRecordView .pf-grid-3 { grid-template-columns: 1fr 1fr 1fr; }
    #ehrRecordView .pf-grid-2 .pf-span-2,
    #ehrRecordView .pf-grid-3 .pf-span-2 { grid-column: span 2; }
    #ehrRecordView .pf-grid-3 .pf-span-3 { grid-column: 1 / -1; }
}
#ehrRecordView .pf-field { margin-bottom: 0.65rem; }
#ehrRecordView .pf-field:last-child { margin-bottom: 0; }
#ehrRecordView .pf-field > label {
    display: block;
    font-size: 0.74rem;
    font-weight: 700;
    color: #475569;
    margin-bottom: 0.35rem;
}
#ehrRecordView .pf-pill-group {
    display: flex;
    flex-wrap: wrap;
    gap: 0.4rem;
}
#ehrRecordView .pf-pill-group input.pf-check {
    position: absolute;
    clip: rect(0, 0, 0, 0);
    pointer-events: none;
}
#ehrRecordView .pf-pill {
    display: inline-flex;
    align-items: center;
    padding: 0.3rem 0.7rem;
    border-radius: 999px;
    border: 1px solid #cbd5e1;
    background: #fff;
    font-size: 0.74rem;
    font-weight: 600;
    color: #475569;
    cursor: pointer;
    transition: background-color .15s ease, border-color .15s ease, color .15s ease;
    user-select: none;
    line-height: 1.3;
}
#ehrRecordView .pf-pill:hover {
    border-color: #16a34a;
    color: #16a34a;
}
#ehrRecordView .pf-check:checked + .pf-pill {
    background: #16a34a;
    border-color: #16a34a;
    color: #fff;
    box-shadow: 0 2px 6px rgba(22, 163, 74, 0.25);
}
#ehrRecordView .pf-check:focus-visible + .pf-pill {
    outline: 2px solid #16a34a;
    outline-offset: 1px;
}</style>

<script>
window.currentRecordAppointmentId = null;
window.currentRecordPatientId = null;
window.currentRecordPatientName = '';
window.currentRecordDoctorName = '';
window.currentRecordDoctorId = 0;
window.currentRecordPatientDob = '';
window.secretaryOdontogramData = {};
window.SECRETARY_ODONTOGRAM_IFRAME_HEIGHT = <?php echo (int) odontogram_embed_height_px(); ?>;
window.BILLING_SENIOR_DISCOUNT_PERCENT = <?php echo json_encode((float) billing_senior_discount_percent()); ?>;
window.BILLING_PWD_DISCOUNT_PERCENT = <?php echo json_encode((float) billing_pwd_discount_percent()); ?>;

window.addEventListener('message', function(ev) {
    if (ev.data && ev.data.type === 'odontogram_update' && ev.data.toothState) {
        secretaryOdontogramData = ev.data.toothState;
    }
});

// EHR step indicator: mark steps before the active one as "done", the active one as "active"
(function() {
    var stepLinks = document.querySelectorAll('#recordTabs .ehr-stepper__link');
    var stepTracks = document.querySelectorAll('#recordTabs .ehr-stepper__track-fill');
    if (!stepLinks.length) return;

    var totalSteps = stepLinks.length;
    var backBtn = document.getElementById('recordBackBtn');
    var nextBtn = document.getElementById('recordNextBtn');
    var createBtn = document.getElementById('submitSecretaryRecordBtn');

    function getLinkForStep(step) {
        return document.querySelector('#recordTabs .ehr-stepper__link[data-step-index="' + step + '"]');
    }

    function updateFooterButtons(activeIndex) {
        var isFirst = activeIndex === 1;
        var isLast = activeIndex === totalSteps;
        backBtn.style.display = isFirst ? 'none' : '';
        nextBtn.style.display = isLast ? 'none' : '';
        createBtn.style.display = isLast ? '' : 'none';
    }

    function updateStepper(activeIndex) {
        stepLinks.forEach(function(link) {
            var idx = parseInt(link.getAttribute('data-step-index'), 10);
            link.classList.remove('is-active', 'is-done');
            if (idx < activeIndex) link.classList.add('is-done');
            else if (idx === activeIndex) link.classList.add('is-active');
        });
        stepTracks.forEach(function(fill, i) {
            fill.style.width = (i + 1 < activeIndex) ? '100%' : ((i + 1 === activeIndex) ? '50%' : '0%');
        });
        updateFooterButtons(activeIndex);
    }

    stepLinks.forEach(function(link) {
        link.addEventListener('shown.bs.tab', function() {
            updateStepper(parseInt(link.getAttribute('data-step-index'), 10));
        });
    });

    nextBtn.addEventListener('click', function() {
        var current = document.querySelector('#recordTabs .ehr-stepper__link.is-active');
        var currentIndex = current ? parseInt(current.getAttribute('data-step-index'), 10) : 1;
        var target = getLinkForStep(Math.min(currentIndex + 1, totalSteps));
        if (target) bootstrap.Tab.getOrCreateInstance(target).show();
    });

    backBtn.addEventListener('click', function() {
        var current = document.querySelector('#recordTabs .ehr-stepper__link.is-active');
        var currentIndex = current ? parseInt(current.getAttribute('data-step-index'), 10) : 1;
        var target = getLinkForStep(Math.max(currentIndex - 1, 1));
        if (target) bootstrap.Tab.getOrCreateInstance(target).show();
    });

    // Exposed so openSecretaryRecordModal can reset the wizard to step 1 each time it opens.
    window.resetEhrRecordStepper = function() {
        var firstLink = getLinkForStep(1);
        if (firstLink) bootstrap.Tab.getOrCreateInstance(firstLink).show();
        updateStepper(1);
    };

    updateStepper(1);
})();

document.getElementById('useInstallment')?.addEventListener('change', function() {
    const opts = document.getElementById('installmentOptions');
    const notice = document.getElementById('installmentDownPaymentNotice');
    if (opts) opts.style.display = this.checked ? '' : 'none';
    if (notice) notice.style.display = this.checked ? '' : 'none';
    // Prefill a sensible starting point (50% of the total due) so the field isn't blank —
    // the secretary/doctor can then adjust it to whatever down payment was actually agreed
    // (e.g. a fixed ₱5,000–10,000 for a big-ticket case like braces, rather than a percentage).
    const downInput = document.getElementById('installmentDownPayment');
    if (this.checked && downInput && (downInput.value === '' || Number(downInput.value) <= 0)) {
        downInput.value = (getBillingTotalDue() * 0.5).toFixed(2);
    }
    if (typeof updateInstallmentNotice === 'function') updateInstallmentNotice();
    if (typeof updatePaymentCalculation === 'function') updatePaymentCalculation();
});

document.getElementById('installmentDownPayment')?.addEventListener('input', function() {
    if (typeof updateInstallmentNotice === 'function') updateInstallmentNotice();
    if (typeof updatePaymentCalculation === 'function') updatePaymentCalculation();
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
        <div class="col-md-1"><button type="button" class="btn btn-sm btn-outline-danger" onclick="this.closest('.prescription-row').remove(); updatePrintPrescriptionsBtnState();"><i class="bi bi-trash"></i></button></div>
    `;
    body.appendChild(wrap);
    updatePrintPrescriptionsBtnState();
}

document.getElementById('addPrescriptionBtn')?.addEventListener('click', () => addPrescriptionRow());

function collectPrescriptionRows() {
    const rows = [];
    document.querySelectorAll('#prescriptionsBody .prescription-row').forEach(row => {
        const med = row.querySelector('.rx-med')?.value?.trim() || '';
        if (!med) return;
        rows.push({
            medicine_name: med,
            dosage: row.querySelector('.rx-dosage')?.value || '',
            medicine_brand: row.querySelector('.rx-brand')?.value || '',
            duration: row.querySelector('.rx-duration')?.value || '',
            notes: row.querySelector('.rx-notes')?.value || ''
        });
    });
    return rows;
}

function updatePrintPrescriptionsBtnState() {
    const btn = document.getElementById('printPrescriptionsBtn');
    if (!btn) return;
    btn.disabled = collectPrescriptionRows().length === 0;
}

document.getElementById('prescriptionsBody')?.addEventListener('input', function(ev) {
    if (ev.target?.classList?.contains('rx-med')) {
        updatePrintPrescriptionsBtnState();
    }
});

function calculatePatientAge(dob) {
    if (!dob) return '';
    const d = new Date(dob);
    if (isNaN(d.getTime())) return '';
    const diff = new Date(Date.now() - d.getTime());
    return Math.abs(diff.getUTCFullYear() - 1970);
}

function printPrescriptions() {
    const rows = collectPrescriptionRows();
    if (!rows.length) {
        notifySecretary('Add at least one prescription before printing.', 'warning');
        return;
    }
    
    // Show loading state
    const printBtn = document.getElementById('printPrescriptionsBtn');
    const originalBtnText = printBtn?.innerHTML;
    if (printBtn) {
        printBtn.disabled = true;
        printBtn.innerHTML = '<i class="bi bi-hourglass-split"></i> Loading...';
    }
    
    // Fetch doctor's prescription profile
    fetch('../assets/api/doctors/get_prescription_profile.php?doctor_id=' + currentRecordDoctorId)
        .then(response => response.json())
        .then(data => {
            if (printBtn) {
                printBtn.disabled = false;
                printBtn.innerHTML = originalBtnText;
            }
            
            const profile = data.success ? data.profile : null;
            generateAndPrintPrescription(rows, profile);
        })
        .catch(err => {
            console.error('Error fetching prescription profile:', err);
            if (printBtn) {
                printBtn.disabled = false;
                printBtn.innerHTML = originalBtnText;
            }
            // Fallback to default
            generateAndPrintPrescription(rows, null);
        });
}

function generateAndPrintPrescription(rows, profile) {
    const esc = escapeBillingHtml;
    const today = new Date().toLocaleDateString('en-US', { year: 'numeric', month: 'long', day: 'numeric' });
    const age = calculatePatientAge(currentRecordPatientDob);
    
    // Use profile data or defaults
    const doctorName = profile?.full_name || ('Dr. ' + (currentRecordDoctorName || ''));
    const professionalTitle = profile?.professional_title || 'DMD';
    const clinicName = profile?.clinic_name || "Azucena's Dental Clinic";
    const clinicAddress = profile?.clinic_address || 'Metz Arcade, Cagayan de Oro City, Philippines';
    const clinicPhone = profile?.clinic_phone || '0917 984 3031';
    
    // Build license display — licenses are stored as a JSON array ({type, number} pairs)
    // in the `licenses` column, not as flat license_type_N/license_number_N fields
    // (those were dropped from the schema; see scripts/alter_licenses.php).
    let licenseHtml = '';
    if (profile) {
        let licenses = [];
        try {
            const parsed = typeof profile.licenses === 'string' ? JSON.parse(profile.licenses) : profile.licenses;
            if (Array.isArray(parsed)) {
                licenses = parsed.filter(lic => lic && lic.type && lic.number);
            }
        } catch (e) {
            licenses = [];
        }

        if (licenses.length > 0) {
            licenses.forEach((lic, idx) => {
                licenseHtml += `
                    <div style="line-height:1.9;">
                        <div>${esc(lic.type)} No.: <span style="display:inline-block;min-width:180px;border-bottom:1px solid #333;">${esc(lic.number)}</span></div>
                    </div>`;
            });
        } else {
            licenseHtml = `
                <div style="line-height:1.9;">
                    <div>Lic. No.: <span style="display:inline-block;min-width:180px;border-bottom:1px solid #333;">&nbsp;</span></div>
                    <div>PTR No.: <span style="display:inline-block;min-width:180px;border-bottom:1px solid #333;">&nbsp;</span></div>
                </div>`;
        }
    } else {
        licenseHtml = `
            <div style="line-height:1.9;">
                <div>Lic. No.: <span style="display:inline-block;min-width:180px;border-bottom:1px solid #333;">&nbsp;</span></div>
                <div>PTR No.: <span style="display:inline-block;min-width:180px;border-bottom:1px solid #333;">&nbsp;</span></div>
            </div>`;
    }
    
    // Signature handling
    let signatureHtml = '';
    if (profile?.signature_drawn) {
        signatureHtml = `
            <div style="text-align:center;width:230px;">
                <img src="${esc(profile.signature_drawn)}" alt="Signature" style="max-width:100%; max-height:60px; padding-top:4px;">
                <div style="font-size:0.82em;color:#666;margin-top:4px;">${esc(doctorName)}</div>
                <div style="font-size:0.82em;color:#666;">${esc(professionalTitle)}</div>
            </div>`;
    } else if (profile?.signature_image) {
        signatureHtml = `
            <div style="text-align:center;width:230px;">
                <img src="../${esc(profile.signature_image)}" alt="Signature" style="max-width:100%; max-height:60px; padding-top:4px;">
                <div style="font-size:0.82em;color:#666;margin-top:4px;">${esc(doctorName)}</div>
                <div style="font-size:0.82em;color:#666;">${esc(professionalTitle)}</div>
            </div>`;
    } else {
        signatureHtml = `
            <div style="text-align:center;width:230px;">
                <div style="border-top:1px solid #111;padding-top:4px;font-weight:600;min-height:40px;">${esc(doctorName)}</div>
                <div style="font-size:0.82em;color:#666;">${esc(professionalTitle)}</div>
                <div style="font-size:0.82em;color:#666;">Signature over Printed Name</div>
            </div>`;
    }

    let rxRowsHtml = '';
    rows.forEach((rx, i) => {
        rxRowsHtml += `
            <div style="margin-bottom:16px;">
                <div style="font-weight:700;">${i + 1}. ${esc(rx.medicine_name)}${rx.medicine_brand ? ' (' + esc(rx.medicine_brand) + ')' : ''}</div>
                <div style="margin-left:16px;font-size:0.85em;color:#333;font-style:italic;">
                    ${rx.dosage ? esc(rx.dosage) : ''}${rx.dosage && rx.duration ? ' &mdash; ' : ''}${rx.duration ? esc(rx.duration) : ''}
                    ${rx.notes ? '<div style="font-style:normal;color:#555;">' + esc(rx.notes) + '</div>' : ''}
                </div>
            </div>`;
    });

    const html = `
        <div style="text-align:center;border-bottom:2px solid #111;padding-bottom:10px;margin-bottom:14px;">
            <img src="../assets/img/clinic-logo.png" style="height:48px;" onerror="this.style.display='none'">
            <div style="font-size:1.15em;font-weight:700;margin-top:4px;letter-spacing:0.02em;">${esc(clinicName)}</div>
            <div style="font-size:0.75em;color:#555;">${esc(clinicAddress)}</div>
            <div style="font-size:0.75em;color:#555;">Tel: ${esc(clinicPhone)}</div>
        </div>
        <div style="display:flex;justify-content:space-between;font-size:0.85em;margin-bottom:6px;">
            <div><strong>Patient:</strong> ${esc(currentRecordPatientName || '')}${age !== '' ? ' (' + age + ' y/o)' : ''}</div>
            <div><strong>Date:</strong> ${esc(today)}</div>
        </div>
        <div style="border-bottom:1px solid #ccc;margin-bottom:14px;"></div>
        <div style="font-size:2.1em;font-weight:700;font-family:Georgia,'Times New Roman',serif;margin-bottom:10px;line-height:1;">&#8478;</div>
        <div style="min-height:180px;">${rxRowsHtml}</div>
        <div style="margin-top:50px;font-size:0.85em;">
            <div style="display:flex;justify-content:flex-end;">
                ${signatureHtml}
            </div>
            <div style="margin-top:18px;">
                ${licenseHtml}
            </div>
        </div>
    `;

    const win = window.open('', '', 'height=700,width=500');
    win.document.write(`
        <html>
        <head>
            <title>Prescription</title>
            <style>
                @page { size: 5.5in 8.5in; margin: 0.4in; }
                body { width: 5.5in; font-family: Arial, sans-serif; color: #111; margin: 0; padding: 0.2in; }
                img { max-width: 100%; }
            </style>
        </head>
        <body>${html}</body>
        </html>
    `);
    win.document.close();
    win.focus();
    setTimeout(() => { win.print(); }, 200);
}

document.getElementById('printPrescriptionsBtn')?.addEventListener('click', printPrescriptions);

function postOdontogramToSecretaryFrame(data) {
    const frame = document.getElementById('odontogramFrame');
    if (!frame || !frame.contentWindow) return;
    frame.contentWindow.postMessage({ type: 'load_odontogram', toothState: data || {}, readonly: false }, '*');
}

function isSecretaryOdontogramFullscreen() {
    const viewport = document.getElementById('secretaryOdontogramViewport');
    return !!viewport && window.OdontogramFullscreen.isActive(viewport);
}

function requestSecretaryOdontogramFullscreen() {
    const viewport = document.getElementById('secretaryOdontogramViewport');
    if (!viewport) return;
    window.OdontogramFullscreen.request(viewport);
}

function exitSecretaryOdontogramFullscreen() {
    const viewport = document.getElementById('secretaryOdontogramViewport');
    window.OdontogramFullscreen.exit(viewport);
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
document.addEventListener('odontogram:pseudofullscreenchange', function (e) {
    if (e.target.id === 'secretaryOdontogramViewport') syncSecretaryOdontogramFullscreenUI();
});
document.addEventListener('keydown', function (e) {
    if (e.key === 'Escape' && isSecretaryOdontogramFullscreen()) {
        e.preventDefault(); // so the same Esc doesn't also close the record view below
        exitSecretaryOdontogramFullscreen();
    }
});

// ---------- Queue list <-> Create Record view (in place, like Patients) ----------
var ehrListScroll = 0;

function showEhrRecordView() {
    var list = document.getElementById('ehrListView');
    var view = document.getElementById('ehrRecordView');
    if (!view) return;
    if (list && !list.hidden) ehrListScroll = window.scrollY || 0;
    if (list) list.hidden = true;
    view.hidden = false;
    try { window.scrollTo({ top: 0, behavior: 'auto' }); } catch (e) { window.scrollTo(0, 0); }
    view.querySelector('[data-ehr-back]')?.focus({ preventScroll: true });
}

function closeEhrRecordView() {
    var list = document.getElementById('ehrListView');
    var view = document.getElementById('ehrRecordView');
    if (!view || view.hidden) return;
    if (isSecretaryOdontogramFullscreen()) exitSecretaryOdontogramFullscreen();
    view.hidden = true;
    if (list) list.hidden = false;
    window.scrollTo(0, ehrListScroll || 0);
    var row = window.currentRecordAppointmentId && document.querySelector('#ehrQueueList [data-appointment-id="' + window.currentRecordAppointmentId + '"]');
    if (row) row.focus({ preventScroll: true });
}

document.getElementById('ehrQueueList')?.addEventListener('click', function (e) {
    var row = e.target.closest('[data-ehr-open]');
    if (row) openSecretaryRecordModal(parseInt(row.dataset.appointmentId, 10), parseInt(row.dataset.patientId, 10));
});
document.querySelector('#ehrRecordView [data-ehr-back]')?.addEventListener('click', closeEhrRecordView);
document.addEventListener('keydown', function (e) {
    // Esc first leaves odontogram full screen (handled above); a second Esc goes back.
    if (e.key !== 'Escape' || e.defaultPrevented || isSecretaryOdontogramFullscreen() || document.querySelector('.modal.show')) return;
    var view = document.getElementById('ehrRecordView');
    if (view && !view.hidden) closeEhrRecordView();
});

document.getElementById('ehrQueueSearch')?.addEventListener('input', function () {
    var q = this.value.trim().toLowerCase();
    var shown = 0;
    document.querySelectorAll('#ehrQueueList [data-ehr-open]').forEach(function (row) {
        var match = !q || (row.dataset.search || '').indexOf(q) !== -1;
        row.hidden = !match;
        if (match) shown++;
    });
    var empty = document.getElementById('ehrQueueEmpty');
    if (empty) empty.hidden = shown > 0;
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

window.recordBillingServiceIds = new Set();

// Populated by checkOutstandingInstallments() when the appointment currently open in Create
// Record is itself a follow-up visit of an existing installment plan — used by
// getAmountDueNow() so "Record a payment now" collects the real next-term amount instead of
// being stuck at ₱0.00 (this visit has no billable service of its own).
window.currentVisitInstallmentInfo = null;
// Every unpaid installment term this patient has (any earlier bill), from the same request.
// Drives the Billing notice and the submit rules: with a plan still running, today's
// services are paid in full, or go on a second plan only if nothing is overdue.
window.currentPatientInstallments = null;

function billingPeso(value) {
    return '₱' + Number(value || 0).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 });
}

// The notice depends on whether this visit has anything to bill, which changes as services
// and charges load or are edited — so it is re-evaluated with the totals, not set once.
function refreshInstallmentNotice() {
    const banner = document.getElementById('outstandingInstallmentBanner');
    const bannerText = document.getElementById('outstandingInstallmentBannerText');
    if (!banner || !bannerText) return null;

    const visitPlan = currentVisitInstallmentInfo;
    const visitTerms = (visitPlan && Array.isArray(visitPlan.pending_terms)) ? visitPlan.pending_terms : [];
    const allTerms = (currentPatientInstallments && Array.isArray(currentPatientInstallments.terms)) ? currentPatientInstallments.terms : [];
    const todayTotal = typeof getBillingTotalDue === 'function' ? getBillingTotalDue() : 0;

    let label = '';
    let tone = 'alert-info';
    if (visitTerms.length > 0 && todayTotal <= 0) {
        const next = visitTerms[0];
        label = `This visit is part of an existing installment plan — no new charge will be added. `
            + `Next payment due: ${billingPeso(next.remaining_amount)} (term ${next.term_number}, due ${next.due_date}). `
            + `Check "Record a payment now" below to collect it.`;
    } else if (allTerms.length > 0) {
        const remaining = billingPeso(currentPatientInstallments.total_outstanding);
        const overdue = allTerms.filter(t => t.overdue).reduce((sum, t) => sum + Number(t.remaining_amount || 0), 0);
        const next = allTerms[0];
        if (overdue > 0) {
            tone = 'alert-danger';
            label = `This patient has ${billingPeso(overdue)} in overdue installment payments (${remaining} remaining on the plan). `
                + `Today's services must be paid in full, and a new installment plan cannot be started until the plan is up to date. `
                + `Plan payments are collected from the Billing page.`;
        } else {
            tone = 'alert-warning';
            label = `This patient is still paying an installment plan: ${remaining} remaining, next payment ${billingPeso(next.remaining_amount)} due ${next.due_date}. `
                + `Today's services must be paid in full today, or put on a second plan with the owner's approval. `
                + `Plan payments are collected from the Billing page.`;
        }
    }

    if (!label) {
        banner.style.display = 'none';
        renderInstallmentPlanBreakdown(null);
        return null;
    }
    bannerText.textContent = label;
    renderInstallmentPlanBreakdown(visitPlan ? visitPlan.plan : null);
    banner.className = 'alert py-2 px-3 mb-3 ' + tone;
    banner.style.display = '';
    return { label: label, tone: tone };
}

function showBillingProblem(message) {
    notifySecretary(message, 'warning');
    const tabLink = document.querySelector('#recordTabs .ehr-stepper__link[href="#tabBilling"]');
    if (tabLink) bootstrap.Tab.getOrCreateInstance(tabLink).show();
}

// What an installment plan is made of, shown under the "part of an existing installment
// plan" notice: what was billed, discount, total, down payment, paid so far, and every term —
// so "next payment due" isn't just a bare number. Built with textContent only.
function renderInstallmentPlanBreakdown(plan) {
    const host = document.getElementById('installmentPlanBreakdown');
    if (!host) return;
    host.innerHTML = '';
    host.hidden = true;
    if (!plan || !Array.isArray(plan.terms) || plan.terms.length === 0) return;

    const peso = v => '₱' + Number(v || 0).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 });
    const el = (tag, cls, text) => {
        const node = document.createElement(tag);
        if (cls) node.className = cls;
        if (text !== undefined) node.textContent = text;
        return node;
    };
    const line = (label, value, cls) => {
        const row = el('div', 'inst-breakdown__line' + (cls ? ' ' + cls : ''));
        row.appendChild(el('span', '', label));
        row.appendChild(el('span', '', value));
        return row;
    };

    const origin = [plan.visit_date ? 'visit on ' + plan.visit_date : '', plan.visit_reference ? 'Ref ' + plan.visit_reference : ''].filter(Boolean).join(', ');
    host.appendChild(el('div', 'inst-breakdown__title', 'Installment plan breakdown' + (origin ? ' (' + origin + ')' : '')));

    const cols = el('div', 'inst-breakdown__cols');
    const summary = el('div', 'inst-breakdown__col');
    (plan.items || []).forEach(item => summary.appendChild(line(item.description || 'Item', peso(item.amount))));
    if (Number(plan.discount_amount) > 0) {
        const pct = Number(plan.discount_percent) > 0 ? ' (' + Number(plan.discount_percent) + '%)' : '';
        summary.appendChild(line('Discount' + pct, '− ' + peso(plan.discount_amount)));
    }
    summary.appendChild(line('Plan total', peso(plan.total), 'is-strong'));
    summary.appendChild(line('Down payment', peso(plan.down_payment)));
    summary.appendChild(line('Paid so far', peso(plan.amount_paid)));
    summary.appendChild(line('Remaining balance', peso(plan.balance), 'is-strong'));
    cols.appendChild(summary);

    const termsCol = el('div', 'inst-breakdown__col');
    const table = el('table', 'inst-breakdown__terms');
    const head = el('tr');
    ['Term', 'Due', 'Amount', 'Paid', 'Status'].forEach(h => head.appendChild(el('th', '', h)));
    table.appendChild(head);
    const nextTerm = plan.terms.find(t => t.status !== 'paid');
    plan.terms.forEach(t => {
        const tr = el('tr', t === nextTerm ? 'is-next' : '');
        tr.appendChild(el('td', '', Number(t.term_number) === 0 ? 'Down payment' : String(t.term_number)));
        tr.appendChild(el('td', '', t.due_date));
        tr.appendChild(el('td', '', peso(t.amount)));
        tr.appendChild(el('td', '', peso(t.paid_amount)));
        tr.appendChild(el('td', '', t.status === 'paid' ? 'Paid' : (t === nextTerm ? 'Due next' : (t.status === 'partial' ? 'Partly paid' : 'Pending'))));
        table.appendChild(tr);
    });
    termsCol.appendChild(table);
    cols.appendChild(termsCol);
    host.appendChild(cols);
    host.hidden = false;
}

async function checkOutstandingInstallments(patientId, appointmentId) {
    const banner = document.getElementById('outstandingInstallmentBanner');
    const bannerText = document.getElementById('outstandingInstallmentBannerText');
    currentVisitInstallmentInfo = null;
    currentPatientInstallments = null;
    if (banner) banner.style.display = 'none';
    renderInstallmentPlanBreakdown(null);

    try {
        const url = '../assets/api/billing/get_patient_outstanding_installments.php?patient_id=' + patientId
            + (appointmentId ? '&appointment_id=' + appointmentId : '');
        const res = await fetch(url);
        const data = await res.json();
        if (!data.success) return;

        currentVisitInstallmentInfo = data.current_visit_installment || null;
        currentPatientInstallments = { terms: Array.isArray(data.terms) ? data.terms : [], total_outstanding: Number(data.total_outstanding) || 0, has_overdue: !!data.has_overdue };
        updatePaymentCalculation();

        refreshInstallmentNotice();
        // A banner tucked inside the Billing tab is easy to miss if the secretary doesn't
        // click that far — a toast up front makes sure it's seen on open. Shown a moment
        // later, once this visit's services have loaded, since the wording depends on them.
        setTimeout(function () {
            if (currentRecordAppointmentId !== appointmentId) return;
            const notice = refreshInstallmentNotice();
            if (notice) notifySecretary(notice.label, notice.tone === 'alert-danger' ? 'danger' : (notice.tone === 'alert-warning' ? 'warning' : 'info'));
        }, 1500);
    } catch (e) {
        console.error('checkOutstandingInstallments failed:', e);
    }
}

// A patient who still has planned treatments (proposed or accepted) must leave with a next
// visit: either one is already booked, or a follow-up date has to be set here. The server
// (create_secretary_record.php) enforces the same rule; this just says so before submit.
window.currentRecordNextAppointment = null;

function secretaryPendingPlanCount() {
    const widget = document.getElementById('secretaryTreatmentPlan')?._tpWidget;
    return ((widget && widget.items) || []).filter(i => i.status === 'proposed' || i.status === 'accepted').length;
}

function isFollowUpDateRequired() {
    return secretaryPendingPlanCount() > 0 && !currentRecordNextAppointment;
}

function refreshFollowUpRequirement() {
    const note = document.getElementById('followUpRequirementNote');
    const input = document.getElementById('followUpDate');
    if (!note || !input) return;
    const pending = secretaryPendingPlanCount();
    if (pending > 0 && currentRecordNextAppointment) {
        note.className = 'small mt-1 text-success';
        note.textContent = 'Next appointment already booked on ' +
            currentRecordNextAppointment.appointment_date + '. A follow-up date is optional.';
        note.hidden = false;
    } else {
        note.hidden = true;
    }
    if (!isFollowUpDateRequired() || input.value) input.classList.remove('is-invalid');
}

function secretaryTodayYmd() {
    const d = new Date();
    return d.getFullYear() + '-' + String(d.getMonth() + 1).padStart(2, '0') + '-' + String(d.getDate()).padStart(2, '0');
}

function showFollowUpDateProblem(message) {
    notifySecretary(message, 'warning');
    const tabLink = document.querySelector('#recordTabs .ehr-stepper__link[href="#tabNotes"]');
    if (tabLink) bootstrap.Tab.getOrCreateInstance(tabLink).show();
    const input = document.getElementById('followUpDate');
    if (input) {
        input.classList.add('is-invalid');
        input.focus();
    }
}

document.getElementById('followUpDate')?.addEventListener('input', refreshFollowUpRequirement);
// The plan widget re-renders whenever items load or change (added, accepted, declined).
(function () {
    const planHost = document.getElementById('secretaryTreatmentPlan');
    if (planHost && planHost.parentNode) {
        new MutationObserver(refreshFollowUpRequirement).observe(planHost.parentNode, { childList: true, subtree: true });
    }
})();

async function openSecretaryRecordModal(appointmentId, patientId) {
    currentRecordAppointmentId = appointmentId;
    currentRecordPatientId = patientId;
    currentRecordNextAppointment = null;

    if (window.TreatmentPlan) {
        window.TreatmentPlan.mount(document.getElementById('secretaryTreatmentPlan'), {
            patientId: patientId,
            appointmentId: appointmentId,
            role: 'secretary',
            csrfToken: <?php echo json_encode($csrf_token); ?>,
            notify: notifySecretary
        });
    }

    if (typeof window.resetEhrRecordStepper === 'function') {
        window.resetEhrRecordStepper();
    }

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

        // Check for outstanding installment terms from earlier billings (e.g. a missed
        // monthly braces-adjustment payment) — surfaced proactively here, not just left for
        // the secretary to notice on the Billing screen, since payments apply oldest-term-first
        // and collecting only "this visit's" amount would otherwise silently miss it.
        checkOutstandingInstallments(patientId, appointmentId);

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
        currentRecordPatientName = String(data.appointment?.patient_name || '').trim();
        currentRecordDoctorName = String(data.appointment?.doctor_name || '').trim();
        currentRecordDoctorId = parseInt(data.appointment?.doctor_id || 0, 10) || 0;
        currentRecordPatientDob = data.appointment?.date_of_birth || '';
        if (patientNameEl) {
            patientNameEl.textContent = currentRecordPatientName || 'Create Clinical Record';
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
            }, { iconOnlyDownload: true });
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
        currentRecordNextAppointment = data.next_appointment || null;
        refreshFollowUpRequirement();
        const tp = data.treatment_plan || {};
        document.getElementById('followUpInterval').value = tp.follow_up_interval_months || '';
        document.getElementById('followUpTotalVisits').value = tp.follow_up_total_visits || '';

        const rxBody = document.getElementById('prescriptionsBody');
        rxBody.innerHTML = '';
        if (data.prescriptions && data.prescriptions.length) {
            data.prescriptions.forEach(rx => addPrescriptionRow(rx));
        }

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
        document.getElementById('installmentDownPaymentNotice').style.display = 'none';
        document.getElementById('installmentTerms').value = '6';
        document.getElementById('installmentDownPayment').value = '';
        document.getElementById('recordPaymentNow').checked = false;
        document.getElementById('paymentFields').style.display = 'none';
        document.getElementById('paymentAmountTendered').value = '';
        document.getElementById('paymentMethod').value = 'cash';
        document.getElementById('paymentOrNumber').value = '';
        document.getElementById('paymentNotes').value = '';
        resetBillingDiscountFields();
        updateBillingTotals();

        // Pre-fill visit clinical exam from this appointment's clinical record
        const visitClinical = data.existing_visit_clinical || data.existing_profiling || {};
        Object.keys(visitClinical).forEach(key => {
            const fields = document.querySelectorAll('#ehrRecordView [name="' + key + '"]');
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
            initToggleableClinicalRadios(document.getElementById('ehrRecordView'));
        }
        
        showEhrRecordView();
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
    refreshInstallmentNotice();
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
        }
        // The row is a Bootstrap .d-flex (display: flex !important), which an inline
        // display:none cannot override — so "None" used to leave the last discount showing.
        discountRow.classList.toggle('d-none', discountAmount <= 0);
    }
    updatePaymentCalculation();
    updateInstallmentNotice();
}

function updateInstallmentNotice() {
    const notice = document.getElementById('installmentDownPaymentNotice');
    if (!notice) return;
    const downPayment = getInstallmentDownPaymentAmount();
    const total = getBillingTotalDue();
    const percent = total > 0 ? (downPayment / total * 100) : 0;
    notice.textContent = 'Patient pays today: ₱' + formatBillingAmount(downPayment) + ' (' + percent.toFixed(1).replace(/\.0$/, '') + '% down payment)';
}

function getBillingTotalDue() {
    const subtotal = getBillingSubtotal();
    const discount = getSelectedBillingDiscount();
    const discountAmount = (discount.type === 'none' || discount.percent <= 0)
        ? 0
        : Math.round(subtotal * (discount.percent / 100) * 100) / 100;
    return Math.max(0, Math.round((subtotal - discountAmount) * 100) / 100);
}

// The down payment is a plain peso amount the secretary/doctor sets directly (not a fixed
// 50% — a big-ticket, doctor-priced case like braces might use a ₱5,000-10,000 down payment
// regardless of the total), clamped to the total due so it can never exceed what's owed.
function getInstallmentDownPaymentAmount() {
    const total = getBillingTotalDue();
    const input = document.getElementById('installmentDownPayment');
    const entered = input ? parseFloat(input.value) : NaN;
    const downPayment = Number.isFinite(entered) && entered >= 0 ? entered : total * 0.5;
    return Math.round(Math.min(downPayment, total) * 100) / 100;
}

// When an installment plan is being created, only today's down payment is actually due —
// the rest is due on future terms. Capping against the full total (instead of the down
// payment) would record the whole tendered amount as a payment and mark every future term
// paid immediately, instead of returning the excess as change.
function getAmountDueNow() {
    const useInstallment = document.getElementById('useInstallment')?.checked;
    if (useInstallment) return getInstallmentDownPaymentAmount();

    const billingTotal = getBillingTotalDue();
    // This visit has no billable service/charge of its own (billingTotal === 0) but is a
    // follow-up of an existing installment plan — "due now" is the real next term amount,
    // not ₱0.00. If the doctor DID add a charge for this visit (e.g. a broken bracket repair),
    // that takes priority and is billed normally instead.
    if (billingTotal <= 0 && currentVisitInstallmentInfo && Array.isArray(currentVisitInstallmentInfo.pending_terms) && currentVisitInstallmentInfo.pending_terms.length > 0) {
        return Number(currentVisitInstallmentInfo.pending_terms[0].remaining_amount) || 0;
    }
    return billingTotal;
}

function updatePaymentCalculation() {
    const tendered = parseFloat(document.getElementById('paymentAmountTendered')?.value || '0') || 0;
    const amountDueNow = getAmountDueNow();
    const amountApplied = Math.min(tendered, amountDueNow);
    const change = Math.max(0, tendered - amountApplied);

    const appliedEl = document.getElementById('paymentAmountAppliedDisplay');
    const changeEl = document.getElementById('paymentChangeDisplay');
    if (appliedEl) appliedEl.textContent = '₱' + formatBillingAmount(amountApplied);
    if (changeEl) changeEl.textContent = '₱' + formatBillingAmount(change);
}

document.getElementById('recordPaymentNow')?.addEventListener('change', function() {
    const fields = document.getElementById('paymentFields');
    if (fields) fields.style.display = this.checked ? 'block' : 'none';
    updatePaymentCalculation();
});
document.getElementById('paymentAmountTendered')?.addEventListener('input', updatePaymentCalculation);

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
        tr.innerHTML = '<td colspan="2" class="text-muted small">No package services added.</td>';
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
    `;
    // Clinically-rendered services can't be removed here — they reflect what was actually
    // done for the patient. Only manually-added "extra charge" rows (addBillingChargeRow)
    // are deletable.
    tbody.appendChild(tr);
    recordBillingServiceIds.add(sid);
    updateBillingServicesEmptyRow();
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
            ? collectVisitClinicalData('#ehrRecordView')
            : {};
        if (typeof collectVisitClinicalData !== 'function') {
            document.querySelectorAll('#ehrRecordView .visit-clinical-field').forEach(field => {
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

        const prescriptions = collectPrescriptionRows();

        const followUpDateValue = document.getElementById('followUpDate')?.value || '';
        if (isFollowUpDateRequired()) {
            if (!followUpDateValue) {
                showFollowUpDateProblem('This patient still has planned treatments and no upcoming appointment. Set a follow-up date before submitting the record.');
                secretaryRecordSubmitting = false;
                return;
            }
            if (followUpDateValue <= secretaryTodayYmd()) {
                showFollowUpDateProblem('The follow-up date must be after today.');
                secretaryRecordSubmitting = false;
                return;
            }
        }

        // Optional payment recorded at record-creation time
        const recordPaymentNow = document.getElementById('recordPaymentNow')?.checked || false;
        const paymentTendered = parseFloat(document.getElementById('paymentAmountTendered')?.value || '0') || 0;
        const paymentOrNumber = document.getElementById('paymentOrNumber')?.value?.trim() || '';
        // If an installment plan is being created, only today's down payment is due now —
        // cap against that, not the full total, so any excess tendered comes back as change.
        const amountDueNow = getAmountDueNow();
        const paymentAmountApplied = Math.min(paymentTendered, amountDueNow);

        if (recordPaymentNow) {
            if (paymentOrNumber === '') {
                notifySecretary('OR number is required to record a payment.', 'warning');
                secretaryRecordSubmitting = false;
                return;
            }
            if (paymentAmountApplied <= 0) {
                notifySecretary('Enter an amount tendered to record a payment, or uncheck "Record a payment now".', 'warning');
                secretaryRecordSubmitting = false;
                return;
            }
            if (paymentAmountApplied < amountDueNow && paymentAmountApplied < amountDueNow * 0.5) {
                notifySecretary('A partial payment must be at least 50% of the amount due now, or leave it blank to pay in full/later.', 'warning');
                secretaryRecordSubmitting = false;
                return;
            }
        }

        // Patient still paying an installment plan: today's services are paid in full, or go
        // on a second plan only when nothing is overdue. Either way the secretary confirms
        // before the record goes through (the server enforces the same rules).
        const existingPlanTerms = (currentPatientInstallments && currentPatientInstallments.terms) || [];
        const todayTotal = getBillingTotalDue();
        let confirmSecondInstallment = false;
        if (existingPlanTerms.length > 0 && todayTotal > 0) {
            const planRemaining = billingPeso(currentPatientInstallments.total_outstanding);
            const overdueAmount = existingPlanTerms.filter(t => t.overdue).reduce((sum, t) => sum + Number(t.remaining_amount || 0), 0);
            let confirmMessage;
            if (document.getElementById('useInstallment')?.checked) {
                if (overdueAmount > 0) {
                    showBillingProblem(`This patient has ${billingPeso(overdueAmount)} in overdue installment payments. A new installment plan cannot be started until the existing plan is up to date. Today's services must be paid in full.`);
                    secretaryRecordSubmitting = false;
                    return;
                }
                confirmMessage = `This patient already has an installment plan with ${planRemaining} remaining, and its payments are up to date. `
                    + `Starting a second installment plan for today's services (${billingPeso(todayTotal)}) needs the owner's approval. Has it been approved?`;
                confirmSecondInstallment = true;
            } else {
                if (!recordPaymentNow || paymentAmountApplied + 0.005 < todayTotal) {
                    showBillingProblem(`This patient is still paying an installment plan (${planRemaining} remaining), so today's services (${billingPeso(todayTotal)}) must be paid in full today. Tick "Record a payment now" and enter the full amount.`);
                    secretaryRecordSubmitting = false;
                    return;
                }
                confirmMessage = `This patient has an existing installment plan with ${planRemaining} remaining. `
                    + `Today's payment of ${billingPeso(todayTotal)} covers today's services only and does not reduce the plan. Proceed?`;
            }
            const proceed = await window.appConfirm(confirmMessage, { title: 'Existing installment plan', confirmText: 'Proceed' });
            if (!proceed) {
                secretaryRecordSubmitting = false;
                return;
            }
        }

        const fd = new FormData();
        fd.append('appointment_id', currentRecordAppointmentId);
        fd.append('patient_id', currentRecordPatientId);
        fd.append('confirm_second_installment', confirmSecondInstallment ? '1' : '0');
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
        fd.append('installment_down_payment', String(getInstallmentDownPaymentAmount()));
        fd.append('record_payment', recordPaymentNow ? '1' : '0');
        fd.append('payment_amount_applied', String(paymentAmountApplied));
        fd.append('payment_amount_tendered', String(paymentTendered));
        fd.append('payment_method', document.getElementById('paymentMethod')?.value || 'cash');
        fd.append('payment_or_number', paymentOrNumber);
        fd.append('payment_notes', document.getElementById('paymentNotes')?.value || '');
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
            if (json.code === 'follow_up_required') {
                showFollowUpDateProblem(json.message || 'Set a follow-up date before submitting the record.');
            } else if (['installment_overdue', 'installment_confirm_required', 'full_payment_required'].includes(json.code)) {
                showBillingProblem(json.message);
            } else {
                notifySecretary(json.message || 'The system encountered an issue. Please try again.', 'danger');
            }
            secretaryRecordSubmitting = false;
            return;
        }
        
        notifySecretary(json.already_processed || json.idempotent_replay ? 'Clinical record was already submitted.' : 'Clinical record created successfully!', 'success');

        // This visit didn't get its own new charge because it's already covered by an
        // existing installment plan — confirm clearly what actually happened instead of
        // leaving the secretary looking at a ₱0.00 total wondering if something broke.
        if (json.installment_covered_visit) {
            if (json.installment_payment_applied) {
                notifySecretary(`No new charge for this visit (installment-covered). ₱${Number(json.installment_payment_applied).toFixed(2)} applied to the existing installment plan.`, 'success');
            } else {
                notifySecretary('No new charge for this visit — already covered by an existing installment plan.', 'info');
            }
        }

        // A single (non-recurring) follow-up date failing to book used to be swallowed
        // silently — the record would "succeed" with no visible sign the visit never got
        // scheduled. Surface it loudly now instead.
        if (json.follow_up_scheduling_error) {
            notifySecretary('Follow-up visit was NOT scheduled: ' + json.follow_up_scheduling_error, 'danger');
        }

        // Recurring follow-ups (e.g. 12 monthly braces-adjustment visits) were booked up
        // front — let the secretary know right away if any month couldn't be scheduled
        // (e.g. no open slot) so it can be booked manually instead of silently missing.
        const recurring = json.recurring_follow_ups;
        if (recurring) {
            if (recurring.failed && recurring.failed.length > 0) {
                const missedDates = recurring.failed.map(f => f.date).join(', ');
                notifySecretary(
                    `Booked ${recurring.booked} of ${recurring.requested} monthly follow-up visits. ` +
                    `Could not book: ${missedDates} — please schedule these manually.`,
                    'warning'
                );
            } else if (recurring.booked > 0) {
                notifySecretary(`All ${recurring.booked} monthly follow-up visits were booked.`, 'success');
            }
        }

        closeEhrRecordView();

        // Reload page to update queue — give extra time to actually read any extra toast
        // (scheduling error, installment info, recurring summary) before it's gone.
        const hasExtraToast = !!(recurring || json.follow_up_scheduling_error || json.installment_covered_visit);
        setTimeout(() => location.reload(), hasExtraToast ? 2500 : 1000);
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
window.MEDIA_BASE_URL = '<?php echo htmlspecialchars(rtrim(str_replace('\\', '/', dirname(dirname($_SERVER['SCRIPT_NAME']))), '/')); ?>';

function notifySecretary(message, type) {
    window.showToast(String(message || ''), type || 'info');
}
</script>
