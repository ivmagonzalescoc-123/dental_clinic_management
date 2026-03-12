SELECT 'role' AS table_name, COUNT(*) AS rows_count FROM role
UNION ALL SELECT 'specialization', COUNT(*) FROM specialization
UNION ALL SELECT 'services', COUNT(*) FROM services
UNION ALL SELECT 'treatments', COUNT(*) FROM treatments
UNION ALL SELECT 'tooth', COUNT(*) FROM tooth
UNION ALL SELECT 'tooth_status_type', COUNT(*) FROM tooth_status_type
UNION ALL SELECT 'timeslot_service', COUNT(*) FROM timeslot_service
UNION ALL SELECT 'users', COUNT(*) FROM users
UNION ALL SELECT 'patients', COUNT(*) FROM patients
UNION ALL SELECT 'appointments', COUNT(*) FROM appointments
UNION ALL SELECT 'schedules', COUNT(*) FROM schedules
UNION ALL SELECT 'billing', COUNT(*) FROM billing
UNION ALL SELECT 'doctor_leaves', COUNT(*) FROM doctor_leaves
UNION ALL SELECT 'doctor_availability', COUNT(*) FROM doctor_availability
UNION ALL SELECT 'tooth_status', COUNT(*) FROM tooth_status;
