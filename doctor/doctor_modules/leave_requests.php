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
<?php
// Weekly pattern: which weekdays this doctor works and at what hours. Regular doctors
// work Mon–Sat; part-time doctors only the weekdays set in their availability. The
// clinic is closed on Sundays. Hours default to 9 AM – 5 PM when none are set.
$weekdayShort = ['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'];
$weekdayLong = ['Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday'];
$fmtHour = function (string $t): string {
    $ts = strtotime($t);
    if ($ts === false) return '';
    return date((int)date('i', $ts) === 0 ? 'g A' : 'g:i A', $ts);
};
$workHours = []; // weekday => "9 AM – 5 PM"
for ($w = 1; $w <= 6; $w++) {
    $works = ($workType === 'part_time') ? !empty($availabilityWeekdays[$w]) : true;
    if (!$works) continue;
    $t = $availabilityTimes[$w] ?? null;
    $from = $fmtHour(($t['start'] ?? '') !== '' ? $t['start'] : '09:00:00');
    $to = $fmtHour(($t['end'] ?? '') !== '' ? $t['end'] : '17:00:00');
    $workHours[$w] = $from . ' – ' . $to;
}
$distinctHours = array_values(array_unique($workHours));
$sameHours = count($distinctHours) === 1;
$hoursLabel = $workHours ? ($sameHours ? $distinctHours[0] : 'Varies by day') : 'No hours set';

