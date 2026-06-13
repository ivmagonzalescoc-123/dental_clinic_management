<?php
$profile_portal_base = $profile_portal_base ?? '#';
$profile_name = $header_display_name ?? 'My Account';
$profile_role = $header_role_label ?? '';
?>
<li class="nav-item header-dropdown header-dropdown--profile">
    <a class="nav-link nav-profile header-dropdown__toggle d-flex align-items-center pe-0" href="#" role="button"
        data-portal-dropdown-toggle aria-haspopup="true" aria-expanded="false">
        <?php echo profile_render_avatar_markup($conn, '../', ['class' => 'rounded-circle header-profile-avatar', 'alt' => 'Profile']); ?>
        <span class="d-none d-lg-block user-display-name"><?php echo htmlspecialchars($profile_name); ?></span>
        <i class="bi bi-chevron-down header-dropdown__caret d-none d-lg-block" aria-hidden="true"></i>
    </a>
    <div class="header-dropdown__menu profile-menu" role="menu">
        <div class="profile-menu__header">
            <?php echo profile_render_avatar_markup($conn, '../', ['class' => 'rounded-circle profile-menu__avatar', 'alt' => 'Profile']); ?>
            <div class="profile-menu__id">
                <span class="profile-menu__name"><?php echo htmlspecialchars($profile_name); ?></span>
                <?php if ($profile_role !== ''): ?>
                    <span class="profile-menu__role"><?php echo htmlspecialchars($profile_role); ?></span>
                <?php endif; ?>
            </div>
        </div>
        <div class="profile-menu__items">
            <a class="profile-menu__item" href="<?php echo htmlspecialchars($profile_portal_base); ?>?page=profile" role="menuitem">
                <i class="bi bi-person-circle" aria-hidden="true"></i>
                <span>My Profile</span>
            </a>
            <a class="profile-menu__item" href="<?php echo htmlspecialchars($profile_portal_base); ?>?page=settings" role="menuitem">
                <i class="bi bi-gear" aria-hidden="true"></i>
                <span>Account Settings</span>
            </a>
            <div class="profile-menu__divider" role="separator"></div>
            <button type="button" class="profile-menu__item profile-menu__item--danger" data-no-action-loading="1" onclick="logout()" role="menuitem">
                <i class="bi bi-box-arrow-right" aria-hidden="true"></i>
                <span>Log Out</span>
            </button>
        </div>
    </div>
</li>
