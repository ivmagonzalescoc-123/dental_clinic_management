<?php
include dirname(dirname(__DIR__)) . '/config/database.php';
include dirname(dirname(__DIR__)) . '/config/security.php';
security_start_session();
$csrf_token = security_generate_csrf_token();

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
			<form id="addPatientForm" class="ux-form-shell">
				<div class="modal-body">
					<input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf_token); ?>">
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
							<label class="form-label">Password</label>
							<input type="text" class="form-control compact-control" value="Auto-generated if email is provided" readonly>
							<small class="text-muted">System generates a secure default password when email is provided.</small>
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
							<input type="tel" name="phone_number" class="form-control compact-control">
						</div>
						<div class="col-md-2 mb-3">
							<label class="form-label">Date of Birth</label>
							<input type="date" name="date_of_birth" class="form-control compact-control">
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
			<form id="profilingForm" class="ux-form-shell">
				<div class="modal-body">
					<input type="hidden" id="profiling_patient_id" name="patient_id">
					<div class="mb-3" id="profilingPatientName" class="alert alert-light"></div>

					<p class="text-muted small mb-3">Patient background only — demographics and chronic conditions. Visit exam data is recorded per appointment in the Records Queue.</p>
					<div class="row">
						<div class="col-md-6 mb-3">
							<label class="form-label">Race</label>
							<input type="text" id="pf_race" name="pf_race" class="form-control">
						</div>
						<div class="col-md-6 mb-3">
							<label class="form-label">Occupation</label>
							<input type="text" id="pf_occupation" name="pf_occupation" class="form-control">
						</div>
					</div>
					<div class="row">
						<div class="col-md-6 mb-3">
							<label class="form-label">Medical History</label>
							<textarea id="pf_medical_history" name="pf_medical_history" class="form-control" rows="2"></textarea>
						</div>
						<div class="col-md-6 mb-3">
							<label class="form-label">Medications</label>
							<textarea id="pf_medications" name="pf_medications" class="form-control" rows="2"></textarea>
						</div>
					</div>
					<div class="row">
						<div class="col-md-6 mb-3">
							<label class="form-label">Allergies</label>
							<input type="text" id="pf_allergies" name="pf_allergies" class="form-control">
						</div>
						<div class="col-md-3 mb-3">
							<label class="form-label">Smoking</label>
							<input type="text" id="pf_smoking" name="pf_smoking" class="form-control" placeholder="e.g. Non-smoker">
						</div>
						<div class="col-md-3 mb-3">
							<label class="form-label">Alcohol</label>
							<input type="text" id="pf_alcohol" name="pf_alcohol" class="form-control">
						</div>
					</div>
					<div class="row">
						<div class="col-md-3 mb-3">
							<div class="form-check mt-4">
								<input class="form-check-input" type="checkbox" id="pf_hypertensive" name="pf_hypertensive">
								<label class="form-check-label" for="pf_hypertensive">Hypertensive</label>
							</div>
						</div>
						<div class="col-md-3 mb-3">
							<div class="form-check mt-4">
								<input class="form-check-input" type="checkbox" id="pf_diabetic" name="pf_diabetic">
								<label class="form-check-label" for="pf_diabetic">Diabetic</label>
							</div>
						</div>
						<div class="col-md-3 mb-3">
							<div class="form-check mt-4">
								<input class="form-check-input" type="checkbox" id="pf_asthmatic" name="pf_asthmatic">
								<label class="form-check-label" for="pf_asthmatic">Asthmatic</label>
							</div>
						</div>
						<div class="col-md-3 mb-3">
							<div class="form-check mt-4">
								<input class="form-check-input" type="checkbox" id="pf_heart_disease" name="pf_heart_disease">
								<label class="form-check-label" for="pf_heart_disease">Heart Disease</label>
							</div>
						</div>
						<div class="col-md-3 mb-3">
							<div class="form-check mt-4">
								<input class="form-check-input" type="checkbox" id="pf_hiv_positive" name="pf_hiv_positive">
								<label class="form-check-label" for="pf_hiv_positive">HIV Positive</label>
							</div>
						</div>
					</div>
					<div class="row">
						<div class="col-md-4 mb-3">
							<label class="form-label">Bleeding Disorder</label>
							<input type="text" id="pf_bleeding_disorder" name="pf_bleeding_disorder" class="form-control">
						</div>
						<div class="col-md-4 mb-3">
							<label class="form-label">ASA Classification</label>
							<input type="text" id="pf_asa" name="pf_asa" class="form-control">
						</div>
						<div class="col-md-4 mb-3">
							<label class="form-label">Pregnancy Status</label>
							<input type="text" id="pf_pregnancy_status" name="pf_pregnancy_status" class="form-control">
						</div>
					</div>
					<div class="row">
						<div class="col-md-6 mb-3">
							<label class="form-label">Previous Dental Treatment</label>
							<textarea id="pf_previous_dental_treatment" name="pf_previous_dental_treatment" class="form-control" rows="2"></textarea>
						</div>
						<div class="col-md-6 mb-3">
							<label class="form-label">Dental Anxiety</label>
							<input type="text" id="pf_dental_anxiety" name="pf_dental_anxiety" class="form-control">
						</div>
					</div>
					<div class="row">
						<div class="col-md-6 mb-3">
							<label class="form-label">Emergency Contact</label>
							<input type="text" id="pf_emergency_contact" name="pf_emergency_contact" class="form-control">
						</div>
						<div class="col-md-6 mb-3">
							<label class="form-label">Emergency Phone</label>
							<input type="text" id="pf_emergency_phone" name="pf_emergency_phone" class="form-control">
						</div>
					</div>
					<div class="row">
						<div class="col-md-12 mb-3">
							<label class="form-label">Additional Notes</label>
							<textarea id="pf_background_notes" name="pf_background_notes" class="form-control" rows="2"></textarea>
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
function notifySecretaryPatients(message, type) {
	if (typeof window.showAppToast === 'function') {
		window.showAppToast(String(message || ''), type || 'info');
		return;
	}
	alert(String(message || ''));
}
// Open profiling modal and load patient data
function openProfilingModal(patient) {
	document.getElementById('profiling_patient_id').value = patient.patient_id;
	let ageText = 'N/A';
	if (patient.date_of_birth) {
		const dob = new Date(patient.date_of_birth);
		if (!isNaN(dob.getTime())) {
			const ageDate = new Date(Date.now() - dob.getTime());
			ageText = Math.abs(ageDate.getUTCFullYear() - 1970);
		}
	}

	document.getElementById('profilingPatientName').innerHTML = 
		`<strong>Patient:</strong> ${patient.first_name} ${patient.middle_name ? patient.middle_name + ' ' : ''}${patient.last_name}<br>
		 <small class="text-muted">
			<strong>DOB:</strong> ${patient.date_of_birth || 'N/A'} (Age: ${ageText}) &nbsp;|&nbsp; 
			<strong>Gender:</strong> ${patient.gender || 'N/A'} <br>
			<strong>Phone:</strong> ${patient.phone_number || 'N/A'} &nbsp;|&nbsp; 
			<strong>Address:</strong> ${patient.address || 'N/A'}
		 </small>`;
	
	// Fetch existing profiling data
	fetch(`../assets/api/patients/get_profiling.php?patient_id=${patient.patient_id}`)
		.then(r => r.json())
		.then(data => {
			if (data.success && data.profiling) {
				const pf = data.profiling;
				document.getElementById('pf_race').value = pf.race || '';
				document.getElementById('pf_occupation').value = pf.occupation || '';
				document.getElementById('pf_medical_history').value = pf.medical_history || '';
				document.getElementById('pf_medications').value = pf.medications || '';
				document.getElementById('pf_allergies').value = pf.allergies || '';
				document.getElementById('pf_smoking').value = pf.smoking || '';
				document.getElementById('pf_alcohol').value = pf.alcohol || '';
				document.getElementById('pf_hypertensive').checked = !!pf.hypertensive;
				document.getElementById('pf_diabetic').checked = !!pf.diabetic;
				document.getElementById('pf_asthmatic').checked = !!pf.asthmatic;
				document.getElementById('pf_heart_disease').checked = !!pf.heart_disease;
				document.getElementById('pf_hiv_positive').checked = !!pf.hiv_positive;
				document.getElementById('pf_bleeding_disorder').value = pf.bleeding_disorder || '';
				document.getElementById('pf_asa').value = pf.asa || '';
				document.getElementById('pf_pregnancy_status').value = pf.pregnancy_status || '';
				document.getElementById('pf_previous_dental_treatment').value = pf.previous_dental_treatment || '';
				document.getElementById('pf_dental_anxiety').value = pf.dental_anxiety || '';
				document.getElementById('pf_emergency_contact').value = pf.emergency_contact || '';
				document.getElementById('pf_emergency_phone').value = pf.emergency_phone || '';
				document.getElementById('pf_background_notes').value = pf.background_notes || '';
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
		race: document.getElementById('pf_race').value.trim(),
		occupation: document.getElementById('pf_occupation').value.trim(),
		medical_history: document.getElementById('pf_medical_history').value.trim(),
		medications: document.getElementById('pf_medications').value.trim(),
		allergies: document.getElementById('pf_allergies').value.trim(),
		smoking: document.getElementById('pf_smoking').value.trim(),
		alcohol: document.getElementById('pf_alcohol').value.trim(),
		hypertensive: document.getElementById('pf_hypertensive').checked,
		diabetic: document.getElementById('pf_diabetic').checked,
		asthmatic: document.getElementById('pf_asthmatic').checked,
		heart_disease: document.getElementById('pf_heart_disease').checked,
		hiv_positive: document.getElementById('pf_hiv_positive').checked,
		bleeding_disorder: document.getElementById('pf_bleeding_disorder').value.trim(),
		asa: document.getElementById('pf_asa').value.trim(),
		pregnancy_status: document.getElementById('pf_pregnancy_status').value.trim(),
		previous_dental_treatment: document.getElementById('pf_previous_dental_treatment').value.trim(),
		dental_anxiety: document.getElementById('pf_dental_anxiety').value.trim(),
		emergency_contact: document.getElementById('pf_emergency_contact').value.trim(),
		emergency_phone: document.getElementById('pf_emergency_phone').value.trim(),
		background_notes: document.getElementById('pf_background_notes').value.trim()
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
			notifySecretaryPatients('Profiling saved successfully!', 'success');
			bootstrap.Modal.getInstance(document.getElementById('profilingModal')).hide();
		} else {
			notifySecretaryPatients('Error: ' + (res.message || 'Unable to save profiling'), 'danger');
		}
	})
	.catch(err => {
		console.error(err);
		notifySecretaryPatients('Error saving profiling', 'danger');
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
			if (res.generated_password) {
				notifySecretaryPatients('Patient created. Default password: ' + res.generated_password + ' (must change on first login).', 'success');
			}
			// reload to show new patient (simple approach)
			location.reload();
		} else {
			notifySecretaryPatients('Error: ' + (res.message || 'Unable to create patient'), 'danger');
		}
	}).catch(err => {
		console.error(err);
		notifySecretaryPatients('Error creating patient', 'danger');
	});
});
</script>

