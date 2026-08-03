<?php
require_once 'config/security.php';
security_start_session();

// Google Identity Services' OAuth popup flow polls window.closed on the popup it opens;
// without this header the browser's default Cross-Origin-Opener-Policy silently blocks
// that check and spams "Cross-Origin-Opener-Policy policy would block the window.closed
// call" in the console. same-origin-allow-popups keeps our own origin isolated while still
// letting window.closed work for popups we open ourselves (Google's official fix for this).
header('Cross-Origin-Opener-Policy: same-origin-allow-popups');

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
$googleClientId = (string)env('GOOGLE_CLIENT_ID', '');
$clinicLogo = 'assets/img/clinic-logo.png';
$pageBackgroundImage = 'https://images.unsplash.com/photo-1629909613654-28e377c37b09?w=1400&h=900&fit=crop&auto=format';

$pageBgSrc = $pageBackgroundImage;
if (!preg_match('#^https?://#i', $pageBackgroundImage)) {
    $localFile = __DIR__ . DIRECTORY_SEPARATOR . str_replace(['/', '\\'], DIRECTORY_SEPARATOR, ltrim($pageBackgroundImage, '/\\'));
    if (!is_file($localFile)) {
        $pageBgSrc = 'https://images.unsplash.com/photo-1629909613654-28e377c37b09?w=1400&h=900&fit=crop&auto=format';
    }
}

$services = [
    [
        'title' => 'Teeth Whitening',
        'desc' => 'Achieve a brighter, whiter smile with our professional-grade whitening treatments. Safe, effective, and long-lasting results.',
        'icon' => 'sparkles',
        'duration' => '60 min',
    ],
    [
        'title' => 'Dental Implants',
        'desc' => 'Restore missing teeth with permanent, natural-looking implants that blend seamlessly with your existing smile.',
        'icon' => 'shield',
        'duration' => '2–3 sessions',
    ],
    [
        'title' => 'Orthodontics',
        'desc' => 'Straighten your teeth with modern braces or clear aligners. We create custom treatment plans for every patient.',
        'icon' => 'zap',
        'duration' => '12–24 months',
    ],
    [
        'title' => 'Preventive Care',
        'desc' => 'Regular cleanings, check-ups, and fluoride treatments to keep your teeth healthy and catch issues early.',
        'icon' => 'heart',
        'duration' => '45 min',
    ],
    [
        'title' => 'Porcelain Veneers',
        'desc' => 'Transform your smile with ultra-thin porcelain veneers that correct chips, gaps, stains, and misshapen teeth.',
        'icon' => 'award',
        'duration' => '2 sessions',
    ],
    [
        'title' => 'Pediatric Dentistry',
        'desc' => 'Gentle, child-friendly dental care in a welcoming environment that builds lifelong habits of oral health.',
        'icon' => 'calendar',
        'duration' => '30–45 min',
    ],
];

$testimonials = [
    [
        'name' => 'Maria Santos',
        'role' => 'Patient since 2019',
        'avatar' => 'MS',
        'rating' => 5,
        'text' => 'Dr. Azucena and her team are absolutely amazing. I used to be terrified of the dentist, but they made me feel so comfortable. My smile has never looked better!',
    ],
    [
        'name' => 'Jose Reyes',
        'role' => 'Patient since 2021',
        'avatar' => 'JR',
        'rating' => 5,
        'text' => 'Got my braces here and the entire process was smooth. The team is professional, friendly, and always ready to answer my questions. Highly recommend!',
    ],
    [
        'name' => 'Ana Villanueva',
        'role' => 'Patient since 2020',
        'avatar' => 'AV',
        'rating' => 5,
        'text' => 'The teeth whitening treatment gave me incredible results. The clinic is spotless, modern, and the staff is so warm and welcoming. 10/10 experience.',
    ],
    [
        'name' => 'Carlos Mendoza',
        'role' => 'Patient since 2022',
        'avatar' => 'CM',
        'rating' => 5,
        'text' => 'I brought my whole family here and everyone loves it. The pediatric care for my kids is gentle and fun. We won\'t go anywhere else!',
    ],
];

$stats = [
    ['value' => '2,500+', 'label' => 'Happy Patients'],
    ['value' => '15+', 'label' => 'Years Experience'],
    ['value' => '98%', 'label' => 'Satisfaction Rate'],
    ['value' => '12', 'label' => 'Expert Specialists'],
];

