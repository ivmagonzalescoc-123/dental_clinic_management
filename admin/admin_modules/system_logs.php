<?php
include dirname(dirname(__DIR__)) . '/config/database.php';

$search = trim((string)($_GET['search'] ?? ''));
$dateFrom = (string)($_GET['from'] ?? '');
$dateTo = (string)($_GET['to'] ?? '');
$view = (string)($_GET['view'] ?? 'audit_logs');

$allowedViews = ['user_logs', 'active_users', 'patient_sessions', 'audit_logs'];
if (!in_array($view, $allowedViews, true)) {
    $view = 'user_logs';
}

$userSessionTimeoutSeconds = (60 * 60) + 60;
$patientSessionTimeoutSeconds = (15 * 60) + 60;

if ($dateFrom !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateFrom)) {
    $dateFrom = '';
}
if ($dateTo !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateTo)) {
    $dateTo = '';
}

function stmt_bind_params(mysqli_stmt $stmt, string $types, array &$params): void
{
    if ($types === '') return;
    $bind = [];
    $bind[] = $types;
    foreach ($params as $k => $v) {
        $bind[] = &$params[$k];
    }
    call_user_func_array([$stmt, 'bind_param'], $bind);
}

function format_user_label(array $r): string
{
    $name = trim(($r['first_name'] ?? '') . ' ' . ($r['last_name'] ?? ''));
    $email = (string)($r['email'] ?? '');

    if ($name !== '' && $email !== '') return $name . ' (' . $email . ')';
    if ($name !== '') return $name;
    if ($email !== '') return $email;
    return 'Unknown';
}

function format_activity_status(?string $logoutTime, ?string $lastActivity, int $sessionTimeoutSeconds): string
{
    if (empty($lastActivity)) return 'Inactive';
    if (!empty($logoutTime)) return 'Inactive';
    if ((time() - strtotime($lastActivity)) > $sessionTimeoutSeconds) return 'Inactive';
    return 'Active';
}

function session_badge_class(string $status): string
{
    return $status === 'Active' ? 'success' : 'secondary';
}

function format_audit_actor(array $r): string
{
    $name = trim((string)($r['first_name'] ?? '') . ' ' . (string)($r['last_name'] ?? ''));
    $email = trim((string)($r['email'] ?? ''));

    if ($name !== '' && $email !== '') return $name . ' (' . $email . ')';
    if ($name !== '') return $name;
    if ($email !== '') return $email;
    return 'System/Unknown';
}

if (function_exists('table_exists') && table_exists($conn, 'user_logs')) {
    $cleanup = $conn->prepare('UPDATE user_logs SET logout_time = NOW() WHERE logout_time IS NULL AND COALESCE(last_activity, login_time) IS NOT NULL AND TIMESTAMPDIFF(SECOND, COALESCE(last_activity, login_time), NOW()) > ?');
    if ($cleanup) {
        $cleanup->bind_param('i', $userSessionTimeoutSeconds);
        $cleanup->execute();
        $cleanup->close();
    }
}

if (function_exists('table_exists') && table_exists($conn, 'patient_logs')) {
    $cleanup = $conn->prepare('UPDATE patient_logs SET logout_time = NOW() WHERE logout_time IS NULL AND COALESCE(last_activity, login_time) IS NOT NULL AND TIMESTAMPDIFF(SECOND, COALESCE(last_activity, login_time), NOW()) > ?');
    if ($cleanup) {
        $cleanup->bind_param('i', $patientSessionTimeoutSeconds);
        $cleanup->execute();
        $cleanup->close();
    }
}

$rows = [];

