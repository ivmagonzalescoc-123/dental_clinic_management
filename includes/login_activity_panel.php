<?php
/**
 * Shared "Device & Login Activity" panel for the Account Settings page.
 * Expects, set by the including file, before this include:
 *   $loginActivityTable    'patient_logs' or 'user_logs'
 *   $loginActivityIdColumn 'patient_id' or 'user_id'
 *   $loginActivityIdValue  (int) the current session's patient_id/user_id
 *   $conn                  mysqli connection (already in scope on portal pages)
 */

include_once dirname(__DIR__) . '/config/login_activity_helpers.php';

$loginActivityLogs = [];
if (!empty($loginActivityIdValue) && isset($conn) && $conn instanceof mysqli) {
    // Fetch a larger window than before so the date-range filter below has
    // something real to filter — the scrollable list keeps this from
    // growing the page.
    $loginActivityLogs = login_activity_get_logs($conn, $loginActivityTable, $loginActivityIdColumn, (int)$loginActivityIdValue, 50);
}

$loginActivityCurrent = $loginActivityLogs[0] ?? null;
$loginActivityHistory = count($loginActivityLogs) > 1 ? array_slice($loginActivityLogs, 1) : [];

if (!function_exists('login_activity_format_time')) {
    function login_activity_format_time($value)
    {
        if (empty($value)) {
            return '—';
        }
        $ts = strtotime((string)$value);
        return $ts ? date('M j, Y g:i A', $ts) : '—';
    }
}
?>
<div class="card border-0 shadow-sm">
    <div class="card-header">
        <h5 class="card-title mb-0"><i class="bi bi-shield-check me-1"></i>Device &amp; Login Activity</h5>
    </div>
    <div class="card-body">
        <div class="dpp-section-title mb-2"><i class="bi bi-laptop"></i> Current Device</div>
        <?php if ($loginActivityCurrent): ?>
            <?php $curDevice = login_activity_parse_user_agent($loginActivityCurrent['user_agent'] ?? ''); ?>
            <div class="dpp-header" style="margin-bottom:16px;">
                <div class="dpp-avatar" style="background:#0d9488;">
                    <i class="bi <?php echo htmlspecialchars($curDevice['icon']); ?>" style="font-size:1.4rem;"></i>
                </div>
                <div>
                    <p class="dpp-name" style="font-size:1rem;"><?php echo htmlspecialchars($curDevice['label']); ?></p>
                    <div class="dpp-chips">
                        <span class="dpp-chip"><i class="bi bi-geo-alt"></i> <?php echo htmlspecialchars($loginActivityCurrent['ip_address'] ?: 'Unknown IP'); ?></span>
                        <span class="dpp-chip"><i class="bi bi-clock-history"></i> <?php echo htmlspecialchars(login_activity_format_time($loginActivityCurrent['login_time'])); ?></span>
                    </div>
                </div>
            </div>
        <?php else: ?>
            <p class="text-muted small mb-3">No login activity recorded yet.</p>
        <?php endif; ?>

        <div class="d-flex align-items-center justify-content-between flex-wrap gap-2 mb-2 mt-2">
            <div class="dpp-section-title mb-0"><i class="bi bi-clock-history"></i> Login History</div>
            <?php if (!empty($loginActivityHistory)): ?>
                <form class="d-flex align-items-center gap-1 flex-wrap" id="loginActivityFilterForm" onsubmit="return false;">
                    <input type="date" class="form-control form-control-sm" id="loginActivityFrom" style="width:150px;" aria-label="From date">
                    <span class="text-muted small">to</span>
                    <input type="date" class="form-control form-control-sm" id="loginActivityTo" style="width:150px;" aria-label="To date">
                    <button type="button" class="btn btn-sm btn-primary" id="loginActivityFilterBtn"><i class="bi bi-funnel"></i> Filter</button>
                    <button type="button" class="btn btn-sm btn-outline-secondary" id="loginActivityFilterClearBtn">Clear</button>
                </form>
            <?php endif; ?>
        </div>
        <?php if (empty($loginActivityHistory)): ?>
            <p class="text-muted small mb-0">No earlier logins on file.</p>
        <?php else: ?>
            <div class="d-flex flex-column gap-2" id="loginActivityHistoryList" style="max-height:320px;overflow-y:auto;padding-right:4px;">
                <?php foreach ($loginActivityHistory as $log): ?>
                    <?php
                        $device = login_activity_parse_user_agent($log['user_agent'] ?? '');
                        $loginTs = !empty($log['login_time']) ? strtotime((string)$log['login_time']) : false;
                        $loginDateAttr = $loginTs ? date('Y-m-d', $loginTs) : '';
                    ?>
                    <div class="dpp-section login-activity-entry" data-login-date="<?php echo htmlspecialchars($loginDateAttr); ?>" style="margin-bottom:0;padding:10px 12px;">
                        <div class="d-flex align-items-center justify-content-between flex-wrap gap-2">
                            <div class="d-flex align-items-center gap-2">
                                <i class="bi <?php echo htmlspecialchars($device['icon']); ?> text-muted"></i>
                                <span class="dpp-field-value"><?php echo htmlspecialchars($device['label']); ?></span>
                            </div>
                            <span class="dpp-field-label mb-0"><?php echo htmlspecialchars(login_activity_format_time($log['login_time'])); ?></span>
                        </div>
                        <div class="dpp-field-label mt-1"><i class="bi bi-geo-alt"></i> <?php echo htmlspecialchars($log['ip_address'] ?: 'Unknown IP'); ?></div>
                    </div>
                <?php endforeach; ?>
                <p class="text-muted small mb-0 text-center py-2" id="loginActivityNoMatch" style="display:none;">No logins in that date range.</p>
            </div>
        <?php endif; ?>
    </div>
</div>

<?php if (!empty($loginActivityHistory)): ?>
<script>
(function () {
    var fromInput = document.getElementById('loginActivityFrom');
    var toInput = document.getElementById('loginActivityTo');
    var filterBtn = document.getElementById('loginActivityFilterBtn');
    var clearBtn = document.getElementById('loginActivityFilterClearBtn');
    var entries = document.querySelectorAll('.login-activity-entry');
    var noMatch = document.getElementById('loginActivityNoMatch');
    if (!filterBtn || !entries.length) return;

    function applyFilter() {
        var from = fromInput.value || null;
        var to = toInput.value || null;
        var visibleCount = 0;
        entries.forEach(function (entry) {
            var date = entry.getAttribute('data-login-date') || '';
            var matches = true;
            if (from && date && date < from) matches = false;
            if (to && date && date > to) matches = false;
            entry.style.display = matches ? '' : 'none';
            if (matches) visibleCount++;
        });
        if (noMatch) noMatch.style.display = visibleCount === 0 ? '' : 'none';
    }

    filterBtn.addEventListener('click', applyFilter);
    clearBtn?.addEventListener('click', function () {
        fromInput.value = '';
        toInput.value = '';
        applyFilter();
    });
})();
</script>
<?php endif; ?>
