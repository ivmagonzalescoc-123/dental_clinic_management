<?php
include_once '../config/session_check.php';
check_session(['patient']);
$csrf_token = security_generate_csrf_token();

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
    <link href="../assets/img/main_logo.png" rel="icon">
    <link href="../assets/img/apple-touch-icon.png" rel="apple-touch-icon">

    <!-- Google Fonts -->
    <link href="https://fonts.gstatic.com" rel="preconnect">
    <link
        href="https://fonts.googleapis.com/css?family=Mothwing:300,300i,400,400i,600,600i,700,700i"
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
    <link href="../assets/css/main.css?v=20260328-theme7" rel="stylesheet">
    <link href="../assets/css/module-content-ux.css?v=20260328-ux4" rel="stylesheet">
</head>

<body>

    <!-- ======= Header ======= -->
    <header id="header" class="header fixed-top d-flex align-items-center">
        <div class="d-flex align-items-center">
            <a href="patient.php?page=dashboard" class="logo d-flex align-items-center">
                <img src="../assets/img/main_logo.png" alt="">
                <div class="ms-2">
                    <span class="d-none d-lg-block fw-bold">Dental Clinic</span>
                    <small class="d-none d-lg-block text-muted"><?php echo htmlspecialchars($header_role_label); ?></small>
                </div>
            </a>
            <button class="toggle-sidebar-btn ms-2" type="button" aria-label="Toggle sidebar">
                <i class="bi bi-list"></i>
            </button>
        </div>

        <div class="header-search">
            <div class="search-input-wrap">
                <input type="text" placeholder="Search..." class="search-input" id="patientSearch" name="search">
                <i class="bi bi-search search-input-icon" aria-hidden="true"></i>
            </div>
        </div>

        <nav class="header-nav ms-auto">
            <ul class="d-flex align-items-center">
                <li class="nav-item d-block d-lg-none">
                    <a class="nav-link nav-icon search-bar-toggle" href="#">
                        <i class="bi bi-search"></i>
                    </a>
                </li>

                

                <li class="nav-item dropdown pe-3">
                    <a class="nav-link nav-profile d-flex align-items-center pe-0" href="#" data-bs-toggle="dropdown">
                        <img src="../assets/img/profile.png" alt="Profile" class="rounded-circle">
                        <span class="d-none d-lg-block user-display-name"><?php echo htmlspecialchars($header_display_name); ?></span>
                    </a>
                    <ul class="dropdown-menu dropdown-menu-end dropdown-menu-arrow profile">
                
                        <li>
                            <a class="dropdown-item d-flex align-items-center" href="patient.php?page=profile">
                         
                                <span>My Profile</span>
                            </a>
                        </li>
                        <li>
                            <hr class="dropdown-divider">
                        </li>
                        <li>
                            <a class="dropdown-item d-flex align-items-center" href="patient.php?page=settings">
                             
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
                <a class="nav-link collapsed" href="patient.php?page=dashboard">
                    <i class="bi bi-grid"></i>
                    <span>Dashboard</span>
                </a>
            </li>
            <li class="nav-item">
                <a class="nav-link collapsed" href="patient.php?page=appointments">
                    <i class="bi bi-calendar2-week"></i>
                    <span>Appointments</span>
                </a>
            </li>
            <li class="nav-item">
                <a class="nav-link collapsed" href="patient.php?page=services">
                    <i class="bi bi-receipt"></i>
                    <span>Services</span>
                </a>
            </li>
            <li class="nav-item">
                <a class="nav-link collapsed" href="patient.php?page=transactions">
                    <i class="bi bi-cart"></i>
                    <span>Transactions</span>
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
                                    include "patient_modules/dashboard.php";
                                    break;
                                
                                case "appointments":
                                    include "patient_modules/appointments.php";
                                    break;
                                case "services":
                                    include "patient_modules/services.php";
                                    break;
                                case "transactions":
                                    include "patient_modules/transactions.php";
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
    <script src="../assets/js/main.js?v=20260316-2"></script>
    <script src="../assets/js/session-idle.js"></script>

    <!-- Active Navigation Highlight -->
    <script>
        const patientCsrfToken = <?php echo json_encode($csrf_token); ?>;
        const mustChangePassword = <?php echo !empty($_SESSION['must_change_password']) ? 'true' : 'false'; ?>;

        document.addEventListener('DOMContentLoaded', function() {
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

            if (mustChangePassword && !window.location.search.includes('page=settings')) {
                window.location.href = 'patient.php?page=settings&force_password_change=1';
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