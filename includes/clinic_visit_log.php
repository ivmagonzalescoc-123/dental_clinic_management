<?php
/**
 * Clinic visit log: one row per completed visit with services availed, treatments done
 * and how the bill stands. Shared by the doctor's Clinical Records page and the
 * secretary/admin Records page — a plain summary table, no modals.
 *
 * Set before including:
 *   $visitLogDoctorId    int|null  limit to this doctor's visits; null = every doctor
 *                                  (adds a Doctor column)
 *   $visitLogPatientLink string|null  sprintf pattern for the patient name link, e.g.
 *                                  'doctor.php?page=patients&patient_id=%d'; null = plain text
 *   $visitLogSubtitle    string    line under the page title
 * Requires $conn (config/database.php).
 */
include_once dirname(__DIR__) . '/config/treatment_plan_helpers.php';

$visitLogDoctorId = isset($visitLogDoctorId) ? (int)$visitLogDoctorId : null;
$visitLogPatientLink = $visitLogPatientLink ?? null;
$visitLogSubtitle = $visitLogSubtitle ?? 'A log of completed visits: what was availed, treatments done and payments.';
$showDoctor = $visitLogDoctorId === null;

$doneStatuses = ['completed', 'records_complete', 'completed_by_doctor', 'awaiting_secretary_records'];
$statusList = "'" . implode("','", $doneStatuses) . "'";
$doctorFilter = $showDoctor ? '' : 'AND (a.user_id = ? OR cr.doctor_id = ?)';

