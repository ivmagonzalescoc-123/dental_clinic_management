<?php
include dirname(dirname(__DIR__)) . '/config/database.php';
include dirname(dirname(__DIR__)) . '/config/security.php';
include_once dirname(dirname(__DIR__)) . '/config/workflow_notifications.php';
include_once dirname(dirname(__DIR__)) . '/assets/api/appointments/availability_guard.php';

security_start_session();
$csrf_token = security_generate_csrf_token();

$message = '';
$messageType = 'info';
$inDoctorManagement = !empty($doctorManagementMode);
$leavesBasePage = $inDoctorManagement ? 'doctor_management' : 'doctor_leaves';
$leavesExtraParams = $inDoctorManagement ? '&tab=leaves' : '';

$currentRole = $_SESSION['role'] ?? '';
if ($currentRole !== 'admin') {
    echo '<div class="alert alert-danger">Only admin can process leave requests.</div>';
    return;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $csrf = $_POST['csrf_token'] ?? '';
    if (!security_validate_csrf_token($csrf)) {
        $message = 'Security token mismatch. Please refresh and try again.';
        $messageType = 'danger';
    } else {
        $action = $_POST['action'] ?? '';
        $leaveId = (int)($_POST['leave_id'] ?? 0);

        if ($leaveId <= 0) {
            $message = 'Invalid leave request.';
            $messageType = 'danger';
        } elseif ($action === 'approve') {
            $leaveStmt = $conn->prepare('SELECT user_id, leave_start_date, leave_end_date FROM doctor_leaves WHERE leave_id = ? AND status = ? LIMIT 1');
            $leaveRow = null;
            if ($leaveStmt) {
                $pendingStatus = 'pending';
                $leaveStmt->bind_param('is', $leaveId, $pendingStatus);
                $leaveStmt->execute();
                $leaveRow = $leaveStmt->get_result()->fetch_assoc();
                $leaveStmt->close();
            }

            $upd = $conn->prepare("UPDATE doctor_leaves SET status = 'approved' WHERE leave_id = ? AND status = 'pending'");
            if ($upd) {
                $upd->bind_param('i', $leaveId);
                $upd->execute();
                if ($upd->affected_rows > 0) {
                    $message = 'Leave request approved.';
                    $messageType = 'success';

                    if ($leaveRow) {
                        $doctorId = (int)$leaveRow['user_id'];
                        $leaveStart = (string)$leaveRow['leave_start_date'];
                        $leaveEnd = (string)$leaveRow['leave_end_date'];

                        $conflicts = [];
                        $confStmt = $conn->prepare(
                            "SELECT appointment_id, patient_id, appointment_date
                             FROM appointments
                             WHERE user_id = ? AND appointment_date BETWEEN ? AND ? AND status IN ('scheduled','confirmed')"
                        );
                        if ($confStmt) {
                            $confStmt->bind_param('iss', $doctorId, $leaveStart, $leaveEnd);
                            $confStmt->execute();
                            $confRes = $confStmt->get_result();
                            while ($row = $confRes->fetch_assoc()) {
                                $conflicts[] = $row;
                            }
                            $confStmt->close();
                        }

                        if (!empty($conflicts) && function_exists('appt_ensure_needs_reassignment_column')) {
                            appt_ensure_needs_reassignment_column($conn);
                            $conflictIds = array_map(function ($c) {
                                return (int)$c['appointment_id'];
                            }, $conflicts);
                            $placeholders = implode(',', array_fill(0, count($conflictIds), '?'));
                            $types = str_repeat('i', count($conflictIds));
                            $flagStmt = $conn->prepare("UPDATE appointments SET needs_reassignment = 1 WHERE appointment_id IN ($placeholders)");
                            if ($flagStmt) {
                                $flagStmt->bind_param($types, ...$conflictIds);
                                $flagStmt->execute();
                                $flagStmt->close();
                            }
                        }

                        if (!empty($conflicts) && function_exists('wf_notify_doctor_leave_conflicts')) {
                            $docStmt = $conn->prepare('SELECT first_name, last_name FROM users WHERE user_id = ? LIMIT 1');
                            $doctorName = 'the assigned doctor';
                            if ($docStmt) {
                                $docStmt->bind_param('i', $doctorId);
                                $docStmt->execute();
                                $docRow = $docStmt->get_result()->fetch_assoc();
                                $docStmt->close();
                                if ($docRow) {
                                    $doctorName = trim(($docRow['first_name'] ?? '') . ' ' . ($docRow['last_name'] ?? '')) ?: $doctorName;
                                }
                            }
                            wf_notify_doctor_leave_conflicts($conn, $doctorId, $doctorName, $leaveStart, $leaveEnd, $conflicts);
                            $conflictCount = count($conflicts);
                            $message .= ' ' . $conflictCount . ' existing appointment' . ($conflictCount === 1 ? '' : 's')
                                . ' conflict' . ($conflictCount === 1 ? 's' : '') . ' with this leave — affected patients and secretaries have been notified.';
                        }
                    }
                } else {
                    $message = 'Leave request is not pending or no longer exists.';
                    $messageType = 'warning';
                }
                $upd->close();
            } else {
                $message = 'Unable to approve leave request.';
                $messageType = 'danger';
            }
        } elseif ($action === 'reject') {
            $upd = $conn->prepare("UPDATE doctor_leaves SET status = 'rejected' WHERE leave_id = ? AND status = 'pending'");
            if ($upd) {
                $upd->bind_param('i', $leaveId);
                $upd->execute();
                if ($upd->affected_rows > 0) {
                    $message = 'Leave request rejected.';
                    $messageType = 'success';
                } else {
                    $message = 'Leave request is not pending or no longer exists.';
                    $messageType = 'warning';
                }
                $upd->close();
            } else {
                $message = 'Unable to reject leave request.';
                $messageType = 'danger';
            }
        }
    }
}

