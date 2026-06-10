<?php
require_once 'config/security.php';
security_start_session();

$csrf_token = security_generate_csrf_token();
$clinicLogo = 'assets/img/clinic-logo.jpg';
$loginBackground = 'assets/img/login-background.jpg';
?>
<!DOCTYPE html>
<html lang="en" class="h-full scroll-smooth">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
    <meta name="description" content="Create your patient account at Azucena's Dental Clinic.">
    <title>Create Account | Azucena's Dental Clinic</title>
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
                },
            },
        };
    </script>
    <style>
        .input-field {
            transition: border-color 0.2s ease;
        }
        .input-field:focus {
            border-color: rgb(14 165 233);
            box-shadow: none;
            outline: none;
        }
        .btn-submit {
            transition: background-color 0.25s ease, transform 0.2s ease, box-shadow 0.25s ease;
        }
        .btn-submit:hover:not(:disabled) {
            transform: translateY(-1px);
            box-shadow: 0 8px 20px -6px rgba(14, 165, 233, 0.45);
        }
    </style>
    <link href="assets/css/auth-mobile.css?v=20260607-mobile" rel="stylesheet">
</head>
<body class="auth-page min-h-screen font-sans antialiased text-slate-800">

    <div class="fixed inset-0 z-0" aria-hidden="true">
        <img src="<?php echo htmlspecialchars($loginBackground, ENT_QUOTES, 'UTF-8'); ?>" alt="" class="absolute inset-0 h-full w-full object-cover object-center">
        <div class="absolute inset-0 bg-white/50"></div>
    </div>

    <main class="relative z-10 flex min-h-screen items-center justify-center px-4 py-10 sm:px-6">
        <div class="auth-card auth-card-wide w-full max-w-2xl">
            <div class="overflow-hidden rounded-2xl bg-white shadow-2xl ring-1 ring-slate-100/80">
                <div class="auth-card-inner px-6 py-8 sm:px-10 sm:py-10">
                    <img
                        src="<?php echo htmlspecialchars($clinicLogo, ENT_QUOTES, 'UTF-8'); ?>"
                        alt="Azucena's Dental Clinic logo"
                        class="mx-auto mb-4 h-20 w-auto object-contain"
                        width="140"
                        height="140"
                    >
                    <h1 class="text-center text-2xl font-bold text-slate-900 sm:text-3xl">Create Account</h1>

                    <div id="register-alert" class="mt-6 hidden rounded-xl border px-4 py-3 text-sm" role="alert"></div>

                    <form id="register-form" class="mt-8 space-y-5" novalidate>
                        <div class="grid gap-5 sm:grid-cols-2">
                            <div>
                                <label for="first_name" class="sr-only">First Name</label>
                                <input type="text" id="first_name" name="first_name" required placeholder="First Name"
                                    class="input-field w-full rounded-xl border border-slate-300 px-4 py-3 text-sm placeholder:text-slate-400">
                            </div>
                            <div>
                                <label for="last_name" class="sr-only">Last Name</label>
                                <input type="text" id="last_name" name="last_name" required placeholder="Last Name"
                                    class="input-field w-full rounded-xl border border-slate-300 px-4 py-3 text-sm placeholder:text-slate-400">
                            </div>
                        </div>

                        <div>
                            <label for="email" class="sr-only">Email Address</label>
                            <input type="email" id="email" name="email" required placeholder="Email Address" autocomplete="email"
                                class="input-field w-full rounded-xl border border-slate-300 px-4 py-3 text-sm placeholder:text-slate-400">
                        </div>

                        <div class="grid gap-5 sm:grid-cols-2">
                            <div>
                                <label for="dob" class="mb-1.5 block text-sm font-medium text-sky-600">Date of Birth</label>
                                <input type="date" id="dob" name="dob" required
                                    class="input-field w-full rounded-xl border border-slate-300 px-4 py-3 text-sm text-slate-700">
                            </div>
                            <div>
                                <label for="gender" class="mb-1.5 block text-sm font-medium text-sky-600">Gender</label>
                                <select id="gender" name="gender" required
                                    class="input-field w-full rounded-xl border border-slate-300 bg-white px-4 py-3 text-sm text-slate-700">
                                    <option value="" disabled selected>Select</option>
                                    <option value="male">Male</option>
                                    <option value="female">Female</option>
                                    <option value="other">Other</option>
                                    <option value="prefer_not">Prefer not to say</option>
                                </select>
                            </div>
                        </div>

                        <div>
                            <label for="phone" class="sr-only">Phone Number</label>
                            <input type="tel" id="phone" name="phone" required placeholder="Phone Number" autocomplete="tel"
                                class="input-field w-full rounded-xl border border-slate-300 px-4 py-3 text-sm placeholder:text-slate-400">
                        </div>

                        <div>
                            <label for="address" class="sr-only">Address</label>
                            <input type="text" id="address" name="address" required placeholder="Address" autocomplete="street-address"
                                class="input-field w-full rounded-xl border border-slate-300 px-4 py-3 text-sm placeholder:text-slate-400">
                        </div>

                        <div class="grid gap-5 sm:grid-cols-2">
                            <div>
                                <label for="password" class="mb-1.5 block text-sm font-medium text-sky-600">Password</label>
                                <input type="password" id="password" name="password" required minlength="8" autocomplete="new-password"
                                    class="input-field w-full rounded-xl border border-slate-300 px-4 py-3 text-sm placeholder:text-slate-400"
                                    placeholder="Create a password">
                            </div>
                            <div>
                                <label for="confirm_password" class="mb-1.5 block text-sm font-medium text-sky-600">Confirm Password</label>
                                <input type="password" id="confirm_password" name="confirm_password" required minlength="8" autocomplete="new-password"
                                    class="input-field w-full rounded-xl border border-slate-300 px-4 py-3 text-sm placeholder:text-slate-400"
                                    placeholder="Re-enter password">
                            </div>
                        </div>

                        <div class="rounded-xl border border-sky-100 bg-sky-50 px-4 py-3 text-sm text-slate-600">
                            <p class="font-medium text-slate-800">Password must have:</p>
                            <ul class="mt-2 list-disc space-y-1 pl-5">
                                <li>At least 8 characters</li>
                                <li>One uppercase and one lowercase letter</li>
                                <li>One number and one special character (@ # $ % & * ! ?)</li>
                            </ul>
                        </div>

                        <details class="rounded-xl border border-slate-200 bg-white px-4 py-3 text-sm text-slate-600">
                            <summary class="cursor-pointer font-semibold text-slate-700">View Terms and Conditions</summary>
                            <div class="mt-3 space-y-2">
                                <p>By creating an account, you agree to provide truthful and updated personal and medical information for dental care services.</p>
                                <p>You acknowledge that your appointment requests are subject to clinic approval, schedule availability, and standard clinic policies.</p>
                                <p>You consent to the secure processing of your account and health-related data for treatment, scheduling, reminders, and billing purposes.</p>
                                <p>You are responsible for safeguarding your account credentials and for notifying the clinic immediately of unauthorized account access.</p>
                                <p>The clinic may update these terms to comply with legal, operational, or patient safety requirements, with updates reflected in this system.</p>
                            </div>
                        </details>

                        <label class="flex items-center gap-2 text-sm text-slate-600">
                            <input type="checkbox" id="terms" class="h-4 w-4 rounded border-slate-300 text-sky-500 focus:ring-sky-400">
                            I agree to the Terms and Conditions
                        </label>

                        <button type="submit" class="btn-submit mx-auto mt-2 block w-full rounded-xl bg-sky-500 px-6 py-3.5 text-sm font-bold text-white hover:bg-sky-600 focus:outline-none focus:ring-2 focus:ring-sky-400 focus:ring-offset-2">
                            Create Account
                        </button>
                    </form>
                </div>
            </div>

            <p class="mt-6 text-center text-sm text-slate-600">
                Already have an account?
                <a href="login.php" class="font-bold text-sky-600 hover:text-sky-700">Login here</a>
            </p>
            <p class="mt-3 text-center">
                <a href="index.php" class="text-xs text-slate-500 hover:text-sky-500">Back to Home</a>
            </p>
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

        function validatePasswordPolicy(password) {
            if (password.length < 8) return 'Password must be at least 8 characters.';
            if (!/[A-Z]/.test(password)) return 'Password must include at least one uppercase letter.';
            if (!/[a-z]/.test(password)) return 'Password must include at least one lowercase letter.';
            if (!/\d/.test(password)) return 'Password must include at least one number.';
            if (!/[^A-Za-z0-9]/.test(password)) return 'Password must include at least one special character.';
            return '';
        }

        document.getElementById('register-form').addEventListener('submit', async (event) => {
            event.preventDefault();

            const firstName = document.getElementById('first_name').value.trim();
            const lastName = document.getElementById('last_name').value.trim();
            const email = document.getElementById('email').value.trim();
            const dob = document.getElementById('dob').value;
            const gender = document.getElementById('gender').value;
            const phone = document.getElementById('phone').value.trim();
            const address = document.getElementById('address').value.trim();
            const password = document.getElementById('password').value;
            const confirmPassword = document.getElementById('confirm_password').value;
            const termsAccepted = document.getElementById('terms').checked;
            const phoneDigits = phone.replace(/\D/g, '');

            if (phoneDigits.length < 4) {
                setAlert('Phone number must contain at least 4 digits.', 'warning');
                return;
            }

            if (!termsAccepted) {
                setAlert('Please accept the Terms and Conditions to create an account.', 'warning');
                return;
            }

            const policyError = validatePasswordPolicy(password);
            if (policyError) {
                setAlert(policyError, 'warning');
                return;
            }

            if (password !== confirmPassword) {
                setAlert('Password and confirmation do not match.', 'warning');
                return;
            }

            try {
                const response = await fetch('assets/api/auth/register_api.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({
                        first_name: firstName,
                        last_name: lastName,
                        email: email,
                        date_of_birth: dob,
                        gender: gender,
                        phone_number: phone,
                        address: address,
                        password: password,
                        confirm_password: confirmPassword,
                        role: 'patient',
                        terms_accepted: termsAccepted ? 1 : 0,
                        csrf_token: csrfToken
                    })
                });

                const data = await response.json();

                if (data.success) {
                    setAlert('Account created! Taking you to your dashboard...', 'success');
                    setTimeout(() => {
                        window.location.href = 'patient/patient.php';
                    }, 1200);
                } else {
                    setAlert(data.message || 'Registration failed', 'error');
                }
            } catch (error) {
                setAlert('An error occurred. Please try again.', 'error');
                console.error('Error:', error);
            }
        });
    </script>
</body>
</html>
