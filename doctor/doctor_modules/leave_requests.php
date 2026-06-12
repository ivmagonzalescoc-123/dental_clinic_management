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

$selectedMonth = trim((string)($_GET['month'] ?? date('Y-m')));
if (!preg_match('/^\d{4}-\d{2}$/', $selectedMonth)) {
    $selectedMonth = date('Y-m');
}
$monthStart = $selectedMonth . '-01';
$monthEnd = date('Y-m-t', strtotime($monthStart));

$doctorProfile = [
    'work_type' => 'regular',
    'first_name' => '',
    'last_name' => '',
];
$doctorStmt = $conn->prepare("SELECT first_name, last_name, COALESCE(work_type, 'regular') AS work_type FROM users WHERE user_id = ? LIMIT 1");
if ($doctorStmt) {
    $doctorStmt->bind_param('i', $doctorId);
    $doctorStmt->execute();
    $doctorRes = $doctorStmt->get_result();
    if ($doctorRes && ($doctorRow = $doctorRes->fetch_assoc())) {
        $doctorProfile = $doctorRow;
    }
    $doctorStmt->close();
}

$availabilityWeekdays = [];
if ($conn->query("SHOW TABLES LIKE 'doctor_availability'")->num_rows > 0) {
    $availStmt = $conn->prepare('SELECT weekday FROM doctor_availability WHERE user_id = ?');
    if ($availStmt) {
        $availStmt->bind_param('i', $doctorId);
        $availStmt->execute();
        $availRes = $availStmt->get_result();
        while ($a = $availRes->fetch_assoc()) {
            $weekday = (int)($a['weekday'] ?? -1);
            if ($weekday >= 0 && $weekday <= 6) {
                $availabilityWeekdays[$weekday] = true;
            }
        }
        $availStmt->close();
    }
}

$approvedLeaveByDate = [];
$pendingLeaveByDate = [];
$leaveDetailsByDate = [];

foreach ($leaves as $leave) {
    $status = strtolower((string)($leave['status'] ?? ''));
    $from = max($monthStart, (string)($leave['leave_start_date'] ?? ''));
    $to = min($monthEnd, (string)($leave['leave_end_date'] ?? ''));

    if ($from === '' || $to === '' || $from > $to) {
        continue;
    }

    $cursor = new DateTime($from);
    $end = new DateTime($to);
    while ($cursor <= $end) {
        $dateKey = $cursor->format('Y-m-d');

        if ($status === 'approved') {
            $approvedLeaveByDate[$dateKey] = true;
        }
        if ($status === 'pending') {
            $pendingLeaveByDate[$dateKey] = true;
        }

        if (!isset($leaveDetailsByDate[$dateKey])) {
            $leaveDetailsByDate[$dateKey] = [];
        }
        $leaveDetailsByDate[$dateKey][] = [
            'status' => ucfirst($status !== '' ? $status : 'leave'),
            'start' => (string)($leave['leave_start_date'] ?? ''),
            'end' => (string)($leave['leave_end_date'] ?? ''),
            'reason' => trim((string)($leave['reason'] ?? '')),
        ];

        $cursor->modify('+1 day');
    }
}

$workType = strtolower((string)($doctorProfile['work_type'] ?? 'regular'));
$firstWeekday = (int)date('w', strtotime($monthStart));
$daysInMonth = (int)date('t', strtotime($monthStart));
$monthLabel = date('F Y', strtotime($monthStart));

$availableDays = 0;
$approvedLeaveDays = 0;
$pendingLeaveDays = 0;

for ($d = 1; $d <= $daysInMonth; $d++) {
    $dateStr = $selectedMonth . '-' . str_pad((string)$d, 2, '0', STR_PAD_LEFT);
    $weekday = (int)date('w', strtotime($dateStr));
    $isClosed = ($weekday === 0);

    if (!empty($approvedLeaveByDate[$dateStr])) {
        $approvedLeaveDays++;
        continue;
    }
    if (!empty($pendingLeaveByDate[$dateStr])) {
        $pendingLeaveDays++;
    }
    if ($isClosed) {
        continue;
    }

    $hasAvailability = ($workType === 'part_time') ? !empty($availabilityWeekdays[$weekday]) : true;
    if ($hasAvailability) {
        $availableDays++;
    }
}

$activePage = trim((string)($_GET['page'] ?? 'schedule'));
$scheduleView = trim((string)($_GET['view'] ?? ''));
if (!in_array($scheduleView, ['calendar', 'leave_requests'], true)) {
    $scheduleView = ($activePage === 'leave_requests') ? 'leave_requests' : 'calendar';
}
?>

