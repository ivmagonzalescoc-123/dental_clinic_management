<?php
include dirname(dirname(__DIR__)) . '/config/database.php';

$patient_id = $_SESSION['patient_id'] ?? 0;

$services = [];
$svcSql = "SELECT s.service_id,
                  s.service_name,
                  s.initial_price,
                  s.description,
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
           ) ts ON ts.service_id = s.service_id
           WHERE s.is_active = 1
           ORDER BY s.service_name ASC";
$svcRes = $conn->query($svcSql);
if ($svcRes) {
    while ($r = $svcRes->fetch_assoc()) $services[] = $r;
}
?>

<style>
.patient-svc-grid {
    display: grid;
    grid-template-columns: repeat(5, 1fr);
    gap: 1.15rem;
}
.patient-svc-card {
    position: relative;
    border: 1px solid #e2e8f0;
    border-radius: 14px;
    background: #f5fbfa;
    padding: 1.4rem 1rem;
    cursor: pointer;
    text-align: center;
    z-index: 1;
    transition: box-shadow 0.3s ease, border-color 0.3s ease;
    animation: svcCardIn 0.4s cubic-bezier(0.22,1,0.36,1) forwards;
    opacity: 0;
}
.patient-svc-card:nth-child(5n + 1) { background:#dff3ef; }
.patient-svc-card:nth-child(5n + 2) { background:#e8eafa; }
.patient-svc-card:nth-child(5n + 3) { background:#fff0d9; }
.patient-svc-card:nth-child(5n + 4) { background:#e7f3df; }
.patient-svc-card:nth-child(5n + 5) { background:#f2e4f5; }
@keyframes svcCardIn {
    from { opacity:0; transform:translateY(18px); }
    to   { opacity:1; transform:translateY(0); }
}
.patient-svc-card:hover {
    border-color:#0d9488;
    background:#eef9f7;
    box-shadow:0 10px 28px rgba(13,148,136,0.12);
    transform:translateY(-5px);
}

.svc-card-name {
    font-size:0.95rem;
    font-weight:600;
    color:#0f172a;
    line-height:1.35;
}

/* ---- Overlay wrapper ---- */
.patient-svc-zoom-wrap {
    position:fixed;
    inset:0;
    z-index:9999;
    display:flex;
    align-items:center;
    justify-content:center;
    background:rgba(15,23,42,0.55);
    backdrop-filter:blur(5px);
    -webkit-backdrop-filter:blur(5px);
    opacity:0;
    pointer-events:none;
    transition:opacity 0.3s ease;
}
.patient-svc-zoom-wrap.is-active {
    opacity:1;
    pointer-events:auto;
}

/* ---- Zoom card inside overlay ---- */
.patient-svc-zoom-card {
    position:relative;
    width:460px;
    max-width:90vw;
    background:#fff;
    border-radius:18px;
    padding:2rem 2rem 1.75rem;
    text-align:center;
    box-shadow:0 25px 60px rgba(0,0,0,0.22);
    cursor:default;
    transform:scale(0.7);
    opacity:0;
    transition:transform 0.35s cubic-bezier(0.22,1,0.36,1), opacity 0.35s ease;
}
.patient-svc-zoom-card .svc-zoom-close {
    position:absolute;
    top:12px;
    right:12px;
    width:30px;
    height:30px;
    border:none;
    border-radius:50%;
    background:rgba(0,0,0,0.06);
    cursor:pointer;
    display:flex;
    align-items:center;
    justify-content:center;
    transition:background 0.2s ease;
    padding:0;
}
.patient-svc-zoom-card .svc-zoom-close:hover {
    background:rgba(0,0,0,0.12);
}
.patient-svc-zoom-card .svc-zoom-close::before,
.patient-svc-zoom-card .svc-zoom-close::after {
    content:'';
    position:absolute;
    width:14px;
    height:1.5px;
    background:#64748b;
    border-radius:1px;
}
.patient-svc-zoom-card .svc-zoom-close::before { transform:rotate(45deg); }
.patient-svc-zoom-card .svc-zoom-close::after { transform:rotate(-45deg); }
.patient-svc-zoom-wrap.is-active .patient-svc-zoom-card {
    transform:scale(1);
    opacity:1;
}
.patient-svc-zoom-card .svc-card-name { font-size:1.3rem; }
.patient-svc-zoom-card .svc-card-duration {
    display:inline-flex;
    align-items:center;
    gap:4px;
    font-size:0.85rem;
    color:#64748b;
    background:#f8fafc;
    border:1px solid #e2e8f0;
    border-radius:20px;
    padding:4px 14px;
    font-weight:500;
    margin-top:0.25rem;
}
.patient-svc-zoom-card .svc-card-desc {
    font-size:0.88rem;
    color:#64748b;
    line-height:1.65;
    margin-top:0.5rem;
    text-align:left;
    max-height:50vh;
    overflow-y:auto;
}

.patient-svc-no-results {
    grid-column:1/-1;
    text-align:center;
    padding:2.5rem 1rem;
    color:#94a3b8;
    font-size:0.95rem;
}@media (max-width:1200px) { .patient-svc-grid{grid-template-columns:repeat(4,1fr);} }
@media (max-width:992px)  { .patient-svc-grid{grid-template-columns:repeat(3,1fr);} }
@media (max-width:768px)  { .patient-svc-grid{grid-template-columns:repeat(2,1fr);gap:0.85rem;} .patient-svc-card{padding:1.1rem 0.85rem;} .svc-card-name{font-size:0.88rem;} }
@media (max-width:480px)  { .patient-svc-grid{grid-template-columns:1fr 1fr;gap:0.7rem;} .patient-svc-card{padding:0.9rem 0.65rem;} .svc-card-name{font-size:0.82rem;} }
</style>

<div class="card border-0">
    <div class="card-header d-flex flex-wrap justify-content-between align-items-center gap-2">
        <h5 class="card-title mb-0">Services</h5>
        <span class="badge bg-primary-subtle text-primary border border-primary-subtle px-3 py-2">
            <?php echo count($services); ?> service<?php echo count($services) === 1 ? '' : 's'; ?>
        </span>
    </div>
    <div class="card-body">
        <div class="unified-table-search-toolbar mb-3">
            <div class="unified-table-search-wrap">
                <i class="bi bi-search unified-table-search-icon" aria-hidden="true"></i>
                <input type="search" id="patientServicesSearch" class="form-control unified-table-search-input"
                    placeholder="Search service name or description..." autocomplete="off" aria-label="Search services">
                <button type="button" class="unified-table-search-clear" id="patientServicesSearchClear" aria-label="Clear search" style="display:none;">&times;</button>
            </div>
            <div id="patientServicesSearchMeta" class="patient-appt-history-search-meta"></div>
        </div>

        <?php if (empty($services)): ?>
            <div class="text-center text-muted py-4">No services available.</div>
        <?php else: ?>
            <div class="patient-svc-grid" id="patientSvcGrid">
                <?php foreach ($services as $i => $s): ?>
                    <?php
                    $duration = (int)($s['service_duration_minutes'] ?? 0);
                    $searchBlob = strtolower(trim(
                        ($s['service_name'] ?? '') . ' ' . ($s['description'] ?? '')
                    ));
                    ?>
                    <div class="patient-svc-card" tabindex="0" role="button"
                         aria-label="<?php echo htmlspecialchars($s['service_name']); ?>"
                         data-search="<?php echo htmlspecialchars($searchBlob, ENT_QUOTES, 'UTF-8'); ?>"
                         data-name="<?php echo htmlspecialchars($s['service_name'], ENT_QUOTES, 'UTF-8'); ?>"
                         data-desc="<?php echo htmlspecialchars($s['description'] ?? 'No description available.', ENT_QUOTES, 'UTF-8'); ?>"
                         data-duration="<?php echo $duration; ?>"
                         style="animation-delay:<?php echo $i * 0.045; ?>s">
                        <div class="svc-card-name"><?php echo htmlspecialchars($s['service_name']); ?></div>
                    </div>
                <?php endforeach; ?>
                <div class="patient-svc-no-results" id="patientSvcNoResults" style="display:none;">No services match your search.</div>
            </div>
        <?php endif; ?>
    </div>
</div>

<div class="patient-svc-zoom-wrap" id="svcZoomWrap">
    <div class="patient-svc-zoom-card" id="svcZoomCard">
        <button type="button" class="svc-zoom-close" id="svcZoomClose" aria-label="Close"></button>
        <div class="svc-card-name" id="zoomName"></div>
        <div class="svc-card-duration" id="zoomDur"></div>
        <div class="svc-card-desc" id="zoomDesc"></div>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function () {
    var searchInput = document.getElementById('patientServicesSearch');
    var clearBtn = document.getElementById('patientServicesSearchClear');
    var meta = document.getElementById('patientServicesSearchMeta');
    var cards = Array.from(document.querySelectorAll('.patient-svc-card'));
    var noResults = document.getElementById('patientSvcNoResults');
    var wrap = document.getElementById('svcZoomWrap');
    var zoomCard = document.getElementById('svcZoomCard');
    var zoomName = document.getElementById('zoomName');
    var zoomDur = document.getElementById('zoomDur');
    var zoomDesc = document.getElementById('zoomDesc');
    var closeBtn = document.getElementById('svcZoomClose');
    if (!searchInput || cards.length === 0) return;

    var total = cards.length;
    var isOpen = false;
    var isAnimating = false;

    function updateList() {
        var term = (searchInput.value || '').trim().toLowerCase();
        var visible = 0;
        cards.forEach(function (c) {
            var show = term === '' || (c.getAttribute('data-search') || '').indexOf(term) !== -1;
            c.style.display = show ? '' : 'none';
            if (show) visible++;
        });
        if (clearBtn) clearBtn.style.display = term ? '' : 'none';
        if (meta) meta.textContent = term ? 'Showing ' + visible + ' of ' + total + ' services' : 'Showing all ' + total + ' services';
        if (noResults) noResults.style.display = visible === 0 ? '' : 'none';
    }
    searchInput.addEventListener('input', updateList);
    if (clearBtn) clearBtn.addEventListener('click', function () { searchInput.value = ''; searchInput.focus(); updateList(); });
    updateList();

    function zoomIn(card) {
        if (isAnimating || isOpen) return;
        isAnimating = true;

        zoomName.textContent = card.getAttribute('data-name');
        var d = parseInt(card.getAttribute('data-duration'));
        zoomDur.textContent = d > 0 ? d + ' min' : 'TBD';
        zoomDesc.textContent = card.getAttribute('data-desc');

        zoomCard.style.transition = 'none';
        zoomCard.style.transform = 'scale(0.7)';
        zoomCard.style.opacity = '0';

        wrap.classList.add('is-active');
        document.body.style.overflow = 'hidden';
        zoomCard.offsetHeight;

        zoomCard.style.transition = 'transform 0.35s cubic-bezier(0.22,1,0.36,1), opacity 0.35s ease';
        zoomCard.style.transform = 'scale(1)';
        zoomCard.style.opacity = '1';

        isOpen = true;
        setTimeout(function () { isAnimating = false; }, 360);
    }

    function zoomOut() {
        if (isAnimating || !isOpen) return;
        isAnimating = true;

        zoomCard.style.transition = 'transform 0.3s cubic-bezier(0.22,1,0.36,1), opacity 0.3s ease';
        zoomCard.style.transform = 'scale(0.85)';
        zoomCard.style.opacity = '0';
        wrap.classList.remove('is-active');
        document.body.style.overflow = '';

        setTimeout(function () {
            zoomCard.style.transform = 'scale(0.7)';
            isOpen = false;
            isAnimating = false;
        }, 320);
    }

    cards.forEach(function (card) {
        card.addEventListener('click', function () { zoomIn(card); });
        card.addEventListener('keydown', function (e) {
            if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); zoomIn(card); }
        });
    });

    wrap.addEventListener('click', function (e) {
        if (e.target === wrap) zoomOut();
    });
    closeBtn.addEventListener('click', function (e) {
        e.stopPropagation();
        zoomOut();
    });
    zoomCard.addEventListener('click', function (e) { e.stopPropagation(); });
    document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape' && isOpen) zoomOut();
    });
});
</script>
