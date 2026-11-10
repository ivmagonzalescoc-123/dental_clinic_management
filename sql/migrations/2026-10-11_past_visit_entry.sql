-- Past visit entry: visits encoded from the old paper records.
-- The app also applies this on first use (appointments_ensure_historical_column), so
-- running it by hand is optional. Run once only: a second run fails on the existing column.

ALTER TABLE `appointments`
  ADD COLUMN `is_historical` tinyint(1) NOT NULL DEFAULT '0';

-- Off by default; the admin switches it on from Settings.
INSERT INTO `clinic_settings` (`setting_key`, `setting_value`, `description`)
VALUES ('past_visit_entry_enabled', '0', 'Lets the secretary encode past visits from paper records')
ON DUPLICATE KEY UPDATE `setting_key` = `setting_key`;
