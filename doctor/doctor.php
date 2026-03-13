<?php
include_once '../config/session_check.php';
check_session(['doctor']);
$csrf_token = security_generate_csrf_token();

// Get today's arrived (pending) patient count for this doctor (for sidebar badge)
include_once '../config/database.php';
$doctor_today_count = 0;
$doctor_id = $_SESSION['user_id'] ?? 0;
if ($doctor_id && $conn) {
    $today_for_count = date('Y-m-d');
    $qc_stmt = $conn->prepare(
        "SELECT COUNT(*) AS c FROM patient_arrivals pa
         INNER JOIN appointments a ON a.appointment_id = pa.appointment_id
         WHERE a.user_id = ? AND a.appointment_date = ?
           AND pa.status IN ('pending','arrived','late')"
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
    <link href="../assets/css/module-content-ux.css" rel="stylesheet">
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
                <a class="nav-link collapsed" href="doctor.php?page=leave_requests">
                    <i class="bi bi-calendar-x"></i>
                    <span>Leave Requests</span>
                </a>
            </li>
            <li class="nav-item">
                <a class="nav-link collapsed" href="doctor.php?page=patients">
                    <i class="bi bi-person-lines-fill"></i>
                    <span>Patients</span>
                </a>
            </li>
            <li class="nav-item">
                <a class="nav-link collapsed" href="doctor.php?page=arrivals">
                    <i class="bi bi-person-check"></i>
                    <span>My Patients Today</span>
                    <span id="doctorArrivalsBadge" class="badge bg-success ms-auto"
                        style="margin-left:8px;"><?php echo (int)$doctor_today_count; ?></span>
                </a>
            </li>

            <li class="nav-item">
                <a class="nav-link collapsed" href="doctor.php?page=ehr">
                    <i class="bi bi-journal-medical"></i>
                    <span>Session</span>
                </a>
            </li>
            <!-- Session link removed from sidebar (access via direct URL remains) -->

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
                                case "patients":
                                    include "doctor_modules/patients.php";
                                    break;
                                case "patient_schedule":
                                    include "doctor_modules/patient_schedule.php";
                                    break;
                                case "leave_requests":
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
    <script src="../assets/js/session-idle.js"></script>

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
            el.className = 'alert alert-' + (type || 'info');
            el.role = 'alert';
            el.style.minWidth = '200px';
            el.innerHTML = message;
            container.appendChild(el);
            setTimeout(() => { try { el.remove(); } catch (e) { } }, 4000);
        }
    </script>

</body>

</html>