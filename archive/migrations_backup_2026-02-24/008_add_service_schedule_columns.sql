-- Migration: add scheduling metadata to services
ALTER TABLE `services`
  ADD COLUMN `requires_schedule` TINYINT(1) NOT NULL DEFAULT 0 AFTER `is_active`,
  ADD COLUMN `default_duration_minutes` INT NOT NULL DEFAULT 30 AFTER `requires_schedule`;

-- Mark common procedure services as requiring scheduling with sensible defaults
UPDATE services SET requires_schedule = 1, default_duration_minutes = 120 WHERE service_name LIKE '%Root Canal%';
UPDATE services SET requires_schedule = 1, default_duration_minutes = 90 WHERE service_name LIKE '%Crown%';
UPDATE services SET requires_schedule = 1, default_duration_minutes = 60 WHERE service_name LIKE '%Extraction%';
UPDATE services SET requires_schedule = 1, default_duration_minutes = 45 WHERE service_name LIKE '%Filling%';
UPDATE services SET requires_schedule = 1, default_duration_minutes = 30 WHERE service_name LIKE '%Teeth Cleaning%';
UPDATE services SET requires_schedule = 1, default_duration_minutes = 180 WHERE service_name LIKE '%Denture%';
UPDATE services SET requires_schedule = 1, default_duration_minutes = 120 WHERE service_name LIKE '%Implant%';