$filter = $_GET['filter'] ?? 'pending';
$allowed = ['pending', 'approved', 'rejected', 'all'];
if (!in_array($filter, $allowed, true)) {
    $filter = 'pending';
}

$summaryMonth = trim((string)($_GET['summary_month'] ?? date('Y-m')));
if (!preg_match('/^\d{4}-\d{2}$/', $summaryMonth)) {
    $summaryMonth = date('Y-m');
}
$summaryStart = $summaryMonth . '-01';
$summaryEnd = date('Y-m-t', strtotime($summaryStart));

$doctorSummary = [];
$doctors = [];
$dres = $conn->query("SELECT user_id, first_name, last_name FROM users WHERE role_id = 2 ORDER BY first_name ASC, last_name ASC");
if ($dres) {
    while ($dr = $dres->fetch_assoc()) {
        $did = (int)$dr['user_id'];
        $doctorSummary[$did] = [
            'doctor' => trim((string)($dr['first_name'] ?? '') . ' ' . (string)($dr['last_name'] ?? '')),
            'approved_days' => 0,
            'pending_days' => 0,
            'rejected_days' => 0,
        ];
        $doctors[] = $did;
    }
}

$summaryLeaves = [];
$sumStmt = $conn->prepare(
    "SELECT user_id, leave_start_date, leave_end_date, status
     FROM doctor_leaves
     WHERE leave_start_date <= ?
       AND leave_end_date >= ?"
);
if ($sumStmt) {
    $sumStmt->bind_param('ss', $summaryEnd, $summaryStart);
    $sumStmt->execute();
    $sumRes = $sumStmt->get_result();
    while ($row = $sumRes->fetch_assoc()) {
        $summaryLeaves[] = $row;
    }
    $sumStmt->close();
}

foreach ($summaryLeaves as $lv) {
    $uid = (int)($lv['user_id'] ?? 0);
    if (!isset($doctorSummary[$uid])) {
        continue;
    }

    $status = (string)($lv['status'] ?? '');
    $from = max($summaryStart, (string)($lv['leave_start_date'] ?? ''));
    $to = min($summaryEnd, (string)($lv['leave_end_date'] ?? ''));
    if ($from > $to) {
        continue;
    }

    $cursor = new DateTime($from);
    $end = new DateTime($to);
    while ($cursor <= $end) {
        if ($status === 'approved') {
            $doctorSummary[$uid]['approved_days']++;
        } elseif ($status === 'pending') {
            $doctorSummary[$uid]['pending_days']++;
        } elseif ($status === 'rejected') {
            $doctorSummary[$uid]['rejected_days']++;
        }
        $cursor->modify('+1 day');
    }
}

