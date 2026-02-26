<?php
include dirname(dirname(__DIR__)) . '/config/database.php';

$patient_id = $_SESSION['patient_id'] ?? 0;

// Fetch recent billing transactions for patient
$billings = [];
if ($patient_id) {
	// detect status column
	$hasStatus = false;
	$col = $conn->query("SHOW COLUMNS FROM billing LIKE 'status'");
	if ($col && $col->num_rows > 0) $hasStatus = true;

	$selectStatus = $hasStatus ? ', b.status' : ", 'unpaid' AS status";
	$billSql = "SELECT b.billing_id, b.appointment_id, b.total_amount, b.created_at, a.appointment_date
				" . $selectStatus . "
				FROM billing b
				INNER JOIN appointments a ON b.appointment_id = a.appointment_id
				WHERE a.patient_id = " . (int)$patient_id . "
				ORDER BY b.created_at DESC LIMIT 200";
	$billRes = $conn->query($billSql);
	if ($billRes) {
		while ($row = $billRes->fetch_assoc()) $billings[] = $row;
	}
}
?>

<div class="card border-0">
	<div class="card-header d-flex justify-content-between align-items-center">
		<h5 class="card-title mb-0">Transactions</h5>
		<a href="patient.php?page=transactions_export" class="btn btn-sm btn-outline-secondary">Export</a>
	</div>
	<div class="card-body">
		<?php if (empty($billings)): ?>
			<div class="text-muted">No transactions available.</div>
		<?php else: ?>
			<div class="table-responsive">
				<table class="table table-striped table-sm align-middle">
					<thead>
						<tr><th>Date</th><th>Appointment</th><th>Amount</th><th>Status</th><th>Action</th></tr>
					</thead>
					<tbody>
						<?php foreach ($billings as $b): ?>
							<tr>
											<td><?php echo htmlspecialchars(date('Y-m-d H:i', strtotime($b['created_at']))); ?></td>
											<td><?php echo htmlspecialchars($b['appointment_date'] ?? '-'); ?></td>
											<td>₱<?php echo htmlspecialchars(number_format((float)$b['total_amount'],2)); ?></td>
											<td>
												<?php $st = strtolower($b['status'] ?? 'unpaid'); $badge = $st === 'paid' ? 'success' : ($st === 'partial' ? 'warning' : 'secondary'); ?>
												<span class="badge bg-<?php echo $badge; ?>"><?php echo htmlspecialchars(ucfirst($st)); ?></span>
											</td>
											<td><a class="btn btn-sm btn-outline-primary" href="patient.php?page=billing_view&billing_id=<?php echo htmlspecialchars($b['billing_id']); ?>">View</a></td>
										</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			</div>
		<?php endif; ?>
	</div>
</div>

