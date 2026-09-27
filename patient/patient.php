<?php
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
        href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap"
        rel="stylesheet">

    <!-- Vendor CSS Files -->
    <link href="../assets/vendor/bootstrap/css/bootstrap.min.css" rel="stylesheet">
    <link href="../assets/vendor/bootstrap-icons/bootstrap-icons.css" rel="stylesheet">
    <link href="../assets/vendor/boxicons/css/boxicons.min.css" rel="stylesheet">
    <link href="../assets/vendor/quill/quill.snow.css" rel="stylesheet">
    <link href="../assets/vendor/quill/quill.bubble.css" rel="stylesheet">
    <link href="../assets/vendor/remixicon/remixicon.css" rel="stylesheet">
    <link href="../assets/vendor/simple-datatables/style.css" rel="stylesheet">

    <!-- Template Main CSS File -->
    <link href="../assets/css/main.css?v=20260927-nodarkmode" rel="stylesheet">
    <link href="../assets/css/app-toast.css?v=20260718-dark1" rel="stylesheet">
    <link href="../assets/css/module-content-ux.css?v=20260928-ux" rel="stylesheet">
    <link href="../assets/css/appointment-services.css?v=20260927-nodarkmode" rel="stylesheet">
    <link href="../assets/css/patient-mobile.css?v=20260718-dark1" rel="stylesheet">
    <link href="../assets/css/portal-header-mobile.css?v=20260927-nodarkmode" rel="stylesheet">
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
                    <i class="bi bi-receipt"></i>
                    <span>Services</span>
                </a>
            </li>
            <li class="nav-item">
                <a class="nav-link collapsed" href="patient.php?page=clinic_records" data-tooltip="Clinic Records" title="Clinic Records">
                    <i class="bi bi-journal-medical"></i>
                    <span>Clinic Records</span>
                </a>
            </li>
            <li class="nav-item">
                <a class="nav-link collapsed" href="patient.php?page=transactions" data-tooltip="Billing" title="Billing">
                    <i class="bi bi-cart"></i>
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
        <a class="patient-mobile-nav-item" href="patient.php?page=dashboard" data-page="dashboard">
            <i class="bi bi-grid" aria-hidden="true"></i>
            <span>Home</span>
        </a>
        <a class="patient-mobile-nav-item" href="patient.php?page=appointments" data-page="appointments">
            <i class="bi bi-calendar2-week" aria-hidden="true"></i>
            <span>Book</span>
        </a>
        <a class="patient-mobile-nav-item" href="patient.php?page=services" data-page="services">
            <i class="bi bi-receipt" aria-hidden="true"></i>
            <span>Services</span>
        </a>
        <a class="patient-mobile-nav-item" href="patient.php?page=clinic_records" data-page="clinic_records">
            <i class="bi bi-journal-medical" aria-hidden="true"></i>
            <span>Records</span>
        </a>
        <a class="patient-mobile-nav-item" href="patient.php?page=transactions" data-page="transactions">
            <i class="bi bi-cart" aria-hidden="true"></i>
            <span>Billing</span>
        </a>
    </nav>

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
    <script src="../assets/js/app-toast.js?v=20260614-short"></script>
    <script src="../assets/js/dental-cache.js"></script>
    <script src="../assets/js/dental-ui.js?v=20260718-fix1"></script>
    <script src="../assets/js/app-request.js?v=20260613"></script>
    <script src="../assets/js/app-confirm.js?v=20260611-confirm"></script>
    <script src="../assets/js/password-toggle.js?v=20260614-pw-no-lock"></script>
    <script src="../assets/js/main.js?v=20260928-ux"></script>
    <script src="../assets/js/portal-dropdown.js?v=20260614-notify"></script>
    <script src="../assets/js/session-idle.js"></script>
    <script src="../assets/js/appointment-service-manager.js?v=20260718-fix1"></script>
    <script src="../assets/js/patient-appointments-services.js?v=20260718-fix1"></script>
    <script src="../assets/js/media-gallery.js?v=20260717-1"></script>
    <script src="../assets/js/profiling-display.js?v=20260717-1"></script>

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

            const searchInput = document.getElementById('patientSearch');

            if (searchInput) {
                const portalBaseFile = 'patient.php';
                const pageKeywordMap = {
                    dashboard: ['home', 'overview', 'summary'],
                    appointments: ['booking', 'schedule', 'calendar'],
                    services: ['procedures', 'pricing', 'clinic services'],
                    clinic_records: ['medical record', 'history', 'completed appointments'],
                    transactions: ['billing', 'payments', 'history'],
                    settings: ['preferences', 'configuration'],
                    profile: ['account', 'my profile']
                };

                const styleId = 'portalSearchSuggestionStyles';
                if (!document.getElementById(styleId)) {
                    const style = document.createElement('style');
                    style.id = styleId;
                    style.textContent = '' +
                        '.search-suggest-menu{position:absolute;top:calc(100% + 6px);left:0;right:0;background:#fff;border:1px solid #dbe4f0;border-radius:10px;box-shadow:0 10px 24px rgba(24,45,74,.14);max-height:280px;overflow:auto;z-index:1200;display:none;}' +
                        '.search-suggest-item{display:block;width:100%;text-align:left;border:0;background:transparent;padding:10px 12px;cursor:pointer;}' +
                        '.search-suggest-item:hover,.search-suggest-item.active{background:#eef5ff;}' +
                        '.search-suggest-title{font-size:14px;color:#153b66;font-weight:600;}' +
                        '.search-suggest-meta{font-size:12px;color:#6b7f95;}' +
                        '.search-suggest-empty{padding:10px 12px;font-size:13px;color:#71839a;}' +
                        '.search-suggest-item mark{background:#fff3bf;padding:0 1px;border-radius:2px;}';
                    document.head.appendChild(style);
                }

                const wrap = searchInput.closest('.header-search-wrap');
                const menu = document.createElement('div');
                menu.className = 'search-suggest-menu';
                if (wrap) {
                    wrap.appendChild(menu);
                }

                const linkNodes = document.querySelectorAll('#sidebar-nav a.nav-link[href*="page="], .dropdown-menu a.dropdown-item[href*="page="]');
                const seen = new Set();
                const pages = [];

                linkNodes.forEach((a) => {
                    let urlObj;
                    try {
                        urlObj = new URL(a.href, window.location.origin);
                    } catch (err) {
                        return;
                    }
                    const page = (urlObj.searchParams.get('page') || '').trim();
                    if (!page) return;

                    const title = (a.textContent || '').replace(/\s+/g, ' ').trim() || page.replace(/_/g, ' ');
                    const key = page + '|' + title.toLowerCase();
                    if (seen.has(key)) return;
                    seen.add(key);

                    const keywords = [title, page.replace(/_/g, ' ')].concat(pageKeywordMap[page] || []);
                    const normalized = Array.from(new Set(keywords.map((k) => String(k).toLowerCase().trim()).filter(Boolean)));

                    pages.push({
                        title: title,
                        page: page,
                        href: portalBaseFile + '?page=' + encodeURIComponent(page),
                        keywords: normalized
                    });
                });

                const highlightText = (text, term) => {
                    if (!term) return text;
                    const escaped = term.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
                    return text.replace(new RegExp('(' + escaped + ')', 'ig'), '<mark>$1</mark>');
                };

                const findMatches = (term) => {
                    const q = (term || '').trim().toLowerCase();
                    if (!q) return pages.slice(0, 6);
                    const tokens = q.split(/\s+/).filter(Boolean);
                    return pages.filter((p) => {
                        const hay = (p.title + ' ' + p.page + ' ' + p.keywords.join(' ')).toLowerCase();
                        return tokens.every((t) => hay.indexOf(t) !== -1);
                    }).slice(0, 6);
                };

                let currentSuggestions = [];
                const renderMenu = (term) => {
                    const header = document.getElementById('header');
                    if (header && !header.classList.contains('header-search-open') && window.innerWidth <= 991) {
                        menu.style.display = 'none';
                        return;
                    }
                    currentSuggestions = findMatches(term);
                    if (!currentSuggestions.length) {
                        menu.innerHTML = '<div class="search-suggest-empty">No page matches in this portal.</div>';
                        menu.style.display = 'block';
                        return;
                    }
                    menu.innerHTML = currentSuggestions.map((s, idx) => {
                        const meta = s.keywords.slice(0, 4).join(' • ');
                        return '<button type="button" class="search-suggest-item' + (idx === 0 ? ' active' : '') + '" data-href="' + s.href + '">' +
                            '<div class="search-suggest-title">' + highlightText(s.title, term) + '</div>' +
                            '<div class="search-suggest-meta">' + highlightText(meta, term) + '</div>' +
                            '</button>';
                    }).join('');
                    menu.style.display = 'block';
                };

                const navigateFirst = () => {
                    const first = currentSuggestions[0];
                    if (first && first.href) window.location.href = first.href;
                };

                searchInput.addEventListener('input', function () {
                    renderMenu(searchInput.value || '');
                });

                searchInput.addEventListener('focus', function () {
                    if (window.innerWidth <= 991) {
                        const header = document.getElementById('header');
                        if (header && !header.classList.contains('header-search-open')) return;
                    }
                    renderMenu(searchInput.value || '');
                });

                searchInput.addEventListener('keydown', function (e) {
                    if (e.key === 'Enter') {
                        e.preventDefault();
                        if (menu.style.display === 'none') renderMenu(searchInput.value || '');
                        navigateFirst();
                    }
                    if (e.key === 'Escape') {
                        menu.style.display = 'none';
                    }
                });

                menu.addEventListener('click', function (e) {
                    const btn = e.target.closest('.search-suggest-item');
                    if (!btn) return;
                    const href = btn.getAttribute('data-href');
                    if (href) window.location.href = href;
                });

                document.addEventListener('click', function (e) {
                    if (!wrap || !wrap.contains(e.target)) menu.style.display = 'none';
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