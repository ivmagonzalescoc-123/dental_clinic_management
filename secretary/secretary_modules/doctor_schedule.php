<?php
include dirname(dirname(__DIR__)) . '/config/database.php';
include dirname(dirname(__DIR__)) . '/config/security.php';
include_once dirname(dirname(__DIR__)) . '/config/session_check.php';
check_session(['secretary']);

security_start_session();
$csrf_token = security_generate_csrf_token();
security_ensure_work_type_column($conn);

if (!function_exists('secretary_doctor_has_schedule_on_weekday')) {
    function secretary_doctor_has_schedule_on_weekday($doctor, $availabilityByDoctor, $weekday)
    {
        $weekday = (int)$weekday;
        if ($weekday === 0) {
            return false;
        }

        $workType = strtolower((string)($doctor['work_type'] ?? 'regular'));
        if ($workType !== 'part_time') {
            // Regular doctors are available Monday-Saturday.
            return true;
        }

        $uid = (int)($doctor['user_id'] ?? 0);
        return !empty($availabilityByDoctor[$uid][$weekday]);
    }
}

$message = '';
$messageType = 'info';
$secretaryId = (int)($_SESSION['user_id'] ?? 0);

$selectedMonth = trim((string)($_GET['month'] ?? date('Y-m')));
if (!preg_match('/^\d{4}-\d{2}$/', $selectedMonth)) {
    $selectedMonth = date('Y-m');
}
$monthStart = $selectedMonth . '-01';
$monthEnd = date('Y-m-t', strtotime($monthStart));

