<?php
include dirname(dirname(__DIR__)) . '/config/database.php';
include dirname(dirname(__DIR__)) . '/config/capabilities.php';
include_once dirname(dirname(__DIR__)) . '/config/security.php';
include_once dirname(dirname(__DIR__)) . '/config/system_logs.php';
include_once dirname(dirname(__DIR__)) . '/config/billing_helpers.php';

billing_ensure_tables($conn);
billing_sync_all($conn);

$action = $_GET['action'] ?? '';
$today = date('Y-m-d');
$csrf_token = security_generate_csrf_token();

// detect or create status column
$hasStatus = false;
$colRes = $conn->query("SHOW COLUMNS FROM billing LIKE 'status'");
if ($colRes && $colRes->num_rows > 0) {
    $hasStatus = true;
} else {
    try {
        $conn->query("ALTER TABLE billing ADD COLUMN status ENUM('unpaid','partial','paid') DEFAULT 'unpaid'");
        if ($conn->errno === 0)
            $hasStatus = true;
    } catch (Throwable $e) {
        // ignore - leave $hasStatus false
    }
}

// Fetch all billing records for completed appointments
$billings = [];

// Fetch billing records (include queue number and allow viewing outstanding/paid)
// Guard: ensure required tables exist before running the query
if (!function_exists('table_exists') || (table_exists($conn, 'billing') && table_exists($conn, 'appointments') && table_exists($conn, 'patients'))) {
    $billing_sql = "
    SELECT
        b.billing_id,
        b.appointment_id,
        b.total_amount,
        b.amount_paid,
        b.balance,
        b.created_at,
        " . ($hasStatus ? 'b.status,' : "'unpaid' AS status,") . "
        a.appointment_date,
        p.first_name,
        p.last_name,
        p.email,
        p.phone_number,
        u.first_name as doctor_first_name,
        u.last_name as doctor_last_name
    FROM billing b
    INNER JOIN appointments a ON b.appointment_id = a.appointment_id
    INNER JOIN patients p ON a.patient_id = p.patient_id
    LEFT JOIN users u ON a.user_id = u.user_id
    GROUP BY b.billing_id
    ORDER BY b.created_at DESC
    ";

    $result = null;
    try {
        $result = $conn->query($billing_sql);
    } catch (mysqli_sql_exception $e) {
        // If query fails because the `payment` table doesn't exist, fall back to a safe query
        $billing_sql_no_payment = "
        SELECT
            b.billing_id,
            b.appointment_id,
            b.total_amount,
            b.created_at,
            a.appointment_date,
            p.first_name,
            p.last_name,
            p.email,
            p.phone_number,
            u.first_name as doctor_first_name,
            u.last_name as doctor_last_name
        FROM billing b
        INNER JOIN appointments a ON b.appointment_id = a.appointment_id
        INNER JOIN patients p ON a.patient_id = p.patient_id
        LEFT JOIN users u ON a.user_id = u.user_id
        GROUP BY b.billing_id
        ORDER BY b.created_at DESC
    ";
        $result = $conn->query($billing_sql_no_payment);
    }
    if ($result) {
        while ($row = $result->fetch_assoc()) {
            $billings[] = $row;
        }
    }
} else {
    // required tables missing; log and leave $billings empty to avoid fatal errors
    error_log('Billing page: missing required tables (billing/appointments/patients). Skipping billing query.');
}

// Payments removed: no payment methods
?>

<!-- Alert Messages -->
<?php if (isset($_SESSION['message'])): ?>
    <div class="alert alert-<?php echo $_SESSION['message_type']; ?> alert-dismissible fade show" role="alert">
        <?php echo $_SESSION['message']; ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
    </div>
    <?php unset($_SESSION['message']);
    unset($_SESSION['message_type']); ?>
<?php endif; ?>

