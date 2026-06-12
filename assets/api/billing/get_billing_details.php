<?php
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}
header('Content-Type: application/json');

include dirname(dirname(dirname(__DIR__))) . '/config/database.php';
include_once dirname(dirname(dirname(__DIR__))) . '/config/billing_helpers.php';

if (!in_array($_SESSION['role'] ?? '', ['secretary', 'admin', 'patient'], true)) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

$billingId = (int)($_GET['billing_id'] ?? 0);
if ($billingId <= 0) {
    echo json_encode(['success' => false, 'message' => 'Invalid billing ID']);
    exit;
}

billing_ensure_tables($conn);

billing_migrate_billing_columns($conn);
billing_sync_from_payments($conn, $billingId);

$stmt = $conn->prepare('
    SELECT b.billing_id, b.appointment_id, b.total_amount, b.amount_paid, b.balance, b.status, b.created_at,
           b.subtotal_amount, b.discount_type, b.discount_percent, b.discount_amount, b.discount_notes,
           a.appointment_date, p.first_name, p.last_name, p.email, p.phone_number
    FROM billing b
    INNER JOIN appointments a ON a.appointment_id = b.appointment_id
    INNER JOIN patients p ON p.patient_id = a.patient_id
    WHERE b.billing_id = ?
    LIMIT 1
');
$stmt->bind_param('i', $billingId);
$stmt->execute();
$billing = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$billing) {
    echo json_encode(['success' => false, 'message' => 'Billing not found']);
    exit;
}

$lineItems = [];
$li = $conn->prepare('SELECT description, amount, item_type FROM billing_line_items WHERE appointment_id = ? ORDER BY item_id');
if ($li) {
    $apptId = (int)$billing['appointment_id'];
    $li->bind_param('i', $apptId);
    $li->execute();
    $res = $li->get_result();
    while ($row = $res->fetch_assoc()) {
        $lineItems[] = $row;
    }
    $li->close();
}

$payments = [];
$pay = $conn->prepare('SELECT amount, payment_method, or_number, notes, paid_at FROM payments WHERE billing_id = ? ORDER BY paid_at DESC');
if ($pay) {
    $pay->bind_param('i', $billingId);
    $pay->execute();
    $res = $pay->get_result();
    while ($row = $res->fetch_assoc()) {
        $payments[] = $row;
    }
    $pay->close();
}

$installment = null;
$terms = [];
$inst = $conn->prepare('SELECT installment_id, total_amount, down_payment_percent, down_payment_amount, num_terms FROM billing_installments WHERE billing_id = ? LIMIT 1');
if ($inst) {
    $inst->bind_param('i', $billingId);
    $inst->execute();
    $installment = $inst->get_result()->fetch_assoc();
    $inst->close();
    if ($installment) {
        $tid = (int)$installment['installment_id'];
        $tstmt = $conn->prepare('SELECT term_number, due_date, amount, status, paid_at FROM billing_installment_terms WHERE installment_id = ? ORDER BY term_number');
        if ($tstmt) {
            $tstmt->bind_param('i', $tid);
            $tstmt->execute();
            $tres = $tstmt->get_result();
            while ($t = $tres->fetch_assoc()) {
                $terms[] = $t;
            }
            $tstmt->close();
        }
    }
}

$invoice = null;
$inv = $conn->prepare('SELECT invoice_number, issued_at FROM invoices WHERE billing_id = ? LIMIT 1');
if ($inv) {
    $inv->bind_param('i', $billingId);
    $inv->execute();
    $invoice = $inv->get_result()->fetch_assoc();
    $inv->close();
}

$paidTotal = (float)($billing['amount_paid'] ?? 0);
$balance = (float)($billing['balance'] ?? max(0, (float)$billing['total_amount'] - $paidTotal));

echo json_encode([
    'success' => true,
    'billing' => $billing,
    'line_items' => $lineItems,
    'payments' => $payments,
    'paid_total' => $paidTotal,
    'balance' => $balance,
    'installment' => $installment,
    'installment_terms' => $terms,
    'invoice' => $invoice,
]);
