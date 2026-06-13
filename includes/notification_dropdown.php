<?php
$notifications_portal_url = $notifications_portal_url ?? '#';
?>
<li class="nav-item header-dropdown header-dropdown--notifications">
    <a class="nav-link nav-icon nav-notifications header-dropdown__toggle" href="#" role="button"
        aria-label="Notifications" id="navNotificationsToggle" data-portal-dropdown-toggle aria-haspopup="true" aria-expanded="false">
        <span class="nav-notifications__wrap">
            <i class="bi bi-bell"></i>
            <span id="navNotificationBadge" class="nav-notifications__badge" hidden aria-hidden="true">0</span>
        </span>
    </a>
    <div class="header-dropdown__menu notifications-menu" role="menu">
        <div class="notifications-menu__header">
            <div class="notifications-menu__heading">
                <span class="notifications-menu__title">Notifications</span>
                <span id="navNotificationSubtitle" class="notifications-menu__subtitle">Stay updated</span>
            </div>
            <button type="button" id="navMarkAllRead" class="notifications-menu__mark-all">Mark all read</button>
        </div>
        <div id="navNotificationEmpty" class="notifications-menu__empty" hidden>
            <i class="bi bi-bell-slash" aria-hidden="true"></i>
            <span>You have no notifications yet.</span>
        </div>
        <div id="navNotificationList" class="notifications-menu__list"></div>
        <a class="notifications-menu__footer" href="<?php echo htmlspecialchars($notifications_portal_url); ?>">
            View all notifications
            <i class="bi bi-arrow-right-short" aria-hidden="true"></i>
        </a>
    </div>
</li>
