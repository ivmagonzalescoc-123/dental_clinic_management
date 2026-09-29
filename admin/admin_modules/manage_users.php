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

<div class="module-page-header mb-3">
    <h4 class="mb-0">
        <?php if ($user_type === 'patients'): ?>
            <span class="module-page-header__crumb">User Management</span> <span class="module-page-header__sep">&gt;</span> Patients
        <?php else: ?>
            <span class="module-page-header__crumb">User Management</span> <span class="module-page-header__sep">&gt;</span> System Users
        <?php endif; ?>
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
            <li class="nav-item" role="presentation">
                <button class="nav-link <?php echo $user_type === 'patients' ? 'active' : ''; ?>" id="patients-tab" 
                        data-bs-toggle="tab" data-bs-target="#patients-content" type="button"
                        onclick="ajaxNavigate('admin.php?page=manage_users&type=patients')" role="tab">
                    <i class="bi bi-heart-pulse"></i> Patients (<?php echo count($patients); ?>)
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

    <!-- PATIENTS TABLE -->
    <div class="tab-pane fade <?php echo $user_type === 'patients' ? 'show active' : ''; ?>" id="patients-content" role="tabpanel">
        <div class="d-flex justify-content-between align-items-center gap-2 mb-3">
            <div>
                <h5 class="card-title mb-0">Patient Settings</h5>
            </div>
            <button type="button" class="btn btn-primary btn-sm" data-bs-toggle="modal" data-bs-target="#addPatientModal">
                <i class="bi bi-plus-circle"></i> Add Patient
            </button>
        </div>

        <div class="table-responsive">
            <table class="table table-hover datatable" id="patientsTable" data-skip-table-search="1">
