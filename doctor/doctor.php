<?php
include_once '../config/session_check.php';
check_session(['doctor']);

// Get today's queue count for this doctor (for sidebar badge)
include_once '../config/database.php';
$doctor_queue_count = 0;
$doctor_id = $_SESSION['user_id'] ?? 0;
if ($doctor_id && $conn) {
    $today_for_count = date('Y-m-d');
    $qc_stmt = $conn->prepare("SELECT COUNT(*) AS c FROM check_in_queue q INNER JOIN appointments a ON q.appointment_id = a.appointment_id WHERE a.user_id = ? AND a.appointment_date = ? AND q.status IN ('waiting','called')");
    if ($qc_stmt) {
        $qc_stmt->bind_param('is', $doctor_id, $today_for_count);
        $qc_stmt->execute();
        $qc_res = $qc_stmt->get_result();
        if ($qc_res && ($qc_row = $qc_res->fetch_assoc())) {
            $doctor_queue_count = (int) ($qc_row['c'] ?? 0);
        }
        $qc_stmt->close();
    }
}

// Handle page logic for modules that need to send headers (before any output)
$page = isset($_GET['page']) && $_GET['page'] !== '' ? $_GET['page'] : 'dashboard';

// Process appointments module logic before HTML output
if ($page === 'appointments') {
    // Only run the PHP logic that might send headers
    $action = $_GET['action'] ?? '';
    if ($action && $_SERVER['REQUEST_METHOD'] === 'POST') {
        include 'doctor_modules/appointments.php';
        exit;
    }
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="utf-8">
    <meta content="width=device-width, initial-scale=1.0" name="viewport">
    <title>Azucena's Dental Clinic</title>
    <meta content="" name="description">
    <meta content="" name="keywords">

    <!-- Favicons -->
    <link href="../assets/img/main_logo.png" rel="icon">
    <link href="../assets/img/apple-touch-icon.png" rel="apple-touch-icon">

    <!-- Google Fonts removed to avoid cross-origin stylesheet issues -->

    <!-- Vendor CSS Files -->
    <link href="../assets/vendor/bootstrap/css/bootstrap.min.css" rel="stylesheet">
    <link href="../assets/vendor/bootstrap-icons/bootstrap-icons.css" rel="stylesheet">
    <link href="../assets/vendor/boxicons/css/boxicons.min.css" rel="stylesheet">
    <link href="../assets/vendor/quill/quill.snow.css" rel="stylesheet">
    <link href="../assets/vendor/quill/quill.bubble.css" rel="stylesheet">
    <link href="../assets/vendor/remixicon/remixicon.css" rel="stylesheet">
    <link href="../assets/vendor/simple-datatables/style.css" rel="stylesheet">

    <!-- Template Main CSS File -->
    <link href="../assets/css/main.css" rel="stylesheet">
</head>

<body>

    <!-- ======= Header ======= -->
    <header id="header" class="header fixed-top d-flex align-items-center">
        <div class="d-flex align-items-center">
            <a href="doctor.php?page=dashboard" class="logo d-flex align-items-center">
                <img src="../assets/img/main_logo.png" alt="">
                <div class="ms-2">
                    <span class="d-none d-lg-block fw-bold">Dental Clinic</span>
                    <small class="d-none d-lg-block text-muted">Doctor</small>
                </div>
            </a>
            <button class="toggle-sidebar-btn ms-2" type="button" aria-label="Toggle sidebar">
                <i class="bi bi-list"></i>
            </button>
        </div>

        <div class="header-search">
            <input type="text" placeholder="Search..." class="search-input" id="doctorSearch" name="search"
                aria-label="Search">
            <button class="search-btn">Search</button>
        </div>

        <nav class="header-nav ms-auto">
            <ul class="d-flex align-items-center">
                <li class="nav-item d-block d-lg-none">
                    <a class="nav-link nav-icon search-bar-toggle" href="#">
                        <i class="bi bi-search"></i>
                    </a>
                </li>



                <li class="nav-item dropdown pe-2">
                    <a class="nav-link nav-icon" href="#" data-bs-toggle="dropdown" aria-expanded="false">
                        <i class="bi bi-bell" style="font-size:1.1rem;"></i>
                        <span class="badge bg-danger" id="navNotificationBadge"
                            style="font-size:0.65rem;position:relative;top:-10px;left:-8px;">0</span>
                    </a>
                    <ul class="dropdown-menu dropdown-menu-end" id="navNotificationList" style="min-width:280px;">
                        <li class="dropdown-header">Notifications</li>
                        <li>
                            <hr class="dropdown-divider">
                        </li>
                        <li class="text-center small text-muted">Loading...</li>
                    </ul>
                </li>

                <li class="nav-item dropdown pe-3">
                    <a class="nav-link nav-profile d-flex align-items-center pe-0" href="#" data-bs-toggle="dropdown">
                        <img src="../assets/img/profile.png" alt="Profile" class="rounded-circle">
                    </a>
                    <ul class="dropdown-menu dropdown-menu-end dropdown-menu-arrow profile">

                        <li>
                            <a class="dropdown-item d-flex align-items-center" href="doctor.php?page=profile">

                                <span>My Profile</span>
                            </a>
                        </li>
                        <li>
                            <hr class="dropdown-divider">
                        </li>
                        <li>
                            <a class="dropdown-item d-flex align-items-center" href="doctor.php?page=settings">

                                <span>Account Settings</span>
                            </a>
                        </li>
                        <li>
                            <hr class="dropdown-divider">
                        </li>
                        <li>
                            <a class="dropdown-item d-flex align-items-center" href="#" onclick="logout()">

                                <span>Log Out</span>
                            </a>
                        </li>
                    </ul>
                </li>
            </ul>
        </nav>
    </header>

    <!-- ======= Sidebar ======= -->
    <aside id="sidebar" class="sidebar">
        <ul class="sidebar-nav" id="sidebar-nav">
            <li class="nav-item">
                <a class="nav-link collapsed" href="doctor.php?page=dashboard">
                    <i class="bi bi-grid"></i>
                    <span>Dashboard</span>
                </a>
            </li>

            <li class="nav-item">
                <a class="nav-link collapsed" href="doctor.php?page=appointments">
                    <i class="bi bi-calendar2-week"></i>
                    <span>Appointments</span>
                    <span id="doctorApptIndicator" class="badge bg-info ms-auto"
                        style="margin-left:8px;display:none;font-size:0.75rem;">New</span>
                </a>
            </li>
            <li class="nav-item">
                <a class="nav-link collapsed" href="doctor.php?page=patient_schedule">
                    <i class="bi bi-calendar-range"></i>
                    <span>Scheduling</span>
                </a>
            </li>
            <li class="nav-item">
                <a class="nav-link collapsed" href="doctor.php?page=patients">
                    <i class="bi bi-person-lines-fill"></i>
                    <span>Patients</span>
                </a>
            </li>
            <li class="nav-item">
                <a class="nav-link collapsed" href="doctor.php?page=queue">
                    <i class="bi bi-people-fill"></i>
                    <span>Queue</span>
                    <span id="doctorQueueBadge" class="badge bg-danger ms-auto"
                        style="margin-left:8px;"><?php echo (int) $doctor_queue_count; ?></span>
                </a>
            </li>

            <li class="nav-item">
                <a class="nav-link collapsed" href="doctor.php?page=ehr">
                    <i class="bi bi-journal-medical"></i>
                    <span>Profiling</span>
                </a>
            </li>
            <!-- Profiling link removed from sidebar (access via direct URL remains) -->

            <li class="nav-item">
                <a class="nav-link collapsed" href="doctor.php?page=records">
                    <i class="bi bi-folder2-open"></i>
                    <span>Clinical Records</span>
                </a>
            </li>


        </ul>
    </aside>

    <main id="main" class="main">
        <div class="pagetitle">
            <h1></h1>
            <nav>
                <ol class="breadcrumb"></ol>
            </nav>
        </div>

        <section class="section">
            <div class="row">
                <div class="col-lg-12">
                    <div class="card">
                        <div class="card-body">
                            <!-- Dynamic Content Area -->
                            <?php
                            $page = isset($_GET['page']) && $_GET['page'] !== '' ? $_GET['page'] : 'dashboard';

                            switch ($page) {
                                case "dashboard":
                                    include "doctor_modules/dashboard.php";
                                    break;

                                case "appointments":
                                    include "doctor_modules/appointments.php";
                                    break;

                                case "queue":
                                    include "doctor_modules/queue.php";
                                    break;

                                case "ehr":
                                    include "doctor_modules/ehr.php";
                                    break;

                                case "profiling":
                                    include "doctor_modules/profiling.php";
                                    break;

                                case "records":
                                    include "doctor_modules/records.php";
                                    break;
                                case "patients":
                                    include "doctor_modules/patients.php";
                                    break;
                                case "patient_schedule":
                                    include "doctor_modules/patient_schedule.php";
                                    break;

                                default:
                                    include "doctor_modules/dashboard.php";
                                    break;
                            }
                            ?>
                        </div>
                    </div>
                </div>
            </div>
        </section>
    </main>

    <!-- Footer -->
    <footer class="footer py-3 bg-light">
        <div class="container d-flex justify-content-center">
            <span class="text-muted">Azucena's Dental Clinic &reg; 2026</span>
        </div>
    </footer>

    <a href="#" class="back-to-top d-flex align-items-center justify-content-center">
        <i class="bi bi-arrow-up-short"></i>
    </a>

    <!-- Vendor JS Files -->
    <script src="../assets/vendor/apexcharts/apexcharts.min.js"></script>
    <script src="../assets/vendor/bootstrap/js/bootstrap.bundle.min.js"></script>
    <script src="../assets/vendor/chart.js/chart.umd.js"></script>
    <script src="../assets/vendor/echarts/echarts.min.js"></script>
    <script src="../assets/vendor/quill/quill.min.js"></script>
    <script src="../assets/vendor/simple-datatables/simple-datatables.js"></script>
    <script src="../assets/vendor/tinymce/tinymce.min.js"></script>
    <script src="../assets/vendor/php-email-form/validate.js"></script>

    <!-- Template Main JS File -->
    <script src="../assets/js/main.js"></script>

    <!-- Active Navigation Highlight -->
    <script>
        document.addEventListener('DOMContentLoaded', function () {
            // Get current page from URL
            const params = new URLSearchParams(window.location.search);
            const currentPage = params.get('page') || 'dashboard';

            // Get all sidebar nav links
            const navLinks = document.querySelectorAll('.sidebar-nav .nav-link');

            // Remove active class from all links and add to current page
            navLinks.forEach(link => {
                link.classList.remove('active');

                // Check if this link matches current page
                if (link.href.includes('page=' + currentPage)) {
                    link.classList.add('active');
                    link.classList.remove('collapsed');
                }
            });
        });

        // Logout function
        function logout() {
            fetch('../assets/api/auth/logout_api.php', {
                method: 'POST'
            }).then(response => response.json())
                .then(data => {
                    window.location.href = '../index.php';
                }).catch(error => {
                    console.error('Logout error:', error);
                    window.location.href = '../index.php';
                });
        }
    </script>

    <script>
        // Poll doctor queue every 5 seconds and update badge and queue list
        async function fetchDoctorQueue() {
            try {
                const resp = await fetch('../assets/api/queue/get_doctor_queue.php', { cache: 'no-store' });
                if (!resp.ok) return;
                const data = await resp.json();
                if (!data.success) return;

                // Update badge
                const badge = document.getElementById('doctorQueueBadge');
                if (badge) badge.textContent = data.count || 0;

                // If on appointments page, update queue list
                const params = new URLSearchParams(window.location.search);
                const currentPage = params.get('page') || 'dashboard';
                if (currentPage === 'queue') {
                    const container = document.getElementById('doctorQueueList');
                    if (container) {
                        // build inner HTML
                        let html = '<div class="list-group">';
                        data.queue.forEach(p => {
                            const fullName = (p.first_name || '') + ' ' + (p.last_name || '');
                            html += '<div class="list-group-item" data-appointment-id="' + (p.appointment_id || '') + '">';
                            html += '<div class="d-flex justify-content-between align-items-start">';
                            html += '<div>';
                            html += '<h5 class="mb-1"><span class="badge bg-warning">Queue #' + (p.que_number || '') + '</span> ' + fullName + '</h5>';
                            html += '<p class="mb-1 small text-muted"><i class="bi bi-telephone"></i> ' + (p.phone_number || '') + '</p>';
                            html += '</div>';
                            html += '<div class="btn-group-vertical ms-2">';
                            html += '<button type="button" class="btn btn-sm btn-danger mb-1" onclick="declinePatientAjax(' + (p.appointment_id || '') + ')">Decline</button>';
                            html += '<button type="button" class="btn btn-sm btn-success" onclick="acceptPatientAjax(' + (p.appointment_id || '') + ')">Accept</button>';
                            html += '</div></div></div>';
                        });
                        html += '</div>';
                        container.innerHTML = html;
                    }
                }
            } catch (err) {
                console.error('Queue poll error', err);
            }
        }

        // Start realtime updates: WebSocket temporarily disabled — use SSE then polling
        const currentDoctorId = <?php echo (int) $doctor_id; ?>;
        function startDoctorRealtime() {
            // Original WebSocket code commented out for temporary disablement:
            /*
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
                                const badge = document.getElementById('doctorQueueBadge');
                                const apptInd = document.getElementById('doctorApptIndicator');
                                if (badge) badge.textContent = data.count || 0;
                                if (apptInd) {
                                    if ((data.count || 0) > 0) {
                                        apptInd.style.display = 'inline-block';
                                        apptInd.classList.add('blink');
                                        setTimeout(() => apptInd.classList.remove('blink'), 2500);
                                    } else {
                                        apptInd.style.display = 'none';
                                    }
                                }
                                const params = new URLSearchParams(window.location.search);
                                const currentPage = params.get('page') || 'dashboard';
                                if (currentPage === 'queue') {
                                    const container = document.getElementById('doctorQueueList');
                                    if (container) {
                                        let html = '<div class="list-group">';
                                        (data.queue || []).forEach(p => {
                                            const fullName = (p.first_name || '') + ' ' + (p.last_name || '');
                                            const ownerId = parseInt(p.owner_id || 0, 10);
                                            const ownerName = ((p.owner_first || '') + ' ' + (p.owner_last || '')).trim();
                                            const isMine = (ownerId === currentDoctorId);
                                            html += '<div class="list-group-item" data-appointment-id="' + (p.appointment_id || '') + '">';
                                            html += '<div class="d-flex justify-content-between align-items-start">';
                                            html += '<div>';
                                            html += '<h5 class="mb-1"><span class="badge bg-warning">Queue #' + (p.que_number || '') + '</span> ' + fullName + '</h5>';
                                            html += '<p class="mb-1 small text-muted"><i class="bi bi-telephone"></i> ' + (p.phone_number || '') + '</p>';
                                            if (ownerName && !isMine) html += '<p class="mb-1 small text-muted">Assigned to: ' + ownerName + '</p>';
                                            html += '</div>';
                                            html += '<div class="btn-group-vertical ms-2">';
                                            const patientJson = encodeURIComponent(JSON.stringify(p));
                                            html += '<button type="button" class="btn btn-sm btn-primary mb-1" onclick="loadPatientEHR(JSON.parse(decodeURIComponent(\\'' + patientJson + '\\')))"> <i class="bi bi-file-earmark-medical"></i> See Patient</button>';
                                            if (isMine && p.queue_status === 'waiting') {
                                                html += '<button type="button" class="btn btn-sm btn-warning mb-1" onclick="callPatientAjax(' + (p.appointment_id || '') + ')">Call</button>';
                                            }
                                            if (!isMine) {
                                                html += '<button type="button" class="btn btn-sm btn-secondary mb-1" onclick="claimPatientAjax(' + (p.appointment_id || '') + ')">Claim</button>';
                                            }
                                            html += '<button type="button" class="btn btn-sm btn-success" onclick="acceptPatientAjax(' + (p.appointment_id || '') + ')">Accept</button>';
                                            html += '</div></div></div>';
                                        });
                                        html += '</div>';
                                        container.innerHTML = html;
                                    }
                                }
                            }
                        } catch (err) { console.error('WS parse error', err); }
                    });
                    ws.addEventListener('error', (err) => {
                        console.error('WebSocket error', err);
                        // fallback to SSE
                        tryStartSSE();
                    });
                    ws.addEventListener('close', () => { console.log('WebSocket closed'); tryStartSSE(); });
                    return;
                }
            } catch (e) { console.error('WebSocket init failed', e); }

            // WebSocket not available or failed — try SSE
            tryStartSSE();
            */

            // Fall back to SSE/polling
            tryStartSSE();
        }

        function tryStartSSE() {
            if (window.EventSource) {
                try {
                    const es = new EventSource('../assets/api/queue/stream_doctor_queue.php');
                    es.onmessage = function (e) {
                        try {
                            const data = JSON.parse(e.data);
                            const badge = document.getElementById('doctorQueueBadge');
                            const apptInd = document.getElementById('doctorApptIndicator');
                            if (badge) badge.textContent = data.count || 0;
                            if (apptInd) {
                                if ((data.count || 0) > 0) {
                                    apptInd.style.display = 'inline-block';
                                    apptInd.classList.add('blink');
                                    setTimeout(() => apptInd.classList.remove('blink'), 2500);
                                } else {
                                    apptInd.style.display = 'none';
                                }
                            }
                            const params = new URLSearchParams(window.location.search);
                            const currentPage = params.get('page') || 'dashboard';
                            if (currentPage === 'queue') {
                                const container = document.getElementById('doctorQueueList');
                                if (container) {
                                    let html = '<div class="list-group">';
                                    (data.queue || []).forEach(p => {
                                        const fullName = (p.first_name || '') + ' ' + (p.last_name || '');
                                        const ownerId = parseInt(p.owner_id || 0, 10);
                                        const ownerName = ((p.owner_first || '') + ' ' + (p.owner_last || '')).trim();
                                        const isMine = (ownerId === currentDoctorId);
                                        html += '<div class="list-group-item" data-appointment-id="' + (p.appointment_id || '') + '">';
                                        html += '<div class="d-flex justify-content-between align-items-start">';
                                        html += '<div>';
                                        html += '<h5 class="mb-1"><span class="badge bg-warning">Queue #' + (p.que_number || '') + '</span> ' + fullName + '</h5>';
                                        html += '<p class="mb-1 small text-muted"><i class="bi bi-telephone"></i> ' + (p.phone_number || '') + '</p>';
                                        if (ownerName && !isMine) html += '<p class="mb-1 small text-muted">Assigned to: ' + ownerName + '</p>';
                                        html += '</div>';
                                        html += '<div class="btn-group-vertical ms-2">';
                                        const patientJson = encodeURIComponent(JSON.stringify(p));
                                        html += '<button type="button" class="btn btn-sm btn-primary mb-1" onclick="loadPatientEHR(JSON.parse(decodeURIComponent(\\'' + patientJson + '\\')))"> <i class="bi bi-file-earmark-medical"></i> See Patient</button>';
                                        if (isMine && p.queue_status === 'waiting') {
                                            html += '<button type="button" class="btn btn-sm btn-warning mb-1" onclick="callPatientAjax(' + (p.appointment_id || '') + ')">Call</button>';
                                        }
                                        if (!isMine) {
                                            html += '<button type="button" class="btn btn-sm btn-secondary mb-1" onclick="claimPatientAjax(' + (p.appointment_id || '') + ')">Claim</button>';
                                        }
                                        html += '<button type="button" class="btn btn-sm btn-success" onclick="acceptPatientAjax(' + (p.appointment_id || '') + ')">Accept</button>';
                                        html += '</div></div></div>';
                                    });
                                    html += '</div>';
                                    container.innerHTML = html;
                                }
                            }
                        } catch (err) { console.error('SSE parse error', err); }
                    };
                    es.onerror = function (err) { console.error('SSE error', err); es.close(); startPollingFallback(); };
                    return;
                } catch (e) {
                    console.error('EventSource failed', e);
                }
            }
            // fallback to polling
            startPollingFallback();
        }

        function startPollingFallback() {
            fetchDoctorQueue();
            setInterval(fetchDoctorQueue, 5000);
        }

        // add simple blink CSS
        (function addBlinkCss() {
            const s = document.createElement('style');
            s.innerHTML = '.blink{animation:blink-ani 0.6s linear 4;} @keyframes blink-ani{50%{opacity:0.2}}';
            document.head.appendChild(s);
        })();

        // initial
        document.addEventListener('DOMContentLoaded', startDoctorRealtime);
    </script>

    <script>
        // AJAX call to mark patient as called
        async function callPatientAjax(appointmentId) {
            try {
                const fd = new FormData();
                fd.append('appointment_id', appointmentId);
                const resp = await fetch('../assets/api/queue/call.php', { method: 'POST', body: fd });
                const j = await resp.json();
                if (j.success) {
                    // update badge count immediately
                    const badge = document.getElementById('doctorQueueBadge');
                    if (badge) {
                        const n = parseInt(badge.textContent || '0', 10) || 0;
                        badge.textContent = Math.max(0, n);
                    }
                    // update list item status to 'called'
                    const item = document.querySelector('[data-appointment-id="' + appointmentId + '"]');
                    if (item) {
                        const btn = item.querySelector('.btn-warning');
                        if (btn) {
                            btn.textContent = 'Called';
                            btn.disabled = true;
                        }
                    }
                    showToast('Patient called', 'success');
                } else {
                    showToast('Call failed: ' + (j.message || 'unknown'), 'danger');
                }
            } catch (err) {
                console.error(err);
                showToast('Call error', 'danger');
            }
        }

        // AJAX call to claim a patient (assign appointment to this doctor)
        async function claimPatientAjax(appointmentId) {
            try {
                const fd = new FormData();
                fd.append('appointment_id', appointmentId);
                const resp = await fetch('../assets/api/queue/claim.php', { method: 'POST', body: fd });
                const j = await resp.json();
                if (j.success) {
                    showToast('Appointment claimed', 'success');
                    // refresh queue list (will also be updated via WS)
                    fetchDoctorQueue();
                } else {
                    showToast('Claim failed: ' + (j.message || 'unknown'), 'danger');
                }
            } catch (err) {
                console.error(err);
                showToast('Claim error', 'danger');
            }
        }

        // AJAX call to accept patient (start visit)
        async function acceptPatientAjax(appointmentId) {
            if (!confirm('Start visit for this patient?')) return;
            try {
                const fd = new FormData();
                fd.append('appointment_id', appointmentId);
                const resp = await fetch('../assets/api/queue/accept.php', { method: 'POST', body: fd });
                const j = await resp.json();
                if (j.success) {
                    // redirect to EHR module to complete the clinical record
                    var url = 'doctor.php?page=ehr&appointment_id=' + appointmentId;
                    if (j.schedule_id) url += '&schedule_id=' + j.schedule_id;
                    window.location.href = url;
                } else {
                    showToast('Accept failed: ' + (j.message || 'unknown'), 'danger');
                }
            } catch (err) {
                console.error(err);
                showToast('Accept error', 'danger');
            }
        }
    </script>

    <script>
        // quick bootstrap-like toast using alert banners
        function showToast(message, type) {
            // type: success, danger, info, warning
            const containerId = 'toastContainer';
            let container = document.getElementById(containerId);
            if (!container) {
                container = document.createElement('div');
                container.id = containerId;
                container.style.position = 'fixed';
                container.style.top = '16px';
                container.style.right = '16px';
                container.style.zIndex = 1060;
                document.body.appendChild(container);
            }
            const el = document.createElement('div');
            el.className = 'alert alert-' + (type || 'info') + ' alert-dismissible';
            el.role = 'alert';
            el.style.minWidth = '200px';
            el.innerHTML = message + '<button type="button" class="btn-close" aria-label="Close" style="float:right;"></button>';
            container.appendChild(el);
            const closeBtn = el.querySelector('.btn-close');
            closeBtn.addEventListener('click', () => el.remove());
            setTimeout(() => { try { el.remove(); } catch (e) { } }, 4000);
        }
    </script>

</body>

</html>