<!-- Billing Summary Cards -->
<div class="row mb-4">
    <div class="col-md-4">
        <div class="card">
            <div class="card-body text-center">
                <h6 class="card-title text-muted">Total Billings</h6>
                <h3 class="mb-0">₱<?php
                $totalSum = 0;
                foreach ($billings as $b)
                    $totalSum += (float) $b['total_amount'];
                echo number_format($totalSum, 2);
                ?></h3>
                <small class="text-muted"><?php echo count($billings); ?> invoices</small>
            </div>
        </div>
    </div>
</div>

<!-- Billing Records -->
<div class="card border-0">
    <div class="card-header">
        <h2 class="card-title mb-0">
            <i class="bi bi-receipt"></i> Billing Records
        </h2>
    </div>
    <div class="card-body">
        <!-- Filters -->
        <div class="module-search-toolbar mb-3">
            <select id="billingFilterStatus" class="form-select form-select-sm" style="width: auto; min-width: 160px;">
                <option value="all">All Statuses</option>
                <option value="unpaid">Outstanding</option>
                <option value="partial">Partial</option>
                <option value="paid">Paid</option>
            </select>
            <div class="unified-table-search-wrap">
                <i class="bi bi-search unified-table-search-icon" aria-hidden="true"></i>
                <input id="billingSearch" type="search" class="form-control unified-table-search-input"
                    placeholder="Search patient, doctor, or invoice #" aria-label="Search billing records">
                <button type="button" class="unified-table-search-clear" id="billingSearchClear" aria-label="Clear search" style="display:none;">&times;</button>
            </div>
            <small class="text-muted">Showing <span id="billingCount"><?php echo count($billings); ?></span> records</small>
        </div>

        <div class="table-responsive">
            <table class="table table-hover" id="billingTable" data-skip-table-search="1" data-skip-datatable="1">
                <thead>
                    <tr>
                        <th>Invoice #</th>
                        <th>Patient</th>
                        <th>Doctor</th>
                        <th>Service Date</th>
                        <th>Total</th>
                        <th>Paid</th>
                        <th>Balance</th>
                        <th>Status</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($billings)): ?>
                        <tr>
                            <td colspan="9" class="text-center text-muted py-4">
                                No billing records yet.
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($billings as $billing): ?>
                            <?php
                            // each row includes data attributes for client-side filtering
                            ?>
                            <tr class="billing-data-row" data-status="<?php echo htmlspecialchars(strtolower($billing['status'] ?? 'unpaid')); ?>"
                                data-patient="<?php echo htmlspecialchars(strtolower($billing['first_name'] . ' ' . $billing['last_name'])); ?>"
                                data-doctor="<?php echo htmlspecialchars(strtolower(trim(($billing['doctor_first_name'] ?? '') . ' ' . ($billing['doctor_last_name'] ?? '')))); ?>"
                                data-invoice="<?php echo htmlspecialchars(str_pad((string) $billing['billing_id'], 6, '0', STR_PAD_LEFT)); ?>"
                                data-search="<?php echo htmlspecialchars(strtolower(
                                    $billing['first_name'] . ' ' . $billing['last_name'] . ' ' .
                                    ($billing['doctor_first_name'] ?? '') . ' ' . ($billing['doctor_last_name'] ?? '') . ' ' .
                                    str_pad((string) $billing['billing_id'], 6, '0', STR_PAD_LEFT) . ' ' .
                                    $billing['billing_id']
                                )); ?>">
                                <td><strong>#<?php echo htmlspecialchars(str_pad((string) $billing['billing_id'], 6, '0', STR_PAD_LEFT)); ?></strong>
                                </td>
                                <td><?php echo htmlspecialchars($billing['first_name'] . ' ' . $billing['last_name']); ?></td>
                                <td><?php echo htmlspecialchars(($billing['doctor_first_name'] ?? 'N/A') . ' ' . ($billing['doctor_last_name'] ?? '')); ?>
                                </td>
                                <td><?php echo htmlspecialchars($billing['appointment_date']); ?></td>
                                <td>₱<?php echo number_format((float) $billing['total_amount'], 2); ?></td>
                                <td class="text-success">₱<?php echo number_format((float) ($billing['amount_paid'] ?? 0), 2); ?></td>
                                <?php
                                $balance = (float)($billing['balance'] ?? max(0, (float)$billing['total_amount'] - (float)($billing['amount_paid'] ?? 0)));
                                $st = strtolower($billing['status'] ?? 'unpaid');
                                $badgeClass = $st === 'paid' ? 'success' : ($st === 'partial' ? 'warning' : 'danger');
                                ?>
                                <td class="<?php echo $balance > 0 ? 'text-danger' : 'text-success'; ?>">
                                    <strong>₱<?php echo number_format($balance, 2); ?></strong>
                                </td>
                                <td><span class="badge bg-<?php echo $badgeClass; ?>"><?php echo htmlspecialchars(ucfirst($st)); ?></span></td>
                                <td>
                                    <button type="button" class="btn btn-sm btn-info" data-bs-toggle="modal"
                                        data-bs-target="#invoiceModal"
                                        onclick="viewInvoice(<?php echo htmlspecialchars(json_encode($billing)); ?>)">
                                        <i class="bi bi-file-pdf"></i> Invoice
                                    </button>

                                    <?php if ($hasStatus && (($_SESSION['role'] ?? '') === 'secretary') && $st !== 'paid' && $balance > 0): ?>
                                        <button type="button" class="btn btn-sm btn-success ms-1"
                                            onclick="openPaymentModal(<?php echo (int)$billing['billing_id']; ?>, <?php echo $balance; ?>)">
                                            Record Payment
                                        </button>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- Invoice Modal -->
