<?php
include '../config/database.php';

$action = $_GET['action'] ?? '';
$user_type = $_GET['type'] ?? 'users'; // 'users' or 'patients'
$search = $_GET['search'] ?? ''; // search query

// Handle ARCHIVE action (change status to inactive)
if ($action === 'archive' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $id = $_POST['id'] ?? 0;
    $type = $_POST['type'] ?? 'users';
    
    if ($type === 'patients') {
        // Patients table doesn't have status field, so we'll skip archiving for patients
        $_SESSION['message'] = 'Patients cannot be archived.';
        $_SESSION['message_type'] = 'warning';
    } else {
        $sql = "UPDATE users SET is_active = 0 WHERE user_id = ?";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("i", $id);
        
        if ($stmt->execute()) {
            $_SESSION['message'] = 'User archived successfully!';
            $_SESSION['message_type'] = 'success';
        } else {
            $_SESSION['message'] = 'Error archiving user.';
            $_SESSION['message_type'] = 'danger';
        }
        $stmt->close();
    }
    header('Location: ../admin.php?page=manage_users&type=' . $type);
    exit;
}

// Fetch users from users table
$users = [];
$users_sql = "SELECT u.*, r.role_name FROM users u LEFT JOIN role r ON u.role_id = r.role_id WHERE 1=1";

if (!empty($search)) {
    $search_param = '%' . $search . '%';
    $users_sql .= " AND (u.first_name LIKE ? OR u.last_name LIKE ? OR u.username LIKE ? OR u.email LIKE ? OR u.phone_number LIKE ?)";
}

$users_sql .= " ORDER BY u.user_id DESC";

if (!empty($search)) {
    $stmt = $conn->prepare($users_sql);
    $stmt->bind_param("sssss", $search_param, $search_param, $search_param, $search_param, $search_param);
    $stmt->execute();
    $users_result = $stmt->get_result();
    $stmt->close();
} else {
    $users_result = $conn->query($users_sql);
}

if ($users_result) {
    while ($row = $users_result->fetch_assoc()) {
        $users[] = $row;
    }
}

// Fetch patients from patients table
$patients = [];
$patients_sql = "SELECT * FROM patients WHERE 1=1";

if (!empty($search)) {
    $search_param = '%' . $search . '%';
    $patients_sql .= " AND (first_name LIKE ? OR last_name LIKE ? OR username LIKE ? OR email LIKE ? OR phone_number LIKE ?)";
}

$patients_sql .= " ORDER BY patient_id DESC";

if (!empty($search)) {
    $stmt = $conn->prepare($patients_sql);
    $stmt->bind_param("sssss", $search_param, $search_param, $search_param, $search_param, $search_param);
    $stmt->execute();
    $patients_result = $stmt->get_result();
    $stmt->close();
} else {
    $patients_result = $conn->query($patients_sql);
}

if ($patients_result) {
    while ($row = $patients_result->fetch_assoc()) {
        $patients[] = $row;
    }
}

// Get role list for dropdown
$roles = [];
$roles_sql = "SELECT * FROM role WHERE role_name != 'patient'";
$roles_result = $conn->query($roles_sql);
if ($roles_result) {
    while ($row = $roles_result->fetch_assoc()) {
        $roles[] = $row;
    }
}
?>

 

<!-- Alert Messages -->
<?php if (isset($_SESSION['message'])): ?>
    <div class="alert alert-<?php echo $_SESSION['message_type']; ?> alert-dismissible fade show" role="alert">
        <?php echo $_SESSION['message']; ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
    </div>
    <?php unset($_SESSION['message']); unset($_SESSION['message_type']); ?>
<?php endif; ?>

