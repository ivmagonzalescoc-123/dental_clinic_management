<?php
include dirname(dirname(__DIR__)) . '/config/database.php';

$patient_id = $_SESSION['patient_id'] ?? 0;

// Fetch services — same price/duration logic as manage_services.php & get_timeslots.php
$services = [];
$svcSql = "SELECT s.service_id,
                  s.service_name,
                  s.initial_price,
                  s.description,
                  s.default_duration_minutes,
                  COALESCE(ts.duration_minutes, s.default_duration_minutes, 30) AS service_duration_minutes
           FROM services s
           LEFT JOIN (
               SELECT t1.service_id, t1.duration_minutes
               FROM timeslot_service t1
               INNER JOIN (
                   SELECT service_id, MAX(id) AS max_id
                   FROM timeslot_service
                   GROUP BY service_id
               ) t2 ON t1.id = t2.max_id
           ) ts ON ts.service_id = s.service_id
           WHERE s.is_active = 1
           ORDER BY s.service_name ASC";
$svcRes = $conn->query($svcSql);
if ($svcRes) {
	while ($r = $svcRes->fetch_assoc()) $services[] = $r;
}
?>

<div class="card border-0">
	<div class="card-header d-flex justify-content-between align-items-center">
		<h5 class="card-title mb-0">Services</h5>
	</div>
	<div class="card-body">
		<div class="services-grid d-grid gap-3" style="grid-template-columns:repeat(auto-fill,minmax(220px,1fr));">
			<?php if (empty($services)): ?>
				<div class="text-muted">No services available.</div>
			<?php else: foreach ($services as $s): ?>
				<div class="service-card card p-3 shadow-sm" data-service-id="<?php echo htmlspecialchars($s['service_id']); ?>">
					<div class="service-name fw-bold mb-2"><?php echo htmlspecialchars($s['service_name']); ?></div>
					<div class="service-price mb-2">Starting Price ₱<?php echo htmlspecialchars(number_format((float)$s['initial_price'],2)); ?></div>
					<div class="service-time text-muted small mb-2">
						Estimated Time:
						<?php if (!empty($s['service_duration_minutes'])): ?>
							<?php echo htmlspecialchars((string)((int)$s['service_duration_minutes'])); ?> min
						<?php else: ?>
							TBD
						<?php endif; ?>
					</div>
					<div class="service-desc text-muted small" style="min-height:40px"><?php echo htmlspecialchars($s['description'] ?? ''); ?></div>
				</div>
			<?php endforeach; endif; ?>
		</div>
	</div>
</div>

<style>
	.service-card { border-radius:8px; }
	.service-card:hover { transform: translateY(-4px); }
	.service-price {
		color: #0d6efd;
		font-weight: 700;
	}
</style>

