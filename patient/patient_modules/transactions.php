<div class="container-fluid patient-transactions-page">
    <h1 class="h3 mb-3 text-gray-800">Billing</h1>

    <div class="unified-table-search-toolbar patient-transaction-search-toolbar">
        <div class="unified-table-search-wrap module-search-inline patient-transaction-search-wrap">
            <i class="bi bi-search unified-table-search-icon" aria-hidden="true"></i>
            <input
                type="search"
                id="patientTransactionSearch"
                class="form-control unified-table-search-input"
                placeholder="Search date, item, tooth, remarks, or balance..."
                autocomplete="off"
                aria-label="Search billing history"
            >
            <button type="button" class="unified-table-search-clear" id="patientTransactionSearchClear" aria-label="Clear search" style="display:none;">&times;</button>
        </div>
        <div id="patientTransactionSearchMeta" class="patient-transaction-search-meta"></div>
    </div>

    <div id="transactionAccordion"></div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    const accordion = document.getElementById('transactionAccordion');
    const searchInput = document.getElementById('patientTransactionSearch');
    const searchClearBtn = document.getElementById('patientTransactionSearchClear');
    const searchMeta = document.getElementById('patientTransactionSearchMeta');
    let allTransactions = [];

    fetch('../assets/api/billing/get_transactions.php')
        .then(async (response) => {
            const data = await response.json().catch(() => null);
            if (!response.ok || !data) {
                throw new Error('Invalid server response');
            }
            return data;
        })
        .then(data => {
            if (!data.success) {
                accordion.innerHTML = '<div class="alert alert-warning">' + escapeHtml(data.message || 'Unable to load billing records.') + '</div>';
                return;
            }

            allTransactions = Array.isArray(data.transactions) ? data.transactions : [];
            renderTransactions(allTransactions);

            if (searchInput) {
                searchInput.addEventListener('input', function() {
                    if (searchClearBtn) {
                        searchClearBtn.style.display = this.value ? '' : 'none';
                    }
                    filterTransactions(String(this.value || '').trim());
                });
                searchInput.addEventListener('keydown', function(e) {
                    if (e.key === 'Escape') {
                        searchInput.value = '';
                        if (searchClearBtn) searchClearBtn.style.display = 'none';
                        filterTransactions('');
                    }
                });
            }

            if (searchClearBtn) {
                searchClearBtn.addEventListener('click', function() {
                    if (!searchInput) return;
                    searchInput.value = '';
                    searchClearBtn.style.display = 'none';
                    filterTransactions('');
                    searchInput.focus();
                });
            }
        })
        .catch(error => {
            console.error('Error:', error);
            accordion.innerHTML = '<div class="alert alert-danger">Error loading billing history.</div>';
        });

    function filterTransactions(query) {
        const normalized = query.toLowerCase();

        if (searchClearBtn && searchInput) {
            searchClearBtn.style.display = searchInput.value ? '' : 'none';
        }

        if (!normalized) {
            renderTransactions(allTransactions);
            updateSearchMeta(allTransactions.length, allTransactions.length);
            return;
        }

        const filtered = allTransactions.filter(function(appt) {
            return transactionMatchesQuery(appt, normalized);
        });

        renderTransactions(filtered);
        updateSearchMeta(filtered.length, allTransactions.length);
    }

    function transactionMatchesQuery(appt, query) {
        const haystack = [
            appt.date,
            formatBalanceLabel(appt.billing),
            appt.billing && appt.billing.status ? String(appt.billing.status) : '',
            formatCurrency(appt.billing ? appt.billing.balance : 0),
            formatCurrency(appt.billing ? appt.billing.total_charge : 0),
            formatCurrency(appt.billing ? appt.billing.total_paid : 0)
        ];

        (appt.line_items || []).forEach(function(item) {
            haystack.push(item.description, item.tooth_no, item.remark, item.type);
        });

        (appt.odontogram_findings || []).forEach(function(finding) {
            haystack.push(finding.tooth_no, finding.remark, 'tooth finding');
        });

        return haystack.some(function(part) {
            return String(part || '').toLowerCase().includes(query);
        });
    }

    function updateSearchMeta(visibleCount, totalCount) {
        if (!searchMeta) return;
        if (totalCount === 0) {
            searchMeta.textContent = '';
            return;
        }
        if (visibleCount === totalCount) {
            searchMeta.textContent = visibleCount + ' billing record' + (visibleCount === 1 ? '' : 's');
            return;
        }
        searchMeta.textContent = 'Showing ' + visibleCount + ' of ' + totalCount + ' billing records';
    }

    function renderTransactions(transactions) {
        accordion.innerHTML = '';

        if (!transactions.length) {
            if (allTransactions.length > 0) {
                accordion.innerHTML = '<div class="alert alert-info">No billing records match your search.</div>';
            } else {
                accordion.innerHTML = '<div class="alert alert-info">No billing history found.</div>';
            }
            return;
        }

        transactions.forEach(function(appt) {
            accordion.appendChild(buildTransactionCard(appt));
        });
    }

    function buildTransactionCard(appt) {
        const card = document.createElement('div');
        card.className = 'card shadow mb-3 patient-transaction-card';

        let itemsHtml = '';
        (appt.line_items || []).forEach(function(item) {
            itemsHtml += `
                <tr>
                    <td>${escapeHtml(item.description)}</td>
                    <td>${escapeHtml(item.tooth_no)}</td>
                    <td>${escapeHtml(item.remark)}</td>
                    <td class="text-end">${formatCurrency(item.charge)}</td>
                </tr>
            `;
        });

        (appt.odontogram_findings || []).forEach(function(finding) {
            itemsHtml += `
                <tr>
                    <td>Tooth Finding</td>
                    <td>${escapeHtml(finding.tooth_no)}</td>
                    <td>${escapeHtml(finding.remark)}</td>
                    <td class="text-end">${formatCurrency(0)}</td>
                </tr>
            `;
        });

        const balanceLabel = formatBalanceLabel(appt.billing);
        const balanceClass = hasOutstandingBalance(appt.billing) ? 'text-danger' : 'text-success';
        const balanceDisplay = hasOutstandingBalance(appt.billing)
            ? formatCurrency(appt.billing.balance)
            : 'Paid';

        card.innerHTML = `
            <div class="card-header" id="heading-${appt.appointment_id}">
                <h2 class="mb-0">
                    <button class="btn btn-link btn-block text-start d-flex justify-content-between w-100" type="button" data-bs-toggle="collapse" data-bs-target="#collapse-${appt.appointment_id}" aria-expanded="false" aria-controls="collapse-${appt.appointment_id}">
                        <span><strong>Date:</strong> ${escapeHtml(appt.date)}</span>
                        <span class="ms-auto"><strong>Balance:</strong> <span class="${balanceClass}">${escapeHtml(balanceDisplay)}</span></span>
                    </button>
                </h2>
            </div>
            <div id="collapse-${appt.appointment_id}" class="collapse" aria-labelledby="heading-${appt.appointment_id}" data-bs-parent="#transactionAccordion">
                <div class="card-body">
                    <h6 class="mb-3">Details for Appointment on ${escapeHtml(appt.date)}</h6>
                    <div class="table-responsive">
                        <table class="table table-sm table-hover" data-skip-table-search="1">
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
                                    <td class="text-end ${balanceClass}"><strong>${escapeHtml(balanceDisplay)}</strong></td>
                                </tr>
                            </tfoot>
                        </table>
                    </div>
                </div>
            </div>
        `;

        return card;
    }
});

function hasOutstandingBalance(billing) {
    const balance = Number(billing && billing.balance != null ? billing.balance : 0);
    return Number.isFinite(balance) && balance > 0.009;
}

function formatBalanceLabel(billing) {
    return hasOutstandingBalance(billing) ? formatCurrency(billing.balance) : 'Paid';
}

function escapeHtml(str) {
    if (str === null || str === undefined) return '';
    return str.toString().replace(/&/g, "&amp;").replace(/</g, "&lt;").replace(/>/g, "&gt;").replace(/"/g, "&quot;").replace(/'/g, "&#039;");
}

function formatCurrency(amount) {
    const value = Number(amount);
    if (!Number.isFinite(value)) return '₱0.00';
    return new Intl.NumberFormat('en-PH', { style: 'currency', currency: 'PHP' }).format(value);
}
</script>
