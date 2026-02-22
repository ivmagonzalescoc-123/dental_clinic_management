<?php
include dirname(dirname(__DIR__)) . '/config/database.php';

$action = $_GET['action'] ?? '';
$today = date('Y-m-d');

// Handle payment recording (AJAX)
if ($action === 'record_payment' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $billingId = (int)($_POST['billing_id'] ?? 0);
    $amountPaid = (float)($_POST['amount_paid'] ?? 0);
    $paymentMethod = $_POST['payment_method'] ?? '';
    $remarks = $_POST['remarks'] ?? '';
    
    if ($billingId <= 0 || $amountPaid <= 0 || empty($paymentMethod)) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Invalid payment data.']);
        exit;
    }
    
    $conn->begin_transaction();
    try {
        // Get billing info
        $stmt = $conn->prepare('SELECT b.total_amount, b.status, a.patient_id, p.first_name, p.last_name FROM billing b INNER JOIN appointments a ON b.appointment_id = a.appointment_id INNER JOIN patients p ON a.patient_id = p.patient_id WHERE b.billing_id = ?');
        if (!$stmt) throw new Exception('Error preparing statement.');
        $stmt->bind_param('i', $billingId);
        $stmt->execute();
        $res = $stmt->get_result();
        $billing = $res ? $res->fetch_assoc() : null;
        $stmt->close();
        
        if (!$billing) {
            throw new Exception('Billing record not found.');
        }
        
        $totalAmount = (float)$billing['total_amount'];
        
        // Check if amount exceeds total
        if ($amountPaid > $totalAmount) {
            throw new Exception('Payment amount cannot exceed total billing amount (₱' . number_format($totalAmount, 2) . ')');
        }
        
        // Auto-generate reference number (format: RCP-YYYYMMDD-HHMMSS-BILLINGID)
        $referenceNo = 'RCP-' . date('Ymd') . '-' . date('His') . '-' . str_pad($billingId, 5, '0', STR_PAD_LEFT);
        
        // Record payment
        $paymentDate = date('Y-m-d');
        $stmt = $conn->prepare('
            INSERT INTO payment (billing_id, payment_method, payment_date, amount_paid, reference_no, remarks)
            VALUES (?, ?, ?, ?, ?, ?)
        ');
        if (!$stmt) throw new Exception('Error preparing payment insert.');
        $stmt->bind_param('isdsss', $billingId, $paymentMethod, $paymentDate, $amountPaid, $referenceNo, $remarks);
        if (!$stmt->execute()) {
            throw new Exception('Error recording payment: ' . $stmt->error);
        }
        $stmt->close();
        
        // Get total paid so far
        $stmt = $conn->prepare('SELECT COALESCE(SUM(amount_paid), 0) AS total_paid FROM payment WHERE billing_id = ?');
        $totalPaid = 0;
        if ($stmt) {
            $stmt->bind_param('i', $billingId);
            $stmt->execute();
            $res = $stmt->get_result();
            if ($res && ($row = $res->fetch_assoc())) {
                $totalPaid = (float)($row['total_paid'] ?? 0);
            }
            $stmt->close();
        }
        
        // Update billing status
        if ($totalPaid >= $totalAmount) {
            $newStatus = 'paid';
        } elseif ($totalPaid > 0) {
            $newStatus = 'partial';
        } else {
            $newStatus = 'unpaid';
        }
        
        $stmt = $conn->prepare('UPDATE billing SET status = ?, processed_by = ? WHERE billing_id = ?');
        if ($stmt) {
            $secId = $_SESSION['user_id'] ?? 0;
            $stmt->bind_param('sii', $newStatus, $secId, $billingId);
            $stmt->execute();
            $stmt->close();
        }
        
        $conn->commit();
        
        // Return receipt data as JSON
        echo json_encode([
            'success' => true,
            'message' => 'Payment recorded successfully',
            'receipt' => [
                'referenceNo' => $referenceNo,
                'billingId' => str_pad($billingId, 6, '0', STR_PAD_LEFT),
                'patientName' => $billing['first_name'] . ' ' . $billing['last_name'],
                'paymentMethod' => $paymentMethod,
                'amountPaid' => $amountPaid,
                'totalAmount' => $totalAmount,
                'newStatus' => $newStatus,
                'paymentDate' => $paymentDate,
                'remarks' => $remarks
            ]
        ]);
    } catch (Exception $e) {
        $conn->rollback();
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => 'Error recording payment: ' . $e->getMessage()]);
    }
    exit;
}