<!-- Tab Navigation -->
<div class="mb-3">
    <ul class="nav nav-tabs" id="userTabs" role="tablist">
        <li class="nav-item" role="presentation">
            <button class="nav-link <?php echo $user_type === 'users' ? 'active' : ''; ?>" id="users-tab" 
                    data-bs-toggle="tab" data-bs-target="#users-content" type="button" 
                    onclick="window.location.href='admin.php?page=manage_users&type=users'" role="tab">
                <i class="bi bi-people"></i> System Users (<?php echo count($users); ?>)
            </button>
        </li>
        <li class="nav-item" role="presentation">
            <button class="nav-link <?php echo $user_type === 'patients' ? 'active' : ''; ?>" id="patients-tab" 
                    data-bs-toggle="tab" data-bs-target="#patients-content" type="button"
                    onclick="window.location.href='admin.php?page=manage_users&type=patients'" role="tab">
                <i class="bi bi-heart-pulse"></i> Patients (<?php echo count($patients); ?>)
            </button>
        </li>
    </ul>
</div>

<div class="tab-content" id="userTabContent">
    <!-- USERS TABLE -->
    <div class="tab-pane fade <?php echo $user_type === 'users' ? 'show active' : ''; ?>" id="users-content" role="tabpanel">
        <div class="card">
            <div class="card-header d-flex justify-content-between align-items-center">
                <h5 class="card-title mb-0">System Users</h5>
                <button type="button" class="btn btn-primary btn-sm" data-bs-toggle="modal" data-bs-target="#addUserModal">
                    <i class="bi bi-plus-circle"></i> Add User
                </button>
            </div>
            <div class="card-body">
                <div class="table-responsive">
                    <table class="table table-hover datatable">
                        <thead>
                            <tr>
                                <th>ID</th>
                                <th>Name</th>
                                <th>Username</th>
                                <th>Email</th>
                                <th>Role</th>
                                <th>Phone</th>
                                <th>Status</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($users as $user): ?>
                                <tr>
                                    <td><?php echo htmlspecialchars($user['user_id']); ?></td>
                                    <td><?php echo htmlspecialchars($user['first_name'] . ' ' . $user['last_name']); ?></td>
                                    <td><?php echo htmlspecialchars($user['username']); ?></td>
                                    <td><?php echo htmlspecialchars($user['email']); ?></td>
                                    <td>
                                        <span class="badge">
                                            <?php echo htmlspecialchars($user['role_name'] ?? 'N/A'); ?>
                                        </span>
                                    </td>
                                    <td><?php echo htmlspecialchars($user['phone_number']); ?></td>
                                    <td>
                                        <?php if ($user['is_active']): ?>
                                            <span class="badge bg-success">Active</span>
                                        <?php else: ?>
                                            <span class="badge bg-warning">Inactive</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <button type="button" class="btn btn-sm btn-info" data-bs-toggle="modal" 
                                                data-bs-target="#editUserModal" onclick="editUser(<?php echo htmlspecialchars(json_encode($user)); ?>)">
                                            <i class="bi bi-pencil"></i> Edit
                                        </button>
                                        <form method="POST" style="display:inline;" 
                                              onsubmit="return confirm('Are you sure you want to archive this user?');">
                                            <input type="hidden" name="action" value="archive">
                                            <input type="hidden" name="id" value="<?php echo $user['user_id']; ?>">
                                            <input type="hidden" name="type" value="users">
                                            <button type="submit" class="btn btn-sm btn-warning">
                                                <i class="bi bi-archive"></i> Archive
                                            </button>
                                        </form>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>

    <!-- PATIENTS TABLE -->
    <div class="tab-pane fade <?php echo $user_type === 'patients' ? 'show active' : ''; ?>" id="patients-content" role="tabpanel">
        <div class="card">
            <div class="card-header d-flex justify-content-between align-items-center flex-wrap">
                <h5 class="card-title mb-0">Patient Settings </h5>
                <div class="d-flex gap-2 flex-wrap">
                    <form method="GET" class="d-flex gap-2">
                        <input type="hidden" name="page" value="manage_users">
                        <input type="hidden" name="type" value="patients">
                        <input type="text" class="form-control form-control-sm" name="search" placeholder="Search by name, username, email, or phone..." value="<?php echo htmlspecialchars($search); ?>">
                        <button type="submit" class="btn btn-primary btn-sm">
                            <i class="bi bi-search"></i> Search
                        </button>
                        <?php if (!empty($search)): ?>
                            <a href="admin.php?page=manage_users&type=patients" class="btn btn-secondary btn-sm">
                                <i class="bi bi-x"></i> Clear
                            </a>
                        <?php endif; ?>
                    </form>
                    <button type="button" class="btn btn-primary btn-sm" data-bs-toggle="modal" data-bs-target="#addPatientModal">
                        <i class="bi bi-plus-circle"></i> Add Patient
                    </button>
                </div>
            </div>
            <div class="card-body">
                <div class="table-responsive">
                    <table class="table table-hover datatable">
                        <thead>
                            <tr>
                                <th>ID</th>
                                <th>Full Name</th>
                                <th>Username</th>
                                <th>Email</th>
                                <th>Phone</th>
                                <th>DOB</th>
                                <th>Gender</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($patients as $patient): ?>
                                <tr>
                                    <td><?php echo htmlspecialchars($patient['patient_id']); ?></td>
                                    <td><?php echo htmlspecialchars($patient['first_name'] . ' ' . $patient['last_name']); ?></td>
                                    <td><?php echo htmlspecialchars($patient['username']); ?></td>
                                    <td><?php echo htmlspecialchars($patient['email']); ?></td>
                                    <td><?php echo htmlspecialchars($patient['phone_number']); ?></td>
                                    <td><?php echo htmlspecialchars($patient['date_of_birth']); ?></td>
                                    <td><?php echo htmlspecialchars($patient['gender']); ?></td>
                                    <td>
                                        <button type="button" class="btn btn-sm btn-info" data-bs-toggle="modal" 
                                                data-bs-target="#editPatientModal" onclick="editPatient(<?php echo htmlspecialchars(json_encode($patient)); ?>)">
                                            <i class="bi bi-pencil"></i> Edit
                                        </button>
                                        <form method="POST" style="display:inline;" 
                                              onsubmit="return confirm('Are you sure you want to archive this patient?');">
                                            <input type="hidden" name="action" value="archive">
                                            <input type="hidden" name="id" value="<?php echo $patient['patient_id']; ?>">
                                            <input type="hidden" name="type" value="patients">
                                            <button type="submit" class="btn btn-sm btn-warning">
                                                <i class="bi bi-archive"></i> Archive
                                            </button>
                                        </form>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- ========== ADD USER MODAL ========== -->