<ul class="nav nav-tabs mb-3">
    <li class="nav-item">
        <a class="nav-link <?php echo $scheduleView === 'calendar' ? 'active' : ''; ?>" href="doctor.php?page=schedule&view=calendar&month=<?php echo urlencode($selectedMonth); ?>">
            <i class="bi bi-calendar3 me-1"></i> My Schedule
        </a>
    </li>
    <li class="nav-item">
        <a class="nav-link <?php echo $scheduleView === 'leave_requests' ? 'active' : ''; ?>" href="doctor.php?page=schedule&view=leave_requests&month=<?php echo urlencode($selectedMonth); ?>">
            <i class="bi bi-calendar-x me-1"></i> Leave Requests
        </a>
    </li>
</ul>

<?php if ($scheduleView === 'calendar'): ?>
<div class="card border-0">
    <div class="card-header">
        <div class="d-flex flex-wrap justify-content-between align-items-center gap-2">
            <h5 class="card-title mb-0">My Schedule</h5>
            <form method="GET" action="doctor.php" class="d-flex align-items-center gap-2">
                <input type="hidden" name="page" value="schedule">
                <input type="hidden" name="view" value="calendar">
                <label class="small text-muted mb-0" for="doctorScheduleMonth">Month</label>
                <input id="doctorScheduleMonth" type="month" class="form-control form-control-sm" name="month" value="<?php echo htmlspecialchars($selectedMonth); ?>" style="min-width: 170px;">
                <button type="submit" class="btn btn-sm btn-primary">Apply</button>
            </form>
        </div>
    </div>
    <div class="card-body">
        <p class="text-muted mb-3">This calendar shows your availability days and leave status, not patient bookings.</p>

        <div class="row g-2 mb-3">
            <div class="col-sm-4">
                <div class="schedule-stat-chip schedule-stat-available">Available Days: <?php echo (int)$availableDays; ?></div>
            </div>
            <div class="col-sm-4">
                <div class="schedule-stat-chip schedule-stat-leave">Approved Leave Days: <?php echo (int)$approvedLeaveDays; ?></div>
            </div>
            <div class="col-sm-4">
                <div class="schedule-stat-chip schedule-stat-pending">Pending Leave Days: <?php echo (int)$pendingLeaveDays; ?></div>
            </div>
        </div>

        <div class="table-responsive">
            <table class="table table-bordered doctor-availability-calendar mb-0" data-skip-table-search="1">
                <thead>
                    <tr>
                        <th>Sun</th>
                        <th>Mon</th>
                        <th>Tue</th>
                        <th>Wed</th>
                        <th>Thu</th>
                        <th>Fri</th>
                        <th>Sat</th>
                    </tr>
                </thead>
                <tbody>
                    <?php
                    $dayNum = 1;
                    $started = false;
                    while ($dayNum <= $daysInMonth):
                        echo '<tr>';
                        for ($w = 0; $w < 7; $w++):
                            if (!$started && $w === $firstWeekday) {
                                $started = true;
                            }

                            if (!$started || $dayNum > $daysInMonth) {
                                echo '<td class="bg-light"></td>';
                                continue;
                            }

                            $dateStr = $selectedMonth . '-' . str_pad((string)$dayNum, 2, '0', STR_PAD_LEFT);
                            $weekday = (int)date('w', strtotime($dateStr));
                            $isClosed = ($weekday === 0);
                            $isApprovedLeave = !empty($approvedLeaveByDate[$dateStr]);
                            $isPendingLeave = !empty($pendingLeaveByDate[$dateStr]);
                            $isAvailable = !$isClosed && (($workType === 'part_time') ? !empty($availabilityWeekdays[$weekday]) : true);

                            $cellClass = 'calendar-cell';
                            $markHtml = '';
                            $popoverText = '';

                            if ($isClosed) {
                                $cellClass .= ' cell-closed';
                                $markHtml = '<span class="mark mark-closed">Clinic Closed</span>';
                            } elseif ($isApprovedLeave) {
                                $cellClass .= ' cell-leave';
                                $markHtml = '<button type="button" class="mark mark-leave mark-btn" data-bs-toggle="popover" data-bs-trigger="hover focus" data-bs-placement="top" data-bs-content="{{POPOVER}}">On Leave</button>';
                            } elseif ($isPendingLeave) {
                                $cellClass .= ' cell-pending';
                                $markHtml = '<button type="button" class="mark mark-pending mark-btn" data-bs-toggle="popover" data-bs-trigger="hover focus" data-bs-placement="top" data-bs-content="{{POPOVER}}">Leave Pending</button>';
                            } elseif ($isAvailable) {
                                $cellClass .= ' cell-available';
                                $markHtml = '<span class="mark mark-available">Available</span>';
                            } else {
                                $markHtml = '<span class="mark mark-nosched">No Availability</span>';
                            }

                            if (!empty($leaveDetailsByDate[$dateStr])) {
                                $lines = [];
                                foreach ($leaveDetailsByDate[$dateStr] as $detail) {
                                    $line = (string)($detail['status'] ?? 'Leave') . ': ' . (string)($detail['start'] ?? '') . ' to ' . (string)($detail['end'] ?? '');
                                    if (!empty($detail['reason'])) {
                                        $line .= ' (' . (string)$detail['reason'] . ')';
                                    }
                                    $lines[] = $line;
                                }
                                $popoverText = htmlspecialchars(implode("\n", $lines), ENT_QUOTES, 'UTF-8');
                                $markHtml = str_replace('{{POPOVER}}', $popoverText, $markHtml);
                            }

                            echo '<td class="' . htmlspecialchars($cellClass) . '">';
                            echo '<div class="calendar-day">' . (int)$dayNum . '</div>';
                            echo '<div class="calendar-marks">' . $markHtml . '</div>';
                            echo '</td>';

                            $dayNum++;
                        endfor;
                        echo '</tr>';
                    endwhile;
                    ?>
                </tbody>
            </table>
        </div>

        <div class="d-flex flex-wrap gap-2 mt-3 small">
            <span class="legend-chip legend-available">Green: Available</span>
            <span class="legend-chip legend-leave">Red: Approved Leave</span>
            <span class="legend-chip legend-pending">Amber: Pending Leave</span>
            <span class="legend-chip legend-closed">Yellow: Clinic Closed</span>
            <span class="legend-chip legend-nosched">Gray: No Availability</span>
        </div>
    </div>
