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

$backupApiUrl = rtrim((string)env('APP_URL', ''), '/') . '/scripts/cron_backup.php';
$backupCronKey = env('CRON_SECRET_KEY', '');
$backupBaseUrl = rtrim((string)env('APP_URL', ''), '/') . '/';
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
        <button type="button" class="btn btn-warning btn-sm" data-bs-toggle="modal" data-bs-target="#restoreModal">
            <i class="bi bi-upload"></i> Restore from Backup
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
                                    <a href="<?php echo htmlspecialchars($backupBaseUrl . $b['file_path']); ?>" class="btn btn-sm btn-outline-primary" download target="_blank" title="Download">
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
var backupApiUrl = <?php echo json_encode($backupApiUrl); ?>;
var backupCronKey = <?php echo json_encode($backupCronKey); ?>;
async function runBackupNow() {
    var btn = document.getElementById('backupNowBtn');
    var status = document.getElementById('backupStatus');
    btn.disabled = true;
    btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Backing up...';
    status.className = 'alert alert-info';
    status.textContent = 'Backup in progress...';
    status.classList.remove('d-none');
    try {
        var res = await fetch(backupApiUrl + '?key=' + encodeURIComponent(backupCronKey), { method: 'GET' });
        var json = await res.json();
        if (json.success) {
            status.className = 'alert alert-success';
            status.textContent = 'Backup completed: ' + json.file + ' (' + json.size_kb + ' KB, ' + json.rows + ' rows)';
            setTimeout(function() { location.reload(); }, 1500);
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

<div class="modal fade" id="restoreModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><i class="bi bi-upload"></i> Restore Database</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div class="alert alert-warning">
                    <strong>Warning:</strong> This will <u>overwrite the entire database</u> with the uploaded backup file. All current data will be lost and replaced. This action <strong>cannot be undone</strong>.
                </div>
                <div class="mb-3">
                    <label class="form-label">Select .sql backup file</label>
                    <input type="file" class="form-control" id="restoreFileInput" accept=".sql">
                </div>
                <div class="mb-3" id="restorePasswordGroup" style="display:none;">
                    <label class="form-label">Enter your admin password to confirm</label>
                    <input type="password" class="form-control" id="restorePassword" placeholder="Admin password">
                </div>
                <div id="restoreStatus" class="alert d-none" role="alert"></div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                <button type="button" id="restoreBtn" class="btn btn-danger" onclick="restoreBackup()" disabled>
                    <i class="bi bi-upload"></i> Restore
                </button>
            </div>
        </div>
    </div>
</div>

<script>
document.getElementById('restoreFileInput').addEventListener('change', function() {
    var hasFile = this.files.length > 0;
    document.getElementById('restorePasswordGroup').style.display = hasFile ? '' : 'none';
    document.getElementById('restoreBtn').disabled = true;
    document.getElementById('restorePassword').value = '';
});

document.getElementById('restorePassword').addEventListener('input', function() {
    var hasFile = document.getElementById('restoreFileInput').files.length > 0;
    document.getElementById('restoreBtn').disabled = !(hasFile && this.value.length > 0);
});

async function restoreBackup() {
    var fileInput = document.getElementById('restoreFileInput');
    var password = document.getElementById('restorePassword').value;
    var btn = document.getElementById('restoreBtn');
    var status = document.getElementById('restoreStatus');

    if (!fileInput.files.length || !password) return;

    var ok = await showConfirmModal({
        title: 'Confirm Database Restore',
        message: 'This will completely overwrite the database with the uploaded backup. All current data will be permanently lost. This action cannot be undone.',
        variant: 'danger',
        confirmText: 'Yes, Restore'
    });
    if (!ok) return;

    btn.disabled = true;
    btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Restoring...';
    status.className = 'alert alert-info';
    status.textContent = 'Verifying password and restoring database... This may take a while.';
    status.classList.remove('d-none');

    var fd = new FormData();
    fd.append('backup_file', fileInput.files[0]);
    fd.append('admin_password', password);

    try {
        var res = await fetch('<?php echo htmlspecialchars(rtrim((string)env('APP_URL', ''), '/') . '/scripts/restore_backup.php'); ?>', {
            method: 'POST',
            body: fd
        });
        var json = await res.json();
        if (json.success) {
            var msg = 'Restore completed: ' + json.statements + ' statements executed';
            if (json.errors > 0) {
                msg += ', ' + json.errors + ' errors';
                if (json.error_details && json.error_details.length) {
                    msg += '\n\nErrors: ' + json.error_details.join('\n');
                }
            }
            status.className = 'alert alert-' + (json.errors > 0 ? 'warning' : 'success');
            status.textContent = msg;
        } else {
            status.className = 'alert alert-danger';
            status.textContent = 'Restore failed: ' + (json.message || 'Unknown error');
            btn.disabled = false;
        }
    } catch (e) {
        status.className = 'alert alert-danger';
        status.textContent = 'Restore failed: ' + e.message;
        btn.disabled = false;
    }
    btn.innerHTML = '<i class="bi bi-upload"></i> Restore';
}
</script>
