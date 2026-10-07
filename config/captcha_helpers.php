<?php
// Offline fallback for the login captcha. Cloudflare Turnstile needs internet on both
// the browser and the server; when CAPTCHA_MATH_FALLBACK=true (local/LAN demos only)
// the login form can switch to a simple math question checked against the session.

if (!function_exists('captcha_math_fallback_enabled')) {
    function captcha_math_fallback_enabled(): bool
    {
        return function_exists('env') && env('CAPTCHA_MATH_FALLBACK', false) === true;
    }

    // Generates a new question, stores the answer in the session and returns the question text.
    function captcha_math_new(): string
    {
        $a = random_int(1, 12);
        $b = random_int(1, 12);
        if (random_int(0, 1) === 1) {
            $question = "$a + $b";
            $answer = $a + $b;
        } else {
            if ($b > $a) {
                [$a, $b] = [$b, $a];
            }
            $question = "$a - $b";
            $answer = $a - $b;
        }

        $_SESSION['captcha_math_answer'] = $answer;
        $_SESSION['captcha_math_expires'] = time() + 600;

        return $question;
    }

    // One-time check: the stored answer is cleared whether or not it matches.
    function captcha_math_verify($answer): bool
    {
        $expected = $_SESSION['captcha_math_answer'] ?? null;
        $expires = (int)($_SESSION['captcha_math_expires'] ?? 0);
        unset($_SESSION['captcha_math_answer'], $_SESSION['captcha_math_expires']);

        if ($expected === null || $expires < time()) {
            return false;
        }

        $answer = trim((string)$answer);
        return preg_match('/^-?\d{1,3}$/', $answer) === 1 && (int)$answer === (int)$expected;
    }
}
