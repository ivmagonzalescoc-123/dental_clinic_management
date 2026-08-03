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
    ORDER BY b.created_at DESC
    ";

    $result = null;
    try {
        $result = $conn->query($billing_sql);
    } catch (mysqli_sql_exception $e) {
        // If query fails, fall back to a safe query
        $billing_sql_no_payment = "
        SELECT
            b.billing_id,
            b.appointment_id,
            b.total_amount,
            COALESCE(b.amount_paid, 0) AS amount_paid,
            COALESCE(b.balance, b.total_amount) AS balance,
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

// Fetch payment history
$payments = [];
if (table_exists($conn, 'payments')) {
    $paySql = "
        SELECT p.payment_id, p.billing_id, p.amount, p.payment_method, p.or_number, p.notes, p.paid_at,
               b.total_amount, b.balance,
               a.appointment_date,
               pt.first_name, pt.last_name,
               u.first_name AS recorded_by_name
        FROM payments p
        INNER JOIN billing b ON p.billing_id = b.billing_id
        INNER JOIN appointments a ON b.appointment_id = a.appointment_id
        INNER JOIN patients pt ON a.patient_id = pt.patient_id
        LEFT JOIN users u ON p.recorded_by = u.user_id
        ORDER BY p.paid_at DESC
    ";
    try {
        $payResult = $conn->query($paySql);
        if ($payResult) {
            while ($pRow = $payResult->fetch_assoc()) {
                $payments[] = $pRow;
            }
        }
    } catch (Throwable $e) {
        error_log('Billing page: payments query failed: ' . $e->getMessage());
    }
}

// --- Dashboard Stats ---
$thisMonth = date('Y-m');
$pendingCount = 0;
$overdueCount = 0;
$collectThisMonth = 0.0;

foreach ($billings as $b) {
    $status = strtolower((string)($b['status'] ?? 'unpaid'));
    $balance = (float)($b['balance'] ?? ((float)($b['total_amount'] ?? 0) - (float)($b['amount_paid'] ?? 0)));

    if ($status !== 'paid' && $balance > 0) {
        $pendingCount++;
        $collectThisMonth += $balance;
    }
    if (billing_is_overdue($conn, (int)($b['billing_id'] ?? 0), $status, $balance)) {
        $overdueCount++;
    }
}

// Monthly collection data for chart (last 6 months)
$monthlyData = [];
for ($i = 5; $i >= 0; $i--) {
    $mDate = date('Y-m', strtotime("-{$i} months"));
    $mLabel = date('M Y', strtotime("-{$i} months"));
    $mTotal = 0.0;
    foreach ($billings as $b) {
        $bCreated = substr((string)($b['created_at'] ?? ''), 0, 7);
        if ($bCreated === $mDate) {
            $mTotal += (float)($b['total_amount'] ?? 0);
        }
    }
    $monthlyData[] = ['label' => $mLabel, 'total' => $mTotal];
}
// Ensure at least a baseline so the chart always shows a line
$hasAnyData = false;
foreach ($monthlyData as $md) { if ($md['total'] > 0) { $hasAnyData = true; break; } }
if (!$hasAnyData) {
    $monthlyData[0]['total'] = 0;
    $monthlyData[count($monthlyData) - 1]['total'] = 0;
}
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
    <div class="col-md-3">
        <div class="card border-0 shadow-sm">
            <div class="card-body text-center">
                <div class="mb-2"><i class="bi bi-receipt text-primary" style="font-size:1.6rem;"></i></div>
                <h6 class="text-muted mb-1">Total Billings</h6>
                <h4 class="mb-0 fw-bold">₱<?php
                $totalSum = 0;
                foreach ($billings as $b)
                    $totalSum += (float) $b['total_amount'];
                echo number_format($totalSum, 2);
                ?></h4>
                <small class="text-muted"><?php echo count($billings); ?> invoices</small>
            </div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card border-0 shadow-sm">
            <div class="card-body text-center">
                <div class="mb-2"><i class="bi bi-person-exclamation text-warning" style="font-size:1.6rem;"></i></div>
                <h6 class="text-muted mb-1">Pending Payments</h6>
                <h4 class="mb-0 fw-bold"><?php echo $pendingCount; ?></h4>
                <small class="text-muted">patients with balance</small>
            </div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card border-0 shadow-sm">
            <div class="card-body text-center">
                <div class="mb-2"><i class="bi bi-cash-stack text-success" style="font-size:1.6rem;"></i></div>
                <h6 class="text-muted mb-1">To Collect</h6>
                <h4 class="mb-0 fw-bold">₱<?php echo number_format($collectThisMonth, 2); ?></h4>
                <small class="text-muted">outstanding balance</small>
            </div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card border-0 shadow-sm">
            <div class="card-body text-center">
                <div class="mb-2"><i class="bi bi-exclamation-triangle text-danger" style="font-size:1.6rem;"></i></div>
                <h6 class="text-muted mb-1">Overdue</h6>
                <h4 class="mb-0 fw-bold"><?php echo $overdueCount; ?></h4>
                <small class="text-muted">past due date</small>
            </div>
        </div>
    </div>
</div>

<!-- Monthly Collection Chart -->
<div class="card border-0 shadow-sm mb-4">
    <div class="card-header">
        <h6 class="mb-0"><i class="bi bi-graph-up"></i> Monthly Billings Overview</h6>
    </div>
    <div class="card-body">
        <div id="billingChart"></div>
    </div>
</div>

<!-- Billing Tabs -->
<ul class="nav nav-tabs mb-3" id="billingTabs">
    <li class="nav-item">
        <a class="nav-link active" data-bs-toggle="tab" href="#tabBillingRecords" style="font-size:0.85rem;">
            <i class="bi bi-receipt"></i> Billing Records
        </a>
    </li>
    <li class="nav-item">
        <a class="nav-link" data-bs-toggle="tab" href="#tabPaymentHistory" style="font-size:0.85rem;">
            <i class="bi bi-cash-stack"></i> Payment History
        </a>
    </li>
</ul>

<div class="tab-content">
    <!-- Billing Records Tab -->
    <div class="tab-pane fade show active" id="tabBillingRecords">
        <div class="card border-0">
            <div class="card-header">
                <h2 class="card-title mb-0">
                    <i class="bi bi-receipt"></i> Billing Records
                </h2>
            </div>
            <div class="card-body">
                <!-- Filters -->
                <div class="module-search-toolbar mb-3 flex-wrap gap-2">
            <select id="billingFilterStatus" class="form-select form-select-sm" style="width: auto; min-width: 160px;">
                <option value="all">All Statuses</option>
                <option value="unpaid">Outstanding</option>
                <option value="partial">Partial</option>
                <option value="paid">Paid</option>
                <option value="overdue">Overdue</option>
            </select>
            <input type="date" id="billingFilterDateFrom" class="form-control form-control-sm" style="width: auto; min-width: 140px;" title="From date">
            <span class="text-muted align-self-center">–</span>
            <input type="date" id="billingFilterDateTo" class="form-control form-control-sm" style="width: auto; min-width: 140px;" title="To date">
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
                                data-date="<?php echo htmlspecialchars($billing['appointment_date']); ?>"
                                data-overdue="<?php
                                    $bStatus = strtolower($billing['status'] ?? 'unpaid');
                                    $bBalance = (float)($billing['balance'] ?? 0);
                                    $bBillingId = (int)($billing['billing_id'] ?? 0);
                                    echo billing_is_overdue($conn, $bBillingId, $bStatus, $bBalance) ? 1 : 0;
                                ?>"
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
                                        <?php
                                        // Check if billing has an installment plan
                                        $hasInstallment = false;
                                        $downPaymentAmount = 0;
                                        $nextTermAmount = 0;
                                        $instCheck = $conn->prepare('SELECT installment_id, down_payment_amount FROM billing_installments WHERE billing_id = ? LIMIT 1');
                                        if ($instCheck) {
                                            $instCheck->bind_param('i', $billing['billing_id']);
                                            $instCheck->execute();
                                            $instRow = $instCheck->get_result()->fetch_assoc();
                                            $instCheck->close();
                                            if ($instRow) {
                                                $hasInstallment = true;
                                                $downPaymentAmount = (float)($instRow['down_payment_amount'] ?? 0);
                                                // Get first pending term amount
                                                $termCheck = $conn->prepare('SELECT amount FROM billing_installment_terms WHERE installment_id = ? AND status = "pending" ORDER BY term_number ASC LIMIT 1');
                                                if ($termCheck) {
                                                    $termCheck->bind_param('i', $instRow['installment_id']);
                                                    $termCheck->execute();
                                                    $termRow = $termCheck->get_result()->fetch_assoc();
                                                    $termCheck->close();
                                                    if ($termRow) {
                                                        $nextTermAmount = (float)($termRow['amount'] ?? 0);
                                                    }
                                                }
                                            }
                                        }
                                        $paymentAmount = $hasInstallment ? ($nextTermAmount > 0 ? $nextTermAmount : $downPaymentAmount) : $balance;
                                        ?>
                                        <button type="button" class="btn btn-sm btn-success ms-1"
                                            onclick="openPaymentModal(<?php echo (int)$billing['billing_id']; ?>, <?php echo $paymentAmount; ?>, <?php echo $balance; ?>, <?php echo $hasInstallment ? 'true' : 'false'; ?>)">
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
        </div><!-- end card-body -->
        </div><!-- end card -->
    </div><!-- end billing records tab pane -->

    <!-- Payment History Tab -->
    <div class="tab-pane" id="tabPaymentHistory">
        <div class="card border-0">
            <div class="card-body">
                <div class="module-search-toolbar mb-3 flex-wrap gap-2">
                    <select id="paymentFilterMethod" class="form-select form-select-sm" style="width: auto; min-width: 140px;">
                        <option value="all">All Methods</option>
                        <option value="cash">Cash</option>
                        <option value="card">Card</option>
                        <option value="gcash">GCash</option>
                        <option value="bank_transfer">Bank Transfer</option>
                    </select>
                    <input type="date" id="paymentFilterDateFrom" class="form-control form-control-sm" style="width: auto; min-width: 140px;" title="From date">
                    <span class="text-muted align-self-center">–</span>
                    <input type="date" id="paymentFilterDateTo" class="form-control form-control-sm" style="width: auto; min-width: 140px;" title="To date">
                    <div class="unified-table-search-wrap">
                        <i class="bi bi-search unified-table-search-icon" aria-hidden="true"></i>
                        <input id="paymentSearch" type="search" class="form-control unified-table-search-input"
                            placeholder="Search patient, OR number..." aria-label="Search payments">
                        <button type="button" class="unified-table-search-clear" id="paymentSearchClear" aria-label="Clear search" style="display:none;">&times;</button>
                    </div>
                    <small class="text-muted">Showing <span id="paymentCount"><?php echo count($payments); ?></span> payments</small>
                </div>

                <div class="table-responsive">
                    <table class="table table-hover" id="paymentTable" data-skip-table-search="1">
                        <thead>
                            <tr>
                                <th>OR #</th>
                                <th>Patient</th>
                                <th>Amount</th>
                                <th>Method</th>
                                <th>Balance After</th>
                                <th>Date</th>
                                <th>Recorded By</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($payments)): ?>
                                <tr>
                                    <td colspan="7" class="text-center text-muted py-4">No payments recorded yet.</td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($payments as $pay): ?>
                                    <tr class="payment-data-row"
                                        data-search="<?php echo htmlspecialchars(strtolower(
                                            $pay['first_name'] . ' ' . $pay['last_name'] . ' ' .
                                            $pay['or_number'] . ' ' . $pay['payment_method']
                                        )); ?>"
                                        data-method="<?php echo htmlspecialchars(strtolower($pay['payment_method'])); ?>"
                                        data-date="<?php echo htmlspecialchars(date('Y-m-d', strtotime($pay['paid_at']))); ?>">
                                        <td><strong><?php echo htmlspecialchars($pay['or_number']); ?></strong></td>
                                        <td><?php echo htmlspecialchars($pay['first_name'] . ' ' . $pay['last_name']); ?></td>
                                        <td class="text-success fw-bold">₱<?php echo number_format((float)$pay['amount'], 2); ?></td>
                                        <td><?php echo htmlspecialchars(ucfirst($pay['payment_method'])); ?></td>
                                        <td>₱<?php echo number_format((float)($pay['balance'] ?? 0), 2); ?></td>
                                        <td><?php echo htmlspecialchars(date('M d, Y g:i A', strtotime($pay['paid_at']))); ?></td>
                                        <td><?php echo htmlspecialchars($pay['recorded_by_name'] ?? 'System'); ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</div><!-- end tab-content -->
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
                <button type="button" class="btn btn-outline-primary" id="invoiceExportPdfBtn" onclick="exportInvoicePdf()">
                    <i class="bi bi-file-earmark-pdf"></i> Export to PDF
                </button>
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
                    <label class="form-label">Amount Tendered (PHP)</label>
                    <input type="number" step="0.01" min="0" class="form-control" id="paymentAmountTendered" placeholder="Cash handed by patient">
                </div>
                <div class="mb-3">
                    <p class="text-muted small mb-0">Change: <span id="paymentChangeDisplay">₱0.00</span></p>
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

    function updatePaymentChangeDisplay() {
        const amount = Number(document.getElementById('paymentAmount').value || 0);
        const tendered = Number(document.getElementById('paymentAmountTendered').value || 0);
        const change = Math.max(0, tendered - amount);
        document.getElementById('paymentChangeDisplay').textContent = '₱' + change.toFixed(2);
    }
    document.getElementById('paymentAmount')?.addEventListener('input', updatePaymentChangeDisplay);
    document.getElementById('paymentAmountTendered')?.addEventListener('input', updatePaymentChangeDisplay);

    function openPaymentModal(billingId, paymentAmount, remainingBalance, isInstallment) {
        document.getElementById('paymentBillingId').value = billingId;
        const amount = Number(paymentAmount || 0);
        const balance = Number(remainingBalance || 0);
        const amountInput = document.getElementById('paymentAmount');
        amountInput.value = amount.toFixed(2);
        amountInput.max = balance.toFixed(2);
        document.getElementById('paymentAmountTendered').value = '';
        document.getElementById('paymentOrNumber').value = '';
        updatePaymentChangeDisplay();
        if (isInstallment) {
            document.getElementById('paymentBalanceHint').textContent = 'Installment payment due: ₱' + amount.toFixed(2) + ' (Total remaining: ₱' + balance.toFixed(2) + ')';
        } else {
            document.getElementById('paymentBalanceHint').textContent = 'Remaining balance: ₱' + balance.toFixed(2);
        }
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

        const amountVal = Number(document.getElementById('paymentAmount').value || 0);
        const tenderedVal = Number(document.getElementById('paymentAmountTendered').value || 0);
        if (tenderedVal <= 0) {
            notifyBilling('Enter an amount tendered to record a payment.', 'danger');
            document.getElementById('paymentAmountTendered').focus();
            return;
        }
        if (tenderedVal < amountVal) {
            notifyBilling('Amount tendered cannot be less than the payment amount.', 'danger');
            document.getElementById('paymentAmountTendered').focus();
            return;
        }

        paymentSubmitting = true;
        const idempotencyKey = (window.Idempotency && Idempotency.newKey('pay')) || ('pay-' + Date.now() + '-' + Math.random().toString(36).slice(2));

        try {
            const fd = new FormData();
            fd.append('csrf_token', BILLING_CSRF);
            fd.append('billing_id', document.getElementById('paymentBillingId').value);
            fd.append('amount', document.getElementById('paymentAmount').value);
            fd.append('payment_amount_tendered', document.getElementById('paymentAmountTendered').value);
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
</script>

<script>
    function exportInvoicePdf() {
        if (!window.currentInvoiceBillingId) return;
        window.open('../assets/api/billing/export_invoice_pdf.php?billing_id=' + encodeURIComponent(window.currentInvoiceBillingId), '_blank');
    }

    function viewInvoice(billing) {
        window.currentInvoiceBillingId = billing.billing_id;
        document.getElementById('invoiceContent').innerHTML = '<div class="text-center text-muted py-4"><div class="spinner-border spinner-border-sm me-2"></div> Loading invoice...</div>';
        fetch('../assets/api/billing/get_billing_details.php?billing_id=' + encodeURIComponent(billing.billing_id))
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
                document.getElementById('invoiceContent').innerHTML = '<div class="text-center text-danger py-4"><i class="bi bi-exclamation-triangle fs-3 d-block mb-2"></i>Unable to load invoice details. Please try again.</div>';
            });
    }

    function printInvoice() {
        const invoiceContent = document.getElementById('invoiceContent').innerHTML;
        const printWindow = window.open('', '', 'height=600,width=900');
        const scriptTag = '<scr' + 'ipt>window.print();</scr' + 'ipt>';
        printWindow.document.write(
            '<html><head><title>Invoice</title>' +
            '<link href="../assets/vendor/bootstrap/css/bootstrap.min.css" rel="stylesheet">' +
            '<style>body{font-family:Arial,sans-serif;}.container{margin:20px;}</style>' +
            '</head><body>' + invoiceContent +
            scriptTag +
            '</body></html>'
        );
        printWindow.document.close();
    }

    function filterBillingTable() {
        const status = (document.getElementById('billingFilterStatus')?.value || 'all').toLowerCase();
        const dateFrom = (document.getElementById('billingFilterDateFrom')?.value || '').trim();
        const dateTo = (document.getElementById('billingFilterDateTo')?.value || '').trim();
        const rawQ = (document.getElementById('billingSearch')?.value || '').toLowerCase().trim();
        const q = rawQ.replace(/^#/, '');
        const tbody = document.querySelector('#billingTable tbody');
        if (!tbody) return;

        let visible = 0;
        let noResultsRow = tbody.querySelector('tr.billing-no-results');

        Array.from(tbody.querySelectorAll('tr.billing-data-row')).forEach(row => {
            const rowStatus = (row.getAttribute('data-status') || '').toLowerCase();
            const rowDate = row.getAttribute('data-date') || '';
            const rowOverdue = row.getAttribute('data-overdue') === '1';
            const searchBlob = (row.getAttribute('data-search') || '').toLowerCase();
            let match = true;

            if (status === 'overdue') {
                if (!rowOverdue) match = false;
            } else if (status !== 'all' && rowStatus !== status) {
                match = false;
            }
            if (dateFrom && rowDate < dateFrom) {
                match = false;
            }
            if (dateTo && rowDate > dateTo) {
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
        const dateFromEl = document.getElementById('billingFilterDateFrom');
        const dateToEl = document.getElementById('billingFilterDateTo');
        const searchEl = document.getElementById('billingSearch');

        if (statusEl) statusEl.addEventListener('change', filterBillingTable);
        if (dateFromEl) dateFromEl.addEventListener('change', filterBillingTable);
        if (dateToEl) dateToEl.addEventListener('change', filterBillingTable);
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

<script>
    function filterPaymentTable() {
        const q = (document.getElementById('paymentSearch')?.value || '').toLowerCase().trim();
        const method = (document.getElementById('paymentFilterMethod')?.value || 'all').toLowerCase();
        const dateFrom = document.getElementById('paymentFilterDateFrom')?.value || '';
        const dateTo = document.getElementById('paymentFilterDateTo')?.value || '';
        const tbody = document.querySelector('#paymentTable tbody');
        if (!tbody) return;
        let visible = 0;
        Array.from(tbody.querySelectorAll('tr.payment-data-row')).forEach(row => {
            const searchBlob = (row.getAttribute('data-search') || '').toLowerCase();
            const rowMethod = (row.getAttribute('data-method') || '').toLowerCase();
            const rowDate = row.getAttribute('data-date') || '';
            const matchSearch = !q || searchBlob.includes(q);
            const matchMethod = method === 'all' || rowMethod === method;
            const matchDateFrom = !dateFrom || rowDate >= dateFrom;
            const matchDateTo = !dateTo || rowDate <= dateTo;
            const match = matchSearch && matchMethod && matchDateFrom && matchDateTo;
            row.style.display = match ? '' : 'none';
            if (match) visible++;
        });
        const countEl = document.getElementById('paymentCount');
        if (countEl) countEl.textContent = visible;
    }

    (function () {
        const paymentSearchEl = document.getElementById('paymentSearch');
        if (paymentSearchEl) {
            paymentSearchEl.addEventListener('input', function () {
                clearTimeout(paymentSearchEl._timer);
                paymentSearchEl._timer = setTimeout(filterPaymentTable, 300);
            });
        }
    })();
    document.getElementById('paymentFilterMethod')?.addEventListener('change', filterPaymentTable);
    document.getElementById('paymentFilterDateFrom')?.addEventListener('change', filterPaymentTable);
    document.getElementById('paymentFilterDateTo')?.addEventListener('change', filterPaymentTable);
    document.getElementById('paymentSearchClear')?.addEventListener('click', function() {
        var el = document.getElementById('paymentSearch');
        if (el) { clearTimeout(el._timer); el.value = ''; }
        filterPaymentTable();
    });
</script>

<script>
(function() {
    if (typeof ApexCharts === 'undefined') return;
    var el = document.getElementById('billingChart');
    if (!el) return;

    var labels = <?php echo json_encode(array_column($monthlyData, 'label')); ?>;
    var totals = <?php echo json_encode(array_map(fn($d) => round($d['total'], 2), $monthlyData)); ?>;

    var isDark = document.documentElement.getAttribute('data-theme') === 'dark';
    var textColor = isDark ? '#94a3b8' : '#6c757d';

    var opts = {
        chart: { type: 'area', height: 260, toolbar: { show: false }, foreColor: textColor },
        series: [{ name: 'Billings', data: totals }],
        legend: { show: true, position: 'top', horizontalAlign: 'right', fontSize: '12px', labels: { colors: textColor } },
        xaxis: { categories: labels, axisBorder: { show: true }, axisTicks: { show: true } },
        stroke: { curve: 'smooth', width: 3 },
        colors: ['#0d9488'],
        fill: { type: 'solid', opacity: 0 },
        dataLabels: { enabled: false },
        yaxis: { min: 0, forceNiceScale: true, show: true },
        grid: { show: true, strokeDashArray: 4, borderColor: isDark ? '#2f3d52' : '#e9ecef' },
        tooltip: {
            y: { formatter: function(val) { return '₱' + val.toLocaleString(undefined, {minimumFractionDigits:2}); } }
        }
    };
    new ApexCharts(el, opts).render();
})();
</script>