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
        $message = 'Please refresh the page and try again.';
        $messageType = 'danger';
    } else {
        $action = $_POST['action'] ?? '';
        if ($action === 'request_leave_for_doctor') {
            $doctorId = (int)($_POST['doctor_id'] ?? 0);
            $startDate = trim((string)($_POST['leave_start_date'] ?? ''));
            $endDate = trim((string)($_POST['leave_end_date'] ?? ''));
            $reasonInput = trim((string)($_POST['reason'] ?? ''));
            if ($reasonInput === '') { $reasonInput = 'N/A'; }
            $startOk = DateTime::createFromFormat('Y-m-d', $startDate);
            $endOk = DateTime::createFromFormat('Y-m-d', $endDate);
            if ($doctorId <= 0) { $message = 'Please select a doctor.'; $messageType = 'danger'; }
            elseif (!$startOk || !$endOk) { $message = 'Please provide valid dates.'; $messageType = 'danger'; }
            elseif ($startDate > $endDate) { $message = 'Start must be before end.'; $messageType = 'danger'; }
            elseif ($startDate < date('Y-m-d')) { $message = 'Cannot start in the past.'; $messageType = 'danger'; }
            else {
                $check = $conn->prepare("SELECT leave_id FROM doctor_leaves WHERE user_id = ? AND status IN ('pending','approved') AND NOT (leave_end_date < ? OR leave_start_date > ?) LIMIT 1");
                if ($check) { $check->bind_param('iss', $doctorId, $startDate, $endDate); $check->execute(); $exists = $check->get_result()->fetch_assoc(); $check->close();
                    if ($exists) { $message = 'Overlapping leave exists.'; $messageType = 'warning'; }
                    else { $ins = $conn->prepare('INSERT INTO doctor_leaves (user_id, leave_start_date, leave_end_date, reason, status) VALUES (?, ?, ?, ?, "pending")');
                        if ($ins) { $ins->bind_param('isss', $doctorId, $startDate, $endDate, $reasonInput); $ins->execute(); $ins->close(); $message = 'Leave request submitted.'; $messageType = 'success'; }
                    }
                }
            }
        }
    }
}

$doctors = [];
$docRes = $conn->query("SELECT user_id, first_name, last_name, CONCAT(first_name, ' ', last_name) AS display_name, work_type FROM users WHERE role_id = 2 AND is_active = 1 ORDER BY last_name, first_name");
if ($docRes) { while ($dr = $docRes->fetch_assoc()) $doctors[] = $dr; }

$availabilityByDoctor = [];
$availRes = $conn->query("SELECT user_id, weekday, start_time, end_time FROM doctor_availability WHERE status = 'active'");
if ($availRes) { while ($ar = $availRes->fetch_assoc()) { $availabilityByDoctor[(int)$ar['user_id']][(int)$ar['weekday']] = ['start' => (string)$ar['start_time'], 'end' => (string)$ar['end_time']]; } }

$approvedLeaveByDate = [];
$pendingLeaveByDate = [];
$leaveRes = $conn->query("SELECT dl.user_id, dl.leave_start_date, dl.leave_end_date, dl.reason, dl.status, u.first_name, u.last_name FROM doctor_leaves dl INNER JOIN users u ON u.user_id = dl.user_id WHERE u.role_id = 2 AND dl.status IN ('pending','approved') AND dl.leave_end_date >= '$monthStart' AND dl.leave_start_date <= '$monthEnd'");
if ($leaveRes) { while ($lv = $leaveRes->fetch_assoc()) { $uid = (int)$lv['user_id']; $status = (string)$lv['status']; $start = new DateTime((string)$lv['leave_start_date']); $end = new DateTime((string)$lv['leave_end_date']); $cursor = clone $start; while ($cursor <= $end) { $d = $cursor->format('Y-m-d'); if ($d >= $monthStart && $d <= $monthEnd) { if ($status === 'approved') $approvedLeaveByDate[$d][$uid] = true; else $pendingLeaveByDate[$d][$uid] = true; } $cursor->modify('+1 day'); } } }

$firstWeekday = (int)date('w', strtotime($monthStart));
$daysInMonth = (int)date('t', strtotime($monthStart));
$monthLabel = date('F Y', strtotime($monthStart));
$today = date('Y-m-d');

$dayData = [];
for ($d = 1; $d <= $daysInMonth; $d++) {
    $dateStr = $selectedMonth . '-' . str_pad((string)$d, 2, '0', STR_PAD_LEFT);
    $weekday = (int)date('w', strtotime($dateStr));
    $dayDoctors = [];
    foreach ($doctors as $doc) {
        $uid = (int)$doc['user_id'];
        $isApproved = !empty($approvedLeaveByDate[$dateStr][$uid]);
        $isPending = !empty($pendingLeaveByDate[$dateStr][$uid]);
        $hasSched = secretary_doctor_has_schedule_on_weekday($doc, $availabilityByDoctor, $weekday);
        $times = $availabilityByDoctor[$uid][$weekday] ?? null;
        $status = $isApproved ? 'leave' : ($isPending ? 'pending' : ($hasSched ? 'available' : 'none'));
        $hours = $times ? date('g:i A', strtotime($times['start'])) . ' – ' . date('g:i A', strtotime($times['end'])) : ($status === 'available' && strtolower((string)$doc['work_type']) !== 'part_time' ? '9:00 AM – 5:00 PM' : '');
        $dayDoctors[] = ['name' => 'Dr. ' . htmlspecialchars((string)$doc['last_name']), 'status' => $status, 'hours' => $hours, 'work_type' => (string)$doc['work_type']];
    }
    $dayData[$d] = $dayDoctors;
}

