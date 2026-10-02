<?php
require_once __DIR__ . '/../config/asset_helpers.php';
include_once '../config/session_check.php';
check_session(['admin']);
include_once '../config/system_logs.php';
include_once '../config/profile_helpers.php';
$csrf_token = security_generate_csrf_token();

// Handle modules that may redirect via headers before any HTML output.
$page = isset($_GET['page']) && $_GET['page'] !== '' ? $_GET['page'] : 'dashboard';
$dashboardLayout = in_array($page, ['dashboard', 'reports', 'doctor_management', 'schedule_board', 'appointments', 'system_logs', 'backups'], true);
$action = $_GET['action'] ?? ($_POST['action'] ?? '');

if (function_exists('log_system_event') && $_SERVER['REQUEST_METHOD'] === 'POST' && $action !== '') {
    $normalizedAction = preg_replace('/[^a-z0-9_\.-]/i', '_', strtolower((string)$action));
    $entityType = preg_replace('/[^a-z0-9_\.-]/i', '_', strtolower((string)$page));
    $isViewOnlyAction = in_array($normalizedAction, ['view', 'open', 'show', 'list', 'read', 'index'], true) || str_starts_with($normalizedAction, 'view_');
    if ($normalizedAction !== '' && !$isViewOnlyAction) {
        log_system_event(
            $conn,
            'admin.change.' . $normalizedAction,
            $entityType !== '' ? $entityType : 'admin_module',
            null,
            'Admin data change action executed: page=' . $page . ', action=' . $action,
            (int)($_SESSION['user_id'] ?? 0)
        );
    }
}

if ($page === 'manage_users' && $_SERVER['REQUEST_METHOD'] === 'POST' && in_array($action, ['archive', 'reset_password'], true)) {
    include 'admin_modules/manage_users.php';
    exit;
}

if ($page === 'services' && $_SERVER['REQUEST_METHOD'] === 'POST' && in_array($action, ['add', 'edit', 'archive', 'restore', 'save_time', 'delete_time'], true)) {
    include 'admin_modules/services.php';
    exit;
}

