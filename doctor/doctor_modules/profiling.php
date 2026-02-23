<?php
include dirname(dirname(__DIR__)) . '/config/database.php';
include_once dirname(dirname(__DIR__)) . '/config/session_check.php';
check_session(['doctor']);

// Allow either appointment_id via GET OR selection by queue number
$appointment_id = isset($_GET['appointment_id']) ? (int)$_GET['appointment_id'] : 0;

// If no appointment_id provided, fetch today's queue items for this doctor
$queueItems = [];
if (!$appointment_id) {
    $qstmt = $conn->prepare("SELECT q.appointment_id, q.que_number, a.patient_id, p.first_name, p.last_name, a.appointment_date FROM check_in_queue q INNER JOIN appointments a ON q.appointment_id = a.appointment_id JOIN patients p ON a.patient_id = p.patient_id WHERE a.user_id = ? AND a.appointment_date = ? AND q.status IN ('waiting','called') ORDER BY q.que_number ASC");
    if ($qstmt) {
        $today = date('Y-m-d');
        $qstmt->bind_param('is', $_SESSION['user_id'], $today);
        $qstmt->execute();
        $qres = $qstmt->get_result();
        while ($qr = $qres->fetch_assoc()) $queueItems[] = $qr;
        $qstmt->close();
    }
} else {
    // preload appointment when appointment_id provided
    $stmt = $conn->prepare('SELECT a.*, p.first_name, p.last_name FROM appointments a JOIN patients p ON a.patient_id = p.patient_id WHERE a.appointment_id = ?');
    $stmt->bind_param('i', $appointment_id);
    $stmt->execute();
    $res = $stmt->get_result();
    $appt = $res ? $res->fetch_assoc() : null;
    $stmt->close();

    if ($appt) {
        // populate a single queue-like item so UI can reuse the same dropdown/prefill logic
        $queueItems[] = ['appointment_id' => $appt['appointment_id'], 'que_number' => '', 'patient_id' => $appt['patient_id'], 'first_name' => $appt['first_name'], 'last_name' => $appt['last_name'], 'appointment_date' => $appt['appointment_date']];
    }
}

// Fetch services
$services = [];
$sstmt = $conn->prepare('SELECT service_id, service_name, initial_price FROM services WHERE is_active = 1');
$sstmt->execute();
$sres = $sstmt->get_result();
while ($r = $sres->fetch_assoc()) $services[] = $r;
$sstmt->close();
?>

