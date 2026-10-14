<?php
require_once __DIR__ . '/../config/asset_helpers.php';
include_once '../config/session_check.php';
check_session(['patient']);
include_once '../config/profile_helpers.php';
$csrf_token = security_generate_csrf_token();
$page = isset($_GET['page']) && $_GET['page'] !== '' ? $_GET['page'] : 'dashboard';
$dashboardLayout = ($page === 'dashboard');

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
    <meta content="width=device-width, initial-scale=1.0, viewport-fit=cover" name="viewport">
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
    <link href="<?= asset_url('../assets/css/module-content-ux.css') ?>" rel="stylesheet">
    <link href="<?= asset_url('../assets/css/dental-calendar.css') ?>" rel="stylesheet">
    <link href="<?= asset_url('../assets/css/appointment-services.css') ?>" rel="stylesheet">
    <link href="<?= asset_url('../assets/css/patient-mobile.css') ?>" rel="stylesheet">
    <link href="<?= asset_url('../assets/css/portal-header-mobile.css') ?>" rel="stylesheet">
    <link href="<?= asset_url('../assets/css/portal-nav.css') ?>" rel="stylesheet">
    <link href="<?= asset_url('../assets/css/patient-ledger.css') ?>" rel="stylesheet">
    <link href="<?= asset_url('../assets/css/doctor-patients.css') ?>" rel="stylesheet">
</head>