<thead>
                    <tr>
                        <th>ID</th>
                        <th>Patient</th>
                        <th>Phone</th>
                        <th>DOB</th>
                        <th>Gender</th>
                        <th class="text-end">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($patients)): ?>
                        <tr><td colspan="6" class="text-center text-muted py-4">No patients found.</td></tr>
                    <?php else: ?>
                    <?php foreach ($patients as $patient): ?>
                        <?php $patientJson = htmlspecialchars(json_encode($patient, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP), ENT_QUOTES, 'UTF-8'); ?>
                        <?php
                            $patientAvatar = '';
                            if (!empty($patient['profile_image'])) {
                                $imgPath = $patient['profile_image'];
                                $imgUrl = (strpos($imgPath, 'http') === 0) ? $imgPath : '../' . ltrim($imgPath, '/');
                                $patientAvatar = '<img src="' . htmlspecialchars($imgUrl) . '" alt="" style="width:36px;height:36px;border-radius:50%;object-fit:cover;" class="me-2">';
                            } else {
                                $patientAvatar = '<span class="me-2 d-inline-flex align-items-center justify-content-center rounded-circle bg-secondary text-white" style="width:36px;height:36px;font-size:0.9rem;"><i class="bi bi-person-fill"></i></span>';
                            }
                        ?>
                        <tr>
                            <td><?php echo (int)$patient['patient_id']; ?></td>
                            <td>
                                <?php echo $patientAvatar; ?>
                                <div class="d-inline-block align-middle">
                                    <?php
                                        $puiMiddle = trim((string)($patient['middle_name'] ?? ''));
                                        $puiMiddle = ($puiMiddle !== '' && strtoupper($puiMiddle) !== 'N/A') ? $puiMiddle . ' ' : '';
                                    ?>
                                    <div class="fw-semibold"><?php echo htmlspecialchars(trim(($patient['first_name'] ?? '') . ' ' . $puiMiddle . ($patient['last_name'] ?? '')), ENT_QUOTES, 'UTF-8'); ?></div>
                                    <div class="small text-muted"><?php echo htmlspecialchars((string)($patient['email'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></div>
                                </div>
                            </td>
                            <td><?php echo htmlspecialchars((string)($patient['phone_number'] ?? '—'), ENT_QUOTES, 'UTF-8'); ?></td>
                            <td><?php echo htmlspecialchars((string)($patient['date_of_birth'] ?? '—'), ENT_QUOTES, 'UTF-8'); ?></td>
                            <td><?php echo htmlspecialchars((string)($patient['gender'] ?? '—'), ENT_QUOTES, 'UTF-8'); ?></td>
                            <td class="text-end">
                                <button type="button" class="btn btn-sm btn-primary" title="View and manage patient" onclick="openPatientProfileModal(<?php echo $patientJson; ?>, 'profile')">
                                    <i class="bi bi-person-lines-fill"></i> Manage
                                </button>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
                    </table>
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

<!-- ========== ADD PATIENT MODAL ========== -->
<div class="modal fade" id="addPatientModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-scrollable modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header patient-profile-modal__header">
                <h5 class="modal-title mb-0"><i class="bi bi-person-plus" aria-hidden="true"></i> Add Patient</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <form id="addPatientForm" class="ux-form-shell" method="POST" action="#">
                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token, ENT_QUOTES, 'UTF-8'); ?>">
                    <div class="ux-form-grid">
                        <div>
                            <label class="form-label">First Name *</label>
                            <input type="text" name="first_name" class="form-control" required>
                        </div>
                        <div>
                            <label class="form-label">Middle Name</label>
                            <input type="text" name="middle_name" class="form-control">
                        </div>
                        <div>
                            <label class="form-label">Last Name *</label>
                            <input type="text" name="last_name" class="form-control" required>
                        </div>
                        <div>
                            <label class="form-label">Email *</label>
                            <input type="email" name="email" class="form-control" required>
                            <div class="form-text">Login credentials will be sent to this address.</div>
                        </div>
                        <div>
                            <label class="form-label">Phone</label>
                            <input type="tel" name="phone_number" class="form-control compact-control">
                        </div>
                        <div>
                            <label class="form-label">Date of Birth</label>
                            <input type="date" name="date_of_birth" class="form-control compact-control">
                        </div>
                        <div>
                            <label class="form-label">Gender</label>
                            <select name="gender" class="form-select">
                                <option value="">Unspecified</option>
                                <option value="Male">Male</option>
                                <option value="Female">Female</option>
                                <option value="Other">Other</option>
                            </select>
                        </div>
                        <div class="form-span-2">
                            <label class="form-label">Address</label>
                            <textarea name="address" class="form-control" rows="2"></textarea>
                        </div>
                        <div class="form-span-2">
                            <label class="form-label">Password</label>
                            <input type="text" class="form-control" value="Random password emailed to patient" readonly>
                            <div class="form-text">A secure random password is generated and emailed to the patient.</div>
                        </div>
                    </div>
                    <input type="hidden" name="added_by" value="<?php echo htmlspecialchars((string)($_SESSION['user_id'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>">
                </form>
            </div>
            <div class="modal-footer">
                <button type="submit" form="addPatientForm" class="btn btn-primary">Create Patient</button>
            </div>
        </div>
    </div>
</div>

<!-- ========== PATIENT PROFILE MODAL (tabbed) ========== -->
<div class="modal fade" id="patientProfileModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header patient-profile-modal__header py-2 px-3">
                <div class="d-flex align-items-center gap-2 flex-grow-1 min-w-0">
                    <div id="patientProfileModalAvatar" class="patient-profile-modal__avatar" aria-hidden="true"></div>
                    <h5 class="modal-title mb-0" id="patientProfileModalTitle" style="font-size:0.9rem;">Patient Profile</h5>
                </div>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body py-2 px-3">
                <ul class="nav nav-tabs nav-tabs-sm" id="patientProfileTabs" role="tablist">
                    <li class="nav-item" role="presentation">
                        <button class="nav-link active py-1 px-2" id="patient-tab-profile" data-bs-toggle="tab" data-bs-target="#patientTabProfile" type="button" role="tab" style="font-size:0.82rem;">Profile</button>
                    </li>
                    <li class="nav-item" role="presentation">
                        <button class="nav-link py-1 px-2" id="patient-tab-account" data-bs-toggle="tab" data-bs-target="#patientTabAccount" type="button" role="tab" style="font-size:0.82rem;">Account</button>
                    </li>
                </ul>

                <div class="tab-content pt-2">
                    <div class="tab-pane fade show active" id="patientTabProfile" role="tabpanel">
                        <form id="editPatientForm" class="ux-form-shell" method="POST" action="#">
                            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token, ENT_QUOTES, 'UTF-8'); ?>">
                            <input type="hidden" name="patient_id" id="edit_patient_id">
                            <input type="hidden" name="gender" id="edit_patient_gender_sync">
                            <div style="display:grid;grid-template-columns:1fr 1fr;gap:0.35rem 0.5rem;">
                                <div>
                                    <label class="form-label fw-bold" style="font-size:0.82rem;color:#1e293b;">First Name *</label>
                                    <input type="text" class="form-control" name="first_name" id="edit_patient_first_name" required style="padding:0.3rem 0.5rem;font-size:0.85rem;">
                                </div>
                                <div>
                                    <label class="form-label fw-bold" style="font-size:0.82rem;color:#1e293b;">Middle Name</label>
                                    <input type="text" class="form-control" name="middle_name" id="edit_patient_middle_name" style="padding:0.3rem 0.5rem;font-size:0.85rem;">
                                </div>
                                <div>
                                    <label class="form-label fw-bold" style="font-size:0.82rem;color:#1e293b;">Last Name *</label>
                                    <input type="text" class="form-control" name="last_name" id="edit_patient_last_name" required style="padding:0.3rem 0.5rem;font-size:0.85rem;">
                                </div>
                                <div>
                                    <label class="form-label fw-bold" style="font-size:0.82rem;color:#1e293b;">Email *</label>
                                    <input type="email" class="form-control" name="email" id="edit_patient_email" required style="padding:0.3rem 0.5rem;font-size:0.85rem;">
                                </div>
                                <div>
                                    <label class="form-label fw-bold" style="font-size:0.82rem;color:#1e293b;">Phone</label>
                                    <input type="tel" class="form-control" name="phone_number" id="edit_patient_phone_number" style="padding:0.3rem 0.5rem;font-size:0.85rem;">
                                </div>
                                <div>
                                    <label class="form-label fw-bold" style="font-size:0.82rem;color:#1e293b;">Birthdate *</label>
                                    <input type="date" class="form-control" name="date_of_birth" id="edit_patient_date_of_birth" required style="padding:0.3rem 0.5rem;font-size:0.85rem;">
                                </div>
                                <div>
                                    <label class="form-label fw-bold" style="font-size:0.82rem;color:#1e293b;">Address</label>
                                    <input type="text" class="form-control" name="address" id="edit_patient_address" style="padding:0.3rem 0.5rem;font-size:0.85rem;">
                                </div>
                                <div>
                                    <label class="form-label fw-bold" style="font-size:0.82rem;color:#1e293b;">Occupation</label>
                                    <input type="text" class="form-control" name="occupation" id="edit_patient_occupation" placeholder="e.g. Teacher, Engineer" style="padding:0.3rem 0.5rem;font-size:0.85rem;">
                                </div>
                            </div>
                            <div id="editPatientAlert" class="mt-1"></div>
                            <div class="d-flex justify-content-end mt-1">
                                <button type="submit" class="btn btn-primary btn-sm" style="font-size:0.82rem;padding:0.3rem 0.8rem;">Save</button>
                            </div>
                        </form>
                    </div>

                    <div class="tab-pane fade" id="patientTabAccount" role="tabpanel">
                        <div class="d-flex gap-2 mb-2">
                            <div class="flex-fill p-2 rounded border bg-light">
                                <div style="font-size:0.75rem;color:#64748b;">Phone</div>
                                <div class="fw-semibold" style="font-size:0.82rem;" id="account_patient_phone">—</div>
                            </div>
                            <div class="flex-fill p-2 rounded border bg-light">
                                <div style="font-size:0.75rem;color:#64748b;">Email</div>
                                <div class="fw-semibold" style="font-size:0.82rem;" id="account_patient_email">—</div>
                            </div>
                            <div class="flex-fill p-2 rounded border bg-light">
                                <div style="font-size:0.75rem;color:#64748b;">Last Login</div>
                                <div class="fw-semibold" style="font-size:0.82rem;" id="account_patient_last_login">—</div>
                            </div>
                        </div>

                        <div style="display:grid;grid-template-columns:1fr 1fr;gap:0.5rem;">
                        <div class="card border">
                            <div class="card-body py-2 px-3">
                                <h6 class="mb-1" style="font-size:0.82rem;font-weight:600;">Set Password</h6>
                                <form id="setPatientPasswordForm">
                                    <?php
                                    $password_field_id = 'set_patient_password';
                                    $password_field_label = 'New';
                                    $password_field_attrs = 'required minlength="8" autocomplete="new-password" style="padding:0.3rem 0.5rem;font-size:0.85rem;"';
                                    include dirname(dirname(__DIR__)) . '/includes/password_field.php';
                                    $password_field_id = 'set_patient_password_confirm';
                                    $password_field_label = 'Confirm';
                                    $password_field_attrs = 'required minlength="8" autocomplete="new-password" style="padding:0.3rem 0.5rem;font-size:0.85rem;"';
                                    include dirname(dirname(__DIR__)) . '/includes/password_field.php';
                                    ?>
                                    <button type="submit" class="btn btn-primary btn-sm mt-1" style="font-size:0.82rem;padding:0.3rem 0.7rem;">Update</button>
                                </form>
                            </div>
                        </div>

                        <div class="card border">
                            <div class="card-body py-2 px-3">
                                <h6 class="mb-1" style="font-size:0.82rem;font-weight:600;">Temp Password</h6>
                                <p class="mb-1" style="font-size:0.72rem;color:#64748b;">Generate random password for patient.</p>
                                <form method="POST" action="../assets/api/patients/reset_patient_password.php" id="resetPatientPasswordForm" data-confirm="Generate a new temporary password?" data-confirm-title="Reset Password" data-confirm-ok="Generate">
                                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token, ENT_QUOTES, 'UTF-8'); ?>">
                                    <input type="hidden" name="patient_id" id="reset_patient_id" value="">
                                    <button type="submit" class="btn btn-primary btn-sm" style="font-size:0.82rem;padding:0.3rem 0.7rem;">
                                        <i class="bi bi-key"></i> Generate
                                    </button>
                                </form>
                            </div>
                        </div>
                        </div>
                    </div>
                </div>
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
let lastViewedUser = null;
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

