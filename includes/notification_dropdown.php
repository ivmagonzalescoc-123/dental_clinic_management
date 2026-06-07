<?php
$notifications_portal_url = $notifications_portal_url ?? '#';
?>
<li class="nav-item dropdown">
    <a class="nav-link nav-icon nav-notifications" href="#" data-bs-toggle="dropdown" aria-label="Notifications" id="navNotificationsToggle">
        <span class="nav-notifications__wrap">
            <i class="bi bi-bell"></i>
            <span id="navNotificationBadge" class="nav-notifications__badge" hidden aria-hidden="true">0</span>
        </span>
    </a>
    <ul class="dropdown-menu dropdown-menu-end dropdown-menu-arrow notifications notifications-dropdown">
        <li class="dropdown-header notifications-dropdown__header">
            <div>
                <span class="notifications-dropdown__title">Notifications</span>
                <span id="navNotificationSubtitle" class="notifications-dropdown__subtitle">Stay updated</span>
            </div>
            <button type="button" id="navMarkAllRead" class="btn btn-sm btn-link notifications-mark-all">Mark all read</button>
        </li>
        <li><hr class="dropdown-divider m-0"></li>
        <li id="navNotificationEmpty" class="notifications-dropdown__empty" hidden>No notifications yet.</li>
        <li id="navNotificationList" class="notifications-list"></li>
        <li><hr class="dropdown-divider m-0"></li>
        <li>
            <a class="dropdown-item notifications-dropdown__footer" href="<?php echo htmlspecialchars($notifications_portal_url); ?>">View all notifications</a>
        </li>
    </ul>
</li>
