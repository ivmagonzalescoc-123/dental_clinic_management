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
        header('Location: admin.php?page=appointments&view=' . urlencode($view));
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
            header('Location: admin.php?page=appointments&view=' . urlencode($view));
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

    header('Location: admin.php?page=appointments&view=' . urlencode($view));
    exit;
}

// Counts for tabs
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

// Fetch appointments
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

// Fetch patients for dropdown/search
$patients_list = [];
$patients_sql = "SELECT patient_id, first_name, last_name, email, phone_number FROM patients ORDER BY first_name ASC";
$patients_result = $conn->query($patients_sql);
if ($patients_result) {
    while ($row = $patients_result->fetch_assoc()) {
        $patients_list[] = $row;
    }
}

// Fetch doctors for dropdown
$doctors_list = [];
$doctors_sql = "SELECT user_id, first_name, last_name FROM users WHERE role_id = 2 AND is_active = 1 ORDER BY first_name ASC";
$doctors_result = $conn->query($doctors_sql);
if ($doctors_result) {
    while ($row = $doctors_result->fetch_assoc()) {
        $doctors_list[] = $row;
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
                    onclick="window.location.href='admin.php?page=appointments&view=all'">
                <i class="bi bi-calendar3"></i> All Appointments (<?php echo (int)$totalCount; ?>)
            </button>
        </li>
        <li class="nav-item" role="presentation">
            <button class="nav-link <?php echo $view === 'today' ? 'active' : ''; ?>" type="button"
                    onclick="window.location.href='admin.php?page=appointments&view=today'">
                <i class="bi bi-calendar-check"></i> Today (<?php echo (int)$todayCount; ?>)
            </button>
        </li>
    </ul>
</div>

<!-- Appointments List -->
<div class="card">
    <div class="card-header d-flex justify-content-between align-items-center">
        <h2 class="card-title mb-0">Appointments</h2>
        <button type="button" class="btn btn-primary btn-sm" data-bs-toggle="modal" data-bs-target="#createAppointmentModal">
            <i class="bi bi-plus-circle"></i> Create Appointment
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
                    <?php foreach ($appointments as $appointment): ?>
                        <?php
                            $isTodayAppt = (($appointment['appointment_date'] ?? '') === $today);
                            $alreadyCheckedIn = !empty($appointment['checkin_id']);
                        ?>
                        <tr>
                            <td><?php echo htmlspecialchars($appointment['appointment_id']); ?></td>
                            <td><?php echo htmlspecialchars($appointment['first_name'] . ' ' . $appointment['last_name']); ?></td>
                            <td><?php echo htmlspecialchars(($appointment['doctor_first_name'] ?? 'N/A') . ' ' . ($appointment['doctor_last_name'] ?? '')); ?></td>
                            <!-- branch removed -->
                            <td><?php echo htmlspecialchars($appointment['email']); ?></td>
                            <td><?php echo htmlspecialchars($appointment['phone_number']); ?></td>
                            <td><?php echo htmlspecialchars($appointment['appointment_date']); ?></td>
                            <td><?php echo htmlspecialchars($appointment['reason']); ?></td>
                            <td>
                                <?php if ($appointment['is_online_appointment']): ?>
                                    <span class="badge bg-info">Online</span>
                                <?php else: ?>
                                    <span class="badge bg-secondary">In-Person</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <span class="badge bg-<?php echo $appointment['status'] === 'completed' ? 'success' : ($appointment['status'] === 'cancelled' ? 'danger' : 'warning'); ?>">
                                    <?php echo htmlspecialchars(ucfirst($appointment['status'])); ?>
                                </span>
                            </td>
                            <td>
                                <button type="button" class="btn btn-sm btn-info" data-bs-toggle="modal" 
                                        data-bs-target="#editAppointmentModal" onclick="editAppointment(<?php echo htmlspecialchars(json_encode($appointment)); ?>)">
                                    <i class="bi bi-pencil"></i> Edit
                                </button>

                                <?php if ($isTodayAppt): ?>
                                    <?php if ($alreadyCheckedIn): ?>
                                        <button type="button" class="btn btn-sm btn-success" disabled>
                                            <i class="bi bi-check2-circle"></i>
                                            Checked-in<?php echo !empty($appointment['que_number']) ? ' #' . htmlspecialchars((string)$appointment['que_number']) : ''; ?>
                                        </button>
                                    <?php else: ?>
                                        <form method="POST" action="admin.php?page=appointments&view=<?php echo htmlspecialchars($view); ?>&action=checkin" style="display:inline-block;" onsubmit="return confirm('Accept and check-in this patient?');">
                                            <input type="hidden" name="appointment_id" value="<?php echo htmlspecialchars($appointment['appointment_id']); ?>">
                                            <button type="submit" class="btn btn-sm btn-primary">
                                                <i class="bi bi-person-check"></i> Accept (Check-in)
                                            </button>
                                        </form>
                                    <?php endif; ?>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- ========== CREATE APPOINTMENT MODAL ========== -->
<div class="modal fade" id="createAppointmentModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Create Appointment</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form id="createAppointmentForm">
                <div class="modal-body">
                    <div id="appointmentMessage"></div>
                    <div class="mb-3">
                        <label class="form-label">Search & Select Patient</label>
                        <div class="input-group">
                            <input type="text" class="form-control" id="patientSearchInput" 
                                   placeholder="Search by name, email, or phone...">
                            <button class="btn btn-outline-secondary" type="button" id="patientSearchBtn">
                                <i class="bi bi-search"></i>
                            </button>
                        </div>
                        <div id="patientList" class="list-group mt-2" style="max-height: 300px; overflow-y: auto; display: none;">
                        </div>
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label">Selected Patient</label>
                        <input type="text" class="form-control" id="selectedPatientDisplay" readonly placeholder="No patient selected">
                        <input type="hidden" name="patient_id" id="selectedPatientId">
                    </div>

                    <div class="mb-3">
                        <label class="form-label">Select Doctor</label>
                        <select class="form-control" name="user_id" id="doctorSelect" required>
                            <option value="">-- Select a doctor --</option>
                            <?php foreach ($doctors_list as $doctor): ?>
                                <option value="<?php echo htmlspecialchars($doctor['user_id']); ?>">
                                    <?php echo htmlspecialchars($doctor['first_name'] . ' ' . $doctor['last_name']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="mb-3">
                        <label class="form-label">Select Date</label>
                        <input type="date" class="form-control" id="appointmentDate" name="appointment_date" required>
                    </div>

                    <div class="mb-3">
                        <label class="form-label">Appointment Type</label>
                        <select class="form-control" name="is_online_appointment" id="appointmentType">
                            <option value="0">In-Person</option>
                            <option value="1">Online</option>
                        </select>
                    </div>

                    <div class="mb-3">
                        <label class="form-label">Reason for Appointment</label>
                        <input type="text" class="form-control" name="reason" id="appointmentReason" 
                               placeholder="e.g., Regular Checkup, Tooth Cleaning">
                    </div>

                    <div class="mb-3">
                        <label class="form-label">Status</label>
                        <select class="form-control" name="status" id="appointmentStatus">
                            <option value="scheduled">Scheduled</option>
                            <option value="confirmed">Confirmed</option>
                            <option value="checked_in">Checked In</option>
                            <option value="in_progress">In Progress</option>
                            <option value="completed">Completed</option>
                            <option value="cancelled">Cancelled</option>
                            <option value="no_show">No Show</option>
                        </select>
                    </div>

                    <div class="mb-3">
                        <label class="form-label">Notes</label>
                        <textarea class="form-control" name="notes" id="appointmentNotes" rows="3" 
                                  placeholder="Additional notes about the appointment..."></textarea>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                    <button type="button" class="btn btn-primary" onclick="createAppointment()">Create Appointment</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- ========== EDIT APPOINTMENT MODAL ========== -->
<div class="modal fade" id="editAppointmentModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Edit Appointment</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST">
                <div class="modal-body">
                    <input type="hidden" name="appointment_id" id="editAppointmentId">
                    <div class="mb-3">
                        <label class="form-label">Patient</label>
                        <input type="text" class="form-control" id="editPatientDisplay" readonly>
                    </div>

                    <div class="mb-3">
                        <label class="form-label">Select Doctor</label>
                        <select class="form-control" name="user_id" id="editDoctorSelect" required>
                            <option value="">-- Select a doctor --</option>
                            <?php foreach ($doctors_list as $doctor): ?>
                                <option value="<?php echo htmlspecialchars($doctor['user_id']); ?>">
                                    <?php echo htmlspecialchars($doctor['first_name'] . ' ' . $doctor['last_name']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="row mb-3">
                        <div class="col-md-6">
                            <label class="form-label">Appointment Date</label>
                            <input type="date" class="form-control" name="appointment_date" id="editAppointmentDate" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Appointment Type</label>
                            <select class="form-control" name="is_online_appointment" id="editAppointmentType">
                                <option value="0">In-Person</option>
                                <option value="1">Online</option>
                            </select>
                        </div>
                    </div>

                    <div class="mb-3">
                        <label class="form-label">Reason for Appointment</label>
                        <input type="text" class="form-control" name="reason" id="editAppointmentReason">
                    </div>

                    <div class="mb-3">
                        <label class="form-label">Status</label>
                        <select class="form-control" name="status" id="editAppointmentStatus">
                            <option value="scheduled">Scheduled</option>
                            <option value="confirmed">Confirmed</option>
                            <option value="checked_in">Checked In</option>
                            <option value="in_progress">In Progress</option>
                            <option value="completed">Completed</option>
                            <option value="cancelled">Cancelled</option>
                            <option value="no_show">No Show</option>
                        </select>
                    </div>

                    <div class="mb-3">
                        <label class="form-label">Notes</label>
                        <textarea class="form-control" name="notes" id="editAppointmentNotes" rows="3"></textarea>
                    </div>
                </div>
                <input type="hidden" name="action" value="update">
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                    <button type="submit" class="btn btn-primary">Update Appointment</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
// Create appointment function
function createAppointment() {
    const patientId = document.getElementById('selectedPatientId').value;
    const appointmentDate = document.getElementById('appointmentDate').value;
    const doctorId = document.getElementById('doctorSelect').value;
    const reason = document.getElementById('appointmentReason').value;
    const isOnline = document.getElementById('appointmentType').value;
    const status = document.getElementById('appointmentStatus').value;
    const notes = document.getElementById('appointmentNotes').value;
    const messageDiv = document.getElementById('appointmentMessage');

    if (!patientId || !appointmentDate || !doctorId) {
        messageDiv.innerHTML = '<div class="alert alert-danger">Please select a patient, doctor, and appointment date!</div>';
        return;
    }

    const formData = new FormData();
    formData.append('patient_id', patientId);
    formData.append('appointment_date', appointmentDate);
    formData.append('user_id', doctorId);
    formData.append('reason', reason);
    formData.append('is_online_appointment', isOnline);
    formData.append('status', status);
    formData.append('notes', notes);

    fetch('../assets/api/appointments/create_appointment.php', {
        method: 'POST',
        body: formData
    })
    .then(response => {
        if (!response.ok) {
            throw new Error(`HTTP error! status: ${response.status}`);
        }
        return response.text();
    })
    .then(text => {
        try {
            const data = JSON.parse(text);
            if (data.success) {
                messageDiv.innerHTML = '<div class="alert alert-success">Appointment created successfully!</div>';
                setTimeout(() => {
                    location.reload();
                }, 1500);
            } else {
                messageDiv.innerHTML = '<div class="alert alert-danger">' + data.message + '</div>';
            }
        } catch (e) {
            messageDiv.innerHTML = '<div class="alert alert-danger">Server Error: ' + text.substring(0, 200) + '</div>';
            console.error('JSON Parse Error:', e);
            console.error('Response:', text);
        }
    })
    .catch(error => {
        messageDiv.innerHTML = '<div class="alert alert-danger">Error: ' + error.message + '</div>';
        console.error('Error:', error);
    });
}

// timeslot/branch UI removed

// Patient search functionality
const patientSearchInput = document.getElementById('patientSearchInput');
const patientList = document.getElementById('patientList');
const patientSearchBtn = document.getElementById('patientSearchBtn');
const selectedPatientDisplay = document.getElementById('selectedPatientDisplay');
const selectedPatientId = document.getElementById('selectedPatientId');

const allPatients = <?php echo json_encode($patients_list); ?>;

patientSearchInput.addEventListener('input', function() {
    const searchTerm = this.value.toLowerCase();
    if (searchTerm.length === 0) {
        patientList.style.display = 'none';
        return;
    }

    const filtered = allPatients.filter(patient => 
        patient.first_name.toLowerCase().includes(searchTerm) ||
        patient.last_name.toLowerCase().includes(searchTerm) ||
        patient.email.toLowerCase().includes(searchTerm) ||
        patient.phone_number.includes(searchTerm)
    );

    patientList.innerHTML = '';
    if (filtered.length > 0) {
        filtered.forEach(patient => {
            const div = document.createElement('a');
            div.href = '#';
            div.className = 'list-group-item list-group-item-action';
            div.innerHTML = `<strong>${patient.first_name} ${patient.last_name}</strong><br>
                            <small>${patient.email} | ${patient.phone_number}</small>`;
            div.onclick = (e) => {
                e.preventDefault();
                selectedPatientDisplay.value = `${patient.first_name} ${patient.last_name}`;
                selectedPatientId.value = patient.patient_id;
                patientList.style.display = 'none';
                patientSearchInput.value = '';
            };
            patientList.appendChild(div);
        });
        patientList.style.display = 'block';
    } else {
        patientList.innerHTML = '<div class="list-group-item">No patients found</div>';
        patientList.style.display = 'block';
    }
});

// Hide patient list when clicking outside
document.addEventListener('click', function(event) {
    if (!event.target.closest('.input-group') && !event.target.closest('#patientList')) {
        patientList.style.display = 'none';
    }
});

function editAppointment(appointment) {
    document.getElementById('editAppointmentId').value = appointment.appointment_id;
    document.getElementById('editPatientDisplay').value = appointment.first_name + ' ' + appointment.last_name;
    document.getElementById('editDoctorSelect').value = appointment.user_id;
    document.getElementById('editAppointmentDate').value = appointment.appointment_date;
    document.getElementById('editAppointmentType').value = appointment.is_online_appointment;
    document.getElementById('editAppointmentReason').value = appointment.reason;
    document.getElementById('editAppointmentStatus').value = appointment.status;
    document.getElementById('editAppointmentNotes').value = appointment.notes;
}
</script>