<script>
window.adminPatientCsrf = <?php echo json_encode($csrf_token, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP); ?>;
window.activePatientRecord = null;

function notifyAdminPatients(message, type) {
    if (typeof window.portalNotify === 'function') {
        window.portalNotify(String(message || ''), type || 'info');
        return;
    }
    if (typeof window.showAppToast === 'function') {
        window.showAppToast(String(message || ''), type || 'info');
        return;
    }
    if (typeof window.showToast === 'function') {
        window.showToast(String(message || ''), type || 'info');
    }
}

function patientDisplayName(patient) {
    return [patient.first_name, patient.middle_name, patient.last_name]
        .filter(function (v) { return v && String(v).trim() && String(v).trim().toUpperCase() !== 'N/A'; })
        .join(' ').replace(/\s+/g, ' ').trim();
}

function formatPatientDateTime(value) {
    if (!value) return '—';
    const date = new Date(String(value).replace(' ', 'T'));
    if (Number.isNaN(date.getTime())) return value;
    return date.toLocaleString(undefined, { year: 'numeric', month: 'short', day: 'numeric', hour: 'numeric', minute: '2-digit' });
}

function escapePatientHtml(value) {
    return String(value || '')
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;');
}

function renderPatientModalAvatar(patient) {
    const wrap = document.getElementById('patientProfileModalAvatar');
    if (!wrap) return;
    const name = patientDisplayName(patient);
    const imagePath = String(patient.profile_image || '').trim();
    if (imagePath !== '') {
        const url = /^https?:\/\//i.test(imagePath) ? imagePath : '../' + imagePath.replace(/^\/+/, '');
        wrap.innerHTML = '<img src="' + escapePatientHtml(url) + '" alt="' + escapePatientHtml(name) + '" class="patient-profile-modal__avatar-img">';
        return;
    }
    wrap.innerHTML = '<span class="profile-avatar-icon patient-profile-modal__avatar-icon" role="img" aria-label="' + escapePatientHtml(name) + '"><i class="bi bi-person-fill" aria-hidden="true"></i></span>';
}