$recentLeaves = [];
$recentStmt = $conn->prepare("SELECT dl.leave_id, dl.leave_start_date, dl.leave_end_date, dl.reason, dl.status, dl.created_at, u.last_name FROM doctor_leaves dl INNER JOIN users u ON u.user_id = dl.user_id WHERE u.role_id = 2 ORDER BY dl.created_at DESC LIMIT 10");
if ($recentStmt) { $recentStmt->execute(); $rr = $recentStmt->get_result(); while ($r = $rr->fetch_assoc()) $recentLeaves[] = $r; $recentStmt->close(); }
?>

<?php if ($message !== ''): ?>
    <div class="alert alert-<?php echo htmlspecialchars($messageType); ?> alert-dismissible fade show" role="alert">
        <?php echo htmlspecialchars($message); ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
<?php endif; ?>

<div class="d-flex flex-wrap justify-content-between align-items-center mb-3 gap-2">
    <h5 class="mb-0"><i class="bi bi-calendar2-check me-2"></i>Doctor Schedule</h5>
</div>

<div class="row g-3">
    <div class="col-lg-8">
        <?php
        $calendarDays = [];
        for ($d = 1; $d <= $daysInMonth; $d++) {
            $dateStr = $selectedMonth . '-' . str_pad((string)$d, 2, '0', STR_PAD_LEFT);
            $weekday = (int)date('w', strtotime($dateStr));
            if ($weekday === 0) {
                $calendarDays[$dateStr] = ['state' => 'closed', 'tags' => [['text' => 'Closed', 'tone' => 'muted']], 'selectable' => true];
                continue;
            }
            $availableCount = 0; $leaveCount = 0; $pendingCount = 0;
            foreach ($dayData[$d] as $info) {
                if ($info['status'] === 'available') $availableCount++;
                elseif ($info['status'] === 'leave') $leaveCount++;
                elseif ($info['status'] === 'pending') $pendingCount++;
            }
            $tags = [];
            if ($availableCount > 0) $tags[] = ['text' => $availableCount . ' available', 'tone' => 'ok'];
            if ($leaveCount > 0) $tags[] = ['text' => $leaveCount . ' on leave', 'tone' => 'danger'];
            if ($pendingCount > 0) $tags[] = ['text' => $pendingCount . ' pending', 'tone' => 'warn'];
            if (!$tags) $tags[] = ['text' => 'No doctors', 'tone' => 'muted'];
            $state = $availableCount > 0 ? 'available' : ($leaveCount > 0 ? 'leave' : ($pendingCount > 0 ? 'pending' : 'unavailable'));
            $calendarDays[$dateStr] = ['state' => $state, 'tags' => $tags, 'selectable' => true];
        }
        ?>
        <div class="card border-0">
            <div class="card-header d-flex justify-content-between align-items-center">
                <strong>Doctor availability</strong>
                <span class="text-muted small"><?php echo count($doctors); ?> doctor<?php echo count($doctors) === 1 ? '' : 's'; ?></span>
            </div>
            <div class="card-body">
                <div id="secretaryDoctorCalendar"></div>
            </div>
        </div>
    </div>

    <div class="col-lg-4">
        <div class="card border-0 mb-3"><div class="card-header"><strong><i class="bi bi-calendar-date me-1"></i>Day Details</strong></div>
            <div class="card-body" id="dayDetail"><div class="text-muted small text-center py-4">Click a date to view availability</div></div>
        </div>

        <div class="card border-0 mb-3"><div class="card-header"><strong><i class="bi bi-person-plus me-1"></i>File Leave</strong></div>
            <div class="card-body">
                <form method="POST">
                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">
                    <input type="hidden" name="action" value="request_leave_for_doctor">
                    <div class="mb-2"><label class="form-label small">Doctor</label>
                        <select class="form-select" name="doctor_id" required><option value="">Select</option><?php foreach ($doctors as $doc): ?><option value="<?php echo (int)$doc['user_id']; ?>">Dr. <?php echo htmlspecialchars((string)$doc['last_name']); ?></option><?php endforeach; ?></select></div>
                    <div class="row g-2 mb-2"><div class="col-6"><label class="form-label small">Start</label><input type="date" class="form-control" name="leave_start_date" min="<?php echo $today; ?>" required></div>
                        <div class="col-6"><label class="form-label small">End</label><input type="date" class="form-control" name="leave_end_date" min="<?php echo $today; ?>" required></div></div>
                    <div class="mb-3"><label class="form-label small">Reason</label><textarea class="form-control" name="reason" rows="2" placeholder="Optional"></textarea></div>
                    <button type="submit" class="btn btn-primary w-100"><i class="bi bi-send"></i> Submit</button>
                </form>
            </div>
        </div>

        <div class="card border-0"><div class="card-header"><strong><i class="bi bi-clock-history me-1"></i>Recent Leaves</strong></div>
            <div class="card-body" style="max-height:250px;overflow-y:auto;">
                <?php if (empty($recentLeaves)): ?><div class="text-muted small">No requests.</div>
                <?php else: foreach ($recentLeaves as $lv): $st = strtolower((string)$lv['status']); $badge = match($st) { 'approved'=>'success','pending'=>'warning','rejected'=>'danger',default=>'secondary' }; $cr = preg_replace('/^\[Filed by Secretary.*?\]\s*/','',trim((string)($lv['reason']??'')));
                ?><div class="border-bottom pb-2 mb-2"><div class="d-flex justify-content-between align-items-center"><strong class="small">Dr. <?php echo htmlspecialchars((string)$lv['last_name']); ?></strong><span class="badge bg-<?php echo $badge; ?>"><?php echo htmlspecialchars(ucfirst($st)); ?></span></div><div class="text-muted small"><?php echo htmlspecialchars($lv['leave_start_date']); ?> to <?php echo htmlspecialchars($lv['leave_end_date']); ?></div><?php if ($cr!==''):?><div class="small text-muted"><?php echo htmlspecialchars($cr); ?></div><?php endif;?></div>
                <?php endforeach; endif; ?>
            </div>
        </div>
    </div>
