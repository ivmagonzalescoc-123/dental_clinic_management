<?php

if (!function_exists('email_clinic_name')) {
    function email_clinic_name(): string
    {
        return "Azucena's Dental Clinic";
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
<head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0"><title>Password Reset OTP</title></head>
<body style="margin:0;padding:0;background-color:#f1f5f9;font-family:Segoe UI,Roboto,Helvetica,Arial,sans-serif;color:#334155;">
<table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0" style="background-color:#f1f5f9;padding:32px 16px;"><tr><td align="center"><table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0" style="max-width:520px;background-color:#ffffff;border:1px solid #e2e8f0;border-radius:16px;overflow:hidden;box-shadow:0 10px 30px rgba(15,23,42,0.08);">
<tr><td style="padding:28px 32px 12px;text-align:center;background:linear-gradient(180deg,#f0f9ff 0%,#ffffff 100%);"><div style="font-size:13px;font-weight:700;letter-spacing:0.08em;text-transform:uppercase;color:#0ea5e9;">Password Recovery</div><h1 style="margin:10px 0 6px;font-size:22px;line-height:1.3;font-weight:700;color:#0f172a;">{$clinicSafe}</h1><p style="margin:0;font-size:14px;line-height:1.5;color:#64748b;">Use the code below to reset your password.</p></td></tr>
<tr><td style="padding:8px 32px 24px;text-align:center;"><p style="margin:0 0 12px;font-size:13px;font-weight:600;color:#475569;">Your one-time code</p><div style="display:inline-block;padding:18px 28px;border:1px solid #bae6fd;border-radius:14px;background-color:#f0f9ff;"><span style="font-size:40px;line-height:1;font-weight:800;letter-spacing:0.22em;color:#0369a1;font-family:Consolas,Monaco,Courier New,monospace;user-select:all;-webkit-user-select:all;">{$otpSafe}</span></div><p style="margin:16px 0 0;font-size:12px;line-height:1.5;color:#94a3b8;">Tap and hold the code to copy, then paste it on the reset page.</p></td></tr>
<tr><td style="padding:0 32px 28px;"><table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0" style="background-color:#f8fafc;border:1px solid #e2e8f0;border-radius:12px;"><tr><td style="padding:16px 18px;font-size:13px;line-height:1.6;color:#475569;"><strong style="color:#0f172a;">Expires in {$expiresMinutes} minutes.</strong><br>If you did not request this reset, you can safely ignore this email. Your password will stay the same.</td></tr></table></td></tr>
<tr><td style="padding:18px 32px 24px;border-top:1px solid #f1f5f9;text-align:center;font-size:12px;line-height:1.5;color:#94a3b8;">&copy; {$year} {$clinicSafe}</td></tr>
</table></td></tr></table></body></html>
HTML;
    }
}

if (!function_exists('email_booking_otp_html')) {
    function email_booking_otp_html(string $otp, string $patientName, int $expiresMinutes = 10): string
    {
        $clinic = email_clinic_name();
        $otp = preg_replace('/\D/', '', $otp);
        $otpSafe = htmlspecialchars($otp, ENT_QUOTES, 'UTF-8');
        $clinicSafe = htmlspecialchars($clinic, ENT_QUOTES, 'UTF-8');
        $patientSafe = htmlspecialchars(trim($patientName), ENT_QUOTES, 'UTF-8');
        $year = date('Y');

        return <<<HTML
<!DOCTYPE html>
<html lang="en">
<head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0"><title>Booking Verification</title></head>
<body style="margin:0;padding:0;background-color:#f1f5f9;font-family:Segoe UI,Roboto,Helvetica,Arial,sans-serif;color:#334155;">
<table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0" style="background-color:#f1f5f9;padding:32px 16px;"><tr><td align="center"><table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0" style="max-width:520px;background-color:#ffffff;border:1px solid #e2e8f0;border-radius:16px;overflow:hidden;box-shadow:0 10px 30px rgba(15,23,42,0.08);">
<tr><td style="padding:28px 32px 12px;text-align:center;background:linear-gradient(180deg,#f0fdf4 0%,#ffffff 100%);"><div style="font-size:13px;font-weight:700;letter-spacing:0.08em;text-transform:uppercase;color:#16a34a;">Booking Verification</div><h1 style="margin:10px 0 6px;font-size:22px;line-height:1.3;font-weight:700;color:#0f172a;">{$clinicSafe}</h1><p style="margin:0;font-size:14px;line-height:1.5;color:#64748b;">Hello {$patientSafe}, verify your booking request.</p></td></tr>
<tr><td style="padding:8px 32px 24px;text-align:center;"><p style="margin:0 0 12px;font-size:13px;font-weight:600;color:#475569;">Your one-time verification code</p><div style="display:inline-block;padding:18px 28px;border:1px solid #bbf7d0;border-radius:14px;background-color:#f0fdf4;"><span style="font-size:40px;line-height:1;font-weight:800;letter-spacing:0.22em;color:#15803d;font-family:Consolas,Monaco,Courier New,monospace;user-select:all;-webkit-user-select:all;">{$otpSafe}</span></div><p style="margin:16px 0 0;font-size:12px;line-height:1.5;color:#94a3b8;">Tap and hold the code to copy, then paste it on the booking page.</p></td></tr>
<tr><td style="padding:0 32px 28px;"><table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0" style="background-color:#f8fafc;border:1px solid #e2e8f0;border-radius:12px;"><tr><td style="padding:16px 18px;font-size:13px;line-height:1.6;color:#475569;"><strong style="color:#0f172a;">Expires in {$expiresMinutes} minutes.</strong><br>If you did not request this booking, you can safely ignore this email.</td></tr></table></td></tr>
<tr><td style="padding:18px 32px 24px;border-top:1px solid #f1f5f9;text-align:center;font-size:12px;line-height:1.5;color:#94a3b8;">&copy; {$year} {$clinicSafe}</td></tr>
</table></td></tr></table></body></html>
HTML;
    }
}

