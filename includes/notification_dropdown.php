<?php
$notifications_portal_url = $notifications_portal_url ?? '#';
?>
<li class="nav-item header-dropdown header-dropdown--notifications">
    <a class="nav-link nav-icon nav-notifications header-dropdown__toggle" href="#" role="button"
        aria-label="Notifications" id="navNotificationsToggle" data-portal-dropdown-toggle aria-haspopup="true" aria-expanded="false">
        <i class="bi bi-bell"></i>
        <span id="navNotificationBadge" class="nav-notifications__badge" hidden aria-hidden="true">0</span>
    </a>
    <div class="header-dropdown__menu notifications-menu" role="menu">
        <div id="navNotificationEmpty" class="notifications-menu__empty" hidden>
            <i class="bi bi-bell-slash" aria-hidden="true"></i>
            <span>You're all caught up!</span>
        </div>
        <div id="navNotificationList" class="notifications-menu__list"></div>
        <div class="notifications-menu__footer-row">
            <button type="button" id="navMarkAllRead" class="notifications-menu__mark-all">Mark all read</button>
            <a class="notifications-menu__view-all" href="<?php echo htmlspecialchars($notifications_portal_url); ?>">View all <i class="bi bi-arrow-right-short"></i></a>
        </div>
    </div>
</li>
