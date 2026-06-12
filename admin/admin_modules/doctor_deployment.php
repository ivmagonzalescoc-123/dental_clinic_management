<?php
include dirname(dirname(__DIR__)) . '/config/database.php';
include dirname(dirname(__DIR__)) . '/config/security.php';

security_start_session();
$csrf_token = security_generate_csrf_token();
security_ensure_work_type_column($conn);

$conn->query(
    "CREATE TABLE IF NOT EXISTS doctor_availability (
        availability_id INT(11) NOT NULL AUTO_INCREMENT,
        user_id INT(11) NOT NULL,
        weekday TINYINT(4) NOT NULL COMMENT '0=Sun..6=Sat',
        start_time TIME NOT NULL DEFAULT '08:00:00',
        end_time TIME NOT NULL DEFAULT '17:00:00',
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (availability_id),
        KEY idx_doctor_weekday (user_id, weekday),
        CONSTRAINT fk_doctor_availability_user FOREIGN KEY (user_id) REFERENCES users(user_id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci"
);

$message = '';
$messageType = 'info';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $csrf = $_POST['csrf_token'] ?? '';
    if (!security_validate_csrf_token($csrf)) {
        $message = 'Security token mismatch. Please refresh and try again.';
        $messageType = 'danger';
    } else {
        $doctorId = (int)($_POST['doctor_id'] ?? 0);
        $workType = (string)($_POST['work_type'] ?? 'regular');
        $workType = ($workType === 'part_time') ? 'part_time' : 'regular';
        $weekdays = $_POST['weekdays'] ?? [];

        $validWeekdays = [];
        if (is_array($weekdays)) {
            foreach ($weekdays as $wd) {
                $w = (int)$wd;
                if ($w >= 1 && $w <= 6) {
                    $validWeekdays[$w] = true;
                }
            }
        }

        if ($doctorId <= 0) {
            $message = 'Please select a doctor.';
            $messageType = 'danger';
        } else {
            $check = $conn->prepare('SELECT user_id FROM users WHERE user_id = ? AND role_id = 2 LIMIT 1');
            $exists = false;
            if ($check) {
                $check->bind_param('i', $doctorId);
                $check->execute();
                $exists = (bool)$check->get_result()->fetch_assoc();
                $check->close();
            }

            if (!$exists) {
                $message = 'Doctor does not exist.';
                $messageType = 'danger';
            } elseif ($workType === 'part_time' && empty($validWeekdays)) {
                $message = 'Part-time doctors must have at least one deployment day (Mon-Sat).';
                $messageType = 'warning';
            } else {
                $conn->begin_transaction();
                try {
                    $u = $conn->prepare('UPDATE users SET work_type = ? WHERE user_id = ? AND role_id = 2');
                    if (!$u) {
                        throw new Exception('Failed to update work type.');
                    }
                    $u->bind_param('si', $workType, $doctorId);
                    $u->execute();
                    $u->close();

                    $del = $conn->prepare('DELETE FROM doctor_availability WHERE user_id = ?');
                    if (!$del) {
                        throw new Exception('Failed to refresh worktype');
                    }
                    $del->bind_param('i', $doctorId);
                    $del->execute();
                    $del->close();

                    if ($workType === 'part_time') {
                        $ins = $conn->prepare('INSERT INTO doctor_availability (user_id, weekday, start_time, end_time) VALUES (?, ?, "08:00:00", "17:00:00")');
                        if (!$ins) {
                            throw new Exception('Failed to save worktype');
                        }
                        foreach (array_keys($validWeekdays) as $wd) {
                            $ins->bind_param('ii', $doctorId, $wd);
                            $ins->execute();
                        }
                        $ins->close();
                    }

                    $conn->commit();
                    $message = 'Doctor shift saved successfully.';
                    $messageType = 'success';
                } catch (Throwable $e) {
                    $conn->rollback();
                    $message = 'Failed to save shift days: ' . $e->getMessage();
                    $messageType = 'danger';
                }
            }
        }
    }
}

$doctorRows = [];
$sql = "
SELECT
    u.user_id,
    u.first_name,
    u.last_name,
    COALESCE(u.work_type, 'regular') AS work_type,
    GROUP_CONCAT(DISTINCT da.weekday ORDER BY da.weekday ASC SEPARATOR ',') AS deployed_days
FROM users u
LEFT JOIN doctor_availability da ON da.user_id = u.user_id
WHERE u.role_id = 2
GROUP BY u.user_id, u.first_name, u.last_name, u.work_type
ORDER BY u.first_name ASC, u.last_name ASC
";
$res = $conn->query($sql);
if ($res) {
    while ($r = $res->fetch_assoc()) {
        $doctorRows[] = $r;
    }
}