if ($view === 'user_logs') {
    $sql = "
        SELECT
            ul.user_log_id,
            ul.login_time,
            ul.ip_address,
            u.user_id,
            u.first_name,
            u.last_name,
            u.email
        FROM user_logs ul
        LEFT JOIN users u ON u.user_id = ul.user_id
        WHERE 1=1
    ";

    $params = [];
    $types = '';

    if ($dateFrom !== '') {
        $sql .= ' AND DATE(ul.login_time) >= ?';
        $types .= 's';
        $params[] = $dateFrom;
    }
    if ($dateTo !== '') {
        $sql .= ' AND DATE(ul.login_time) <= ?';
        $types .= 's';
        $params[] = $dateTo;
    }

    if ($search !== '') {
        $sql .= " AND (
            u.first_name LIKE ? OR u.last_name LIKE ? OR u.email LIKE ? OR
            ul.ip_address LIKE ?
        )";
        $types .= 'ssss';
        $like = '%' . $search . '%';
        array_push($params, $like, $like, $like, $like);
    }

    $sql .= ' ORDER BY ul.login_time DESC LIMIT 500';

    $stmt = $conn->prepare($sql);
    if ($stmt) {
        if ($types !== '') {
            stmt_bind_params($stmt, $types, $params);
        }
        $stmt->execute();
        $res = $stmt->get_result();
        if ($res) {
            while ($r = $res->fetch_assoc()) {
                $rows[] = $r;
            }
        }
        $stmt->close();
    }
} elseif ($view === 'active_users') {
    $sql = "
        SELECT
            u.user_id,
            u.first_name,
            u.last_name,
            u.email,
            r.role_name,
            ul.login_time,
            ul.last_activity,
            ul.logout_time,
            ul.ip_address
        FROM users u
        LEFT JOIN role r ON r.role_id = u.role_id
        LEFT JOIN user_logs ul ON ul.user_log_id = (
            SELECT x.user_log_id
            FROM user_logs x
            WHERE x.user_id = u.user_id
            ORDER BY x.login_time DESC
            LIMIT 1
        )
        WHERE 1=1
    ";

    $params = [];
    $types = '';

    if ($search !== '') {
        $sql .= ' AND (u.first_name LIKE ? OR u.last_name LIKE ? OR u.email LIKE ? OR ul.ip_address LIKE ?)';
        $types .= 'ssss';
        $like = '%' . $search . '%';
        array_push($params, $like, $like, $like, $like);
    }

    $sql .= ' ORDER BY u.last_name ASC, u.first_name ASC LIMIT 1000';

    $stmt = $conn->prepare($sql);
    if ($stmt) {
        if ($types !== '') {
            stmt_bind_params($stmt, $types, $params);
        }
        $stmt->execute();
        $res = $stmt->get_result();
        if ($res) {
            while ($r = $res->fetch_assoc()) {
                $rows[] = $r;
            }
        }
        $stmt->close();
    }
} elseif ($view === 'patient_sessions') {
    $sql = "
        SELECT
            p.patient_id,
            p.first_name,
            p.last_name,
            p.email,
            pl.patient_log_id,
            pl.login_time,
            pl.last_activity,
            pl.logout_time,
            pl.ip_address
        FROM patients p
        LEFT JOIN patient_logs pl ON pl.patient_log_id = (
            SELECT x.patient_log_id
            FROM patient_logs x
            WHERE x.patient_id = p.patient_id
            ORDER BY x.login_time DESC
            LIMIT 1
        )
        WHERE 1=1
    ";

    $params = [];
    $types = '';

    if ($search !== '') {
        $sql .= ' AND (p.first_name LIKE ? OR p.last_name LIKE ? OR p.email LIKE ?)';
        $types .= 'sss';
        $like = '%' . $search . '%';
        array_push($params, $like, $like, $like);
    }

    $sql .= ' ORDER BY p.last_name ASC, p.first_name ASC LIMIT 1000';

    $stmt = $conn->prepare($sql);
    if ($stmt) {
        if ($types !== '') {
            stmt_bind_params($stmt, $types, $params);
        }
        $stmt->execute();
        $res = $stmt->get_result();
        if ($res) {
            while ($r = $res->fetch_assoc()) {
                $rows[] = $r;
            }
        }
        $stmt->close();
    }
} else {
    if (function_exists('table_exists') && table_exists($conn, 'system_logs')) {
        $sql = "
            SELECT
                sl.system_log_id,
                sl.created_at,
                sl.action,
                sl.entity_type,
                sl.entity_id,
                sl.details,
                sl.ip_address,
                sl.user_agent,
                u.user_id,
                u.first_name,
                u.last_name,
                u.email
            FROM system_logs sl
            LEFT JOIN users u ON u.user_id = sl.user_id
            WHERE 1=1
                            AND sl.action NOT IN ('auth.login', 'auth.logout')
                            AND sl.action NOT LIKE '%.view%'
                            AND sl.action NOT LIKE '%.open%'
                            AND sl.action NOT LIKE '%.show%'
                            AND sl.action NOT LIKE '%.list%'
                            AND sl.action NOT LIKE '%.read%'
        ";

        $params = [];
        $types = '';

        if ($dateFrom !== '') {
            $sql .= ' AND DATE(sl.created_at) >= ?';
            $types .= 's';
            $params[] = $dateFrom;
        }
        if ($dateTo !== '') {
            $sql .= ' AND DATE(sl.created_at) <= ?';
            $types .= 's';
            $params[] = $dateTo;
        }

        if ($search !== '') {
            $sql .= " AND (
                sl.action LIKE ? OR
                sl.entity_type LIKE ? OR
                sl.details LIKE ? OR
                sl.ip_address LIKE ? OR
                u.first_name LIKE ? OR
                u.last_name LIKE ? OR
                u.email LIKE ?
            )";
            $types .= 'sssssss';
            $like = '%' . $search . '%';
            array_push($params, $like, $like, $like, $like, $like, $like, $like);
        }

        $sql .= ' ORDER BY sl.created_at DESC LIMIT 1000';

        $stmt = $conn->prepare($sql);
        if ($stmt) {
            if ($types !== '') {
                stmt_bind_params($stmt, $types, $params);
            }
            $stmt->execute();
            $res = $stmt->get_result();
            if ($res) {
                while ($r = $res->fetch_assoc()) {
                    $rows[] = $r;
                }
            }
            $stmt->close();
        }
    }
}
?>

