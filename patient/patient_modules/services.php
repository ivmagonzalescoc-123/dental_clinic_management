<?php
include dirname(dirname(__DIR__)) . '/config/database.php';

$patient_id = $_SESSION['patient_id'] ?? 0;

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
	<div class="card-header d-flex flex-wrap justify-content-between align-items-center gap-2">
		<h5 class="card-title mb-0">Services</h5>
		<span class="badge bg-primary-subtle text-primary border border-primary-subtle px-3 py-2">
			<?php echo count($services); ?> service<?php echo count($services) === 1 ? '' : 's'; ?>
		</span>
	</div>
	<div class="card-body">
		<div class="unified-table-search-toolbar mb-3">
			<div class="unified-table-search-wrap">
				<i class="bi bi-search unified-table-search-icon" aria-hidden="true"></i>
				<input type="search" id="patientServicesSearch" class="form-control unified-table-search-input"
					placeholder="Search service name or description..." autocomplete="off" aria-label="Search services">
				<button type="button" class="unified-table-search-clear" id="patientServicesSearchClear" aria-label="Clear search" style="display:none;">&times;</button>
			</div>
			<div id="patientServicesSearchMeta" class="patient-appt-history-search-meta"></div>
		</div>

		<?php if (empty($services)): ?>
			<div class="text-center text-muted py-4">No services available.</div>
		<?php else: ?>
			<div class="table-responsive">
				<table class="table table-hover align-middle mb-0" id="patientServicesTable" data-skip-table-search="1">
					<thead>
						<tr>
							<th>Service</th>
							<th>Est. Duration</th>
							<th>Description</th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ($services as $s): ?>
							<?php
							$duration = (int)($s['service_duration_minutes'] ?? 0);
							$searchBlob = strtolower(trim(
								($s['service_name'] ?? '') . ' ' .
								($s['description'] ?? '')
							));
							?>
							<tr data-search="<?php echo htmlspecialchars($searchBlob, ENT_QUOTES, 'UTF-8'); ?>">
								<td class="fw-semibold"><?php echo htmlspecialchars($s['service_name']); ?></td>
								<td>
									<?php if ($duration > 0): ?>
										<span class="badge bg-light text-dark border"><?php echo $duration; ?> min</span>
									<?php else: ?>
										<span class="text-muted">TBD</span>
									<?php endif; ?>
								</td>
								<td class="text-muted small" style="max-width: 420px;">
									<?php echo htmlspecialchars($s['description'] ?? '—'); ?>
								</td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			</div>
		<?php endif; ?>
	</div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function () {
	var searchInput = document.getElementById('patientServicesSearch');
	var clearBtn = document.getElementById('patientServicesSearchClear');
	var meta = document.getElementById('patientServicesSearchMeta');
	var rows = Array.from(document.querySelectorAll('#patientServicesTable tbody tr'));
	if (!searchInput || rows.length === 0) return;

	var total = rows.length;

	function updateList() {
		var term = (searchInput.value || '').trim().toLowerCase();
		var visible = 0;

		rows.forEach(function (row) {
			var haystack = row.getAttribute('data-search') || '';
			var show = term === '' || haystack.indexOf(term) !== -1;
			row.style.display = show ? '' : 'none';
			if (show) visible++;
		});

		if (clearBtn) clearBtn.style.display = term ? '' : 'none';
		if (meta) {
			meta.textContent = term
				? ('Showing ' + visible + ' of ' + total + ' services')
				: ('Showing all ' + total + ' services');
		}
	}

	searchInput.addEventListener('input', updateList);
	if (clearBtn) {
		clearBtn.addEventListener('click', function () {
			searchInput.value = '';
			searchInput.focus();
			updateList();
		});
	}
	updateList();
});
</script>
