<?php
include dirname(dirname(__DIR__)) . '/config/database.php';

// Default: show today's arrivals (patients who checked-in today)
$date = (string)($_GET['date'] ?? date('Y-m-d'));
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
    $date = date('Y-m-d');
}

// Queue statuses that represent "already arrived and waiting in line"
$queueStatuses = ['waiting', 'called'];

$sql = "
    SELECT
        q.checkin_id,
        q.status AS queue_status,
        q.checkin_time,
        q.que_number,

        a.appointment_id,
        a.appointment_date,
        a.status AS appointment_status,
        a.reason,

        NULL AS start_time,
        NULL AS end_time,

        p.patient_id,
        p.first_name AS patient_first_name,
        p.last_name AS patient_last_name,

        d.user_id AS doctor_id,
        d.first_name AS doctor_first_name,
        d.last_name AS doctor_last_name
    FROM check_in_queue q
    INNER JOIN appointments a ON a.appointment_id = q.appointment_id
    -- timeslot removed; start/end time not available
    LEFT JOIN patients p ON p.patient_id = a.patient_id
    LEFT JOIN users d ON d.user_id = a.user_id
    WHERE DATE(q.checkin_time) = ?
      AND q.status IN (?, ?)
    ORDER BY
        d.last_name ASC,
        d.first_name ASC,
        q.que_number ASC,
        q.checkin_time ASC
";

$stmt = $conn->prepare($sql);
$rows = [];
if ($stmt) {
    $stmt->bind_param('sss', $date, $queueStatuses[0], $queueStatuses[1]);
    $stmt->execute();
    $result = $stmt->get_result();
    if ($result) {
        while ($r = $result->fetch_assoc()) {
            $rows[] = $r;
        }
    }
    $stmt->close();
}

$byDoctor = [];
foreach ($rows as $r) {
    $doctorId = $r['doctor_id'] !== null ? (int)$r['doctor_id'] : 0;
    $doctorName = $doctorId === 0
        ? 'Unassigned Doctor'
        : trim(($r['doctor_first_name'] ?? '') . ' ' . ($r['doctor_last_name'] ?? ''));

    if (!isset($byDoctor[$doctorId])) {
        $byDoctor[$doctorId] = [
            'doctor_id' => $doctorId,
            'doctor_name' => $doctorName,
            'queue' => [],
        ];
    }

    $byDoctor[$doctorId]['queue'][] = $r;
}

function branch_label($branchId)
{
    $map = [1 => 'Main', 2 => 'Butuan', 3 => 'Surigao'];
    if (!$branchId) return 'N/A';
    return $map[(int)$branchId] ?? 'N/A';
}

function queue_badge_class($queueStatus)
{
    switch ($queueStatus) {
        case 'called':
            return 'info';
        case 'waiting':
        default:
            return 'warning';
    }
}

function format_dt($dt)
{
    if (empty($dt)) return '';
    $ts = strtotime($dt);
    if (!$ts) return htmlspecialchars((string)$dt);
    return date('M d, Y h:i A', $ts);
}

function format_time_range($start, $end)
{
    if (empty($start) || empty($end)) return '';
    $startTs = strtotime($start);
    $endTs = strtotime($end);
    if (!$startTs || !$endTs) return '';
    return date('h:i A', $startTs) . ' - ' . date('h:i A', $endTs);
}
?>

