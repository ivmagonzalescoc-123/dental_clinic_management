<li class="nav-item header-clock">
    <button type="button" class="header-clock__time" id="headerClockTrigger" aria-haspopup="dialog" aria-expanded="false" aria-label="Show analog clock">
        <span id="headerClockDisplay"><?php echo date('g:i A'); ?></span>
    </button>
</li>

<div class="analog-clock-overlay" id="analogClockOverlay" aria-hidden="true">
    <div class="analog-clock-popup" role="dialog" aria-modal="true" aria-label="Current time">
        <button type="button" class="analog-clock-popup__close" id="analogClockClose" aria-label="Close clock">
            <i class="bi bi-x-lg"></i>
        </button>
        <div class="analog-clock-face">
            <span class="analog-clock-face__number">12</span>
            <span class="analog-clock-face__number">1</span>
            <span class="analog-clock-face__number">2</span>
            <span class="analog-clock-face__number">3</span>
            <span class="analog-clock-face__number">4</span>
            <span class="analog-clock-face__number">5</span>
            <span class="analog-clock-face__number">6</span>
            <span class="analog-clock-face__number">7</span>
            <span class="analog-clock-face__number">8</span>
            <span class="analog-clock-face__number">9</span>
            <span class="analog-clock-face__number">10</span>
            <span class="analog-clock-face__number">11</span>
            <div class="analog-clock-hand analog-clock-hand--hour" id="analogClockHour"></div>
            <div class="analog-clock-hand analog-clock-hand--minute" id="analogClockMinute"></div>
            <div class="analog-clock-hand analog-clock-hand--second" id="analogClockSecond"></div>
            <div class="analog-clock-face__center"></div>
        </div>
    </div>
</div>