<div class="modal fade" id="addUserModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Add New System User</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST" action="../../assets/api/users/add_user.php">
                <div class="modal-body">
                    <div class="row mb-3">
                        <div class="col-md-6">
                            <label class="form-label">First Name</label>
                            <input type="text" class="form-control" id="add_first_name" name="first_name" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Last Name</label>
                            <input type="text" class="form-control" id="add_last_name" name="last_name" required>
                        </div>
                    </div>
                    <div class="row mb-3">
                        <div class="col-md-6">
                            <label class="form-label">Username</label>
                            <input type="text" class="form-control" id="add_username" name="username" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Email</label>
                            <input type="email" class="form-control" id="add_email" name="email" required>
                        </div>
                    </div>
                    <div class="row mb-3">
                        <div class="col-md-6">
                            <label class="form-label">Password</label>
                            <input type="password" class="form-control" id="add_password" name="password" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Role</label>
                            <select class="form-control" id="add_role_id" name="role_id" required>
                                <option value="">Select Role</option>
                                <?php foreach ($roles as $role): ?>
                                    <option value="<?php echo $role['role_id']; ?>">
                                        <?php echo htmlspecialchars(ucfirst($role['role_name'])); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                    <div class="row mb-3">
                        <div class="col-md-6">
                            <label class="form-label">Phone Number</label>
                            <input type="tel" class="form-control" id="add_phone_number" name="phone_number">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Date of Birth</label>
                            <input type="date" class="form-control" id="add_date_of_birth" name="date_of_birth">
                        </div>
                    </div>
                    <div class="row mb-3">
                        <div class="col-md-6">
                            <label class="form-label">Gender</label>
                            <select class="form-control" id="add_gender" name="gender">
                                <option value="">Select</option>
                                <option value="Male">Male</option>
                                <option value="Female">Female</option>
                                <option value="Other">Other</option>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Address</label>
                            <input type="text" class="form-control" id="add_address" name="address">
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                    <button type="submit" class="btn btn-primary">Add User</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- ========== EDIT USER MODAL ========== -->
<div class="modal fade" id="editUserModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Edit System User</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST" action="../../assets/api/users/update_user.php">
                <input type="hidden" name="user_id" id="edit_user_id">
                <div class="modal-body">
                    <div class="row mb-3">
                        <div class="col-md-6">
                            <label class="form-label">First Name</label>
                            <input type="text" class="form-control" name="first_name" id="edit_first_name" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Last Name</label>
                            <input type="text" class="form-control" name="last_name" id="edit_last_name" required>
                        </div>
                    </div>
                    <div class="row mb-3">
                        <div class="col-md-6">
                            <label class="form-label">Username</label>
                            <input type="text" class="form-control" name="username" id="edit_username" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Email</label>
                            <input type="email" class="form-control" name="email" id="edit_email" required>
                        </div>
                    </div>
                    <div class="row mb-3">
                        <div class="col-md-6">
                            <label class="form-label">Role</label>
                            <select class="form-control" name="role_id" id="edit_role_id" required>
                                <option value="">Select Role</option>
                                <?php foreach ($roles as $role): ?>
                                    <option value="<?php echo $role['role_id']; ?>">
                                        <?php echo htmlspecialchars(ucfirst($role['role_name'])); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Phone Number</label>
                            <input type="tel" class="form-control" name="phone_number" id="edit_phone_number">
                        </div>
                    </div>
                    <div class="row mb-3">
                        <div class="col-md-6">
                            <label class="form-label">Date of Birth</label>
                            <input type="date" class="form-control" name="date_of_birth" id="edit_date_of_birth">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Gender</label>
                            <select class="form-control" name="gender" id="edit_gender">
                                <option value="">Select</option>
                                <option value="Male">Male</option>
                                <option value="Female">Female</option>
                                <option value="Other">Other</option>
                            </select>
                        </div>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Address</label>
                        <input type="text" class="form-control" name="address" id="edit_address">
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Status</label>
                        <select class="form-control" name="is_active" id="edit_is_active">
                            <option value="1">Active</option>
                            <option value="0">Inactive</option>
                        </select>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                    <button type="submit" class="btn btn-primary">Update User</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- ========== ADD PATIENT MODAL ========== -->
