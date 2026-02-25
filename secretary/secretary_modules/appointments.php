<?php
include dirname(dirname(__DIR__)) . '/config/database.php';

$action = $_GET['action'] ?? '';
$view = $_GET['view'] ?? 'all'; // 'all' | 'today'
$search = $_GET['search'] ?? '';

$today = date('Y-m-d');
if ($view !== 'all' && $view !== 'today') {
    $view = 'all';
}

// Handle CHECK-IN (accept) for today's appointment
if ($action === 'checkin' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $appointmentId = (int)($_POST['appointment_id'] ?? 0);

    if ($appointmentId <= 0) {
        $_SESSION['message'] = 'Invalid appointment.';
        $_SESSION['message_type'] = 'warning';
        header('Location: secretary.php?page=appointments&view=' . urlencode($view));
        exit;
    }

    $conn->begin_transaction();
    try {
        // Verify appointment exists and is for today
        $stmt = $conn->prepare('SELECT appointment_date, status FROM appointments WHERE appointment_id = ?');
        if (!$stmt) {
            throw new Exception('Error preparing appointment lookup.');
        }
        $stmt->bind_param('i', $appointmentId);
        $stmt->execute();
        $res = $stmt->get_result();
        $appt = $res ? $res->fetch_assoc() : null;
        $stmt->close();

        if (!$appt) {
            throw new Exception('Appointment not found.');
        }

        $apptDateRaw = $appt['appointment_date'] ?? '';
        $apptDateOnly = $apptDateRaw ? date('Y-m-d', strtotime($apptDateRaw)) : '';
        if ($apptDateOnly !== $today) {
            throw new Exception('You can only check-in appointments scheduled for today.');
        }

        // Already checked-in?
        $stmt = $conn->prepare('SELECT checkin_id FROM check_in_queue WHERE appointment_id = ?');
        if (!$stmt) {
            throw new Exception('Error preparing check-in lookup.');
        }
        $stmt->bind_param('i', $appointmentId);
        $stmt->execute();
        $res = $stmt->get_result();
        $existing = $res ? $res->fetch_assoc() : null;
        $stmt->close();

        if ($existing && !empty($existing['checkin_id'])) {
            $_SESSION['message'] = 'This appointment is already checked-in.';
            $_SESSION['message_type'] = 'info';
            $conn->commit();
            header('Location: secretary.php?page=appointments&view=' . urlencode($view));
            exit;
        }

        // Next queue number for today
        $nextQueueNo = 1;
        $stmt = $conn->prepare('SELECT COALESCE(MAX(que_number), 0) + 1 AS next_no FROM check_in_queue WHERE DATE(checkin_time) = ?');
        if ($stmt) {
            $stmt->bind_param('s', $today);
            $stmt->execute();
            $res = $stmt->get_result();
            if ($res && ($row = $res->fetch_assoc())) {
                $nextQueueNo = (int)($row['next_no'] ?? 1);
                if ($nextQueueNo <= 0) $nextQueueNo = 1;
            }
            $stmt->close();
        }

        $status = 'waiting';
        $stmt = $conn->prepare('INSERT INTO check_in_queue (status, que_number, appointment_id) VALUES (?, ?, ?)');
        if (!$stmt) {
            throw new Exception('Error preparing check-in insert.');
        }
        $stmt->bind_param('sii', $status, $nextQueueNo, $appointmentId);
        if (!$stmt->execute()) {
            throw new Exception('Error creating check-in record: ' . $stmt->error);
        }
        $stmt->close();

        // Keep appointment status in sync
        $newApptStatus = 'checked_in';
        $stmt = $conn->prepare('UPDATE appointments SET status = ? WHERE appointment_id = ?');
        if ($stmt) {
            $stmt->bind_param('si', $newApptStatus, $appointmentId);
            $stmt->execute();
            $stmt->close();
        }

        $conn->commit();

        $_SESSION['message'] = 'Patient accepted and checked-in (Queue #' . $nextQueueNo . ').';
        $_SESSION['message_type'] = 'success';
        // Prepare print ticket for secretary to print the queue number
        $_SESSION['print_ticket'] = [
            'appointment_id' => $appointmentId,
            'queue_number' => $nextQueueNo
        ];
    } catch (Throwable $e) {
        $conn->rollback();
        $_SESSION['message'] = $e->getMessage();
        $_SESSION['message_type'] = 'danger';
    }

    header('Location: secretary.php?page=appointments&view=' . urlencode($view));
    exit;
}

// Handle DECLINE (cancel appointment)
if ($action === 'decline' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $appointmentId = (int)($_POST['appointment_id'] ?? 0);
    $reason = $_POST['decline_reason'] ?? 'Declined by secretary';
    
    if ($appointmentId <= 0) {
        $_SESSION['message'] = 'Invalid appointment.';
        $_SESSION['message_type'] = 'warning';
        header('Location: secretary.php?page=appointments&view=' . urlencode($view));
        exit;
    }
    
    try {
        $status = 'cancelled';
        $stmt = $conn->prepare('UPDATE appointments SET status = ?, notes = ? WHERE appointment_id = ?');
        if ($stmt) {
            $stmt->bind_param('ssi', $status, $reason, $appointmentId);
            $stmt->execute();
            $stmt->close();
        }
        
        $_SESSION['message'] = 'Appointment declined and cancelled.';
        $_SESSION['message_type'] = 'success';
    } catch (Exception $e) {
        $_SESSION['message'] = 'Error declining appointment: ' . $e->getMessage();
        $_SESSION['message_type'] = 'danger';
    }
    
    header('Location: secretary.php?page=appointments&view=' . urlencode($view));
    exit;
}

