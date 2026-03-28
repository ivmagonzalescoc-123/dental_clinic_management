<?php
include dirname(dirname(__DIR__)) . '/config/database.php';

$today = date('Y-m-d');

$checkedIn = 0;
$queueWaiting = 0;
$appointmentsToday = 0;

$res = $conn->prepare("SELECT COUNT(*) FROM appointments WHERE appointment_date = ? AND status IN ('checked_in','in_progress')");
if ($res) { $res->bind_param('s', $today); $res->execute(); $r = $res->get_result(); $checkedIn = $r ? (int)$r->fetch_row()[0] : 0; $res->close(); }

$res = $conn->prepare("SELECT COUNT(*) FROM appointments WHERE appointment_date = ? AND status = 'confirmed'");
if ($res) { $res->bind_param('s', $today); $res->execute(); $r = $res->get_result(); $queueWaiting = $r ? (int)$r->fetch_row()[0] : 0; $res->close(); }

$res = $conn->prepare("SELECT COUNT(*) FROM appointments WHERE DATE(appointment_date) = ?");
if ($res) { $res->bind_param('s', $today); $res->execute(); $r = $res->get_result(); $appointmentsToday = $r ? (int)$r->fetch_row()[0] : 0; $res->close(); }

// Last 7 days appointments
$last7Start = date('Y-m-d', strtotime('-6 days'));
$seriesLabels = [];$seriesValues = [];
for ($i=0;$i<7;$i++){ $d = date('Y-m-d', strtotime($last7Start . ' +'.$i.' days')); $seriesLabels[] = $d; $seriesValues[] = 0; }
$stmt = $conn->prepare('SELECT DATE(appointment_date) dt, COUNT(*) c FROM appointments WHERE DATE(appointment_date) BETWEEN ? AND ? GROUP BY dt ORDER BY dt ASC');
if ($stmt){ $stmt->bind_param('ss', $last7Start, $today); $stmt->execute(); $r = $stmt->get_result(); $map = []; if ($r) while($rr=$r->fetch_assoc()) $map[$rr['dt']] = (int)$rr['c']; foreach($seriesLabels as $idx=>$lbl) if(isset($map[$lbl])) $seriesValues[$idx] = $map[$lbl]; $stmt->close(); }

?>

<div class="dental-dashboard-shell">
	<div class="dental-dashboard-hero">
		<div>
			<h4 class="mb-1">Secretary Dashboard</h4>
			<p class="small">Monitor arrivals, waiting queue, and appointment volume for front-desk operations.</p>
		</div>
		<span class="dental-chip">Today: <?php echo date('M d, Y'); ?></span>
	</div>

	<div class="row g-3">
		<div class="col-md-4"><div class="card dental-kpi-card p-3"><h6>Arrived Today</h6><div class="display-6 fw-bold"><?php echo (int)$checkedIn; ?></div></div></div>
		<div class="col-md-4"><div class="card dental-kpi-card p-3"><h6>Pending Today</h6><div class="display-6 fw-bold"><?php echo (int)$queueWaiting; ?></div></div></div>
		<div class="col-md-4"><div class="card dental-kpi-card p-3"><h6>Appointments Today</h6><div class="display-6 fw-bold"><?php echo (int)$appointmentsToday; ?></div></div></div>

		<div class="col-12"><div class="card dental-surface-card"><div class="card-body"><h5 class="card-title">Appointments | Last 7 days</h5><div class="dental-chart-frame mt-3"><div id="secAppt7Chart" style="min-height:220px;"></div></div></div></div></div>
	</div>
</div>

<script>
	(function(){ if (typeof ApexCharts === 'undefined') return; const labels = <?php echo json_encode($seriesLabels); ?>; const values = <?php echo json_encode($seriesValues); ?>; const opts = { chart:{ type:'area', height:260 }, series:[{ name:'Appointments', data: values }], xaxis:{ categories: labels }, colors:['#ff7f50'] }; new ApexCharts(document.getElementById('secAppt7Chart'), opts).render(); })();
</script>

