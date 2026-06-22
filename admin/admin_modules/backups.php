<?php
include_once dirname(dirname(__DIR__)) . '/config/database.php';
include_once dirname(dirname(__DIR__)) . '/config/session_check.php';
include_once dirname(dirname(__DIR__)) . '/config/system_logs.php';
$csrf_token = security_generate_csrf_token();

$action = $_GET['action'] ?? ($_POST['action'] ?? '');

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'run_backup') {
    $csrf = $_POST['csrf_token'] ?? '';
    if (!security_validate_csrf_token($csrf)) {
        $_SESSION['message'] = 'Invalid security token.';
        $_SESSION['message_type'] = 'danger';
        header('Location: admin.php?page=backups');
        exit;
    }

    $adminId = (int)($_SESSION['user_id'] ?? 0);
    $backupDir = dirname(dirname(__DIR__)) . '/backups';
    if (!is_dir($backupDir)) @mkdir($backupDir, 0755, true);

    $date = date('Y-m-d_H-i-s');
    $fileName = "capstone_dental_backup_{$date}.sql";
    $filePath = $backupDir . '/' . $fileName;

    $handle = @fopen($filePath, 'w');
    if (!$handle) {
        $_SESSION['message'] = 'Failed to create backup file.';
        $_SESSION['message_type'] = 'danger';
        header('Location: admin.php?page=backups');
        exit;
    }

    fwrite($handle, "-- Capstone Dental Clinic Database Backup\n");
    fwrite($handle, "-- Generated: " . date('Y-m-d H:i:s') . "\n");
    fwrite($handle, "-- Database: " . DB_NAME . "\n\n");
    fwrite($handle, "SET SQL_MODE = \"NO_AUTO_VALUE_ON_ZERO\";\n");
    fwrite($handle, "SET time_zone = \"+00:00\";\n");
    fwrite($handle, "SET FOREIGN_KEY_CHECKS = 0;\n\n");

    $tablesRes = $conn->query("SHOW TABLES");
    $tableNames = [];
    while ($row = $tablesRes->fetch_row()) $tableNames[] = $row[0];

    $rowCount = 0;
    foreach ($tableNames as $table) {
        $tableEsc = $conn->real_escape_string($table);
        $createRes = $conn->query("SHOW CREATE TABLE `{$tableEsc}`");
        if ($createRow = $createRes->fetch_row()) {
            fwrite($handle, "DROP TABLE IF EXISTS `{$tableEsc}`;\n");
            fwrite($handle, $createRow[1] . ";\n\n");
        }
        $dataRes = $conn->query("SELECT * FROM `{$tableEsc}`");
        if (!$dataRes || $dataRes->num_rows === 0) continue;

        $colsRes = $conn->query("SHOW COLUMNS FROM `{$tableEsc}`");
        $columns = [];
        while ($colRow = $colsRes->fetch_row()) $columns[] = $colRow[0];

        while ($row = $dataRes->fetch_assoc()) {
            $values = [];
            foreach ($columns as $col) {
                $val = $row[$col];
                $values[] = ($val === null) ? 'NULL' : "'" . $conn->real_escape_string($val) . "'";
            }
            fwrite($handle, "INSERT INTO `{$tableEsc}` (`" . implode('`, `', $columns) . "`) VALUES (" . implode(', ', $values) . ");\n");
            $rowCount++;
        }
        fwrite($handle, "\n");
    }

    fwrite($handle, "SET FOREIGN_KEY_CHECKS = 1;\n");
    fwrite($handle, "-- End of backup\n");
    fclose($handle);

    $fileSizeKB = round(filesize($filePath) / 1024, 2);
    $conn->query("INSERT INTO backup (user_id, backup_date, file_name, file_path, file_size) VALUES ($adminId, NOW(), '" .
        $conn->real_escape_string($fileName) . "', '" .
        $conn->real_escape_string('backups/' . $fileName) . "', $fileSizeKB)");

    if (function_exists('log_system_event')) {
        log_system_event($conn, 'admin.backup.create', 'backup', null,
            "Manual backup: $fileName ($fileSizeKB KB, $rowCount rows)", $adminId);
    }

    $_SESSION['message'] = "Backup created: $fileName ($fileSizeKB KB, $rowCount rows)";
    $_SESSION['message_type'] = 'success';
    header('Location: admin.php?page=backups');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'delete_backup') {
    $csrf = $_POST['csrf_token'] ?? '';
    if (!security_validate_csrf_token($csrf)) {
        $_SESSION['message'] = 'Invalid security token.';
        $_SESSION['message_type'] = 'danger';
        header('Location: admin.php?page=backups');
        exit;
    }
    $backupId = (int)($_POST['backup_id'] ?? 0);
    if ($backupId > 0) {
        $stmt = $conn->prepare('SELECT file_path FROM backup WHERE backup_id = ?');
        $stmt->bind_param('i', $backupId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if ($row) {
            $filePath = dirname(dirname(__DIR__)) . '/' . $row['file_path'];
            if (is_file($filePath)) @unlink($filePath);
            $conn->query("DELETE FROM backup WHERE backup_id = $backupId");
        }
    }
    $_SESSION['message'] = 'Backup deleted.';
    $_SESSION['message_type'] = 'success';
    header('Location: admin.php?page=backups');
    exit;
}

$backups = [];
$res = $conn->query('SELECT b.*, u.first_name, u.last_name FROM backup b LEFT JOIN users u ON b.user_id = u.user_id ORDER BY b.backup_date DESC');
if ($res) {
    while ($row = $res->fetch_assoc()) $backups[] = $row;
}

$totalSize = 0;
foreach ($backups as $b) $totalSize += (float)($b['file_size'] ?? 0);
?>

<?php if (isset($_SESSION['message'])): ?>
    <div class="alert alert-<?php echo $_SESSION['message_type']; ?> alert-dismissible fade show" role="alert">
        <?php echo htmlspecialchars($_SESSION['message']); ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
    <?php unset($_SESSION['message'], $_SESSION['message_type']); ?>
<?php endif; ?>

<div class="d-flex justify-content-between align-items-center mb-3">
    <h5 class="mb-0"><i class="bi bi-database"></i> Database Backups</h5>
    <form method="POST" action="admin.php?page=backups&action=run_backup" class="d-inline">
        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">
        <button type="submit" class="btn btn-primary btn-sm" onclick="this.disabled=true;this.innerHTML='<i class=\'fas fa-spinner fa-spin\'></i> Backing up...'">
            <i class="bi bi-download"></i> Create Backup Now
        </button>
    </form>
</div>

<div class="row mb-3">
    <div class="col-md-3">
        <div class="card border-0 shadow-sm">
            <div class="card-body text-center">
                <h6 class="text-muted">Total Backups</h6>
                <h3><?php echo count($backups); ?></h3>
            </div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card border-0 shadow-sm">
            <div class="card-body text-center">
                <h6 class="text-muted">Total Size</h6>
                <h3><?php echo $totalSize >= 1024 ? round($totalSize / 1024, 2) . ' MB' : round($totalSize, 2) . ' KB'; ?></h3>
            </div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card border-0 shadow-sm">
            <div class="card-body text-center">
                <h6 class="text-muted">Last Backup</h6>
                <h6 class="mb-0"><?php echo !empty($backups) ? date('M d, Y g:i A', strtotime($backups[0]['backup_date'])) : 'Never'; ?></h6>
            </div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card border-0 shadow-sm">
            <div class="card-body text-center">
                <h6 class="text-muted">Cron URL</h6>
                <small class="text-muted">Set in cron-job.org</small>
            </div>
        </div>
    </div>
</div>

<div class="card border-0">
    <div class="card-header">
        <h6 class="mb-0">Backup History</h6>
    </div>
    <div class="card-body">
        <?php if (empty($backups)): ?>
            <div class="text-center text-muted py-4">No backups yet. Click "Create Backup Now" to generate your first backup.</div>
        <?php else: ?>
            <div class="table-responsive">
                <table class="table table-hover align-middle">
                    <thead>
                        <tr>
                            <th>#</th>
                            <th>File</th>
                            <th>Date</th>
                            <th>Size</th>
                            <th>Created By</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($backups as $i => $b): ?>
                            <tr>
                                <td><?php echo $i + 1; ?></td>
                                <td>
                                    <code class="small"><?php echo htmlspecialchars($b['file_name'] ?? ''); ?></code>
                                </td>
                                <td><?php echo date('M d, Y g:i:s A', strtotime($b['backup_date'])); ?></td>
                                <td>
                                    <?php
                                    $sz = (float)($b['file_size'] ?? 0);
                                    echo $sz >= 1024 ? round($sz / 1024, 2) . ' MB' : round($sz, 2) . ' KB';
                                    ?>
                                </td>
                                <td><?php echo htmlspecialchars(trim(($b['first_name'] ?? '') . ' ' . ($b['last_name'] ?? '')) ?: 'Cron'); ?></td>
                                <td>
                                    <div class="d-flex gap-1">
                                        <form method="POST" action="admin.php?page=backups&action=delete_backup" class="d-inline"
                                              data-confirm="Delete this backup permanently?" data-confirm-variant="danger">
                                            <input type="hidden" name="backup_id" value="<?php echo (int)$b['backup_id']; ?>">
                                            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">
                                            <button type="submit" class="btn btn-sm btn-outline-danger" title="Delete">
                                                <i class="bi bi-trash"></i>
                                            </button>
                                        </form>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>
</div>

<div class="card border-0 mt-3">
    <div class="card-header">
        <h6 class="mb-0">Cron Setup Instructions</h6>
    </div>
    <div class="card-body">
        <p class="mb-2">To enable automatic daily backups, add this URL to <a href="https://cron-job.org" target="_blank">cron-job.org</a>:</p>
        <div class="bg-light p-2 rounded mb-3">
            <code class="small"><?php echo htmlspecialchars((env('APP_URL', 'https://azucenadentalclinic.site.je'))); ?>/scripts/cron_backup.php?key=<?php echo htmlspecialchars(env('CRON_SECRET_KEY', 'YOUR_KEY')); ?></code>
        </div>
        <ul class="small text-muted mb-0">
            <li>Schedule: Daily at 2:00 AM (recommended)</li>
            <li>Backup files are stored in <code>backups/</code> directory (retained for 10 years per healthcare data retention policy)</li>
            <li>Protected by <code>CRON_SECRET_KEY</code> from your <code>.env</code></li>
        </ul>
    </div>
</div>
