<?php
$hour = (int)date('H');
$isNight = $hour < 6 || $hour >= 18;
$clockIcon = $isNight ? 'bi-moon-stars' : 'bi-sun';
?>
<li class="nav-item header-clock">
    <span class="header-clock__time">
        <i class="bi <?php echo $clockIcon; ?> header-clock__icon"></i>
        <span id="headerClockDisplay"><?php echo date('g:i A'); ?></span>
    </span>
</li>
