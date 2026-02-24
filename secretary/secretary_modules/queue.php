<?php
include dirname(dirname(__DIR__)) . '/config/database.php';

$today = date('Y-m-d');

$sql = "SELECT q.checkin_id, q.que_number, q.status AS queue_status, q.checkin_time,
               a.appointment_id, a.appointment_date,
               p.first_name AS patient_first, p.last_name AS patient_last,
               u.first_name AS doctor_first, u.last_name AS doctor_last
        FROM check_in_queue q
        JOIN appointments a ON q.appointment_id = a.appointment_id
        LEFT JOIN patients p ON a.patient_id = p.patient_id
        LEFT JOIN users u ON a.user_id = u.user_id
        WHERE DATE(q.checkin_time) = ?
        ORDER BY q.que_number ASC";

$queue = [];
$stmt = $conn->prepare($sql);
if ($stmt) {
    $stmt->bind_param('s', $today);
    $stmt->execute();
    $res = $stmt->get_result();
    if ($res) {
        while ($row = $res->fetch_assoc()) {
            $queue[] = $row;
        }
    }
    $stmt->close();
}
?>

<div class="mb-3">
    <h4>Today's Queue</h4>
    </div>

<div class="card">
    <div class="card-body">
        <div class="table-responsive">
            <table class="table table-striped">
                <thead>
                    <tr>
                        <th>Queue #</th>
                        <th>Patient</th>
                        <th>Doctor</th>
                        <th>Checked In At</th>
                        <th>Status</th>
                    </tr>
                </thead>
                <tbody>
                    <tr id="secretaryQueuePlaceholder" class="text-center text-muted py-4">
                        <td colspan="5">Loading...</td>
                    </tr>
                    <!-- Rows will be populated by JS -->
                </tbody>
            </table>
        </div>
    </div>
</div>
 
<script>
    (function(){
        let badge = null;
        let tbody = null;

        function renderRows(queue){
            if (!tbody) return;
            // clear
            tbody.innerHTML = '';
            if (!queue || queue.length === 0) {
                const tr = document.createElement('tr');
                tr.innerHTML = '<td colspan="5" class="text-center text-muted py-4">No patients in queue for today.</td>';
                tbody.appendChild(tr);
                return;
            }
            queue.forEach(item => {
                const tr = document.createElement('tr');
                const patient = item.patient_name || ((item.patient_first || '') + ' ' + (item.patient_last || '')).trim() || 'Unknown';
                const doctor = item.doctor_name || ((item.doctor_first || '') + ' ' + (item.doctor_last || '')).trim() || 'Unassigned';

                // Map queue status to friendly badge
                const rawStatus = (item.queue_status || '').toString().toLowerCase();
                let statusHtml = '';
                switch (rawStatus) {
                    case 'waiting':
                        statusHtml = '<span class="badge bg-secondary">Waiting</span>';
                        break;
                    case 'called':
                        statusHtml = '<span class="badge bg-primary">Called</span>';
                        break;
                    case 'in_progress':
                    case 'in-progress':
                        statusHtml = '<span class="badge bg-info text-dark">In Progress</span>';
                        break;
                    case 'served':
                    case 'completed':
                        statusHtml = '<span class="badge bg-success">Served</span>';
                        break;
                    case 'cancelled':
                    case 'declined':
                        statusHtml = '<span class="badge bg-danger">Cancelled</span>';
                        break;
                    default:
                        statusHtml = '<span class="badge bg-light text-dark">' + escapeHtml(item.queue_status || '') + '</span>';
                }

                tr.innerHTML = `
                    <td>${parseInt(item.que_number || 0)}</td>
                    <td>${escapeHtml(patient)}</td>
                    <td>${escapeHtml(doctor)}</td>
                    <td>${escapeHtml(item.checkin_time || '')}</td>
                    <td>${statusHtml}</td>
                `;
                tbody.appendChild(tr);
            });
        }

        function escapeHtml(s){
            if (!s) return '';
            return String(s).replace(/[&<>"']/g, function(c){
                return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":"&#39;"}[c];
            });
        }

        async function fetchQueue(){
            try{
                const res = await fetch('../assets/api/queue/get_secretary_queue.php');
                const text = await res.text();
                if (!res.ok) {
                    console.error('Queue fetch failed, status:', res.status, text);
                    showFetchError('Server error: ' + res.status);
                    return;
                }
                let data;
                try {
                    data = JSON.parse(text);
                } catch(jsonErr) {
                    console.error('Invalid JSON from queue API:', jsonErr, text);
                    showFetchError('Invalid server response');
                    return;
                }
                if (data && data.success) {
                    if (badge) badge.textContent = parseInt(data.count || 0);
                    renderRows(data.queue || []);
                } else {
                    console.error('Queue API returned error payload:', data);
                    showFetchError('API error');
                }
            } catch(e) {
                console.error('Error fetching queue (network):', e);
                showFetchError('Network error');
            }
        }

        function showFetchError(msg){
            if (!tbody) return;
            tbody.innerHTML = '<tr><td colspan="5" class="text-center text-danger py-3">' + escapeHtml(msg) + '</td></tr>';
        }

        // Prefer WebSocket for live updates, then SSE, then polling
        function startSecretaryRealtime(){
            // Try WebSocket first
            try {
                if (window.WebSocket) {
                    const ws = new WebSocket('ws://localhost:8080');
                    ws.addEventListener('open', () => console.log('WebSocket connected'));
                    ws.addEventListener('message', (evt) => {
                        try {
                            const msg = JSON.parse(evt.data);
                            if (msg.type === 'queue_update') {
                                const data = msg.data || {};
                                if (badge) badge.textContent = parseInt(data.count || 0);
                                renderRows(data.queue || []);
                            }
                        } catch(err) { console.error('WS parse error', err); }
                    });
                    ws.addEventListener('error', (err) => {
                        console.error('WebSocket error', err);
                        tryStartSSE();
                    });
                    ws.addEventListener('close', () => { console.log('WebSocket closed'); tryStartSSE(); });
                    return;
                }
            } catch(e) { console.error('WebSocket init failed', e); }

            // WebSocket not available or failed — try SSE
            tryStartSSE();
        }

        function tryStartSSE(){
            if (window.EventSource) {
                try {
                    const es = new EventSource('../assets/api/queue/stream_secretary_queue.php');
                    es.onmessage = function(e){
                        try {
                            const data = JSON.parse(e.data);
                            if (badge) badge.textContent = parseInt(data.count || 0);
                            renderRows(data.queue || []);
                        } catch(err) { console.error('SSE parse error', err); }
                    };
                    es.onerror = function(err){ console.error('SSE error', err); es.close(); startPollingFallback(); };
                    return;
                } catch(e) { console.error('EventSource failed', e); }
            }
            // fallback
            startPollingFallback();
        }

        function startPollingFallback(){
            fetchQueue();
            setInterval(fetchQueue, 5000);
        }

        document.addEventListener('DOMContentLoaded', function(){
            badge = document.getElementById('secretaryQueueBadge');
            tbody = document.querySelector('.table.table-striped tbody');
            // fetch once immediately so the table shows a "no patients" message if empty
            fetchQueue();
            startSecretaryRealtime();
        });
    })();
</script>
