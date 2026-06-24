<?php
include dirname(dirname(__DIR__)) . '/config/database.php';
include dirname(dirname(__DIR__)) . '/config/security.php';
include_once dirname(dirname(__DIR__)) . '/config/session_check.php';
check_session(['secretary']);

security_start_session();
$csrf_token = security_generate_csrf_token();
security_ensure_work_type_column($conn);

if (!function_exists('secretary_doctor_has_schedule_on_weekday')) {
    function secretary_doctor_has_schedule_on_weekday($doctor, $availabilityByDoctor, $weekday) {
        $weekday = (int)$weekday;
        if ($weekday === 0) return false;
        $workType = strtolower((string)($doctor['work_type'] ?? 'regular'));
        if ($workType !== 'part_time') return true;
        $uid = (int)($doctor['user_id'] ?? 0);
        return !empty($availabilityByDoctor[$uid][$weekday]);
    }
}

$message = '';
$messageType = 'info';
$secretaryId = (int)($_SESSION['user_id'] ?? 0);

$selectedMonth = trim((string)($_GET['month'] ?? date('Y-m')));
if (!preg_match('/^\d{4}-\d{2}$/', $selectedMonth)) $selectedMonth = date('Y-m');
$monthStart = $selectedMonth . '-01';
$monthEnd = date('Y-m-t', strtotime($monthStart));

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
                    $check = $conn->prepare("SELECT leave_id FROM doctor_leaves WHERE user_id = ? AND status IN ('pending','approved') AND NOT (leave_end_date < ? OR leave_start_date > ?) LIMIT 1");
                    if ($check) {
                        $check->bind_param('iss', $doctorId, $startDate, $endDate);
                        $check->execute();
                        $exists = $check->get_result()->fetch_assoc();
                        $check->close();
                        if ($exists) {
                            $message = 'This doctor already has a pending or approved leave overlapping that range.';
                            $messageType = 'warning';
                        } else {
                            $reason = $reasonInput;
                            $ins = $conn->prepare('INSERT INTO doctor_leaves (user_id, leave_start_date, leave_end_date, reason, status) VALUES (?, ?, ?, ?, "pending")');
                            if ($ins) {
                                $ins->bind_param('isss', $doctorId, $startDate, $endDate, $reason);
                                $ins->execute();
                                $ins->close();
                                $message = 'Leave request submitted for admin approval.';
                                $messageType = 'success';
                            } else {
                                $message = 'Failed to submit leave request.';
                                $messageType = 'danger';
                            }
                        }
                    }
                }
            }
        }
    }
}

$doctors = [];
$docRes = $conn->query("SELECT user_id, first_name, last_name, CONCAT(first_name, ' ', last_name) AS display_name, work_type FROM users WHERE role_id = 2 AND is_active = 1 ORDER BY last_name, first_name");
if ($docRes) {
    while ($dr = $docRes->fetch_assoc()) $doctors[] = $dr;
}
$doctorMap = [];
foreach ($doctors as $d) $doctorMap[(int)$d['user_id']] = $d;

$availabilityByDoctor = [];
$availRes = $conn->query("SELECT user_id, weekday, start_time, end_time FROM doctor_availability WHERE status = 'active'");
if ($availRes) {
    while ($ar = $availRes->fetch_assoc()) {
        $uid = (int)$ar['user_id'];
        $wd = (int)$ar['weekday'];
        $availabilityByDoctor[$uid][$wd] = [
            'start' => (string)$ar['start_time'],
            'end' => (string)$ar['end_time'],
        ];
    }
}

$approvedLeaveByDateDoctor = [];
$pendingLeaveByDateDoctor = [];
$leaveDetailsByDateDoctor = [];

