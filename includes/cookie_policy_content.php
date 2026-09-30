<?php
/**
 * Cookie Policy body, shown in the landing-page modal (index.php, #cookieModal).
 * Keep this list in sync with what the
 * system actually stores: config/security.php (session), config/remember_me.php,
 * and the localStorage/sessionStorage keys in assets/js and index.php.
 */
$cookiePolicyUpdated = 'September 30, 2026';
?>
<style>
    .cookie-table { width: 100%; border-collapse: collapse; margin: 0.5rem 0 0.75rem; font-size: 0.8125rem; }
    .cookie-table th, .cookie-table td { border: 1px solid #e2e8f0; padding: 0.5rem 0.625rem; text-align: left; vertical-align: top; line-height: 1.45; color: #475569; }
    .cookie-table th { background: #f1f7fa; color: #0f1e35; font-weight: 700; }
    .cookie-table code { font-size: 0.75rem; color: #1b7aa3; background: #eff8fb; padding: 0.05rem 0.3rem; border-radius: 4px; white-space: nowrap; }
    .cookie-table-wrap { overflow-x: auto; }
</style>

<p class="text-sm text-slate-600">Last updated: <?php echo htmlspecialchars($cookiePolicyUpdated, ENT_QUOTES, 'UTF-8'); ?>. This policy explains the cookies and similar browser storage used by the Azucena's Dental Clinic website and patient portal.</p>

<h3>1. What Cookies Are</h3>
<p>Cookies are small text files a website saves in your browser. This system also uses your browser's local and session storage, which work similarly but are never sent to our server automatically. Both are covered by this policy.</p>

<h3>2. How We Use Them</h3>
<ul>
    <li>We use <strong>only essential cookies</strong> needed to sign you in, keep your session secure, and remember choices you make.</li>
    <li>We do <strong>not</strong> use advertising, analytics, or tracking cookies, and we do not sell or share cookie data for marketing.</li>
    <li>Because these cookies are required for the service you ask for (such as signing in or booking), they are set without a separate consent prompt, in line with the Data Privacy Act of 2012 (RA 10173).</li>
</ul>

<h3>3. Cookies We Set</h3>
<div class="cookie-table-wrap">
    <table class="cookie-table">
        <thead>
            <tr><th>Name</th><th>Purpose</th><th>How long it lasts</th></tr>
        </thead>
        <tbody>
            <tr>
                <td><code>PHPSESSID</code></td>
                <td>Keeps you signed in as you move between pages and protects forms against forged requests. Marked HttpOnly and SameSite so page scripts and other sites cannot read it.</td>
                <td>Until you log out or your session ends after a period of inactivity.</td>
            </tr>
            <tr>
                <td><code>dental_remember</code></td>
                <td>Set only if you tick <em>Remember me</em>. Keeps you signed in on this device. It holds a random token, never your password, and the token changes each time it is used.</td>
                <td>Up to 30 days, or until you log out.</td>
            </tr>
        </tbody>
    </table>
</div>

<h3>4. Browser Storage We Use</h3>
<p>These items stay on your device and are not cookies. They hold preferences or temporary data, not your medical records.</p>
<div class="cookie-table-wrap">
    <table class="cookie-table">
        <thead>
            <tr><th>Name</th><th>Purpose</th><th>How long it lasts</th></tr>
        </thead>
        <tbody>
            <tr>
                <td><code>dental_remember_email</code></td>
                <td>Fills in your email or username on the login form when you choose <em>Remember me</em>.</td>
                <td>Until you untick Remember me or clear your browser data.</td>
            </tr>
            <tr>
                <td><code>dental_cookie_notice</code></td>
                <td>Remembers that you dismissed the cookie notice so it is not shown again.</td>
                <td>Until you clear your browser data.</td>
            </tr>
            <tr>
                <td><code>sidebar-collapsed</code></td>
                <td>Remembers whether you collapsed the portal sidebar.</td>
                <td>Until you clear your browser data.</td>
            </tr>
            <tr>
                <td><code>dentalPageSkeleton:v1</code></td>
                <td>Stores the on-screen layout of portal pages (box positions only, no text or patient data) to show matching loading placeholders.</td>
                <td>Until you clear your browser data.</td>
            </tr>
            <tr>
                <td><code>dental_*</code> (session storage)</td>
                <td>Briefly caches data the portal already loaded so pages open faster.</td>
                <td>About 30 seconds per item; all cleared when you close the tab.</td>
            </tr>
        </tbody>
    </table>
</div>

<h3>5. Third-Party Services</h3>
<ul>
    <li><strong>Google Sign-In</strong> &mdash; if you choose to sign in with Google, Google may set its own cookies under its privacy policy.</li>
    <li><strong>Cloudflare Turnstile</strong> &mdash; when enabled, protects the login form from bots and may process basic browser and device information.</li>
    <li><strong>Google Fonts and content delivery networks</strong> &mdash; load fonts and page styles. They receive your IP address as part of a normal web request but are not used by us to track you.</li>
</ul>

<h3>6. Managing Cookies</h3>
<ul>
    <li>You can view, block, or delete cookies and site data in your browser settings.</li>
    <li>Blocking essential cookies will prevent you from signing in to the patient portal.</li>
    <li>Logging out ends your session and removes the <em>Remember me</em> cookie for that device.</li>
</ul>

<h3>7. Changes to This Policy</h3>
<p>We may update this policy when the system changes how it uses cookies or storage. The date at the top shows the latest revision.</p>

<h3>8. Contact</h3>
<p>For questions about this policy or your personal data, contact Azucena's Dental Clinic through the Contact section of this website or visit the clinic during business hours. You may also contact the National Privacy Commission (NPC) regarding your data privacy rights.</p>
