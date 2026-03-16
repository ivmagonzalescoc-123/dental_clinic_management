<?php
header('Content-Type: application/json');

include '../../../config/security.php';
security_start_session();

function auth_build_login_captcha_question(): string
{
    $left = random_int(2, 12);
    $right = random_int(1, 9);
    $useSubtraction = (bool)random_int(0, 1);

    if ($useSubtraction && $right > $left) {
        $tmp = $left;
        $left = $right;
        $right = $tmp;
    }

    $operator = $useSubtraction ? '-' : '+';
    $answer = $useSubtraction ? ($left - $right) : ($left + $right);

    $_SESSION['login_captcha_answer'] = (string)$answer;
    $_SESSION['login_captcha_expires_at'] = time() + 300;

    return $left . ' ' . $operator . ' ' . $right;
}

try {
    $question = auth_build_login_captcha_question();
    echo json_encode([
        'success' => true,
        'question' => $question,
        'expires_in' => 300,
    ]);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Unable to generate captcha right now.',
    ]);
}
