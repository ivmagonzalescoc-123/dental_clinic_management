<?php
include_once dirname(dirname(__DIR__)) . '/config/contact_messages_helpers.php';
contact_messages_ensure_table($conn);
?>
<div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
    <div>
        <h5 class="card-title mb-1">Contact Us Inquiries</h5>
    </div>
    <div class="d-flex flex-wrap gap-2">
        <button type="button" class="btn btn-sm btn-outline-success" id="markAllContactReadBtn" disabled>
            <i class="bi bi-envelope-open"></i> Mark all as read
        </button>
        <button type="button" class="btn btn-sm btn-outline-primary" id="syncGmailMessagesBtn">
            <i class="bi bi-envelope-arrow-down"></i> Check Gmail
        </button>
        <button type="button" class="btn btn-sm btn-outline-secondary" id="refreshContactMessagesBtn">
            Refresh
        </button>
    </div>
</div>

<nav class="module-section-nav" aria-label="Inquiry status">
    <div class="module-section-nav__track">
        <ul class="nav nav-pills mb-0 contact-message-tabs" id="contactMessageTabs">
            <li class="nav-item"><button type="button" class="nav-link active" data-status="all">All</button></li>
            <li class="nav-item"><button type="button" class="nav-link" data-status="new">New <span class="nav-dot" id="contactNewCountBadge"></span></button></li>
            <li class="nav-item"><button type="button" class="nav-link" data-status="read">Read</button></li>
            <li class="nav-item contact-source-item">
                <div class="contact-source-switch" role="group" aria-label="Message source">
                    <button type="button" class="contact-source-btn active" data-source="form" aria-pressed="true"><i class="bi bi-globe"></i> Landing page <span class="nav-dot" id="contactFormNewDot"></span></button>
                    <button type="button" class="contact-source-btn" data-source="gmail" aria-pressed="false"><i class="bi bi-envelope"></i> Gmail <span class="nav-dot" id="contactGmailNewDot"></span></button>
                </div>
            </li>
            <li class="nav-item"><button type="button" class="nav-link" data-status="archived">Archived</button></li>
        </ul>
    </div>
</nav>

<div class="contact-filters mb-2">
    <div class="unified-table-search-wrap module-search-inline contact-filters__search">
        <i class="bi bi-search unified-table-search-icon" aria-hidden="true"></i>
        <input
            type="search"
            id="contactMessageSearch"
            class="form-control unified-table-search-input"
            placeholder="Search by name, email, phone, or message..."
            autocomplete="off"
            aria-label="Search contact messages"
        >
        <button type="button" class="unified-table-search-clear" id="contactMessageSearchClear" aria-label="Clear search" style="display:none;">&times;</button>
    </div>
    <button type="button" class="btn btn-sm btn-outline-secondary contact-filters__toggle" id="contactFiltersToggle" aria-expanded="false" aria-controls="contactFiltersPanel">
        <i class="bi bi-funnel"></i> Date &amp; time <span class="badge rounded-pill text-bg-primary" id="contactFiltersBadge" hidden></span>
    </button>
    <select id="contactSort" class="form-select form-select-sm contact-filters__sort" aria-label="Sort messages">
        <option value="newest">Newest first</option>
        <option value="oldest">Oldest first</option>
        <option value="name_asc">Name A–Z</option>
        <option value="name_desc">Name Z–A</option>
    </select>
    <button type="button" class="btn btn-sm btn-link contact-filters__clear" id="contactClearFilters">Clear filters</button>
    <div id="contactMessageSearchMeta" class="contact-filters__meta"></div>
</div>

<div class="contact-filters-panel mb-2" id="contactFiltersPanel" hidden>
    <div class="contact-filters__group" role="group" aria-label="Date received">
        <span class="contact-filters__label">Date</span>
        <input type="date" id="contactDateFrom" class="form-control form-control-sm" aria-label="Received from date">
        <span class="contact-filters__sep">to</span>
        <input type="date" id="contactDateTo" class="form-control form-control-sm" aria-label="Received to date">
    </div>
    <div class="contact-filters__group" role="group" aria-label="Time received">
        <span class="contact-filters__label">Time</span>
        <input type="time" id="contactTimeFrom" class="form-control form-control-sm" aria-label="Received from time">
        <span class="contact-filters__sep">to</span>
        <input type="time" id="contactTimeTo" class="form-control form-control-sm" aria-label="Received to time">
    </div>
