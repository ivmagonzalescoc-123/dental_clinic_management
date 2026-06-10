<?php
require_once 'config/security.php';
security_start_session();

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

$year = date('Y');
$csrf_token = security_generate_csrf_token();
$clinicLogo = 'assets/img/clinic-logo.jpg';
$pageBackgroundImage = 'assets/img/landing-background.jpg';
$pageOverlayStrength = 52;

$pageBgSrc = $pageBackgroundImage;
if (!preg_match('#^https?://#i', $pageBackgroundImage)) {
    $localFile = __DIR__ . DIRECTORY_SEPARATOR . str_replace(['/', '\\'], DIRECTORY_SEPARATOR, ltrim($pageBackgroundImage, '/\\'));
    if (!is_file($localFile)) {
        $pageBgSrc = 'assets/img/landing-background.jpg';
    }
}
$pageOverlayStrength = max(35, min(75, (int)$pageOverlayStrength));
$pageOverlayStyle = 'background-color: rgba(255, 255, 255, ' . ($pageOverlayStrength / 100) . ');';

$services = [
    [
        'title' => 'General Dentistry',
        'desc' => 'Checkups and cleanings',
        'icon' => 'building',
    ],
    [
        'title' => 'Cosmetic Dentistry',
        'desc' => 'Whitening and veneers',
        'icon' => 'sparkles',
    ],
    [
        'title' => 'Restorative Care',
        'desc' => 'Crowns and implants',
        'icon' => 'wrench',
    ],
    [
        'title' => 'Orthodontics',
        'desc' => 'Braces and aligners',
        'icon' => 'scissors',
    ],
];

$aboutPoints = [
    'Dedicated team of 2 experienced doctors',
    'Latest dental technology and equipment',
    'Comfortable, modern clinic environment',
    'Compassionate patient care',
];

