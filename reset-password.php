<?php
require_once 'config/security.php';
security_start_session();

$csrf_token = security_generate_csrf_token();
$clinicLogo = 'assets/img/clinic-logo.jpg';
$loginBackground = 'assets/img/login-background.jpg';

$token = isset($_GET['token']) ? trim($_GET['token']) : '';
$email = isset($_GET['email']) ? trim($_GET['email']) : '';
?>
<!DOCTYPE html>
<html lang="en" class="h-full">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Reset Password | Azucena's Dental Clinic</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600&display=swap" rel="stylesheet">
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
</head>
<body class="min-h-screen font-sans antialiased text-slate-800">

    <div class="fixed inset-0 z-0" aria-hidden="true">
        <img src="<?php echo htmlspecialchars($loginBackground, ENT_QUOTES, 'UTF-8'); ?>" alt="" class="absolute inset-0 h-full w-full object-cover object-center">
        <div class="absolute inset-0 bg-white/50"></div>
    </div>

    <main class="relative z-10 flex min-h-screen items-center justify-center px-4 py-10">
        <div class="w-full max-w-md">
            <div class="rounded-2xl bg-white p-8 text-center shadow-xl ring-1 ring-slate-100">
                <img
                    src="<?php echo htmlspecialchars($clinicLogo, ENT_QUOTES, 'UTF-8'); ?>"
                    alt="Azucena's Dental Clinic logo"
                    class="mx-auto mb-4 h-16 w-auto object-contain"
                    width="120"
                    height="120"
                >
                <h1 class="text-lg font-bold text-slate-900">Reset Your Password</h1>
                <p class="mt-2 text-sm text-slate-600">Enter the OTP sent to your email and choose a new password.</p>

                <div id="reset-alert" class="mt-4 hidden rounded-xl border px-4 py-3 text-sm" role="alert"></div>

                <form id="reset-form" class="mt-6 space-y-4">
                    <input type="hidden" id="reset-token" value="<?php echo htmlspecialchars($token, ENT_QUOTES, 'UTF-8'); ?>">
                    <input type="hidden" id="reset-email" value="<?php echo htmlspecialchars($email, ENT_QUOTES, 'UTF-8'); ?>">

                    <input
                        type="text"
                        id="reset-otp"
                        required
                        placeholder="OTP Code"
                        class="w-full rounded-xl border border-slate-200 px-4 py-3 text-sm focus:border-sky-500 focus:ring-2 focus:ring-sky-200 outline-none"
                    >
                    <input
                        type="password"
                        id="reset-password"
                        required
                        placeholder="New Password"
                        class="w-full rounded-xl border border-slate-200 px-4 py-3 text-sm focus:border-sky-500 focus:ring-2 focus:ring-sky-200 outline-none"
                    >
                    <input
                        type="password"
                        id="reset-confirm"
                        required
                        placeholder="Confirm Password"
                        class="w-full rounded-xl border border-slate-200 px-4 py-3 text-sm focus:border-sky-500 focus:ring-2 focus:ring-sky-200 outline-none"
                    >
                    <button type="submit" class="w-full rounded-xl bg-sky-500 py-3 text-sm font-semibold text-white hover:bg-sky-600 transition-colors">
                        Update Password
                    </button>
                </form>

                <a href="login.php" class="mt-6 inline-block text-sm font-medium text-sky-500 hover:text-sky-600">Back to Login</a>
            </div>
        </div>
    </main>

    <script>
        const csrfToken = <?php echo json_encode($csrf_token); ?>;

        function setAlert(message, type) {
            const alertEl = document.getElementById('reset-alert');
            if (!alertEl) return;

            alertEl.classList.remove('hidden', 'border-red-200', 'bg-red-50', 'text-red-700', 'border-emerald-200', 'bg-emerald-50', 'text-emerald-700', 'border-amber-200', 'bg-amber-50', 'text-amber-700');

            if (type === 'success') {
                alertEl.classList.add('border-emerald-200', 'bg-emerald-50', 'text-emerald-700');
            } else if (type === 'warning') {
                alertEl.classList.add('border-amber-200', 'bg-amber-50', 'text-amber-700');
            } else {
                alertEl.classList.add('border-red-200', 'bg-red-50', 'text-red-700');
            }

            alertEl.textContent = message;
        }

        document.getElementById('reset-form').addEventListener('submit', async (event) => {
            event.preventDefault();

            const token = document.getElementById('reset-token').value.trim();
            const email = document.getElementById('reset-email').value.trim();
            const otp = document.getElementById('reset-otp').value.trim();
            const newPassword = document.getElementById('reset-password').value;
            const confirmPassword = document.getElementById('reset-confirm').value;

            if (!token || !email) {
                setAlert('Reset link is missing or invalid.', 'error');
                return;
            }

            try {
                const response = await fetch('assets/api/auth/reset_password_api.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({
                        email,
                        token,
                        otp,
                        new_password: newPassword,
                        confirm_password: confirmPassword,
                        csrf_token: csrfToken
                    })
                });

                const data = await response.json();

                if (data.success) {
                    setAlert('Password updated successfully. You can now log in.', 'success');
                    setTimeout(() => {
                        window.location.href = 'login.php';
                    }, 1600);
                } else {
                    setAlert(data.message || 'Reset failed', 'error');
                }
            } catch (error) {
                setAlert('An error occurred. Please try again.', 'error');
            }
        });
    </script>
</body>
</html>