$leaves = [];
if ($filter === 'all') {
    $sql = "SELECT dl.leave_id, dl.user_id, dl.leave_start_date, dl.leave_end_date, dl.reason, dl.status, dl.created_at,
                   u.first_name, u.last_name
            FROM doctor_leaves dl
            INNER JOIN users u ON u.user_id = dl.user_id
            WHERE u.role_id = 2
            ORDER BY dl.status = 'pending' DESC, dl.leave_start_date DESC, dl.created_at DESC";
    $res = $conn->query($sql);
    if ($res) {
        while ($row = $res->fetch_assoc()) {
            $leaves[] = $row;
        }
    }
} else {
    $stmt = $conn->prepare(
        "SELECT dl.leave_id, dl.user_id, dl.leave_start_date, dl.leave_end_date, dl.reason, dl.status, dl.created_at,
                u.first_name, u.last_name
         FROM doctor_leaves dl
         INNER JOIN users u ON u.user_id = dl.user_id
         WHERE u.role_id = 2 AND dl.status = ?
         ORDER BY dl.leave_start_date DESC, dl.created_at DESC"
    );
    if ($stmt) {
        $stmt->bind_param('s', $filter);
        $stmt->execute();
        $res = $stmt->get_result();
        while ($row = $res->fetch_assoc()) {
            $leaves[] = $row;
        }
        $stmt->close();
    }
}
?>

