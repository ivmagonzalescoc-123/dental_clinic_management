-- Migration: add clinical_records table
CREATE TABLE IF NOT EXISTS `clinical_records` (
  `record_id` INT AUTO_INCREMENT PRIMARY KEY,
  `appointment_id` INT NOT NULL,
  `doctor_id` INT DEFAULT NULL,
  `patient_id` INT DEFAULT NULL,
  `summary` TEXT DEFAULT NULL,
  `recommended_services` JSON DEFAULT NULL,
  `treatment_plan` JSON DEFAULT NULL,
  `materials_used` JSON DEFAULT NULL,
  `odontogram` JSON DEFAULT NULL,
  `doctor_notes` TEXT DEFAULT NULL,
  `follow_up_date` DATE DEFAULT NULL,
  `status` ENUM('in_progress','submitted','finalized') DEFAULT 'in_progress',
  `submitted_by` INT DEFAULT NULL,
  `submitted_at` DATETIME DEFAULT NULL,
  INDEX (`appointment_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
