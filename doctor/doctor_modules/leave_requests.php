<?php
include dirname(dirname(__DIR__)) . '/config/database.php';
include dirname(dirname(__DIR__)) . '/config/security.php';

security_start_session();
$csrf_token = security_generate_csrf_token();

$doctorId = (int)($_SESSION['user_id'] ?? 0);
$message = '';
$messageType = 'info';

if ($doctorId <= 0) {
    echo '<div class="alert alert-danger">Unauthorized access.</div>';
    return;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $csrf = $_POST['csrf_token'] ?? '';
    if (!security_validate_csrf_token($csrf)) {
        $message = 'Security token mismatch. Please refresh and try again.';
        $messageType = 'danger';
    } else {
        $action = $_POST['action'] ?? '';

        if ($action === 'request_leave') {
            $startDate = trim((string)($_POST['leave_start_date'] ?? ''));
            $endDate = trim((string)($_POST['leave_end_date'] ?? ''));
            $reason = trim((string)($_POST['reason'] ?? ''));

            $startOk = DateTime::createFromFormat('Y-m-d', $startDate);
            $endOk = DateTime::createFromFormat('Y-m-d', $endDate);

            if (!$startOk || !$endOk || $startOk->format('Y-m-d') !== $startDate || $endOk->format('Y-m-d') !== $endDate) {
                $message = 'Please provide valid leave start and end dates.';
                $messageType = 'danger';
            } elseif ($startDate > $endDate) {
                $message = 'Leave start date must be before or equal to end date.';
                $messageType = 'danger';
            } elseif ($startDate < date('Y-m-d')) {
                $message = 'Leave cannot start in the past.';
                $messageType = 'danger';
            } else {
                if (mb_strlen($reason) > 255) {
                    $reason = mb_substr($reason, 0, 255);
                }

                $check = $conn->prepare(
                    "SELECT leave_id
                     FROM doctor_leaves
                     WHERE user_id = ?
                       AND status IN ('pending','approved')
                       AND NOT (leave_end_date < ? OR leave_start_date > ?)
                     LIMIT 1"
                );
                if ($check) {
                    $check->bind_param('iss', $doctorId, $startDate, $endDate);
                    $check->execute();
                    $exists = $check->get_result()->fetch_assoc();
                    $check->close();

                    if ($exists) {
                        $message = 'You already have a pending/approved leave overlapping that date range.';
                        $messageType = 'warning';
                    } else {
                        $ins = $conn->prepare(
                            "INSERT INTO doctor_leaves (user_id, leave_start_date, leave_end_date, reason, status)
                             VALUES (?, ?, ?, ?, 'pending')"
                        );
                        if ($ins) {
                            $ins->bind_param('isss', $doctorId, $startDate, $endDate, $reason);
                            if ($ins->execute()) {
                                $message = 'Leave request submitted. Waiting for admin approval.';
                                $messageType = 'success';
                            } else {
                                $message = 'Unable to submit leave request. Please try again.';
                                $messageType = 'danger';
                            }
                            $ins->close();
                        } else {
                            $message = 'Unable to prepare leave request.';
                            $messageType = 'danger';
                        }
                    }
                } else {
                    $message = 'Unable to validate existing leave requests.';
                    $messageType = 'danger';
                }
            }
        }

        if ($action === 'cancel_leave') {
            $leaveId = (int)($_POST['leave_id'] ?? 0);
            if ($leaveId <= 0) {
                $message = 'Invalid leave request.';
                $messageType = 'danger';
            } else {
                $upd = $conn->prepare("UPDATE doctor_leaves SET status = 'rejected' WHERE leave_id = ? AND user_id = ? AND status = 'pending'");
                if ($upd) {
                    $upd->bind_param('ii', $leaveId, $doctorId);
                    $upd->execute();
                    if ($upd->affected_rows > 0) {
                        $message = 'Pending leave request cancelled.';
                        $messageType = 'success';
                    } else {
                        $message = 'Leave request cannot be cancelled (it may already be processed).';
                        $messageType = 'warning';
                    }
                    $upd->close();
                } else {
                    $message = 'Unable to cancel leave request.';
                    $messageType = 'danger';
                }
            }
        }
    }
}

$leaves = [];
$stmt = $conn->prepare(
    "SELECT leave_id, leave_start_date, leave_end_date, reason, status, created_at
     FROM doctor_leaves
     WHERE user_id = ?
     ORDER BY leave_start_date DESC, created_at DESC"
);
if ($stmt) {
    $stmt->bind_param('i', $doctorId);
    $stmt->execute();
    $res = $stmt->get_result();
    while ($row = $res->fetch_assoc()) {
        $leaves[] = $row;
    }
    $stmt->close();
}
?>

<div class="card border-0">
    <div class="card-header">
        <h5 class="card-title mb-0">Doctor Leave Requests</h5>
    </div>
    <div class="card-body">
        <?php if ($message !== ''): ?>
            <div class="alert alert-<?php echo htmlspecialchars($messageType); ?>"><?php echo htmlspecialchars($message); ?></div>
        <?php endif; ?>

        <form method="POST" class="row g-3 mb-4">
            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">
            <input type="hidden" name="action" value="request_leave">

            <div class="col-md-4">
                <label class="form-label">Leave Start Date</label>
                <input type="date" class="form-control" name="leave_start_date" required min="<?php echo date('Y-m-d'); ?>">
            </div>
            <div class="col-md-4">
                <label class="form-label">Leave End Date</label>
                <input type="date" class="form-control" name="leave_end_date" required min="<?php echo date('Y-m-d'); ?>">
            </div>
            <div class="col-md-12">
                <label class="form-label">Reason (optional)</label>
                <textarea class="form-control" name="reason" rows="2" maxlength="255" placeholder="Reason for leave request"></textarea>
            </div>
            <div class="col-md-12">
                <button type="submit" class="btn btn-primary">Submit Leave Request</button>
            </div>
        </form>

        <div class="table-responsive">
            <table class="table table-hover">
                <thead>
                    <tr>
                        <th>Start Date</th>
                        <th>End Date</th>
                        <th>Reason</th>
                        <th>Status</th>
                        <th>Requested At</th>
                        <th>Action</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($leaves)): ?>
                        <tr><td colspan="6" class="text-center text-muted">No leave requests found.</td></tr>
                    <?php else: ?>
                        <?php foreach ($leaves as $leave): ?>
                            <tr>
                                <td><?php echo htmlspecialchars($leave['leave_start_date']); ?></td>
                                <td><?php echo htmlspecialchars($leave['leave_end_date']); ?></td>
                                <td><?php echo htmlspecialchars($leave['reason'] ?? ''); ?></td>
                                <td>
                                    <?php
                                        $status = $leave['status'];
                                        $cls = $status === 'approved' ? 'success' : ($status === 'pending' ? 'warning text-dark' : 'secondary');
                                    ?>
                                    <span class="badge bg-<?php echo $cls; ?>"><?php echo htmlspecialchars(ucfirst($status)); ?></span>
                                </td>
                                <td><?php echo htmlspecialchars($leave['created_at']); ?></td>
                                <td>
                                    <?php if ($leave['status'] === 'pending'): ?>
                                        <form method="POST" style="display:inline;" onsubmit="return confirm('Cancel this leave request?');">
                                            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">
                                            <input type="hidden" name="action" value="cancel_leave">
                                            <input type="hidden" name="leave_id" value="<?php echo (int)$leave['leave_id']; ?>">
                                            <button type="submit" class="btn btn-sm btn-outline-danger">Cancel</button>
                                        </form>
                                    <?php else: ?>
                                        <span class="text-muted small">-</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>
