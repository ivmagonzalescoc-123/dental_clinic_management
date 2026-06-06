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
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="description" content="Create your patient account at Azucena's Dental Clinic.">
    <title>Create Account | Azucena's Dental Clinic</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
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
            transition: border-color 0.2s ease, box-shadow 0.2s ease;
        }
        .input-field:focus {
            border-color: rgb(14 165 233);
            box-shadow: 0 0 0 3px rgba(14, 165, 233, 0.2);
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
</head>
<body class="min-h-screen font-sans antialiased text-slate-800">

    <div class="fixed inset-0 z-0" aria-hidden="true">
        <img src="<?php echo htmlspecialchars($loginBackground, ENT_QUOTES, 'UTF-8'); ?>" alt="" class="absolute inset-0 h-full w-full object-cover object-center">
        <div class="absolute inset-0 bg-white/50"></div>
    </div>

    <main class="relative z-10 flex min-h-screen items-center justify-center px-4 py-10 sm:px-6">
        <div class="w-full max-w-2xl">
            <div class="overflow-hidden rounded-2xl bg-white shadow-2xl ring-1 ring-slate-100/80">
                <div class="px-6 py-8 sm:px-10 sm:py-10">
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

                        <div class="rounded-xl border border-sky-100 bg-sky-50 px-4 py-3 text-sm text-slate-600">
                            Your default password will be generated automatically using this format:
                            <span class="block font-semibold text-slate-800">Lastname_Last4digitphonenumber (Example: Smith_1234)</span>
                            You will be required to change it on first login.
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

    <script>
        const csrfToken = <?php echo json_encode($csrf_token); ?>;

        function setAlert(message, type) {
            const alertEl = document.getElementById('register-alert');
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

        document.getElementById('register-form').addEventListener('submit', async (event) => {
            event.preventDefault();

            const firstName = document.getElementById('first_name').value.trim();
            const lastName = document.getElementById('last_name').value.trim();
            const email = document.getElementById('email').value.trim();
            const dob = document.getElementById('dob').value;
            const gender = document.getElementById('gender').value;
            const phone = document.getElementById('phone').value.trim();
            const address = document.getElementById('address').value.trim();
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
                        role: 'patient',
                        terms_accepted: termsAccepted ? 1 : 0,
                        csrf_token: csrfToken
                    })
                });

                const data = await response.json();

                if (data.success) {
                    setAlert('Account created. Please change your default password to continue.', 'success');
                    setTimeout(() => {
                        window.location.href = 'patient/patient.php?page=settings&force_password_change=1';
                    }, 1600);
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
