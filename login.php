<?php
require_once 'config/security.php';
security_start_session();

if (isset($_GET['intent']) && $_GET['intent'] === 'appointment') {
    $_SESSION['login_intent'] = 'appointment';
}
$appointmentIntent = isset($_SESSION['login_intent']) && $_SESSION['login_intent'] === 'appointment';

$csrf_token = security_generate_csrf_token();
$clinicLogo = 'assets/img/clinic-logo.jpg';
$loginBackground = 'assets/img/login-background.jpg';
?>
<!DOCTYPE html>
<html lang="en" class="h-full scroll-smooth">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
    <meta name="description" content="Secure login portal for Azucena's Dental Clinic Management Information System.">
    <meta name="robots" content="noindex, nofollow">
    <title>Login | Azucena's Dental Clinic</title>

    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">

    <link rel="stylesheet" href="assets/css/app-toast.css">
    <link rel="stylesheet" href="assets/css/password-toggle.css">
    <script src="https://cdn.tailwindcss.com"></script>
    <script>
        tailwind.config = {
            theme: {
                extend: {
                    fontFamily: {
                        sans: ['Inter', 'system-ui', 'Arial', 'sans-serif'],
                    },
                    boxShadow: {
                        card: '0 25px 50px -12px rgba(14, 165, 233, 0.15), 0 12px 24px -8px rgba(15, 23, 42, 0.12)',
                    },
                },
            },
        };
    </script>

    <style>
        .input-field {
            transition: border-color 0.2s ease, background-color 0.2s ease;
        }
        .input-field:focus {
            border-color: rgb(14 165 233);
            box-shadow: none;
            outline: none;
        }
        .btn-login {
            transition: background-color 0.25s ease, transform 0.2s ease, box-shadow 0.25s ease;
        }
        .btn-login:hover:not(:disabled) {
            transform: translateY(-2px);
            box-shadow: 0 10px 25px -5px rgba(14, 165, 233, 0.45);
        }
        .btn-login:active:not(:disabled) {
            transform: translateY(0);
        }
        .toggle-password:focus-visible {
            outline: 2px solid rgb(14 165 233);
            outline-offset: 2px;
            border-radius: 0.375rem;
        }
    </style>
    <link href="assets/css/auth-mobile.css?v=20260607-mobile" rel="stylesheet">