// Handle CANCEL (remove from queue if already checked in)
if ($action === 'cancel' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $appointmentId = (int)($_POST['appointment_id'] ?? 0);
    
    if ($appointmentId <= 0) {
        $_SESSION['message'] = 'Invalid appointment.';
        $_SESSION['message_type'] = 'warning';
        header('Location: secretary.php?page=appointments&view=' . urlencode($view));
        exit;
    }
    
    $conn->begin_transaction();
    try {
        // Remove from queue if exists
        $stmt = $conn->prepare('DELETE FROM check_in_queue WHERE appointment_id = ?');
        if ($stmt) {
            $stmt->execute([$appointmentId]);
            $stmt->close();
        }
        
        // Reset appointment status back to scheduled
        $status = 'scheduled';
        $stmt = $conn->prepare('UPDATE appointments SET status = ? WHERE appointment_id = ?');
        if ($stmt) {
            $stmt->bind_param('si', $status, $appointmentId);
            $stmt->execute();
            $stmt->close();
        }
        
        $conn->commit();
        $_SESSION['message'] = 'Patient removed from queue.';
        $_SESSION['message_type'] = 'success';
    } catch (Exception $e) {
        $conn->rollback();
        $_SESSION['message'] = 'Error cancelling check-in: ' . $e->getMessage();
        $_SESSION['message_type'] = 'danger';
    }
    
    header('Location: secretary.php?page=appointments&view=' . urlencode($view));
    exit;
}

// Handle APPROVE (secretary confirms/approves appointment)
if ($action === 'approve' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $appointmentId = (int)($_POST['appointment_id'] ?? 0);

    if ($appointmentId <= 0) {
        $_SESSION['message'] = 'Invalid appointment.';
        $_SESSION['message_type'] = 'warning';
        header('Location: secretary.php?page=appointments&view=' . urlencode($view));
        exit;
    }

    // Get appointment date
    $stmt = $conn->prepare('SELECT appointment_date FROM appointments WHERE appointment_id = ?');
    if ($stmt) {
        $stmt->bind_param('i', $appointmentId);
        $stmt->execute();
        $res = $stmt->get_result();
        $appt = $res ? $res->fetch_assoc() : null;
        $stmt->close();
    }

    if (!$appt) {
        $_SESSION['message'] = 'Appointment not found.';
        $_SESSION['message_type'] = 'danger';
        header('Location: secretary.php?page=appointments&view=' . urlencode($view));
        exit;
    }

    $apptDate = $appt['appointment_date'];

    // Count already approved/confirmed for that date
    $cap_stmt = $conn->prepare("SELECT COUNT(*) AS cnt FROM appointments WHERE appointment_date = ? AND status IN ('confirmed','checked_in','in_progress','completed')");
    if ($cap_stmt) {
        $cap_stmt->bind_param('s', $apptDate);
        $cap_stmt->execute();
        $cap_res = $cap_stmt->get_result();
        $cap_row = $cap_res ? $cap_res->fetch_assoc() : null;
        $cap_count = (int)($cap_row['cnt'] ?? 0);
        $cap_stmt->close();

        if ($cap_count >= 15) {
            $_SESSION['message'] = 'Cannot approve: the selected date already has maximum approved appointments.';
            $_SESSION['message_type'] = 'danger';
            header('Location: secretary.php?page=appointments&view=' . urlencode($view));
            exit;
        }
    }

    try {
        $status = 'confirmed';
        $stmt = $conn->prepare('UPDATE appointments SET status = ? WHERE appointment_id = ?');
        if ($stmt) {
            $stmt->bind_param('si', $status, $appointmentId);
            $stmt->execute();
            $stmt->close();
        }

        $_SESSION['message'] = 'Appointment approved.';
        $_SESSION['message_type'] = 'success';
    } catch (Exception $e) {
        $_SESSION['message'] = 'Error approving appointment: ' . $e->getMessage();
        $_SESSION['message_type'] = 'danger';
    }

    header('Location: secretary.php?page=appointments&view=' . urlencode($view));
    exit;
}

// Counts for tabs - ALL APPOINTMENTS (no filtering)
$totalCount = 0;
$todayCount = 0;

$countResult = $conn->query('SELECT COUNT(*) AS c FROM appointments');
if ($countResult && ($r = $countResult->fetch_assoc())) {
    $totalCount = (int)($r['c'] ?? 0);
}

$stmt = $conn->prepare('SELECT COUNT(*) AS c FROM appointments WHERE DATE(appointment_date) = ?');
if ($stmt) {
    $stmt->bind_param('s', $today);
    $stmt->execute();
    $res = $stmt->get_result();
    if ($res && ($r = $res->fetch_assoc())) {
        $todayCount = (int)($r['c'] ?? 0);
    }
    $stmt->close();
}

// Fetch appointments - ALL APPOINTMENTS (no filtering)
$appointments = [];
$appointments_sql = "
    SELECT
        a.*,
        p.first_name,
        p.last_name,
        p.email,
        p.phone_number,
        u.first_name as doctor_first_name,
        u.last_name as doctor_last_name,
        q.checkin_id,
        q.status AS queue_status,
        q.checkin_time,
        q.que_number,
        -- latest schedule info
        (SELECT s.status FROM schedules s WHERE s.appointment_id = a.appointment_id ORDER BY COALESCE(s.scheduled_start, s.created_at) DESC LIMIT 1) AS schedule_status,
        (SELECT DATE_FORMAT(s.scheduled_start, '%Y-%m-%d %H:%i') FROM schedules s WHERE s.appointment_id = a.appointment_id ORDER BY COALESCE(s.scheduled_start, s.created_at) DESC LIMIT 1) AS schedule_start,
        (SELECT DATE_FORMAT(s.scheduled_end, '%Y-%m-%d %H:%i') FROM schedules s WHERE s.appointment_id = a.appointment_id ORDER BY COALESCE(s.scheduled_start, s.created_at) DESC LIMIT 1) AS schedule_end,
        (SELECT COUNT(*) FROM clinical_records cr WHERE cr.appointment_id = a.appointment_id) AS has_history
    FROM appointments a
    LEFT JOIN patients p ON a.patient_id = p.patient_id
    LEFT JOIN users u ON a.user_id = u.user_id
    LEFT JOIN check_in_queue q ON q.appointment_id = a.appointment_id
