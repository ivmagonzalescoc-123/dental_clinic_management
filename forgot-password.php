<?php
require_once 'config/security.php';
security_start_session();

$csrf_token = security_generate_csrf_token();
$clinicLogo = 'assets/img/clinic-logo.jpg';
$loginBackground = 'assets/img/login-background.jpg';
?>
<!DOCTYPE html>
<html lang="en" class="h-full">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
    <title>Forgot Password | Azucena's Dental Clinic</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600&display=swap" rel="stylesheet">
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
                },
            },
        };
    </script>
    <link href="assets/css/auth-mobile.css?v=20260607-mobile" rel="stylesheet">
</head>
<body class="auth-page min-h-screen font-sans antialiased text-slate-800">

    <div class="fixed inset-0 z-0" aria-hidden="true">
        <img src="<?php echo htmlspecialchars($loginBackground, ENT_QUOTES, 'UTF-8'); ?>" alt="" class="absolute inset-0 h-full w-full object-cover object-center">
        <div class="absolute inset-0 bg-white/50"></div>
    </div>

    <main class="relative z-10 flex min-h-screen items-center justify-center px-4 py-10">
        <div class="auth-card w-full max-w-md">
            <div class="auth-card-inner rounded-2xl bg-white p-8 text-center shadow-xl ring-1 ring-slate-100">
                <img
                    src="<?php echo htmlspecialchars($clinicLogo, ENT_QUOTES, 'UTF-8'); ?>"
                    alt="Azucena's Dental Clinic logo"
                    class="auth-logo mx-auto mb-4 h-16 w-auto object-contain"
                    width="120"
                    height="120"
                >
                <h1 class="text-lg font-bold text-slate-900">Password Recovery</h1>
                <p class="mt-2 text-sm text-slate-600">Enter your email to receive an OTP, then set a new password.</p>

                <div id="reset-alert" class="mt-4 hidden rounded-xl border px-4 py-3 text-sm" role="alert"></div>

                <form id="reset-form" class="mt-6 space-y-4">
                    <input
                        type="email"
                        id="reset-email"
                        required
                        placeholder="Email Address"
                        class="w-full rounded-xl border border-slate-200 px-4 py-3 text-sm focus:border-sky-500 focus:ring-0 outline-none"
                    >
                    <button type="button" id="send-otp" class="w-full rounded-xl border border-sky-200 bg-sky-50 py-3 text-sm font-semibold text-sky-600 hover:bg-sky-100 transition-colors">
                        Send OTP
                    </button>

                    <div id="reset-section" class="space-y-4 hidden">
                        <input
                            type="text"
                            id="reset-otp"
                            required
                            placeholder="OTP Code"
                            class="w-full rounded-xl border border-slate-200 px-4 py-3 text-sm focus:border-sky-500 focus:ring-0 outline-none"
                        >
                        <input
                            type="password"
                            id="reset-password"
                            required
                            placeholder="New Password"
                            class="w-full rounded-xl border border-slate-200 px-4 py-3 text-sm focus:border-sky-500 focus:ring-0 outline-none"
                        >
                        <input
                            type="password"
                            id="reset-confirm"
                            required
                            placeholder="Confirm Password"
                            class="w-full rounded-xl border border-slate-200 px-4 py-3 text-sm focus:border-sky-500 focus:ring-0 outline-none"
                        >
                        <button type="submit" class="w-full rounded-xl bg-sky-500 py-3 text-sm font-semibold text-white hover:bg-sky-600 transition-colors">
                            Reset Password
                        </button>
                    </div>
                </form>

                <a href="login.php" class="mt-6 inline-block text-sm font-medium text-sky-500 hover:text-sky-600">Back to Login</a>
            </div>
        </div>
    </main>

    <script src="assets/js/app-toast.js?v=20260608"></script>
    <script src="assets/js/password-toggle.js?v=20260608"></script>
    <script>
        const csrfToken = <?php echo json_encode($csrf_token); ?>;

        function setAlert(message, type) {
            const tone = type === 'error' ? 'danger' : (type || 'info');
            if (typeof window.showAppToast === 'function') {
                window.showAppToast(String(message || ''), tone);
            }
        }

        const sendOtpButton = document.getElementById('send-otp');
        const resetSection = document.getElementById('reset-section');

        sendOtpButton.addEventListener('click', async () => {
            const email = document.getElementById('reset-email').value.trim();
            if (!email) {
                setAlert('Please enter your email address.', 'warning');
                return;
            }

            try {
                const response = await fetch('assets/api/auth/forgot_password_api.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ email, csrf_token: csrfToken })
                });

                const rawText = await response.text();
                let data = null;
                try {
                    data = JSON.parse(rawText);
                } catch (parseError) {
                    data = null;
                }

                if (response.ok && data && data.success) {
                    setAlert('OTP sent. Check your email.', 'success');
                    resetSection.classList.remove('hidden');
                } else if (data && data.message) {
                    setAlert(data.message, 'error');
                } else {
                    const preview = rawText ? rawText.slice(0, 200) : '';
                    setAlert('Request failed (' + response.status + '). ' + preview, 'error');
                }
            } catch (error) {
                setAlert('An error occurred. Please try again.', 'error');
            }
        });

        document.getElementById('reset-form').addEventListener('submit', async (event) => {
            event.preventDefault();

            const email = document.getElementById('reset-email').value.trim();
            const otp = document.getElementById('reset-otp').value.trim();
            const newPassword = document.getElementById('reset-password').value;
            const confirmPassword = document.getElementById('reset-confirm').value;

            if (!email) {
                setAlert('Please enter your email address.', 'warning');
                return;
            }

            if (!otp) {
                setAlert('Please enter the OTP code.', 'warning');
                return;
            }

            try {
                const response = await fetch('assets/api/auth/reset_password_api.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({
                        email,
                        otp,
                        new_password: newPassword,
                        confirm_password: confirmPassword,
                        csrf_token: csrfToken
                    })
                });

                const rawText = await response.text();
                let data = null;
                try {
                    data = JSON.parse(rawText);
                } catch (parseError) {
                    data = null;
                }

                if (response.ok && data && data.success) {
                    setAlert('Password updated successfully. You can now log in.', 'success');
                    setTimeout(() => {
                        window.location.href = 'login.php';
                    }, 1600);
                } else if (data && data.message) {
                    setAlert(data.message, 'error');
                } else {
                    const preview = rawText ? rawText.slice(0, 200) : '';
                    setAlert('Request failed (' + response.status + '). ' + preview, 'error');
                }
            } catch (error) {
                setAlert('An error occurred. Please try again.', 'error');
            }
        });
    </script>
</body>
</html>