$aboutPoints = [
    'Gentle, patient-first approach',
    'State-of-the-art digital X-rays',
    'Pain-free anesthesia options',
    'Flexible payment plans',
    'English & Filipino speaking staff',
    'Child-friendly environment',
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
    <link href="https://fonts.googleapis.com/css2?family=Nunito:wght@400;500;600;700;800&family=Lora:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="assets/css/app-toast.css?v=20260718-dark1">
    <link rel="stylesheet" href="assets/css/app-request.css">
    <script src="https://cdn.tailwindcss.com"></script>
    <?php if ($googleClientId !== ''): ?>
    <script src="https://accounts.google.com/gsi/client" async defer></script>
    <?php endif; ?>
    <script>
        tailwind.config = {
            theme: {
                extend: {
                    fontFamily: {
                        sans: ['Nunito', 'system-ui', 'sans-serif'],
                        display: ['Lora', 'Georgia', 'serif'],
                    },
                    colors: {
                        primary: {
                            DEFAULT: '#0d9488',
                            foreground: '#ffffff',
                        },
                        secondary: {
                            DEFAULT: '#ccfbf1',
                            foreground: '#0f1e35',
                        },
                        muted: {
                            DEFAULT: '#f0fdfa',
                            foreground: '#5a6e8c',
                        },
                        card: {
                            DEFAULT: '#ffffff',
                            foreground: '#0f1e35',
                        },
                        background: '#f7f9fc',
                        foreground: '#0f1e35',
                        border: 'rgba(13, 148, 136, 0.12)',
                    },
                },
            },
        };
    </script>
    <style>
        .reveal {
            opacity: 0;
            transform: translateY(28px);
            transition: opacity 0.6s ease, transform 0.6s ease;
        }
        .reveal.is-visible {
            opacity: 1;
            transform: translateY(0);
        }
        .reveal-delay-1 { transition-delay: 0.1s; }
        .reveal-delay-2 { transition-delay: 0.2s; }
        .reveal-delay-3 { transition-delay: 0.3s; }

        @keyframes floatSlow {
            0%, 100% { transform: translateY(0); }
            50% { transform: translateY(-10px); }
        }
        .float-slow { animation: floatSlow 6s ease-in-out infinite; }

        .nav-link {
            position: relative;
            color: rgba(255,255,255,0.85);
            transition: color 0.25s ease;
        }
        .nav-link::after {
            content: '';
            position: absolute;
            left: 0;
            bottom: -4px;
            height: 2px;
            width: 0;
            background-color: #0d9488;
            transition: width 0.25s ease;
        }
        .nav-link:hover::after,
        .nav-link.active::after {
            width: 100%;
        }
        nav[aria-label="Main"] .nav-link:hover {
            color: #ffffff;
        }
        .header-scrolled .nav-link {
            color: #0f1e35 !important;
        }
        .header-scrolled .nav-link:hover {
            color: #0d9488 !important;
        }
        .header-scrolled .nav-link.active {
            color: #0d9488 !important;
        }
        .header-scrolled .nav-link::after {
            background-color: #0d9488;
        }

        .auth-overlay {
            position: fixed;
            inset: 0;
            z-index: 70;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 1rem;
            opacity: 0;
            visibility: hidden;
            transition: opacity 0.25s ease, visibility 0.25s ease;
        }
        .auth-overlay.open { opacity: 1; visibility: visible; }
        .auth-backdrop {
            position: absolute;
            inset: 0;
            background-color: rgba(15, 30, 53, 0.55);
            backdrop-filter: blur(3px);
        }
        .auth-panel {
            position: relative;
            z-index: 1;
            width: 100%;
            max-height: 92vh;
            display: flex;
            flex-direction: column;
            border-radius: 1.25rem;
            overflow: hidden;
            background-color: #fff;
            box-shadow: 0 25px 60px -15px rgba(15, 30, 53, 0.35);
            animation: authPanelIn 0.25s ease;
        }
        .auth-panel .auth-header {
            flex-shrink: 0;
        }
        .auth-panel .auth-body {
            flex: 1;
            min-height: 0;
            overflow-y: auto;
            overscroll-behavior: contain;
        }
        .auth-panel .auth-footer {
            flex-shrink: 0;
        }

        @keyframes authPanelIn {
            from { opacity: 0; transform: translateY(14px) scale(0.985); }
            to { opacity: 1; transform: translateY(0) scale(1); }
        }
        .auth-field {
            transition: border-color 0.2s ease, background-color 0.2s ease;
        }
        .auth-field:focus {
            border-color: rgb(13, 148, 136);
            background-color: #fff;
            box-shadow: none;
            outline: none;
        }
        .auth-field.field-invalid {
            border-color: #ef4444 !important;
            background-color: #fff !important;
        }
        .auth-field.field-invalid:focus {
            border-color: #ef4444 !important;
        }
        .auth-field-msg {
            font-size: 0.75rem;
            margin-top: 0.25rem;
            color: #ef4444;
            display: none;
            line-height: 1.3;
        }
        .auth-field-msg.show { display: block; }

        .auth-panel .auth-header,
        .terms-panel > div:first-child {
            background: #ffffff;
            border-color: transparent !important;
            border-radius: 1.25rem 1.25rem 0 0;
        }
        .auth-panel .auth-header h2,
        .terms-panel > div:first-child h2 {
            color: #1e293b !important;
        }
        .auth-panel .auth-header p,
        .terms-panel > div:first-child p {
            color: #64748b !important;
        }
        .auth-panel .auth-header [data-auth-close],
        .terms-panel > div:first-child [data-auth-close] {
            color: #64748b !important;
        }
        .auth-panel .auth-header [data-auth-close]:hover,
        .terms-panel > div:first-child [data-auth-close]:hover {
            background-color: #f1f5f9 !important;
            color: #1e293b !important;
        }

        .terms-overlay {
            position: fixed;
            inset: 0;
            z-index: 80;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 1rem;
            opacity: 0;
            visibility: hidden;
            transition: opacity 0.25s ease, visibility 0.25s ease;
        }
        .terms-overlay.open { opacity: 1; visibility: visible; }
        .terms-backdrop {
            position: absolute;
            inset: 0;
            background-color: rgba(15, 30, 53, 0.6);
            backdrop-filter: blur(3px);
        }
        .terms-panel {
            position: relative;
            z-index: 1;
            display: flex;
            flex-direction: column;
            width: 100%;
            max-width: 42rem;
            max-height: 88vh;
            border-radius: 1.25rem;
            background-color: #fff;
            box-shadow: 0 25px 60px -15px rgba(15, 30, 53, 0.35);
            animation: authPanelIn 0.22s ease;
        }
        .terms-content {
            overflow-y: auto;
            padding: 1.25rem 1.5rem;
            overscroll-behavior: contain;
        }
        .terms-content h3 {
            margin-top: 1.25rem;
            margin-bottom: 0.5rem;
            font-size: 0.95rem;
            font-weight: 700;
            color: #0f1e35;
        }
        .terms-content h3:first-child { margin-top: 0; }
        .terms-content p,
        .terms-content li {
            font-size: 0.875rem;
            line-height: 1.55;
            color: #475569;
        }
        .terms-content ul {
            margin: 0.5rem 0 0.75rem;
            padding-left: 1.25rem;
            list-style: disc;
        }
        .terms-content li + li { margin-top: 0.35rem; }

        .service-card:hover .service-icon-box {
            background: #0d9488;
        }
        .service-card:hover .service-icon-box svg {
            color: #fff;
        }

        @media (prefers-reduced-motion: reduce) {
            .reveal { opacity: 1; transform: none; transition: none; }
            .float-slow { animation: none; }
            .auth-panel { animation: none; }
        }

        /* Back to top button — consistent across all browsers */
        .back-to-top-btn {
            position: fixed;
            bottom: 24px;
            right: 24px;
            z-index: 40;
            display: none;
            align-items: center;
            justify-content: center;
            width: 44px;
            height: 44px;
            padding: 0;
            border: none;
            border-radius: 50%;
            background-color: #0d9488;
            color: #ffffff;
            cursor: pointer;
            box-shadow: 0 4px 14px rgba(13, 148, 136, 0.4);
            transition: background-color 0.2s ease, box-shadow 0.2s ease, transform 0.2s ease;
            -webkit-appearance: none;
            appearance: none;
            line-height: 1;
        }
        .back-to-top-btn:hover {
            background-color: #0f766e;
            box-shadow: 0 6px 20px rgba(13, 148, 136, 0.5);
            transform: translateY(-2px);
        }
        .back-to-top-btn:active {
            transform: translateY(0);
            box-shadow: 0 2px 8px rgba(13, 148, 136, 0.3);
        }
        .back-to-top-btn:focus-visible {
            outline: 2px solid #0d9488;
            outline-offset: 2px;
        }
        .back-to-top-btn.is-visible {
            display: flex;
        }

        /* Complaint checklist checkboxes */
        .complaint-check {
            width: 1rem;
            height: 1rem;
            accent-color: #0d9488;
            cursor: pointer;
        }

    </style>
</head>
<body class="font-sans antialiased text-foreground bg-background">

    <!-- Header -->
    <header id="siteHeader" class="fixed top-0 left-0 right-0 z-50 transition-all duration-300" style="background: transparent; backdrop-filter: none; border-bottom: none; box-shadow: none;">
        <nav class="mx-auto flex max-w-6xl items-center justify-between px-6 h-16 lg:h-20">
            <a href="index.php" class="flex items-center gap-2 group">
                <img
                    src="<?php echo htmlspecialchars($clinicLogo, ENT_QUOTES, 'UTF-8'); ?>"
                    alt="Azucena's Dental Clinic logo"
                    class="h-8 w-8 rounded-full object-cover"
                    width="32"
                    height="32"
                >
                <span id="siteTitle" class="tracking-tight transition-colors duration-300 font-display text-lg font-semibold text-white">
                    Azucena's Dental Clinic
                </span>
            </a>

            <ul class="hidden items-center gap-8 text-sm font-medium md:flex" aria-label="Main">
                <li><a href="#track-booking" class="nav-link transition-colors duration-200">Track Appointment</a></li>
                <li><a href="#services" class="nav-link transition-colors duration-200">Services</a></li>
                <li><a href="#about" class="nav-link transition-colors duration-200">About</a></li>
                <li><a href="#testimonials" class="nav-link transition-colors duration-200">Testimonials</a></li>
                <li><a href="#contact" class="nav-link transition-colors duration-200">Contact</a></li>
            </ul>

            <div class="hidden items-center gap-2 md:flex">
                <button type="button" data-auth-open="login" id="navLoginBtn" class="rounded-full border px-5 py-2.5 text-sm font-semibold transition-all duration-200" style="background: rgba(255,255,255,0.15); color: #ffffff; border: 1px solid rgba(255,255,255,0.35); backdrop-filter: blur(8px);">
                    Login
                </button>
                <button type="button" data-auth-open="booking" id="navRegisterBtn" class="hidden rounded-full bg-primary px-5 py-2.5 text-sm font-semibold text-white hover:opacity-90 active:scale-95 transition-all duration-200 sm:inline-flex">
                    Book Appointment
                </button>
            </div>

            <div class="flex items-center gap-2 md:hidden">
                <button type="button" id="mobileMenuBtn" class="inline-flex h-10 w-10 items-center justify-center rounded-lg transition-colors duration-300" style="color: #ffffff;" aria-label="Open menu" aria-expanded="false" aria-controls="mobileMenu">
                    <svg id="mobileMenuIconOpen" class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke-width="1.8" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M3.75 6.75h16.5M3.75 12h16.5m-16.5 5.25h16.5"/></svg>
                    <svg id="mobileMenuIconClose" class="hidden h-5 w-5" fill="none" viewBox="0 0 24 24" stroke-width="1.8" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12"/></svg>
                </button>
            </div>
        </nav>

        <!-- Mobile menu -->
        <div id="mobileMenu" class="hidden border-b md:hidden" style="background: #f7f9fc; border-color: rgba(13,148,136,0.1);">
            <ul class="flex flex-col gap-4 px-6 pb-6 pt-4">
                <li><a href="#track-booking" class="mobile-nav-link block text-foreground hover:text-primary transition-colors" style="font-weight: 500;">Track Appointment</a></li>
                <li><a href="#services" class="mobile-nav-link block text-foreground hover:text-primary transition-colors" style="font-weight: 500;">Services</a></li>
                <li><a href="#about" class="mobile-nav-link block text-foreground hover:text-primary transition-colors" style="font-weight: 500;">About</a></li>
                <li><a href="#testimonials" class="mobile-nav-link block text-foreground hover:text-primary transition-colors" style="font-weight: 500;">Testimonials</a></li>
                <li><a href="#contact" class="mobile-nav-link block text-foreground hover:text-primary transition-colors" style="font-weight: 500;">Contact</a></li>
                <li>
                    <button type="button" data-auth-open="login" class="mobile-nav-link block w-full text-center bg-primary text-white px-5 py-3 rounded-full text-sm" style="font-weight: 600;">Login</button>
                </li>
                <li>
                    <button type="button" data-auth-open="booking" class="mobile-nav-link block w-full text-center border border-primary text-primary px-5 py-3 rounded-full text-sm" style="font-weight: 600;">Book Appointment</button>
                </li>
            </ul>
        </div>
    </header>

    <main>
        <!-- Hero -->
        <section id="hero" class="relative min-h-screen flex items-center overflow-hidden">
            <div class="absolute inset-0 bg-cover bg-center" style="background-image: url('<?php echo htmlspecialchars($pageBgSrc, ENT_QUOTES, 'UTF-8'); ?>');"></div>
            <div class="absolute inset-0" style="background: linear-gradient(120deg, rgba(15,30,53,0.88) 0%, rgba(13,148,136,0.55) 100%);"></div>

            <div class="relative z-10 mx-auto grid max-w-6xl items-center gap-10 px-6 pt-24 pb-16 w-full lg:grid-cols-[1.15fr,0.85fr] lg:py-28">
                <div class="reveal is-visible">
                    <span class="inline-flex items-center gap-2 rounded-full border px-4 py-1.5 text-sm" style="background: rgba(255,255,255,0.1); border-color: rgba(255,255,255,0.2); backdrop-filter: blur(4px);">
                        <span class="h-2 w-2 rounded-full bg-green-400 animate-pulse"></span>
                        <span class="text-white/90" style="font-weight: 500;">Now accepting new patients</span>
                    </span>

                    <h1 class="mt-6 font-display text-white" style="font-size: clamp(2.4rem, 5vw, 4rem); line-height: 1.15; font-weight: 600;">
                        Your Smile Deserves the <br class="hidden sm:inline"> <span style="color: #5eead4;">Best Care</span>
                    </h1>

                    <p class="mt-6 max-w-xl text-lg leading-relaxed text-white/80">
                        At Azucena's Dental Clinic, we combine compassionate care with modern technology to give you a healthy, beautiful smile you'll love for life.
                    </p>

                    <div class="mt-10 flex flex-wrap gap-4">
                        <a href="#contact" class="group inline-flex items-center gap-2 rounded-full bg-primary px-7 py-3.5 text-sm font-semibold text-white hover:opacity-90 active:scale-95 transition-all duration-200">
                            Questions? Message us!
                            <svg class="h-4 w-4 transition-transform group-hover:translate-x-0.5" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M13.5 4.5L21 12m0 0l-7.5 7.5M21 12H3"/></svg>
                        </a>
                        <a href="#services" class="inline-flex items-center gap-2 rounded-full border border-white/25 bg-white/10 px-7 py-3.5 text-sm font-medium text-white backdrop-blur-sm hover:bg-white/20 transition-all duration-200">
                            Our Services
                        </a>
                    </div>

                    <div class="mt-12 flex flex-wrap gap-8">
                        <?php foreach ($stats as $stat): ?>
                        <div class="text-white">
                            <div class="font-display text-2xl" style="font-weight: 700; color: #5eead4;"><?php echo htmlspecialchars($stat['value'], ENT_QUOTES, 'UTF-8'); ?></div>
                            <div class="mt-0.5 text-sm text-white/65"><?php echo htmlspecialchars($stat['label'], ENT_QUOTES, 'UTF-8'); ?></div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>

            <a href="#services" class="absolute bottom-8 left-1/2 -translate-x-1/2 text-white/60 hover:text-white/90 transition-colors animate-bounce" aria-label="Scroll down">
                <svg class="h-7 w-7" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M19.5 8.25l-7.5 7.5-7.5-7.5"/></svg>
            </a>
        </section>

        <!-- Track Your Booking -->
        <section id="track-booking" class="py-16" style="background: linear-gradient(135deg, #f0fdfa 0%, #f0fdf4 100%);">
            <div class="mx-auto max-w-6xl px-6">
                <div class="reveal mx-auto max-w-xl text-center">
                    <span class="inline-block text-primary text-sm tracking-widest uppercase" style="font-weight: 600;">Appointment Status</span>
                    <h2 class="mt-3 font-display text-foreground" style="font-size: clamp(1.6rem, 3vw, 2.2rem); font-weight: 600;">Track Your Appointment</h2>
                    <p class="mt-3 text-muted-foreground leading-relaxed">Enter your appointment number to check the status of your appointment request.</p>

                    <div class="mt-6 flex items-center gap-2">
                        <div class="relative flex-1">
                            <span class="pointer-events-none absolute inset-y-0 left-0 flex items-center pl-3.5 text-slate-400">
                                <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M19.5 14.25v-2.625a3.375 3.375 0 00-3.375-3.375h-1.5A1.125 1.125 0 0113.5 7.125v-1.5a3.375 3.375 0 00-3.375-3.375H8.25m0 12.75h7.5m-7.5 3H12M10.5 2.25H5.625c-.621 0-1.125.504-1.125 1.125v17.25c0 .621.504 1.125 1.125 1.125h12.75c.621 0 1.125-.504 1.125-1.125V11.25a9 9 0 00-9-9z"/></svg>
                            </span>
                            <input type="text" id="bookingTrackInput" placeholder="Enter appointment number (e.g. 1001)" class="w-full rounded-xl border border-slate-200 bg-white py-3 pl-10 pr-4 text-sm text-slate-900 placeholder:text-slate-400 focus:border-primary focus:ring-2 focus:ring-primary/20 outline-none transition-all">
                        </div>
                        <button type="button" id="bookingTrackBtn" class="rounded-xl bg-primary px-6 py-3 text-sm font-semibold text-white hover:opacity-90 active:scale-95 transition-all duration-200 whitespace-nowrap">
                            Track
                        </button>
                    </div>

                    <!-- Progress Steps (hidden by default) -->
                    <div id="bookingTrackResult" class="mt-6 hidden">
                        <div class="rounded-2xl border border-slate-200 bg-white p-6 shadow-sm">
                            <div class="flex items-center justify-between mb-1">
                                <h3 class="text-sm font-bold text-slate-900">Appointment Tracking Code <span id="trackBookingId"></span></h3>
                                <span id="trackBookingStatus" class="inline-flex items-center rounded-full px-2.5 py-0.5 text-xs font-semibold"></span>
                            </div>
                            <p id="trackBookingReason" class="mt-1 text-xs text-red-600 hidden"></p>

                            <!-- Steps -->
                            <div class="flex items-start mt-5">
                                <!-- Step 1 -->
                                <div class="flex flex-col items-center" style="min-width:80px;">
                                    <div id="step1Circle" class="flex items-center justify-center w-10 h-10 rounded-full border-2 border-dashed border-slate-300 text-slate-400 text-xs font-bold transition-all duration-300">1</div>
                                    <span id="step1Label" class="mt-2 text-xs font-medium text-slate-400 text-center leading-tight">Booking<br>Created</span>
                                </div>
                                <!-- Line 1 -->
                                <div class="flex-1 flex items-center pt-5 px-1">
                                    <div id="line1" class="h-0.5 w-full bg-slate-200 transition-colors duration-300"></div>
                                </div>
                                <!-- Step 2 -->
                                <div class="flex flex-col items-center" style="min-width:80px;">
                                    <div id="step2Circle" class="flex items-center justify-center w-10 h-10 rounded-full border-2 border-dashed border-slate-300 text-slate-400 text-xs font-bold transition-all duration-300">2</div>
                                    <span id="step2Label" class="mt-2 text-xs font-medium text-slate-400 text-center leading-tight">Booking<br>Confirmed</span>
                                </div>
                                <!-- Line 2 -->
                                <div class="flex-1 flex items-center pt-5 px-1">
                                    <div id="line2" class="h-0.5 w-full bg-slate-200 transition-colors duration-300"></div>
                                </div>
                                <!-- Step 3 -->
                                <div class="flex flex-col items-center" style="min-width:80px;">
                                    <div id="step3Circle" class="flex items-center justify-center w-10 h-10 rounded-full border-2 border-dashed border-slate-300 text-slate-400 text-xs font-bold transition-all duration-300">3</div>
                                    <span id="step3Label" class="mt-2 text-xs font-medium text-slate-400 text-center leading-tight">Visit<br>Clinic</span>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div id="bookingTrackError" class="mt-4 hidden text-sm text-red-500 font-medium"></div>
                </div>
            </div>
        </section>

        <!-- Services -->
        <section id="services" class="py-24 bg-background">
            <div class="mx-auto max-w-6xl px-6">
                <div class="reveal mx-auto max-w-2xl text-center">
                    <span class="inline-block text-primary text-sm tracking-widest uppercase" style="font-weight: 600;">What We Offer</span>
                    <h2 class="mt-3 font-display text-foreground" style="font-size: clamp(1.8rem, 3vw, 2.8rem); font-weight: 600;">Comprehensive Dental Services</h2>
                    <p class="mt-4 text-muted-foreground leading-relaxed">From routine check-ups to advanced cosmetic treatments, we have everything you need for a lifetime of healthy smiles.</p>
                </div>

                <div class="mt-12 grid gap-6 sm:grid-cols-2 lg:grid-cols-3">
                    <?php foreach ($services as $i => $service): ?>
                    <div class="reveal reveal-delay-<?php echo min($i, 3); ?> service-card group relative rounded-2xl border border-border bg-card p-7 cursor-pointer transition-all duration-300 hover:shadow-lg hover:border-primary/30 hover:-translate-y-1">
                        <div class="service-icon-box mb-5 flex h-12 w-12 items-center justify-center rounded-xl transition-colors duration-300" style="background: #e4f3ee;">
                            <?php if ($service['icon'] === 'sparkles'): ?>
                            <svg class="h-6 w-6 transition-colors duration-300" style="color: #0d9488;" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M9.813 15.904L9 18.75l-.813-2.846a4.5 4.5 0 00-3.09-3.09L2.25 12l2.846-.813a4.5 4.5 0 003.09-3.09L9 5.25l.813 2.846a4.5 4.5 0 003.09 3.09L15.75 12l-2.846.813a4.5 4.5 0 00-3.09 3.09zM18.259 8.715L18 9.75l-.259-1.035a3.375 3.375 0 00-2.455-2.456L14.25 6l1.036-.259a3.375 3.375 0 002.455-2.456L18 2.25l.259 1.035a3.375 3.375 0 002.455 2.456L21.75 6l-1.036.259a3.375 3.375 0 00-2.455 2.456zM16.894 20.567L16.5 21.75l-.394-1.183a2.25 2.25 0 00-1.423-1.423L13.5 18.75l1.183-.394a2.25 2.25 0 001.423-1.423l.394-1.183.394 1.183a2.25 2.25 0 001.423 1.423l1.183.394-1.183.394a2.25 2.25 0 00-1.423 1.423z"/></svg>
                            <?php elseif ($service['icon'] === 'shield'): ?>
                            <svg class="h-6 w-6 transition-colors duration-300" style="color: #0d9488;" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M9 12.75L11.25 15 15 9.75m-3-7.036A11.959 11.959 0 013.598 6 11.99 11.99 0 003 9.749c0 5.592 3.824 10.29 9 11.623 5.176-1.332 9-6.03 9-11.622 0-1.31-.21-2.571-.598-3.751h-.152c-3.196 0-6.1-1.248-8.25-3.285z"/></svg>
                            <?php elseif ($service['icon'] === 'zap'): ?>
                            <svg class="h-6 w-6 transition-colors duration-300" style="color: #0d9488;" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M3.75 13.5l10.5-11.25L12 10.5h8.25L9.75 21.75 12 13.5H3.75z"/></svg>
                            <?php elseif ($service['icon'] === 'heart'): ?>
                            <svg class="h-6 w-6 transition-colors duration-300" style="color: #0d9488;" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M21 8.25c0-2.485-2.099-4.5-4.688-4.5-1.935 0-3.597 1.126-4.312 2.733-.715-1.607-2.377-2.733-4.313-2.733C5.1 3.75 3 5.765 3 8.25c0 7.22 9 12 9 12s9-4.78 9-12z"/></svg>
                            <?php elseif ($service['icon'] === 'award'): ?>
                            <svg class="h-6 w-6 transition-colors duration-300" style="color: #0d9488;" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M16.5 18.75h-9m9 0a3 3 0 013 3h-15a3 3 0 013-3m9 0v-3.375c0-.621-.503-1.125-1.125-1.125h-.871M7.5 18.75v-3.375c0-.621.504-1.125 1.125-1.125h.872m5.007 0H9.497m5.007 0a7.454 7.454 0 01-.982-3.172M9.497 14.25a7.454 7.454 0 00.981-3.172M5.25 4.236c-.982.143-1.954.317-2.916.52A6.003 6.003 0 007.73 9.728M5.25 4.236V4.5c0 2.108.966 3.99 2.48 5.228M5.25 4.236V2.721C7.456 2.41 9.71 2.25 12 2.25c2.291 0 4.545.16 6.75.47v1.516M18.75 4.236c.982.143 1.954.317 2.916.52A6.003 6.003 0 0116.27 9.728M18.75 4.236V4.5c0 2.108-.966 3.99-2.48 5.228m0 0a6.015 6.015 0 01-2.27.308 6.015 6.015 0 01-2.27-.308"/></svg>
                            <?php else: ?>
                            <svg class="h-6 w-6 transition-colors duration-300" style="color: #0d9488;" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M6.75 3v2.25M17.25 3v2.25M3 18.75V7.5a2.25 2.25 0 012.25-2.25h13.5A2.25 2.25 0 0121 7.5v11.25m-18 0A2.25 2.25 0 005.25 21h13.5A2.25 2.25 0 0021 18.75m-18 0v-7.5A2.25 2.25 0 015.25 9h13.5A2.25 2.25 0 0121 11.25v7.5"/></svg>
                            <?php endif; ?>
                        </div>

                        <h3 class="text-card-foreground mb-2" style="font-size: 1.05rem; font-weight: 700;"><?php echo htmlspecialchars($service['title'], ENT_QUOTES, 'UTF-8'); ?></h3>
                        <p class="text-muted-foreground text-sm leading-relaxed mb-4"><?php echo htmlspecialchars($service['desc'], ENT_QUOTES, 'UTF-8'); ?></p>

                        <div class="flex items-center justify-between pt-4 border-t border-border">
                            <span class="text-xs text-muted-foreground"><?php echo htmlspecialchars($service['duration'], ENT_QUOTES, 'UTF-8'); ?></span>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </section>

        <!-- About -->
        <section id="about" class="py-24" style="background: #f0fdfa;">
            <div class="mx-auto max-w-6xl px-6">
                <div class="grid items-center gap-16 lg:grid-cols-2">
                    <div class="reveal">
                        <div class="relative">
                            <div class="absolute -top-4 -left-4 h-full w-full rounded-3xl" style="background: rgba(13,148,136,0.08); z-index: 0;"></div>
                            <img
                                src="assets/img/profile_picture.jpg"
                                alt="Azucena's Dental Clinic"
                                class="relative z-10 w-full rounded-3xl object-cover"
                                style="height: 520px; object-position: top;"
                            >
                            <div class="absolute bottom-6 right-6 z-20 rounded-2xl border border-primary/12 bg-white px-5 py-4 shadow-xl">
                                <div class="flex items-center gap-3">
                                    <div class="flex h-10 w-10 items-center justify-center rounded-full" style="background: #ccfbf1;">
                                        <svg class="h-5 w-5 text-primary" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M16.5 18.75h-9m9 0a3 3 0 013 3h-15a3 3 0 013-3m9 0v-3.375c0-.621-.503-1.125-1.125-1.125h-.871M7.5 18.75v-3.375c0-.621.504-1.125 1.125-1.125h.872m5.007 0H9.497m5.007 0a7.454 7.454 0 01-.982-3.172M9.497 14.25a7.454 7.454 0 00.981-3.172M5.25 4.236c-.982.143-1.954.317-2.916.52A6.003 6.003 0 007.73 9.728M5.25 4.236V4.5c0 2.108.966 3.99 2.48 5.228M5.25 4.236V2.721C7.456 2.41 9.71 2.25 12 2.25c2.291 0 4.545.16 6.75.47v1.516M18.75 4.236c.982.143 1.954.317 2.916.52A6.003 6.003 0 0116.27 9.728M18.75 4.236V4.5c0 2.108-.966 3.99-2.48 5.228m0 0a6.015 6.015 0 01-2.27.308 6.015 6.015 0 01-2.27-.308"/></svg>
                                    </div>
                                    <div>
                                        <div class="text-card-foreground text-sm" style="font-weight: 700;">Board Certified</div>
                                        <div class="text-muted-foreground text-xs">Philippine Dental Association</div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="reveal reveal-delay-1">
                        <span class="inline-block text-primary text-sm mb-3 tracking-widest uppercase" style="font-weight: 600;">About Us</span>
                        <h2 class="text-foreground mb-5" style="font-family: 'Lora', Georgia, serif; font-size: clamp(1.8rem, 3vw, 2.6rem); font-weight: 600;">Caring for Smiles With Heart & Expertise</h2>
                        <p class="text-muted-foreground leading-relaxed mb-5">
                            Azucena's Dental Clinic has been actively serving our community since 2023. With a dedicated team of experienced doctors and modern technology, we focus on delivering exceptional dental care with compassion and professionalism.
                        </p>
                        <p class="text-muted-foreground leading-relaxed mb-8">
                            Our team of board-certified specialists is dedicated to staying at the forefront of dental medicine, using the latest technology and techniques to ensure every patient receives the best possible care.
                        </p>

                        <div class="mb-8 grid grid-cols-1 gap-4 sm:grid-cols-2">
                            <?php foreach ($aboutPoints as $point): ?>
                            <div class="flex items-start gap-2.5">
                                <svg class="mt-0.5 h-5 w-5 shrink-0 text-primary" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M9 12.75L11.25 15 15 9.75M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                                <span class="text-card-foreground text-sm" style="font-weight: 500;"><?php echo htmlspecialchars($point, ENT_QUOTES, 'UTF-8'); ?></span>
                            </div>
                            <?php endforeach; ?>
                        </div>

                        <div class="flex flex-wrap gap-8">
                            <?php foreach (array_slice($stats, 0, 3) as $stat): ?>
                            <div>
                                <div class="text-primary" style="font-family: 'Lora', Georgia, serif; font-size: 2rem; font-weight: 700;"><?php echo htmlspecialchars($stat['value'], ENT_QUOTES, 'UTF-8'); ?></div>
                                <div class="text-muted-foreground text-sm"><?php echo htmlspecialchars($stat['label'], ENT_QUOTES, 'UTF-8'); ?></div>
                            </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </div>
            </div>
        </section>

        <!-- Testimonials -->
        <section id="testimonials" class="py-24 bg-background">
            <div class="mx-auto max-w-6xl px-6">
                <div class="reveal mx-auto max-w-2xl text-center">
                    <span class="inline-block text-primary text-sm tracking-widest uppercase" style="font-weight: 600;">Patient Stories</span>
                    <h2 class="mt-3 font-display text-foreground" style="font-size: clamp(1.8rem, 3vw, 2.8rem); font-weight: 600;">What Our Patients Say</h2>
                </div>

                <div class="mt-12 grid gap-6 sm:grid-cols-2">
                    <?php foreach ($testimonials as $i => $t): ?>
                    <div class="reveal reveal-delay-<?php echo min($i, 3); ?> rounded-2xl border border-border bg-card p-8 transition-all duration-300 hover:border-primary/25 hover:shadow-md">
                        <div class="mb-5 flex gap-1">
                            <?php for ($s = 0; $s < $t['rating']; $s++): ?>
                            <svg class="h-4 w-4 text-amber-400" fill="currentColor" viewBox="0 0 24 24"><path d="M12 2l3.09 6.26L22 9.27l-5 4.87 1.18 6.88L12 17.77l-6.18 3.25L7 14.14 2 9.27l6.91-1.01L12 2z"/></svg>
                            <?php endfor; ?>
                        </div>
                        <p class="text-card-foreground mb-6 leading-relaxed" style="font-size: 0.97rem;">
                            &ldquo;<?php echo htmlspecialchars($t['text'], ENT_QUOTES, 'UTF-8'); ?>&rdquo;
                        </p>
                        <div class="flex items-center gap-3">
                            <div class="flex h-10 w-10 items-center justify-center rounded-full text-sm text-white" style="background: #0d9488; font-weight: 700;">
                                <?php echo htmlspecialchars($t['avatar'], ENT_QUOTES, 'UTF-8'); ?>
                            </div>
                            <div>
                                <div class="text-card-foreground text-sm" style="font-weight: 700;"><?php echo htmlspecialchars($t['name'], ENT_QUOTES, 'UTF-8'); ?></div>
                                <div class="text-muted-foreground text-xs"><?php echo htmlspecialchars($t['role'], ENT_QUOTES, 'UTF-8'); ?></div>
                            </div>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </section>

        <!-- Contact -->
        <section id="contact" class="py-24 bg-background">
            <div class="mx-auto max-w-6xl px-6">
                <div class="reveal mx-auto max-w-2xl text-center mb-16">
                    <span class="inline-block text-primary text-sm tracking-widest uppercase" style="font-weight: 600;">Get in Touch</span>
                    <h2 class="mt-3 font-display text-foreground" style="font-size: clamp(1.8rem, 3vw, 2.8rem); font-weight: 600;">Contact Us</h2>
                    <p class="mt-4 text-muted-foreground leading-relaxed">Questions about treatments or appointments? Reach out and we'll get back to you shortly.</p>
                </div>

                <div class="grid items-start gap-12 lg:grid-cols-[1fr,1.2fr]">
                    <!-- Left: clinic info -->
                    <div class="reveal space-y-5">
                        <div class="flex items-start gap-4">
                            <div class="flex h-10 w-10 shrink-0 items-center justify-center rounded-xl" style="background: #ccfbf1;">
                                <svg class="h-5 w-5 text-primary" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M15 10.5a3.75 3.75 0 11-7.5 0 3.75 3.75 0 017.5 0z"/><path stroke-linecap="round" stroke-linejoin="round" d="M19.5 10.5c0 7.142-7.5 11.25-7.5 11.25S4.5 17.642 4.5 10.5a7.5 7.5 0 1115 0z"/></svg>
                            </div>
                            <div>
                                <div class="text-muted-foreground text-xs uppercase tracking-wider mb-0.5" style="font-weight: 600;">Address</div>
                                <div class="text-card-foreground text-sm" style="font-weight: 500;">Metz Arcade, Cagayan de Oro City, Philippines</div>
                            </div>
                        </div>

                        <div class="flex items-start gap-4">
                            <div class="flex h-10 w-10 shrink-0 items-center justify-center rounded-xl" style="background: #ccfbf1;">
                                <svg class="h-5 w-5 text-primary" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M2.25 6.75c0 8.284 6.716 15 15 15h2.25a2.25 2.25 0 002.25-2.25v-1.372c0-.516-.351-.966-.852-1.091l-4.423-1.106c-.44-.11-.902.055-1.173.417l-.97 1.293c-.282.376-.769.542-1.21.38a12.035 12.035 0 01-7.143-7.143c-.162-.441.004-.928.38-1.21l1.293-.97c.363-.271.527-.734.417-1.173L6.963 3.102a1.125 1.125 0 00-1.091-.852H4.5A2.25 2.25 0 002.25 6.75z"/></svg>
                            </div>
                            <div>
                                <div class="text-muted-foreground text-xs uppercase tracking-wider mb-0.5" style="font-weight: 600;">Phone</div>
                                <div class="text-card-foreground text-sm" style="font-weight: 500;">+63 (88) 000 0000</div>
                            </div>
                        </div>

                        <div class="flex items-start gap-4">
                            <div class="flex h-10 w-10 shrink-0 items-center justify-center rounded-xl" style="background: #ccfbf1;">
                                <svg class="h-5 w-5 text-primary" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M21.75 6.75v10.5a2.25 2.25 0 01-2.25 2.25h-15a2.25 2.25 0 01-2.25-2.25V6.75m19.5 0A2.25 2.25 0 0019.5 4.5h-15a2.25 2.25 0 00-2.25 2.25m19.5 0v.243a2.25 2.25 0 01-1.07 1.916l-7.5 4.615a2.25 2.25 0 01-2.36 0L3.32 8.91a2.25 2.25 0 01-1.07-1.916V6.75"/></svg>
                            </div>
                            <div>
                                <div class="text-muted-foreground text-xs uppercase tracking-wider mb-0.5" style="font-weight: 600;">Email</div>
                                <div class="text-card-foreground text-sm" style="font-weight: 500;">info@azucenadental.ph</div>
                            </div>
                        </div>

                        <div class="flex items-start gap-4">
                            <div class="flex h-10 w-10 shrink-0 items-center justify-center rounded-xl" style="background: #ccfbf1;">
                                <svg class="h-5 w-5 text-primary" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M12 6v6h4.5m4.5 0a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                            </div>
                            <div>
                                <div class="text-muted-foreground text-xs uppercase tracking-wider mb-0.5" style="font-weight: 600;">Clinic Hours</div>
                                <div class="text-card-foreground text-sm" style="font-weight: 500;">Mon – Sat: 8:00 AM – 5:00 PM</div>
                            </div>
                        </div>

                        <div class="mt-6 overflow-hidden rounded-2xl border border-border" style="height: 200px;">
                            <iframe
                                title="Azucena Dental Clinic location"
                                class="h-full w-full"
                                loading="lazy"
                                referrerpolicy="no-referrer-when-downgrade"
                                src="https://maps.google.com/maps?q=Metz+Arcade,+Cagayan+de+Oro+City,+Philippines&amp;output=embed"
                                allowfullscreen
                            ></iframe>
                        </div>
                    </div>

                    <!-- Right: message form -->
                    <div class="reveal reveal-delay-1">
                        <form id="contact-form" class="rounded-3xl border border-border bg-card p-6 shadow-lg sm:p-8" novalidate>
                            <h3 class="text-lg font-bold text-card-foreground">Send us a message</h3>
                            <p class="mt-1 text-sm text-muted-foreground">Fill out the form below and we'll get back to you within 24 hours.<br>Note: Sundays are excluded.</p>
                            <div id="contact-form-alert" class="mt-4 hidden rounded-xl border px-4 py-3 text-sm" role="alert"></div>
                            <div class="mt-5 space-y-4">
                                <div class="grid gap-4 sm:grid-cols-2">
                                    <div>
                                        <label for="contact-name" class="block text-sm font-medium text-card-foreground">Full Name</label>
                                        <input type="text" id="contact-name" name="name" required placeholder="Maria Santos" class="mt-1.5 w-full rounded-xl border border-border px-4 py-3 text-sm transition-colors focus:border-primary focus:ring-2 focus:ring-primary/20 outline-none" style="background: #f0fdfa;">
                                    </div>
                                    <div>
                                        <label for="contact-phone" class="block text-sm font-medium text-card-foreground">Phone Number</label>
                                        <input type="tel" id="contact-phone" name="phone" required inputmode="tel" autocomplete="tel" placeholder="e.g. 0917 123 4567" class="mt-1.5 w-full rounded-xl border border-border px-4 py-3 text-sm transition-colors focus:border-primary focus:ring-2 focus:ring-primary/20 outline-none" style="background: #f0fdfa;">
                                    </div>
                                </div>
                                <div>
                                    <label for="contact-email" class="block text-sm font-medium text-card-foreground">Email</label>
                                    <input type="email" id="contact-email" name="email" required placeholder="maria@email.com" class="mt-1.5 w-full rounded-xl border border-border px-4 py-3 text-sm transition-colors focus:border-primary focus:ring-2 focus:ring-primary/20 outline-none" style="background: #f0fdfa;">
                                </div>
                                <div>
                                    <label for="contact-message" class="block text-sm font-medium text-card-foreground">Message</label>
                                    <textarea id="contact-message" name="message" rows="5" required class="mt-1.5 w-full resize-y rounded-xl border border-border px-4 py-3 text-sm transition-colors focus:border-primary focus:ring-2 focus:ring-primary/20 outline-none" style="background: #f0fdfa;" placeholder="How can we help you?"></textarea>
                                </div>
                                <button type="submit" id="contact-form-submit" class="flex w-full items-center justify-center gap-2 rounded-xl bg-primary py-3.5 text-sm font-bold text-white hover:opacity-90 active:scale-[0.98] transition-all duration-200">
                                    Send Message
                                    <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M13.5 4.5L21 12m0 0l-7.5 7.5M21 12H3"/></svg>
                                </button>
                            </div>
                        </form>
                    </div>
                </div>
            </div>
        </section>
    </main>

    <!-- Footer -->
    <footer class="bg-foreground py-10 text-white/70">
        <div class="mx-auto flex max-w-6xl flex-col items-center justify-between gap-4 px-6 md:flex-row">
            <div class="flex items-center gap-2">
                <img
                    src="<?php echo htmlspecialchars($clinicLogo, ENT_QUOTES, 'UTF-8'); ?>"
                    alt="Azucena's Dental Clinic logo"
                    class="h-7 w-7 rounded-full object-cover"
                    width="28"
                    height="28"
                >
                <span class="font-display text-sm text-white" style="font-weight: 600;">Azucena's Dental Clinic</span>
            </div>
            <p class="text-center text-sm">&copy; <?php echo $year; ?> Azucena's Dental Clinic. All rights reserved.</p>
            <div class="flex gap-5 text-sm">
                <a href="#hero" class="hover:text-white transition-colors">Home</a>
                <a href="#services" class="hover:text-white transition-colors">Services</a>
                <a href="#contact" class="hover:text-white transition-colors">Contact</a>
            </div>
        </div>
    </footer>

    <!-- Account dialogs -->
    <div id="authModal" class="auth-overlay" role="dialog" aria-modal="true" aria-hidden="true">
        <div class="auth-backdrop" data-auth-close></div>

        <!-- Login -->
        <section id="authPanelLogin" class="auth-panel hidden max-w-md" aria-label="Login">
            <div class="auth-header flex items-center justify-between border-b border-slate-100 px-5 py-4 sm:px-7">
                <div class="flex-1 text-center">
                    <h2 class="text-xl font-bold text-slate-900">Welcome back</h2>
                    <p class="text-xs text-slate-500">Sign in to your account</p>
                </div>
                <button type="button" data-auth-close class="rounded-lg p-1 text-slate-400 hover:bg-slate-100 hover:text-slate-600 transition-colors" aria-label="Close">
                    <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke-width="1.8" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12"/></svg>
                </button>
            </div>

            <div class="auth-body">
            <form id="login-form" class="space-y-4 px-5 py-4 sm:px-7" novalidate>
                <div class="space-y-1.5">
                    <label for="login-email" class="block text-sm font-medium text-slate-700">Email</label>
                    <div class="relative">
                        <span class="pointer-events-none absolute inset-y-0 left-0 flex items-center pl-3.5 text-slate-400">
                            <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M21.75 6.75v10.5a2.25 2.25 0 01-2.25 2.25h-15a2.25 2.25 0 01-2.25-2.25V6.75m19.5 0A2.25 2.25 0 0019.5 4.5h-15a2.25 2.25 0 00-2.25 2.25m19.5 0v.243a2.25 2.25 0 01-1.07 1.916l-7.5 4.615a2.25 2.25 0 01-2.36 0L3.32 8.91a2.25 2.25 0 01-1.07-1.916V6.75"/></svg>
                        </span>
                        <input type="email" id="login-email" name="email" autocomplete="email" required placeholder="Enter your email"
                            class="auth-field block w-full rounded-lg border border-slate-200 bg-slate-50/50 py-2.5 pl-10 pr-4 text-sm text-slate-900 placeholder:text-slate-400">
                    </div>
                    <p class="auth-field-msg" id="login-email-error"></p>
                </div>

                <div class="space-y-1.5">
                    <label for="login-password" class="block text-sm font-medium text-slate-700">Password</label>
                    <div class="relative">
                        <span class="pointer-events-none absolute inset-y-0 left-0 flex items-center pl-3.5 text-slate-400">
                            <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M16.5 10.5V6.75a4.5 4.5 0 10-9 0v3.75m-.75 11.25h10.5a2.25 2.25 0 002.25-2.25v-6.75a2.25 2.25 0 00-2.25-2.25H6.75a2.25 2.25 0 00-2.25 2.25v6.75a2.25 2.25 0 002.25 2.25z"/></svg>
                        </span>
                        <input type="password" id="login-password" name="password" autocomplete="current-password" required placeholder="Enter your password"
                            class="auth-field block w-full rounded-lg border border-slate-200 bg-slate-50/50 py-2.5 pl-10 pr-12 text-sm text-slate-900 placeholder:text-slate-400">
                        <button type="button" data-toggle-password="login-password" class="absolute inset-y-0 right-0 flex items-center pr-3.5 text-slate-400 hover:text-primary transition-colors" aria-label="Show password" aria-pressed="false">
                            <svg data-icon="eye" class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M2.036 12.322a1.012 1.012 0 010-.639C3.423 7.51 7.36 4.5 12 4.5c4.638 0 8.573 3.007 9.963 7.178.07.207.07.431 0 .639C20.577 16.49 16.64 19.5 12 19.5c-4.638 0-8.573-3.007-9.963-7.178z"/><path stroke-linecap="round" stroke-linejoin="round" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/></svg>
                            <svg data-icon="eye-off" class="hidden h-5 w-5" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M3.98 8.223A10.477 10.477 0 001.934 12C3.226 16.338 7.244 19.5 12 19.5c.993 0 1.953-.138 2.863-.395M6.228 6.228A10.45 10.45 0 0112 4.5c4.756 0 8.773 3.162 10.065 7.498a10.523 10.523 0 01-4.293 5.774M6.228 6.228L3 3m3.228 3.228l3.65 3.65m7.894 7.894L21 21m-3.228-3.228l-3.65-3.65m0 0a3 3 0 10-4.243-4.243m4.242 4.242L9.88 9.88"/></svg>
                        </button>
                    </div>
                </div>

                <div class="flex flex-wrap items-center justify-between gap-3 text-sm">
                    <button type="button" data-auth-switch="forgot" class="font-medium text-primary hover:text-primary transition-colors">Forgot Password?</button>
                </div>

                <div class="space-y-2">
                    <div class="flex items-center gap-2">
                        <span id="loginCaptchaQuestion" class="shrink-0 rounded-lg border border-slate-200 bg-slate-50 px-3 py-2 text-sm text-slate-700" style="min-width:140px;max-width:140px;text-align:center;">Loading...</span>
                        <button type="button" id="refreshLoginCaptchaBtn" class="shrink-0 text-sm font-semibold text-primary hover:text-primary">Refresh</button>
                        <input type="text" id="loginCaptchaAnswer" name="captcha" inputmode="numeric" pattern="-?[0-9]+" required placeholder="Answer"
                            class="auth-field min-w-0 rounded-lg border border-slate-200 bg-slate-50/50 py-2.5 px-4 text-sm text-slate-900 placeholder:text-slate-400" style="width:900px;max-width:90px;">
                    </div>
                </div>

                <button type="submit" class="w-full rounded-full bg-primary px-4 py-3 text-sm font-semibold text-white hover:bg-primary/90 focus:outline-none focus:ring-2 focus:ring-primary/40 focus:ring-offset-2 transition-colors disabled:opacity-60 disabled:cursor-not-allowed">
                    LOGIN
                </button>

                <?php if ($googleClientId !== ''): ?>
                <div class="flex items-center gap-3">
                    <div class="flex-1 h-px bg-slate-200"></div>
                    <span class="text-xs font-medium text-slate-400">or</span>
                    <div class="flex-1 h-px bg-slate-200"></div>
                </div>
                <div class="flex justify-center">
                    <button type="button" id="google-login-btn"
                        class="flex h-11 w-11 items-center justify-center rounded-full border border-slate-200 bg-white shadow-sm hover:shadow-md hover:border-slate-300 transition-all"
                        title="Sign in with Google" aria-label="Sign in with Google">
                        <svg viewBox="0 0 24 24" class="h-5 w-5" xmlns="http://www.w3.org/2000/svg">
                            <path d="M22.56 12.25c0-.78-.07-1.53-.2-2.25H12v4.26h5.92c-.26 1.37-1.04 2.53-2.21 3.31v2.77h3.57c2.08-1.92 3.28-4.74 3.28-8.09z" fill="#4285F4"/>
                            <path d="M12 23c2.97 0 5.46-.98 7.28-2.66l-3.57-2.77c-.98.66-2.23 1.06-3.71 1.06-2.86 0-5.29-1.93-6.16-4.53H2.18v2.84C3.99 20.53 7.7 23 12 23z" fill="#34A853"/>
                            <path d="M5.84 14.09c-.22-.66-.35-1.36-.35-2.09s.13-1.43.35-2.09V7.07H2.18C1.43 8.55 1 10.22 1 12s.43 3.45 1.18 4.93l2.85-2.22.81-.62z" fill="#FBBC05"/>
                            <path d="M12 5.38c1.62 0 3.06.56 4.21 1.64l3.15-3.15C17.45 2.09 14.97 1 12 1 7.7 1 3.99 3.47 2.18 7.07l3.66 2.84c.87-2.6 3.3-4.53 6.16-4.53z" fill="#EA4335"/>
                        </svg>
                    </button>
                </div>
                <?php endif; ?>
            </form>
            </div>

            <footer class="auth-footer border-t border-slate-100 bg-slate-50/80 px-5 py-3 sm:px-7">
                <p class="text-center text-[10px] leading-relaxed text-slate-500">
                    Compliant with the Philippine Data Privacy Act of 2012 (RA 10173)
                </p>
            </footer>
        </section>

        <!-- Book Appointment (Guest) -->
        <section id="authPanelBooking" class="auth-panel hidden max-w-lg" aria-label="Book appointment">
            <div class="auth-header flex items-center justify-between border-b border-slate-100 px-5 py-4 sm:px-7">
                <div class="flex-1 text-center">
                    <h2 class="text-xl font-bold text-slate-900">Book an Appointment</h2>
                    <p class="text-xs text-slate-500">Schedule your visit with us</p>
                </div>
                <button type="button" data-auth-close class="rounded-lg p-1 text-slate-400 hover:bg-slate-100 hover:text-slate-600 transition-colors" aria-label="Close">
                    <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke-width="1.8" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12"/></svg>
                </button>
            </div>

            <div class="auth-body">
            <!-- Step 1: Booking Form -->
            <form id="booking-form" class="space-y-3 px-5 py-4 sm:px-7" novalidate>
                <div class="grid gap-3 sm:grid-cols-2">
                    <div>
                        <label for="book-first-name" class="mb-1 block text-xs font-semibold text-slate-600">First Name</label>
                        <input type="text" id="book-first-name" name="first_name" required placeholder="First name"
                            class="auth-field w-full rounded-lg border border-slate-200 bg-slate-50/50 px-3 py-2.5 text-sm placeholder:text-slate-400">
                        <p class="auth-field-msg" id="book-first-name-error"></p>
                    </div>
                    <div>
                        <label for="book-last-name" class="mb-1 block text-xs font-semibold text-slate-600">Last Name</label>
                        <input type="text" id="book-last-name" name="last_name" required placeholder="Last name"
                            class="auth-field w-full rounded-lg border border-slate-200 bg-slate-50/50 px-3 py-2.5 text-sm placeholder:text-slate-400">
                        <p class="auth-field-msg" id="book-last-name-error"></p>
                    </div>
                </div>

                <div class="grid gap-3 sm:grid-cols-2">
                    <div>
                        <label for="book-email" class="mb-1 block text-xs font-semibold text-slate-600">Email</label>
                        <input type="email" id="book-email" name="email" required placeholder="you@example.com" autocomplete="email"
                            class="auth-field w-full rounded-lg border border-slate-200 bg-slate-50/50 px-3 py-2.5 text-sm placeholder:text-slate-400">
                        <p class="auth-field-msg" id="book-email-error"></p>
                    </div>
                    <div>
                        <label for="book-phone" class="mb-1 block text-xs font-semibold text-slate-600">Phone</label>
                        <div class="flex">
                            <select id="book-phone-code" name="phone_code"
                                class="auth-field shrink-0 rounded-l-lg border border-slate-200 bg-slate-50/50 px-2 py-2.5 text-sm text-slate-700 border-r-0 cursor-pointer focus:border-primary focus:ring-0 focus:outline-none"
                                style="min-width: 70px; max-width: 70px;">
                                <option value="+63" selected>+63</option>
                                <option value="+1">+1</option>
                                <option value="+44">+44</option>
                                <option value="+61">+61</option>
                                <option value="+81">+81</option>
                                <option value="+82">+82</option>
                                <option value="+86">+86</option>
                                <option value="+91">+91</option>
                                <option value="+65">+65</option>
                                <option value="+60">+60</option>
                                <option value="+62">+62</option>
                                <option value="+66">+66</option>
                                <option value="+84">+84</option>
                                <option value="+971">+971</option>
                                <option value="+966">+966</option>
                                <option value="+49">+49</option>
                                <option value="+33">+33</option>
                                <option value="+34">+34</option>
                                <option value="+39">+39</option>
                                <option value="+55">+55</option>
                                <option value="+52">+52</option>
                                <option value="+27">+27</option>
                                <option value="+234">+234</option>
                                <option value="+254">+254</option>
                            </select>
                            <input type="tel" id="book-phone" name="phone_number" required placeholder="9XX XXX XXXX" autocomplete="tel" inputmode="numeric" maxlength="10"
                                class="auth-field w-full rounded-r-lg border border-slate-200 bg-slate-50/50 px-3 py-2.5 text-sm placeholder:text-slate-400">
                        </div>
                        <p class="auth-field-msg" id="book-phone-error"></p>
                    </div>
                </div>

                <div class="grid gap-3 sm:grid-cols-3">
                    <div>
                        <label for="book-dob" class="mb-1 block text-xs font-semibold text-slate-600">Birthdate</label>
                        <input type="date" id="book-dob" name="date_of_birth" required
                            class="auth-field w-full rounded-lg border border-slate-200 bg-slate-50/50 px-3 py-2.5 text-sm text-slate-700">
                        <p class="auth-field-msg" id="book-dob-error"></p>
                    </div>
                    <div>
                        <label for="book-pref-date" class="mb-1 block text-xs font-semibold text-slate-600">Preferred Date</label>
                        <input type="date" id="book-pref-date" name="preferred_date" required
                            class="auth-field w-full rounded-lg border border-slate-200 bg-slate-50/50 px-3 py-2.5 text-sm text-slate-700">
                        <p class="auth-field-msg" id="book-pref-date-error"></p>
                    </div>
                    <div>
                        <label class="mb-1 block text-xs font-semibold text-slate-600">Time</label>
                        <div class="flex items-center gap-0">
                            <input type="text" id="book-hour" inputmode="numeric" maxlength="2" placeholder="8"
                                class="book-time-field w-10 text-center text-sm font-semibold text-slate-800 bg-slate-50/80 border border-slate-200 rounded-l-lg px-1 py-2.5 outline-none focus:border-primary transition-colors">
                            <span class="text-base font-bold text-slate-400 px-0.5">:</span>
                            <input type="text" id="book-minute" inputmode="numeric" maxlength="2" placeholder="00"
                                class="book-time-field w-10 text-center text-sm font-semibold text-slate-800 bg-slate-50/80 border-y border-slate-200 px-1 py-2.5 outline-none focus:border-primary transition-colors">
                            <button type="button" id="btn-meridiem" class="px-3 py-2.5 text-xs font-bold bg-slate-50/80 text-primary border border-slate-200 rounded-r-lg cursor-pointer hover:bg-primary/5 transition-colors">AM</button>
                        </div>
                        <input type="hidden" id="book-time" name="preferred_time" value="8:00 AM">
                        <p class="auth-field-msg" id="book-time-error"></p>
                    </div>
                </div>

                <div>
                    <label class="mb-1 block text-xs font-semibold text-slate-600">Chief Complaint</label>
                    <div class="grid grid-cols-2 gap-x-4 gap-y-2 rounded-lg border border-slate-200 bg-slate-50/50 px-3 py-3">
                        
                        <label class="flex items-center gap-2 cursor-pointer group">
                            <input type="checkbox" name="complaint[]" value="Toothache" class="complaint-check rounded border-slate-300 text-primary focus:ring-primary/40">
                            <span class="text-sm text-slate-700 group-hover:text-primary transition-colors">Toothache</span>
                        </label>
                        <label class="flex items-center gap-2 cursor-pointer group">
                            <input type="checkbox" name="complaint[]" value="Broken / Chipped Tooth" class="complaint-check rounded border-slate-300 text-primary focus:ring-primary/40">
                            <span class="text-sm text-slate-700 group-hover:text-primary transition-colors">Broken / Chipped Tooth</span>
                        </label>
                        <label class="flex items-center gap-2 cursor-pointer group">
                            <input type="checkbox" name="complaint[]" value="Swollen Gums" class="complaint-check rounded border-slate-300 text-primary focus:ring-primary/40">
                            <span class="text-sm text-slate-700 group-hover:text-primary transition-colors">Swollen Gums</span>
                        </label>
                        <label class="flex items-center gap-2 cursor-pointer group">
                            <input type="checkbox" name="complaint[]" value="Bleeding Gums" class="complaint-check rounded border-slate-300 text-primary focus:ring-primary/40">
                            <span class="text-sm text-slate-700 group-hover:text-primary transition-colors">Bleeding Gums</span>
                        </label>
                        <label class="flex items-center gap-2 cursor-pointer group">
                            <input type="checkbox" name="complaint[]" value="Sensitive Teeth" class="complaint-check rounded border-slate-300 text-primary focus:ring-primary/40">
                            <span class="text-sm text-slate-700 group-hover:text-primary transition-colors">Sensitive Teeth</span>
                        </label>
                        <label class="flex items-center gap-2 cursor-pointer group">
                            <input type="checkbox" name="complaint[]" value="Loose Tooth" class="complaint-check rounded border-slate-300 text-primary focus:ring-primary/40">
                            <span class="text-sm text-slate-700 group-hover:text-primary transition-colors">Loose Tooth</span>
                        </label>
                        <label class="flex items-center gap-2 cursor-pointer group">
                            <input type="checkbox" name="complaint[]" value="Tooth Decay / Cavity" class="complaint-check rounded border-slate-300 text-primary focus:ring-primary/40">
                            <span class="text-sm text-slate-700 group-hover:text-primary transition-colors">Tooth Decay / Cavity</span>
                        </label>
                        <label class="flex items-center gap-2 cursor-pointer group">
                            <input type="checkbox" name="complaint[]" value="Jaw Pain" class="complaint-check rounded border-slate-300 text-primary focus:ring-primary/40">
                            <span class="text-sm text-slate-700 group-hover:text-primary transition-colors">Jaw Pain</span>
                        </label>
                        <label class="flex items-center gap-2 cursor-pointer group">
                            <input type="checkbox" name="complaint[]" value="Difficulty Chewing" class="complaint-check rounded border-slate-300 text-primary focus:ring-primary/40">
                            <span class="text-sm text-slate-700 group-hover:text-primary transition-colors">Difficulty Chewing</span>
                        </label>
                        <label class="flex items-center gap-2 cursor-pointer group">
                            <input type="checkbox" name="complaint[]" value="Bad Breath" class="complaint-check rounded border-slate-300 text-primary focus:ring-primary/40">
                            <span class="text-sm text-slate-700 group-hover:text-primary transition-colors">Bad Breath</span>
                        </label>
                        <label class="flex items-center gap-2 cursor-pointer group">
                            <input type="checkbox" name="complaint[]" value="Abscess / Pus" class="complaint-check rounded border-slate-300 text-primary focus:ring-primary/40">
                            <span class="text-sm text-slate-700 group-hover:text-primary transition-colors">Abscess / Pus</span>
                        </label>
                    </div>
                </div>

                <div>
                    <label for="book-reason" class="mb-1 block text-xs font-semibold text-slate-600">Additional Details <span class="font-normal text-slate-400">(optional)</span></label>
                    <textarea id="book-reason" name="reason" rows="2" placeholder="Any other details about your condition..."
                        class="auth-field w-full rounded-lg border border-slate-200 bg-slate-50/50 px-3 py-2.5 text-sm placeholder:text-slate-400 resize-none"></textarea>
                </div>

                <div>
                    <label class="flex items-start gap-2 cursor-pointer">
                        <input type="checkbox" id="reg-terms" required class="mt-0.5 rounded border-slate-300 text-primary focus:ring-primary/40">
                        <span class="text-xs text-slate-600">
                            I have read and agree to the
                            <button type="button" id="openTermsModal" class="font-semibold text-primary hover:text-primary/80 underline">Terms and Conditions</button>.
                        </span>
                    </label>
                    <p class="auth-field-msg" id="reg-terms-error"></p>
                </div>

                <div id="booking-alert" class="hidden rounded-lg border px-3 py-2 text-sm" role="alert"></div>

                <button type="submit" id="booking-submit-btn" class="block w-full rounded-full bg-primary px-5 py-2.5 text-sm font-bold text-white hover:bg-primary/90 focus:outline-none focus:ring-2 focus:ring-primary/40 focus:ring-offset-2 transition-colors disabled:opacity-60 disabled:cursor-not-allowed">
                    Book an Appointment
                </button>

                <p class="text-center text-xs text-slate-500">
                    Already have an account? <button type="button" data-auth-switch="login" class="font-bold text-primary hover:text-primary/80">Login</button>
                </p>
            </form>

            <!-- Step 2: OTP Verification (hidden by default) -->
            <div id="booking-otp-section" class="hidden px-5 py-5 sm:px-7 text-center">
                <div class="mx-auto mb-3 flex h-12 w-12 items-center justify-center rounded-full bg-green-50">
                    <svg class="h-6 w-6 text-green-600" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M21.75 6.75v10.5a2.25 2.25 0 01-2.25 2.25h-15a2.25 2.25 0 01-2.25-2.25V6.75m19.5 0A2.25 2.25 0 0019.5 4.5h-15a2.25 2.25 0 00-2.25 2.25m19.5 0v.243a2.25 2.25 0 01-1.07 1.916l-7.5 4.615a2.25 2.25 0 01-2.36 0L3.32 8.91a2.25 2.25 0 01-1.07-1.916V6.75"/></svg>
                </div>
                <h3 class="text-base font-bold text-slate-900">Check Your Email</h3>
                <p class="mt-1 text-sm text-slate-500">Code sent to <strong id="otp-email-display"></strong></p>

                <div class="mt-4">
                    <input type="hidden" id="booking-otp">
                    <div class="otp-boxes flex justify-center gap-2" id="booking-otp-boxes" data-otp-target="booking-otp">
                        <input type="text" inputmode="numeric" maxlength="1" class="otp-box h-12 w-11 rounded-lg border border-slate-200 bg-slate-50/50 text-center text-xl font-bold text-slate-900 focus:border-primary focus:outline-none focus:ring-2 focus:ring-primary/40">
                        <input type="text" inputmode="numeric" maxlength="1" class="otp-box h-12 w-11 rounded-lg border border-slate-200 bg-slate-50/50 text-center text-xl font-bold text-slate-900 focus:border-primary focus:outline-none focus:ring-2 focus:ring-primary/40">
                        <input type="text" inputmode="numeric" maxlength="1" class="otp-box h-12 w-11 rounded-lg border border-slate-200 bg-slate-50/50 text-center text-xl font-bold text-slate-900 focus:border-primary focus:outline-none focus:ring-2 focus:ring-primary/40">
                        <input type="text" inputmode="numeric" maxlength="1" class="otp-box h-12 w-11 rounded-lg border border-slate-200 bg-slate-50/50 text-center text-xl font-bold text-slate-900 focus:border-primary focus:outline-none focus:ring-2 focus:ring-primary/40">
                        <input type="text" inputmode="numeric" maxlength="1" class="otp-box h-12 w-11 rounded-lg border border-slate-200 bg-slate-50/50 text-center text-xl font-bold text-slate-900 focus:border-primary focus:outline-none focus:ring-2 focus:ring-primary/40">
                        <input type="text" inputmode="numeric" maxlength="1" class="otp-box h-12 w-11 rounded-lg border border-slate-200 bg-slate-50/50 text-center text-xl font-bold text-slate-900 focus:border-primary focus:outline-none focus:ring-2 focus:ring-primary/40">
                    </div>
                </div>

                <div id="otp-alert" class="mt-3 hidden rounded-lg border px-3 py-2 text-sm" role="alert"></div>

                <button type="button" id="verify-otp-btn" class="mt-4 block w-full rounded-full bg-primary px-5 py-2.5 text-sm font-bold text-white hover:bg-primary/90 focus:outline-none focus:ring-2 focus:ring-primary/40 focus:ring-offset-2 transition-colors disabled:opacity-60 disabled:cursor-not-allowed">
                    Confirm Appointment
                </button>

                <button type="button" id="resend-otp-btn" class="mt-2 text-sm font-medium text-primary hover:text-primary/80 transition-colors">
                    Resend code
                </button>
            </div>

            <!-- Step 3: Success (hidden by default) -->
            <div id="booking-success-section" class="hidden px-5 py-6 sm:px-8 text-center">
                <!-- Animated check -->
                <div class="mx-auto mb-4 flex h-16 w-16 items-center justify-center rounded-full bg-green-50" style="box-shadow: 0 0 0 6px rgba(34,197,94,0.1);">
                    <svg class="h-8 w-8 text-green-500" fill="none" viewBox="0 0 24 24" stroke-width="2.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M9 12.75L11.25 15 15 9.75M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                </div>

                <h3 class="text-lg font-bold text-slate-900">Booking Request Received!</h3>
                <p class="mt-1.5 text-sm text-slate-500">A copy has been sent to your inbox. We'll notify you once the clinic reviews and approves your request.</p>

                <!-- Tracking number card -->
                <div class="mt-5 rounded-xl border border-green-200 bg-green-50/60 px-5 py-4">
                    <p class="text-xs font-semibold uppercase tracking-wider text-green-700 mb-2">Your Reference Number</p>
                    <div class="flex items-center justify-center gap-2">
                        <span id="booking-ref-display" class="text-2xl font-bold text-green-700 tracking-wide" style="font-family: 'Courier New', monospace;">—</span>
                        <button type="button" id="copyBookingRefBtn" class="rounded-md border border-green-300 bg-white px-2 py-1 text-xs font-medium text-green-700 hover:bg-green-100 transition-colors" title="Copy to clipboard">
                            <svg class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M15.666 3.888A2.25 2.25 0 0013.5 2.25h-3c-1.03 0-1.9.693-2.166 1.638m7.332 0c.055.194.084.4.084.612v0a.75.75 0 01-.75.75H9.75a.75.75 0 01-.75-.75v0c0-.212.03-.418.084-.612m7.332 0c.646.049 1.288.11 1.927.184 1.1.128 1.907 1.077 1.907 2.185V19.5a2.25 2.25 0 01-2.25 2.25H6.75A2.25 2.25 0 014.5 19.5V6.257c0-1.108.806-2.057 1.907-2.185a48.208 48.208 0 011.927-.184"/></svg>
                        </button>
                    </div>
                </div>

                <!-- Next steps -->
                <div class="mt-5 rounded-xl border border-slate-200 bg-slate-50 px-4 py-3 text-left">
                    <p class="text-xs font-semibold text-slate-700 mb-2">What happens next?</p>
                    <div class="space-y-2">
                        <div class="flex items-start gap-2.5">
                            <span class="mt-0.5 flex h-5 w-5 shrink-0 items-center justify-center rounded-full bg-primary/10 text-primary text-[10px] font-bold">1</span>
                            <p class="text-xs text-slate-600 leading-relaxed">We'll review your booking and send a confirmation to your email.</p>
                        </div>
                        <div class="flex items-start gap-2.5">
                            <span class="mt-0.5 flex h-5 w-5 shrink-0 items-center justify-center rounded-full bg-primary/10 text-primary text-[10px] font-bold">2</span>
                            <p class="text-xs text-slate-600 leading-relaxed">Use your reference number to track your appointment status anytime.</p>
                        </div>
                    </div>
                </div>

                <button type="button" data-auth-close class="mt-5 w-full rounded-lg bg-primary px-5 py-3 text-sm font-bold text-white hover:bg-primary/90 transition-colors">
                    Done
                </button>
            </div>
            </div>
        </section>

        <!-- Password recovery -->
        <section id="authPanelForgot" class="auth-panel hidden max-w-md" aria-label="Password recovery">
            <div class="auth-header flex items-start justify-between border-b border-slate-100 px-5 py-4 sm:px-7">
                <div class="flex items-center gap-2.5">
                    <img src="<?php echo htmlspecialchars($clinicLogo, ENT_QUOTES, 'UTF-8'); ?>" alt="" class="h-9 w-9 rounded-full object-cover" width="36" height="36">
                    <div>
                        <h2 class="text-sm font-bold text-slate-900">Password Recovery</h2>
                        <p class="text-[11px] text-slate-500">Enter your email to receive an OTP</p>
                    </div>
                </div>
                <button type="button" data-auth-close class="rounded-lg p-1 text-slate-400 hover:bg-slate-100 hover:text-slate-600 transition-colors" aria-label="Close">
                    <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke-width="1.8" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12"/></svg>
                </button>
            </div>

            <div class="auth-body">
            <form id="reset-form" class="space-y-4 px-5 py-4 sm:px-7">
                <input type="email" id="reset-email" required placeholder="Email Address"
                    class="auth-field w-full rounded-lg border border-slate-200 bg-slate-50/50 px-3 py-2.5 text-sm placeholder:text-slate-400">
                <button type="button" id="send-otp" data-action-loading="1" class="w-full rounded-lg border border-primary/20 bg-primary/5 py-2.5 text-sm font-semibold text-primary hover:bg-primary/10 transition-colors">
                    Send OTP
                </button>

                <div id="reset-section" class="space-y-3 hidden">
                    <input type="hidden" id="reset-otp">
                    <div class="otp-boxes flex justify-center gap-2" id="reset-otp-boxes" data-otp-target="reset-otp">
                        <input type="text" inputmode="numeric" maxlength="1" class="otp-box h-12 w-11 rounded-lg border border-slate-200 bg-slate-50/50 text-center text-xl font-bold text-slate-900 focus:border-primary focus:outline-none focus:ring-2 focus:ring-primary/40">
                        <input type="text" inputmode="numeric" maxlength="1" class="otp-box h-12 w-11 rounded-lg border border-slate-200 bg-slate-50/50 text-center text-xl font-bold text-slate-900 focus:border-primary focus:outline-none focus:ring-2 focus:ring-primary/40">
                        <input type="text" inputmode="numeric" maxlength="1" class="otp-box h-12 w-11 rounded-lg border border-slate-200 bg-slate-50/50 text-center text-xl font-bold text-slate-900 focus:border-primary focus:outline-none focus:ring-2 focus:ring-primary/40">
                        <input type="text" inputmode="numeric" maxlength="1" class="otp-box h-12 w-11 rounded-lg border border-slate-200 bg-slate-50/50 text-center text-xl font-bold text-slate-900 focus:border-primary focus:outline-none focus:ring-2 focus:ring-primary/40">
                        <input type="text" inputmode="numeric" maxlength="1" class="otp-box h-12 w-11 rounded-lg border border-slate-200 bg-slate-50/50 text-center text-xl font-bold text-slate-900 focus:border-primary focus:outline-none focus:ring-2 focus:ring-primary/40">
                        <input type="text" inputmode="numeric" maxlength="1" class="otp-box h-12 w-11 rounded-lg border border-slate-200 bg-slate-50/50 text-center text-xl font-bold text-slate-900 focus:border-primary focus:outline-none focus:ring-2 focus:ring-primary/40">
                    </div>
                    <div class="relative">
                        <input type="password" id="reset-password" required placeholder="New Password"
                            class="auth-field w-full rounded-lg border border-slate-200 bg-slate-50/50 px-3 py-2.5 pr-10 text-sm placeholder:text-slate-400">
                        <button type="button" data-toggle-password="reset-password" class="absolute inset-y-0 right-0 flex items-center pr-3 text-slate-400 hover:text-primary transition-colors" aria-label="Show password" aria-pressed="false">
                            <svg data-icon="eye" class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M2.036 12.322a1.012 1.012 0 010-.639C3.423 7.51 7.36 4.5 12 4.5c4.638 0 8.573 3.007 9.963 7.178.07.207.07.431 0 .639C20.577 16.49 16.64 19.5 12 19.5c-4.638 0-8.573-3.007-9.963-7.178z"/><path stroke-linecap="round" stroke-linejoin="round" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/></svg>
                            <svg data-icon="eye-off" class="hidden h-4 w-4" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M3.98 8.223A10.477 10.477 0 001.934 12C3.226 16.338 7.244 19.5 12 19.5c.993 0 1.953-.138 2.863-.395M6.228 6.228A10.45 10.45 0 0112 4.5c4.756 0 8.773 3.162 10.065 7.498a10.523 10.523 0 01-4.293 5.774M6.228 6.228L3 3m3.228 3.228l3.65 3.65m7.894 7.894L21 21m-3.228-3.228l-3.65-3.65m0 0a3 3 0 10-4.243-4.243m4.242 4.242L9.88 9.88"/></svg>
                        </button>
                    </div>
                    <input type="password" id="reset-confirm" required placeholder="Confirm Password"
                        class="auth-field w-full rounded-lg border border-slate-200 bg-slate-50/50 px-3 py-2.5 text-sm placeholder:text-slate-400">
                    <button type="submit" class="w-full rounded-lg bg-primary py-2.5 text-sm font-semibold text-white hover:bg-primary/90 transition-colors">
                        Reset Password
                    </button>
                </div>

                <p class="pt-1 text-center">
                    <button type="button" data-auth-switch="login" class="text-sm font-medium text-primary hover:text-primary">Back to Login</button>
                </p>
            </form>
            </div>
        </section>
    </div>

    <!-- Terms and Conditions -->
    <div id="termsModal" class="terms-overlay" role="dialog" aria-modal="true" aria-labelledby="termsModalTitle" aria-hidden="true">
        <div class="terms-backdrop" data-terms-close></div>
        <div class="terms-panel">
            <div class="border-b border-slate-100 px-6 py-5 sm:px-8">
                <h2 id="termsModalTitle" class="text-base font-bold text-slate-900">Terms and Conditions</h2>
                <p class="mt-1 text-xs text-slate-500">Azucena's Dental Clinic Patient Portal</p>
            </div>

            <div class="terms-content sm:px-8">
                <p class="text-sm text-slate-600">Last updated: <?php echo date('F j, Y'); ?>. By booking an appointment and using this system, you agree to the following terms.</p>

                <h3>1. Acceptance of Terms</h3>
                <p>These Terms and Conditions govern your use of the Azucena's Dental Clinic online patient portal. Booking an appointment through this system constitutes your acceptance of these terms, our privacy practices, and applicable Philippine laws including the Data Privacy Act of 2012 (Republic Act No. 10173).</p>

                <h3>2. Account Registration</h3>
                <p>Booking an appointment through this system automatically creates your patient portal account, used to track and manage your appointments.</p>
                <ul>
                    <li>You must provide accurate, complete, and current personal, contact, and health-related information.</li>
                    <li>You are responsible for maintaining the confidentiality of your login credentials.</li>
                    <li>You must notify the clinic immediately of any unauthorized access or suspected breach of your account.</li>
                    <li>The clinic may suspend or deactivate accounts that contain false information or are used improperly.</li>
                </ul>

                <h3>3. Appointments, Rescheduling, and Emergency Priority</h3>
                <ul>
                    <li>Online appointment requests are subject to clinic approval, dentist availability, and scheduling policies.</li>
                    <li>The clinic reserves the right to reschedule, reassign to another available dentist, or otherwise adjust a scheduled or confirmed appointment whenever necessary due to dentist availability — including sudden doctor leave, illness, or other unforeseen circumstances. Affected patients will be notified and assisted in arranging a new schedule.</li>
                    <li>Emergency and urgent cases are given scheduling priority. If a dentist must attend to an emergency while already treating another patient, the dentist will bring the ongoing procedure to a safe stopping point before attending to the emergency case; any resulting delay will be communicated to the affected patient.</li>
                    <li>Missed appointments, late arrivals, or repeated no-shows may affect future booking privileges.</li>
                    <li>Treatment plans, fees, and clinical decisions remain at the discretion of licensed dental professionals.</li>
                    <li>Walk-in emergency cases should be addressed in person or through direct clinic contact for the fastest response.</li>
                </ul>

                <h3>4. Medical and Dental Records</h3>
                <p>Information you provide—including dental history, profiling data, treatment notes, prescriptions, billing records, and uploaded documents—may be stored as part of your patient record. These records support diagnosis, treatment, continuity of care, and legal compliance for dental practice in the Philippines.</p>

                <h3>5. Data Privacy (RA 10173)</h3>
                <p>Azucena's Dental Clinic processes personal and sensitive personal information in accordance with the Data Privacy Act of 2012 and its implementing rules. Sensitive personal information may include health, medical, and dental data necessary for treatment and clinic operations.</p>

                <h3>6. Purpose of Data Collection and Use</h3>
                <ul>
                    <li>Patient registration, identity verification, and account management</li>
                    <li>Appointment scheduling, reminders, follow-ups, and clinic communications</li>
                    <li>Dental treatment, clinical documentation, and continuity of care</li>
                    <li>Billing, payment tracking, installment plans, and official receipts</li>
                    <li>Compliance with health regulations, record-keeping laws, and lawful requests</li>
                </ul>

                <h3>7. Data Retention</h3>
                <ul>
                    <li>Patient and clinical records are retained only as long as necessary for treatment, legal, regulatory, and operational purposes.</li>
                    <li>Medical and dental records are generally kept for a minimum period required by applicable health and professional regulations, or longer when required by law or ongoing treatment.</li>
                    <li>Account, billing, and transaction records are retained for auditing, tax, and financial compliance purposes.</li>
                    <li>System logs and security records may be retained for a limited period to investigate incidents and protect the platform.</li>
                    <li>When retention is no longer required, data is securely archived or permanently deleted in accordance with clinic policy.</li>
                </ul>

                <h3>8. Data Security</h3>
                <p>The clinic implements reasonable organizational, physical, and technical safeguards—including access controls, secure authentication, and monitored system activity—to protect your information against unauthorized access, disclosure, alteration, or destruction.</p>

                <h3>9. Your Rights as a Data Subject</h3>
                <p>Under the Data Privacy Act, you may request access to, correction of, or objection to the processing of your personal data, subject to legal and clinical limitations. You may also file a complaint with the National Privacy Commission (NPC) if you believe your data privacy rights have been violated.</p>

                <h3>10. Communications and Notifications</h3>
                <p>By using this system, you consent to receive appointment updates, billing notices, password recovery messages, and clinic-related notifications through the contact details you provide, including email and phone when applicable.</p>

                <h3>11. Billing, Payment, and Installment Plans</h3>
                <ul>
                    <li>Fees are based on the clinic's current service rates and the treatment plan recommended by your dentist.</li>
                    <li>Payment records, OR numbers, installment terms, and billing history may be stored in the system. You agree that financial information submitted through the portal is accurate and used solely for clinic billing and accounting purposes.</li>
                    <li>For qualifying treatments, the clinic may offer an installment payment plan requiring an initial down payment (a percentage of the total cost) with the remaining balance divided into scheduled installment terms, each with its own due date.</li>
                    <li>Applicable senior citizen, PWD, or other authorized discounts will be applied in accordance with clinic policy and upon presentation of the required supporting documentation.</li>
                    <li>Patients will receive reminders for upcoming and overdue installment due dates. Failure to settle an installment on or before its due date may result in late fees, suspension of further treatment, or referral for collection, at the clinic's discretion.</li>
                    <li>All payments are recorded and an official receipt is issued through the clinic's billing system.</li>
                </ul>

                <h3>12. Disclosure to Third Parties</h3>
                <p>Your information is not sold to third parties. Disclosure may occur only when required by law, necessary for treatment coordination, needed for secure system hosting or payment processing, or authorized by you in writing.</p>

                <h3>13. Minors and Guardians</h3>
                <p>If the account is created for a minor, the parent or legal guardian consents to the collection and processing of the minor's information for dental care and clinic services.</p>

                <h3>14. System Use and Acceptable Conduct</h3>
                <ul>
                    <li>You must not attempt to access another patient's records or staff-only areas.</li>
                    <li>You must not upload malicious content or interfere with system security.</li>
                    <li>Misuse of the portal may result in account suspension and appropriate action under applicable law.</li>
                </ul>

                <h3>15. Policy Updates</h3>
                <p>The clinic may update these Terms and Conditions, privacy practices, or retention policies to reflect legal, regulatory, or operational changes. Material updates will be reflected in this system. Continued use after updates constitutes acceptance of the revised terms.</p>

                <h3>16. Contact and Privacy Inquiries</h3>
                <p>For questions about these terms, your personal data, or requests related to the Data Privacy Act, contact Azucena's Dental Clinic through the Contact section of this website or visit the clinic during business hours.</p>

                <h3>17. Governing Law</h3>
                <p>These terms are governed by the laws of the Republic of the Philippines. Any disputes shall be resolved in accordance with applicable Philippine law and regulations.</p>
            </div>

            <div class="flex flex-col-reverse gap-2 border-t border-slate-100 bg-slate-50/80 px-6 py-4 sm:flex-row sm:justify-end sm:px-8">
                <button type="button" data-terms-close class="rounded-xl border border-slate-200 bg-white px-5 py-2.5 text-sm font-semibold text-slate-700 hover:bg-slate-50 transition-colors">
                    Close
                </button>
                <button type="button" id="acceptTermsBtn" class="rounded-xl bg-primary px-5 py-2.5 text-sm font-semibold text-white hover:bg-primary/90 transition-colors">
                    I Agree
                </button>
            </div>
        </div>
    </div>

    <!-- Back to top -->
    <button type="button" id="backToTopBtn" class="back-to-top-btn" aria-label="Back to top">
        <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M18 15l-6-6-6 6"/></svg>
    </button>

    <script src="assets/js/app-toast.js?v=20260614-short"></script>
    <script src="assets/js/app-request.js?v=20260612"></script>
    <script>
        const contactCsrfToken = <?php echo json_encode($csrf_token); ?>;
        const csrfToken = contactCsrfToken;

        function setAlert(message, type) {
            const tone = type === 'error' ? 'danger' : (type || 'info');
            if (typeof window.showAppToast === 'function') {
                window.showAppToast(String(message || ''), tone);
            }
        }

        // ----- Booking Tracker -----
        (function() {
            const input = document.getElementById('bookingTrackInput');
            const btn = document.getElementById('bookingTrackBtn');
            const resultCard = document.getElementById('bookingTrackResult');
            const errorEl = document.getElementById('bookingTrackError');

            function setStep(circleId, labelId, active, isError) {
                const circle = document.getElementById(circleId);
                const label = document.getElementById(labelId);
                if (isError) {
                    circle.className = circle.className
                        .replace('border-dashed border-slate-300 text-slate-400', 'border-solid border-red-500 bg-red-500 text-white');
                    label.className = label.className.replace('text-slate-400', 'text-red-600');
                    circle.innerHTML = '<svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke-width="2.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12"/></svg>';
                } else if (active) {
                    circle.className = circle.className
                        .replace('border-dashed border-slate-300 text-slate-400', 'border-solid border-green-500 bg-green-500 text-white');
                    label.className = label.className.replace('text-slate-400', 'text-green-600');
                    circle.innerHTML = '<svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke-width="2.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M4.5 12.75l6 6 9-13.5"/></svg>';
                }
            }

            function setLineActive(lineId, isError) {
                const line = document.getElementById(lineId);
                if (line) {
                    const color = isError ? 'bg-red-500' : 'bg-green-500';
                    line.className = line.className.replace('bg-slate-200', color);
                }
            }

            function resetSteps() {
                [1,2,3].forEach(function(i) {
                    var c = document.getElementById('step' + i + 'Circle');
                    var l = document.getElementById('step' + i + 'Label');
                    c.className = 'flex items-center justify-center w-10 h-10 rounded-full border-2 border-dashed border-slate-300 text-slate-400 text-xs font-bold transition-all duration-300';
                    c.innerHTML = i;
                    l.className = 'mt-2 text-xs font-medium text-slate-400 text-center leading-tight';
                });
                ['line1','line2'].forEach(function(id) {
                    var el = document.getElementById(id);
                    if (el) el.className = 'h-0.5 w-full bg-slate-200 transition-colors duration-300';
                });
            }

            function doTrack() {
                var raw = (input.value || '').trim();
                // Old-style plain numeric booking numbers (from links/emails sent before
                // the tracking code existed) still work via booking_id; anything else is
                // treated as the newer, unguessable alphanumeric tracking code.
                var isNumeric = /^[0-9]+$/.test(raw);
                var id = isNumeric ? raw : raw.replace(/[^A-Za-z0-9]/g, '').toUpperCase();
                if (!id) {
                    errorEl.textContent = 'Please enter a valid booking reference number.';
                    errorEl.classList.remove('hidden');
                    resultCard.classList.add('hidden');
                    return;
                }
                errorEl.classList.add('hidden');
                resultCard.classList.add('hidden');
                btn.textContent = 'Searching...';
                btn.disabled = true;

                var trackParam = isNumeric ? ('booking_id=' + encodeURIComponent(id)) : ('tracking_code=' + encodeURIComponent(id));
                fetch('assets/api/bookings/track_booking.php?' + trackParam)
                    .then(function(r) { return r.json(); })
                    .then(function(data) {
                        btn.textContent = 'Track';
                        btn.disabled = false;
                        if (!data.success) {
                            errorEl.textContent = data.message || 'Booking not found.';
                            errorEl.classList.remove('hidden');
                            return;
                        }
                        document.getElementById('trackBookingId').textContent = data.tracking_code || data.booking_id;
                        var statusEl = document.getElementById('trackBookingStatus');
                        var reasonEl = document.getElementById('trackBookingReason');
                        reasonEl.textContent = '';
                        reasonEl.classList.add('hidden');

                        if (data.cancelled) {
                            statusEl.textContent = 'Cancelled';
                            statusEl.style.backgroundColor = '#fee2e2';
                            statusEl.style.color = '#dc2626';
                            if (data.decline_reason) {
                                reasonEl.textContent = 'Reason: ' + data.decline_reason;
                                reasonEl.classList.remove('hidden');
                            }
                            resetSteps();
                            // Booking Created still happened — keep it green — then mark the
                            // next step (where it was rejected/cancelled) red with a cross.
                            setStep('step1Circle', 'step1Label', true);
                            setStep('step2Circle', 'step2Label', false, true);
                            setLineActive('line1', true);
                            resultCard.classList.remove('hidden');
                            return;
                        }

                        if (data.no_show) {
                            statusEl.textContent = 'No Show';
                            statusEl.style.backgroundColor = '#fee2e2';
                            statusEl.style.color = '#dc2626';
                            resetSteps();
                            resultCard.classList.remove('hidden');
                            return;
                        }

                        resetSteps();

                        resetSteps();
                        if (data.step1) setStep('step1Circle', 'step1Label', true);
                        if (data.step2) { setStep('step2Circle', 'step2Label', true); setLineActive('line1'); }
                        if (data.step3) { setStep('step3Circle', 'step3Label', true); setLineActive('line2'); }
                        
                        if (data.step3) {
                            statusEl.textContent = 'Successfully visited the clinic';
                            statusEl.style.backgroundColor = '#dcfce7';
                            statusEl.style.color = '#166534';
                        } else if (data.step2) {
                            statusEl.textContent = 'Booking confirmed, visit the clinic on the scheduled date';
                            statusEl.style.backgroundColor = '#ccfbf1';
                            statusEl.style.color = '#0d9488';
                        } else {
                            statusEl.textContent = 'Booking created, waiting for secretary approval';
                            statusEl.style.backgroundColor = '#fef3c7';
                            statusEl.style.color = '#92400e';
                        }
                        resultCard.classList.remove('hidden');
                    })
                    .catch(function() {
                        btn.textContent = 'Track';
                        btn.disabled = false;
                        errorEl.textContent = 'Something went wrong. Please try again.';
                        errorEl.classList.remove('hidden');
                    });
            }

            if (btn) btn.addEventListener('click', doTrack);
            if (input) input.addEventListener('keydown', function(e) { if (e.key === 'Enter') { e.preventDefault(); doTrack(); } });
        })();

        // ----- Header scroll behavior (Figma-style transparent → solid) -----
        const siteHeader = document.getElementById('siteHeader');
        const siteTitle = document.getElementById('siteTitle');
        const navLoginBtn = document.getElementById('navLoginBtn');
        const navRegisterBtn = document.getElementById('navRegisterBtn');
        const backToTopBtn = document.getElementById('backToTopBtn');
        const mobileMenuBtnEl = document.getElementById('mobileMenuBtn');

        function onScroll() {
            const y = window.scrollY || 0;
            const scrolled = y > 40;

            if (siteHeader) {
                siteHeader.style.background = scrolled ? 'rgba(247,250,248,0.92)' : 'transparent';
                siteHeader.style.backdropFilter = scrolled ? 'blur(12px)' : 'none';
                siteHeader.style.borderBottom = scrolled ? '1px solid rgba(42,140,112,0.1)' : 'none';
                siteHeader.style.boxShadow = scrolled ? '0 2px 24px rgba(42,140,112,0.06)' : 'none';
                siteHeader.classList.toggle('header-scrolled', scrolled);
            }

            if (siteTitle) {
                siteTitle.style.color = scrolled ? '#0f1e35' : '#ffffff';
            }

            if (navLoginBtn) {
                if (scrolled) {
                    navLoginBtn.style.background = '#0d9488';
                    navLoginBtn.style.color = '#ffffff';
                    navLoginBtn.style.border = 'none';
                    navLoginBtn.style.backdropFilter = 'none';
                } else {
                    navLoginBtn.style.background = 'rgba(255,255,255,0.15)';
                    navLoginBtn.style.color = '#ffffff';
                    navLoginBtn.style.border = '1px solid rgba(255,255,255,0.35)';
                    navLoginBtn.style.backdropFilter = 'blur(8px)';
                }
            }

            if (mobileMenuBtnEl) {
                mobileMenuBtnEl.style.color = scrolled ? '#0f1e35' : '#ffffff';
            }

            if (backToTopBtn) {
                backToTopBtn.classList.toggle('is-visible', y >= 480);
            }
        }
        window.addEventListener('scroll', onScroll, { passive: true });
        onScroll();

        backToTopBtn?.addEventListener('click', function() {
            window.scrollTo({ top: 0, behavior: 'smooth' });
        });

        // ----- Auth dialogs -----
        const authModal = document.getElementById('authModal');
        const authPanels = {
            login: document.getElementById('authPanelLogin'),
            booking: document.getElementById('authPanelBooking'),
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
            // Reset booking form if open
            const bookingForm = document.getElementById('booking-form');
            const otpSection = document.getElementById('booking-otp-section');
            const successSection = document.getElementById('booking-success-section');
            if (bookingForm) bookingForm.classList.remove('hidden');
            if (otpSection) otpSection.classList.add('hidden');
            if (successSection) successSection.classList.add('hidden');
            if (bookingForm) bookingForm.reset();
            const otpInput = document.getElementById('booking-otp');
            if (otpInput) otpInput.value = '';
        }

        document.querySelectorAll('[data-auth-open]').forEach(function(btn) {
            btn.addEventListener('click', function() {
                setMobileMenu(false);
                openAuthModal(btn.getAttribute('data-auth-open'));
            });
        });

        document.querySelectorAll('[data-auth-switch]').forEach(function(btn) {
            btn.addEventListener('click', function() {
                const target = btn.getAttribute('data-auth-switch');
                if (target === 'forgot') {
                    const loginEmail = document.getElementById('login-email');
                    const resetEmail = document.getElementById('reset-email');
                    if (loginEmail && resetEmail && loginEmail.value.trim()) {
                        resetEmail.value = loginEmail.value.trim();
                    }
                }
                openAuthModal(target);
            });
        });

        document.querySelectorAll('[data-auth-close]').forEach(function(el) {
            el.addEventListener('click', closeAuthModal);
        });

        document.addEventListener('keydown', function(event) {
            if (event.key === 'Escape') {
                if (termsModal?.classList.contains('open')) {
                    closeTermsModal();
                    return;
                }
                if (authModal?.classList.contains('open')) {
                    closeAuthModal();
                }
            }
        });

        // ----- Terms and Conditions modal -----
        const termsModal = document.getElementById('termsModal');
        const regTermsCheckbox = document.getElementById('reg-terms');

        function openTermsModal() {
            if (!termsModal) return;
            termsModal.classList.add('open');
            termsModal.setAttribute('aria-hidden', 'false');
            document.body.style.overflow = 'hidden';
        }

        function closeTermsModal() {
            if (!termsModal) return;
            termsModal.classList.remove('open');
            termsModal.setAttribute('aria-hidden', 'true');
            if (!authModal?.classList.contains('open')) {
                document.body.style.overflow = '';
            }
        }

        document.getElementById('openTermsModal')?.addEventListener('click', function(event) {
            event.preventDefault();
            event.stopPropagation();
            openTermsModal();
        });

        document.querySelectorAll('[data-terms-close]').forEach(function(el) {
            el.addEventListener('click', closeTermsModal);
        });

        document.getElementById('acceptTermsBtn')?.addEventListener('click', function() {
            if (regTermsCheckbox) regTermsCheckbox.checked = true;
            closeTermsModal();
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
        function validateLoginEmail() {
            const val = document.getElementById('login-email').value.trim();
            if (!val) { showFieldError('login-email', 'login-email-error', 'Email is required.'); return false; }
            if (!/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(val)) { showFieldError('login-email', 'login-email-error', 'Enter a valid email address.'); return false; }
            clearFieldError('login-email', 'login-email-error');
            return true;
        }

        document.getElementById('login-email')?.addEventListener('blur', validateLoginEmail);

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
                    setAlert('Login successful.', 'success');

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
                    const passwordEl = document.getElementById('login-password');
                    if (passwordEl) {
                        passwordEl.value = '';
                        passwordEl.focus();
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
        // ----- Booking form (guest) -----
        function setBookingAlert(message, type) {
            const alertEl = document.getElementById('booking-alert');
            if (!alertEl) return;
            const tone = type === 'error' ? 'danger' : (type || 'info');
            alertEl.className = 'rounded-xl border px-4 py-3 text-sm';
            if (tone === 'danger') {
                alertEl.classList.add('border-red-200', 'bg-red-50', 'text-red-700');
            } else if (tone === 'success') {
                alertEl.classList.add('border-green-200', 'bg-green-50', 'text-green-700');
            } else if (tone === 'warning') {
                alertEl.classList.add('border-amber-200', 'bg-amber-50', 'text-amber-700');
            } else {
                alertEl.classList.add('border-blue-200', 'bg-blue-50', 'text-blue-700');
            }
            alertEl.textContent = message;
            alertEl.classList.remove('hidden');
        }

        let bookingEmail = '';
        let bookingId = 0;

        // ----- Booking inline validation (on blur) -----
        function showFieldError(inputId, errorId, message) {
            const input = document.getElementById(inputId);
            const errorEl = document.getElementById(errorId);
            if (input) input.classList.add('field-invalid');
            if (errorEl) { errorEl.textContent = message; errorEl.classList.add('show'); }
        }

        function clearFieldError(inputId, errorId) {
            const input = document.getElementById(inputId);
            const errorEl = document.getElementById(errorId);
            if (input) input.classList.remove('field-invalid');
            if (errorEl) { errorEl.textContent = ''; errorEl.classList.remove('show'); }
        }

        function validateBookEmail() {
            const val = document.getElementById('book-email').value.trim();
            if (!val) { showFieldError('book-email', 'book-email-error', 'Email is required.'); return false; }
            if (!/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(val)) { showFieldError('book-email', 'book-email-error', 'Enter a valid email address.'); return false; }
            clearFieldError('book-email', 'book-email-error');
            return true;
        }

        function validateBookFirstName() {
            const val = document.getElementById('book-first-name').value.trim();
            if (!val) { showFieldError('book-first-name', 'book-first-name-error', 'First name is required.'); return false; }
            clearFieldError('book-first-name', 'book-first-name-error');
            return true;
        }

        function validateBookLastName() {
            const val = document.getElementById('book-last-name').value.trim();
            if (!val) { showFieldError('book-last-name', 'book-last-name-error', 'Last name is required.'); return false; }
            clearFieldError('book-last-name', 'book-last-name-error');
            return true;
        }

        function validateBookDob() {
            const val = document.getElementById('book-dob').value;
            if (!val) { showFieldError('book-dob', 'book-dob-error', 'Date of birth is required.'); return false; }
            clearFieldError('book-dob', 'book-dob-error');
            return true;
        }

        function validateBookPrefDate() {
            const val = document.getElementById('book-pref-date').value;
            if (!val) { showFieldError('book-pref-date', 'book-pref-date-error', 'Preferred date is required.'); return false; }
            const today = new Date();
            today.setHours(0, 0, 0, 0);
            if (new Date(val) < today) { showFieldError('book-pref-date', 'book-pref-date-error', 'Preferred date cannot be in the past.'); return false; }
            clearFieldError('book-pref-date', 'book-pref-date-error');
            return true;
        }

        function validateBookPhone() {
            const code = document.getElementById('book-phone-code').value;
            const raw = document.getElementById('book-phone').value.trim();
            if (!raw) { showFieldError('book-phone', 'book-phone-error', 'Phone number is required.'); return false; }
            const digits = raw.replace(/\D/g, '');
            if (digits.length < 7 || digits.length > 15) { showFieldError('book-phone', 'book-phone-error', 'Enter a valid phone number (7–15 digits).'); return false; }
            if (code === '+63' && (!/^\d{10}$/.test(digits) || !digits.startsWith('9'))) {
                showFieldError('book-phone', 'book-phone-error', 'PH numbers must be 10 digits starting with 9 (e.g. 9171234567).');
                return false;
            }
            clearFieldError('book-phone', 'book-phone-error');
            return true;
        }

        ['book-email'].forEach(function(id) {
            document.getElementById(id)?.addEventListener('blur', validateBookEmail);
        });
        ['book-first-name'].forEach(function(id) {
            document.getElementById(id)?.addEventListener('blur', validateBookFirstName);
        });
        ['book-last-name'].forEach(function(id) {
            document.getElementById(id)?.addEventListener('blur', validateBookLastName);
        });
        ['book-dob'].forEach(function(id) {
            document.getElementById(id)?.addEventListener('blur', validateBookDob);
        });
        ['book-pref-date'].forEach(function(id) {
            document.getElementById(id)?.addEventListener('blur', validateBookPrefDate);
        });
        ['book-phone'].forEach(function(id) {
            document.getElementById(id)?.addEventListener('blur', validateBookPhone);
            document.getElementById(id)?.addEventListener('input', function() {
                this.value = this.value.replace(/\D/g, '').slice(0, 10);
            });
        });

        function validateBookTime() {
            const raw = document.getElementById('book-time').value.trim();
            if (!raw) { showFieldError('book-time', 'book-time-error', 'Time is required.'); return false; }
            const match = raw.match(/^(\d{1,2}):(\d{2})\s*(AM|PM)$/i);
            if (!match) { showFieldError('book-time', 'book-time-error', 'Please set a valid time.'); return false; }
            let h = parseInt(match[1], 10);
            const m = parseInt(match[2], 10);
            const meridiem = match[3].toUpperCase();
            let h24 = h;
            if (meridiem === 'AM' && h === 12) h24 = 0;
            else if (meridiem === 'PM' && h !== 12) h24 = h + 12;
            const mins = h24 * 60 + m;
            if (mins < 480) { showFieldError('book-time', 'book-time-error', 'Earliest time is 8:00 AM.'); return false; }
            if (mins > 990) { showFieldError('book-time', 'book-time-error', 'Latest time is 4:30 PM (cut-off before 5 PM closing).'); return false; }
            clearFieldError('book-time', 'book-time-error');
            return true;
        }

        // ----- Time picker logic -----
        (function() {
            const hourEl = document.getElementById('book-hour');
            const minEl = document.getElementById('book-minute');
            const merBtn = document.getElementById('btn-meridiem');
            const hiddenEl = document.getElementById('book-time');
            let currentMeridiem = 'AM';

            function syncTime() {
                const h = (hourEl.value || '').padStart(2, '0');
                const m = (minEl.value || '').padStart(2, '0');
                hiddenEl.value = h + ':' + m + ' ' + currentMeridiem;
            }

            function syncAndValidate() {
                syncTime();
                if (hourEl.value && minEl.value) validateBookTime();
            }

            merBtn.addEventListener('click', function() {
                currentMeridiem = currentMeridiem === 'AM' ? 'PM' : 'AM';
                merBtn.textContent = currentMeridiem;
                syncAndValidate();
            });

            hourEl.addEventListener('input', function() {
                this.value = this.value.replace(/\D/g, '').slice(0, 2);
                if (parseInt(this.value, 10) > 12) this.value = '12';
                if (parseInt(this.value, 10) === 0 && this.value.length === 2) this.value = '12';
                syncAndValidate();
            });

            hourEl.addEventListener('blur', function() {
                let v = parseInt(this.value, 10);
                if (isNaN(v) || v < 1) v = 1;
                if (v > 12) v = 12;
                this.value = v;
                syncAndValidate();
            });

            minEl.addEventListener('input', function() {
                this.value = this.value.replace(/\D/g, '').slice(0, 2);
                if (parseInt(this.value, 10) > 59) this.value = '59';
                syncAndValidate();
            });

            minEl.addEventListener('blur', function() {
                let v = parseInt(this.value, 10);
                if (isNaN(v) || v < 0) v = 0;
                if (v > 59) v = 59;
                this.value = v < 10 ? '0' + v : '' + v;
                syncAndValidate();
            });
        })();

        document.getElementById('booking-form')?.addEventListener('submit', async function(event) {
            event.preventDefault();

            const firstName = document.getElementById('book-first-name').value.trim();
            const lastName = document.getElementById('book-last-name').value.trim();
            const email = document.getElementById('book-email').value.trim();
            const phone = document.getElementById('book-phone').value.trim();
            const dob = document.getElementById('book-dob').value;
            const prefDate = document.getElementById('book-pref-date').value;
            const prefTime = document.getElementById('book-time')?.value || '8:00 AM';
            const checkedComplaints = Array.from(document.querySelectorAll('.complaint-check:checked')).map(function(cb) { return cb.value; });
            const reasonText = document.getElementById('book-reason').value.trim();
            const reason = checkedComplaints.length > 0
                ? (reasonText ? checkedComplaints.join(', ') + ' — ' + reasonText : checkedComplaints.join(', '))
                : reasonText;
            const submitBtn = document.getElementById('booking-submit-btn');

            const fnValid = validateBookFirstName();
            const lnValid = validateBookLastName();
            const emailValid = validateBookEmail();
            const phoneValid = validateBookPhone();
            const dobValid = validateBookDob();
            const prefDateValid = validateBookPrefDate();
            const timeValid = validateBookTime();

            if (!fnValid || !lnValid || !emailValid || !phoneValid || !dobValid || !prefDateValid || !timeValid) {
                return;
            }

            if (regTermsCheckbox && !regTermsCheckbox.checked) {
                showFieldError('reg-terms', 'reg-terms-error', 'You must agree to the Terms and Conditions to book an appointment.');
                if (typeof showToast === 'function') {
                    showToast('Please agree to the Terms and Conditions to book an appointment.', 'warning');
                }
                regTermsCheckbox.focus();
                return;
            }
            clearFieldError('reg-terms', 'reg-terms-error');

            submitBtn.disabled = true;
            submitBtn.textContent = 'Sending...';

            try {
                const fd = new FormData();
                fd.append('first_name', firstName);
                fd.append('last_name', lastName);
                fd.append('email', email);
                fd.append('phone_number', document.getElementById('book-phone-code').value + phone.replace(/\D/g, ''));
                fd.append('date_of_birth', dob);
                fd.append('preferred_date', prefDate);
                fd.append('preferred_time', prefTime);
                fd.append('reason', reason);
                fd.append('terms_accepted', regTermsCheckbox && regTermsCheckbox.checked ? '1' : '0');
                fd.append('csrf_token', csrfToken);

                const response = await fetch('assets/api/bookings/create_booking.php', {
                    method: 'POST',
                    body: fd
                });
                const data = await response.json();

                if (data.success) {
                    bookingEmail = email;
                    // Show OTP section
                    document.getElementById('booking-form').classList.add('hidden');
                    document.getElementById('booking-otp-section').classList.remove('hidden');
                    document.getElementById('otp-email-display').textContent = email;
                    document.querySelector('#booking-otp-boxes .otp-box')?.focus();
                } else {
                    if (data.code === 'account_exists') {
                        setBookingAlert(data.message, 'warning');
                        setTimeout(function() { openAuthModal('login'); }, 2000);
                    } else {
                        setBookingAlert(data.message || 'Booking failed. Please try again.', 'error');
                    }
                }
            } catch (error) {
                setBookingAlert('An error occurred. Please try again.', 'error');
                console.error('Booking error:', error);
            } finally {
                submitBtn.disabled = false;
                submitBtn.textContent = 'Request Appointment';
            }
        });

        // OTP verification
        document.getElementById('verify-otp-btn')?.addEventListener('click', async function() {
            const otp = document.getElementById('booking-otp').value.trim();
            const btn = this;
            const otpAlert = document.getElementById('otp-alert');

            if (!otp || otp.length !== 6) {
                if (otpAlert) {
                    otpAlert.className = 'mt-4 rounded-xl border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-700';
                    otpAlert.textContent = 'Please enter the 6-digit code.';
                    otpAlert.classList.remove('hidden');
                }
                return;
            }

            btn.disabled = true;
            btn.textContent = 'Verifying...';

            try {
                const fd = new FormData();
                fd.append('otp', otp);

                const response = await fetch('assets/api/bookings/verify_booking_otp.php', {
                    method: 'POST',
                    body: fd
                });
                const data = await response.json();

                if (data.success) {
                    // Show success
                    document.getElementById('booking-otp-section').classList.add('hidden');
                    document.getElementById('booking-success-section').classList.remove('hidden');
                    document.getElementById('booking-ref-display').textContent = data.tracking_code || ('#' + (data.booking_id || ''));

                    // Copy button
                    document.getElementById('copyBookingRefBtn')?.addEventListener('click', function() {
                        const ref = data.tracking_code || ('#' + (data.booking_id || ''));
                        if (navigator.clipboard && navigator.clipboard.writeText) {
                            navigator.clipboard.writeText(ref);
                        } else {
                            const tmp = document.createElement('textarea');
                            tmp.value = ref;
                            document.body.appendChild(tmp);
                            tmp.select();
                            document.execCommand('copy');
                            document.body.removeChild(tmp);
                        }
                        const btn = this;
                        btn.innerHTML = '<svg class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke-width="2.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M4.5 12.75l6 6 9-13.5"/></svg>';
                        setTimeout(function() { btn.innerHTML = '<svg class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M15.666 3.888A2.25 2.25 0 0013.5 2.25h-3c-1.03 0-1.9.693-2.166 1.638m7.332 0c.055.194.084.4.084.612v0a.75.75 0 01-.75.75H9.75a.75.75 0 01-.75-.75v0c0-.212.03-.418.084-.612m7.332 0c.646.049 1.288.11 1.927.184 1.1.128 1.907 1.077 1.907 2.185V19.5a2.25 2.25 0 01-2.25 2.25H6.75A2.25 2.25 0 014.5 19.5V6.257c0-1.108.806-2.057 1.907-2.185a48.208 48.208 0 011.927-.184"/></svg>'; }, 1500);
                    });
                } else {
                    if (otpAlert) {
                        otpAlert.className = 'mt-4 rounded-xl border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700';
                        otpAlert.textContent = data.message || 'Verification failed.';
                        otpAlert.classList.remove('hidden');
                    }
                }
            } catch (error) {
                if (otpAlert) {
                    otpAlert.className = 'mt-4 rounded-xl border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700';
                    otpAlert.textContent = 'An error occurred. Please try again.';
                    otpAlert.classList.remove('hidden');
                }
            } finally {
                btn.disabled = false;
                btn.textContent = 'Verify & Confirm Booking';
            }
        });

        // Resend OTP
        document.getElementById('resend-otp-btn')?.addEventListener('click', async function() {
            const btn = this;
            btn.disabled = true;
            btn.textContent = 'Sending...';

            try {
                const fd = new FormData();
                fd.append('first_name', document.getElementById('book-first-name').value.trim());
                fd.append('last_name', document.getElementById('book-last-name').value.trim());
                fd.append('email', bookingEmail);
                fd.append('phone_number', document.getElementById('book-phone-code').value + document.getElementById('book-phone').value.trim().replace(/\D/g, ''));
                fd.append('date_of_birth', document.getElementById('book-dob').value);
                fd.append('preferred_date', document.getElementById('book-pref-date').value);
                fd.append('preferred_time', document.getElementById('book-time')?.value || '8:00 AM');
                const resendComplaints = Array.from(document.querySelectorAll('.complaint-check:checked')).map(function(cb) { return cb.value; });
                const resendReasonText = document.getElementById('book-reason').value.trim();
                const resendReason = resendComplaints.length > 0
                    ? (resendReasonText ? resendComplaints.join(', ') + ' — ' + resendReasonText : resendComplaints.join(', '))
                    : resendReasonText;
                fd.append('reason', resendReason);
                fd.append('csrf_token', csrfToken);

                await fetch('assets/api/bookings/create_booking.php', { method: 'POST', body: fd });
                const otpAlert = document.getElementById('otp-alert');
                if (otpAlert) {
                    otpAlert.className = 'mt-4 rounded-xl border border-green-200 bg-green-50 px-4 py-3 text-sm text-green-700';
                    otpAlert.textContent = 'New code sent. Check your email.';
                    otpAlert.classList.remove('hidden');
                }
            } catch (e) {
                // silent
            } finally {
                btn.disabled = false;
                btn.textContent = 'Resend code';
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
                    this.textContent = 'Resend OTP';
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
                    setAlert('Password updated.', 'success');
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

        // ----- Google OAuth -----
        const googleClientId = <?php echo json_encode($googleClientId); ?>;
        let googleApiReady = false;
        let googleTokenClient = null;

        function handleGoogleCredential(response) {
            if (!response) {
                setAlert('Google sign-in was cancelled.', 'warning');
                return;
            }
            if (!response.access_token) {
                console.error('Google OAuth response:', response);
                setAlert('Google sign-in failed: no access token received.', 'error');
                return;
            }
            setAlert('Signing in with Google...', 'info');
            fetch('https://www.googleapis.com/oauth2/v3/userinfo', {
                headers: { 'Authorization': 'Bearer ' + response.access_token }
            })
            .then(function(r) { return r.json(); })
            .then(function(userInfo) {
                if (userInfo.error) {
                    setAlert('Google sign-in failed: ' + (userInfo.error_description || userInfo.error), 'error');
                    return null;
                }
                return fetch('assets/api/auth/google_oauth_api.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({
                        access_token: response.access_token,
                        email: userInfo.email || '',
                        name: userInfo.name || '',
                        given_name: userInfo.given_name || '',
                        family_name: userInfo.family_name || '',
                        picture: userInfo.picture || '',
                        sub: userInfo.sub || ''
                    })
                });
            })
            .then(function(r) { return r ? r.json() : null; })
            .then(function(data) {
                if (!data) return;
                if (data.success) {
                    setAlert(data.message || 'Signed in successfully.', 'success');
                    setTimeout(function() {
                        window.location.href = data.redirect || 'patient/patient.php';
                    }, 800);
                } else {
                    setAlert(data.message || 'Google sign-in failed. Please try again.', 'error');
                }
            })
            .catch(function(err) {
                console.error('Google sign-in error:', err);
                setAlert('Google sign-in failed. Please try again.', 'error');
            });
        }

        function triggerGoogleSignIn() {
            if (!googleClientId || !googleApiReady || !googleTokenClient) {
                setAlert('Google sign-in is still loading. Please try again in a moment.', 'warning');
                return;
            }
            googleTokenClient.requestAccessToken({ prompt: 'consent' });
        }

        if (googleClientId) {
            const _gsiPoll = setInterval(function() {
                if (typeof google !== 'undefined' && google.accounts && google.accounts.oauth2) {
                    clearInterval(_gsiPoll);
                    googleApiReady = true;
                    googleTokenClient = google.accounts.oauth2.initTokenClient({
                        client_id: googleClientId,
                        scope: 'openid email profile',
                        callback: handleGoogleCredential,
                        error_callback: function(err) {
                            console.error('Google OAuth error:', err);
                            setAlert('Google sign-in error: ' + (err.type || 'unknown'), 'error');
                        }
                    });
                }
            }, 100);

            setTimeout(function() {
                if (!googleApiReady) {
                    clearInterval(_gsiPoll);
                    setAlert('Google sign-in could not load. Please refresh the page.', 'error');
                }
            }, 10000);
        }

        document.getElementById('google-login-btn')?.addEventListener('click', triggerGoogleSignIn);

        // Session timeout notice
        if (new URLSearchParams(window.location.search).has('timeout')) {
            setAlert('Session expired.', 'warning');
            openAuthModal('login');
            window.history.replaceState({}, '', 'index.php');
        }

        // ----- Mobile menu -----
        const mobileMenuBtn2 = document.getElementById('mobileMenuBtn');
        const mobileMenu = document.getElementById('mobileMenu');
        const iconOpen = document.getElementById('mobileMenuIconOpen');
        const iconClose = document.getElementById('mobileMenuIconClose');

        function setMobileMenu(open) {
            if (!mobileMenu || !mobileMenuBtn2) return;
            mobileMenu.classList.toggle('hidden', !open);
            iconOpen?.classList.toggle('hidden', open);
            iconClose?.classList.toggle('hidden', !open);
            mobileMenuBtn2.setAttribute('aria-expanded', open ? 'true' : 'false');
        }

        mobileMenuBtn2?.addEventListener('click', function() {
            setMobileMenu(mobileMenu.classList.contains('hidden'));
        });

        document.querySelectorAll('.mobile-nav-link').forEach(function(link) {
            link.addEventListener('click', function() { setMobileMenu(false); });
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
        const sections = ['hero', 'services', 'about', 'testimonials', 'contact'];
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
            const tone = type === 'error' ? 'danger' : (type === 'success' ? 'success' : (type || 'info'));
            if (typeof window.showAppToast === 'function') {
                window.showAppToast(String(message || ''), tone);
                return;
            }
            const alertEl = document.getElementById('contact-form-alert');
            if (!alertEl) return;
            alertEl.classList.remove('hidden');
            alertEl.textContent = message;
        }

        const contactForm = document.getElementById('contact-form');
        if (contactForm) {
            contactForm.addEventListener('submit', async function(event) {
                event.preventDefault();

                const name = document.getElementById('contact-name').value.trim();
                const email = document.getElementById('contact-email').value.trim();
                const phone = document.getElementById('contact-phone').value.trim();
                const message = document.getElementById('contact-message').value.trim();

                if (!name || !email || !phone || !message) {
                    setContactAlert('Complete all fields.', 'error');
                    return;
                }

                const phoneDigits = phone.replace(/\D+/g, '');
                if (phoneDigits.length < 7 || phoneDigits.length > 15) {
                    setContactAlert('Please enter a valid phone number.', 'error');
                    return;
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
                        setContactAlert(data.message || 'Could not send message.', 'error');
                        return;
                    }

                    contactForm.reset();
                    setContactAlert(data.message || 'Message sent.', 'success');
                } catch (error) {
                    setContactAlert('Send failed.', 'error');
                }
            });
        }
    </script>

    <script>
        // Per-digit OTP boxes: keeps the underlying hidden input (read by the
        // existing verify/reset logic) in sync with each visible 1-char box.
        (function() {
            document.querySelectorAll('.otp-boxes').forEach(function(group) {
                const hiddenInput = document.getElementById(group.getAttribute('data-otp-target'));
                const boxes = Array.from(group.querySelectorAll('.otp-box'));
                if (!hiddenInput || boxes.length === 0) return;

                function syncHidden() {
                    hiddenInput.value = boxes.map(function(b) { return b.value; }).join('');
                }

                boxes.forEach(function(box, idx) {
                    box.addEventListener('input', function() {
                        box.value = box.value.replace(/[^0-9]/g, '').slice(0, 1);
                        syncHidden();
                        if (box.value && idx < boxes.length - 1) {
                            boxes[idx + 1].focus();
                        }
                    });

                    box.addEventListener('keydown', function(e) {
                        if (e.key === 'Backspace' && !box.value && idx > 0) {
                            boxes[idx - 1].focus();
                        } else if (e.key === 'ArrowLeft' && idx > 0) {
                            boxes[idx - 1].focus();
                        } else if (e.key === 'ArrowRight' && idx < boxes.length - 1) {
                            boxes[idx + 1].focus();
                        }
                    });

                    box.addEventListener('paste', function(e) {
                        e.preventDefault();
                        const pasted = (e.clipboardData || window.clipboardData).getData('text').replace(/[^0-9]/g, '');
                        if (!pasted) return;
                        pasted.split('').slice(0, boxes.length).forEach(function(ch, i) {
                            boxes[i].value = ch;
                        });
                        syncHidden();
                        const nextEmpty = boxes.findIndex(function(b) { return !b.value; });
                        boxes[nextEmpty === -1 ? boxes.length - 1 : nextEmpty].focus();
                    });
                });
            });
        })();
    </script>

</body>
</html>