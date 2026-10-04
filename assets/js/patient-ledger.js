/**
 * Transaction ledger view (charges, discounts, payments, running balance), shared by the
 * staff Patient Chart "Ledger" tab and the patient's own Billing page.
 *
 *   PatientLedger.load(containerEl, patientId)   // patientId omitted for the patient portal
 *
 * Data: assets/api/billing/get_patient_ledger.php (built by billing_patient_ledger()).
 */
(function (global) {
    'use strict';

    var API = '../assets/api/billing/get_patient_ledger.php';
    var TYPE = {
        charge: { label: 'Charge', icon: 'bi-receipt' },
        discount: { label: 'Discount', icon: 'bi-tag' },
        payment: { label: 'Payment', icon: 'bi-cash-coin' }
    };

    function esc(v) {
        return String(v == null ? '' : v).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;').replace(/'/g, '&#39;');
    }
    function peso(v) {
        return '₱' + Number(v || 0).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 });
    }
    function fmtDate(value) {
        var m = /^(\d{4})-(\d{2})-(\d{2})/.exec(String(value || ''));
        if (!m) return '—';
        return new Date(+m[1], +m[2] - 1, +m[3]).toLocaleDateString(undefined, { month: 'short', day: 'numeric', year: 'numeric' });
    }
    function tile(label, value, cls) {
        return '<div class="pl-tile' + (cls ? ' ' + cls : '') + '"><span class="pl-tile__label">' + esc(label) + '</span><strong class="pl-tile__value">' + esc(value) + '</strong></div>';
    }

    function render(el, data) {
        var entries = (data && data.entries) || [];
        var t = (data && data.totals) || {};
        if (!entries.length) {
            el.innerHTML = '<div class="pl-empty"><i class="bi bi-journal-text"></i><span>No charges or payments yet.</span></div>';
            return;
        }
        var owing = Number(t.balance || 0) > 0;
        var rows = entries.map(function (e) {
            var ty = TYPE[e.type] || TYPE.charge;
            var visit = e.visit_date && e.visit_date.slice(0, 10) !== String(e.date || '').slice(0, 10)
                ? '<span class="pl-sub">Visit ' + esc(fmtDate(e.visit_date)) + '</span>' : '';
            return '<tr class="pl-row pl-row--' + esc(e.type) + '">' +
                '<td class="pl-date" data-label="Date">' + esc(fmtDate(e.date)) + '</td>' +
                '<td class="pl-desc" data-label="Details"><span class="pl-type"><i class="bi ' + ty.icon + '"></i> ' + esc(ty.label) + '</span>' +
                    '<span class="pl-text">' + esc(e.description) + '</span>' + visit + '</td>' +
                '<td class="pl-ref" data-label="Reference">' + esc(e.reference || '') + '</td>' +
                '<td class="pl-num pl-charge" data-label="Charge">' + (e.charge ? esc(peso(e.charge)) : '') + '</td>' +
                '<td class="pl-num pl-credit" data-label="Credit">' + (e.credit ? '−' + esc(peso(e.credit)) : '') + '</td>' +
                '<td class="pl-num pl-balance" data-label="Balance">' + esc(peso(e.balance)) + '</td>' +
            '</tr>';
        }).join('');

        el.innerHTML =
            '<div class="pl-tiles">' +
                tile('Total charges', peso(t.charges)) +
                tile('Discounts', peso(t.discounts)) +
                tile('Payments', peso(t.payments)) +
                tile(owing ? 'Balance due' : 'Balance', peso(t.balance), owing ? 'is-due' : 'is-clear') +
            '</div>' +
            '<div class="pl-bar">' +
                '<span class="pl-count">' + entries.length + ' transaction' + (entries.length === 1 ? '' : 's') + '</span>' +
                '<button type="button" class="pl-print" data-pl-print><i class="bi bi-printer"></i> Print</button>' +
            '</div>' +
            '<div class="pl-table-wrap"><table class="pl-table" data-skip-table-search="1">' +
                '<thead><tr><th>Date</th><th>Details</th><th>Reference</th><th class="pl-num">Charge</th><th class="pl-num">Credit</th><th class="pl-num">Balance</th></tr></thead>' +
                '<tbody>' + rows + '</tbody>' +
                '<tfoot><tr><td colspan="3">Ending balance</td><td class="pl-num">' + esc(peso(t.charges)) + '</td>' +
                    '<td class="pl-num">−' + esc(peso(Number(t.discounts || 0) + Number(t.payments || 0))) + '</td>' +
                    '<td class="pl-num">' + esc(peso(t.balance)) + '</td></tr></tfoot>' +
            '</table></div>';
    }

    function load(el, patientId) {
        if (!el) return Promise.resolve();
        el.classList.add('pl-ledger');
        el.innerHTML = '<div class="pl-loading" aria-busy="true"><span></span><span></span><span></span></div>';
        var url = API + (patientId ? '?patient_id=' + encodeURIComponent(patientId) : '');
        return fetch(url, { credentials: 'same-origin', cache: 'no-store' })
            .then(function (r) { return r.json(); })
            .then(function (j) {
                if (!j || !j.success) throw new Error((j && j.message) || 'Failed');
                render(el, j);
            })
            .catch(function () {
                el.innerHTML = '<div class="pl-empty"><i class="bi bi-wifi-off"></i><span>Could not load the ledger. Please try again.</span></div>';
            });
    }

    // Print only the ledger: copy it into a clean window.
    document.addEventListener('click', function (e) {
        var btn = e.target.closest('[data-pl-print]');
        if (!btn) return;
        var ledger = btn.closest('.pl-ledger');
        var titleEl = document.querySelector('[data-pl-title]');
        var title = (ledger && ledger.getAttribute('data-pl-title')) || (titleEl ? titleEl.textContent : 'Transaction ledger');
        var css = Array.prototype.map.call(document.querySelectorAll('link[rel="stylesheet"][href*="patient-ledger"]'), function (l) {
            return '<link rel="stylesheet" href="' + l.href + '">';
        }).join('');
        var w = global.open('', '_blank');
        if (!w || !ledger) return;
        w.document.write('<!doctype html><html><head><meta charset="utf-8"><title>' + esc(title) + '</title>' + css +
            '<style>body{font-family:system-ui,sans-serif;margin:24px;color:#0f172a}.pl-bar{display:none}</style></head><body>' +
            '<h2 style="margin:0 0 4px">' + esc(title) + '</h2><p style="margin:0 0 16px;color:#64748b">Printed ' + esc(new Date().toLocaleString()) + '</p>' +
            '<div class="pl-ledger">' + ledger.innerHTML + '</div></body></html>');
        w.document.close();
        w.focus();
        setTimeout(function () { w.print(); }, 300);
    });

    global.PatientLedger = { load: load, render: render };
})(window);