<div class="modal fade" id="invoiceModal" tabindex="-1" role="dialog" aria-hidden="true">
    <div class="modal-dialog modal-lg" role="document">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">
                    <i class="bi bi-file-pdf"></i> Invoice
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body" id="invoiceContent" style="max-height: 70vh; overflow-y: auto;">
                <!-- Invoice will be generated here -->
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                <button type="button" class="btn btn-primary" onclick="printInvoice()">
                    <i class="bi bi-printer"></i> Print
                </button>
            </div>
        </div>
    </div>
</div>

<div class="modal fade" id="paymentModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Record Payment</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <input type="hidden" id="paymentBillingId">
                <p class="text-muted small mb-2" id="paymentBalanceHint">Remaining balance: ₱0.00</p>
                <div class="mb-3">
                    <label class="form-label">Payment Amount (PHP)</label>
                    <input type="number" step="0.01" min="0.01" class="form-control" id="paymentAmount">
                </div>
                <div class="mb-3">
                    <label class="form-label">Payment Method</label>
                    <select class="form-select" id="paymentMethod">
                        <option value="cash">Cash</option>
                        <option value="gcash">GCash</option>
                        <option value="card">Card</option>
                        <option value="bank">Bank Transfer</option>
                    </select>
                </div>
                <div class="mb-3">
                    <label class="form-label">OR Number <span class="text-danger">*</span></label>
                    <input type="text" class="form-control" id="paymentOrNumber" maxlength="50" required placeholder="Official receipt number">
                </div>
                <div class="mb-3">
                    <label class="form-label">Notes</label>
                    <input type="text" class="form-control" id="paymentNotes" placeholder="Optional">
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                <button type="button" class="btn btn-success" id="savePaymentBtn">Save Payment</button>
            </div>
        </div>
    </div>
</div>