if (!function_exists('email_booking_confirmed_html')) {
    function email_booking_confirmed_html(string $patientName, int $bookingId, string $preferredDate, string $preferredTime, string $reason = ''): string
    {
        $clinic = email_clinic_name();
        $patientNameSafe = htmlspecialchars(trim($patientName), ENT_QUOTES, 'UTF-8');
        $clinicSafe = htmlspecialchars($clinic, ENT_QUOTES, 'UTF-8');
        $timeLabel = $preferredTime === 'morning' ? 'Morning (8:00 AM - 12:00 PM)' : 'Afternoon (1:00 PM - 4:30 PM)';
        $dateFormatted = date('l, F j, Y', strtotime($preferredDate));
        $year = date('Y');
        $appUrl = function_exists('app_base_url') ? app_base_url() : (isset($_SERVER['HTTPS']) ? 'https' : 'http') . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost');

        return <<<HTML
<!DOCTYPE html>
<html lang="en">
<head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0"><title>Booking Confirmed</title></head>
<body style="margin:0;padding:0;background-color:#f1f5f9;font-family:Segoe UI,Roboto,Helvetica,Arial,sans-serif;color:#334155;">
<table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0" style="background-color:#f1f5f9;padding:32px 16px;"><tr><td align="center"><table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0" style="max-width:520px;background-color:#ffffff;border:1px solid #e2e8f0;border-radius:16px;overflow:hidden;box-shadow:0 10px 30px rgba(15,23,42,0.08);">
<tr><td style="padding:28px 32px 12px;text-align:center;background:linear-gradient(180deg,#f0fdf4 0%,#ffffff 100%);"><div style="display:inline-block;padding:4px 14px;border-radius:20px;background-color:#16a34a;color:#ffffff;font-size:11px;font-weight:700;letter-spacing:0.06em;text-transform:uppercase;">Booking Confirmed</div><h1 style="margin:14px 0 6px;font-size:22px;line-height:1.3;font-weight:700;color:#0f172a;">{$clinicSafe}</h1><p style="margin:0;font-size:14px;line-height:1.5;color:#64748b;">Hello {$patientNameSafe}</p></td></tr>
<tr><td style="padding:8px 32px 24px;text-align:center;"><h2 style="margin:0 0 12px;font-size:18px;font-weight:700;color:#0f172a;">Your booking is confirmed!</h2><p style="margin:0;font-size:14px;line-height:1.6;color:#475569;">Visit the clinic on <strong>{$dateFormatted}</strong>, <strong>{$timeLabel}</strong>.</p><p style="margin:12px 0 0;font-size:16px;line-height:1.5;color:#0f172a;">Your Booking Ref# is <strong style="font-size:20px;color:#16a34a;">{$bookingId}</strong></p><p style="margin:16px 0 0;font-size:13px;line-height:1.6;color:#64748b;">You can track your booking status anytime on our <a href="{$appUrl}#track-booking" style="color:#1d6fd8;font-weight:600;text-decoration:none;">Track Booking</a> page using your Booking Ref#.</p></td></tr>
<tr><td style="padding:0 32px 24px;"><table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0" style="background-color:#f0f9ff;border:1px solid #bae6fd;border-radius:12px;"><tr><td style="padding:14px 18px;font-size:13px;line-height:1.6;color:#0369a1;"><strong>Clinic Address:</strong> Metz Arcade, Cagayan de Oro City, Philippines<br><strong>Clinic Hours:</strong> Mon-Sat, 8:00 AM - 5:00 PM</td></tr></table></td></tr>
<tr><td style="padding:18px 32px 24px;border-top:1px solid #f1f5f9;text-align:center;font-size:12px;line-height:1.5;color:#94a3b8;">&copy; {$year} {$clinicSafe}</td></tr>
</table></td></tr></table></body></html>
HTML;
    }
}

