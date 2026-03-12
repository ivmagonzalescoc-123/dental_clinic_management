<?php
include dirname(dirname(__DIR__)) . '/config/database.php';

// Get logged-in doctor ID
$doctor_id = $_SESSION['user_id'] ?? 0;
if ($doctor_id <= 0) {
    echo '<div class="alert alert-danger">You must be logged in to view this page.</div>';
    exit;
}

$action = $_GET['action'] ?? '';
$today = date('Y-m-d');

// Define a flag to prevent re-processing header logic
if (!defined('APPOINTMENTS_HEADER_PROCESSED')) {
    define('APPOINTMENTS_HEADER_PROCESSED', true);
    
    // Handle COMPLETE VISIT (submit EHR and send to billing)
    if ($action === 'complete' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $appointmentId = (int)($_POST['appointment_id'] ?? 0);
        $serviceId = (int)($_POST['service_id'] ?? 0);
        $toothData = $_POST['tooth_data'] ?? []; // Array of tooth updates
        $treatmentIds = $_POST['treatment_ids'] ?? []; // Array of treatment IDs
        $notes = $_POST['notes'] ?? '';
        $followUpDate = $_POST['follow_up_date'] ?? null;
        $materials_used = isset($_POST['materials_used']) ? json_decode($_POST['materials_used'], true) : null;
        $doctor_fee = isset($_POST['doctor_fee']) ? (float)$_POST['doctor_fee'] : 0.0;
        $recommended_services = isset($_POST['recommended_services']) ? json_decode($_POST['recommended_services'], true) : null;
        
        if ($appointmentId <= 0 || $serviceId <= 0) {
            $_SESSION['message'] = 'Invalid appointment or service data.';
            $_SESSION['message_type'] = 'warning';
            header('Location: doctor.php?page=appointments');
            exit;
        }
        
        $conn->begin_transaction();
        try {
            // Verify appointment exists and belongs to this doctor
            $stmt = $conn->prepare('SELECT appointment_id, patient_id, user_id FROM appointments WHERE appointment_id = ? AND user_id = ?');
            if (!$stmt) throw new Exception('Error preparing statement.');
            $stmt->bind_param('ii', $appointmentId, $doctor_id);
            $stmt->execute();
            $res = $stmt->get_result();
            $appt = $res ? $res->fetch_assoc() : null;
            $stmt->close();
            
            if (!$appt) {
                throw new Exception('Appointment not found or access denied.');
            }
            
            $patientId = $appt['patient_id'];
            
            // Update appointment status to completed and add notes
            $status = 'completed';
            $stmt = $conn->prepare('UPDATE appointments SET status = ?, notes = ? WHERE appointment_id = ?');
            if ($stmt) {
                $stmt->bind_param('ssi', $status, $notes, $appointmentId);
                $stmt->execute();
                $stmt->close();
            }
            
            // Record tooth status for each tooth updated
            foreach ($toothData as $toothId => $toothStatus) {
                if (!empty($toothStatus)) {
                    $stmt = $conn->prepare('
                        INSERT INTO tooth_status (tooth_selected, status) 
                        VALUES (?, ?)
                        ON DUPLICATE KEY UPDATE status = VALUES(status)
                    ');
                    if ($stmt) {
                        $stmt->bind_param('is', $toothId, $toothStatus);
                        $stmt->execute();
                        $stmt->close();
                    }
                }
            }
            
            // Record treatments if provided
            if (!empty($treatmentIds)) {
                foreach ($treatmentIds as $treatmentId) {
                    $treatmentId = (int)$treatmentId;
                    // You can add logic to link treatments to appointments/patients here
                }
            }
            
            // Update arrival status to served
            $stmt = $conn->prepare('UPDATE patient_arrivals SET status = ? WHERE appointment_id = ?');
            if ($stmt) {
                $arrStatus = 'served';
                $stmt->bind_param('si', $arrStatus, $appointmentId);
                $stmt->execute();
                $stmt->close();
            }
            
            // Get service price
            $stmt = $conn->prepare('SELECT initial_price FROM services WHERE service_id = ?');
            $servicePrice = 0;
            if ($stmt) {
                $stmt->bind_param('i', $serviceId);
                $stmt->execute();
                $res = $stmt->get_result();
                if ($res && ($row = $res->fetch_assoc())) {
                    $servicePrice = (float)($row['initial_price'] ?? 0);
                }
                $stmt->close();
            }

            // Calculate billing total: service + materials + doctor fee + any recommended services
            $billingAmount = 0.0;
            $billingAmount += $servicePrice;
            if ($materials_used && is_array($materials_used)) {
                foreach ($materials_used as $m) {
                    $qty = isset($m['qty']) ? (float)$m['qty'] : 0;
                    $price = isset($m['price']) ? (float)$m['price'] : 0;
                    $billingAmount += $qty * $price;
                }
            }
            if ($recommended_services && is_array($recommended_services)) {
                foreach ($recommended_services as $sid) {
                    $sstmt = $conn->prepare('SELECT initial_price FROM services WHERE service_id = ?');
                    $sstmt->bind_param('i', $sid);
                    $sstmt->execute();
                    $sres = $sstmt->get_result();
                    if ($sres && ($srow = $sres->fetch_assoc())) $billingAmount += (float)$srow['initial_price'];
                    $sstmt->close();
                }
            }
            if ($doctor_fee > 0) $billingAmount += $doctor_fee;

            $billingStatus = 'unpaid';
            $existingBillingId = 0;
            $chk = $conn->prepare('SELECT billing_id FROM billing WHERE appointment_id = ? LIMIT 1');
            if ($chk) {
                $chk->bind_param('i', $appointmentId);
                $chk->execute();
                $cres = $chk->get_result();
                $crow = $cres ? $cres->fetch_assoc() : null;
                $existingBillingId = (int)($crow['billing_id'] ?? 0);
                $chk->close();
            }

            if ($existingBillingId > 0) {
                $stmt = $conn->prepare('UPDATE billing SET user_id = ?, total_amount = ?, status = ?, created_at = NOW() WHERE billing_id = ?');
                if ($stmt) {
                    $stmt->bind_param('idsi', $patientId, $billingAmount, $billingStatus, $existingBillingId);
                    $stmt->execute();
                    $stmt->close();
                }
            } else {
                $stmt = $conn->prepare('INSERT INTO billing (appointment_id, user_id, total_amount, status, created_at) VALUES (?, ?, ?, ?, NOW())');
                if ($stmt) {
                    $stmt->bind_param('iids', $appointmentId, $patientId, $billingAmount, $billingStatus);
                    $stmt->execute();
                    $stmt->close();
                }
            }
            
            // Handle follow-up appointment if requested
            if (!empty($followUpDate)) {
                $followUpDate = date('Y-m-d', strtotime($followUpDate));
                // You can add logic to create follow-up appointment here
                // For now, we'll just store it in notes
            }
            
            $conn->commit();
            
            $_SESSION['message'] = 'Visit completed successfully. Patient sent to secretary for billing.';
            $_SESSION['message_type'] = 'success';
        } catch (Throwable $e) {
            $conn->rollback();
            $_SESSION['message'] = 'Error completing visit: ' . $e->getMessage();
            $_SESSION['message_type'] = 'danger';
        }
        
        header('Location: doctor.php?page=appointments');
        exit;
    }
    
    // Handle ACCEPT patient (start visit) - set arrival status in_progress and appointment status
    if ($action === 'accept' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $appointmentId = (int)($_POST['appointment_id'] ?? 0);
        if ($appointmentId <= 0) {
            $_SESSION['message'] = 'Invalid appointment.';
            $_SESSION['message_type'] = 'warning';
            header('Location: doctor.php?page=appointments');
            exit;
        }

        $conn->begin_transaction();
        try {
            $arrStatus = 'in_progress';
            $stmt = $conn->prepare('UPDATE patient_arrivals SET status = ? WHERE appointment_id = ?');
            if ($stmt) {
                $stmt->bind_param('si', $arrStatus, $appointmentId);
                $stmt->execute();
                $stmt->close();
            }

            $apptStatus = 'in_progress';
            $ust = $conn->prepare('UPDATE appointments SET status = ? WHERE appointment_id = ? AND user_id = ?');
            if ($ust) {
                $ust->bind_param('sii', $apptStatus, $appointmentId, $doctor_id);
                $ust->execute();
                $ust->close();
            }

            $conn->commit();
            $_SESSION['message'] = 'Patient accepted. Visit started.';
            $_SESSION['message_type'] = 'success';
        } catch (Throwable $e) {
            $conn->rollback();
            $_SESSION['message'] = 'Error accepting patient: ' . $e->getMessage();
            $_SESSION['message_type'] = 'danger';
        }

        header('Location: doctor.php?page=appointments');
        exit;
    }
} // End of header processing conditional

// Fetch all appointments for this doctor (for reference)
$allAppointments = [];
$all_sql = "
    SELECT
        a.appointment_id,
        a.patient_id,
        a.appointment_date,
        a.reason,
        a.status,
        p.first_name,
        p.last_name,
        NULL as appointment_time,
        (SELECT COUNT(*) FROM clinical_records cr WHERE cr.appointment_id = a.appointment_id) AS has_history,
        -- latest schedule summary for this appointment (if any)
        (SELECT s.status FROM schedules s WHERE s.appointment_id = a.appointment_id ORDER BY COALESCE(s.scheduled_start, s.created_at) DESC LIMIT 1) AS schedule_status,
        (SELECT DATE_FORMAT(s.scheduled_start, '%Y-%m-%d %H:%i') FROM schedules s WHERE s.appointment_id = a.appointment_id ORDER BY COALESCE(s.scheduled_start, s.created_at) DESC LIMIT 1) AS schedule_start,
        (SELECT DATE_FORMAT(s.scheduled_end, '%Y-%m-%d %H:%i') FROM schedules s WHERE s.appointment_id = a.appointment_id ORDER BY COALESCE(s.scheduled_start, s.created_at) DESC LIMIT 1) AS schedule_end
    FROM appointments a
    INNER JOIN patients p ON a.patient_id = p.patient_id
    -- timeslot removed; appointment_time not available
    WHERE a.user_id = ?
    ORDER BY a.appointment_date DESC, a.created_at DESC
    LIMIT 20
";

$stmt = $conn->prepare($all_sql);
if ($stmt) {
    $stmt->bind_param('i', $doctor_id);
    $stmt->execute();
    $result = $stmt->get_result();
    while ($row = $result->fetch_assoc()) {
        $allAppointments[] = $row;
    }
    $stmt->close();
}

// Fetch services for the dropdown
$services = [];
$stmt = $conn->query('SELECT service_id, service_name, initial_price FROM services WHERE is_active = 1 ORDER BY service_name');
if ($stmt) {
    while ($row = $stmt->fetch_assoc()) {
        $services[] = $row;
    }
}

// Fetch treatments
$treatments = [];
$stmt = $conn->query('SELECT treatment_id, treatment_name FROM treatments ORDER BY treatment_name');
if ($stmt) {
    while ($row = $stmt->fetch_assoc()) {
        $treatments[] = $row;
    }
}

// Fetch tooth data
$teeth = [];
$stmt = $conn->query('SELECT tooth_id, tooth_number, upper_lower FROM tooth ORDER BY tooth_number');
if ($stmt) {
    while ($row = $stmt->fetch_assoc()) {
        $teeth[] = $row;
    }
}
?>

<!-- Alert Messages -->
<?php if (isset($_SESSION['message'])): ?>
    <div class="alert alert-<?php echo $_SESSION['message_type']; ?> alert-dismissible fade show" role="alert">
        <?php echo $_SESSION['message']; ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
    </div>
    <?php unset($_SESSION['message']); unset($_SESSION['message_type']); ?>
<?php endif; ?>

<!-- Recent Appointments (full width on appointments page) -->
<div class="row">
    <div class="col-lg-12">
        <div class="card border-0">
            <div class="card-header">
                <h2 class="card-title mb-0">
                    <i class="bi bi-calendar-check"></i> Recent Appointments
                </h2>
            </div>
            <div class="card-body">
                <div class="table-responsive">
                    <table class="table table-sm">
                        <thead>
                            <tr>
                                <th>Patient</th>
                                <th>Date</th>
                                <th>Status</th>
                                <th>Schedule</th>
                                <th>Record</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($allAppointments as $appt): ?>
                                <tr>
                                    <td><?php echo htmlspecialchars($appt['first_name'] . ' ' . $appt['last_name']); ?></td>
                                    <td><?php echo htmlspecialchars($appt['appointment_date']); ?></td>
                                    <td>
                                        <span class="badge bg-<?php echo $appt['status'] === 'completed' ? 'success' : ($appt['status'] === 'cancelled' ? 'danger' : 'warning'); ?>">
                                            <?php echo htmlspecialchars(ucfirst($appt['status'])); ?>
                                        </span>
                                    </td>
                                    <td>
                                        <?php if (!empty($appt['schedule_status'])): ?>
                                            <span class="badge bg-<?php echo ($appt['schedule_status'] === 'scheduled' ? 'primary' : ($appt['schedule_status'] === 'completed' ? 'success' : 'secondary')); ?>">
                                                <?php echo ($appt['schedule_status'] === 'scheduled') ? 'Followup' : htmlspecialchars(ucfirst($appt['schedule_status'])); ?>
                                            </span>
                                            <div class="small text-muted"><?php echo htmlspecialchars($appt['schedule_start'] ?? ''); ?> <?php echo $appt['schedule_end'] ? '&ndash; ' . htmlspecialchars($appt['schedule_end']) : ''; ?></div>
                                        <?php else: ?>
                                            <span class="text-muted small">No schedule</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php if (!empty($appt['has_history'])): ?>
                                            <button class="btn btn-sm btn-outline-secondary" onclick="openHistoryModal(<?php echo (int)$appt['appointment_id']; ?>)">
                                                <i class="bi bi-file-earmark-text"></i> View
                                            </button>
                                        <?php else: ?>
                                            <span class="text-muted small">No record</span>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</div>

            <!-- History Modal (read-only) -->
            <div class="modal fade" id="historyModal" tabindex="-1" aria-hidden="true">
                <div class="modal-dialog modal-xl">
                    <div class="modal-content">
                        <div class="modal-header">
                            <h5 class="modal-title">Appointment History</h5>
                            <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                        </div>
                        <div class="modal-body" style="max-height:75vh; overflow:auto;">
                            <div id="historyContent">
                                <div class="mb-3"><strong>Patient:</strong> <span id="histPatientName"></span></div>
                                <div class="mb-3"><strong>Appointment Date:</strong> <span id="histApptDate"></span></div>

                                <div class="mb-3" id="histProfilingWrap">
                                    <h6>Profiling / EHR Overview</h6>
                                    <div class="row">
                                        <div class="col-md-6"><strong>Medical History:</strong> <div id="histMedicalHistory" class="small text-muted"></div></div>
                                        <div class="col-md-6"><strong>Medications:</strong> <div id="histMedications" class="small text-muted"></div></div>
                                        <div class="col-md-4"><strong>Allergies:</strong> <div id="histAllergies" class="small text-muted"></div></div>
                                        <div class="col-md-4"><strong>Smoking:</strong> <div id="histSmoking" class="small text-muted"></div></div>
                                        <div class="col-md-4"><strong>Alcohol:</strong> <div id="histAlcohol" class="small text-muted"></div></div>
                                        <div class="col-md-4"><strong>ASA:</strong> <div id="histASA" class="small text-muted"></div></div>
                                        <div class="col-md-4"><strong>Last Visit:</strong> <div id="histLastVisit" class="small text-muted"></div></div>
                                        <div class="col-12"><strong>Periodontal / Oral Exam:</strong> <div id="histPeriodontal" class="small text-muted"></div></div>
                                        <div class="col-12"><strong>Dental Chart / Findings:</strong> <div id="histDentalChart" class="small text-muted"></div></div>
                                        <div class="col-12"><strong>Radiographs / Imaging Notes:</strong> <div id="histRadiographs" class="small text-muted"></div></div>
                                    </div>
                                </div>

                                <div class="mb-3"><strong>Clinical Notes:</strong>
                                    <pre id="histNotes" style="white-space:pre-wrap;background:#f8f9fa;padding:10px;border-radius:4px;"></pre>
                                </div>
                                <div class="mb-3"><strong>Recommended Services:</strong>
                                    <ul id="histServices"></ul>
                                </div>
                                <div class="mb-3"><strong>Treatment Plan:</strong>
                                    <pre id="histTreatment" style="white-space:pre-wrap;background:#f8f9fa;padding:10px;border-radius:4px;"></pre>
                                </div>
                                <div class="mb-3"><strong>Materials Used:</strong>
                                    <pre id="histMaterials" style="white-space:pre-wrap;background:#f8f9fa;padding:10px;border-radius:4px;"></pre>
                                </div>
                                <div class="mb-3"><strong>Images:</strong>
                                    <div id="histImages" class="d-flex flex-wrap gap-2"></div>
                                </div>

                                <div class="mb-3"><strong>Related Schedules</strong>
                                    <div id="histSchedules" class="list-group small"></div>
                                </div>
                                <div class="mb-3">
                                    <label class="form-label"><strong>Odontogram (read-only)</strong></label>
                                    <div id="histOdontogramWrap" style="border:1px solid #ddd; height:420px;">
                                        <iframe id="histOdontoFrame" src="../odontogram/index.html" style="width:100%;height:100%;border:0;" ></iframe>
                                    </div>
                                </div>
                            </div>
                        </div>
                        <div class="modal-footer">
                            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                        </div>
                    </div>
                </div>
            </div>

<!-- EHR Modal -->
<div class="modal fade" id="ehrModal" tabindex="-1" role="dialog" aria-hidden="true">
    <div class="modal-dialog modal-lg" role="document">
        <div class="modal-content">
            <div class="modal-header bg-primary text-white">
                <h5 class="modal-title">
                    <i class="bi bi-file-earmark-medical"></i> Patient EHR & Clinical Notes
                </h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <form method="POST" action="doctor.php?page=appointments&action=complete">
                <div class="modal-body" style="max-height: 70vh; overflow-y: auto;">
                    <input type="hidden" name="appointment_id" id="modalAppointmentId">
                    <input type="hidden" name="patient_id" id="modalPatientId">
                    
                    <!-- Patient Info -->
                    <div class="mb-3 p-3 bg-light rounded">
                        <h6 class="mb-2"><i class="bi bi-person"></i> Patient Information</h6>
                        <p class="mb-0"><strong id="modalPatientName"></strong></p>
                        <p class="mb-0 small text-muted"><span id="modalPatientPhone"></span> | <span id="modalPatientEmail"></span></p>
                    </div>
                    
                    <!-- Odontogram -->
                    <div class="mb-3">
                        <label class="form-label"><i class="bi bi-tooth"></i> <strong>Odontogram (Tooth Chart)</strong></label>
                        <div class="card p-3">
                            <div class="row mb-2">
                                <div class="col-12">
                                    <strong>Upper Teeth</strong>
                                    <div class="d-flex flex-wrap gap-2 mb-3">
                                        <?php foreach ($teeth as $tooth): 
                                            if ($tooth['upper_lower'] == 1): // Upper teeth
                                        ?>
                                            <div class="tooth-selector">
                                                <select class="form-select form-select-sm tooth-status-select" 
                                                        data-tooth-id="<?php echo $tooth['tooth_id']; ?>"
                                                        name="tooth_data[<?php echo $tooth['tooth_id']; ?>]">
                                                    <option value="">Tooth <?php echo htmlspecialchars($tooth['tooth_number']); ?></option>
                                                    <option value="healthy">Healthy</option>
                                                    <option value="decayed">Decayed</option>
                                                    <option value="filled">Filled</option>
                                                    <option value="crowned">Crowned</option>
                                                    <option value="root_canal">Root Canal</option>
                                                    <option value="missing">Missing</option>
                                                </select>
                                            </div>
                                        <?php endif; endforeach; ?>
                                    </div>
                                </div>
                            </div>
                            <div class="row">
                                <div class="col-12">
                                    <strong>Lower Teeth</strong>
                                    <div class="d-flex flex-wrap gap-2">
                                        <?php foreach ($teeth as $tooth): 
                                            if ($tooth['upper_lower'] == 0): // Lower teeth
                                        ?>
                                            <div class="tooth-selector">
                                                <select class="form-select form-select-sm tooth-status-select" 
                                                        data-tooth-id="<?php echo $tooth['tooth_id']; ?>"
                                                        name="tooth_data[<?php echo $tooth['tooth_id']; ?>]">
                                                    <option value="">Tooth <?php echo htmlspecialchars($tooth['tooth_number']); ?></option>
                                                    <option value="healthy">Healthy</option>
                                                    <option value="decayed">Decayed</option>
                                                    <option value="filled">Filled</option>
                                                    <option value="crowned">Crowned</option>
                                                    <option value="root_canal">Root Canal</option>
                                                    <option value="missing">Missing</option>
                                                </select>
                                            </div>
                                        <?php endif; endforeach; ?>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Treatments -->
                    <div class="mb-3">
                        <label class="form-label"><i class="bi bi-bandaid"></i> <strong>Treatments Recommended</strong></label>
                        <div class="card p-3">
                            <?php foreach ($treatments as $treatment): ?>
                                <div class="form-check">
                                    <input type="checkbox" class="form-check-input treatment-checkbox" 
                                           id="treatment_<?php echo $treatment['treatment_id']; ?>"
                                           name="treatment_ids[]" value="<?php echo $treatment['treatment_id']; ?>">
                                    <label class="form-check-label" for="treatment_<?php echo $treatment['treatment_id']; ?>">
                                        <?php echo htmlspecialchars($treatment['treatment_name']); ?>
                                    </label>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                    
                    <!-- Service for Consultation -->
                    <div class="mb-3">
                        <label for="serviceSelect" class="form-label"><i class="bi bi-cash-coin"></i> <strong>Service/Consultation Type</strong></label>
                        <select class="form-select" id="serviceSelect" name="service_id" required>
                            <option value="">-- Select Service --</option>
                            <?php foreach ($services as $service): ?>
                                <option value="<?php echo $service['service_id']; ?>">
                                    <?php echo htmlspecialchars($service['service_name']); ?> (₱<?php echo number_format($service['initial_price'], 2); ?>)
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <small class="text-muted">This will be the consultation fee. Additional treatment charges can be added later in billing.</small>
                    </div>
                    
                    <!-- Clinical Notes -->
                    <div class="mb-3">
                        <label for="clinicalNotes" class="form-label"><i class="bi bi-file-text"></i> <strong>Clinical Notes</strong></label>
                        <textarea class="form-control" id="clinicalNotes" name="notes" rows="4" placeholder="Enter clinical observations, findings, recommendations, etc."></textarea>
                    </div>
                    
                    <!-- Follow-up Appointment -->
                    <div class="mb-3">
                        <label for="followUpDate" class="form-label"><i class="bi bi-calendar-plus"></i> <strong>Schedule Follow-up Appointment (Optional)</strong></label>
                        <input type="date" class="form-control" id="followUpDate" name="follow_up_date">
                        <small class="text-muted">Patient can confirm this during billing phase.</small>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-success">
                        <i class="bi bi-check-circle"></i> Complete Visit & Send to Billing
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<style>
.tooth-selector {
    min-width: 120px;
}

.tooth-selector select {
    font-size: 0.85rem;
}
</style>

<script>
function loadPatientEHR(patient) {
    document.getElementById('modalAppointmentId').value = patient.appointment_id;
    document.getElementById('modalPatientId').value = patient.patient_id;
    document.getElementById('modalPatientName').textContent = patient.first_name + ' ' + patient.last_name;
    document.getElementById('modalPatientPhone').textContent = patient.phone_number;
    document.getElementById('modalPatientEmail').textContent = patient.email;
    
    // Clear previous selections
    document.querySelectorAll('.tooth-status-select').forEach(select => select.value = '');
    document.querySelectorAll('.treatment-checkbox').forEach(check => check.checked = false);
    document.getElementById('serviceSelect').value = '';
    document.getElementById('clinicalNotes').value = '';
    document.getElementById('followUpDate').value = '';
}

// Service map for resolving service IDs to names
const serviceMap = {
    <?php foreach ($services as $s): ?>
        "<?php echo $s['service_id']; ?>": "<?php echo htmlspecialchars(addslashes($s['service_name'])); ?>",
    <?php endforeach; ?>
};

// Current session role/user for client-side role checks
const CURRENT_ROLE = '<?php echo $_SESSION['role'] ?? ''; ?>';
const CURRENT_USER = <?php echo (int)($_SESSION['user_id'] ?? 0); ?>;

// If page requested to show history on load, trigger modal
<?php if (!empty($_GET['show_history']) && !empty($_GET['appointment_id'])): ?>
document.addEventListener('DOMContentLoaded', function(){
    try { openHistoryModal(<?php echo (int)$_GET['appointment_id']; ?>); } catch(e){}
});
<?php endif; ?>

// Open history modal and fetch clinical record (read-only)
async function openHistoryModal(appointmentId) {
    const modalEl = document.getElementById('historyModal');
    const bs = new bootstrap.Modal(modalEl);
    // clear
    document.getElementById('histPatientName').textContent = '';
    document.getElementById('histApptDate').textContent = '';
    document.getElementById('histNotes').textContent = '';
    document.getElementById('histServices').innerHTML = '';
    document.getElementById('histTreatment').textContent = '';
    document.getElementById('histMaterials').textContent = '';

        try {
            const res = await fetch('../assets/api/ehr/get_records_by_appointment_detailed.php?appointment_id=' + encodeURIComponent(appointmentId));
            const j = await res.json();
            if (!j.success) {
                alert('Could not load history: ' + (j.message || ''));
                return;
            }
            const rec = j.record;
            const schedules = j.schedules || [];
        if (!rec) {
            alert('No clinical record found for this appointment.');
            return;
        }
        // show patient+date
        // fetch patient name from DOM or server: we'll try to pull patient via appointment list row
        document.getElementById('histPatientName').textContent = rec._patient_name || ('Patient ID: ' + (rec.patient_id || ''));
        document.getElementById('histApptDate').textContent = rec._appointment_date || rec.follow_up_date || (rec.submitted_at ?? '');
        document.getElementById('histNotes').textContent = rec.doctor_notes || '';

        // profiling: prefer clinical-record-level `profiling` then patient-level `patient_profiling`
        const pf = rec.profiling || rec.patient_profiling || null;
        if (pf) {
            try {
                const p = (typeof pf === 'string') ? JSON.parse(pf) : pf;
                document.getElementById('histMedicalHistory').textContent = p.medical_history || '';
                document.getElementById('histMedications').textContent = p.medications || '';
                document.getElementById('histAllergies').textContent = p.allergies || '';
                document.getElementById('histSmoking').textContent = p.smoking || '';
                document.getElementById('histAlcohol').textContent = p.alcohol || '';
                document.getElementById('histASA').textContent = p.asa || '';
                document.getElementById('histLastVisit').textContent = p.last_visit || '';
                document.getElementById('histPeriodontal').textContent = p.periodontal || '';
                document.getElementById('histDentalChart').textContent = p.dental_chart || '';
            } catch (e) { console.warn('parse profiling', e); }
        } else {
            // clear
            ['histMedicalHistory','histMedications','histAllergies','histSmoking','histAlcohol','histASA','histLastVisit','histPeriodontal','histDentalChart'].forEach(id => document.getElementById(id).textContent = '');
        }

        // radiographs notes
        document.getElementById('histRadiographs').textContent = rec.radiographs_notes || '';

        // services
        const ul = document.getElementById('histServices');
        if (Array.isArray(rec.recommended_services)) {
            rec.recommended_services.forEach(sid => {
                const li = document.createElement('li');
                const name = (sid in serviceMap) ? serviceMap[sid] : ('Service ID: ' + sid);
                li.textContent = name;
                ul.appendChild(li);
            });
        }

        // treatment plan and materials
        document.getElementById('histTreatment').textContent = JSON.stringify(rec.treatment_plan || {}, null, 2);
        document.getElementById('histMaterials').textContent = JSON.stringify(rec.materials_used || {}, null, 2);

        // images
        const imgWrap = document.getElementById('histImages');
        imgWrap.innerHTML = '';
        if (Array.isArray(rec.images) && rec.images.length) {
            rec.images.forEach(src => {
                const a = document.createElement('a');
                a.href = src;
                a.target = '_blank';
                const img = document.createElement('img');
                img.src = src;
                img.style.maxWidth = '150px';
                img.style.maxHeight = '120px';
                img.className = 'img-thumbnail';
                a.appendChild(img);
                imgWrap.appendChild(a);
            });
        }

        // odontogram: send to iframe via postMessage if present
        try {
            const odf = document.getElementById('histOdontoFrame');
            if (rec.odontogram && odf && odf.contentWindow) {
                odf.contentWindow.postMessage({ type: 'load_odontogram', toothState: rec.odontogram, readonly: true }, '*');
            }
        } catch (e) {}

        // schedules: list all related schedules with expand/collapse and actions
        try {
            const wrap = document.getElementById('histSchedules');
            wrap.innerHTML = '';
            if (Array.isArray(schedules) && schedules.length) {
                schedules.forEach(s => {
                    const id = s.schedule_id || Math.random().toString(36).substr(2,6);
                    const start = s.scheduled_start ? s.scheduled_start : '(no start)';
                    const end = s.scheduled_end ? (' — ' + s.scheduled_end) : '';

                    const item = document.createElement('div');
                    item.className = 'list-group-item';

                    const header = document.createElement('div');
                    header.className = 'd-flex w-100 justify-content-between align-items-center';
                    header.innerHTML = `<div><strong>${(s.status||'').toUpperCase()}</strong> <span class="text-muted">${start}${end}</span></div>`;

                    const toggleBtn = document.createElement('a');
                    toggleBtn.setAttribute('data-bs-toggle','collapse');
                    toggleBtn.href = '#sch-details-' + id;
                    toggleBtn.className = 'btn btn-sm btn-outline-secondary';
                    toggleBtn.textContent = 'Details';
                    header.appendChild(toggleBtn);
                    item.appendChild(header);

                    const details = document.createElement('div');
                    details.className = 'collapse mt-2';
                    details.id = 'sch-details-' + id;

                    let inner = `<div class="small text-muted">Created: ${s.created_at || 'N/A'}</div>`;
                    if (s.priority) inner += `<div class="small text-muted">Priority: ${s.priority}</div>`;
                    inner += `<div class="pt-2">Schedule notes: ${s.notes || ''}</div>`;

                    // actions container
                    inner += '<div class="mt-2">';

                    // Mark Arrived - only secretary/admin allowed (server enforces too)
                    if (CURRENT_ROLE === 'secretary' || CURRENT_ROLE === 'admin') {
                        inner += `<button class="btn btn-sm btn-success me-1" data-action="arrive" data-sid="${s.schedule_id}">Mark Arrived</button>`;
                    }

                    // Cancel - admin/secretary can cancel any; doctors can cancel their own (server enforces)
                    if (['admin','secretary','doctor'].includes(CURRENT_ROLE)) {
                        inner += `<button class="btn btn-sm btn-danger me-1" data-action="cancel" data-sid="${s.schedule_id}">Cancel</button>`;
                    }

                    // Reschedule - show if admin/secretary or doctor-owner
                    if (CURRENT_ROLE === 'admin' || CURRENT_ROLE === 'secretary' || (CURRENT_ROLE === 'doctor' && s.user_id == CURRENT_USER)) {
                        inner += `<button class="btn btn-sm btn-warning me-1" data-action="reschedule" data-sid="${s.schedule_id}">Reschedule</button>`;
                    }

                    inner += '</div>';
                    details.innerHTML = inner;
                    item.appendChild(details);

                    // wire event delegation for the buttons
                    item.addEventListener('click', async function(e){
                        const btn = e.target.closest('button[data-action]');
                        if (!btn) return;
                        const act = btn.getAttribute('data-action');
                        const sid = btn.getAttribute('data-sid');
                        if (act === 'arrive') {
                            btn.disabled = true;
                            try {
                                const fd = new FormData(); fd.append('schedule_id', sid);
                                const r = await fetch('../assets/api/appointments/mark_schedule_arrived.php', { method: 'POST', body: fd });
                                const j = await r.json();
                                if (j.success) {
                                    btn.textContent = 'Arrived';
                                    btn.classList.remove('btn-success');
                                    btn.classList.add('btn-secondary');
                                } else alert(j.message || 'Failed');
                            } catch (e) { alert('Error'); }
                            btn.disabled = false;
                        } else if (act === 'cancel') {
                            if (!confirm('Cancel this schedule?')) return;
                            btn.disabled = true;
                            try {
                                const fd = new FormData(); fd.append('schedule_id', sid);
                                const r = await fetch('../assets/api/appointments/cancel_schedule.php', { method: 'POST', body: fd });
                                const j = await r.json();
                                if (j.success) {
                                    // update header status
                                    header.querySelector('strong').textContent = 'CANCELLED';
                                } else alert(j.message || 'Failed');
                            } catch (e) { alert('Error'); }
                            btn.disabled = false;
                        } else if (act === 'reschedule') {
                            openRescheduleModal(s);
                        }
                    });

                    wrap.appendChild(item);
                });
            } else {
                wrap.innerHTML = '<div class="text-muted small">No schedules found for this appointment.</div>';
            }
        } catch (e) { console.warn('render schedules', e); }

        bs.show();
    } catch (e) {
        console.error(e);
        alert('Error loading history');
    }
}
</script>

<!-- Reschedule Modal -->
<!-- flatpickr CSS/JS -->
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/flatpickr/dist/flatpickr.min.css">
<script src="https://cdn.jsdelivr.net/npm/flatpickr"></script>
<div class="modal fade" id="rescheduleModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Reschedule</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <input type="hidden" id="rescheduleScheduleId" />
                <div class="mb-2"><label class="form-label">Start</label><input id="rescheduleStart" class="form-control" type="datetime-local"/></div>
                <div class="mb-2"><label class="form-label">End (optional)</label><input id="rescheduleEnd" class="form-control" type="datetime-local"/></div>
                <div class="mb-2"><button id="rescheduleSuggest" class="btn btn-sm btn-outline-primary">Suggest slots</button></div>
                <div id="rescheduleSuggestions" class="list-group"></div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                <button id="rescheduleSaveBtn" type="button" class="btn btn-primary">Save</button>
            </div>
        </div>
    </div>
</div>

<script>
const resModal = new bootstrap.Modal(document.getElementById('rescheduleModal'));
// initialize flatpickr on modal inputs
let fpStart = null, fpEnd = null;
function initReschedulePickers() {
    try {
        if (typeof flatpickr !== 'undefined') {
            fpStart = flatpickr('#rescheduleStart', { enableTime: true, dateFormat: 'Y-m-d H:i', time_24hr: true });
            fpEnd = flatpickr('#rescheduleEnd', { enableTime: true, dateFormat: 'Y-m-d H:i', time_24hr: true });
        }
    } catch (e) { console.warn('flatpickr init', e); }
}
initReschedulePickers();
function toLocalInput(dtStr) {
    if (!dtStr) return '';
    // dtStr expected 'YYYY-MM-DD HH:MM:SS' or 'YYYY-MM-DD HH:MM'
    const s = dtStr.replace(' ', 'T').replace(/:00$/,'');
    return s;
}
function toServerFormat(localVal) {
    if (!localVal) return '';
    // localVal like '2026-02-25T09:30'
    return localVal.replace('T',' ');
}

async function openRescheduleModal(schedule) {
    document.getElementById('rescheduleScheduleId').value = schedule.schedule_id || '';
    document.getElementById('rescheduleStart').value = toLocalInput(schedule.scheduled_start || '');
    document.getElementById('rescheduleEnd').value = toLocalInput(schedule.scheduled_end || '');
    document.getElementById('rescheduleSuggestions').innerHTML = '';
    resModal.show();
    // attach suggest handler
}

document.getElementById('rescheduleSuggest').addEventListener('click', async function(){
    const sid = document.getElementById('rescheduleScheduleId').value;
    if (!sid) return alert('No schedule selected');
    // attempt to find schedule owner by querying schedules endpoint (we have no dedicated get-schedule api); reuse smart_schedule with user_id
    // We'll derive user_id from the DOM by searching rendered schedule item if possible
    const btn = this; btn.disabled = true;
    try {
        // fetch schedule info for accurate user_id / duration
        const g = await fetch('../assets/api/appointments/get_schedule.php?schedule_id=' + encodeURIComponent(sid));
        const gs = await g.json();
        if (!gs.success) return alert(gs.message || 'Could not resolve schedule');
        const user_id = gs.schedule.user_id || CURRENT_USER;
        const duration = gs.schedule.duration || 30;
        // Post to smart_schedule
        const fd = new FormData(); fd.append('user_id', user_id); fd.append('days_ahead', 14); fd.append('duration', duration);
        const res = await fetch('../assets/api/appointments/smart_schedule.php', { method: 'POST', body: fd });
        const j = await res.json();
        const wrap = document.getElementById('rescheduleSuggestions'); wrap.innerHTML = '';
        if (!j.success) return alert(j.message || 'No suggestions');
            (j.slots || []).slice(0,10).forEach(slot => {
            const a = document.createElement('a'); a.href = '#'; a.className = 'list-group-item list-group-item-action';
            a.textContent = slot.start + (slot.end ? (' — ' + slot.end) : '');
            a.addEventListener('click', function(e){
                e.preventDefault();
                const startLocal = slot.start.replace(' ', 'T').replace(/:00$/,'');
                const endLocal = slot.end ? slot.end.replace(' ', 'T').replace(/:00$/,'') : '';
                if (fpStart && typeof fpStart.setDate === 'function') fpStart.setDate(startLocal);
                else document.getElementById('rescheduleStart').value = startLocal;
                if (endLocal) {
                  if (fpEnd && typeof fpEnd.setDate === 'function') fpEnd.setDate(endLocal);
                  else document.getElementById('rescheduleEnd').value = endLocal;
                }
            });
            wrap.appendChild(a);
        });
    } catch (e) { console.error(e); alert('Error fetching suggestions'); }
    btn.disabled = false;
});

document.getElementById('rescheduleSaveBtn').addEventListener('click', async function(){
    const sid = document.getElementById('rescheduleScheduleId').value;
    const start = toServerFormat(document.getElementById('rescheduleStart').value);
    const end = toServerFormat(document.getElementById('rescheduleEnd').value);
    if (!sid || !start) return alert('Start datetime required');
    this.disabled = true;
    try {
        const fd = new FormData(); fd.append('schedule_id', sid); fd.append('scheduled_start', start); fd.append('scheduled_end', end);
        const res = await fetch('../assets/api/appointments/reschedule_schedule.php', { method: 'POST', body: fd });
        const j = await res.json();
        if (!j.success) return alert(j.message || 'Failed to reschedule');
        alert('Rescheduled');
        resModal.hide();
        // reload page or update UI: simple approach reload
        location.reload();
    } catch (e) { console.error(e); alert('Error'); }
    this.disabled = false;
});
</script>
