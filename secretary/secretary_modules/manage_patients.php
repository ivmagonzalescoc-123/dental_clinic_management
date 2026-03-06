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

<div class="card border-0">
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
						<th>Actions</th>
					</tr>
				</thead>
				<tbody>
					<?php if (empty($patients)): ?>
						<tr><td colspan="9" class="text-center text-muted">No patients found.</td></tr>
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
								<td>
									<button class="btn btn-sm btn-outline-primary" data-bs-toggle="modal" data-bs-target="#profilingModal" onclick="openProfilingModal(<?= htmlspecialchars(json_encode($p)); ?>)">
										<i class="bi bi-pencil"></i> Profiling
									</button>
								</td>
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

<!-- Patient Profiling Modal -->
<div class="modal fade" id="profilingModal" tabindex="-1" aria-hidden="true">
	<div class="modal-dialog modal-lg">
		<div class="modal-content">
			<div class="modal-header bg-info text-white">
				<h5 class="modal-title"><i class="bi bi-file-medical"></i> Patient Profiling</h5>
				<button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
			</div>
			<form id="profilingForm">
				<div class="modal-body">
					<input type="hidden" id="profiling_patient_id" name="patient_id">
					<div class="mb-3" id="profilingPatientName" class="alert alert-light"></div>

					<div class="row">
						<div class="col-md-12 mb-3">
							<label class="form-label">Medical History (conditions)</label>
							<textarea id="pf_medical_history_sec" name="pf_medical_history" class="form-control" rows="2" placeholder="e.g., Diabetes, Hypertension"></textarea>
						</div>
					</div>

					<div class="row">
						<div class="col-md-6 mb-3">
							<label class="form-label">Medications</label>
							<input type="text" id="pf_medications_sec" name="pf_medications" class="form-control" placeholder="List current medications">
						</div>
						<div class="col-md-6 mb-3">
							<label class="form-label">Allergies</label>
							<input type="text" id="pf_allergies_sec" name="pf_allergies" class="form-control" placeholder="Any allergies">
						</div>
					</div>

					<div class="row">
						<div class="col-md-6 mb-3">
							<label class="form-label">Smoking</label>
							<select id="pf_smoking_sec" name="pf_smoking" class="form-select">
								<option value="">-- Select --</option>
								<option value="never">Never</option>
								<option value="former">Former</option>
								<option value="current">Current</option>
							</select>
						</div>
						<div class="col-md-6 mb-3">
							<label class="form-label">Alcohol</label>
							<select id="pf_alcohol_sec" name="pf_alcohol" class="form-select">
								<option value="">-- Select --</option>
								<option value="none">None</option>
								<option value="social">Social</option>
								<option value="regular">Regular</option>
							</select>
						</div>
					</div>

					<div class="row">
						<div class="col-md-6 mb-3">
							<label class="form-label">ASA Class</label>
							<select id="pf_asa_sec" name="pf_asa" class="form-select">
								<option value="">-- Select --</option>
								<option value="I">I</option>
								<option value="II">II</option>
								<option value="III">III</option>
								<option value="IV">IV</option>
							</select>
						</div>
						<div class="col-md-6 mb-3">
							<label class="form-label">Last Dental Visit</label>
							<input type="date" id="pf_last_visit_sec" name="pf_last_visit" class="form-control">
						</div>
					</div>
				</div>
				<div class="modal-footer">
					<button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
					<button type="submit" class="btn btn-primary">Save Profiling</button>
				</div>
			</form>
		</div>
	</div>
</div>

<script>
// Open profiling modal and load patient data
function openProfilingModal(patient) {
	document.getElementById('profiling_patient_id').value = patient.patient_id;
	document.getElementById('profilingPatientName').innerHTML = 
		`<strong>Patient:</strong> ${patient.first_name} ${patient.middle_name ? patient.middle_name + ' ' : ''}${patient.last_name}`;
	
	// Fetch existing profiling data
	fetch(`../assets/api/patients/get_profiling.php?patient_id=${patient.patient_id}`)
		.then(r => r.json())
		.then(data => {
			if (data.success && data.profiling) {
				const pf = data.profiling;
				document.getElementById('pf_medical_history_sec').value = pf.medical_history || '';
				document.getElementById('pf_medications_sec').value = pf.medications || '';
				document.getElementById('pf_allergies_sec').value = pf.allergies || '';
				document.getElementById('pf_smoking_sec').value = pf.smoking || '';
				document.getElementById('pf_alcohol_sec').value = pf.alcohol || '';
				document.getElementById('pf_asa_sec').value = pf.asa || '';
				document.getElementById('pf_last_visit_sec').value = pf.last_visit || '';
			} else {
				// Clear form if no existing profiling
				document.getElementById('profilingForm').reset();
				document.getElementById('profiling_patient_id').value = patient.patient_id;
			}
		})
		.catch(err => console.warn('Error loading profiling:', err));
}

// Save profiling
document.getElementById('profilingForm').addEventListener('submit', function(e) {
	e.preventDefault();
	const patient_id = document.getElementById('profiling_patient_id').value;
	
	const profiling = {
		medical_history: document.getElementById('pf_medical_history_sec').value.trim(),
		medications: document.getElementById('pf_medications_sec').value.trim(),
		allergies: document.getElementById('pf_allergies_sec').value.trim(),
		smoking: document.getElementById('pf_smoking_sec').value.trim(),
		alcohol: document.getElementById('pf_alcohol_sec').value.trim(),
		asa: document.getElementById('pf_asa_sec').value.trim(),
		last_visit: document.getElementById('pf_last_visit_sec').value.trim()
	};

	fetch('../assets/api/patients/save_profiling.php', {
		method: 'POST',
		headers: {
			'Content-Type': 'application/json'
		},
		body: JSON.stringify({
			patient_id: parseInt(patient_id),
			profiling: profiling
		})
	})
	.then(r => r.json())
	.then(res => {
		if (res.success) {
			alert('Profiling saved successfully!');
			bootstrap.Modal.getInstance(document.getElementById('profilingModal')).hide();
		} else {
			alert('Error: ' + (res.message || 'Unable to save profiling'));
		}
	})
	.catch(err => {
		console.error(err);
		alert('Error saving profiling');
	});
});

// Add Patient Form
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