<div class="card">
    <div class="card-header d-flex flex-wrap justify-content-between align-items-center gap-2">
        <div class="d-flex align-items-center gap-2" style="min-width: 0;">
            <h2 class="card-title mb-0 text-truncate">Queue Management</h2>
            <span class="text-muted small d-none d-lg-inline">Patients who already arrived (checked-in)</span>
        </div>

        <form class="d-flex align-items-center gap-2" method="GET" action="admin.php">
            <input type="hidden" name="page" value="queue">
            <input type="date" class="form-control form-control-sm" name="date" value="<?php echo htmlspecialchars($date); ?>" title="Date">
            <button class="btn btn-primary btn-sm" type="submit">View</button>
            <a class="btn btn-outline-secondary btn-sm" href="admin.php?page=queue">Today</a>
        </form>
    </div>

    <div class="card-body">
        <?php if (empty($byDoctor)): ?>
            <div class="alert alert-info mb-0">No checked-in queue records for this date.</div>
        <?php else: ?>
            <div class="accordion" id="queueByDoctor">
                <?php foreach ($byDoctor as $doctorBlock): ?>
                    <?php
                        $doctorId = (int)$doctorBlock['doctor_id'];
                        $doctorName = (string)$doctorBlock['doctor_name'];
                        $queue = $doctorBlock['queue'];
                        $count = count($queue);
                        $collapseId = 'doctorQueueCollapse' . $doctorId;
                        $headingId = 'doctorQueueHeading' . $doctorId;
                    ?>
                    <div class="accordion-item mb-2">
                        <h2 class="accordion-header" id="<?php echo htmlspecialchars($headingId); ?>">
                            <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#<?php echo htmlspecialchars($collapseId); ?>" aria-expanded="false" aria-controls="<?php echo htmlspecialchars($collapseId); ?>">
                                <div class="w-100 d-flex justify-content-between align-items-center">
                                    <div class="fw-semibold text-truncate me-2"><?php echo htmlspecialchars($doctorName); ?></div>
                                    <span class="badge bg-secondary"><?php echo $count; ?> in queue</span>
                                </div>
                            </button>
                        </h2>
                        <div id="<?php echo htmlspecialchars($collapseId); ?>" class="accordion-collapse collapse" aria-labelledby="<?php echo htmlspecialchars($headingId); ?>" data-bs-parent="#queueByDoctor">
                            <div class="accordion-body">
                                <div class="table-responsive">
                                    <table class="table table-sm table-hover align-middle mb-0">
                                        <thead>
                                            <tr>
                                                <th style="width: 90px;">Queue #</th>
                                                <th>Patient</th>
                                                <th style="width: 220px;">Appointment</th>
                                                <th style="width: 220px;">Checked-in</th>
                                                <th style="width: 120px;">Status</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php foreach ($queue as $item): ?>
                                                <?php
                                                    $queueNo = $item['que_number'] ?? '';
                                                    $patientName = trim(($item['patient_first_name'] ?? '') . ' ' . ($item['patient_last_name'] ?? ''));
                                                    $apptDate = $item['appointment_date'] ?? '';
                                                    $timeRange = format_time_range($item['start_time'] ?? null, $item['end_time'] ?? null);
                                                    $queueStatus = $item['queue_status'] ?? 'waiting';
                                                ?>
                                                <tr>
                                                    <td class="fw-semibold"><?php echo htmlspecialchars((string)$queueNo); ?></td>
                                                    <td>
                                                        <div class="fw-semibold"><?php echo htmlspecialchars($patientName !== '' ? $patientName : 'Unknown Patient'); ?></div>
                                                        <?php if (!empty($item['reason'])): ?>
                                                            <div class="text-muted small text-truncate" style="max-width: 520px;">Reason: <?php echo htmlspecialchars((string)$item['reason']); ?></div>
                                                        <?php endif; ?>
                                                    </td>
                                                    <td>
                                                        <div><?php echo htmlspecialchars((string)$apptDate); ?></div>
                                                        <?php if ($timeRange !== ''): ?>
                                                            <div class="text-muted small"><?php echo htmlspecialchars($timeRange); ?></div>
                                                        <?php endif; ?>
                                                    </td>
                                                    <td><?php echo htmlspecialchars(format_dt($item['checkin_time'] ?? '')); ?></td>
                                                    <td>
                                                        <span class="badge bg-<?php echo htmlspecialchars(queue_badge_class($queueStatus)); ?>">
                                                            <?php echo htmlspecialchars(ucfirst((string)$queueStatus)); ?>
                                                        </span>
                                                    </td>
                                                </tr>
                                            <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                </div>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>
</div>