<div class="modal fade" id="addPatientModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Add New Patient</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST" action="../../assets/api/patients/add_patient.php">
                <div class="modal-body">
                    <div class="row mb-3">
                        <div class="col-md-4">
                            <label class="form-label">First Name</label>
                            <input type="text" class="form-control" id="add_patient_first_name" name="first_name" required>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">Middle Name</label>
                            <input type="text" class="form-control" id="add_patient_middle_name" name="middle_name">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">Last Name</label>
                            <input type="text" class="form-control" id="add_patient_last_name" name="last_name" required>
                        </div>
                    </div>
                    <div class="row mb-3">
                        <div class="col-md-6">
                            <label class="form-label">Username</label>
                            <input type="text" class="form-control" id="add_patient_username" name="username" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Email</label>
                            <input type="email" class="form-control" id="add_patient_email" name="email" required>
                        </div>
                    </div>
                    <div class="row mb-3">
                        <div class="col-md-6">
                            <label class="form-label">Password</label>
                            <input type="password" class="form-control" id="add_patient_password" name="password" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Date of Birth</label>
                            <input type="date" class="form-control" id="add_patient_date_of_birth" name="date_of_birth" required>
                        </div>
                    </div>
                    <div class="row mb-3">
                        <div class="col-md-6">
                            <label class="form-label">Gender</label>
                            <select class="form-control" id="add_patient_gender" name="gender" required>
                                <option value="">Select</option>
                                <option value="Male">Male</option>
                                <option value="Female">Female</option>
                                <option value="Other">Other</option>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Phone Number</label>
                            <input type="tel" class="form-control" id="add_patient_phone_number" name="phone_number" required>
                        </div>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Address</label>
                        <input type="text" class="form-control" id="add_patient_address" name="address" required>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                    <button type="submit" class="btn btn-primary">Add Patient</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- ========== EDIT PATIENT MODAL ========== -->
