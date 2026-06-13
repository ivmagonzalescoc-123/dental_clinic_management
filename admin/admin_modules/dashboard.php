<?php
include dirname(dirname(__DIR__)) . '/config/database.php';
include_once dirname(dirname(__DIR__)) . '/config/dashboard_helpers.php';

$today = date('Y-m-d');

function column_exists(mysqli $conn, string $table, string $column): bool
{
	// Use information_schema because many MariaDB/MySQL builds do not support
	// placeholders reliably in SHOW statements.
	$sql = "SELECT 1
		FROM information_schema.COLUMNS
		WHERE TABLE_SCHEMA = DATABASE()
			AND TABLE_NAME = ?
			AND COLUMN_NAME = ?
		LIMIT 1";
	$stmt = $conn->prepare($sql);
	if (!$stmt) return false;
	$stmt->bind_param('ss', $table, $column);
	$stmt->execute();
	$res = $stmt->get_result();
	$ok = $res && $res->num_rows > 0;
	$stmt->close();
	return $ok;
}

function table_exists_local(mysqli $conn, string $table): bool
{
	$sql = "SELECT 1
		FROM information_schema.TABLES
		WHERE TABLE_SCHEMA = DATABASE()
			AND TABLE_NAME = ?
		LIMIT 1";
	$stmt = $conn->prepare($sql);
	if (!$stmt) return false;
	$stmt->bind_param('s', $table);
	$stmt->execute();
	$res = $stmt->get_result();
	$ok = $res && $res->num_rows > 0;
	$stmt->close();
	return $ok;
}

$usersHasIsActive = column_exists($conn, 'users', 'is_active');

$patientsTotal = dashboard_scalar_int($conn, 'SELECT COUNT(*) FROM patients');
$appointmentsToday = dashboard_scalar_int($conn, 'SELECT COUNT(*) FROM appointments WHERE appointment_date = ?', 's', [$today]);
$appointmentsPendingToday = dashboard_scalar_int(
	$conn,
	"SELECT COUNT(*) FROM appointments WHERE appointment_date = ? AND status IN ('scheduled','confirmed')",
	's',
	[$today]
);

$checkedInToday = dashboard_scalar_int(
	$conn,
	"SELECT COUNT(*) FROM appointments WHERE appointment_date = ? AND status IN ('checked_in','in_progress')",
	's',
	[$today]
);
$queueWaitingToday = dashboard_scalar_int(
	$conn,
	"SELECT COUNT(*) FROM appointments WHERE appointment_date = ? AND status = 'confirmed'",
	's',
	[$today]
);

$usersTotal = $usersHasIsActive
	? dashboard_scalar_int($conn, 'SELECT COUNT(*) FROM users WHERE is_active = 1')
	: dashboard_scalar_int($conn, 'SELECT COUNT(*) FROM users');

$doctorsTotal = dashboard_scalar_int(
	$conn,
	$usersHasIsActive
		? "SELECT COUNT(*) FROM users u INNER JOIN role r ON r.role_id = u.role_id WHERE r.role_name = 'doctor' AND u.is_active = 1"
		: "SELECT COUNT(*) FROM users u INNER JOIN role r ON r.role_id = u.role_id WHERE r.role_name = 'doctor'"
);

$secretariesTotal = dashboard_scalar_int(
	$conn,
	$usersHasIsActive
		? "SELECT COUNT(*) FROM users u INNER JOIN role r ON r.role_id = u.role_id WHERE r.role_name = 'secretary' AND u.is_active = 1"
		: "SELECT COUNT(*) FROM users u INNER JOIN role r ON r.role_id = u.role_id WHERE r.role_name = 'secretary'"
);

$pipelineScheduled = dashboard_scalar_int($conn, "SELECT COUNT(*) FROM appointments WHERE status = 'scheduled'");
$pipelineConfirmed = dashboard_scalar_int($conn, "SELECT COUNT(*) FROM appointments WHERE status = 'confirmed'");
$pipelineCheckedIn = dashboard_scalar_int($conn, "SELECT COUNT(*) FROM appointments WHERE status = 'checked_in'");
$pipelineInProgress = dashboard_scalar_int($conn, "SELECT COUNT(*) FROM appointments WHERE status = 'in_progress'");
$pipelineAwaitingRecords = dashboard_scalar_int($conn, "SELECT COUNT(*) FROM appointments WHERE status = 'awaiting_secretary_records'");
$pipelineCompleted = dashboard_scalar_int($conn, "SELECT COUNT(*) FROM appointments WHERE status IN ('completed','records_complete')");

$pipelineBilled = 0;
$pipelinePaid = 0;
if (table_exists_local($conn, 'billing')) {
	$pipelineBilled = dashboard_scalar_int($conn, 'SELECT COUNT(*) FROM billing');
	if (column_exists($conn, 'billing', 'status')) {
		$pipelinePaid = dashboard_scalar_int($conn, "SELECT COUNT(*) FROM billing WHERE status = 'paid'");
	}
}

