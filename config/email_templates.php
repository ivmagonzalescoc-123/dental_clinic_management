<?php
/**
 * HTML email templates. Every email shares one layout (email_layout()): a branded header with
 * a status pill, one dominant "key" box for the thing the patient needs (OTP, reference code,
 * password, amount), a short details list, an optional notice and button, and a footer with the
 * clinic's real contact details. Inline styles + tables only, so it renders the same in Gmail,
 * Outlook and phone mail apps.
 *
 * Contact details can be overridden in .env with CLINIC_PHONE, CLINIC_EMAIL, CLINIC_ADDRESS
 * and CLINIC_HOURS.
 */

if (!function_exists('email_clinic_name')) {
    function email_clinic_name(): string
    {
        return "Azucena's Dental Clinic";
    }
}

if (!function_exists('email_env')) {
    function email_env(string $key, string $default): string
    {
        $value = function_exists('env') ? trim((string)env($key, '')) : '';
        return $value !== '' ? $value : $default;
    }
}

if (!function_exists('email_clinic_phone')) {
    function email_clinic_phone(): string
    {
        return email_env('CLINIC_PHONE', '0917 984 3031');
    }
}

if (!function_exists('email_clinic_contact_email')) {
    function email_clinic_contact_email(): string
    {
        return email_env('CLINIC_EMAIL', 'azucenasdentalclinic@gmail.com');
    }
}

if (!function_exists('email_clinic_address')) {
    function email_clinic_address(): string
    {
        return email_env('CLINIC_ADDRESS', 'Metz Arcade, Cagayan de Oro City, Philippines');
    }
}

if (!function_exists('email_clinic_hours')) {
    function email_clinic_hours(): string
    {
        return email_env('CLINIC_HOURS', 'Mon–Sat, 9:00 AM – 5:00 PM');
    }
}

if (!function_exists('email_e')) {
    function email_e($value): string
    {
        return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
    }
}

