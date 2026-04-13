<?php
include_once '../config/database.php';
include_once '../config/security.php';
include_once '../config/capabilities.php';
security_start_session();
security_ensure_auth_columns($conn);
security_ensure_work_type_column($conn);
$csrf_token = security_generate_csrf_token();

$action = $_GET['action'] ?? ($_POST['action'] ?? '');
$user_type = $_GET['type'] ?? 'users'; // 'users' or 'patients'
$search = $_GET['search'] ?? ''; // search query

if (!function_exists('manage_users_redirect')) {
    function manage_users_redirect($type = 'users')
    {
        $safeType = ($type === 'patients') ? 'patients' : 'users';
        $url = 'admin.php?page=manage_users&type=' . rawurlencode($safeType);
        if (!headers_sent()) {
            header('Location: ' . $url);
        } else {
            echo '<script>window.location.href=' . json_encode($url) . ';</script>';
            echo '<noscript><meta http-equiv="refresh" content="0;url=' . htmlspecialchars($url, ENT_QUOTES, 'UTF-8') . '"></noscript>';
        }
        exit;
    }
}

// Handle ARCHIVE action (change status to inactive)
if ($action === 'archive' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    capabilities_require_web('manage_users', 'admin.php?page=manage_users&type=users');
    $csrf = $_POST['csrf_token'] ?? '';
    if (!security_validate_csrf_token($csrf)) {
        $_SESSION['message'] = 'Security token mismatch. Please refresh and try again.';
        $_SESSION['message_type'] = 'danger';
        manage_users_redirect($_POST['type'] ?? 'users');
    }

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
    manage_users_redirect($type);
}

// Handle RESET PASSWORD action (admin only for system users)
if ($action === 'reset_password' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    capabilities_require_web('manage_users', 'admin.php?page=manage_users&type=users');
    $csrf = $_POST['csrf_token'] ?? '';
    if (!security_validate_csrf_token($csrf)) {
        $_SESSION['message'] = 'Security token mismatch. Please refresh and try again.';
        $_SESSION['message_type'] = 'danger';
        manage_users_redirect('users');
    }

    $id = (int)($_POST['id'] ?? 0);
    if ($id <= 0) {
        $_SESSION['message'] = 'Invalid user selected for password reset.';
        $_SESSION['message_type'] = 'danger';
        manage_users_redirect('users');
    }

    $findSql = "SELECT u.user_id, u.first_name, u.last_name, u.email, r.role_name
                FROM users u
                LEFT JOIN role r ON r.role_id = u.role_id
                WHERE u.user_id = ?
                LIMIT 1";
    $findStmt = $conn->prepare($findSql);
    if (!$findStmt) {
        $_SESSION['message'] = 'Database error while preparing reset.';
        $_SESSION['message_type'] = 'danger';
        manage_users_redirect('users');
    }
    $findStmt->bind_param('i', $id);
    $findStmt->execute();
    $found = $findStmt->get_result();
    $targetUser = $found ? $found->fetch_assoc() : null;
    $findStmt->close();

    if (!$targetUser) {
        $_SESSION['message'] = 'User account not found.';
        $_SESSION['message_type'] = 'danger';
        manage_users_redirect('users');
    }

    $temporaryPassword = security_generate_default_password();
    $temporaryHash = security_hash_password($temporaryPassword);

    $updSql = "UPDATE users
               SET password_hash = ?,
                   must_change_password = 1,
                   password_updated_at = NOW(),
                   failed_login_attempts = 0,
                   locked_until = NULL
               WHERE user_id = ?";
    $updStmt = $conn->prepare($updSql);
    if (!$updStmt) {
        $_SESSION['message'] = 'Database error while saving new password.';
        $_SESSION['message_type'] = 'danger';
        manage_users_redirect('users');
    }
    $updStmt->bind_param('si', $temporaryHash, $id);
    $ok = $updStmt->execute();
    $updStmt->close();

    if ($ok) {
        if ((int)($_SESSION['user_id'] ?? 0) === $id) {
            $_SESSION['must_change_password'] = true;
        }
        $displayName = trim((string)($targetUser['first_name'] ?? '') . ' ' . (string)($targetUser['last_name'] ?? ''));
        $roleName = (string)($targetUser['role_name'] ?? 'user');
        $_SESSION['message'] = 'Temporary password generated for ' . $displayName . ' (' . $roleName . '): ' . $temporaryPassword . '. Share this securely. User must change password on next login.';
        $_SESSION['message_type'] = 'success';
    } else {
        $_SESSION['message'] = 'Failed to reset password.';
        $_SESSION['message_type'] = 'danger';
    }

    manage_users_redirect('users');
}

