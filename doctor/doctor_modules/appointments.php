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
            
            // Update queue status to served/completed
            $queueStatus = 'served';
            $stmt = $conn->prepare('UPDATE check_in_queue SET status = ?, served_time = NOW(), completed_time = NOW() WHERE appointment_id = ?');
            if ($stmt) {
                $stmt->bind_param('si', $queueStatus, $appointmentId);
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
            
            // Insert billing record (consultation fee only)
            $billingAmount = $servicePrice;
            $billingStatus = 'unpaid';
            $stmt = $conn->prepare('
                INSERT INTO billing (appointment_id, user_id, total_amount, status, created_at)
                VALUES (?, ?, ?, ?, NOW())
            ');
            if ($stmt) {
                $stmt->bind_param('iids', $appointmentId, $patientId, $billingAmount, $billingStatus);
                $stmt->execute();
                $stmt->close();
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
    
    // Handle CALL patient (mark queue as called)
    if ($action === 'call' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $appointmentId = (int)($_POST['appointment_id'] ?? 0);
        if ($appointmentId <= 0) {
            $_SESSION['message'] = 'Invalid appointment.';
            $_SESSION['message_type'] = 'warning';
            header('Location: doctor.php?page=appointments');
            exit;
        }

        try {
            $stmt = $conn->prepare('UPDATE check_in_queue q INNER JOIN appointments a ON q.appointment_id = a.appointment_id SET q.status = ?, q.called_time = NOW() WHERE q.appointment_id = ? AND a.user_id = ?');
            if ($stmt) {
                $status = 'called';
                $stmt->bind_param('sii', $status, $appointmentId, $doctor_id);
                $stmt->execute();
                $stmt->close();
            }

            $_SESSION['message'] = 'Patient called.';
            $_SESSION['message_type'] = 'success';
        } catch (Throwable $e) {
            $_SESSION['message'] = 'Error calling patient: ' . $e->getMessage();
            $_SESSION['message_type'] = 'danger';
        }

        header('Location: doctor.php?page=appointments');
        exit;
    }

    // Handle ACCEPT patient (start visit) - set queue status in_progress and appointment status
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
            $queueStatus = 'in_progress';
            $stmt = $conn->prepare('UPDATE check_in_queue q INNER JOIN appointments a ON q.appointment_id = a.appointment_id SET q.status = ?, q.served_time = NOW() WHERE q.appointment_id = ? AND a.user_id = ?');
            if ($stmt) {
                $stmt->bind_param('sii', $queueStatus, $appointmentId, $doctor_id);
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

// Fetch queue - only checked-in patients for today
$queue = [];
$queue_sql = "
    SELECT
        a.appointment_id,
        a.patient_id,
        a.reason,
        a.status AS appointment_status,
        p.first_name,
        p.last_name,
        p.email,
        p.phone_number,
        NULL as appointment_time,
        q.checkin_id,
        q.que_number,
        q.checkin_time,
        q.status AS queue_status
    FROM check_in_queue q
    INNER JOIN appointments a ON q.appointment_id = a.appointment_id
    INNER JOIN patients p ON a.patient_id = p.patient_id
    -- timeslot removed; appointment_time not available
    WHERE a.user_id = ?
    AND a.appointment_date = ?
    AND q.status IN ('waiting', 'called')
    ORDER BY q.que_number ASC
";

$stmt = $conn->prepare($queue_sql);
if ($stmt) {
    $stmt->bind_param('is', $doctor_id, $today);
    $stmt->execute();
    $result = $stmt->get_result();
    while ($row = $result->fetch_assoc()) {
        $queue[] = $row;
    }
    $stmt->close();
}

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
        NULL as appointment_time
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
        <div class="card">
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
                                <th>Time</th>
                                <th>Status</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($allAppointments as $appt): ?>
                                <tr>
                                    <td><?php echo htmlspecialchars($appt['first_name'] . ' ' . $appt['last_name']); ?></td>
                                    <td><?php echo htmlspecialchars($appt['appointment_date']); ?></td>
                                    <td><?php echo htmlspecialchars($appt['appointment_time']); ?></td>
                                    <td>
                                        <span class="badge bg-<?php echo $appt['status'] === 'completed' ? 'success' : ($appt['status'] === 'cancelled' ? 'danger' : 'warning'); ?>">
                                            <?php echo htmlspecialchars(ucfirst($appt['status'])); ?>
                                        </span>
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
</script>