$heroPoints = [
    'Active since 2023 with proven expertise',
    'Advanced technology and techniques',
    'Gentle, patient-focused care',
];
?>
<!DOCTYPE html>
<html lang="en" class="scroll-smooth">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="description" content="Azucena's Dental Clinic - compassionate, state-of-the-art dental care in Cagayan de Oro City since 2023.">
    <title>Azucena's Dental Clinic | Home</title>
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
</head>
<body class="font-sans antialiased text-slate-800">

    <header class="sticky top-0 z-50 border-b border-slate-100 bg-white/95 backdrop-blur-sm">
        <div class="mx-auto flex max-w-6xl items-center justify-between gap-4 px-4 py-4 sm:px-6">
            <a href="index.php" class="flex items-center gap-2.5">
                <img
                    src="<?php echo htmlspecialchars($clinicLogo, ENT_QUOTES, 'UTF-8'); ?>"
                    alt="Azucena's Dental Clinic logo"
                    class="h-9 w-auto object-contain sm:h-10"
                    width="40"
                    height="40"
                >
                <span class="text-sm font-bold text-slate-900 sm:text-base">Azucena's Dental Clinic</span>
            </a>
            <nav class="hidden items-center gap-6 text-sm font-medium text-slate-600 md:flex" aria-label="Main">
                <a href="#hero" class="hover:text-sky-500 transition-colors">Home</a>
                <a href="#services" class="hover:text-sky-500 transition-colors">Services</a>
                <a href="#about" class="hover:text-sky-500 transition-colors">About</a>
                <a href="#contact" class="hover:text-sky-500 transition-colors">Contact</a>
            </nav>
            <a href="login.php" class="rounded-lg bg-sky-500 px-4 py-2 text-sm font-semibold text-white hover:bg-sky-600 transition-colors">
                Login
            </a>
        </div>
    </header>

    <div class="relative">
        <div class="absolute inset-0 z-0" aria-hidden="true">
            <img
                src="<?php echo htmlspecialchars($pageBgSrc, ENT_QUOTES, 'UTF-8'); ?>"
                alt=""
                class="h-full w-full object-cover object-center"
            >
            <div class="absolute inset-0" style="<?php echo $pageOverlayStyle; ?>"></div>
        </div>

        <section id="hero" class="relative z-10 border-b border-white/40">
            <div class="mx-auto max-w-3xl px-4 py-16 text-center sm:px-6 sm:py-20 lg:py-24">
                <h1 class="text-3xl font-bold tracking-tight text-slate-900 sm:text-4xl lg:text-5xl">
                    Transform Your Smile!
                </h1>
                <p class="mt-4 text-base leading-relaxed text-slate-600 sm:text-lg">
                    Experience compassionate, state-of-the-art dental treatment in a comfortable environment. Your oral health is our priority.
                </p>
                <ul class="mt-8 space-y-3 text-left sm:mx-auto sm:max-w-md">
                    <?php foreach ($heroPoints as $point): ?>
                    <li class="flex items-start gap-3 text-sm text-slate-700 sm:text-base">
                        <span class="mt-0.5 flex h-6 w-6 shrink-0 items-center justify-center rounded-full bg-sky-500 text-white">
                            <svg class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke-width="3" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M4.5 12.75l6 6 9-13.5"/></svg>
                        </span>
                        <?php echo htmlspecialchars($point, ENT_QUOTES, 'UTF-8'); ?>
                    </li>
                    <?php endforeach; ?>
                </ul>
                <div class="mt-10 flex flex-wrap justify-center gap-3">
                    <a href="login.php?intent=appointment" class="inline-flex items-center gap-2 rounded-xl bg-sky-500 px-5 py-3 text-sm font-semibold text-white hover:bg-sky-600 transition-colors">
                        <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M6.75 3v2.25M17.25 3v2.25M3 18.75V7.5a2.25 2.25 0 012.25-2.25h13.5A2.25 2.25 0 0121 7.5v11.25m-18 0A2.25 2.25 0 005.25 21h13.5A2.25 2.25 0 0021 18.75m-18 0v-7.5A2.25 2.25 0 015.25 9h13.5A2.25 2.25 0 0121 11.25v7.5"/></svg>
                        Book Appointment
                    </a>
                    <a href="register.php" class="inline-flex items-center gap-2 rounded-xl border-2 border-sky-500 bg-white/90 px-5 py-3 text-sm font-semibold text-sky-600 hover:bg-white transition-colors">
                        <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M18 7.5v3m0 0v3m0-3h3m-3 0h-3m-2.25-4.125a3.375 3.375 0 11-6.75 0 3.375 3.375 0 016.75 0zM3 19.235v-.11a6.375 6.375 0 0112.75 0v.109A12.318 12.318 0 019.374 21c-2.331 0-4.512-.645-6.374-1.766z"/></svg>
                        Create Account
                    </a>
                </div>
            </div>
        </section>

        <section id="services" class="relative z-10 py-16 sm:py-20">
            <div class="mx-auto max-w-6xl px-4 sm:px-6">
                <h2 class="text-center text-2xl font-bold text-slate-900 sm:text-3xl">Our Services</h2>
                <div class="mt-10 grid gap-6 sm:grid-cols-2 lg:grid-cols-4">
                    <?php foreach ($services as $service): ?>
                    <article class="rounded-2xl border border-white/80 bg-white/92 p-6 text-center shadow-md backdrop-blur-[2px] transition-shadow hover:bg-white hover:shadow-lg">
                        <div class="mx-auto mb-4 flex h-14 w-14 items-center justify-center text-sky-500">
                            <?php if ($service['icon'] === 'building'): ?>
                            <svg class="h-10 w-10" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M2.25 21h19.5m-18-18v18m10.5-18v18m6-13.5V21M6.75 6.75h.75m-.75 3h.75m-.75 3h.75m3-6h.75m-.75 3h.75m-.75 3h.75M6.75 21v-3.375c0-.621.504-1.125 1.125-1.125h2.25c.621 0 1.125.504 1.125 1.125V21M3.75 9h.75m-.75 3h.75m-.75 3h.75"/></svg>
                            <?php elseif ($service['icon'] === 'sparkles'): ?>
                            <svg class="h-10 w-10" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M12 3v2.25m6.364.386l-1.591 1.591M21 12h-2.25m-.386 6.364l-1.591-1.591M12 18.75V21m-4.773-4.227l-1.591 1.591M5.25 12H3m4.227-4.773L5.636 5.636M15.75 12a3.75 3.75 0 11-7.5 0 3.75 3.75 0 017.5 0z"/></svg>
                            <?php elseif ($service['icon'] === 'wrench'): ?>
                            <svg class="h-10 w-10" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M11.42 15.17L17.25 21A2.652 2.652 0 0021 17.25l-5.877-5.877M11.42 15.17l2.496-3.03c.317-.384.74-.626 1.208-.766M11.42 15.17l-4.655 5.653a2.548 2.548 0 11-3.586-3.586l6.837-5.45m5.108-.233c.55-.164 1.163-.188 1.743-.14a4.5 4.5 0 004.486-6.336l-3.276 3.277a3.004 3.004 0 01-2.25-2.25l3.276-3.276a4.5 4.5 0 00-6.336 4.486c.091 1.103-.071 2.218-.904 2.95l-1.102 1.102m-.233 5.108a2.548 2.548 0 11-3.586-3.586l5.653-4.655"/></svg>
                            <?php else: ?>
                            <svg class="h-10 w-10" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M7.848 8.25l1.536 1.536M12 3v2.25m0 13.5V21M5.106 5.106l1.06 1.06M18.894 5.106l-1.06 1.06M3 12h2.25m13.5 0H21M5.106 18.894l1.06-1.06M18.894 18.894l-1.06-1.06M9 12.75a3 3 0 106 0 3 3 0 00-6 0z"/></svg>
                            <?php endif; ?>
                        </div>
                        <h3 class="font-bold text-slate-900"><?php echo htmlspecialchars($service['title'], ENT_QUOTES, 'UTF-8'); ?></h3>
                        <p class="mt-2 text-sm text-slate-500"><?php echo htmlspecialchars($service['desc'], ENT_QUOTES, 'UTF-8'); ?></p>
                    </article>
                    <?php endforeach; ?>
                </div>
            </div>
        </section>
    </div>

    <section id="about" class="border-y border-slate-100 bg-slate-50/60 py-16 sm:py-20">
        <div class="mx-auto grid max-w-6xl items-start gap-10 px-4 sm:px-6 lg:grid-cols-2 lg:gap-14">
            <div>
                <h2 class="text-2xl font-bold text-slate-900 sm:text-3xl">About Us</h2>
                <p class="mt-4 leading-relaxed text-slate-600">
                    Azucena Dental Clinic has been actively serving our community since 2023. With a dedicated team of 2 experienced doctors and modern technology, we focus on delivering exceptional dental care with compassion and professionalism. Every patient deserves the best treatment possible.
                </p>
                <ul class="mt-8 space-y-3">
                    <?php foreach ($aboutPoints as $point): ?>
                    <li class="flex items-start gap-3 text-slate-700">
                        <span class="mt-0.5 flex h-6 w-6 shrink-0 items-center justify-center rounded-full border-2 border-sky-500 text-sky-500">
                            <svg class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke-width="2.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M4.5 12.75l6 6 9-13.5"/></svg>
                        </span>
                        <?php echo htmlspecialchars($point, ENT_QUOTES, 'UTF-8'); ?>
                    </li>
                    <?php endforeach; ?>
                </ul>
            </div>
            <div class="overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-md">
                <iframe
                    title="Azucena Dental Clinic location - Metz Arcade, Cagayan de Oro City"
                    class="h-80 w-full sm:h-96"
                    loading="lazy"
                    referrerpolicy="no-referrer-when-downgrade"
                    src="https://maps.google.com/maps?q=Metz+Arcade,+Cagayan+de+Oro+City,+Philippines&amp;output=embed"
                    allowfullscreen
                ></iframe>
                <p class="border-t border-slate-100 bg-white px-4 py-3 text-sm text-slate-600">
                    <span class="font-semibold text-slate-800">Metz Arcade</span> - Cagayan de Oro City, Philippines
                </p>
            </div>
        </div>
    </section>

    <section id="contact" class="py-16 sm:py-20">
        <div class="mx-auto max-w-6xl px-4 sm:px-6">
            <h2 class="text-center text-2xl font-bold text-slate-900 sm:text-3xl">Contact Us</h2>
            <p class="mx-auto mt-3 max-w-xl text-center text-slate-600"></p>
            <div class="mt-10 grid gap-8 lg:grid-cols-2">
                <div class="space-y-5 rounded-2xl border border-slate-200 bg-sky-50/40 p-6 sm:p-8">
                    <div class="flex gap-4">
                        <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-lg bg-sky-500 text-white">
                            <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M15 10.5a3.75 3.75 0 11-7.5 0 3.75 3.75 0 017.5 0z"/><path stroke-linecap="round" stroke-linejoin="round" d="M19.5 10.5c0 7.142-7.5 11.25-7.5 11.25S4.5 17.642 4.5 10.5a7.5 7.5 0 1115 0z"/></svg>
                        </span>
                        <div>
                            <p class="font-semibold text-slate-900">Clinic Address</p>
                            <p class="mt-1 text-sm text-slate-600">Metz Arcade, Cagayan de Oro City, Philippines</p>
                        </div>
                    </div>
                    <div class="flex gap-4">
                        <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-lg bg-sky-500 text-white">
                            <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M2.25 6.75c0 8.284 6.716 15 15 15h2.25a2.25 2.25 0 002.25-2.25v-1.372c0-.516-.351-.966-.852-1.091l-4.423-1.106c-.44-.11-.902.055-1.173.417l-.97 1.293c-.282.376-.769.542-1.21.38a12.035 12.035 0 01-7.143-7.143c-.162-.441.004-.928.38-1.21l1.293-.97c.363-.271.527-.734.417-1.173L6.963 3.102a1.125 1.125 0 00-1.091-.852H4.5A2.25 2.25 0 002.25 6.75z"/></svg>
                        </span>
                        <div>
                            <p class="font-semibold text-slate-900">Phone</p>
                            <p class="mt-1 text-sm text-slate-600">+63 (88) 000 0000</p>
                        </div>
                    </div>
                    <div class="flex gap-4">
                        <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-lg bg-sky-500 text-white">
                            <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M21.75 6.75v10.5a2.25 2.25 0 01-2.25 2.25h-15a2.25 2.25 0 01-2.25-2.25V6.75m19.5 0A2.25 2.25 0 0019.5 4.5h-15a2.25 2.25 0 00-2.25 2.25m19.5 0v.243a2.25 2.25 0 01-1.07 1.916l-7.5 4.615a2.25 2.25 0 01-2.36 0L3.32 8.91a2.25 2.25 0 01-1.07-1.916V6.75"/></svg>
                        </span>
                        <div>
                            <p class="font-semibold text-slate-900">Email</p>
                            <p class="mt-1 text-sm text-slate-600">info@azucenadental.ph</p>
                        </div>
                    </div>
                    <div class="flex gap-4">
                        <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-lg bg-sky-500 text-white">
                            <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M12 6v6h4.5m4.5 0a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                        </span>
                        <div>
                            <p class="font-semibold text-slate-900">Clinic Hours</p>
                            <p class="mt-1 text-sm text-slate-600">Mon - Sat: 8:00 AM - 5:00 PM</p>
                        </div>
                    </div>
                </div>
                <form id="contact-form" class="rounded-2xl border border-slate-200 bg-white p-6 shadow-sm sm:p-8" novalidate>
                    <div id="contact-form-alert" class="hidden mb-4 rounded-xl border px-4 py-3 text-sm" role="alert"></div>
                    <div class="space-y-4">
                        <div>
                            <label for="contact-name" class="block text-sm font-medium text-slate-700">Full Name</label>
                            <input type="text" id="contact-name" name="name" required class="mt-1 w-full rounded-xl border border-slate-200 px-4 py-2.5 text-sm focus:border-sky-500 focus:ring-2 focus:ring-sky-200 outline-none">
                        </div>
                        <div>
                            <label for="contact-email" class="block text-sm font-medium text-slate-700">Email</label>
                            <input type="email" id="contact-email" name="email" required class="mt-1 w-full rounded-xl border border-slate-200 px-4 py-2.5 text-sm focus:border-sky-500 focus:ring-2 focus:ring-sky-200 outline-none">
                        </div>
                        <div>
                            <label for="contact-phone" class="block text-sm font-medium text-slate-700">Phone Number</label>
                            <input type="tel" id="contact-phone" name="phone" required inputmode="tel" autocomplete="tel" placeholder="e.g. 0917 123 4567" class="mt-1 w-full rounded-xl border border-slate-200 px-4 py-2.5 text-sm focus:border-sky-500 focus:ring-2 focus:ring-sky-200 outline-none">
                        </div>
                        <div>
                            <label for="contact-message" class="block text-sm font-medium text-slate-700">Message</label>
                            <textarea id="contact-message" name="message" rows="4" required class="mt-1 w-full rounded-xl border border-slate-200 px-4 py-2.5 text-sm focus:border-sky-500 focus:ring-2 focus:ring-sky-200 outline-none resize-y" placeholder="How can we help you?"></textarea>
                        </div>
                        <button type="submit" id="contact-form-submit" class="w-full rounded-xl bg-sky-500 py-3 text-sm font-semibold text-white hover:bg-sky-600 transition-colors">
                            Send Message
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </section>

    <footer class="border-t border-slate-100 bg-slate-50 py-8 text-center text-sm text-slate-500">
        <p>&copy; <?php echo $year; ?> Azucena's Dental Clinic. All rights reserved.</p>
    </footer>

    <script>
        const contactCsrfToken = <?php echo json_encode($csrf_token); ?>;

        function setContactAlert(message, type) {
            const alertEl = document.getElementById('contact-form-alert');
            if (!alertEl) return;

            alertEl.classList.remove('hidden', 'border-red-200', 'bg-red-50', 'text-red-700', 'border-emerald-200', 'bg-emerald-50', 'text-emerald-700');

            if (type === 'success') {
                alertEl.classList.add('border-emerald-200', 'bg-emerald-50', 'text-emerald-700');
            } else {
                alertEl.classList.add('border-red-200', 'bg-red-50', 'text-red-700');
            }

            alertEl.textContent = message;
        }

        const contactForm = document.getElementById('contact-form');
        if (contactForm) {
            contactForm.addEventListener('submit', async function(event) {
                event.preventDefault();

                const submitBtn = document.getElementById('contact-form-submit');
                const name = document.getElementById('contact-name').value.trim();
                const email = document.getElementById('contact-email').value.trim();
                const phone = document.getElementById('contact-phone').value.trim();
                const message = document.getElementById('contact-message').value.trim();

                if (!name || !email || !phone || !message) {
                    setContactAlert('Please complete all fields before sending your message.', 'error');
                    return;
                }

                const phoneDigits = phone.replace(/\D+/g, '');
                if (phoneDigits.length < 7 || phoneDigits.length > 15) {
                    setContactAlert('Please enter a valid phone number.', 'error');
                    return;
                }

                if (submitBtn) {
                    submitBtn.disabled = true;
                    submitBtn.textContent = 'Sending...';
                }

                try {
                    const response = await fetch('assets/api/contact/submit_contact_message.php', {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json' },
                        body: JSON.stringify({
                            name: name,
                            email: email,
                            phone: phone,
                            message: message,
                            csrf_token: contactCsrfToken
                        })
                    });
                    const data = await response.json();

                    if (!response.ok || !data.success) {
                        setContactAlert(data.message || 'Unable to send your message right now.', 'error');
                        return;
                    }

                    contactForm.reset();
                    setContactAlert(data.message || 'Thank you! Your message has been sent.', 'success');
                } catch (error) {
                    setContactAlert('An error occurred while sending your message. Please try again.', 'error');
                } finally {
                    if (submitBtn) {
                        submitBtn.disabled = false;
                        submitBtn.textContent = 'Send Message';
                    }
                }
            });
        }
    </script>

</body>
</html>
