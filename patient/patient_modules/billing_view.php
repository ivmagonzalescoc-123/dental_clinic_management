<?php
include dirname(dirname(__DIR__)) . '/config/database.php';
include_once dirname(dirname(__DIR__)) . '/config/billing_helpers.php';

billing_ensure_tables($conn);

$billing_id = (int)($_GET['billing_id'] ?? 0);
$patient_id = $_SESSION['patient_id'] ?? 0;

if ($billing_id <= 0) {
    echo '<div class="alert alert-warning">Invalid billing record.</div>';
    return;
}

// Fetch billing and appointment
$stmt = $conn->prepare("SELECT b.*, a.appointment_id, a.appointment_date, a.patient_id FROM billing b LEFT JOIN appointments a ON b.appointment_id = a.appointment_id WHERE b.billing_id = ? LIMIT 1");
if (!$stmt) {
    echo '<div class="alert alert-danger">Database error.</div>';
    return;
}
$stmt->bind_param('i', $billing_id);
$stmt->execute();
$res = $stmt->get_result();
$billing = $res ? $res->fetch_assoc() : null;
$stmt->close();

if (!$billing) {
    echo '<div class="alert alert-warning">Billing not found.</div>';
    return;
}

// Security: ensure patient owns the billing (if patient context)
if (!empty($patient_id) && !empty($billing['patient_id']) && (int)$billing['patient_id'] !== (int)$patient_id) {
    echo '<div class="alert alert-danger">Access denied.</div>';
    return;
}

$appointment_id = (int)($billing['appointment_id'] ?? 0);

$items = [];
$items_total = 0.0;

// 1) Selected services for appointment (if any)
if ($appointment_id > 0) {
    $ss = $conn->prepare('SELECT ss.selected_service_id, ss.service_id, s.service_name, s.initial_price FROM selected_services ss LEFT JOIN services s ON ss.service_id = s.service_id WHERE ss.appointment_id = ?');
    if ($ss) {
        $ss->bind_param('i', $appointment_id);
        $ss->execute();
        $r = $ss->get_result();
        while ($row = $r->fetch_assoc()) {
            $amt = (float)($row['initial_price'] ?? 0.0);
            $items[] = [ 'description' => ($row['service_name'] ?? 'Service'), 'amount' => $amt ];
            $items_total += $amt;
        }
        $ss->close();
    }
}

// 2) Clinical record recommended_services and materials_used
if ($appointment_id > 0) {
    $cr = $conn->prepare('SELECT record_id FROM clinical_records WHERE appointment_id = ? LIMIT 1');
    if ($cr) {
        $cr->bind_param('i', $appointment_id);
        $cr->execute();
        $rr = $cr->get_result();
        $rec = $rr ? $rr->fetch_assoc() : null;
        $cr->close();

        if ($rec && isset($rec['record_id'])) {
            $recId = (int)$rec['record_id'];

            $svcStmt = $conn->prepare('SELECT s.service_name, s.initial_price FROM clinical_record_services crs LEFT JOIN services s ON s.service_id = crs.service_id WHERE crs.record_id = ?');
            if ($svcStmt) {
                $svcStmt->bind_param('i', $recId);
                $svcStmt->execute();
                $svcRes = $svcStmt->get_result();
                while ($srow = $svcRes->fetch_assoc()) {
                    $amt = (float)($srow['initial_price'] ?? 0.0);
                    $items[] = ['description' => ($srow['service_name'] ?? 'Service'), 'amount' => $amt];
                    $items_total += $amt;
                }
                $svcStmt->close();
            }

            $matStmt = $conn->prepare('SELECT material_name, quantity, cost FROM treatment_materials WHERE record_id = ?');
            if ($matStmt) {
                $matStmt->bind_param('i', $recId);
                $matStmt->execute();
                $matRes = $matStmt->get_result();
                while ($mrow = $matRes->fetch_assoc()) {
                    $qty = (float)($mrow['quantity'] ?? 1);
                    $price = (float)($mrow['cost'] ?? 0);
                    $line = $qty * $price;
                    $items[] = ['description' => ($mrow['material_name'] ?? 'Material') . ' (x' . ($qty) . ')', 'amount' => $line];
                    $items_total += $line;
                }
                $matStmt->close();
            }
        }
    }
}

// 3) If items_total < billing total, add a consultation/other fee line to show remainder
$billing_subtotal = (float)($billing['subtotal_amount'] ?? 0.0);
$billing_discount_type = (string)($billing['discount_type'] ?? 'none');
$billing_discount_percent = (float)($billing['discount_percent'] ?? 0.0);
$billing_discount = $billing_subtotal * $billing_discount_percent / 100;
$billing_total = (float)($billing['total_amount'] ?? 0.0);
if ($billing_subtotal <= 0) {
    $billing_subtotal = $billing_total + $billing_discount;
}
$remainder = $billing_subtotal - $items_total;
if ($remainder > 0.005) {
    $items[] = [ 'description' => 'Consultation / Treatment Fee', 'amount' => $remainder ];
    $items_total += $remainder;
}

?>

<div class="card">
    <div class="card-header d-flex justify-content-between align-items-center">
        <h5 class="card-title mb-0">Billing Transactions</h5>
        <a class="btn btn-sm btn-outline-secondary" href="patient.php?page=transactions">Back</a>
    </div>
    <div class="card-body">
        <?php $status = strtolower($billing['status'] ?? 'unpaid'); $badge = $status === 'paid' ? 'success' : ($status === 'partial' ? 'warning' : 'secondary'); ?>
        <div class="mb-3 text-muted">Billing #: <?php echo htmlspecialchars($billing_id); ?> &nbsp; | &nbsp; Date: <?php echo htmlspecialchars($billing['created_at'] ?? ''); ?> &nbsp; | &nbsp; Status: <span class="badge bg-<?php echo $badge; ?>"><?php echo htmlspecialchars(ucfirst($status)); ?></span></div>

        <?php if (empty($items)): ?>
            <div class="text-muted">No itemized expenses found for this billing.</div>
        <?php else: ?>
            <div class="table-responsive">
                <table class="table table-sm align-middle">
                    <thead>
                        <tr><th>Description</th><th class="text-end">Amount</th></tr>
                    </thead>
                    <tbody>
                        <?php foreach ($items as $it): ?>
                            <tr>
                                <td><?php echo htmlspecialchars($it['description']); ?></td>
                                <td class="text-end">₱<?php echo htmlspecialchars(number_format((float)$it['amount'],2)); ?></td>
                            </tr>
                        <?php endforeach; ?>
                        <tr class="table-light">
                            <td><strong>Subtotal</strong></td>
                            <td class="text-end"><strong>₱<?php echo htmlspecialchars(number_format($billing_subtotal > 0 ? $billing_subtotal : $items_total, 2)); ?></strong></td>
                        </tr>
                        <?php if ($billing_discount > 0 && $billing_discount_type !== 'none'): ?>
                            <tr>
                                <td class="text-success"><?php echo htmlspecialchars(billing_discount_label($billing_discount_type, $billing_discount_percent)); ?></td>
                                <td class="text-end text-success">-₱<?php echo htmlspecialchars(number_format($billing_discount, 2)); ?></td>
                            </tr>
                        <?php endif; ?>
                        <tr class="table-light">
                            <td><strong>Total Due</strong></td>
                            <td class="text-end"><strong>₱<?php echo htmlspecialchars(number_format($billing_total, 2)); ?></strong></td>
                        </tr>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>
</div>