</div>

<div class="contact-bulk-bar mb-2" id="contactBulkBar" hidden>
    <span id="contactSelectedCount">0 selected</span>
    <button type="button" class="btn btn-sm btn-danger" id="contactArchiveSelectedBtn"><i class="bi bi-archive"></i> Archive selected</button>
    <button type="button" class="btn btn-sm btn-link" id="contactClearSelectionBtn">Clear selection</button>
</div>

<div id="contactMessagesAlert"></div>
<div id="contactMessagesEmpty" class="alert alert-info" style="display:none;">No contact messages found.</div>

<div class="table-responsive border rounded">
    <table class="table table-hover align-middle mb-0" id="contactMessagesTable" data-skip-table-search="1">
        <thead class="table-light">
            <tr>
                <th class="contact-check-col"><input type="checkbox" class="form-check-input" id="contactSelectAll" aria-label="Select all shown messages"></th>
                <th>Received</th>
                <th>Name</th>
                <th>Email</th>
                <th>Phone</th>
                <th>Status</th>
                <th class="text-end">Actions</th>
            </tr>
        </thead>
        <tbody id="contactMessagesBody">
            <tr id="contactMessagesLoadingRow">
                <td colspan="7" class="text-center py-4 text-muted">Loading contact messages...</td>
            </tr>
        </tbody>
    </table>
</div>

<div class="modal fade" id="contactMessageModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Contact Message</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <dl class="row mb-0">
                    <dt class="col-sm-3">Received</dt>
                    <dd class="col-sm-9" id="contactModalReceived">-</dd>
                    <dt class="col-sm-3">Name</dt>
                    <dd class="col-sm-9" id="contactModalName">-</dd>
                    <dt class="col-sm-3">Email</dt>
                    <dd class="col-sm-9" id="contactModalEmail">-</dd>
                    <dt class="col-sm-3">Phone</dt>
                    <dd class="col-sm-9" id="contactModalPhone">-</dd>
                    <dt class="col-sm-3">Status</dt>
                    <dd class="col-sm-9" id="contactModalStatus">-</dd>
                    <dt class="col-sm-3">Message</dt>
                    <dd class="col-sm-9"><div id="contactModalMessage" class="border rounded p-3 bg-light"></div></dd>
                </dl>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline-primary" id="contactModalMarkReadBtn">Mark as Read</button>
                <button type="button" class="btn btn-outline-danger" id="contactModalArchiveBtn">Archive</button>
            </div>
        </div>
    </div>
</div>

