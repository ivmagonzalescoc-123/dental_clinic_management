<?php
include dirname(dirname(__DIR__)) . '/config/database.php';

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
    $cr = $conn->prepare('SELECT recommended_services, materials_used, doctor_notes FROM clinical_records WHERE appointment_id = ? LIMIT 1');
    if ($cr) {
        $cr->bind_param('i', $appointment_id);
        $cr->execute();
        $rr = $cr->get_result();
        $rec = $rr ? $rr->fetch_assoc() : null;
        $cr->close();

        if ($rec) {
            // recommended_services may be JSON array of ids or array of objects
            if (!empty($rec['recommended_services'])) {
                $rs = json_decode($rec['recommended_services'], true);
                if (is_array($rs) && count($rs) > 0) {
                    // if numeric array of ids
                    $ids = array_filter($rs, function($v){ return is_numeric($v); });
                    if (!empty($ids)) {
                        // fetch service names/prices for these ids
                        $in = implode(',', array_map('intval', $ids));
                        $q = 'SELECT service_id, service_name, initial_price FROM services WHERE service_id IN (' . $in . ')';
                        $qres = $conn->query($q);
                        if ($qres) {
                            while ($srow = $qres->fetch_assoc()) {
                                $amt = (float)($srow['initial_price'] ?? 0.0);
                                $items[] = [ 'description' => ($srow['service_name'] ?? 'Service'), 'amount' => $amt ];
                                $items_total += $amt;
                            }
                        }
                    } else {
                        // assume array of objects with name/price
                        foreach ($rs as $obj) {
                            if (is_array($obj)) {
                                $amt = (float)($obj['initial_price'] ?? $obj['price'] ?? 0.0);
                                $items[] = [ 'description' => ($obj['service_name'] ?? $obj['name'] ?? 'Service'), 'amount' => $amt ];
                                $items_total += $amt;
                            }
                        }
                    }
                }
            }

            // materials_used: array or object map
            if (!empty($rec['materials_used'])) {
                $mu = json_decode($rec['materials_used'], true);
                if ($mu) {
                    // materials could be object with numeric arrays inside (see SQL sample)
                    if (isset($mu[0]) && is_array($mu[0])) {
                        // array of materials
                        foreach ($mu as $m) {
                            if (!is_array($m)) continue;
                            $qty = (float)($m['qty'] ?? 1);
                            $price = (float)($m['price'] ?? 0);
                            $line = $qty * $price;
                            $items[] = [ 'description' => ($m['name'] ?? 'Material') . ' (x' . ($qty) . ')', 'amount' => $line ];
                            $items_total += $line;
                        }
                    } else {
                        // object map of arrays per tooth etc.
                        foreach ($mu as $entry) {
                            if (!is_array($entry)) continue;
                            foreach ($entry as $m) {
                                if (!is_array($m)) continue;
                                $qty = (float)($m['qty'] ?? 1);
                                $price = (float)($m['price'] ?? 0);
                                $line = $qty * $price;
                                $items[] = [ 'description' => ($m['name'] ?? 'Material') . ' (x' . ($qty) . ')', 'amount' => $line ];
                                $items_total += $line;
                            }
                        }
                    }
                }
            }
        }
    }
}

// 3) If items_total < billing total, add a consultation/other fee line to show remainder
$billing_total = (float)($billing['total_amount'] ?? 0.0);
$remainder = $billing_total - $items_total;
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
                            <td><strong>Total</strong></td>
                            <td class="text-end"><strong>₱<?php echo htmlspecialchars(number_format($items_total,2)); ?></strong></td>
                        </tr>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>
</div>
