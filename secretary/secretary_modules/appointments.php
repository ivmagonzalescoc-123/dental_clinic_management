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

        if (($appt['appointment_date'] ?? '') !== $today) {
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

// Counts for tabs - ALL APPOINTMENTS (no filtering)
$totalCount = 0;
$todayCount = 0;

$countResult = $conn->query('SELECT COUNT(*) AS c FROM appointments');
if ($countResult && ($r = $countResult->fetch_assoc())) {
    $totalCount = (int)($r['c'] ?? 0);
}

$stmt = $conn->prepare('SELECT COUNT(*) AS c FROM appointments WHERE appointment_date = ?');
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
        q.que_number
    FROM appointments a
    LEFT JOIN patients p ON a.patient_id = p.patient_id
    LEFT JOIN users u ON a.user_id = u.user_id
    LEFT JOIN check_in_queue q ON q.appointment_id = a.appointment_id
";

if ($view === 'today') {
    $appointments_sql .= " WHERE a.appointment_date = ? ";
}

$appointments_sql .= " ORDER BY a.appointment_date DESC, a.created_at DESC";

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
                        <th>Doctor</th>
                        <th>Email</th>
                        <th>Phone</th>
                        <th>Date</th>
                        <th>Reason</th>
                        <th>Type</th>
                        <th>Status</th>
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
                                $isTodayAppt = (($appointment['appointment_date'] ?? '') === $today);
                                $alreadyCheckedIn = !empty($appointment['checkin_id']);
                            ?>
                            <tr>
                                <td><?php echo htmlspecialchars($appointment['appointment_id']); ?></td>
                                <td><?php echo htmlspecialchars($appointment['first_name'] . ' ' . $appointment['last_name']); ?></td>
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
                                    <span class="badge bg-<?php echo $appointment['status'] === 'completed' ? 'success' : ($appointment['status'] === 'cancelled' ? 'danger' : 'warning'); ?>">
                                        <?php echo htmlspecialchars(ucfirst($appointment['status'])); ?>
                                    </span>
                                </td>
                                <td>
                                    <?php 
                                        $isCompleted = ($appointment['status'] === 'completed');
                                        $isCancelled = ($appointment['status'] === 'cancelled');
                                        $isDisabled = $isCompleted || $isCancelled;
                                    ?>
                                    <?php if ($isTodayAppt && !$isDisabled): ?>
                                        <?php if ($alreadyCheckedIn): ?>
                                            <!-- Already checked-in: show Cancel button only, Decline disabled -->
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
                                            <!-- Not checked-in: show Accept and Decline buttons -->
                                            <div class="btn-group btn-group-sm" role="group">
                                                <form method="POST" action="secretary.php?page=appointments&view=<?php echo htmlspecialchars($view); ?>&action=checkin" style="display:inline-block;" onsubmit="return confirm('Accept and check-in this patient?');">
                                                    <input type="hidden" name="appointment_id" value="<?php echo htmlspecialchars($appointment['appointment_id']); ?>">
                                                    <button type="submit" class="btn btn-sm btn-success">
                                                        <i class="bi bi-check-circle"></i> Accept
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
                                        <span class="text-muted small">Future appointment</span>
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
                        <input type="hidden" name="patient_id" id="bookPatientId" value="">
                        <input type="hidden" name="user_id" id="bookUserId" value="<?= htmlspecialchars($_SESSION['user_id'] ?? ''); ?>">
                <div class="modal-body">
                    <div class="mb-3">
                        <label for="bookPatientName" class="form-label"><strong>Patient Name</strong></label>
                        <input type="text" class="form-control" id="bookPatientName" name="patient_name" placeholder="Enter patient name" required>
                        <div class="form-text">If patient is not registered, a walk-in account will be created automatically.</div>
                    </div>
                    
                    <div class="mb-3">
                        <label for="bookPatientEmail" class="form-label"><strong>Email</strong></label>
                        <input type="email" class="form-control" id="bookPatientEmail" name="patient_email" placeholder="Enter patient email">
                    </div>
                    
                    <div class="mb-3">
                        <label for="bookPatientPhone" class="form-label"><strong>Phone</strong></label>
                        <input type="tel" class="form-control" id="bookPatientPhone" name="patient_phone" placeholder="Enter patient phone">
                    </div>
                    
                    <div class="mb-3">
                        <label for="bookAppointmentDate" class="form-label"><strong>Appointment Date</strong></label>
                        <input type="date" class="form-control" id="bookAppointmentDate" name="appointment_date" required>
                    </div>
                    
                    <!-- timeslot removed -->
                    
                    <div class="mb-3">
                        <label for="bookReason" class="form-label"><strong>Reason for Appointment</strong></label>
                        <textarea class="form-control" id="bookReason" name="reason" rows="3" placeholder="Enter reason for appointment" required></textarea>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary">
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

        // Handle book appointment form: create walk-in patient (if needed) then create appointment
        const bookForm = document.getElementById('bookAppointmentForm');
        if (bookForm) {
            bookForm.addEventListener('submit', async function(e) {
                e.preventDefault();
                const patientIdInput = document.getElementById('bookPatientId');
                let patientId = patientIdInput.value ? parseInt(patientIdInput.value) : 0;
                const name = document.getElementById('bookPatientName').value.trim();
                const email = document.getElementById('bookPatientEmail').value.trim();
                const phone = document.getElementById('bookPatientPhone').value.trim();
                const appointmentDate = document.getElementById('bookAppointmentDate').value;
                const reason = document.getElementById('bookReason').value.trim();
                const userId = document.getElementById('bookUserId').value || '';

                if (!appointmentDate || !name) {
                    alert('Patient name and appointment date are required');
                    return;
                }

                try {
                    if (!patientId) {
                        // Split name into first/last
                        const parts = name.split(' ');
                        const first_name = parts.shift() || name;
                        const last_name = parts.length ? parts.pop() : '';
                        const middle_name = parts.length ? parts.join(' ') : '';

                        const pd = new FormData();
                        pd.append('first_name', first_name);
                        pd.append('middle_name', middle_name);
                        pd.append('last_name', last_name);
                        pd.append('email', email);
                        pd.append('phone_number', phone);

                        const resp = await fetch('../assets/api/patients/create_walkin.php', { method: 'POST', body: pd });
                        const json = await resp.json();
                        if (!json.success) throw new Error(json.message || 'Failed to create patient');
                        patientId = json.patient_id;
                        patientIdInput.value = patientId;
                    }

                    // Now create appointment
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
                    alert('Error: ' + err.message);
                }
            });
        }
    });
</script>