<style>
.contact-filters { display: flex; flex-wrap: wrap; align-items: center; gap: 8px 10px; }
.contact-filters__search { flex: 1 1 220px; max-width: 340px; }
.contact-filters__toggle { display: inline-flex; align-items: center; gap: 6px; white-space: nowrap; }
.contact-filters__toggle[aria-expanded="true"] { background: #f1f5f9; }
.contact-filters__sort { width: auto; min-width: 140px; padding-right: 2.25rem !important; background-position: right .65rem center !important; }
.contact-filters__clear { padding-left: 0; padding-right: 0; white-space: nowrap; }
.contact-filters__meta { margin-left: auto; font-size: 0.82rem; color: #64748b; white-space: nowrap; }
.contact-filters-panel { display: flex; flex-wrap: wrap; gap: 8px 20px; padding: 10px 12px; border: 1px solid #e2e8f0; border-radius: 10px; background: #f8fafc; }
.contact-filters-panel[hidden] { display: none; }
.contact-filters__group { display: flex; align-items: center; gap: 6px; min-width: 0; }
.contact-filters__group .form-control { width: 140px; min-width: 0; }
.contact-filters__label { width: 38px; font-size: 0.74rem; font-weight: 700; color: #5d6f85; text-transform: uppercase; letter-spacing: 0.04em; }
.contact-filters__sep { font-size: 0.8rem; color: #94a3b8; }
.contact-bulk-bar { display: flex; flex-wrap: wrap; align-items: center; gap: 10px; padding: 8px 12px; border-radius: 10px; background: #f0fdfa; border: 1px solid #b7e4dc; font-size: 0.86rem; font-weight: 600; color: #0f766e; }
.contact-bulk-bar[hidden] { display: none; }
.contact-check-col { width: 36px; text-align: center; }
#contactMessagesTable { min-width: 0 !important; width: 100%; }
#contactMessagesTable td:last-child { white-space: nowrap; }
.contact-source-item { display: flex; align-items: center; margin: 0 4px; }
.contact-source-switch { display: inline-flex; padding: 3px; border-radius: 999px; background: #e2e8f0; gap: 2px; }
.contact-source-btn { display: inline-flex; align-items: center; gap: 6px; border: 0; background: transparent; color: #475569; font-size: 0.84rem; font-weight: 600; padding: 5px 12px; border-radius: 999px; white-space: nowrap; transition: background .15s, color .15s, box-shadow .15s; }
.contact-source-btn:hover { color: #0f766e; }
.contact-source-btn.active { background: #fff; color: #0f766e; box-shadow: 0 1px 3px rgba(15, 23, 42, .15); }
.contact-source-btn .nav-dot { position: static; }
.contact-when { white-space: nowrap; line-height: 1.25; }
.contact-when small { display: block; color: #64748b; }
.contact-email-cell { display: block; max-width: 240px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
@media (max-width: 767.98px) { #contactMessagesTable { min-width: 760px !important; } }
@media (max-width: 575.98px) {
    .contact-filters__search { max-width: none; flex-basis: 100%; }
    .contact-filters__group { width: 100%; }
    .contact-filters__group .form-control { flex: 1 1 0; width: auto; }
    .contact-filters__meta { margin-left: 0; flex-basis: 100%; }
}
</style>

<script>
document.addEventListener('DOMContentLoaded', function() {
    // Hide contact dot when page loads
    const contactDot = document.getElementById('secretaryContactDot');
    if (contactDot) contactDot.classList.remove('is-active');

    const secretaryCsrfToken = <?php echo json_encode($csrf_token ?? ''); ?>;
    const tbody = document.getElementById('contactMessagesBody');
    const emptyEl = document.getElementById('contactMessagesEmpty');
    const alertEl = document.getElementById('contactMessagesAlert');
    const searchInput = document.getElementById('contactMessageSearch');
    const searchClearBtn = document.getElementById('contactMessageSearchClear');
    const searchMeta = document.getElementById('contactMessageSearchMeta');
    const newCountBadge = document.getElementById('contactNewCountBadge');
    const sidebarDot = document.getElementById('secretaryContactDot');
    const refreshBtn = document.getElementById('refreshContactMessagesBtn');
    const syncGmailBtn = document.getElementById('syncGmailMessagesBtn');
    const tabs = document.querySelectorAll('#contactMessageTabs .nav-link');
    const modalEl = document.getElementById('contactMessageModal');
    const markReadBtn = document.getElementById('contactModalMarkReadBtn');
    const archiveBtn = document.getElementById('contactModalArchiveBtn');

    const dateFromEl = document.getElementById('contactDateFrom');
    const dateToEl = document.getElementById('contactDateTo');
    const timeFromEl = document.getElementById('contactTimeFrom');
    const timeToEl = document.getElementById('contactTimeTo');
    const sortEl = document.getElementById('contactSort');
    const clearFiltersBtn = document.getElementById('contactClearFilters');
    const filtersToggle = document.getElementById('contactFiltersToggle');
    const filtersPanel = document.getElementById('contactFiltersPanel');
    const filtersBadge = document.getElementById('contactFiltersBadge');
    const selectAllEl = document.getElementById('contactSelectAll');
    const bulkBar = document.getElementById('contactBulkBar');
    const selectedCountEl = document.getElementById('contactSelectedCount');
    const archiveSelectedBtn = document.getElementById('contactArchiveSelectedBtn');
    const clearSelectionBtn = document.getElementById('contactClearSelectionBtn');
    const markAllReadBtn = document.getElementById('markAllContactReadBtn');

    let currentStatus = 'all';
    let currentSource = 'form';
    let newCounts = { form: 0, gmail: 0 };
    const sourceBtns = Array.from(document.querySelectorAll('.contact-source-btn'));
    const sourceDots = { form: document.getElementById('contactFormNewDot'), gmail: document.getElementById('contactGmailNewDot') };
    const SOURCE_LABELS = { form: 'landing page', gmail: 'Gmail' };
    let allMessages = [];
    let selectedIds = new Set();
    let lastNewCount = 0;
    let activeMessage = null;
    let contactModal = modalEl ? new bootstrap.Modal(modalEl) : null;

    function escapeHtml(value) {
        return String(value || '')
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#039;');
    }

    function showAlert(message, type) {
        if (typeof window.showToast === 'function') {
            window.showToast(String(message || ''), type || 'info');
            return;
        }
        if (!alertEl) return;
        alertEl.innerHTML = '<div class="alert alert-' + type + ' alert-dismissible fade show" role="alert">' +
            escapeHtml(message) +
            '<button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button></div>';
    }

    function formatDateTime(value) {
        if (!value) return '-';
        const dt = new Date(value.replace(' ', 'T'));
        return Number.isNaN(dt.getTime()) ? value : dt.toLocaleString();
    }

    function statusBadge(status) {
        const key = String(status || 'new').toLowerCase();
        let cls = 'secondary';
        if (key === 'new') cls = 'danger';
        else if (key === 'read') cls = 'primary';
        else if (key === 'archived') cls = 'secondary';
        return '<span class="badge bg-' + cls + '">' + escapeHtml(key.charAt(0).toUpperCase() + key.slice(1)) + '</span>';
    }

    // The API's new_count is clinic-wide; this page works per source, so badges use new_counts.
    function updateBadges(newCount, countsBySource) {
        if (countsBySource && typeof countsBySource === 'object') {
            newCounts = { form: Number(countsBySource.form || 0), gmail: Number(countsBySource.gmail || 0) };
        }
        Object.keys(sourceDots).forEach(function(key) {
            if (sourceDots[key]) sourceDots[key].classList.toggle('is-active', newCounts[key] > 0);
        });
        newCount = newCounts[currentSource] || 0;
        lastNewCount = newCount;
        if (markAllReadBtn) markAllReadBtn.disabled = newCount <= 0;
        if (newCountBadge) {
            if (newCount > 0) {
                newCountBadge.classList.add('is-active');
            } else {
                newCountBadge.classList.remove('is-active');
            }
        }
        // Always hide sidebar dot when on this page
        if (sidebarDot) {
            sidebarDot.classList.remove('is-active');
        }
    }

    function messageMatchesQuery(row, query) {
        if (!query) return true;
        const haystack = [
            row.full_name,
            row.email,
            row.phone_number,
            row.message,
            row.status,
            row.created_at
        ].join(' ').toLowerCase();
        return haystack.includes(query);
    }

    // Date on top, time underneath — keeps the Received column narrow.
    function formatWhenCell(value) {
        const text = String(formatDateTime(value) || '');
        const cut = text.indexOf(', ');
        if (cut < 0) return escapeHtml(text);
        return escapeHtml(text.slice(0, cut)) + '<small>' + escapeHtml(text.slice(cut + 2)) + '</small>';
    }

    function hasActiveFilters() {
        return !!(String(searchInput ? searchInput.value : '').trim() ||
            (dateFromEl && dateFromEl.value) || (dateToEl && dateToEl.value) ||
            (timeFromEl && timeFromEl.value) || (timeToEl && timeToEl.value));
    }

    // created_at is the server's "YYYY-MM-DD HH:MM:SS", so date/time filters compare
    // its parts as strings - no timezone conversion that could shift a message a day.
    function getFilteredMessages() {
        const query = String(searchInput ? searchInput.value : '').trim().toLowerCase();
        const dFrom = dateFromEl ? dateFromEl.value : '';
        const dTo = dateToEl ? dateToEl.value : '';
        const tFrom = timeFromEl ? timeFromEl.value : '';
        const tTo = timeToEl ? timeToEl.value : '';
        const rows = allMessages.filter(function(row) {
            if (!messageMatchesQuery(row, query)) return false;
            const stamp = String(row.created_at || '');
            const day = stamp.slice(0, 10);
            const time = stamp.slice(11, 16);
            if (dFrom && day < dFrom) return false;
            if (dTo && day > dTo) return false;
            if (tFrom && time < tFrom) return false;
            if (tTo && time > tTo) return false;
            return true;
        });
        const sort = sortEl ? sortEl.value : 'newest';
        rows.sort(function(a, b) {
            if (sort === 'name_asc' || sort === 'name_desc') {
                const cmp = String(a.full_name || '').localeCompare(String(b.full_name || ''), undefined, { sensitivity: 'base' });
                return sort === 'name_asc' ? cmp : -cmp;
            }
            const cmp = String(a.created_at || '').localeCompare(String(b.created_at || ''));
            return sort === 'oldest' ? cmp : -cmp;
        });
        return rows;
    }

    function updateSelectionUi(visibleSelectable) {
        const count = selectedIds.size;
        if (bulkBar) bulkBar.hidden = count === 0;
        if (selectedCountEl) selectedCountEl.textContent = count + ' selected';
        if (selectAllEl) {
            const selectedVisible = visibleSelectable.filter(function(id) { return selectedIds.has(id); }).length;
            selectAllEl.disabled = visibleSelectable.length === 0;
            selectAllEl.checked = visibleSelectable.length > 0 && selectedVisible === visibleSelectable.length;
            selectAllEl.indeterminate = selectedVisible > 0 && selectedVisible < visibleSelectable.length;
        }
    }

    function updateSearchMeta(visibleCount, totalCount, query) {
        if (!searchMeta) return;
        if (totalCount === 0) {
            searchMeta.textContent = '';
            return;
        }
        if (!query) {
            searchMeta.textContent = totalCount + ' message' + (totalCount === 1 ? '' : 's');
            return;
        }
        searchMeta.textContent = 'Showing ' + visibleCount + ' of ' + totalCount;
    }

    function formatPhoneLink(phone) {
        const value = String(phone || '').trim();
        if (!value) return '-';
        const tel = value.replace(/[^\d+]/g, '');
        if (!tel) return escapeHtml(value);
        return '<a href="tel:' + escapeHtml(tel) + '">' + escapeHtml(value) + '</a>';
    }

    function renderMessages() {
        const filtered = getFilteredMessages();
        const query = String(searchInput ? searchInput.value : '').trim().toLowerCase();

        if (searchClearBtn && searchInput) {
            searchClearBtn.style.display = searchInput.value ? '' : 'none';
        }

        if (!tbody) return;
        tbody.innerHTML = '';

        // Only messages currently shown can stay selected, so a bulk action never
        // touches rows hidden by a filter the secretary may have forgotten about.
        const visibleSelectable = filtered
            .filter(function(row) { return String(row.status) !== 'archived'; })
            .map(function(row) { return Number(row.message_id); });
        selectedIds = new Set(Array.from(selectedIds).filter(function(id) { return visibleSelectable.includes(id); }));
        updateSelectionUi(visibleSelectable);

        if (!filtered.length) {
            emptyEl.style.display = '';
            if (allMessages.length) {
                emptyEl.textContent = 'No contact messages match your filters.';
            } else {
                emptyEl.textContent = currentSource === 'gmail' ? 'No Gmail messages here.' : 'No landing page messages here.';
            }
            updateSearchMeta(0, allMessages.length, hasActiveFilters() ? 'filtered' : '');
            return;
        }

        emptyEl.style.display = 'none';
        filtered.forEach(function(row) {
            const tr = document.createElement('tr');
            const id = Number(row.message_id);
            const archived = String(row.status) === 'archived';
            if (selectedIds.has(id)) tr.classList.add('table-active');
            tr.innerHTML =
                '<td class="contact-check-col"><input type="checkbox" class="form-check-input contact-row-check" data-id="' + id + '"' +
                    (selectedIds.has(id) ? ' checked' : '') + (archived ? ' disabled title="Already archived"' : '') +
                    ' aria-label="Select message from ' + escapeHtml(row.full_name) + '"></td>' +
                '<td class="contact-when">' + formatWhenCell(row.created_at) + '</td>' +
                '<td>' + escapeHtml(row.full_name) + '</td>' +
                '<td><a class="contact-email-cell" href="mailto:' + escapeHtml(row.email) + '" title="' + escapeHtml(row.email) + '">' + escapeHtml(row.email) + '</a></td>' +
                '<td>' + formatPhoneLink(row.phone_number) + '</td>' +
                '<td>' + statusBadge(row.status) + '</td>' +
                '<td class="text-end">' +
                    '<button type="button" class="btn btn-sm btn-outline-primary me-1 contact-view-btn" data-id="' + row.message_id + '">View</button>' +
                    (String(row.status) === 'new'
                        ? '<button type="button" class="btn btn-sm btn-outline-success contact-read-btn" data-id="' + row.message_id + '">Mark Read</button>'
                        : (String(row.status) !== 'archived'
                            ? '<button type="button" class="btn btn-sm btn-outline-secondary contact-archive-btn" data-id="' + row.message_id + '">Archive</button>'
                            : '')) +
                '</td>';
            tbody.appendChild(tr);
        });

        updateSearchMeta(filtered.length, allMessages.length, hasActiveFilters() ? 'filtered' : '');
        updateFiltersBadge();
    }

    function updateFiltersBadge() {
        if (!filtersBadge) return;
        const count = [dateFromEl, dateToEl, timeFromEl, timeToEl].filter(function(el) { return el && el.value; }).length;
        filtersBadge.textContent = String(count);
        filtersBadge.hidden = count === 0;
    }

    async function postAction(payload) {
        const resp = await fetch('../assets/api/contact/manage_contact_messages.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(Object.assign({ csrf_token: secretaryCsrfToken }, payload))
        });
        const data = await resp.json();
        if (!resp.ok || !data.success) {
            throw new Error(data.message || 'Unable to update messages.');
        }
        updateBadges(Number(data.new_count || 0), data.new_counts);
        return data;
    }

    async function confirmAction(opts) {
        if (typeof window.showConfirmModal === 'function') return window.showConfirmModal(opts);
        return window.confirm(opts.message);
    }

    async function loadMessages() {
        if (tbody && typeof DentalUI !== 'undefined' && DentalUI.showSkeleton) {
            DentalUI.showSkeleton(tbody, 6, 7);
        } else if (tbody) {
            tbody.innerHTML = '<tr><td colspan="7" class="text-center py-4 text-muted">Loading contact messages...</td></tr>';
        }

        try {
            const resp = await fetch('../assets/api/contact/manage_contact_messages.php?status=' + encodeURIComponent(currentStatus) + '&source=' + encodeURIComponent(currentSource), {
                cache: 'no-store'
            });
            const data = await resp.json();
            if (!resp.ok || !data.success) {
                throw new Error(data.message || 'Unable to load contact messages.');
            }

            allMessages = Array.isArray(data.messages) ? data.messages : [];
            updateBadges(Number(data.new_count || 0), data.new_counts);
            renderMessages();
        } catch (err) {
            if (tbody) {
                tbody.innerHTML = '<tr><td colspan="7" class="text-center py-4 text-danger">' + escapeHtml(err.message || 'Failed to load messages.') + '</td></tr>';
            }
        }
    }

    async function updateMessage(action, messageId) {
        const resp = await fetch('../assets/api/contact/manage_contact_messages.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                action: action,
                message_id: messageId,
                csrf_token: secretaryCsrfToken
            })
        });
        const data = await resp.json();
        if (!resp.ok || !data.success) {
            throw new Error(data.message || 'Unable to update message.');
        }
        updateBadges(Number(data.new_count || 0), data.new_counts);
        return data;
    }

    function openMessageModal(messageId) {
        activeMessage = allMessages.find(function(row) {
            return Number(row.message_id) === Number(messageId);
        }) || null;
        if (!activeMessage || !contactModal) return;

        document.getElementById('contactModalReceived').textContent = formatDateTime(activeMessage.created_at);
        document.getElementById('contactModalName').textContent = activeMessage.full_name || '-';
        document.getElementById('contactModalEmail').innerHTML = activeMessage.email
            ? '<a href="mailto:' + escapeHtml(activeMessage.email) + '">' + escapeHtml(activeMessage.email) + '</a>'
            : '-';
        document.getElementById('contactModalPhone').innerHTML = formatPhoneLink(activeMessage.phone_number);
        document.getElementById('contactModalStatus').innerHTML = statusBadge(activeMessage.status);
        document.getElementById('contactModalMessage').textContent = activeMessage.message || '';

        if (markReadBtn) markReadBtn.style.display = String(activeMessage.status) === 'new' ? '' : 'none';
        if (archiveBtn) archiveBtn.style.display = String(activeMessage.status) === 'archived' ? 'none' : '';

        contactModal.show();

        if (String(activeMessage.status) === 'new') {
            updateMessage('mark_read', activeMessage.message_id)
                .then(function() {
                    activeMessage.status = 'read';
                    document.getElementById('contactModalStatus').innerHTML = statusBadge('read');
                    if (markReadBtn) markReadBtn.style.display = 'none';
                    if (archiveBtn) archiveBtn.style.display = '';
                    return loadMessages();
                })
                .catch(function(err) {
                    showAlert(err.message || 'Unable to mark message as read.', 'warning');
                });
        }
    }

    tabs.forEach(function(tab) {
        tab.addEventListener('click', function() {
            tabs.forEach(function(item) { item.classList.remove('active'); });
            tab.classList.add('active');
            currentStatus = tab.getAttribute('data-status') || 'all';
            selectedIds.clear();
            if (searchInput) searchInput.value = '';
            if (searchClearBtn) searchClearBtn.style.display = 'none';
            loadMessages();
        });
    });

    sourceBtns.forEach(function(btn) {
        btn.addEventListener('click', function() {
            const source = btn.getAttribute('data-source') || 'form';
            if (source === currentSource) return;
            currentSource = source;
            sourceBtns.forEach(function(item) {
                const on = item === btn;
                item.classList.toggle('active', on);
                item.setAttribute('aria-pressed', on ? 'true' : 'false');
            });
            selectedIds.clear();
            updateBadges(0);
            loadMessages();
        });
    });

    if (searchInput) {
        searchInput.addEventListener('input', renderMessages);
        searchInput.addEventListener('keydown', function(e) {
            if (e.key === 'Escape') {
                searchInput.value = '';
                if (searchClearBtn) searchClearBtn.style.display = 'none';
                renderMessages();
            }
        });
    }

    if (searchClearBtn && searchInput) {
        searchClearBtn.addEventListener('click', function() {
            searchInput.value = '';
            searchClearBtn.style.display = 'none';
            renderMessages();
            searchInput.focus();
        });
    }

    if (refreshBtn) {
        refreshBtn.addEventListener('click', loadMessages);
    }

    [dateFromEl, dateToEl, timeFromEl, timeToEl, sortEl].forEach(function(el) {
        if (el) el.addEventListener('change', renderMessages);
    });

    if (filtersToggle && filtersPanel) {
        filtersToggle.addEventListener('click', function() {
            const open = filtersPanel.hidden;
            filtersPanel.hidden = !open;
            filtersToggle.setAttribute('aria-expanded', open ? 'true' : 'false');
            if (open && dateFromEl) dateFromEl.focus();
        });
    }

    if (clearFiltersBtn) {
        clearFiltersBtn.addEventListener('click', function() {
            [searchInput, dateFromEl, dateToEl, timeFromEl, timeToEl].forEach(function(el) { if (el) el.value = ''; });
            if (sortEl) sortEl.value = 'newest';
            renderMessages();
        });
    }

    if (selectAllEl) {
        selectAllEl.addEventListener('change', function() {
            const visible = Array.from(tbody.querySelectorAll('.contact-row-check:not(:disabled)')).map(function(cb) { return Number(cb.getAttribute('data-id')); });
            visible.forEach(function(id) { if (selectAllEl.checked) selectedIds.add(id); else selectedIds.delete(id); });
            renderMessages();
        });
    }

    if (tbody) {
        tbody.addEventListener('change', function(e) {
            const cb = e.target.closest('.contact-row-check');
            if (!cb) return;
            const id = Number(cb.getAttribute('data-id'));
            if (cb.checked) selectedIds.add(id); else selectedIds.delete(id);
            renderMessages();
        });
    }

    if (clearSelectionBtn) {
        clearSelectionBtn.addEventListener('click', function() {
            selectedIds.clear();
            renderMessages();
        });
    }

    if (archiveSelectedBtn) {
        archiveSelectedBtn.addEventListener('click', async function() {
            const ids = Array.from(selectedIds);
            if (!ids.length) return;
            const ok = await confirmAction({
                title: 'Archive messages',
                message: 'Archive ' + ids.length + ' selected message' + (ids.length === 1 ? '' : 's') + '?',
                confirmText: 'Archive',
                variant: 'danger'
            });
            if (!ok) return;
            archiveSelectedBtn.disabled = true;
            try {
                const data = await postAction({ action: 'archive_selected', message_ids: ids });
                showAlert(data.message || 'Messages archived.', 'success');
                selectedIds.clear();
                await loadMessages();
            } catch (err) {
                showAlert(err.message || 'Unable to archive messages.', 'danger');
            } finally {
                archiveSelectedBtn.disabled = false;
            }
        });
    }

    if (markAllReadBtn) {
        markAllReadBtn.addEventListener('click', async function() {
            if (lastNewCount <= 0) return;
            const ok = await confirmAction({
                title: 'Mark all as read',
                message: 'Mark all ' + lastNewCount + ' unread ' + SOURCE_LABELS[currentSource] + ' message' + (lastNewCount === 1 ? '' : 's') + ' as read?',
                confirmText: 'Mark all as read'
            });
            if (!ok) return;
            markAllReadBtn.disabled = true;
            try {
                const data = await postAction({ action: 'mark_all_read', source: currentSource });
                showAlert(data.message || 'All messages marked as read.', 'success');
                await loadMessages();
            } catch (err) {
                showAlert(err.message || 'Unable to mark messages as read.', 'danger');
                markAllReadBtn.disabled = lastNewCount <= 0;
            }
        });
    }

    if (syncGmailBtn) {
        syncGmailBtn.addEventListener('click', async function() {
            syncGmailBtn.disabled = true;
            const originalHtml = syncGmailBtn.innerHTML;
            syncGmailBtn.innerHTML = '<span class="spinner-border spinner-border-sm" role="status" aria-hidden="true"></span> Checking...';
            try {
                const resp = await fetch('../assets/api/contact/sync_gmail_messages.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ csrf_token: secretaryCsrfToken })
                });
                const data = await resp.json();
                if (!resp.ok || !data.success) {
                    throw new Error(data.message || 'Unable to check Gmail.');
                }
                showAlert(data.message || 'Gmail checked.', data.imported > 0 ? 'success' : 'info');
                updateBadges(Number(data.new_count || 0), data.new_counts);
                await loadMessages();
            } catch (err) {
                showAlert(err.message || 'Unable to check Gmail.', 'danger');
            } finally {
                syncGmailBtn.disabled = false;
                syncGmailBtn.innerHTML = originalHtml;
            }
        });
    }

    if (tbody) {
        tbody.addEventListener('click', function(e) {
            const viewBtn = e.target.closest('.contact-view-btn');
            const readBtn = e.target.closest('.contact-read-btn');
            const archiveBtnEl = e.target.closest('.contact-archive-btn');

            if (viewBtn) {
                openMessageModal(viewBtn.getAttribute('data-id'));
                return;
            }

            if (readBtn) {
                updateMessage('mark_read', readBtn.getAttribute('data-id'))
                    .then(function() {
                        showAlert('Message marked as read.', 'success');
                        return loadMessages();
                    })
                    .catch(function(err) {
                        showAlert(err.message || 'Unable to mark message as read.', 'danger');
                    });
                return;
            }

            if (archiveBtnEl) {
                updateMessage('archive', archiveBtnEl.getAttribute('data-id'))
                    .then(function() {
                        showAlert('Message archived.', 'success');
                        return loadMessages();
                    })
                    .catch(function(err) {
                        showAlert(err.message || 'Unable to archive message.', 'danger');
                    });
            }
        });
    }

    if (markReadBtn) {
        markReadBtn.addEventListener('click', function() {
            if (!activeMessage) return;
            updateMessage('mark_read', activeMessage.message_id)
                .then(function() {
                    showAlert('Message marked as read.', 'success');
                    if (contactModal) contactModal.hide();
                    return loadMessages();
                })
                .catch(function(err) {
                    showAlert(err.message || 'Unable to mark message as read.', 'danger');
                });
        });
    }

    if (archiveBtn) {
        archiveBtn.addEventListener('click', function() {
            if (!activeMessage) return;
            updateMessage('archive', activeMessage.message_id)
                .then(function() {
                    showAlert('Message archived.', 'success');
                    if (contactModal) contactModal.hide();
                    return loadMessages();
                })
                .catch(function(err) {
                    showAlert(err.message || 'Unable to archive message.', 'danger');
                });
        });
    }

    loadMessages();
});
</script>
