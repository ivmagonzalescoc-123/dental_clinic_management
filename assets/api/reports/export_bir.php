<?php
/**
 * BIR-oriented sales & collections export (CSV).
 * For tax record-keeping: gross sales, collections, and line-item detail.
 */

include dirname(dirname(dirname(__DIR__))) . '/config/session_check.php';
check_session(['admin']);

include dirname(dirname(dirname(__DIR__))) . '/config/database.php';
include_once dirname(dirname(dirname(__DIR__))) . '/config/billing_helpers.php';

billing_ensure_tables($conn);

$range = $_GET['range'] ?? '30d';
$startDate = $_GET['start_date'] ?? '';
$endDate = $_GET['end_date'] ?? date('Y-m-d');
$doctorId = isset($_GET['doctor_id']) && $_GET['doctor_id'] !== '' ? (int)$_GET['doctor_id'] : 0;

$today = date('Y-m-d');
if ($range === '7d') {
    $startDate = date('Y-m-d', strtotime('-6 days'));
    $endDate = $today;
} elseif ($range === '30d') {
    $startDate = date('Y-m-d', strtotime('-29 days'));
    $endDate = $today;
} elseif ($range === 'month') {
    $startDate = date('Y-m-01');
    $endDate = $today;
} else {
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $startDate)) {
        $startDate = date('Y-m-d', strtotime('-29 days'));
    }
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $endDate)) {
        $endDate = $today;
    }
}

$clinicName = "Azucena's Dental Clinic";
$doctorClause = $doctorId > 0 ? ' AND a.user_id = ?' : '';
$baseTypes = 'ss';
$baseParams = [$startDate, $endDate];
if ($doctorId > 0) {
    $baseTypes .= 'i';
    $baseParams[] = $doctorId;
}

function bir_csv_cell($value): string
{
    $text = (string)$value;
    $text = str_replace(["\r", "\n"], ' ', $text);
    if (strpbrk($text, ",\"\n") !== false) {
        return '"' . str_replace('"', '""', $text) . '"';
    }
    return $text;
}

function bir_csv_row(array $cells): string
{
    return implode(',', array_map('bir_csv_cell', $cells)) . "\r\n";
}

$totalBilled = 0.0;
$totalCollected = 0.0;
$totalOutstanding = 0.0;
$invoiceCount = 0;

$salesRows = [];
if (table_exists($conn, 'billing')) {
    $salesSql = "
        SELECT b.billing_id, b.total_amount, b.amount_paid, b.balance, b.status, b.created_at,
               a.appointment_date, a.appointment_id,
               p.first_name AS patient_first, p.last_name AS patient_last,
               d.first_name AS doctor_first, d.last_name AS doctor_last,
               inv.invoice_number
        FROM billing b
        INNER JOIN appointments a ON a.appointment_id = b.appointment_id
        INNER JOIN patients p ON p.patient_id = a.patient_id
        LEFT JOIN users d ON d.user_id = a.user_id
        LEFT JOIN invoices inv ON inv.billing_id = b.billing_id
        WHERE a.appointment_date BETWEEN ? AND ?{$doctorClause}
        ORDER BY a.appointment_date ASC, b.billing_id ASC
    ";
    $stmt = $conn->prepare($salesSql);
    if ($stmt) {
        $bind = [$baseTypes];
        foreach ($baseParams as $k => $v) {
            $bind[] = &$baseParams[$k];
        }
        call_user_func_array([$stmt, 'bind_param'], $bind);
        $stmt->execute();
        $res = $stmt->get_result();
        while ($row = $res->fetch_assoc()) {
            $salesRows[] = $row;
            $totalBilled += (float)($row['total_amount'] ?? 0);
            $totalCollected += (float)($row['amount_paid'] ?? 0);
            $totalOutstanding += (float)($row['balance'] ?? 0);
            $invoiceCount++;
        }
        $stmt->close();
    }
}

$paymentRows = [];
if (table_exists($conn, 'payments') && table_exists($conn, 'billing')) {
    $paySql = "
        SELECT pay.paid_at, pay.amount, pay.payment_method, pay.notes,
               b.billing_id, inv.invoice_number,
               p.first_name AS patient_first, p.last_name AS patient_last,
               a.appointment_date
        FROM payments pay
        INNER JOIN billing b ON b.billing_id = pay.billing_id
        INNER JOIN appointments a ON a.appointment_id = b.appointment_id
        INNER JOIN patients p ON p.patient_id = a.patient_id
        LEFT JOIN invoices inv ON inv.billing_id = b.billing_id
        WHERE DATE(pay.paid_at) BETWEEN ? AND ?{$doctorClause}
        ORDER BY pay.paid_at ASC
    ";
    $stmt = $conn->prepare($paySql);
    if ($stmt) {
        $bind = [$baseTypes];
        foreach ($baseParams as $k => $v) {
            $bind[] = &$baseParams[$k];
        }
        call_user_func_array([$stmt, 'bind_param'], $bind);
        $stmt->execute();
        $res = $stmt->get_result();
        while ($row = $res->fetch_assoc()) {
            $paymentRows[] = $row;
        }
        $stmt->close();
    }
}