<div class="modal fade" id="editPatientModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Edit Patient</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST" action="../../assets/api/patients/update_patient.php">
                <input type="hidden" name="patient_id" id="edit_patient_id">
                <div class="modal-body">
                    <div class="row mb-3">
                        <div class="col-md-4">
                            <label class="form-label">First Name</label>
                            <input type="text" class="form-control" name="first_name" id="edit_patient_first_name" required>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">Middle Name</label>
                            <input type="text" class="form-control" name="middle_name" id="edit_patient_middle_name">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">Last Name</label>
                            <input type="text" class="form-control" name="last_name" id="edit_patient_last_name" required>
                        </div>
                    </div>
                    <div class="row mb-3">
                        <div class="col-md-6">
                            <label class="form-label">Username</label>
                            <input type="text" class="form-control" name="username" id="edit_patient_username" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Email</label>
                            <input type="email" class="form-control" name="email" id="edit_patient_email" required>
                        </div>
                    </div>
                    <div class="row mb-3">
                        <div class="col-md-6">
                            <label class="form-label">Date of Birth</label>
                            <input type="date" class="form-control" name="date_of_birth" id="edit_patient_date_of_birth" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Gender</label>
                            <select class="form-control" name="gender" id="edit_patient_gender" required>
                                <option value="">Select</option>
                                <option value="Male">Male</option>
                                <option value="Female">Female</option>
                                <option value="Other">Other</option>
                            </select>
                        </div>
                    </div>
                    <div class="row mb-3">
                        <div class="col-md-6">
                            <label class="form-label">Phone Number</label>
                            <input type="tel" class="form-control" name="phone_number" id="edit_patient_phone_number" required>
                        </div>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Address</label>
                        <input type="text" class="form-control" name="address" id="edit_patient_address" required>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                    <button type="submit" class="btn btn-primary">Update Patient</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
// Edit User Function
function editUser(user) {
    document.getElementById('edit_user_id').value = user.user_id;
    document.getElementById('edit_first_name').value = user.first_name;
    document.getElementById('edit_last_name').value = user.last_name;
    document.getElementById('edit_username').value = user.username;
    document.getElementById('edit_email').value = user.email;
    document.getElementById('edit_phone_number').value = user.phone_number;
    document.getElementById('edit_date_of_birth').value = user.date_of_birth;
    document.getElementById('edit_gender').value = user.gender;
    document.getElementById('edit_address').value = user.address;
    document.getElementById('edit_is_active').value = user.is_active;
    document.getElementById('edit_role_id').value = user.role_id;
}

// Edit Patient Function
function editPatient(patient) {
    document.getElementById('edit_patient_id').value = patient.patient_id;
    document.getElementById('edit_patient_first_name').value = patient.first_name;
    document.getElementById('edit_patient_middle_name').value = patient.middle_name;
    document.getElementById('edit_patient_last_name').value = patient.last_name;
    document.getElementById('edit_patient_username').value = patient.username;
    document.getElementById('edit_patient_email').value = patient.email;
    document.getElementById('edit_patient_date_of_birth').value = patient.date_of_birth;
    document.getElementById('edit_patient_gender').value = patient.gender;
    document.getElementById('edit_patient_phone_number').value = patient.phone_number;
    document.getElementById('edit_patient_address').value = patient.address;
}
</script>