<h5 class="card-title mb-0">Doctor Leaves</h5>
        <nav class="module-section-nav module-section-nav--compact" aria-label="Leave status filter">
            <div class="module-section-nav__track">
                <div class="btn-group" role="group" aria-label="Filter leaves">
                    <a href="admin.php?page=<?php echo htmlspecialchars($leavesBasePage); ?><?php echo htmlspecialchars($leavesExtraParams); ?>&filter=pending" class="btn btn-sm <?php echo $filter === 'pending' ? 'btn-primary' : 'btn-outline-primary'; ?>">Pending</a>
                    <a href="admin.php?page=<?php echo htmlspecialchars($leavesBasePage); ?><?php echo htmlspecialchars($leavesExtraParams); ?>&filter=approved" class="btn btn-sm <?php echo $filter === 'approved' ? 'btn-success' : 'btn-outline-success'; ?>">Approved</a>
                    <a href="admin.php?page=<?php echo htmlspecialchars($leavesBasePage); ?><?php echo htmlspecialchars($leavesExtraParams); ?>&filter=rejected" class="btn btn-sm <?php echo $filter === 'rejected' ? 'btn-secondary' : 'btn-outline-secondary'; ?>">Rejected</a>
                    <a href="admin.php?page=<?php echo htmlspecialchars($leavesBasePage); ?><?php echo htmlspecialchars($leavesExtraParams); ?>&filter=all" class="btn btn-sm <?php echo $filter === 'all' ? 'btn-dark' : 'btn-outline-dark'; ?>">All</a>
                </div>
            </div>
        </nav>
    <?php if ($message !== ''): ?>
            <div class="alert alert-<?php echo htmlspecialchars($messageType); ?> alert-dismissible fade show" role="alert">
                <?php echo htmlspecialchars($message); ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
            </div>
        <?php endif; ?>

        <form method="GET" action="admin.php" class="row g-2 align-items-end mb-2">
                    <input type="hidden" name="page" value="<?php echo htmlspecialchars($leavesBasePage); ?>">
                    <?php if ($inDoctorManagement): ?>
                        <input type="hidden" name="tab" value="leaves">
                    <?php endif; ?>
                    <input type="hidden" name="filter" value="<?php echo htmlspecialchars($filter); ?>">
                    <div class="col-md-3">
                        <label class="form-label mb-1">Monthly Leave Summary</label>
                        <input type="month" class="form-control" name="summary_month" value="<?php echo htmlspecialchars($summaryMonth); ?>">
                    </div>
                    <div class="col-md-2">
                        <button type="submit" class="btn btn-outline-primary w-100">View</button>
                    </div>
                </form>

                <div class="table-responsive">
                    <table class="table table-sm align-middle mb-0">
                        <thead>
                            <tr>
                                <th>Doctor</th>
                                <th>Approved Leave Days</th>
                                <th>Pending Leave Days</th>
                                <th>Rejected Leave Days</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($doctorSummary)): ?>
                                <tr><td colspan="4" class="text-center text-muted">No doctors found.</td></tr>
                            <?php else: ?>
                                <?php foreach ($doctorSummary as $sum): ?>
                                    <tr>
                                        <td><?php echo htmlspecialchars((string)$sum['doctor']); ?></td>
                                        <td><span class="badge bg-success"><?php echo (int)$sum['approved_days']; ?></span></td>
                                        <td><span class="badge bg-warning text-dark"><?php echo (int)$sum['pending_days']; ?></span></td>
                                        <td><span class="badge bg-secondary"><?php echo (int)$sum['rejected_days']; ?></span></td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>

        <div class="table-responsive">
            <table class="table table-hover datatable" data-skip-table-search="1">
                <thead>
                    <tr>
                        <th>Doctor</th>
                        <th>Start Date</th>
                        <th>End Date</th>
                        <th>Reason</th>
                        <th>Status</th>
                        <th>Requested At</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($leaves)): ?>
                        <tr>
                            <td colspan="6" class="text-center text-muted py-3">No leave requests found.</td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($leaves as $leave): ?>
                            <tr>
                                <td><?php echo htmlspecialchars(trim(($leave['first_name'] ?? '') . ' ' . ($leave['last_name'] ?? ''))); ?></td>
                                <td><?php echo htmlspecialchars($leave['leave_start_date']); ?></td>
                                <td><?php echo htmlspecialchars($leave['leave_end_date']); ?></td>
                                <td>
                                    <?php
                                        $reasonText = (string)($leave['reason'] ?? '');
                                        $filedByName = '';
                                        $isFiledBySecretary = false;
                                        if (preg_match('/^\[Filed by Secretary\s*[#:]\s*(.*?)\]\s*/', $reasonText, $matches)) {
                                            $isFiledBySecretary = true;
                                            $filedByName = trim($matches[1]);
                                            $displayReasonText = preg_replace('/^\[Filed by Secretary\s*[#:]\s*.*?\]\s*/', '', $reasonText) ?? $reasonText;
                                        } else {
                                            $displayReasonText = $reasonText;
                                        }
                                    ?>
                                    <?php if ($isFiledBySecretary): ?>
                                        <span class="text-success fw-bold" style="background:none;padding:0;">Filed by <?php echo htmlspecialchars($filedByName); ?></span><br>
                                    <?php endif; ?>
                                    <?php echo htmlspecialchars($displayReasonText); ?>
                                </td>
                                <td>
                                    <?php
                                        $status = $leave['status'];
                                        $cls = $status === 'approved' ? 'success' : ($status === 'pending' ? 'warning text-dark' : 'secondary');
                                    ?>
                                    <?php if ($leave['status'] === 'pending'): ?>
                                        <div class="d-flex flex-column gap-2">
                                            <span class="badge bg-<?php echo $cls; ?> align-self-start"><?php echo htmlspecialchars(ucfirst($status)); ?></span>
                                            <div class="d-flex gap-1">
                                                <form method="POST" data-confirm="Approve this leave request?" data-confirm-title="Approve Leave" data-confirm-ok="Approve">
                                                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">
                                                    <input type="hidden" name="action" value="approve">
                                                    <input type="hidden" name="leave_id" value="<?php echo (int)$leave['leave_id']; ?>">
                                                    <button type="submit" class="btn btn-sm btn-success">Approve</button>
                                                </form>
                                                <form method="POST" data-confirm="Reject this leave request?" data-confirm-title="Reject Leave" data-confirm-variant="danger" data-confirm-ok="Reject">
                                                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">
                                                    <input type="hidden" name="action" value="reject">
                                                    <input type="hidden" name="leave_id" value="<?php echo (int)$leave['leave_id']; ?>">
                                                    <button type="submit" class="btn btn-sm btn-outline-danger">Decline</button>
                                                </form>
                                            </div>
                                        </div>
                                    <?php else: ?>
                                        <span class="badge bg-<?php echo $cls; ?>"><?php echo htmlspecialchars(ucfirst($status)); ?></span>
                                    <?php endif; ?>
                                </td>
                                <td><?php echo htmlspecialchars($leave['created_at']); ?></td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
