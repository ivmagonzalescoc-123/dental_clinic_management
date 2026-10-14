<?php
include_once dirname(dirname(__DIR__)) . '/config/database.php';
include_once dirname(dirname(__DIR__)) . '/config/session_check.php';
include_once dirname(dirname(__DIR__)) . '/config/system_logs.php';
include_once dirname(dirname(__DIR__)) . '/config/env.php';
$csrf_token = security_generate_csrf_token();

$action = $_GET['action'] ?? '';

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
    <div class="d-flex gap-2">
        <button type="button" id="backupNowBtn" class="btn btn-primary btn-sm" onclick="runBackupNow()">
            <i class="bi bi-download"></i> Create Backup Now
        </button>
    </div>
</div>

<div id="backupStatus" class="alert d-none" role="alert"></div>

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
                                <td><code class="small"><?php echo htmlspecialchars($b['file_name'] ?? ''); ?></code></td>
                                <td><?php echo date('M d, Y g:i:s A', strtotime($b['backup_date'])); ?></td>
                                <td><?php $sz = (float)($b['file_size'] ?? 0); echo $sz >= 1024 ? round($sz / 1024, 2) . ' MB' : round($sz, 2) . ' KB'; ?></td>
                                <td><?php echo htmlspecialchars(trim(($b['first_name'] ?? '') . ' ' . ($b['last_name'] ?? '')) ?: 'Cron'); ?></td>
                                <td>
                                    <a href="../assets/api/admin/download_backup.php?backup_id=<?php echo (int)$b['backup_id']; ?>" download class="btn btn-sm btn-outline-primary" title="Download">
                                        <i class="bi bi-download"></i>
                                    </a>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>
</div>


<script>
var backupCsrfToken = <?php echo json_encode($csrf_token); ?>;
async function runBackupNow() {
    var btn = document.getElementById('backupNowBtn');
    var status = document.getElementById('backupStatus');
    btn.disabled = true;
    btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Backing up...';
    status.className = 'alert alert-info';
    status.textContent = 'Backup in progress...';
    status.classList.remove('d-none');
    try {
        var res = await fetch('../assets/api/admin/create_backup.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ csrf_token: backupCsrfToken })
        });
        var json = await res.json();
        if (json.success) {
            status.className = 'alert alert-success';
            status.textContent = 'Backup completed: ' + json.file + ' (' + json.size_kb + ' KB, ' + json.rows + ' rows)';
            // Reload so the new backup shows in the history (the button stays off until then).
            setTimeout(function() { location.reload(); }, 1500);
            return;
        } else {
            status.className = 'alert alert-danger';
            status.textContent = 'Backup failed: ' + (json.message || 'Unknown error');
        }
    } catch (e) {
        status.className = 'alert alert-danger';
        status.textContent = 'Backup failed: ' + e.message;
    }
    btn.disabled = false;
    btn.innerHTML = '<i class="bi bi-download"></i> Create Backup Now';
}
</script>
