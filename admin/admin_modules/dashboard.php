<?php
include dirname(dirname(__DIR__)) . '/config/database.php';

$today = date('Y-m-d');
$last7Start = date('Y-m-d', strtotime('-6 days'));
// Optional user filter for per-user dashboard
$selectedUserId = isset($_GET['user_id']) && is_numeric($_GET['user_id']) ? (int)$_GET['user_id'] : 0;

// Fetch users for filter dropdown
$usersList = [];
$ures = $conn->query("SELECT u.user_id, u.first_name, u.last_name, r.role_name FROM users u INNER JOIN role r ON r.role_id = u.role_id ORDER BY u.first_name ASC");
if ($ures) {
	while ($ur = $ures->fetch_assoc()) $usersList[] = $ur;
}

function scalar_int(mysqli $conn, string $sql, ?string $types = null, array $params = []): int
{
	if ($types === null) {
		$res = $conn->query($sql);
		if ($res && ($row = $res->fetch_row())) {
			return (int)($row[0] ?? 0);
		}
		return 0;
	}

	$stmt = $conn->prepare($sql);
	if (!$stmt) return 0;

	if ($types !== '') {
		$bind = [];
		$bind[] = $types;
		foreach ($params as $k => $v) {
			$bind[] = &$params[$k];
		}
		call_user_func_array([$stmt, 'bind_param'], $bind);
	}
	$stmt->execute();
	$res = $stmt->get_result();
	$val = 0;
	if ($res && ($row = $res->fetch_row())) {
		$val = (int)($row[0] ?? 0);
	}
	$stmt->close();
	return $val;
}

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

$usersHasIsActive = column_exists($conn, 'users', 'is_active');
$servicesHasIsActive = column_exists($conn, 'services', 'is_active');

$patientsTotal = scalar_int($conn, 'SELECT COUNT(*) FROM patients');
$appointmentsTotal = scalar_int($conn, 'SELECT COUNT(*) FROM appointments');
$appointmentsToday = scalar_int($conn, 'SELECT COUNT(*) FROM appointments WHERE appointment_date = ?', 's', [$today]);
$appointmentsPendingToday = scalar_int(
	$conn,
	"SELECT COUNT(*) FROM appointments WHERE appointment_date = ? AND status IN ('scheduled','confirmed')",
	's',
	[$today]
);

$checkedInToday = scalar_int($conn, 'SELECT COUNT(*) FROM check_in_queue WHERE DATE(checkin_time) = ?', 's', [$today]);
$queueWaitingToday = scalar_int(
	$conn,
	"SELECT COUNT(*) FROM check_in_queue WHERE DATE(checkin_time) = ? AND status IN ('waiting','called')",
	's',
	[$today]
);

$servicesTotal = $servicesHasIsActive
	? scalar_int($conn, 'SELECT COUNT(*) FROM services WHERE is_active = 1')
	: scalar_int($conn, 'SELECT COUNT(*) FROM services');

$usersTotal = $usersHasIsActive
	? scalar_int($conn, 'SELECT COUNT(*) FROM users WHERE is_active = 1')
	: scalar_int($conn, 'SELECT COUNT(*) FROM users');

$doctorsTotal = scalar_int(
	$conn,
	$usersHasIsActive
		? "SELECT COUNT(*) FROM users u INNER JOIN role r ON r.role_id = u.role_id WHERE r.role_name = 'doctor' AND u.is_active = 1"
		: "SELECT COUNT(*) FROM users u INNER JOIN role r ON r.role_id = u.role_id WHERE r.role_name = 'doctor'"
);

$secretariesTotal = scalar_int(
	$conn,
	$usersHasIsActive
		? "SELECT COUNT(*) FROM users u INNER JOIN role r ON r.role_id = u.role_id WHERE r.role_name = 'secretary' AND u.is_active = 1"
		: "SELECT COUNT(*) FROM users u INNER JOIN role r ON r.role_id = u.role_id WHERE r.role_name = 'secretary'"
);

// Appointments by day (last 7 days)
$seriesLabels = [];
$seriesValues = [];
for ($i = 0; $i < 7; $i++) {
	$d = date('Y-m-d', strtotime($last7Start . ' +' . $i . ' days'));
	$seriesLabels[] = $d;
	$seriesValues[] = 0;
}

