SET FOREIGN_KEY_CHECKS = 0;

ALTER TABLE `tooth_status_type` DROP FOREIGN KEY `fk_tst_tooth`;
ALTER TABLE `tooth_status_type` DROP COLUMN `tooth_id`;
ALTER TABLE `tooth_status_type`
  ADD COLUMN `label` varchar(120) NOT NULL DEFAULT '' AFTER `status_type_id`,
  ADD COLUMN `description` varchar(255) DEFAULT NULL AFTER `code`;

TRUNCATE TABLE `tooth_status_type`;

INSERT INTO `tooth_status_type` (`status_type_id`, `label`, `category`, `code`, `description`) VALUES
(1, 'Dental Caries', 'decay', 'C', 'Dental Caries'),
(2, 'Caries — Vital Pulp Expose', 'decay', 'C1', 'Dental Caries with Vital Pulp Expose'),
(3, 'Caries — Non-vital Pulp Expose', 'decay', 'C2', 'Dental Caries with Non-vital Pulp Expose'),
(4, 'Indicated for Extraction', 'other', 'X', 'Indicated for Extraction'),
(5, 'Retained Root Fragment', 'missing', 'RF', 'Retained Root Fragment'),
(6, 'Amalgam Filling', 'restoration', 'Am', 'Amalgam Filling'),
(7, 'Silicate Filling', 'restoration', 'S', 'Silicate Filling'),
(8, 'Gold Crown', 'restoration', 'GC', 'Gold Crown'),
(9, 'Bridge Abutment', 'restoration', 'Ab1', 'Bridge Abutment'),
(10, 'Pontic', 'restoration', 'P', 'Pontic'),
(11, 'Gold Clasp', 'restoration', 'GCI', 'Gold Clasp'),
(12, 'Gold Inlays', 'restoration', 'GI', 'Gold Inlays'),
(13, 'Missing (Extraction)', 'missing', 'M', 'Missing due to Extraction'),
(14, 'Unerupted', 'other', 'Un', 'Unerupted');

ALTER TABLE `tooth_status_type`
  ADD UNIQUE KEY `uk_chart_legend_code` (`code`);

SET FOREIGN_KEY_CHECKS = 1;
