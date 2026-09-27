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
        $message = 'Please refresh the page and try again.';
        $messageType = 'danger';
    } else {
        $action = $_POST['action'] ?? '';

        if ($action === 'request_leave') {
            $startDate = trim((string)($_POST['leave_start_date'] ?? ''));
            $endDate = trim((string)($_POST['leave_end_date'] ?? ''));
            $reason = trim((string)($_POST['reason'] ?? ''));
            if ($reason === '') { $reason = 'N/A'; }

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
                            $message = 'An error occurred. Please try again.';
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
$availabilityTimes = [];
if ($conn->query("SHOW TABLES LIKE 'doctor_availability'")->num_rows > 0) {
    $availStmt = $conn->prepare('SELECT weekday, start_time, end_time FROM doctor_availability WHERE user_id = ? AND status = "active"');
    if ($availStmt) {
        $availStmt->bind_param('i', $doctorId);
        $availStmt->execute();
        $availRes = $availStmt->get_result();
        while ($a = $availRes->fetch_assoc()) {
            $weekday = (int)($a['weekday'] ?? -1);
            if ($weekday >= 0 && $weekday <= 6) {
                $availabilityWeekdays[$weekday] = true;
                $availabilityTimes[$weekday] = [
                    'start' => (string)($a['start_time'] ?? ''),
                    'end' => (string)($a['end_time'] ?? ''),
                ];
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

<div class="module-page-header mb-3">
    <h4 class="mb-0">
        <span class="module-page-header__crumb">Scheduling</span> <span class="module-page-header__sep">&gt;</span>
        <?php echo $scheduleView === 'leave_requests' ? 'Leave Requests' : 'My Schedule'; ?>
    </h4>
</div>

<nav class="module-section-nav" aria-label="Schedule sections">
    <div class="module-section-nav__track">
        <ul class="nav nav-tabs mb-0">
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
    </div>
</nav>

<?php if ($scheduleView === 'calendar'): ?>
<div class="card border-0">
    <div class="card-header">
        <div class="d-flex flex-wrap justify-content-between align-items-center gap-2">
            <h5 class="card-title mb-0">My Schedule</h5>
        </div>
    </div>
    <div class="card-body">
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

        <?php
        $calendarDays = [];
        for ($dayNum = 1; $dayNum <= $daysInMonth; $dayNum++) {
            $dateStr = $selectedMonth . '-' . str_pad((string)$dayNum, 2, '0', STR_PAD_LEFT);
            $weekday = (int)date('w', strtotime($dateStr));
            $isClosed = ($weekday === 0);
            $isApprovedLeave = !empty($approvedLeaveByDate[$dateStr]);
            $isPendingLeave = !empty($pendingLeaveByDate[$dateStr]);
            $isAvailable = !$isClosed && (($workType === 'part_time') ? !empty($availabilityWeekdays[$weekday]) : true);

            if ($isClosed) {
                $calendarDays[$dateStr] = ['state' => 'closed', 'tags' => [['text' => 'Closed', 'tone' => 'muted']]];
            } elseif ($isApprovedLeave) {
                $reasonText = '';
                if (!empty($leaveDetailsByDate[$dateStr])) {
                    $reasonText = trim((string)($leaveDetailsByDate[$dateStr][0]['reason'] ?? ''));
                    $reasonText = trim((string)preg_replace('/^\[Filed by Secretary.*?\]\s*/', '', $reasonText));
                }
                $calendarDays[$dateStr] = ['state' => 'leave', 'tags' => [['text' => 'Leave', 'tone' => 'danger']], 'title' => 'Leave' . ($reasonText !== '' && $reasonText !== 'N/A' ? ': ' . $reasonText : '')];
            } elseif ($isPendingLeave) {
                $calendarDays[$dateStr] = ['state' => 'pending', 'tags' => [['text' => 'Pending leave', 'tone' => 'warn']]];
            } elseif ($isAvailable) {
                $hours = !empty($availabilityTimes[$weekday])
                    ? date('g:i A', strtotime($availabilityTimes[$weekday]['start'])) . ' – ' . date('g:i A', strtotime($availabilityTimes[$weekday]['end']))
                    : '9:00 AM – 5:00 PM';
                $calendarDays[$dateStr] = ['state' => 'available', 'tags' => [['text' => $hours, 'tone' => 'info']]];
            } else {
                $calendarDays[$dateStr] = ['state' => 'unavailable', 'tags' => [['text' => 'No availability', 'tone' => 'muted']]];
            }
        }
        ?>
        <div id="doctorScheduleCalendar"></div>
        <script>
        document.addEventListener('DOMContentLoaded', function () {
            var days = <?php echo json_encode($calendarDays, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
            DentalCalendar.create(document.getElementById('doctorScheduleCalendar'), {
                month: <?php echo json_encode($selectedMonth); ?>,
                getDay: function (iso) { return days[iso] || { state: 'muted' }; },
                onMonthChange: function (ym) {
                    return DentalCalendar.goTo('doctor.php?page=schedule&view=calendar&month=' + encodeURIComponent(ym));
                },
                legend: [
                    { tone: 'info', label: 'Your hours' },
                    { tone: 'danger', label: 'Leave' },
                    { tone: 'warn', label: 'Pending leave' },
                    { tone: 'muted', label: 'Closed / no availability' }
                ]
            });
        });
        </script>
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
.schedule-stat-chip { border-radius: 999px; padding: 8px 12px; font-size: 0.84rem; font-weight: 600; text-align: center; }
.schedule-stat-available { background: #dcfce7; color: #166534; }
.schedule-stat-leave { background: #fee2e2; color: #991b1b; }
.schedule-stat-pending { background: #fde68a; color: #92400e; }
</style>
