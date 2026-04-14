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
            const notificationId = n.notification_id || '';
            const href = resolveHref(n.link);
            const item = document.createElement('div');
            item.className = 'list-group-item py-3';

            const title = document.createElement('div');
            title.className = 'fw-semibold';
            title.textContent = n.title || 'Notification';

            const body = document.createElement('div');
            body.className = 'small text-muted mt-1';
            body.textContent = n.body || '';

            const meta = document.createElement('div');
            meta.className = 'd-flex justify-content-between align-items-center mt-2';
            const dateEl = document.createElement('small');
            dateEl.className = 'text-muted';
            dateEl.textContent = n.created_at ? new Date(n.created_at).toLocaleString() : '';
            meta.appendChild(dateEl);

            if (href) {
                const openBtn = document.createElement('a');
                openBtn.className = 'btn btn-sm btn-primary';
                openBtn.href = href;
                openBtn.textContent = 'Open';
                openBtn.addEventListener('click', function() {
                    markRead(notificationId);
                });
                meta.appendChild(openBtn);
            }

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
            for (const n of loadedNotifications) {
                if (n && n.notification_id) {
                    await markRead(n.notification_id);
                }
            }
            await loadNotifications();
        });
    }

    loadNotifications();
})();
</script>