// Fetch users from users table
$users = [];
$users_sql = "SELECT u.*, r.role_name, ds.specialization_id, sp.specialization_name
              FROM users u
              LEFT JOIN role r ON u.role_id = r.role_id
              LEFT JOIN (
                  SELECT user_id, MIN(specialization_id) AS specialization_id
                  FROM dentist_specialization
                  GROUP BY user_id
              ) ds ON ds.user_id = u.user_id
              LEFT JOIN specialization sp ON sp.specialization_id = ds.specialization_id
              WHERE 1=1";

if (!empty($search)) {
    $search_param = '%' . $search . '%';
    $users_sql .= " AND (u.first_name LIKE ? OR u.last_name LIKE ? OR u.email LIKE ? OR u.phone_number LIKE ?)";
}

$users_sql .= " ORDER BY u.user_id DESC";

if (!empty($search)) {
    $stmt = $conn->prepare($users_sql);
    $stmt->bind_param("ssss", $search_param, $search_param, $search_param, $search_param);
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
    $patients_sql .= " AND (first_name LIKE ? OR last_name LIKE ? OR email LIKE ? OR phone_number LIKE ?)";
}

$patients_sql .= " ORDER BY patient_id DESC";

if (!empty($search)) {
    $stmt = $conn->prepare($patients_sql);
    $stmt->bind_param("ssss", $search_param, $search_param, $search_param, $search_param);
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

$specializations = [];
$specRes = $conn->query('SELECT specialization_id, specialization_name FROM specialization ORDER BY specialization_name ASC');
if ($specRes) {
    while ($specRow = $specRes->fetch_assoc()) {
        $specializations[] = $specRow;
    }
}

$doctorRoleId = 2;
foreach ($roles as $roleRow) {
    if (strtolower((string)($roleRow['role_name'] ?? '')) === 'doctor') {
        $doctorRoleId = (int)($roleRow['role_id'] ?? 2);
        break;
    }
}
?>

 

<!-- Alert Messages -->
<?php if (isset($_SESSION['message'])): ?>
    <div class="alert alert-<?php echo $_SESSION['message_type']; ?> alert-dismissible fade show" role="alert">
        <?php echo htmlspecialchars((string)$_SESSION['message']); ?>
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
                                <th>Email</th>
                                <th>Role</th>
                                <th>Work Type</th>
                                <th>Specialization</th>
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
                                    <td><?php echo htmlspecialchars($user['email']); ?></td>
                                    <td>
                                        <span class="badge bg-secondary">
                                            <?php echo htmlspecialchars($user['role_name'] ?? 'N/A'); ?>
                                        </span>
                                    </td>
                                    <td>
                                        <?php
                                            $isDoctor = ((int)($user['role_id'] ?? 0) === (int)$doctorRoleId);
                                            $workType = ((string)($user['work_type'] ?? 'regular') === 'part_time') ? 'Part-time' : 'Regular';
                                        ?>
                                        <?php if ($isDoctor): ?>
                                            <span class="badge bg-<?php echo $workType === 'Part-time' ? 'warning text-dark' : 'success'; ?>"><?php echo htmlspecialchars($workType); ?></span>
                                        <?php else: ?>
                                            <span class="badge bg-success">Regular</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php if ($isDoctor): ?>
                                            <?php echo htmlspecialchars($user['specialization_name'] ?? '—'); ?>
                                        <?php else: ?>
                                            <span class="text-muted">—</span>
                                        <?php endif; ?>
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
                                            <i class="bi bi-pencil"></i>
                                        </button>
                                        <form method="POST" style="display:inline;" onsubmit="return confirm('Generate a new temporary password for this account?');">
                                            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">
                                            <input type="hidden" name="action" value="reset_password">
                                            <input type="hidden" name="id" value="<?php echo (int)$user['user_id']; ?>">
                                            <button type="submit" class="btn btn-sm btn-warning" title="Generate Temporary Password">
                                                <i class="bi bi-key"></i>
                                            </button>
                                        </form>
                                        <form method="POST" style="display:inline;" 
                                              onsubmit="return confirm('Are you sure you want to archive this user?');">
                                                                                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">
                                            <input type="hidden" name="action" value="archive">
                                            <input type="hidden" name="id" value="<?php echo $user['user_id']; ?>">
                                            <input type="hidden" name="type" value="users">
                                            <button type="submit" class="btn btn-sm btn-danger">
                                                <i class="bi bi-trash"></i>
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
                        <input type="text" class="form-control form-control-sm" name="search" placeholder="Search by name, email, or phone..." value="<?php echo htmlspecialchars($search); ?>">
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
                                    <td><?php echo htmlspecialchars($patient['email']); ?></td>
                                    <td><?php echo htmlspecialchars($patient['phone_number']); ?></td>
                                    <td><?php echo htmlspecialchars($patient['date_of_birth']); ?></td>
                                    <td><?php echo htmlspecialchars($patient['gender']); ?></td>
                                    <td>
                                        <button type="button" class="btn btn-sm btn-info" data-bs-toggle="modal" 
                                                data-bs-target="#editPatientModal" onclick="editPatient(<?php echo htmlspecialchars(json_encode($patient)); ?>)">
                                            <i class="bi bi-pencil"></i>
                                        </button>
                                        <form method="POST" style="display:inline;" 
                                              onsubmit="return confirm('Are you sure you want to archive this patient?');">
                                                                                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">
                                            <input type="hidden" name="action" value="archive">
                                            <input type="hidden" name="id" value="<?php echo $patient['patient_id']; ?>">
                                            <input type="hidden" name="type" value="patients">
                                            <button type="submit" class="btn btn-sm btn-danger">
                                                <i class="bi bi-archive"></i>
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
            <form method="POST" action="../assets/api/users/add_user.php">
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">
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
                            <label class="form-label">Email</label>
                            <input type="email" class="form-control" id="add_email" name="email" required>
                        </div>
                    </div>
                    <div class="row mb-3">
                        <div class="col-md-6">
                            <label class="form-label">Password</label>
                            <input type="text" class="form-control" id="add_password" value="Auto-generated secure default password" readonly>
                            <small class="text-muted">A strong default password is generated automatically and the user must change it on first login.</small>
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
                    <div class="row mb-3" id="add_work_type_wrap" style="display:none;">
                        <div class="col-md-6">
                            <label class="form-label">Doctor Work Type</label>
                            <select class="form-control" id="add_work_type" name="work_type">
                                <option value="regular" selected>Regular</option>
                                <option value="part_time">Part-time</option>
                            </select>
                            <small class="text-muted">Regular doctors are available Monday-Saturday. Part-time doctors require deployment days from admin.</small>
                        </div>
                        <div class="col-md-6" id="add_specialization_wrap" style="display:none;">
                            <label class="form-label">Doctor Specialization</label>
                            <select class="form-control" id="add_specialization_id" name="specialization_id">
                                <option value="">Select specialization</option>
                                <?php foreach ($specializations as $spec): ?>
                                    <option value="<?php echo (int)$spec['specialization_id']; ?>"><?php echo htmlspecialchars($spec['specialization_name']); ?></option>
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
            <form method="POST" action="../assets/api/users/update_user.php">
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">
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
                    <div class="row mb-3" id="edit_work_type_wrap" style="display:none;">
                        <div class="col-md-6">
                            <label class="form-label">Doctor Work Type</label>
                            <select class="form-control" name="work_type" id="edit_work_type">
                                <option value="regular">Regular</option>
                                <option value="part_time">Part-time</option>
                            </select>
                            <small class="text-muted">Part-time doctors can be deployed on specific weekdays in Admin Doctor Deployment.</small>
                        </div>
                        <div class="col-md-6" id="edit_specialization_wrap" style="display:none;">
                            <label class="form-label">Doctor Specialization</label>
                            <select class="form-control" name="specialization_id" id="edit_specialization_id">
                                <option value="">Select specialization</option>
                                <?php foreach ($specializations as $spec): ?>
                                    <option value="<?php echo (int)$spec['specialization_id']; ?>"><?php echo htmlspecialchars($spec['specialization_name']); ?></option>
                                <?php endforeach; ?>
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
            <form method="POST" action="../assets/api/patients/add_patient.php">
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">
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
                            <label class="form-label">Email</label>
                            <input type="email" class="form-control" id="add_patient_email" name="email" required>
                        </div>
                    </div>
                    <div class="row mb-3">
                        <div class="col-md-6">
                            <label class="form-label">Password</label>
                            <input type="text" class="form-control" id="add_patient_password" value="Auto-generated secure default password" readonly>
                            <small class="text-muted">Patient receives a generated default password and must change it after login.</small>
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
            <form method="POST" action="../assets/api/patients/update_patient.php">
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">
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
    document.getElementById('edit_email').value = user.email;
    document.getElementById('edit_phone_number').value = user.phone_number;
    document.getElementById('edit_date_of_birth').value = user.date_of_birth;
    document.getElementById('edit_gender').value = user.gender;
    document.getElementById('edit_address').value = user.address;
    document.getElementById('edit_is_active').value = user.is_active;
    document.getElementById('edit_role_id').value = user.role_id;
    document.getElementById('edit_work_type').value = (user.work_type === 'part_time') ? 'part_time' : 'regular';
    document.getElementById('edit_specialization_id').value = user.specialization_id ? String(user.specialization_id) : '';
    toggleDoctorWorkTypeFields();
}