function fillProfileForm(patient) {
    document.getElementById('edit_patient_id').value = patient.patient_id || '';
    document.getElementById('edit_patient_first_name').value = patient.first_name || '';
    document.getElementById('edit_patient_middle_name').value = patient.middle_name || '';
    document.getElementById('edit_patient_last_name').value = patient.last_name || '';
    document.getElementById('edit_patient_email').value = patient.email || '';
    document.getElementById('edit_patient_phone_number').value = patient.phone_number || '';
    document.getElementById('edit_patient_date_of_birth').value = patient.date_of_birth || '';
    document.getElementById('edit_patient_gender_sync').value = patient.gender || '';
    document.getElementById('edit_patient_address').value = patient.address || '';
    document.getElementById('edit_patient_occupation').value = patient.occupation || '';
    document.getElementById('reset_patient_id').value = patient.patient_id || '';
    document.getElementById('account_patient_phone').textContent = patient.phone_number || '—';
    document.getElementById('account_patient_email').textContent = patient.email || '—';
    document.getElementById('account_patient_last_login').textContent = formatPatientDateTime(patient.last_login_at);
}

function openPatientProfileModal(patient, activeTab) {
    activePatientRecord = Object.assign({}, patient);
    const modalEl = document.getElementById('patientProfileModal');
    const modal = bootstrap.Modal.getOrCreateInstance(modalEl);
    document.getElementById('patientProfileModalTitle').textContent = patientDisplayName(patient);
    renderPatientModalAvatar(patient);
    fillProfileForm(patient);

    const tabId = activeTab === 'account' ? 'patient-tab-account' : 'patient-tab-profile';
    const tabTrigger = document.getElementById(tabId);
    if (tabTrigger) bootstrap.Tab.getOrCreateInstance(tabTrigger).show();
    modal.show();
}

