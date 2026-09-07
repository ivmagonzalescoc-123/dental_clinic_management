<?php
$files = [
    'assets/api/arrivals/start_session.php',
    'assets/api/arrivals/mark_no_show.php',
    'assets/api/arrivals/mark_arrived.php',
    'assets/api/appointments/cancel_schedule.php',
    'assets/api/appointments/confirm_appointment.php',
    'assets/api/appointments/create_appointment.php',
    'assets/api/appointments/create_secretary_record.php',
    'assets/api/appointments/finish_appointment.php',
    'assets/api/appointments/manage_services.php',
    'assets/api/appointments/request_reschedule.php',
    'assets/api/appointments/update_appointment.php',
    'assets/api/appointments/reschedule_schedule.php',
    'assets/api/patients/add_patient.php',
    'assets/api/patients/update_patient.php',
    'assets/api/patients/set_patient_password.php',
    'assets/api/patients/create_walkin.php',
    'assets/api/patients/reset_patient_password.php',
    'assets/api/contact/submit_contact_message.php',
    'assets/api/contact/manage_contact_messages.php',
    'assets/api/billing/record_payment.php',
    'assets/api/bookings/decline_booking.php',
    'assets/api/auth/upload_profile_image_api.php',
    'assets/api/auth/update_profile_api.php',
    'assets/api/bookings/reschedule_booking.php',
    'assets/api/bookings/create_booking.php',
    'assets/api/auth/reset_password_api.php',
    'assets/api/auth/change_password_api.php',
    'assets/api/auth/forgot_password_api.php',
    'assets/api/users/add_user.php',
    'assets/api/users/update_user.php',
];

$basePath = 'C:/xampp/htdocs/capstone_dental/';

foreach ($files as $file) {
    $fullPath = $basePath . $file;
    if (!file_exists($fullPath)) {
        echo "Not found: $file\n";
        continue;
    }
    
    $content = file_get_contents($fullPath);
    $original = $content;
    
    // Replace various CSRF/security token messages with generic message
    $patterns = [
        '/Invalid CSRF token\. Please refresh and try again\./' => 'Please refresh the page and try again.',
        '/Invalid CSRF token/' => 'Please refresh the page and try again.',
        '/Invalid security token\. Please refresh and try again\./' => 'Please refresh the page and try again.',
        '/Invalid security token/' => 'Please refresh the page and try again.',
        '/Security token mismatch\. Please refresh and try again\./' => 'Please refresh the page and try again.',
        '/Security token mismatch/' => 'Please refresh the page and try again.',
    ];
    
    foreach ($patterns as $search => $replace) {
        $content = preg_replace($search, $replace, $content);
    }
    
    if ($content !== $original) {
        file_put_contents($fullPath, $content);
        echo "Updated: $file\n";
    } else {
        echo "No change: $file\n";
    }
}

echo "Done!\n";