<div class="card">
    <div class="card-body">
        <h4>Profiling / Consultation</h4>
        <p class="text-muted">Appointment: <span id="apptInfo"><?php echo $appointment_id ? htmlspecialchars($appointment_id) : 'Not selected'; ?></span></p>

        <form id="profilingForm">
            <input type="hidden" name="appointment_id" id="appointment_id_hidden" value="<?php echo (int)$appointment_id; ?>">

            <div class="mb-3" id="queueSelectorWrap">
                <label class="form-label">Select Queue Number / Patient</label>
                <select id="queueSelector" class="form-select">
                    <option value="">-- Select queue / appointment --</option>
                    <?php foreach ($queueItems as $qi): ?>
                        <?php $display = trim(($qi['que_number'] ? ('Queue #' . $qi['que_number'] . ' - ') : '') . ($qi['first_name'] . ' ' . $qi['last_name'])); ?>
                        <option value="<?php echo (int)$qi['appointment_id']; ?>" data-que="<?php echo htmlspecialchars($qi['que_number']); ?>"><?php echo htmlspecialchars($display); ?></option>
                    <?php endforeach; ?>
                </select>
                <div class="form-text">If you arrived from queue accept, the appointment will already be selected.</div>
            </div>

            <div class="mb-3">
                <label class="form-label">Recommended Services</label>
                <div>
                    <?php foreach ($services as $s): ?>
                    <div class="form-check">
                        <input class="form-check-input" type="checkbox" value="<?php echo (int)$s['service_id']; ?>" id="srv-<?php echo (int)$s['service_id']; ?>">
                        <label class="form-check-label" for="srv-<?php echo (int)$s['service_id']; ?>"><?php echo htmlspecialchars($s['service_name']); ?> — ₱<?php echo number_format($s['initial_price'],2); ?></label>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>

            <div class="mb-3">
                <label class="form-label">Treatment Plan (structured JSON allowed)</label>
                <textarea class="form-control" name="treatment_plan" id="treatment_plan" rows="3"></textarea>
            </div>

            <div class="mb-3">
                <label class="form-label">Materials Used</label>
                <div id="materialsList"></div>
                <button type="button" class="btn btn-sm btn-outline-primary mt-2" id="addMaterialBtn">Add Material</button>
            </div>

            <div class="mb-3">
                <label class="form-label"><i class="bi bi-tooth"></i> Odontogram (3D)</label>
                <div class="form-check mb-2">
                    <input class="form-check-input" type="checkbox" value="1" id="enableOdontogram" checked>
                    <label class="form-check-label" for="enableOdontogram">Load odontogram (optional)</label>
                    <button type="button" id="loadOdontogramBtn" class="btn btn-sm btn-outline-secondary ms-2" style="display:none;">Load Odontogram</button>
                </div>
                <div id="odontogramContainer">
                    <!-- iframe will be created here on demand -->
                </div>
                <input type="hidden" id="odontogram_data" name="odontogram">
            </div>

            <div class="mb-3">
                <label class="form-label">Doctor Notes</label>
                <textarea class="form-control" name="doctor_notes" id="doctor_notes" rows="3"></textarea>
            </div>

            <div class="mb-3">
                <label class="form-label">Follow-up Date</label>
                <input type="date" name="follow_up_date" class="form-control" id="follow_up_date">
            </div>

            <div class="d-flex gap-2">
                <button type="button" id="saveDraftBtn" class="btn btn-secondary">Save Draft</button>
                <button type="button" id="finalizeBtn" class="btn btn-primary">Submit & Finalize (Create Billing)</button>
            </div>
        </form>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function(){
    const addMaterialBtn = document.getElementById('addMaterialBtn');
    // create a global helper so prefill function can call it outside local scope
    window.addMaterialRow = function(data = {}){
        const materialsList = document.getElementById('materialsList');
        const div = document.createElement('div');
        div.className = 'd-flex gap-2 mb-2';
        div.innerHTML = `
            <input class="form-control form-control-sm" placeholder="Material name" value="${data.name||''}">
            <input class="form-control form-control-sm" placeholder="Qty" value="${data.qty||1}" style="width:80px;">
            <input class="form-control form-control-sm" placeholder="Price" value="${data.price||0}" style="width:120px;">
            <button type="button" class="btn btn-sm btn-danger">Remove</button>
        `;
        const removeBtn = div.querySelector('button');
        removeBtn.addEventListener('click', ()=>div.remove());
        materialsList.appendChild(div);
    };
    addMaterialBtn.addEventListener('click', ()=>window.addMaterialRow());

    // message listener from odontogram iframe (works even if iframe created later)
    window.addEventListener('message', (ev)=>{
        if (!ev.data) return;
        if (ev.data.type === 'odontogram_update'){
            document.getElementById('odontogram_data').value = JSON.stringify(ev.data.toothState || {});
        }
    });

    // Odontogram lazy-loading
    const odontogramContainer = document.getElementById('odontogramContainer');
    const enableOdontogram = document.getElementById('enableOdontogram');
    const loadOdontogramBtn = document.getElementById('loadOdontogramBtn');
    let odontogramFrame = null;

    function createOdontogramIframe(){
        if (odontogramFrame) return odontogramFrame;
        odontogramFrame = document.createElement('iframe');
        odontogramFrame.id = 'odontogramFrame';
        odontogramFrame.src = '/odontogram/index.html';
        odontogramFrame.style.width = '100%';
        odontogramFrame.style.height = '420px';
        odontogramFrame.style.border = '1px solid #ddd';
        odontogramContainer.innerHTML = '';
        odontogramContainer.appendChild(odontogramFrame);
        return odontogramFrame;
    }

    // When checkbox toggled, create/remove iframe or show load button
    enableOdontogram.addEventListener('change', ()=>{
        if (enableOdontogram.checked) {
            // create iframe immediately
            createOdontogramIframe();
            loadOdontogramBtn.style.display = 'none';
        } else {
            // remove iframe but keep stored data
            if (odontogramFrame && odontogramFrame.parentNode) odontogramFrame.parentNode.removeChild(odontogramFrame);
            odontogramFrame = null;
            loadOdontogramBtn.style.display = 'inline-block';
        }
    });

    loadOdontogramBtn.addEventListener('click', ()=>{
        enableOdontogram.checked = true;
        createOdontogramIframe();
        loadOdontogramBtn.style.display = 'none';
        // if there's preloaded odontogram data, send it after load
        const odData = document.getElementById('odontogram_data').value;
        if (odData) {
            odontogramFrame.addEventListener('load', ()=>{
                try { odontogramFrame.contentWindow.postMessage({ type: 'load_odontogram', toothState: JSON.parse(odData) }, '*'); } catch(e){}
            });
        }
    });

    // initialize: if unchecked, show load button; otherwise create iframe
    if (enableOdontogram.checked) {
        createOdontogramIframe();
    } else {
        loadOdontogramBtn.style.display = 'inline-block';
    }

    // Save draft
    document.getElementById('saveDraftBtn').addEventListener('click', async ()=>{
        await submitRecord(false);
    });

    document.getElementById('finalizeBtn').addEventListener('click', async ()=>{
        if (!confirm('Finalize and create billing for this visit?')) return;
        await submitRecord(true);
    });

    async function submitRecord(finalize){
        const appointment_id = parseInt(document.getElementById('appointment_id_hidden').value || '0', 10);
        if (!appointment_id) { alert('Please select a queue / appointment before saving.'); return; }
        const recommended = [...document.querySelectorAll('input[type=checkbox][id^=srv-]:checked')].map(i=>parseInt(i.value,10));
        const treatment_plan = (()=>{ try { return JSON.parse(document.getElementById('treatment_plan').value || 'null'); } catch(e){ return document.getElementById('treatment_plan').value || null; }})();
        const materials = [...materialsList.querySelectorAll('div')].map(div=>{
            const inputs = div.querySelectorAll('input');
            return { name: inputs[0].value, qty: parseFloat(inputs[1].value)||0, price: parseFloat(inputs[2].value)||0 };
        });
        const odontogram = (document.getElementById('odontogram_data').value || null);
        const doctor_notes = document.getElementById('doctor_notes').value;
        const follow_up_date = document.getElementById('follow_up_date').value || null;

        const fd = new FormData();
        fd.append('appointment_id', appointment_id);
        fd.append('recommended_services', JSON.stringify(recommended));
        fd.append('treatment_plan', JSON.stringify(treatment_plan));
        fd.append('materials_used', JSON.stringify(materials));
        fd.append('odontogram', odontogram ? odontogram : '');
        fd.append('doctor_notes', doctor_notes);
        fd.append('follow_up_date', follow_up_date);
        fd.append('finalize', finalize? '1' : '0');

        const resp = await fetch('/assets/api/ehr/save_clinical_record.php', { method: 'POST', body: fd });
        const j = await resp.json();
        if (j.success) {
            alert('Saved' + (finalize? ' and finalized. Billing created: ₱' + (j.billing_amount||0): ''));
            if (finalize) window.location.href = 'doctor.php?page=appointments';
        } else {
            alert('Error: ' + (j.message||'unknown'));
        }
    }
});
</script>

