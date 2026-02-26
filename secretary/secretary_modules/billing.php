<?php
include dirname(dirname(__DIR__)) . '/config/database.php';

$action = $_GET['action'] ?? '';
$today = date('Y-m-d');

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
        b.created_at,
        a.appointment_date,
        p.first_name,
        p.last_name,
        p.email,
        p.phone_number,
        u.first_name as doctor_first_name,
        u.last_name as doctor_last_name,
            MAX(q.que_number) AS que_number
    FROM billing b
    INNER JOIN appointments a ON b.appointment_id = a.appointment_id
    INNER JOIN patients p ON a.patient_id = p.patient_id
    LEFT JOIN users u ON a.user_id = u.user_id
        -- payments removed: no JOIN to payment
    LEFT JOIN check_in_queue q ON a.appointment_id = q.appointment_id
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
            u.last_name as doctor_last_name,
            MAX(q.que_number) AS que_number
        FROM billing b
        INNER JOIN appointments a ON b.appointment_id = a.appointment_id
        INNER JOIN patients p ON a.patient_id = p.patient_id
        LEFT JOIN users u ON a.user_id = u.user_id
        LEFT JOIN check_in_queue q ON a.appointment_id = q.appointment_id
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
    <?php unset($_SESSION['message']); unset($_SESSION['message_type']); ?>
<?php endif; ?>

<!-- Billing Summary Cards -->
<div class="row mb-4">
    <div class="col-md-4">
        <div class="card">
            <div class="card-body text-center">
                <h6 class="card-title text-muted">Total Billings</h6>
                <h3 class="mb-0">₱<?php 
                    $totalSum = 0;
                    foreach ($billings as $b) $totalSum += (float)$b['total_amount'];
                    echo number_format($totalSum, 2);
                ?></h3>
                <small class="text-muted"><?php echo count($billings); ?> invoices</small>
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
        <!-- Filters -->
        <div class="row mb-3 align-items-center">
            <div class="col-md-3">
                <select id="billingFilterStatus" class="form-select">
                    <option value="all">All Statuses</option>
                    <option value="unpaid">Outstanding</option>
                    <option value="partial">Partial</option>
                    <option value="paid">Paid</option>
                </select>
            </div>
            <div class="col-md-5">
                <input id="billingSearch" type="text" class="form-control" placeholder="Search by patient name or invoice #">
            </div>
            <div class="col-md-4 text-end">
                <small class="text-muted">Showing <span id="billingCount"><?php echo count($billings); ?></span> records</small>
            </div>
        </div>

        <div class="table-responsive">
            <table class="table table-hover datatable" id="billingTable">
                <thead>
                    <tr>
                        <th>Queue</th>
                        <th>Invoice #</th>
                        <th>Patient</th>
                        <th>Doctor</th>
                        <th>Service Date</th>
                        <th>Amount</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($billings)): ?>
                                <tr>
                                    <td colspan="7" class="text-center text-muted py-4">
                                        No billing records yet.
                                    </td>
                                </tr>
                    <?php else: ?>
                        <?php foreach ($billings as $billing): ?>
                                    <?php
                                    // each row includes data attributes for client-side filtering
                                    ?>
                                    <tr data-patient="<?php echo htmlspecialchars($billing['first_name'] . ' ' . $billing['last_name']); ?>" data-invoice="<?php echo htmlspecialchars(str_pad((string)$billing['billing_id'], 6, '0', STR_PAD_LEFT)); ?>">
                                <td><?php echo htmlspecialchars($billing['que_number'] ?? '—'); ?></td>
                                <td><strong>#<?php echo htmlspecialchars(str_pad((string)$billing['billing_id'], 6, '0', STR_PAD_LEFT)); ?></strong></td>
                                <td><?php echo htmlspecialchars($billing['first_name'] . ' ' . $billing['last_name']); ?></td>
                                <td><?php echo htmlspecialchars(($billing['doctor_first_name'] ?? 'N/A') . ' ' . ($billing['doctor_last_name'] ?? '')); ?></td>
                                <td><?php echo htmlspecialchars($billing['appointment_date']); ?></td>
                                        <td>₱<?php echo number_format((float)$billing['total_amount'], 2); ?></td>
                                        <td>
                                            <button type="button" class="btn btn-sm btn-info" data-bs-toggle="modal" data-bs-target="#invoiceModal"
                                                    onclick="viewInvoice(<?php echo htmlspecialchars(json_encode($billing)); ?>)">
                                                <i class="bi bi-file-pdf"></i> Invoice
                                            </button>
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

<!-- Payments are out-of-scope: payment and receipt UI removed (invoices only) -->

