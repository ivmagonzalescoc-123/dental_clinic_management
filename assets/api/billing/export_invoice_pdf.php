<?php
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

include dirname(dirname(dirname(__DIR__))) . '/config/database.php';
include_once dirname(dirname(dirname(__DIR__))) . '/config/billing_helpers.php';
require_once dirname(dirname(dirname(__DIR__))) . '/vendor/autoload.php';

use Dompdf\Dompdf;
use Dompdf\Options;

if (!in_array($_SESSION['role'] ?? '', ['secretary', 'admin', 'patient'], true)) {
    http_response_code(403);
    echo 'Unauthorized';
    exit;
}

$billingId = (int)($_GET['billing_id'] ?? 0);
if ($billingId <= 0) {
    http_response_code(400);
    echo 'Invalid billing ID';
    exit;
}

billing_ensure_tables($conn);
billing_migrate_billing_columns($conn);
billing_sync_from_payments($conn, $billingId);

$stmt = $conn->prepare('
    SELECT b.billing_id, b.appointment_id, b.total_amount, b.amount_paid, b.status, b.created_at,
           b.subtotal_amount, b.discount_type, b.discount_percent, b.discount_notes,
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
    http_response_code(404);
    echo 'Billing not found';
    exit;
}

// A patient may only export their own invoice.
if (($_SESSION['role'] ?? '') === 'patient') {
    $ownerStmt = $conn->prepare('SELECT a.patient_id FROM billing b INNER JOIN appointments a ON a.appointment_id = b.appointment_id WHERE b.billing_id = ? LIMIT 1');
    $ownerStmt->bind_param('i', $billingId);
    $ownerStmt->execute();
    $ownerRow = $ownerStmt->get_result()->fetch_assoc();
    $ownerStmt->close();
    if (!$ownerRow || (int)$ownerRow['patient_id'] !== (int)($_SESSION['patient_id'] ?? 0)) {
        http_response_code(403);
        echo 'Unauthorized';
        exit;
    }
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
        $tstmt = $conn->prepare('SELECT term_number, due_date, amount, paid_amount, status, paid_at FROM billing_installment_terms WHERE installment_id = ? ORDER BY term_number');
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
$balance = max(0, (float)$billing['total_amount'] - $paidTotal);
$billingSubtotal = (float)($billing['subtotal_amount'] ?? $billing['total_amount']);
$discountType = $billing['discount_type'] ?? 'none';
$discountPercent = (float)($billing['discount_percent'] ?? 0);
$discountAmount = $billingSubtotal * $discountPercent / 100;
$discountNotes = $billing['discount_notes'] ?? '';
$billingFullyPaid = $balance <= 0 && $paidTotal > 0;
$invoiceNum = $invoice ? $invoice['invoice_number'] : str_pad((string)$billingId, 6, '0', STR_PAD_LEFT);

function pdf_esc($v)
{
    return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
}

$itemRows = '';
foreach ($lineItems as $item) {
    $itemRows .= '<tr><td>' . pdf_esc($item['description']) . '</td><td class="amt">&#8369;' . number_format((float)$item['amount'], 2) . '</td></tr>';
}

$discountRowHtml = '';
if ($discountAmount > 0 && $discountType !== 'none') {
    $discountLabel = 'Discount';
    if ($discountType === 'senior') {
        $discountLabel = 'Senior Citizen Discount (' . $discountPercent . '%)';
    } elseif ($discountType === 'pwd') {
        $discountLabel = 'PWD Discount (' . $discountPercent . '%)';
    } elseif ($discountType === 'custom') {
        $discountLabel = 'Custom Discount (' . $discountPercent . '%)';
    }
    $discountRowHtml = '<tr><td>' . pdf_esc($discountLabel . ($discountNotes ? ' — ' . $discountNotes : '')) . '</td><td class="amt green">-&#8369;' . number_format($discountAmount, 2) . '</td></tr>';
}

$instRows = '';
if ($installment) {
    foreach ($terms as $t) {
        $termStatus = ($t['status'] === 'paid' || $billingFullyPaid) ? 'paid' : $t['status'];
        $termAmount = (float)$t['amount'];
        $termPaid = (float)($t['paid_amount'] ?? 0);
        $termRemaining = $billingFullyPaid ? 0 : max(0, round($termAmount - $termPaid, 2));
        $amountLabel = ($termStatus === 'partial')
            ? number_format($termRemaining, 2) . ' remaining of &#8369;' . number_format($termAmount, 2)
            : number_format($termAmount, 2);
        $instRows .= '<tr><td>Term ' . (int)$t['term_number'] . ' — due ' . pdf_esc($t['due_date']) . ' (' . pdf_esc($termStatus) . ')</td><td class="amt">&#8369;' . $amountLabel . '</td></tr>';
    }
}

$payRows = '';
foreach ($payments as $p) {
    $orLabel = $p['or_number'] ? ('OR ' . pdf_esc($p['or_number']) . ' — ') : '';
    $payRows .= '<tr><td>' . $orLabel . pdf_esc($p['payment_method']) . ' — ' . pdf_esc($p['paid_at']) . '</td><td class="amt">&#8369;' . number_format((float)$p['amount'], 2) . '</td></tr>';
}

$clinicName = function_exists('email_clinic_name') ? email_clinic_name() : "Azucena's Dental Clinic";

/**
 * The clinic logo as an inline image (remote/file loading is off for Dompdf). The source
 * PNG is 1024px and ~1.4 MB, so it is scaled down first to keep the PDF small.
 */
function invoice_logo_data_uri(): string
{
    $path = dirname(dirname(__DIR__)) . '/img/clinic-logo.png';
    if (!is_file($path)) {
        return '';
    }
    if (function_exists('imagecreatefrompng') && function_exists('imagecopyresampled')) {
        $src = @imagecreatefrompng($path);
        if ($src) {
            $size = 200;
            $dst = imagecreatetruecolor($size, $size);
            imagealphablending($dst, false);
            imagesavealpha($dst, true);
            imagefill($dst, 0, 0, imagecolorallocatealpha($dst, 0, 0, 0, 127));
            imagecopyresampled($dst, $src, 0, 0, 0, 0, $size, $size, imagesx($src), imagesy($src));
            ob_start();
            imagepng($dst, null, 9);
            $png = ob_get_clean();
            imagedestroy($src);
            imagedestroy($dst);
            if ($png) {
                return 'data:image/png;base64,' . base64_encode($png);
            }
        }
    }
    return 'data:image/png;base64,' . base64_encode((string)file_get_contents($path));
}
$logoUri = invoice_logo_data_uri();

$html = '<!DOCTYPE html><html><head><meta charset="UTF-8"><style>
body { font-family: DejaVu Sans, sans-serif; font-size: 12px; color: #1e293b; }
h1 { font-size: 20px; margin: 0 0 4px; text-align: center; }
.sub { text-align: center; color: #64748b; margin: 0 0 20px; }
table { width: 100%; border-collapse: collapse; margin-bottom: 14px; }
th, td { border: 1px solid #cbd5e1; padding: 6px 8px; text-align: left; }
th { background: #f1f5f9; }
.amt { text-align: right; }
.green { color: #16a34a; }
.info-table td { border: none; padding: 2px 0; }
.section-title { font-size: 13px; font-weight: bold; margin: 14px 0 6px; }
.footer { text-align: center; color: #64748b; margin-top: 16px; font-size: 11px; }
.logo { text-align: center; margin: 0 0 6px; }
.logo img { width: 80px; height: 80px; }
</style></head><body>
' . ($logoUri !== '' ? '<div class="logo"><img src="' . $logoUri . '" alt=""></div>' : '') . '
<h1>' . pdf_esc($clinicName) . '</h1>
<p class="sub">Patient Invoice</p>
<table class="info-table" style="border:none;">
<tr>
<td style="width:50%;border:none;vertical-align:top;">
<strong>Invoice #:</strong> ' . pdf_esc($invoiceNum) . '<br>
<strong>Date:</strong> ' . pdf_esc(explode(' ', (string)$billing['created_at'])[0]) . '<br>
<strong>Service Date:</strong> ' . pdf_esc($billing['appointment_date']) . '<br>
<strong>Status:</strong> ' . pdf_esc(strtoupper($billing['status'] ?? 'unpaid')) . '
</td>
<td style="width:50%;border:none;vertical-align:top;text-align:right;">
<strong>' . pdf_esc($billing['first_name'] . ' ' . $billing['last_name']) . '</strong><br>
Email: ' . pdf_esc($billing['email']) . '<br>
Phone: ' . pdf_esc($billing['phone_number']) . '
</td>
</tr>
</table>
<table>
<thead><tr><th>Description</th><th class="amt">Amount</th></tr></thead>
<tbody>
' . $itemRows . '
<tr style="background:#f8fafc;"><td><strong>Subtotal:</strong></td><td class="amt"><strong>&#8369;' . number_format($billingSubtotal, 2) . '</strong></td></tr>
' . $discountRowHtml . '
<tr style="background:#f8fafc;"><td><strong>Total Amount Due:</strong></td><td class="amt"><strong>&#8369;' . number_format((float)$billing['total_amount'], 2) . '</strong></td></tr>
<tr><td>Paid to Date</td><td class="amt">&#8369;' . number_format($paidTotal, 2) . '</td></tr>
<tr><td>Balance</td><td class="amt"><strong>&#8369;' . number_format($balance, 2) . '</strong></td></tr>
</tbody>
</table>';

if ($instRows !== '') {
    $html .= '<div class="section-title">Installment Schedule</div><table><tbody>' . $instRows . '</tbody></table>';
}
if ($payRows !== '') {
    $html .= '<div class="section-title">Payments</div><table><tbody>' . $payRows . '</tbody></table>';
}

$html .= '<p class="footer">Thank you for choosing ' . pdf_esc($clinicName) . '!</p>
</body></html>';

$options = new Options();
$options->set('isRemoteEnabled', false);
$options->set('defaultFont', 'DejaVu Sans');

$dompdf = new Dompdf($options);
$dompdf->loadHtml($html);
$dompdf->setPaper('A4', 'portrait');
$dompdf->render();

$filename = 'Invoice-' . $invoiceNum . '.pdf';
$dompdf->stream($filename, ['Attachment' => true]);