<script>
// Utility: fetch and prefill clinical record for a given appointment id
function prefillClinicalRecord(appointmentId){
    if (!appointmentId) return;
    fetch('/assets/api/ehr/get_clinical_record.php?appointment_id=' + encodeURIComponent(appointmentId))
        .then(r=>r.json())
        .then(data=>{
            if (!data || !data.record) return;
            const rec = data.record;
            // Recommended services (array of ids)
            try {
                if (rec.recommended_services && Array.isArray(rec.recommended_services)) {
                    rec.recommended_services.forEach(id=>{
                        const cb = document.getElementById('srv-' + id);
                        if (cb) cb.checked = true;
                    });
                }
            } catch(e){console.warn('prefill services', e)}

            // Treatment plan (could be JSON or string)
            try {
                if (rec.treatment_plan) {
                    if (typeof rec.treatment_plan === 'object') document.getElementById('treatment_plan').value = JSON.stringify(rec.treatment_plan, null, 2);
                    else document.getElementById('treatment_plan').value = rec.treatment_plan;
                }
            } catch(e){console.warn('prefill treatment', e)}

            // Materials
            try {
                if (rec.materials_used && Array.isArray(rec.materials_used)) {
                    rec.materials_used.forEach(m=>{
                        addMaterialRow(m);
                    });
                }
            } catch(e){console.warn('prefill materials', e)}

            // Doctor notes
            try { if (rec.doctor_notes) document.getElementById('doctor_notes').value = rec.doctor_notes; } catch(e){}

            // Follow-up date
            try { if (rec.follow_up_date) document.getElementById('follow_up_date').value = rec.follow_up_date; } catch(e){}

            // Odontogram: store data and send to iframe if it's loaded; otherwise show load button
            try {
                if (rec.odontogram) {
                    document.getElementById('odontogram_data').value = JSON.stringify(rec.odontogram);
                    const frame = document.getElementById('odontogramFrame');
                    const payload = { type: 'load_odontogram', toothState: rec.odontogram };
                    if (frame && frame.contentWindow && frame.contentWindow.postMessage) {
                        try { frame.contentWindow.postMessage(payload, '*'); } catch(e){}
                    } else {
                        // ensure user can load it manually
                        const loadBtn = document.getElementById('loadOdontogramBtn');
                        if (loadBtn) loadBtn.style.display = 'inline-block';
                    }
                }
            } catch(e){console.warn('prefill odontogram', e)}
        })
        .catch(err=>{ console.warn('Could not prefill clinical record', err); });
}

// Wire queue selector to set appointment and prefill
document.getElementById('queueSelector')?.addEventListener('change', function(){
    const apptId = parseInt(this.value || '0', 10);
    document.getElementById('appointment_id_hidden').value = apptId;
    const label = this.options[this.selectedIndex]?.text || '';
    document.getElementById('apptInfo').textContent = apptId ? label : 'Not selected';
    if (apptId) prefillClinicalRecord(apptId);
});

// On page load, if an appointment was provided via server-side, prefill and select it in dropdown
document.addEventListener('DOMContentLoaded', function(){
    const initialAppt = parseInt(document.getElementById('appointment_id_hidden').value || '0', 10);
    if (initialAppt) {
        const sel = document.getElementById('queueSelector');
        if (sel) {
            for (let i=0;i<sel.options.length;i++){
                if (parseInt(sel.options[i].value||'0',10)===initialAppt) { sel.selectedIndex = i; break; }
            }
        }
        // update display
        const chosenText = sel ? (sel.options[sel.selectedIndex]?.text || initialAppt) : initialAppt;
        document.getElementById('apptInfo').textContent = chosenText;
        prefillClinicalRecord(initialAppt);
    }
});
</script>