<!-- Initialize DataTable -->
<script>
    function viewInvoice(billing) {
        // fetch clinical record for this appointment to include in invoice (secretary view)
            const appRoot = window.location.pathname.split('/').slice(0,2).join('/') || '';
            fetch(appRoot + '/assets/api/ehr/get_clinical_record.php?appointment_id=' + encodeURIComponent(billing.appointment_id))
                .then(r=>r.json()).then(data=>{
                const rec = (data && data.record) ? data.record : null;
                // Prepare recommended services (may be array of objects from API)
                let servicesArr = [];
                if (rec && rec.recommended_services) {
                    if (Array.isArray(rec.recommended_services)) servicesArr = rec.recommended_services;
                    else if (typeof rec.recommended_services === 'object') servicesArr = Object.values(rec.recommended_services);
                }
                const servicesHtml = servicesArr.length ? servicesArr.map(s => `${s.service_name || s.name || 'Service'} — ₱${(parseFloat(s.initial_price||s.price)||0).toFixed(2)}`).join('<br>') : 'N/A';
                let servicesRows = '';
                let servicesTotal = 0.0;
                servicesArr.forEach(s => {
                    const price = parseFloat(s.initial_price || s.price) || 0;
                    servicesTotal += price;
                    servicesRows += `<tr><td>${(s.service_name || s.name || 'Service')}</td><td class="text-end">₱${price.toFixed(2)}</td></tr>`;
                });
                // Normalize materials into an array (handle array or object-shaped JSON)
                let matArr = [];
                if (rec && rec.materials_used) {
                    if (Array.isArray(rec.materials_used)) matArr = rec.materials_used;
                    else if (typeof rec.materials_used === 'object') matArr = Object.values(rec.materials_used);
                }
                const materialsHtml = matArr.length ? matArr.map(m=>`${m.name} (x${m.qty}) ₱${(parseFloat(m.price)||0).toFixed(2)}`).join('<br>') : '';
                // Build itemized rows for invoice table
                let materialsRows = '';
                let materialsTotal = 0.0;
                matArr.forEach(m => {
                    const qty = parseFloat(m.qty) || 0;
                    const price = parseFloat(m.price) || 0;
                    const line = qty * price;
                    materialsTotal += line;
                    materialsRows += `<tr><td>${(m.name || 'Material')} (x${qty})</td><td class="text-end">₱${line.toFixed(2)}</td></tr>`;
                });
                const billingTotal = parseFloat(billing.total_amount) || 0;
                const consultationFee = Math.max(0, billingTotal - materialsTotal - servicesTotal);
                const notes = rec && rec.doctor_notes ? rec.doctor_notes : '';

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
                        ${consultationFee > 0 ? `<tr><td>Consultation & Treatment Fee</td><td class="text-end">₱${consultationFee.toFixed(2)}</td></tr>` : ''}
                        ${servicesRows}
                        ${materialsRows}
                        <tr class="table-light">
                            <td><strong>Total Amount Due:</strong></td>
                            <td class="text-end"><strong>₱${billingTotal.toFixed(2)}</strong></td>
                        </tr>
                    </tbody>
                </table>
                
                <!-- Payments removed: invoices only -->
                
                <hr>
                <h6>Clinical Summary (Doctor)</h6>
                <p class="mb-1"><strong>Recommended Services:</strong> ${svcHtml}</p>
                <p class="mb-1"><strong>Treatment Plan:</strong> ${rec && rec.treatment_plan ? (typeof rec.treatment_plan === 'string' ? rec.treatment_plan : JSON.stringify(rec.treatment_plan)) : 'N/A'}</p>
                <p class="mb-1"><strong>Materials Used:</strong><br>${materialsHtml || 'N/A'}</p>
                <p class="mb-1"><strong>Follow-up Date:</strong> ${rec && rec.follow_up_date ? rec.follow_up_date : 'N/A'}</p>
                <p class="mb-1"><strong>Doctor Notes:</strong><br>${notes ? notes.replace(/\n/g,'<br>') : 'N/A'}</p>
                <hr>
                <p class="text-center text-muted small">Thank you for choosing Azucena's Dental Clinic!</p>
            </div>
        `;
                document.getElementById('invoiceContent').innerHTML = invoiceHtml;
            }).catch(err=>{
                console.error('Could not load clinical record', err);
            });
    }
    
    // Payments are out-of-scope: payment JS removed
    
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
    
    // Client-side filtering for billing table
    function filterBillingTable() {
        const status = document.getElementById('billingFilterStatus').value;
        const q = (document.getElementById('billingSearch').value || '').toLowerCase().trim();
        const tbody = document.querySelector('#billingTable tbody');
        if (!tbody) return;
        let visible = 0;
        Array.from(tbody.querySelectorAll('tr')).forEach(row => {
            const rowStatus = (row.getAttribute('data-status') || '').toLowerCase();
            const patient = (row.getAttribute('data-patient') || '').toLowerCase();
            const invoice = (row.getAttribute('data-invoice') || '').toLowerCase();
            let match = true;
            if (status !== 'all' && rowStatus !== status) match = false;
            if (q) {
                if (!(patient.includes(q) || invoice.includes(q))) match = false;
            }
            row.style.display = match ? '' : 'none';
            if (match) visible++;
        });
        document.getElementById('billingCount').textContent = visible;
    }

    document.addEventListener('DOMContentLoaded', function() {
        // Attach filter handlers
        const statusEl = document.getElementById('billingFilterStatus');
        const searchEl = document.getElementById('billingSearch');
        if (statusEl) statusEl.addEventListener('change', filterBillingTable);
        if (searchEl) searchEl.addEventListener('input', function(){
            // debounce simple
            clearTimeout(searchEl._timer);
            searchEl._timer = setTimeout(filterBillingTable, 200);
        });

        // Initialize table helper if available (non-blocking)
        if (window.SimpleDataTable) {
            try {
                new SimpleDataTable({
                    element: document.querySelector('.datatable'),
                    searchable: true,
                    sortable: true,
                    rowsPerPage: 10
                });
            } catch (e) { console.warn('SimpleDataTable init failed', e); }
        }
    });
</script>
