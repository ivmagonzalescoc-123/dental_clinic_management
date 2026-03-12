SET NAMES utf8mb4;

INSERT INTO doctor_availability (user_id, weekday, start_time, end_time)
SELECT
    u.user_id,
    s.weekday,
    s.start_time,
    s.end_time
FROM users u
JOIN (
    SELECT 1 AS weekday, '09:00:00' AS start_time, '12:00:00' AS end_time
    UNION ALL SELECT 1, '13:00:00', '17:00:00'
    UNION ALL SELECT 2, '09:00:00', '12:00:00'
    UNION ALL SELECT 2, '13:00:00', '17:00:00'
    UNION ALL SELECT 3, '09:00:00', '12:00:00'
    UNION ALL SELECT 3, '13:00:00', '17:00:00'
    UNION ALL SELECT 4, '09:00:00', '12:00:00'
    UNION ALL SELECT 4, '13:00:00', '17:00:00'
    UNION ALL SELECT 5, '09:00:00', '12:00:00'
    UNION ALL SELECT 5, '13:00:00', '17:00:00'
) s
LEFT JOIN doctor_availability da
    ON da.user_id = u.user_id
   AND da.weekday = s.weekday
   AND da.start_time = s.start_time
   AND da.end_time = s.end_time
WHERE u.role_id = 2
  AND u.is_active = 1
  AND da.availability_id IS NULL;

INSERT IGNORE INTO timeslot (doctor_id, slot_date, start_time, end_time, status)
WITH RECURSIVE dates AS (
    SELECT CURDATE() AS d
    UNION ALL
    SELECT DATE_ADD(d, INTERVAL 1 DAY)
    FROM dates
    WHERE d < DATE_ADD(CURDATE(), INTERVAL 45 DAY)
)
SELECT
    da.user_id AS doctor_id,
    dates.d AS slot_date,
    da.start_time,
    da.end_time,
    'available' AS status
FROM doctor_availability da
JOIN dates
    ON WEEKDAY(dates.d) + 1 = da.weekday
JOIN users u
    ON u.user_id = da.user_id
   AND u.role_id = 2
   AND u.is_active = 1;

SELECT COUNT(*) AS doctor_count FROM users WHERE role_id = 2 AND is_active = 1;
SELECT COUNT(*) AS availability_count FROM doctor_availability;
SELECT COUNT(*) AS timeslot_count FROM timeslot;
SELECT COUNT(*) AS available_timeslot_count FROM timeslot WHERE status = 'available';