$visits = [];
$stmt = $conn->prepare("
    SELECT a.appointment_id, a.appointment_date, a.status AS appointment_status,
           p.patient_id, p.first_name, p.last_name,
           d.first_name AS doctor_first_name, d.last_name AS doctor_last_name,
           (SELECT DATE_FORMAT(s.scheduled_start, '%H:%i') FROM patient_schedules s WHERE s.appointment_id = a.appointment_id ORDER BY s.scheduled_start DESC LIMIT 1) AS start_time,
           cr.status AS record_status,
           b.total_amount, b.amount_paid, b.balance, b.status AS billing_status
    FROM appointments a
    INNER JOIN patients p ON p.patient_id = a.patient_id
    LEFT JOIN users d ON d.user_id = a.user_id
    LEFT JOIN clinical_records cr ON cr.record_id = (
        SELECT MAX(cr2.record_id) FROM clinical_records cr2 WHERE cr2.appointment_id = a.appointment_id
    )
    LEFT JOIN billing b ON b.appointment_id = a.appointment_id
    WHERE (a.status IN ($statusList) OR cr.record_id IS NOT NULL)
      $doctorFilter
    ORDER BY a.appointment_date DESC, start_time DESC, a.appointment_id DESC
    LIMIT 1000
");
if ($stmt) {
    if (!$showDoctor) $stmt->bind_param('ii', $visitLogDoctorId, $visitLogDoctorId);
    $stmt->execute();
    $res = $stmt->get_result();
    while ($row = $res->fetch_assoc()) {
        $row['services'] = [];
        $row['treatments'] = [];
        $visits[(int)$row['appointment_id']] = $row;
    }
    $stmt->close();
}

if ($visits) {
    $ids = array_keys($visits);
    $in = implode(',', array_fill(0, count($ids), '?'));
    $types = str_repeat('i', count($ids));

    // Services availed: what was billed, falling back to the booked services for
    // visits without line items yet.
    $q = $conn->prepare("SELECT appointment_id, description FROM billing_line_items WHERE item_type = 'service' AND appointment_id IN ($in) ORDER BY item_id ASC");
    if ($q) {
        $q->bind_param($types, ...$ids);
        $q->execute();
        $r = $q->get_result();
        while ($row = $r->fetch_assoc()) {
            $desc = trim((string)$row['description']);
            if ($desc !== '') $visits[(int)$row['appointment_id']]['services'][] = $desc;
        }
        $q->close();
    }
    $q = $conn->prepare("SELECT ss.appointment_id, s.service_name FROM selected_services ss INNER JOIN services s ON s.service_id = ss.service_id WHERE ss.appointment_id IN ($in)");
    if ($q) {
        $q->bind_param($types, ...$ids);
        $q->execute();
        $r = $q->get_result();
        $booked = [];
        while ($row = $r->fetch_assoc()) $booked[(int)$row['appointment_id']][] = (string)$row['service_name'];
        $q->close();
        foreach ($booked as $aid => $names) {
            if (empty($visits[$aid]['services'])) $visits[$aid]['services'] = $names;
        }
    }

    treatment_plan_ensure_table($conn);
    $q = $conn->prepare("SELECT i.completed_appointment_id, i.tooth_number, s.service_name FROM patient_treatment_plan_items i LEFT JOIN services s ON s.service_id = i.service_id WHERE i.completed_appointment_id IN ($in) ORDER BY i.phase, i.item_id");
    if ($q) {
        $q->bind_param($types, ...$ids);
        $q->execute();
        $r = $q->get_result();
        while ($row = $r->fetch_assoc()) {
            $label = (string)($row['service_name'] ?? 'Treatment');
            if (!empty($row['tooth_number'])) $label .= ' #' . $row['tooth_number'];
            $visits[(int)$row['completed_appointment_id']]['treatments'][] = $label;
        }
        $q->close();
    }
}

if (!function_exists('visit_log_peso')) {
    function visit_log_peso($v): string
    {
        return '₱' . number_format((float)$v, 2);
    }

    function visit_log_payment_badge(?string $status): array
    {
        switch ((string)$status) {
            case 'paid': return ['Paid', 'success'];
            case 'partial': return ['Partial', 'warning'];
            case 'unpaid': return ['Unpaid', 'danger'];
            case '': return ['No bill', 'secondary'];
            default: return [ucfirst((string)$status), 'secondary'];
        }
    }
}
?>

<div class="card border-0 cvl">
    <div class="card-header cvl__header">
        <div>
            <h2 class="card-title mb-0">Clinical Records</h2>
            <p class="cvl__sub mb-0"><?php echo htmlspecialchars($visitLogSubtitle); ?></p>
        </div>
    </div>

    <div class="card-body pb-2">
        <div class="cvl__stats" id="cvlStats">
            <div><span>Visits</span><strong data-stat="visits">0</strong></div>
            <div><span>Billed</span><strong data-stat="billed">₱0.00</strong></div>
            <div><span>Collected</span><strong data-stat="paid">₱0.00</strong></div>
            <div><span>Outstanding</span><strong data-stat="balance">₱0.00</strong></div>
        </div>

        <div class="cvl__toolbar">
            <div class="unified-table-search-wrap module-search-inline cvl__search">
                <i class="bi bi-search unified-table-search-icon" aria-hidden="true"></i>
                <input type="search" id="cvlSearch" class="form-control unified-table-search-input" placeholder="Search patient, <?php echo $showDoctor ? 'doctor, ' : ''; ?>service or treatment..." aria-label="Search clinic log" autocomplete="off">
            </div>
            <div class="cvl__chips" role="group" aria-label="Filter visits">
                <button type="button" class="cvl-chip is-active" data-log-filter="all">All</button>
                <button type="button" class="cvl-chip" data-log-filter="month"><i class="bi bi-calendar3"></i> This month</button>
                <button type="button" class="cvl-chip" data-log-filter="balance"><i class="bi bi-hourglass-split"></i> With balance</button>
                <button type="button" class="cvl-chip" data-log-filter="paid"><i class="bi bi-check2-circle"></i> Paid</button>
            </div>
        </div>
    </div>

    <div class="card-body pt-0">
        <div class="table-responsive">
            <table class="table table-hover align-middle cvl__table" id="cvlTable" data-skip-datatable="1">
                <thead>
                    <tr>
                        <th>Date</th>
                        <th>Patient</th>
                        <?php if ($showDoctor): ?><th>Doctor</th><?php endif; ?>
                        <th>Services availed</th>
                        <th>Treatments done</th>
                        <th class="text-end">Total</th>
                        <th class="text-end">Paid</th>
                        <th class="text-end">Balance</th>
                        <th>Payment</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($visits as $v):
                        $name = trim($v['first_name'] . ' ' . $v['last_name']);
                        $doctor = trim((string)$v['doctor_first_name'] . ' ' . (string)$v['doctor_last_name']);
                        $doctor = $doctor !== '' ? 'Dr. ' . $doctor : '—';
                        $hasBill = $v['billing_status'] !== null;
                        [$payLabel, $payClass] = visit_log_payment_badge($hasBill ? (string)$v['billing_status'] : '');
                        $total = (float)($v['total_amount'] ?? 0);
                        $paid = (float)($v['amount_paid'] ?? 0);
                        $balance = (float)($v['balance'] ?? 0);
                        $time = $v['start_time'] ? date('g:i A', strtotime($v['start_time'])) : '';
                        $search = strtolower($name . ' ' . ($showDoctor ? $doctor . ' ' : '') . implode(' ', $v['services']) . ' ' . implode(' ', $v['treatments']));
                    ?>
                        <tr data-month="<?php echo htmlspecialchars(substr((string)$v['appointment_date'], 0, 7)); ?>"
                            data-pay="<?php echo htmlspecialchars($hasBill ? (string)$v['billing_status'] : 'none'); ?>"
                            data-total="<?php echo $total; ?>" data-paid="<?php echo $paid; ?>" data-balance="<?php echo $balance; ?>"
                            data-search="<?php echo htmlspecialchars($search); ?>">
                            <td class="cvl__date">
                                <strong><?php echo htmlspecialchars(date('M j, Y', strtotime((string)$v['appointment_date']))); ?></strong>
                                <?php if ($time): ?><small><?php echo htmlspecialchars($time); ?></small><?php endif; ?>
                            </td>
                            <td>
                                <?php if ($visitLogPatientLink): ?>
                                    <a class="cvl__patient" href="<?php echo htmlspecialchars(sprintf($visitLogPatientLink, (int)$v['patient_id'])); ?>"><?php echo htmlspecialchars($name); ?></a>
                                <?php else: ?>
                                    <span class="cvl__patient-name"><?php echo htmlspecialchars($name); ?></span>
                                <?php endif; ?>
                            </td>
                            <?php if ($showDoctor): ?><td class="cvl__doctor"><?php echo htmlspecialchars($doctor); ?></td><?php endif; ?>
                            <td><?php echo $v['services'] ? htmlspecialchars(implode(', ', $v['services'])) : '<span class="text-muted">—</span>'; ?></td>
                            <td><?php echo $v['treatments'] ? htmlspecialchars(implode(', ', $v['treatments'])) : '<span class="text-muted">—</span>'; ?></td>
                            <td class="text-end cvl__num"><?php echo $hasBill ? visit_log_peso($total) : '—'; ?></td>
                            <td class="text-end cvl__num"><?php echo $hasBill ? visit_log_peso($paid) : '—'; ?></td>
                            <td class="text-end cvl__num<?php echo $balance > 0 ? ' text-danger' : ''; ?>"><?php echo $hasBill ? visit_log_peso($balance) : '—'; ?></td>
                            <td><span class="badge bg-<?php echo $payClass; ?>"><?php echo htmlspecialchars($payLabel); ?></span></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <div class="cvl__empty" id="cvlEmpty" <?php echo $visits ? 'hidden' : ''; ?>>
            <i class="bi bi-journal-x"></i><span><?php echo $visits ? 'No visits match your search or filter.' : 'No completed visits yet.'; ?></span>
        </div>
    </div>
</div>

<style>
.cvl__header { display: flex; align-items: center; justify-content: space-between; gap: 12px; }
.cvl__sub { font-size: 0.85rem; color: #64748b; margin-top: 2px; }
.cvl__stats { display: grid; grid-template-columns: repeat(4, minmax(0, 1fr)); gap: 10px; margin-bottom: 14px; }
.cvl__stats > div { background: #f8fafc; border: 1px solid #e5eaf0; border-radius: 12px; padding: 10px 14px; }
.cvl__stats span { display: block; font-size: 0.7rem; font-weight: 700; text-transform: uppercase; letter-spacing: .05em; color: #94a3b8; }
.cvl__stats strong { font-size: 1.15rem; color: #0f172a; font-variant-numeric: tabular-nums; }
.cvl__toolbar { display: flex; flex-wrap: wrap; align-items: center; gap: 10px 12px; }
.cvl__search { flex: 1 1 240px; max-width: 360px; }
.cvl__chips { display: flex; flex-wrap: wrap; gap: 6px; }
.cvl-chip {
    display: inline-flex; align-items: center; gap: 6px; border: 1px solid #dbe3ec; background: #fff; color: #475569;
    border-radius: 999px; padding: 5px 12px; font: inherit; font-size: 0.8rem; font-weight: 600; cursor: pointer;
    transition: background-color .15s ease, border-color .15s ease, color .15s ease;
}
.cvl-chip:hover { border-color: #a9d8ea; color: #17698c; }
.cvl-chip.is-active { background: #1b7aa3; border-color: #1b7aa3; color: #fff; }
.cvl-chip:focus-visible { outline: 3px solid rgba(27, 122, 163, .3); outline-offset: 1px; }
.cvl__table { font-size: 0.88rem; }
.cvl__table th { font-size: 0.72rem; text-transform: uppercase; letter-spacing: .04em; color: #64748b; white-space: nowrap; }
.cvl__date strong { display: block; white-space: nowrap; }
.cvl__date small { color: #64748b; }
.cvl__patient { font-weight: 600; color: #17698c; text-decoration: none; }
.cvl__patient:hover { text-decoration: underline; }
.cvl__patient-name { font-weight: 600; color: #0f172a; }
.cvl__doctor { white-space: nowrap; color: #475569; }
.cvl__num { font-variant-numeric: tabular-nums; white-space: nowrap; }
.cvl__empty { display: flex; align-items: center; justify-content: center; gap: 8px; padding: 24px; color: #64748b; font-size: 0.9rem; }
.cvl__empty[hidden] { display: none; }
.cvl__empty i { font-size: 1.3rem; color: #94a3b8; }
@media (max-width: 767.98px) {
    .cvl__stats { grid-template-columns: repeat(2, minmax(0, 1fr)); }
    .cvl__search { max-width: none; flex-basis: 100%; }
}
</style>

<script>
(function () {
    var table = document.getElementById('cvlTable');
    if (!table) return;
    var rows = Array.prototype.slice.call(table.tBodies[0].rows);
    var search = document.getElementById('cvlSearch');
    var empty = document.getElementById('cvlEmpty');
    var thisMonth = <?php echo json_encode(date('Y-m')); ?>;
    var filter = 'all';

    function peso(v) {
        return '₱' + Number(v || 0).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 });
    }
    function inFilter(tr) {
        if (filter === 'month') return tr.getAttribute('data-month') === thisMonth;
        if (filter === 'balance') return Number(tr.getAttribute('data-balance')) > 0;
        if (filter === 'paid') return tr.getAttribute('data-pay') === 'paid';
        return true;
    }
    function apply() {
        var terms = (search ? search.value : '').trim().toLowerCase().split(/\s+/).filter(Boolean);
        var t = { visits: 0, billed: 0, paid: 0, balance: 0 };
        rows.forEach(function (tr) {
            var hay = tr.getAttribute('data-search') || '';
            var show = inFilter(tr) && terms.every(function (term) { return hay.indexOf(term) !== -1; });
            tr.hidden = !show;
            if (!show) return;
            t.visits++;
            t.billed += Number(tr.getAttribute('data-total')) || 0;
            t.paid += Number(tr.getAttribute('data-paid')) || 0;
            t.balance += Number(tr.getAttribute('data-balance')) || 0;
        });
        var stats = document.getElementById('cvlStats');
        if (stats) {
            stats.querySelector('[data-stat="visits"]').textContent = t.visits;
            stats.querySelector('[data-stat="billed"]').textContent = peso(t.billed);
            stats.querySelector('[data-stat="paid"]').textContent = peso(t.paid);
            stats.querySelector('[data-stat="balance"]').textContent = peso(t.balance);
        }
        if (empty && rows.length) empty.hidden = t.visits > 0;
    }

    if (search) search.addEventListener('input', apply);
    document.querySelectorAll('[data-log-filter]').forEach(function (btn) {
        btn.addEventListener('click', function () {
            filter = btn.getAttribute('data-log-filter');
            document.querySelectorAll('[data-log-filter]').forEach(function (b) { b.classList.toggle('is-active', b === btn); });
            apply();
        });
    });
    apply();
})();
</script>