$selectedDoctor = (int)($_GET['doctor_id'] ?? 0);
$viewFilter = trim((string)($_GET['view_filter'] ?? 'all'));
$allowedViewFilter = ['all', 'leave', 'available'];
if (!in_array($viewFilter, $allowedViewFilter, true)) {
    $viewFilter = 'all';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $csrf = $_POST['csrf_token'] ?? '';
    if (!security_validate_csrf_token($csrf)) {
        $message = 'Security token mismatch. Please refresh and try again.';
        $messageType = 'danger';
    } else {
        $action = $_POST['action'] ?? '';
        if ($action === 'request_leave_for_doctor') {
            $doctorId = (int)($_POST['doctor_id'] ?? 0);
            $startDate = trim((string)($_POST['leave_start_date'] ?? ''));
            $endDate = trim((string)($_POST['leave_end_date'] ?? ''));
            $reasonInput = trim((string)($_POST['reason'] ?? ''));

            $startOk = DateTime::createFromFormat('Y-m-d', $startDate);
            $endOk = DateTime::createFromFormat('Y-m-d', $endDate);

            if ($doctorId <= 0) {
                $message = 'Please select a doctor.';
                $messageType = 'danger';
            } elseif (!$startOk || !$endOk || $startOk->format('Y-m-d') !== $startDate || $endOk->format('Y-m-d') !== $endDate) {
                $message = 'Please provide valid leave start and end dates.';
                $messageType = 'danger';
            } elseif ($startDate > $endDate) {
                $message = 'Leave start date must be before or equal to end date.';
                $messageType = 'danger';
            } elseif ($startDate < date('Y-m-d')) {
                $message = 'Leave cannot start in the past.';
                $messageType = 'danger';
            } else {
                $doctorCheck = $conn->prepare('SELECT user_id FROM users WHERE user_id = ? AND role_id = 2 LIMIT 1');
                $doctorExists = false;
                if ($doctorCheck) {
                    $doctorCheck->bind_param('i', $doctorId);
                    $doctorCheck->execute();
                    $doctorExists = (bool)$doctorCheck->get_result()->fetch_assoc();
                    $doctorCheck->close();
                }

                if (!$doctorExists) {
                    $message = 'Selected doctor does not exist.';
                    $messageType = 'danger';
                } else {
                    $check = $conn->prepare(
                        "SELECT leave_id
                         FROM doctor_leaves
                         WHERE user_id = ?
                           AND status IN ('pending', 'approved')
                           AND NOT (leave_end_date < ? OR leave_start_date > ?)
                         LIMIT 1"
                    );

                    if ($check) {
                        $check->bind_param('iss', $doctorId, $startDate, $endDate);
                        $check->execute();
                        $exists = $check->get_result()->fetch_assoc();
                        $check->close();

                        if ($exists) {
                            $message = 'This doctor already has a pending or approved leave overlapping that range.';
                            $messageType = 'warning';
                        } else {
                            if (mb_strlen($reasonInput) > 220) {
                                $reasonInput = mb_substr($reasonInput, 0, 220);
                            }
                            $reason = '[Filed by Secretary #' . $secretaryId . '] ' . $reasonInput;
                            if (mb_strlen($reason) > 255) {
                                $reason = mb_substr($reason, 0, 255);
                            }

                            $ins = $conn->prepare(
                                "INSERT INTO doctor_leaves (user_id, leave_start_date, leave_end_date, reason, status)
                                 VALUES (?, ?, ?, ?, 'pending')"
                            );
                            if ($ins) {
                                $ins->bind_param('isss', $doctorId, $startDate, $endDate, $reason);
                                if ($ins->execute()) {
                                    $message = 'Leave request submitted. Admin must approve or decline it.';
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
                        $message = 'Unable to validate overlapping leave requests.';
                        $messageType = 'danger';
                    }
                }
            }
        }
    }
}

$doctors = [];
$doctorMap = [];
$dq = $conn->query("SELECT user_id, first_name, last_name, COALESCE(work_type, 'regular') AS work_type FROM users WHERE role_id = 2 ORDER BY first_name ASC, last_name ASC");
if ($dq) {
    while ($row = $dq->fetch_assoc()) {
        $id = (int)$row['user_id'];
        $name = trim((string)($row['first_name'] ?? '') . ' ' . (string)($row['last_name'] ?? ''));
        $row['display_name'] = $name;
        $doctors[] = $row;
        $doctorMap[$id] = $row;
    }
}

$availabilityByDoctor = [];
if ($conn->query("SHOW TABLES LIKE 'doctor_availability'")->num_rows > 0) {
    $aq = $conn->query('SELECT user_id, weekday FROM doctor_availability');
    if ($aq) {
        while ($r = $aq->fetch_assoc()) {
            $uid = (int)$r['user_id'];
            $weekday = (int)$r['weekday'];
            if (!isset($availabilityByDoctor[$uid])) {
                $availabilityByDoctor[$uid] = [];
            }
            $availabilityByDoctor[$uid][$weekday] = true;
        }
    }
} elseif ($conn->query("SHOW TABLES LIKE 'schedule'")->num_rows > 0) {
    $map = [
        'sunday' => 0,
        'monday' => 1,
        'tuesday' => 2,
        'wednesday' => 3,
        'thursday' => 4,
        'friday' => 5,
        'saturday' => 6,
    ];
    $aq = $conn->query("SELECT user_id, day_of_week FROM schedule WHERE status = 'active'");
    if ($aq) {
        while ($r = $aq->fetch_assoc()) {
            $uid = (int)$r['user_id'];
            $key = strtolower((string)($r['day_of_week'] ?? ''));
            if (!isset($map[$key])) {
                continue;
            }
            if (!isset($availabilityByDoctor[$uid])) {
                $availabilityByDoctor[$uid] = [];
            }
            $availabilityByDoctor[$uid][$map[$key]] = true;
        }
    }
}

$leaveRows = [];
$leaveStmt = $conn->prepare(
    "SELECT dl.leave_id, dl.user_id, dl.leave_start_date, dl.leave_end_date, dl.reason, dl.status, dl.created_at,
            u.first_name, u.last_name
     FROM doctor_leaves dl
     INNER JOIN users u ON u.user_id = dl.user_id
     WHERE u.role_id = 2
       AND dl.leave_start_date <= ?
       AND dl.leave_end_date >= ?
     ORDER BY dl.leave_start_date ASC, dl.created_at DESC"
);
if ($leaveStmt) {
    $leaveStmt->bind_param('ss', $monthEnd, $monthStart);
    $leaveStmt->execute();
    $lr = $leaveStmt->get_result();
    while ($row = $lr->fetch_assoc()) {
        $leaveRows[] = $row;
    }
    $leaveStmt->close();
}

$doctorSummary = [];
foreach ($doctors as $d) {
    $doctorSummary[(int)$d['user_id']] = [
        'name' => (string)$d['display_name'],
        'approved_days' => 0,
        'pending_days' => 0,
    ];
}

$approvedLeaveByDateDoctor = [];
$pendingLeaveByDateDoctor = [];
$leaveDetailsByDateDoctor = [];
foreach ($leaveRows as $lv) {
    $uid = (int)$lv['user_id'];
    $status = (string)$lv['status'];

    $from = max($monthStart, (string)$lv['leave_start_date']);
    $to = min($monthEnd, (string)$lv['leave_end_date']);
    if ($from > $to) {
        continue;
    }

    $cursor = new DateTime($from);
    $end = new DateTime($to);
    while ($cursor <= $end) {
        $d = $cursor->format('Y-m-d');

        if ($status === 'approved') {
            if (!isset($approvedLeaveByDateDoctor[$d])) {
                $approvedLeaveByDateDoctor[$d] = [];
            }
            $approvedLeaveByDateDoctor[$d][$uid] = true;
            if (!isset($leaveDetailsByDateDoctor[$d])) {
                $leaveDetailsByDateDoctor[$d] = [];
            }
            if (!isset($leaveDetailsByDateDoctor[$d][$uid])) {
                $leaveDetailsByDateDoctor[$d][$uid] = [];
            }
            $leaveDetailsByDateDoctor[$d][$uid][] = [
                'status' => $status,
                'start' => (string)($lv['leave_start_date'] ?? ''),
                'end' => (string)($lv['leave_end_date'] ?? ''),
                'reason' => (string)($lv['reason'] ?? ''),
            ];
            if (isset($doctorSummary[$uid])) {
                $doctorSummary[$uid]['approved_days']++;
            }
        }

        if ($status === 'pending') {
            if (!isset($pendingLeaveByDateDoctor[$d])) {
                $pendingLeaveByDateDoctor[$d] = [];
            }
            $pendingLeaveByDateDoctor[$d][$uid] = true;
            if (isset($doctorSummary[$uid])) {
                $doctorSummary[$uid]['pending_days']++;
            }
        }

        $cursor->modify('+1 day');
    }
}

$calendarStats = [];
$monthDate = new DateTime($monthStart);
$calendarEnd = new DateTime($monthEnd);
while ($monthDate <= $calendarEnd) {
    $d = $monthDate->format('Y-m-d');
    $weekday = (int)$monthDate->format('w');

    $approvedCount = 0;
    $pendingCount = 0;
    $availableCount = 0;
    $noScheduleCount = 0;

    foreach ($doctors as $doc) {
        $uid = (int)$doc['user_id'];
        $isApprovedLeave = !empty($approvedLeaveByDateDoctor[$d][$uid]);
        $isPendingLeave = !empty($pendingLeaveByDateDoctor[$d][$uid]);
        $hasSchedule = secretary_doctor_has_schedule_on_weekday($doc, $availabilityByDoctor, $weekday);

        if ($isApprovedLeave) {
            $approvedCount++;
            continue;
        }

        if ($isPendingLeave) {
            $pendingCount++;
        }

        if ($hasSchedule) {
            $availableCount++;
        } else {
            $noScheduleCount++;
        }
    }

    $calendarStats[$d] = [
        'approved_leave_count' => $approvedCount,
        'pending_leave_count' => $pendingCount,
        'available_count' => $availableCount,
        'no_schedule_count' => $noScheduleCount,
    ];

    $monthDate->modify('+1 day');
}

$recentLeaves = [];
$recentStmt = $conn->prepare(
    "SELECT dl.leave_id, dl.user_id, dl.leave_start_date, dl.leave_end_date, dl.reason, dl.status, dl.created_at,
            u.first_name, u.last_name
     FROM doctor_leaves dl
     INNER JOIN users u ON u.user_id = dl.user_id
     WHERE u.role_id = 2
     ORDER BY dl.created_at DESC
     LIMIT 20"
);
if ($recentStmt) {
    $recentStmt->execute();
    $rr = $recentStmt->get_result();
    while ($r = $rr->fetch_assoc()) {
        $recentLeaves[] = $r;
    }
    $recentStmt->close();
}

$firstWeekday = (int)date('w', strtotime($monthStart));
$daysInMonth = (int)date('t', strtotime($monthStart));
$monthLabel = date('F Y', strtotime($monthStart));

if (!function_exists('secretary_leave_popover_html')) {
    function secretary_leave_popover_html(array $items, $doctorName)
    {
        if (empty($items)) {
            return '';
        }

        $parts = [];
        foreach ($items as $item) {
            $status = ucfirst((string)($item['status'] ?? 'leave'));
            $start = (string)($item['start'] ?? '');
            $end = (string)($item['end'] ?? '');
            $reason = trim((string)($item['reason'] ?? ''));
            $line = $status . ': ' . $start . ' to ' . $end;
            if ($reason !== '') {
                $line .= ' (' . $reason . ')';
            }
            $parts[] = $line;
        }

        return htmlspecialchars($doctorName . "\n" . implode("\n", $parts), ENT_QUOTES, 'UTF-8');
    }
}

?>

<div class="d-flex flex-wrap justify-content-between align-items-center mb-3 gap-2">
    <h5 class="mb-0"><i class="bi bi-calendar2-check me-2"></i>Doctor Availability Calendar</h5>
    <span class="text-muted small">Secretaries can monitor doctor schedules and submit leave requests for admin approval.</span>
</div>

<?php if ($message !== ''): ?>
    <div class="alert alert-<?php echo htmlspecialchars($messageType); ?>"><?php echo htmlspecialchars($message); ?></div>
<?php endif; ?>

<div class="row g-3">
    <div class="col-lg-8">
        <form class="card border-0 mb-3" method="GET" action="secretary.php">
            <div class="card-body row g-2 align-items-end">
                <input type="hidden" name="page" value="doctor_schedule">
                <div class="col-md-3">
                    <label class="form-label">Month</label>
                    <input type="month" class="form-control" name="month" value="<?php echo htmlspecialchars($selectedMonth); ?>">
                </div>
                <div class="col-md-4">
                    <label class="form-label">Doctor Focus</label>
                    <select name="doctor_id" class="form-select">
                        <option value="0">All Doctors</option>
                        <?php foreach ($doctors as $doc): ?>
                            <?php $did = (int)$doc['user_id']; ?>
                            <option value="<?php echo $did; ?>" <?php echo $selectedDoctor === $did ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars((string)$doc['display_name']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-3">
                    <label class="form-label">Day Filter</label>
                    <select name="view_filter" class="form-select">
                        <option value="all" <?php echo $viewFilter === 'all' ? 'selected' : ''; ?>>All Days</option>
                        <option value="leave" <?php echo $viewFilter === 'leave' ? 'selected' : ''; ?>>Leave Days</option>
                        <option value="available" <?php echo $viewFilter === 'available' ? 'selected' : ''; ?>>Available Days</option>
                    </select>
                </div>
                <div class="col-md-2 d-flex gap-2">
                    <button class="btn btn-primary w-100" type="submit"><i class="bi bi-funnel"></i></button>
                    <a class="btn btn-outline-secondary w-100" href="secretary.php?page=doctor_schedule">Reset</a>
                </div>
            </div>
        </form>

        <div class="card border-0 mb-3">
            <div class="card-header d-flex justify-content-between align-items-center">
                <strong><?php echo htmlspecialchars($monthLabel); ?></strong>
                <div class="small text-muted">
                    <?php if ($selectedDoctor > 0 && isset($doctorMap[$selectedDoctor])): ?>
                        Focus: <?php echo htmlspecialchars((string)$doctorMap[$selectedDoctor]['display_name']); ?>
                    <?php else: ?>
                        Showing all doctors
                    <?php endif; ?>
                </div>
            </div>
            <div class="card-body p-2">
                <div class="table-responsive">
                    <table class="table table-bordered calendar-table mb-0">
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
                                    $isClinicClosed = ($weekday === 0); // Sunday
                                    $stats = $calendarStats[$dateStr] ?? [
                                        'approved_leave_count' => 0,
                                        'pending_leave_count' => 0,
                                        'available_count' => 0,
                                        'no_schedule_count' => 0,
                                    ];

                                    $cellClass = 'calendar-cell';
                                    if ($selectedDoctor > 0) {
                                        $selectedDoctorData = $doctorMap[$selectedDoctor] ?? ['user_id' => $selectedDoctor, 'work_type' => 'regular'];
                                        $isLeave = !empty($approvedLeaveByDateDoctor[$dateStr][$selectedDoctor]) || !empty($pendingLeaveByDateDoctor[$dateStr][$selectedDoctor]);
                                        $hasSched = secretary_doctor_has_schedule_on_weekday($selectedDoctorData, $availabilityByDoctor, $weekday);
                                        if ($isClinicClosed) {
                                            $cellClass .= ' cell-closed';
                                        } elseif ($isLeave) {
                                            $cellClass .= ' cell-leave';
                                        } elseif ($hasSched) {
                                            $cellClass .= ' cell-available';
                                        }

                                        if ($viewFilter === 'leave' && !$isLeave) {
                                            $cellClass .= ' cell-muted';
                                        }
                                        if ($viewFilter === 'available' && ($isLeave || !$hasSched)) {
                                            $cellClass .= ' cell-muted';
                                        }
                                    }

                                    echo '<td class="' . htmlspecialchars($cellClass) . '">';
                                    echo '<div class="calendar-day">' . (int)$dayNum . '</div>';
                                    echo '<div class="calendar-marks">';
                                    if ($selectedDoctor > 0) {
                                        $docName = 'Dr. ' . trim((string)($doctorMap[$selectedDoctor]['last_name'] ?? $doctorMap[$selectedDoctor]['first_name'] ?? 'Doctor'));
                                        $isApproved = !empty($approvedLeaveByDateDoctor[$dateStr][$selectedDoctor]);
                                        $hasSched = secretary_doctor_has_schedule_on_weekday($doctorMap[$selectedDoctor] ?? [], $availabilityByDoctor, $weekday);

                                        if ($isClinicClosed) {
                                            echo '<div class="mark mark-closed">Clinic Closed</div>';
                                        } elseif ($isApproved) {
                                            $popover = secretary_leave_popover_html($leaveDetailsByDateDoctor[$dateStr][$selectedDoctor] ?? [], $docName);
                                            echo '<button type="button" class="mark mark-leave mark-btn" data-bs-toggle="popover" data-bs-trigger="focus" data-bs-placement="top" data-bs-custom-class="leave-popover" data-bs-content="' . $popover . '">' . htmlspecialchars($docName) . '</button>';
                                        } elseif ($hasSched) {
                                            echo '<div class="mark mark-present">' . htmlspecialchars($docName) . '</div>';
                                        }
                                    } else {
                                        if ($isClinicClosed) {
                                            echo '<div class="mark mark-closed">Clinic Closed</div>';
                                        } else {
                                            $leaveDoctors = [];
                                            $presentCount = 0;
                                            foreach ($doctors as $doc) {
                                                $uid = (int)$doc['user_id'];
                                                $isApprovedLeave = !empty($approvedLeaveByDateDoctor[$dateStr][$uid]);
                                                $hasSched = secretary_doctor_has_schedule_on_weekday($doc, $availabilityByDoctor, $weekday);
                                                if ($isApprovedLeave) {
                                                    $leaveDoctors[] = [
                                                        'uid' => $uid,
                                                        'name' => 'Dr. ' . trim((string)($doc['last_name'] ?? $doc['first_name'] ?? 'Doctor')),
                                                    ];
                                                } elseif ($hasSched) {
                                                    $presentCount++;
                                                }
                                            }

                                            if (!empty($leaveDoctors)) {
                                                $leaveSlice = array_slice($leaveDoctors, 0, 2);
                                                foreach ($leaveSlice as $leaveDoc) {
                                                    $popover = secretary_leave_popover_html($leaveDetailsByDateDoctor[$dateStr][$leaveDoc['uid']] ?? [], $leaveDoc['name']);
                                                    echo '<button type="button" class="mark mark-leave mark-btn" data-bs-toggle="popover" data-bs-trigger="focus" data-bs-placement="top" data-bs-custom-class="leave-popover" data-bs-content="' . $popover . '">' . htmlspecialchars($leaveDoc['name']) . '</button>';
                                                }
                                                $remaining = count($leaveDoctors) - count($leaveSlice);
                                                if ($remaining > 0) {
                                                    $remainingDetails = [];
                                                    foreach (array_slice($leaveDoctors, count($leaveSlice)) as $remDoc) {
                                                        $remItems = $leaveDetailsByDateDoctor[$dateStr][$remDoc['uid']] ?? [];
                                                        if (!empty($remItems)) {
                                                            $firstItem = $remItems[0];
                                                            $remainingDetails[] = $remDoc['name'] . ': ' . (string)($firstItem['start'] ?? '') . ' to ' . (string)($firstItem['end'] ?? '');
                                                        } else {
                                                            $remainingDetails[] = $remDoc['name'];
                                                        }
                                                    }
                                                    $remainingPopover = htmlspecialchars(implode("\n", $remainingDetails), ENT_QUOTES, 'UTF-8');
                                                    echo '<button type="button" class="mark mark-leave mark-btn" data-bs-toggle="popover" data-bs-trigger="focus" data-bs-placement="top" data-bs-custom-class="leave-popover" data-bs-content="' . $remainingPopover . '">+' . (int)$remaining . ' more</button>';
                                                }
                                            }
                                            if ($presentCount > 0) {
                                                echo '<div class="mark mark-present">Present ' . (int)$presentCount . '</div>';
                                            }
                                        }
                                    }
                                    echo '</div>';

                                    echo '</td>';
                                    $dayNum++;
                                endfor;
                                echo '</tr>';
                            endwhile;
                            ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <div class="card border-0 mb-3">
            <div class="card-body py-2">
                <div class="d-flex flex-wrap gap-2 align-items-center small">
                    <strong class="me-1">Legend:</strong>
                    <span class="legend-chip legend-leave">Red: Leave</span>
                    <span class="legend-chip legend-present">Green: Present/Available</span>
                    <span class="legend-chip legend-closed">Yellow: Clinic Closed</span>
                </div>
            </div>
        </div>

    </div>

    <div class="col-lg-4">
        <div class="card border-0 mb-3">
            <div class="card-header"><strong>File Leave Request for Doctor</strong></div>
            <div class="card-body">
                <form method="POST" action="secretary.php?page=doctor_schedule&month=<?php echo urlencode($selectedMonth); ?>&doctor_id=<?php echo (int)$selectedDoctor; ?>&view_filter=<?php echo urlencode($viewFilter); ?>">
                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">
                    <input type="hidden" name="action" value="request_leave_for_doctor">

                    <div class="mb-2">
                        <label class="form-label">Doctor</label>
                        <select class="form-select" name="doctor_id" required>
                            <option value="">Select doctor</option>
                            <?php foreach ($doctors as $doc): ?>
                                <?php $did = (int)$doc['user_id']; ?>
                                <option value="<?php echo $did; ?>" <?php echo $selectedDoctor === $did ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars((string)$doc['display_name']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="mb-2">
                        <label class="form-label">Start Date</label>
                        <input type="date" class="form-control" name="leave_start_date" required min="<?php echo date('Y-m-d'); ?>">
                    </div>

                    <div class="mb-2">
                        <label class="form-label">End Date</label>
                        <input type="date" class="form-control" name="leave_end_date" required min="<?php echo date('Y-m-d'); ?>">
                    </div>

                    <div class="mb-2">
                        <label class="form-label">Reason</label>
                        <textarea class="form-control" name="reason" rows="3" maxlength="220" placeholder="State why this leave is being filed."></textarea>
                    </div>

                    <button class="btn btn-primary w-100" type="submit">Submit Leave Request</button>
                    <small class="text-muted d-block mt-2">Admin approval is required before leave becomes active.</small>
                </form>
            </div>
        </div>

        <div class="card border-0">
            <div class="card-header"><strong>Recent Leave Requests</strong></div>
            <div class="card-body p-0">
                <div class="list-group list-group-flush">
                    <?php if (empty($recentLeaves)): ?>
                        <div class="p-3 text-muted small">No leave requests found.</div>
                    <?php else: ?>
                        <?php foreach ($recentLeaves as $lv): ?>
                            <?php
                                $status = (string)($lv['status'] ?? '');
                                $badge = $status === 'approved' ? 'success' : ($status === 'pending' ? 'warning text-dark' : 'secondary');
                            ?>
                            <div class="list-group-item">
                                <div class="d-flex justify-content-between align-items-center">
                                    <strong><?php echo htmlspecialchars(trim((string)($lv['first_name'] ?? '') . ' ' . (string)($lv['last_name'] ?? ''))); ?></strong>
                                    <span class="badge bg-<?php echo $badge; ?>"><?php echo htmlspecialchars(ucfirst($status)); ?></span>
                                </div>
                                <div class="small text-muted"><?php echo htmlspecialchars((string)$lv['leave_start_date']); ?> to <?php echo htmlspecialchars((string)$lv['leave_end_date']); ?></div>
                                <div class="small"><?php echo htmlspecialchars((string)($lv['reason'] ?? '')); ?></div>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
</div>

<style>
.calendar-table th {
    text-align: center;
    background: #f8fafc;
}
.calendar-cell {
    height: 112px;
    vertical-align: top;
    background: #ffffff;
}
.calendar-cell.cell-leave {
    background: #fff9fa;
}
.calendar-cell.cell-available {
    background: #f8fffb;
}
.calendar-cell.cell-closed {
    background: #fffcf2;
}
.calendar-cell.cell-muted {
    opacity: 0.35;
}
.calendar-day {
    font-weight: 700;
    font-size: 1rem;
    margin-bottom: 4px;
}
.calendar-marks {
    display: flex;
    flex-direction: column;
    gap: 4px;
}
.mark {
    display: inline-block;
    width: fit-content;
    max-width: 100%;
    border-radius: 999px;
    font-size: 0.72rem;
    padding: 2px 8px;
    line-height: 1.2;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
}
.mark-btn {
    border: none;
    cursor: pointer;
}
.mark-leave {
    background: #fee2e2;
    color: #991b1b;
}
.mark-present {
    background: #dcfce7;
    color: #166534;
}
.mark-closed {
    background: #fef3c7;
    color: #92400e;
}
.legend-chip {
    border-radius: 999px;
    padding: 4px 10px;
}
.legend-leave {
    background: #fee2e2;
    color: #991b1b;
}
.legend-present {
    background: #dcfce7;
    color: #166534;
}
.legend-closed {
    background: #fef3c7;
    color: #92400e;
}
.leave-popover .popover-body {
    white-space: pre-line;
    font-size: 0.82rem;
}
@media (max-width: 768px) {
    .calendar-cell {
        height: 88px;
    }
    .calendar-day {
        font-size: 0.9rem;
    }
    .mark {
        font-size: 0.68rem;
        padding: 2px 6px;
    }
}
</style>

<script>
document.addEventListener('DOMContentLoaded', function() {
    if (typeof bootstrap === 'undefined' || typeof bootstrap.Popover === 'undefined') {
        return;
    }
    document.querySelectorAll('[data-bs-toggle="popover"]').forEach(function(el) {
        new bootstrap.Popover(el, {
            html: false,
            sanitize: true,
            boundary: 'viewport'
        });
    });
});
</script>