if (!function_exists('email_app_url')) {
    function email_app_url(): string
    {
        return function_exists('app_base_url')
            ? app_base_url()
            : ((isset($_SERVER['HTTPS']) ? 'https' : 'http') . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost'));
    }
}

if (!function_exists('email_time_label')) {
    function email_time_label(string $preferredTime): string
    {
        if ($preferredTime === 'morning') return 'Morning (9:00 AM – 12:00 PM)';
        if ($preferredTime === 'afternoon') return 'Afternoon (1:00 PM – 4:30 PM)';
        return $preferredTime;
    }
}

if (!function_exists('email_date_label')) {
    function email_date_label(string $date): string
    {
        $ts = strtotime($date);
        return $ts ? date('l, F j, Y', $ts) : $date;
    }
}

if (!function_exists('email_tone')) {
    /** [accent, soft background, border, text on soft background] */
    function email_tone(string $tone): array
    {
        switch ($tone) {
            case 'success': return ['#15803d', '#f0fdf4', '#bbf7d0', '#166534'];
            case 'warning': return ['#b45309', '#fffbeb', '#fde68a', '#92400e'];
            case 'danger':  return ['#b91c1c', '#fef2f2', '#fecaca', '#991b1b'];
            default:        return ['#17698c', '#eef7fb', '#bfe0ec', '#0e4f6b'];
        }
    }
}

if (!function_exists('email_layout')) {
    /**
     * @param array $o title, preheader, tone, label, heading, intro (HTML), key [label, value,
     *                 caption, mono], details [[label, value HTML], ...], notice [tone, html],
     *                 cta [text, url], outro (HTML)
     */
    function email_layout(array $o): string
    {
        $clinic = email_e(email_clinic_name());
        [$accent, $soft, $border, $softText] = email_tone($o['tone'] ?? 'info');
        $font = "Segoe UI,Roboto,Helvetica,Arial,sans-serif";
        $mono = "Consolas,Menlo,'Courier New',monospace";
        $year = date('Y');

        $html = '<!DOCTYPE html><html lang="en"><head><meta charset="UTF-8">'
            . '<meta name="viewport" content="width=device-width, initial-scale=1.0">'
            . '<meta name="color-scheme" content="light only">'
            . '<title>' . email_e($o['title'] ?? $clinic) . '</title></head>'
            . '<body style="margin:0;padding:0;background-color:#f2f5f7;font-family:' . $font . ';color:#334155;">';

        // Inbox preview line (hidden in the email itself).
        if (!empty($o['preheader'])) {
            $html .= '<div style="display:none;max-height:0;overflow:hidden;opacity:0;font-size:1px;line-height:1px;color:#f2f5f7;">'
                . email_e($o['preheader']) . '</div>';
        }

        $html .= '<table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0" style="background-color:#f2f5f7;padding:28px 12px;"><tr><td align="center">'
            . '<table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0" style="max-width:560px;background-color:#ffffff;border:1px solid #e3e9ee;border-radius:14px;overflow:hidden;">';

        // Header: accent stripe, clinic name, status pill.
        $html .= '<tr><td style="height:5px;line-height:5px;font-size:0;background-color:' . $accent . ';">&nbsp;</td></tr>'
            . '<tr><td style="padding:20px 28px 0;"><table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0"><tr>'
            . '<td style="font-size:15px;font-weight:700;color:#17698c;letter-spacing:0.01em;">'
            . '<span style="display:inline-block;width:26px;height:26px;line-height:26px;border-radius:8px;background-color:#17698c;color:#ffffff;text-align:center;font-size:14px;font-weight:800;margin-right:8px;vertical-align:middle;">A</span>'
            . '<span style="vertical-align:middle;">' . $clinic . '</span></td>';
        if (!empty($o['label'])) {
            $html .= '<td align="right" style="white-space:nowrap;"><span style="display:inline-block;padding:4px 10px;border-radius:999px;background-color:' . $soft . ';border:1px solid ' . $border . ';color:' . $softText . ';font-size:11px;font-weight:700;letter-spacing:0.05em;text-transform:uppercase;">'
                . email_e($o['label']) . '</span></td>';
        }
        $html .= '</tr></table></td></tr>';

        // Heading + intro.
        $html .= '<tr><td style="padding:22px 28px 6px;">'
            . '<h1 style="margin:0 0 8px;font-size:22px;line-height:1.3;font-weight:700;color:#0f172a;">' . email_e($o['heading'] ?? '') . '</h1>'
            . (!empty($o['intro']) ? '<p style="margin:0;font-size:14px;line-height:1.65;color:#475569;">' . $o['intro'] . '</p>' : '')
            . '</td></tr>';

        // Key box: the one thing the patient needs.
        if (!empty($o['key'])) {
            $k = $o['key'];
            $isMono = $k['mono'] ?? true;
            $size = $k['size'] ?? ($isMono ? '34px' : '26px');
            $html .= '<tr><td style="padding:18px 28px 6px;">'
                . '<table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0" style="background-color:' . $soft . ';border:2px dashed ' . $border . ';border-radius:12px;"><tr><td align="center" style="padding:18px 16px;">'
                . '<div style="font-size:11px;font-weight:700;letter-spacing:0.08em;text-transform:uppercase;color:' . $softText . ';">' . email_e($k['label'] ?? '') . '</div>'
                . '<div style="margin-top:8px;font-size:' . $size . ';line-height:1.15;font-weight:800;letter-spacing:' . ($isMono ? '0.18em' : '0.02em') . ';color:' . $accent . ';font-family:' . ($isMono ? $mono : $font) . ';word-break:break-all;">' . email_e($k['value'] ?? '') . '</div>'
                . (!empty($k['caption']) ? '<div style="margin-top:8px;font-size:12px;line-height:1.5;color:#64748b;">' . $k['caption'] . '</div>' : '')
                . '</td></tr></table></td></tr>';
        }

        // Details list.
        if (!empty($o['details'])) {
            $rows = '';
            $last = count($o['details']) - 1;
            foreach (array_values($o['details']) as $i => $d) {
                $line = $i < $last ? 'border-bottom:1px solid #eef2f5;' : '';
                $rows .= '<tr>'
                    . '<td style="padding:10px 0;' . $line . 'font-size:13px;color:#64748b;width:38%;vertical-align:top;">' . email_e($d[0]) . '</td>'
                    . '<td style="padding:10px 0;' . $line . 'font-size:14px;color:#0f172a;font-weight:600;vertical-align:top;">' . $d[1] . '</td>'
                    . '</tr>';
            }
            $html .= '<tr><td style="padding:14px 28px 4px;"><table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0">' . $rows . '</table></td></tr>';
        }

        // Notice.
        if (!empty($o['notice'])) {
            [, $nSoft, $nBorder, $nText] = email_tone($o['notice']['tone'] ?? 'info');
            $html .= '<tr><td style="padding:14px 28px 4px;"><table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0" style="background-color:' . $nSoft . ';border-left:4px solid ' . $nBorder . ';border-radius:8px;"><tr>'
                . '<td style="padding:12px 16px;font-size:13px;line-height:1.6;color:' . $nText . ';">' . $o['notice']['html'] . '</td></tr></table></td></tr>';
        }

        // Button.
        if (!empty($o['cta'])) {
            $html .= '<tr><td align="center" style="padding:20px 28px 4px;"><table role="presentation" cellspacing="0" cellpadding="0" border="0"><tr>'
                . '<td style="border-radius:10px;background-color:#17698c;"><a href="' . email_e($o['cta']['url']) . '" style="display:inline-block;padding:12px 26px;font-size:14px;font-weight:700;color:#ffffff;text-decoration:none;border-radius:10px;">'
                . email_e($o['cta']['text']) . '</a></td></tr></table></td></tr>';
        }

        if (!empty($o['outro'])) {
            $html .= '<tr><td style="padding:16px 28px 0;font-size:13px;line-height:1.6;color:#64748b;">' . $o['outro'] . '</td></tr>';
        }

        // Footer: real contact details (tappable on phones).
        $phone = email_clinic_phone();
        $tel = preg_replace('/[^\d+]/', '', $phone);
        if (strpos($tel, '0') === 0) $tel = '+63' . substr($tel, 1);
        $mail = email_clinic_contact_email();
        $html .= '<tr><td style="padding:24px 28px 0;"><div style="border-top:1px solid #eef2f5;font-size:0;line-height:0;">&nbsp;</div></td></tr>'
            . '<tr><td style="padding:14px 28px 22px;font-size:12px;line-height:1.7;color:#64748b;">'
            . '<strong style="color:#0f172a;">' . $clinic . '</strong><br>'
            . email_e(email_clinic_address()) . '<br>'
            . '<a href="tel:' . email_e($tel) . '" style="color:#17698c;text-decoration:none;font-weight:600;">' . email_e($phone) . '</a>'
            . ' &nbsp;·&nbsp; <a href="mailto:' . email_e($mail) . '" style="color:#17698c;text-decoration:none;font-weight:600;">' . email_e($mail) . '</a><br>'
            . email_e(email_clinic_hours())
            . '<div style="margin-top:10px;font-size:11px;color:#94a3b8;">This is an automated message — please don\'t reply to this email. For questions, call or email the clinic above. &copy; ' . $year . ' ' . $clinic . '</div>'
            . '</td></tr>';

        $html .= '</table></td></tr></table></body></html>';
        return $html;
    }
}

if (!function_exists('email_password_reset_otp_html')) {
    function email_password_reset_otp_html(string $otp, int $expiresMinutes = 15): string
    {
        $otp = preg_replace('/\D/', '', $otp);
        return email_layout([
            'title' => 'Password reset code',
            'preheader' => "Your password reset code is {$otp}. It expires in {$expiresMinutes} minutes.",
            'tone' => 'info',
            'label' => 'Password reset',
            'heading' => 'Reset your password',
            'intro' => 'Enter this code on the reset page to choose a new password.',
            'key' => ['label' => 'Your one-time code', 'value' => $otp, 'caption' => 'Expires in <strong>' . (int)$expiresMinutes . ' minutes</strong>. Tap and hold to copy.'],
            'notice' => ['tone' => 'info', 'html' => "Didn't request this? You can ignore this email — your password stays the same."],
        ]);
    }
}

if (!function_exists('email_booking_otp_html')) {
    function email_booking_otp_html(string $otp, string $patientName, int $expiresMinutes = 10): string
    {
        $otp = preg_replace('/\D/', '', $otp);
        $name = email_e(trim($patientName) ?: 'there');
        return email_layout([
            'title' => 'Booking verification code',
            'preheader' => "Your booking verification code is {$otp}. It expires in {$expiresMinutes} minutes.",
            'tone' => 'success',
            'label' => 'Verification',
            'heading' => 'Verify your booking',
            'intro' => "Hi {$name}, enter this code on the booking page to send your request to the clinic.",
            'key' => ['label' => 'Verification code', 'value' => $otp, 'caption' => 'Expires in <strong>' . (int)$expiresMinutes . ' minutes</strong>. Tap and hold to copy.'],
            'notice' => ['tone' => 'info', 'html' => 'Already have a booking, or need a different date? Call the clinic at <strong>' . email_e(email_clinic_phone()) . '</strong> instead of booking again.'],
            'outro' => "Didn't try to book? You can safely ignore this email.",
        ]);
    }
}

if (!function_exists('email_booking_details')) {
    /** Shared details rows for booking emails. */
    function email_booking_details(string $preferredDate, string $preferredTime, string $reason = ''): array
    {
        $rows = [
            ['Date', email_e(email_date_label($preferredDate))],
            ['Time', email_e(email_time_label($preferredTime))],
        ];
        if (trim($reason) !== '') $rows[] = ['Reason', email_e(trim($reason))];
        return $rows;
    }
}

if (!function_exists('email_booking_confirmed_html')) {
    // Sent right after OTP verification — the booking only has status 'verified' at this
    // point, NOT secretary-approved yet (see email_booking_approved_html() for that stage).
    // Wording here must stay "received/pending review", not "confirmed", to avoid implying
    // the appointment is already approved.
    function email_booking_confirmed_html(string $patientName, int $bookingId, string $preferredDate, string $preferredTime, string $reason = '', string $trackingCode = ''): string
    {
        $ref = $trackingCode !== '' ? $trackingCode : (string)$bookingId;
        $name = email_e(trim($patientName) ?: 'there');
        return email_layout([
            'title' => 'Booking request received',
            'preheader' => "We received your booking request. Ref {$ref} — pending review.",
            'tone' => 'warning',
            'label' => 'Pending review',
            'heading' => "We've received your booking request",
            'intro' => "Hi {$name}, our staff will review it shortly. We'll email you again once it's approved.",
            'key' => ['label' => 'Booking reference', 'value' => $ref, 'caption' => 'Keep this code to track your booking.'],
            'details' => email_booking_details($preferredDate, $preferredTime, $reason),
            'cta' => ['text' => 'Track my booking', 'url' => email_app_url() . '#track-booking'],
        ]);
    }
}

if (!function_exists('email_booking_approved_html')) {
    function email_booking_approved_html(string $patientName, int $bookingId, string $preferredDate, string $preferredTime, string $reason = '', string $trackingCode = ''): string
    {
        $ref = $trackingCode !== '' ? $trackingCode : (string)$bookingId;
        $name = email_e(trim($patientName) ?: 'there');
        $date = email_date_label($preferredDate);
        return email_layout([
            'title' => 'Appointment approved',
            'preheader' => "Your appointment on {$date} is approved. Ref {$ref}.",
            'tone' => 'success',
            'label' => 'Approved',
            'heading' => 'Your appointment is approved',
            'intro' => "Hi {$name}, great news — the clinic has approved your booking. See you soon!",
            'key' => ['label' => 'Booking reference', 'value' => $ref, 'caption' => 'Show this code when you arrive at the clinic.'],
            'details' => email_booking_details($preferredDate, $preferredTime, $reason),
            'notice' => ['tone' => 'info', 'html' => 'Need to reschedule or cancel? Call the clinic at <strong>' . email_e(email_clinic_phone()) . '</strong> and our staff will update it for you.'],
            'cta' => ['text' => 'Track my booking', 'url' => email_app_url() . '#track-booking'],
        ]);
    }
}

if (!function_exists('email_booking_declined_html')) {
    function email_booking_declined_html(string $patientName, int $bookingId, string $preferredDate, string $preferredTime, string $reason = '', string $trackingCode = ''): string
    {
        $ref = $trackingCode !== '' ? $trackingCode : (string)$bookingId;
        $name = email_e(trim($patientName) ?: 'there');
        $reason = trim($reason);
        return email_layout([
            'title' => 'Booking request declined',
            'preheader' => "Your booking request {$ref} could not be accommodated.",
            'tone' => 'danger',
            'label' => 'Declined',
            'heading' => "We couldn't accommodate your booking",
            'intro' => "Hi {$name}, we're sorry — the clinic couldn't fit your requested schedule.",
            'key' => ['label' => 'Booking reference', 'value' => $ref, 'size' => '24px'],
            'details' => email_booking_details($preferredDate, $preferredTime),
            'notice' => $reason !== '' ? ['tone' => 'danger', 'html' => '<strong>Reason:</strong> ' . email_e($reason)] : null,
            'cta' => ['text' => 'Book another date', 'url' => email_app_url()],
            'outro' => 'You can also call the clinic and we\'ll help you find a schedule that works.',
        ]);
    }
}

if (!function_exists('email_booking_rescheduled_html')) {
    function email_booking_rescheduled_html(string $patientName, int $bookingId, string $preferredDate, string $preferredTime, string $trackingCode = ''): string
    {
        $ref = $trackingCode !== '' ? $trackingCode : (string)$bookingId;
        $name = email_e(trim($patientName) ?: 'there');
        $date = email_date_label($preferredDate);
        return email_layout([
            'title' => 'Booking rescheduled',
            'preheader' => "Your booking {$ref} was moved to {$date}.",
            'tone' => 'warning',
            'label' => 'Rescheduled',
            'heading' => 'Your booking has a new schedule',
            'intro' => "Hi {$name}, the clinic staff moved your booking to the date below.",
            'key' => ['label' => 'Booking reference', 'value' => $ref, 'size' => '26px'],
            'details' => email_booking_details($preferredDate, $preferredTime),
            'notice' => ['tone' => 'warning', 'html' => "Doesn't work for you? Call the clinic at <strong>" . email_e(email_clinic_phone()) . '</strong> to arrange another time.'],
            'cta' => ['text' => 'Track my booking', 'url' => email_app_url() . '#track-booking'],
        ]);
    }
}

if (!function_exists('email_patient_account_created_html')) {
    function email_patient_account_created_html(string $patientName, string $loginEmail, string $temporaryPassword): string
    {
        $name = email_e(trim($patientName) ?: 'there');
        return email_layout([
            'title' => 'Your patient account',
            'preheader' => 'Your patient portal account is ready. Sign in with the temporary password inside.',
            'tone' => 'info',
            'label' => 'New account',
            'heading' => 'Your patient account is ready',
            'intro' => "Hi {$name}, the clinic created a patient portal account for you. Use it to view your appointments, records and billing.",
            'key' => ['label' => 'Temporary password', 'value' => (string)$temporaryPassword, 'size' => '26px', 'caption' => 'Tap and hold to copy. You\'ll be asked to change it after signing in.'],
            'details' => [['Login email', email_e(trim($loginEmail))]],
            'cta' => ['text' => 'Sign in to the portal', 'url' => email_app_url()],
            'notice' => ['tone' => 'warning', 'html' => "Keep this password private. Didn't expect this email? Please contact the clinic."],
        ]);
    }
}

if (!function_exists('email_appointment_cancelled_html')) {
    function email_appointment_cancelled_html(string $patientName, string $appointmentDate, string $reason = ''): string
    {
        $name = email_e(trim($patientName) ?: 'there');
        $date = email_date_label($appointmentDate);
        $reason = trim($reason);
        return email_layout([
            'title' => 'Appointment cancelled',
            'preheader' => "Your appointment on {$date} has been cancelled.",
            'tone' => 'danger',
            'label' => 'Cancelled',
            'heading' => 'Your appointment was cancelled',
            'intro' => "Hi {$name}, your appointment below is no longer scheduled.",
            'details' => [['Date', email_e($date)]],
            'notice' => $reason !== '' ? ['tone' => 'danger', 'html' => '<strong>Reason:</strong> ' . email_e($reason)] : null,
            'cta' => ['text' => 'Book a new appointment', 'url' => email_app_url()],
        ]);
    }
}

if (!function_exists('email_appointment_leave_conflict_html')) {
    function email_appointment_leave_conflict_html(string $patientName, string $appointmentDate, string $doctorName): string
    {
        $name = email_e(trim($patientName) ?: 'there');
        $date = email_date_label($appointmentDate);
        return email_layout([
            'title' => 'Appointment needs rescheduling',
            'preheader' => "Your dentist is unavailable on {$date}. We'll help you reschedule.",
            'tone' => 'warning',
            'label' => 'Action needed',
            'heading' => 'Your appointment needs a new schedule',
            'intro' => "Hi {$name}, Dr. " . email_e(trim($doctorName)) . " is unexpectedly unavailable on your appointment date. Our staff will contact you to reschedule or arrange another doctor.",
            'details' => [['Original date', email_e($date)], ['Doctor', 'Dr. ' . email_e(trim($doctorName))]],
            'cta' => ['text' => 'Request a new date', 'url' => email_app_url()],
            'outro' => 'You can also call the clinic to reschedule right away.',
        ]);
    }
}

if (!function_exists('email_appointment_reminder_html')) {
    function email_appointment_reminder_html(string $patientName, string $scheduledStart, string $doctorName, string $appointmentId, string $type = '24h'): string
    {
        $name = email_e(trim($patientName) ?: 'there');
        $doctor = $doctorName !== '' ? ('Dr. ' . $doctorName) : 'Your dentist';

        if ($type === '24h') {
            $o = ['tone' => 'info', 'label' => 'Reminder', 'heading' => 'Your appointment is tomorrow',
                'intro' => "Hi {$name}, this is a friendly reminder of your dental appointment.",
                'notice' => ['tone' => 'info', 'html' => 'Please arrive 10–15 minutes early.']];
        } elseif ($type === '1h') {
            $o = ['tone' => 'warning', 'label' => 'Starting soon', 'heading' => 'Your appointment starts in 1 hour',
                'intro' => "Hi {$name}, your appointment with " . email_e($doctor) . ' is coming up.',
                'notice' => ['tone' => 'warning', 'html' => 'Running late or can\'t make it? Please call the clinic as soon as possible.']];
        } elseif ($type === 'missed') {
            $o = ['tone' => 'danger', 'label' => 'Missed', 'heading' => 'We missed you today',
                'intro' => "Hi {$name}, our records show your appointment may have been missed.",
                'notice' => ['tone' => 'danger', 'html' => 'Please contact the clinic to reschedule at your earliest convenience.']];
        } else {
            $o = ['tone' => 'danger', 'label' => 'No-show', 'heading' => 'Appointment marked as no-show',
                'intro' => "Hi {$name}, your appointment was marked as a no-show.",
                'notice' => ['tone' => 'danger', 'html' => 'Please contact the clinic if you would like to reschedule.']];
        }

        return email_layout($o + [
            'title' => $o['heading'],
            'preheader' => $o['heading'] . ' — ' . $scheduledStart . '.',
            'key' => ['label' => 'Date & time', 'value' => $scheduledStart, 'mono' => false, 'size' => '22px'],
            'details' => [['Doctor', email_e($doctor)], ['Reference', email_e($appointmentId)]],
        ]);
    }
}

if (!function_exists('email_installment_reminder_html')) {
    function email_installment_reminder_html(string $patientName, string $billingId, string $termNo, string $dueDate, string $amount, string $type = 'due_soon'): string
    {
        $name = email_e(trim($patientName) ?: 'there');
        $dueSoon = $type === 'due_soon';
        return email_layout([
            'title' => $dueSoon ? 'Payment reminder' : 'Payment overdue',
            'preheader' => ($dueSoon ? 'Installment due ' : 'Installment overdue since ') . $dueDate . ' — ₱' . $amount . '.',
            'tone' => $dueSoon ? 'warning' : 'danger',
            'label' => $dueSoon ? 'Payment reminder' : 'Overdue',
            'heading' => $dueSoon ? 'Your installment is due tomorrow' : 'Your installment is overdue',
            'intro' => "Hi {$name}, here are the details of your next installment payment.",
            'key' => ['label' => 'Amount due', 'value' => '₱' . $amount, 'mono' => false, 'size' => '32px'],
            'details' => [['Due date', email_e($dueDate)], ['Term', '#' . email_e($termNo)], ['Billing', '#' . email_e($billingId)]],
            'notice' => ['tone' => $dueSoon ? 'info' : 'danger', 'html' => $dueSoon ? 'Please contact the clinic if you need assistance.' : 'Please contact the clinic to settle your balance.'],
        ]);
    }
}
