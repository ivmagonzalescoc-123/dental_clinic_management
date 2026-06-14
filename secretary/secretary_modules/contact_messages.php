<?php
include_once dirname(dirname(__DIR__)) . '/config/contact_messages_helpers.php';
contact_messages_ensure_table($conn);
?>
<div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
    <div>
        <h5 class="card-title mb-1">Contact Us Inquiries</h5>
        <small class="text-muted">Messages submitted from the public website contact form.</small>
    </div>
    <button type="button" class="btn btn-sm btn-outline-secondary" id="refreshContactMessagesBtn">
        Refresh
    </button>
</div>

<nav class="module-section-nav" aria-label="Inquiry status">
    <div class="module-section-nav__track">
        <ul class="nav nav-pills mb-0 contact-message-tabs" id="contactMessageTabs">
            <li class="nav-item"><button type="button" class="nav-link active" data-status="all">All</button></li>
            <li class="nav-item"><button type="button" class="nav-link" data-status="new">New <span class="badge bg-danger ms-1" id="contactNewCountBadge">0</span></button></li>
            <li class="nav-item"><button type="button" class="nav-link" data-status="read">Read</button></li>
            <li class="nav-item"><button type="button" class="nav-link" data-status="archived">Archived</button></li>
        </ul>
    </div>
</nav>

<div class="unified-table-search-toolbar patient-appt-history-search-toolbar mb-3">
    <div class="unified-table-search-wrap module-search-inline patient-appt-history-search-wrap">
        <i class="bi bi-search unified-table-search-icon" aria-hidden="true"></i>
        <input
            type="search"
            id="contactMessageSearch"
            class="form-control unified-table-search-input"
            placeholder="Search name, email, phone, or message..."
            autocomplete="off"
            aria-label="Search contact messages"
        >
        <button type="button" class="unified-table-search-clear" id="contactMessageSearchClear" aria-label="Clear search" style="display:none;">&times;</button>
    </div>
    <div id="contactMessageSearchMeta" class="patient-appt-history-search-meta"></div>
</div>

<div id="contactMessagesAlert"></div>
<div id="contactMessagesEmpty" class="alert alert-info" style="display:none;">No contact messages found.</div>

<div class="table-responsive border rounded">
    <table class="table table-hover align-middle mb-0" id="contactMessagesTable" data-skip-table-search="1">
        <thead class="table-light">
            <tr>
                <th>Received</th>
                <th>Name</th>
                <th>Email</th>
                <th>Phone</th>
                <th>Message</th>
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
                <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Close</button>
                <button type="button" class="btn btn-outline-primary" id="contactModalMarkReadBtn">Mark as Read</button>
                <button type="button" class="btn btn-outline-danger" id="contactModalArchiveBtn">Archive</button>
            </div>
        </div>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    const secretaryCsrfToken = <?php echo json_encode($csrf_token ?? ''); ?>;
    const tbody = document.getElementById('contactMessagesBody');
    const emptyEl = document.getElementById('contactMessagesEmpty');
    const alertEl = document.getElementById('contactMessagesAlert');
    const searchInput = document.getElementById('contactMessageSearch');
    const searchClearBtn = document.getElementById('contactMessageSearchClear');
    const searchMeta = document.getElementById('contactMessageSearchMeta');
    const newCountBadge = document.getElementById('contactNewCountBadge');
    const sidebarBadge = document.getElementById('secretaryContactBadge');
    const refreshBtn = document.getElementById('refreshContactMessagesBtn');
    const tabs = document.querySelectorAll('#contactMessageTabs .nav-link');
    const modalEl = document.getElementById('contactMessageModal');
    const markReadBtn = document.getElementById('contactModalMarkReadBtn');
    const archiveBtn = document.getElementById('contactModalArchiveBtn');

    let currentStatus = 'all';
    let allMessages = [];
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

    function updateBadges(newCount) {
        if (newCountBadge) newCountBadge.textContent = String(newCount);
        if (sidebarBadge) {
            if (newCount > 0) {
                sidebarBadge.textContent = String(newCount);
                sidebarBadge.style.display = '';
            } else {
                sidebarBadge.style.display = 'none';
            }
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

    function getFilteredMessages() {
        const query = String(searchInput ? searchInput.value : '').trim().toLowerCase();
        return allMessages.filter(function(row) {
            return messageMatchesQuery(row, query);
        });
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

        if (!filtered.length) {
            emptyEl.style.display = allMessages.length ? '' : 'none';
            if (allMessages.length) {
                emptyEl.textContent = 'No contact messages match your search.';
            } else {
                emptyEl.textContent = 'No contact messages found.';
            }
            updateSearchMeta(0, allMessages.length, query);
            return;
        }

        emptyEl.style.display = 'none';
        filtered.forEach(function(row) {
            const tr = document.createElement('tr');
            tr.innerHTML =
                '<td>' + escapeHtml(formatDateTime(row.created_at)) + '</td>' +
                '<td>' + escapeHtml(row.full_name) + '</td>' +
                '<td><a href="mailto:' + escapeHtml(row.email) + '">' + escapeHtml(row.email) + '</a></td>' +
                '<td>' + formatPhoneLink(row.phone_number) + '</td>' +
                '<td class="text-truncate" style="max-width:280px;">' + escapeHtml(row.message) + '</td>' +
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

        updateSearchMeta(filtered.length, allMessages.length, query);
    }

    async function loadMessages() {
        if (tbody) {
            tbody.innerHTML = '<tr><td colspan="7" class="text-center py-4 text-muted">Loading contact messages...</td></tr>';
        }

        try {
            const resp = await fetch('../assets/api/contact/manage_contact_messages.php?status=' + encodeURIComponent(currentStatus), {
                cache: 'no-store'
            });
            const data = await resp.json();
            if (!resp.ok || !data.success) {
                throw new Error(data.message || 'Unable to load contact messages.');
            }

            allMessages = Array.isArray(data.messages) ? data.messages : [];
            updateBadges(Number(data.new_count || 0));
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
        updateBadges(Number(data.new_count || 0));
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
            if (searchInput) searchInput.value = '';
            if (searchClearBtn) searchClearBtn.style.display = 'none';
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