<script>
    const BILLING_CSRF = '<?php echo htmlspecialchars($csrf_token); ?>';

    function openPaymentModal(billingId, remainingBalance) {
        document.getElementById('paymentBillingId').value = billingId;
        const balance = Number(remainingBalance || 0);
        const amountInput = document.getElementById('paymentAmount');
        amountInput.value = balance.toFixed(2);
        amountInput.max = balance.toFixed(2);
        document.getElementById('paymentOrNumber').value = '';
        document.getElementById('paymentBalanceHint').textContent = 'Remaining balance: ₱' + balance.toFixed(2);
        new bootstrap.Modal(document.getElementById('paymentModal')).show();
    }

    let paymentSubmitting = false;

    function notifyBilling(message, type) {
        if (typeof window.portalNotify === 'function') {
            window.portalNotify(String(message || ''), type || 'info');
            return;
        }
        if (typeof window.showAppToast === 'function') {
            window.showAppToast(String(message || ''), type || 'info');
            return;
        }
        if (typeof window.showToast === 'function') {
            window.showToast(String(message || ''), type || 'info');
        }
    }

    document.getElementById('savePaymentBtn')?.addEventListener('click', async function() {
        if (paymentSubmitting) return;

        const orNumber = (document.getElementById('paymentOrNumber').value || '').trim();
        if (!orNumber) {
            notifyBilling('OR number is required', 'danger');
            document.getElementById('paymentOrNumber').focus();
            return;
        }

        paymentSubmitting = true;
        const idempotencyKey = (window.Idempotency && Idempotency.newKey('pay')) || ('pay-' + Date.now() + '-' + Math.random().toString(36).slice(2));

        try {
            const fd = new FormData();
            fd.append('csrf_token', BILLING_CSRF);
            fd.append('billing_id', document.getElementById('paymentBillingId').value);
            fd.append('amount', document.getElementById('paymentAmount').value);
            fd.append('payment_method', document.getElementById('paymentMethod').value);
            fd.append('or_number', orNumber);
            fd.append('notes', document.getElementById('paymentNotes').value);
            fd.append('idempotency_key', idempotencyKey);
            const res = await fetch('../assets/api/billing/record_payment.php', { method: 'POST', body: fd });
            const j = await res.json();
            if (!j.success) {
                notifyBilling(j.message || 'Payment failed', 'danger');
                paymentSubmitting = false;
                return;
            }

            const paymentModalEl = document.getElementById('paymentModal');
            const paymentModal = paymentModalEl ? bootstrap.Modal.getInstance(paymentModalEl) : null;
            if (paymentModal) paymentModal.hide();

            notifyBilling(
                j.duplicate_replay || j.idempotent_replay ? 'Payment already recorded.' : 'Payment recorded successfully',
                'success'
            );
            setTimeout(function() { location.reload(); }, 1500);
        } catch (err) {
            console.error(err);
            notifyBilling('Payment failed. Please try again.', 'danger');
            paymentSubmitting = false;
        }
    });

    function viewInvoice(billing) {
        const appRoot = window.location.pathname.split('/').slice(0, 2).join('/') || '';
        fetch(appRoot + '/assets/api/billing/get_billing_details.php?billing_id=' + encodeURIComponent(billing.billing_id))
            .then(r => r.json()).then(details => {
                if (!details.success) return;
                const lineItems = details.line_items || [];
                let itemRows = '';
                lineItems.forEach(item => {
                    const amt = parseFloat(item.amount) || 0;
                    itemRows += `<tr><td>${item.description}</td><td class="text-end">₱${amt.toFixed(2)}</td></tr>`;
                });
                let payRows = '';
                (details.payments || []).forEach(p => {
                    const orLabel = p.or_number ? `OR ${p.or_number} — ` : '';
                    payRows += `<tr><td>${orLabel}${p.payment_method} — ${p.paid_at}</td><td class="text-end">₱${(parseFloat(p.amount)||0).toFixed(2)}</td></tr>`;
                });
                let instRows = '';
                const invoiceBalance = parseFloat(details.balance) || 0;
                const invoicePaid = parseFloat(details.paid_total) || 0;
                const billingFullyPaid = invoiceBalance <= 0 && invoicePaid > 0;
                if (details.installment) {
                    instRows += `<p class="small mb-1"><strong>Installment Plan:</strong> 50% down (₱${parseFloat(details.installment.down_payment_amount).toFixed(2)}) + ${details.installment.num_terms} terms</p>`;
                    (details.installment_terms || []).forEach(t => {
                        const termStatus = (t.status === 'paid' || billingFullyPaid) ? 'paid' : t.status;
                        instRows += `<tr><td>Term ${t.term_number} — due ${t.due_date} (${termStatus})</td><td class="text-end">₱${(parseFloat(t.amount)||0).toFixed(2)}</td></tr>`;
                    });
                }
                const billingTotal = parseFloat(billing.total_amount) || 0;
                const billingSubtotal = parseFloat(details.billing?.subtotal_amount ?? billingTotal) || billingTotal;
                const discountType = details.billing?.discount_type || 'none';
                const discountPercent = parseFloat(details.billing?.discount_percent || 0) || 0;
                const discountAmount = parseFloat(details.billing?.discount_amount || 0) || 0;
                const discountNotes = details.billing?.discount_notes || '';
                let discountRowHtml = '';
                if (discountAmount > 0 && discountType !== 'none') {
                    let discountLabel = 'Discount';
                    if (discountType === 'senior') discountLabel = 'Senior Citizen Discount (' + discountPercent + '%)';
                    else if (discountType === 'pwd') discountLabel = 'PWD Discount (' + discountPercent + '%)';
                    else if (discountType === 'custom') discountLabel = 'Custom Discount (' + discountPercent + '%)';
                    discountRowHtml = `<tr><td>${discountLabel}${discountNotes ? ' — ' + discountNotes : ''}</td><td class="text-end text-success">-₱${discountAmount.toFixed(2)}</td></tr>`;
                }
                const invoiceNum = details.invoice ? details.invoice.invoice_number : String(billing.billing_id).padStart(6, '0');
                const invoiceHtml = `
            <div class="container mt-4">
                <div class="text-center mb-4">
                    <img src="../assets/img/clinic-logo.png" alt="Clinic Logo" style="max-height: 80px; margin-bottom: 10px;">
                    <h4>Azucena's Dental Clinic</h4>
                    <p class="text-muted">Patient Invoice</p>
                </div>
                
                <div class="row mb-4">
                    <div class="col-6">
                        <h6>Invoice Details:</h6>
                        <p class="mb-1"><strong>Invoice #:</strong> ${invoiceNum}</p>
                        <p class="mb-1"><strong>Date:</strong> ${billing.created_at.split(' ')[0]}</p>
                        <p class="mb-1"><strong>Service Date:</strong> ${billing.appointment_date}</p>
                        <p class="mb-1"><strong>Status:</strong> ${ (billing.status || 'unpaid').toUpperCase() }</p>
                    </div>
                    <div class="col-6 text-end">
                        <h6>Patient Information:</h6>
                        <p class="mb-1"><strong>${billing.first_name} ${billing.last_name}</strong></p>
                        <p class="mb-1">Email: ${billing.email}</p>
                        <p class="mb-0">Phone: ${billing.phone_number}</p>
                    </div>
                </div>
                
                <hr>
                
                    <table class="table table-bordered invoice-summary-table" data-skip-table-search="1">
                    <thead class="table-light">
                        <tr>
                            <th>Description</th>
                            <th class="text-end">Amount</th>
                        </tr>
                    </thead>
                    <tbody>
                        ${itemRows}
                        <tr class="table-light">
                            <td><strong>Subtotal:</strong></td>
                            <td class="text-end"><strong>₱${billingSubtotal.toFixed(2)}</strong></td>
                        </tr>
                        ${discountRowHtml}
                        <tr class="table-light">
                            <td><strong>Total Amount Due:</strong></td>
                            <td class="text-end"><strong>₱${billingTotal.toFixed(2)}</strong></td>
                        </tr>
                        <tr><td>Paid to Date</td><td class="text-end">₱${(parseFloat(details.paid_total)||0).toFixed(2)}</td></tr>
                        <tr><td>Balance</td><td class="text-end"><strong>₱${(parseFloat(details.balance)||0).toFixed(2)}</strong></td></tr>
                    </tbody>
                </table>
                ${instRows ? `<h6 class="mt-3">Installment Schedule</h6><table class="table table-sm table-bordered invoice-detail-table" data-skip-table-search="1"><tbody>${instRows}</tbody></table>` : ''}
                ${payRows ? `<h6 class="mt-3">Payments</h6><table class="table table-sm table-bordered invoice-detail-table" data-skip-table-search="1"><tbody>${payRows}</tbody></table>` : ''}
                <hr>
                <p class="text-center text-muted small">Thank you for choosing Azucena's Dental Clinic!</p>
            </div>
        `;
                document.getElementById('invoiceContent').innerHTML = invoiceHtml;
            }).catch(err => {
                console.error('Could not load billing details', err);
            });
    }

    function printInvoice() {
        const invoiceContent = document.getElementById('invoiceContent').innerHTML;
        const printWindow = window.open('', '', 'height=600,width=900');
        printWindow.document.write(`
            <html>
            <head>
                <title>Invoice</title>
                <link href="../assets/vendor/bootstrap/css/bootstrap.min.css" rel="stylesheet">
                <style>
                    body { font-family: Arial, sans-serif; }
                    .container { margin: 20px; }
                </style>
            </head>
            <body>
                ${invoiceContent}
                <script>window.print();<\/script>
            </body>
            </html>
        `);
        printWindow.document.close();
    }

    function filterBillingTable() {
        const status = (document.getElementById('billingFilterStatus')?.value || 'all').toLowerCase();
        const rawQ = (document.getElementById('billingSearch')?.value || '').toLowerCase().trim();
        const q = rawQ.replace(/^#/, '');
        const tbody = document.querySelector('#billingTable tbody');
        if (!tbody) return;

        let visible = 0;
        let noResultsRow = tbody.querySelector('tr.billing-no-results');

        Array.from(tbody.querySelectorAll('tr.billing-data-row')).forEach(row => {
            const rowStatus = (row.getAttribute('data-status') || '').toLowerCase();
            const searchBlob = (row.getAttribute('data-search') || '').toLowerCase();
            let match = true;

            if (status !== 'all' && rowStatus !== status) {
                match = false;
            }
            if (q && !searchBlob.includes(q)) {
                match = false;
            }

            row.style.display = match ? '' : 'none';
            if (match) visible++;
        });

        const countEl = document.getElementById('billingCount');
        if (countEl) countEl.textContent = visible;

        const dataRows = tbody.querySelectorAll('tr.billing-data-row');
        if (dataRows.length > 0 && visible === 0) {
            if (!noResultsRow) {
                noResultsRow = document.createElement('tr');
                noResultsRow.className = 'billing-no-results';
                noResultsRow.innerHTML = '<td colspan="9" class="text-center text-muted py-4">No billing records match your filters.</td>';
                tbody.appendChild(noResultsRow);
            }
            noResultsRow.style.display = '';
        } else if (noResultsRow) {
            noResultsRow.style.display = 'none';
        }
    }

    document.addEventListener('DOMContentLoaded', function () {
        const statusEl = document.getElementById('billingFilterStatus');
        const searchEl = document.getElementById('billingSearch');

        if (statusEl) statusEl.addEventListener('change', filterBillingTable);
        const clearEl = document.getElementById('billingSearchClear');
        if (searchEl) {
            searchEl.addEventListener('input', function () {
                if (clearEl) clearEl.style.display = searchEl.value ? '' : 'none';
                clearTimeout(searchEl._timer);
                searchEl._timer = setTimeout(filterBillingTable, 150);
            });
            searchEl.addEventListener('keydown', function (e) {
                if (e.key === 'Escape') {
                    searchEl.value = '';
                    if (clearEl) clearEl.style.display = 'none';
                    filterBillingTable();
                }
            });
        }
        if (clearEl && searchEl) {
            clearEl.addEventListener('click', function () {
                searchEl.value = '';
                clearEl.style.display = 'none';
                filterBillingTable();
                searchEl.focus();
            });
        }

        filterBillingTable();
    });
</script>