$lineItemsByAppt = [];
if (table_exists($conn, 'billing_line_items') && !empty($salesRows)) {
    $apptIds = array_unique(array_map(static fn($r) => (int)$r['appointment_id'], $salesRows));
    if (!empty($apptIds)) {
        $in = implode(',', array_fill(0, count($apptIds), '?'));
        $types = str_repeat('i', count($apptIds));
        $liStmt = $conn->prepare("SELECT appointment_id, description, amount, item_type FROM billing_line_items WHERE appointment_id IN ($in) ORDER BY appointment_id, item_id");
        if ($liStmt) {
            $liStmt->bind_param($types, ...$apptIds);
            $liStmt->execute();
            $liRes = $liStmt->get_result();
            while ($li = $liRes->fetch_assoc()) {
                $aid = (int)$li['appointment_id'];
                if (!isset($lineItemsByAppt[$aid])) {
                    $lineItemsByAppt[$aid] = [];
                }
                $lineItemsByAppt[$aid][] = $li;
            }
            $liStmt->close();
        }
    }
}

$filename = 'BIR_Clinic_Report_' . $startDate . '_to_' . $endDate . '.csv';
header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Pragma: no-cache');
header('Expires: 0');

echo "\xEF\xBB\xBF";

echo bir_csv_row([$clinicName . ' — BIR Sales & Collections Report']);
echo bir_csv_row(['Reporting Period', $startDate . ' to ' . $endDate]);
echo bir_csv_row(['Generated On', date('Y-m-d H:i:s')]);
echo bir_csv_row([]);

echo bir_csv_row(['SUMMARY (for BIR record-keeping)']);
echo bir_csv_row(['Description', 'Amount (PHP)']);
echo bir_csv_row(['Total Gross Sales / Billed', number_format($totalBilled, 2, '.', '')]);
echo bir_csv_row(['Total Collections Received', number_format($totalCollected, 2, '.', '')]);
echo bir_csv_row(['Outstanding Receivables', number_format($totalOutstanding, 2, '.', '')]);
echo bir_csv_row(['Number of Invoices', (string)$invoiceCount]);
echo bir_csv_row(['Payment Transactions in Period', (string)count($paymentRows)]);
echo bir_csv_row([]);

echo bir_csv_row(['DETAILED SALES REGISTER']);
echo bir_csv_row([
    'Invoice No',
    'Service Date',
    'Patient Name',
    'Doctor',
    'Line Item',
    'Item Type',
    'Gross Amount (PHP)',
    'Collected (PHP)',
    'Balance (PHP)',
    'Billing Status',
]);

foreach ($salesRows as $sale) {
    $invoiceNo = $sale['invoice_number'] ?: ('INV-' . str_pad((string)$sale['billing_id'], 6, '0', STR_PAD_LEFT));
    $patient = trim(($sale['patient_first'] ?? '') . ' ' . ($sale['patient_last'] ?? ''));
    $doctor = trim(($sale['doctor_first'] ?? '') . ' ' . ($sale['doctor_last'] ?? ''));
    $apptId = (int)$sale['appointment_id'];
    $items = $lineItemsByAppt[$apptId] ?? [];

    if (empty($items)) {
        echo bir_csv_row([
            $invoiceNo,
            $sale['appointment_date'],
            $patient,
            $doctor,
            'Dental services (package)',
            'service',
            number_format((float)$sale['total_amount'], 2, '.', ''),
            number_format((float)$sale['amount_paid'], 2, '.', ''),
            number_format((float)$sale['balance'], 2, '.', ''),
            $sale['status'],
        ]);
        continue;
    }

    foreach ($items as $item) {
        echo bir_csv_row([
            $invoiceNo,
            $sale['appointment_date'],
            $patient,
            $doctor,
            $item['description'],
            $item['item_type'],
            number_format((float)$item['amount'], 2, '.', ''),
            '',
            '',
            $sale['status'],
        ]);
    }
}

echo bir_csv_row([]);
echo bir_csv_row(['COLLECTIONS / PAYMENT RECEIPTS']);
echo bir_csv_row([
    'Payment Date',
    'Invoice No',
    'Service Date',
    'Patient Name',
    'Amount Received (PHP)',
    'Payment Method',
    'Notes',
]);

foreach ($paymentRows as $pay) {
    $invoiceNo = $pay['invoice_number'] ?: ('INV-' . str_pad((string)$pay['billing_id'], 6, '0', STR_PAD_LEFT));
    $patient = trim(($pay['patient_first'] ?? '') . ' ' . ($pay['patient_last'] ?? ''));
    echo bir_csv_row([
        $pay['paid_at'],
        $invoiceNo,
        $pay['appointment_date'],
        $patient,
        number_format((float)$pay['amount'], 2, '.', ''),
        $pay['payment_method'],
        $pay['notes'] ?? '',
    ]);
}

exit;