</div>

<style>
.ds-detail-date{font-size:.82rem;font-weight:700;color:#475569;margin-bottom:10px;padding-bottom:8px;border-bottom:1px solid #e2e8f0}
.ds-row{display:flex;align-items:center;justify-content:space-between;padding:8px 10px;border-radius:10px;margin-bottom:6px;transition:background .12s ease}
.ds-row:hover{background:#f8fafc}
.ds-name{font-size:.82rem;font-weight:700;color:#1e293b}
.ds-hours{font-size:.75rem;font-weight:600;color:#0d9488}
.ds-type{font-size:.65rem;color:#94a3b8}
.ds-badge{font-size:.65rem;font-weight:700;padding:3px 10px;border-radius:999px}
.ds-avail{background:#dcfce7;color:#166534}
.ds-onleave{background:#fee2e2;color:#991b1b}
.ds-pend{background:#fef3c7;color:#92400e}
.ds-none{background:#f1f5f9;color:#94a3b8}
</style>

<script>
var dayData=<?php echo json_encode($dayData); ?>;
var selMonth=<?php echo json_encode($selectedMonth); ?>;
function selectDay(day){
    var data=dayData[day]||[],panel=document.getElementById('dayDetail');if(!panel)return;
    var dt=new Date(selMonth+'-'+String(day).padStart(2,'0')+'T00:00:00');
    var label=dt.toLocaleDateString('en-US',{weekday:'long',month:'long',day:'numeric',year:'numeric'});
    if(!data.length){panel.innerHTML='<div class="ds-detail-date">'+label+'</div><div class="text-muted small text-center py-3">No doctors scheduled</div>';return}
    var h='<div class="ds-detail-date">'+label+'</div>';
    data.forEach(function(d){
        var sl=d.status==='available'?'Available':d.status==='leave'?'On Leave':d.status==='pending'?'Pending':'No Schedule';
        var sc='ds-'+(d.status==='available'?'avail':d.status==='leave'?'onleave':d.status==='pending'?'pend':'none');
        h+='<div class="ds-row"><div><div class="ds-name">'+d.name+'</div>';
        if(d.hours)h+='<div class="ds-hours">'+d.hours+'</div>';
        h+='<div class="ds-type">'+(d.work_type==='part_time'?'Part-time':'Regular')+'</div></div>';
        h+='<span class="ds-badge '+sc+'">'+sl+'</span></div>';
    });
    panel.innerHTML=h;
}

document.addEventListener('DOMContentLoaded',function(){
    var days=<?php echo json_encode($calendarDays, JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT); ?>;
    DentalCalendar.create(document.getElementById('secretaryDoctorCalendar'),{
        month:selMonth,
        getDay:function(iso){return days[iso]||{state:'muted'};},
        onSelect:function(iso){selectDay(parseInt(iso.slice(8),10));},
        onMonthChange:function(ym){return DentalCalendar.goTo('secretary.php?page=doctor_schedule&month='+encodeURIComponent(ym));},
        legend:[{tone:'ok',label:'Doctors available'},{tone:'danger',label:'On leave'},{tone:'warn',label:'Pending leave'},{tone:'muted',label:'Closed / no doctors'}]
    });
});
</script>