$weekdayNames = [
    1 => 'Mon',
    2 => 'Tue',
    3 => 'Wed',
    4 => 'Thu',
    5 => 'Fri',
    6 => 'Sat',
];
?>

<div class="card border-0">
    <div class="card-header d-flex justify-content-between align-items-center">
        <h5 class="card-title mb-0">Doctor's Worktype</h5>
    </div>
    <div class="card-body">
        <?php if ($message !== ''): ?>
            <div class="alert alert-<?php echo htmlspecialchars($messageType); ?> alert-dismissible fade show" role="alert">
                <?php echo htmlspecialchars($message); ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
            </div>
        <?php endif; ?>

        <form method="POST" class="card border-0 bg-light mb-3">
            <div class="card-body row g-2 align-items-end">
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">
                <div class="col-md-4">
                    <label class="form-label">Doctor</label>
                    <select name="doctor_id" id="deploy_doctor_id" class="form-select" required>
                        <option value="">Select doctor</option>
                        <?php foreach ($doctorRows as $dr): ?>
                            <option value="<?php echo (int)$dr['user_id']; ?>">
                                <?php echo htmlspecialchars('Dr. ' . trim((string)$dr['first_name'] . ' ' . (string)$dr['last_name'])); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-3">
                    <label class="form-label">Work Type</label>
                    <select name="work_type" id="deploy_work_type" class="form-select" required>
                        <option value="regular">Regular</option>
                        <option value="part_time">Part-time</option>
                    </select>
                </div>
                <div class="col-md-5" id="deploy_days_wrap" style="display:none;">
                    <label class="form-label">Deployment Days (Mon-Sat)</label>
                    <div class="d-flex flex-wrap gap-2">
                        <?php foreach ($weekdayNames as $wd => $label): ?>
                            <label class="form-check-label border rounded px-2 py-1 small">
                                <input class="form-check-input me-1" type="checkbox" name="weekdays[]" value="<?php echo (int)$wd; ?>"> <?php echo htmlspecialchars($label); ?>
                            </label>
                        <?php endforeach; ?>
                    </div>
                </div>
                <div class="col-12">
                    <button type="submit" class="btn btn-primary">Save Worktype</button>
                </div>
            </div>
        </form>

        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead>
                    <tr>
                        <th>Doctor</th>
                        <th>Work Type</th>
                        <th>Deployed Days</th>
                        <th>Doctor Schedule</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($doctorRows)): ?>
                        <tr><td colspan="4" class="text-center text-muted">No doctors found.</td></tr>
                    <?php else: ?>
                        <?php foreach ($doctorRows as $dr): ?>
                            <?php
                                $type = ((string)($dr['work_type'] ?? 'regular') === 'part_time') ? 'part_time' : 'regular';
                                $daysRaw = trim((string)($dr['deployed_days'] ?? ''));
                                $daysOut = [];
                                if ($daysRaw !== '') {
                                    foreach (explode(',', $daysRaw) as $d) {
                                        $dw = (int)$d;
                                        if (isset($weekdayNames[$dw])) {
                                            $daysOut[] = $weekdayNames[$dw];
                                        }
                                    }
                                }
                            ?>
                            <tr>
                                <td><?php echo htmlspecialchars('Dr. ' . trim((string)$dr['first_name'] . ' ' . (string)$dr['last_name'])); ?></td>
                                <td>
                                    <?php if ($type === 'regular'): ?>
                                        <span class="badge bg-success">Regular</span>
                                    <?php else: ?>
                                        <span class="badge bg-warning text-dark">Part-time</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php echo $type === 'regular'
                                        ? '<span class="text-muted">Mon-Sat (auto)</span>'
                                        : htmlspecialchars(!empty($daysOut) ? implode(', ', $daysOut) : 'Not assigned'); ?>
                                </td>
                                <td>
                                    <?php echo $type === 'regular'
                                        ? 'Scheduled from Monday-Saturday unless on leave.'
                                        : 'Assigned deployment days.'; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<script>
(function() {
    function toggleDeployDays() {
        const typeSel = document.getElementById('deploy_work_type');
        const wrap = document.getElementById('deploy_days_wrap');
        if (!typeSel || !wrap) return;
        wrap.style.display = (typeSel.value === 'part_time') ? '' : 'none';
    }

    document.addEventListener('DOMContentLoaded', function() {
        const typeSel = document.getElementById('deploy_work_type');
        if (typeSel) typeSel.addEventListener('change', toggleDeployDays);
        toggleDeployDays();
    });
})();
</script>