$pipelineTotal = max(1, $pipelineScheduled + $pipelineConfirmed + $pipelineCheckedIn + $pipelineInProgress + $pipelineAwaitingRecords + $pipelineCompleted);
$pipelineCompletionPct = (int)round(($pipelineCompleted / $pipelineTotal) * 100);

// Today's appointments (top list)
$todayAppointments = [];
$stmt = $conn->prepare("
	    SELECT a.appointment_id, a.appointment_date, a.status, a.reason, a.is_online_appointment,
		   t.start_time, t.end_time,
		   p.first_name, p.last_name,
		   d.first_name AS doctor_first_name, d.last_name AS doctor_last_name
	FROM appointments a
	LEFT JOIN timeslot t ON t.timeslot_id = a.timeslot_id
	LEFT JOIN patients p ON p.patient_id = a.patient_id
	LEFT JOIN users d ON d.user_id = a.user_id
	WHERE a.appointment_date = ?
	    ORDER BY COALESCE(t.start_time, '23:59:59'), a.created_at ASC
	LIMIT 10
");
if ($stmt) {
	$stmt->bind_param('s', $today);
	$stmt->execute();
	$res = $stmt->get_result();
	if ($res) {
		while ($r = $res->fetch_assoc()) {
			$todayAppointments[] = $r;
		}
	}
	$stmt->close();
}

// Recent user login sessions
$recentUserLogs = [];
$res = $conn->query("
	SELECT ul.login_time, ul.logout_time, ul.ip_address,
		   u.first_name, u.last_name, u.email
	FROM user_logs ul
	LEFT JOIN users u ON u.user_id = ul.user_id
	ORDER BY ul.login_time DESC
	LIMIT 8
");
if ($res) {
	while ($r = $res->fetch_assoc()) {
		$recentUserLogs[] = $r;
	}
}

?>

<div class="dental-dashboard-shell">
	<div class="dental-dashboard-hero">
		<div>
			<h4 class="mb-0">Admin Dashboard</h4>
		</div>
		<span class="dental-chip">Operations Center</span>
	</div>

<div class="row g-3">
	<div class="col-12 col-md-6 col-xl-3">
		<?php dashboard_render_kpi_card('Patients', (string)(int)$patientsTotal, 'bi-people'); ?>
	</div>

	<div class="col-12 col-md-6 col-xl-3">
		<?php dashboard_render_kpi_card('Appointments Today', (string)(int)$appointmentsToday, 'bi-calendar-event', 'teal'); ?>
	</div>

	<div class="col-12 col-md-6 col-xl-3">
		<?php dashboard_render_kpi_card('Arrivals Today', (string)(int)$checkedInToday, 'bi-person-check', 'amber'); ?>
	</div>

	<div class="col-12 col-md-6 col-xl-3">
		<?php dashboard_render_kpi_card('Users Active', (string)(int)$usersTotal, 'bi-person-badge', 'violet'); ?>
	</div>

	<div class="col-12">
		<div class="card dental-surface-card">
			<div class="card-body">
				<div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
					<h5 class="card-title mb-0">Dental Management Pipeline <span>| End-to-end workflow</span></h5>
					<span class="badge bg-primary">Completion: <?php echo (int)$pipelineCompletionPct; ?>%</span>
				</div>
				<div class="progress mb-3" style="height: 10px;">
					<div class="progress-bar bg-success" role="progressbar" style="width: <?php echo (int)$pipelineCompletionPct; ?>%" aria-valuenow="<?php echo (int)$pipelineCompletionPct; ?>" aria-valuemin="0" aria-valuemax="100"></div>
				</div>
				<div class="row g-2">
					<div class="col-6 col-md-3 col-xl-2"><div class="dental-pipeline-stat"><div class="small text-muted">Scheduled</div><div class="h5 mb-0"><?php echo (int)$pipelineScheduled; ?></div></div></div>
					<div class="col-6 col-md-3 col-xl-2"><div class="dental-pipeline-stat"><div class="small text-muted">Confirmed</div><div class="h5 mb-0"><?php echo (int)$pipelineConfirmed; ?></div></div></div>
					<div class="col-6 col-md-3 col-xl-2"><div class="dental-pipeline-stat"><div class="small text-muted">Checked In</div><div class="h5 mb-0"><?php echo (int)$pipelineCheckedIn; ?></div></div></div>
					<div class="col-6 col-md-3 col-xl-2"><div class="dental-pipeline-stat"><div class="small text-muted">In Progress</div><div class="h5 mb-0"><?php echo (int)$pipelineInProgress; ?></div></div></div>
					<div class="col-6 col-md-3 col-xl-2"><div class="dental-pipeline-stat"><div class="small text-muted">Awaiting Records</div><div class="h5 mb-0"><?php echo (int)$pipelineAwaitingRecords; ?></div></div></div>
					<div class="col-6 col-md-3 col-xl-2"><div class="dental-pipeline-stat"><div class="small text-muted">Completed</div><div class="h5 mb-0"><?php echo (int)$pipelineCompleted; ?></div></div></div>
					<div class="col-6 col-md-3 col-xl-2"><div class="dental-pipeline-stat"><div class="small text-muted">Billed / Paid</div><div class="h5 mb-0"><?php echo (int)$pipelineBilled; ?> / <?php echo (int)$pipelinePaid; ?></div></div></div>
				</div>
			</div>
		</div>
	</div>

	<div class="col-12 col-xl-6">
		<div class="card dental-surface-card">
			<div class="card-body">
				<div class="d-flex justify-content-between align-items-center">
					<h5 class="card-title mb-0">Today’s Appointments <span>| Next 10</span></h5>
					<a class="card-link-action" href="admin.php?page=doctor_management&tab=schedule">View all</a>
				</div>

				<?php if (empty($todayAppointments)): ?>
					<div class="alert alert-info mb-0">No appointments today.</div>
				<?php else: ?>
					<div class="table-responsive">
						<table class="table table-sm table-hover align-middle mb-0">
							<thead>
								<tr>
									<th style="width: 90px;">Time</th>
									<th>Patient</th>
									<th style="width: 120px;">Type</th>
									<th style="width: 130px;">Status</th>
								</tr>
							</thead>
							<tbody>
								<?php foreach ($todayAppointments as $a): ?>
									<?php
										$patient = trim(($a['first_name'] ?? '') . ' ' . ($a['last_name'] ?? ''));
										$startTime = dashboard_format_time($a['start_time'] ?? null);
										$endTime = dashboard_format_time($a['end_time'] ?? null);
										$time = ($startTime !== '' && $endTime !== '')
											? $startTime . ' - ' . $endTime
											: $startTime;
										$status = (string)($a['status'] ?? 'scheduled');
									?>
									<tr>
										<td class="text-muted"><?php echo htmlspecialchars($time); ?></td>
										<td>
											<div class="fw-semibold"><?php echo htmlspecialchars($patient !== '' ? $patient : 'Unknown'); ?></div>
											<?php if (!empty($a['reason'])): ?>
												<div class="text-muted small text-truncate" style="max-width: 520px;">Reason: <?php echo htmlspecialchars((string)$a['reason']); ?></div>
											<?php endif; ?>
										</td>
										<td>
											<?php if (!empty($a['is_online_appointment'])): ?>
												<span class="badge bg-info">Online</span>
											<?php else: ?>
												<span class="badge bg-secondary">In-Person</span>
											<?php endif; ?>
										</td>
										<td>
											<span class="badge bg-<?php echo htmlspecialchars(dashboard_appt_status_badge_class($status)); ?>"><?php echo htmlspecialchars(dashboard_format_status_label($status)); ?></span>
										</td>
									</tr>
								<?php endforeach; ?>
							</tbody>
						</table>
					</div>
				<?php endif; ?>
			</div>
		</div>
	</div>

	<div class="col-12 col-xl-6">
		<div class="card dental-surface-card">
			<div class="card-body">
				<div class="d-flex justify-content-between align-items-center">
					<h5 class="card-title mb-0">User Logs <span>| Recent</span></h5>
					<a class="card-link-action" href="admin.php?page=system_logs">Open logs</a>
				</div>

				<?php if (empty($recentUserLogs)): ?>
					<div class="alert alert-info mb-0">No logs yet.</div>
				<?php else: ?>
					<div class="table-responsive">
						<table class="table table-sm table-hover align-middle mb-0">
							<thead>
								<tr>
									<th style="width: 180px;">Login</th>
									<th style="width: 180px;">Logout</th>
									<th style="width: 220px;">User</th>
									<th style="width: 140px;">IP</th>
								</tr>
							</thead>
							<tbody>
								<?php foreach ($recentUserLogs as $l): ?>
									<?php
										$uname = trim(($l['first_name'] ?? '') . ' ' . ($l['last_name'] ?? ''));
											$email = (string)($l['email'] ?? '');
											$userLabel = $uname !== '' ? $uname : ($email !== '' ? $email : 'System');
									?>
									<tr>
										<td><?php echo htmlspecialchars((string)($l['login_time'] ?? '')); ?></td>
										<td><?php echo htmlspecialchars((string)($l['logout_time'] ?? '')); ?></td>
										<td><?php echo htmlspecialchars($userLabel); ?></td>
										<td class="text-muted"><?php echo htmlspecialchars((string)($l['ip_address'] ?? '')); ?></td>
									</tr>
								<?php endforeach; ?>
							</tbody>
						</table>
					</div>
				<?php endif; ?>
			</div>
		</div>
	</div>
</div>
</div>

