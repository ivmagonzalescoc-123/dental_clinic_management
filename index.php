<?php
require_once 'config/security.php';
security_start_session();
require_once 'config/database.php';
$csrf_token = security_generate_csrf_token();

function build_login_captcha_question(): string
{
    $left = random_int(2, 12);
    $right = random_int(1, 9);
    $useSubtraction = (bool)random_int(0, 1);
    if ($useSubtraction && $right > $left) {
        $tmp = $left;
        $left = $right;
        $right = $tmp;
    }

    $operator = $useSubtraction ? '-' : '+';
    $answer = $useSubtraction ? ($left - $right) : ($left + $right);

    $_SESSION['login_captcha_answer'] = (string)$answer;
    $_SESSION['login_captcha_expires_at'] = time() + 300;

    return $left . ' ' . $operator . ' ' . $right;
}

// Redirect logged-in users to their respective dashboard
if (isset($_SESSION['user_id']) || isset($_SESSION['patient_id'])) {
    $role = $_SESSION['role'] ?? '';
    
    if ($role === 'admin') {
        header('Location: admin/admin.php');
        exit;
    } elseif ($role === 'doctor') {
        header('Location: doctor/doctor.php');
        exit;
    } elseif ($role === 'secretary') {
        header('Location: secretary/secretary.php');
        exit;
    } elseif ($role === 'patient') {
        header('Location: patient/patient.php');
        exit;
    }
}

$login_captcha_question = build_login_captcha_question();
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Azucena's Dental Clinic</title>
    <link href="assets/vendor/bootstrap/css/bootstrap.min.css" rel="stylesheet">
    <link href="assets/vendor/bootstrap-icons/bootstrap-icons.css" rel="stylesheet">
    <link href="assets/css/landing-style.css" rel="stylesheet">
</head>

