<?php
require_once __DIR__ . '/../config/database.php';

$conn->query("SET FOREIGN_KEY_CHECKS = 0");

$tablesToTruncate = [
    'appointment_media',
    'appointment_doctor_notes',
    'appointment_reschedule_requests',
    'appointments',
    'bookings',
    'clinical_records',
    'clinical_record_images',
    'clinical_record_services',
    'billing',
    'billing_installments',
    'billing_installment_terms',
    'billing_line_items',
    'invoices',
    'payments',
    'prescriptions',
    'patient_schedules',
    'selected_services',
    'patient_profiling',
    'patient_notifications',
    'patient_logs',
    'patients',
    'notifications',
    'system_logs',
    'contact_messages',
    'backup',
    'rate_limit_hits',
    'password_resets',
    'dentist_specialization',
    'doctor_leaves',
    'doctor_availability',
    'doctor_prescription_profiles',
    'timeslot',
    'schedule_reminders',
    'timeslot_service',
];

foreach ($tablesToTruncate as $table) {
    if ($conn->query("TRUNCATE TABLE `$table`")) {
        echo "OK: TRUNCATE TABLE `$table`" . PHP_EOL;
    } else {
        echo "Error TRUNCATE `$table`: " . $conn->error . PHP_EOL;
    }
}

// Delete all users except admin (user_id=1)
if ($conn->query("DELETE FROM `users` WHERE `user_id` != 1")) {
    echo "OK: DELETE FROM users WHERE user_id != 1" . PHP_EOL;
} else {
    echo "Error DELETE users: " . $conn->error . PHP_EOL;
}

$conn->query("SET FOREIGN_KEY_CHECKS = 1");

echo "Done!" . PHP_EOL;