if ($page === 'reviews' && $_SERVER['REQUEST_METHOD'] === 'POST' && in_array($action, ['feature', 'unfeature', 'delete'], true)) {
    include 'admin_modules/reviews.php';
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'ai_chat') {
    header('Content-Type: application/json');
    include 'admin_modules/ai_chat_handler.php';
    exit;
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
        href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap"
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
    <link href="<?= asset_url('../assets/css/odontogram-rotate-prompt.css') ?>" rel="stylesheet">
</head>

<body class="admin-portal">
    <script>
    (function () {
        try {
            if (window.innerWidth > 991 && localStorage.getItem('sidebar-collapsed') === 'true') {
                document.body.classList.add('toggle-sidebar');
            }
        } catch (e) {}
    })();
    </script>
    <!-- Loaded early: the records module calls window.OdontogramFullscreen at parse time. -->
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
            <a href="admin.php?page=dashboard" class="logo d-flex align-items-center">
                <img src="../assets/img/clinic-logo.png" alt="">
            </a>
        </div>

        <div class="header-actions ms-auto d-flex align-items-center flex-shrink-0">
        <?php
        $header_search_id = 'adminSearch';
        $header_search_placeholder = 'Search pages...';
        include dirname(__DIR__) . '/includes/header_search.php';
        ?>

        <nav class="header-nav">
            <ul class="d-flex align-items-center">
                <?php include dirname(__DIR__) . '/includes/header_clock.php'; ?>

                <?php
                $notifications_portal_url = 'admin.php?page=notifications';
                include dirname(__DIR__) . '/includes/notification_dropdown.php';
                ?>

                <?php
                $profile_portal_base = 'admin.php';
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
                <a class="nav-link collapsed" href="admin.php?page=dashboard" data-tooltip="Dashboard" title="Dashboard">
                    <i class="bi bi-grid"></i>
                    <span>Dashboard</span>
                </a>
            </li>
            <li class="nav-item">
                <a class="nav-link collapsed" href="admin.php?page=manage_users" data-tooltip="Manage Users" title="Manage Users">
                    <i class="bi bi-person"></i>
                    <span>Manage Users</span>
                </a>
            </li>
            
            <li class="nav-item">
                <a class="nav-link collapsed" href="admin.php?page=services" data-tooltip="Services" title="Services">
                    <i class="bi bi-bag-heart"></i>
                    <span>Services</span>
                </a>
            </li>
            <li class="nav-item">
                <a class="nav-link collapsed" href="admin.php?page=billing" data-tooltip="Billing" title="Billing">
                    <i class="bi bi-receipt"></i>
                    <span>Billing</span>
                </a>
            </li>
            <li class="nav-item">
                <a class="nav-link collapsed" href="admin.php?page=doctor_management&tab=schedule" data-tooltip="Doctor Management" title="Doctor Management">
                    <i class="bi bi-calendar2-check"></i>
                    <span>Doctor Management</span>
                </a>
            </li>
            <li class="nav-item">
                <a class="nav-link collapsed" href="admin.php?page=records" data-tooltip="Clinical Records" title="Clinical Records">
                    <i class="bi bi-journal-medical"></i>
                    <span>Clinical Records</span>
                </a>
            </li>
            <li class="nav-item">
                <a class="nav-link collapsed" href="admin.php?page=contact_messages" data-tooltip="Contact Us" title="Contact Us">
                    <i class="bi bi-envelope"></i>
                    <span>Contact Us</span>
                </a>
            </li>
            <li class="nav-item">
                <a class="nav-link collapsed" href="admin.php?page=reports" data-tooltip="Reports" title="Reports">
                    <i class="bi bi-bar-chart-line"></i>
                    <span>Reports</span>
                </a>
            </li>
            <li class="nav-item">
                <a class="nav-link collapsed" href="admin.php?page=reviews" data-tooltip="Reviews" title="Reviews">
                    <i class="bi bi-star"></i>
                    <span>Reviews</span>
                </a>
            </li>
            <li class="nav-item">
                <a class="nav-link collapsed" href="admin.php?page=system_logs" data-tooltip="System Logs" title="System Logs">
                    <i class="bi bi-file-text"></i>
                    <span>System Logs</span>
                </a>
            </li>
            <li class="nav-item">
                <a class="nav-link collapsed" href="admin.php?page=backups" data-tooltip="Backups" title="Backups">
                    <i class="bi bi-database"></i>
                    <span>Backups</span>
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
                                    include "admin_modules/dashboard.php";
                                    break;
                                case "manage_users":
                                    include "admin_modules/manage_users.php";
                                    break;
                                case "schedule_board":
                                    $_GET['tab'] = 'schedule';
                                    include "admin_modules/doctor_management.php";
                                    break;
                                case "appointments":
                                    $_GET['tab'] = 'schedule';
                                    include "admin_modules/doctor_management.php";
                                    break;
                                case "services":
                                    include "admin_modules/services.php";
                                    break;
                                case "reviews":
                                    include "admin_modules/reviews.php";
                                    break;
                                case "billing":
                                    include "admin_modules/billing.php";
                                    break;
                                case "doctor_management":
                                    include "admin_modules/doctor_management.php";
                                    break;
                                case "doctor_leaves":
                                    include "admin_modules/doctor_leaves.php";
                                    break;
                                case "doctor_deployment":
                                    include "admin_modules/doctor_deployment.php";
                                    break;
                                case "reports":
                                    include "admin_modules/reports.php";
                                    break;
                                case "records":
                                    include "admin_modules/records.php";
                                    break;
                                case "contact_messages":
                                    include "admin_modules/contact_messages.php";
                                    break;
                                case "notifications":
                                    include "admin_modules/notifications.php";
                                    break;
                                case "system_logs":
                                    include "admin_modules/system_logs.php";
                                    break;
                                case "profile":
                                    include "admin_modules/profile.php";
                                    break;
                                case "settings":
                                    include "admin_modules/settings.php";
                                    break;
                                case "backups":
                                    include "admin_modules/backups.php";
                                    break;
                                default:
                                    include "admin_modules/dashboard.php";
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
    <script src="<?= asset_url('../assets/js/app-toast.js') ?>"></script>
    <script src="<?= asset_url('../assets/js/dental-cache.js') ?>"></script>
    <script src="<?= asset_url('../assets/js/dental-ui.js') ?>"></script>
    <script src="<?= asset_url('../assets/js/app-request.js') ?>"></script>
    <script src="<?= asset_url('../assets/js/app-confirm.js') ?>"></script>
    <script src="<?= asset_url('../assets/js/password-toggle.js') ?>"></script>
    <script src="<?= asset_url('../assets/js/dental-calendar.js') ?>"></script>
    <script src="<?= asset_url('../assets/js/page-skeleton.js') ?>"></script>
    <script src="<?= asset_url('../assets/js/main.js') ?>"></script>
    <script src="<?= asset_url('../assets/js/portal-dropdown.js') ?>"></script>
    <script src="<?= asset_url('../assets/js/portal-search.js') ?>"></script>
    <script src="<?= asset_url('../assets/js/profiling-display.js') ?>"></script>
    <script src="<?= asset_url('../assets/js/media-gallery.js') ?>"></script>
    <script src="<?= asset_url('../assets/js/session-idle.js') ?>"></script>

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
                    inputId: 'adminSearch',
                    keywords: {
                        "dashboard": ["home", "overview", "summary"],
                        "manage_users": ["users", "accounts", "staff", "patients", "doctor", "secretary"],
                        "services": ["procedures", "pricing", "clinic services", "treatments"],
                        "billing": ["invoices", "payments", "payment history", "balance", "receipts"],
                        "doctor_management": ["doctor", "dentist", "providers", "schedule"],
                        "records": ["clinical records", "ehr", "patient history", "odontogram", "chart"],
                        "contact_messages": ["contact us", "inquiries", "messages", "gmail", "email"],
                        "reports": ["analytics", "charts", "insights", "revenue", "bir"],
                        "reviews": ["testimonials", "feedback", "ratings"],
                        "system_logs": ["logs", "audit", "history", "activity"],
                        "backups": ["backup", "database", "restore", "export"],
                        "notifications": ["alerts", "updates"],
                        "settings": ["preferences", "configuration", "password"],
                        "profile": ["account", "my profile"]
                    },
                    sections: [
                        {
                            "title": "Clinic Users",
                            "href": "admin.php?page=manage_users&type=users",
                            "keywords": ["staff", "doctor", "secretary", "admin"],
                            "parent": "Manage Users"
                        },
                        {
                            "title": "Patients",
                            "href": "admin.php?page=manage_users&type=patients",
                            "keywords": ["patient accounts"],
                            "parent": "Manage Users"
                        },
                        {
                            "title": "Archived Services",
                            "href": "admin.php?page=services&tab=archived",
                            "keywords": ["archive", "restore"],
                            "parent": "Services"
                        },
                        {
                            "title": "Service Time",
                            "href": "admin.php?page=services&tab=timeslot",
                            "keywords": ["time slots", "duration"],
                            "parent": "Services"
                        },
                        {
                            "title": "Doctor Schedule",
                            "href": "admin.php?page=doctor_management&tab=schedule",
                            "keywords": ["calendar", "availability"],
                            "parent": "Doctor Management"
                        },
                        {
                            "title": "Doctor Leaves",
                            "href": "admin.php?page=doctor_management&tab=leaves",
                            "keywords": ["leave", "vacation", "absence", "day off"],
                            "parent": "Doctor Management"
                        },
                        {
                            "title": "Doctor Worktype",
                            "href": "admin.php?page=doctor_management&tab=deployment",
                            "keywords": ["deployment", "part time", "regular", "assignment"],
                            "parent": "Doctor Management"
                        },
                        {
                            "title": "Prescription Profiles",
                            "href": "admin.php?page=doctor_management&tab=prescription_profiles",
                            "keywords": ["prescription", "rx", "medicine", "license", "ptr"],
                            "parent": "Doctor Management"
                        },
                        {
                            "title": "Featured Reviews",
                            "href": "admin.php?page=reviews&filter=featured",
                            "keywords": ["featured", "landing page"],
                            "parent": "Reviews"
                        },
                        {
                            "title": "Active Users",
                            "href": "admin.php?page=system_logs&view=active_users",
                            "keywords": ["online", "sessions"],
                            "parent": "System Logs"
                        },
                        {
                            "title": "Audit Logs",
                            "href": "admin.php?page=system_logs&view=audit_logs",
                            "keywords": ["changes", "audit trail"],
                            "parent": "System Logs"
                        },
                        {
                            "title": "User Logs",
                            "href": "admin.php?page=system_logs&view=user_logs",
                            "keywords": ["login", "logout", "staff"],
                            "parent": "System Logs"
                        },
                        {
                            "title": "Patient Sessions",
                            "href": "admin.php?page=system_logs&view=patient_sessions",
                            "keywords": ["patient login"],
                            "parent": "System Logs"
                        },
                        {
                            "title": "Notifications",
                            "href": "admin.php?page=notifications",
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

        if (typeof initSessionIdleMonitor === 'function') {
            initSessionIdleMonitor({
                idleLimitSeconds: 3600,
                countdownSeconds: 60,
                roleLabel: 'User',
                pingUrl: '../assets/api/auth/session_ping.php',
                logoutFn: logout
            });
        }
    </script>

</body>

</html>