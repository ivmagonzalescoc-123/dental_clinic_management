-- Doctor schedule changes: working hours for a date range on chosen weekdays (whole days
-- off are leave requests instead, which the admin approves).
-- A doctor's own change is saved as approved; one the secretary asks for stays pending until
-- the doctor approves it. The app also creates this table on first use (dsc_ensure_table in
-- config/doctor_schedule_helpers.php), so running it by hand is optional.

CREATE TABLE IF NOT EXISTS `doctor_schedule_changes` (
  `change_id` int NOT NULL AUTO_INCREMENT,
  `user_id` int NOT NULL,
  `start_date` date NOT NULL,
  `end_date` date NOT NULL,
  `weekdays` varchar(20) NOT NULL DEFAULT '',
  `kind` enum('hours','off') NOT NULL DEFAULT 'hours',
  `start_time` time DEFAULT NULL,
  `end_time` time DEFAULT NULL,
  `note` varchar(255) DEFAULT NULL,
  `status` enum('pending','approved','declined','cancelled') NOT NULL DEFAULT 'approved',
  `requested_by` int DEFAULT NULL,
  `requested_role` varchar(20) NOT NULL DEFAULT 'doctor',
  `decided_at` datetime DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`change_id`),
  KEY `idx_dsc_doctor_dates` (`user_id`, `status`, `start_date`, `end_date`),
  CONSTRAINT `fk_dsc_doctor` FOREIGN KEY (`user_id`) REFERENCES `users` (`user_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