</head>
<body class="auth-page h-full min-h-screen font-sans antialiased text-slate-800">

    <div class="fixed inset-0 z-0" aria-hidden="true">
        <img
            src="<?php echo htmlspecialchars($loginBackground, ENT_QUOTES, 'UTF-8'); ?>"
            alt=""
            class="absolute inset-0 h-full w-full object-cover object-center"
            loading="eager"
            decoding="async"
        >
        <div class="absolute inset-0 bg-white/50"></div>
    </div>

    <main class="relative z-10 flex min-h-screen items-center justify-center px-4 py-8 sm:px-6 lg:px-8">
        <div class="auth-card w-full max-w-md">
            <div class="overflow-hidden rounded-2xl bg-white shadow-2xl shadow-card ring-1 ring-slate-100/80">
                <div class="auth-card-inner px-6 py-8 sm:px-8 sm:py-10">
                    <header class="mb-8 text-center">
                        <img
                            src="<?php echo htmlspecialchars($clinicLogo, ENT_QUOTES, 'UTF-8'); ?>"
                            alt="Azucena's Dental Clinic logo"
                            class="auth-logo mx-auto mb-4 h-24 w-auto object-contain sm:h-28"
                            width="160"
                            height="160"
                        >
                        <h1 class="text-xl font-bold tracking-tight text-slate-900 sm:text-2xl">
                            Azucena's Dental Clinic
                        </h1>
                    </header>

                    <div id="login-alert" class="mb-6 hidden rounded-xl border px-4 py-3 text-sm" role="alert"></div>

                    <form class="space-y-6" novalidate id="login-form">
                        <div class="space-y-1.5">
                            <label for="email" class="block text-sm font-medium text-slate-700">
                                Email
                            </label>
                            <div class="relative">
                                <span class="pointer-events-none absolute inset-y-0 left-0 flex items-center pl-3.5 text-slate-400">
                                    <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" aria-hidden="true">
                                        <path stroke-linecap="round" stroke-linejoin="round" d="M21.75 6.75v10.5a2.25 2.25 0 01-2.25 2.25h-15a2.25 2.25 0 01-2.25-2.25V6.75m19.5 0A2.25 2.25 0 0019.5 4.5h-15a2.25 2.25 0 00-2.25 2.25m19.5 0v.243a2.25 2.25 0 01-1.07 1.916l-7.5 4.615a2.25 2.25 0 01-2.36 0L3.32 8.91a2.25 2.25 0 01-1.07-1.916V6.75" />
                                    </svg>
                                </span>
                                <input
                                    type="email"
                                    id="email"
                                    name="email"
                                    autocomplete="email"
                                    required
                                    placeholder="Enter your email"
                                    class="input-field block w-full rounded-xl border border-slate-200 bg-slate-50/50 py-3 pl-11 pr-4 text-sm text-slate-900 placeholder:text-slate-400 focus:bg-white"
                                >
                            </div>
                        </div>

                        <div class="space-y-1.5">
                            <label for="password" class="block text-sm font-medium text-slate-700">
                                Password
                            </label>
                            <div class="relative">
                                <span class="pointer-events-none absolute inset-y-0 left-0 flex items-center pl-3.5 text-slate-400">
                                    <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" aria-hidden="true">
                                        <path stroke-linecap="round" stroke-linejoin="round" d="M16.5 10.5V6.75a4.5 4.5 0 10-9 0v3.75m-.75 11.25h10.5a2.25 2.25 0 002.25-2.25v-6.75a2.25 2.25 0 00-2.25-2.25H6.75a2.25 2.25 0 00-2.25 2.25v6.75a2.25 2.25 0 002.25 2.25z" />
                                    </svg>
                                </span>
                                <input
                                    type="password"
                                    id="password"
                                    name="password"
                                    autocomplete="current-password"
                                    required
                                    placeholder="Enter your password"
                                    class="input-field block w-full rounded-xl border border-slate-200 bg-slate-50/50 py-3 pl-11 pr-12 text-sm text-slate-900 placeholder:text-slate-400 focus:bg-white"
                                >
                                <button
                                    type="button"
                                    id="toggle-password"
                                    class="toggle-password absolute inset-y-0 right-0 flex items-center pr-3.5 text-slate-400 hover:text-sky-500 transition-colors"
                                    aria-label="Show password"
                                    aria-pressed="false"
                                >
                                    <svg id="icon-eye" class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                                        <path stroke-linecap="round" stroke-linejoin="round" d="M2.036 12.322a1.012 1.012 0 010-.639C3.423 7.51 7.36 4.5 12 4.5c4.638 0 8.573 3.007 9.963 7.178.07.207.07.431 0 .639C20.577 16.49 16.64 19.5 12 19.5c-4.638 0-8.573-3.007-9.963-7.178z" />
                                        <path stroke-linecap="round" stroke-linejoin="round" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z" />
                                    </svg>
                                    <svg id="icon-eye-off" class="hidden h-5 w-5" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                                        <path stroke-linecap="round" stroke-linejoin="round" d="M3.98 8.223A10.477 10.477 0 001.934 12C3.226 16.338 7.244 19.5 12 19.5c.993 0 1.953-.138 2.863-.395M6.228 6.228A10.45 10.45 0 0112 4.5c4.756 0 8.773 3.162 10.065 7.498a10.523 10.523 0 01-4.293 5.774M6.228 6.228L3 3m3.228 3.228l3.65 3.65m7.894 7.894L21 21m-3.228-3.228l-3.65-3.65m0 0a3 3 0 10-4.243-4.243m4.242 4.242L9.88 9.88" />
                                    </svg>
                                </button>
                            </div>
                        </div>

                        <div class="space-y-2">
                            <label class="block text-sm font-medium text-slate-700">Captcha</label>
                            <div class="auth-captcha-row flex flex-wrap items-center gap-3">
                                <span id="loginCaptchaQuestion" class="rounded-lg border border-slate-200 bg-slate-50 px-3 py-2 text-sm text-slate-700">Loading...</span>
                                <button type="button" id="refreshLoginCaptchaBtn" class="text-sm font-semibold text-sky-500 hover:text-sky-600">Refresh</button>
                            </div>
                            <input
                                type="text"
                                id="loginCaptchaAnswer"
                                name="captcha"
                                inputmode="numeric"
                                pattern="-?[0-9]+"
                                required
                                placeholder="Enter the captcha answer"
                                class="input-field block w-full rounded-xl border border-slate-200 bg-slate-50/50 py-3 px-4 text-sm text-slate-900 placeholder:text-slate-400 focus:bg-white"
                            >
                        </div>

                        <div class="auth-links-row flex flex-wrap items-center justify-between gap-3 text-sm">
                            <span class="text-slate-600">
                               
                                <a href="forgot-password.php" class="font-medium text-sky-500 hover:text-sky-600 transition-colors">Forgot Password</a>
                            </span>
                            <a href="register.php" class="font-medium text-sky-500 hover:text-sky-600 transition-colors">Create Account</a>
                        </div>

                        <button
                            type="submit"
                            class="btn-login w-full rounded-xl bg-sky-500 px-4 py-3.5 text-sm font-semibold text-white hover:bg-sky-600 focus:outline-none focus:ring-2 focus:ring-sky-400 focus:ring-offset-2 disabled:opacity-60 disabled:cursor-not-allowed"
                        >
                            <?php echo $appointmentIntent ? 'Login to Book' : 'Login to Dashboard'; ?>
                        </button>
                    </form>
                </div>

                <footer class="border-t border-slate-100 bg-slate-50/80 px-4 py-4 sm:px-6">
                    <p class="text-center text-[10px] leading-relaxed text-slate-500 sm:text-xs">
                        Secure portal access - compliant with the Philippine Data Privacy Act of 2012 (RA 10173)
                    </p>
                </footer>
            </div>

            <p class="mt-6 text-center text-xs text-slate-500/90">
                <a href="index.php#contact" class="text-sky-500 hover:text-sky-600 font-medium">Contact Us</a>
                <span class="mx-2 text-slate-300">|</span>
                <a href="index.php" class="text-sky-500 hover:text-sky-600 font-medium">Back to Home</a>
                <span class="mx-2 text-slate-300">|</span>
                &copy; <?php echo date('Y'); ?> Azucena's Dental Clinic. All rights reserved.
            </p>
        </div>
    </main>

    <script src="assets/js/app-toast.js?v=20260608"></script>
    <script src="assets/js/password-toggle.js?v=20260608"></script>
    <script>
        const csrfToken = <?php echo json_encode($csrf_token); ?>;
        const appointmentIntent = <?php echo json_encode($appointmentIntent); ?>;

        function setAlert(message, type) {
            const tone = type === 'error' ? 'danger' : (type || 'info');
            if (typeof window.showAppToast === 'function') {
                window.showAppToast(String(message || ''), tone);
            }
        }

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

        (function () {
            const passwordInput = document.getElementById('password');
            const toggleBtn = document.getElementById('toggle-password');
            const iconEye = document.getElementById('icon-eye');
            const iconEyeOff = document.getElementById('icon-eye-off');

            if (toggleBtn && passwordInput) {
                toggleBtn.addEventListener('click', function () {
                    const isHidden = passwordInput.type === 'password';
                    passwordInput.type = isHidden ? 'text' : 'password';
                    toggleBtn.setAttribute('aria-pressed', isHidden ? 'true' : 'false');
                    toggleBtn.setAttribute('aria-label', isHidden ? 'Hide password' : 'Show password');
                    if (iconEye && iconEyeOff) {
                        iconEye.classList.toggle('hidden', isHidden);
                        iconEyeOff.classList.toggle('hidden', !isHidden);
                    }
                });
            }
        })();

        document.getElementById('login-form').addEventListener('submit', async (event) => {
            event.preventDefault();

            const email = document.getElementById('email').value.trim();
            const password = document.getElementById('password').value;
            const captchaAnswer = document.getElementById('loginCaptchaAnswer').value.trim();

            if (!captchaAnswer) {
                setAlert('Please solve the captcha before logging in.', 'warning');
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
                    setAlert('Login successful. Redirecting...', 'success');

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
                        redirectUrl = 'patient/patient.php';
                    }

                    setTimeout(() => {
                        window.location.href = redirectUrl;
                    }, 1400);
                } else {
                    if (data.captcha && data.captcha.question) {
                        setLoginCaptchaQuestion(data.captcha.question);
                        const ans = document.getElementById('loginCaptchaAnswer');
                        if (ans) ans.value = '';
                    } else {
                        await refreshLoginCaptcha();
                    }
                    setAlert(data.message || 'Login failed', 'error');
                }
            } catch (error) {
                await refreshLoginCaptcha();
                setAlert('An error occurred. Please try again.', 'error');
                console.error('Error:', error);
            }
        });

        const refreshLoginCaptchaBtn = document.getElementById('refreshLoginCaptchaBtn');
        if (refreshLoginCaptchaBtn) {
            refreshLoginCaptchaBtn.addEventListener('click', () => {
                refreshLoginCaptcha();
            });
        }

        refreshLoginCaptcha();
    </script>
</body>
</html>
