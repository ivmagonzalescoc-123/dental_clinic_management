-- Insert a test appointment, schedule and reminder for patient_id=1 and doctor user_id=2
START TRANSACTION;
INSERT INTO appointments (appointment_date, reason, status, user_id, patient_id) VALUES (CURDATE(), 'Test appointment for reminders', 'confirmed', 2, 1);
SET @appt_id = LAST_INSERT_ID();
INSERT INTO schedules (appointment_id, user_id, scheduled_start, scheduled_end, status, priority) VALUES (@appt_id, 2, DATE_ADD(NOW(), INTERVAL 1 HOUR), DATE_ADD(NOW(), INTERVAL 90 MINUTE), 'scheduled', 1);
SET @sched_id = LAST_INSERT_ID();
INSERT INTO schedule_reminders (schedule_id, remind_at, channel, payload) VALUES (@sched_id, NOW(), 'email', JSON_OBJECT('test','true'));
COMMIT;
SELECT 'DONE' AS status, @appt_id AS appointment_id, @sched_id AS schedule_id;