$leaveRes = $conn->query("SELECT dl.user_id, dl.leave_start_date, dl.leave_end_date, dl.reason, dl.status, u.first_name, u.last_name
                          FROM doctor_leaves dl INNER JOIN users u ON u.user_id = dl.user_id
                          WHERE u.role_id = 2 AND dl.status IN ('pending','approved') AND dl.leave_end_date >= '$monthStart' AND dl.leave_start_date <= '$monthEnd'");
if ($leaveRes) {
    while ($lv = $leaveRes->fetch_assoc()) {
        $uid = (int)$lv['user_id'];
        $status = (string)$lv['status'];
        $start = new DateTime((string)$lv['leave_start_date']);
        $end = new DateTime((string)$lv['leave_end_date']);
        $cursor = clone $start;
        while ($cursor <= $end) {
            $d = $cursor->format('Y-m-d');
            if ($d >= $monthStart && $d <= $monthEnd) {
                if ($status === 'approved') $approvedLeaveByDateDoctor[$d][$uid] = true;
                else $pendingLeaveByDateDoctor[$d][$uid] = true;
                $leaveDetailsByDateDoctor[$d][$uid][] = [
                    'status' => $status,
                    'start' => $lv['leave_start_date'],
                    'end' => $lv['leave_end_date'],
                    'reason' => trim((string)($lv['reason'] ?? '')),
                    'name' => trim((string)($lv['first_name'] . ' ' . $lv['last_name'])),
                ];
            }
            $cursor->modify('+1 day');
        }
    }
}

$firstWeekday = (int)date('w', strtotime($monthStart));
$daysInMonth = (int)date('t', strtotime($monthStart));
$monthLabel = date('F Y', strtotime($monthStart));
$today = date('Y-m-d');

$recentLeaves = [];
$recentStmt = $conn->prepare("SELECT dl.leave_id, dl.user_id, dl.leave_start_date, dl.leave_end_date, dl.reason, dl.status, dl.created_at, u.first_name, u.last_name
                              FROM doctor_leaves dl INNER JOIN users u ON u.user_id = dl.user_id WHERE u.role_id = 2 ORDER BY dl.created_at DESC LIMIT 10");
if ($recentStmt) {
    $recentStmt->execute();
    $rr = $recentStmt->get_result();
    while ($r = $rr->fetch_assoc()) $recentLeaves[] = $r;
    $recentStmt->close();
}
?>

<div class="d-flex flex-wrap justify-content-between align-items-center mb-3 gap-2">
    <h5 class="mb-0"><i class="bi bi-calendar2-check me-2"></i>Doctor Schedule</h5>
    <span class="text-muted small">Click a doctor name to view their hours</span>
</div>

<?php if ($message !== ''): ?>
    <div class="alert alert-<?php echo htmlspecialchars($messageType); ?> alert-dismissible fade show" role="alert">
        <?php echo htmlspecialchars($message); ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
<?php endif; ?>

<div class="row g-3">
    <div class="col-lg-8">
        <form class="card border-0 mb-3" method="GET" action="secretary.php">
            <div class="card-body row g-2 align-items-end">
                <input type="hidden" name="page" value="doctor_schedule">
                <div class="col-md-4">
                    <label class="form-label small fw-semibold">Month</label>
                    <input type="month" class="form-control" name="month" value="<?php echo htmlspecialchars($selectedMonth); ?>">
                </div>
                <div class="col-md-4">
                    <label class="form-label small fw-semibold">Filter by Doctor</label>
                    <select name="doctor_id" class="form-select">
                        <option value="0">All Doctors</option>
                        <?php foreach ($doctors as $doc): ?>
                            <option value="<?php echo (int)$doc['user_id']; ?>" <?php echo (int)($_GET['doctor_id'] ?? 0) === (int)$doc['user_id'] ? 'selected' : ''; ?>>
                                Dr. <?php echo htmlspecialchars((string)$doc['last_name']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-4 d-flex gap-2">
                    <button class="btn btn-primary" type="submit"><i class="bi bi-funnel"></i> Filter</button>
                    <a class="btn btn-outline-secondary" href="secretary.php?page=doctor_schedule">Reset</a>
                </div>
            </div>
        </form>

        <div class="card border-0 mb-3">
            <div class="card-header d-flex justify-content-between align-items-center">
                <strong><?php echo htmlspecialchars($monthLabel); ?></strong>
                <span class="text-muted small">
                    <?php
                    $filteredDoc = (int)($_GET['doctor_id'] ?? 0);
                    echo $filteredDoc > 0 && isset($doctorMap[$filteredDoc])
                        ? 'Dr. ' . htmlspecialchars((string)$doctorMap[$filteredDoc]['last_name'])
                        : count($doctors) . ' doctors';
                    ?>
                </span>
            </div>
            <div class="card-body p-2">
                <table class="table table-bordered dr-schedule-table mb-0" data-skip-table-search="1">
                    <thead>
                        <tr>
                            <?php foreach (['Sun','Mon','Tue','Wed','Thu','Fri','Sat'] as $day): ?>
                                <th><?php echo $day; ?></th>
                            <?php endforeach; ?>
                        </tr>
                    </thead>
                    <tbody>
                        <?php
                        $dayNum = 1;
                        $started = false;
                        while ($dayNum <= $daysInMonth):
                            echo '<tr>';
                            for ($w = 0; $w < 7; $w++):
                                if (!$started && $w === $firstWeekday) $started = true;
                                if (!$started || $dayNum > $daysInMonth) {
                                    echo '<td class="dr-cell dr-cell-empty"></td>';
                                    continue;
                                }
                                $dateStr = $selectedMonth . '-' . str_pad((string)$dayNum, 2, '0', STR_PAD_LEFT);
                                $weekday = (int)date('w', strtotime($dateStr));
                                $isToday = ($dateStr === $today);
                                $isClosed = ($weekday === 0);

                                echo '<td class="dr-cell' . ($isToday ? ' dr-cell-today' : '') . ($isClosed ? ' dr-cell-closed' : '') . '">';
                                echo '<div class="dr-day-num">' . (int)$dayNum;
                                if ($isToday) echo '<span class="dr-today-dot"></span>';
                                echo '</div>';
                                echo '<div class="dr-doctors-list">';

                                if ($isClosed) {
                                    echo '<span class="dr-chip dr-chip-closed">Closed</span>';
                                } else {
                                    $filteredDoc = (int)($_GET['doctor_id'] ?? 0);
                                    foreach ($doctors as $doc) {
                                        $uid = (int)$doc['user_id'];
                                        if ($filteredDoc > 0 && $uid !== $filteredDoc) continue;

                                        $isApproved = !empty($approvedLeaveByDateDoctor[$dateStr][$uid]);
                                        $isPending = !empty($pendingLeaveByDateDoctor[$dateStr][$uid]);
                                        $hasSched = secretary_doctor_has_schedule_on_weekday($doc, $availabilityByDoctor, $weekday);

                                        if ($isApproved) {
                                            $reason = '';
                                            if (!empty($leaveDetailsByDateDoctor[$dateStr][$uid])) {
                                                $reason = trim((string)($leaveDetailsByDateDoctor[$dateStr][$uid][0]['reason'] ?? ''));
                                                $reason = preg_replace('/^\[Filed by Secretary.*?\]\s*/', '', $reason);
                                            }
                                            echo '<span class="dr-chip dr-chip-leave" data-doctor-id="' . $uid . '" data-date="' . htmlspecialchars($dateStr) . '" title="On Leave' . ($reason !== '' ? ': ' . htmlspecialchars($reason) : '') . '">';
                                            echo htmlspecialchars(trim((string)$doc['last_name']));
                                            echo '</span>';
                                        } elseif ($hasSched) {
                                            $times = $availabilityByDoctor[$uid][$weekday] ?? null;
                                            $workType = strtolower((string)$doc['work_type']);
                                            if ($times) {
                                                $hours = date('g:i A', strtotime($times['start'])) . ' - ' . date('g:i A', strtotime($times['end']));
                                            } elseif ($workType === 'part_time') {
                                                $hours = '';
                                            } else {
                                                $hours = '8:00 AM - 5:00 PM';
                                            }
                                            echo '<span class="dr-chip dr-chip-available" data-doctor-id="' . $uid . '" data-date="' . htmlspecialchars($dateStr) . '" data-hours="' . htmlspecialchars($hours) . '" data-work-type="' . htmlspecialchars((string)$doc['work_type']) . '">';
                                            echo htmlspecialchars(trim((string)$doc['last_name']));
                                            echo '</span>';
                                        }
                                    }
                                }
                                echo '</div></td>';
                                $dayNum++;
                            endfor;
                            echo '</tr>';
                        endwhile;
                        ?>
                    </tbody>
                </table>
            </div>
        </div>

        <div class="card border-0 mb-3">
            <div class="card-body py-2">
                <div class="d-flex flex-wrap gap-3 align-items-center small">
                    <strong>Legend:</strong>
                    <span class="dr-legend-item"><span class="dr-legend-dot" style="background:#dcfce7;border:1px solid #86efac;"></span> Available</span>
                    <span class="dr-legend-item"><span class="dr-legend-dot" style="background:#fee2e2;border:1px solid #fca5a5;"></span> On Leave</span>
                    <span class="dr-legend-item"><span class="dr-legend-dot" style="background:#fef3c7;border:1px solid #fcd34d;"></span> Pending Leave</span>
                    <span class="dr-legend-item"><span class="dr-legend-dot" style="background:#f1f5f9;border:1px solid #cbd5e1;"></span> Closed</span>
                </div>
            </div>
        </div>
    </div>

    <div class="col-lg-4">
        <div class="card border-0 mb-3">
            <div class="card-header"><strong><i class="bi bi-person-plus me-1"></i>File Leave Request</strong></div>
            <div class="card-body">
                <form method="POST">
                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">
                    <input type="hidden" name="action" value="request_leave_for_doctor">
                    <div class="mb-2">
                        <label class="form-label small">Doctor</label>
                        <select class="form-select" name="doctor_id" required>
                            <option value="">Select doctor</option>
                            <?php foreach ($doctors as $doc): ?>
                                <option value="<?php echo (int)$doc['user_id']; ?>">
                                    Dr. <?php echo htmlspecialchars((string)$doc['last_name']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="row g-2 mb-2">
                        <div class="col-6">
                            <label class="form-label small">Start Date</label>
                            <input type="date" class="form-control" name="leave_start_date" min="<?php echo $today; ?>" required>
                        </div>
                        <div class="col-6">
                            <label class="form-label small">End Date</label>
                            <input type="date" class="form-control" name="leave_end_date" min="<?php echo $today; ?>" required>
                        </div>
                    </div>
                    <div class="mb-3">
                        <label class="form-label small">Reason</label>
                        <textarea class="form-control" name="reason" rows="2" placeholder="Optional reason"></textarea>
                    </div>
                    <button type="submit" class="btn btn-primary w-100"><i class="bi bi-send"></i> Submit Request</button>
                </form>
            </div>
        </div>

        <div class="card border-0">
            <div class="card-header"><strong><i class="bi bi-clock-history me-1"></i>Recent Leave Requests</strong></div>
            <div class="card-body" style="max-height:340px;overflow-y:auto;">
                <?php if (empty($recentLeaves)): ?>
                    <div class="text-muted small">No leave requests yet.</div>
                <?php else: ?>
                    <?php foreach ($recentLeaves as $lv):
                        $status = strtolower((string)$lv['status']);
                        $badge = match($status) { 'approved' => 'success', 'pending' => 'warning', 'rejected' => 'danger', default => 'secondary' };
                        $displayReason = trim((string)($lv['reason'] ?? ''));
                        $isFiled = (bool)preg_match('/Filed by Secretary/i', $displayReason);
                        $cleanReason = preg_replace('/^\[Filed by Secretary.*?\]\s*/', '', $displayReason);
                    ?>
                        <div class="border-bottom pb-2 mb-2">
                            <div class="d-flex justify-content-between align-items-center">
                                <strong class="small">Dr. <?php echo htmlspecialchars((string)$lv['last_name']); ?></strong>
                                <span class="badge bg-<?php echo $badge; ?>"><?php echo htmlspecialchars(ucfirst($status)); ?></span>
                            </div>
                            <div class="text-muted small"><?php echo htmlspecialchars($lv['leave_start_date']); ?> to <?php echo htmlspecialchars($lv['leave_end_date']); ?></div>
                            <?php if ($isFiled): ?>
                                <div class="small text-success fw-semibold">Filed by Secretary</div>
                            <?php endif; ?>
                            <?php if ($cleanReason !== ''): ?>
                                <div class="small text-muted"><?php echo htmlspecialchars($cleanReason); ?></div>
                            <?php endif; ?>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<!-- Doctor Hours Modal -->
<div class="modal fade" id="doctorHoursModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered modal-sm">
        <div class="modal-content">
            <div class="modal-header py-2">
                <h6 class="modal-title" id="doctorHoursTitle">Doctor Hours</h6>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body text-center">
                <div id="doctorHoursDate" class="text-muted small mb-2"></div>
                <div id="doctorHoursName" class="fw-bold mb-2" style="font-size:1.1rem;"></div>
                <div id="doctorHoursTime" class="mb-2"></div>
                <div id="doctorHoursType" class="small text-muted"></div>
            </div>
        </div>
    </div>
</div>

<style>
.dr-schedule-table th {
    text-align: center;
    background: #f8fafc;
    font-size: 0.78rem;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: 0.04em;
    color: #64748b;
    padding: 8px 4px;
}
.dr-cell {
    vertical-align: top;
    padding: 6px;
    min-height: 90px;
    background: #fff;
    transition: background 0.15s ease;
}
.dr-cell-today {
    background: #f0f7ff !important;
    box-shadow: inset 0 0 0 2px #1173c1;
}
.dr-cell-closed {
    background: #fafafa;
}
.dr-cell-empty {
    background: #f9fafb;
}
.dr-day-num {
    font-weight: 700;
    font-size: 0.88rem;
    color: #1e293b;
    margin-bottom: 4px;
    display: flex;
    align-items: center;
    gap: 4px;
}
.dr-today-dot {
    width: 6px;
    height: 6px;
    border-radius: 50%;
    background: #1173c1;
    display: inline-block;
}
.dr-doctors-list {
    display: flex;
    flex-wrap: wrap;
    gap: 3px;
}
.dr-chip {
    display: inline-block;
    padding: 2px 8px;
    border-radius: 999px;
    font-size: 0.68rem;
    font-weight: 700;
    line-height: 1.4;
    white-space: nowrap;
    cursor: pointer;
    transition: transform 0.12s ease, box-shadow 0.12s ease;
    letter-spacing: 0.01em;
}
.dr-chip:hover {
    transform: translateY(-1px);
    box-shadow: 0 2px 6px rgba(0,0,0,0.1);
}
.dr-chip-available {
    background: #dcfce7;
    color: #166534;
}
.dr-chip-available:hover {
    background: #bbf7d0;
}
.dr-chip-leave {
    background: #fee2e2;
    color: #991b1b;
}
.dr-chip-leave:hover {
    background: #fecaca;
}
.dr-chip-pending {
    background: #fef3c7;
    color: #92400e;
}
.dr-chip-pending:hover {
    background: #fde68a;
}
.dr-chip-closed {
    background: #f1f5f9;
    color: #94a3b8;
    cursor: default;
}
.dr-legend-item {
    display: inline-flex;
    align-items: center;
    gap: 5px;
    color: #475569;
}
.dr-legend-dot {
    width: 10px;
    height: 10px;
    border-radius: 999px;
    display: inline-block;
}
@media (max-width: 768px) {
    .dr-cell { min-height: 68px; padding: 4px; }
    .dr-day-num { font-size: 0.8rem; }
    .dr-chip { font-size: 0.62rem; padding: 1px 5px; }
}
</style>

<script>
document.addEventListener('DOMContentLoaded', function() {
    var modal = document.getElementById('doctorHoursModal');
    if (!modal) return;
    var bsModal = null;

    document.querySelectorAll('.dr-chip-available, .dr-chip-leave, .dr-chip-pending').forEach(function(chip) {
        chip.addEventListener('click', function() {
            var doctorId = parseInt(this.dataset.doctorId || '0');
            var date = this.dataset.date || '';
            var hours = this.dataset.hours || '';
            var workType = this.dataset.workType || 'regular';
            var name = this.textContent.trim();
            var isLeave = this.classList.contains('dr-chip-leave');
            var isPending = this.classList.contains('dr-chip-pending');

            document.getElementById('doctorHoursTitle').textContent = 'Dr. ' + name;
            document.getElementById('doctorHoursDate').textContent = date ? new Date(date + 'T00:00:00').toLocaleDateString('en-US', { weekday: 'long', month: 'long', day: 'numeric', year: 'numeric' }) : '';

            if (isLeave) {
                document.getElementById('doctorHoursTime').innerHTML = '<span class="badge bg-danger" style="font-size:0.9rem;padding:6px 14px;">On Leave</span>';
                document.getElementById('doctorHoursType').textContent = 'Not available for appointments';
            } else if (isPending) {
                document.getElementById('doctorHoursTime').innerHTML = '<span class="badge bg-warning text-dark" style="font-size:0.9rem;padding:6px 14px;">Pending Leave</span>';
                document.getElementById('doctorHoursType').textContent = 'Leave request under review';
            } else if (hours) {
                document.getElementById('doctorHoursTime').innerHTML = '<div style="font-size:1.3rem;font-weight:700;color:#1173c1;">' + hours + '</div>';
                document.getElementById('doctorHoursType').textContent = workType === 'part_time' ? 'Part-time schedule' : 'Regular schedule';
            } else {
                document.getElementById('doctorHoursTime').innerHTML = '<div style="font-size:1.3rem;font-weight:700;color:#1173c1;">8:00 AM - 5:00 PM</div>';
                document.getElementById('doctorHoursType').textContent = 'Regular schedule';
            }

            if (!bsModal) bsModal = new bootstrap.Modal(modal);
            bsModal.show();
        });
    });
});
</script>