<body class="patient-portal">
    <script>
    (function () {
        try {
            if (window.innerWidth > 991 && localStorage.getItem('sidebar-collapsed') === 'true') {
                document.body.classList.add('toggle-sidebar');
            }
        } catch (e) {}
    })();
    </script>

    <!-- Global Loading Overlay -->
    <div id="globalLoadingOverlay" style="position:fixed;inset:0;z-index:99999;display:flex;align-items:center;justify-content:center;pointer-events:none;">
        <div style="width:36px;height:36px;border:3px solid #e2e8f0;border-top-color:#1d6fd8;border-radius:50%;animation:spin 0.8s linear infinite;"></div>
    </div>
    <style>@keyframes spin{to{transform:rotate(360deg)}}</style>

    <!-- ======= Header ======= -->
    <header id="header" class="header fixed-top d-flex align-items-center">
        <div class="d-flex align-items-center">
            <a href="patient.php?page=dashboard" class="logo d-flex align-items-center">
                <img src="../assets/img/clinic-logo.png" alt="">
            </a>
        </div>

        <div class="header-actions ms-auto d-flex align-items-center flex-shrink-0">
        <?php
        $header_search_id = 'patientSearch';
        $header_search_placeholder = 'Search pages...';
        include dirname(__DIR__) . '/includes/header_search.php';
        ?>

        <nav class="header-nav">
            <ul class="d-flex align-items-center">

                <?php include dirname(__DIR__) . '/includes/header_clock.php'; ?>

                <?php
                $notifications_portal_url = 'patient.php?page=notifications';
                include dirname(__DIR__) . '/includes/notification_dropdown.php';
                ?>

                

                <?php
                $profile_portal_base = 'patient.php';
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
                <a class="nav-link collapsed" href="patient.php?page=dashboard" data-tooltip="Dashboard" title="Dashboard">
                    <i class="bi bi-grid"></i>
                    <span>Dashboard</span>
                </a>
            </li>
            <li class="nav-item">
                <a class="nav-link collapsed" href="patient.php?page=appointments" data-tooltip="Appointments" title="Appointments">
                    <i class="bi bi-calendar2-week"></i>
                    <span>Appointments</span>
                </a>
            </li>
            <li class="nav-item">
                <a class="nav-link collapsed" href="patient.php?page=services" data-tooltip="Services" title="Services">
                    <i class="bi bi-bag-heart"></i>
                    <span>Services</span>
                </a>
            </li>
            <li class="nav-item">
                <a class="nav-link collapsed" href="patient.php?page=clinic_records" data-tooltip="Clinic Records" title="Clinic Records">
                    <i class="bi bi-folder2-open"></i>
                    <span>Clinic Records</span>
                </a>
            </li>
            <li class="nav-item">
                <a class="nav-link collapsed" href="patient.php?page=transactions" data-tooltip="Billing" title="Billing">
                    <i class="bi bi-receipt"></i>
                    <span>Billing</span>
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
                                    include "patient_modules/dashboard.php";
                                    break;
                                
                                case "appointments":
                                    include "patient_modules/appointments.php";
                                    break;
                                case "services":
                                    include "patient_modules/services.php";
                                    break;
                                case "clinic_records":
                                    include "patient_modules/clinic_records.php";
                                    break;
                                case "transactions":
                                    include "patient_modules/transactions.php";
                                    break;
                                case "billing_view":
                                    include "patient_modules/billing_view.php";
                                    break;
                                case "notifications":
                                    include "patient_modules/notifications.php";
                                    break;
                                case "settings":
                                    include "patient_modules/settings.php";
                                    break;
                                case "profile":
                                    include "patient_modules/profile.php";
                                    break;
                                default:
                                    include "patient_modules/dashboard.php";
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

    <nav class="patient-mobile-nav d-lg-none" aria-label="Patient navigation">
        <a class="patient-mobile-nav-item" href="patient.php?page=services" data-page="services">
            <i class="bi bi-bag-heart" aria-hidden="true"></i>
            <span>Services</span>
        </a>
        <a class="patient-mobile-nav-item" href="patient.php?page=appointments" data-page="appointments">
            <i class="bi bi-calendar2-week" aria-hidden="true"></i>
            <span>Book</span>
        </a>
        <a class="patient-mobile-nav-item patient-mobile-nav-item--main" href="patient.php?page=dashboard" data-page="dashboard">
            <span class="patient-mobile-nav-fab"><i class="bi bi-grid-fill" aria-hidden="true"></i></span>
            <span>Dashboard</span>
        </a>
        <a class="patient-mobile-nav-item" href="patient.php?page=clinic_records" data-page="clinic_records">
            <i class="bi bi-folder2-open" aria-hidden="true"></i>
            <span>Records</span>
        </a>
        <a class="patient-mobile-nav-item" href="patient.php?page=transactions" data-page="transactions">
            <i class="bi bi-receipt" aria-hidden="true"></i>
            <span>Billing</span>
        </a>
    </nav>

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
    <script src="<?= asset_url('../assets/js/treatment-plan.js') ?>"></script>
    <script src="<?= asset_url('../assets/js/portal-dropdown.js') ?>"></script>
    <script src="<?= asset_url('../assets/js/portal-search.js') ?>"></script>
    <script src="<?= asset_url('../assets/js/session-idle.js') ?>"></script>
    <script src="<?= asset_url('../assets/js/appointment-service-manager.js') ?>"></script>
    <script src="<?= asset_url('../assets/js/patient-appointments-services.js') ?>"></script>
    <script src="<?= asset_url('../assets/js/media-gallery.js') ?>"></script>
    <script src="<?= asset_url('../assets/js/profiling-display.js') ?>"></script>
    <script src="<?= asset_url('../assets/js/patient-ledger.js') ?>"></script>
    <script src="<?= asset_url('../assets/js/patient-clinic-records.js') ?>"></script>

    <!-- Active Navigation Highlight -->
    <script>
        const patientCsrfToken = <?php echo json_encode($csrf_token); ?>;
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

            document.querySelectorAll('.patient-mobile-nav-item').forEach(function (item) {
                item.classList.toggle('active', item.getAttribute('data-page') === currentPage);
            });

            function syncPatientMobileLayout() {
                if (window.innerWidth <= 991) {
                    document.body.classList.remove('toggle-sidebar');
                }
            }
            syncPatientMobileLayout();
            window.addEventListener('resize', syncPatientMobileLayout);

            // Header page search - shared logic in assets/js/portal-search.js.
            if (typeof window.initPortalSearch === 'function') {
                window.initPortalSearch({
                    inputId: 'patientSearch',
                    keywords: {
                        "dashboard": ["home", "overview", "summary"],
                        "appointments": ["book", "booking", "schedule", "calendar", "reschedule", "cancel"],
                        "services": ["procedures", "pricing", "treatments", "clinic services"],
                        "clinic_records": ["medical record", "history", "completed appointments", "dental chart"],
                        "transactions": ["billing", "payments", "invoices", "receipts", "balance"],
                        "notifications": ["alerts", "updates"],
                        "settings": ["preferences", "configuration", "password"],
                        "profile": ["account", "my profile"]
                    },
                    sections: [
                        {
                            "title": "Notifications",
                            "href": "patient.php?page=notifications",
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
                idleLimitSeconds: 900,
                countdownSeconds: 60,
                roleLabel: 'Patient',
                pingUrl: '../assets/api/auth/session_ping.php',
                logoutFn: logout
            });
        }
    </script>

</body>

</html>