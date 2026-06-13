SET FOREIGN_KEY_CHECKS = 0;

ALTER TABLE `prescriptions` DROP FOREIGN KEY IF EXISTS `fk_prescriptions_treatment`;
ALTER TABLE `prescriptions` DROP INDEX IF EXISTS `fk_prescriptions_treatment`;
ALTER TABLE `prescriptions` DROP COLUMN IF EXISTS `selected_treatment_id`;

ALTER TABLE `tooth_images` DROP FOREIGN KEY IF EXISTS `fk_tooth_images_treatment`;
ALTER TABLE `tooth_images` DROP INDEX IF EXISTS `fk_tooth_images_treatment`;
ALTER TABLE `tooth_images` DROP COLUMN IF EXISTS `selected_treatment_id`;

ALTER TABLE `tooth_status` DROP FOREIGN KEY IF EXISTS `fk_tooth_status_selected_treatment`;
ALTER TABLE `tooth_status` DROP INDEX IF EXISTS `fk_tooth_status_selected_treatment`;
ALTER TABLE `tooth_status` DROP COLUMN IF EXISTS `selected_treatment_id`;

DROP TABLE IF EXISTS `selected_treatments`;
DROP TABLE IF EXISTS `treatments`;

SET FOREIGN_KEY_CHECKS = 1;