if (!function_exists('email_patient_account_created_html')) {
    function email_patient_account_created_html(string $patientName, string $loginEmail, string $temporaryPassword): string
    {
        $clinic = email_clinic_name();
        $patientNameSafe = htmlspecialchars(trim($patientName), ENT_QUOTES, 'UTF-8');
        $loginEmailSafe = htmlspecialchars(trim($loginEmail), ENT_QUOTES, 'UTF-8');
        $passwordSafe = htmlspecialchars((string)$temporaryPassword, ENT_QUOTES, 'UTF-8');
        $clinicSafe = htmlspecialchars($clinic, ENT_QUOTES, 'UTF-8');
        $year = date('Y');

        return <<<HTML
<!DOCTYPE html>
<html lang="en">
<head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0"><title>Patient Account Created</title></head>
<body style="margin:0;padding:0;background-color:#f1f5f9;font-family:Segoe UI,Roboto,Helvetica,Arial,sans-serif;color:#334155;">
<table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0" style="background-color:#f1f5f9;padding:32px 16px;"><tr><td align="center"><table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0" style="max-width:520px;background-color:#ffffff;border:1px solid #e2e8f0;border-radius:16px;overflow:hidden;box-shadow:0 10px 30px rgba(15,23,42,0.08);">
<tr><td style="padding:28px 32px 12px;text-align:center;background:linear-gradient(180deg,#f0f9ff 0%,#ffffff 100%);"><div style="font-size:13px;font-weight:700;letter-spacing:0.08em;text-transform:uppercase;color:#0ea5e9;">Patient Portal</div><h1 style="margin:10px 0 6px;font-size:22px;line-height:1.3;font-weight:700;color:#0f172a;">{$clinicSafe}</h1><p style="margin:0;font-size:14px;line-height:1.5;color:#64748b;">Hello {$patientNameSafe}, your account has been created.</p></td></tr>
<tr><td style="padding:8px 32px 24px;text-align:center;"><p style="margin:0 0 12px;font-size:13px;font-weight:600;color:#475569;">Your temporary password</p><div style="display:inline-block;padding:18px 28px;border:1px solid #bae6fd;border-radius:14px;background-color:#f0f9ff;"><span style="font-size:32px;line-height:1.2;font-weight:800;letter-spacing:0.08em;color:#0369a1;font-family:Consolas,Monaco,Courier New,monospace;user-select:all;-webkit-user-select:all;">{$passwordSafe}</span></div><p style="margin:16px 0 0;font-size:12px;line-height:1.5;color:#94a3b8;">Tap and hold the password to copy, then paste it on the login page.</p></td></tr>
<tr><td style="padding:0 32px 28px;"><table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0" style="background-color:#f8fafc;border:1px solid #e2e8f0;border-radius:12px;"><tr><td style="padding:16px 18px;font-size:13px;line-height:1.6;color:#475569;"><strong style="color:#0f172a;">Login email:</strong> {$loginEmailSafe}<br><br>Please sign in and change your password after your first visit. If you did not expect this email, contact the clinic.</td></tr></table></td></tr>
<tr><td style="padding:18px 32px 24px;border-top:1px solid #f1f5f9;text-align:center;font-size:12px;line-height:1.5;color:#94a3b8;">&copy; {$year} {$clinicSafe}</td></tr>
</table></td></tr></table></body></html>
HTML;
    }
}

