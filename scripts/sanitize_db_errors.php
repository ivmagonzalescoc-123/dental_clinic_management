<?php
$files = [
    'assets/api/users/update_user.php',
    'assets/api/users/add_user.php',
    'doctor/doctor_modules/leave_requests.php',
    'assets/api/patients/update_patient.php',
    'assets/api/patients/set_patient_password.php',
    'assets/api/patients/add_patient.php',
    'assets/api/patients/save_profiling.php',
    'assets/api/patients/reset_patient_password.php',
    'patient/patient_modules/billing_view.php',
    'assets/api/doctors/get_prescription_profile.php',
    'assets/api/admin/download_backup.php',
    'assets/api/appointments/update_appointment.php',
    'assets/api/bookings/verify_booking_otp.php',
    'assets/api/bookings/track_booking.php',
    'assets/api/appointments/manage_services.php',
    'assets/api/bookings/reschedule_booking.php',
    'assets/api/bookings/decline_booking.php',
    'assets/api/bookings/convert_booking.php',
    'assets/api/bookings/approve_booking.php',
    'admin/admin_modules/manage_users.php',
    'assets/api/billing/get_transactions.php',
    'assets/api/auth/update_profile_api.php',
    'assets/api/auth/forgot_password_api.php',
    'assets/api/auth/change_password_api.php',
    'assets/api/auth/reset_password_api.php',
    'assets/api/auth/login_api.php',
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
    
    $patterns = [
        '/Database error\. Please try again\./' => 'An error occurred. Please try again.',
        '/Database error while preparing reset\./' => 'An error occurred. Please try again.',
        '/Database error while saving new password\./' => 'An error occurred. Please try again.',
        '/Database error\./' => 'An error occurred. Please try again.',
        '/Database error/' => 'An error occurred. Please try again.',
        '/Unable to prepare profile update\./' => 'An error occurred. Please try again.',
        '/Unable to prepare leave request\./' => 'An error occurred. Please try again.',
        '/Unable to prepare transaction query\./' => 'An error occurred. Please try again.',
        '/Insert prepare failed: .*/' => 'An error occurred. Please try again.',
        '/Timeslot prepare failed: .*/' => 'An error occurred. Please try again.',
        '/Database error: .*/' => 'An error occurred. Please try again.',
        '/query failed: .*/' => 'An error occurred. Please try again.',
        '/prepare failed: .*/' => 'An error occurred. Please try again.',
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