// Build appointments per-day for last 7 days (optionally filtered by user)
$sql = 'SELECT appointment_date, COUNT(*) AS c FROM appointments WHERE appointment_date BETWEEN ? AND ?';
if ($selectedUserId) $sql .= ' AND user_id = ?';
$sql .= ' GROUP BY appointment_date ORDER BY appointment_date ASC';
$stmt = $conn->prepare($sql);
if ($stmt) {
    if ($selectedUserId) $stmt->bind_param('ssi', $last7Start, $today, $selectedUserId);
    else $stmt->bind_param('ss', $last7Start, $today);
    $stmt->execute();
    $res = $stmt->get_result();
    if ($res) {
        $map = [];
        while ($r = $res->fetch_assoc()) {
            $map[$r['appointment_date']] = (int)($r['c'] ?? 0);
        }
        foreach ($seriesLabels as $idx => $d) {
            if (isset($map[$d])) $seriesValues[$idx] = $map[$d];
        }
    }
    $stmt->close();
}

// Today's appointments (top list)
$todayAppointments = [];
$stmt = $conn->prepare("
	    SELECT a.appointment_id, a.appointment_date, a.status, a.reason, a.is_online_appointment,
		    NULL AS start_time, NULL AS end_time,
		   p.first_name, p.last_name,
		   d.first_name AS doctor_first_name, d.last_name AS doctor_last_name
	FROM appointments a
	    -- timeslot removed; start/end time not available
	LEFT JOIN patients p ON p.patient_id = a.patient_id
	LEFT JOIN users d ON d.user_id = a.user_id
	WHERE a.appointment_date = ?
	    ORDER BY a.created_at ASC
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
		   u.first_name, u.last_name, u.username
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

function badge_class_for_appt_status(string $status): string
{
	switch ($status) {
		case 'completed':
			return 'success';
		case 'cancelled':
		case 'no_show':
			return 'danger';
		case 'checked_in':
			return 'primary';
		case 'confirmed':
			return 'info';
		case 'in_progress':
			return 'warning';
		case 'scheduled':
		default:
			return 'secondary';
	}
}

function fmt_time(?string $dt): string
{
	if (!$dt) return '';
	$ts = strtotime($dt);
	if (!$ts) return '';
	return date('h:i A', $ts);
}
?>

<div class="row g-3">
	<div class="col-12 col-md-6 col-xl-3">
		<div class="card info-card">
			<div class="card-body">
				<h5 class="card-title">Patients <span>| Total</span></h5>
				<div class="d-flex align-items-center">
					<div class="card-icon rounded-circle d-flex align-items-center justify-content-center">
						<i class="bi bi-people"></i>
					</div>
					<div class="ps-3">
						<h6 class="mb-0"><?php echo (int)$patientsTotal; ?></h6>
						<a class="small" href="admin.php?page=patients">View records</a>
					</div>
				</div>
			</div>
		</div>
	</div>

	<div class="col-12 col-md-6 col-xl-3">
		<div class="card info-card">
			<div class="card-body">
				<h5 class="card-title">Appointments <span>| Today</span></h5>
				<div class="d-flex align-items-center">
					<div class="card-icon rounded-circle d-flex align-items-center justify-content-center">
						<i class="bi bi-calendar-check"></i>
					</div>
					<div class="ps-3">
						<h6 class="mb-0"><?php echo (int)$appointmentsToday; ?></h6>
						<span class="text-muted small"><?php echo (int)$appointmentsPendingToday; ?> pending</span>
						<div><a class="small" href="admin.php?page=appointments&view=today">Open today</a></div>
					</div>
				</div>
			</div>
		</div>
	</div>

	<div class="col-12 col-md-6 col-xl-3">
		<div class="card info-card">
			<div class="card-body">
				<h5 class="card-title">Queue <span>| Today</span></h5>
				<div class="d-flex align-items-center">
					<div class="card-icon rounded-circle d-flex align-items-center justify-content-center">
						<i class="bi bi-list-ol"></i>
					</div>
					<div class="ps-3">
						<h6 class="mb-0"><?php echo (int)$checkedInToday; ?></h6>
						<span class="text-muted small"><?php echo (int)$queueWaitingToday; ?> waiting/called</span>
						<div><a class="small" href="admin.php?page=queue">View queue</a></div>
					</div>
				</div>
			</div>
		</div>
	</div>

	<div class="col-12 col-md-6 col-xl-3">
		<div class="card info-card">
			<div class="card-body">
				<h5 class="card-title">Users <span>| Active</span></h5>
				<div class="d-flex align-items-center">
					<div class="card-icon rounded-circle d-flex align-items-center justify-content-center">
						<i class="bi bi-person-badge"></i>
					</div>
					<div class="ps-3">
						<h6 class="mb-0"><?php echo (int)$usersTotal; ?></h6>
						<span class="text-muted small"><?php echo (int)$doctorsTotal; ?> doctors, <?php echo (int)$secretariesTotal; ?> secretaries</span>
						<div><a class="small" href="admin.php?page=manage_users&type=users">Manage</a></div>
					</div>
				</div>
			</div>
		</div>
	</div>

	<div class="col-12">
		<div class="card">
			<div class="card-body">
				<div class="d-flex flex-wrap justify-content-between align-items-center gap-2">
					<div class="d-flex align-items-center gap-2">
						<h5 class="card-title mb-0">Appointments <span>| Last 7 days</span></h5>
						<select id="dashboardUserFilter" class="form-select form-select-sm ms-2" style="width:220px;">
							<option value="">All Users</option>
							<?php foreach ($usersList as $u): ?>
								<option value="<?php echo (int)$u['user_id']; ?>" <?php echo $selectedUserId == $u['user_id'] ? 'selected' : ''; ?>><?php echo htmlspecialchars($u['first_name'] . ' ' . $u['last_name'] . ' (' . $u['role_name'] . ')'); ?></option>
							<?php endforeach; ?>
						</select>
					</div>
					<div class="text-muted small">Services active: <?php echo (int)$servicesTotal; ?> • Appointments total: <?php echo (int)$appointmentsTotal; ?></div>
				</div>
				<div id="appt7Chart" style="min-height: 260px;"></div>
			</div>
		</div>
	</div>

	<div class="col-12 col-xl-6">
		<div class="card">
			<div class="card-body">
				<div class="d-flex justify-content-between align-items-center">
					<h5 class="card-title mb-0">Today’s Appointments <span>| Next 10</span></h5>
					<a class="small" href="admin.php?page=appointments&view=today">View all</a>
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
										$time = (fmt_time($a['start_time'] ?? null) && fmt_time($a['end_time'] ?? null))
											? fmt_time($a['start_time'] ?? null) . ' - ' . fmt_time($a['end_time'] ?? null)
											: '';
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
											<span class="badge bg-<?php echo htmlspecialchars(badge_class_for_appt_status($status)); ?>"><?php echo htmlspecialchars(ucfirst($status)); ?></span>
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
		<div class="card">
			<div class="card-body">
				<div class="d-flex justify-content-between align-items-center">
					<h5 class="card-title mb-0">User Logs <span>| Recent</span></h5>
					<a class="small" href="admin.php?page=system_logs">Open logs</a>
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
										$username = (string)($l['username'] ?? '');
										$userLabel = $uname !== '' ? $uname : ($username !== '' ? $username : 'System');
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

<script>
	(function () {
			if (typeof ApexCharts === 'undefined') return;

		const labels = <?php echo json_encode($seriesLabels); ?>;
		const values = <?php echo json_encode($seriesValues); ?>;

		const options = {
			chart: { type: 'line', height: 260, toolbar: { show: false } },
			series: [{ name: 'Appointments', data: values }],
			xaxis: { categories: labels },
			stroke: { curve: 'smooth', width: 3 },
			colors: ['#4154f1'],
			markers: { size: 4 },
			grid: { strokeDashArray: 4 },
			yaxis: { min: 0, forceNiceScale: true },
			dataLabels: { enabled: false }
		};

		const el = document.querySelector('#appt7Chart');
		if (!el) return;
		const chart = new ApexCharts(el, options);
		chart.render();

			// Dashboard user filter wiring
			document.getElementById('dashboardUserFilter')?.addEventListener('change', function () {
				const uid = this.value || '';
				const params = new URLSearchParams(window.location.search);
				if (uid) params.set('user_id', uid); else params.delete('user_id');
				params.set('page', 'dashboard');
				window.location.href = 'admin.php?' + params.toString();
			});
	})();
</script>

