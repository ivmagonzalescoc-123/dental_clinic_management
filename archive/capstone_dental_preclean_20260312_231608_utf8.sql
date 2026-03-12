-- MariaDB dump 10.19  Distrib 10.4.32-MariaDB, for Win64 (AMD64)
--
-- Host: localhost    Database: capstone_dental
-- ------------------------------------------------------
-- Server version	10.4.32-MariaDB

/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;
/*!40103 SET @OLD_TIME_ZONE=@@TIME_ZONE */;
/*!40103 SET TIME_ZONE='+00:00' */;
/*!40014 SET @OLD_UNIQUE_CHECKS=@@UNIQUE_CHECKS, UNIQUE_CHECKS=0 */;
/*!40014 SET @OLD_FOREIGN_KEY_CHECKS=@@FOREIGN_KEY_CHECKS, FOREIGN_KEY_CHECKS=0 */;
/*!40101 SET @OLD_SQL_MODE=@@SQL_MODE, SQL_MODE='NO_AUTO_VALUE_ON_ZERO' */;
/*!40111 SET @OLD_SQL_NOTES=@@SQL_NOTES, SQL_NOTES=0 */;

--
-- Table structure for table `appointments`
--

DROP TABLE IF EXISTS `appointments`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `appointments` (
  `appointment_id` int(11) NOT NULL AUTO_INCREMENT,
  `appointment_date` date NOT NULL,
  `timeslot_id` int(11) DEFAULT NULL,
  `service_id` int(11) DEFAULT NULL,
  `reason` varchar(255) DEFAULT NULL,
  `is_online_appointment` tinyint(1) DEFAULT 0,
  `notes` text DEFAULT NULL,
  `status` enum('scheduled','confirmed','checked_in','in_progress','completed','cancelled','no_show','rescheduled') DEFAULT 'scheduled',
  `created_at` datetime DEFAULT current_timestamp(),
  `user_id` int(11) DEFAULT NULL,
  `rescheduled_from` int(11) DEFAULT NULL,
  `patient_id` int(11) NOT NULL,
  `is_emergency` tinyint(1) DEFAULT 0,
  PRIMARY KEY (`appointment_id`),
  KEY `fk_appointments_user` (`user_id`),
  KEY `fk_appointments_rescheduled_from` (`rescheduled_from`),
  KEY `fk_appointments_patient` (`patient_id`),
  KEY `timeslot_id` (`timeslot_id`),
  KEY `service_id` (`service_id`),
  CONSTRAINT `appointments_ibfk_1` FOREIGN KEY (`timeslot_id`) REFERENCES `timeslot` (`timeslot_id`) ON DELETE SET NULL,
  CONSTRAINT `appointments_ibfk_2` FOREIGN KEY (`service_id`) REFERENCES `services` (`service_id`) ON DELETE SET NULL,
  CONSTRAINT `fk_appointments_patient` FOREIGN KEY (`patient_id`) REFERENCES `patients` (`patient_id`) ON DELETE CASCADE,
  CONSTRAINT `fk_appointments_rescheduled_from` FOREIGN KEY (`rescheduled_from`) REFERENCES `appointments` (`appointment_id`) ON DELETE SET NULL,
  CONSTRAINT `fk_appointments_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`user_id`) ON DELETE SET NULL
) ENGINE=InnoDB AUTO_INCREMENT=33 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `appointments`
--

LOCK TABLES `appointments` WRITE;
/*!40000 ALTER TABLE `appointments` DISABLE KEYS */;
INSERT INTO `appointments` VALUES (20,'2026-02-25',NULL,NULL,'Tooth Ache',1,'no show','cancelled','2026-02-25 20:11:28',2,NULL,1,0),(22,'2026-02-25',NULL,NULL,'Test appointment for reminders',0,'sig balikbalik','cancelled','2026-02-25 20:59:34',2,NULL,1,0),(23,'2026-02-25',NULL,NULL,'Ouch',1,'\n[Clinical record submitted by doctor 2]','completed','2026-02-25 21:41:18',2,NULL,1,0),(24,'2026-02-25',NULL,NULL,'Sakit akong ngipon doc help',1,'\n[Clinical record submitted by doctor 2]','completed','2026-02-25 21:56:23',2,NULL,3,0),(28,'2026-02-27',NULL,NULL,'',0,'0','scheduled','2026-02-26 07:55:15',2,NULL,3,1),(29,'2026-02-26',NULL,NULL,'asdasd',0,'\n[Clinical record submitted by doctor 2]','completed','2026-02-26 08:01:10',2,NULL,2,1),(30,'2026-03-06',NULL,NULL,'general yearly consultation',0,'','in_progress','2026-03-06 09:01:29',2,NULL,1,0),(31,'2026-03-06',NULL,NULL,'test',0,'','in_progress','2026-03-06 09:14:26',2,NULL,3,0),(32,'2026-03-06',NULL,NULL,'asdasd',0,'','in_progress','2026-03-06 09:18:01',2,NULL,2,0);
/*!40000 ALTER TABLE `appointments` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `backup`
--

DROP TABLE IF EXISTS `backup`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `backup` (
  `backup_id` int(11) NOT NULL AUTO_INCREMENT,
  `user_id` int(11) DEFAULT NULL,
  `backup_date` datetime DEFAULT current_timestamp(),
  `file_name` varchar(255) DEFAULT NULL,
  `file_path` varchar(500) DEFAULT NULL,
  `file_size` decimal(12,2) DEFAULT NULL,
  PRIMARY KEY (`backup_id`),
  KEY `fk_backup_user` (`user_id`),
  CONSTRAINT `fk_backup_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`user_id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `backup`
--

LOCK TABLES `backup` WRITE;
/*!40000 ALTER TABLE `backup` DISABLE KEYS */;
/*!40000 ALTER TABLE `backup` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `billing`
--

DROP TABLE IF EXISTS `billing`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `billing` (
  `billing_id` int(11) NOT NULL AUTO_INCREMENT,
  `appointment_id` int(11) DEFAULT NULL,
  `user_id` int(11) DEFAULT NULL,
  `total_amount` decimal(12,2) NOT NULL DEFAULT 0.00,
  `created_at` datetime DEFAULT current_timestamp(),
  `status` enum('unpaid','partial','paid') NOT NULL DEFAULT 'unpaid',
  `processed_by` int(11) DEFAULT NULL,
  PRIMARY KEY (`billing_id`),
  UNIQUE KEY `appointment_id` (`appointment_id`),
  KEY `fk_billing_user` (`user_id`),
  KEY `fk_billing_processed_by` (`processed_by`),
  CONSTRAINT `fk_billing_appointment` FOREIGN KEY (`appointment_id`) REFERENCES `appointments` (`appointment_id`) ON DELETE CASCADE,
  CONSTRAINT `fk_billing_processed_by` FOREIGN KEY (`processed_by`) REFERENCES `users` (`user_id`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `fk_billing_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`user_id`) ON DELETE SET NULL
) ENGINE=InnoDB AUTO_INCREMENT=10 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `billing`
--

