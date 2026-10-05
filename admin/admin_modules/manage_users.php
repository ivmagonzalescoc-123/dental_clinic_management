<?php
include_once '../config/database.php';
include_once '../config/security.php';
include_once '../config/capabilities.php';
include_once '../config/remember_me.php';
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

// Patients moved to their own sidebar page; keep old links working.
if ($user_type === 'patients' && $action === '' && $_SERVER['REQUEST_METHOD'] === 'GET') {
    $url = 'admin.php?page=patients';
    if (!headers_sent()) {
        header('Location: ' . $url);
    } else {
        echo '<script>window.location.href=' . json_encode($url) . ';</script>';
    }
    exit;
}

// Handle ARCHIVE action (change status to inactive)
if ($action === 'archive' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    capabilities_require_web('manage_users', 'admin.php?page=manage_users&type=users');
    $csrf = $_POST['csrf_token'] ?? '';
    if (!security_validate_csrf_token($csrf)) {
        $_SESSION['message'] = 'Please refresh the page and try again.';
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

// Handle REACTIVATE action (change status to active)
if ($action === 'reactivate' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    capabilities_require_web('manage_users', 'admin.php?page=manage_users&type=users');
    $csrf = $_POST['csrf_token'] ?? '';
    if (!security_validate_csrf_token($csrf)) {
        $_SESSION['message'] = 'Please refresh the page and try again.';
        $_SESSION['message_type'] = 'danger';
        manage_users_redirect($_POST['type'] ?? 'users');
    }

    $id = (int)($_POST['id'] ?? 0);
    $type = $_POST['type'] ?? 'users';

    if ($type === 'patients') {
        $_SESSION['message'] = 'Patients cannot be reactivated from this view.';
        $_SESSION['message_type'] = 'warning';
        manage_users_redirect($type);
    }

    if ($id <= 0) {
        $_SESSION['message'] = 'Invalid user selected for reactivation.';
        $_SESSION['message_type'] = 'danger';
        manage_users_redirect('users');
    }

    $sql = 'UPDATE users SET is_active = 1 WHERE user_id = ?';
    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        $_SESSION['message'] = 'Error preparing user reactivation.';
        $_SESSION['message_type'] = 'danger';
        manage_users_redirect('users');
    }

    $stmt->bind_param('i', $id);
    if ($stmt->execute()) {
        $_SESSION['message'] = 'User reactivated successfully!';
        $_SESSION['message_type'] = 'success';
    } else {
        $_SESSION['message'] = 'Error reactivating user.';
        $_SESSION['message_type'] = 'danger';
    }
    $stmt->close();

    manage_users_redirect('users');
}

// Handle RESET PASSWORD action (system users and patients)
if ($action === 'reset_password' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $resetType = ($_POST['type'] ?? 'users') === 'patients' ? 'patients' : 'users';
    capabilities_require_web('manage_users', 'admin.php?page=manage_users&type=' . $resetType);
    $csrf = $_POST['csrf_token'] ?? '';
    if (!security_validate_csrf_token($csrf)) {
        $_SESSION['message'] = 'Please refresh the page and try again.';
        $_SESSION['message_type'] = 'danger';
        manage_users_redirect($resetType);
    }

    $id = (int)($_POST['id'] ?? 0);
    if ($id <= 0) {
        $_SESSION['message'] = $resetType === 'patients'
            ? 'Invalid patient selected for password reset.'
            : 'Invalid user selected for password reset.';
        $_SESSION['message_type'] = 'danger';
        manage_users_redirect($resetType);
    }

    if ($resetType === 'patients') {
        $findSql = 'SELECT patient_id, first_name, last_name, email
                    FROM patients
                    WHERE patient_id = ?
                    LIMIT 1';
        $findStmt = $conn->prepare($findSql);
        if (!$findStmt) {
            $_SESSION['message'] = 'An error occurred. Please try again.';
            $_SESSION['message_type'] = 'danger';
            manage_users_redirect('patients');
        }
        $findStmt->bind_param('i', $id);
        $findStmt->execute();
        $found = $findStmt->get_result();
        $targetPatient = $found ? $found->fetch_assoc() : null;
        $findStmt->close();

        if (!$targetPatient) {
            $_SESSION['message'] = 'Patient account not found.';
            $_SESSION['message_type'] = 'danger';
            manage_users_redirect('patients');
        }

        $patientEmail = trim((string)($targetPatient['email'] ?? ''));
        if ($patientEmail === '') {
            $_SESSION['message'] = 'This patient has no email on file. Add an email before generating a login password.';
            $_SESSION['message_type'] = 'warning';
            manage_users_redirect('patients');
        }

        $temporaryPassword = security_generate_default_password();
        $temporaryHash = security_hash_password($temporaryPassword);

        $updSql = 'UPDATE patients
                   SET password_hash = ?,
                       must_change_password = 0,
                       password_updated_at = NOW(),
                       failed_login_attempts = 0,
                       locked_until = NULL
                   WHERE patient_id = ?';
        $updStmt = $conn->prepare($updSql);
        if (!$updStmt) {
            $_SESSION['message'] = 'An error occurred. Please try again.';
            $_SESSION['message_type'] = 'danger';
            manage_users_redirect('patients');
        }
        $updStmt->bind_param('si', $temporaryHash, $id);
        $ok = $updStmt->execute();
        $updStmt->close();

        if ($ok) {
            remember_me_forget_all($conn, 'patient', (int)$id);
            $displayName = trim((string)($targetPatient['first_name'] ?? '') . ' ' . (string)($targetPatient['last_name'] ?? ''));
            security_set_generated_password_modal(
                $temporaryPassword,
                $displayName . ' (patient)',
                'Temporary password generated for patient.',
                false
            );
        } else {
            $_SESSION['message'] = 'Failed to reset patient password.';
            $_SESSION['message_type'] = 'danger';
        }

        manage_users_redirect('patients');
    }

    $findSql = "SELECT u.user_id, u.first_name, u.last_name, u.email, r.role_name
                FROM users u
                LEFT JOIN role r ON r.role_id = u.role_id
                WHERE u.user_id = ?
                LIMIT 1";
    $findStmt = $conn->prepare($findSql);
    if (!$findStmt) {
        $_SESSION['message'] = 'An error occurred. Please try again.';
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
        $_SESSION['message'] = 'An error occurred. Please try again.';
        $_SESSION['message_type'] = 'danger';
        manage_users_redirect('users');
    }
    $updStmt->bind_param('si', $temporaryHash, $id);
    $ok = $updStmt->execute();
    $updStmt->close();

    if ($ok) {
        remember_me_forget_all($conn, 'user', (int)$id);
        if ((int)($_SESSION['user_id'] ?? 0) === $id) {
            $_SESSION['must_change_password'] = true;
        }
        $displayName = trim((string)($targetUser['first_name'] ?? '') . ' ' . (string)($targetUser['last_name'] ?? ''));
        $roleName = (string)($targetUser['role_name'] ?? 'user');
        security_set_generated_password_modal(
            $temporaryPassword,
            $displayName . ' (' . $roleName . ')',
            'Temporary password generated successfully.',
            true
        );
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

<div class="module-page-header mb-3">
    <h4 class="mb-0">
        <span class="module-page-header__crumb">User Management</span> <span class="module-page-header__sep">&gt;</span> System Users
    </h4>
</div>

<!-- Alert Messages -->
<?php if (isset($_SESSION['message'])): ?>
    <div class="alert alert-<?php echo $_SESSION['message_type']; ?> alert-dismissible fade show" role="alert">
        <?php echo htmlspecialchars((string)$_SESSION['message']); ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
    </div>
    <?php unset($_SESSION['message']); unset($_SESSION['message_type']); ?>
<?php endif; ?>

<!-- Tab Navigation -->
<nav class="module-section-nav" aria-label="User management sections">
    <div class="module-section-nav__track">
        <ul class="nav nav-tabs mb-0" id="userTabs" role="tablist">
            <li class="nav-item" role="presentation">
                <button class="nav-link <?php echo $user_type === 'users' ? 'active' : ''; ?>" id="users-tab" 
                        data-bs-toggle="tab" data-bs-target="#users-content" type="button" 
                        onclick="ajaxNavigate('admin.php?page=manage_users&type=users')" role="tab">
                    <i class="bi bi-people"></i> Clinic Users (<?php echo count($users); ?>)
                </button>
            </li>
        </ul>
    </div>
</nav>

<div class="tab-content" id="userTabContent">
    <!-- USERS TABLE -->
    <div class="tab-pane fade <?php echo $user_type === 'users' ? 'show active' : ''; ?>" id="users-content" role="tabpanel">
        <div class="card">
            <div class="card-header d-flex justify-content-between align-items-center">
                <h5 class="card-title mb-0">Clinic Users</h5>
                <button type="button" class="btn btn-primary btn-sm" data-bs-toggle="modal" data-bs-target="#addUserModal">
                    <i class="bi bi-plus-circle"></i> Add User
                </button>
            </div>
            <div class="card-body">
                <div class="table-responsive">
                    <table class="table table-hover datatable" data-skip-table-search="1">
                        <thead>
                            <tr>
                                <th>User</th>
                                <th>Role</th>
                                <th>Status</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($users as $user): ?>
                                <?php $isDoctor = ((int)($user['role_id'] ?? 0) === (int)$doctorRoleId); ?>
                                <?php
                                    $userAvatar = '';
                                    if (!empty($user['profile_image'])) {
                                        $imgPath = $user['profile_image'];
                                        $imgUrl = (strpos($imgPath, 'http') === 0) ? $imgPath : '../' . ltrim($imgPath, '/');
                                        $userAvatar = '<img src="' . htmlspecialchars($imgUrl) . '" alt="" style="width:32px;height:32px;border-radius:50%;object-fit:cover;" class="me-2">';
                                    } else {
                                        $userAvatar = '<span class="me-2 d-inline-flex align-items-center justify-content-center rounded-circle bg-secondary text-white" style="width:32px;height:32px;font-size:0.85rem;"><i class="bi bi-person-fill"></i></span>';
                                    }
                                ?>
                                <tr>
                                    <td>
                                        <?php echo $userAvatar; ?>
                                        <div class="d-inline-block align-middle">
                                            <div class="fw-semibold"><?php echo htmlspecialchars($user['first_name'] . ' ' . $user['last_name']); ?></div>
                                        </div>
                                    </td>
                                    <td>
                                        <span class="badge bg-secondary">
                                            <?php echo htmlspecialchars($user['role_name'] ?? 'N/A'); ?>
                                        </span>
                                    </td>
                                    <td>
                                        <?php if ($user['is_active']): ?>
                                            <span class="badge bg-success">Active</span>
                                        <?php else: ?>
                                            <span class="badge bg-warning">Inactive</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <button type="button" class="btn btn-sm btn-primary" data-bs-toggle="modal"
                                                data-bs-target="#viewUserModal" onclick="viewUser(<?php echo htmlspecialchars(json_encode($user)); ?>)" title="View Details">
                                            <i class="bi bi-eye"></i>
                                        </button>
                                        <form method="POST" style="display:inline;" data-confirm="Generate a new temporary password for this account?" data-confirm-title="Reset Password" data-confirm-ok="Generate">
                                            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">
                                            <input type="hidden" name="action" value="reset_password">
                                            <input type="hidden" name="id" value="<?php echo (int)$user['user_id']; ?>">
                                            <button type="submit" class="btn btn-sm btn-warning" title="Generate Temporary Password">
                                                <i class="bi bi-key"></i>
                                            </button>
                                        </form>
                                        <?php if ((int)($user['is_active'] ?? 0) === 1): ?>
                                            <form method="POST" style="display:inline;" 
                                                  data-confirm="Are you sure you want to archive this user?"
                                                  data-confirm-title="Archive User"
                                                  data-confirm-variant="danger"
                                                  data-confirm-ok="Archive">
                                                                                            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">
                                                <input type="hidden" name="action" value="archive">
                                                <input type="hidden" name="id" value="<?php echo $user['user_id']; ?>">
                                                <input type="hidden" name="type" value="users">
                                                <button type="submit" class="btn btn-sm btn-danger" title="Archive User">
                                                    <i class="bi bi-trash"></i>
                                                </button>
                                            </form>
                                        <?php else: ?>
                                            <form method="POST" style="display:inline;" 
                                                  data-confirm="Reactivate this user account?"
                                                  data-confirm-title="Reactivate User"
                                                  data-confirm-ok="Reactivate">
                                                                                            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">
                                                <input type="hidden" name="action" value="reactivate">
                                                <input type="hidden" name="id" value="<?php echo $user['user_id']; ?>">
                                                <input type="hidden" name="type" value="users">
                                                <button type="submit" class="btn btn-sm btn-success" title="Reactivate User">
                                                    <i class="bi bi-arrow-clockwise"></i>
                                                </button>
                                            </form>
                                        <?php endif; ?>
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
                        <div class="col-md-4">
                            <label class="form-label">First Name</label>
                            <input type="text" class="form-control" id="add_first_name" name="first_name" required>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">Middle Name</label>
                            <input type="text" class="form-control" id="add_middle_name" name="middle_name">
                        </div>
                        <div class="col-md-4">
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
                            <small class="text-muted">A strong default password is generated automatically. The user can change it anytime in Account Settings.</small>
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
                    <div class="row mb-2">
                        <div class="col-md-4">
                            <label class="form-label">First Name</label>
                            <input type="text" class="form-control" name="first_name" id="edit_first_name" required>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">Middle Name</label>
                            <input type="text" class="form-control" name="middle_name" id="edit_middle_name">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">Last Name</label>
                            <input type="text" class="form-control" name="last_name" id="edit_last_name" required>
                        </div>
                    </div>
                    <div class="row mb-2">
                        <div class="col-md-6">
                            <label class="form-label">Email</label>
                            <input type="email" class="form-control" name="email" id="edit_email" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Phone Number</label>
                            <input type="tel" class="form-control" name="phone_number" id="edit_phone_number">
                        </div>
                    </div>
                    <div class="row mb-2">
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
                        <div class="col-md-6" id="edit_specialization_wrap" style="display:none;">
                            <label class="form-label">Specialization</label>
                            <select class="form-control" name="specialization_id" id="edit_specialization_id">
                                <option value="">Select specialization</option>
                                <?php foreach ($specializations as $spec): ?>
                                    <option value="<?php echo (int)$spec['specialization_id']; ?>"><?php echo htmlspecialchars($spec['specialization_name']); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                    <div class="row mb-2">
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
                    <div class="row mb-2">
                        <div class="col-md-8">
                            <label class="form-label">Address</label>
                            <input type="text" class="form-control" name="address" id="edit_address">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">Status</label>
                            <select class="form-control" name="is_active" id="edit_is_active">
                                <option value="1">Active</option>
                                <option value="0">Inactive</option>
                            </select>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="submit" class="btn btn-primary">Update User</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- ========== VIEW USER MODAL (read-only details) ========== -->
<div class="modal fade" id="viewUserModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><i class="bi bi-person-badge"></i> User Details</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <div class="d-flex align-items-center gap-3 mb-3">
                    <div id="view_user_avatar" class="flex-shrink-0" style="width: 72px; height: 72px; border-radius: 50%; background: #eef1f6; display: flex; align-items: center; justify-content: center; overflow: hidden;">
                        <i class="bi bi-person-fill" style="font-size: 2rem; opacity: 0.5;"></i>
                    </div>
                    <div>
                        <h5 class="mb-0" id="view_user_name">—</h5>
                        <span id="view_user_status_badge"></span>
                    </div>
                </div>
                <div class="row row-cols-2 g-3">
                    <div>
                        <div class="small text-muted">Role</div>
                        <div class="fw-semibold" id="view_user_role">—</div>
                    </div>
                    <div>
                        <div class="small text-muted">Work Type</div>
                        <div class="fw-semibold" id="view_user_work_type">—</div>
                    </div>
                    <div id="view_user_specialization_wrap">
                        <div class="small text-muted">Specialization</div>
                        <div class="fw-semibold" id="view_user_specialization">—</div>
                    </div>
                    <div>
                        <div class="small text-muted">Email</div>
                        <div class="fw-semibold" id="view_user_email">—</div>
                    </div>
                    <div>
                        <div class="small text-muted">Phone</div>
                        <div class="fw-semibold" id="view_user_phone">—</div>
                    </div>
                    <div>
                        <div class="small text-muted">Date of Birth</div>
                        <div class="fw-semibold" id="view_user_dob">—</div>
                    </div>
                    <div>
                        <div class="small text-muted">Gender</div>
                        <div class="fw-semibold" id="view_user_gender">—</div>
                    </div>
                    <div class="col-12">
                        <div class="small text-muted">Address</div>
                        <div class="fw-semibold" id="view_user_address">—</div>
                    </div>
                    <div>
                        <div class="small text-muted">Account Created</div>
                        <div class="fw-semibold" id="view_user_created">—</div>
                    </div>
                    <div>
                        <div class="small text-muted">Last Login</div>
                        <div class="fw-semibold" id="view_user_last_login">—</div>
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-info" id="view_user_edit_btn" data-bs-toggle="modal" data-bs-target="#editUserModal">
                    <i class="bi bi-pencil"></i> Edit
                </button>
            </div>
        </div>
    </div>
</div>

<script>
// Edit User Function
function editUser(user) {
    document.getElementById('edit_user_id').value = user.user_id;
    document.getElementById('edit_first_name').value = user.first_name;
    document.getElementById('edit_middle_name').value = user.middle_name || '';
    document.getElementById('edit_last_name').value = user.last_name;
    document.getElementById('edit_email').value = user.email;
    document.getElementById('edit_phone_number').value = user.phone_number;
    document.getElementById('edit_date_of_birth').value = user.date_of_birth;
    document.getElementById('edit_gender').value = user.gender;
    document.getElementById('edit_address').value = user.address;
    document.getElementById('edit_is_active').value = user.is_active;
    document.getElementById('edit_role_id').value = user.role_id;
    document.getElementById('edit_specialization_id').value = user.specialization_id ? String(user.specialization_id) : '';
    toggleDoctorWorkTypeFields();
}

// View User Function — populates the read-only details modal
var lastViewedUser = null;
function viewUser(user) {
    lastViewedUser = user;
    const doctorRoleId = <?php echo (int)$doctorRoleId; ?>;
    const isDoctor = String(user.role_id) === String(doctorRoleId);

    // Set avatar
    const avatarEl = document.getElementById('view_user_avatar');
    if (user.profile_image) {
        const imgUrl = /^https?:\/\//i.test(user.profile_image) ? user.profile_image : '../' + user.profile_image.replace(/^\/+/, '');
        avatarEl.innerHTML = '<img src="' + imgUrl + '" alt="" style="width:100%;height:100%;object-fit:cover;">';
    } else {
        avatarEl.innerHTML = '<i class="bi bi-person-fill" style="font-size: 2rem; opacity: 0.5;"></i>';
    }

    document.getElementById('view_user_name').textContent = [user.first_name, user.middle_name, user.last_name]
        .filter(function (v) { return v && String(v).trim() && String(v).trim().toUpperCase() !== 'N/A'; })
        .join(' ');
    document.getElementById('view_user_status_badge').innerHTML = (Number(user.is_active) === 1)
        ? '<span class="badge bg-success">Active</span>'
        : '<span class="badge bg-warning">Inactive</span>';
    document.getElementById('view_user_role').textContent = user.role_name || 'N/A';
    document.getElementById('view_user_work_type').textContent = isDoctor
        ? (user.work_type === 'part_time' ? 'Part-time' : 'Regular')
        : 'Regular';
    document.getElementById('view_user_specialization_wrap').style.display = isDoctor ? '' : 'none';
    document.getElementById('view_user_specialization').textContent = user.specialization_name || '—';
    document.getElementById('view_user_email').textContent = user.email || '—';
    document.getElementById('view_user_phone').textContent = user.phone_number || '—';
    document.getElementById('view_user_dob').textContent = user.date_of_birth || '—';
    document.getElementById('view_user_gender').textContent = user.gender || '—';
    document.getElementById('view_user_address').textContent = user.address || '—';
    document.getElementById('view_user_created').textContent = formatPatientDateTime(user.created_at);
    document.getElementById('view_user_last_login').textContent = formatPatientDateTime(user.last_login_at);
}

document.getElementById('view_user_edit_btn')?.addEventListener('click', function () {
    if (lastViewedUser) editUser(lastViewedUser);
});

function toggleDoctorWorkTypeFields() {
    const doctorRoleId = <?php echo (int)$doctorRoleId; ?>;
    const addRole = document.getElementById('add_role_id');
    const editRole = document.getElementById('edit_role_id');
    const addWrap = document.getElementById('add_work_type_wrap');
    const addSpecWrap = document.getElementById('add_specialization_wrap');
    const editSpecWrap = document.getElementById('edit_specialization_wrap');
    const addWorkType = document.getElementById('add_work_type');
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
    if (editRole && editSpecWrap) {
        const isDoctor = (String(editRole.value) === String(doctorRoleId));
        editSpecWrap.style.display = isDoctor ? '' : 'none';
        if (editSpec) editSpec.required = isDoctor;
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
</script>

<!-- Temporary password modal (copyable; not shown in toast) -->
<div class="modal fade" id="tempPasswordModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Temporary Password</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <p class="mb-2 fw-semibold" id="tempPasswordAccountLabel"></p>
                <p class="text-muted small mb-3" id="tempPasswordHint"></p>
                <label class="form-label" for="tempPasswordValue">Password</label>
                <div class="input-group">
                    <input type="text" class="form-control font-monospace" id="tempPasswordValue" readonly autocomplete="off">
                    <button type="button" class="btn btn-outline-primary" id="tempPasswordCopyBtn">Copy</button>
                </div>
        </div>
    </div>
</div>

<?php if (!empty($_SESSION['temp_password_modal'])):
    $tempPasswordModal = $_SESSION['temp_password_modal'];
    unset($_SESSION['temp_password_modal']);
?>
<script>
document.addEventListener('DOMContentLoaded', function () {
    const modalEl = document.getElementById('tempPasswordModal');
    const input = document.getElementById('tempPasswordValue');
    const labelEl = document.getElementById('tempPasswordAccountLabel');
    const copyBtn = document.getElementById('tempPasswordCopyBtn');
    const hintEl = document.getElementById('tempPasswordHint');
    if (!modalEl || !input || !labelEl) return;

    labelEl.textContent = <?php echo json_encode('Account: ' . ($tempPasswordModal['account_label'] ?? ''), JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP); ?>;
    input.value = <?php echo json_encode((string)($tempPasswordModal['password'] ?? ''), JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP); ?>;
    if (hintEl) {
        hintEl.textContent = <?php echo !empty($tempPasswordModal['require_change_on_login'])
            ? json_encode('Copy this password and share it securely. This staff account must change it before using other pages.', JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP)
            : json_encode('Copy this password and share it securely. The account may change it anytime from Account Settings.', JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP); ?>;
    }

    function copyPassword() {
        const value = input.value || '';
        if (!value) return;
        const done = function () {
            if (typeof window.showToast === 'function') {
                window.showToast('Password copied to clipboard.', 'success');
            }
        };
        if (navigator.clipboard && navigator.clipboard.writeText) {
            navigator.clipboard.writeText(value).then(done).catch(function () {
                input.focus();
                input.select();
                document.execCommand('copy');
                done();
            });
        } else {
            input.focus();
            input.select();
            document.execCommand('copy');
            done();
        }
    }

    copyBtn?.addEventListener('click', copyPassword);
    input.addEventListener('click', function () {
        input.select();
    });

    bootstrap.Modal.getOrCreateInstance(modalEl).show();
});
</script>
<?php endif; ?>
