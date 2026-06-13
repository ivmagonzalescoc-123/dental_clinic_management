<?php
include dirname(dirname(__DIR__)) . '/config/database.php';

$hasIsActive = false;
$colResult = $conn->query("SHOW COLUMNS FROM services LIKE 'is_active'");
if ($colResult && $colResult->num_rows > 0) {
    $hasIsActive = true;
}

$services = [];
$svcSql = "SELECT s.service_id,
                  s.service_name,
                  s.initial_price,
                  s.description,
                  s.created_at,
                  s.default_duration_minutes,
                  COALESCE(ts.duration_minutes, s.default_duration_minutes, 30) AS service_duration_minutes
           FROM services s
           LEFT JOIN (
               SELECT t1.service_id, t1.duration_minutes
               FROM timeslot_service t1
               INNER JOIN (
                   SELECT service_id, MAX(id) AS max_id
                   FROM timeslot_service
                   GROUP BY service_id
               ) t2 ON t1.id = t2.max_id
           ) ts ON ts.service_id = s.service_id";
if ($hasIsActive) {
    $svcSql .= " WHERE s.is_active = 1";
}
$svcSql .= " ORDER BY s.service_name ASC";

$svcRes = $conn->query($svcSql);
if ($svcRes) {
    while ($row = $svcRes->fetch_assoc()) {
        $services[] = $row;
    }
}
?>

<div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
    <div>
        <h5 class="card-title mb-1">Clinic Services</h5>
        <small class="text-muted">Reference list of available services, starting prices, and estimated duration.</small>
    </div>
    <span class="badge bg-primary-subtle text-primary border border-primary-subtle px-3 py-2">
        <?php echo count($services); ?> service<?php echo count($services) === 1 ? '' : 's'; ?>
    </span>
</div>

<div class="unified-table-search-toolbar patient-appt-history-search-toolbar mb-3">
    <div class="unified-table-search-wrap module-search-inline patient-appt-history-search-wrap">
        <i class="bi bi-search unified-table-search-icon" aria-hidden="true"></i>
        <input
            type="search"
            id="secretaryServicesSearch"
            class="form-control unified-table-search-input"
            placeholder="Search service name or description..."
            autocomplete="off"
            aria-label="Search services"
        >
        <button type="button" class="unified-table-search-clear" id="secretaryServicesSearchClear" aria-label="Clear search" style="display:none;">&times;</button>
    </div>
    <div id="secretaryServicesSearchMeta" class="patient-appt-history-search-meta"></div>
</div>

<?php if (empty($services)): ?>
    <div class="alert alert-info mb-0">No services are available right now.</div>
<?php else: ?>
    <div class="table-responsive border rounded">
        <table class="table table-hover align-middle mb-0" id="secretaryServicesTable" data-skip-table-search="1">
            <thead class="table-light">
                <tr>
                    <th>Service</th>
                    <th>Starting Price</th>
                    <th>Est. Duration</th>
                    <th>Description</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($services as $service): ?>
                    <?php
                    $duration = (int)($service['service_duration_minutes'] ?? 0);
                    $searchBlob = strtolower(trim(
                        ($service['service_name'] ?? '') . ' ' .
                        ($service['description'] ?? '') . ' ' .
                        number_format((float)($service['initial_price'] ?? 0), 2)
                    ));
                    ?>
                    <tr data-search="<?php echo htmlspecialchars($searchBlob, ENT_QUOTES, 'UTF-8'); ?>">
                        <td class="fw-semibold"><?php echo htmlspecialchars($service['service_name']); ?></td>
                        <td class="text-primary fw-semibold">₱<?php echo number_format((float)$service['initial_price'], 2); ?></td>
                        <td>
                            <?php if ($duration > 0): ?>
                                <span class="badge bg-light text-dark border"><?php echo $duration; ?> min</span>
                            <?php else: ?>
                                <span class="text-muted">TBD</span>
                            <?php endif; ?>
                        </td>
                        <td class="text-muted small" style="max-width: 420px;">
                            <?php echo htmlspecialchars($service['description'] ?? '—'); ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
<?php endif; ?>

<script>
document.addEventListener('DOMContentLoaded', function () {
    const searchInput = document.getElementById('secretaryServicesSearch');
    const clearBtn = document.getElementById('secretaryServicesSearchClear');
    const meta = document.getElementById('secretaryServicesSearchMeta');
    const rows = Array.from(document.querySelectorAll('#secretaryServicesTable tbody tr'));
    if (!searchInput || rows.length === 0) return;

    const total = rows.length;

    function updateList() {
        const term = (searchInput.value || '').trim().toLowerCase();
        let visible = 0;

        rows.forEach(function (row) {
            const haystack = row.getAttribute('data-search') || '';
            const show = term === '' || haystack.indexOf(term) !== -1;
            row.style.display = show ? '' : 'none';
            if (show) visible++;
        });

        if (clearBtn) {
            clearBtn.style.display = term ? '' : 'none';
        }
        if (meta) {
            meta.textContent = term
                ? ('Showing ' + visible + ' of ' + total + ' services')
                : ('Showing all ' + total + ' services');
        }
    }

    searchInput.addEventListener('input', updateList);
    if (clearBtn) {
        clearBtn.addEventListener('click', function () {
            searchInput.value = '';
            searchInput.focus();
            updateList();
        });
    }

    updateList();
});
</script>