// Fetch all billing records for completed appointments
$billings = [];
$billing_sql = "
    SELECT
        b.billing_id,
        b.appointment_id,
        b.total_amount,
        b.status,
        b.created_at,
        a.appointment_date,
        p.first_name,
        p.last_name,
        p.email,
        p.phone_number,
        u.first_name as doctor_first_name,
        u.last_name as doctor_last_name,
        COALESCE(SUM(pm.amount_paid), 0) AS total_paid
    FROM billing b
    INNER JOIN appointments a ON b.appointment_id = a.appointment_id
    INNER JOIN patients p ON a.patient_id = p.patient_id
    LEFT JOIN users u ON a.user_id = u.user_id
    LEFT JOIN payment pm ON b.billing_id = pm.billing_id
    WHERE a.status = 'completed'
    GROUP BY b.billing_id
    ORDER BY b.created_at DESC
";

$result = $conn->query($billing_sql);
if ($result) {
    while ($row = $result->fetch_assoc()) {
        $billings[] = $row;
    }
}

// Get payment methods
$paymentMethods = ['Cash', 'Check', 'Credit Card', 'Debit Card', 'Online Transfer', 'GCash', 'PayMaya'];
?>

<!-- Alert Messages -->
<?php if (isset($_SESSION['message'])): ?>
    <div class="alert alert-<?php echo $_SESSION['message_type']; ?> alert-dismissible fade show" role="alert">
        <?php echo $_SESSION['message']; ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
    </div>
    <?php unset($_SESSION['message']); unset($_SESSION['message_type']); ?>
<?php endif; ?>

<!-- Billing Summary Cards -->
<div class="row mb-4">
    <div class="col-md-3">
        <div class="card">
            <div class="card-body text-center">
                <h6 class="card-title text-muted">Total Billings</h6>
                <h3 class="mb-0">₱<?php 
                    $totalSum = 0;
                    foreach ($billings as $b) $totalSum += (float)$b['total_amount'];
                    echo number_format($totalSum, 2);
                ?></h3>
            </div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card">
            <div class="card-body text-center">
                <h6 class="card-title text-muted">Total Paid</h6>
                <h3 class="mb-0 text-success">₱<?php 
                    $totalPaidSum = 0;
                    foreach ($billings as $b) $totalPaidSum += (float)$b['total_paid'];
                    echo number_format($totalPaidSum, 2);
                ?></h3>
            </div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card">
            <div class="card-body text-center">
                <h6 class="card-title text-muted">Outstanding</h6>
                <h3 class="mb-0 text-danger">₱<?php 
                    echo number_format($totalSum - $totalPaidSum, 2);
                ?></h3>
            </div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card">
            <div class="card-body text-center">
                <h6 class="card-title text-muted">Fully Paid</h6>
                <h3 class="mb-0"><?php 
                    $paidCount = 0;
                    foreach ($billings as $b) if ($b['status'] === 'paid') $paidCount++;
                    echo $paidCount;
                ?></h3>
            </div>
        </div>
    </div>
</div>

