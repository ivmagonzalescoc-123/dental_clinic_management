<?php
include_once dirname(dirname(dirname(__DIR__))) . '/config/session_check.php';
include_once dirname(dirname(dirname(__DIR__))) . '/config/treatment_plan_helpers.php';

if (!in_array($_SESSION['role'] ?? '', ['doctor', 'secretary', 'admin'], true) || empty($_SESSION['user_id'])) {
    http_response_code(403);
    exit('Unauthorized');
}

$patientId = (int)($_GET['patient_id'] ?? 0);
$stmt = $conn->prepare('SELECT first_name, middle_name, last_name, date_of_birth, phone_number FROM patients WHERE patient_id = ? LIMIT 1');
$stmt->bind_param('i', $patientId);
$stmt->execute();
$patient = $stmt->get_result()->fetch_assoc();
$stmt->close();
if (!$patient) {
    http_response_code(404);
    exit('Patient not found');
}

$items = array_values(array_filter(treatment_plan_list($conn, $patientId), fn($i) => $i['status'] !== 'declined'));
$totals = treatment_plan_totals($items);
$byPhase = [];
foreach ($items as $i) $byPhase[$i['phase']][] = $i;
ksort($byPhase);

$middle = trim((string)($patient['middle_name'] ?? ''));
$patientName = trim($patient['first_name'] . ' ' . ($middle !== '' && strtoupper($middle) !== 'N/A' ? $middle . ' ' : '') . $patient['last_name']);
$statusLabels = ['proposed' => 'Proposed', 'accepted' => 'Accepted', 'completed' => 'Done'];

function e($v): string { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }
function peso(float $v): string { return '₱' . number_format($v, 2); }
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Treatment Estimate — <?php echo e($patientName); ?></title>
<style>
    * { box-sizing: border-box; }
    body { font-family: Inter, "Segoe UI", Arial, sans-serif; color: #0f172a; margin: 0; padding: 32px 16px; background: #f1f5f9; }
    .sheet { max-width: 800px; margin: 0 auto; background: #fff; padding: 36px 40px; border-radius: 10px; box-shadow: 0 4px 20px rgba(15,35,60,.08); }
    header { display: flex; justify-content: space-between; align-items: flex-start; gap: 16px; border-bottom: 2px solid #1b7aa3; padding-bottom: 14px; margin-bottom: 20px; }
    h1 { margin: 0; font-size: 1.3rem; color: #17698c; }
    .muted { color: #64748b; font-size: .85rem; }
    .meta { display: grid; grid-template-columns: repeat(auto-fit, minmax(180px, 1fr)); gap: 6px 20px; margin-bottom: 20px; font-size: .9rem; }
    h2 { font-size: .95rem; margin: 22px 0 8px; color: #17698c; }
    table { width: 100%; border-collapse: collapse; font-size: .88rem; }
    th, td { padding: 7px 8px; border-bottom: 1px solid #e2e8f0; text-align: left; vertical-align: top; }
    th { background: #f8fafc; font-size: .75rem; text-transform: uppercase; letter-spacing: .04em; color: #475569; }
    td.num, th.num { text-align: right; white-space: nowrap; }
    tr.subtotal td { font-weight: 700; border-bottom: 0; }
    .totals { margin-top: 22px; margin-left: auto; width: min(320px, 100%); font-size: .92rem; }
    .totals div { display: flex; justify-content: space-between; padding: 4px 0; }
    .totals .grand { font-weight: 800; font-size: 1.05rem; border-top: 2px solid #0f172a; margin-top: 4px; padding-top: 8px; }
    .note { margin-top: 22px; font-size: .8rem; color: #475569; line-height: 1.5; }
    .signatures { display: grid; grid-template-columns: 1fr 1fr; gap: 40px; margin-top: 56px; font-size: .85rem; }
    .signatures div { border-top: 1px solid #0f172a; padding-top: 6px; text-align: center; }
    .empty { padding: 24px; text-align: center; color: #64748b; }
    .actions { max-width: 800px; margin: 0 auto 12px; text-align: right; }
    .actions button { background: #1b7aa3; color: #fff; border: 0; border-radius: 8px; padding: 8px 18px; font-weight: 600; cursor: pointer; }
    @media print {
        body { background: #fff; padding: 0; }
        .sheet { box-shadow: none; padding: 0; }
        .actions { display: none; }
    }
</style>
</head>
<body>
<div class="actions"><button type="button" onclick="window.print()">Print estimate</button></div>
<div class="sheet">
    <header>
        <div>
            <h1>Azucena's Dental Clinic</h1>
            <div class="muted">Treatment Plan &amp; Cost Estimate</div>
        </div>
        <div class="muted">Prepared <?php echo e(date('F j, Y')); ?></div>
    </header>

    <div class="meta">
        <div><span class="muted">Patient</span><br><strong><?php echo e($patientName); ?></strong></div>
        <?php if (!empty($patient['date_of_birth'])): ?>
        <div><span class="muted">Date of birth</span><br><?php echo e(date('M j, Y', strtotime($patient['date_of_birth']))); ?></div>
        <?php endif; ?>
        <?php if (!empty($patient['phone_number'])): ?>
        <div><span class="muted">Contact</span><br><?php echo e($patient['phone_number']); ?></div>
        <?php endif; ?>
    </div>

    <?php if (!$items): ?>
        <div class="empty">No treatment plan items for this patient yet.</div>
    <?php else: ?>
        <?php foreach ($byPhase as $phase => $phaseItems): $sub = 0.0; ?>
            <h2><?php echo e(treatment_plan_phase_label((int)$phase)); ?></h2>
            <table>
                <thead><tr><th style="width:60px;">Tooth</th><th>Procedure</th><th style="width:90px;">Status</th><th class="num" style="width:120px;">Estimate</th></tr></thead>
                <tbody>
                <?php foreach ($phaseItems as $i): $sub += $i['estimated_cost']; ?>
                    <tr>
                        <td><?php echo $i['tooth_number'] ? e(treatment_plan_teeth_label($i['tooth_number'])) : '—'; ?></td>
                        <td><?php echo e($i['service_name'] ?? 'Service #' . $i['service_id']); ?><?php if (!empty($i['notes'])): ?><br><span class="muted"><?php echo e($i['notes']); ?></span><?php endif; ?></td>
                        <td><?php echo e($statusLabels[$i['status']] ?? $i['status']); ?></td>
                        <td class="num"><?php echo peso($i['estimated_cost']); ?></td>
                    </tr>
                <?php endforeach; ?>
                <tr class="subtotal"><td colspan="3">Subtotal</td><td class="num"><?php echo peso($sub); ?></td></tr>
                </tbody>
            </table>
        <?php endforeach; ?>

        <div class="totals">
            <div><span>Total treatment plan</span><span><?php echo peso($totals['plan']); ?></span></div>
            <div><span>Already done</span><span>− <?php echo peso($totals['completed']); ?></span></div>
            <div class="grand"><span>Remaining estimate</span><span><?php echo peso($totals['remaining']); ?></span></div>
        </div>
    <?php endif; ?>

    <p class="note">This is an estimate based on the current diagnosis. Final charges are billed per visit for the work actually done and may change if the treatment needs change. Installment payment may be arranged at the front desk.</p>

    <div class="signatures">
        <div>Patient / Guardian signature over printed name</div>
        <div>Attending dentist</div>
    </div>
</div>
</body>
</html>
