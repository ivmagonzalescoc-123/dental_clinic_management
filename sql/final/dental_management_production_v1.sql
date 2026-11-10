-- Dental Management - Production v1 (2026-11-05)
-- Clean starting database: full schema, no patients, appointments, billing, services or logs.
-- Accounts kept: admin@gmail.com (admin), doctor@gmail.com (doctor), secretary@gmail.com (secretary).
-- Passwords are unchanged from the testing database - change them after the first login.
-- MariaDB-compatible (utf8mb4_general_ci, no LOCK TABLES). Import into an empty database.


/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!50503 SET NAMES utf8mb4 */;
/*!40103 SET @OLD_TIME_ZONE=@@TIME_ZONE */;
/*!40103 SET TIME_ZONE='+00:00' */;
/*!40014 SET @OLD_UNIQUE_CHECKS=@@UNIQUE_CHECKS, UNIQUE_CHECKS=0 */;
/*!40014 SET @OLD_FOREIGN_KEY_CHECKS=@@FOREIGN_KEY_CHECKS, FOREIGN_KEY_CHECKS=0 */;
/*!40101 SET @OLD_SQL_MODE=@@SQL_MODE, SQL_MODE='NO_AUTO_VALUE_ON_ZERO' */;
/*!40111 SET @OLD_SQL_NOTES=@@SQL_NOTES, SQL_NOTES=0 */;
DROP TABLE IF EXISTS `appointment_doctor_notes`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `appointment_doctor_notes` (
  `note_id` int NOT NULL AUTO_INCREMENT,
  `appointment_id` int NOT NULL,
  `doctor_id` int NOT NULL,
  `note_text` text CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci,
  `created_at` datetime DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`note_id`),
  KEY `idx_appointment` (`appointment_id`),
  CONSTRAINT `appointment_doctor_notes_ibfk_1` FOREIGN KEY (`appointment_id`) REFERENCES `appointments` (`appointment_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

/*!40000 ALTER TABLE `appointment_doctor_notes` DISABLE KEYS */;
/*!40000 ALTER TABLE `appointment_doctor_notes` ENABLE KEYS */;
DROP TABLE IF EXISTS `appointment_media`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `appointment_media` (
  `media_id` int NOT NULL AUTO_INCREMENT,
  `appointment_id` int NOT NULL,
  `file_name` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL,
  `file_path` varchar(500) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL,
  `file_type` enum('photo','xray','document') CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT 'photo',
  `file_size` int DEFAULT NULL,
  `media_order` int DEFAULT '0',
  `tooth_number` int DEFAULT NULL,
  `uploaded_by` int DEFAULT NULL,
  `notes` text CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci,
  `uploaded_at` datetime DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`media_id`),
  KEY `idx_appointment` (`appointment_id`),
  CONSTRAINT `appointment_media_ibfk_1` FOREIGN KEY (`appointment_id`) REFERENCES `appointments` (`appointment_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

/*!40000 ALTER TABLE `appointment_media` DISABLE KEYS */;
/*!40000 ALTER TABLE `appointment_media` ENABLE KEYS */;
DROP TABLE IF EXISTS `appointment_reschedule_requests`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `appointment_reschedule_requests` (
  `request_id` int NOT NULL AUTO_INCREMENT,
  `appointment_id` int NOT NULL,
  `patient_id` int NOT NULL,
  `requested_doctor_id` int DEFAULT NULL,
  `requested_date` date NOT NULL,
  `requested_timeslot_id` int NOT NULL,
  `request_note` varchar(500) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT 'N/A',
  `status` enum('pending','approved','rejected','cancelled') CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL DEFAULT 'pending',
  `reviewed_by` int DEFAULT NULL,
  `review_note` varchar(500) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT 'N/A',
  `reviewed_at` datetime DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`request_id`),
  KEY `idx_arr_status_created` (`status`,`created_at`),
  KEY `idx_arr_appointment_status` (`appointment_id`,`status`),
  KEY `idx_arr_patient_status` (`patient_id`,`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

/*!40000 ALTER TABLE `appointment_reschedule_requests` DISABLE KEYS */;
/*!40000 ALTER TABLE `appointment_reschedule_requests` ENABLE KEYS */;
DROP TABLE IF EXISTS `appointments`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `appointments` (
  `appointment_id` int NOT NULL AUTO_INCREMENT,
  `appointment_date` date NOT NULL,
  `timeslot_id` int DEFAULT NULL,
  `reason` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT 'N/A',
  `is_online_appointment` tinyint(1) DEFAULT '0',
  `notes` text CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci,
  `status` enum('scheduled','confirmed','arrived','checked_in','in_progress','completed_by_doctor','awaiting_secretary_records','records_complete','completed','cancelled','no_show','rescheduled') CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT 'scheduled',
  `created_at` datetime DEFAULT CURRENT_TIMESTAMP,
  `user_id` int DEFAULT NULL,
  `rescheduled_from` int DEFAULT NULL,
  `patient_id` int NOT NULL,
  `is_emergency` tinyint(1) DEFAULT '0',
  `completed_by_doctor_at` datetime DEFAULT NULL,
  `session_started_at` datetime DEFAULT NULL,
  `secretary_records_completed_at` datetime DEFAULT NULL,
  `booking_id` int DEFAULT NULL,
  `cancellation_reason` varchar(500) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT 'N/A',
  `needs_reassignment` tinyint(1) NOT NULL DEFAULT '0',
  `reference_code` varchar(10) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT NULL,
  `is_historical` tinyint(1) NOT NULL DEFAULT '0',
  PRIMARY KEY (`appointment_id`),
  UNIQUE KEY `idx_appointments_reference_code` (`reference_code`),
  KEY `fk_appointments_user` (`user_id`),
  KEY `fk_appointments_rescheduled_from` (`rescheduled_from`),
  KEY `fk_appointments_patient` (`patient_id`),
  KEY `timeslot_id` (`timeslot_id`),
  KEY `fk_appointments_booking` (`booking_id`),
  KEY `idx_patient_date` (`patient_id`,`appointment_date`),
  KEY `idx_appointments_status` (`status`),
  KEY `idx_appointments_date_status` (`appointment_date`,`status`),
  CONSTRAINT `appointments_ibfk_1` FOREIGN KEY (`timeslot_id`) REFERENCES `timeslot` (`timeslot_id`) ON DELETE SET NULL,
  CONSTRAINT `fk_appointments_booking` FOREIGN KEY (`booking_id`) REFERENCES `bookings` (`booking_id`) ON DELETE SET NULL,
  CONSTRAINT `fk_appointments_patient` FOREIGN KEY (`patient_id`) REFERENCES `patients` (`patient_id`) ON DELETE CASCADE,
  CONSTRAINT `fk_appointments_rescheduled_from` FOREIGN KEY (`rescheduled_from`) REFERENCES `appointments` (`appointment_id`) ON DELETE SET NULL,
  CONSTRAINT `fk_appointments_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`user_id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

/*!40000 ALTER TABLE `appointments` DISABLE KEYS */;
/*!40000 ALTER TABLE `appointments` ENABLE KEYS */;
DROP TABLE IF EXISTS `auth_remember_tokens`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `auth_remember_tokens` (
  `token_id` int NOT NULL AUTO_INCREMENT,
  `account_type` varchar(10) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL,
  `account_id` int NOT NULL,
  `selector` char(24) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL,
  `validator_hash` char(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL,
  `expires_at` datetime NOT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `user_agent` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT NULL,
  PRIMARY KEY (`token_id`),
  UNIQUE KEY `uq_remember_selector` (`selector`),
  KEY `idx_remember_account` (`account_type`,`account_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

/*!40000 ALTER TABLE `auth_remember_tokens` DISABLE KEYS */;
/*!40000 ALTER TABLE `auth_remember_tokens` ENABLE KEYS */;
DROP TABLE IF EXISTS `backup`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `backup` (
  `backup_id` int NOT NULL AUTO_INCREMENT,
  `user_id` int DEFAULT NULL,
  `backup_date` datetime DEFAULT CURRENT_TIMESTAMP,
  `file_name` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT NULL,
  `file_path` varchar(500) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT NULL,
  `file_size` decimal(12,2) DEFAULT NULL,
  PRIMARY KEY (`backup_id`),
  KEY `fk_backup_user` (`user_id`),
  CONSTRAINT `fk_backup_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`user_id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

/*!40000 ALTER TABLE `backup` DISABLE KEYS */;
/*!40000 ALTER TABLE `backup` ENABLE KEYS */;
DROP TABLE IF EXISTS `billing`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `billing` (
  `billing_id` int NOT NULL AUTO_INCREMENT,
  `appointment_id` int DEFAULT NULL,
  `user_id` int DEFAULT NULL,
  `total_amount` decimal(12,2) NOT NULL DEFAULT '0.00',
  `amount_paid` decimal(12,2) NOT NULL DEFAULT '0.00',
  `balance` decimal(12,2) NOT NULL DEFAULT '0.00',
  `subtotal_amount` decimal(12,2) NOT NULL DEFAULT '0.00',
  `discount_type` enum('none','senior','pwd','custom') CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL DEFAULT 'none',
  `discount_percent` decimal(5,2) NOT NULL DEFAULT '0.00',
  `discount_notes` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT 'N/A',
  `created_at` datetime DEFAULT CURRENT_TIMESTAMP,
  `status` enum('unpaid','partial','paid') CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL DEFAULT 'unpaid',
  `processed_by` int DEFAULT NULL,
  PRIMARY KEY (`billing_id`),
  UNIQUE KEY `appointment_id` (`appointment_id`),
  KEY `fk_billing_user` (`user_id`),
  KEY `fk_billing_processed_by` (`processed_by`),
  KEY `idx_billing_status` (`status`),
  CONSTRAINT `fk_billing_appointment` FOREIGN KEY (`appointment_id`) REFERENCES `appointments` (`appointment_id`) ON DELETE CASCADE,
  CONSTRAINT `fk_billing_processed_by` FOREIGN KEY (`processed_by`) REFERENCES `users` (`user_id`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `fk_billing_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`user_id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

/*!40000 ALTER TABLE `billing` DISABLE KEYS */;
/*!40000 ALTER TABLE `billing` ENABLE KEYS */;
DROP TABLE IF EXISTS `billing_installment_terms`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `billing_installment_terms` (
  `term_id` int NOT NULL AUTO_INCREMENT,
  `installment_id` int NOT NULL,
  `term_number` int NOT NULL,
  `due_date` date NOT NULL,
  `amount` decimal(12,2) NOT NULL DEFAULT '0.00',
  `paid_amount` decimal(12,2) NOT NULL DEFAULT '0.00',
  `status` enum('pending','partial','paid') CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL DEFAULT 'pending',
  `paid_at` datetime DEFAULT NULL,
  `due_soon_reminder_sent_at` datetime DEFAULT NULL,
  `overdue_reminder_sent_at` datetime DEFAULT NULL,
  PRIMARY KEY (`term_id`),
  KEY `idx_bit_installment` (`installment_id`),
  KEY `idx_bit_due` (`due_date`),
  CONSTRAINT `fk_bit_installment` FOREIGN KEY (`installment_id`) REFERENCES `billing_installments` (`installment_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

/*!40000 ALTER TABLE `billing_installment_terms` DISABLE KEYS */;
/*!40000 ALTER TABLE `billing_installment_terms` ENABLE KEYS */;
DROP TABLE IF EXISTS `billing_installments`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `billing_installments` (
  `installment_id` int NOT NULL AUTO_INCREMENT,
  `billing_id` int NOT NULL,
  `total_amount` decimal(12,2) NOT NULL DEFAULT '0.00',
  `down_payment_percent` decimal(5,2) NOT NULL DEFAULT '50.00',
  `down_payment_amount` decimal(12,2) NOT NULL DEFAULT '0.00',
  `num_terms` int NOT NULL DEFAULT '0',
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`installment_id`),
  UNIQUE KEY `uq_installment_billing` (`billing_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

/*!40000 ALTER TABLE `billing_installments` DISABLE KEYS */;
/*!40000 ALTER TABLE `billing_installments` ENABLE KEYS */;
DROP TABLE IF EXISTS `billing_line_items`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `billing_line_items` (
  `item_id` int NOT NULL AUTO_INCREMENT,
  `billing_id` int DEFAULT NULL,
  `appointment_id` int NOT NULL,
  `service_id` int DEFAULT NULL,
  `description` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL,
  `amount` decimal(12,2) NOT NULL DEFAULT '0.00',
  `item_type` enum('service','extra') CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL DEFAULT 'service',
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`item_id`),
  KEY `idx_bli_appointment` (`appointment_id`),
  KEY `idx_bli_billing` (`billing_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

/*!40000 ALTER TABLE `billing_line_items` DISABLE KEYS */;
/*!40000 ALTER TABLE `billing_line_items` ENABLE KEYS */;
DROP TABLE IF EXISTS `bookings`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `bookings` (
  `booking_id` int NOT NULL AUTO_INCREMENT,
  `first_name` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL,
  `last_name` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL,
  `email` varchar(150) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL,
  `phone_number` varchar(30) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT NULL,
  `date_of_birth` date DEFAULT NULL,
  `preferred_date` date NOT NULL,
  `preferred_time` varchar(20) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL DEFAULT '8:00 AM',
  `reason` varchar(500) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT 'N/A',
  `otp_hash` char(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT NULL,
  `otp_expires_at` datetime DEFAULT NULL,
  `status` enum('pending_verification','verified','approved','converted','cancelled') CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL DEFAULT 'pending_verification',
  `converted_to_appointment_id` int DEFAULT NULL,
  `converted_to_patient_id` int DEFAULT NULL,
  `created_at` datetime DEFAULT CURRENT_TIMESTAMP,
  `decline_reason` varchar(500) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT 'N/A',
  `tracking_code` varchar(10) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT NULL,
  PRIMARY KEY (`booking_id`),
  UNIQUE KEY `idx_bookings_tracking_code` (`tracking_code`),
  KEY `idx_bookings_status` (`status`),
  KEY `idx_bookings_email` (`email`),
  KEY `idx_bookings_preferred_date` (`preferred_date`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

/*!40000 ALTER TABLE `bookings` DISABLE KEYS */;
/*!40000 ALTER TABLE `bookings` ENABLE KEYS */;
DROP TABLE IF EXISTS `clinic_settings`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `clinic_settings` (
  `setting_key` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL,
  `setting_value` text CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci,
  `description` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT 'N/A',
  `updated_at` datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`setting_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

/*!40000 ALTER TABLE `clinic_settings` DISABLE KEYS */;
INSERT INTO `clinic_settings` VALUES ('consultation_fee','1000.00','Default consultation fee for consultation-only appointments','2026-07-20 08:48:32'),('past_visit_entry_enabled','0','Lets the secretary encode past visits from paper records','2026-10-11 10:18:33');
/*!40000 ALTER TABLE `clinic_settings` ENABLE KEYS */;
DROP TABLE IF EXISTS `clinical_findings`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `clinical_findings` (
  `finding_id` int NOT NULL AUTO_INCREMENT,
  `record_id` int NOT NULL,
  `patient_id` int NOT NULL,
  `findings_json` text CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`finding_id`),
  UNIQUE KEY `uniq_cf_record` (`record_id`),
  KEY `idx_cf_patient` (`patient_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

/*!40000 ALTER TABLE `clinical_findings` DISABLE KEYS */;
/*!40000 ALTER TABLE `clinical_findings` ENABLE KEYS */;
DROP TABLE IF EXISTS `clinical_record_images`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `clinical_record_images` (
  `image_id` int NOT NULL AUTO_INCREMENT,
  `record_id` int NOT NULL,
  `file_path` varchar(500) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL,
  `file_type` enum('photo','xray','document') CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT 'photo',
  `tooth_number` int DEFAULT NULL,
  `uploaded_by` int DEFAULT NULL,
  `uploaded_at` datetime DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`image_id`),
  KEY `idx_cri_record` (`record_id`),
  CONSTRAINT `fk_cri_record` FOREIGN KEY (`record_id`) REFERENCES `clinical_records` (`record_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

/*!40000 ALTER TABLE `clinical_record_images` DISABLE KEYS */;
/*!40000 ALTER TABLE `clinical_record_images` ENABLE KEYS */;
DROP TABLE IF EXISTS `clinical_record_services`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `clinical_record_services` (
  `id` int NOT NULL AUTO_INCREMENT,
  `record_id` int NOT NULL,
  `service_id` int NOT NULL,
  `notes` text CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_crs_record_service` (`record_id`,`service_id`),
  KEY `idx_crs_record` (`record_id`),
  KEY `idx_crs_service` (`service_id`),
  CONSTRAINT `fk_crs_record` FOREIGN KEY (`record_id`) REFERENCES `clinical_records` (`record_id`) ON DELETE CASCADE,
  CONSTRAINT `fk_crs_service` FOREIGN KEY (`service_id`) REFERENCES `services` (`service_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

/*!40000 ALTER TABLE `clinical_record_services` DISABLE KEYS */;
/*!40000 ALTER TABLE `clinical_record_services` ENABLE KEYS */;
DROP TABLE IF EXISTS `clinical_records`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `clinical_records` (
  `record_id` int NOT NULL AUTO_INCREMENT,
  `appointment_id` int NOT NULL,
  `doctor_id` int DEFAULT NULL,
  `patient_id` int DEFAULT NULL,
  `summary` text CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci,
  `doctor_notes` text CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci,
  `follow_up_date` date DEFAULT NULL,
  `status` enum('in_progress','submitted','finalized') CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT 'in_progress',
  `submitted_by` int DEFAULT NULL,
  `submitted_at` datetime DEFAULT NULL,
  `primary_image` varchar(500) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT NULL,
  PRIMARY KEY (`record_id`),
  KEY `appointment_id` (`appointment_id`),
  KEY `doctor_id` (`doctor_id`),
  KEY `patient_id` (`patient_id`),
  KEY `submitted_by` (`submitted_by`),
  KEY `idx_clinical_records_status` (`status`),
  KEY `idx_clinical_records_submitted_at` (`submitted_at`),
  CONSTRAINT `fk_clinrec_appointment` FOREIGN KEY (`appointment_id`) REFERENCES `appointments` (`appointment_id`) ON DELETE CASCADE,
  CONSTRAINT `fk_clinrec_doctor` FOREIGN KEY (`doctor_id`) REFERENCES `users` (`user_id`) ON DELETE SET NULL,
  CONSTRAINT `fk_clinrec_patient` FOREIGN KEY (`patient_id`) REFERENCES `patients` (`patient_id`) ON DELETE SET NULL,
  CONSTRAINT `fk_clinrec_submitted_by` FOREIGN KEY (`submitted_by`) REFERENCES `users` (`user_id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

/*!40000 ALTER TABLE `clinical_records` DISABLE KEYS */;
/*!40000 ALTER TABLE `clinical_records` ENABLE KEYS */;
DROP TABLE IF EXISTS `contact_messages`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `contact_messages` (
  `message_id` int NOT NULL AUTO_INCREMENT,
  `full_name` varchar(150) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL,
  `email` varchar(150) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL,
  `phone_number` varchar(30) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT NULL,
  `message` text CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL,
  `html_body` mediumtext CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci,
  `status` enum('new','read','archived') CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL DEFAULT 'new',
  `source` varchar(20) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL DEFAULT 'form',
  `gmail_uid` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT NULL,
  `read_by` int DEFAULT NULL,
  `read_at` datetime DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`message_id`),
  UNIQUE KEY `idx_contact_gmail_uid` (`gmail_uid`),
  KEY `idx_contact_status_created` (`status`,`created_at`),
  KEY `idx_contact_email` (`email`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

/*!40000 ALTER TABLE `contact_messages` DISABLE KEYS */;
/*!40000 ALTER TABLE `contact_messages` ENABLE KEYS */;
DROP TABLE IF EXISTS `dentist_specialization`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `dentist_specialization` (
  `doctor_specialized_id` int NOT NULL AUTO_INCREMENT,
  `user_id` int NOT NULL,
  `specialization_id` int NOT NULL,
  PRIMARY KEY (`doctor_specialized_id`),
  UNIQUE KEY `uq_dentist_specialization_user_spec` (`user_id`,`specialization_id`),
  KEY `idx_dentist_specialization_specialization` (`specialization_id`),
  CONSTRAINT `fk_dentist_specialization_specialization` FOREIGN KEY (`specialization_id`) REFERENCES `specialization` (`specialization_id`) ON DELETE CASCADE,
  CONSTRAINT `fk_dentist_specialization_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`user_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

/*!40000 ALTER TABLE `dentist_specialization` DISABLE KEYS */;
/*!40000 ALTER TABLE `dentist_specialization` ENABLE KEYS */;
DROP TABLE IF EXISTS `doctor_availability`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `doctor_availability` (
  `availability_id` int NOT NULL AUTO_INCREMENT,
  `user_id` int NOT NULL,
  `weekday` tinyint NOT NULL,
  `start_time` time NOT NULL,
  `end_time` time NOT NULL,
  `status` enum('active','inactive','suspended') CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL DEFAULT 'active',
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`availability_id`),
  KEY `user_id` (`user_id`),
  CONSTRAINT `fk_avail_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`user_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

/*!40000 ALTER TABLE `doctor_availability` DISABLE KEYS */;
/*!40000 ALTER TABLE `doctor_availability` ENABLE KEYS */;
DROP TABLE IF EXISTS `doctor_leaves`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `doctor_leaves` (
  `leave_id` int NOT NULL AUTO_INCREMENT,
  `user_id` int NOT NULL,
  `leave_start_date` date NOT NULL,
  `leave_end_date` date NOT NULL,
  `reason` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT 'N/A',
  `status` enum('pending','approved','rejected') CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL DEFAULT 'approved',
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`leave_id`),
  KEY `idx_doctor_leave_dates` (`user_id`,`leave_start_date`,`leave_end_date`),
  KEY `idx_doctor_leave_status` (`status`),
  CONSTRAINT `fk_doctor_leaves_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`user_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

/*!40000 ALTER TABLE `doctor_leaves` DISABLE KEYS */;
/*!40000 ALTER TABLE `doctor_leaves` ENABLE KEYS */;
DROP TABLE IF EXISTS `doctor_prescription_profiles`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `doctor_prescription_profiles` (
  `profile_id` int NOT NULL AUTO_INCREMENT,
  `user_id` int NOT NULL,
  `full_name` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL,
  `professional_title` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT 'DMD',
  `licenses` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin,
  `signature_image` varchar(500) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT NULL,
  `signature_drawn` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci,
  `clinic_name` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT 'Azucena''s Dental Clinic',
  `clinic_address` varchar(500) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT 'Metz Arcade, Cagayan de Oro City, Philippines',
  `clinic_phone` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT '0917 984 3031',
  `created_at` datetime DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`profile_id`),
  UNIQUE KEY `unique_user_profile` (`user_id`),
  KEY `idx_user_id` (`user_id`),
  CONSTRAINT `doctor_prescription_profiles_chk_1` CHECK (json_valid(`licenses`))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

/*!40000 ALTER TABLE `doctor_prescription_profiles` DISABLE KEYS */;
/*!40000 ALTER TABLE `doctor_prescription_profiles` ENABLE KEYS */;
DROP TABLE IF EXISTS `doctor_quick_phrases`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `doctor_quick_phrases` (
  `phrase_id` int NOT NULL AUTO_INCREMENT,
  `doctor_id` int NOT NULL,
  `field_key` varchar(40) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL,
  `phrase` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`phrase_id`),
  KEY `idx_dqp_doctor_field` (`doctor_id`,`field_key`)
) ENGINE=InnoDB AUTO_INCREMENT=65 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

/*!40000 ALTER TABLE `doctor_quick_phrases` DISABLE KEYS */;
INSERT INTO `doctor_quick_phrases` VALUES (1,6,'_seeded','','2026-10-13 11:58:15'),(2,6,'doctor_notes','Follow-up appointment needed.','2026-10-13 11:58:15'),(3,6,'doctor_notes','Return after 1 week.','2026-10-13 11:58:15'),(4,6,'doctor_notes','Return after 6 months for check-up and cleaning.','2026-10-13 11:58:15'),(5,6,'doctor_notes','Patient tolerated the procedure well.','2026-10-13 11:58:15'),(6,6,'doctor_notes','Post-operative instructions given.','2026-10-13 11:58:15'),(7,6,'doctor_notes','Oral hygiene instructions given.','2026-10-13 11:58:15'),(8,6,'doctor_notes','Prescription given.','2026-10-13 11:58:15'),(9,6,'doctor_notes','No complications.','2026-10-13 11:58:15'),(10,6,'doctor_notes','Referred for X-ray.','2026-10-13 11:58:15'),(11,6,'doctor_summary','Treatment completed as planned.','2026-10-13 11:58:15'),(12,6,'doctor_summary','Oral prophylaxis done.','2026-10-13 11:58:15'),(13,6,'doctor_summary','Tooth restored with composite filling.','2026-10-13 11:58:15'),(14,6,'doctor_summary','Tooth extracted, bleeding controlled.','2026-10-13 11:58:15'),(15,6,'doctor_summary','Treatment ongoing, to be continued next visit.','2026-10-13 11:58:15'),(16,6,'doctor_summary','For observation.','2026-10-13 11:58:15'),(17,6,'doctor_chief_complaint','Toothache','2026-10-13 11:58:15'),(18,6,'doctor_chief_complaint','Tooth sensitivity','2026-10-13 11:58:15'),(19,6,'doctor_chief_complaint','Bleeding gums','2026-10-13 11:58:15'),(20,6,'doctor_chief_complaint','Swelling','2026-10-13 11:58:15'),(21,6,'doctor_chief_complaint','Broken or chipped tooth','2026-10-13 11:58:15'),(22,6,'doctor_chief_complaint','Loose tooth','2026-10-13 11:58:15'),(23,6,'doctor_chief_complaint','Routine check-up','2026-10-13 11:58:15'),(24,6,'doctor_chief_complaint','Cleaning','2026-10-13 11:58:15'),(25,6,'doctor_chief_complaint','Braces adjustment','2026-10-13 11:58:15'),(26,6,'doctor_case_history','No relevant medical history','2026-10-13 11:58:15'),(27,6,'doctor_case_history','No known allergies','2026-10-13 11:58:15'),(28,6,'doctor_case_history','Hypertensive, on maintenance medication','2026-10-13 11:58:15'),(29,6,'doctor_case_history','Diabetic','2026-10-13 11:58:15'),(30,6,'doctor_case_history','Previous extraction','2026-10-13 11:58:15'),(31,6,'doctor_case_history','Currently in orthodontic treatment','2026-10-13 11:58:15'),(32,6,'doctor_case_history','First dental visit','2026-10-13 11:58:15');
/*!40000 ALTER TABLE `doctor_quick_phrases` ENABLE KEYS */;
DROP TABLE IF EXISTS `doctor_schedule_changes`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `doctor_schedule_changes` (
  `change_id` int NOT NULL AUTO_INCREMENT,
  `user_id` int NOT NULL,
  `start_date` date NOT NULL,
  `end_date` date NOT NULL,
  `weekdays` varchar(20) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL DEFAULT '',
  `kind` enum('hours','off') CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL DEFAULT 'hours',
  `start_time` time DEFAULT NULL,
  `end_time` time DEFAULT NULL,
  `note` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT NULL,
  `status` enum('pending','approved','declined','cancelled') CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL DEFAULT 'approved',
  `requested_by` int DEFAULT NULL,
  `requested_role` varchar(20) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL DEFAULT 'doctor',
  `decided_at` datetime DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`change_id`),
  KEY `idx_dsc_doctor_dates` (`user_id`,`status`,`start_date`,`end_date`),
  CONSTRAINT `fk_dsc_doctor` FOREIGN KEY (`user_id`) REFERENCES `users` (`user_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

/*!40000 ALTER TABLE `doctor_schedule_changes` DISABLE KEYS */;
/*!40000 ALTER TABLE `doctor_schedule_changes` ENABLE KEYS */;
DROP TABLE IF EXISTS `invoices`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `invoices` (
  `invoice_id` int NOT NULL AUTO_INCREMENT,
  `billing_id` int NOT NULL,
  `invoice_number` varchar(30) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL,
  `issued_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `issued_by` int DEFAULT NULL,
  PRIMARY KEY (`invoice_id`),
  UNIQUE KEY `uq_invoice_billing` (`billing_id`),
  UNIQUE KEY `uq_invoice_number` (`invoice_number`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

/*!40000 ALTER TABLE `invoices` DISABLE KEYS */;
/*!40000 ALTER TABLE `invoices` ENABLE KEYS */;
DROP TABLE IF EXISTS `notifications`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `notifications` (
  `notification_id` int NOT NULL AUTO_INCREMENT,
  `user_id` int DEFAULT NULL,
  `title` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL,
  `body` text CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci,
  `link` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT NULL,
  `is_read` tinyint(1) DEFAULT '0',
  `created_at` datetime DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`notification_id`),
  KEY `idx_notifications_user` (`user_id`),
  KEY `idx_notifications_is_read` (`is_read`),
  CONSTRAINT `fk_notifications_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`user_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

/*!40000 ALTER TABLE `notifications` DISABLE KEYS */;
/*!40000 ALTER TABLE `notifications` ENABLE KEYS */;
DROP TABLE IF EXISTS `password_resets`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `password_resets` (
  `reset_id` int NOT NULL AUTO_INCREMENT,
  `user_type` enum('user','patient') CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL,
  `user_id` int NOT NULL,
  `email` varchar(150) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL,
  `token_hash` char(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL,
  `otp_hash` char(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL,
  `expires_at` datetime NOT NULL,
  `used_at` datetime DEFAULT NULL,
  `ip_address` varchar(45) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT NULL,
  `created_at` datetime NOT NULL,
  PRIMARY KEY (`reset_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

/*!40000 ALTER TABLE `password_resets` DISABLE KEYS */;
/*!40000 ALTER TABLE `password_resets` ENABLE KEYS */;
DROP TABLE IF EXISTS `patient_feedback`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `patient_feedback` (
  `feedback_id` int NOT NULL AUTO_INCREMENT,
  `patient_id` int NOT NULL,
  `appointment_id` int DEFAULT NULL,
  `doctor_id` int DEFAULT NULL,
  `rating` tinyint NOT NULL,
  `comment` text CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci,
  `is_featured` tinyint(1) NOT NULL DEFAULT '0',
  `created_at` datetime DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`feedback_id`),
  UNIQUE KEY `uniq_patient_feedback` (`patient_id`),
  KEY `idx_feedback_featured` (`is_featured`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

/*!40000 ALTER TABLE `patient_feedback` DISABLE KEYS */;
/*!40000 ALTER TABLE `patient_feedback` ENABLE KEYS */;
DROP TABLE IF EXISTS `patient_logs`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `patient_logs` (
  `patient_log_id` int NOT NULL AUTO_INCREMENT,
  `patient_id` int NOT NULL,
  `login_time` datetime DEFAULT NULL,
  `last_activity` datetime DEFAULT NULL,
  `logout_time` datetime DEFAULT NULL,
  `ip_address` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT NULL,
  `user_agent` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT NULL,
  PRIMARY KEY (`patient_log_id`),
  KEY `fk_patient_logs_patient` (`patient_id`),
  CONSTRAINT `fk_patient_logs_patient` FOREIGN KEY (`patient_id`) REFERENCES `patients` (`patient_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

/*!40000 ALTER TABLE `patient_logs` DISABLE KEYS */;
/*!40000 ALTER TABLE `patient_logs` ENABLE KEYS */;
DROP TABLE IF EXISTS `patient_notifications`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `patient_notifications` (
  `patient_notification_id` int NOT NULL AUTO_INCREMENT,
  `patient_id` int NOT NULL,
  `title` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL,
  `body` text CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL,
  `link` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT NULL,
  `is_read` tinyint(1) NOT NULL DEFAULT '0',
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`patient_notification_id`),
  KEY `idx_patient_notifications_patient` (`patient_id`),
  KEY `idx_patient_notifications_read` (`is_read`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

/*!40000 ALTER TABLE `patient_notifications` DISABLE KEYS */;
/*!40000 ALTER TABLE `patient_notifications` ENABLE KEYS */;
DROP TABLE IF EXISTS `patient_profiling`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `patient_profiling` (
  `profiling_id` int NOT NULL AUTO_INCREMENT,
  `patient_id` int NOT NULL,
  `record_id` int DEFAULT NULL,
  `race` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT 'N/A',
  `occupation` varchar(150) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL DEFAULT 'N/A',
  `medical_history` text CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci,
  `medications` text CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci,
  `allergies` varchar(500) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT 'N/A',
  `smoking` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT 'N/A',
  `alcohol` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT 'N/A',
  `is_hypertensive` tinyint(1) DEFAULT '0',
  `is_diabetic` tinyint(1) DEFAULT '0',
  `is_asthmatic` tinyint(1) DEFAULT '0',
  `has_heart_disease` tinyint(1) DEFAULT '0',
  `is_hiv_positive` tinyint(1) DEFAULT '0',
  `bleeding_disorder` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT 'N/A',
  `pregnancy_status` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT 'N/A',
  `previous_dental_treatment` text CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci,
  `dental_anxiety` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT 'N/A',
  `emergency_contact` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL DEFAULT 'N/A',
  `emergency_phone` varchar(30) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL DEFAULT 'N/A',
  `background_notes` text CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci,
  `created_at` datetime DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`profiling_id`),
  KEY `idx_pp_patient` (`patient_id`),
  KEY `idx_pp_record` (`record_id`),
  CONSTRAINT `fk_pp_patient` FOREIGN KEY (`patient_id`) REFERENCES `patients` (`patient_id`) ON DELETE CASCADE,
  CONSTRAINT `fk_pp_record` FOREIGN KEY (`record_id`) REFERENCES `clinical_records` (`record_id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

/*!40000 ALTER TABLE `patient_profiling` DISABLE KEYS */;
/*!40000 ALTER TABLE `patient_profiling` ENABLE KEYS */;
DROP TABLE IF EXISTS `patient_schedules`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `patient_schedules` (
  `schedule_id` int NOT NULL AUTO_INCREMENT,
  `appointment_id` int NOT NULL,
  `user_id` int DEFAULT NULL,
  `scheduled_start` datetime NOT NULL,
  `scheduled_end` datetime DEFAULT NULL,
  `status` enum('scheduled','rescheduled','cancelled','completed') CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT 'scheduled',
  `priority` tinyint DEFAULT '1',
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`schedule_id`),
  KEY `appointment_id` (`appointment_id`),
  KEY `user_id` (`user_id`),
  CONSTRAINT `fk_patient_schedules_appointment` FOREIGN KEY (`appointment_id`) REFERENCES `appointments` (`appointment_id`) ON DELETE CASCADE,
  CONSTRAINT `fk_patient_schedules_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`user_id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

/*!40000 ALTER TABLE `patient_schedules` DISABLE KEYS */;
/*!40000 ALTER TABLE `patient_schedules` ENABLE KEYS */;
DROP TABLE IF EXISTS `patient_treatment_plan_items`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `patient_treatment_plan_items` (
  `item_id` int NOT NULL AUTO_INCREMENT,
  `patient_id` int NOT NULL,
  `service_id` int NOT NULL,
  `tooth_number` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT NULL,
  `phase` tinyint NOT NULL DEFAULT '1',
  `estimated_cost` decimal(12,2) NOT NULL DEFAULT '0.00',
  `notes` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT NULL,
  `status` enum('proposed','accepted','completed','declined') CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL DEFAULT 'proposed',
  `source_appointment_id` int DEFAULT NULL,
  `created_by` int DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `accepted_at` datetime DEFAULT NULL,
  `completed_appointment_id` int DEFAULT NULL,
  `completed_at` datetime DEFAULT NULL,
  `updated_at` datetime DEFAULT NULL,
  PRIMARY KEY (`item_id`),
  KEY `idx_tp_patient` (`patient_id`,`status`),
  KEY `idx_tp_completed_appt` (`completed_appointment_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

/*!40000 ALTER TABLE `patient_treatment_plan_items` DISABLE KEYS */;
/*!40000 ALTER TABLE `patient_treatment_plan_items` ENABLE KEYS */;
DROP TABLE IF EXISTS `patients`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `patients` (
  `patient_id` int NOT NULL AUTO_INCREMENT,
  `first_name` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL,
  `last_name` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL,
  `middle_name` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT 'N/A',
  `password_hash` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT NULL,
  `google_id` varchar(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT NULL,
  `google_picture` varchar(500) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT NULL,
  `date_of_birth` date DEFAULT NULL,
  `gender` varchar(20) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT 'N/A',
  `phone_number` varchar(30) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT NULL,
  `email` varchar(150) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT NULL,
  `address` text CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci,
  `occupation` varchar(150) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL DEFAULT 'N/A',
  `created_at` datetime DEFAULT CURRENT_TIMESTAMP,
  `added_by` int DEFAULT NULL,
  `must_change_password` tinyint(1) NOT NULL DEFAULT '0',
  `password_updated_at` datetime DEFAULT NULL,
  `failed_login_attempts` int NOT NULL DEFAULT '0',
  `locked_until` datetime DEFAULT NULL,
  `last_login_at` datetime DEFAULT NULL,
  `profile_image` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT NULL,
  PRIMARY KEY (`patient_id`),
  UNIQUE KEY `uq_patients_google_id` (`google_id`),
  KEY `fk_patients_added_by` (`added_by`),
  KEY `idx_patients_email` (`email`),
  CONSTRAINT `fk_patients_added_by` FOREIGN KEY (`added_by`) REFERENCES `users` (`user_id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

/*!40000 ALTER TABLE `patients` DISABLE KEYS */;
/*!40000 ALTER TABLE `patients` ENABLE KEYS */;
DROP TABLE IF EXISTS `payments`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `payments` (
  `payment_id` int NOT NULL AUTO_INCREMENT,
  `billing_id` int NOT NULL,
  `amount` decimal(12,2) NOT NULL DEFAULT '0.00',
  `payment_method` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT 'cash',
  `or_number` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL,
  `notes` varchar(500) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT 'N/A',
  `recorded_by` int DEFAULT NULL,
  `paid_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`payment_id`),
  KEY `idx_payments_billing` (`billing_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

/*!40000 ALTER TABLE `payments` DISABLE KEYS */;
/*!40000 ALTER TABLE `payments` ENABLE KEYS */;
DROP TABLE IF EXISTS `prescriptions`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `prescriptions` (
  `prescription_id` int NOT NULL AUTO_INCREMENT,
  `appointment_id` int DEFAULT NULL,
  `prescription_date` date DEFAULT NULL,
  `medicine_name` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT 'N/A',
  `dosage` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT 'N/A',
  `medicine_brand` varchar(150) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT 'N/A',
  `duration` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT 'N/A',
  `notes` text CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci,
  PRIMARY KEY (`prescription_id`),
  KEY `fk_prescriptions_appointment` (`appointment_id`),
  CONSTRAINT `fk_prescriptions_appointment` FOREIGN KEY (`appointment_id`) REFERENCES `appointments` (`appointment_id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

/*!40000 ALTER TABLE `prescriptions` DISABLE KEYS */;
/*!40000 ALTER TABLE `prescriptions` ENABLE KEYS */;
DROP TABLE IF EXISTS `rate_limit_hits`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `rate_limit_hits` (
  `hit_id` int NOT NULL AUTO_INCREMENT,
  `bucket` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL,
  `identifier` varchar(150) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL,
  `created_at` datetime NOT NULL,
  PRIMARY KEY (`hit_id`),
  KEY `idx_rate_limit_lookup` (`bucket`,`identifier`,`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

/*!40000 ALTER TABLE `rate_limit_hits` DISABLE KEYS */;
/*!40000 ALTER TABLE `rate_limit_hits` ENABLE KEYS */;
DROP TABLE IF EXISTS `role`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `role` (
  `role_id` int NOT NULL AUTO_INCREMENT,
  `role_name` enum('admin','doctor','secretary','patient') CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT NULL,
  `description` text CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci,
  PRIMARY KEY (`role_id`)
) ENGINE=InnoDB AUTO_INCREMENT=5 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

/*!40000 ALTER TABLE `role` DISABLE KEYS */;
INSERT INTO `role` VALUES (1,'admin','Administrator with full system access'),(2,'doctor','Doctor/Dentist - Can manage appointments and patient data'),(3,'secretary','Secretary/Receptionist - Can schedule appointments and manage front desk'),(4,'patient','Patient - Can view appointments and medical records');
/*!40000 ALTER TABLE `role` ENABLE KEYS */;
DROP TABLE IF EXISTS `schedule_reminders`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `schedule_reminders` (
  `reminder_id` int NOT NULL AUTO_INCREMENT,
  `patient_schedule_id` int NOT NULL,
  `remind_at` datetime NOT NULL,
  `channel` enum('email','sms','push') CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL DEFAULT 'email',
  `sent_at` datetime DEFAULT NULL,
  `payload` text CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci,
  PRIMARY KEY (`reminder_id`),
  KEY `patient_schedule_id` (`patient_schedule_id`),
  CONSTRAINT `fk_schedule_reminder` FOREIGN KEY (`patient_schedule_id`) REFERENCES `patient_schedules` (`schedule_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

/*!40000 ALTER TABLE `schedule_reminders` DISABLE KEYS */;
/*!40000 ALTER TABLE `schedule_reminders` ENABLE KEYS */;
DROP TABLE IF EXISTS `selected_services`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `selected_services` (
  `selected_service_id` int NOT NULL AUTO_INCREMENT,
  `appointment_id` int NOT NULL,
  `notes` text CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci,
  `created_at` datetime DEFAULT CURRENT_TIMESTAMP,
  `service_id` int NOT NULL,
  PRIMARY KEY (`selected_service_id`),
  KEY `fk_selected_services_appointment` (`appointment_id`),
  KEY `fk_selected_services_service` (`service_id`),
  CONSTRAINT `fk_selected_services_appointment` FOREIGN KEY (`appointment_id`) REFERENCES `appointments` (`appointment_id`) ON DELETE CASCADE,
  CONSTRAINT `fk_selected_services_service` FOREIGN KEY (`service_id`) REFERENCES `services` (`service_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

/*!40000 ALTER TABLE `selected_services` DISABLE KEYS */;
/*!40000 ALTER TABLE `selected_services` ENABLE KEYS */;
DROP TABLE IF EXISTS `services`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `services` (
  `service_id` int NOT NULL AUTO_INCREMENT,
  `service_name` varchar(200) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL,
  `initial_price` decimal(12,2) NOT NULL DEFAULT '0.00',
  `description` text CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci,
  `created_at` datetime DEFAULT CURRENT_TIMESTAMP,
  `added_by` int DEFAULT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT '1',
  `requires_schedule` tinyint(1) NOT NULL DEFAULT '0',
  `default_duration_minutes` int NOT NULL DEFAULT '30',
  PRIMARY KEY (`service_id`),
  KEY `fk_services_added_by` (`added_by`),
  CONSTRAINT `fk_services_added_by` FOREIGN KEY (`added_by`) REFERENCES `users` (`user_id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

/*!40000 ALTER TABLE `services` DISABLE KEYS */;
/*!40000 ALTER TABLE `services` ENABLE KEYS */;
DROP TABLE IF EXISTS `specialization`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `specialization` (
  `specialization_id` int NOT NULL AUTO_INCREMENT,
  `specialization_name` varchar(150) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL,
  `description` text CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci,
  PRIMARY KEY (`specialization_id`)
) ENGINE=InnoDB AUTO_INCREMENT=9 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

/*!40000 ALTER TABLE `specialization` DISABLE KEYS */;
INSERT INTO `specialization` VALUES (1,'General Dentistry','General dental care and treatments'),(2,'Orthodontics','Teeth alignment and braces'),(3,'Periodontics','Gum disease and dental implants'),(4,'Pedodontics','Pediatric dental care'),(5,'Endodontics','Root canal treatment'),(6,'Prosthodontics','Dentures and bridges'),(7,'Oral Surgery','Extraction and surgical procedures'),(8,'Cosmetic Dentistry','Whitening and aesthetic procedures');
/*!40000 ALTER TABLE `specialization` ENABLE KEYS */;
DROP TABLE IF EXISTS `system_logs`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `system_logs` (
  `system_log_id` int NOT NULL AUTO_INCREMENT,
  `user_id` int DEFAULT NULL,
  `action` varchar(120) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL,
  `entity_type` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT NULL,
  `entity_id` int DEFAULT NULL,
  `details` text CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci,
  `ip_address` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT NULL,
  `user_agent` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT NULL,
  `created_at` datetime DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`system_log_id`),
  KEY `fk_system_logs_user` (`user_id`),
  KEY `idx_system_logs_action_created` (`action`,`created_at`),
  CONSTRAINT `fk_system_logs_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`user_id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

/*!40000 ALTER TABLE `system_logs` DISABLE KEYS */;
/*!40000 ALTER TABLE `system_logs` ENABLE KEYS */;
DROP TABLE IF EXISTS `timeslot`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `timeslot` (
  `timeslot_id` int NOT NULL AUTO_INCREMENT,
  `doctor_id` int NOT NULL,
  `slot_date` date NOT NULL,
  `start_time` time NOT NULL,
  `end_time` time NOT NULL,
  `status` enum('available','booked','blocked','break') CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT 'available',
  `appointment_id` int DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`timeslot_id`),
  UNIQUE KEY `uq_doctor_slot` (`doctor_id`,`slot_date`,`start_time`),
  KEY `idx_timeslot_doc_date_status` (`doctor_id`,`slot_date`,`status`),
  KEY `idx_timeslot_appointment_id` (`appointment_id`),
  CONSTRAINT `fk_timeslot_appointment` FOREIGN KEY (`appointment_id`) REFERENCES `appointments` (`appointment_id`) ON DELETE SET NULL,
  CONSTRAINT `timeslot_ibfk_1` FOREIGN KEY (`doctor_id`) REFERENCES `users` (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

/*!40000 ALTER TABLE `timeslot` DISABLE KEYS */;
/*!40000 ALTER TABLE `timeslot` ENABLE KEYS */;
DROP TABLE IF EXISTS `timeslot_service`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `timeslot_service` (
  `id` int NOT NULL AUTO_INCREMENT,
  `service_id` int NOT NULL,
  `duration_minutes` int NOT NULL,
  `buffer_minutes` int DEFAULT '5',
  `slots_per_day_limit` int DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_service` (`service_id`),
  CONSTRAINT `timeslot_service_ibfk_1` FOREIGN KEY (`service_id`) REFERENCES `services` (`service_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

/*!40000 ALTER TABLE `timeslot_service` DISABLE KEYS */;
/*!40000 ALTER TABLE `timeslot_service` ENABLE KEYS */;
DROP TABLE IF EXISTS `tooth`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `tooth` (
  `tooth_id` int NOT NULL AUTO_INCREMENT,
  `tooth_number` varchar(10) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL,
  `upper_lower` tinyint(1) DEFAULT NULL,
  PRIMARY KEY (`tooth_id`)
) ENGINE=InnoDB AUTO_INCREMENT=33 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

/*!40000 ALTER TABLE `tooth` DISABLE KEYS */;
INSERT INTO `tooth` VALUES (1,'11',1),(2,'12',1),(3,'13',1),(4,'14',1),(5,'15',1),(6,'16',1),(7,'17',1),(8,'18',1),(9,'21',1),(10,'22',1),(11,'23',1),(12,'24',1),(13,'25',1),(14,'26',1),(15,'27',1),(16,'28',1),(17,'31',0),(18,'32',0),(19,'33',0),(20,'34',0),(21,'35',0),(22,'36',0),(23,'37',0),(24,'38',0),(25,'41',0),(26,'42',0),(27,'43',0),(28,'44',0),(29,'45',0),(30,'46',0),(31,'47',0),(32,'48',0);
/*!40000 ALTER TABLE `tooth` ENABLE KEYS */;
DROP TABLE IF EXISTS `tooth_status`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `tooth_status` (
  `tooth_status_id` int NOT NULL AUTO_INCREMENT,
  `record_id` int DEFAULT NULL,
  `tooth_selected` int NOT NULL,
  `status_type_id` int DEFAULT NULL,
  `tooth_surface` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT 'N/A',
  `status` varchar(10) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT NULL,
  `notes` text CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci,
  PRIMARY KEY (`tooth_status_id`),
  UNIQUE KEY `uq_tooth_status_record_tooth_surface` (`record_id`,`tooth_selected`,`tooth_surface`,`status`),
  KEY `fk_tooth_status_tooth` (`tooth_selected`),
  KEY `fk_tooth_status_type` (`status_type_id`),
  KEY `fk_tooth_status_record` (`record_id`),
  CONSTRAINT `fk_tooth_status_record` FOREIGN KEY (`record_id`) REFERENCES `clinical_records` (`record_id`) ON DELETE CASCADE,
  CONSTRAINT `fk_tooth_status_type` FOREIGN KEY (`status_type_id`) REFERENCES `tooth_status_type` (`status_type_id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

/*!40000 ALTER TABLE `tooth_status` DISABLE KEYS */;
/*!40000 ALTER TABLE `tooth_status` ENABLE KEYS */;
DROP TABLE IF EXISTS `tooth_status_type`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `tooth_status_type` (
  `status_type_id` int NOT NULL AUTO_INCREMENT,
  `label` varchar(120) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL,
  `category` enum('decay','restoration','missing','other') CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT 'other',
  `code` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL,
  `description` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT 'N/A',
  PRIMARY KEY (`status_type_id`),
  UNIQUE KEY `uk_chart_legend_code` (`code`)
) ENGINE=InnoDB AUTO_INCREMENT=15 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

/*!40000 ALTER TABLE `tooth_status_type` DISABLE KEYS */;
INSERT INTO `tooth_status_type` VALUES (1,'Dental Caries','decay','C','Dental Caries'),(2,'Caries — Vital Pulp Expose','decay','C1','Dental Caries with Vital Pulp Expose'),(3,'Caries — Non-vital Pulp Expose','decay','C2','Dental Caries with Non-vital Pulp Expose'),(4,'Indicated for Extraction','other','X','Indicated for Extraction'),(5,'Retained Root Fragment','missing','RF','Retained Root Fragment'),(6,'Amalgam Filling','restoration','Am','Amalgam Filling'),(7,'Silicate Filling','restoration','S','Silicate Filling'),(8,'Gold Crown','restoration','GC','Gold Crown'),(9,'Bridge Abutment','restoration','Ab1','Bridge Abutment'),(10,'Pontic','restoration','P','Pontic'),(11,'Gold Clasp','restoration','GCI','Gold Clasp'),(12,'Gold Inlays','restoration','GI','Gold Inlays'),(13,'Missing (Extraction)','missing','M','Missing due to Extraction'),(14,'Unerupted','other','Un','Unerupted');
/*!40000 ALTER TABLE `tooth_status_type` ENABLE KEYS */;
DROP TABLE IF EXISTS `treatment_materials`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `treatment_materials` (
  `material_id` int NOT NULL AUTO_INCREMENT,
  `record_id` int NOT NULL,
  `material_name` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL,
  `quantity` decimal(10,2) DEFAULT '1.00',
  `unit` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT 'N/A',
  `cost` decimal(12,2) DEFAULT NULL,
  `notes` text CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci,
  PRIMARY KEY (`material_id`),
  KEY `idx_tm_record` (`record_id`),
  CONSTRAINT `fk_tm_record` FOREIGN KEY (`record_id`) REFERENCES `clinical_records` (`record_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

/*!40000 ALTER TABLE `treatment_materials` DISABLE KEYS */;
/*!40000 ALTER TABLE `treatment_materials` ENABLE KEYS */;
DROP TABLE IF EXISTS `treatment_plans`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `treatment_plans` (
  `treatment_id` int NOT NULL AUTO_INCREMENT,
  `record_id` int NOT NULL,
  `service_id` int DEFAULT NULL,
  `tooth_number` int DEFAULT NULL,
  `description` text CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL,
  `status` enum('planned','in_progress','completed','cancelled') CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT 'planned',
  `estimated_cost` decimal(12,2) DEFAULT NULL,
  `created_at` datetime DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`treatment_id`),
  KEY `idx_tp_record` (`record_id`),
  KEY `idx_tp_service` (`service_id`),
  CONSTRAINT `fk_tp_record` FOREIGN KEY (`record_id`) REFERENCES `clinical_records` (`record_id`) ON DELETE CASCADE,
  CONSTRAINT `fk_tp_service` FOREIGN KEY (`service_id`) REFERENCES `services` (`service_id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

/*!40000 ALTER TABLE `treatment_plans` DISABLE KEYS */;
/*!40000 ALTER TABLE `treatment_plans` ENABLE KEYS */;
DROP TABLE IF EXISTS `user_logs`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `user_logs` (
  `user_log_id` int NOT NULL AUTO_INCREMENT,
  `user_id` int NOT NULL,
  `login_time` datetime DEFAULT NULL,
  `last_activity` datetime DEFAULT NULL,
  `logout_time` datetime DEFAULT NULL,
  `ip_address` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT NULL,
  `user_agent` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT NULL,
  PRIMARY KEY (`user_log_id`),
  KEY `fk_user_logs_user` (`user_id`),
  CONSTRAINT `fk_user_logs_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`user_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

/*!40000 ALTER TABLE `user_logs` DISABLE KEYS */;
/*!40000 ALTER TABLE `user_logs` ENABLE KEYS */;
DROP TABLE IF EXISTS `users`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `users` (
  `user_id` int NOT NULL AUTO_INCREMENT,
  `first_name` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL,
  `last_name` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL,
  `middle_name` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT 'N/A',
  `password_hash` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL,
  `date_of_birth` date DEFAULT NULL,
  `gender` varchar(20) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT 'N/A',
  `phone_number` varchar(30) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT NULL,
  `email` varchar(150) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT NULL,
  `address` text CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci,
  `is_active` tinyint(1) DEFAULT '1',
  `created_at` datetime DEFAULT CURRENT_TIMESTAMP,
  `role_id` int DEFAULT NULL,
  `added_by` int DEFAULT NULL,
  `must_change_password` tinyint(1) NOT NULL DEFAULT '0',
  `password_updated_at` datetime DEFAULT NULL,
  `failed_login_attempts` int NOT NULL DEFAULT '0',
  `locked_until` datetime DEFAULT NULL,
  `last_login_at` datetime DEFAULT NULL,
  `profile_image` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT NULL,
  `work_type` enum('regular','part_time') CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL DEFAULT 'regular',
  PRIMARY KEY (`user_id`),
  UNIQUE KEY `email` (`email`),
  KEY `fk_users_role` (`role_id`),
  KEY `fk_users_added_by` (`added_by`),
  CONSTRAINT `fk_users_added_by` FOREIGN KEY (`added_by`) REFERENCES `users` (`user_id`) ON DELETE SET NULL,
  CONSTRAINT `fk_users_role` FOREIGN KEY (`role_id`) REFERENCES `role` (`role_id`) ON DELETE SET NULL
) ENGINE=InnoDB AUTO_INCREMENT=8 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

/*!40000 ALTER TABLE `users` DISABLE KEYS */;
INSERT INTO `users` VALUES (1,'Coco','Martin','Dela Cruz','$2y$10$qkdR.zAPJXkeOPQcc6AlZOi843rNN8wULUA2HGgK3uEVGldx0mdc2','1980-01-15','female','1234567890','admin@gmail.com','Barangay 15 CDO',1,'2026-01-20 22:57:04',1,NULL,0,'2026-09-07 17:20:25',0,NULL,NULL,NULL,'regular'),(6,'Jennifer','Carie','Maria','$2y$10$y7/b.MfS7Zmz3N9u5EC6/ezYzwWuba.1lwhunkaNt3DqPUFRL1R1a','1985-07-19','female','+6391234567890','doctor@gmail.com','Barangay 15, CDO',1,'2026-09-07 17:17:34',2,NULL,0,'2026-09-07 17:20:51',0,NULL,NULL,NULL,'regular'),(7,'Secret','Degamo','Silva','$2y$10$iDlbeL476r.L3oGyk4OYfed/io/.wFE5/QxeCQp4cmGkf68qDHIBi','1994-02-11','other','+6391234567891','secretary@gmail.com','Lapasan CDO',1,'2026-09-07 17:18:49',3,NULL,0,'2026-09-07 17:20:00',0,NULL,NULL,NULL,'regular');
/*!40000 ALTER TABLE `users` ENABLE KEYS */;
/*!40103 SET TIME_ZONE=@OLD_TIME_ZONE */;

/*!40101 SET SQL_MODE=@OLD_SQL_MODE */;
/*!40014 SET FOREIGN_KEY_CHECKS=@OLD_FOREIGN_KEY_CHECKS */;
/*!40014 SET UNIQUE_CHECKS=@OLD_UNIQUE_CHECKS */;
/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
/*!40111 SET SQL_NOTES=@OLD_SQL_NOTES */;