// Edit patient profile via AJAX
document.getElementById('editPatientForm').addEventListener('submit', function (event) {
    event.preventDefault();
    const form = event.target;
    const data = new FormData(form);
    const alertEl = document.getElementById('editPatientAlert');

    fetch('../assets/api/patients/update_patient.php', {
        method: 'POST',
        body: data
    })
        .then(function (response) { return response.json(); })
        .then(function (result) {
            if (result.success) {
                notifyAdminPatients(result.message || 'Patient updated successfully.', 'success');
                if (activePatientRecord) {
                    activePatientRecord.first_name = data.get('first_name');
                    activePatientRecord.middle_name = data.get('middle_name');
                    activePatientRecord.last_name = data.get('last_name');
                    activePatientRecord.email = data.get('email');
                    activePatientRecord.phone_number = data.get('phone_number');
                    activePatientRecord.date_of_birth = data.get('date_of_birth');
                    activePatientRecord.gender = data.get('gender');
                    activePatientRecord.address = data.get('address');
                    activePatientRecord.occupation = data.get('occupation');
                }
                if (alertEl) alertEl.innerHTML = '';
            } else {
                if (alertEl) {
                    alertEl.innerHTML = '<div class="alert alert-danger py-2 px-3 mb-0 small">' + (result.message || 'Unable to update patient.') + '</div>';
                }
                notifyAdminPatients(result.message || 'Unable to update patient.', 'danger');
            }
        })
        .catch(function () {
            notifyAdminPatients('Unable to update patient.', 'danger');
        });
});

document.getElementById('setPatientPasswordForm').addEventListener('submit', function (event) {
    event.preventDefault();
    if (!activePatientRecord || !activePatientRecord.patient_id) {
        notifyAdminPatients('Select a patient first.', 'warning');
        return;
    }
    const newPassword = document.getElementById('set_patient_password').value;
    const confirmPassword = document.getElementById('set_patient_password_confirm').value;
    fetch('../assets/api/patients/set_patient_password.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ csrf_token: adminPatientCsrf, patient_id: activePatientRecord.patient_id, new_password: newPassword, confirm_password: confirmPassword })
    })
        .then(function (response) { return response.json(); })
        .then(function (result) {
            if (result.success) {
                notifyAdminPatients(result.message || 'Password updated.', 'success');
                document.getElementById('setPatientPasswordForm').reset();
            } else {
                notifyAdminPatients(result.message || 'Unable to update password.', 'danger');
            }
        })
        .catch(function () { notifyAdminPatients('Unable to update password.', 'danger'); });
});

document.getElementById('addPatientForm').addEventListener('submit', function (event) {
    event.preventDefault();
    const form = event.target;
    const data = new FormData(form);
    fetch('../assets/api/patients/create_walkin.php', { method: 'POST', body: data })
        .then(function (response) { return response.json(); })
        .then(function (result) {
            if (result.success) {
                const tone = result.email_sent === false ? 'warning' : 'success';
                const message = result.already_exists
                    ? (result.message || 'Patient already exists.')
                    : (result.message || 'Patient account created successfully.');
                notifyAdminPatients(message, tone);
                bootstrap.Modal.getInstance(document.getElementById('addPatientModal'))?.hide();
                if (!result.already_exists) {
                    setTimeout(function () { location.reload(); }, 1200);
                }
            } else {
                notifyAdminPatients(result.message || 'Unable to create patient.', 'danger');
            }
        })
        .catch(function () { notifyAdminPatients('Unable to create patient.', 'danger'); });
});
</script>
