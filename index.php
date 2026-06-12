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

$pageBgSrc = $pageBackgroundImage;
if (!preg_match('#^https?://#i', $pageBackgroundImage)) {
    $localFile = __DIR__ . DIRECTORY_SEPARATOR . str_replace(['/', '\\'], DIRECTORY_SEPARATOR, ltrim($pageBackgroundImage, '/\\'));
    if (!is_file($localFile)) {
        $pageBgSrc = 'assets/img/landing-background.jpg';
    }
}

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

$stats = [
    ['value' => '2023', 'label' => 'Serving since'],
    ['value' => '2', 'label' => 'Experienced doctors'],
    ['value' => 'Mon–Sat', 'label' => '8:00 AM – 5:00 PM'],
    ['value' => '100%', 'label' => 'Patient-focused care'],
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
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="assets/css/app-toast.css">
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
        /* Scroll-reveal animation */
        .reveal {
            opacity: 0;
            transform: translateY(24px);
            transition: opacity 0.6s ease, transform 0.6s ease;
        }
        .reveal.is-visible {
            opacity: 1;
            transform: translateY(0);
        }
        .reveal-delay-1 { transition-delay: 0.1s; }
        .reveal-delay-2 { transition-delay: 0.2s; }
        .reveal-delay-3 { transition-delay: 0.3s; }

        /* Soft floating accent behind hero card */
        @keyframes floatSlow {
            0%, 100% { transform: translateY(0); }
            50% { transform: translateY(-10px); }
        }
        .float-slow { animation: floatSlow 6s ease-in-out infinite; }

        /* Underline grow on nav hover */
        .nav-link {
            position: relative;
        }
        .nav-link::after {
            content: '';
            position: absolute;
            left: 0;
            bottom: -4px;
            height: 2px;
            width: 0;
            background-color: #0ea5e9;
            transition: width 0.25s ease;
        }
        .nav-link:hover::after,
        .nav-link.active::after {
            width: 100%;
        }

        /* Auth dialog */
        .auth-overlay {
            position: fixed;
            inset: 0;
            z-index: 70;
            display: none;
            align-items: center;
            justify-content: center;
            padding: 1rem;
        }
        .auth-overlay.open { display: flex; }
        .auth-backdrop {
            position: absolute;
            inset: 0;
            background-color: rgba(15, 23, 42, 0.55);
            backdrop-filter: blur(3px);
        }
        .auth-panel {
            position: relative;
            z-index: 1;
            width: 100%;
            max-height: 92vh;
            overflow-y: auto;
            border-radius: 1.25rem;
            background-color: #fff;
            box-shadow: 0 25px 60px -15px rgba(15, 23, 42, 0.35);
            animation: authPanelIn 0.22s ease;
        }
        @keyframes authPanelIn {
            from { opacity: 0; transform: translateY(14px) scale(0.985); }
            to { opacity: 1; transform: translateY(0) scale(1); }
        }
        .auth-field {
            transition: border-color 0.2s ease, background-color 0.2s ease;
        }
        .auth-field:focus {
            border-color: rgb(14 165 233);
            background-color: #fff;
            box-shadow: none;
            outline: none;
        }

        @media (prefers-reduced-motion: reduce) {
            .reveal { opacity: 1; transform: none; transition: none; }
            .float-slow { animation: none; }
            .auth-panel { animation: none; }
        }
    </style>
</head>
<body class="font-sans antialiased text-slate-800 bg-white">

    <!-- Top info bar -->
    <div class="hidden border-b border-sky-100 bg-sky-50 text-xs text-slate-600 sm:block">
        <div class="mx-auto flex max-w-6xl items-center justify-between gap-4 px-4 py-2 sm:px-6">
            <div class="flex items-center gap-5">
                <span class="inline-flex items-center gap-1.5">
                    <svg class="h-3.5 w-3.5 text-sky-500" fill="none" viewBox="0 0 24 24" stroke-width="1.8" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M12 6v6h4.5m4.5 0a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                    Mon - Sat: 8:00 AM - 5:00 PM
                </span>
                <span class="inline-flex items-center gap-1.5">
                    <svg class="h-3.5 w-3.5 text-sky-500" fill="none" viewBox="0 0 24 24" stroke-width="1.8" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M15 10.5a3.75 3.75 0 11-7.5 0 3.75 3.75 0 017.5 0z"/><path stroke-linecap="round" stroke-linejoin="round" d="M19.5 10.5c0 7.142-7.5 11.25-7.5 11.25S4.5 17.642 4.5 10.5a7.5 7.5 0 1115 0z"/></svg>
                    Metz Arcade, Cagayan de Oro City
                </span>
            </div>
            <a href="tel:+63880000000" class="inline-flex items-center gap-1.5 font-semibold text-sky-600 hover:text-sky-700 transition-colors">
                <svg class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke-width="1.8" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M2.25 6.75c0 8.284 6.716 15 15 15h2.25a2.25 2.25 0 002.25-2.25v-1.372c0-.516-.351-.966-.852-1.091l-4.423-1.106c-.44-.11-.902.055-1.173.417l-.97 1.293c-.282.376-.769.542-1.21.38a12.035 12.035 0 01-7.143-7.143c-.162-.441.004-.928.38-1.21l1.293-.97c.363-.271.527-.734.417-1.173L6.963 3.102a1.125 1.125 0 00-1.091-.852H4.5A2.25 2.25 0 002.25 6.75z"/></svg>
                +63 (88) 000 0000
            </a>
        </div>
    </div>

    <!-- Header -->
    <header id="siteHeader" class="sticky top-0 z-50 border-b border-slate-100 bg-white/95 backdrop-blur-sm transition-shadow">
        <div class="mx-auto flex max-w-6xl items-center justify-between gap-4 px-4 py-3.5 sm:px-6">
            <a href="index.php" class="flex items-center gap-2.5">
                <img
                    src="<?php echo htmlspecialchars($clinicLogo, ENT_QUOTES, 'UTF-8'); ?>"
                    alt="Azucena's Dental Clinic logo"
                    class="h-9 w-9 rounded-full object-cover ring-2 ring-sky-100 sm:h-10 sm:w-10"
                    width="40"
                    height="40"
                >
                <span class="leading-tight">
                    <span class="block text-sm font-bold text-slate-900 sm:text-base">Azucena's Dental Clinic</span>
                    <span class="hidden text-[11px] font-medium uppercase tracking-wider text-sky-500 sm:block">Caring for your smile</span>
                </span>
            </a>

            <nav class="hidden items-center gap-7 text-sm font-medium text-slate-600 md:flex" aria-label="Main">
                <a href="#hero" class="nav-link hover:text-sky-500 transition-colors">Home</a>
                <a href="#services" class="nav-link hover:text-sky-500 transition-colors">Services</a>
                <a href="#about" class="nav-link hover:text-sky-500 transition-colors">About</a>
                <a href="#contact" class="nav-link hover:text-sky-500 transition-colors">Contact</a>
            </nav>

            <div class="flex items-center gap-2">
                <button type="button" data-auth-open="login" class="hidden rounded-lg border border-sky-200 px-4 py-2 text-sm font-semibold text-sky-600 hover:bg-sky-50 transition-colors sm:inline-flex">
                    Login
                </button>
                <button type="button" data-auth-open="register" class="hidden rounded-lg bg-sky-500 px-4 py-2 text-sm font-semibold text-white shadow-sm shadow-sky-200 hover:bg-sky-600 transition-colors sm:inline-flex">
                    Create Account
                </button>
                <button type="button" id="mobileMenuBtn" class="inline-flex h-10 w-10 items-center justify-center rounded-lg border border-slate-200 text-slate-600 hover:bg-slate-50 md:hidden" aria-label="Open menu" aria-expanded="false" aria-controls="mobileMenu">
                    <svg id="mobileMenuIconOpen" class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke-width="1.8" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M3.75 6.75h16.5M3.75 12h16.5m-16.5 5.25h16.5"/></svg>
                    <svg id="mobileMenuIconClose" class="hidden h-5 w-5" fill="none" viewBox="0 0 24 24" stroke-width="1.8" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12"/></svg>
                </button>
            </div>
        </div>

        <!-- Mobile menu -->
        <div id="mobileMenu" class="hidden border-t border-slate-100 bg-white md:hidden">
            <nav class="mx-auto max-w-6xl space-y-1 px-4 py-3" aria-label="Mobile">
                <a href="#hero" class="mobile-nav-link block rounded-lg px-3 py-2.5 text-sm font-medium text-slate-700 hover:bg-sky-50 hover:text-sky-600">Home</a>
                <a href="#services" class="mobile-nav-link block rounded-lg px-3 py-2.5 text-sm font-medium text-slate-700 hover:bg-sky-50 hover:text-sky-600">Services</a>
                <a href="#about" class="mobile-nav-link block rounded-lg px-3 py-2.5 text-sm font-medium text-slate-700 hover:bg-sky-50 hover:text-sky-600">About</a>
                <a href="#contact" class="mobile-nav-link block rounded-lg px-3 py-2.5 text-sm font-medium text-slate-700 hover:bg-sky-50 hover:text-sky-600">Contact</a>
                <div class="flex gap-2 pt-2">
                    <button type="button" data-auth-open="login" class="flex-1 rounded-lg border border-sky-200 px-4 py-2.5 text-center text-sm font-semibold text-sky-600">Login</button>
                    <button type="button" data-auth-open="register" class="flex-1 rounded-lg bg-sky-500 px-4 py-2.5 text-center text-sm font-semibold text-white">Create Account</button>
                </div>
            </nav>
        </div>
    </header>

    <main>
        <!-- Hero -->
        <section id="hero" class="relative overflow-hidden">
            <div class="absolute inset-0 z-0" aria-hidden="true">
                <img
                    src="<?php echo htmlspecialchars($pageBgSrc, ENT_QUOTES, 'UTF-8'); ?>"
                    alt=""
                    class="h-full w-full object-cover object-center"
                >
                <div class="absolute inset-0 bg-gradient-to-r from-white via-white/92 to-white/65"></div>
            </div>

            <div class="relative z-10 mx-auto grid max-w-6xl items-center gap-10 px-4 py-16 sm:px-6 sm:py-20 lg:grid-cols-[1.15fr,0.85fr] lg:py-28">
                <div class="reveal is-visible">
                    <span class="inline-flex items-center gap-2 rounded-full border border-sky-200 bg-sky-50 px-3.5 py-1.5 text-xs font-semibold uppercase tracking-wider text-sky-600">
                        <svg class="h-3.5 w-3.5" fill="currentColor" viewBox="0 0 24 24"><path d="M12 2l2.4 6.6L21 11l-6.6 2.4L12 20l-2.4-6.6L3 11l6.6-2.4z"/></svg>
                        Trusted dental care
                    </span>
                    <h1 class="mt-5 text-4xl font-extrabold tracking-tight text-slate-900 sm:text-5xl lg:text-[3.4rem] lg:leading-[1.1]">
                        Transform Your <span class="text-sky-500">Smile!</span>
                    </h1>
                    <p class="mt-5 max-w-xl text-base leading-relaxed text-slate-600 sm:text-lg">
                        Experience compassionate, state-of-the-art dental treatment in a comfortable environment. Your oral health is our priority.
                    </p>

                    <div class="mt-8 flex flex-wrap gap-3">
                        <button type="button" data-auth-open="register" class="group inline-flex items-center gap-2 rounded-xl bg-sky-500 px-6 py-3.5 text-sm font-semibold text-white shadow-lg shadow-sky-200 hover:bg-sky-600 hover:shadow-sky-300 transition-all">
                            <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M18 7.5v3m0 0v3m0-3h3m-3 0h-3m-2.25-4.125a3.375 3.375 0 11-6.75 0 3.375 3.375 0 016.75 0zM3 19.235v-.11a6.375 6.375 0 0112.75 0v.109A12.318 12.318 0 019.374 21c-2.331 0-4.512-.645-6.374-1.766z"/></svg>
                            Create Account
                            <svg class="h-4 w-4 transition-transform group-hover:translate-x-0.5" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M13.5 4.5L21 12m0 0l-7.5 7.5M21 12H3"/></svg>
                        </button>
                        <a href="#contact" class="inline-flex items-center gap-2 rounded-xl border-2 border-sky-500 bg-white/90 px-6 py-3.5 text-sm font-semibold text-sky-600 hover:bg-sky-50 transition-colors">
                            <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M8.625 12a.375.375 0 11-.75 0 .375.375 0 01.75 0zm0 0H8.25m4.125 0a.375.375 0 11-.75 0 .375.375 0 01.75 0zm0 0H12m4.125 0a.375.375 0 11-.75 0 .375.375 0 01.75 0zm0 0h-.375M21 12c0 4.556-4.03 8.25-9 8.25a9.764 9.764 0 01-2.555-.337A5.972 5.972 0 015.41 20.97a5.969 5.969 0 01-.474-.065 4.48 4.48 0 00.978-2.025c.09-.457-.133-.901-.467-1.226C3.93 16.178 3 14.189 3 12c0-4.556 4.03-8.25 9-8.25s9 3.694 9 8.25z"/></svg>
                            Inquire
                        </a>
                    </div>

                    <ul class="mt-9 grid gap-3 sm:grid-cols-1">
                        <?php foreach ($heroPoints as $point): ?>
                        <li class="flex items-center gap-3 text-sm text-slate-700 sm:text-base">
                            <span class="flex h-6 w-6 shrink-0 items-center justify-center rounded-full bg-sky-500 text-white shadow-sm shadow-sky-200">
                                <svg class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke-width="3" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M4.5 12.75l6 6 9-13.5"/></svg>
                            </span>
                            <?php echo htmlspecialchars($point, ENT_QUOTES, 'UTF-8'); ?>
                        </li>
                        <?php endforeach; ?>
                    </ul>
                </div>

                <!-- Hero side card -->
                <div class="reveal is-visible reveal-delay-1 hidden lg:block">
                    <div class="float-slow relative mx-auto max-w-sm">
                        <div class="absolute -inset-4 rounded-3xl bg-sky-100/60 blur-2xl" aria-hidden="true"></div>
                        <div class="relative rounded-3xl border border-sky-100 bg-white/95 p-7 shadow-xl shadow-sky-100/70 backdrop-blur">
                            <div class="flex items-center gap-3">
                                <span class="flex h-12 w-12 items-center justify-center rounded-2xl bg-sky-500 text-white shadow-md shadow-sky-200">
                                    <svg class="h-6 w-6" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M6.75 3v2.25M17.25 3v2.25M3 18.75V7.5a2.25 2.25 0 012.25-2.25h13.5A2.25 2.25 0 0121 7.5v11.25m-18 0A2.25 2.25 0 005.25 21h13.5A2.25 2.25 0 0021 18.75m-18 0v-7.5A2.25 2.25 0 015.25 9h13.5A2.25 2.25 0 0121 11.25v7.5"/></svg>
                                </span>
                                <div>
                                    <p class="text-sm font-bold text-slate-900">Easy Online Booking</p>
                                    <p class="text-xs text-slate-500">Schedule in minutes</p>
                                </div>
                            </div>
                            <div class="mt-5 space-y-3.5">
                                <div class="flex items-center justify-between rounded-xl bg-sky-50 px-4 py-3">
                                    <span class="text-xs font-medium text-slate-600">Clinic Hours</span>
                                    <span class="text-xs font-bold text-slate-900">Mon–Sat, 8AM–5PM</span>
                                </div>
                                <div class="flex items-center justify-between rounded-xl bg-sky-50 px-4 py-3">
                                    <span class="text-xs font-medium text-slate-600">Location</span>
                                    <span class="text-xs font-bold text-slate-900">Metz Arcade, CDO</span>
                                </div>
                                <div class="flex items-center justify-between rounded-xl bg-sky-50 px-4 py-3">
                                    <span class="text-xs font-medium text-slate-600">Doctors</span>
                                    <span class="text-xs font-bold text-slate-900">2 experienced dentists</span>
                                </div>
                            </div>
                            <a href="#contact" class="mt-6 block rounded-xl bg-sky-500 py-3 text-center text-sm font-semibold text-white hover:bg-sky-600 transition-colors">
                                Inquire Now
                            </a>
                        </div>
                    </div>
                </div>
            </div>
        </section>

        <!-- Stats strip -->
        <section class="border-y border-sky-100 bg-sky-500" aria-label="Clinic highlights">
            <div class="mx-auto grid max-w-6xl grid-cols-2 gap-px overflow-hidden px-4 py-8 sm:px-6 lg:grid-cols-4 lg:py-10">
                <?php foreach ($stats as $i => $stat): ?>
                <div class="reveal reveal-delay-<?php echo min($i, 3); ?> px-4 py-3 text-center">
                    <p class="text-2xl font-extrabold tracking-tight text-white sm:text-3xl"><?php echo htmlspecialchars($stat['value'], ENT_QUOTES, 'UTF-8'); ?></p>
                    <p class="mt-1 text-xs font-medium uppercase tracking-wider text-sky-100"><?php echo htmlspecialchars($stat['label'], ENT_QUOTES, 'UTF-8'); ?></p>
                </div>
                <?php endforeach; ?>
            </div>
        </section>

        <!-- Services -->
        <section id="services" class="py-16 sm:py-24">
            <div class="mx-auto max-w-6xl px-4 sm:px-6">
                <div class="reveal mx-auto max-w-2xl text-center">
                    <span class="text-xs font-bold uppercase tracking-widest text-sky-500">What we offer</span>
                    <h2 class="mt-3 text-3xl font-extrabold tracking-tight text-slate-900 sm:text-4xl">Our Services</h2>
                    <p class="mt-4 text-slate-600">Comprehensive dental care for every member of your family, delivered with skill and compassion.</p>
                </div>

                <div class="mt-12 grid gap-6 sm:grid-cols-2 lg:grid-cols-4">
                    <?php foreach ($services as $i => $service): ?>
                    <article class="reveal reveal-delay-<?php echo min($i, 3); ?> group relative overflow-hidden rounded-2xl border border-slate-100 bg-white p-7 text-center shadow-sm transition-all duration-300 hover:-translate-y-1.5 hover:border-sky-200 hover:shadow-xl hover:shadow-sky-100">
                        <div class="absolute inset-x-0 top-0 h-1 bg-sky-500 opacity-0 transition-opacity group-hover:opacity-100" aria-hidden="true"></div>
                        <div class="mx-auto mb-5 flex h-16 w-16 items-center justify-center rounded-2xl bg-sky-50 text-sky-500 transition-colors group-hover:bg-sky-500 group-hover:text-white">
                            <?php if ($service['icon'] === 'building'): ?>
                            <svg class="h-8 w-8" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M2.25 21h19.5m-18-18v18m10.5-18v18m6-13.5V21M6.75 6.75h.75m-.75 3h.75m-.75 3h.75m3-6h.75m-.75 3h.75m-.75 3h.75M6.75 21v-3.375c0-.621.504-1.125 1.125-1.125h2.25c.621 0 1.125.504 1.125 1.125V21M3.75 9h.75m-.75 3h.75m-.75 3h.75"/></svg>
                            <?php elseif ($service['icon'] === 'sparkles'): ?>
                            <svg class="h-8 w-8" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M12 3v2.25m6.364.386l-1.591 1.591M21 12h-2.25m-.386 6.364l-1.591-1.591M12 18.75V21m-4.773-4.227l-1.591 1.591M5.25 12H3m4.227-4.773L5.636 5.636M15.75 12a3.75 3.75 0 11-7.5 0 3.75 3.75 0 017.5 0z"/></svg>
                            <?php elseif ($service['icon'] === 'wrench'): ?>
                            <svg class="h-8 w-8" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M11.42 15.17L17.25 21A2.652 2.652 0 0021 17.25l-5.877-5.877M11.42 15.17l2.496-3.03c.317-.384.74-.626 1.208-.766M11.42 15.17l-4.655 5.653a2.548 2.548 0 11-3.586-3.586l6.837-5.45m5.108-.233c.55-.164 1.163-.188 1.743-.14a4.5 4.5 0 004.486-6.336l-3.276 3.277a3.004 3.004 0 01-2.25-2.25l3.276-3.276a4.5 4.5 0 00-6.336 4.486c.091 1.103-.071 2.218-.904 2.95l-1.102 1.102m-.233 5.108a2.548 2.548 0 11-3.586-3.586l5.653-4.655"/></svg>
                            <?php else: ?>
                            <svg class="h-8 w-8" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M7.848 8.25l1.536 1.536M12 3v2.25m0 13.5V21M5.106 5.106l1.06 1.06M18.894 5.106l-1.06 1.06M3 12h2.25m13.5 0H21M5.106 18.894l1.06-1.06M18.894 18.894l-1.06-1.06M9 12.75a3 3 0 106 0 3 3 0 00-6 0z"/></svg>
                            <?php endif; ?>
                        </div>
                        <h3 class="text-base font-bold text-slate-900"><?php echo htmlspecialchars($service['title'], ENT_QUOTES, 'UTF-8'); ?></h3>
                        <p class="mt-2 text-sm leading-relaxed text-slate-500"><?php echo htmlspecialchars($service['desc'], ENT_QUOTES, 'UTF-8'); ?></p>
                    </article>
                    <?php endforeach; ?>
                </div>
            </div>
        </section>

        <!-- About -->
        <section id="about" class="border-y border-slate-100 bg-slate-50/60 py-16 sm:py-24">
            <div class="mx-auto grid max-w-6xl items-center gap-12 px-4 sm:px-6 lg:grid-cols-2 lg:gap-16">
                <div class="reveal">
                    <span class="text-xs font-bold uppercase tracking-widest text-sky-500">Who we are</span>
                    <h2 class="mt-3 text-3xl font-extrabold tracking-tight text-slate-900 sm:text-4xl">About Us</h2>
                    <p class="mt-5 leading-relaxed text-slate-600">
                        Azucena Dental Clinic has been actively serving our community since 2023. With a dedicated team of 2 experienced doctors and modern technology, we focus on delivering exceptional dental care with compassion and professionalism. Every patient deserves the best treatment possible.
                    </p>
                    <ul class="mt-8 grid gap-4 sm:grid-cols-2">
                        <?php foreach ($aboutPoints as $point): ?>
                        <li class="flex items-start gap-3 rounded-xl border border-slate-100 bg-white p-4 text-sm text-slate-700 shadow-sm">
                            <span class="mt-0.5 flex h-6 w-6 shrink-0 items-center justify-center rounded-full border-2 border-sky-500 text-sky-500">
                                <svg class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke-width="2.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M4.5 12.75l6 6 9-13.5"/></svg>
                            </span>
                            <?php echo htmlspecialchars($point, ENT_QUOTES, 'UTF-8'); ?>
                        </li>
                        <?php endforeach; ?>
                    </ul>
                </div>

                <div class="reveal reveal-delay-1">
                    <div class="overflow-hidden rounded-3xl border border-slate-200 bg-white shadow-lg shadow-slate-200/60">
                        <iframe
                            title="Azucena Dental Clinic location - Metz Arcade, Cagayan de Oro City"
                            class="h-80 w-full sm:h-96"
                            loading="lazy"
                            referrerpolicy="no-referrer-when-downgrade"
                            src="https://maps.google.com/maps?q=Metz+Arcade,+Cagayan+de+Oro+City,+Philippines&amp;output=embed"
                            allowfullscreen
                        ></iframe>
                        <div class="flex items-center gap-3 border-t border-slate-100 bg-white px-5 py-4">
                            <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-xl bg-sky-50 text-sky-500">
                                <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M15 10.5a3.75 3.75 0 11-7.5 0 3.75 3.75 0 017.5 0z"/><path stroke-linecap="round" stroke-linejoin="round" d="M19.5 10.5c0 7.142-7.5 11.25-7.5 11.25S4.5 17.642 4.5 10.5a7.5 7.5 0 1115 0z"/></svg>
                            </span>
                            <p class="text-sm text-slate-600">
                                <span class="block font-semibold text-slate-900">Metz Arcade</span>
                                Cagayan de Oro City, Philippines
                            </p>
                        </div>
                    </div>
                </div>
            </div>
        </section>

        <!-- Contact -->
        <section id="contact" class="py-16 sm:py-24">
            <div class="mx-auto max-w-6xl px-4 sm:px-6">
                <div class="reveal mx-auto max-w-2xl text-center">
                    <span class="text-xs font-bold uppercase tracking-widest text-sky-500">Get in touch</span>
                    <h2 class="mt-3 text-3xl font-extrabold tracking-tight text-slate-900 sm:text-4xl">Contact Us</h2>
                    <p class="mt-4 text-slate-600">Questions about treatments or appointments? Send us a message and we'll get back to you.</p>
                </div>

                <div class="mt-12 grid gap-8 lg:grid-cols-[0.9fr,1.1fr]">
                    <div class="reveal space-y-4">
                        <div class="flex gap-4 rounded-2xl border border-slate-100 bg-white p-5 shadow-sm transition-shadow hover:shadow-md">
                            <span class="flex h-11 w-11 shrink-0 items-center justify-center rounded-xl bg-sky-500 text-white shadow-sm shadow-sky-200">
                                <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M15 10.5a3.75 3.75 0 11-7.5 0 3.75 3.75 0 017.5 0z"/><path stroke-linecap="round" stroke-linejoin="round" d="M19.5 10.5c0 7.142-7.5 11.25-7.5 11.25S4.5 17.642 4.5 10.5a7.5 7.5 0 1115 0z"/></svg>
                            </span>
                            <div>
                                <p class="font-semibold text-slate-900">Clinic Address</p>
                                <p class="mt-1 text-sm text-slate-600">Metz Arcade, Cagayan de Oro City, Philippines</p>
                            </div>
                        </div>

                        <div class="flex gap-4 rounded-2xl border border-slate-100 bg-white p-5 shadow-sm transition-shadow hover:shadow-md">
                            <span class="flex h-11 w-11 shrink-0 items-center justify-center rounded-xl bg-sky-500 text-white shadow-sm shadow-sky-200">
                                <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M2.25 6.75c0 8.284 6.716 15 15 15h2.25a2.25 2.25 0 002.25-2.25v-1.372c0-.516-.351-.966-.852-1.091l-4.423-1.106c-.44-.11-.902.055-1.173.417l-.97 1.293c-.282.376-.769.542-1.21.38a12.035 12.035 0 01-7.143-7.143c-.162-.441.004-.928.38-1.21l1.293-.97c.363-.271.527-.734.417-1.173L6.963 3.102a1.125 1.125 0 00-1.091-.852H4.5A2.25 2.25 0 002.25 6.75z"/></svg>
                            </span>
                            <div>
                                <p class="font-semibold text-slate-900">Phone</p>
                                <p class="mt-1 text-sm text-slate-600">+63 (88) 000 0000</p>
                            </div>
                        </div>

                        <div class="flex gap-4 rounded-2xl border border-slate-100 bg-white p-5 shadow-sm transition-shadow hover:shadow-md">
                            <span class="flex h-11 w-11 shrink-0 items-center justify-center rounded-xl bg-sky-500 text-white shadow-sm shadow-sky-200">
                                <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M21.75 6.75v10.5a2.25 2.25 0 01-2.25 2.25h-15a2.25 2.25 0 01-2.25-2.25V6.75m19.5 0A2.25 2.25 0 0019.5 4.5h-15a2.25 2.25 0 00-2.25 2.25m19.5 0v.243a2.25 2.25 0 01-1.07 1.916l-7.5 4.615a2.25 2.25 0 01-2.36 0L3.32 8.91a2.25 2.25 0 01-1.07-1.916V6.75"/></svg>
                            </span>
                            <div>
                                <p class="font-semibold text-slate-900">Email</p>
                                <p class="mt-1 text-sm text-slate-600">info@azucenadental.ph</p>
                            </div>
                        </div>

                        <div class="flex gap-4 rounded-2xl border border-slate-100 bg-white p-5 shadow-sm transition-shadow hover:shadow-md">
                            <span class="flex h-11 w-11 shrink-0 items-center justify-center rounded-xl bg-sky-500 text-white shadow-sm shadow-sky-200">
                                <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M12 6v6h4.5m4.5 0a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                            </span>
                            <div>
                                <p class="font-semibold text-slate-900">Clinic Hours</p>
                                <p class="mt-1 text-sm text-slate-600">Mon - Sat: 8:00 AM - 5:00 PM</p>
                            </div>
                        </div>
                    </div>

                    <form id="contact-form" class="reveal reveal-delay-1 rounded-3xl border border-slate-200 bg-white p-6 shadow-lg shadow-slate-200/50 sm:p-8" novalidate>
                        <h3 class="text-lg font-bold text-slate-900">Send us a message</h3>
                        <div id="contact-form-alert" class="hidden mt-4 rounded-xl border px-4 py-3 text-sm" role="alert"></div>
                        <div class="mt-5 space-y-4">
                            <div class="grid gap-4 sm:grid-cols-2">
                                <div>
                                    <label for="contact-name" class="block text-sm font-medium text-slate-700">Full Name</label>
                                    <input type="text" id="contact-name" name="name" required class="mt-1.5 w-full rounded-xl border border-slate-200 px-4 py-2.5 text-sm transition-colors focus:border-sky-500 focus:ring-2 focus:ring-sky-200 outline-none">
                                </div>
                                <div>
                                    <label for="contact-phone" class="block text-sm font-medium text-slate-700">Phone Number</label>
                                    <input type="tel" id="contact-phone" name="phone" required inputmode="tel" autocomplete="tel" placeholder="e.g. 0917 123 4567" class="mt-1.5 w-full rounded-xl border border-slate-200 px-4 py-2.5 text-sm transition-colors focus:border-sky-500 focus:ring-2 focus:ring-sky-200 outline-none">
                                </div>
                            </div>
                            <div>
                                <label for="contact-email" class="block text-sm font-medium text-slate-700">Email</label>
                                <input type="email" id="contact-email" name="email" required class="mt-1.5 w-full rounded-xl border border-slate-200 px-4 py-2.5 text-sm transition-colors focus:border-sky-500 focus:ring-2 focus:ring-sky-200 outline-none">
                            </div>
                            <div>
                                <label for="contact-message" class="block text-sm font-medium text-slate-700">Message</label>
                                <textarea id="contact-message" name="message" rows="5" required class="mt-1.5 w-full rounded-xl border border-slate-200 px-4 py-2.5 text-sm transition-colors focus:border-sky-500 focus:ring-2 focus:ring-sky-200 outline-none resize-y" placeholder="How can we help you?"></textarea>
                            </div>
                            <button type="submit" id="contact-form-submit" class="w-full rounded-xl bg-sky-500 py-3.5 text-sm font-semibold text-white shadow-md shadow-sky-200 hover:bg-sky-600 transition-colors">
                                Send Message
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </section>

        <!-- CTA band -->
        <section class="border-t border-sky-100 bg-gradient-to-r from-sky-500 to-sky-600">
            <div class="mx-auto flex max-w-6xl flex-col items-center justify-between gap-6 px-4 py-12 text-center sm:px-6 lg:flex-row lg:text-left">
                <div class="reveal">
                    <h2 class="text-2xl font-extrabold text-white sm:text-3xl">Ready to transform your smile?</h2>
                    <p class="mt-2 text-sky-100">Create your account or send us an inquiry — your oral health is our priority.</p>
                </div>
                <div class="reveal reveal-delay-1 flex shrink-0 flex-wrap justify-center gap-3">
                    <button type="button" data-auth-open="register" class="inline-flex items-center gap-2 rounded-xl bg-white px-7 py-3.5 text-sm font-bold text-sky-600 shadow-lg hover:bg-sky-50 transition-colors">
                        Create Account
                        <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M13.5 4.5L21 12m0 0l-7.5 7.5M21 12H3"/></svg>
                    </button>
                    <a href="#contact" class="inline-flex items-center gap-2 rounded-xl border-2 border-white/80 px-7 py-3.5 text-sm font-bold text-white hover:bg-white/10 transition-colors">
                        Inquire
                    </a>
                </div>
            </div>
        </section>
    </main>

    <!-- Footer -->
    <footer class="bg-slate-50">
        <div class="mx-auto grid max-w-6xl gap-10 px-4 py-12 sm:px-6 sm:py-14 lg:grid-cols-[1.2fr,0.8fr,1fr]">
            <div>
                <a href="index.php" class="flex items-center gap-2.5">
                    <img
                        src="<?php echo htmlspecialchars($clinicLogo, ENT_QUOTES, 'UTF-8'); ?>"
                        alt="Azucena's Dental Clinic logo"
                        class="h-10 w-10 rounded-full object-cover ring-2 ring-sky-100"
                        width="40"
                        height="40"
                    >
                    <span class="text-base font-bold text-slate-900">Azucena's Dental Clinic</span>
                </a>
                <p class="mt-4 max-w-sm text-sm leading-relaxed text-slate-500">
                    Compassionate, state-of-the-art dental treatment in a comfortable environment. Serving Cagayan de Oro City since 2023.
                </p>
            </div>
            <div>
                <p class="text-sm font-bold uppercase tracking-wider text-slate-900">Quick Links</p>
                <ul class="mt-4 space-y-2.5 text-sm text-slate-500">
                    <li><a href="#hero" class="hover:text-sky-500 transition-colors">Home</a></li>
                    <li><a href="#services" class="hover:text-sky-500 transition-colors">Services</a></li>
                    <li><a href="#about" class="hover:text-sky-500 transition-colors">About</a></li>
                    <li><a href="#contact" class="hover:text-sky-500 transition-colors">Contact</a></li>
                    <li><button type="button" data-auth-open="login" class="hover:text-sky-500 transition-colors">Patient Login</button></li>
                    <li><button type="button" data-auth-open="register" class="hover:text-sky-500 transition-colors">Create Account</button></li>
                </ul>
            </div>
            <div>
                <p class="text-sm font-bold uppercase tracking-wider text-slate-900">Visit Us</p>
                <ul class="mt-4 space-y-3 text-sm text-slate-500">
                    <li class="flex items-start gap-2.5">
                        <svg class="mt-0.5 h-4 w-4 shrink-0 text-sky-500" fill="none" viewBox="0 0 24 24" stroke-width="1.8" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M15 10.5a3.75 3.75 0 11-7.5 0 3.75 3.75 0 017.5 0z"/><path stroke-linecap="round" stroke-linejoin="round" d="M19.5 10.5c0 7.142-7.5 11.25-7.5 11.25S4.5 17.642 4.5 10.5a7.5 7.5 0 1115 0z"/></svg>
                        Metz Arcade, Cagayan de Oro City, Philippines
                    </li>
                    <li class="flex items-start gap-2.5">
                        <svg class="mt-0.5 h-4 w-4 shrink-0 text-sky-500" fill="none" viewBox="0 0 24 24" stroke-width="1.8" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M2.25 6.75c0 8.284 6.716 15 15 15h2.25a2.25 2.25 0 002.25-2.25v-1.372c0-.516-.351-.966-.852-1.091l-4.423-1.106c-.44-.11-.902.055-1.173.417l-.97 1.293c-.282.376-.769.542-1.21.38a12.035 12.035 0 01-7.143-7.143c-.162-.441.004-.928.38-1.21l1.293-.97c.363-.271.527-.734.417-1.173L6.963 3.102a1.125 1.125 0 00-1.091-.852H4.5A2.25 2.25 0 002.25 6.75z"/></svg>
                        +63 (88) 000 0000
                    </li>
                    <li class="flex items-start gap-2.5">
                        <svg class="mt-0.5 h-4 w-4 shrink-0 text-sky-500" fill="none" viewBox="0 0 24 24" stroke-width="1.8" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M21.75 6.75v10.5a2.25 2.25 0 01-2.25 2.25h-15a2.25 2.25 0 01-2.25-2.25V6.75m19.5 0A2.25 2.25 0 0019.5 4.5h-15a2.25 2.25 0 00-2.25 2.25m19.5 0v.243a2.25 2.25 0 01-1.07 1.916l-7.5 4.615a2.25 2.25 0 01-2.36 0L3.32 8.91a2.25 2.25 0 01-1.07-1.916V6.75"/></svg>
                        info@azucenadental.ph
                    </li>
                    <li class="flex items-start gap-2.5">
                        <svg class="mt-0.5 h-4 w-4 shrink-0 text-sky-500" fill="none" viewBox="0 0 24 24" stroke-width="1.8" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M12 6v6h4.5m4.5 0a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                        Mon - Sat: 8:00 AM - 5:00 PM
                    </li>
                </ul>
            </div>
        </div>
        <div class="border-t border-slate-200 py-5 text-center text-sm text-slate-500">
            <p>&copy; <?php echo $year; ?> Azucena's Dental Clinic. All rights reserved.</p>
        </div>
    </footer>

    <!-- Account dialogs -->
    <div id="authModal" class="auth-overlay" role="dialog" aria-modal="true" aria-hidden="true">
        <div class="auth-backdrop" data-auth-close></div>

        <!-- Login -->
        <section id="authPanelLogin" class="auth-panel hidden max-w-md" aria-label="Login">
            <div class="flex items-start justify-between border-b border-slate-100 px-6 py-5 sm:px-8">
                <div class="flex items-center gap-3">
                    <img src="<?php echo htmlspecialchars($clinicLogo, ENT_QUOTES, 'UTF-8'); ?>" alt="" class="h-11 w-11 rounded-full object-cover ring-2 ring-sky-100" width="44" height="44">
                    <div>
                        <h2 class="text-base font-bold text-slate-900">Welcome back</h2>
                        <p class="text-xs text-slate-500">Sign in to your account</p>
                    </div>
                </div>
                <button type="button" data-auth-close class="rounded-lg p-1.5 text-slate-400 hover:bg-slate-100 hover:text-slate-600 transition-colors" aria-label="Close">
                    <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke-width="1.8" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12"/></svg>
                </button>
            </div>

            <form id="login-form" class="space-y-5 px-6 py-6 sm:px-8" novalidate>
                <div class="space-y-1.5">
                    <label for="login-email" class="block text-sm font-medium text-slate-700">Email</label>
                    <div class="relative">
                        <span class="pointer-events-none absolute inset-y-0 left-0 flex items-center pl-3.5 text-slate-400">
                            <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M21.75 6.75v10.5a2.25 2.25 0 01-2.25 2.25h-15a2.25 2.25 0 01-2.25-2.25V6.75m19.5 0A2.25 2.25 0 0019.5 4.5h-15a2.25 2.25 0 00-2.25 2.25m19.5 0v.243a2.25 2.25 0 01-1.07 1.916l-7.5 4.615a2.25 2.25 0 01-2.36 0L3.32 8.91a2.25 2.25 0 01-1.07-1.916V6.75"/></svg>
                        </span>
                        <input type="email" id="login-email" name="email" autocomplete="email" required placeholder="Enter your email"
                            class="auth-field block w-full rounded-xl border border-slate-200 bg-slate-50/50 py-3 pl-11 pr-4 text-sm text-slate-900 placeholder:text-slate-400">
                    </div>
                </div>

                <div class="space-y-1.5">
                    <label for="login-password" class="block text-sm font-medium text-slate-700">Password</label>
                    <div class="relative">
                        <span class="pointer-events-none absolute inset-y-0 left-0 flex items-center pl-3.5 text-slate-400">
                            <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M16.5 10.5V6.75a4.5 4.5 0 10-9 0v3.75m-.75 11.25h10.5a2.25 2.25 0 002.25-2.25v-6.75a2.25 2.25 0 00-2.25-2.25H6.75a2.25 2.25 0 00-2.25 2.25v6.75a2.25 2.25 0 002.25 2.25z"/></svg>
                        </span>
                        <input type="password" id="login-password" name="password" autocomplete="current-password" required placeholder="Enter your password"
                            class="auth-field block w-full rounded-xl border border-slate-200 bg-slate-50/50 py-3 pl-11 pr-12 text-sm text-slate-900 placeholder:text-slate-400">
                        <button type="button" data-toggle-password="login-password" class="absolute inset-y-0 right-0 flex items-center pr-3.5 text-slate-400 hover:text-sky-500 transition-colors" aria-label="Show password" aria-pressed="false">
                            <svg data-icon="eye" class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M2.036 12.322a1.012 1.012 0 010-.639C3.423 7.51 7.36 4.5 12 4.5c4.638 0 8.573 3.007 9.963 7.178.07.207.07.431 0 .639C20.577 16.49 16.64 19.5 12 19.5c-4.638 0-8.573-3.007-9.963-7.178z"/><path stroke-linecap="round" stroke-linejoin="round" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/></svg>
                            <svg data-icon="eye-off" class="hidden h-5 w-5" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M3.98 8.223A10.477 10.477 0 001.934 12C3.226 16.338 7.244 19.5 12 19.5c.993 0 1.953-.138 2.863-.395M6.228 6.228A10.45 10.45 0 0112 4.5c4.756 0 8.773 3.162 10.065 7.498a10.523 10.523 0 01-4.293 5.774M6.228 6.228L3 3m3.228 3.228l3.65 3.65m7.894 7.894L21 21m-3.228-3.228l-3.65-3.65m0 0a3 3 0 10-4.243-4.243m4.242 4.242L9.88 9.88"/></svg>
                        </button>
                    </div>
                </div>

                <div class="space-y-2">
                    <label class="block text-sm font-medium text-slate-700">Captcha</label>
                    <div class="flex flex-wrap items-center gap-3">
                        <span id="loginCaptchaQuestion" class="rounded-lg border border-slate-200 bg-slate-50 px-3 py-2 text-sm text-slate-700">Loading...</span>
                        <button type="button" id="refreshLoginCaptchaBtn" class="text-sm font-semibold text-sky-500 hover:text-sky-600">Refresh</button>
                    </div>
                    <input type="text" id="loginCaptchaAnswer" name="captcha" inputmode="numeric" pattern="-?[0-9]+" required placeholder="Enter the captcha answer"
                        class="auth-field block w-full rounded-xl border border-slate-200 bg-slate-50/50 py-3 px-4 text-sm text-slate-900 placeholder:text-slate-400">
                </div>

                <div class="flex flex-wrap items-center justify-between gap-3 text-sm">
                    <button type="button" data-auth-switch="forgot" class="font-medium text-sky-500 hover:text-sky-600 transition-colors">Forgot Password</button>
                    <button type="button" data-auth-switch="register" class="font-medium text-sky-500 hover:text-sky-600 transition-colors">Create Account</button>
                </div>

                <button type="submit" class="w-full rounded-xl bg-sky-500 px-4 py-3.5 text-sm font-semibold text-white hover:bg-sky-600 focus:outline-none focus:ring-2 focus:ring-sky-400 focus:ring-offset-2 transition-colors disabled:opacity-60 disabled:cursor-not-allowed">
                    Login to Dashboard
                </button>
            </form>

            <footer class="border-t border-slate-100 bg-slate-50/80 px-6 py-4 sm:px-8">
                <p class="text-center text-[10px] leading-relaxed text-slate-500 sm:text-xs">
                    Secure portal access - compliant with the Philippine Data Privacy Act of 2012 (RA 10173)
                </p>
            </footer>
        </section>

        <!-- Create Account -->
        <section id="authPanelRegister" class="auth-panel hidden max-w-2xl" aria-label="Create account">
            <div class="flex items-start justify-between border-b border-slate-100 px-6 py-5 sm:px-8">
                <div class="flex items-center gap-3">
                    <img src="<?php echo htmlspecialchars($clinicLogo, ENT_QUOTES, 'UTF-8'); ?>" alt="" class="h-11 w-11 rounded-full object-cover ring-2 ring-sky-100" width="44" height="44">
                    <div>
                        <h2 class="text-base font-bold text-slate-900">Create Account</h2>
                        <p class="text-xs text-slate-500">Register as a patient of Azucena's Dental Clinic</p>
                    </div>
                </div>
                <button type="button" data-auth-close class="rounded-lg p-1.5 text-slate-400 hover:bg-slate-100 hover:text-slate-600 transition-colors" aria-label="Close">
                    <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke-width="1.8" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12"/></svg>
                </button>
            </div>

            <form id="register-form" class="space-y-5 px-6 py-6 sm:px-8" novalidate>
                <div class="grid gap-5 sm:grid-cols-2">
                    <div>
                        <label for="reg-first-name" class="mb-1.5 block text-sm font-medium text-slate-700">First Name</label>
                        <input type="text" id="reg-first-name" name="first_name" required placeholder="First name"
                            class="auth-field w-full rounded-xl border border-slate-200 bg-slate-50/50 px-4 py-3 text-sm placeholder:text-slate-400">
                    </div>
                    <div>
                        <label for="reg-last-name" class="mb-1.5 block text-sm font-medium text-slate-700">Last Name</label>
                        <input type="text" id="reg-last-name" name="last_name" required placeholder="Last name"
                            class="auth-field w-full rounded-xl border border-slate-200 bg-slate-50/50 px-4 py-3 text-sm placeholder:text-slate-400">
                    </div>
                </div>

                <div>
                    <label for="reg-email" class="mb-1.5 block text-sm font-medium text-slate-700">Email Address</label>
                    <input type="email" id="reg-email" name="email" required placeholder="you@example.com" autocomplete="email"
                        class="auth-field w-full rounded-xl border border-slate-200 bg-slate-50/50 px-4 py-3 text-sm placeholder:text-slate-400">
                </div>

                <div class="grid gap-5 sm:grid-cols-2">
                    <div>
                        <label for="reg-dob" class="mb-1.5 block text-sm font-medium text-slate-700">Date of Birth</label>
                        <input type="date" id="reg-dob" name="dob" required
                            class="auth-field w-full rounded-xl border border-slate-200 bg-slate-50/50 px-4 py-3 text-sm text-slate-700">
                    </div>
                    <div>
                        <label for="reg-gender" class="mb-1.5 block text-sm font-medium text-slate-700">Gender</label>
                        <select id="reg-gender" name="gender" required
                            class="auth-field w-full rounded-xl border border-slate-200 bg-slate-50/50 px-4 py-3 text-sm text-slate-700">
                            <option value="" disabled selected>Select</option>
                            <option value="male">Male</option>
                            <option value="female">Female</option>
                            <option value="other">Other</option>
                            <option value="prefer_not">Prefer not to say</option>
                        </select>
                    </div>
                </div>

                <div class="grid gap-5 sm:grid-cols-2">
                    <div>
                        <label for="reg-phone" class="mb-1.5 block text-sm font-medium text-slate-700">Phone Number</label>
                        <input type="tel" id="reg-phone" name="phone" required placeholder="e.g. 0917 123 4567" autocomplete="tel"
                            class="auth-field w-full rounded-xl border border-slate-200 bg-slate-50/50 px-4 py-3 text-sm placeholder:text-slate-400">
                    </div>
                    <div>
                        <label for="reg-address" class="mb-1.5 block text-sm font-medium text-slate-700">Address</label>
                        <input type="text" id="reg-address" name="address" required placeholder="House no., street, barangay, city" autocomplete="street-address"
                            class="auth-field w-full rounded-xl border border-slate-200 bg-slate-50/50 px-4 py-3 text-sm placeholder:text-slate-400">
                    </div>
                </div>

                <div class="grid gap-5 sm:grid-cols-2">
                    <div>
                        <label for="reg-password" class="mb-1.5 block text-sm font-medium text-slate-700">Password</label>
                        <div class="relative">
                            <input type="password" id="reg-password" name="password" required minlength="8" autocomplete="new-password" placeholder="Create a password"
                                class="auth-field w-full rounded-xl border border-slate-200 bg-slate-50/50 px-4 py-3 pr-12 text-sm placeholder:text-slate-400">
                            <button type="button" data-toggle-password="reg-password" class="absolute inset-y-0 right-0 flex items-center pr-3.5 text-slate-400 hover:text-sky-500 transition-colors" aria-label="Show password" aria-pressed="false">
                                <svg data-icon="eye" class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M2.036 12.322a1.012 1.012 0 010-.639C3.423 7.51 7.36 4.5 12 4.5c4.638 0 8.573 3.007 9.963 7.178.07.207.07.431 0 .639C20.577 16.49 16.64 19.5 12 19.5c-4.638 0-8.573-3.007-9.963-7.178z"/><path stroke-linecap="round" stroke-linejoin="round" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/></svg>
                                <svg data-icon="eye-off" class="hidden h-5 w-5" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M3.98 8.223A10.477 10.477 0 001.934 12C3.226 16.338 7.244 19.5 12 19.5c.993 0 1.953-.138 2.863-.395M6.228 6.228A10.45 10.45 0 0112 4.5c4.756 0 8.773 3.162 10.065 7.498a10.523 10.523 0 01-4.293 5.774M6.228 6.228L3 3m3.228 3.228l3.65 3.65m7.894 7.894L21 21m-3.228-3.228l-3.65-3.65m0 0a3 3 0 10-4.243-4.243m4.242 4.242L9.88 9.88"/></svg>
                            </button>
                        </div>
                    </div>
                    <div>
                        <label for="reg-confirm-password" class="mb-1.5 block text-sm font-medium text-slate-700">Confirm Password</label>
                        <input type="password" id="reg-confirm-password" name="confirm_password" required minlength="8" autocomplete="new-password" placeholder="Re-enter password"
                            class="auth-field w-full rounded-xl border border-slate-200 bg-slate-50/50 px-4 py-3 text-sm placeholder:text-slate-400">
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
                    <input type="checkbox" id="reg-terms" class="h-4 w-4 rounded border-slate-300 text-sky-500 focus:ring-sky-400">
                    I agree to the Terms and Conditions
                </label>

                <button type="submit" class="block w-full rounded-xl bg-sky-500 px-6 py-3.5 text-sm font-bold text-white hover:bg-sky-600 focus:outline-none focus:ring-2 focus:ring-sky-400 focus:ring-offset-2 transition-colors">
                    Create Account
                </button>

                <p class="text-center text-sm text-slate-600">
                    Already have an account?
                    <button type="button" data-auth-switch="login" class="font-bold text-sky-600 hover:text-sky-700">Login here</button>
                </p>
            </form>
        </section>

        <!-- Password recovery -->
        <section id="authPanelForgot" class="auth-panel hidden max-w-md" aria-label="Password recovery">
            <div class="flex items-start justify-between border-b border-slate-100 px-6 py-5 sm:px-8">
                <div class="flex items-center gap-3">
                    <img src="<?php echo htmlspecialchars($clinicLogo, ENT_QUOTES, 'UTF-8'); ?>" alt="" class="h-11 w-11 rounded-full object-cover ring-2 ring-sky-100" width="44" height="44">
                    <div>
                        <h2 class="text-base font-bold text-slate-900">Password Recovery</h2>
                        <p class="text-xs text-slate-500">Enter your email to receive an OTP, then set a new password</p>
                    </div>
                </div>
                <button type="button" data-auth-close class="rounded-lg p-1.5 text-slate-400 hover:bg-slate-100 hover:text-slate-600 transition-colors" aria-label="Close">
                    <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke-width="1.8" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12"/></svg>
                </button>
            </div>

            <form id="reset-form" class="space-y-4 px-6 py-6 sm:px-8">
                <input type="email" id="reset-email" required placeholder="Email Address"
                    class="auth-field w-full rounded-xl border border-slate-200 bg-slate-50/50 px-4 py-3 text-sm placeholder:text-slate-400">
                <button type="button" id="send-otp" class="w-full rounded-xl border border-sky-200 bg-sky-50 py-3 text-sm font-semibold text-sky-600 hover:bg-sky-100 transition-colors">
                    Send OTP
                </button>

                <div id="reset-section" class="space-y-4 hidden">
                    <input type="text" id="reset-otp" required placeholder="OTP Code"
                        class="auth-field w-full rounded-xl border border-slate-200 bg-slate-50/50 px-4 py-3 text-sm placeholder:text-slate-400">
                    <div class="relative">
                        <input type="password" id="reset-password" required placeholder="New Password"
                            class="auth-field w-full rounded-xl border border-slate-200 bg-slate-50/50 px-4 py-3 pr-12 text-sm placeholder:text-slate-400">
                        <button type="button" data-toggle-password="reset-password" class="absolute inset-y-0 right-0 flex items-center pr-3.5 text-slate-400 hover:text-sky-500 transition-colors" aria-label="Show password" aria-pressed="false">
                            <svg data-icon="eye" class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M2.036 12.322a1.012 1.012 0 010-.639C3.423 7.51 7.36 4.5 12 4.5c4.638 0 8.573 3.007 9.963 7.178.07.207.07.431 0 .639C20.577 16.49 16.64 19.5 12 19.5c-4.638 0-8.573-3.007-9.963-7.178z"/><path stroke-linecap="round" stroke-linejoin="round" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/></svg>
                            <svg data-icon="eye-off" class="hidden h-5 w-5" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M3.98 8.223A10.477 10.477 0 001.934 12C3.226 16.338 7.244 19.5 12 19.5c.993 0 1.953-.138 2.863-.395M6.228 6.228A10.45 10.45 0 0112 4.5c4.756 0 8.773 3.162 10.065 7.498a10.523 10.523 0 01-4.293 5.774M6.228 6.228L3 3m3.228 3.228l3.65 3.65m7.894 7.894L21 21m-3.228-3.228l-3.65-3.65m0 0a3 3 0 10-4.243-4.243m4.242 4.242L9.88 9.88"/></svg>
                        </button>
                    </div>
                    <input type="password" id="reset-confirm" required placeholder="Confirm Password"
                        class="auth-field w-full rounded-xl border border-slate-200 bg-slate-50/50 px-4 py-3 text-sm placeholder:text-slate-400">
                    <button type="submit" class="w-full rounded-xl bg-sky-500 py-3 text-sm font-semibold text-white hover:bg-sky-600 transition-colors">
                        Reset Password
                    </button>
                </div>

                <p class="pt-1 text-center">
                    <button type="button" data-auth-switch="login" class="text-sm font-medium text-sky-500 hover:text-sky-600">Back to Login</button>
                </p>
            </form>
        </section>
    </div>

    <!-- Back to top -->
    <button type="button" id="backToTopBtn" class="fixed bottom-6 right-6 z-40 hidden h-11 w-11 items-center justify-center rounded-full bg-sky-500 text-white shadow-lg shadow-sky-300/60 hover:bg-sky-600 transition-colors" aria-label="Back to top">
        <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M4.5 15.75l7.5-7.5 7.5 7.5"/></svg>
    </button>

    <script src="assets/js/app-toast.js?v=20260608"></script>
    <script>
        const contactCsrfToken = <?php echo json_encode($csrf_token); ?>;
        const csrfToken = contactCsrfToken;

        function setAlert(message, type) {
            const tone = type === 'error' ? 'danger' : (type || 'info');
            if (typeof window.showAppToast === 'function') {
                window.showAppToast(String(message || ''), tone);
            }
        }

        // ----- Auth dialogs -----
        const authModal = document.getElementById('authModal');
        const authPanels = {
            login: document.getElementById('authPanelLogin'),
            register: document.getElementById('authPanelRegister'),
            forgot: document.getElementById('authPanelForgot')
        };
        let loginCaptchaLoaded = false;

        function openAuthModal(name) {
            if (!authModal || !authPanels[name]) return;
            Object.keys(authPanels).forEach(function(key) {
                authPanels[key].classList.toggle('hidden', key !== name);
            });
            authModal.classList.add('open');
            authModal.setAttribute('aria-hidden', 'false');
            document.body.style.overflow = 'hidden';

            if (name === 'login' && !loginCaptchaLoaded) {
                loginCaptchaLoaded = true;
                refreshLoginCaptcha();
            }

            const firstInput = authPanels[name].querySelector('input');
            if (firstInput) setTimeout(function() { firstInput.focus(); }, 80);
        }

        function closeAuthModal() {
            if (!authModal) return;
            authModal.classList.remove('open');
            authModal.setAttribute('aria-hidden', 'true');
            document.body.style.overflow = '';
        }

        document.querySelectorAll('[data-auth-open]').forEach(function(btn) {
            btn.addEventListener('click', function() {
                setMobileMenu(false);
                openAuthModal(btn.getAttribute('data-auth-open'));
            });
        });

        document.querySelectorAll('[data-auth-switch]').forEach(function(btn) {
            btn.addEventListener('click', function() {
                openAuthModal(btn.getAttribute('data-auth-switch'));
            });
        });

        document.querySelectorAll('[data-auth-close]').forEach(function(el) {
            el.addEventListener('click', closeAuthModal);
        });

        document.addEventListener('keydown', function(event) {
            if (event.key === 'Escape' && authModal?.classList.contains('open')) {
                closeAuthModal();
            }
        });

        // ----- Password visibility toggles -----
        document.querySelectorAll('[data-toggle-password]').forEach(function(toggleBtn) {
            const input = document.getElementById(toggleBtn.getAttribute('data-toggle-password'));
            const iconEye = toggleBtn.querySelector('[data-icon="eye"]');
            const iconEyeOff = toggleBtn.querySelector('[data-icon="eye-off"]');
            if (!input) return;

            toggleBtn.addEventListener('click', function() {
                const isHidden = input.type === 'password';
                input.type = isHidden ? 'text' : 'password';
                toggleBtn.setAttribute('aria-pressed', isHidden ? 'true' : 'false');
                toggleBtn.setAttribute('aria-label', isHidden ? 'Hide password' : 'Show password');
                if (iconEye && iconEyeOff) {
                    iconEye.classList.toggle('hidden', isHidden);
                    iconEyeOff.classList.toggle('hidden', !isHidden);
                }
            });
        });

        // ----- Login captcha -----
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

            setLoginCaptchaQuestion('Refresh to generate captcha');
            if (answerInput) answerInput.value = '';
        }

        document.getElementById('refreshLoginCaptchaBtn')?.addEventListener('click', function() {
            refreshLoginCaptcha();
        });

        // ----- Login -----
        document.getElementById('login-form')?.addEventListener('submit', async function(event) {
            event.preventDefault();

            const email = document.getElementById('login-email').value.trim();
            const password = document.getElementById('login-password').value;
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

                    setTimeout(function() {
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

        // ----- Create account -----
        function validatePasswordPolicy(password) {
            if (password.length < 8) return 'Password must be at least 8 characters.';
            if (!/[A-Z]/.test(password)) return 'Password must include at least one uppercase letter.';
            if (!/[a-z]/.test(password)) return 'Password must include at least one lowercase letter.';
            if (!/\d/.test(password)) return 'Password must include at least one number.';
            if (!/[^A-Za-z0-9]/.test(password)) return 'Password must include at least one special character.';
            return '';
        }

        document.getElementById('register-form')?.addEventListener('submit', async function(event) {
            event.preventDefault();

            const firstName = document.getElementById('reg-first-name').value.trim();
            const lastName = document.getElementById('reg-last-name').value.trim();
            const email = document.getElementById('reg-email').value.trim();
            const dob = document.getElementById('reg-dob').value;
            const gender = document.getElementById('reg-gender').value;
            const phone = document.getElementById('reg-phone').value.trim();
            const address = document.getElementById('reg-address').value.trim();
            const password = document.getElementById('reg-password').value;
            const confirmPassword = document.getElementById('reg-confirm-password').value;
            const termsAccepted = document.getElementById('reg-terms').checked;
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
                    setTimeout(function() {
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

        // ----- Password recovery -----
        const resetSection = document.getElementById('reset-section');

        document.getElementById('send-otp')?.addEventListener('click', async function() {
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
                    resetSection?.classList.remove('hidden');
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

        document.getElementById('reset-form')?.addEventListener('submit', async function(event) {
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
                    document.getElementById('reset-form').reset();
                    resetSection?.classList.add('hidden');
                    setTimeout(function() {
                        openAuthModal('login');
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

        // Session timeout notice (redirected here from a protected page)
        if (new URLSearchParams(window.location.search).has('timeout')) {
            setAlert('Your session has expired. Please log in again.', 'warning');
            openAuthModal('login');
            window.history.replaceState({}, '', 'index.php');
        }

        // ----- Mobile menu -----
        const mobileMenuBtn = document.getElementById('mobileMenuBtn');
        const mobileMenu = document.getElementById('mobileMenu');
        const iconOpen = document.getElementById('mobileMenuIconOpen');
        const iconClose = document.getElementById('mobileMenuIconClose');

        function setMobileMenu(open) {
            if (!mobileMenu || !mobileMenuBtn) return;
            mobileMenu.classList.toggle('hidden', !open);
            iconOpen?.classList.toggle('hidden', open);
            iconClose?.classList.toggle('hidden', !open);
            mobileMenuBtn.setAttribute('aria-expanded', open ? 'true' : 'false');
        }

        mobileMenuBtn?.addEventListener('click', function() {
            setMobileMenu(mobileMenu.classList.contains('hidden'));
        });

        document.querySelectorAll('.mobile-nav-link').forEach(function(link) {
            link.addEventListener('click', function() { setMobileMenu(false); });
        });

        // ----- Header shadow on scroll + back-to-top visibility -----
        const siteHeader = document.getElementById('siteHeader');
        const backToTopBtn = document.getElementById('backToTopBtn');

        function onScroll() {
            const y = window.scrollY || 0;
            siteHeader?.classList.toggle('shadow-md', y > 8);
            if (backToTopBtn) {
                backToTopBtn.classList.toggle('hidden', y < 480);
                backToTopBtn.classList.toggle('flex', y >= 480);
            }
        }
        window.addEventListener('scroll', onScroll, { passive: true });
        onScroll();

        backToTopBtn?.addEventListener('click', function() {
            window.scrollTo({ top: 0, behavior: 'smooth' });
        });

        // ----- Scroll reveal -----
        const revealEls = document.querySelectorAll('.reveal');
        if ('IntersectionObserver' in window) {
            const revealObserver = new IntersectionObserver(function(entries) {
                entries.forEach(function(entry) {
                    if (entry.isIntersecting) {
                        entry.target.classList.add('is-visible');
                        revealObserver.unobserve(entry.target);
                    }
                });
            }, { threshold: 0.15 });
            revealEls.forEach(function(el) { revealObserver.observe(el); });
        } else {
            revealEls.forEach(function(el) { el.classList.add('is-visible'); });
        }

        // ----- Active nav highlight -----
        const sections = ['hero', 'services', 'about', 'contact'];
        const navLinks = document.querySelectorAll('nav[aria-label="Main"] .nav-link');
        if ('IntersectionObserver' in window && navLinks.length) {
            const navObserver = new IntersectionObserver(function(entries) {
                entries.forEach(function(entry) {
                    if (!entry.isIntersecting) return;
                    const id = entry.target.id;
                    navLinks.forEach(function(link) {
                        link.classList.toggle('active', link.getAttribute('href') === '#' + id);
                    });
                });
            }, { rootMargin: '-40% 0px -50% 0px' });
            sections.forEach(function(id) {
                const el = document.getElementById(id);
                if (el) navObserver.observe(el);
            });
        }

        // ----- Contact form -----
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
