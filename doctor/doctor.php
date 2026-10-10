<?php
require_once __DIR__ . '/../config/asset_helpers.php';
include_once '../config/session_check.php';
check_session(['doctor']);
$csrf_token = security_generate_csrf_token();
include_once '../config/system_logs.php';

// Get today's arrived (pending) patient count for this doctor (for sidebar badge)
include_once '../config/database.php';
include_once '../config/profile_helpers.php';
$doctor_today_count = 0;
$doctor_id = $_SESSION['user_id'] ?? 0;
if ($doctor_id && $conn) {
    $today_for_count = date('Y-m-d');
    $qc_stmt = $conn->prepare(
        "SELECT COUNT(*) AS c FROM appointments a
                 LEFT JOIN timeslot t ON t.timeslot_id = a.timeslot_id
                 WHERE COALESCE(a.user_id, t.doctor_id) = ?
                     AND COALESCE(t.slot_date, a.appointment_date) = ?
                     AND a.status IN ('confirmed','arrived','checked_in','in_progress')"
    );
    if ($qc_stmt) {
        $qc_stmt->bind_param('is', $doctor_id, $today_for_count);
        $qc_stmt->execute();
        $qc_res = $qc_stmt->get_result();
        if ($qc_res && ($qc_row = $qc_res->fetch_assoc())) {
            $doctor_today_count = (int)($qc_row['c'] ?? 0);
        }
        $qc_stmt->close();
    }
}

// Handle page logic for modules that need to send headers (before any output)
$page = isset($_GET['page']) && $_GET['page'] !== '' ? $_GET['page'] : 'dashboard';
$dashboardLayout = ($page === 'dashboard');
$action = $_GET['action'] ?? ($_POST['action'] ?? '');

if (function_exists('log_system_event') && $_SERVER['REQUEST_METHOD'] === 'POST' && $action !== '') {
    $normalizedAction = preg_replace('/[^a-z0-9_\.-]/i', '_', strtolower((string)$action));
    $entityType = preg_replace('/[^a-z0-9_\.-]/i', '_', strtolower((string)$page));
    $isViewOnlyAction = in_array($normalizedAction, ['view', 'open', 'show', 'list', 'read', 'index'], true) || str_starts_with($normalizedAction, 'view_');
    if ($normalizedAction !== '' && !$isViewOnlyAction) {
        log_system_event(
            $conn,
            'doctor.change.' . $normalizedAction,
            $entityType !== '' ? $entityType : 'doctor_module',
            null,
            'Doctor data change action executed: page=' . $page . ', action=' . $action,
            (int)($_SESSION['user_id'] ?? 0)
        );
    }
}

// Process appointments module logic before HTML output
if ($page === 'appointments') {
    // Only run the PHP logic that might send headers
    if ($action && $_SERVER['REQUEST_METHOD'] === 'POST') {
        include 'doctor_modules/appointments.php';
        exit;
    }
}

$header_display_name = trim(((string)($_SESSION['first_name'] ?? '')) . ' ' . ((string)($_SESSION['last_name'] ?? '')));
if ($header_display_name === '') {
    $header_display_name = (string)($_SESSION['email'] ?? 'User');
}

