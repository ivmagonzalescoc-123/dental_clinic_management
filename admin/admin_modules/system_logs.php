<?php
include dirname(dirname(__DIR__)) . '/config/database.php';

$search = trim((string)($_GET['search'] ?? ''));
$dateFrom = (string)($_GET['from'] ?? '');
$dateTo = (string)($_GET['to'] ?? '');
$view = (string)($_GET['view'] ?? 'user_logs');

$allowedViews = ['user_logs', 'active_users', 'patient_sessions'];
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

function format_session_status(?string $logoutTime, ?string $lastActivity, int $sessionTimeoutSeconds): string
{
    if (!empty($logoutTime)) return 'Closed';
    if (!empty($lastActivity) && (time() - strtotime($lastActivity)) > $sessionTimeoutSeconds) return 'Closed';
    return 'Active';
}

function session_badge_class(string $status): string
{
    return $status === 'Active' ? 'success' : 'secondary';
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
            ul.last_activity,
            ul.logout_time,
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
            ul.user_log_id,
            ul.login_time,
            ul.last_activity,
            ul.ip_address,
            u.user_id,
            u.first_name,
            u.last_name,
            u.email,
            r.role_name
        FROM user_logs ul
        INNER JOIN users u ON u.user_id = ul.user_id
        LEFT JOIN role r ON r.role_id = u.role_id
        WHERE ul.logout_time IS NULL
          AND TIMESTAMPDIFF(SECOND, COALESCE(ul.last_activity, ul.login_time), NOW()) <= ?
    ";

        $params = [$userSessionTimeoutSeconds];
    $types = 'i';

    if ($search !== '') {
        $sql .= ' AND (u.first_name LIKE ? OR u.last_name LIKE ? OR u.email LIKE ? OR ul.ip_address LIKE ?)';
        $types .= 'ssss';
        $like = '%' . $search . '%';
        array_push($params, $like, $like, $like, $like);
    }

    $sql .= ' ORDER BY COALESCE(ul.last_activity, ul.login_time) DESC LIMIT 500';

    $stmt = $conn->prepare($sql);
    if ($stmt) {
        stmt_bind_params($stmt, $types, $params);
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
}
?>

<div class="card">
    <div class="card-header d-flex justify-content-between align-items-center gap-2" style="flex-wrap: nowrap;">
        <div class="d-flex align-items-center gap-2" style="min-width:0;">
            <h2 class="card-title mb-0">Session Logs</h2>
        </div>

        <div class="d-flex align-items-center gap-2" style="flex: 0 0 auto;">
            <form class="d-flex align-items-center gap-2" method="GET" action="admin.php" style="flex-wrap: nowrap;">
                <input type="hidden" name="page" value="system_logs">
                <input type="hidden" name="view" value="<?php echo htmlspecialchars($view); ?>">

                <input type="text" class="form-control form-control-sm" name="search" placeholder="Search user or IP..." value="<?php echo htmlspecialchars($search); ?>" style="width: 260px;">

                <div class="dropdown">
                    <button class="btn btn-outline-secondary btn-sm dropdown-toggle" type="button" data-bs-toggle="dropdown" aria-expanded="false">
                        <i class="bi bi-funnel"></i>
                        <span class="d-none d-md-inline">Filter</span>
                    </button>

                    <div class="dropdown-menu dropdown-menu-end p-3" style="min-width: 320px;">
                        <div class="mb-2 fw-semibold">Filters</div>

                        <?php if ($view === 'user_logs'): ?>
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
                <a class="nav-link <?php echo $view === 'user_logs' ? 'active' : ''; ?>" href="admin.php?page=system_logs&view=user_logs">User Logs</a>
            </li>
            <li class="nav-item">
                <a class="nav-link <?php echo $view === 'active_users' ? 'active' : ''; ?>" href="admin.php?page=system_logs&view=active_users">Active Users</a>
            </li>
            <li class="nav-item">
                <a class="nav-link <?php echo $view === 'patient_sessions' ? 'active' : ''; ?>" href="admin.php?page=system_logs&view=patient_sessions">Patient Sessions</a>
            </li>
        </ul>
    </div>

    <div class="card-body">
        <?php if (empty($rows)): ?>
            <div class="alert alert-info mb-0">No logs found for the current filters.</div>
        <?php else: ?>
            <div class="table-responsive">
                <table class="table table-hover datatable align-middle">
                    <thead>
                        <?php if ($view === 'user_logs'): ?>
                            <tr>
                                <th style="width: 190px;">Login</th>
                                <th style="width: 190px;">Last Activity</th>
                                <th style="width: 190px;">Logout</th>
                                <th style="width: 90px;">Status</th>
                                <th style="width: 240px;">User</th>
                                <th style="width: 160px;">IP</th>
                            </tr>
                        <?php elseif ($view === 'active_users'): ?>
                            <tr>
                                <th style="width: 240px;">User</th>
                                <th style="width: 140px;">Role</th>
                                <th style="width: 190px;">Login</th>
                                <th style="width: 190px;">Last Activity</th>
                                <th style="width: 90px;">Status</th>
                                <th style="width: 160px;">IP</th>
                            </tr>
                        <?php else: ?>
                            <tr>
                                <th style="width: 240px;">Patient</th>
                                <th style="width: 190px;">Last Login</th>
                                <th style="width: 190px;">Last Activity</th>
                                <th style="width: 190px;">Logout</th>
                                <th style="width: 90px;">Status</th>
                                <th style="width: 160px;">IP</th>
                            </tr>
                        <?php endif; ?>
                    </thead>
                    <tbody>
                        <?php if ($view === 'user_logs'): ?>
                            <?php foreach ($rows as $r): ?>
                                <?php $status = format_session_status($r['logout_time'] ?? null, $r['last_activity'] ?? null, $userSessionTimeoutSeconds); ?>
                                <tr>
                                    <td><?php echo htmlspecialchars((string)($r['login_time'] ?? '')); ?></td>
                                    <td><?php echo htmlspecialchars((string)($r['last_activity'] ?? '')); ?></td>
                                    <td><?php echo htmlspecialchars((string)($r['logout_time'] ?? '')); ?></td>
                                    <td>
                                        <span class="badge bg-<?php echo htmlspecialchars(session_badge_class($status)); ?>">
                                            <?php echo htmlspecialchars($status); ?>
                                        </span>
                                    </td>
                                    <td><?php echo htmlspecialchars(format_user_label($r)); ?></td>
                                    <td><?php echo htmlspecialchars((string)($r['ip_address'] ?? '')); ?></td>
                                </tr>
                            <?php endforeach; ?>
                        <?php elseif ($view === 'active_users'): ?>
                            <?php foreach ($rows as $r): ?>
                                <tr>
                                    <td><?php echo htmlspecialchars(format_user_label($r)); ?></td>
                                    <td><?php echo htmlspecialchars((string)($r['role_name'] ?? '')); ?></td>
                                    <td><?php echo htmlspecialchars((string)($r['login_time'] ?? '')); ?></td>
                                    <td><?php echo htmlspecialchars((string)($r['last_activity'] ?? '')); ?></td>
                                    <td><span class="badge bg-success">Active</span></td>
                                    <td><?php echo htmlspecialchars((string)($r['ip_address'] ?? '')); ?></td>
                                </tr>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <?php foreach ($rows as $r): ?>
                                <?php $status = format_session_status($r['logout_time'] ?? null, $r['last_activity'] ?? null, $patientSessionTimeoutSeconds); ?>
                                <tr>
                                    <td><?php echo htmlspecialchars(trim((string)($r['first_name'] ?? '') . ' ' . (string)($r['last_name'] ?? '')) ?: (string)($r['email'] ?? 'Unknown')); ?></td>
                                    <td><?php echo htmlspecialchars((string)($r['login_time'] ?? '')); ?></td>
                                    <td><?php echo htmlspecialchars((string)($r['last_activity'] ?? '')); ?></td>
                                    <td><?php echo htmlspecialchars((string)($r['logout_time'] ?? '')); ?></td>
                                    <td>
                                        <span class="badge bg-<?php echo htmlspecialchars(session_badge_class($status)); ?>">
                                            <?php echo htmlspecialchars($status); ?>
                                        </span>
                                    </td>
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