</div>
<?php endif; ?>

<?php if ($scheduleView === 'leave_requests'): ?>
<div class="card border-0 mt-3">
    <div class="card-header">
        <h5 class="card-title mb-0">Leave Requests</h5>
    </div>
    <div class="card-body">
        <?php if ($message !== ''): ?>
            <div class="alert alert-<?php echo htmlspecialchars($messageType); ?> alert-dismissible fade show" role="alert">
                <?php echo htmlspecialchars($message); ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
            </div>
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
                                        <form method="POST" style="display:inline;" data-confirm="Cancel this leave request?" data-confirm-title="Cancel Leave" data-confirm-variant="danger" data-confirm-ok="Cancel Request">
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
<?php endif; ?>

<style>
.doctor-availability-calendar th {
    text-align: center;
    background: #f8fafc;
}

.doctor-availability-calendar .calendar-cell {
    height: 104px;
    vertical-align: top;
    background: #ffffff;
}

.doctor-availability-calendar .calendar-cell.cell-available {
    background: #f0fdf4;
}

.doctor-availability-calendar .calendar-cell.cell-leave {
    background: #fff1f2;
}

.doctor-availability-calendar .calendar-cell.cell-pending {
    background: #fffbeb;
}

.doctor-availability-calendar .calendar-cell.cell-closed {
    background: #fefce8;
}

.doctor-availability-calendar .calendar-day {
    font-weight: 700;
    font-size: 0.98rem;
    margin-bottom: 4px;
}

.doctor-availability-calendar .calendar-marks {
    display: flex;
    flex-direction: column;
    gap: 4px;
}

.mark {
    display: inline-block;
    width: fit-content;
    max-width: 100%;
    border-radius: 6px;
    font-size: 0.72rem;
    padding: 3px 8px;
    line-height: 1.2;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
}

.mark-btn {
    border: none;
    cursor: pointer;
}

.mark-available {
    background: #dcfce7;
    color: #166534;
}

.mark-leave {
    background: #fee2e2;
    color: #991b1b;
}

.mark-pending {
    background: #fde68a;
    color: #92400e;
}

.mark-closed {
    background: #fef3c7;
    color: #92400e;
}

.mark-nosched {
    background: #e5e7eb;
    color: #374151;
}

.schedule-stat-chip {
    border-radius: 999px;
    padding: 8px 12px;
    font-size: 0.84rem;
    font-weight: 600;
    text-align: center;
}

.schedule-stat-available {
    background: #dcfce7;
    color: #166534;
}

.schedule-stat-leave {
    background: #fee2e2;
    color: #991b1b;
}

.schedule-stat-pending {
    background: #fde68a;
    color: #92400e;
}

.legend-chip {
    border-radius: 999px;
    padding: 4px 10px;
}

.legend-available {
    background: #dcfce7;
    color: #166534;
}

.legend-leave {
    background: #fee2e2;
    color: #991b1b;
}

.legend-pending {
    background: #fde68a;
    color: #92400e;
}

.legend-closed {
    background: #fef3c7;
    color: #92400e;
}

.legend-nosched {
    background: #e5e7eb;
    color: #374151;
}

@media (max-width: 768px) {
    .doctor-availability-calendar .calendar-cell {
        height: 86px;
    }

    .doctor-availability-calendar .calendar-day {
        font-size: 0.86rem;
    }

    .mark {
        font-size: 0.66rem;
        padding: 2px 6px;
    }
}
</style>
<script>
document.addEventListener('DOMContentLoaded', function () {
    if (typeof bootstrap === 'undefined' || typeof bootstrap.Popover === 'undefined') {
        return;
    }
    document.querySelectorAll('[data-bs-toggle="popover"]').forEach(function (el) {
        new bootstrap.Popover(el, {
            html: false,
            sanitize: true,
            boundary: 'viewport'
        });
    });
});
</script>