<div class="card">
    <div class="card-header d-flex justify-content-between align-items-center gap-2" style="flex-wrap: nowrap;">
        <div class="d-flex align-items-center gap-2" style="min-width:0;">
            <h2 class="card-title mb-0">System Logs</h2>
        </div>

        <div class="d-flex align-items-center gap-2" style="flex: 0 0 auto;">
            <form class="module-search-toolbar" method="GET" action="admin.php" style="margin:0;">
                <input type="hidden" name="page" value="system_logs">
                <input type="hidden" name="view" value="<?php echo htmlspecialchars($view); ?>">

                <div class="unified-table-search-wrap module-search-inline">
                    <i class="bi bi-search unified-table-search-icon" aria-hidden="true"></i>
                    <input type="search" class="form-control unified-table-search-input" name="search" placeholder="Search name, email, or IP..." value="<?php echo htmlspecialchars($search); ?>" aria-label="Search system logs">
                </div>

                <div class="dropdown">
                    <button class="btn btn-outline-secondary btn-sm dropdown-toggle" type="button" data-bs-toggle="dropdown" aria-expanded="false">
                        <i class="bi bi-funnel"></i>
                        <span class="d-none d-md-inline">Filter</span>
                    </button>

                    <div class="dropdown-menu dropdown-menu-end p-3" style="min-width: 320px;">
                        <div class="mb-2 fw-semibold">Filters</div>

                        <?php if ($view === 'user_logs' || $view === 'audit_logs'): ?>
                            <div class="mb-2">
                                <label class="form-label small mb-1">From</label>
                                <input type="date" class="form-control form-control-sm" name="from" value="<?php echo htmlspecialchars($dateFrom); ?>">
                            </div>

                            <div class="mb-3">
                                <label class="form-label small mb-1">To</label>
                                <input type="date" class="form-control form-control-sm" name="to" value="<?php echo htmlspecialchars($dateTo); ?>">
                            </div>
                        <?php endif; ?>

                        <div class="d-flex justify-content-end gap-2">
                            <a class="btn btn-outline-secondary btn-sm" href="admin.php?page=system_logs&view=<?php echo urlencode($view); ?>">Clear</a>
                            <button class="btn btn-primary btn-sm" type="submit">Apply</button>
                        </div>
                    </div>
                </div>

                <button class="btn btn-primary btn-sm" type="submit"><i class="bi bi-search"></i> Search</button>
            </form>
        </div>
    </div>

    <div class="card-body border-bottom pb-2">
        <ul class="nav nav-tabs card-header-tabs">
            <li class="nav-item">
                <a class="nav-link <?php echo $view === 'user_logs' ? 'active' : ''; ?>" href="admin.php?page=system_logs&view=user_logs">Login Logs</a>
            </li>
            <li class="nav-item">
                <a class="nav-link <?php echo $view === 'active_users' ? 'active' : ''; ?>" href="admin.php?page=system_logs&view=active_users">Active Users</a>
            </li>
            <li class="nav-item">
                <a class="nav-link <?php echo $view === 'patient_sessions' ? 'active' : ''; ?>" href="admin.php?page=system_logs&view=patient_sessions">Patient Sessions</a>
            </li>
            <li class="nav-item">
                <a class="nav-link <?php echo $view === 'audit_logs' ? 'active' : ''; ?>" href="admin.php?page=system_logs&view=audit_logs">Audit Logs</a>
            </li>
        </ul>
    </div>

    <div class="card-body">
        <?php if (empty($rows)): ?>
            <div class="alert alert-info mb-0">No logs found for the current filters.</div>
        <?php else: ?>
            <div class="table-responsive">
                <table class="table table-hover datatable align-middle" data-skip-table-search="1">
                    <thead>
                        <?php if ($view === 'user_logs'): ?>
                            <tr>
                                <th style="width: 240px;">User</th>
                                <th style="width: 190px;">Login</th>
                                <th style="width: 160px;">IP</th>
                            </tr>
                        <?php elseif ($view === 'active_users'): ?>
                            <tr>
                                <th style="width: 240px;">User</th>
                                <th style="width: 140px;">Role</th>
                                <th style="width: 190px;">Login</th>
                                <th style="width: 190px;">Last Activity</th>
                                <th style="width: 160px;">IP</th>
                                <th style="width: 110px;">Status</th>
                            </tr>
                        <?php elseif ($view === 'patient_sessions'): ?>
                            <tr>
                                <th style="width: 240px;">Patient</th>
                                <th style="width: 190px;">Last Login</th>
                                <th style="width: 190px;">Last Activity</th>
                                <th style="width: 160px;">IP</th>
                                <th style="width: 110px;">Status</th>
                            </tr>
                        <?php else: ?>
                            <tr>
                                <th style="width: 180px;">Timestamp</th>
                                <th style="width: 240px;">Actor</th>
                                <th style="width: 180px;">Action</th>
                                <th style="width: 180px;">Entity</th>
                                <th>Details</th>
                                <th style="width: 160px;">IP</th>
                            </tr>
                        <?php endif; ?>
                    </thead>
                    <tbody>
                        <?php if ($view === 'user_logs'): ?>
                            <?php foreach ($rows as $r): ?>
                                <tr>
                                    <td><?php echo htmlspecialchars(format_user_label($r)); ?></td>
                                    <td><?php echo htmlspecialchars((string)($r['login_time'] ?? '')); ?></td>
                                    <td><?php echo htmlspecialchars((string)($r['ip_address'] ?? '')); ?></td>
                                </tr>
                            <?php endforeach; ?>
                        <?php elseif ($view === 'active_users'): ?>
                            <?php foreach ($rows as $r): ?>
                                <?php $status = format_activity_status($r['logout_time'] ?? null, $r['last_activity'] ?? null, $userSessionTimeoutSeconds); ?>
                                <tr>
                                    <td><?php echo htmlspecialchars(format_user_label($r)); ?></td>
                                    <td><?php echo htmlspecialchars((string)($r['role_name'] ?? '')); ?></td>
                                    <td><?php echo htmlspecialchars((string)($r['login_time'] ?? '')); ?></td>
                                    <td><?php echo htmlspecialchars((string)($r['last_activity'] ?? '')); ?></td>
                                    <td><?php echo htmlspecialchars((string)($r['ip_address'] ?? '')); ?></td>
                                    <td>
                                        <span class="badge bg-<?php echo htmlspecialchars(session_badge_class($status)); ?>">
                                            <?php echo htmlspecialchars($status); ?>
                                        </span>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php elseif ($view === 'patient_sessions'): ?>
                            <?php foreach ($rows as $r): ?>
                                <?php $status = format_activity_status($r['logout_time'] ?? null, $r['last_activity'] ?? null, $patientSessionTimeoutSeconds); ?>
                                <tr>
                                    <td><?php echo htmlspecialchars(trim((string)($r['first_name'] ?? '') . ' ' . (string)($r['last_name'] ?? '')) ?: (string)($r['email'] ?? 'Unknown')); ?></td>
                                    <td><?php echo htmlspecialchars((string)($r['login_time'] ?? '')); ?></td>
                                    <td><?php echo htmlspecialchars((string)($r['last_activity'] ?? '')); ?></td>
                                    <td><?php echo htmlspecialchars((string)($r['ip_address'] ?? '')); ?></td>
                                    <td>
                                        <span class="badge bg-<?php echo htmlspecialchars(session_badge_class($status)); ?>">
                                            <?php echo htmlspecialchars($status); ?>
                                        </span>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <?php foreach ($rows as $r): ?>
                                <tr>
                                    <td><?php echo htmlspecialchars((string)($r['created_at'] ?? '')); ?></td>
                                    <td><?php echo htmlspecialchars(format_audit_actor($r)); ?></td>
                                    <td><code><?php echo htmlspecialchars((string)($r['action'] ?? '')); ?></code></td>
                                    <td>
                                        <?php
                                            $entityType = (string)($r['entity_type'] ?? '');
                                            $entityId = (string)($r['entity_id'] ?? '');
                                            echo htmlspecialchars(trim($entityType . ($entityId !== '' ? ' #' . $entityId : '')));
                                        ?>
                                    </td>
                                    <td><?php echo htmlspecialchars((string)($r['details'] ?? '')); ?></td>
                                    <td><?php echo htmlspecialchars((string)($r['ip_address'] ?? '')); ?></td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>
</div>

<?php if ($view === 'active_users' || $view === 'patient_sessions'): ?>
<script>
    setTimeout(function () {
        window.location.reload();
    }, 30000);
</script>
<?php endif; ?>