<!-- Billing Records -->
<div class="card">
    <div class="card-header">
        <h2 class="card-title mb-0">
            <i class="bi bi-receipt"></i> Billing Records
        </h2>
    </div>
    <div class="card-body">
        <div class="table-responsive">
            <table class="table table-hover datatable">
                <thead>
                    <tr>
                        <th>Invoice #</th>
                        <th>Patient</th>
                        <th>Doctor</th>
                        <th>Service Date</th>
                        <th>Amount</th>
                        <th>Paid</th>
                        <th>Outstanding</th>
                        <th>Status</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($billings)): ?>
                        <tr>
                            <td colspan="9" class="text-center text-muted py-4">
                                No completed appointments with billing yet.
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($billings as $billing): ?>
                            <?php
                                $outstanding = (float)$billing['total_amount'] - (float)$billing['total_paid'];
                                $statusBadgeClass = $billing['status'] === 'paid' ? 'success' : ($billing['status'] === 'partial' ? 'warning' : 'danger');
                            ?>
                            <tr>
                                <td><strong>#<?php echo htmlspecialchars(str_pad((string)$billing['billing_id'], 6, '0', STR_PAD_LEFT)); ?></strong></td>
                                <td><?php echo htmlspecialchars($billing['first_name'] . ' ' . $billing['last_name']); ?></td>
                                <td><?php echo htmlspecialchars(($billing['doctor_first_name'] ?? 'N/A') . ' ' . ($billing['doctor_last_name'] ?? '')); ?></td>
                                <td><?php echo htmlspecialchars($billing['appointment_date']); ?></td>
                                <td>₱<?php echo number_format((float)$billing['total_amount'], 2); ?></td>
                                <td class="text-success"><strong>₱<?php echo number_format((float)$billing['total_paid'], 2); ?></strong></td>
                                <td class="text-danger">₱<?php echo number_format($outstanding, 2); ?></td>
                                <td>
                                    <span class="badge bg-<?php echo $statusBadgeClass; ?>">
                                        <?php echo htmlspecialchars(ucfirst($billing['status'])); ?>
                                    </span>
                                </td>
                                <td>
                                    <button type="button" class="btn btn-sm btn-info" data-bs-toggle="modal" data-bs-target="#invoiceModal"
                                            onclick="viewInvoice(<?php echo htmlspecialchars(json_encode($billing)); ?>)">
                                        <i class="bi bi-file-pdf"></i> Invoice
                                    </button>
                                    <?php if ($billing['status'] !== 'paid'): ?>
                                        <button type="button" class="btn btn-sm btn-success" data-bs-toggle="modal" data-bs-target="#paymentModal"
                                                onclick="setPaymentBilling(<?php echo $billing['billing_id']; ?>, <?php echo (float)$billing['total_amount']; ?>, <?php echo (float)$billing['total_paid']; ?>)">
                                            <i class="bi bi-cash-coin"></i> Pay
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

<!-- Payment Modal -->
<div class="modal fade" id="paymentModal" tabindex="-1" role="dialog" aria-hidden="true">
    <div class="modal-dialog" role="document">
        <div class="modal-content">
            <div class="modal-header bg-success text-white">
                <h5 class="modal-title">
                    <i class="bi bi-cash-coin"></i> Record Payment
                </h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <form id="paymentForm">
                <div class="modal-body">
                    <input type="hidden" id="paymentBillingId" name="billing_id">
                    
                    <div class="alert alert-info">
                        <strong>Total Amount:</strong> ₱<span id="paymentTotalAmount">0.00</span><br>
                        <strong>Already Paid:</strong> ₱<span id="paymentPaidAmount">0.00</span><br>
                        <strong>Remaining:</strong> ₱<span id="paymentRemaining">0.00</span>
                    </div>
                    
                    <div class="mb-3">
                        <label for="amountPaid" class="form-label"><strong>Amount to Pay</strong></label>
                        <input type="number" step="0.01" class="form-control" id="amountPaid" name="amount_paid" placeholder="0.00" required
                               onchange="validatePaymentAmount(this)">
                        <small class="text-muted">Must be ≤ ₱<span id="maxPayment">0.00</span></small>
                    </div>
                    
                    <div class="mb-3">
                        <label for="paymentMethod" class="form-label"><strong>Payment Method</strong></label>
                        <select class="form-select" id="paymentMethod" name="payment_method" required>
                            <option value="">-- Select Method --</option>
                            <?php foreach ($paymentMethods as $method): ?>
                                <option value="<?php echo htmlspecialchars($method); ?>"><?php echo htmlspecialchars($method); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    
                    <div class="mb-3">
                        <label for="remarks" class="form-label">Remarks (Optional)</label>
                        <textarea class="form-control" id="remarks" name="remarks" rows="2" placeholder="Any additional notes..."></textarea>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="button" class="btn btn-success" onclick="submitPayment()">
                        <i class="bi bi-check-circle"></i> Record Payment
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Receipt Modal -->
<div class="modal fade" id="receiptModal" tabindex="-1" role="dialog" aria-hidden="true">
    <div class="modal-dialog modal-lg" role="document">
        <div class="modal-content">
            <div class="modal-header bg-success text-white">
                <h5 class="modal-title">
                    <i class="bi bi-receipt"></i> Payment Receipt
                </h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close" onclick="location.reload()"></button>
            </div>
            <div class="modal-body" id="receiptContent" style="max-height: 70vh; overflow-y: auto;">
                <!-- Receipt will be generated here -->
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-primary" onclick="printReceipt()">
                    <i class="bi bi-printer"></i> Print
                </button>
                <button type="button" class="btn btn-success" data-bs-dismiss="modal" onclick="location.reload()">
                    <i class="bi bi-check-circle"></i> Close
                </button>
            </div>
        </div>
    </div>