<body>
    <!-- NAVBAR -->
    <nav class="navbar navbar-expand-lg navbar-minimal sticky-top">
        <div class="container-fluid px-4">
            <a class="navbar-brand" href="index.php">
                <img src="assets/img/main_logo.png" alt="Azucena Dental Logo" class="navbar-logo">
                Azucena Dental Clinic
            </a>
            <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#navbarNav">
                <span class="navbar-toggler-icon"></span>
            </button>
            <div class="collapse navbar-collapse" id="navbarNav">
                <ul class="navbar-nav ms-auto">
                    <li class="nav-item">
                        <a class="nav-link" href="#home">Home</a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" href="#services">Services</a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" href="#about">About</a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" href="#contact">Contact</a>
                    </li>
                    <li class="nav-item">
                        <button class="btn btn-sm btn-login" data-bs-toggle="modal" data-bs-target="#loginModal">
                            Login
                        </button>
                    </li>
                </ul>
            </div>
        </div>
    </nav>

    <!-- HERO SECTION -->
    <section class="hero-minimal" id="home">
        <div class="container">
            <div class="row align-items-center g-4">
                <!-- Left Content -->
                <div class="col-lg-6">
                    <h1>Transform Your Smile!</h1>
                    <p class="lead">Experience compassionate, state-of-the-art dental treatment in a comfortable
                        environment. Your oral health is our priority.</p>

                    <!-- Trust Indicators -->
                    <div class="trust-indicators mb-4">
                        <div class="trust-item">
                            <i class="bi bi-check-circle-fill"></i>
                            <span>Active since 2023 with proven expertise</span>
                        </div>
                        <div class="trust-item">
                            <i class="bi bi-check-circle-fill"></i>
                            <span>Advanced technology & techniques</span>
                        </div>
                        <div class="trust-item">
                            <i class="bi bi-check-circle-fill"></i>
                            <span>Gentle, patient-focused care</span>
                        </div>
                    </div>

                    <div class="mt-4">
                        <button class="btn btn-secondary-minimal" data-bs-toggle="modal"
                            data-bs-target="#registerModal">
                            <i class="bi bi-person-plus"></i>Create Account
                        </button>
                    </div>
                </div>

                <!-- Right Carousel -->
                <div class="col-lg-6">
                    <div class="custom-carousel">
                        <div class="carousel-wrapper">
                            <div class="carousel-slide active">
                                <img src="assets/img/tooth4.png" alt="Clinic">
                            </div>
                            <div class="carousel-slide">
                                <img src="assets/img/tooth1.jpg" alt="Dental Care">
                            </div>
                            <div class="carousel-slide">
                                <img src="assets/img/tooth3.png" alt="Clinic">
                            </div>
                        </div>
                        <button class="carousel-btn prev" onclick="prevSlide()">
                            <i class="bi bi-chevron-left"></i>
                        </button>
                        <button class="carousel-btn next" onclick="nextSlide()">
                            <i class="bi bi-chevron-right"></i>
                        </button>
                        <div class="carousel-dots">
                            <span class="dot active" onclick="currentSlide(0)"></span>
                            <span class="dot" onclick="currentSlide(1)"></span>
                            <span class="dot" onclick="currentSlide(2)"></span>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </section>

    <!-- SERVICES SECTION -->
    <section class="services-minimal" id="services">
        <div class="container">
            <h2 class="text-center mb-5 reveal">Our Services</h2>
            <div class="row g-4">
                <div class="col-md-6 col-lg-3">
                    <div class="service-card-minimal reveal">
                        <i class="bi bi-hospital"></i>
                        <h5>General Dentistry</h5>
                        <p>Checkups & cleanings</p>
                    </div>
                </div>
                <div class="col-md-6 col-lg-3">
                    <div class="service-card-minimal reveal">
                        <i class="bi bi-brightness-high"></i>
                        <h5>Cosmetic Dentistry</h5>
                        <p>Whitening & veneers</p>
                    </div>
                </div>
                <div class="col-md-6 col-lg-3">
                    <div class="service-card-minimal reveal">
                        <i class="bi bi-wrench"></i>
                        <h5>Restorative Care</h5>
                        <p>Crowns & implants</p>
                    </div>
                </div>
                <div class="col-md-6 col-lg-3">
                    <div class="service-card-minimal reveal">
                        <i class="bi bi-scissors"></i>
                        <h5>Orthodontics</h5>
                        <p>Braces & aligners</p>
                    </div>
                </div>
            </div>
        </div>
    </section>

    <!-- ABOUT SECTION -->
    <section class="about-minimal" id="about">
        <div class="container">
            <div class="row g-5">
                <div class="col-lg-6 reveal-left">
                    <h3>About Us</h3>
                    <p>Azucena Dental Clinic has been actively serving our community since 2023. With a dedicated team
                        of 2 experienced doctors and modern technology, we focus on delivering exceptional dental care
                        with compassion and professionalism. Every patient deserves the best treatment possible.</p>
                    <ul class="about-list">
                        <li><i class="bi bi-check-circle"></i> Dedicated team of 2 experienced doctors</li>
                        <li><i class="bi bi-check-circle"></i> Latest dental technology & equipment</li>
                        <li><i class="bi bi-check-circle"></i> Comfortable, modern clinic environment</li>
                        <li><i class="bi bi-check-circle"></i> Compassionate patient care</li>
                    </ul>
                </div>
                <div class="col-lg-6 reveal-right">
                    <div class="map-container">
                        <iframe
                            src="https://www.google.com/maps/embed?pb=!1m18!1m12!1m3!1d3926.018!2d124.6480!3d8.48722!2m3!1f0!2f0!3f0!3m2!1i1024!2i768!4f13.1!3m3!1m2!1s0x32ffc9c9c9c9c9c9%3A0x9c9c9c9c9c9c9c9c!2sMetz%20Arcade%2C%20Capt.%20Vicente%20Roa%20St%2C%20Cagayan%20de%20Oro!5e0!3m2!1sen!2sph!4v1674123456789"
                            width="100%" height="350" style="border:0; border-radius: 8px;" allowfullscreen=""
                            loading="lazy" referrerpolicy="no-referrer-when-downgrade"></iframe>
                    </div>
                </div>
            </div>
        </div>
    </section>

    <!-- CONTACT SECTION -->
    <section class="contact-section" id="contact">
        <div class="container">
            <h2 class="text-center mb-5 reveal">Contact Us</h2>
            <div class="row g-4">
                <div class="col-md-4">
                    <div class="contact-card reveal">
                        <i class="bi bi-telephone"></i>
                        <h5>Phone</h5>
                        <p>0917 984 3031</p>
                    </div>
                </div>
                <div class="col-md-4">
                    <div class="contact-card reveal">
                        <i class="bi bi-envelope"></i>
                        <h5>Email</h5>
                        <p>azucena@gmail.com</p>
                    </div>
                </div>
                <div class="col-md-4">
                    <div class="contact-card reveal">
                        <i class="bi bi-geo-alt"></i>
                        <h5>Location</h5>
                        <p>Metz Arcade, Cagayan de Oro, 9000</p>
                    </div>
                </div>
            </div>
        </div>
    </section>

    <!-- FOOTER -->
    <footer class="footer-minimal">
        <div class="container">
            <p>&copy; 2026 Azucena Dental Clinic. All rights reserved.</p>
        </div>
    </footer>

    <!-- LOGIN MODAL -->
    <div class="modal fade" id="loginModal" tabindex="-1">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-header border-0">
                    <h2 class="modal-title">Login</h2>
                </div>
                <div class="modal-body login-body">
                    <form id="loginForm">
                        <div class="mb-3">
                            <div class="form-floating">
                                <input type="email" class="form-control" id="loginEmail"
                                    placeholder="Email Address" required>
                                <label for="loginEmail">Email Address</label>
                            </div>
                        </div>
                        <div class="mb-3">
                            <div class="form-floating">
                                <input type="password" class="form-control" id="loginPassword"
                                    placeholder="Password" required>
                                <label for="loginPassword">Password</label>
                            </div>
                        </div>
                        <div class="mb-3">
                            <label class="form-label fw-semibold">Captcha</label>
                            <div class="d-flex align-items-center gap-2 mb-2">
                                <span id="loginCaptchaQuestion" class="badge bg-light text-dark border px-3 py-2"><?php echo htmlspecialchars($login_captcha_question); ?></span>
                                <button type="button" class="btn btn-sm btn-outline-secondary" id="refreshLoginCaptchaBtn" title="Refresh captcha">
                                    <i class="bi bi-arrow-clockwise"></i>
                                </button>
                            </div>
                            <input type="text" class="form-control" id="loginCaptchaAnswer" placeholder="Enter your answer" inputmode="numeric" pattern="-?[0-9]+" required>
                        </div>
                        <div class="d-flex justify-content-center">
                            <button type="submit" class="btn btn-primary-minimal btn-compact">Login</button>
                        </div>
                    </form>
                    <div class="mt-4 text-center">
                        <p class="mb-2" style="color: #1e293b; font-size: 0.9rem;">Don't have an account?</p>
                        <a href="#" data-bs-dismiss="modal" data-bs-toggle="modal" data-bs-target="#registerModal"
                            style="font-weight: 700; color: #2563eb; text-decoration: none;">Create Account</a>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- REGISTER MODAL -->
    <div class="modal fade" id="registerModal" tabindex="-1">
        <div class="modal-dialog modal-dialog-centered modal-lg">
            <div class="modal-content">
                <div class="modal-header border-0">
                    <h2 class="modal-title">Create Account</h2>
                </div>
                <div class="modal-body" style="background: #f9fafb;">
                    <form id="registerForm" style="background: white; padding: 1.5rem; border-radius: 12px;">
                        <div class="row g-3">
                            <div class="col-md-6">
                                <div class="form-floating">
                                    <input type="text" class="form-control" id="registerFirstName"
                                        placeholder="First Name" required>
                                    <label for="registerFirstName">First Name</label>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="form-floating">
                                    <input type="text" class="form-control" id="registerLastName"
                                        placeholder="Last Name" required>
                                    <label for="registerLastName">Last Name</label>
                                </div>
                            </div>
                        </div>
                        <div class="mb-3">
                            <div class="form-floating">
                                <input type="email" class="form-control" id="registerEmail" placeholder="Email Address"
                                    required>
                                <label for="registerEmail">Email Address</label>
                            </div>
                        </div>
                        <div class="row g-3">
                            <div class="col-md-6">
                                <div class="form-floating">
                                    <input type="date" class="form-control" id="registerDOB" placeholder="Date of Birth"
                                        required>
                                    <label for="registerDOB">Date of Birth</label>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="form-floating">
                                    <select class="form-control" id="registerGender" name="gender" required>
                                        <option value="">Select</option>
                                        <option value="Male">Male</option>
                                        <option value="Female">Female</option>
                                        <option value="Other">Other</option>
                                    </select>
                                    <label for="registerGender">Gender</label>
                                </div>
                            </div>
                        </div>
                        <div class="mb-3">
                            <div class="form-floating">
                                <input type="tel" class="form-control" id="registerPhone" placeholder="Phone Number"
                                    required>
                                <label for="registerPhone">Phone Number</label>
                            </div>
                        </div>
                        <div class="mb-3">
                            <div class="form-floating">
                                <input type="text" class="form-control" id="registerAddress" placeholder="Address"
                                    required>
                                <label for="registerAddress">Address</label>
                            </div>
                        </div>
                        <div class="alert alert-info small" role="alert">
                            Your default password will be generated automatically using this format: <br> <strong>Lastname_Last4digitphonenumber (Example: Smith_1234)</strong>. <br>
                            You will be required to change it on first login.
                        </div>
                        <div class="form-check mt-3">
                            <input class="form-check-input border-black" type="checkbox" id="registerTerms" required>
                            <label class="form-check-label" for="registerTerms">
                                I agree to the
                                <a href="#" data-bs-toggle="modal" data-bs-target="#termsModal">Terms and Conditions</a>
                            </label>
                        </div>
                        <div class="d-flex justify-content-center mt-3">
                            <button type="submit" class="btn btn-primary-minimal btn-compact">Create Account</button>
                        </div>
                    </form>
                    <hr>
                    <p class="text-center small">Already have an account? <a href="#" data-bs-dismiss="modal"
                            data-bs-toggle="modal" data-bs-target="#loginModal">Login here</a></p>
                </div>
            </div>
        </div>
    </div>

    <div class="modal fade" id="termsModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-dialog-scrollable">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Terms and Conditions</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <p>By creating an account, you agree to provide truthful and updated personal and medical information for dental care services.</p>
                    <p>You acknowledge that your appointment requests are subject to clinic approval, schedule availability, and standard clinic policies.</p>
                    <p>You consent to the secure processing of your account and health-related data for treatment, scheduling, reminders, and billing purposes.</p>
                    <p>You are responsible for safeguarding your account credentials and for notifying the clinic immediately of unauthorized account access.</p>
                    <p>The clinic may update these terms to comply with legal, operational, or patient safety requirements, with updates reflected in this system.</p>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-primary" data-bs-dismiss="modal">I Understand</button>
                </div>
            </div>
        </div>
    </div>

    <div class="toast-container position-fixed top-50 start-50 translate-middle p-3" style="z-index: 2000;">
        <div id="authCenterToast" class="toast align-items-center text-white border-0" role="alert" aria-live="assertive" aria-atomic="true">
            <div class="d-flex">
                <div class="toast-body" id="authCenterToastBody"></div>
            </div>
        </div>
    </div>

    <script src="assets/vendor/bootstrap/js/bootstrap.bundle.min.js"></script>
    <script>
        const csrfToken = <?php echo json_encode($csrf_token); ?>;

        function setLoginCaptchaQuestion(question) {
            const questionEl = document.getElementById('loginCaptchaQuestion');
            if (questionEl) {
                questionEl.textContent = question || 'Unavailable';
            }
        }

        async function refreshLoginCaptcha() {
            const answerInput = document.getElementById('loginCaptchaAnswer');
            try {
                const response = await fetch('assets/api/auth/get_login_captcha.php', {
                    method: 'GET',
                    cache: 'no-store'
                });
                const data = await response.json();
                if (data.success) {
                    setLoginCaptchaQuestion(data.question || '');
                    if (answerInput) answerInput.value = '';
                    return;
                }
            } catch (error) {
                console.error('Captcha refresh failed:', error);
            }

            setLoginCaptchaQuestion('Refresh page to generate captcha');
            if (answerInput) answerInput.value = '';
        }

        function escapeHtml(text) {
            const map = { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#039;' };
            return String(text || '').replace(/[&<>"']/g, m => map[m]);
        }

        function showCenterToast(message, type = 'danger', delay = 2600) {
            const toastEl = document.getElementById('authCenterToast');
            const bodyEl = document.getElementById('authCenterToastBody');
            if (!toastEl || !bodyEl || !window.bootstrap) {
                alert(message);
                return;
            }

            toastEl.classList.remove('bg-success', 'bg-danger', 'bg-warning', 'bg-info', 'text-dark');

            if (type === 'success') {
                toastEl.classList.add('bg-success');
            } else if (type === 'warning') {
                toastEl.classList.add('bg-warning', 'text-dark');
            } else if (type === 'info') {
                toastEl.classList.add('bg-info', 'text-dark');
            } else {
                toastEl.classList.add('bg-danger');
            }

            bodyEl.textContent = message;
            const toast = bootstrap.Toast.getOrCreateInstance(toastEl, { delay: delay });
            toast.show();
        }

        const registerModalEl = document.getElementById('registerModal');
        const termsModalEl = document.getElementById('termsModal');
        let reopenRegisterModalAfterTerms = false;

        if (registerModalEl && termsModalEl && window.bootstrap) {
            termsModalEl.addEventListener('show.bs.modal', () => {
                reopenRegisterModalAfterTerms = registerModalEl.classList.contains('show');
            });

            termsModalEl.addEventListener('hidden.bs.modal', () => {
                if (reopenRegisterModalAfterTerms) {
                    bootstrap.Modal.getOrCreateInstance(registerModalEl).show();
                }
                reopenRegisterModalAfterTerms = false;
            });
        }

        // Login Form Handler
        document.getElementById('loginForm').addEventListener('submit', async (e) => {
            e.preventDefault();
            const loginToastDelay = 2000;
            const email = document.getElementById('loginEmail').value;
            const password = document.getElementById('loginPassword').value;
            const captchaAnswer = document.getElementById('loginCaptchaAnswer').value.trim();

            if (!captchaAnswer) {
                showCenterToast('Please solve the captcha before logging in.', 'warning', loginToastDelay);
                return;
            }

            try {
                const response = await fetch('assets/api/auth/login_api.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ email, password, captcha_answer: captchaAnswer, csrf_token: csrfToken })
                });

                const data = await response.json();

                if (data.success) {
                    showCenterToast('Login successful! Redirecting...', 'success', loginToastDelay);

                    let redirectUrl = 'index.php';
                    const role = data.user.role;
                    const force = !!data.must_change_password;

                    if (role === 'admin') {
                        redirectUrl = force ? 'admin/admin.php?page=settings&force_password_change=1' : 'admin/admin.php';
                    } else if (role === 'doctor') {
                        redirectUrl = force ? 'doctor/doctor.php?page=settings&force_password_change=1' : 'doctor/doctor.php';
                    } else if (role === 'secretary') {
                        redirectUrl = force ? 'secretary/secretary.php?page=settings&force_password_change=1' : 'secretary/secretary.php';
                    } else if (role === 'patient') {
                        redirectUrl = force ? 'patient/patient.php?page=settings&force_password_change=1' : 'patient/patient.php';
                    }

                    setTimeout(() => {
                        window.location.href = redirectUrl;
                    }, loginToastDelay + 100);
                } else {
                    if (data.captcha && data.captcha.question) {
                        setLoginCaptchaQuestion(data.captcha.question);
                        const ans = document.getElementById('loginCaptchaAnswer');
                        if (ans) ans.value = '';
                    } else {
                        await refreshLoginCaptcha();
                    }
                    showCenterToast(data.message || 'Login failed', 'danger', loginToastDelay);
                }
            } catch (error) {
                await refreshLoginCaptcha();
                showCenterToast('An error occurred. Please try again.', 'danger', loginToastDelay);
                console.error('Error:', error);
            }
        });

        const refreshLoginCaptchaBtn = document.getElementById('refreshLoginCaptchaBtn');
        if (refreshLoginCaptchaBtn) {
            refreshLoginCaptchaBtn.addEventListener('click', () => {
                refreshLoginCaptcha();
            });
        }

        // Register Form Handler
        document.getElementById('registerForm').addEventListener('submit', async (e) => {
            e.preventDefault();
            const firstName = document.getElementById('registerFirstName').value;
            const lastName = document.getElementById('registerLastName').value;
            const email = document.getElementById('registerEmail').value;
            const dob = document.getElementById('registerDOB').value;
            const gender = document.getElementById('registerGender').value;
            const phone = document.getElementById('registerPhone').value;
            const address = document.getElementById('registerAddress').value;
            const termsAccepted = document.getElementById('registerTerms').checked;
            const phoneDigits = phone.replace(/\D/g, '');

            if (phoneDigits.length < 4) {
                showCenterToast('Phone number must contain at least 4 digits.', 'warning');
                return;
            }

            if (!termsAccepted) {
                showCenterToast('Please accept the Terms and Conditions to create an account.', 'warning');
                return;
            }

            try {
                const response = await fetch('assets/api/auth/register_api.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({
                        first_name: firstName,
                        last_name: lastName,
                        email,
                        date_of_birth: dob,
                        gender: gender,
                        phone_number: phone,
                        address: address,
                        role: 'patient',
                        terms_accepted: termsAccepted ? 1 : 0,
                        csrf_token: csrfToken
                    })
                });

                const data = await response.json();

                if (data.success) {
                    showCenterToast('Account created. Please change your default password to continue.', 'success', 2000);
                    setTimeout(() => {
                        window.location.href = 'patient/patient.php?page=settings&force_password_change=1';
                    }, 1800);
                } else {
                    showCenterToast(data.message || 'Registration failed', 'danger');
                }
            } catch (error) {
                showCenterToast('An error occurred. Please try again.', 'danger');
                console.error('Error:', error);
            }
        });

        // Scroll Reveal Animation using Intersection Observer (Repeatable)
        const observerOptions = {
            threshold: 0.1,
            rootMargin: '0px 0px -100px 0px'
        };

        const observer = new IntersectionObserver((entries) => {
            entries.forEach(entry => {
                if (entry.isIntersecting) {
                    entry.target.classList.add('active');
                } else {
                    entry.target.classList.remove('active');
                }
            });
        }, observerOptions);

        // Observe all reveal elements
        document.querySelectorAll('.reveal, .reveal-fade, .reveal-left, .reveal-right, .reveal-scale').forEach(element => {
            observer.observe(element);
        });

        // Auto-navigate to home on page reload
        window.addEventListener('load', () => {
            window.location.hash = '#home';
            setTimeout(() => {
                document.getElementById('home').scrollIntoView({ behavior: 'smooth' });
            }, 100);
        });

        // Smooth scrolling for anchor links
        document.querySelectorAll('a[href^="#"]').forEach(anchor => {
            anchor.addEventListener('click', function (e) {
                const href = this.getAttribute('href');
                if (href !== '#') {
                    e.preventDefault();
                    const target = document.querySelector(href);
                    if (target) {
                        target.scrollIntoView({ behavior: 'smooth' });
                    }
                }
            });
        });

        // Custom Carousel Functions
        let currentIndex = 0;
        let carouselInterval;

        function showSlide(n) {
            const slides = document.querySelectorAll('.carousel-slide');
            const dots = document.querySelectorAll('.dot');

            if (n >= slides.length) currentIndex = 0;
            if (n < 0) currentIndex = slides.length - 1;

            slides.forEach(slide => slide.classList.remove('active'));
            dots.forEach(dot => dot.classList.remove('active'));

            slides[currentIndex].classList.add('active');
            dots[currentIndex].classList.add('active');
        }

        function nextSlide() {
            currentIndex++;
            showSlide(currentIndex);
            resetInterval();
        }

        function prevSlide() {
            currentIndex--;
            showSlide(currentIndex);
            resetInterval();
        }

        function currentSlide(n) {
            currentIndex = n;
            showSlide(currentIndex);
            resetInterval();
        }

        function autoSlide() {
            currentIndex++;
            showSlide(currentIndex);
        }

        function resetInterval() {
            clearInterval(carouselInterval);
            carouselInterval = setInterval(autoSlide, 2000);
        }

        // Initialize carousel
        carouselInterval = setInterval(autoSlide, 2000);
    </script>
</body>

</html>
<?php
$conn->close();
?>