LOCK TABLES `billing` WRITE;
/*!40000 ALTER TABLE `billing` DISABLE KEYS */;
INSERT INTO `billing` VALUES (7,23,1,1000.00,'2026-02-25 21:45:12','paid',3),(8,24,3,1100.00,'2026-02-25 22:02:36','paid',3),(9,29,2,0.00,'2026-02-26 08:09:28','unpaid',NULL);
/*!40000 ALTER TABLE `billing` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `check_in_queue`
--

DROP TABLE IF EXISTS `check_in_queue`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `check_in_queue` (
  `checkin_id` int(11) NOT NULL AUTO_INCREMENT,
  `status` enum('waiting','called','in_progress','served','cancelled','no_show','late') DEFAULT 'waiting',
  `checkin_time` datetime DEFAULT current_timestamp(),
  `que_number` int(11) DEFAULT NULL,
  `appointment_id` int(11) DEFAULT NULL,
  `served_time` datetime DEFAULT NULL,
  `completed_time` datetime DEFAULT NULL,
  `is_emergency` tinyint(1) DEFAULT 0,
  `timeslot_id` int(11) DEFAULT NULL,
  `expected_time` time DEFAULT NULL,
  PRIMARY KEY (`checkin_id`),
  UNIQUE KEY `appointment_id` (`appointment_id`),
  KEY `timeslot_id` (`timeslot_id`),
  CONSTRAINT `check_in_queue_ibfk_1` FOREIGN KEY (`timeslot_id`) REFERENCES `timeslot` (`timeslot_id`),
  CONSTRAINT `fk_checkin_appointment` FOREIGN KEY (`appointment_id`) REFERENCES `appointments` (`appointment_id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=21 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `check_in_queue`
--

LOCK TABLES `check_in_queue` WRITE;
/*!40000 ALTER TABLE `check_in_queue` DISABLE KEYS */;
INSERT INTO `check_in_queue` VALUES (12,'served','2026-02-25 21:41:40',1,23,'2026-02-25 21:45:12','2026-02-25 21:45:12',0,NULL,NULL),(13,'served','2026-02-25 21:57:36',2,24,'2026-02-25 22:02:36','2026-02-25 22:02:36',0,NULL,NULL),(16,'','2026-02-26 07:58:40',1,28,'2026-02-26 08:07:53',NULL,1,NULL,NULL),(17,'served','2026-02-26 08:01:14',2,29,'2026-02-26 08:09:28','2026-02-26 08:09:28',1,NULL,NULL),(18,'','2026-03-06 09:01:36',1,30,'2026-03-06 09:02:43',NULL,0,NULL,NULL),(19,'','2026-03-06 09:14:31',2,31,'2026-03-06 09:14:39',NULL,0,NULL,NULL),(20,'','2026-03-06 09:18:05',3,32,'2026-03-06 09:18:15',NULL,0,NULL,NULL);
/*!40000 ALTER TABLE `check_in_queue` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `clinical_records`
--

DROP TABLE IF EXISTS `clinical_records`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `clinical_records` (
  `record_id` int(11) NOT NULL AUTO_INCREMENT,
  `appointment_id` int(11) NOT NULL,
  `schedule_id` int(11) DEFAULT NULL,
  `doctor_id` int(11) DEFAULT NULL,
  `patient_id` int(11) DEFAULT NULL,
  `summary` text DEFAULT NULL,
  `recommended_services` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`recommended_services`)),
  `treatment_plan` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`treatment_plan`)),
  `materials_used` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`materials_used`)),
  `odontogram` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`odontogram`)),
  `doctor_notes` text DEFAULT NULL,
  `follow_up_date` date DEFAULT NULL,
  `status` enum('in_progress','submitted','finalized') DEFAULT 'in_progress',
  `submitted_by` int(11) DEFAULT NULL,
  `submitted_at` datetime DEFAULT NULL,
  `images` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`images`)),
  `tooth_image` varchar(255) DEFAULT NULL,
  PRIMARY KEY (`record_id`),
  KEY `appointment_id` (`appointment_id`),
  KEY `schedule_id` (`schedule_id`),
  CONSTRAINT `fk_clinrec_schedule` FOREIGN KEY (`schedule_id`) REFERENCES `schedules` (`schedule_id`) ON DELETE SET NULL
) ENGINE=InnoDB AUTO_INCREMENT=10 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `clinical_records`
--

LOCK TABLES `clinical_records` WRITE;
/*!40000 ALTER TABLE `clinical_records` DISABLE KEYS */;
INSERT INTO `clinical_records` VALUES (7,23,15,2,1,NULL,'[1]',NULL,'[{\"name\":\"gloves and surgical masks\",\"qty\":1,\"price\":500}]',NULL,'No more issues',NULL,'submitted',2,'2026-02-25 21:45:12','[\"assets\\/uploads\\/clinical_images\\/23\\/1772027112_731870fc66c6.jpeg\"]','assets/uploads/clinical_images/23/1772027112_731870fc66c6.jpeg'),(8,24,17,2,3,NULL,'[1]',NULL,'[{\"name\":\"Gloves\",\"qty\":2,\"price\":50},{\"name\":\"SteelBrush\",\"qty\":1,\"price\":500}]',NULL,'None','2026-02-27','submitted',2,'2026-02-25 22:02:36','[\"assets\\/uploads\\/clinical_images\\/24\\/1772028156_f3600203914d.jpeg\"]','assets/uploads/clinical_images/24/1772028156_f3600203914d.jpeg'),(9,29,NULL,2,2,NULL,NULL,NULL,NULL,'{\"1\":[],\"2\":[],\"3\":[],\"4\":[],\"5\":[],\"6\":[],\"7\":[],\"8\":[],\"9\":[],\"10\":[],\"11\":[],\"12\":[],\"13\":[],\"14\":[],\"15\":[],\"16\":[],\"17\":[],\"18\":[],\"19\":[\"missing\"],\"20\":[],\"21\":[],\"22\":[],\"23\":[],\"24\":[],\"25\":[],\"26\":[],\"27\":[],\"28\":[],\"29\":[],\"30\":[],\"31\":[],\"32\":[]}','',NULL,'submitted',2,'2026-02-26 08:09:28',NULL,NULL);
/*!40000 ALTER TABLE `clinical_records` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `doctor_availability`
--

DROP TABLE IF EXISTS `doctor_availability`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `doctor_availability` (
  `availability_id` int(11) NOT NULL AUTO_INCREMENT,
  `user_id` int(11) NOT NULL,
  `weekday` tinyint(4) NOT NULL COMMENT '0=Sun..6=Sat',
  `start_time` time NOT NULL,
  `end_time` time NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`availability_id`),
  KEY `user_id` (`user_id`),
  CONSTRAINT `fk_avail_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`user_id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=28 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `doctor_availability`
--

LOCK TABLES `doctor_availability` WRITE;
/*!40000 ALTER TABLE `doctor_availability` DISABLE KEYS */;
INSERT INTO `doctor_availability` VALUES (1,2,1,'09:00:00','12:00:00','2026-03-12 12:23:00'),(2,2,1,'13:00:00','17:00:00','2026-03-12 12:23:00'),(3,2,2,'09:00:00','12:00:00','2026-03-12 12:23:00'),(4,2,2,'13:00:00','17:00:00','2026-03-12 12:23:00'),(5,2,3,'09:00:00','12:00:00','2026-03-12 12:23:00'),(6,2,3,'13:00:00','17:00:00','2026-03-12 12:23:00'),(7,2,4,'09:00:00','12:00:00','2026-03-12 12:23:00'),(8,2,4,'13:00:00','17:00:00','2026-03-12 12:23:00'),(9,2,5,'09:00:00','12:00:00','2026-03-12 12:23:00'),(10,2,5,'13:00:00','17:00:00','2026-03-12 12:23:00'),(26,4,2,'08:00:00','17:00:00','2026-03-12 15:09:54'),(27,4,3,'08:00:00','17:00:00','2026-03-12 15:09:54');
/*!40000 ALTER TABLE `doctor_availability` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `doctor_leaves`
--

DROP TABLE IF EXISTS `doctor_leaves`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `doctor_leaves` (
  `leave_id` int(11) NOT NULL AUTO_INCREMENT,
  `user_id` int(11) NOT NULL,
  `leave_start_date` date NOT NULL,
  `leave_end_date` date NOT NULL,
  `reason` varchar(255) DEFAULT NULL,
  `status` enum('pending','approved','rejected') NOT NULL DEFAULT 'approved',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`leave_id`),
  KEY `idx_doctor_leave_dates` (`user_id`,`leave_start_date`,`leave_end_date`),
  KEY `idx_doctor_leave_status` (`status`),
  CONSTRAINT `fk_doctor_leaves_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`user_id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=2 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `doctor_leaves`
--

LOCK TABLES `doctor_leaves` WRITE;
/*!40000 ALTER TABLE `doctor_leaves` DISABLE KEYS */;
INSERT INTO `doctor_leaves` VALUES (1,2,'2026-03-12','2026-03-13','family reunion','approved','2026-03-12 13:00:32');
/*!40000 ALTER TABLE `doctor_leaves` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `medical_history`
--

DROP TABLE IF EXISTS `medical_history`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `medical_history` (
  `history_id` int(11) NOT NULL AUTO_INCREMENT,
  `patients_id` int(11) NOT NULL,
  `history_type` varchar(150) DEFAULT NULL,
  `history_from` date DEFAULT NULL,
  `history_to` date DEFAULT NULL,
  `detailed_info` text DEFAULT NULL,
  PRIMARY KEY (`history_id`),
  KEY `fk_medical_history_patient` (`patients_id`),
  CONSTRAINT `fk_medical_history_patient` FOREIGN KEY (`patients_id`) REFERENCES `patients` (`patient_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `medical_history`
--

LOCK TABLES `medical_history` WRITE;
/*!40000 ALTER TABLE `medical_history` DISABLE KEYS */;
/*!40000 ALTER TABLE `medical_history` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `notifications`
--

DROP TABLE IF EXISTS `notifications`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `notifications` (
  `notification_id` int(11) NOT NULL AUTO_INCREMENT,
  `user_id` int(11) DEFAULT NULL,
  `title` varchar(255) NOT NULL,
  `body` text DEFAULT NULL,
  `link` varchar(255) DEFAULT NULL,
  `is_read` tinyint(1) DEFAULT 0,
  `created_at` datetime DEFAULT current_timestamp(),
  PRIMARY KEY (`notification_id`),
  KEY `user_id` (`user_id`),
  KEY `idx_notifications_user` (`user_id`),
  KEY `idx_notifications_is_read` (`is_read`),
  CONSTRAINT `fk_notifications_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`user_id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=4 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `notifications`
--

LOCK TABLES `notifications` WRITE;
/*!40000 ALTER TABLE `notifications` DISABLE KEYS */;
INSERT INTO `notifications` VALUES (1,1,'Upcoming appointment reminder','Appointment #22 is scheduled at 2026-02-25 21:59:34. Please be prepared.','/patient/patient.php?page=appointments&appointment_id=22',0,'2026-02-25 21:01:22'),(2,2,'Upcoming appointment reminder','Appointment #22 is scheduled at 2026-02-25 21:59:34. Please be prepared.','/patient/patient.php?page=appointments&appointment_id=22',1,'2026-02-25 21:01:22'),(3,3,'Upcoming appointment reminder','Appointment #22 is scheduled at 2026-02-25 21:59:34. Please be prepared.','/patient/patient.php?page=appointments&appointment_id=22',0,'2026-02-25 21:01:22');
/*!40000 ALTER TABLE `notifications` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `patient_arrivals`
--

DROP TABLE IF EXISTS `patient_arrivals`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `patient_arrivals` (
  `arrival_id` int(11) NOT NULL AUTO_INCREMENT,
  `appointment_id` int(11) NOT NULL,
  `timeslot_id` int(11) NOT NULL,
  `expected_time` time NOT NULL,
  `arrived_at` datetime DEFAULT NULL,
  `status` enum('pending','arrived','late','no_show') DEFAULT 'pending',
  `marked_by` int(11) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`arrival_id`),
  UNIQUE KEY `uq_appointment` (`appointment_id`),
  KEY `timeslot_id` (`timeslot_id`),
  KEY `marked_by` (`marked_by`),
  CONSTRAINT `patient_arrivals_ibfk_1` FOREIGN KEY (`appointment_id`) REFERENCES `appointments` (`appointment_id`) ON DELETE CASCADE,
  CONSTRAINT `patient_arrivals_ibfk_2` FOREIGN KEY (`timeslot_id`) REFERENCES `timeslot` (`timeslot_id`) ON DELETE CASCADE,
  CONSTRAINT `patient_arrivals_ibfk_3` FOREIGN KEY (`marked_by`) REFERENCES `users` (`user_id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `patient_arrivals`
--

LOCK TABLES `patient_arrivals` WRITE;
/*!40000 ALTER TABLE `patient_arrivals` DISABLE KEYS */;
/*!40000 ALTER TABLE `patient_arrivals` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `patient_profiling_audit`
--

DROP TABLE IF EXISTS `patient_profiling_audit`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `patient_profiling_audit` (
  `audit_id` int(11) NOT NULL AUTO_INCREMENT,
  `patient_id` int(11) NOT NULL,
  `profiling` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`profiling`)),
  `changed_by` int(11) DEFAULT NULL,
  `changed_at` datetime DEFAULT current_timestamp(),
  PRIMARY KEY (`audit_id`),
  KEY `patient_id` (`patient_id`)
) ENGINE=InnoDB AUTO_INCREMENT=2 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `patient_profiling_audit`
--

LOCK TABLES `patient_profiling_audit` WRITE;
/*!40000 ALTER TABLE `patient_profiling_audit` DISABLE KEYS */;
INSERT INTO `patient_profiling_audit` VALUES (1,1,'{\"medical_history\":\"Highblood\",\"medications\":\"Penicilin\",\"allergies\":\"No\",\"smoking\":\"current\",\"alcohol\":\"social\",\"asa\":\"II\",\"last_visit\":\"\"}',3,'2026-03-06 09:02:22');
/*!40000 ALTER TABLE `patient_profiling_audit` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `patients`
--

DROP TABLE IF EXISTS `patients`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `patients` (
  `patient_id` int(11) NOT NULL AUTO_INCREMENT,
  `first_name` varchar(100) NOT NULL,
  `last_name` varchar(100) NOT NULL,
  `middle_name` varchar(100) DEFAULT NULL,
  `password_hash` varchar(255) DEFAULT NULL,
  `date_of_birth` date DEFAULT NULL,
  `gender` varchar(20) DEFAULT NULL,
  `phone_number` varchar(30) DEFAULT NULL,
  `email` varchar(150) DEFAULT NULL,
  `address` text DEFAULT NULL,
  `created_at` datetime DEFAULT current_timestamp(),
  `added_by` int(11) DEFAULT NULL,
  `must_change_password` tinyint(1) NOT NULL DEFAULT 0,
  `password_updated_at` datetime DEFAULT NULL,
  `failed_login_attempts` int(11) NOT NULL DEFAULT 0,
  `locked_until` datetime DEFAULT NULL,
  `last_login_at` datetime DEFAULT NULL,
  `profiling` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`profiling`)),
  PRIMARY KEY (`patient_id`),
  KEY `fk_patients_added_by` (`added_by`),
  CONSTRAINT `fk_patients_added_by` FOREIGN KEY (`added_by`) REFERENCES `users` (`user_id`) ON DELETE SET NULL
) ENGINE=InnoDB AUTO_INCREMENT=4 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `patients`
--

LOCK TABLES `patients` WRITE;
/*!40000 ALTER TABLE `patients` DISABLE KEYS */;
INSERT INTO `patients` VALUES (1,'Paul','Gonzales','Gomez','$2y$10$UAYsvpNGkf9KjYAjLyC/juzYtX3SLiUzrH/7DaFxLpklQi0GM/RkK','1990-01-01','Unspecified','0927562616','patient1@example.com','Brgy 7 CDO','2026-01-23 15:50:00',NULL,0,'2026-03-12 21:02:11',0,NULL,NULL,'{\"medical_history\":\"Highblood\",\"medications\":\"Penicilin\",\"allergies\":\"No\",\"smoking\":\"current\",\"alcohol\":\"social\",\"asa\":\"II\",\"last_visit\":\"\"}'),(2,'Harry','Tan','Richard','$2y$10$t6wRZXTV2Z2XaT2eUKPRw.KMirGeOcgOxY..gPLIJTkN1QvHaI.g2','1985-02-02','Unspecified','0920912616','patient2@example.com','Gusa CDO','2026-01-23 15:50:00',NULL,0,'2026-03-12 21:02:11',0,NULL,NULL,NULL),(3,'Ivan Louiz','Gonzales','','$2y$10$KlbloUvtx9.ixRCKq5qQGea0ZYURHhepWZ0UfrPNYIGFTzlG3yBoO','1559-11-09','Male','09123456789','ivan@gmail.com','Unahan Agusan CDO','2026-02-25 21:55:55',NULL,0,'2026-03-12 21:02:11',0,NULL,NULL,NULL);
/*!40000 ALTER TABLE `patients` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `prescriptions`
--

DROP TABLE IF EXISTS `prescriptions`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `prescriptions` (
  `prescription_id` int(11) NOT NULL AUTO_INCREMENT,
  `appointment_id` int(11) DEFAULT NULL,
  `prescription_date` date DEFAULT NULL,
  `medicine_name` varchar(255) DEFAULT NULL,
  `dosage` varchar(100) DEFAULT NULL,
  `medicine_brand` varchar(150) DEFAULT NULL,
  `duration` varchar(100) DEFAULT NULL,
  `selected_treatment_id` int(11) DEFAULT NULL,
  `notes` text DEFAULT NULL,
  PRIMARY KEY (`prescription_id`),
  KEY `fk_prescriptions_appointment` (`appointment_id`),
  KEY `fk_prescriptions_treatment` (`selected_treatment_id`),
  CONSTRAINT `fk_prescriptions_appointment` FOREIGN KEY (`appointment_id`) REFERENCES `appointments` (`appointment_id`) ON DELETE SET NULL,
  CONSTRAINT `fk_prescriptions_treatment` FOREIGN KEY (`selected_treatment_id`) REFERENCES `selected_treatments` (`selected_treatment_id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `prescriptions`
--

LOCK TABLES `prescriptions` WRITE;
/*!40000 ALTER TABLE `prescriptions` DISABLE KEYS */;
/*!40000 ALTER TABLE `prescriptions` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `role`
--

DROP TABLE IF EXISTS `role`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `role` (
  `role_id` int(11) NOT NULL AUTO_INCREMENT,
  `role_name` enum('admin','doctor','secretary','patient') DEFAULT NULL,
  `description` text DEFAULT NULL,
  PRIMARY KEY (`role_id`)
) ENGINE=InnoDB AUTO_INCREMENT=5 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `role`
--

LOCK TABLES `role` WRITE;
/*!40000 ALTER TABLE `role` DISABLE KEYS */;
INSERT INTO `role` VALUES (1,'admin','Administrator with full system access'),(2,'doctor','Doctor/Dentist - Can manage appointments and patient data'),(3,'secretary','Secretary/Receptionist - Can schedule appointments and manage front desk'),(4,'patient','Patient - Can view appointments and medical records');
/*!40000 ALTER TABLE `role` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `schedule`
--

DROP TABLE IF EXISTS `schedule`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `schedule` (
  `schedule_id` int(11) NOT NULL AUTO_INCREMENT,
  `status` enum('active','inactive','suspended') DEFAULT 'active',
  `available_from` time NOT NULL,
  `available_to` time NOT NULL,
  `day_of_week` varchar(20) NOT NULL,
  `user_id` int(11) DEFAULT NULL,
  PRIMARY KEY (`schedule_id`),
  KEY `fk_schedule_user` (`user_id`),
  CONSTRAINT `fk_schedule_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`user_id`) ON DELETE SET NULL
) ENGINE=InnoDB AUTO_INCREMENT=22 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `schedule`
--

LOCK TABLES `schedule` WRITE;
/*!40000 ALTER TABLE `schedule` DISABLE KEYS */;
INSERT INTO `schedule` VALUES (8,'active','09:00:00','17:00:00','Monday',2),(9,'active','09:00:00','17:00:00','Tuesday',2),(10,'active','09:00:00','17:00:00','Wednesday',2),(11,'active','09:00:00','17:00:00','Thursday',2),(12,'active','09:00:00','17:00:00','Friday',2),(13,'active','09:00:00','14:00:00','Saturday',2),(14,'inactive','10:00:00','14:00:00','Sunday',2),(15,'active','08:00:00','17:00:00','Monday',3),(16,'active','08:00:00','17:00:00','Tuesday',3),(17,'active','08:00:00','17:00:00','Wednesday',3),(18,'active','08:00:00','17:00:00','Thursday',3),(19,'active','08:00:00','17:00:00','Friday',3),(20,'active','08:00:00','14:00:00','Saturday',3),(21,'inactive','10:00:00','14:00:00','Sunday',3);
/*!40000 ALTER TABLE `schedule` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `schedule_reminders`
--

DROP TABLE IF EXISTS `schedule_reminders`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `schedule_reminders` (
  `reminder_id` int(11) NOT NULL AUTO_INCREMENT,
  `schedule_id` int(11) NOT NULL,
  `remind_at` datetime NOT NULL,
  `channel` enum('email','sms','push') NOT NULL DEFAULT 'email',
  `sent_at` datetime DEFAULT NULL,
  `payload` text DEFAULT NULL,
  PRIMARY KEY (`reminder_id`),
  KEY `schedule_id` (`schedule_id`),
  CONSTRAINT `fk_schedule_reminder` FOREIGN KEY (`schedule_id`) REFERENCES `schedules` (`schedule_id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=8 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `schedule_reminders`
--

LOCK TABLES `schedule_reminders` WRITE;
/*!40000 ALTER TABLE `schedule_reminders` DISABLE KEYS */;
INSERT INTO `schedule_reminders` VALUES (6,14,'2026-02-25 20:59:34','email','2026-02-25 21:01:22','{\"test\": \"true\"}'),(7,17,'2026-02-26 09:00:00','',NULL,'{\"appointment_id\":24,\"schedule_id\":17}');
/*!40000 ALTER TABLE `schedule_reminders` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `schedules`
--

DROP TABLE IF EXISTS `schedules`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `schedules` (
  `schedule_id` int(11) NOT NULL AUTO_INCREMENT,
  `appointment_id` int(11) NOT NULL,
  `user_id` int(11) DEFAULT NULL COMMENT 'doctor assigned',
  `scheduled_start` datetime NOT NULL,
  `scheduled_end` datetime DEFAULT NULL,
  `status` enum('scheduled','rescheduled','cancelled','completed') DEFAULT 'scheduled',
  `priority` tinyint(4) DEFAULT 1,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT NULL ON UPDATE current_timestamp(),
  PRIMARY KEY (`schedule_id`),
  KEY `appointment_id` (`appointment_id`),
  KEY `user_id` (`user_id`),
  CONSTRAINT `fk_schedules_appointment` FOREIGN KEY (`appointment_id`) REFERENCES `appointments` (`appointment_id`) ON DELETE CASCADE,
  CONSTRAINT `fk_schedules_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`user_id`) ON DELETE SET NULL
) ENGINE=InnoDB AUTO_INCREMENT=22 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `schedules`
--

LOCK TABLES `schedules` WRITE;
/*!40000 ALTER TABLE `schedules` DISABLE KEYS */;
INSERT INTO `schedules` VALUES (14,22,2,'2026-02-25 21:59:34','2026-02-25 22:29:34','scheduled',1,'2026-02-25 12:59:34',NULL),(15,23,2,'2026-02-25 13:42:20',NULL,'',1,'2026-02-25 13:42:20',NULL),(16,24,2,'2026-02-25 13:58:16',NULL,'',1,'2026-02-25 13:58:16',NULL),(17,24,2,'2026-02-27 09:00:00','2026-02-27 09:30:00','scheduled',1,'2026-02-25 14:02:36',NULL),(18,28,2,'2026-02-26 08:07:53',NULL,'',1,'2026-02-26 00:07:53',NULL),(19,30,2,'2026-03-06 09:02:43',NULL,'',1,'2026-03-06 01:02:43',NULL),(20,31,2,'2026-03-06 09:14:39',NULL,'',1,'2026-03-06 01:14:39',NULL),(21,32,2,'2026-03-06 09:18:15',NULL,'',1,'2026-03-06 01:18:15',NULL);
/*!40000 ALTER TABLE `schedules` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `selected_services`
--

DROP TABLE IF EXISTS `selected_services`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `selected_services` (
  `selected_service_id` int(11) NOT NULL AUTO_INCREMENT,
  `appointment_id` int(11) NOT NULL,
  `notes` text DEFAULT NULL,
  `created_at` datetime DEFAULT current_timestamp(),
  `service_id` int(11) NOT NULL,
  PRIMARY KEY (`selected_service_id`),
  KEY `fk_selected_services_appointment` (`appointment_id`),
  KEY `fk_selected_services_service` (`service_id`),
  CONSTRAINT `fk_selected_services_appointment` FOREIGN KEY (`appointment_id`) REFERENCES `appointments` (`appointment_id`) ON DELETE CASCADE,
  CONSTRAINT `fk_selected_services_service` FOREIGN KEY (`service_id`) REFERENCES `services` (`service_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `selected_services`
--

LOCK TABLES `selected_services` WRITE;
/*!40000 ALTER TABLE `selected_services` DISABLE KEYS */;
/*!40000 ALTER TABLE `selected_services` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `selected_treatments`
--

DROP TABLE IF EXISTS `selected_treatments`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `selected_treatments` (
  `selected_treatment_id` int(11) NOT NULL AUTO_INCREMENT,
  `treatment_id` int(11) NOT NULL,
  `selected_service_id` int(11) DEFAULT NULL,
  `status` enum('pending','in_progress','completed','cancelled','declined') DEFAULT 'pending',
  `notes` text DEFAULT NULL,
  `created_at` datetime DEFAULT current_timestamp(),
  PRIMARY KEY (`selected_treatment_id`),
  KEY `fk_selected_treatments_treatment` (`treatment_id`),
  KEY `fk_selected_treatments_service` (`selected_service_id`),
  CONSTRAINT `fk_selected_treatments_service` FOREIGN KEY (`selected_service_id`) REFERENCES `selected_services` (`selected_service_id`) ON DELETE CASCADE,
  CONSTRAINT `fk_selected_treatments_treatment` FOREIGN KEY (`treatment_id`) REFERENCES `treatments` (`treatment_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `selected_treatments`
--

LOCK TABLES `selected_treatments` WRITE;
/*!40000 ALTER TABLE `selected_treatments` DISABLE KEYS */;
/*!40000 ALTER TABLE `selected_treatments` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `services`
--

DROP TABLE IF EXISTS `services`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `services` (
  `service_id` int(11) NOT NULL AUTO_INCREMENT,
  `service_name` varchar(200) NOT NULL,
  `initial_price` decimal(12,2) NOT NULL DEFAULT 0.00,
  `description` text DEFAULT NULL,
  `created_at` datetime DEFAULT current_timestamp(),
  `added_by` int(11) DEFAULT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `requires_schedule` tinyint(1) NOT NULL DEFAULT 0,
  `default_duration_minutes` int(11) NOT NULL DEFAULT 30,
  PRIMARY KEY (`service_id`),
  KEY `fk_services_added_by` (`added_by`),
  CONSTRAINT `fk_services_added_by` FOREIGN KEY (`added_by`) REFERENCES `users` (`user_id`) ON DELETE SET NULL
) ENGINE=InnoDB AUTO_INCREMENT=11 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `services`
--

LOCK TABLES `services` WRITE;
/*!40000 ALTER TABLE `services` DISABLE KEYS */;
INSERT INTO `services` VALUES (1,'Teeth Cleaning',500.00,'Professional teeth cleaning and scaling','2026-01-23 15:54:14',1,1,1,30),(2,'Root Canal Treatment',3000.00,'Root canal therapy for damaged teeth','2026-01-23 15:54:14',1,1,1,120),(3,'Crown Placement',5000.00,'Dental crown installation','2026-01-23 15:54:14',1,1,1,90),(4,'Filling',800.00,'Tooth filling for cavities','2026-01-23 15:54:14',1,1,1,45),(5,'Extraction',1500.00,'Tooth extraction','2026-01-23 15:54:14',1,1,1,60),(6,'Whitening',2000.00,'Professional teeth whitening','2026-01-23 15:54:14',1,1,0,30),(7,'Orthodontic Consultation',1000.00,'Braces and alignment consultation','2026-01-23 15:54:14',1,1,0,30),(8,'Denture Fitting',4000.00,'Partial or complete denture fitting','2026-01-23 15:54:14',1,1,1,180),(9,'Implant Consultation',2000.00,'Dental implant consultation and planning','2026-01-23 15:54:14',1,1,1,120),(10,'Periodic Checkup',300.00,'Regular dental examination','2026-01-23 15:54:14',1,1,0,30);
/*!40000 ALTER TABLE `services` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `specialization`
--

DROP TABLE IF EXISTS `specialization`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `specialization` (
  `specialization_id` int(11) NOT NULL AUTO_INCREMENT,
  `specialization_name` varchar(150) NOT NULL,
  `description` text DEFAULT NULL,
  PRIMARY KEY (`specialization_id`)
) ENGINE=InnoDB AUTO_INCREMENT=9 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `specialization`
--

LOCK TABLES `specialization` WRITE;
/*!40000 ALTER TABLE `specialization` DISABLE KEYS */;
INSERT INTO `specialization` VALUES (1,'General Dentistry','General dental care and treatments'),(2,'Orthodontics','Teeth alignment and braces'),(3,'Periodontics','Gum disease and dental implants'),(4,'Pedodontics','Pediatric dental care'),(5,'Endodontics','Root canal treatment'),(6,'Prosthodontics','Dentures and bridges'),(7,'Oral Surgery','Extraction and surgical procedures'),(8,'Cosmetic Dentistry','Whitening and aesthetic procedures');
/*!40000 ALTER TABLE `specialization` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `timeslot`
--

DROP TABLE IF EXISTS `timeslot`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `timeslot` (
  `timeslot_id` int(11) NOT NULL AUTO_INCREMENT,
  `doctor_id` int(11) NOT NULL,
  `slot_date` date NOT NULL,
  `start_time` time NOT NULL,
  `end_time` time NOT NULL,
  `status` enum('available','booked','blocked','break') DEFAULT 'available',
  `appointment_id` int(11) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`timeslot_id`),
  UNIQUE KEY `uq_doctor_slot` (`doctor_id`,`slot_date`,`start_time`),
  KEY `appointment_id` (`appointment_id`),
  KEY `idx_timeslot_doc_date_status` (`doctor_id`,`slot_date`,`status`),
  CONSTRAINT `timeslot_ibfk_1` FOREIGN KEY (`doctor_id`) REFERENCES `users` (`user_id`),
  CONSTRAINT `timeslot_ibfk_2` FOREIGN KEY (`appointment_id`) REFERENCES `appointments` (`appointment_id`) ON DELETE SET NULL
) ENGINE=InnoDB AUTO_INCREMENT=1376 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `timeslot`
--

LOCK TABLES `timeslot` WRITE;
/*!40000 ALTER TABLE `timeslot` DISABLE KEYS */;
INSERT INTO `timeslot` VALUES (1,2,'2026-03-12','09:00:00','12:00:00','available',NULL,'2026-03-12 12:24:10'),(2,2,'2026-03-12','13:00:00','17:00:00','available',NULL,'2026-03-12 12:24:10'),(3,2,'2026-03-13','09:00:00','12:00:00','available',NULL,'2026-03-12 12:24:10'),(4,2,'2026-03-13','13:00:00','17:00:00','available',NULL,'2026-03-12 12:24:10'),(5,2,'2026-03-16','09:00:00','12:00:00','available',NULL,'2026-03-12 12:24:10'),(6,2,'2026-03-16','13:00:00','17:00:00','available',NULL,'2026-03-12 12:24:10'),(7,2,'2026-03-17','09:00:00','12:00:00','available',NULL,'2026-03-12 12:24:10'),(8,2,'2026-03-17','13:00:00','17:00:00','available',NULL,'2026-03-12 12:24:10'),(9,2,'2026-03-18','09:00:00','12:00:00','available',NULL,'2026-03-12 12:24:10'),(10,2,'2026-03-18','13:00:00','17:00:00','available',NULL,'2026-03-12 12:24:10'),(11,2,'2026-03-19','09:00:00','12:00:00','available',NULL,'2026-03-12 12:24:10'),(12,2,'2026-03-19','13:00:00','17:00:00','available',NULL,'2026-03-12 12:24:10'),(13,2,'2026-03-20','09:00:00','12:00:00','available',NULL,'2026-03-12 12:24:10'),(14,2,'2026-03-20','13:00:00','17:00:00','available',NULL,'2026-03-12 12:24:10'),(15,2,'2026-03-23','09:00:00','12:00:00','available',NULL,'2026-03-12 12:24:10'),(16,2,'2026-03-23','13:00:00','17:00:00','available',NULL,'2026-03-12 12:24:10'),(17,2,'2026-03-24','09:00:00','12:00:00','available',NULL,'2026-03-12 12:24:10'),(18,2,'2026-03-24','13:00:00','17:00:00','available',NULL,'2026-03-12 12:24:10'),(19,2,'2026-03-25','09:00:00','12:00:00','available',NULL,'2026-03-12 12:24:10'),(20,2,'2026-03-25','13:00:00','17:00:00','available',NULL,'2026-03-12 12:24:10'),(21,2,'2026-03-26','09:00:00','12:00:00','available',NULL,'2026-03-12 12:24:10'),(22,2,'2026-03-26','13:00:00','17:00:00','available',NULL,'2026-03-12 12:24:10'),(23,2,'2026-03-27','09:00:00','12:00:00','available',NULL,'2026-03-12 12:24:10'),(24,2,'2026-03-27','13:00:00','17:00:00','available',NULL,'2026-03-12 12:24:10'),(25,2,'2026-03-30','09:00:00','12:00:00','available',NULL,'2026-03-12 12:24:10'),(26,2,'2026-03-30','13:00:00','17:00:00','available',NULL,'2026-03-12 12:24:10'),(27,2,'2026-03-31','09:00:00','12:00:00','available',NULL,'2026-03-12 12:24:10'),(28,2,'2026-03-31','13:00:00','17:00:00','available',NULL,'2026-03-12 12:24:10'),(29,2,'2026-04-01','09:00:00','12:00:00','available',NULL,'2026-03-12 12:24:10'),(30,2,'2026-04-01','13:00:00','17:00:00','available',NULL,'2026-03-12 12:24:10'),(31,2,'2026-04-02','09:00:00','12:00:00','available',NULL,'2026-03-12 12:24:10'),(32,2,'2026-04-02','13:00:00','17:00:00','available',NULL,'2026-03-12 12:24:10'),(33,2,'2026-04-03','09:00:00','12:00:00','available',NULL,'2026-03-12 12:24:10'),(34,2,'2026-04-03','13:00:00','17:00:00','available',NULL,'2026-03-12 12:24:10'),(35,2,'2026-04-06','09:00:00','12:00:00','available',NULL,'2026-03-12 12:24:10'),(36,2,'2026-04-06','13:00:00','17:00:00','available',NULL,'2026-03-12 12:24:10'),(37,2,'2026-04-07','09:00:00','12:00:00','available',NULL,'2026-03-12 12:24:10'),(38,2,'2026-04-07','13:00:00','17:00:00','available',NULL,'2026-03-12 12:24:10'),(39,2,'2026-04-08','09:00:00','12:00:00','available',NULL,'2026-03-12 12:24:10'),(40,2,'2026-04-08','13:00:00','17:00:00','available',NULL,'2026-03-12 12:24:10'),(41,2,'2026-04-09','09:00:00','12:00:00','available',NULL,'2026-03-12 12:24:10'),(42,2,'2026-04-09','13:00:00','17:00:00','available',NULL,'2026-03-12 12:24:10'),(43,2,'2026-04-10','09:00:00','12:00:00','available',NULL,'2026-03-12 12:24:10'),(44,2,'2026-04-10','13:00:00','17:00:00','available',NULL,'2026-03-12 12:24:10'),(45,2,'2026-04-13','09:00:00','12:00:00','available',NULL,'2026-03-12 12:24:10'),(46,2,'2026-04-13','13:00:00','17:00:00','available',NULL,'2026-03-12 12:24:10'),(47,2,'2026-04-14','09:00:00','12:00:00','available',NULL,'2026-03-12 12:24:10'),(48,2,'2026-04-14','13:00:00','17:00:00','available',NULL,'2026-03-12 12:24:10'),(49,2,'2026-04-15','09:00:00','12:00:00','available',NULL,'2026-03-12 12:24:10'),(50,2,'2026-04-15','13:00:00','17:00:00','available',NULL,'2026-03-12 12:24:10'),(51,2,'2026-04-16','09:00:00','12:00:00','available',NULL,'2026-03-12 12:24:10'),(52,2,'2026-04-16','13:00:00','17:00:00','available',NULL,'2026-03-12 12:24:10'),(53,2,'2026-04-17','09:00:00','12:00:00','available',NULL,'2026-03-12 12:24:10'),(54,2,'2026-04-17','13:00:00','17:00:00','available',NULL,'2026-03-12 12:24:10'),(55,2,'2026-04-20','09:00:00','12:00:00','available',NULL,'2026-03-12 12:24:10'),(56,2,'2026-04-20','13:00:00','17:00:00','available',NULL,'2026-03-12 12:24:10'),(57,2,'2026-04-21','09:00:00','12:00:00','available',NULL,'2026-03-12 12:24:10'),(58,2,'2026-04-21','13:00:00','17:00:00','available',NULL,'2026-03-12 12:24:10'),(59,2,'2026-04-22','09:00:00','12:00:00','available',NULL,'2026-03-12 12:24:10'),(60,2,'2026-04-22','13:00:00','17:00:00','available',NULL,'2026-03-12 12:24:10'),(61,2,'2026-04-23','09:00:00','12:00:00','available',NULL,'2026-03-12 12:24:10'),(62,2,'2026-04-23','13:00:00','17:00:00','available',NULL,'2026-03-12 12:24:10'),(63,2,'2026-04-24','09:00:00','12:00:00','available',NULL,'2026-03-12 12:24:10'),(64,2,'2026-04-24','13:00:00','17:00:00','available',NULL,'2026-03-12 12:24:10'),(128,2,'2026-03-14','08:00:00','08:20:00','available',NULL,'2026-03-12 14:54:41'),(129,2,'2026-03-14','08:20:00','08:40:00','available',NULL,'2026-03-12 14:54:41'),(130,2,'2026-03-14','08:40:00','09:00:00','available',NULL,'2026-03-12 14:54:41'),(131,2,'2026-03-14','09:00:00','09:20:00','available',NULL,'2026-03-12 14:54:41'),(132,2,'2026-03-14','09:20:00','09:40:00','available',NULL,'2026-03-12 14:54:41'),(133,2,'2026-03-14','09:40:00','10:00:00','available',NULL,'2026-03-12 14:54:41'),(134,2,'2026-03-14','10:00:00','10:20:00','available',NULL,'2026-03-12 14:54:41'),(135,2,'2026-03-14','10:20:00','10:40:00','available',NULL,'2026-03-12 14:54:41'),(136,2,'2026-03-14','10:40:00','11:00:00','available',NULL,'2026-03-12 14:54:41'),(137,2,'2026-03-14','11:00:00','11:20:00','available',NULL,'2026-03-12 14:54:41'),(138,2,'2026-03-14','11:20:00','11:40:00','available',NULL,'2026-03-12 14:54:41'),(139,2,'2026-03-14','11:40:00','12:00:00','available',NULL,'2026-03-12 14:54:41'),(140,2,'2026-03-14','13:00:00','13:20:00','available',NULL,'2026-03-12 14:54:41'),(141,2,'2026-03-14','13:20:00','13:40:00','available',NULL,'2026-03-12 14:54:41'),(142,2,'2026-03-14','13:40:00','14:00:00','available',NULL,'2026-03-12 14:54:41'),(143,2,'2026-03-14','14:00:00','14:20:00','available',NULL,'2026-03-12 14:54:41'),(144,2,'2026-03-14','14:20:00','14:40:00','available',NULL,'2026-03-12 14:54:41'),(145,2,'2026-03-14','14:40:00','15:00:00','available',NULL,'2026-03-12 14:54:41'),(146,2,'2026-03-14','15:00:00','15:20:00','available',NULL,'2026-03-12 14:54:41'),(147,2,'2026-03-14','15:20:00','15:40:00','available',NULL,'2026-03-12 14:54:41'),(148,2,'2026-03-14','15:40:00','16:00:00','available',NULL,'2026-03-12 14:54:41'),(149,2,'2026-03-14','16:00:00','16:20:00','available',NULL,'2026-03-12 14:54:41'),(150,2,'2026-03-14','16:20:00','16:40:00','available',NULL,'2026-03-12 14:54:41'),(151,2,'2026-03-14','16:40:00','17:00:00','available',NULL,'2026-03-12 14:54:41'),(152,2,'2026-03-16','08:00:00','08:20:00','available',NULL,'2026-03-12 14:54:41'),(153,2,'2026-03-16','08:20:00','08:40:00','available',NULL,'2026-03-12 14:54:41'),(154,2,'2026-03-16','08:40:00','09:00:00','available',NULL,'2026-03-12 14:54:41'),(156,2,'2026-03-16','09:20:00','09:40:00','available',NULL,'2026-03-12 14:54:41'),(157,2,'2026-03-16','09:40:00','10:00:00','available',NULL,'2026-03-12 14:54:41'),(158,2,'2026-03-16','10:00:00','10:20:00','available',NULL,'2026-03-12 14:54:41'),(159,2,'2026-03-16','10:20:00','10:40:00','available',NULL,'2026-03-12 14:54:41'),(160,2,'2026-03-16','10:40:00','11:00:00','available',NULL,'2026-03-12 14:54:41'),(161,2,'2026-03-16','11:00:00','11:20:00','available',NULL,'2026-03-12 14:54:41'),(162,2,'2026-03-16','11:20:00','11:40:00','available',NULL,'2026-03-12 14:54:41'),(163,2,'2026-03-16','11:40:00','12:00:00','available',NULL,'2026-03-12 14:54:41'),(165,2,'2026-03-16','13:20:00','13:40:00','available',NULL,'2026-03-12 14:54:41'),(166,2,'2026-03-16','13:40:00','14:00:00','available',NULL,'2026-03-12 14:54:41'),(167,2,'2026-03-16','14:00:00','14:20:00','available',NULL,'2026-03-12 14:54:41'),(168,2,'2026-03-16','14:20:00','14:40:00','available',NULL,'2026-03-12 14:54:41'),(169,2,'2026-03-16','14:40:00','15:00:00','available',NULL,'2026-03-12 14:54:41'),(170,2,'2026-03-16','15:00:00','15:20:00','available',NULL,'2026-03-12 14:54:41'),(171,2,'2026-03-16','15:20:00','15:40:00','available',NULL,'2026-03-12 14:54:41'),(172,2,'2026-03-16','15:40:00','16:00:00','available',NULL,'2026-03-12 14:54:41'),(173,2,'2026-03-16','16:00:00','16:20:00','available',NULL,'2026-03-12 14:54:41'),(174,2,'2026-03-16','16:20:00','16:40:00','available',NULL,'2026-03-12 14:54:41'),(175,2,'2026-03-16','16:40:00','17:00:00','available',NULL,'2026-03-12 14:54:41'),(176,2,'2026-03-17','08:00:00','08:20:00','available',NULL,'2026-03-12 14:54:41'),(177,2,'2026-03-17','08:20:00','08:40:00','available',NULL,'2026-03-12 14:54:41'),(178,2,'2026-03-17','08:40:00','09:00:00','available',NULL,'2026-03-12 14:54:41'),(180,2,'2026-03-17','09:20:00','09:40:00','available',NULL,'2026-03-12 14:54:41'),(181,2,'2026-03-17','09:40:00','10:00:00','available',NULL,'2026-03-12 14:54:41'),(182,2,'2026-03-17','10:00:00','10:20:00','available',NULL,'2026-03-12 14:54:41'),(183,2,'2026-03-17','10:20:00','10:40:00','available',NULL,'2026-03-12 14:54:41'),(184,2,'2026-03-17','10:40:00','11:00:00','available',NULL,'2026-03-12 14:54:41'),(185,2,'2026-03-17','11:00:00','11:20:00','available',NULL,'2026-03-12 14:54:41'),(186,2,'2026-03-17','11:20:00','11:40:00','available',NULL,'2026-03-12 14:54:41'),(187,2,'2026-03-17','11:40:00','12:00:00','available',NULL,'2026-03-12 14:54:41'),(189,2,'2026-03-17','13:20:00','13:40:00','available',NULL,'2026-03-12 14:54:41'),(190,2,'2026-03-17','13:40:00','14:00:00','available',NULL,'2026-03-12 14:54:41'),(191,2,'2026-03-17','14:00:00','14:20:00','available',NULL,'2026-03-12 14:54:41'),(192,2,'2026-03-17','14:20:00','14:40:00','available',NULL,'2026-03-12 14:54:41'),(193,2,'2026-03-17','14:40:00','15:00:00','available',NULL,'2026-03-12 14:54:41'),(194,2,'2026-03-17','15:00:00','15:20:00','available',NULL,'2026-03-12 14:54:41'),(195,2,'2026-03-17','15:20:00','15:40:00','available',NULL,'2026-03-12 14:54:41'),(196,2,'2026-03-17','15:40:00','16:00:00','available',NULL,'2026-03-12 14:54:41'),(197,2,'2026-03-17','16:00:00','16:20:00','available',NULL,'2026-03-12 14:54:41'),(198,2,'2026-03-17','16:20:00','16:40:00','available',NULL,'2026-03-12 14:54:41'),(199,2,'2026-03-17','16:40:00','17:00:00','available',NULL,'2026-03-12 14:54:41'),(200,2,'2026-03-18','08:00:00','08:20:00','available',NULL,'2026-03-12 14:54:41'),(201,2,'2026-03-18','08:20:00','08:40:00','available',NULL,'2026-03-12 14:54:41'),(202,2,'2026-03-18','08:40:00','09:00:00','available',NULL,'2026-03-12 14:54:41'),(204,2,'2026-03-18','09:20:00','09:40:00','available',NULL,'2026-03-12 14:54:41'),(205,2,'2026-03-18','09:40:00','10:00:00','available',NULL,'2026-03-12 14:54:41'),(206,2,'2026-03-18','10:00:00','10:20:00','available',NULL,'2026-03-12 14:54:41'),(207,2,'2026-03-18','10:20:00','10:40:00','available',NULL,'2026-03-12 14:54:41'),(208,2,'2026-03-18','10:40:00','11:00:00','available',NULL,'2026-03-12 14:54:41'),(209,2,'2026-03-18','11:00:00','11:20:00','available',NULL,'2026-03-12 14:54:41'),(210,2,'2026-03-18','11:20:00','11:40:00','available',NULL,'2026-03-12 14:54:41'),(211,2,'2026-03-18','11:40:00','12:00:00','available',NULL,'2026-03-12 14:54:41'),(213,2,'2026-03-18','13:20:00','13:40:00','available',NULL,'2026-03-12 14:54:41'),(214,2,'2026-03-18','13:40:00','14:00:00','available',NULL,'2026-03-12 14:54:41'),(215,2,'2026-03-18','14:00:00','14:20:00','available',NULL,'2026-03-12 14:54:41'),(216,2,'2026-03-18','14:20:00','14:40:00','available',NULL,'2026-03-12 14:54:41'),(217,2,'2026-03-18','14:40:00','15:00:00','available',NULL,'2026-03-12 14:54:41'),(218,2,'2026-03-18','15:00:00','15:20:00','available',NULL,'2026-03-12 14:54:41'),(219,2,'2026-03-18','15:20:00','15:40:00','available',NULL,'2026-03-12 14:54:41'),(220,2,'2026-03-18','15:40:00','16:00:00','available',NULL,'2026-03-12 14:54:41'),(221,2,'2026-03-18','16:00:00','16:20:00','available',NULL,'2026-03-12 14:54:41'),(222,2,'2026-03-18','16:20:00','16:40:00','available',NULL,'2026-03-12 14:54:41'),(223,2,'2026-03-18','16:40:00','17:00:00','available',NULL,'2026-03-12 14:54:41'),(224,2,'2026-03-19','08:00:00','08:20:00','available',NULL,'2026-03-12 14:54:41'),(225,2,'2026-03-19','08:20:00','08:40:00','available',NULL,'2026-03-12 14:54:41'),(226,2,'2026-03-19','08:40:00','09:00:00','available',NULL,'2026-03-12 14:54:41'),(228,2,'2026-03-19','09:20:00','09:40:00','available',NULL,'2026-03-12 14:54:41'),(229,2,'2026-03-19','09:40:00','10:00:00','available',NULL,'2026-03-12 14:54:41'),(230,2,'2026-03-19','10:00:00','10:20:00','available',NULL,'2026-03-12 14:54:41'),(231,2,'2026-03-19','10:20:00','10:40:00','available',NULL,'2026-03-12 14:54:41'),(232,2,'2026-03-19','10:40:00','11:00:00','available',NULL,'2026-03-12 14:54:41'),(233,2,'2026-03-19','11:00:00','11:20:00','available',NULL,'2026-03-12 14:54:41'),(234,2,'2026-03-19','11:20:00','11:40:00','available',NULL,'2026-03-12 14:54:41'),(235,2,'2026-03-19','11:40:00','12:00:00','available',NULL,'2026-03-12 14:54:41'),(237,2,'2026-03-19','13:20:00','13:40:00','available',NULL,'2026-03-12 14:54:41'),(238,2,'2026-03-19','13:40:00','14:00:00','available',NULL,'2026-03-12 14:54:41'),(239,2,'2026-03-19','14:00:00','14:20:00','available',NULL,'2026-03-12 14:54:41'),(240,2,'2026-03-19','14:20:00','14:40:00','available',NULL,'2026-03-12 14:54:41'),(241,2,'2026-03-19','14:40:00','15:00:00','available',NULL,'2026-03-12 14:54:41'),(242,2,'2026-03-19','15:00:00','15:20:00','available',NULL,'2026-03-12 14:54:41'),(243,2,'2026-03-19','15:20:00','15:40:00','available',NULL,'2026-03-12 14:54:41'),(244,2,'2026-03-19','15:40:00','16:00:00','available',NULL,'2026-03-12 14:54:41'),(245,2,'2026-03-19','16:00:00','16:20:00','available',NULL,'2026-03-12 14:54:41'),(246,2,'2026-03-19','16:20:00','16:40:00','available',NULL,'2026-03-12 14:54:41'),(247,2,'2026-03-19','16:40:00','17:00:00','available',NULL,'2026-03-12 14:54:41'),(248,2,'2026-03-20','08:00:00','08:20:00','available',NULL,'2026-03-12 14:54:41'),(249,2,'2026-03-20','08:20:00','08:40:00','available',NULL,'2026-03-12 14:54:41'),(250,2,'2026-03-20','08:40:00','09:00:00','available',NULL,'2026-03-12 14:54:41'),(252,2,'2026-03-20','09:20:00','09:40:00','available',NULL,'2026-03-12 14:54:41'),(253,2,'2026-03-20','09:40:00','10:00:00','available',NULL,'2026-03-12 14:54:41'),(254,2,'2026-03-20','10:00:00','10:20:00','available',NULL,'2026-03-12 14:54:41'),(255,2,'2026-03-20','10:20:00','10:40:00','available',NULL,'2026-03-12 14:54:41'),(256,2,'2026-03-20','10:40:00','11:00:00','available',NULL,'2026-03-12 14:54:41'),(257,2,'2026-03-20','11:00:00','11:20:00','available',NULL,'2026-03-12 14:54:41'),(258,2,'2026-03-20','11:20:00','11:40:00','available',NULL,'2026-03-12 14:54:41'),(259,2,'2026-03-20','11:40:00','12:00:00','available',NULL,'2026-03-12 14:54:41'),(261,2,'2026-03-20','13:20:00','13:40:00','available',NULL,'2026-03-12 14:54:41'),(262,2,'2026-03-20','13:40:00','14:00:00','available',NULL,'2026-03-12 14:54:41'),(263,2,'2026-03-20','14:00:00','14:20:00','available',NULL,'2026-03-12 14:54:41'),(264,2,'2026-03-20','14:20:00','14:40:00','available',NULL,'2026-03-12 14:54:41'),(265,2,'2026-03-20','14:40:00','15:00:00','available',NULL,'2026-03-12 14:54:41'),(266,2,'2026-03-20','15:00:00','15:20:00','available',NULL,'2026-03-12 14:54:41'),(267,2,'2026-03-20','15:20:00','15:40:00','available',NULL,'2026-03-12 14:54:41'),(268,2,'2026-03-20','15:40:00','16:00:00','available',NULL,'2026-03-12 14:54:41'),(269,2,'2026-03-20','16:00:00','16:20:00','available',NULL,'2026-03-12 14:54:41'),(270,2,'2026-03-20','16:20:00','16:40:00','available',NULL,'2026-03-12 14:54:41'),(271,2,'2026-03-20','16:40:00','17:00:00','available',NULL,'2026-03-12 14:54:41'),(272,2,'2026-03-21','08:00:00','08:20:00','available',NULL,'2026-03-12 14:54:41'),(273,2,'2026-03-21','08:20:00','08:40:00','available',NULL,'2026-03-12 14:54:41'),(274,2,'2026-03-21','08:40:00','09:00:00','available',NULL,'2026-03-12 14:54:41'),(275,2,'2026-03-21','09:00:00','09:20:00','available',NULL,'2026-03-12 14:54:41'),(276,2,'2026-03-21','09:20:00','09:40:00','available',NULL,'2026-03-12 14:54:41'),(277,2,'2026-03-21','09:40:00','10:00:00','available',NULL,'2026-03-12 14:54:41'),(278,2,'2026-03-21','10:00:00','10:20:00','available',NULL,'2026-03-12 14:54:41'),(279,2,'2026-03-21','10:20:00','10:40:00','available',NULL,'2026-03-12 14:54:41'),(280,2,'2026-03-21','10:40:00','11:00:00','available',NULL,'2026-03-12 14:54:41'),(281,2,'2026-03-21','11:00:00','11:20:00','available',NULL,'2026-03-12 14:54:41'),(282,2,'2026-03-21','11:20:00','11:40:00','available',NULL,'2026-03-12 14:54:41'),(283,2,'2026-03-21','11:40:00','12:00:00','available',NULL,'2026-03-12 14:54:41'),(284,2,'2026-03-21','13:00:00','13:20:00','available',NULL,'2026-03-12 14:54:41'),(285,2,'2026-03-21','13:20:00','13:40:00','available',NULL,'2026-03-12 14:54:41'),(286,2,'2026-03-21','13:40:00','14:00:00','available',NULL,'2026-03-12 14:54:41'),(287,2,'2026-03-21','14:00:00','14:20:00','available',NULL,'2026-03-12 14:54:41'),(288,2,'2026-03-21','14:20:00','14:40:00','available',NULL,'2026-03-12 14:54:41'),(289,2,'2026-03-21','14:40:00','15:00:00','available',NULL,'2026-03-12 14:54:41'),(290,2,'2026-03-21','15:00:00','15:20:00','available',NULL,'2026-03-12 14:54:41'),(291,2,'2026-03-21','15:20:00','15:40:00','available',NULL,'2026-03-12 14:54:41'),(292,2,'2026-03-21','15:40:00','16:00:00','available',NULL,'2026-03-12 14:54:41'),(293,2,'2026-03-21','16:00:00','16:20:00','available',NULL,'2026-03-12 14:54:41'),(294,2,'2026-03-21','16:20:00','16:40:00','available',NULL,'2026-03-12 14:54:41'),(295,2,'2026-03-21','16:40:00','17:00:00','available',NULL,'2026-03-12 14:54:41'),(296,2,'2026-03-23','08:00:00','08:20:00','available',NULL,'2026-03-12 14:54:41'),(297,2,'2026-03-23','08:20:00','08:40:00','available',NULL,'2026-03-12 14:54:41'),(298,2,'2026-03-23','08:40:00','09:00:00','available',NULL,'2026-03-12 14:54:41'),(300,2,'2026-03-23','09:20:00','09:40:00','available',NULL,'2026-03-12 14:54:41'),(301,2,'2026-03-23','09:40:00','10:00:00','available',NULL,'2026-03-12 14:54:41'),(302,2,'2026-03-23','10:00:00','10:20:00','available',NULL,'2026-03-12 14:54:41'),(303,2,'2026-03-23','10:20:00','10:40:00','available',NULL,'2026-03-12 14:54:41'),(304,2,'2026-03-23','10:40:00','11:00:00','available',NULL,'2026-03-12 14:54:42'),(305,2,'2026-03-23','11:00:00','11:20:00','available',NULL,'2026-03-12 14:54:42'),(306,2,'2026-03-23','11:20:00','11:40:00','available',NULL,'2026-03-12 14:54:42'),(307,2,'2026-03-23','11:40:00','12:00:00','available',NULL,'2026-03-12 14:54:42'),(309,2,'2026-03-23','13:20:00','13:40:00','available',NULL,'2026-03-12 14:54:42'),(310,2,'2026-03-23','13:40:00','14:00:00','available',NULL,'2026-03-12 14:54:42'),(311,2,'2026-03-23','14:00:00','14:20:00','available',NULL,'2026-03-12 14:54:42'),(312,2,'2026-03-23','14:20:00','14:40:00','available',NULL,'2026-03-12 14:54:42'),(313,2,'2026-03-23','14:40:00','15:00:00','available',NULL,'2026-03-12 14:54:42'),(314,2,'2026-03-23','15:00:00','15:20:00','available',NULL,'2026-03-12 14:54:42'),(315,2,'2026-03-23','15:20:00','15:40:00','available',NULL,'2026-03-12 14:54:42'),(316,2,'2026-03-23','15:40:00','16:00:00','available',NULL,'2026-03-12 14:54:42'),(317,2,'2026-03-23','16:00:00','16:20:00','available',NULL,'2026-03-12 14:54:42'),(318,2,'2026-03-23','16:20:00','16:40:00','available',NULL,'2026-03-12 14:54:42'),(319,2,'2026-03-23','16:40:00','17:00:00','available',NULL,'2026-03-12 14:54:42'),(320,2,'2026-03-24','08:00:00','08:20:00','available',NULL,'2026-03-12 14:54:42'),(321,2,'2026-03-24','08:20:00','08:40:00','available',NULL,'2026-03-12 14:54:42'),(322,2,'2026-03-24','08:40:00','09:00:00','available',NULL,'2026-03-12 14:54:42'),(324,2,'2026-03-24','09:20:00','09:40:00','available',NULL,'2026-03-12 14:54:42'),(325,2,'2026-03-24','09:40:00','10:00:00','available',NULL,'2026-03-12 14:54:42'),(326,2,'2026-03-24','10:00:00','10:20:00','available',NULL,'2026-03-12 14:54:42'),(327,2,'2026-03-24','10:20:00','10:40:00','available',NULL,'2026-03-12 14:54:42'),(328,2,'2026-03-24','10:40:00','11:00:00','available',NULL,'2026-03-12 14:54:42'),(329,2,'2026-03-24','11:00:00','11:20:00','available',NULL,'2026-03-12 14:54:42'),(330,2,'2026-03-24','11:20:00','11:40:00','available',NULL,'2026-03-12 14:54:42'),(331,2,'2026-03-24','11:40:00','12:00:00','available',NULL,'2026-03-12 14:54:42'),(333,2,'2026-03-24','13:20:00','13:40:00','available',NULL,'2026-03-12 14:54:42'),(334,2,'2026-03-24','13:40:00','14:00:00','available',NULL,'2026-03-12 14:54:42'),(335,2,'2026-03-24','14:00:00','14:20:00','available',NULL,'2026-03-12 14:54:42'),(336,2,'2026-03-24','14:20:00','14:40:00','available',NULL,'2026-03-12 14:54:42'),(337,2,'2026-03-24','14:40:00','15:00:00','available',NULL,'2026-03-12 14:54:42'),(338,2,'2026-03-24','15:00:00','15:20:00','available',NULL,'2026-03-12 14:54:42'),(339,2,'2026-03-24','15:20:00','15:40:00','available',NULL,'2026-03-12 14:54:42'),(340,2,'2026-03-24','15:40:00','16:00:00','available',NULL,'2026-03-12 14:54:42'),(341,2,'2026-03-24','16:00:00','16:20:00','available',NULL,'2026-03-12 14:54:42'),(342,2,'2026-03-24','16:20:00','16:40:00','available',NULL,'2026-03-12 14:54:42'),(343,2,'2026-03-24','16:40:00','17:00:00','available',NULL,'2026-03-12 14:54:42'),(344,2,'2026-03-25','08:00:00','08:20:00','available',NULL,'2026-03-12 14:54:42'),(345,2,'2026-03-25','08:20:00','08:40:00','available',NULL,'2026-03-12 14:54:42'),(346,2,'2026-03-25','08:40:00','09:00:00','available',NULL,'2026-03-12 14:54:42'),(348,2,'2026-03-25','09:20:00','09:40:00','available',NULL,'2026-03-12 14:54:42'),(349,2,'2026-03-25','09:40:00','10:00:00','available',NULL,'2026-03-12 14:54:42'),(350,2,'2026-03-25','10:00:00','10:20:00','available',NULL,'2026-03-12 14:54:42'),(351,2,'2026-03-25','10:20:00','10:40:00','available',NULL,'2026-03-12 14:54:42'),(352,2,'2026-03-25','10:40:00','11:00:00','available',NULL,'2026-03-12 14:54:42'),(353,2,'2026-03-25','11:00:00','11:20:00','available',NULL,'2026-03-12 14:54:42'),(354,2,'2026-03-25','11:20:00','11:40:00','available',NULL,'2026-03-12 14:54:42'),(355,2,'2026-03-25','11:40:00','12:00:00','available',NULL,'2026-03-12 14:54:42'),(357,2,'2026-03-25','13:20:00','13:40:00','available',NULL,'2026-03-12 14:54:42'),(358,2,'2026-03-25','13:40:00','14:00:00','available',NULL,'2026-03-12 14:54:42'),(359,2,'2026-03-25','14:00:00','14:20:00','available',NULL,'2026-03-12 14:54:42'),(360,2,'2026-03-25','14:20:00','14:40:00','available',NULL,'2026-03-12 14:54:42'),(361,2,'2026-03-25','14:40:00','15:00:00','available',NULL,'2026-03-12 14:54:42'),(362,2,'2026-03-25','15:00:00','15:20:00','available',NULL,'2026-03-12 14:54:42'),(363,2,'2026-03-25','15:20:00','15:40:00','available',NULL,'2026-03-12 14:54:42'),(364,2,'2026-03-25','15:40:00','16:00:00','available',NULL,'2026-03-12 14:54:42'),(365,2,'2026-03-25','16:00:00','16:20:00','available',NULL,'2026-03-12 14:54:42'),(366,2,'2026-03-25','16:20:00','16:40:00','available',NULL,'2026-03-12 14:54:42'),(367,2,'2026-03-25','16:40:00','17:00:00','available',NULL,'2026-03-12 14:54:42'),(368,2,'2026-03-26','08:00:00','08:20:00','available',NULL,'2026-03-12 14:54:42'),(369,2,'2026-03-26','08:20:00','08:40:00','available',NULL,'2026-03-12 14:54:42'),(370,2,'2026-03-26','08:40:00','09:00:00','available',NULL,'2026-03-12 14:54:42'),(372,2,'2026-03-26','09:20:00','09:40:00','available',NULL,'2026-03-12 14:54:42'),(373,2,'2026-03-26','09:40:00','10:00:00','available',NULL,'2026-03-12 14:54:42'),(374,2,'2026-03-26','10:00:00','10:20:00','available',NULL,'2026-03-12 14:54:42'),(375,2,'2026-03-26','10:20:00','10:40:00','available',NULL,'2026-03-12 14:54:42'),(376,2,'2026-03-26','10:40:00','11:00:00','available',NULL,'2026-03-12 14:54:42'),(377,2,'2026-03-26','11:00:00','11:20:00','available',NULL,'2026-03-12 14:54:42'),(378,2,'2026-03-26','11:20:00','11:40:00','available',NULL,'2026-03-12 14:54:42'),(379,2,'2026-03-26','11:40:00','12:00:00','available',NULL,'2026-03-12 14:54:42'),(381,2,'2026-03-26','13:20:00','13:40:00','available',NULL,'2026-03-12 14:54:42'),(382,2,'2026-03-26','13:40:00','14:00:00','available',NULL,'2026-03-12 14:54:42'),(383,2,'2026-03-26','14:00:00','14:20:00','available',NULL,'2026-03-12 14:54:42'),(384,2,'2026-03-26','14:20:00','14:40:00','available',NULL,'2026-03-12 14:54:42'),(385,2,'2026-03-26','14:40:00','15:00:00','available',NULL,'2026-03-12 14:54:42'),(386,2,'2026-03-26','15:00:00','15:20:00','available',NULL,'2026-03-12 14:54:42'),(387,2,'2026-03-26','15:20:00','15:40:00','available',NULL,'2026-03-12 14:54:42'),(388,2,'2026-03-26','15:40:00','16:00:00','available',NULL,'2026-03-12 14:54:42'),(389,2,'2026-03-26','16:00:00','16:20:00','available',NULL,'2026-03-12 14:54:42'),(390,2,'2026-03-26','16:20:00','16:40:00','available',NULL,'2026-03-12 14:54:42'),(391,2,'2026-03-26','16:40:00','17:00:00','available',NULL,'2026-03-12 14:54:42'),(392,2,'2026-03-27','08:00:00','08:20:00','available',NULL,'2026-03-12 14:54:42'),(393,2,'2026-03-27','08:20:00','08:40:00','available',NULL,'2026-03-12 14:54:42'),(394,2,'2026-03-27','08:40:00','09:00:00','available',NULL,'2026-03-12 14:54:42'),(396,2,'2026-03-27','09:20:00','09:40:00','available',NULL,'2026-03-12 14:54:42'),(397,2,'2026-03-27','09:40:00','10:00:00','available',NULL,'2026-03-12 14:54:42'),(398,2,'2026-03-27','10:00:00','10:20:00','available',NULL,'2026-03-12 14:54:42'),(399,2,'2026-03-27','10:20:00','10:40:00','available',NULL,'2026-03-12 14:54:42'),(400,2,'2026-03-27','10:40:00','11:00:00','available',NULL,'2026-03-12 14:54:42'),(401,2,'2026-03-27','11:00:00','11:20:00','available',NULL,'2026-03-12 14:54:42'),(402,2,'2026-03-27','11:20:00','11:40:00','available',NULL,'2026-03-12 14:54:42'),(403,2,'2026-03-27','11:40:00','12:00:00','available',NULL,'2026-03-12 14:54:42'),(405,2,'2026-03-27','13:20:00','13:40:00','available',NULL,'2026-03-12 14:54:42'),(406,2,'2026-03-27','13:40:00','14:00:00','available',NULL,'2026-03-12 14:54:42'),(407,2,'2026-03-27','14:00:00','14:20:00','available',NULL,'2026-03-12 14:54:42'),(408,2,'2026-03-27','14:20:00','14:40:00','available',NULL,'2026-03-12 14:54:42'),(409,2,'2026-03-27','14:40:00','15:00:00','available',NULL,'2026-03-12 14:54:42'),(410,2,'2026-03-27','15:00:00','15:20:00','available',NULL,'2026-03-12 14:54:42'),(411,2,'2026-03-27','15:20:00','15:40:00','available',NULL,'2026-03-12 14:54:42'),(412,2,'2026-03-27','15:40:00','16:00:00','available',NULL,'2026-03-12 14:54:42'),(413,2,'2026-03-27','16:00:00','16:20:00','available',NULL,'2026-03-12 14:54:42'),(414,2,'2026-03-27','16:20:00','16:40:00','available',NULL,'2026-03-12 14:54:42'),(415,2,'2026-03-27','16:40:00','17:00:00','available',NULL,'2026-03-12 14:54:42'),(416,2,'2026-03-28','08:00:00','08:20:00','available',NULL,'2026-03-12 14:54:42'),(417,2,'2026-03-28','08:20:00','08:40:00','available',NULL,'2026-03-12 14:54:42'),(418,2,'2026-03-28','08:40:00','09:00:00','available',NULL,'2026-03-12 14:54:42'),(419,2,'2026-03-28','09:00:00','09:20:00','available',NULL,'2026-03-12 14:54:42'),(420,2,'2026-03-28','09:20:00','09:40:00','available',NULL,'2026-03-12 14:54:42'),(421,2,'2026-03-28','09:40:00','10:00:00','available',NULL,'2026-03-12 14:54:42'),(422,2,'2026-03-28','10:00:00','10:20:00','available',NULL,'2026-03-12 14:54:42'),(423,2,'2026-03-28','10:20:00','10:40:00','available',NULL,'2026-03-12 14:54:42'),(424,2,'2026-03-28','10:40:00','11:00:00','available',NULL,'2026-03-12 14:54:42'),(425,2,'2026-03-28','11:00:00','11:20:00','available',NULL,'2026-03-12 14:54:42'),(426,2,'2026-03-28','11:20:00','11:40:00','available',NULL,'2026-03-12 14:54:42'),(427,2,'2026-03-28','11:40:00','12:00:00','available',NULL,'2026-03-12 14:54:42'),(428,2,'2026-03-28','13:00:00','13:20:00','available',NULL,'2026-03-12 14:54:42'),(429,2,'2026-03-28','13:20:00','13:40:00','available',NULL,'2026-03-12 14:54:42'),(430,2,'2026-03-28','13:40:00','14:00:00','available',NULL,'2026-03-12 14:54:42'),(431,2,'2026-03-28','14:00:00','14:20:00','available',NULL,'2026-03-12 14:54:42'),(432,2,'2026-03-28','14:20:00','14:40:00','available',NULL,'2026-03-12 14:54:42'),(433,2,'2026-03-28','14:40:00','15:00:00','available',NULL,'2026-03-12 14:54:42'),(434,2,'2026-03-28','15:00:00','15:20:00','available',NULL,'2026-03-12 14:54:42'),(435,2,'2026-03-28','15:20:00','15:40:00','available',NULL,'2026-03-12 14:54:42'),(436,2,'2026-03-28','15:40:00','16:00:00','available',NULL,'2026-03-12 14:54:42'),(437,2,'2026-03-28','16:00:00','16:20:00','available',NULL,'2026-03-12 14:54:42'),(438,2,'2026-03-28','16:20:00','16:40:00','available',NULL,'2026-03-12 14:54:42'),(439,2,'2026-03-28','16:40:00','17:00:00','available',NULL,'2026-03-12 14:54:42'),(440,2,'2026-03-30','08:00:00','08:20:00','available',NULL,'2026-03-12 14:54:42'),(441,2,'2026-03-30','08:20:00','08:40:00','available',NULL,'2026-03-12 14:54:42'),(442,2,'2026-03-30','08:40:00','09:00:00','available',NULL,'2026-03-12 14:54:42'),(444,2,'2026-03-30','09:20:00','09:40:00','available',NULL,'2026-03-12 14:54:42'),(445,2,'2026-03-30','09:40:00','10:00:00','available',NULL,'2026-03-12 14:54:42'),(446,2,'2026-03-30','10:00:00','10:20:00','available',NULL,'2026-03-12 14:54:42'),(447,2,'2026-03-30','10:20:00','10:40:00','available',NULL,'2026-03-12 14:54:42'),(448,2,'2026-03-30','10:40:00','11:00:00','available',NULL,'2026-03-12 14:54:42'),(449,2,'2026-03-30','11:00:00','11:20:00','available',NULL,'2026-03-12 14:54:42'),(450,2,'2026-03-30','11:20:00','11:40:00','available',NULL,'2026-03-12 14:54:42'),(451,2,'2026-03-30','11:40:00','12:00:00','available',NULL,'2026-03-12 14:54:42'),(453,2,'2026-03-30','13:20:00','13:40:00','available',NULL,'2026-03-12 14:54:42'),(454,2,'2026-03-30','13:40:00','14:00:00','available',NULL,'2026-03-12 14:54:42'),(455,2,'2026-03-30','14:00:00','14:20:00','available',NULL,'2026-03-12 14:54:42'),(456,2,'2026-03-30','14:20:00','14:40:00','available',NULL,'2026-03-12 14:54:42'),(457,2,'2026-03-30','14:40:00','15:00:00','available',NULL,'2026-03-12 14:54:42'),(458,2,'2026-03-30','15:00:00','15:20:00','available',NULL,'2026-03-12 14:54:42'),(459,2,'2026-03-30','15:20:00','15:40:00','available',NULL,'2026-03-12 14:54:42'),(460,2,'2026-03-30','15:40:00','16:00:00','available',NULL,'2026-03-12 14:54:42'),(461,2,'2026-03-30','16:00:00','16:20:00','available',NULL,'2026-03-12 14:54:42'),(462,2,'2026-03-30','16:20:00','16:40:00','available',NULL,'2026-03-12 14:54:42'),(463,2,'2026-03-30','16:40:00','17:00:00','available',NULL,'2026-03-12 14:54:42'),(464,2,'2026-03-31','08:00:00','08:20:00','available',NULL,'2026-03-12 14:54:42'),(465,2,'2026-03-31','08:20:00','08:40:00','available',NULL,'2026-03-12 14:54:42'),(466,2,'2026-03-31','08:40:00','09:00:00','available',NULL,'2026-03-12 14:54:42'),(468,2,'2026-03-31','09:20:00','09:40:00','available',NULL,'2026-03-12 14:54:42'),(469,2,'2026-03-31','09:40:00','10:00:00','available',NULL,'2026-03-12 14:54:42'),(470,2,'2026-03-31','10:00:00','10:20:00','available',NULL,'2026-03-12 14:54:42'),(471,2,'2026-03-31','10:20:00','10:40:00','available',NULL,'2026-03-12 14:54:42'),(472,2,'2026-03-31','10:40:00','11:00:00','available',NULL,'2026-03-12 14:54:42'),(473,2,'2026-03-31','11:00:00','11:20:00','available',NULL,'2026-03-12 14:54:42'),(474,2,'2026-03-31','11:20:00','11:40:00','available',NULL,'2026-03-12 14:54:42'),(475,2,'2026-03-31','11:40:00','12:00:00','available',NULL,'2026-03-12 14:54:42'),(477,2,'2026-03-31','13:20:00','13:40:00','available',NULL,'2026-03-12 14:54:43'),(478,2,'2026-03-31','13:40:00','14:00:00','available',NULL,'2026-03-12 14:54:43'),(479,2,'2026-03-31','14:00:00','14:20:00','available',NULL,'2026-03-12 14:54:43'),(480,2,'2026-03-31','14:20:00','14:40:00','available',NULL,'2026-03-12 14:54:43'),(481,2,'2026-03-31','14:40:00','15:00:00','available',NULL,'2026-03-12 14:54:43'),(482,2,'2026-03-31','15:00:00','15:20:00','available',NULL,'2026-03-12 14:54:43'),(483,2,'2026-03-31','15:20:00','15:40:00','available',NULL,'2026-03-12 14:54:43'),(484,2,'2026-03-31','15:40:00','16:00:00','available',NULL,'2026-03-12 14:54:43'),(485,2,'2026-03-31','16:00:00','16:20:00','available',NULL,'2026-03-12 14:54:43'),(486,2,'2026-03-31','16:20:00','16:40:00','available',NULL,'2026-03-12 14:54:43'),(487,2,'2026-03-31','16:40:00','17:00:00','available',NULL,'2026-03-12 14:54:43'),(488,2,'2026-04-01','08:00:00','08:20:00','available',NULL,'2026-03-12 14:54:43'),(489,2,'2026-04-01','08:20:00','08:40:00','available',NULL,'2026-03-12 14:54:43'),(490,2,'2026-04-01','08:40:00','09:00:00','available',NULL,'2026-03-12 14:54:43'),(492,2,'2026-04-01','09:20:00','09:40:00','available',NULL,'2026-03-12 14:54:43'),(493,2,'2026-04-01','09:40:00','10:00:00','available',NULL,'2026-03-12 14:54:43'),(494,2,'2026-04-01','10:00:00','10:20:00','available',NULL,'2026-03-12 14:54:43'),(495,2,'2026-04-01','10:20:00','10:40:00','available',NULL,'2026-03-12 14:54:43'),(496,2,'2026-04-01','10:40:00','11:00:00','available',NULL,'2026-03-12 14:54:43'),(497,2,'2026-04-01','11:00:00','11:20:00','available',NULL,'2026-03-12 14:54:43'),(498,2,'2026-04-01','11:20:00','11:40:00','available',NULL,'2026-03-12 14:54:43'),(499,2,'2026-04-01','11:40:00','12:00:00','available',NULL,'2026-03-12 14:54:43'),(501,2,'2026-04-01','13:20:00','13:40:00','available',NULL,'2026-03-12 14:54:43'),(502,2,'2026-04-01','13:40:00','14:00:00','available',NULL,'2026-03-12 14:54:43'),(503,2,'2026-04-01','14:00:00','14:20:00','available',NULL,'2026-03-12 14:54:43'),(504,2,'2026-04-01','14:20:00','14:40:00','available',NULL,'2026-03-12 14:54:43'),(505,2,'2026-04-01','14:40:00','15:00:00','available',NULL,'2026-03-12 14:54:43'),(506,2,'2026-04-01','15:00:00','15:20:00','available',NULL,'2026-03-12 14:54:43'),(507,2,'2026-04-01','15:20:00','15:40:00','available',NULL,'2026-03-12 14:54:43'),(508,2,'2026-04-01','15:40:00','16:00:00','available',NULL,'2026-03-12 14:54:43'),(509,2,'2026-04-01','16:00:00','16:20:00','available',NULL,'2026-03-12 14:54:43'),(510,2,'2026-04-01','16:20:00','16:40:00','available',NULL,'2026-03-12 14:54:43'),(511,2,'2026-04-01','16:40:00','17:00:00','available',NULL,'2026-03-12 14:54:43'),(512,2,'2026-04-02','08:00:00','08:20:00','available',NULL,'2026-03-12 14:54:43'),(513,2,'2026-04-02','08:20:00','08:40:00','available',NULL,'2026-03-12 14:54:43'),(514,2,'2026-04-02','08:40:00','09:00:00','available',NULL,'2026-03-12 14:54:43'),(516,2,'2026-04-02','09:20:00','09:40:00','available',NULL,'2026-03-12 14:54:43'),(517,2,'2026-04-02','09:40:00','10:00:00','available',NULL,'2026-03-12 14:54:43'),(518,2,'2026-04-02','10:00:00','10:20:00','available',NULL,'2026-03-12 14:54:43'),(519,2,'2026-04-02','10:20:00','10:40:00','available',NULL,'2026-03-12 14:54:43'),(520,2,'2026-04-02','10:40:00','11:00:00','available',NULL,'2026-03-12 14:54:43'),(521,2,'2026-04-02','11:00:00','11:20:00','available',NULL,'2026-03-12 14:54:43'),(522,2,'2026-04-02','11:20:00','11:40:00','available',NULL,'2026-03-12 14:54:43'),(523,2,'2026-04-02','11:40:00','12:00:00','available',NULL,'2026-03-12 14:54:43'),(525,2,'2026-04-02','13:20:00','13:40:00','available',NULL,'2026-03-12 14:54:43'),(526,2,'2026-04-02','13:40:00','14:00:00','available',NULL,'2026-03-12 14:54:43'),(527,2,'2026-04-02','14:00:00','14:20:00','available',NULL,'2026-03-12 14:54:43'),(528,2,'2026-04-02','14:20:00','14:40:00','available',NULL,'2026-03-12 14:54:43'),(529,2,'2026-04-02','14:40:00','15:00:00','available',NULL,'2026-03-12 14:54:43'),(530,2,'2026-04-02','15:00:00','15:20:00','available',NULL,'2026-03-12 14:54:43'),(531,2,'2026-04-02','15:20:00','15:40:00','available',NULL,'2026-03-12 14:54:43'),(532,2,'2026-04-02','15:40:00','16:00:00','available',NULL,'2026-03-12 14:54:43'),(533,2,'2026-04-02','16:00:00','16:20:00','available',NULL,'2026-03-12 14:54:43'),(534,2,'2026-04-02','16:20:00','16:40:00','available',NULL,'2026-03-12 14:54:43'),(535,2,'2026-04-02','16:40:00','17:00:00','available',NULL,'2026-03-12 14:54:43'),(536,2,'2026-04-03','08:00:00','08:20:00','available',NULL,'2026-03-12 14:54:43'),(537,2,'2026-04-03','08:20:00','08:40:00','available',NULL,'2026-03-12 14:54:43'),(538,2,'2026-04-03','08:40:00','09:00:00','available',NULL,'2026-03-12 14:54:43'),(540,2,'2026-04-03','09:20:00','09:40:00','available',NULL,'2026-03-12 14:54:43'),(541,2,'2026-04-03','09:40:00','10:00:00','available',NULL,'2026-03-12 14:54:43'),(542,2,'2026-04-03','10:00:00','10:20:00','available',NULL,'2026-03-12 14:54:43'),(543,2,'2026-04-03','10:20:00','10:40:00','available',NULL,'2026-03-12 14:54:43'),(544,2,'2026-04-03','10:40:00','11:00:00','available',NULL,'2026-03-12 14:54:43'),(545,2,'2026-04-03','11:00:00','11:20:00','available',NULL,'2026-03-12 14:54:43'),(546,2,'2026-04-03','11:20:00','11:40:00','available',NULL,'2026-03-12 14:54:43'),(547,2,'2026-04-03','11:40:00','12:00:00','available',NULL,'2026-03-12 14:54:43'),(549,2,'2026-04-03','13:20:00','13:40:00','available',NULL,'2026-03-12 14:54:43'),(550,2,'2026-04-03','13:40:00','14:00:00','available',NULL,'2026-03-12 14:54:43'),(551,2,'2026-04-03','14:00:00','14:20:00','available',NULL,'2026-03-12 14:54:43'),(552,2,'2026-04-03','14:20:00','14:40:00','available',NULL,'2026-03-12 14:54:43'),(553,2,'2026-04-03','14:40:00','15:00:00','available',NULL,'2026-03-12 14:54:43'),(554,2,'2026-04-03','15:00:00','15:20:00','available',NULL,'2026-03-12 14:54:43'),(555,2,'2026-04-03','15:20:00','15:40:00','available',NULL,'2026-03-12 14:54:43'),(556,2,'2026-04-03','15:40:00','16:00:00','available',NULL,'2026-03-12 14:54:43'),(557,2,'2026-04-03','16:00:00','16:20:00','available',NULL,'2026-03-12 14:54:43'),(558,2,'2026-04-03','16:20:00','16:40:00','available',NULL,'2026-03-12 14:54:43'),(559,2,'2026-04-03','16:40:00','17:00:00','available',NULL,'2026-03-12 14:54:43'),(560,2,'2026-04-04','08:00:00','08:20:00','available',NULL,'2026-03-12 14:54:43'),(561,2,'2026-04-04','08:20:00','08:40:00','available',NULL,'2026-03-12 14:54:43'),(562,2,'2026-04-04','08:40:00','09:00:00','available',NULL,'2026-03-12 14:54:43'),(563,2,'2026-04-04','09:00:00','09:20:00','available',NULL,'2026-03-12 14:54:43'),(564,2,'2026-04-04','09:20:00','09:40:00','available',NULL,'2026-03-12 14:54:43'),(565,2,'2026-04-04','09:40:00','10:00:00','available',NULL,'2026-03-12 14:54:43'),(566,2,'2026-04-04','10:00:00','10:20:00','available',NULL,'2026-03-12 14:54:43'),(567,2,'2026-04-04','10:20:00','10:40:00','available',NULL,'2026-03-12 14:54:43'),(568,2,'2026-04-04','10:40:00','11:00:00','available',NULL,'2026-03-12 14:54:43'),(569,2,'2026-04-04','11:00:00','11:20:00','available',NULL,'2026-03-12 14:54:43'),(570,2,'2026-04-04','11:20:00','11:40:00','available',NULL,'2026-03-12 14:54:43'),(571,2,'2026-04-04','11:40:00','12:00:00','available',NULL,'2026-03-12 14:54:43'),(572,2,'2026-04-04','13:00:00','13:20:00','available',NULL,'2026-03-12 14:54:43'),(573,2,'2026-04-04','13:20:00','13:40:00','available',NULL,'2026-03-12 14:54:43'),(574,2,'2026-04-04','13:40:00','14:00:00','available',NULL,'2026-03-12 14:54:43'),(575,2,'2026-04-04','14:00:00','14:20:00','available',NULL,'2026-03-12 14:54:43'),(576,2,'2026-04-04','14:20:00','14:40:00','available',NULL,'2026-03-12 14:54:43'),(577,2,'2026-04-04','14:40:00','15:00:00','available',NULL,'2026-03-12 14:54:43'),(578,2,'2026-04-04','15:00:00','15:20:00','available',NULL,'2026-03-12 14:54:43'),(579,2,'2026-04-04','15:20:00','15:40:00','available',NULL,'2026-03-12 14:54:43'),(580,2,'2026-04-04','15:40:00','16:00:00','available',NULL,'2026-03-12 14:54:43'),(581,2,'2026-04-04','16:00:00','16:20:00','available',NULL,'2026-03-12 14:54:43'),(582,2,'2026-04-04','16:20:00','16:40:00','available',NULL,'2026-03-12 14:54:43'),(583,2,'2026-04-04','16:40:00','17:00:00','available',NULL,'2026-03-12 14:54:43'),(584,2,'2026-04-06','08:00:00','08:20:00','available',NULL,'2026-03-12 14:54:43'),(585,2,'2026-04-06','08:20:00','08:40:00','available',NULL,'2026-03-12 14:54:43'),(586,2,'2026-04-06','08:40:00','09:00:00','available',NULL,'2026-03-12 14:54:43'),(588,2,'2026-04-06','09:20:00','09:40:00','available',NULL,'2026-03-12 14:54:43'),(589,2,'2026-04-06','09:40:00','10:00:00','available',NULL,'2026-03-12 14:54:43'),(590,2,'2026-04-06','10:00:00','10:20:00','available',NULL,'2026-03-12 14:54:43'),(591,2,'2026-04-06','10:20:00','10:40:00','available',NULL,'2026-03-12 14:54:43'),(592,2,'2026-04-06','10:40:00','11:00:00','available',NULL,'2026-03-12 14:54:43'),(593,2,'2026-04-06','11:00:00','11:20:00','available',NULL,'2026-03-12 14:54:43'),(594,2,'2026-04-06','11:20:00','11:40:00','available',NULL,'2026-03-12 14:54:43'),(595,2,'2026-04-06','11:40:00','12:00:00','available',NULL,'2026-03-12 14:54:43'),(597,2,'2026-04-06','13:20:00','13:40:00','available',NULL,'2026-03-12 14:54:43'),(598,2,'2026-04-06','13:40:00','14:00:00','available',NULL,'2026-03-12 14:54:43'),(599,2,'2026-04-06','14:00:00','14:20:00','available',NULL,'2026-03-12 14:54:43'),(600,2,'2026-04-06','14:20:00','14:40:00','available',NULL,'2026-03-12 14:54:43'),(601,2,'2026-04-06','14:40:00','15:00:00','available',NULL,'2026-03-12 14:54:43'),(602,2,'2026-04-06','15:00:00','15:20:00','available',NULL,'2026-03-12 14:54:43'),(603,2,'2026-04-06','15:20:00','15:40:00','available',NULL,'2026-03-12 14:54:43'),(604,2,'2026-04-06','15:40:00','16:00:00','available',NULL,'2026-03-12 14:54:43'),(605,2,'2026-04-06','16:00:00','16:20:00','available',NULL,'2026-03-12 14:54:43'),(606,2,'2026-04-06','16:20:00','16:40:00','available',NULL,'2026-03-12 14:54:43'),(607,2,'2026-04-06','16:40:00','17:00:00','available',NULL,'2026-03-12 14:54:43'),(608,2,'2026-04-07','08:00:00','08:20:00','available',NULL,'2026-03-12 14:54:43'),(609,2,'2026-04-07','08:20:00','08:40:00','available',NULL,'2026-03-12 14:54:43'),(610,2,'2026-04-07','08:40:00','09:00:00','available',NULL,'2026-03-12 14:54:43'),(612,2,'2026-04-07','09:20:00','09:40:00','available',NULL,'2026-03-12 14:54:43'),(613,2,'2026-04-07','09:40:00','10:00:00','available',NULL,'2026-03-12 14:54:44'),(614,2,'2026-04-07','10:00:00','10:20:00','available',NULL,'2026-03-12 14:54:44'),(615,2,'2026-04-07','10:20:00','10:40:00','available',NULL,'2026-03-12 14:54:44'),(616,2,'2026-04-07','10:40:00','11:00:00','available',NULL,'2026-03-12 14:54:44'),(617,2,'2026-04-07','11:00:00','11:20:00','available',NULL,'2026-03-12 14:54:44'),(618,2,'2026-04-07','11:20:00','11:40:00','available',NULL,'2026-03-12 14:54:44'),(619,2,'2026-04-07','11:40:00','12:00:00','available',NULL,'2026-03-12 14:54:44'),(621,2,'2026-04-07','13:20:00','13:40:00','available',NULL,'2026-03-12 14:54:44'),(622,2,'2026-04-07','13:40:00','14:00:00','available',NULL,'2026-03-12 14:54:44'),(623,2,'2026-04-07','14:00:00','14:20:00','available',NULL,'2026-03-12 14:54:44'),(624,2,'2026-04-07','14:20:00','14:40:00','available',NULL,'2026-03-12 14:54:44'),(625,2,'2026-04-07','14:40:00','15:00:00','available',NULL,'2026-03-12 14:54:44'),(626,2,'2026-04-07','15:00:00','15:20:00','available',NULL,'2026-03-12 14:54:44'),(627,2,'2026-04-07','15:20:00','15:40:00','available',NULL,'2026-03-12 14:54:44'),(628,2,'2026-04-07','15:40:00','16:00:00','available',NULL,'2026-03-12 14:54:44'),(629,2,'2026-04-07','16:00:00','16:20:00','available',NULL,'2026-03-12 14:54:44'),(630,2,'2026-04-07','16:20:00','16:40:00','available',NULL,'2026-03-12 14:54:44'),(631,2,'2026-04-07','16:40:00','17:00:00','available',NULL,'2026-03-12 14:54:44'),(632,2,'2026-04-08','08:00:00','08:20:00','available',NULL,'2026-03-12 14:54:44'),(633,2,'2026-04-08','08:20:00','08:40:00','available',NULL,'2026-03-12 14:54:44'),(634,2,'2026-04-08','08:40:00','09:00:00','available',NULL,'2026-03-12 14:54:44'),(636,2,'2026-04-08','09:20:00','09:40:00','available',NULL,'2026-03-12 14:54:44'),(637,2,'2026-04-08','09:40:00','10:00:00','available',NULL,'2026-03-12 14:54:44'),(638,2,'2026-04-08','10:00:00','10:20:00','available',NULL,'2026-03-12 14:54:44'),(639,2,'2026-04-08','10:20:00','10:40:00','available',NULL,'2026-03-12 14:54:44'),(640,2,'2026-04-08','10:40:00','11:00:00','available',NULL,'2026-03-12 14:54:44'),(641,2,'2026-04-08','11:00:00','11:20:00','available',NULL,'2026-03-12 14:54:44'),(642,2,'2026-04-08','11:20:00','11:40:00','available',NULL,'2026-03-12 14:54:44'),(643,2,'2026-04-08','11:40:00','12:00:00','available',NULL,'2026-03-12 14:54:44'),(645,2,'2026-04-08','13:20:00','13:40:00','available',NULL,'2026-03-12 14:54:44'),(646,2,'2026-04-08','13:40:00','14:00:00','available',NULL,'2026-03-12 14:54:44'),(647,2,'2026-04-08','14:00:00','14:20:00','available',NULL,'2026-03-12 14:54:44'),(648,2,'2026-04-08','14:20:00','14:40:00','available',NULL,'2026-03-12 14:54:44'),(649,2,'2026-04-08','14:40:00','15:00:00','available',NULL,'2026-03-12 14:54:44'),(650,2,'2026-04-08','15:00:00','15:20:00','available',NULL,'2026-03-12 14:54:44'),(651,2,'2026-04-08','15:20:00','15:40:00','available',NULL,'2026-03-12 14:54:44'),(652,2,'2026-04-08','15:40:00','16:00:00','available',NULL,'2026-03-12 14:54:44'),(653,2,'2026-04-08','16:00:00','16:20:00','available',NULL,'2026-03-12 14:54:44'),(654,2,'2026-04-08','16:20:00','16:40:00','available',NULL,'2026-03-12 14:54:44'),(655,2,'2026-04-08','16:40:00','17:00:00','available',NULL,'2026-03-12 14:54:44'),(656,2,'2026-04-09','08:00:00','08:20:00','available',NULL,'2026-03-12 14:54:44'),(657,2,'2026-04-09','08:20:00','08:40:00','available',NULL,'2026-03-12 14:54:44'),(658,2,'2026-04-09','08:40:00','09:00:00','available',NULL,'2026-03-12 14:54:44'),(660,2,'2026-04-09','09:20:00','09:40:00','available',NULL,'2026-03-12 14:54:44'),(661,2,'2026-04-09','09:40:00','10:00:00','available',NULL,'2026-03-12 14:54:44'),(662,2,'2026-04-09','10:00:00','10:20:00','available',NULL,'2026-03-12 14:54:44'),(663,2,'2026-04-09','10:20:00','10:40:00','available',NULL,'2026-03-12 14:54:44'),(664,2,'2026-04-09','10:40:00','11:00:00','available',NULL,'2026-03-12 14:54:44'),(665,2,'2026-04-09','11:00:00','11:20:00','available',NULL,'2026-03-12 14:54:44'),(666,2,'2026-04-09','11:20:00','11:40:00','available',NULL,'2026-03-12 14:54:44'),(667,2,'2026-04-09','11:40:00','12:00:00','available',NULL,'2026-03-12 14:54:44'),(669,2,'2026-04-09','13:20:00','13:40:00','available',NULL,'2026-03-12 14:54:44'),(670,2,'2026-04-09','13:40:00','14:00:00','available',NULL,'2026-03-12 14:54:44'),(671,2,'2026-04-09','14:00:00','14:20:00','available',NULL,'2026-03-12 14:54:44'),(672,2,'2026-04-09','14:20:00','14:40:00','available',NULL,'2026-03-12 14:54:44'),(673,2,'2026-04-09','14:40:00','15:00:00','available',NULL,'2026-03-12 14:54:44'),(674,2,'2026-04-09','15:00:00','15:20:00','available',NULL,'2026-03-12 14:54:44'),(675,2,'2026-04-09','15:20:00','15:40:00','available',NULL,'2026-03-12 14:54:44'),(676,2,'2026-04-09','15:40:00','16:00:00','available',NULL,'2026-03-12 14:54:44'),(677,2,'2026-04-09','16:00:00','16:20:00','available',NULL,'2026-03-12 14:54:44'),(678,2,'2026-04-09','16:20:00','16:40:00','available',NULL,'2026-03-12 14:54:44'),(679,2,'2026-04-09','16:40:00','17:00:00','available',NULL,'2026-03-12 14:54:44'),(680,2,'2026-04-10','08:00:00','08:20:00','available',NULL,'2026-03-12 14:54:44'),(681,2,'2026-04-10','08:20:00','08:40:00','available',NULL,'2026-03-12 14:54:44'),(682,2,'2026-04-10','08:40:00','09:00:00','available',NULL,'2026-03-12 14:54:44'),(684,2,'2026-04-10','09:20:00','09:40:00','available',NULL,'2026-03-12 14:54:44'),(685,2,'2026-04-10','09:40:00','10:00:00','available',NULL,'2026-03-12 14:54:44'),(686,2,'2026-04-10','10:00:00','10:20:00','available',NULL,'2026-03-12 14:54:44'),(687,2,'2026-04-10','10:20:00','10:40:00','available',NULL,'2026-03-12 14:54:44'),(688,2,'2026-04-10','10:40:00','11:00:00','available',NULL,'2026-03-12 14:54:44'),(689,2,'2026-04-10','11:00:00','11:20:00','available',NULL,'2026-03-12 14:54:44'),(690,2,'2026-04-10','11:20:00','11:40:00','available',NULL,'2026-03-12 14:54:44'),(691,2,'2026-04-10','11:40:00','12:00:00','available',NULL,'2026-03-12 14:54:44'),(693,2,'2026-04-10','13:20:00','13:40:00','available',NULL,'2026-03-12 14:54:44'),(694,2,'2026-04-10','13:40:00','14:00:00','available',NULL,'2026-03-12 14:54:44'),(695,2,'2026-04-10','14:00:00','14:20:00','available',NULL,'2026-03-12 14:54:44'),(696,2,'2026-04-10','14:20:00','14:40:00','available',NULL,'2026-03-12 14:54:44'),(697,2,'2026-04-10','14:40:00','15:00:00','available',NULL,'2026-03-12 14:54:44'),(698,2,'2026-04-10','15:00:00','15:20:00','available',NULL,'2026-03-12 14:54:44'),(699,2,'2026-04-10','15:20:00','15:40:00','available',NULL,'2026-03-12 14:54:44'),(700,2,'2026-04-10','15:40:00','16:00:00','available',NULL,'2026-03-12 14:54:44'),(701,2,'2026-04-10','16:00:00','16:20:00','available',NULL,'2026-03-12 14:54:44'),(702,2,'2026-04-10','16:20:00','16:40:00','available',NULL,'2026-03-12 14:54:44'),(703,2,'2026-04-10','16:40:00','17:00:00','available',NULL,'2026-03-12 14:54:44'),(704,2,'2026-04-11','08:00:00','08:20:00','available',NULL,'2026-03-12 14:54:44'),(705,2,'2026-04-11','08:20:00','08:40:00','available',NULL,'2026-03-12 14:54:44'),(706,2,'2026-04-11','08:40:00','09:00:00','available',NULL,'2026-03-12 14:54:44'),(707,2,'2026-04-11','09:00:00','09:20:00','available',NULL,'2026-03-12 14:54:44'),(708,2,'2026-04-11','09:20:00','09:40:00','available',NULL,'2026-03-12 14:54:44'),(709,2,'2026-04-11','09:40:00','10:00:00','available',NULL,'2026-03-12 14:54:44'),(710,2,'2026-04-11','10:00:00','10:20:00','available',NULL,'2026-03-12 14:54:44'),(711,2,'2026-04-11','10:20:00','10:40:00','available',NULL,'2026-03-12 14:54:44'),(712,2,'2026-04-11','10:40:00','11:00:00','available',NULL,'2026-03-12 14:54:44'),(713,2,'2026-04-11','11:00:00','11:20:00','available',NULL,'2026-03-12 14:54:44'),(714,2,'2026-04-11','11:20:00','11:40:00','available',NULL,'2026-03-12 14:54:44'),(715,2,'2026-04-11','11:40:00','12:00:00','available',NULL,'2026-03-12 14:54:44'),(716,2,'2026-04-11','13:00:00','13:20:00','available',NULL,'2026-03-12 14:54:44'),(717,2,'2026-04-11','13:20:00','13:40:00','available',NULL,'2026-03-12 14:54:44'),(718,2,'2026-04-11','13:40:00','14:00:00','available',NULL,'2026-03-12 14:54:44'),(719,2,'2026-04-11','14:00:00','14:20:00','available',NULL,'2026-03-12 14:54:44'),(720,2,'2026-04-11','14:20:00','14:40:00','available',NULL,'2026-03-12 14:54:44'),(721,2,'2026-04-11','14:40:00','15:00:00','available',NULL,'2026-03-12 14:54:44'),(722,2,'2026-04-11','15:00:00','15:20:00','available',NULL,'2026-03-12 14:54:44'),(723,2,'2026-04-11','15:20:00','15:40:00','available',NULL,'2026-03-12 14:54:44'),(724,2,'2026-04-11','15:40:00','16:00:00','available',NULL,'2026-03-12 14:54:44'),(725,2,'2026-04-11','16:00:00','16:20:00','available',NULL,'2026-03-12 14:54:45'),(726,2,'2026-04-11','16:20:00','16:40:00','available',NULL,'2026-03-12 14:54:45'),(727,2,'2026-04-11','16:40:00','17:00:00','available',NULL,'2026-03-12 14:54:45'),(728,4,'2026-03-12','08:00:00','08:20:00','available',NULL,'2026-03-12 14:54:53'),(729,4,'2026-03-12','08:20:00','08:40:00','available',NULL,'2026-03-12 14:54:53'),(730,4,'2026-03-12','08:40:00','09:00:00','available',NULL,'2026-03-12 14:54:53'),(731,4,'2026-03-12','09:00:00','09:20:00','available',NULL,'2026-03-12 14:54:53'),(732,4,'2026-03-12','09:20:00','09:40:00','available',NULL,'2026-03-12 14:54:53'),(733,4,'2026-03-12','09:40:00','10:00:00','available',NULL,'2026-03-12 14:54:53'),(734,4,'2026-03-12','10:00:00','10:20:00','available',NULL,'2026-03-12 14:54:53'),(735,4,'2026-03-12','10:20:00','10:40:00','available',NULL,'2026-03-12 14:54:53'),(736,4,'2026-03-12','10:40:00','11:00:00','available',NULL,'2026-03-12 14:54:53'),(737,4,'2026-03-12','11:00:00','11:20:00','available',NULL,'2026-03-12 14:54:53'),(738,4,'2026-03-12','11:20:00','11:40:00','available',NULL,'2026-03-12 14:54:53'),(739,4,'2026-03-12','11:40:00','12:00:00','available',NULL,'2026-03-12 14:54:53'),(740,4,'2026-03-12','13:00:00','13:20:00','available',NULL,'2026-03-12 14:54:53'),(741,4,'2026-03-12','13:20:00','13:40:00','available',NULL,'2026-03-12 14:54:53'),(742,4,'2026-03-12','13:40:00','14:00:00','available',NULL,'2026-03-12 14:54:53'),(743,4,'2026-03-12','14:00:00','14:20:00','available',NULL,'2026-03-12 14:54:53'),(744,4,'2026-03-12','14:20:00','14:40:00','available',NULL,'2026-03-12 14:54:53'),(745,4,'2026-03-12','14:40:00','15:00:00','available',NULL,'2026-03-12 14:54:53'),(746,4,'2026-03-12','15:00:00','15:20:00','available',NULL,'2026-03-12 14:54:53'),(747,4,'2026-03-12','15:20:00','15:40:00','available',NULL,'2026-03-12 14:54:53'),(748,4,'2026-03-12','15:40:00','16:00:00','available',NULL,'2026-03-12 14:54:53'),(749,4,'2026-03-12','16:00:00','16:20:00','available',NULL,'2026-03-12 14:54:53'),(750,4,'2026-03-12','16:20:00','16:40:00','available',NULL,'2026-03-12 14:54:53'),(751,4,'2026-03-12','16:40:00','17:00:00','available',NULL,'2026-03-12 14:54:53'),(752,4,'2026-03-13','08:00:00','08:20:00','available',NULL,'2026-03-12 14:54:53'),(753,4,'2026-03-13','08:20:00','08:40:00','available',NULL,'2026-03-12 14:54:53'),(754,4,'2026-03-13','08:40:00','09:00:00','available',NULL,'2026-03-12 14:54:53'),(755,4,'2026-03-13','09:00:00','09:20:00','available',NULL,'2026-03-12 14:54:54'),(756,4,'2026-03-13','09:20:00','09:40:00','available',NULL,'2026-03-12 14:54:54'),(757,4,'2026-03-13','09:40:00','10:00:00','available',NULL,'2026-03-12 14:54:54'),(758,4,'2026-03-13','10:00:00','10:20:00','available',NULL,'2026-03-12 14:54:54'),(759,4,'2026-03-13','10:20:00','10:40:00','available',NULL,'2026-03-12 14:54:54'),(760,4,'2026-03-13','10:40:00','11:00:00','available',NULL,'2026-03-12 14:54:54'),(761,4,'2026-03-13','11:00:00','11:20:00','available',NULL,'2026-03-12 14:54:54'),(762,4,'2026-03-13','11:20:00','11:40:00','available',NULL,'2026-03-12 14:54:54'),(763,4,'2026-03-13','11:40:00','12:00:00','available',NULL,'2026-03-12 14:54:54'),(764,4,'2026-03-13','13:00:00','13:20:00','available',NULL,'2026-03-12 14:54:54'),(765,4,'2026-03-13','13:20:00','13:40:00','available',NULL,'2026-03-12 14:54:54'),(766,4,'2026-03-13','13:40:00','14:00:00','available',NULL,'2026-03-12 14:54:54'),(767,4,'2026-03-13','14:00:00','14:20:00','available',NULL,'2026-03-12 14:54:54'),(768,4,'2026-03-13','14:20:00','14:40:00','available',NULL,'2026-03-12 14:54:54'),(769,4,'2026-03-13','14:40:00','15:00:00','available',NULL,'2026-03-12 14:54:54'),(770,4,'2026-03-13','15:00:00','15:20:00','available',NULL,'2026-03-12 14:54:54'),(771,4,'2026-03-13','15:20:00','15:40:00','available',NULL,'2026-03-12 14:54:54'),(772,4,'2026-03-13','15:40:00','16:00:00','available',NULL,'2026-03-12 14:54:54'),(773,4,'2026-03-13','16:00:00','16:20:00','available',NULL,'2026-03-12 14:54:54'),(774,4,'2026-03-13','16:20:00','16:40:00','available',NULL,'2026-03-12 14:54:54'),(775,4,'2026-03-13','16:40:00','17:00:00','available',NULL,'2026-03-12 14:54:54'),(776,4,'2026-03-14','08:00:00','08:20:00','available',NULL,'2026-03-12 14:54:54'),(777,4,'2026-03-14','08:20:00','08:40:00','available',NULL,'2026-03-12 14:54:54'),(778,4,'2026-03-14','08:40:00','09:00:00','available',NULL,'2026-03-12 14:54:54'),(779,4,'2026-03-14','09:00:00','09:20:00','available',NULL,'2026-03-12 14:54:54'),(780,4,'2026-03-14','09:20:00','09:40:00','available',NULL,'2026-03-12 14:54:54'),(781,4,'2026-03-14','09:40:00','10:00:00','available',NULL,'2026-03-12 14:54:54'),(782,4,'2026-03-14','10:00:00','10:20:00','available',NULL,'2026-03-12 14:54:54'),(783,4,'2026-03-14','10:20:00','10:40:00','available',NULL,'2026-03-12 14:54:54'),(784,4,'2026-03-14','10:40:00','11:00:00','available',NULL,'2026-03-12 14:54:54'),(785,4,'2026-03-14','11:00:00','11:20:00','available',NULL,'2026-03-12 14:54:54'),(786,4,'2026-03-14','11:20:00','11:40:00','available',NULL,'2026-03-12 14:54:54'),(787,4,'2026-03-14','11:40:00','12:00:00','available',NULL,'2026-03-12 14:54:54'),(788,4,'2026-03-14','13:00:00','13:20:00','available',NULL,'2026-03-12 14:54:54'),(789,4,'2026-03-14','13:20:00','13:40:00','available',NULL,'2026-03-12 14:54:54'),(790,4,'2026-03-14','13:40:00','14:00:00','available',NULL,'2026-03-12 14:54:54'),(791,4,'2026-03-14','14:00:00','14:20:00','available',NULL,'2026-03-12 14:54:54'),(792,4,'2026-03-14','14:20:00','14:40:00','available',NULL,'2026-03-12 14:54:54'),(793,4,'2026-03-14','14:40:00','15:00:00','available',NULL,'2026-03-12 14:54:54'),(794,4,'2026-03-14','15:00:00','15:20:00','available',NULL,'2026-03-12 14:54:54'),(795,4,'2026-03-14','15:20:00','15:40:00','available',NULL,'2026-03-12 14:54:54'),(796,4,'2026-03-14','15:40:00','16:00:00','available',NULL,'2026-03-12 14:54:54'),(797,4,'2026-03-14','16:00:00','16:20:00','available',NULL,'2026-03-12 14:54:54'),(798,4,'2026-03-14','16:20:00','16:40:00','available',NULL,'2026-03-12 14:54:54'),(799,4,'2026-03-14','16:40:00','17:00:00','available',NULL,'2026-03-12 14:54:54'),(800,4,'2026-03-16','08:00:00','08:20:00','available',NULL,'2026-03-12 14:54:54'),(801,4,'2026-03-16','08:20:00','08:40:00','available',NULL,'2026-03-12 14:54:54'),(802,4,'2026-03-16','08:40:00','09:00:00','available',NULL,'2026-03-12 14:54:54'),(803,4,'2026-03-16','09:00:00','09:20:00','available',NULL,'2026-03-12 14:54:54'),(804,4,'2026-03-16','09:20:00','09:40:00','available',NULL,'2026-03-12 14:54:54'),(805,4,'2026-03-16','09:40:00','10:00:00','available',NULL,'2026-03-12 14:54:54'),(806,4,'2026-03-16','10:00:00','10:20:00','available',NULL,'2026-03-12 14:54:54'),(807,4,'2026-03-16','10:20:00','10:40:00','available',NULL,'2026-03-12 14:54:54'),(808,4,'2026-03-16','10:40:00','11:00:00','available',NULL,'2026-03-12 14:54:54'),(809,4,'2026-03-16','11:00:00','11:20:00','available',NULL,'2026-03-12 14:54:54'),(810,4,'2026-03-16','11:20:00','11:40:00','available',NULL,'2026-03-12 14:54:54'),(811,4,'2026-03-16','11:40:00','12:00:00','available',NULL,'2026-03-12 14:54:54'),(812,4,'2026-03-16','13:00:00','13:20:00','available',NULL,'2026-03-12 14:54:54'),(813,4,'2026-03-16','13:20:00','13:40:00','available',NULL,'2026-03-12 14:54:54'),(814,4,'2026-03-16','13:40:00','14:00:00','available',NULL,'2026-03-12 14:54:54'),(815,4,'2026-03-16','14:00:00','14:20:00','available',NULL,'2026-03-12 14:54:54'),(816,4,'2026-03-16','14:20:00','14:40:00','available',NULL,'2026-03-12 14:54:54'),(817,4,'2026-03-16','14:40:00','15:00:00','available',NULL,'2026-03-12 14:54:54'),(818,4,'2026-03-16','15:00:00','15:20:00','available',NULL,'2026-03-12 14:54:54'),(819,4,'2026-03-16','15:20:00','15:40:00','available',NULL,'2026-03-12 14:54:54'),(820,4,'2026-03-16','15:40:00','16:00:00','available',NULL,'2026-03-12 14:54:54'),(821,4,'2026-03-16','16:00:00','16:20:00','available',NULL,'2026-03-12 14:54:54'),(822,4,'2026-03-16','16:20:00','16:40:00','available',NULL,'2026-03-12 14:54:54'),(823,4,'2026-03-16','16:40:00','17:00:00','available',NULL,'2026-03-12 14:54:54'),(824,4,'2026-03-17','08:00:00','08:20:00','available',NULL,'2026-03-12 14:54:54'),(825,4,'2026-03-17','08:20:00','08:40:00','available',NULL,'2026-03-12 14:54:54'),(826,4,'2026-03-17','08:40:00','09:00:00','available',NULL,'2026-03-12 14:54:54'),(827,4,'2026-03-17','09:00:00','09:20:00','available',NULL,'2026-03-12 14:54:54'),(828,4,'2026-03-17','09:20:00','09:40:00','available',NULL,'2026-03-12 14:54:54'),(829,4,'2026-03-17','09:40:00','10:00:00','available',NULL,'2026-03-12 14:54:54'),(830,4,'2026-03-17','10:00:00','10:20:00','available',NULL,'2026-03-12 14:54:54'),(831,4,'2026-03-17','10:20:00','10:40:00','available',NULL,'2026-03-12 14:54:54'),(832,4,'2026-03-17','10:40:00','11:00:00','available',NULL,'2026-03-12 14:54:54'),(833,4,'2026-03-17','11:00:00','11:20:00','available',NULL,'2026-03-12 14:54:54'),(834,4,'2026-03-17','11:20:00','11:40:00','available',NULL,'2026-03-12 14:54:54'),(835,4,'2026-03-17','11:40:00','12:00:00','available',NULL,'2026-03-12 14:54:54'),(836,4,'2026-03-17','13:00:00','13:20:00','available',NULL,'2026-03-12 14:54:54'),(837,4,'2026-03-17','13:20:00','13:40:00','available',NULL,'2026-03-12 14:54:54'),(838,4,'2026-03-17','13:40:00','14:00:00','available',NULL,'2026-03-12 14:54:54'),(839,4,'2026-03-17','14:00:00','14:20:00','available',NULL,'2026-03-12 14:54:54'),(840,4,'2026-03-17','14:20:00','14:40:00','available',NULL,'2026-03-12 14:54:54'),(841,4,'2026-03-17','14:40:00','15:00:00','available',NULL,'2026-03-12 14:54:54'),(842,4,'2026-03-17','15:00:00','15:20:00','available',NULL,'2026-03-12 14:54:54'),(843,4,'2026-03-17','15:20:00','15:40:00','available',NULL,'2026-03-12 14:54:54'),(844,4,'2026-03-17','15:40:00','16:00:00','available',NULL,'2026-03-12 14:54:54'),(845,4,'2026-03-17','16:00:00','16:20:00','available',NULL,'2026-03-12 14:54:54'),(846,4,'2026-03-17','16:20:00','16:40:00','available',NULL,'2026-03-12 14:54:54'),(847,4,'2026-03-17','16:40:00','17:00:00','available',NULL,'2026-03-12 14:54:54'),(848,4,'2026-03-18','08:00:00','08:20:00','available',NULL,'2026-03-12 14:54:54'),(849,4,'2026-03-18','08:20:00','08:40:00','available',NULL,'2026-03-12 14:54:54'),(850,4,'2026-03-18','08:40:00','09:00:00','available',NULL,'2026-03-12 14:54:54'),(851,4,'2026-03-18','09:00:00','09:20:00','available',NULL,'2026-03-12 14:54:54'),(852,4,'2026-03-18','09:20:00','09:40:00','available',NULL,'2026-03-12 14:54:54'),(853,4,'2026-03-18','09:40:00','10:00:00','available',NULL,'2026-03-12 14:54:54'),(854,4,'2026-03-18','10:00:00','10:20:00','available',NULL,'2026-03-12 14:54:54'),(855,4,'2026-03-18','10:20:00','10:40:00','available',NULL,'2026-03-12 14:54:54'),(856,4,'2026-03-18','10:40:00','11:00:00','available',NULL,'2026-03-12 14:54:54'),(857,4,'2026-03-18','11:00:00','11:20:00','available',NULL,'2026-03-12 14:54:54'),(858,4,'2026-03-18','11:20:00','11:40:00','available',NULL,'2026-03-12 14:54:54'),(859,4,'2026-03-18','11:40:00','12:00:00','available',NULL,'2026-03-12 14:54:54'),(860,4,'2026-03-18','13:00:00','13:20:00','available',NULL,'2026-03-12 14:54:54'),(861,4,'2026-03-18','13:20:00','13:40:00','available',NULL,'2026-03-12 14:54:54'),(862,4,'2026-03-18','13:40:00','14:00:00','available',NULL,'2026-03-12 14:54:54'),(863,4,'2026-03-18','14:00:00','14:20:00','available',NULL,'2026-03-12 14:54:54'),(864,4,'2026-03-18','14:20:00','14:40:00','available',NULL,'2026-03-12 14:54:54'),(865,4,'2026-03-18','14:40:00','15:00:00','available',NULL,'2026-03-12 14:54:54'),(866,4,'2026-03-18','15:00:00','15:20:00','available',NULL,'2026-03-12 14:54:54'),(867,4,'2026-03-18','15:20:00','15:40:00','available',NULL,'2026-03-12 14:54:54'),(868,4,'2026-03-18','15:40:00','16:00:00','available',NULL,'2026-03-12 14:54:54'),(869,4,'2026-03-18','16:00:00','16:20:00','available',NULL,'2026-03-12 14:54:54'),(870,4,'2026-03-18','16:20:00','16:40:00','available',NULL,'2026-03-12 14:54:54'),(871,4,'2026-03-18','16:40:00','17:00:00','available',NULL,'2026-03-12 14:54:54'),(872,4,'2026-03-19','08:00:00','08:20:00','available',NULL,'2026-03-12 14:54:54'),(873,4,'2026-03-19','08:20:00','08:40:00','available',NULL,'2026-03-12 14:54:54'),(874,4,'2026-03-19','08:40:00','09:00:00','available',NULL,'2026-03-12 14:54:54'),(875,4,'2026-03-19','09:00:00','09:20:00','available',NULL,'2026-03-12 14:54:54'),(876,4,'2026-03-19','09:20:00','09:40:00','available',NULL,'2026-03-12 14:54:54'),(877,4,'2026-03-19','09:40:00','10:00:00','available',NULL,'2026-03-12 14:54:54'),(878,4,'2026-03-19','10:00:00','10:20:00','available',NULL,'2026-03-12 14:54:54'),(879,4,'2026-03-19','10:20:00','10:40:00','available',NULL,'2026-03-12 14:54:54'),(880,4,'2026-03-19','10:40:00','11:00:00','available',NULL,'2026-03-12 14:54:54'),(881,4,'2026-03-19','11:00:00','11:20:00','available',NULL,'2026-03-12 14:54:54'),(882,4,'2026-03-19','11:20:00','11:40:00','available',NULL,'2026-03-12 14:54:54'),(883,4,'2026-03-19','11:40:00','12:00:00','available',NULL,'2026-03-12 14:54:54'),(884,4,'2026-03-19','13:00:00','13:20:00','available',NULL,'2026-03-12 14:54:54'),(885,4,'2026-03-19','13:20:00','13:40:00','available',NULL,'2026-03-12 14:54:54'),(886,4,'2026-03-19','13:40:00','14:00:00','available',NULL,'2026-03-12 14:54:54'),(887,4,'2026-03-19','14:00:00','14:20:00','available',NULL,'2026-03-12 14:54:54'),(888,4,'2026-03-19','14:20:00','14:40:00','available',NULL,'2026-03-12 14:54:54'),(889,4,'2026-03-19','14:40:00','15:00:00','available',NULL,'2026-03-12 14:54:54'),(890,4,'2026-03-19','15:00:00','15:20:00','available',NULL,'2026-03-12 14:54:54'),(891,4,'2026-03-19','15:20:00','15:40:00','available',NULL,'2026-03-12 14:54:54'),(892,4,'2026-03-19','15:40:00','16:00:00','available',NULL,'2026-03-12 14:54:54'),(893,4,'2026-03-19','16:00:00','16:20:00','available',NULL,'2026-03-12 14:54:54'),(894,4,'2026-03-19','16:20:00','16:40:00','available',NULL,'2026-03-12 14:54:54'),(895,4,'2026-03-19','16:40:00','17:00:00','available',NULL,'2026-03-12 14:54:54'),(896,4,'2026-03-20','08:00:00','08:20:00','available',NULL,'2026-03-12 14:54:54'),(897,4,'2026-03-20','08:20:00','08:40:00','available',NULL,'2026-03-12 14:54:54'),(898,4,'2026-03-20','08:40:00','09:00:00','available',NULL,'2026-03-12 14:54:54'),(899,4,'2026-03-20','09:00:00','09:20:00','available',NULL,'2026-03-12 14:54:54'),(900,4,'2026-03-20','09:20:00','09:40:00','available',NULL,'2026-03-12 14:54:54'),(901,4,'2026-03-20','09:40:00','10:00:00','available',NULL,'2026-03-12 14:54:54'),(902,4,'2026-03-20','10:00:00','10:20:00','available',NULL,'2026-03-12 14:54:54'),(903,4,'2026-03-20','10:20:00','10:40:00','available',NULL,'2026-03-12 14:54:54'),(904,4,'2026-03-20','10:40:00','11:00:00','available',NULL,'2026-03-12 14:54:54'),(905,4,'2026-03-20','11:00:00','11:20:00','available',NULL,'2026-03-12 14:54:54'),(906,4,'2026-03-20','11:20:00','11:40:00','available',NULL,'2026-03-12 14:54:54'),(907,4,'2026-03-20','11:40:00','12:00:00','available',NULL,'2026-03-12 14:54:54'),(908,4,'2026-03-20','13:00:00','13:20:00','available',NULL,'2026-03-12 14:54:54'),(909,4,'2026-03-20','13:20:00','13:40:00','available',NULL,'2026-03-12 14:54:54'),(910,4,'2026-03-20','13:40:00','14:00:00','available',NULL,'2026-03-12 14:54:54'),(911,4,'2026-03-20','14:00:00','14:20:00','available',NULL,'2026-03-12 14:54:54'),(912,4,'2026-03-20','14:20:00','14:40:00','available',NULL,'2026-03-12 14:54:54'),(913,4,'2026-03-20','14:40:00','15:00:00','available',NULL,'2026-03-12 14:54:54'),(914,4,'2026-03-20','15:00:00','15:20:00','available',NULL,'2026-03-12 14:54:54'),(915,4,'2026-03-20','15:20:00','15:40:00','available',NULL,'2026-03-12 14:54:54'),(916,4,'2026-03-20','15:40:00','16:00:00','available',NULL,'2026-03-12 14:54:54'),(917,4,'2026-03-20','16:00:00','16:20:00','available',NULL,'2026-03-12 14:54:54'),(918,4,'2026-03-20','16:20:00','16:40:00','available',NULL,'2026-03-12 14:54:54'),(919,4,'2026-03-20','16:40:00','17:00:00','available',NULL,'2026-03-12 14:54:54'),(920,4,'2026-03-21','08:00:00','08:20:00','available',NULL,'2026-03-12 14:54:54'),(921,4,'2026-03-21','08:20:00','08:40:00','available',NULL,'2026-03-12 14:54:54'),(922,4,'2026-03-21','08:40:00','09:00:00','available',NULL,'2026-03-12 14:54:54'),(923,4,'2026-03-21','09:00:00','09:20:00','available',NULL,'2026-03-12 14:54:55'),(924,4,'2026-03-21','09:20:00','09:40:00','available',NULL,'2026-03-12 14:54:55'),(925,4,'2026-03-21','09:40:00','10:00:00','available',NULL,'2026-03-12 14:54:55'),(926,4,'2026-03-21','10:00:00','10:20:00','available',NULL,'2026-03-12 14:54:55'),(927,4,'2026-03-21','10:20:00','10:40:00','available',NULL,'2026-03-12 14:54:55'),(928,4,'2026-03-21','10:40:00','11:00:00','available',NULL,'2026-03-12 14:54:55'),(929,4,'2026-03-21','11:00:00','11:20:00','available',NULL,'2026-03-12 14:54:55'),(930,4,'2026-03-21','11:20:00','11:40:00','available',NULL,'2026-03-12 14:54:55'),(931,4,'2026-03-21','11:40:00','12:00:00','available',NULL,'2026-03-12 14:54:55'),(932,4,'2026-03-21','13:00:00','13:20:00','available',NULL,'2026-03-12 14:54:55'),(933,4,'2026-03-21','13:20:00','13:40:00','available',NULL,'2026-03-12 14:54:55'),(934,4,'2026-03-21','13:40:00','14:00:00','available',NULL,'2026-03-12 14:54:55'),(935,4,'2026-03-21','14:00:00','14:20:00','available',NULL,'2026-03-12 14:54:55'),(936,4,'2026-03-21','14:20:00','14:40:00','available',NULL,'2026-03-12 14:54:55'),(937,4,'2026-03-21','14:40:00','15:00:00','available',NULL,'2026-03-12 14:54:55'),(938,4,'2026-03-21','15:00:00','15:20:00','available',NULL,'2026-03-12 14:54:55'),(939,4,'2026-03-21','15:20:00','15:40:00','available',NULL,'2026-03-12 14:54:55'),(940,4,'2026-03-21','15:40:00','16:00:00','available',NULL,'2026-03-12 14:54:55'),(941,4,'2026-03-21','16:00:00','16:20:00','available',NULL,'2026-03-12 14:54:55'),(942,4,'2026-03-21','16:20:00','16:40:00','available',NULL,'2026-03-12 14:54:55'),(943,4,'2026-03-21','16:40:00','17:00:00','available',NULL,'2026-03-12 14:54:55'),(944,4,'2026-03-23','08:00:00','08:20:00','available',NULL,'2026-03-12 14:54:55'),(945,4,'2026-03-23','08:20:00','08:40:00','available',NULL,'2026-03-12 14:54:55'),(946,4,'2026-03-23','08:40:00','09:00:00','available',NULL,'2026-03-12 14:54:55'),(947,4,'2026-03-23','09:00:00','09:20:00','available',NULL,'2026-03-12 14:54:55'),(948,4,'2026-03-23','09:20:00','09:40:00','available',NULL,'2026-03-12 14:54:55'),(949,4,'2026-03-23','09:40:00','10:00:00','available',NULL,'2026-03-12 14:54:55'),(950,4,'2026-03-23','10:00:00','10:20:00','available',NULL,'2026-03-12 14:54:55'),(951,4,'2026-03-23','10:20:00','10:40:00','available',NULL,'2026-03-12 14:54:55'),(952,4,'2026-03-23','10:40:00','11:00:00','available',NULL,'2026-03-12 14:54:55'),(953,4,'2026-03-23','11:00:00','11:20:00','available',NULL,'2026-03-12 14:54:55'),(954,4,'2026-03-23','11:20:00','11:40:00','available',NULL,'2026-03-12 14:54:55'),(955,4,'2026-03-23','11:40:00','12:00:00','available',NULL,'2026-03-12 14:54:55'),(956,4,'2026-03-23','13:00:00','13:20:00','available',NULL,'2026-03-12 14:54:55'),(957,4,'2026-03-23','13:20:00','13:40:00','available',NULL,'2026-03-12 14:54:55'),(958,4,'2026-03-23','13:40:00','14:00:00','available',NULL,'2026-03-12 14:54:55'),(959,4,'2026-03-23','14:00:00','14:20:00','available',NULL,'2026-03-12 14:54:55'),(960,4,'2026-03-23','14:20:00','14:40:00','available',NULL,'2026-03-12 14:54:55'),(961,4,'2026-03-23','14:40:00','15:00:00','available',NULL,'2026-03-12 14:54:55'),(962,4,'2026-03-23','15:00:00','15:20:00','available',NULL,'2026-03-12 14:54:55'),(963,4,'2026-03-23','15:20:00','15:40:00','available',NULL,'2026-03-12 14:54:55'),(964,4,'2026-03-23','15:40:00','16:00:00','available',NULL,'2026-03-12 14:54:55'),(965,4,'2026-03-23','16:00:00','16:20:00','available',NULL,'2026-03-12 14:54:55'),(966,4,'2026-03-23','16:20:00','16:40:00','available',NULL,'2026-03-12 14:54:55'),(967,4,'2026-03-23','16:40:00','17:00:00','available',NULL,'2026-03-12 14:54:55'),(968,4,'2026-03-24','08:00:00','08:20:00','available',NULL,'2026-03-12 14:54:55'),(969,4,'2026-03-24','08:20:00','08:40:00','available',NULL,'2026-03-12 14:54:55'),(970,4,'2026-03-24','08:40:00','09:00:00','available',NULL,'2026-03-12 14:54:55'),(971,4,'2026-03-24','09:00:00','09:20:00','available',NULL,'2026-03-12 14:54:55'),(972,4,'2026-03-24','09:20:00','09:40:00','available',NULL,'2026-03-12 14:54:55'),(973,4,'2026-03-24','09:40:00','10:00:00','available',NULL,'2026-03-12 14:54:55'),(974,4,'2026-03-24','10:00:00','10:20:00','available',NULL,'2026-03-12 14:54:55'),(975,4,'2026-03-24','10:20:00','10:40:00','available',NULL,'2026-03-12 14:54:55'),(976,4,'2026-03-24','10:40:00','11:00:00','available',NULL,'2026-03-12 14:54:55'),(977,4,'2026-03-24','11:00:00','11:20:00','available',NULL,'2026-03-12 14:54:55'),(978,4,'2026-03-24','11:20:00','11:40:00','available',NULL,'2026-03-12 14:54:55'),(979,4,'2026-03-24','11:40:00','12:00:00','available',NULL,'2026-03-12 14:54:55'),(980,4,'2026-03-24','13:00:00','13:20:00','available',NULL,'2026-03-12 14:54:55'),(981,4,'2026-03-24','13:20:00','13:40:00','available',NULL,'2026-03-12 14:54:55'),(982,4,'2026-03-24','13:40:00','14:00:00','available',NULL,'2026-03-12 14:54:55'),(983,4,'2026-03-24','14:00:00','14:20:00','available',NULL,'2026-03-12 14:54:55'),(984,4,'2026-03-24','14:20:00','14:40:00','available',NULL,'2026-03-12 14:54:55'),(985,4,'2026-03-24','14:40:00','15:00:00','available',NULL,'2026-03-12 14:54:55'),(986,4,'2026-03-24','15:00:00','15:20:00','available',NULL,'2026-03-12 14:54:55'),(987,4,'2026-03-24','15:20:00','15:40:00','available',NULL,'2026-03-12 14:54:55'),(988,4,'2026-03-24','15:40:00','16:00:00','available',NULL,'2026-03-12 14:54:55'),(989,4,'2026-03-24','16:00:00','16:20:00','available',NULL,'2026-03-12 14:54:55'),(990,4,'2026-03-24','16:20:00','16:40:00','available',NULL,'2026-03-12 14:54:55'),(991,4,'2026-03-24','16:40:00','17:00:00','available',NULL,'2026-03-12 14:54:55'),(992,4,'2026-03-25','08:00:00','08:20:00','available',NULL,'2026-03-12 14:54:55'),(993,4,'2026-03-25','08:20:00','08:40:00','available',NULL,'2026-03-12 14:54:55'),(994,4,'2026-03-25','08:40:00','09:00:00','available',NULL,'2026-03-12 14:54:55'),(995,4,'2026-03-25','09:00:00','09:20:00','available',NULL,'2026-03-12 14:54:55'),(996,4,'2026-03-25','09:20:00','09:40:00','available',NULL,'2026-03-12 14:54:55'),(997,4,'2026-03-25','09:40:00','10:00:00','available',NULL,'2026-03-12 14:54:55'),(998,4,'2026-03-25','10:00:00','10:20:00','available',NULL,'2026-03-12 14:54:55'),(999,4,'2026-03-25','10:20:00','10:40:00','available',NULL,'2026-03-12 14:54:55'),(1000,4,'2026-03-25','10:40:00','11:00:00','available',NULL,'2026-03-12 14:54:55'),(1001,4,'2026-03-25','11:00:00','11:20:00','available',NULL,'2026-03-12 14:54:55'),(1002,4,'2026-03-25','11:20:00','11:40:00','available',NULL,'2026-03-12 14:54:55'),(1003,4,'2026-03-25','11:40:00','12:00:00','available',NULL,'2026-03-12 14:54:55'),(1004,4,'2026-03-25','13:00:00','13:20:00','available',NULL,'2026-03-12 14:54:55'),(1005,4,'2026-03-25','13:20:00','13:40:00','available',NULL,'2026-03-12 14:54:55'),(1006,4,'2026-03-25','13:40:00','14:00:00','available',NULL,'2026-03-12 14:54:55'),(1007,4,'2026-03-25','14:00:00','14:20:00','available',NULL,'2026-03-12 14:54:55'),(1008,4,'2026-03-25','14:20:00','14:40:00','available',NULL,'2026-03-12 14:54:55'),(1009,4,'2026-03-25','14:40:00','15:00:00','available',NULL,'2026-03-12 14:54:55'),(1010,4,'2026-03-25','15:00:00','15:20:00','available',NULL,'2026-03-12 14:54:55'),(1011,4,'2026-03-25','15:20:00','15:40:00','available',NULL,'2026-03-12 14:54:55'),(1012,4,'2026-03-25','15:40:00','16:00:00','available',NULL,'2026-03-12 14:54:55'),(1013,4,'2026-03-25','16:00:00','16:20:00','available',NULL,'2026-03-12 14:54:55'),(1014,4,'2026-03-25','16:20:00','16:40:00','available',NULL,'2026-03-12 14:54:55'),(1015,4,'2026-03-25','16:40:00','17:00:00','available',NULL,'2026-03-12 14:54:55'),(1016,4,'2026-03-26','08:00:00','08:20:00','available',NULL,'2026-03-12 14:54:55'),(1017,4,'2026-03-26','08:20:00','08:40:00','available',NULL,'2026-03-12 14:54:55'),(1018,4,'2026-03-26','08:40:00','09:00:00','available',NULL,'2026-03-12 14:54:55'),(1019,4,'2026-03-26','09:00:00','09:20:00','available',NULL,'2026-03-12 14:54:55'),(1020,4,'2026-03-26','09:20:00','09:40:00','available',NULL,'2026-03-12 14:54:55'),(1021,4,'2026-03-26','09:40:00','10:00:00','available',NULL,'2026-03-12 14:54:55'),(1022,4,'2026-03-26','10:00:00','10:20:00','available',NULL,'2026-03-12 14:54:55'),(1023,4,'2026-03-26','10:20:00','10:40:00','available',NULL,'2026-03-12 14:54:55'),(1024,4,'2026-03-26','10:40:00','11:00:00','available',NULL,'2026-03-12 14:54:55'),(1025,4,'2026-03-26','11:00:00','11:20:00','available',NULL,'2026-03-12 14:54:55'),(1026,4,'2026-03-26','11:20:00','11:40:00','available',NULL,'2026-03-12 14:54:55'),(1027,4,'2026-03-26','11:40:00','12:00:00','available',NULL,'2026-03-12 14:54:55'),(1028,4,'2026-03-26','13:00:00','13:20:00','available',NULL,'2026-03-12 14:54:55'),(1029,4,'2026-03-26','13:20:00','13:40:00','available',NULL,'2026-03-12 14:54:55'),(1030,4,'2026-03-26','13:40:00','14:00:00','available',NULL,'2026-03-12 14:54:55'),(1031,4,'2026-03-26','14:00:00','14:20:00','available',NULL,'2026-03-12 14:54:55'),(1032,4,'2026-03-26','14:20:00','14:40:00','available',NULL,'2026-03-12 14:54:55'),(1033,4,'2026-03-26','14:40:00','15:00:00','available',NULL,'2026-03-12 14:54:55'),(1034,4,'2026-03-26','15:00:00','15:20:00','available',NULL,'2026-03-12 14:54:55'),(1035,4,'2026-03-26','15:20:00','15:40:00','available',NULL,'2026-03-12 14:54:55'),(1036,4,'2026-03-26','15:40:00','16:00:00','available',NULL,'2026-03-12 14:54:55'),(1037,4,'2026-03-26','16:00:00','16:20:00','available',NULL,'2026-03-12 14:54:55'),(1038,4,'2026-03-26','16:20:00','16:40:00','available',NULL,'2026-03-12 14:54:55'),(1039,4,'2026-03-26','16:40:00','17:00:00','available',NULL,'2026-03-12 14:54:55'),(1040,4,'2026-03-27','08:00:00','08:20:00','available',NULL,'2026-03-12 14:54:55'),(1041,4,'2026-03-27','08:20:00','08:40:00','available',NULL,'2026-03-12 14:54:55'),(1042,4,'2026-03-27','08:40:00','09:00:00','available',NULL,'2026-03-12 14:54:55'),(1043,4,'2026-03-27','09:00:00','09:20:00','available',NULL,'2026-03-12 14:54:55'),(1044,4,'2026-03-27','09:20:00','09:40:00','available',NULL,'2026-03-12 14:54:55'),(1045,4,'2026-03-27','09:40:00','10:00:00','available',NULL,'2026-03-12 14:54:55'),(1046,4,'2026-03-27','10:00:00','10:20:00','available',NULL,'2026-03-12 14:54:55'),(1047,4,'2026-03-27','10:20:00','10:40:00','available',NULL,'2026-03-12 14:54:55'),(1048,4,'2026-03-27','10:40:00','11:00:00','available',NULL,'2026-03-12 14:54:55'),(1049,4,'2026-03-27','11:00:00','11:20:00','available',NULL,'2026-03-12 14:54:55'),(1050,4,'2026-03-27','11:20:00','11:40:00','available',NULL,'2026-03-12 14:54:55'),(1051,4,'2026-03-27','11:40:00','12:00:00','available',NULL,'2026-03-12 14:54:55'),(1052,4,'2026-03-27','13:00:00','13:20:00','available',NULL,'2026-03-12 14:54:55'),(1053,4,'2026-03-27','13:20:00','13:40:00','available',NULL,'2026-03-12 14:54:55'),(1054,4,'2026-03-27','13:40:00','14:00:00','available',NULL,'2026-03-12 14:54:55'),(1055,4,'2026-03-27','14:00:00','14:20:00','available',NULL,'2026-03-12 14:54:55'),(1056,4,'2026-03-27','14:20:00','14:40:00','available',NULL,'2026-03-12 14:54:55'),(1057,4,'2026-03-27','14:40:00','15:00:00','available',NULL,'2026-03-12 14:54:55'),(1058,4,'2026-03-27','15:00:00','15:20:00','available',NULL,'2026-03-12 14:54:55'),(1059,4,'2026-03-27','15:20:00','15:40:00','available',NULL,'2026-03-12 14:54:55'),(1060,4,'2026-03-27','15:40:00','16:00:00','available',NULL,'2026-03-12 14:54:55'),(1061,4,'2026-03-27','16:00:00','16:20:00','available',NULL,'2026-03-12 14:54:55'),(1062,4,'2026-03-27','16:20:00','16:40:00','available',NULL,'2026-03-12 14:54:55'),(1063,4,'2026-03-27','16:40:00','17:00:00','available',NULL,'2026-03-12 14:54:55'),(1064,4,'2026-03-28','08:00:00','08:20:00','available',NULL,'2026-03-12 14:54:55'),(1065,4,'2026-03-28','08:20:00','08:40:00','available',NULL,'2026-03-12 14:54:55'),(1066,4,'2026-03-28','08:40:00','09:00:00','available',NULL,'2026-03-12 14:54:55'),(1067,4,'2026-03-28','09:00:00','09:20:00','available',NULL,'2026-03-12 14:54:55'),(1068,4,'2026-03-28','09:20:00','09:40:00','available',NULL,'2026-03-12 14:54:55'),(1069,4,'2026-03-28','09:40:00','10:00:00','available',NULL,'2026-03-12 14:54:55'),(1070,4,'2026-03-28','10:00:00','10:20:00','available',NULL,'2026-03-12 14:54:55'),(1071,4,'2026-03-28','10:20:00','10:40:00','available',NULL,'2026-03-12 14:54:55'),(1072,4,'2026-03-28','10:40:00','11:00:00','available',NULL,'2026-03-12 14:54:55'),(1073,4,'2026-03-28','11:00:00','11:20:00','available',NULL,'2026-03-12 14:54:55'),(1074,4,'2026-03-28','11:20:00','11:40:00','available',NULL,'2026-03-12 14:54:55'),(1075,4,'2026-03-28','11:40:00','12:00:00','available',NULL,'2026-03-12 14:54:55'),(1076,4,'2026-03-28','13:00:00','13:20:00','available',NULL,'2026-03-12 14:54:55'),(1077,4,'2026-03-28','13:20:00','13:40:00','available',NULL,'2026-03-12 14:54:55'),(1078,4,'2026-03-28','13:40:00','14:00:00','available',NULL,'2026-03-12 14:54:55'),(1079,4,'2026-03-28','14:00:00','14:20:00','available',NULL,'2026-03-12 14:54:55'),(1080,4,'2026-03-28','14:20:00','14:40:00','available',NULL,'2026-03-12 14:54:55'),(1081,4,'2026-03-28','14:40:00','15:00:00','available',NULL,'2026-03-12 14:54:56'),(1082,4,'2026-03-28','15:00:00','15:20:00','available',NULL,'2026-03-12 14:54:56'),(1083,4,'2026-03-28','15:20:00','15:40:00','available',NULL,'2026-03-12 14:54:56'),(1084,4,'2026-03-28','15:40:00','16:00:00','available',NULL,'2026-03-12 14:54:56'),(1085,4,'2026-03-28','16:00:00','16:20:00','available',NULL,'2026-03-12 14:54:56'),(1086,4,'2026-03-28','16:20:00','16:40:00','available',NULL,'2026-03-12 14:54:56'),(1087,4,'2026-03-28','16:40:00','17:00:00','available',NULL,'2026-03-12 14:54:56'),(1088,4,'2026-03-30','08:00:00','08:20:00','available',NULL,'2026-03-12 14:54:56'),(1089,4,'2026-03-30','08:20:00','08:40:00','available',NULL,'2026-03-12 14:54:56'),(1090,4,'2026-03-30','08:40:00','09:00:00','available',NULL,'2026-03-12 14:54:56'),(1091,4,'2026-03-30','09:00:00','09:20:00','available',NULL,'2026-03-12 14:54:56'),(1092,4,'2026-03-30','09:20:00','09:40:00','available',NULL,'2026-03-12 14:54:56'),(1093,4,'2026-03-30','09:40:00','10:00:00','available',NULL,'2026-03-12 14:54:56'),(1094,4,'2026-03-30','10:00:00','10:20:00','available',NULL,'2026-03-12 14:54:56'),(1095,4,'2026-03-30','10:20:00','10:40:00','available',NULL,'2026-03-12 14:54:56'),(1096,4,'2026-03-30','10:40:00','11:00:00','available',NULL,'2026-03-12 14:54:56'),(1097,4,'2026-03-30','11:00:00','11:20:00','available',NULL,'2026-03-12 14:54:56'),(1098,4,'2026-03-30','11:20:00','11:40:00','available',NULL,'2026-03-12 14:54:56'),(1099,4,'2026-03-30','11:40:00','12:00:00','available',NULL,'2026-03-12 14:54:56'),(1100,4,'2026-03-30','13:00:00','13:20:00','available',NULL,'2026-03-12 14:54:56'),(1101,4,'2026-03-30','13:20:00','13:40:00','available',NULL,'2026-03-12 14:54:56'),(1102,4,'2026-03-30','13:40:00','14:00:00','available',NULL,'2026-03-12 14:54:56'),(1103,4,'2026-03-30','14:00:00','14:20:00','available',NULL,'2026-03-12 14:54:56'),(1104,4,'2026-03-30','14:20:00','14:40:00','available',NULL,'2026-03-12 14:54:56'),(1105,4,'2026-03-30','14:40:00','15:00:00','available',NULL,'2026-03-12 14:54:56'),(1106,4,'2026-03-30','15:00:00','15:20:00','available',NULL,'2026-03-12 14:54:56'),(1107,4,'2026-03-30','15:20:00','15:40:00','available',NULL,'2026-03-12 14:54:56'),(1108,4,'2026-03-30','15:40:00','16:00:00','available',NULL,'2026-03-12 14:54:56'),(1109,4,'2026-03-30','16:00:00','16:20:00','available',NULL,'2026-03-12 14:54:56'),(1110,4,'2026-03-30','16:20:00','16:40:00','available',NULL,'2026-03-12 14:54:56'),(1111,4,'2026-03-30','16:40:00','17:00:00','available',NULL,'2026-03-12 14:54:56'),(1112,4,'2026-03-31','08:00:00','08:20:00','available',NULL,'2026-03-12 14:54:56'),(1113,4,'2026-03-31','08:20:00','08:40:00','available',NULL,'2026-03-12 14:54:56'),(1114,4,'2026-03-31','08:40:00','09:00:00','available',NULL,'2026-03-12 14:54:56'),(1115,4,'2026-03-31','09:00:00','09:20:00','available',NULL,'2026-03-12 14:54:56'),(1116,4,'2026-03-31','09:20:00','09:40:00','available',NULL,'2026-03-12 14:54:56'),(1117,4,'2026-03-31','09:40:00','10:00:00','available',NULL,'2026-03-12 14:54:56'),(1118,4,'2026-03-31','10:00:00','10:20:00','available',NULL,'2026-03-12 14:54:56'),(1119,4,'2026-03-31','10:20:00','10:40:00','available',NULL,'2026-03-12 14:54:56'),(1120,4,'2026-03-31','10:40:00','11:00:00','available',NULL,'2026-03-12 14:54:56'),(1121,4,'2026-03-31','11:00:00','11:20:00','available',NULL,'2026-03-12 14:54:56'),(1122,4,'2026-03-31','11:20:00','11:40:00','available',NULL,'2026-03-12 14:54:56'),(1123,4,'2026-03-31','11:40:00','12:00:00','available',NULL,'2026-03-12 14:54:56'),(1124,4,'2026-03-31','13:00:00','13:20:00','available',NULL,'2026-03-12 14:54:56'),(1125,4,'2026-03-31','13:20:00','13:40:00','available',NULL,'2026-03-12 14:54:56'),(1126,4,'2026-03-31','13:40:00','14:00:00','available',NULL,'2026-03-12 14:54:56'),(1127,4,'2026-03-31','14:00:00','14:20:00','available',NULL,'2026-03-12 14:54:56'),(1128,4,'2026-03-31','14:20:00','14:40:00','available',NULL,'2026-03-12 14:54:56'),(1129,4,'2026-03-31','14:40:00','15:00:00','available',NULL,'2026-03-12 14:54:56'),(1130,4,'2026-03-31','15:00:00','15:20:00','available',NULL,'2026-03-12 14:54:56'),(1131,4,'2026-03-31','15:20:00','15:40:00','available',NULL,'2026-03-12 14:54:56'),(1132,4,'2026-03-31','15:40:00','16:00:00','available',NULL,'2026-03-12 14:54:56'),(1133,4,'2026-03-31','16:00:00','16:20:00','available',NULL,'2026-03-12 14:54:56'),(1134,4,'2026-03-31','16:20:00','16:40:00','available',NULL,'2026-03-12 14:54:56'),(1135,4,'2026-03-31','16:40:00','17:00:00','available',NULL,'2026-03-12 14:54:56'),(1136,4,'2026-04-01','08:00:00','08:20:00','available',NULL,'2026-03-12 14:54:56'),(1137,4,'2026-04-01','08:20:00','08:40:00','available',NULL,'2026-03-12 14:54:56'),(1138,4,'2026-04-01','08:40:00','09:00:00','available',NULL,'2026-03-12 14:54:56'),(1139,4,'2026-04-01','09:00:00','09:20:00','available',NULL,'2026-03-12 14:54:56'),(1140,4,'2026-04-01','09:20:00','09:40:00','available',NULL,'2026-03-12 14:54:56'),(1141,4,'2026-04-01','09:40:00','10:00:00','available',NULL,'2026-03-12 14:54:56'),(1142,4,'2026-04-01','10:00:00','10:20:00','available',NULL,'2026-03-12 14:54:56'),(1143,4,'2026-04-01','10:20:00','10:40:00','available',NULL,'2026-03-12 14:54:56'),(1144,4,'2026-04-01','10:40:00','11:00:00','available',NULL,'2026-03-12 14:54:56'),(1145,4,'2026-04-01','11:00:00','11:20:00','available',NULL,'2026-03-12 14:54:56'),(1146,4,'2026-04-01','11:20:00','11:40:00','available',NULL,'2026-03-12 14:54:56'),(1147,4,'2026-04-01','11:40:00','12:00:00','available',NULL,'2026-03-12 14:54:56'),(1148,4,'2026-04-01','13:00:00','13:20:00','available',NULL,'2026-03-12 14:54:56'),(1149,4,'2026-04-01','13:20:00','13:40:00','available',NULL,'2026-03-12 14:54:56'),(1150,4,'2026-04-01','13:40:00','14:00:00','available',NULL,'2026-03-12 14:54:56'),(1151,4,'2026-04-01','14:00:00','14:20:00','available',NULL,'2026-03-12 14:54:56'),(1152,4,'2026-04-01','14:20:00','14:40:00','available',NULL,'2026-03-12 14:54:56'),(1153,4,'2026-04-01','14:40:00','15:00:00','available',NULL,'2026-03-12 14:54:56'),(1154,4,'2026-04-01','15:00:00','15:20:00','available',NULL,'2026-03-12 14:54:56'),(1155,4,'2026-04-01','15:20:00','15:40:00','available',NULL,'2026-03-12 14:54:56'),(1156,4,'2026-04-01','15:40:00','16:00:00','available',NULL,'2026-03-12 14:54:56'),(1157,4,'2026-04-01','16:00:00','16:20:00','available',NULL,'2026-03-12 14:54:56'),(1158,4,'2026-04-01','16:20:00','16:40:00','available',NULL,'2026-03-12 14:54:56'),(1159,4,'2026-04-01','16:40:00','17:00:00','available',NULL,'2026-03-12 14:54:56'),(1160,4,'2026-04-02','08:00:00','08:20:00','available',NULL,'2026-03-12 14:54:56'),(1161,4,'2026-04-02','08:20:00','08:40:00','available',NULL,'2026-03-12 14:54:56'),(1162,4,'2026-04-02','08:40:00','09:00:00','available',NULL,'2026-03-12 14:54:56'),(1163,4,'2026-04-02','09:00:00','09:20:00','available',NULL,'2026-03-12 14:54:56'),(1164,4,'2026-04-02','09:20:00','09:40:00','available',NULL,'2026-03-12 14:54:56'),(1165,4,'2026-04-02','09:40:00','10:00:00','available',NULL,'2026-03-12 14:54:56'),(1166,4,'2026-04-02','10:00:00','10:20:00','available',NULL,'2026-03-12 14:54:56'),(1167,4,'2026-04-02','10:20:00','10:40:00','available',NULL,'2026-03-12 14:54:56'),(1168,4,'2026-04-02','10:40:00','11:00:00','available',NULL,'2026-03-12 14:54:56'),(1169,4,'2026-04-02','11:00:00','11:20:00','available',NULL,'2026-03-12 14:54:56'),(1170,4,'2026-04-02','11:20:00','11:40:00','available',NULL,'2026-03-12 14:54:56'),(1171,4,'2026-04-02','11:40:00','12:00:00','available',NULL,'2026-03-12 14:54:56'),(1172,4,'2026-04-02','13:00:00','13:20:00','available',NULL,'2026-03-12 14:54:56'),(1173,4,'2026-04-02','13:20:00','13:40:00','available',NULL,'2026-03-12 14:54:56'),(1174,4,'2026-04-02','13:40:00','14:00:00','available',NULL,'2026-03-12 14:54:56'),(1175,4,'2026-04-02','14:00:00','14:20:00','available',NULL,'2026-03-12 14:54:56'),(1176,4,'2026-04-02','14:20:00','14:40:00','available',NULL,'2026-03-12 14:54:56'),(1177,4,'2026-04-02','14:40:00','15:00:00','available',NULL,'2026-03-12 14:54:56'),(1178,4,'2026-04-02','15:00:00','15:20:00','available',NULL,'2026-03-12 14:54:56'),(1179,4,'2026-04-02','15:20:00','15:40:00','available',NULL,'2026-03-12 14:54:56'),(1180,4,'2026-04-02','15:40:00','16:00:00','available',NULL,'2026-03-12 14:54:56'),(1181,4,'2026-04-02','16:00:00','16:20:00','available',NULL,'2026-03-12 14:54:56'),(1182,4,'2026-04-02','16:20:00','16:40:00','available',NULL,'2026-03-12 14:54:56'),(1183,4,'2026-04-02','16:40:00','17:00:00','available',NULL,'2026-03-12 14:54:56'),(1184,4,'2026-04-03','08:00:00','08:20:00','available',NULL,'2026-03-12 14:54:56'),(1185,4,'2026-04-03','08:20:00','08:40:00','available',NULL,'2026-03-12 14:54:56'),(1186,4,'2026-04-03','08:40:00','09:00:00','available',NULL,'2026-03-12 14:54:56'),(1187,4,'2026-04-03','09:00:00','09:20:00','available',NULL,'2026-03-12 14:54:56'),(1188,4,'2026-04-03','09:20:00','09:40:00','available',NULL,'2026-03-12 14:54:56'),(1189,4,'2026-04-03','09:40:00','10:00:00','available',NULL,'2026-03-12 14:54:56'),(1190,4,'2026-04-03','10:00:00','10:20:00','available',NULL,'2026-03-12 14:54:56'),(1191,4,'2026-04-03','10:20:00','10:40:00','available',NULL,'2026-03-12 14:54:56'),(1192,4,'2026-04-03','10:40:00','11:00:00','available',NULL,'2026-03-12 14:54:56'),(1193,4,'2026-04-03','11:00:00','11:20:00','available',NULL,'2026-03-12 14:54:56'),(1194,4,'2026-04-03','11:20:00','11:40:00','available',NULL,'2026-03-12 14:54:56'),(1195,4,'2026-04-03','11:40:00','12:00:00','available',NULL,'2026-03-12 14:54:56'),(1196,4,'2026-04-03','13:00:00','13:20:00','available',NULL,'2026-03-12 14:54:56'),(1197,4,'2026-04-03','13:20:00','13:40:00','available',NULL,'2026-03-12 14:54:56'),(1198,4,'2026-04-03','13:40:00','14:00:00','available',NULL,'2026-03-12 14:54:56'),(1199,4,'2026-04-03','14:00:00','14:20:00','available',NULL,'2026-03-12 14:54:56'),(1200,4,'2026-04-03','14:20:00','14:40:00','available',NULL,'2026-03-12 14:54:56'),(1201,4,'2026-04-03','14:40:00','15:00:00','available',NULL,'2026-03-12 14:54:56'),(1202,4,'2026-04-03','15:00:00','15:20:00','available',NULL,'2026-03-12 14:54:56'),(1203,4,'2026-04-03','15:20:00','15:40:00','available',NULL,'2026-03-12 14:54:56'),(1204,4,'2026-04-03','15:40:00','16:00:00','available',NULL,'2026-03-12 14:54:57'),(1205,4,'2026-04-03','16:00:00','16:20:00','available',NULL,'2026-03-12 14:54:57'),(1206,4,'2026-04-03','16:20:00','16:40:00','available',NULL,'2026-03-12 14:54:57'),(1207,4,'2026-04-03','16:40:00','17:00:00','available',NULL,'2026-03-12 14:54:57'),(1208,4,'2026-04-04','08:00:00','08:20:00','available',NULL,'2026-03-12 14:54:57'),(1209,4,'2026-04-04','08:20:00','08:40:00','available',NULL,'2026-03-12 14:54:57'),(1210,4,'2026-04-04','08:40:00','09:00:00','available',NULL,'2026-03-12 14:54:57'),(1211,4,'2026-04-04','09:00:00','09:20:00','available',NULL,'2026-03-12 14:54:57'),(1212,4,'2026-04-04','09:20:00','09:40:00','available',NULL,'2026-03-12 14:54:57'),(1213,4,'2026-04-04','09:40:00','10:00:00','available',NULL,'2026-03-12 14:54:57'),(1214,4,'2026-04-04','10:00:00','10:20:00','available',NULL,'2026-03-12 14:54:57'),(1215,4,'2026-04-04','10:20:00','10:40:00','available',NULL,'2026-03-12 14:54:57'),(1216,4,'2026-04-04','10:40:00','11:00:00','available',NULL,'2026-03-12 14:54:57'),(1217,4,'2026-04-04','11:00:00','11:20:00','available',NULL,'2026-03-12 14:54:57'),(1218,4,'2026-04-04','11:20:00','11:40:00','available',NULL,'2026-03-12 14:54:57'),(1219,4,'2026-04-04','11:40:00','12:00:00','available',NULL,'2026-03-12 14:54:57'),(1220,4,'2026-04-04','13:00:00','13:20:00','available',NULL,'2026-03-12 14:54:57'),(1221,4,'2026-04-04','13:20:00','13:40:00','available',NULL,'2026-03-12 14:54:57'),(1222,4,'2026-04-04','13:40:00','14:00:00','available',NULL,'2026-03-12 14:54:57'),(1223,4,'2026-04-04','14:00:00','14:20:00','available',NULL,'2026-03-12 14:54:57'),(1224,4,'2026-04-04','14:20:00','14:40:00','available',NULL,'2026-03-12 14:54:57'),(1225,4,'2026-04-04','14:40:00','15:00:00','available',NULL,'2026-03-12 14:54:57'),(1226,4,'2026-04-04','15:00:00','15:20:00','available',NULL,'2026-03-12 14:54:57'),(1227,4,'2026-04-04','15:20:00','15:40:00','available',NULL,'2026-03-12 14:54:57'),(1228,4,'2026-04-04','15:40:00','16:00:00','available',NULL,'2026-03-12 14:54:57'),(1229,4,'2026-04-04','16:00:00','16:20:00','available',NULL,'2026-03-12 14:54:57'),(1230,4,'2026-04-04','16:20:00','16:40:00','available',NULL,'2026-03-12 14:54:57'),(1231,4,'2026-04-04','16:40:00','17:00:00','available',NULL,'2026-03-12 14:54:57'),(1232,4,'2026-04-06','08:00:00','08:20:00','available',NULL,'2026-03-12 14:54:57'),(1233,4,'2026-04-06','08:20:00','08:40:00','available',NULL,'2026-03-12 14:54:57'),(1234,4,'2026-04-06','08:40:00','09:00:00','available',NULL,'2026-03-12 14:54:57'),(1235,4,'2026-04-06','09:00:00','09:20:00','available',NULL,'2026-03-12 14:54:57'),(1236,4,'2026-04-06','09:20:00','09:40:00','available',NULL,'2026-03-12 14:54:57'),(1237,4,'2026-04-06','09:40:00','10:00:00','available',NULL,'2026-03-12 14:54:57'),(1238,4,'2026-04-06','10:00:00','10:20:00','available',NULL,'2026-03-12 14:54:57'),(1239,4,'2026-04-06','10:20:00','10:40:00','available',NULL,'2026-03-12 14:54:57'),(1240,4,'2026-04-06','10:40:00','11:00:00','available',NULL,'2026-03-12 14:54:57'),(1241,4,'2026-04-06','11:00:00','11:20:00','available',NULL,'2026-03-12 14:54:57'),(1242,4,'2026-04-06','11:20:00','11:40:00','available',NULL,'2026-03-12 14:54:57'),(1243,4,'2026-04-06','11:40:00','12:00:00','available',NULL,'2026-03-12 14:54:57'),(1244,4,'2026-04-06','13:00:00','13:20:00','available',NULL,'2026-03-12 14:54:57'),(1245,4,'2026-04-06','13:20:00','13:40:00','available',NULL,'2026-03-12 14:54:57'),(1246,4,'2026-04-06','13:40:00','14:00:00','available',NULL,'2026-03-12 14:54:57'),(1247,4,'2026-04-06','14:00:00','14:20:00','available',NULL,'2026-03-12 14:54:57'),(1248,4,'2026-04-06','14:20:00','14:40:00','available',NULL,'2026-03-12 14:54:57'),(1249,4,'2026-04-06','14:40:00','15:00:00','available',NULL,'2026-03-12 14:54:57'),(1250,4,'2026-04-06','15:00:00','15:20:00','available',NULL,'2026-03-12 14:54:57'),(1251,4,'2026-04-06','15:20:00','15:40:00','available',NULL,'2026-03-12 14:54:57'),(1252,4,'2026-04-06','15:40:00','16:00:00','available',NULL,'2026-03-12 14:54:57'),(1253,4,'2026-04-06','16:00:00','16:20:00','available',NULL,'2026-03-12 14:54:57'),(1254,4,'2026-04-06','16:20:00','16:40:00','available',NULL,'2026-03-12 14:54:57'),(1255,4,'2026-04-06','16:40:00','17:00:00','available',NULL,'2026-03-12 14:54:57'),(1256,4,'2026-04-07','08:00:00','08:20:00','available',NULL,'2026-03-12 14:54:57'),(1257,4,'2026-04-07','08:20:00','08:40:00','available',NULL,'2026-03-12 14:54:57'),(1258,4,'2026-04-07','08:40:00','09:00:00','available',NULL,'2026-03-12 14:54:57'),(1259,4,'2026-04-07','09:00:00','09:20:00','available',NULL,'2026-03-12 14:54:57'),(1260,4,'2026-04-07','09:20:00','09:40:00','available',NULL,'2026-03-12 14:54:57'),(1261,4,'2026-04-07','09:40:00','10:00:00','available',NULL,'2026-03-12 14:54:57'),(1262,4,'2026-04-07','10:00:00','10:20:00','available',NULL,'2026-03-12 14:54:57'),(1263,4,'2026-04-07','10:20:00','10:40:00','available',NULL,'2026-03-12 14:54:57'),(1264,4,'2026-04-07','10:40:00','11:00:00','available',NULL,'2026-03-12 14:54:57'),(1265,4,'2026-04-07','11:00:00','11:20:00','available',NULL,'2026-03-12 14:54:57'),(1266,4,'2026-04-07','11:20:00','11:40:00','available',NULL,'2026-03-12 14:54:57'),(1267,4,'2026-04-07','11:40:00','12:00:00','available',NULL,'2026-03-12 14:54:57'),(1268,4,'2026-04-07','13:00:00','13:20:00','available',NULL,'2026-03-12 14:54:57'),(1269,4,'2026-04-07','13:20:00','13:40:00','available',NULL,'2026-03-12 14:54:57'),(1270,4,'2026-04-07','13:40:00','14:00:00','available',NULL,'2026-03-12 14:54:57'),(1271,4,'2026-04-07','14:00:00','14:20:00','available',NULL,'2026-03-12 14:54:57'),(1272,4,'2026-04-07','14:20:00','14:40:00','available',NULL,'2026-03-12 14:54:57'),(1273,4,'2026-04-07','14:40:00','15:00:00','available',NULL,'2026-03-12 14:54:57'),(1274,4,'2026-04-07','15:00:00','15:20:00','available',NULL,'2026-03-12 14:54:57'),(1275,4,'2026-04-07','15:20:00','15:40:00','available',NULL,'2026-03-12 14:54:57'),(1276,4,'2026-04-07','15:40:00','16:00:00','available',NULL,'2026-03-12 14:54:57'),(1277,4,'2026-04-07','16:00:00','16:20:00','available',NULL,'2026-03-12 14:54:57'),(1278,4,'2026-04-07','16:20:00','16:40:00','available',NULL,'2026-03-12 14:54:57'),(1279,4,'2026-04-07','16:40:00','17:00:00','available',NULL,'2026-03-12 14:54:57'),(1280,4,'2026-04-08','08:00:00','08:20:00','available',NULL,'2026-03-12 14:54:57'),(1281,4,'2026-04-08','08:20:00','08:40:00','available',NULL,'2026-03-12 14:54:57'),(1282,4,'2026-04-08','08:40:00','09:00:00','available',NULL,'2026-03-12 14:54:57'),(1283,4,'2026-04-08','09:00:00','09:20:00','available',NULL,'2026-03-12 14:54:57'),(1284,4,'2026-04-08','09:20:00','09:40:00','available',NULL,'2026-03-12 14:54:57'),(1285,4,'2026-04-08','09:40:00','10:00:00','available',NULL,'2026-03-12 14:54:57'),(1286,4,'2026-04-08','10:00:00','10:20:00','available',NULL,'2026-03-12 14:54:57'),(1287,4,'2026-04-08','10:20:00','10:40:00','available',NULL,'2026-03-12 14:54:57'),(1288,4,'2026-04-08','10:40:00','11:00:00','available',NULL,'2026-03-12 14:54:57'),(1289,4,'2026-04-08','11:00:00','11:20:00','available',NULL,'2026-03-12 14:54:57'),(1290,4,'2026-04-08','11:20:00','11:40:00','available',NULL,'2026-03-12 14:54:57'),(1291,4,'2026-04-08','11:40:00','12:00:00','available',NULL,'2026-03-12 14:54:57'),(1292,4,'2026-04-08','13:00:00','13:20:00','available',NULL,'2026-03-12 14:54:57'),(1293,4,'2026-04-08','13:20:00','13:40:00','available',NULL,'2026-03-12 14:54:57'),(1294,4,'2026-04-08','13:40:00','14:00:00','available',NULL,'2026-03-12 14:54:57'),(1295,4,'2026-04-08','14:00:00','14:20:00','available',NULL,'2026-03-12 14:54:57'),(1296,4,'2026-04-08','14:20:00','14:40:00','available',NULL,'2026-03-12 14:54:57'),(1297,4,'2026-04-08','14:40:00','15:00:00','available',NULL,'2026-03-12 14:54:57'),(1298,4,'2026-04-08','15:00:00','15:20:00','available',NULL,'2026-03-12 14:54:57'),(1299,4,'2026-04-08','15:20:00','15:40:00','available',NULL,'2026-03-12 14:54:57'),(1300,4,'2026-04-08','15:40:00','16:00:00','available',NULL,'2026-03-12 14:54:57'),(1301,4,'2026-04-08','16:00:00','16:20:00','available',NULL,'2026-03-12 14:54:57'),(1302,4,'2026-04-08','16:20:00','16:40:00','available',NULL,'2026-03-12 14:54:57'),(1303,4,'2026-04-08','16:40:00','17:00:00','available',NULL,'2026-03-12 14:54:57'),(1304,4,'2026-04-09','08:00:00','08:20:00','available',NULL,'2026-03-12 14:54:57'),(1305,4,'2026-04-09','08:20:00','08:40:00','available',NULL,'2026-03-12 14:54:57'),(1306,4,'2026-04-09','08:40:00','09:00:00','available',NULL,'2026-03-12 14:54:57'),(1307,4,'2026-04-09','09:00:00','09:20:00','available',NULL,'2026-03-12 14:54:57'),(1308,4,'2026-04-09','09:20:00','09:40:00','available',NULL,'2026-03-12 14:54:57'),(1309,4,'2026-04-09','09:40:00','10:00:00','available',NULL,'2026-03-12 14:54:57'),(1310,4,'2026-04-09','10:00:00','10:20:00','available',NULL,'2026-03-12 14:54:57'),(1311,4,'2026-04-09','10:20:00','10:40:00','available',NULL,'2026-03-12 14:54:57'),(1312,4,'2026-04-09','10:40:00','11:00:00','available',NULL,'2026-03-12 14:54:58'),(1313,4,'2026-04-09','11:00:00','11:20:00','available',NULL,'2026-03-12 14:54:58'),(1314,4,'2026-04-09','11:20:00','11:40:00','available',NULL,'2026-03-12 14:54:58'),(1315,4,'2026-04-09','11:40:00','12:00:00','available',NULL,'2026-03-12 14:54:58'),(1316,4,'2026-04-09','13:00:00','13:20:00','available',NULL,'2026-03-12 14:54:58'),(1317,4,'2026-04-09','13:20:00','13:40:00','available',NULL,'2026-03-12 14:54:58'),(1318,4,'2026-04-09','13:40:00','14:00:00','available',NULL,'2026-03-12 14:54:58'),(1319,4,'2026-04-09','14:00:00','14:20:00','available',NULL,'2026-03-12 14:54:58'),(1320,4,'2026-04-09','14:20:00','14:40:00','available',NULL,'2026-03-12 14:54:58'),(1321,4,'2026-04-09','14:40:00','15:00:00','available',NULL,'2026-03-12 14:54:58'),(1322,4,'2026-04-09','15:00:00','15:20:00','available',NULL,'2026-03-12 14:54:58'),(1323,4,'2026-04-09','15:20:00','15:40:00','available',NULL,'2026-03-12 14:54:58'),(1324,4,'2026-04-09','15:40:00','16:00:00','available',NULL,'2026-03-12 14:54:58'),(1325,4,'2026-04-09','16:00:00','16:20:00','available',NULL,'2026-03-12 14:54:58'),(1326,4,'2026-04-09','16:20:00','16:40:00','available',NULL,'2026-03-12 14:54:58'),(1327,4,'2026-04-09','16:40:00','17:00:00','available',NULL,'2026-03-12 14:54:58'),(1328,4,'2026-04-10','08:00:00','08:20:00','available',NULL,'2026-03-12 14:54:58'),(1329,4,'2026-04-10','08:20:00','08:40:00','available',NULL,'2026-03-12 14:54:58'),(1330,4,'2026-04-10','08:40:00','09:00:00','available',NULL,'2026-03-12 14:54:58'),(1331,4,'2026-04-10','09:00:00','09:20:00','available',NULL,'2026-03-12 14:54:58'),(1332,4,'2026-04-10','09:20:00','09:40:00','available',NULL,'2026-03-12 14:54:58'),(1333,4,'2026-04-10','09:40:00','10:00:00','available',NULL,'2026-03-12 14:54:58'),(1334,4,'2026-04-10','10:00:00','10:20:00','available',NULL,'2026-03-12 14:54:58'),(1335,4,'2026-04-10','10:20:00','10:40:00','available',NULL,'2026-03-12 14:54:58'),(1336,4,'2026-04-10','10:40:00','11:00:00','available',NULL,'2026-03-12 14:54:58'),(1337,4,'2026-04-10','11:00:00','11:20:00','available',NULL,'2026-03-12 14:54:58'),(1338,4,'2026-04-10','11:20:00','11:40:00','available',NULL,'2026-03-12 14:54:58'),(1339,4,'2026-04-10','11:40:00','12:00:00','available',NULL,'2026-03-12 14:54:58'),(1340,4,'2026-04-10','13:00:00','13:20:00','available',NULL,'2026-03-12 14:54:58'),(1341,4,'2026-04-10','13:20:00','13:40:00','available',NULL,'2026-03-12 14:54:58'),(1342,4,'2026-04-10','13:40:00','14:00:00','available',NULL,'2026-03-12 14:54:58'),(1343,4,'2026-04-10','14:00:00','14:20:00','available',NULL,'2026-03-12 14:54:58'),(1344,4,'2026-04-10','14:20:00','14:40:00','available',NULL,'2026-03-12 14:54:58'),(1345,4,'2026-04-10','14:40:00','15:00:00','available',NULL,'2026-03-12 14:54:58'),(1346,4,'2026-04-10','15:00:00','15:20:00','available',NULL,'2026-03-12 14:54:58'),(1347,4,'2026-04-10','15:20:00','15:40:00','available',NULL,'2026-03-12 14:54:58'),(1348,4,'2026-04-10','15:40:00','16:00:00','available',NULL,'2026-03-12 14:54:58'),(1349,4,'2026-04-10','16:00:00','16:20:00','available',NULL,'2026-03-12 14:54:58'),(1350,4,'2026-04-10','16:20:00','16:40:00','available',NULL,'2026-03-12 14:54:58'),(1351,4,'2026-04-10','16:40:00','17:00:00','available',NULL,'2026-03-12 14:54:58'),(1352,4,'2026-04-11','08:00:00','08:20:00','available',NULL,'2026-03-12 14:54:58'),(1353,4,'2026-04-11','08:20:00','08:40:00','available',NULL,'2026-03-12 14:54:58'),(1354,4,'2026-04-11','08:40:00','09:00:00','available',NULL,'2026-03-12 14:54:58'),(1355,4,'2026-04-11','09:00:00','09:20:00','available',NULL,'2026-03-12 14:54:58'),(1356,4,'2026-04-11','09:20:00','09:40:00','available',NULL,'2026-03-12 14:54:58'),(1357,4,'2026-04-11','09:40:00','10:00:00','available',NULL,'2026-03-12 14:54:58'),(1358,4,'2026-04-11','10:00:00','10:20:00','available',NULL,'2026-03-12 14:54:58'),(1359,4,'2026-04-11','10:20:00','10:40:00','available',NULL,'2026-03-12 14:54:58'),(1360,4,'2026-04-11','10:40:00','11:00:00','available',NULL,'2026-03-12 14:54:58'),(1361,4,'2026-04-11','11:00:00','11:20:00','available',NULL,'2026-03-12 14:54:58'),(1362,4,'2026-04-11','11:20:00','11:40:00','available',NULL,'2026-03-12 14:54:58'),(1363,4,'2026-04-11','11:40:00','12:00:00','available',NULL,'2026-03-12 14:54:58'),(1364,4,'2026-04-11','13:00:00','13:20:00','available',NULL,'2026-03-12 14:54:58'),(1365,4,'2026-04-11','13:20:00','13:40:00','available',NULL,'2026-03-12 14:54:58'),(1366,4,'2026-04-11','13:40:00','14:00:00','available',NULL,'2026-03-12 14:54:58'),(1367,4,'2026-04-11','14:00:00','14:20:00','available',NULL,'2026-03-12 14:54:58'),(1368,4,'2026-04-11','14:20:00','14:40:00','available',NULL,'2026-03-12 14:54:58'),(1369,4,'2026-04-11','14:40:00','15:00:00','available',NULL,'2026-03-12 14:54:58'),(1370,4,'2026-04-11','15:00:00','15:20:00','available',NULL,'2026-03-12 14:54:58'),(1371,4,'2026-04-11','15:20:00','15:40:00','available',NULL,'2026-03-12 14:54:58'),(1372,4,'2026-04-11','15:40:00','16:00:00','available',NULL,'2026-03-12 14:54:58'),(1373,4,'2026-04-11','16:00:00','16:20:00','available',NULL,'2026-03-12 14:54:58'),(1374,4,'2026-04-11','16:20:00','16:40:00','available',NULL,'2026-03-12 14:54:58'),(1375,4,'2026-04-11','16:40:00','17:00:00','available',NULL,'2026-03-12 14:54:58');
/*!40000 ALTER TABLE `timeslot` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `timeslot_service`
--

DROP TABLE IF EXISTS `timeslot_service`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `timeslot_service` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `service_id` int(11) NOT NULL,
  `duration_minutes` int(11) NOT NULL,
  `buffer_minutes` int(11) DEFAULT 5,
  `slots_per_day_limit` int(11) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_service` (`service_id`),
  CONSTRAINT `timeslot_service_ibfk_1` FOREIGN KEY (`service_id`) REFERENCES `services` (`service_id`)
) ENGINE=InnoDB AUTO_INCREMENT=5 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `timeslot_service`
--

LOCK TABLES `timeslot_service` WRITE;
/*!40000 ALTER TABLE `timeslot_service` DISABLE KEYS */;
INSERT INTO `timeslot_service` VALUES (1,1,30,5,NULL,'2026-03-10 02:22:02'),(2,2,120,10,NULL,'2026-03-10 02:22:02'),(3,3,90,10,NULL,'2026-03-10 02:22:02'),(4,4,45,5,NULL,'2026-03-10 02:22:02');
/*!40000 ALTER TABLE `timeslot_service` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `tooth`
--

DROP TABLE IF EXISTS `tooth`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `tooth` (
  `tooth_id` int(11) NOT NULL AUTO_INCREMENT,
  `tooth_number` varchar(10) NOT NULL,
  `upper_lower` tinyint(1) DEFAULT NULL,
  PRIMARY KEY (`tooth_id`)
) ENGINE=InnoDB AUTO_INCREMENT=33 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `tooth`
--

LOCK TABLES `tooth` WRITE;
/*!40000 ALTER TABLE `tooth` DISABLE KEYS */;
INSERT INTO `tooth` VALUES (1,'11',1),(2,'12',1),(3,'13',1),(4,'14',1),(5,'15',1),(6,'16',1),(7,'17',1),(8,'18',1),(9,'21',1),(10,'22',1),(11,'23',1),(12,'24',1),(13,'25',1),(14,'26',1),(15,'27',1),(16,'28',1),(17,'31',0),(18,'32',0),(19,'33',0),(20,'34',0),(21,'35',0),(22,'36',0),(23,'37',0),(24,'38',0),(25,'41',0),(26,'42',0),(27,'43',0),(28,'44',0),(29,'45',0),(30,'46',0),(31,'47',0),(32,'48',0);
/*!40000 ALTER TABLE `tooth` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `tooth_images`
--

DROP TABLE IF EXISTS `tooth_images`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `tooth_images` (
  `image_id` int(11) NOT NULL AUTO_INCREMENT,
  `image_file` longblob DEFAULT NULL,
  `uploaded_at` datetime DEFAULT current_timestamp(),
  `file_name` varchar(255) DEFAULT NULL,
  `file_path` varchar(500) DEFAULT NULL,
  `selected_treatment_id` int(11) DEFAULT NULL,
  PRIMARY KEY (`image_id`),
  KEY `fk_tooth_images_treatment` (`selected_treatment_id`),
  CONSTRAINT `fk_tooth_images_treatment` FOREIGN KEY (`selected_treatment_id`) REFERENCES `selected_treatments` (`selected_treatment_id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `tooth_images`
--

LOCK TABLES `tooth_images` WRITE;
/*!40000 ALTER TABLE `tooth_images` DISABLE KEYS */;
/*!40000 ALTER TABLE `tooth_images` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `tooth_status`
--

DROP TABLE IF EXISTS `tooth_status`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `tooth_status` (
  `tooth_status_id` int(11) NOT NULL AUTO_INCREMENT,
  `tooth_selected` int(11) NOT NULL,
  `status_type_id` int(11) DEFAULT NULL,
  `tooth_surface` varchar(50) DEFAULT NULL,
  `status` enum('healthy','decayed','filled','missing','crowned','root_canal','restored','unknown') DEFAULT 'unknown',
  `selected_treatment_id` int(11) DEFAULT NULL,
  PRIMARY KEY (`tooth_status_id`),
  KEY `fk_tooth_status_tooth` (`tooth_selected`),
  KEY `fk_tooth_status_type` (`status_type_id`),
  KEY `fk_tooth_status_selected_treatment` (`selected_treatment_id`),
  CONSTRAINT `fk_tooth_status_selected_treatment` FOREIGN KEY (`selected_treatment_id`) REFERENCES `selected_treatments` (`selected_treatment_id`) ON DELETE SET NULL,
  CONSTRAINT `fk_tooth_status_tooth` FOREIGN KEY (`tooth_selected`) REFERENCES `tooth` (`tooth_id`) ON DELETE CASCADE,
  CONSTRAINT `fk_tooth_status_type` FOREIGN KEY (`status_type_id`) REFERENCES `tooth_status_type` (`status_type_id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `tooth_status`
--

LOCK TABLES `tooth_status` WRITE;
/*!40000 ALTER TABLE `tooth_status` DISABLE KEYS */;
/*!40000 ALTER TABLE `tooth_status` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `tooth_status_type`
--

DROP TABLE IF EXISTS `tooth_status_type`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `tooth_status_type` (
  `status_type_id` int(11) NOT NULL AUTO_INCREMENT,
  `tooth_id` int(11) NOT NULL,
  `category` enum('decay','restoration','missing','other') DEFAULT 'other',
  `code` varchar(50) DEFAULT NULL,
  PRIMARY KEY (`status_type_id`),
  KEY `fk_tst_tooth` (`tooth_id`),
  CONSTRAINT `fk_tst_tooth` FOREIGN KEY (`tooth_id`) REFERENCES `tooth` (`tooth_id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=9 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `tooth_status_type`
--

LOCK TABLES `tooth_status_type` WRITE;
/*!40000 ALTER TABLE `tooth_status_type` DISABLE KEYS */;
INSERT INTO `tooth_status_type` VALUES (1,1,'decay','DCY'),(2,1,'restoration','RST'),(3,1,'missing','MIS'),(4,1,'other','OTH'),(5,2,'decay','DCY'),(6,2,'restoration','RST'),(7,2,'missing','MIS'),(8,2,'other','OTH');
/*!40000 ALTER TABLE `tooth_status_type` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `treatments`
--

DROP TABLE IF EXISTS `treatments`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `treatments` (
  `treatment_id` int(11) NOT NULL AUTO_INCREMENT,
  `treatment_name` varchar(200) NOT NULL,
  `treatment_type` varchar(100) DEFAULT NULL,
  `description` text DEFAULT NULL,
  `created_at` datetime DEFAULT current_timestamp(),
  PRIMARY KEY (`treatment_id`)
) ENGINE=InnoDB AUTO_INCREMENT=11 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `treatments`
--

LOCK TABLES `treatments` WRITE;
/*!40000 ALTER TABLE `treatments` DISABLE KEYS */;
INSERT INTO `treatments` VALUES (1,'Scaling and Polishing','Cleaning','Professional cleaning and polishing of teeth','2026-01-23 15:54:14'),(2,'Endodontic Therapy','Root Canal','Treatment of root canal infection','2026-01-23 15:54:14'),(3,'Crown Preparation','Restoration','Preparation and placement of dental crown','2026-01-23 15:54:14'),(4,'Amalgam Filling','Filling','Traditional silver filling for cavities','2026-01-23 15:54:14'),(5,'Composite Filling','Filling','Tooth-colored resin filling','2026-01-23 15:54:14'),(6,'Simple Extraction','Extraction','Extraction of visible teeth','2026-01-23 15:54:14'),(7,'Surgical Extraction','Extraction','Extraction of impacted or embedded teeth','2026-01-23 15:54:14'),(8,'Teeth Bleaching','Cosmetic','Professional whitening treatment','2026-01-23 15:54:14'),(9,'Bracket Installation','Orthodontics','Installation of orthodontic brackets','2026-01-23 15:54:14'),(10,'Provisional Restoration','Temporary','Temporary restoration while permanent treatment is pending','2026-01-23 15:54:14');
/*!40000 ALTER TABLE `treatments` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `user_access_modules`
--

DROP TABLE IF EXISTS `user_access_modules`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `user_access_modules` (
  `user_module_id` int(11) NOT NULL AUTO_INCREMENT,
  `user_id` int(11) NOT NULL,
  `modules` varchar(150) DEFAULT NULL,
  `can_view` tinyint(1) DEFAULT 0,
  `can_edit` tinyint(1) DEFAULT 0,
  `can_deactivate` tinyint(1) DEFAULT 0,
  PRIMARY KEY (`user_module_id`),
  KEY `fk_uam_user` (`user_id`),
  CONSTRAINT `fk_uam_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`user_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `user_access_modules`
--

LOCK TABLES `user_access_modules` WRITE;
/*!40000 ALTER TABLE `user_access_modules` DISABLE KEYS */;
/*!40000 ALTER TABLE `user_access_modules` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `user_logs`
--

DROP TABLE IF EXISTS `user_logs`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `user_logs` (
  `user_log_id` int(11) NOT NULL AUTO_INCREMENT,
  `user_id` int(11) NOT NULL,
  `login_time` datetime DEFAULT NULL,
  `logout_time` datetime DEFAULT NULL,
  `ip_address` varchar(100) DEFAULT NULL,
  PRIMARY KEY (`user_log_id`),
  KEY `fk_user_logs_user` (`user_id`),
  CONSTRAINT `fk_user_logs_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`user_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `user_logs`
--

LOCK TABLES `user_logs` WRITE;
/*!40000 ALTER TABLE `user_logs` DISABLE KEYS */;
/*!40000 ALTER TABLE `user_logs` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `users`
--

DROP TABLE IF EXISTS `users`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `users` (
  `user_id` int(11) NOT NULL AUTO_INCREMENT,
  `first_name` varchar(100) NOT NULL,
  `last_name` varchar(100) NOT NULL,
  `middle_name` varchar(100) DEFAULT NULL,
  `password_hash` varchar(255) NOT NULL,
  `date_of_birth` date DEFAULT NULL,
  `gender` varchar(20) DEFAULT NULL,
  `phone_number` varchar(30) DEFAULT NULL,
  `email` varchar(150) DEFAULT NULL,
  `address` text DEFAULT NULL,
  `is_active` tinyint(1) DEFAULT 1,
  `created_at` datetime DEFAULT current_timestamp(),
  `specialization_id` int(11) DEFAULT NULL,
  `role_id` int(11) DEFAULT NULL,
  `added_by` int(11) DEFAULT NULL,
  `must_change_password` tinyint(1) NOT NULL DEFAULT 0,
  `password_updated_at` datetime DEFAULT NULL,
  `failed_login_attempts` int(11) NOT NULL DEFAULT 0,
  `locked_until` datetime DEFAULT NULL,
  `last_login_at` datetime DEFAULT NULL,
  `work_type` enum('regular','part_time') NOT NULL DEFAULT 'regular',
  PRIMARY KEY (`user_id`),
  UNIQUE KEY `email` (`email`),
  KEY `fk_users_specialization` (`specialization_id`),
  KEY `fk_users_role` (`role_id`),
  KEY `fk_users_added_by` (`added_by`),
  CONSTRAINT `fk_users_added_by` FOREIGN KEY (`added_by`) REFERENCES `users` (`user_id`) ON DELETE SET NULL,
  CONSTRAINT `fk_users_role` FOREIGN KEY (`role_id`) REFERENCES `role` (`role_id`) ON DELETE SET NULL,
  CONSTRAINT `fk_users_specialization` FOREIGN KEY (`specialization_id`) REFERENCES `specialization` (`specialization_id`) ON DELETE SET NULL
) ENGINE=InnoDB AUTO_INCREMENT=5 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `users`
--

LOCK TABLES `users` WRITE;
/*!40000 ALTER TABLE `users` DISABLE KEYS */;
INSERT INTO `users` VALUES (1,'Admin','User','Admin','$2y$10$jxi94smqf8APzJhrmp5nguVWrMDxlY.p0GZOhAVSl9Jhrn5/m4phS','1980-01-15','Male','1234567890','admin@dentalclinic.com','123 Admin Street',1,'2026-01-20 22:57:04',NULL,1,NULL,0,'2026-03-12 20:37:18',0,NULL,'2026-03-12 23:02:38','regular'),(2,'Dr. John','Dentist','Michael','$2y$10$tswCVb9AxlWOb4AM2gVTE.iYgxgamR1n.8rfXTjvPU8pcbZC8IX0m','1985-05-20','Male','0987654321','doctor@dentalclinic.com','456 Doctor Avenue',1,'2026-01-20 22:57:04',NULL,2,NULL,0,'2026-03-12 20:38:23',0,NULL,'2026-03-12 22:28:09','regular'),(3,'Mary','Secretary','Jane','$2y$10$L4her2tbM6RSUR5ONC5ySOVZmnFAW7OFK0aAvdx2hc756ZlqqKw76','1990-08-10','Female','1112223333','secretary@dentalclinic.com','789 Secretary Lane',1,'2026-01-20 22:57:04',NULL,3,NULL,0,'2026-03-12 20:17:52',0,NULL,'2026-03-12 22:57:41','regular'),(4,'Hanz','Tan',NULL,'$2y$10$9IZkUO7.4CJobHU3YTt3I.sJLuDADpfWTqckZrR3c/khjUtAFVpV2','1111-11-11','Male','+63 9275269342','tan@gmail.com','Zone 5 Gusa',1,'2026-03-12 21:26:09',NULL,2,NULL,1,'2026-03-12 21:26:09',0,NULL,'2026-03-12 21:26:43','part_time');
/*!40000 ALTER TABLE `users` ENABLE KEYS */;
UNLOCK TABLES;
/*!40103 SET TIME_ZONE=@OLD_TIME_ZONE */;

/*!40101 SET SQL_MODE=@OLD_SQL_MODE */;
/*!40014 SET FOREIGN_KEY_CHECKS=@OLD_FOREIGN_KEY_CHECKS */;
/*!40014 SET UNIQUE_CHECKS=@OLD_UNIQUE_CHECKS */;
/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
/*!40111 SET SQL_NOTES=@OLD_SQL_NOTES */;

-- Dump completed on 2026-03-12 23:16:08

