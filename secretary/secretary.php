<?php
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
    <link href="../assets/css/main.css?v=20260715-header1" rel="stylesheet">
    <link href="../assets/css/app-toast.css?v=20260614-short" rel="stylesheet">
    <link href="../assets/css/portal-header-mobile.css?v=20260715-header1" rel="stylesheet">
    <link href="../assets/css/module-content-ux.css?v=20260614-section-nav1" rel="stylesheet">
</head>

<body class="secretary-portal">

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
                <div class="ms-2">
                    <span class="fw-bold">Dental Clinic</span>
                    <small class="d-none d-lg-block text-muted"><?php echo htmlspecialchars($header_role_label); ?></small>
                </div>
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
                <i class="bi bi-list toggle-icon-open"></i>
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
                <a class="nav-link collapsed" href="secretary.php?page=manage_patients" data-tooltip="Patient Management" title="Patient Management">
                    <i class="bi bi-person"></i>
                    <span>Patient Management</span>
                </a>
            </li>
            <li class="nav-item">
                <a class="nav-link collapsed d-flex align-items-center" href="secretary.php?page=appointments" data-tooltip="Appointments" title="Appointments">
                    <i class="bi bi-calendar2-week"></i>
                    <span class="ms-2">Appointments</span>
                    <span class="nav-dot" id="secretaryAppointmentsDot"></span>
                </a>
            </li>
            <li class="nav-item">
                <a class="nav-link collapsed" href="secretary.php?page=doctor_schedule" data-tooltip="Doctor Schedule" title="Doctor Schedule">
                    <i class="bi bi-calendar2-check"></i>
                    <span>Doctor Schedule</span>
                </a>
            </li>
            <li class="nav-item">
                <a class="nav-link collapsed d-flex align-items-center" href="secretary.php?page=arrivals" data-tooltip="Arrivals" title="Arrivals">
                    <i class="bi bi-person-check"></i>
                    <span class="ms-2">Arrivals</span>
                    <span class="nav-dot<?php echo (int)$pendingCount > 0 ? ' is-active' : ''; ?>" id="secretaryArrivalsDot"></span>
                </a>
            </li>
            <li class="nav-item">
                <a class="nav-link collapsed d-flex align-items-center" href="secretary.php?page=ehr_queue" data-tooltip="EHR Queue" title="EHR Queue">
                    <i class="bi bi-journal-medical"></i>
                    <span class="ms-2">EHR Queue</span>
                    <span class="nav-dot<?php echo (int)$recordsQueueCount > 0 ? ' is-active' : ''; ?>" id="secretaryEhrDot"></span>
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
                <a class="nav-link collapsed d-flex align-items-center" href="secretary.php?page=contact_messages" data-tooltip="Contact Us" title="Contact Us">
                    <i class="bi bi-envelope"></i>
                    <span class="ms-2">Contact Us</span>
                    <span class="nav-dot" id="secretaryContactDot"></span>
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
                    <?php if (!$dashboardLayout && $page !== 'manage_patients'): ?><div class="card"><div class="card-body"><?php endif; ?>
                            <!-- Dynamic Content Area -->
                            <?php
                            try {
                                switch ($page) {
                                    case "dashboard":
                                        include "secretary_modules/dashboard.php";
                                        break;
                                    case "manage_patients":
                                        include "secretary_modules/manage_patients.php";
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
                    <?php if (!$dashboardLayout && $page !== 'manage_patients'): ?></div></div><?php endif; ?>
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
    <script src="../assets/js/idempotency.js?v=20260606-idem"></script>
    <script src="../assets/js/app-toast.js?v=20260614-short"></script>
    <script src="../assets/js/dental-cache.js"></script>
    <script src="../assets/js/dental-ui.js"></script>
    <script src="../assets/js/app-request.js?v=20260613"></script>
    <script src="../assets/js/app-confirm.js?v=20260611-confirm"></script>
    <script src="../assets/js/password-toggle.js?v=20260614-pw-no-lock"></script>
    <script>window.__pageLoadStart = Date.now();</script>
    <script src="../assets/js/main.js?v=20260715-header2"></script>
    <script src="../assets/js/portal-dropdown.js?v=20260614-notify"></script>
    <script src="../assets/js/profiling-display.js?v=20260606-hiv"></script>
    <script src="../assets/js/clinical-assessment-fields.js?v=20260606-clinical"></script>
    <script src="../assets/js/media-gallery.js?v=20260606-media"></script>
    <script src="../assets/js/appointment-service-manager.js"></script>

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

            const searchInput = document.getElementById('secretarySearch');

            if (searchInput) {
                const portalBaseFile = 'secretary.php';
                const pageKeywordMap = {
                    dashboard: ['home', 'overview', 'summary'],
                    appointments: ['booking', 'calendar', 'schedule', 'follow-up', 'followup'],
                    patients: ['patient list', 'records', 'profiles'],
                    doctors: ['doctor list', 'dentist', 'providers'],
                    services: ['procedures', 'pricing', 'clinic services'],
                    billing: ['payments', 'transactions', 'invoice'],
                    contact_messages: ['contact', 'inquiry', 'message', 'email'],
                    reports: ['analytics', 'charts', 'insights'],
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

        // Secretary idle timeout is disabled.
    </script>

</body>

</html>