-- Migration: 005_create_scheduling_tables.sql
-- Adds scheduling and reminders tables and doctor availability

SET FOREIGN_KEY_CHECKS=0;

CREATE TABLE IF NOT EXISTS doctor_availability (
  availability_id INT AUTO_INCREMENT PRIMARY KEY,
  user_id INT NOT NULL,
  weekday TINYINT NOT NULL COMMENT '0=Sun..6=Sat',
  start_time TIME NOT NULL,
  end_time TIME NOT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  INDEX (user_id),
  CONSTRAINT fk_doctor_avail_user FOREIGN KEY (user_id) REFERENCES users(user_id) ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS schedules (
  schedule_id INT AUTO_INCREMENT PRIMARY KEY,
  appointment_id INT NOT NULL,
  user_id INT DEFAULT NULL COMMENT 'doctor assigned',
  scheduled_start DATETIME NOT NULL,
  scheduled_end DATETIME DEFAULT NULL,
  status ENUM('scheduled','rescheduled','cancelled','completed') DEFAULT 'scheduled',
  priority TINYINT DEFAULT 1,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  INDEX (appointment_id),
  INDEX (user_id),
  CONSTRAINT fk_schedules_appointment FOREIGN KEY (appointment_id) REFERENCES appointments(appointment_id) ON DELETE CASCADE,
  CONSTRAINT fk_schedules_user FOREIGN KEY (user_id) REFERENCES users(user_id) ON DELETE SET NULL
);

CREATE TABLE IF NOT EXISTS schedule_reminders (
  reminder_id INT AUTO_INCREMENT PRIMARY KEY,
  schedule_id INT NOT NULL,
  remind_at DATETIME NOT NULL,
  channel ENUM('email','sms','push') NOT NULL DEFAULT 'email',
  sent_at DATETIME DEFAULT NULL,
  payload TEXT DEFAULT NULL,
  INDEX (schedule_id),
  CONSTRAINT fk_schedule_reminder FOREIGN KEY (schedule_id) REFERENCES schedules(schedule_id) ON DELETE CASCADE
);

SET FOREIGN_KEY_CHECKS=1;

-- Optional: backfill existing approved/confirmed appointments into schedules
-- INSERT INTO schedules (appointment_id, user_id, scheduled_start, status)
-- SELECT appointment_id, user_id, appointment_date, status FROM appointments WHERE status IN ('confirmed','scheduled');