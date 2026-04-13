<?php
include dirname(dirname(__DIR__)) . '/config/database.php';

// Get date range from query
$reportType = $_GET['type'] ?? 'appointments';
$startDate = $_GET['start_date'] ?? date('Y-m-d', strtotime('-30 days'));
$endDate = $_GET['end_date'] ?? date('Y-m-d');
$doctorId = $_GET['doctor_id'] ?? '';
$status = $_GET['status'] ?? '';

// Validate dates
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $startDate)) $startDate = date('Y-m-d', strtotime('-30 days'));
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $endDate)) $endDate = date('Y-m-d');

// Fetch all doctors for filter dropdown
$doctors = [];
$doctorRes = $conn->query("SELECT user_id, first_name, last_name FROM users u 
    INNER JOIN role r ON r.role_id = u.role_id 
    WHERE r.role_name = 'doctor' 
    ORDER BY u.first_name ASC");
if ($doctorRes) {
    while ($row = $doctorRes->fetch_assoc()) {
        $doctors[] = $row;
    }
}

// APPOINTMENTS REPORT
$appointmentsData = [];
if ($reportType === 'appointments') {
    $sql = "SELECT a.appointment_id, a.appointment_date, a.status, a.reason,
            NULL AS start_time, NULL AS end_time,
            p.first_name AS patient_first, p.last_name AS patient_last,
            d.first_name AS doctor_first, d.last_name AS doctor_last,
            s.service_name,
            TIMESTAMPDIFF(MINUTE, NULL, NULL) AS duration_mins
        FROM appointments a
        -- timeslot removed; duration/time not available
        LEFT JOIN patients p ON p.patient_id = a.patient_id
        LEFT JOIN users d ON d.user_id = a.user_id
        LEFT JOIN selected_services ss ON ss.appointment_id = a.appointment_id
        LEFT JOIN services s ON s.service_id = ss.service_id
        WHERE DATE(a.appointment_date) BETWEEN ? AND ?";
    
    if ($doctorId) $sql .= " AND a.user_id = ?";
    if ($status) $sql .= " AND a.status = ?";
    
    $sql .= " ORDER BY a.appointment_date DESC, a.created_at DESC";
    
    $stmt = $conn->prepare($sql);
    if ($stmt) {
        $params = [$startDate, $endDate];
        $types = 'ss';
        if ($doctorId) {
            $params[] = $doctorId;
            $types .= 'i';
        }
        if ($status) {
            $params[] = $status;
            $types .= 's';
        }
        
        $bind = [$types];
        foreach ($params as $k => $v) {
            $bind[] = &$params[$k];
        }
        call_user_func_array([$stmt, 'bind_param'], $bind);
        
        $stmt->execute();
        $res = $stmt->get_result();
        while ($row = $res->fetch_assoc()) {
            $appointmentsData[] = $row;
        }
        $stmt->close();
    }
}

// Build time-series for appointments in selected range (for charts)
$dateLabels = [];
$dateValues = [];
$startTs = strtotime($startDate);
$endTs = strtotime($endDate);
if ($startTs && $endTs && $startTs <= $endTs) {
    for ($d = $startTs; $d <= $endTs; $d = strtotime('+1 day', $d)) {
        $dateLabels[] = date('Y-m-d', $d);
        $dateValues[] = 0;
    }
    $sql = "SELECT DATE(appointment_date) AS dt, COUNT(*) AS c FROM appointments WHERE DATE(appointment_date) BETWEEN ? AND ?";
    $types = 'ss'; $params = [$startDate, $endDate];
    if ($doctorId) { $sql .= ' AND user_id = ?'; $types .= 'i'; $params[] = (int)$doctorId; }
    $sql .= ' GROUP BY dt ORDER BY dt ASC';
    $stmt = $conn->prepare($sql);
    if ($stmt) {
        // bind params
        $bind = [$types];
        foreach ($params as $k => $v) $bind[] = &$params[$k];
        call_user_func_array([$stmt, 'bind_param'], $bind);
        $stmt->execute();
        $res = $stmt->get_result();
        $map = [];
        if ($res) while ($r = $res->fetch_assoc()) $map[$r['dt']] = (int)$r['c'];
        $stmt->close();
        foreach ($dateLabels as $idx => $lbl) if (isset($map[$lbl])) $dateValues[$idx] = $map[$lbl];
    }
}

// SERVICES REPORT
$servicesData = [];
$serviceStats = [];
if ($reportType === 'services') {
    $sql = "SELECT s.service_id, s.service_name, COUNT(ss.selected_service_id) AS usage_count,
            GROUP_CONCAT(DISTINCT u.first_name, ' ', u.last_name) AS performed_by
        FROM services s
        LEFT JOIN selected_services ss ON ss.service_id = s.service_id
        LEFT JOIN appointments a ON a.appointment_id = ss.appointment_id
        LEFT JOIN users u ON u.user_id = a.user_id
        WHERE s.is_active = 1 OR s.is_active IS NULL
        GROUP BY s.service_id, s.service_name
        ORDER BY usage_count DESC";
    
    $res = $conn->query($sql);
    if ($res) {
        while ($row = $res->fetch_assoc()) {
            $servicesData[] = $row;
        }
    }
}

// DOCTOR PERFORMANCE REPORT
$doctorPerformanceData = [];
if ($reportType === 'doctor_performance') {
    $sql = "SELECT u.user_id, u.first_name, u.last_name, u.email,
            COUNT(a.appointment_id) AS total_appointments,
            SUM(CASE WHEN a.status = 'completed' THEN 1 ELSE 0 END) AS completed_count,
            SUM(CASE WHEN a.status = 'cancelled' THEN 1 ELSE 0 END) AS cancelled_count,
            ROUND(AVG(CASE WHEN a.status = 'completed' THEN 1 ELSE 0 END) * 100, 2) AS completion_rate
        FROM users u
        INNER JOIN role r ON r.role_id = u.role_id
        LEFT JOIN appointments a ON a.user_id = u.user_id AND DATE(a.appointment_date) BETWEEN ? AND ?
        WHERE r.role_name = 'doctor'
        GROUP BY u.user_id, u.first_name, u.last_name, u.email
        ORDER BY total_appointments DESC";
    
    $stmt = $conn->prepare($sql);
    if ($stmt) {
        $stmt->bind_param('ss', $startDate, $endDate);
        $stmt->execute();
        $res = $stmt->get_result();
        while ($row = $res->fetch_assoc()) {
            $doctorPerformanceData[] = $row;
        }
        $stmt->close();
    }
}

// PATIENT ANALYTICS REPORT
 $patientAnalyticsData = [];
 $totalPatients = 0;
 $newPatients = 0;
 $patientsWithAppts = 0;
 // Always load patient analytics data (not just when tab is selected)
 if (function_exists('table_exists') && table_exists($conn, 'patients')) {
     try {
         // Total patients
         $totalRes = $conn->query("SELECT COUNT(*) AS total FROM patients");
         $totalPatients = $totalRes ? (int)$totalRes->fetch_assoc()['total'] : 0;

         // New patients in date range
         $newRes = $conn->query("SELECT COUNT(*) AS total FROM patients WHERE DATE(created_at) BETWEEN '$startDate' AND '$endDate'");
         $newPatients = $newRes ? (int)$newRes->fetch_assoc()['total'] : 0;

         // Patients with appointments (guard appointments table)
         if (table_exists($conn, 'appointments')) {
             $appointRes = $conn->query("SELECT COUNT(DISTINCT patient_id) AS total FROM appointments WHERE DATE(appointment_date) BETWEEN '$startDate' AND '$endDate'");
             $patientsWithAppts = $appointRes ? (int)$appointRes->fetch_assoc()['total'] : 0;
         } else {
             $patientsWithAppts = 0;
         }
     } catch (Throwable $e) {
         error_log('Reports patient analytics query failed: ' . $e->getMessage());
         $totalPatients = $newPatients = $patientsWithAppts = 0;
     }
} else {
     error_log('Reports: patients table missing; skipping patient analytics queries');
}

if ($reportType === 'patient_analytics') {
    // Top patients (most appointments) - filtered by date range
    $topRes = $conn->query("SELECT p.patient_id, p.first_name, p.last_name, p.email, p.phone,
        COUNT(a.appointment_id) AS appointment_count,
        MAX(a.appointment_date) AS last_appointment
        FROM patients p
        LEFT JOIN appointments a ON a.patient_id = p.patient_id
        WHERE DATE(a.appointment_date) BETWEEN '$startDate' AND '$endDate' OR a.appointment_id IS NULL
        GROUP BY p.patient_id, p.first_name, p.last_name, p.email, p.phone
        HAVING appointment_count > 0
        ORDER BY appointment_count DESC
        LIMIT 20");
    if ($topRes) {
        while ($row = $topRes->fetch_assoc()) {
            $patientAnalyticsData[] = $row;
        }
    }
}

// Appointment status summary
$statusSummary = [];
$statusRes = $conn->query("SELECT status, COUNT(*) AS count FROM appointments 
    WHERE DATE(appointment_date) BETWEEN '$startDate' AND '$endDate'
    GROUP BY status");
if ($statusRes) {
    while ($row = $statusRes->fetch_assoc()) {
        $statusSummary[] = $row;
    }
}

// Prepare arrays for charts
$statusLabels = [];
$statusCounts = [];
foreach ($statusSummary as $s) { $statusLabels[] = $s['status']; $statusCounts[] = (int)$s['count']; }

$servicesLabels = [];
$servicesCounts = [];
foreach ($servicesData as $s) { $servicesLabels[] = $s['service_name']; $servicesCounts[] = (int)$s['usage_count']; }

$doctorLabels = [];
$doctorTotalAppts = [];
$doctorCompletion = [];
foreach ($doctorPerformanceData as $d) { $doctorLabels[] = $d['first_name'] . ' ' . $d['last_name']; $doctorTotalAppts[] = (int)$d['total_appointments']; $doctorCompletion[] = (float)$d['completion_rate']; }

$totalAppointmentsRange = array_sum($statusCounts);
$completedAppointmentsRange = 0;
$cancelledAppointmentsRange = 0;
foreach ($statusSummary as $s) {
    $k = strtolower((string)($s['status'] ?? ''));
    if ($k === 'completed') $completedAppointmentsRange = (int)$s['count'];
    if ($k === 'cancelled') $cancelledAppointmentsRange = (int)$s['count'];
}
$completionRateRange = $totalAppointmentsRange > 0 ? round(($completedAppointmentsRange / $totalAppointmentsRange) * 100, 1) : 0;
$dayCount = max(1, count($dateLabels));
$avgAppointmentsPerDay = $totalAppointmentsRange > 0 ? round($totalAppointmentsRange / $dayCount, 1) : 0;

// Patient analytics pie: new vs returning
$returning = max(0, (int)$totalPatients - (int)$newPatients);
 $patientPie = [ 'labels' => ['New Patients','Returning Patients'], 'data' => [ (int)$newPatients, $returning ] ];
?>

<div class="card">
    <div class="card-header d-flex justify-content-between align-items-center gap-2" style="flex-wrap: nowrap;">
        <div class="d-flex align-items-center gap-2" style="min-width:0;">
            <h2 class="card-title mb-0">Reports</h2>
            <span class="text-muted small d-none d-lg-inline">Analytics & Performance</span>
        </div>
    </div>

    <!-- Tab Navigation -->
    <ul class="nav nav-tabs border-bottom" role="tablist" style="padding: 0 1rem; margin-top: 1rem;">
        <li class="nav-item" role="presentation">
            <button class="nav-link <?php echo $reportType === 'appointments' ? 'active' : ''; ?>" 
                id="appointments-tab" data-bs-toggle="tab" data-bs-target="#appointments-pane" type="button" role="tab">
                <i class="bi bi-calendar-event"></i> Appointments
            </button>
        </li>
        <li class="nav-item" role="presentation">
            <button class="nav-link <?php echo $reportType === 'services' ? 'active' : ''; ?>" 
                id="services-tab" data-bs-toggle="tab" data-bs-target="#services-pane" type="button" role="tab">
                <i class="bi bi-wrench"></i> Services
            </button>
        </li>
        <li class="nav-item" role="presentation">
            <button class="nav-link <?php echo $reportType === 'doctor_performance' ? 'active' : ''; ?>" 
                id="doctor-tab" data-bs-toggle="tab" data-bs-target="#doctor-pane" type="button" role="tab">
                <i class="bi bi-person-badge"></i> Doctors
            </button>
        </li>
        <li class="nav-item" role="presentation">
            <button class="nav-link <?php echo $reportType === 'patient_analytics' ? 'active' : ''; ?>" 
                id="patient-tab" data-bs-toggle="tab" data-bs-target="#patient-pane" type="button" role="tab">
                <i class="bi bi-people"></i> Patients
            </button>
        </li>
    </ul>

    <div class="card-body">
        <!-- Filter Section -->
        <div class="row mb-3">
            <div class="col-md-2">
                <label class="form-label small">From</label>
                <input type="date" class="form-control form-control-sm" id="startDate" value="<?php echo htmlspecialchars($startDate); ?>">
            </div>
            <div class="col-md-2">
                <label class="form-label small">To</label>
                <input type="date" class="form-control form-control-sm" id="endDate" value="<?php echo htmlspecialchars($endDate); ?>">
            </div>
            <div class="col-md-3">
                <label class="form-label small">Doctor</label>
                <select class="form-control form-control-sm" id="doctorFilter">
                    <option value="">All Doctors</option>
                    <?php foreach ($doctors as $doc): ?>
                        <option value="<?php echo $doc['user_id']; ?>" <?php echo $doctorId == $doc['user_id'] ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars($doc['first_name'] . ' ' . $doc['last_name']); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-3">
                <label class="form-label small">Status</label>
                <select class="form-control form-control-sm" id="statusFilter">
                    <option value="">All Status</option>
                    <option value="scheduled" <?php echo $status === 'scheduled' ? 'selected' : ''; ?>>Scheduled</option>
                    <option value="confirmed" <?php echo $status === 'confirmed' ? 'selected' : ''; ?>>Confirmed</option>
                    <option value="completed" <?php echo $status === 'completed' ? 'selected' : ''; ?>>Completed</option>
                    <option value="cancelled" <?php echo $status === 'cancelled' ? 'selected' : ''; ?>>Cancelled</option>
                </select>
            </div>
            <div class="col-md-2 d-flex align-items-end gap-2">
                <button class="btn btn-primary btn-sm w-100" onclick="applyFilters()">
                    <i class="bi bi-search"></i> Filter
                </button>
            </div>
        </div>

        <hr>

        <div class="row g-3 mb-3">
            <div class="col-12 col-md-6 col-xl-3">
                <div class="card dental-kpi-card p-3 h-100">
                    <h6 class="mb-1">Appointments in Range</h6>
                    <div class="display-6 fw-bold"><?php echo (int)$totalAppointmentsRange; ?></div>
                    <div class="text-muted small"><?php echo htmlspecialchars($startDate); ?> to <?php echo htmlspecialchars($endDate); ?></div>
                </div>
            </div>
            <div class="col-12 col-md-6 col-xl-3">
                <div class="card dental-kpi-card p-3 h-100">
                    <h6 class="mb-1">Completed</h6>
                    <div class="display-6 fw-bold"><?php echo (int)$completedAppointmentsRange; ?></div>
                    <div class="text-muted small">Completion rate: <?php echo (float)$completionRateRange; ?>%</div>
                </div>
            </div>
            <div class="col-12 col-md-6 col-xl-3">
                <div class="card dental-kpi-card p-3 h-100">
                    <h6 class="mb-1">Cancelled</h6>
                    <div class="display-6 fw-bold"><?php echo (int)$cancelledAppointmentsRange; ?></div>
                    <div class="text-muted small">Status trend indicator</div>
                </div>
            </div>
            <div class="col-12 col-md-6 col-xl-3">
                <div class="card dental-kpi-card p-3 h-100">
                    <h6 class="mb-1">Avg per Day</h6>
                    <div class="display-6 fw-bold"><?php echo (float)$avgAppointmentsPerDay; ?></div>
                    <div class="text-muted small">Across selected date window</div>
                </div>
            </div>
        </div>

        <div class="row g-3 mb-4">
            <div class="col-12 col-xl-5">
                <div class="card dental-surface-card h-100">
                    <div class="card-body">
                        <h6 class="card-title small mb-2">Overall Status Breakdown</h6>
                        <div id="overviewStatusChart" style="min-height:240px;"></div>
                    </div>
                </div>
            </div>
            <div class="col-12 col-xl-7">
                <div class="card dental-surface-card h-100">
                    <div class="card-body">
                        <h6 class="card-title small mb-2">Daily Appointments Trend</h6>
                        <div id="overviewTrendChart" style="min-height:240px;"></div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Tab Content -->
        <div class="tab-content">
            <!-- Appointments Tab -->
            <div class="tab-pane fade <?php echo $reportType === 'appointments' ? 'show active' : ''; ?>" id="appointments-pane">
                <div class="row mb-3">
                    <div class="col-md-6">
                        <div class="card mb-3">
                            <div class="card-body">
                                <h6 class="card-title small">Appointment Status</h6>
                                <div id="appointmentsStatusChart" style="min-height:220px;"></div>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-6">
                        <div class="card mb-3">
                            <div class="card-body">
                                <h6 class="card-title small">Appointments Over Time</h6>
                                <div id="appointmentsTimeSeriesChart" style="min-height:220px;"></div>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="table-responsive">
                    <table class="table table-sm table-hover">
                        <thead class="table-light">
                            <tr>
                                <th>Date</th>
                                <th>Time</th>
                                <th>Patient</th>
                                <th>Doctor</th>
                                <th>Service</th>
                                <th>Duration</th>
                                <th>Status</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (count($appointmentsData) > 0): ?>
                                <?php foreach ($appointmentsData as $appt): ?>
                                    <tr>
                                        <td><?php echo date('M d, Y', strtotime($appt['appointment_date'])); ?></td>
                                        <td><?php echo $appt['start_time'] ? substr($appt['start_time'], 0, 5) : '—'; ?></td>
                                        <td><?php echo htmlspecialchars($appt['patient_first'] . ' ' . $appt['patient_last']); ?></td>
                                        <td><?php echo htmlspecialchars($appt['doctor_first'] . ' ' . $appt['doctor_last']); ?></td>
                                        <td><?php echo htmlspecialchars($appt['service_name'] ?? '—'); ?></td>
                                        <td><?php echo $appt['duration_mins'] ? $appt['duration_mins'] . ' min' : '—'; ?></td>
                                        <td>
                                            <span class="badge bg-<?php
                                                echo $appt['status'] === 'completed' ? 'success' : 
                                                     ($appt['status'] === 'cancelled' ? 'danger' : 
                                                      ($appt['status'] === 'confirmed' ? 'info' : 'warning'));
                                            ?>">
                                                <?php echo ucfirst($appt['status']); ?>
                                            </span>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <tr>
                                    <td colspan="7" class="text-center text-muted py-3">No appointments found</td>
                                </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
                <div class="text-muted small mt-2">
                    Total: <?php echo count($appointmentsData); ?> appointments
                </div>
            </div>

            <!-- Services Tab -->
            <div class="tab-pane fade <?php echo $reportType === 'services' ? 'show active' : ''; ?>" id="services-pane">
                <div class="mb-3">
                    <div id="servicesUsageChart" style="min-height:300px;"></div>
                </div>
                <div class="table-responsive">
                    <table class="table table-sm table-hover">
                        <thead class="table-light">
                            <tr>
                                <th>Service</th>
                                <th>Usage Count</th>
                                <th>Performed By</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (count($servicesData) > 0): ?>
                                <?php foreach ($servicesData as $svc): ?>
                                    <tr>
                                        <td><?php echo htmlspecialchars($svc['service_name']); ?></td>
                                        <td>
                                            <span class="badge bg-primary"><?php echo $svc['usage_count']; ?></span>
                                        </td>
                                        <td><?php echo htmlspecialchars($svc['performed_by'] ?? '—'); ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <tr>
                                    <td colspan="3" class="text-center text-muted py-3">No services found</td>
                                </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>

            <!-- Doctor Performance Tab -->
            <div class="tab-pane fade <?php echo $reportType === 'doctor_performance' ? 'show active' : ''; ?>" id="doctor-pane">
                <div class="mb-3">
                    <div id="doctorPerfChart" style="min-height:300px;"></div>
                </div>
                <div class="table-responsive">
                    <table class="table table-sm table-hover">
                        <thead class="table-light">
                            <tr>
                                <th>Doctor</th>
                                <th>Email</th>
                                <th>Total Appts</th>
                                <th>Completed</th>
                                <th>Cancelled</th>
                                <th>Completion %</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (count($doctorPerformanceData) > 0): ?>
                                <?php foreach ($doctorPerformanceData as $doc): ?>
                                    <tr>
                                        <td><?php echo htmlspecialchars($doc['first_name'] . ' ' . $doc['last_name']); ?></td>
                                        <td><?php echo htmlspecialchars($doc['email']); ?></td>
                                        <td><?php echo $doc['total_appointments']; ?></td>
                                        <td><span class="badge bg-success"><?php echo $doc['completed_count']; ?></span></td>
                                        <td><span class="badge bg-danger"><?php echo $doc['cancelled_count']; ?></span></td>
                                        <td><?php echo $doc['completion_rate'] ?? '0'; ?>%</td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <tr>
                                    <td colspan="6" class="text-center text-muted py-3">No doctors found</td>
                                </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>

            <!-- Patient Analytics Tab -->
            <div class="tab-pane fade <?php echo $reportType === 'patient_analytics' ? 'show active' : ''; ?>" id="patient-pane">
                <div class="row mb-3">
                    <div class="col-md-3">
                        <div class="card bg-light border-0">
                            <div class="card-body text-center">
                                <h5 class="card-title small text-muted">Total Patients</h5>
                                <h2 class="text-primary"><?php echo $totalPatients; ?></h2>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="card bg-light border-0">
                            <div class="card-body text-center">
                                <h5 class="card-title small text-muted">New Patients</h5>
                                <h2 class="text-success"><?php echo $newPatients; ?></h2>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="card bg-light border-0">
                            <div class="card-body text-center">
                                <h5 class="card-title small text-muted">With Appointments</h5>
                                <h2 class="text-info"><?php echo $patientsWithAppts; ?></h2>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="row">
                    <div class="col-md-6">
                        <h6 class="mt-4 mb-3">Top Patients by Appointment Count</h6>
                    </div>
                    <div class="col-md-6">
                        <div id="patientAnalyticsChart" style="min-height:220px;"></div>
                    </div>
                </div>
                <div class="table-responsive">
                    <table class="table table-sm table-hover">
                        <thead class="table-light">
                            <tr>
                                <th>Patient</th>
                                <th>Email</th>
                                <th>Phone</th>
                                <th>Appointments</th>
                                <th>Last Visit</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (count($patientAnalyticsData) > 0): ?>
                                <?php foreach ($patientAnalyticsData as $pat): ?>
                                    <tr>
                                        <td><?php echo htmlspecialchars($pat['first_name'] . ' ' . $pat['last_name']); ?></td>
                                        <td><?php echo htmlspecialchars($pat['email']); ?></td>
                                        <td><?php echo htmlspecialchars($pat['phone']); ?></td>
                                        <td><span class="badge bg-primary"><?php echo $pat['appointment_count']; ?></span></td>
                                        <td><?php echo $pat['last_appointment'] ? date('M d, Y', strtotime($pat['last_appointment'])) : '—'; ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <tr>
                                    <td colspan="5" class="text-center text-muted py-3">No patient data found</td>
                                </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
function applyFilters() {
    const type = document.querySelector('.nav-link.active')?.getAttribute('data-bs-target')?.replace('#', '').replace('-pane', '') || 'appointments';
    const startDate = document.getElementById('startDate').value;
    const endDate = document.getElementById('endDate').value;
    const doctorId = document.getElementById('doctorFilter').value;
    const status = document.getElementById('statusFilter').value;

    const params = new URLSearchParams({
        page: 'reports',
        type: type,
        start_date: startDate,
        end_date: endDate,
        doctor_id: doctorId,
        status: status
    });

    window.location.href = 'admin.php?' + params.toString();
}

// Allow Enter key to apply filters
document.getElementById('startDate')?.addEventListener('keypress', (e) => {
    if (e.key === 'Enter') applyFilters();
});
document.getElementById('endDate')?.addEventListener('keypress', (e) => {
    if (e.key === 'Enter') applyFilters();
});
</script>
<script>
// Render ApexCharts for reports after vendor scripts are loaded
(function () {
    const renderNoData = (id, label) => {
        const el = document.getElementById(id);
        if (!el) return;
        el.innerHTML = '<div class="d-flex align-items-center justify-content-center text-muted small" style="min-height:220px;">No data available for ' + label + '.</div>';
    };

    const renderCharts = () => {

        // Appointments status pie
        const statusLabels = <?php echo json_encode($statusLabels); ?>;
        const statusCounts = <?php echo json_encode($statusCounts); ?>;
        if (statusLabels && statusLabels.length) {
            const statusEl = document.getElementById('appointmentsStatusChart');
            const overviewStatusEl = document.getElementById('overviewStatusChart');

            const opts = {
                chart: { type: 'donut', height: 250 },
                series: statusCounts,
                labels: statusLabels,
                legend: { position: 'bottom' }
            };
            if (statusEl) new ApexCharts(statusEl, opts).render();

            const overviewDonut = {
                chart: { type: 'donut', height: 240 },
                series: statusCounts,
                labels: statusLabels,
                legend: { position: 'bottom' },
                colors: ['#0d6efd', '#198754', '#dc3545', '#ffc107', '#6f42c1', '#20c997']
            };
            if (overviewStatusEl) new ApexCharts(overviewStatusEl, overviewDonut).render();
        } else {
            renderNoData('appointmentsStatusChart', 'appointment status');
            renderNoData('overviewStatusChart', 'status breakdown');
        }

        // Appointments time series
        const apptLabels = <?php echo json_encode($dateLabels); ?>;
        const apptValues = <?php echo json_encode($dateValues); ?>;
        if (apptLabels && apptLabels.length) {
            const timeSeriesEl = document.getElementById('appointmentsTimeSeriesChart');
            const overviewTrendEl = document.getElementById('overviewTrendChart');

            const opts2 = {
                chart: { type: 'area', height: 250, toolbar: { show: false } },
                series: [{ name: 'Appointments', data: apptValues }],
                xaxis: { categories: apptLabels },
                stroke: { curve: 'smooth' },
                colors: ['#2b8aef']
            };
            if (timeSeriesEl) new ApexCharts(timeSeriesEl, opts2).render();

            const overviewTrend = {
                chart: { type: 'area', height: 240, toolbar: { show: false } },
                series: [{ name: 'Appointments', data: apptValues }],
                xaxis: { categories: apptLabels },
                stroke: { curve: 'smooth', width: 2 },
                fill: {
                    type: 'gradient',
                    gradient: {
                        shadeIntensity: 1,
                        opacityFrom: 0.35,
                        opacityTo: 0.06,
                        stops: [0, 90, 100]
                    }
                },
                colors: ['#0d6efd']
            };
            if (overviewTrendEl) new ApexCharts(overviewTrendEl, overviewTrend).render();
        } else {
            renderNoData('appointmentsTimeSeriesChart', 'appointments over time');
            renderNoData('overviewTrendChart', 'appointments trend');
        }

        // Services usage
        const svcLabels = <?php echo json_encode($servicesLabels); ?>;
        const svcCounts = <?php echo json_encode($servicesCounts); ?>;
        if (svcLabels && svcLabels.length) {
            const svcEl = document.getElementById('servicesUsageChart');
            const opts3 = { chart: { type: 'bar', height: 320 }, series: [{ name: 'Usage', data: svcCounts }], xaxis: { categories: svcLabels }, plotOptions: { bar: { horizontal: false } } };
            if (svcEl) new ApexCharts(svcEl, opts3).render();
        } else {
            renderNoData('servicesUsageChart', 'services usage');
        }

        // Doctor performance
        const docLabels = <?php echo json_encode($doctorLabels); ?>;
        const docAppts = <?php echo json_encode($doctorTotalAppts); ?>;
        const docComp = <?php echo json_encode($doctorCompletion); ?>;
        if (docLabels && docLabels.length) {
            const docEl = document.getElementById('doctorPerfChart');
            const opts4 = {
                chart: { type: 'bar', height: 320 },
                series: [{ name: 'Total Appts', data: docAppts }, { name: 'Completion %', data: docComp }],
                xaxis: { categories: docLabels },
                yaxis: [{ title: { text: 'Count' } }, { opposite: true, title: { text: 'Completion %' } }]
            };
            if (docEl) new ApexCharts(docEl, opts4).render();
        } else {
            renderNoData('doctorPerfChart', 'doctor performance');
        }

        // Patient analytics pie
        const patientPie = <?php echo json_encode($patientPie); ?>;
        if (patientPie && patientPie.labels) {
            const patientEl = document.getElementById('patientAnalyticsChart');
            const opts5 = { chart: { type: 'donut', height: 220 }, series: patientPie.data, labels: patientPie.labels, legend: { position: 'bottom' } };
            if (patientEl) new ApexCharts(patientEl, opts5).render();
        } else {
            renderNoData('patientAnalyticsChart', 'patient analytics');
        }
    };

    let attempt = 0;
    const maxAttempts = 30;
    const bootstrapCharts = () => {
        attempt += 1;
        if (typeof ApexCharts === 'undefined') {
            if (attempt < maxAttempts) {
                setTimeout(bootstrapCharts, 100);
                return;
            }
            renderNoData('overviewStatusChart', 'status breakdown (chart library failed to load)');
            renderNoData('overviewTrendChart', 'appointments trend (chart library failed to load)');
            renderNoData('appointmentsStatusChart', 'appointment status (chart library failed to load)');
            renderNoData('appointmentsTimeSeriesChart', 'appointments over time (chart library failed to load)');
            renderNoData('servicesUsageChart', 'services usage (chart library failed to load)');
            renderNoData('doctorPerfChart', 'doctor performance (chart library failed to load)');
            renderNoData('patientAnalyticsChart', 'patient analytics (chart library failed to load)');
            return;
        }
        try {
            renderCharts();
        } catch (e) {
            console.error('Reports chart render error:', e);
            renderNoData('overviewStatusChart', 'status breakdown');
            renderNoData('overviewTrendChart', 'appointments trend');
            renderNoData('appointmentsStatusChart', 'appointment status');
            renderNoData('appointmentsTimeSeriesChart', 'appointments over time');
            renderNoData('servicesUsageChart', 'services usage');
            renderNoData('doctorPerfChart', 'doctor performance');
            renderNoData('patientAnalyticsChart', 'patient analytics');
        }
    };

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', bootstrapCharts);
    } else {
        bootstrapCharts();
    }
})();
</script>