if (!function_exists('email_appointment_reminder_html')) {
    function email_appointment_reminder_html(string $patientName, string $scheduledStart, string $doctorName, string $appointmentId, string $type = '24h'): string
    {
        $clinic = email_clinic_name();
        $clinicSafe = htmlspecialchars($clinic, ENT_QUOTES, 'UTF-8');
        $patientSafe = htmlspecialchars(trim($patientName) ?: 'Patient', ENT_QUOTES, 'UTF-8');
        $doctorSafe = htmlspecialchars($doctorName !== '' ? ('Dr. ' . $doctorName) : 'Your dentist', ENT_QUOTES, 'UTF-8');
        $dateSafe = htmlspecialchars($scheduledStart, ENT_QUOTES, 'UTF-8');
        $idSafe = htmlspecialchars($appointmentId, ENT_QUOTES, 'UTF-8');
        $year = date('Y');

        if ($type === '24h') {
            $badge = 'Appointment Reminder'; $badgeColor = '#0ea5e9';
            $heading = 'Your appointment is tomorrow';
            $subtext = "This is a friendly reminder that your dental appointment is scheduled for <strong>{$dateSafe}</strong>.";
            $note = 'Please arrive 10-15 minutes early to complete any necessary paperwork.';
        } elseif ($type === '1h') {
            $badge = 'Starting Soon'; $badgeColor = '#f59e0b';
            $heading = 'Your appointment starts in 1 hour';
            $subtext = "Your appointment with <strong>{$doctorSafe}</strong> begins at <strong>{$dateSafe}</strong>.";
            $note = 'If you need to reschedule, please contact the clinic as soon as possible.';
        } elseif ($type === 'missed') {
            $badge = 'Missed Appointment'; $badgeColor = '#ef4444';
            $heading = 'We missed you today';
            $subtext = "Our records show that your appointment at <strong>{$dateSafe}</strong> may have been missed.";
            $note = 'Please contact the clinic to reschedule at your earliest convenience.';
        } else {
            $badge = 'Appointment Update'; $badgeColor = '#8b5cf6';
            $heading = 'Appointment status update';
            $subtext = "Your appointment at <strong>{$dateSafe}</strong> has been marked as no-show.";
            $note = 'Please contact the clinic if you would like to reschedule.';
        }

        return <<<HTML
<!DOCTYPE html>
<html lang="en">
<head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0"><title>{$badge}</title></head>
<body style="margin:0;padding:0;background-color:#f1f5f9;font-family:Segoe UI,Roboto,Helvetica,Arial,sans-serif;color:#334155;">
<table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0" style="background-color:#f1f5f9;padding:32px 16px;"><tr><td align="center"><table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0" style="max-width:520px;background-color:#ffffff;border:1px solid #e2e8f0;border-radius:16px;overflow:hidden;box-shadow:0 10px 30px rgba(15,23,42,0.08);">
<tr><td style="padding:28px 32px 12px;text-align:center;background:linear-gradient(180deg,#f0f9ff 0%,#ffffff 100%);"><div style="display:inline-block;padding:4px 14px;border-radius:20px;background-color:{$badgeColor};color:#ffffff;font-size:11px;font-weight:700;letter-spacing:0.06em;text-transform:uppercase;">{$badge}</div><h1 style="margin:14px 0 6px;font-size:22px;line-height:1.3;font-weight:700;color:#0f172a;">{$clinicSafe}</h1><p style="margin:0;font-size:14px;line-height:1.5;color:#64748b;">Hello {$patientSafe}</p></td></tr>
<tr><td style="padding:8px 32px 24px;text-align:center;"><h2 style="margin:0 0 12px;font-size:18px;font-weight:700;color:#0f172a;">{$heading}</h2><p style="margin:0;font-size:14px;line-height:1.6;color:#475569;">{$subtext}</p></td></tr>
<tr><td style="padding:0 32px 20px;"><table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0" style="background-color:#f8fafc;border:1px solid #e2e8f0;border-radius:12px;"><tr><td style="padding:18px;"><table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0">
<tr><td style="padding:4px 0;font-size:13px;color:#64748b;width:100px;vertical-align:top;"><strong style="color:#0f172a;">Doctor</strong></td><td style="padding:4px 0;font-size:13px;color:#334155;">{$doctorSafe}</td></tr>
<tr><td style="padding:4px 0;font-size:13px;color:#64748b;vertical-align:top;"><strong style="color:#0f172a;">Date &amp; Time</strong></td><td style="padding:4px 0;font-size:13px;color:#334155;">{$dateSafe}</td></tr>
<tr><td style="padding:4px 0;font-size:13px;color:#64748b;vertical-align:top;"><strong style="color:#0f172a;">Reference</strong></td><td style="padding:4px 0;font-size:13px;color:#334155;">#{$idSafe}</td></tr>
</table></td></tr></table></td></tr>
<tr><td style="padding:0 32px 24px;"><table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0" style="background-color:#fffbeb;border:1px solid #fde68a;border-radius:12px;"><tr><td style="padding:14px 18px;font-size:13px;line-height:1.6;color:#92400e;">{$note}</td></tr></table></td></tr>
<tr><td style="padding:18px 32px 24px;border-top:1px solid #f1f5f9;text-align:center;font-size:12px;line-height:1.5;color:#94a3b8;">&copy; {$year} {$clinicSafe}</td></tr>
</table></td></tr></table></body></html>
HTML;
    }
}

