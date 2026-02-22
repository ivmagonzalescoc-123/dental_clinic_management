<?php
include dirname(dirname(__DIR__)) . '/config/database.php';

$search = trim((string)($_GET['search'] ?? ''));
$dateFrom = (string)($_GET['from'] ?? '');
$dateTo = (string)($_GET['to'] ?? '');

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
    $username = (string)($r['username'] ?? '');

    if ($name !== '' && $username !== '') return $name . ' (' . $username . ')';
    if ($name !== '') return $name;
    if ($username !== '') return $username;
    return 'Unknown';
}

function format_session_status(?string $logoutTime): string
{
    return empty($logoutTime) ? 'Active' : 'Closed';
}

function session_badge_class(?string $logoutTime): string
{
    return empty($logoutTime) ? 'success' : 'secondary';
}

$rows = [];

$sql = "
    SELECT
        ul.user_log_id,
        ul.login_time,
        ul.logout_time,
        ul.ip_address,
        u.user_id,
        u.first_name,
        u.last_name,
        u.username
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
        u.first_name LIKE ? OR u.last_name LIKE ? OR u.username LIKE ? OR
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
?>

<div class="card">
    <div class="card-header d-flex justify-content-between align-items-center gap-2" style="flex-wrap: nowrap;">
        <div class="d-flex align-items-center gap-2" style="min-width:0;">
            <h2 class="card-title mb-0">User Logs</h2>
        </div>

        <div class="d-flex align-items-center gap-2" style="flex: 0 0 auto;">
            <form class="d-flex align-items-center gap-2" method="GET" action="admin.php" style="flex-wrap: nowrap;">
                <input type="hidden" name="page" value="system_logs">

                <input type="text" class="form-control form-control-sm" name="search" placeholder="Search user or IP..." value="<?php echo htmlspecialchars($search); ?>" style="width: 260px;">

                <div class="dropdown">
                    <button class="btn btn-outline-secondary btn-sm dropdown-toggle" type="button" data-bs-toggle="dropdown" aria-expanded="false">
                        <i class="bi bi-funnel"></i>
                        <span class="d-none d-md-inline">Filter</span>
                    </button>

                    <div class="dropdown-menu dropdown-menu-end p-3" style="min-width: 320px;">
                        <div class="mb-2 fw-semibold">Filters</div>

                        <div class="mb-2">
                            <label class="form-label small mb-1">From</label>
                            <input type="date" class="form-control form-control-sm" name="from" value="<?php echo htmlspecialchars($dateFrom); ?>">
                        </div>

                        <div class="mb-3">
                            <label class="form-label small mb-1">To</label>
                            <input type="date" class="form-control form-control-sm" name="to" value="<?php echo htmlspecialchars($dateTo); ?>">
                        </div>

                        <div class="d-flex justify-content-end gap-2">
                            <a class="btn btn-outline-secondary btn-sm" href="admin.php?page=system_logs">Clear</a>
                            <button class="btn btn-primary btn-sm" type="submit">Apply</button>
                        </div>
                    </div>
                </div>

                <button class="btn btn-primary btn-sm" type="submit"><i class="bi bi-search"></i> Search</button>
            </form>
        </div>
    </div>

    <div class="card-body">
        <?php if (empty($rows)): ?>
            <div class="alert alert-info mb-0">No logs found for the current filters.</div>
        <?php else: ?>
            <div class="table-responsive">
                <table class="table table-hover datatable align-middle">
                    <thead>
                        <tr>
                            <th style="width: 190px;">Login</th>
                            <th style="width: 190px;">Logout</th>
                            <th style="width: 90px;">Status</th>
                            <th style="width: 240px;">User</th>
                            <th style="width: 160px;">IP</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($rows as $r): ?>
                            <tr>
                                <td><?php echo htmlspecialchars((string)($r['login_time'] ?? '')); ?></td>
                                <td><?php echo htmlspecialchars((string)($r['logout_time'] ?? '')); ?></td>
                                <td>
                                    <span class="badge bg-<?php echo htmlspecialchars(session_badge_class($r['logout_time'] ?? null)); ?>">
                                        <?php echo htmlspecialchars(format_session_status($r['logout_time'] ?? null)); ?>
                                    </span>
                                </td>
                                <td><?php echo htmlspecialchars(format_user_label($r)); ?></td>
                                <td><?php echo htmlspecialchars((string)($r['ip_address'] ?? '')); ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>
</div>