$role_key = strtolower((string)($_SESSION['role'] ?? ''));
switch ($role_key) {
    case 'admin':
        $header_role_label = 'Administrator';
        break;
    case 'doctor':
        $header_role_label = 'Doctor';
        break;
    case 'secretary':
        $header_role_label = 'Secretary';
        break;
    case 'patient':
        $header_role_label = 'Patient';
        break;
    default:
        $header_role_label = 'User';
        break;
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
    <link href="../assets/img/clinic-logo.png" rel="icon">
    <link href="../assets/img/apple-touch-icon.png" rel="apple-touch-icon">

    <!-- Google Fonts -->
    <link href="https://fonts.gstatic.com" rel="preconnect">
    <link
        href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap"
        rel="stylesheet">

    <!-- Vendor CSS Files -->
    <link href="<?= asset_url('../assets/vendor/bootstrap/css/bootstrap.min.css') ?>" rel="stylesheet">
    <link href="<?= asset_url('../assets/vendor/bootstrap-icons/bootstrap-icons.css') ?>" rel="stylesheet">
    <link href="<?= asset_url('../assets/vendor/boxicons/css/boxicons.min.css') ?>" rel="stylesheet">
    <link href="<?= asset_url('../assets/vendor/quill/quill.snow.css') ?>" rel="stylesheet">
    <link href="<?= asset_url('../assets/vendor/quill/quill.bubble.css') ?>" rel="stylesheet">
    <link href="<?= asset_url('../assets/vendor/remixicon/remixicon.css') ?>" rel="stylesheet">
    <link href="<?= asset_url('../assets/vendor/simple-datatables/style.css') ?>" rel="stylesheet">

    <!-- Template Main CSS File -->
    <link href="<?= asset_url('../assets/css/main.css') ?>" rel="stylesheet">
    <link href="<?= asset_url('../assets/css/overlay-scrollbars.css') ?>" rel="stylesheet">
    <script src="<?= asset_url('../assets/js/overlay-scrollbars.js') ?>" defer></script>
    <link href="<?= asset_url('../assets/css/app-toast.css') ?>" rel="stylesheet">
    <link href="<?= asset_url('../assets/css/portal-header-mobile.css') ?>" rel="stylesheet">
    <link href="<?= asset_url('../assets/css/portal-nav.css') ?>" rel="stylesheet">
    <link href="<?= asset_url('../assets/css/module-content-ux.css') ?>" rel="stylesheet">
    <link href="<?= asset_url('../assets/css/doctor-patients.css') ?>" rel="stylesheet">
    <link href="<?= asset_url('../assets/css/dental-calendar.css') ?>" rel="stylesheet">
    <link href="<?= asset_url('../assets/css/appointment-services.css') ?>" rel="stylesheet">
    <link href="<?= asset_url('../assets/css/odontogram-rotate-prompt.css') ?>" rel="stylesheet">
</head>

<body class="doctor-portal">
    <script>
    (function () {
        try {
            if (window.innerWidth > 991 && localStorage.getItem('sidebar-collapsed') === 'true') {
                document.body.classList.add('toggle-sidebar');
            }
        } catch (e) {}
    })();
    </script>
    <!-- Loaded early (before EHR/records module content further down) since
         those modules call window.OdontogramFullscreen synchronously at
         parse time — a footer-loaded script would be too late. -->
    <script src="<?= asset_url('../assets/js/odontogram-fullscreen.js') ?>"></script>

    <!-- Global Loading Overlay -->
    <div id="globalLoadingOverlay" style="position:fixed;inset:0;z-index:99999;display:flex;align-items:center;justify-content:center;pointer-events:none;">
        <div style="width:36px;height:36px;border:3px solid #e2e8f0;border-top-color:#1d6fd8;border-radius:50%;animation:spin 0.8s linear infinite;"></div>
    </div>
    <style>@keyframes spin{to{transform:rotate(360deg)}}</style>

    <!-- ======= Header ======= -->
    <header id="header" class="header fixed-top d-flex align-items-center">
        <div class="d-flex align-items-center">
            <button class="mobile-sidebar-toggle d-lg-none" type="button" aria-label="Toggle sidebar">
                <i class="bi bi-list toggle-icon-open"></i>
                <i class="bi bi-x-lg toggle-icon-closed"></i>
            </button>
            <a href="doctor.php?page=dashboard" class="logo d-flex align-items-center">
                <img src="../assets/img/clinic-logo.png" alt="">
            </a>
        </div>

        <div class="header-actions ms-auto d-flex align-items-center flex-shrink-0">
        <?php
        $header_search_id = 'doctorSearch';
        $header_search_placeholder = 'Search pages...';
        include dirname(__DIR__) . '/includes/header_search.php';
        ?>

        <nav class="header-nav">
            <ul class="d-flex align-items-center">

                <?php include dirname(__DIR__) . '/includes/header_clock.php'; ?>

                <?php
                $notifications_portal_url = 'doctor.php?page=notifications';
                include dirname(__DIR__) . '/includes/notification_dropdown.php';
                ?>

                <?php
                $profile_portal_base = 'doctor.php';
                include dirname(__DIR__) . '/includes/profile_dropdown.php';
                ?>
            </ul>
        </nav>
        </div>
    </header>

    <!-- ======= Sidebar ======= -->
    <aside id="sidebar" class="sidebar">
        <div class="sidebar-toggle-area">
            <button class="toggle-sidebar-btn" type="button" aria-label="Toggle sidebar">
                <i class="bi bi-chevron-double-left toggle-icon-open"></i>
                <i class="bi bi-chevron-double-right toggle-icon-closed"></i>
            </button>
        </div>
        <ul class="sidebar-nav" id="sidebar-nav">
            <li class="nav-item">
                <a class="nav-link collapsed" href="doctor.php?page=dashboard" data-tooltip="Dashboard" title="Dashboard">
                    <i class="bi bi-grid"></i>
                    <span>Dashboard</span>
                </a>
            </li>

            <li class="nav-item">
                <a class="nav-link collapsed" href="doctor.php?page=appointments" data-tooltip="Appointments" title="Appointments">
                    <i class="bi bi-calendar2-week"></i>
                    <span>Appointments</span>
                    <span class="nav-dot" id="doctorApptDot"></span>
                </a>
            </li>
            <li class="nav-item">
                <a class="nav-link collapsed" href="doctor.php?page=schedule" data-tooltip="Schedule" title="Schedule">
                    <i class="bi bi-calendar3"></i>
                    <span>Schedule</span>
                </a>
            </li>
            <li class="nav-item">
                <a class="nav-link collapsed" href="doctor.php?page=patients" data-tooltip="Patients" title="Patients">
                    <i class="bi bi-person-lines-fill"></i>
                    <span>Patients</span>
                </a>
            </li>
            <li class="nav-item">
                <a class="nav-link collapsed" href="doctor.php?page=arrivals" data-tooltip="My Patients Today" title="My Patients Today">
                    <i class="bi bi-person-check"></i>
                    <span>My Patients Today</span>
                    <span class="nav-dot<?php echo (int)$doctor_today_count > 0 ? ' is-active' : ''; ?>" id="doctorArrivalsDot"></span>
                </a>
            </li>

            <li class="nav-item">
                <a class="nav-link collapsed" href="doctor.php?page=ehr" data-tooltip="Patient Visit" title="Patient Visit">
                    <i class="bi bi-journal-medical"></i>
                    <span>Patient Visit</span>
                </a>
            </li>
            <!-- Session link removed from sidebar (access via direct URL remains) -->

            <li class="nav-item">
                <a class="nav-link collapsed" href="doctor.php?page=records" data-tooltip="Clinical Records" title="Clinical Records">
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
                    <?php if (!$dashboardLayout): ?><div class="card"><div class="card-body"><?php endif; ?>
                            <!-- Dynamic Content Area -->
                            <?php
                            switch ($page) {
                                case "dashboard":
                                    include "doctor_modules/dashboard.php";
                                    break;

                                case "appointments":
                                    include "doctor_modules/appointments.php";
                                    break;

                                case "arrivals":
                                    include "doctor_modules/arrivals.php";
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
                                case "notifications":
                                    include "doctor_modules/notifications.php";
                                    break;
                                case "patients":
                                    include "doctor_modules/patients.php";
                                    break;
                                case "patient_schedule":
                                    $_GET['tab'] = 'followup';
                                    include "doctor_modules/appointments.php";
                                    break;
                                case "leave_requests":
                                case "schedule":
                                    include "doctor_modules/leave_requests.php";
                                    break;
                                case "profile":
                                    include "doctor_modules/profile.php";
                                    break;
                                case "settings":
                                    include "doctor_modules/settings.php";
                                    break;

                                default:
                                    include "doctor_modules/dashboard.php";
                                    break;
                            }
                            ?>
                    <?php if (!$dashboardLayout): ?></div></div><?php endif; ?>
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
    <script src="<?= asset_url('../assets/vendor/apexcharts/apexcharts.min.js') ?>"></script>
    <script src="<?= asset_url('../assets/vendor/bootstrap/js/bootstrap.bundle.min.js') ?>"></script>
    <script src="<?= asset_url('../assets/vendor/chart.js/chart.umd.js') ?>"></script>
    <script src="<?= asset_url('../assets/vendor/echarts/echarts.min.js') ?>"></script>
    <script src="<?= asset_url('../assets/vendor/quill/quill.min.js') ?>"></script>
    <script src="<?= asset_url('../assets/vendor/simple-datatables/simple-datatables.js') ?>"></script>
    <script src="<?= asset_url('../assets/vendor/tinymce/tinymce.min.js') ?>"></script>
    <script src="<?= asset_url('../assets/vendor/php-email-form/validate.js') ?>"></script>

    <!-- Template Main JS File -->
    <script src="<?= asset_url('../assets/js/idempotency.js') ?>"></script>
    <script src="<?= asset_url('../assets/js/app-toast.js') ?>"></script>
    <script src="<?= asset_url('../assets/js/dental-cache.js') ?>"></script>
    <script src="<?= asset_url('../assets/js/dental-ui.js') ?>"></script>
    <script src="<?= asset_url('../assets/js/app-request.js') ?>"></script>
    <script src="<?= asset_url('../assets/js/app-confirm.js') ?>"></script>
    <script src="<?= asset_url('../assets/js/password-toggle.js') ?>"></script>
    <script src="<?= asset_url('../assets/js/dental-calendar.js') ?>"></script>
    <script src="<?= asset_url('../assets/js/page-skeleton.js') ?>"></script>
    <script src="<?= asset_url('../assets/js/main.js') ?>"></script>
    <script src="<?= asset_url('../assets/js/treatment-plan.js') ?>"></script>
    <script src="<?= asset_url('../assets/js/portal-dropdown.js') ?>"></script>
    <script src="<?= asset_url('../assets/js/portal-search.js') ?>"></script>
    <script src="<?= asset_url('../assets/js/profiling-display.js') ?>"></script>
    <script src="<?= asset_url('../assets/js/doctor-patient-profile.js') ?>"></script>
    <script src="<?= asset_url('../assets/js/clinical-assessment-fields.js') ?>"></script>
    <script src="<?= asset_url('../assets/js/media-gallery.js') ?>"></script>
    <script src="<?= asset_url('../assets/js/doctor-patients.js') ?>"></script>
    <script src="<?= asset_url('../assets/js/patient-account.js') ?>"></script>
    <script src="<?= asset_url('../assets/js/appointment-service-manager.js') ?>"></script>

    <!-- Active Navigation Highlight -->
    <script>
        document.addEventListener('DOMContentLoaded', function () {
            var params = new URLSearchParams(window.location.search);
            var currentPage = params.get('page') || 'dashboard';
            if (typeof window.updateActiveNav === 'function') {
                window.updateActiveNav(currentPage);
            } else {
                var navLinks = document.querySelectorAll('.sidebar-nav .nav-link');
                navLinks.forEach(function(link) {
                    link.classList.remove('active');
                    if (link.href && link.href.includes('page=' + currentPage)) {
                        link.classList.add('active');
                    }
                });
            }

            // Header page search - shared logic in assets/js/portal-search.js.
            if (typeof window.initPortalSearch === 'function') {
                window.initPortalSearch({
                    inputId: 'doctorSearch',
                    keywords: {
                        "dashboard": ["home", "overview", "summary"],
                        "appointments": ["booking", "bookings", "calendar", "visits"],
                        "schedule": ["availability", "calendar", "time slots", "leave"],
                        "patients": ["patient list", "profiles", "history", "charts", "add patient", "patient accounts"],
                        "arrivals": ["today", "queue", "checked in", "waiting"],
                        "ehr": ["patient visit", "visit", "session", "treatment", "charting", "odontogram", "clinical notes", "complete visit", "complete session"],
                        "records": ["clinical records", "history", "odontogram"],
                        "notifications": ["alerts", "updates"],
                        "settings": ["preferences", "configuration", "password"],
                        "profile": ["account", "my profile"]
                    },
                    sections: [
                        {
                            "title": "General",
                            "href": "doctor.php?page=appointments&tab=general",
                            "keywords": ["all appointments"],
                            "parent": "Appointments"
                        },
                        {
                            "title": "Follow-ups",
                            "href": "doctor.php?page=appointments&tab=followup",
                            "keywords": ["follow up", "followup", "recall"],
                            "parent": "Appointments"
                        },
                        {
                            "title": "Recent",
                            "href": "doctor.php?page=appointments&tab=recent",
                            "keywords": ["past", "completed"],
                            "parent": "Appointments"
                        },
                        {
                            "title": "Calendar",
                            "href": "doctor.php?page=schedule&view=calendar",
                            "keywords": ["availability"],
                            "parent": "Schedule"
                        },
                        {
                            "title": "Leave Requests",
                            "href": "doctor.php?page=schedule&view=leave_requests",
                            "keywords": ["leave", "vacation", "absence", "day off"],
                            "parent": "Schedule"
                        },
                        {
                            "title": "Notifications",
                            "href": "doctor.php?page=notifications",
                            "keywords": ["alerts", "updates"]
                        }
                    ]
                });
            }
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

        // Doctor idle timeout is disabled.
    </script>

    <script>
        window.showToast = window.showToast || window.showAppToast;
    </script>

</body>

</html>