</div>

<!-- Initialize DataTable -->
<script>
    function viewInvoice(billing) {
        const invoiceHtml = `
            <div class="container mt-4">
                <div class="text-center mb-4">
                    <img src="../assets/img/main_logo.png" alt="Clinic Logo" style="max-height: 80px; margin-bottom: 10px;">
                    <h4>Azucena's Dental Clinic</h4>
                    <p class="text-muted">Patient Invoice</p>
                </div>
                
                <div class="row mb-4">
                    <div class="col-6">
                        <h6>Invoice Details:</h6>
                        <p class="mb-1"><strong>Invoice #:</strong> ${String(billing.billing_id).padStart(6, '0')}</p>
                        <p class="mb-1"><strong>Date:</strong> ${billing.created_at.split(' ')[0]}</p>
                        <p class="mb-1"><strong>Service Date:</strong> ${billing.appointment_date}</p>
                    </div>
                    <div class="col-6 text-end">
                        <h6>Patient Information:</h6>
                        <p class="mb-1"><strong>${billing.first_name} ${billing.last_name}</strong></p>
                        <p class="mb-1">Email: ${billing.email}</p>
                        <p class="mb-0">Phone: ${billing.phone_number}</p>
                    </div>
                </div>
                
                <hr>
                
                <table class="table table-bordered">
                    <thead class="table-light">
                        <tr>
                            <th>Description</th>
                            <th class="text-end">Amount</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr>
                            <td>Consultation & Treatment Fee</td>
                            <td class="text-end">₱${parseFloat(billing.total_amount).toFixed(2)}</td>
                        </tr>
                        <tr class="table-light">
                            <td><strong>Total Amount Due:</strong></td>
                            <td class="text-end"><strong>₱${parseFloat(billing.total_amount).toFixed(2)}</strong></td>
                        </tr>
                    </tbody>
                </table>
                
                <div class="row mt-4">
                    <div class="col-6">
                        <h6>Payment Status:</h6>
                        <p class="mb-1"><strong>Total Amount:</strong> ₱${parseFloat(billing.total_amount).toFixed(2)}</p>
                        <p class="mb-1"><strong>Paid:</strong> ₱${parseFloat(billing.total_paid).toFixed(2)}</p>
                        <p class="mb-0"><strong>Outstanding:</strong> ₱${(parseFloat(billing.total_amount) - parseFloat(billing.total_paid)).toFixed(2)}</p>
                    </div>
                    <div class="col-6">
                        <p><span class="badge bg-${billing.status === 'paid' ? 'success' : (billing.status === 'partial' ? 'warning' : 'danger')}">
                            ${billing.status.toUpperCase()}
                        </span></p>
                    </div>
                </div>
                
                <hr>
                <p class="text-center text-muted small">Thank you for choosing Azucena's Dental Clinic!</p>
            </div>
        `;
        document.getElementById('invoiceContent').innerHTML = invoiceHtml;
    }
    
    function setPaymentBilling(billingId, totalAmount, totalPaid) {
        document.getElementById('paymentBillingId').value = billingId;
        document.getElementById('paymentTotalAmount').textContent = parseFloat(totalAmount).toFixed(2);
        document.getElementById('paymentPaidAmount').textContent = parseFloat(totalPaid).toFixed(2);
        const remaining = parseFloat(totalAmount) - parseFloat(totalPaid);
        document.getElementById('paymentRemaining').textContent = remaining.toFixed(2);
        document.getElementById('maxPayment').textContent = remaining.toFixed(2);
        document.getElementById('amountPaid').value = remaining.toFixed(2);
        document.getElementById('amountPaid').max = remaining.toFixed(2);
    }
    
    function validatePaymentAmount(input) {
        const max = parseFloat(document.getElementById('maxPayment').textContent);
        const amount = parseFloat(input.value);
        if (amount > max) {
            alert('Payment amount cannot exceed ₱' + max.toFixed(2));
            input.value = max.toFixed(2);
        }
    }
    
    function submitPayment() {
        const form = document.getElementById('paymentForm');
        const formData = new FormData(form);
        formData.append('action', 'record_payment');
        
        fetch('secretary.php?page=billing&action=record_payment', {
            method: 'POST',
            body: formData
        })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                // Close payment modal
                const paymentModal = bootstrap.Modal.getInstance(document.getElementById('paymentModal'));
                paymentModal.hide();
                
                // Show receipt
                displayReceipt(data.receipt);
                
                // Show receipt modal
                const receiptModal = new bootstrap.Modal(document.getElementById('receiptModal'));
                receiptModal.show();
            } else {
                alert('Error: ' + data.message);
            }
        })
        .catch(error => {
            console.error('Error:', error);
            alert('Error processing payment');
        });
    }
    
    function displayReceipt(receipt) {
        const receiptHtml = `
            <div class="container mt-4">
                <div class="text-center mb-4">
                    <img src="../assets/img/main_logo.png" alt="Clinic Logo" style="max-height: 80px; margin-bottom: 10px;">
                    <h4>Azucena's Dental Clinic</h4>
                    <p class="text-muted">Payment Receipt</p>
                </div>
                
                <div class="card mb-3">
                    <div class="card-body">
                        <div class="row mb-3">
                            <div class="col-6">
                                <h6>Receipt Details:</h6>
                                <p class="mb-1"><strong>Reference #:</strong> <span class="badge bg-primary">${receipt.referenceNo}</span></p>
                                <p class="mb-1"><strong>Invoice #:</strong> ${receipt.billingId}</p>
                                <p class="mb-0"><strong>Date:</strong> ${receipt.paymentDate}</p>
                            </div>
                            <div class="col-6">
                                <h6>Patient:</h6>
                                <p class="mb-0"><strong>${receipt.patientName}</strong></p>
                            </div>
                        </div>
                    </div>
                </div>
                
                <table class="table table-bordered">
                    <thead class="table-light">
                        <tr>
                            <th>Description</th>
                            <th class="text-end">Amount</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr>
                            <td>Payment Received</td>
                            <td class="text-end">₱${parseFloat(receipt.amountPaid).toFixed(2)}</td>
                        </tr>
                        <tr>
                            <td><strong>Payment Method:</strong></td>
                            <td class="text-end"><strong>${receipt.paymentMethod}</strong></td>
                        </tr>
                    </tbody>
                </table>
                
                <div class="row mt-4">
                    <div class="col-6">
                        <h6>Balance Summary:</h6>
                        <p class="mb-1"><strong>Total Amount:</strong> ₱${parseFloat(receipt.totalAmount).toFixed(2)}</p>
                        <p class="mb-1"><strong>Amount Paid:</strong> ₱${parseFloat(receipt.amountPaid).toFixed(2)}</p>
                        <p class="mb-0"><strong>Status:</strong> <span class="badge bg-${receipt.newStatus === 'paid' ? 'success' : 'warning'}">${receipt.newStatus.toUpperCase()}</span></p>
                    </div>
                    <div class="col-6">
                        ${receipt.remarks ? '<h6>Remarks:</h6><p>' + receipt.remarks + '</p>' : ''}
                    </div>
                </div>
                
                <hr>
                <p class="text-center text-muted small">Thank you for your payment!</p>
            </div>
        `;
        document.getElementById('receiptContent').innerHTML = receiptHtml;
    }
    
    function printReceipt() {
        const receiptContent = document.getElementById('receiptContent').innerHTML;
        const printWindow = window.open('', '', 'height=600,width=900');
        printWindow.document.write(`
            <html>
            <head>
                <title>Payment Receipt</title>
                <link href="../assets/vendor/bootstrap/css/bootstrap.min.css" rel="stylesheet">
                <style>
                    body { font-family: Arial, sans-serif; }
                    .container { margin: 20px; }
                </style>
            </head>
            <body>
                ${receiptContent}
                <script>window.print();<\/script>
            </body>
            </html>
        `);
        printWindow.document.close();
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
    
    document.addEventListener('DOMContentLoaded', function() {
        if (window.SimpleDataTable) {
            new SimpleDataTable({
                element: document.querySelector('.datatable'),
                searchable: true,
                sortable: true,
                rowsPerPage: 10
            });
        }
    });
</script>
