-- Migration: 006_add_schedule_id_to_clinical_records.sql
SET FOREIGN_KEY_CHECKS=0;

ALTER TABLE clinical_records
  ADD COLUMN schedule_id INT DEFAULT NULL AFTER appointment_id,
  ADD INDEX (`schedule_id`);

ALTER TABLE clinical_records
  ADD CONSTRAINT fk_clinrec_schedule FOREIGN KEY (schedule_id) REFERENCES schedules(schedule_id) ON DELETE SET NULL;

SET FOREIGN_KEY_CHECKS=1;