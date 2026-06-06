<div class="container-fluid">
    <h1 class="h3 mb-4 text-gray-800">Transaction History</h1>
    <div id="transactionAccordion">
        <!-- Transactions will be loaded here by JavaScript -->
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    fetch('../get_transactions.php')
        .then(response => response.json())
        .then(data => {
            const accordion = document.getElementById('transactionAccordion');
            if (data.success && data.transactions.length > 0) {
                data.transactions.forEach(appt => {
                    const card = document.createElement('div');
                    card.className = 'card shadow mb-3';
                    
                    let itemsHtml = '';
                    // Line items (services, materials)
                    appt.line_items.forEach(item => {
                        itemsHtml += `
                            <tr>
                                <td>${escapeHtml(item.description)}</td>
                                <td>${escapeHtml(item.tooth_no)}</td>
                                <td>${escapeHtml(item.remark)}</td>
                                <td class="text-end">${formatCurrency(item.charge)}</td>
                            </tr>
                        `;
                    });
                    // Odontogram findings
                    appt.odontogram_findings.forEach(finding => {
                         itemsHtml += `
                            <tr>
                                <td>Tooth Finding</td>
                                <td>${escapeHtml(finding.tooth_no)}</td>
                                <td>${escapeHtml(finding.remark)}</td>
                                <td class="text-end">${formatCurrency(0)}</td>
                            </tr>
                        `;
                    });

                    const balanceClass = appt.billing.balance > 0 ? 'text-danger' : 'text-success';

                    card.innerHTML = `
                        <div class="card-header" id="heading-${appt.appointment_id}">
                            <h2 class="mb-0">
                                <button class="btn btn-link btn-block text-start d-flex justify-content-between w-100" type="button" data-bs-toggle="collapse" data-bs-target="#collapse-${appt.appointment_id}" aria-expanded="false" aria-controls="collapse-${appt.appointment_id}">
                                    <span><strong>Date:</strong> ${appt.date}</span>
                                    <span class="ms-auto"><strong>Balance:</strong> <span class="${balanceClass}">${formatCurrency(appt.billing.balance)}</span></span>
                                </button>
                            </h2>
                        </div>
                        <div id="collapse-${appt.appointment_id}" class="collapse" aria-labelledby="heading-${appt.appointment_id}" data-bs-parent="#transactionAccordion">
                            <div class="card-body">
                                <h6 class="mb-3">Details for Appointment on ${appt.date}</h6>
                                <table class="table table-sm table-hover">
                                    <thead class="table-light">
                                        <tr>
                                            <th>Description</th>
                                            <th>Tooth No.</th>
                                            <th>Remarks</th>
                                            <th class="text-end">Charge</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        ${itemsHtml.length > 0 ? itemsHtml : '<tr><td colspan="4">No specific items recorded.</td></tr>'}
                                    </tbody>
                                    <tfoot>
                                        <tr class="table-group-divider">
                                            <td colspan="3" class="text-end"><strong>Total Charge:</strong></td>
                                            <td class="text-end">${formatCurrency(appt.billing.total_charge)}</td>
                                        </tr>
                                        <tr>
                                            <td colspan="3" class="text-end"><strong>Total Paid (Credit):</strong></td>
                                            <td class="text-end text-success">${formatCurrency(appt.billing.total_paid)}</td>
                                        </tr>
                                        <tr>
                                            <td colspan="3" class="text-end"><strong>Balance:</strong></td>
                                            <td class="text-end ${balanceClass}"><strong>${formatCurrency(appt.billing.balance)}</strong></td>
                                        </tr>
                                    </tfoot>
                                </table>
                            </div>
                        </div>
                    `;
                    accordion.appendChild(card);
                });
            } else {
                accordion.innerHTML = '<div class="alert alert-info">No transaction history found.</div>';
            }
        })
        .catch(error => {
            console.error('Error:', error);
            document.getElementById('transactionAccordion').innerHTML = '<div class="alert alert-danger">Error loading transaction history.</div>';
        });
});

function escapeHtml(str) {
    if (str === null || str === undefined) return '';
    return str.toString().replace(/&/g, "&amp;").replace(/</g, "&lt;").replace(/>/g, "&gt;").replace(/"/g, "&quot;").replace(/'/g, "&#039;");
}

function formatCurrency(amount) {
    if (typeof amount !== 'number') return '0.00';
    return new Intl.NumberFormat('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 }).format(amount);
}
</script>