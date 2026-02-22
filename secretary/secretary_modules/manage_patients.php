<?php
include dirname(dirname(__DIR__)) . '/config/database.php';

$patients = [];
$res = $conn->query('SELECT patient_id, first_name, middle_name, last_name, phone_number, email, date_of_birth, gender, address, created_at FROM patients ORDER BY created_at DESC');
if ($res) {
	while ($row = $res->fetch_assoc()) {
		$patients[] = $row;
	}
}
?>

<div class="d-flex justify-content-between align-items-center mb-3">
	<h3>Patient Management</h3>
	<button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#addPatientModal">
		<i class="bi bi-person-plus"></i> Add Patient
	</button>
</div>

<div class="card">
	<div class="card-body">
		<div class="table-responsive">
			<table class="table table-hover" id="patientsTable">
				<thead>
					<tr>
						<th>ID</th>
						<th>Name</th>
						<th>Phone</th>
						<th>Email</th>
						<th>DOB</th>
						<th>Gender</th>
						<th>Address</th>
						<th>Created</th>
					</tr>
				</thead>
				<tbody>
					<?php if (empty($patients)): ?>
						<tr><td colspan="8" class="text-center text-muted">No patients found.</td></tr>
					<?php else: ?>
						<?php foreach ($patients as $p): ?>
							<tr>
								<td><?= htmlspecialchars($p['patient_id']); ?></td>
								<td><?= htmlspecialchars($p['first_name'] . ' ' . ($p['middle_name'] ? $p['middle_name'] . ' ' : '') . $p['last_name']); ?></td>
								<td><?= htmlspecialchars($p['phone_number']); ?></td>
								<td><?= htmlspecialchars($p['email']); ?></td>
								<td><?= htmlspecialchars($p['date_of_birth']); ?></td>
								<td><?= htmlspecialchars($p['gender']); ?></td>
								<td><?= htmlspecialchars($p['address']); ?></td>
								<td><?= htmlspecialchars($p['created_at']); ?></td>
							</tr>
						<?php endforeach; ?>
					<?php endif; ?>
				</tbody>
			</table>
		</div>
	</div>
</div>

<!-- Add Patient Modal -->
<div class="modal fade" id="addPatientModal" tabindex="-1" aria-hidden="true">
	<div class="modal-dialog modal-lg">
		<div class="modal-content">
			<div class="modal-header bg-primary text-white">
				<h5 class="modal-title"><i class="bi bi-person-plus"></i> Add Patient</h5>
				<button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
			</div>
			<form id="addPatientForm">
				<div class="modal-body">
					<div class="row">
						<div class="col-md-4 mb-3">
							<label class="form-label">First Name *</label>
							<input type="text" name="first_name" class="form-control" required>
						</div>
						<div class="col-md-4 mb-3">
							<label class="form-label">Middle Name</label>
							<input type="text" name="middle_name" class="form-control">
						</div>
						<div class="col-md-4 mb-3">
							<label class="form-label">Last Name *</label>
							<input type="text" name="last_name" class="form-control" required>
						</div>
					</div>
					<div class="row">
						<div class="col-md-3 mb-3">
							<label class="form-label">Username</label>
							<input type="text" name="username" class="form-control" placeholder="username">
						</div>
						<div class="col-md-3 mb-3">
							<label class="form-label">Password</label>
							<input type="password" name="password" class="form-control" placeholder="password">
						</div>
						<div class="col-md-2 mb-3">
							<label class="form-label">Gender</label>
							<select name="gender" class="form-select">
								<option value="">Unspecified</option>
								<option value="Male">Male</option>
								<option value="Female">Female</option>
								<option value="Other">Other</option>
							</select>
						</div>
						<div class="col-md-2 mb-3">
							<label class="form-label">Phone</label>
							<input type="tel" name="phone_number" class="form-control">
						</div>
						<div class="col-md-2 mb-3">
							<label class="form-label">Date of Birth</label>
							<input type="date" name="date_of_birth" class="form-control">
						</div>
					</div>
					<div class="row">
						<div class="col-md-12 mb-3">
							<label class="form-label">Email</label>
							<input type="email" name="email" class="form-control">
						</div>
					</div>
					<div class="mb-3">
						<label class="form-label">Address</label>
						<textarea name="address" class="form-control"></textarea>
					</div>
					<input type="hidden" name="added_by" value="<?= htmlspecialchars($_SESSION['user_id'] ?? ''); ?>">
				</div>
				<div class="modal-footer">
					<button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
					<button type="submit" class="btn btn-primary">Create Patient</button>
				</div>
			</form>
		</div>
	</div>
</div>

<script>
document.getElementById('addPatientForm').addEventListener('submit', function(e) {
	e.preventDefault();
	const form = e.target;
	const data = new FormData(form);

	fetch('../assets/api/patients/create_walkin.php', {
		method: 'POST',
		body: data
	}).then(r => r.json()).then(res => {
		if (res.success) {
			// reload to show new patient (simple approach)
			location.reload();
		} else {
			alert('Error: ' + (res.message || 'Unable to create patient'));
		}
	}).catch(err => {
		console.error(err);
		alert('Error creating patient');
	});
});
</script>