";

if ($view === 'today') {
    $appointments_sql .= " WHERE DATE(a.appointment_date) = ? ";
}
// Fetch doctors for the booking dropdown (role_id = 2)
$doctors = [];
$doc_stmt = $conn->prepare('SELECT user_id, first_name, last_name FROM users WHERE role_id = 2 AND is_active = 1');
if ($doc_stmt) {
    $doc_stmt->execute();
    $doc_res = $doc_stmt->get_result();
    while ($d = $doc_res->fetch_assoc()) {
        $doctors[] = $d;
    }
    $doc_stmt->close();
}

// Fetch patients for the patient dropdown (secretary selection)
$patients = [];
$pat_stmt = $conn->prepare('SELECT patient_id, first_name, middle_name, last_name, email FROM patients ORDER BY last_name, first_name');
if ($pat_stmt) {
    $pat_stmt->execute();
    $pat_res = $pat_stmt->get_result();
    while ($p = $pat_res->fetch_assoc()) {
        $patients[] = $p;
    }
    $pat_stmt->close();
}

$appointments_sql .= " ORDER BY  a.created_at DESC";

if ($view === 'today') {
    $stmt = $conn->prepare($appointments_sql);
    if ($stmt) {
        $stmt->bind_param('s', $today);
        $stmt->execute();
        $appointments_result = $stmt->get_result();
        if ($appointments_result) {
            while ($row = $appointments_result->fetch_assoc()) {
                $appointments[] = $row;
            }
        }
        $stmt->close();
    }
} else {
    $appointments_result = $conn->query($appointments_sql);
    if ($appointments_result) {
        while ($row = $appointments_result->fetch_assoc()) {
            $appointments[] = $row;
        }
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

<?php if (isset($_SESSION['print_ticket'])):
    $pt = $_SESSION['print_ticket'];
?>
    <script>
        window.open('secretary/print_ticket.php?appointment_id=<?php echo (int)$pt['appointment_id']; ?>&queue_number=<?php echo (int)$pt['queue_number']; ?>', '_blank', 'width=400,height=600');
    </script>
<?php unset($_SESSION['print_ticket']); endif; ?>

<!-- Tab Navigation (All vs Today) -->
<div class="mb-3">
    <ul class="nav nav-tabs" role="tablist">
        <li class="nav-item" role="presentation">
            <button class="nav-link <?php echo $view === 'all' ? 'active' : ''; ?>" type="button"
                    onclick="window.location.href='secretary.php?page=appointments&view=all'">
                <i class="bi bi-calendar3"></i> All Appointments (<?php echo (int)$totalCount; ?>)
            </button>
        </li>
        <li class="nav-item" role="presentation">
            <button class="nav-link <?php echo $view === 'today' ? 'active' : ''; ?>" type="button"
                    onclick="window.location.href='secretary.php?page=appointments&view=today'">
                <i class="bi bi-calendar-check"></i> Today (<?php echo (int)$todayCount; ?>)
            </button>
        </li>
    </ul>
</div>

<!-- Appointments List -->
<div class="card">
    <div class="card-header d-flex justify-content-between align-items-center">
        <h2 class="card-title mb-0">All Appointments</h2>
        <button type="button" class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#bookAppointmentModal">
            <i class="bi bi-plus-circle"></i> Book New Appointment
        </button>
    </div>
    <div class="card-body">
        <div class="table-responsive">
            <table class="table table-hover datatable">
                <thead>
                    <tr>
                        <th>ID</th>
                        <th>Patient Name</th>
                        <th>Schedule</th>
                        <th>Doctor</th>
                        <th>Email</th>
                        <th>Phone</th>
                        <th>Date</th>
                        <th>Reason</th>
                        <th>Type</th>
                        <th>Actions</th>
                        
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($appointments)): ?>
                        <tr>
                            <td colspan="10" class="text-center text-muted py-4">
                                No appointments found.
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($appointments as $appointment): ?>
                            <?php
                                $isTodayAppt = false;
                                if (!empty($appointment['appointment_date'])) {
                                    $isTodayAppt = (date('Y-m-d', strtotime($appointment['appointment_date'])) === $today);
                                }
                                $alreadyCheckedIn = !empty($appointment['checkin_id']);
                            ?>
                            <tr>
                                <td><?php echo htmlspecialchars($appointment['appointment_id']); ?></td>
                                <td><?php echo htmlspecialchars($appointment['first_name'] . ' ' . $appointment['last_name']); ?></td>
                                <td>
                                    <?php if (!empty($appointment['schedule_status'])): ?>
                                        <div><span class="badge bg-<?php echo ($appointment['schedule_status'] === 'scheduled' ? 'primary' : ($appointment['schedule_status'] === 'completed' ? 'success' : 'secondary')); ?>"><?php echo ($appointment['schedule_status'] === 'scheduled') ? 'followup' : htmlspecialchars(ucfirst($appointment['schedule_status'])); ?></span>
                                        <div class="small text-muted"><?php echo htmlspecialchars($appointment['schedule_start'] ?? ''); ?> <?php echo $appointment['schedule_end'] ? '&ndash; ' . htmlspecialchars($appointment['schedule_end']) : ''; ?></div>
                                        </div>
                                    <?php else: ?>
                                        <span class="text-muted small">No schedule</span>
                                    <?php endif; ?>
                                </td>
                                <td><?php echo htmlspecialchars(($appointment['doctor_first_name'] ?? 'N/A') . ' ' . ($appointment['doctor_last_name'] ?? '')); ?></td>
                                <td><?php echo htmlspecialchars($appointment['email']); ?></td>
                                <td><?php echo htmlspecialchars($appointment['phone_number']); ?></td>
                                <td><?php echo htmlspecialchars($appointment['appointment_date']); ?></td>
                                <td><?php echo htmlspecialchars($appointment['reason']); ?></td>
                                <td>
                                    <?php if ($appointment['is_online_appointment']): ?>
                                        <span class="badge bg-info">Online</span>
                                    <?php else: ?>
                                        <span class="badge bg-secondary">Walk-In</span>
                                    <?php endif; ?>
                                </td>
                                
                                <td>
                                    <?php 
                                        $isCompleted = ($appointment['status'] === 'completed');
                                        $isCancelled = ($appointment['status'] === 'cancelled');
                                        $isDisabled = $isCompleted || $isCancelled;
                                    ?>
                                    <?php
                                        $status = $appointment['status'];
                                    ?>
                                    <?php if ($status === 'scheduled' && !$isDisabled): ?>
                                        <div class="btn-group btn-group-sm" role="group">
                                            <div style="display:inline-block;">
                                                <button type="button" class="btn btn-sm btn-primary" onclick="confirmAppointment(<?php echo (int)$appointment['appointment_id']; ?>)">
                                                    <i class="bi bi-check2-square"></i> Approve
                                                </button>
                                            </div>
                                            <button type="button" class="btn btn-sm btn-danger" data-bs-toggle="modal" data-bs-target="#declineModal" onclick="setDeclineAppointment(<?php echo htmlspecialchars($appointment['appointment_id']); ?>)">
                                                <i class="bi bi-x-circle"></i> Decline
                                            </button>
                                        </div>
                                    <?php elseif (in_array($status, ['confirmed','checked_in']) && $isTodayAppt && !$isDisabled): ?>
                                        <?php if ($alreadyCheckedIn): ?>
                                            <div class="btn-group btn-group-sm" role="group">
                                                <span class="badge bg-success me-2">Queue #<?php echo htmlspecialchars((string)$appointment['que_number']); ?></span>
                                                <form method="POST" action="secretary.php?page=appointments&view=<?php echo htmlspecialchars($view); ?>&action=cancel" style="display:inline-block;" onsubmit="return confirm('Remove this patient from queue?');">
                                                    <input type="hidden" name="appointment_id" value="<?php echo htmlspecialchars($appointment['appointment_id']); ?>">
                                                    <button type="submit" class="btn btn-sm btn-warning">
                                                        <i class="bi bi-x-circle"></i> Cancel
                                                    </button>
                                                </form>
                                            </div>
                                        <?php else: ?>
                                            <div class="btn-group btn-group-sm" role="group">
                                                <form method="POST" action="secretary.php?page=appointments&view=<?php echo htmlspecialchars($view); ?>&action=checkin" style="display:inline-block;" onsubmit="return confirm('Mark patient as arrived and add to queue?');">
                                                    <input type="hidden" name="appointment_id" value="<?php echo htmlspecialchars($appointment['appointment_id']); ?>">
                                                    <button type="submit" class="btn btn-sm btn-success">
                                                        <i class="bi bi-person-check"></i> Arrived
                                                    </button>
                                                </form>
                                                <button type="button" class="btn btn-sm btn-danger" data-bs-toggle="modal" data-bs-target="#declineModal" onclick="setDeclineAppointment(<?php echo htmlspecialchars($appointment['appointment_id']); ?>)">
                                                    <i class="bi bi-x-circle"></i> Decline
                                                </button>
                                            </div>
                                        <?php endif; ?>
                                    <?php elseif ($isCompleted || $isCancelled): ?>
                                        <span class="badge bg-<?php echo $isCompleted ? 'success' : 'danger'; ?>"><?php echo htmlspecialchars(ucfirst($appointment['status'])); ?></span>
                                    <?php else: ?>
                                        <span class="text-muted small"><?php echo htmlspecialchars(ucfirst($appointment['status'])); ?></span>
                                    <?php endif; ?>
                                </td>
                                
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- Book New Appointment Modal -->
<div class="modal fade" id="bookAppointmentModal" tabindex="-1" role="dialog" aria-hidden="true">
    <div class="modal-dialog modal-lg" role="document">
        <div class="modal-content">
            <div class="modal-header bg-primary text-white">
                <h5 class="modal-title">
                    <i class="bi bi-plus-circle"></i> Book New Appointment
                </h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
                    <form id="bookAppointmentForm" method="POST" action="#">
                <div class="modal-body">
                    <!-- Step 1: Patient Info -->
                    <div id="stepPatient" class="modal-step">
                        <!-- Patient selection (single dropdown below) -->

                        <div class="mb-3">
                            <label for="bookPatientSelect" class="form-label"><strong>Select Patient</strong></label>
                            <select id="bookPatientSelect" name="patient_id" class="form-select" required>
                                <option value="">-- Select Patient --</option>
                                <?php foreach ($patients as $p): ?>
                                    <?php $fullname = trim($p['first_name'] . ' ' . ($p['middle_name'] ? $p['middle_name'] . ' ' : '') . $p['last_name']); ?>
                                    <option value="<?php echo htmlspecialchars($p['patient_id']); ?>"><?php echo htmlspecialchars($fullname . ($p['email'] ? ' — ' . $p['email'] : '')); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        
                    </div>

                    <!-- Step 2: Booking Info -->
                    <div id="stepBooking" class="modal-step" style="display:none;">
                        <div class="mb-3">
                            <label for="bookDoctorSelect" class="form-label"><strong>Select Doctor</strong></label>
                            <select id="bookDoctorSelect" name="user_id" class="form-select" required>
                                <option value="">-- Select Doctor --</option>
                                <?php foreach ($doctors as $d): ?>
                                    <option value="<?php echo htmlspecialchars($d['user_id']); ?>"><?php echo htmlspecialchars($d['first_name'] . ' ' . $d['last_name']); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="mb-3">
                            <label for="bookAppointmentDateInput" class="form-label"><strong>Appointment Date</strong></label>
                            <input type="hidden" id="bookAppointmentDateInput" name="appointment_date" required>
                            <div id="bookCalendar" class="d-flex flex-wrap gap-2">Loading available dates...</div>
                        </div>

                        <div class="mb-3">
                            <label for="bookReason" class="form-label"><strong>Reason for Appointment</strong></label>
                            <textarea class="form-control" id="bookReason" name="reason" rows="3" placeholder="Enter reason for appointment" required></textarea>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" id="bookSubmitBtn" class="btn btn-success">
                        <i class="bi bi-check-circle"></i> Book Appointment
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Decline Appointment Modal -->
<div class="modal fade" id="declineModal" tabindex="-1" role="dialog" aria-hidden="true">
    <div class="modal-dialog" role="document">
        <div class="modal-content">
            <div class="modal-header bg-danger text-white">
                <h5 class="modal-title">
                    <i class="bi bi-x-circle"></i> Decline Appointment
                </h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <form method="POST" action="secretary.php?page=appointments&view=all&action=decline" onsubmit="return confirm('Are you sure you want to decline this appointment?');">
                <div class="modal-body">
                    <input type="hidden" id="declineAppointmentId" name="appointment_id">
                    
                    <div class="mb-3">
                        <label for="declineReason" class="form-label"><strong>Reason for Declining</strong></label>
                        <textarea class="form-control" id="declineReason" name="decline_reason" rows="3" placeholder="Enter reason for declining this appointment" required></textarea>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-danger">
                        <i class="bi bi-x-circle"></i> Decline Appointment
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Initialize DataTable -->
<script>
    function setDeclineAppointment(appointmentId) {
        document.getElementById('declineAppointmentId').value = appointmentId;
    }
    
    document.addEventListener('DOMContentLoaded', function() {
        if (window.SimpleDataTable) {
            new SimpleDataTable({
                element: document.querySelector('.datatable'),
                searchable: true,
                sortable: true,
                rowsPerPage: 10
            });
        }

        // Handle book appointment form: booking requires existing patient_id
        const bookForm = document.getElementById('bookAppointmentForm');
        if (bookForm) {
            const stepPatient = document.getElementById('stepPatient');
            const stepBooking = document.getElementById('stepBooking');
            // Show both sections for secretary: select patient + booking details
            if (stepPatient) stepPatient.style.display = '';
            if (stepBooking) stepBooking.style.display = '';

            // Patient selection (single dropdown)
            const bookPatientSelect = document.getElementById('bookPatientSelect');
            if (bookPatientSelect) {
                // keep the select value as the patient id submitted by the form
                bookPatientSelect.addEventListener('change', function() {
                    // no-op: value is read directly on submit
                });
            }

            // Confirm appointment via AJAX (calls confirm_appointment.php)
            window.confirmAppointment = async function(appointmentId) {
                if (!confirm('Approve and schedule this appointment?')) return;
                try {
                    const fd = new FormData();
                    fd.append('appointment_id', appointmentId);
                    const resp = await fetch('../assets/api/appointments/confirm_appointment.php', { method: 'POST', body: fd });
                    const j = await resp.json();
                    if (j.success) {
                        // reload to reflect changes; show simple alert
                        alert(j.message || 'Appointment approved');
                        window.location.reload();
                    } else {
                        alert('Approve failed: ' + (j.message || 'unknown'));
                    }
                } catch (err) {
                    console.error(err);
                    alert('Approve error');
                }
            };

            // Load available dates into a simple calendar grid for secretary
            async function loadAvailableDates(days = 30) {
                const cal = document.getElementById('bookCalendar');
                const hid = document.getElementById('bookAppointmentDateInput');
                if (!cal || !hid) return;
                cal.innerHTML = '';
                try {
                    const res = await fetch('../assets/api/appointments/get_available_dates.php?days=' + encodeURIComponent(days));
                    const j = await res.json();
                    if (!j.success || !Array.isArray(j.dates)) {
                        cal.innerHTML = '<div class="text-muted">Unable to load dates</div>';
                        return;
                    }
                    const dates = j.dates || [];
                    if (dates.length === 0) {
                        cal.innerHTML = '<div class="text-muted">No available dates in range</div>';
                        return;
                    }

                    // Render month-style calendars for secretary (full dates selectable)
                    // Build mapping of monthKey -> items; always include next 5 months
                    const byMonth = {};
                    const monthsToShow = 5;
                    const startDate = new Date();
                    for (let m = 0; m < monthsToShow; m++) {
                        const d = new Date(startDate.getFullYear(), startDate.getMonth() + m, 1);
                        const key = d.getFullYear() + '-' + (d.getMonth() + 1);
                        byMonth[key] = [];
                    }
                    // populate items into months (if present)
                    dates.forEach(d => {
                        const dt = new Date(d.date + 'T00:00:00');
                        const key = dt.getFullYear() + '-' + (dt.getMonth() + 1);
                        if (!byMonth[key]) byMonth[key] = [];
                        byMonth[key].push(d);
                    });

                    // create viewport + sliding track for secretary calendar
                    const viewport = document.createElement('div');
                    viewport.className = 'calendar-viewport';
                    viewport.style.position = 'relative';
                    viewport.style.overflow = 'hidden';
                    viewport.style.minHeight = '360px';
                    cal.innerHTML = '';
                    cal.appendChild(viewport);

                    const track = document.createElement('div');
                    track.className = 'calendar-track';
                    track.style.display = 'flex';
                    track.style.transition = 'transform 320ms ease';
                    // will set explicit width after monthKeys computed
                    viewport.appendChild(track);

                    function createMonthCard(monthKey, items) {
                        const parts = monthKey.split('-');
                        const year = parseInt(parts[0], 10);
                        const month = parseInt(parts[1], 10) - 1;

                        const monthStart = new Date(year, month, 1);
                        const monthName = monthStart.toLocaleString(undefined, { month: 'long' });

                        const card = document.createElement('div');
                        card.className = 'calendar-card card p-2 mb-2';
                        const pct = 100 / monthKeys.length;
                        card.style.flex = '0 0 ' + pct + '%';
                        card.style.boxSizing = 'border-box';

                        const hdr = document.createElement('div');
                        hdr.className = 'mb-2';
                        hdr.innerHTML = '<strong>' + monthName + ' ' + year + '</strong>';
                        card.appendChild(hdr);

                        const table = document.createElement('table');
                        table.className = 'table table-bordered mb-0';
                        const thead = document.createElement('thead');
                        const tr = document.createElement('tr');
                        ['Sun','Mon','Tue','Wed','Thu','Fri','Sat'].forEach(w => { const th = document.createElement('th'); th.style.width = '14%'; th.style.textAlign = 'center'; th.textContent = w; tr.appendChild(th); });
                        thead.appendChild(tr);
                        table.appendChild(thead);

                        const tbody = document.createElement('tbody');

                        const firstWeekday = monthStart.getDay();
                        let curDay = 1 - firstWeekday;
                        while (curDay <= 31) {
                            const row = document.createElement('tr');
                            let emptyRow = true;
                            for (let wd = 0; wd < 7; wd++, curDay++) {
                                const cell = document.createElement('td');
                                cell.style.height = '60px';
                                cell.style.verticalAlign = 'top';
                                if (curDay < 1 || curDay > new Date(year, month + 1, 0).getDate()) {
                                    cell.innerHTML = '';
                                } else {
                                    emptyRow = false;
                                    const dtLocal = new Date(year, month, curDay);
                                    const weekday = dtLocal.getDay();
                                    const dateStr = dtLocal.getFullYear() + '-' + String(dtLocal.getMonth() + 1).padStart(2,'0') + '-' + String(dtLocal.getDate()).padStart(2,'0');
                                    const item = items.find(it => it.date === dateStr);
                                    const dayDiv = document.createElement('div');
                                    const dayHeader = document.createElement('div');
                                    dayHeader.innerHTML = '<small class="text-muted">' + curDay + '</small>';
                                    dayDiv.appendChild(dayHeader);
                                    if (item && item.count) {
                                        const countDiv = document.createElement('div');
                                        countDiv.innerHTML = '<small>' + item.count + ' booked</small>';
                                        dayDiv.appendChild(countDiv);
                                    }
                                    if (weekday === 0) {
                                        // Sunday - clinic closed
                                        const badge = document.createElement('div');
                                        badge.innerHTML = '<small class="text-danger">CLOSED</small>';
                                        dayDiv.appendChild(badge);
                                        dayDiv.className = 'full-day text-muted';
                                        dayDiv.title = 'Clinic closed (Sunday)';
                                    } else if (item && !item.available) {
                                        const badge = document.createElement('div');
                                        badge.innerHTML = '<small class="text-danger">FULL</small>';
                                        dayDiv.appendChild(badge);
                                        dayDiv.className = 'full-day text-muted';
                                        dayDiv.title = (item.count ? (item.count + ' booked') : '') + ' — FULL';
                                    } else {
                                        // selectable
                                        dayDiv.className = item && item.count ? 'has-bookings' : 'selectable-date';
                                        dayDiv.style.cursor = 'pointer';
                                        dayDiv.addEventListener('click', function() {
                                            hid.value = dateStr;
                                            document.querySelectorAll('#bookCalendar .selected').forEach(el => el.classList.remove('selected'));
                                            dayDiv.classList.add('selected');
                                        });
                                        dayDiv.title = item && item.count ? (item.count + ' booked') : '';
                                    }
                                    cell.appendChild(dayDiv);
                                }
                                row.appendChild(cell);
                            }
                            if (emptyRow) break;
                            tbody.appendChild(row);
                        }
                        table.appendChild(tbody);
                        card.appendChild(table);
                        track.appendChild(card);
                        return card;
                    }

                    const monthKeys = Object.keys(byMonth).sort((a,b) => {
                        const pa = a.split('-').map(Number); const pb = b.split('-').map(Number);
                        return (pa[0] - pb[0]) || (pa[1] - pb[1]);
                    });
                    let currentIndex = 0;
                    const header = document.createElement('div');
                    header.className = 'd-flex justify-content-between align-items-center mb-2';
                    const prevBtn = document.createElement('button'); prevBtn.type='button'; prevBtn.className='btn btn-light btn-sm'; prevBtn.textContent='◀';
                    const nextBtn = document.createElement('button'); nextBtn.type='button'; nextBtn.className='btn btn-light btn-sm'; nextBtn.textContent='▶';
                    const title = document.createElement('div'); title.className='fw-bold';
                    header.appendChild(prevBtn); header.appendChild(title); header.appendChild(nextBtn);
                    cal.parentNode.insertBefore(header, cal);

                    function showMonthIndex(i) {
                        if (i < 0) i = 0; if (i >= monthKeys.length) i = monthKeys.length - 1;
                        currentIndex = i;
                        const key = monthKeys[currentIndex];
                        const parts = key.split('-');
                        const dt = new Date(parseInt(parts[0],10), parseInt(parts[1],10)-1, 1);
                        title.textContent = dt.toLocaleString(undefined, { month: 'long', year: 'numeric' });
                        // ensure track width equals number of months
                        track.style.width = (monthKeys.length * 100) + '%';
                        const shift = -currentIndex * (100 / monthKeys.length);
                        track.style.transform = 'translateX(' + shift + '%)';
                    }
                    prevBtn.addEventListener('click', () => showMonthIndex(currentIndex - 1));
                    nextBtn.addEventListener('click', () => showMonthIndex(currentIndex + 1));
                    // build all month cards on track
                    monthKeys.forEach(k => createMonthCard(k, byMonth[k] || []));
                    showMonthIndex(0);

                    if (!document.getElementById('book-calendar-style')) {
                        const s = document.createElement('style');
                        s.id = 'book-calendar-style';
                        s.textContent = '#bookCalendar .selected{background:#0b5ed7 !important;color:#fff;padding:4px;border-radius:6px;border:2px solid #084298;box-shadow:none;} #bookCalendar .card{width:100%;} #bookCalendar .text-muted{opacity:0.65;} #bookCalendar .selectable-date{background:linear-gradient(180deg,#e9f7ef 0%,#dff6e8 100%);border-radius:6px;} #bookCalendar .has-bookings{background:linear-gradient(180deg,#fff8dc 0%,#fff3cd 100%);border-radius:6px;} #bookCalendar .full-day{background:#f8d7da;color:#721c24;font-weight:700;border-radius:6px;} #bookCalendar .calendar-card table td{border:1px solid #e9ecef;padding:6px;} #bookCalendar .calendar-card table th{background:#f1f3f5;color:#495057;text-align:center;} #bookCalendar .calendar-card td div{min-height:48px;display:flex;flex-direction:column;gap:4px;} #bookCalendar .calendar-card td small{font-weight:700;color:#0d6efd;font-size:1rem;} /* hover: no padding/size change; only background and border to avoid enlargement */ #bookCalendar .selectable-date:hover,#bookCalendar .has-bookings:hover{background:#0d6efd;color:#fff;border:1px solid rgba(11,94,215,0.9);border-radius:6px;box-shadow:none;} .calendar-viewport{position:relative;} .calendar-track{width:100%;} .calendar-card{will-change:transform,opacity;flex:0 0 100%;} .calendar-card table td{vertical-align:top;}';
                        document.head.appendChild(s);
                    }
                } catch (e) {
                    console.error(e);
                    cal.innerHTML = '<div class="text-danger">Error loading dates</div>';
                }
            }

            // Load dates when modal opens
            const bookModal = document.getElementById('bookAppointmentModal');
            if (bookModal) {
                bookModal.addEventListener('shown.bs.modal', function () {
                    loadAvailableDates(30);
                });
            }

            // Booking submit
            bookForm.addEventListener('submit', async function(e) {
                e.preventDefault();
                const patientId = bookPatientSelect ? parseInt(bookPatientSelect.value) || 0 : 0;
                const appointmentDate = document.getElementById('bookAppointmentDateInput') ? document.getElementById('bookAppointmentDateInput').value : '';
                const reason = document.getElementById('bookReason').value.trim();
                const userSelect = document.getElementById('bookDoctorSelect');
                const userId = userSelect ? userSelect.value : '';

                if (!appointmentDate || !patientId || !userId) {
                    alert('Please select a patient, a doctor, and an appointment date.');
                    return;
                }

                try {
                    const ad = new FormData();
                    ad.append('patient_id', patientId);
                    ad.append('appointment_date', appointmentDate);
                    ad.append('user_id', userId);
                    ad.append('reason', reason);

                    const aresp = await fetch('../assets/api/appointments/create_appointment.php', { method: 'POST', body: ad });
                    const ajson = await aresp.json();
                    if (ajson.success) {
                        alert('Appointment created successfully');
                        location.reload();
                    } else {
                        alert('Error creating appointment: ' + (ajson.message || 'unknown'));
                    }
                } catch (err) {
                    console.error(err);
                    alert('Error: ' + (err.message || err));
                }
            });
        }
    });
</script>

<!-- Reschedule Modal (secretary) -->
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/flatpickr/dist/flatpickr.min.css">
<script src="https://cdn.jsdelivr.net/npm/flatpickr"></script>
<div class="modal fade" id="rescheduleModalSec" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Reschedule</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <input type="hidden" id="rescheduleScheduleIdSec" />
                <div class="mb-2"><label class="form-label">Start</label><input id="rescheduleStartSec" class="form-control" type="datetime-local"/></div>
                <div class="mb-2"><label class="form-label">End (optional)</label><input id="rescheduleEndSec" class="form-control" type="datetime-local"/></div>
                <div class="mb-2"><button id="rescheduleSuggestSec" class="btn btn-sm btn-outline-primary">Suggest slots</button></div>
                <div id="rescheduleSuggestionsSec" class="list-group"></div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                <button id="rescheduleSaveBtnSec" type="button" class="btn btn-primary">Save</button>
            </div>
        </div>
    </div>
</div>

<script>
const resModalSec = new bootstrap.Modal(document.getElementById('rescheduleModalSec'));
let fpStartSec = null, fpEndSec = null;
function initSecPickers() {
    try {
        if (typeof flatpickr !== 'undefined') {
            fpStartSec = flatpickr('#rescheduleStartSec', { enableTime: true, dateFormat: 'Y-m-d H:i', time_24hr: true });
            fpEndSec = flatpickr('#rescheduleEndSec', { enableTime: true, dateFormat: 'Y-m-d H:i', time_24hr: true });
        }
    } catch(e){ console.warn('flatpickr sec init', e); }
}
initSecPickers();
function toLocalInputSec(dtStr) { if (!dtStr) return ''; return dtStr.replace(' ', 'T').replace(/:00$/,''); }
function toServerFormatSec(localVal) { if (!localVal) return ''; return localVal.replace('T',' '); }
function openRescheduleModalSec(schedule) {
    document.getElementById('rescheduleScheduleIdSec').value = schedule.schedule_id || '';
    const startLocal = toLocalInputSec(schedule.scheduled_start || '');
    const endLocal = toLocalInputSec(schedule.scheduled_end || '');
    if (fpStartSec && typeof fpStartSec.setDate === 'function') fpStartSec.setDate(startLocal);
    else document.getElementById('rescheduleStartSec').value = startLocal;
    if (fpEndSec && typeof fpEndSec.setDate === 'function') fpEndSec.setDate(endLocal);
    else document.getElementById('rescheduleEndSec').value = endLocal;
    document.getElementById('rescheduleSuggestionsSec').innerHTML = '';
    resModalSec.show();
}
document.getElementById('rescheduleSuggestSec').addEventListener('click', async function(){
    const btn = this; btn.disabled = true;
    try { const fd = new FormData(); fd.append('user_id', CURRENT_USER); fd.append('days_ahead', 14); fd.append('duration', 30); const res = await fetch('../assets/api/appointments/smart_schedule.php', { method: 'POST', body: fd }); const j = await res.json(); const wrap = document.getElementById('rescheduleSuggestionsSec'); wrap.innerHTML = ''; if (!j.success) return alert(j.message || 'No suggestions'); (j.slots || []).slice(0,10).forEach(slot => { const a = document.createElement('a'); a.href = '#'; a.className = 'list-group-item list-group-item-action'; a.textContent = slot.start + (slot.end ? (' — ' + slot.end) : ''); a.addEventListener('click', function(e){ e.preventDefault(); document.getElementById('rescheduleStartSec').value = slot.start.replace(' ', 'T'); document.getElementById('rescheduleEndSec').value = slot.end ? slot.end.replace(' ', 'T') : ''; }); wrap.appendChild(a); }); } catch(e){ console.error(e); alert('Error fetching suggestions'); } btn.disabled = false; });
document.getElementById('rescheduleSuggestSec').addEventListener('click', async function(){
    const sid = document.getElementById('rescheduleScheduleIdSec').value;
    if (!sid) return alert('No schedule selected');
    const btn = this; btn.disabled = true;
    try {
        const g = await fetch('../assets/api/appointments/get_schedule.php?schedule_id=' + encodeURIComponent(sid));
        const gs = await g.json();
        if (!gs.success) return alert(gs.message || 'Could not resolve schedule');
        const user_id = gs.schedule.user_id || CURRENT_USER;
        const duration = gs.schedule.duration || 30;
        const fd = new FormData(); fd.append('user_id', user_id); fd.append('days_ahead', 14); fd.append('duration', duration);
        const res = await fetch('../assets/api/appointments/smart_schedule.php', { method: 'POST', body: fd });
        const j = await res.json();
        const wrap = document.getElementById('rescheduleSuggestionsSec'); wrap.innerHTML = '';
        if (!j.success) return alert(j.message || 'No suggestions');
        (j.slots || []).slice(0,10).forEach(slot => { const a = document.createElement('a'); a.href = '#'; a.className = 'list-group-item list-group-item-action'; a.textContent = slot.start + (slot.end ? (' — ' + slot.end) : ''); a.addEventListener('click', function(e){ e.preventDefault(); document.getElementById('rescheduleStartSec').value = slot.start.replace(' ', 'T'); document.getElementById('rescheduleEndSec').value = slot.end ? slot.end.replace(' ', 'T') : ''; }); wrap.appendChild(a); });
    } catch(e){ console.error(e); alert('Error fetching suggestions'); }
    btn.disabled = false;
});
document.getElementById('rescheduleSaveBtnSec').addEventListener('click', async function(){
    const sid = document.getElementById('rescheduleScheduleIdSec').value; const start = toServerFormatSec(document.getElementById('rescheduleStartSec').value); const end = toServerFormatSec(document.getElementById('rescheduleEndSec').value);
    if (!sid || !start) { alert('Start datetime required'); return; }
    this.disabled = true;
    try {
        const fd = new FormData(); fd.append('schedule_id', sid); fd.append('scheduled_start', start); fd.append('scheduled_end', end);
        const r = await fetch('../assets/api/appointments/reschedule_schedule.php', { method: 'POST', body: fd });
        const j = await r.json();
        if (!j.success) {
            alert(j.message || 'Failed to reschedule');
        } else {
            alert('Rescheduled');
            resModalSec.hide();
            location.reload();
        }
    } catch (e) {
        console.error(e);
        alert('Error');
    } finally {
        this.disabled = false;
    }
});
</script>

<script>
const CURRENT_ROLE = '<?php echo $_SESSION['role'] ?? ''; ?>';
const CURRENT_USER = <?php echo (int)($_SESSION['user_id'] ?? 0); ?>;
</script>
