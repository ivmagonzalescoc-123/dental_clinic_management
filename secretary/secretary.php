<?php
require_once __DIR__ . '/../config/asset_helpers.php';
include_once '../config/session_check.php';
check_session(['secretary']);
$csrf_token = security_generate_csrf_token();
$baseUrl = rtrim(dirname($_SERVER['SCRIPT_NAME']), '/\\');
// include database to show queue counts in sidebar (use include_once to avoid re-definitions)
include_once '../config/database.php';
include_once '../config/system_logs.php';
include_once '../config/capabilities.php';
include_once '../config/profile_helpers.php';
include_once '../config/contact_messages_helpers.php';

// Handle AJAX API requests before HTML output
$page = isset($_GET['page']) && $_GET['page'] !== '' ? $_GET['page'] : 'dashboard';
$dashboardLayout = ($page === 'dashboard');
$action = $_GET['action'] ?? '';

if ($action === '' && isset($_POST['action'])) {
    $action = (string)$_POST['action'];
}

if (function_exists('log_system_event') && $_SERVER['REQUEST_METHOD'] === 'POST' && $action !== '') {
    $normalizedAction = preg_replace('/[^a-z0-9_\.-]/i', '_', strtolower((string)$action));
    $entityType = preg_replace('/[^a-z0-9_\.-]/i', '_', strtolower((string)$page));
    $isViewOnlyAction = in_array($normalizedAction, ['view', 'open', 'show', 'list', 'read', 'index'], true) || str_starts_with($normalizedAction, 'view_');
    if ($normalizedAction !== '' && !$isViewOnlyAction) {
        log_system_event(
            $conn,
            'secretary.change.' . $normalizedAction,
            $entityType !== '' ? $entityType : 'secretary_module',
            null,
            'Secretary data change action executed: page=' . $page . ', action=' . $action,
            (int)($_SESSION['user_id'] ?? 0)
        );
    }
}

// Handle appointments POST actions before any HTML output to avoid headers-sent warnings
if ($page === 'appointments' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    capabilities_require_web('appointment.manage', 'secretary.php?page=appointments');
    include 'secretary_modules/appointments.php';
    exit;
}

// Compute today's pending arrival count for sidebar badge
$today = date('Y-m-d');
$pendingCount = 0;
try {
    $stmt = $conn->prepare(
        "SELECT COUNT(*) AS c
         FROM appointments
         WHERE appointment_date = ?
           AND status = 'confirmed'"
    );
    if ($stmt) {
        $stmt->bind_param('s', $today);
        $stmt->execute();
        $res = $stmt->get_result();
        if ($res && ($r = $res->fetch_assoc())) {
            $pendingCount = (int)($r['c'] ?? 0);
        }
        $stmt->close();
    }
} catch (Throwable $e) {
    error_log('Error computing pendingCount: ' . $e->getMessage());
    $pendingCount = 0;
}

$newContactCount = 0;
try {
    $newContactCount = contact_messages_count_new($conn);
} catch (Throwable $e) {
    error_log('Error computing newContactCount: ' . $e->getMessage());
    $newContactCount = 0;
}

