<?php

if (!function_exists('email_clinic_name')) {
    function email_clinic_name(): string
    {
        return "Azucena's Dental Clinic";
    }
}

if (!function_exists('email_password_reset_otp_text')) {
    function email_password_reset_otp_text(string $otp, int $expiresMinutes = 15): string
    {
        $clinic = email_clinic_name();
        $otp = preg_replace('/\D/', '', $otp);

        return "Password Reset - {$clinic}\n\n"
            . "Use this one-time code to reset your password:\n\n"
            . "{$otp}\n\n"
            . "This code expires in {$expiresMinutes} minutes.\n"
            . "If you did not request a password reset, you can ignore this email.\n\n"
            . "{$clinic}";
    }
}

if (!function_exists('email_password_reset_otp_html')) {
    function email_password_reset_otp_html(string $otp, int $expiresMinutes = 15): string
    {
        $clinic = email_clinic_name();
        $otp = preg_replace('/\D/', '', $otp);
        $otpSafe = htmlspecialchars($otp, ENT_QUOTES, 'UTF-8');
        $clinicSafe = htmlspecialchars($clinic, ENT_QUOTES, 'UTF-8');
        $year = date('Y');

        return <<<HTML
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Password Reset OTP</title>
</head>
<body style="margin:0;padding:0;background-color:#f1f5f9;font-family:Segoe UI,Roboto,Helvetica,Arial,sans-serif;color:#334155;">
    <table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0" style="background-color:#f1f5f9;padding:32px 16px;">
        <tr>
            <td align="center">
                <table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0" style="max-width:520px;background-color:#ffffff;border:1px solid #e2e8f0;border-radius:16px;overflow:hidden;box-shadow:0 10px 30px rgba(15,23,42,0.08);">
                    <tr>
                        <td style="padding:28px 32px 12px;text-align:center;background:linear-gradient(180deg,#f0f9ff 0%,#ffffff 100%);">
                            <div style="font-size:13px;font-weight:700;letter-spacing:0.08em;text-transform:uppercase;color:#0ea5e9;">Password Recovery</div>
                            <h1 style="margin:10px 0 6px;font-size:22px;line-height:1.3;font-weight:700;color:#0f172a;">{$clinicSafe}</h1>
                            <p style="margin:0;font-size:14px;line-height:1.5;color:#64748b;">Use the code below to reset your password.</p>
                        </td>
                    </tr>
                    <tr>
                        <td style="padding:8px 32px 24px;text-align:center;">
                            <p style="margin:0 0 12px;font-size:13px;font-weight:600;color:#475569;">Your one-time code</p>
                            <div style="display:inline-block;padding:18px 28px;border:1px solid #bae6fd;border-radius:14px;background-color:#f0f9ff;">
                                <span style="font-size:40px;line-height:1;font-weight:800;letter-spacing:0.22em;color:#0369a1;font-family:Consolas,Monaco,Courier New,monospace;user-select:all;-webkit-user-select:all;">{$otpSafe}</span>
                            </div>
                            <p style="margin:16px 0 0;font-size:12px;line-height:1.5;color:#94a3b8;">Tap and hold the code to copy, then paste it on the reset page.</p>
                        </td>
                    </tr>
                    <tr>
                        <td style="padding:0 32px 28px;">
                            <table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0" style="background-color:#f8fafc;border:1px solid #e2e8f0;border-radius:12px;">
                                <tr>
                                    <td style="padding:16px 18px;font-size:13px;line-height:1.6;color:#475569;">
                                        <strong style="color:#0f172a;">Expires in {$expiresMinutes} minutes.</strong><br>
                                        If you did not request this reset, you can safely ignore this email. Your password will stay the same.
                                    </td>
                                </tr>
                            </table>
                        </td>
                    </tr>
                    <tr>
                        <td style="padding:18px 32px 24px;border-top:1px solid #f1f5f9;text-align:center;font-size:12px;line-height:1.5;color:#94a3b8;">
                            &copy; {$year} {$clinicSafe}
                        </td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>
</body>
</html>
HTML;
    }
}
