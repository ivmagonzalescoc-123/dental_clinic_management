<?php
include '../config/session_check.php';
check_session(['admin']);
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
    <link href="../assets/css/main.css" rel="stylesheet">
</head>

<body>

    <!-- ======= Header ======= -->
    <header id="header" class="header fixed-top d-flex align-items-center">
        <div class="d-flex align-items-center">
            <a href="admin.php?page=dashboard" class="logo d-flex align-items-center">
                <img src="../assets/img/main_logo.png" alt="">
                <div class="ms-2">
                    <span class="d-none d-lg-block fw-bold">Azucena's Dental Clinic</span>
                </div>
            </a>
        </div>

        <div class="header-search">
            <input type="text" placeholder="Search..." class="search-input" id="adminSearch" name="search">
            <button class="search-btn">Search</button>
        </div>

        <nav class="header-nav ms-auto">
            <ul class="d-flex align-items-center">
                <small class="d-none d-lg-block text-muted">Administrator</small>
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
                            <a class="dropdown-item d-flex align-items-center" href="admin.php?page=profile">
                         
                                <span>My Profile</span>
                            </a>
                        </li>
                        <li>
                            <hr class="dropdown-divider">
                        </li>
                        <li>
                            <a class="dropdown-item d-flex align-items-center" href="admin.php?page=settings">
                             
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
                <a class="nav-link collapsed" href="admin.php?page=dashboard">
                    <i class="bi bi-grid"></i>
                    <span>Dashboard</span>
                </a>
            </li>
            <li class="nav-item">
                <a class="nav-link collapsed" href="admin.php?page=patients">
                    <i class="bi bi-person-lines-fill"></i>
                    <span>Patients Records</span>
                </a>
            </li>
            <li class="nav-item">
                <a class="nav-link collapsed" href="admin.php?page=manage_users">
                    <i class="bi bi-person"></i>
                    <span>Manage Users</span>
                </a>
            </li>
            <li class="nav-item">
                <a class="nav-link collapsed" href="admin.php?page=appointments">
                    <i class="bi bi-calendar2-week"></i>
                    <span>Appointments</span>
                </a>
            </li>
            <li class="nav-item">
                <a class="nav-link collapsed" href="admin.php?page=queue">
                    <i class="bi bi-list-ol"></i>
                    <span>On Queue</span>
                </a>
            </li>
            <li class="nav-item">
                <a class="nav-link collapsed" href="admin.php?page=services">
                    <i class="bi bi-bag-heart"></i>
                    <span>Services</span>
                </a>
            </li>
            <li class="nav-item">
                <a class="nav-link collapsed" href="admin.php?page=reports">
                    <i class="bi bi-bar-chart-line"></i>
                    <span>Reports</span>
                </a>
            </li>
            <li class="nav-item">
                <a class="nav-link collapsed" href="admin.php?page=system_logs">
                    <i class="bi bi-file-text"></i>
                    <span>User Logs</span>
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
                                    include "admin_modules/dashboard.php";
                                    break;
                                case "patients":
                                    include "admin_modules/patients.php";
                                    break;
                                case "manage_users":
                                    include "admin_modules/manage_users.php";
                                    break;
                                case "appointments":
                                    include "admin_modules/appointments.php";
                                    break;
                                case "queue":
                                    include "admin_modules/queue.php";
                                    break;
                                case "services":
                                    include "admin_modules/services.php";
                                    break;
                                case "reports":
                                    include "admin_modules/reports.php";
                                    break;
                                case "system_logs":
                                    include "admin_modules/system_logs.php";
                                    break;
                                default:
                                    include "admin_modules/dashboard.php";
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