// Compute counts for sidebar dots
$recordsQueueCount = 0;
$pendingReqCount = 0;
$guestBookingsCount = 0;
try {
    // Records queue: appointments awaiting secretary clinical record creation
    $rqStmt = $conn->query("SELECT COUNT(*) AS c FROM appointments WHERE status = 'awaiting_secretary_records'");
    if ($rqStmt && ($rqRow = $rqStmt->fetch_assoc())) {
        $recordsQueueCount = (int)($rqRow['c'] ?? 0);
    }
    // Pending reschedule requests
    $rrStmt = $conn->query("SELECT COUNT(*) AS c FROM appointment_reschedule_requests WHERE status = 'pending'");
    if ($rrStmt && ($rrRow = $rrStmt->fetch_assoc())) {
        $pendingReqCount = (int)($rrRow['c'] ?? 0);
    }
    // Guest bookings (new customers)
    $gbStmt = $conn->query("SELECT COUNT(*) AS c FROM bookings WHERE status IN ('pending_verification','verified')");
    if ($gbStmt && ($gbRow = $gbStmt->fetch_assoc())) {
        $guestBookingsCount = (int)($gbRow['c'] ?? 0);
    }
} catch (Throwable $e) {
    error_log('Error computing sidebar dot counts: ' . $e->getMessage());
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
    <link href="<?= asset_url('../assets/css/dental-calendar.css') ?>" rel="stylesheet">
    <link href="<?= asset_url('../assets/css/appointment-services.css') ?>" rel="stylesheet">
    <link href="<?= asset_url('../assets/css/odontogram-rotate-prompt.css') ?>" rel="stylesheet">
    <link href="<?= asset_url('../assets/css/doctor-patients.css') ?>" rel="stylesheet">
</head>

<body class="secretary-portal">
    <script>
    (function () {
        try {
            if (window.innerWidth > 991 && localStorage.getItem('sidebar-collapsed') === 'true') {
                document.body.classList.add('toggle-sidebar');
            }
        } catch (e) {}
    })();
    </script>
    <!-- Loaded early (before EHR queue/records module content further down)
         since those modules call window.OdontogramFullscreen synchronously
         at parse time — a footer-loaded script would be too late. -->
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
            <a href="secretary.php?page=dashboard" class="logo d-flex align-items-center">
                <img src="../assets/img/clinic-logo.png" alt="">
            </a>
        </div>

        <div class="header-actions ms-auto d-flex align-items-center flex-shrink-0">
        <?php
        $header_search_id = 'secretarySearch';
        $header_search_placeholder = 'Search pages...';
        include dirname(__DIR__) . '/includes/header_search.php';
        ?>

        <nav class="header-nav">
            <ul class="d-flex align-items-center">

                <?php include dirname(__DIR__) . '/includes/header_clock.php'; ?>

                <?php
                $notifications_portal_url = 'secretary.php?page=notifications';
                include dirname(__DIR__) . '/includes/notification_dropdown.php';
                ?>

                

                <?php
                $profile_portal_base = 'secretary.php';
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
                <a class="nav-link collapsed" href="secretary.php?page=dashboard" data-tooltip="Dashboard" title="Dashboard">
                    <i class="bi bi-grid"></i>
                    <span>Dashboard</span>
                </a>
            </li>
            <li class="nav-item">
                <a class="nav-link collapsed" href="secretary.php?page=patients" data-tooltip="Patients" title="Patients">
                    <i class="bi bi-people"></i>
                    <span>Patients</span>
                </a>
            </li>
            <li class="nav-item">
                <a class="nav-link collapsed" href="secretary.php?page=appointments" data-tooltip="Appointments" title="Appointments">
                    <i class="bi bi-calendar2-week"></i>
                    <span>Appointments</span>
                    <span class="nav-dot<?php echo ((int)$guestBookingsCount > 0 || (int)$pendingReqCount > 0) ? ' is-active' : ''; ?>" id="secretaryAppointmentsDot"></span>
                </a>
            </li>
            <li class="nav-item">
                <a class="nav-link collapsed" href="secretary.php?page=doctor_schedule" data-tooltip="Doctor Schedule" title="Doctor Schedule">
                    <i class="bi bi-calendar2-check"></i>
                    <span>Doctor Schedule</span>
                </a>
            </li>
            <li class="nav-item">
                <a class="nav-link collapsed" href="secretary.php?page=arrivals" data-tooltip="Arrivals" title="Arrivals">
                    <i class="bi bi-person-check"></i>
                    <span>Arrivals</span>
                    <span class="nav-dot<?php echo (int)$pendingCount > 0 ? ' is-active' : ''; ?>" id="secretaryArrivalsDot"></span>
                </a>
            </li>
            <li class="nav-item">
                <a class="nav-link collapsed" href="secretary.php?page=ehr_queue" data-tooltip="EHR Queue" title="EHR Queue">
                    <i class="bi bi-journal-medical"></i>
                    <span>EHR Queue</span>
                    <span class="nav-dot<?php echo (int)$recordsQueueCount > 0 ? ' is-active' : ''; ?>" id="secretaryEhrDot"></span>
                </a>
            </li>
            <li class="nav-item">
                <a class="nav-link collapsed" href="secretary.php?page=records" data-tooltip="Records" title="Records">
                    <i class="bi bi-folder2-open"></i>
                    <span>Records</span>
                </a>
            </li>
            <li class="nav-item">
                <a class="nav-link collapsed" href="secretary.php?page=billing" data-tooltip="Billing" title="Billing">
                    <i class="bi bi-receipt"></i>
                    <span>Billing</span>
                </a>
            </li>
            <li class="nav-item">
                <a class="nav-link collapsed" href="secretary.php?page=services" data-tooltip="Services" title="Services">
                    <i class="bi bi-clipboard2-pulse"></i>
                    <span>Services</span>
                </a>
            </li>
            <li class="nav-item">
                <a class="nav-link collapsed" href="secretary.php?page=contact_messages" data-tooltip="Contact Us" title="Contact Us">
                    <i class="bi bi-envelope"></i>
                    <span>Contact Us</span>
                    <span class="nav-dot<?php echo (int)$newContactCount > 0 ? ' is-active' : ''; ?>" id="secretaryContactDot"></span>
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
                            try {
                                switch ($page) {
                                    case "dashboard":
                                        include "secretary_modules/dashboard.php";
                                        break;
                                    case "patients":
                                    case "manage_patients": // old link
                                        include "secretary_modules/patients.php";
                                        break;
                                    case "appointments":
                                        include "secretary_modules/appointments.php";
                                        break;
                                    case "scheduling":
                                        header('Location: secretary.php?page=appointments');
                                        exit;
                                    case "doctor_schedule":
                                        include "secretary_modules/doctor_schedule.php";
                                        break;
                                    case "billing":
                                        include "secretary_modules/billing.php";
                                        break;
                                    case "services":
                                        include "secretary_modules/services.php";
                                        break;
                                    case "contact_messages":
                                        include "secretary_modules/contact_messages.php";
                                        break;
                                    case "arrivals":
                                        include "secretary_modules/arrivals.php";
                                        break;
                                    case "ehr_queue":
                                        include "secretary_modules/ehr_queue.php";
                                        break;
                                    case "records":
                                        include "secretary_modules/records.php";
                                        break;
                                    case "notifications":
                                        include "secretary_modules/notifications.php";
                                        break;
                                    case "profile":
                                        include "secretary_modules/profile.php";
                                        break;
                                    case "settings":
                                        include "secretary_modules/settings.php";
                                        break;
                                    default:
                                        include "secretary_modules/dashboard.php";
                                        break;
                                }
                            } catch (Throwable $e) {
                                error_log('Error including secretary page ' . $page . ': ' . $e->getMessage());
                                echo '<div class="alert alert-danger">An internal error occurred loading this page. Please contact the administrator.</div>';
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
        document.addEventListener('DOMContentLoaded', function() {
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
                    inputId: 'secretarySearch',
                    keywords: {
                        "dashboard": ["home", "overview", "summary"],
                        "patients": ["patient list", "registration", "walk-in", "accounts", "add patient", "charts", "history"],
                        "appointments": ["booking", "bookings", "requests", "calendar", "reschedule"],
                        "doctor_schedule": ["doctor", "availability", "calendar", "leave"],
                        "arrivals": ["check in", "queue", "today", "waiting"],
                        "ehr_queue": ["ehr", "records queue", "clinical", "encoding"],
                        "records": ["clinical records", "history", "patient history"],
                        "billing": ["payments", "invoices", "transactions", "receipts", "balance"],
                        "services": ["procedures", "pricing", "clinic services"],
                        "contact_messages": ["contact us", "inquiries", "messages", "gmail", "email"],
                        "notifications": ["alerts", "updates"],
                        "settings": ["preferences", "configuration", "password"],
                        "profile": ["account", "my profile"]
                    },
                    sections: [
                        {
                            "title": "New Patients",
                            "href": "secretary.php?page=appointments&section=new_customers",
                            "keywords": ["new customers", "first visit"],
                            "parent": "Appointments"
                        },
                        {
                            "title": "Follow-ups",
                            "href": "secretary.php?page=appointments&section=follow_ups",
                            "keywords": ["follow up", "followup", "recall"],
                            "parent": "Appointments"
                        },
                        {
                            "title": "All Appointments",
                            "href": "secretary.php?page=appointments&section=appointments&view=all",
                            "keywords": ["list"],
                            "parent": "Appointments"
                        },
                        {
                            "title": "Notifications",
                            "href": "secretary.php?page=notifications",
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

        // Secretary idle timeout is disabled.
    </script>

</body>

</html>