<?php
include_once '../config/session_check.php';
check_session(['secretary']);
// include database to show queue counts in sidebar (use include_once to avoid re-definitions)
include_once '../config/database.php';

// Handle AJAX API requests before HTML output
$page = $_GET['page'] ?? '';
$action = $_GET['action'] ?? '';

if ($page === 'billing' && $action === 'record_payment' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json');
    include 'secretary_modules/billing.php';
    exit;
}
// Handle appointments POST actions before any HTML output to avoid headers-sent warnings
if ($page === 'appointments' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    include 'secretary_modules/appointments.php';
    exit;
}

// Compute today's pending arrival count for sidebar badge
$today = date('Y-m-d');
$pendingCount = 0;
try {
    if (function_exists('table_exists') && table_exists($conn, 'patient_arrivals')) {
        $stmt = $conn->prepare(
            "SELECT COUNT(*) AS c FROM patient_arrivals pa
             INNER JOIN appointments a ON a.appointment_id = pa.appointment_id
             WHERE a.appointment_date = ? AND pa.status = 'pending'"
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
    }
} catch (Throwable $e) {
    error_log('Error computing pendingCount: ' . $e->getMessage());
    $pendingCount = 0;
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
            <a href="secretary.php?page=dashboard" class="logo d-flex align-items-center">
                <img src="../assets/img/main_logo.png" alt="">
                <div class="ms-2">
                    <span class="d-none d-lg-block fw-bold">Dental Clinic</span>
                    <small class="d-none d-lg-block text-muted">Secretary</small>
                </div>
            </a>
            <button class="toggle-sidebar-btn ms-2" type="button" aria-label="Toggle sidebar">
                <i class="bi bi-list"></i>
            </button>
        </div>

        <div class="header-search">
            <input type="text" placeholder="Search..." class="search-input" id="secretarySearch" name="search">
            <button class="search-btn">Search</button>
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
                    </a>
                    <ul class="dropdown-menu dropdown-menu-end dropdown-menu-arrow profile">
                
                        <li>
                            <a class="dropdown-item d-flex align-items-center" href="secretary.php?page=profile">
                         
                                <span>My Profile</span>
                            </a>
                        </li>
                        <li>
                            <hr class="dropdown-divider">
                        </li>
                        <li>
                            <a class="dropdown-item d-flex align-items-center" href="secretary.php?page=settings">
                             
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
                <a class="nav-link collapsed" href="secretary.php?page=dashboard">
                    <i class="bi bi-grid"></i>
                    <span>Dashboard</span>
                </a>
            </li>
            <li class="nav-item">
                <a class="nav-link collapsed" href="secretary.php?page=manage_patients">
                    <i class="bi bi-person"></i>
                    <span>Patient Management</span>
                </a>
            </li>
            <li class="nav-item">
                <a class="nav-link collapsed" href="secretary.php?page=appointments">
                    <i class="bi bi-calendar2-week"></i>
                    <span>Appointments</span>
                </a>
            </li>
            <li class="nav-item">
                <a class="nav-link collapsed" href="secretary.php?page=scheduling">
                    <i class="bi bi-calendar-range"></i>
                    <span>Scheduling</span>
                </a>
            </li>
            <li class="nav-item">
                <a class="nav-link collapsed d-flex align-items-center" href="secretary.php?page=arrivals">
                    <i class="bi bi-person-check"></i>
                    <span class="ms-2">Arrivals</span>
                    <span class="badge bg-warning ms-auto" id="secretaryArrivalsBadge"><?php echo (int)$pendingCount; ?></span>
                </a>
            </li>
            <li class="nav-item">
                <a class="nav-link collapsed" href="secretary.php?page=billing">
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
                    <div class="card">
                        <div class="card-body">
                            <!-- Dynamic Content Area -->
                            <?php
                            $page = isset($_GET['page']) && $_GET['page'] !== '' ? $_GET['page'] : 'dashboard';
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
                                    case "billing":
                                        include "secretary_modules/billing.php";
                                        break;
                                    case "arrivals":
                                        include "secretary_modules/arrivals.php";
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

</body>

</html>