function toggleDoctorWorkTypeFields() {
    const doctorRoleId = <?php echo (int)$doctorRoleId; ?>;
    const addRole = document.getElementById('add_role_id');
    const editRole = document.getElementById('edit_role_id');
    const addWrap = document.getElementById('add_work_type_wrap');
    const editWrap = document.getElementById('edit_work_type_wrap');
    const addSpecWrap = document.getElementById('add_specialization_wrap');
    const editSpecWrap = document.getElementById('edit_specialization_wrap');
    const addWorkType = document.getElementById('add_work_type');
    const editWorkType = document.getElementById('edit_work_type');
    const addSpec = document.getElementById('add_specialization_id');
    const editSpec = document.getElementById('edit_specialization_id');

    if (addRole && addWrap) {
        const isDoctor = (String(addRole.value) === String(doctorRoleId));
        addWrap.style.display = isDoctor ? '' : 'none';
        if (addSpecWrap) addSpecWrap.style.display = isDoctor ? '' : 'none';
        if (addSpec) addSpec.required = isDoctor;
        if (!isDoctor && addWorkType) addWorkType.value = 'regular';
        if (!isDoctor && addSpec) addSpec.value = '';
    }
    if (editRole && editWrap) {
        const isDoctor = (String(editRole.value) === String(doctorRoleId));
        editWrap.style.display = isDoctor ? '' : 'none';
        if (editSpecWrap) editSpecWrap.style.display = isDoctor ? '' : 'none';
        if (editSpec) editSpec.required = isDoctor;
        if (!isDoctor && editWorkType) editWorkType.value = 'regular';
        if (!isDoctor && editSpec) editSpec.value = '';
    }
}

document.addEventListener('DOMContentLoaded', function() {
    const addRole = document.getElementById('add_role_id');
    const editRole = document.getElementById('edit_role_id');
    if (addRole) addRole.addEventListener('change', toggleDoctorWorkTypeFields);
    if (editRole) editRole.addEventListener('change', toggleDoctorWorkTypeFields);
    toggleDoctorWorkTypeFields();
});

// Edit Patient Function
function editPatient(patient) {
    document.getElementById('edit_patient_id').value = patient.patient_id;
    document.getElementById('edit_patient_first_name').value = patient.first_name;
    document.getElementById('edit_patient_middle_name').value = patient.middle_name;
    document.getElementById('edit_patient_last_name').value = patient.last_name;
    document.getElementById('edit_patient_email').value = patient.email;
    document.getElementById('edit_patient_date_of_birth').value = patient.date_of_birth;
    document.getElementById('edit_patient_gender').value = patient.gender;
    document.getElementById('edit_patient_phone_number').value = patient.phone_number;
    document.getElementById('edit_patient_address').value = patient.address;
}
</script>
