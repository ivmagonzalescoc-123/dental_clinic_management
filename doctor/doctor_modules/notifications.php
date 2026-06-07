<div class="d-flex justify-content-between align-items-center mb-3">
    <h5 class="card-title mb-0">Notifications</h5>
    <button id="markAllNotificationsRead" class="btn btn-sm btn-outline-primary" type="button">Mark all as read</button>
</div>

<div id="notificationsEmpty" class="alert alert-info mb-0" style="display:none;">No notifications found.</div>
<div id="notificationsList" class="list-group"></div>

<script>
(function() {
    const listEl = document.getElementById('notificationsList');
    const emptyEl = document.getElementById('notificationsEmpty');
    const markAllBtn = document.getElementById('markAllNotificationsRead');
    let loadedNotifications = [];

    function resolveHref(link) {
        const raw = String(link || '').trim();
        if (!raw || raw === '#' || /^javascript:/i.test(raw)) return null;
        if (/^https?:\/\//i.test(raw)) return raw;
        if (raw.startsWith('/')) return raw;
        return raw;
    }

    function escapeHtml(value) {
        return String(value || '')
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#39;');
    }

    function highlightDateTimes(value) {
        const escaped = escapeHtml(value);
        const dateTimeRegex = /(\b\d{4}-\d{2}-\d{2}(?:[ T]\d{1,2}:\d{2}(?::\d{2})?)?\b|\b\d{1,2}:\d{2}(?::\d{2})?\s?(?:AM|PM|am|pm)?\b|\b(?:Jan|Feb|Mar|Apr|May|Jun|Jul|Aug|Sep|Sept|Oct|Nov|Dec)[a-z]*\s+\d{1,2},?\s+\d{4}\b)/g;
        return escaped.replace(dateTimeRegex, '<span class="notification-datetime">$1</span>');
    }

    async function markRead(notificationId) {
        if (!notificationId) return;
        try {
            await fetch('../assets/api/notifications/mark_read.php', {
                method: 'POST',
                body: new URLSearchParams({ notification_id: notificationId })
            });
        } catch (e) {
            // no-op
        }
    }

    function render(notifications) {
        listEl.innerHTML = '';
        const rows = Array.isArray(notifications) ? notifications : [];
        if (!rows.length) {
            emptyEl.style.display = '';
            return;
        }

        emptyEl.style.display = 'none';
        rows.forEach(function(n) {
            const item = document.createElement('div');
            item.className = 'list-group-item py-3';

            const title = document.createElement('div');
            title.className = 'fw-semibold';
            title.textContent = n.title || 'Notification';

            const body = document.createElement('div');
            body.className = 'small text-muted mt-1';
            body.innerHTML = highlightDateTimes(n.body || '');

            const meta = document.createElement('div');
            meta.className = 'd-flex justify-content-between align-items-center mt-2';
            const dateEl = document.createElement('small');
            dateEl.className = 'notification-time';
            dateEl.textContent = n.created_at ? new Date(n.created_at).toLocaleString() : '';
            meta.appendChild(dateEl);

            item.appendChild(title);
            item.appendChild(body);
            item.appendChild(meta);
            listEl.appendChild(item);
        });
    }

    async function loadNotifications() {
        try {
            const resp = await fetch('../assets/api/notifications/get_notifications.php', { cache: 'no-store' });
            const json = await resp.json();
            if (!resp.ok || !json.success) {
                emptyEl.style.display = '';
                emptyEl.textContent = 'Unable to load notifications.';
                return;
            }
            loadedNotifications = Array.isArray(json.notifications) ? json.notifications : [];
            render(loadedNotifications);
        } catch (e) {
            emptyEl.style.display = '';
            emptyEl.textContent = 'Unable to load notifications.';
        }
    }

    if (markAllBtn) {
        markAllBtn.addEventListener('click', async function() {
            try {
                await fetch('../assets/api/notifications/mark_all_read.php', { method: 'POST' });
            } catch (e) {
                // no-op
            }
            if (typeof window.markAllNotificationsRead === 'function') {
                window.markAllNotificationsRead();
                return;
            }
            await loadNotifications();
        });
    }

    loadNotifications();
})();
</script>