if (!function_exists('email_installment_reminder_html')) {
    function email_installment_reminder_html(string $patientName, string $billingId, string $termNo, string $dueDate, string $amount, string $type = 'due_soon'): string
    {
        $clinic = email_clinic_name();
        $clinicSafe = htmlspecialchars($clinic, ENT_QUOTES, 'UTF-8');
        $patientSafe = htmlspecialchars(trim($patientName) ?: 'Patient', ENT_QUOTES, 'UTF-8');
        $billingSafe = htmlspecialchars($billingId, ENT_QUOTES, 'UTF-8');
        $termSafe = htmlspecialchars($termNo, ENT_QUOTES, 'UTF-8');
        $dueDateSafe = htmlspecialchars($dueDate, ENT_QUOTES, 'UTF-8');
        $amountSafe = htmlspecialchars($amount, ENT_QUOTES, 'UTF-8');
        $year = date('Y');

        if ($type === 'due_soon') {
            $badge = 'Payment Reminder'; $badgeColor = '#f59e0b';
            $heading = 'Installment payment due tomorrow';
            $note = 'Please contact the clinic if you need assistance.';
        } else {
            $badge = 'Payment Overdue'; $badgeColor = '#ef4444';
            $heading = 'Installment payment is overdue';
            $note = 'Please contact the clinic to settle your balance.';
        }

        return <<<HTML
<!DOCTYPE html>
<html lang="en">
<head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0"><title>{$badge}</title></head>
<body style="margin:0;padding:0;background-color:#f1f5f9;font-family:Segoe UI,Roboto,Helvetica,Arial,sans-serif;color:#334155;">
<table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0" style="background-color:#f1f5f9;padding:32px 16px;"><tr><td align="center"><table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0" style="max-width:520px;background-color:#ffffff;border:1px solid #e2e8f0;border-radius:16px;overflow:hidden;box-shadow:0 10px 30px rgba(15,23,42,0.08);">
<tr><td style="padding:28px 32px 12px;text-align:center;background:linear-gradient(180deg,#fffbeb 0%,#ffffff 100%);"><div style="display:inline-block;padding:4px 14px;border-radius:20px;background-color:{$badgeColor};color:#ffffff;font-size:11px;font-weight:700;letter-spacing:0.06em;text-transform:uppercase;">{$badge}</div><h1 style="margin:14px 0 6px;font-size:22px;line-height:1.3;font-weight:700;color:#0f172a;">{$clinicSafe}</h1><p style="margin:0;font-size:14px;line-height:1.5;color:#64748b;">Hello {$patientSafe}</p></td></tr>
<tr><td style="padding:8px 32px 24px;text-align:center;"><h2 style="margin:0 0 12px;font-size:18px;font-weight:700;color:#0f172a;">{$heading}</h2><p style="margin:0;font-size:14px;line-height:1.6;color:#475569;">Installment term #{$termSafe} for billing #{$billingSafe} is due on <strong>{$dueDateSafe}</strong>.</p><p style="margin:12px 0 0;font-size:18px;font-weight:700;color:#0f172a;">Amount due: <strong style="color:#16a34a;">&#8369;{$amountSafe}</strong></p></td></tr>
<tr><td style="padding:0 32px 24px;"><table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0" style="background-color:#f8fafc;border:1px solid #e2e8f0;border-radius:12px;"><tr><td style="padding:16px 18px;font-size:13px;line-height:1.6;color:#475569;text-align:center;">{$note}</td></tr></table></td></tr>
<tr><td style="padding:18px 32px 24px;border-top:1px solid #f1f5f9;text-align:center;font-size:12px;line-height:1.5;color:#94a3b8;">&copy; {$year} {$clinicSafe}</td></tr>
</table></td></tr></table></body></html>
HTML;
    }
}