// "Mon – Sat", "Mon, Thu", "Mon – Wed, Fri"
$runs = [];
foreach (array_keys($workHours) as $w) {
    $last = count($runs) - 1;
    if ($last >= 0 && end($runs[$last]) === $w - 1) $runs[$last][] = $w;
    else $runs[] = [$w];
}
$daysLabel = implode(', ', array_map(function ($run) use ($weekdayShort) {
    if (count($run) >= 3) return $weekdayShort[$run[0]] . ' – ' . $weekdayShort[end($run)];
    return implode(', ', array_map(function ($w) use ($weekdayShort) { return $weekdayShort[$w]; }, $run));
}, $runs));
$dayCount = count($workHours);
$dayCountLabel = $dayCount === 0 ? 'No working days set' : ($dayCount === 1 ? '1 day a week' : $dayCount . ' days a week');
$todayWeekday = (int)date('w');
?>
<div class="card border-0 ds">
    <div class="card-body">
        <div class="ds-summary">
            <div class="ds-summary__item">
                <span class="ds-summary__icon"><i class="bi bi-clock"></i></span>
                <div>
                    <span class="ds-summary__label">Working hours</span>
                    <strong class="ds-summary__value"><?php echo htmlspecialchars($hoursLabel); ?></strong>
                    <span class="ds-summary__sub"><?php echo $workType === 'part_time' ? 'Part-time' : 'Regular'; ?> schedule</span>
                </div>
            </div>
            <div class="ds-summary__item">
                <span class="ds-summary__icon"><i class="bi bi-calendar-week"></i></span>
                <div>
                    <span class="ds-summary__label">Working days</span>
                    <strong class="ds-summary__value"><?php echo htmlspecialchars($daysLabel !== '' ? $daysLabel : '—'); ?></strong>
                    <span class="ds-summary__sub"><?php echo htmlspecialchars($dayCountLabel); ?></span>
                </div>
            </div>
            <ol class="ds-week" aria-label="Weekly schedule">
                <?php for ($w = 0; $w <= 6; $w++):
                    $isWork = isset($workHours[$w]);
                    $cls = $w === 0 ? 'is-closed' : ($isWork ? 'is-work' : 'is-off');
                    $note = $w === 0 ? 'Closed' : ($isWork ? ($sameHours ? 'Working' : $workHours[$w]) : 'Off');
                ?>
                    <li class="ds-week__day <?php echo $cls; ?><?php echo $w === $todayWeekday ? ' is-today' : ''; ?>"
                        title="<?php echo htmlspecialchars($weekdayLong[$w] . ': ' . ($isWork ? $workHours[$w] : ($w === 0 ? 'Clinic closed' : 'Day off'))); ?>">
                        <span class="ds-week__name"><?php echo $weekdayShort[$w]; ?></span>
                        <span class="ds-week__mark" aria-hidden="true"><i class="bi <?php echo $isWork ? 'bi-check-lg' : 'bi-dash'; ?>"></i></span>
                        <span class="ds-week__note"><?php echo htmlspecialchars($note); ?></span>
                    </li>
                <?php endfor; ?>
            </ol>
        </div>

        <div class="ds-stats">
            <div class="ds-stat ds-stat--work"><strong><?php echo (int)$availableDays; ?></strong><span>Working days in <?php echo htmlspecialchars(date('F', strtotime($monthStart))); ?></span></div>
            <div class="ds-stat ds-stat--leave"><strong><?php echo (int)$approvedLeaveDays; ?></strong><span>Approved leave</span></div>
            <div class="ds-stat ds-stat--pending"><strong><?php echo (int)$pendingLeaveDays; ?></strong><span>Pending leave</span></div>
        </div>

        <?php
        // Calendar shows only what changes day to day: working, leave, pending, off.
        // The hours live in the summary above, not repeated in every cell.
        $calendarDays = [];
        for ($dayNum = 1; $dayNum <= $daysInMonth; $dayNum++) {
            $dateStr = $selectedMonth . '-' . str_pad((string)$dayNum, 2, '0', STR_PAD_LEFT);
            $weekday = (int)date('w', strtotime($dateStr));
            $isClosed = ($weekday === 0);
            $isApprovedLeave = !empty($approvedLeaveByDate[$dateStr]);
            $isPendingLeave = !empty($pendingLeaveByDate[$dateStr]);
            $isAvailable = !$isClosed && isset($workHours[$weekday]);

            if ($isClosed) {
                $calendarDays[$dateStr] = ['state' => 'closed', 'title' => 'Clinic closed'];
            } elseif ($isApprovedLeave) {
                $reasonText = '';
                if (!empty($leaveDetailsByDate[$dateStr])) {
                    $reasonText = trim((string)($leaveDetailsByDate[$dateStr][0]['reason'] ?? ''));
                    $reasonText = trim((string)preg_replace('/^\[Filed by Secretary.*?\]\s*/', '', $reasonText));
                }
                $calendarDays[$dateStr] = ['state' => 'leave', 'tags' => [['text' => 'On leave', 'tone' => 'danger']], 'title' => 'Leave' . ($reasonText !== '' && $reasonText !== 'N/A' ? ': ' . $reasonText : '')];
            } elseif ($isPendingLeave) {
                $calendarDays[$dateStr] = ['state' => 'pending', 'tags' => [['text' => 'Leave pending', 'tone' => 'warn']]];
            } elseif ($isAvailable && $dateStr < date('Y-m-d')) {
                $calendarDays[$dateStr] = ['state' => 'past', 'title' => 'Worked · ' . $workHours[$weekday]];
            } elseif ($isAvailable) {
                $calendarDays[$dateStr] = ['state' => 'available', 'mark' => 'info', 'title' => 'Working · ' . $workHours[$weekday]];
            } else {
                $calendarDays[$dateStr] = ['state' => 'unavailable', 'title' => 'Day off'];
            }
        }
        ?>
        <div id="doctorScheduleCalendar" class="ds-calendar"></div>
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
                    { tone: 'info', label: 'Working day' },
                    { tone: 'danger', label: 'On leave' },
                    { tone: 'warn', label: 'Leave pending' },
                    { tone: 'muted', label: 'Day off / closed' }
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
/* ---- My Schedule ---- */
.ds-summary { display: grid; grid-template-columns: minmax(0, 1fr) minmax(0, 1fr) minmax(0, 1.6fr); gap: 14px; align-items: stretch; margin-bottom: 14px; }
.ds-summary__item { display: flex; align-items: center; gap: 12px; background: #f8fbfd; border: 1px solid #e5eef4; border-radius: 14px; padding: 14px 16px; min-width: 0; }
.ds-summary__icon { flex: 0 0 44px; height: 44px; border-radius: 12px; display: flex; align-items: center; justify-content: center; background: #1b7aa3; color: #fff; font-size: 1.2rem; }
.ds-summary__label { display: block; font-size: 0.7rem; font-weight: 700; text-transform: uppercase; letter-spacing: .05em; color: #94a3b8; }
.ds-summary__value { display: block; font-size: 1.15rem; font-weight: 700; color: #0f172a; line-height: 1.25; }
.ds-summary__sub { display: block; font-size: 0.8rem; color: #64748b; }

.ds-week { list-style: none; margin: 0; padding: 0; display: grid; grid-template-columns: repeat(7, minmax(0, 1fr)); gap: 6px; }
.ds-week__day { display: flex; flex-direction: column; align-items: center; justify-content: center; gap: 3px; border-radius: 12px; padding: 8px 4px; border: 1px solid #e5eaf0; background: #f8fafc; color: #94a3b8; text-align: center; min-width: 0; }
.ds-week__name { font-size: 0.78rem; font-weight: 700; text-transform: uppercase; letter-spacing: .04em; }
.ds-week__mark { width: 24px; height: 24px; border-radius: 999px; display: flex; align-items: center; justify-content: center; background: #e2e8f0; font-size: 0.85rem; }
.ds-week__note { font-size: 0.68rem; font-weight: 600; line-height: 1.2; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; max-width: 100%; }
.ds-week__day.is-work { background: #eff8fb; border-color: #a9d8ea; color: #17698c; }
.ds-week__day.is-work .ds-week__mark { background: #1b7aa3; color: #fff; }
.ds-week__day.is-closed .ds-week__note { color: #b91c1c; }
.ds-week__day.is-today { box-shadow: 0 0 0 2px #1b7aa3; }

.ds-stats { display: grid; grid-template-columns: repeat(3, minmax(0, 1fr)); gap: 10px; margin-bottom: 16px; }
.ds-stat { display: flex; align-items: center; gap: 10px; border-radius: 12px; padding: 10px 14px; border: 1px solid transparent; }
.ds-stat strong { font-size: 1.35rem; font-weight: 800; line-height: 1; font-variant-numeric: tabular-nums; }
.ds-stat span { font-size: 0.82rem; font-weight: 600; }
.ds-stat--work { background: #eff8fb; border-color: #cfe7f1; color: #17698c; }
.ds-stat--leave { background: #fff1f2; border-color: #fecdd3; color: #b91c1c; }
.ds-stat--pending { background: #fffbeb; border-color: #fde68a; color: #92400e; }

/* Working days: a soft tint and teal date instead of a time label in every cell. */
.ds-calendar .dcal-day.is-available { background: #f2f9fc; }
.ds-calendar .dcal-day.is-available .dcal-num { color: #17698c; font-weight: 700; }
.ds-calendar .dcal-day.is-past { background: #fff; }
.ds-calendar .dcal-day.is-closed,
.ds-calendar .dcal-day.is-unavailable { background: repeating-linear-gradient(135deg, #f8fafc 0 6px, #f1f5f9 6px 12px); }

@media (max-width: 1199.98px) {
    .ds-summary { grid-template-columns: minmax(0, 1fr) minmax(0, 1fr); }
    .ds-week { grid-column: 1 / -1; }
}
@media (max-width: 575.98px) {
    .ds-summary { grid-template-columns: minmax(0, 1fr); }
    .ds-week { gap: 4px; }
    .ds-week__note { display: none; }
    .ds-stats { grid-template-columns: minmax(0, 1fr); }
}
</style>
