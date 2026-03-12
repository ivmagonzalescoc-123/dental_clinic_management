-- phpMyAdmin SQL Dump
-- version 5.2.1
-- https://www.phpmyadmin.net/
--
-- Host: 127.0.0.1
-- Generation Time: Mar 12, 2026 at 05:01 AM
-- Server version: 10.4.32-MariaDB
-- PHP Version: 8.2.12

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
START TRANSACTION;
SET time_zone = "+00:00";


/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;

--
-- Database: `capstone_dental`
--

-- --------------------------------------------------------

--
-- Table structure for table `appointments`
--

CREATE TABLE `appointments` (
  `appointment_id` int(11) NOT NULL,
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
  `is_emergency` tinyint(1) DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `appointments`
--

INSERT INTO `appointments` (`appointment_id`, `appointment_date`, `timeslot_id`, `service_id`, `reason`, `is_online_appointment`, `notes`, `status`, `created_at`, `user_id`, `rescheduled_from`, `patient_id`, `is_emergency`) VALUES
(20, '2026-02-25', NULL, NULL, 'Tooth Ache', 1, 'no show', 'cancelled', '2026-02-25 20:11:28', 2, NULL, 1, 0),
(22, '2026-02-25', NULL, NULL, 'Test appointment for reminders', 0, 'sig balikbalik', 'cancelled', '2026-02-25 20:59:34', 2, NULL, 1, 0),
(23, '2026-02-25', NULL, NULL, 'Ouch', 1, '\n[Clinical record submitted by doctor 2]', 'completed', '2026-02-25 21:41:18', 2, NULL, 1, 0),
(24, '2026-02-25', NULL, NULL, 'Sakit akong ngipon doc help', 1, '\n[Clinical record submitted by doctor 2]', 'completed', '2026-02-25 21:56:23', 2, NULL, 3, 0),
(28, '2026-02-27', NULL, NULL, '', 0, '0', 'scheduled', '2026-02-26 07:55:15', 2, NULL, 3, 1),
(29, '2026-02-26', NULL, NULL, 'asdasd', 0, '\n[Clinical record submitted by doctor 2]', 'completed', '2026-02-26 08:01:10', 2, NULL, 2, 1),
(30, '2026-03-06', NULL, NULL, 'general yearly consultation', 0, '', 'in_progress', '2026-03-06 09:01:29', 2, NULL, 1, 0),
(31, '2026-03-06', NULL, NULL, 'test', 0, '', 'in_progress', '2026-03-06 09:14:26', 2, NULL, 3, 0),
(32, '2026-03-06', NULL, NULL, 'asdasd', 0, '', 'in_progress', '2026-03-06 09:18:01', 2, NULL, 2, 0);

-- --------------------------------------------------------

--
-- Table structure for table `backup`
--

CREATE TABLE `backup` (
  `backup_id` int(11) NOT NULL,
  `user_id` int(11) DEFAULT NULL,
  `backup_date` datetime DEFAULT current_timestamp(),
  `file_name` varchar(255) DEFAULT NULL,
  `file_path` varchar(500) DEFAULT NULL,
  `file_size` decimal(12,2) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `billing`
--

CREATE TABLE `billing` (
  `billing_id` int(11) NOT NULL,
  `appointment_id` int(11) DEFAULT NULL,
  `user_id` int(11) DEFAULT NULL,
  `total_amount` decimal(12,2) NOT NULL DEFAULT 0.00,
  `created_at` datetime DEFAULT current_timestamp(),
  `status` enum('unpaid','partial','paid') NOT NULL DEFAULT 'unpaid',
  `processed_by` int(11) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `billing`
--

INSERT INTO `billing` (`billing_id`, `appointment_id`, `user_id`, `total_amount`, `created_at`, `status`, `processed_by`) VALUES
(7, 23, 1, 1000.00, '2026-02-25 21:45:12', 'paid', 3),
(8, 24, 3, 1100.00, '2026-02-25 22:02:36', 'paid', 3),
(9, 29, 2, 0.00, '2026-02-26 08:09:28', 'unpaid', NULL);

-- --------------------------------------------------------

--
-- Table structure for table `check_in_queue`
--

CREATE TABLE `check_in_queue` (
  `checkin_id` int(11) NOT NULL,
  `status` enum('waiting','called','in_progress','served','cancelled','no_show','late') DEFAULT 'waiting',
  `checkin_time` datetime DEFAULT current_timestamp(),
  `que_number` int(11) DEFAULT NULL,
  `appointment_id` int(11) DEFAULT NULL,
  `served_time` datetime DEFAULT NULL,
  `completed_time` datetime DEFAULT NULL,
  `is_emergency` tinyint(1) DEFAULT 0,
  `timeslot_id` int(11) DEFAULT NULL,
  `expected_time` time DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `check_in_queue`
--

INSERT INTO `check_in_queue` (`checkin_id`, `status`, `checkin_time`, `que_number`, `appointment_id`, `served_time`, `completed_time`, `is_emergency`, `timeslot_id`, `expected_time`) VALUES
(12, 'served', '2026-02-25 21:41:40', 1, 23, '2026-02-25 21:45:12', '2026-02-25 21:45:12', 0, NULL, NULL),
(13, 'served', '2026-02-25 21:57:36', 2, 24, '2026-02-25 22:02:36', '2026-02-25 22:02:36', 0, NULL, NULL),
(16, '', '2026-02-26 07:58:40', 1, 28, '2026-02-26 08:07:53', NULL, 1, NULL, NULL),
(17, 'served', '2026-02-26 08:01:14', 2, 29, '2026-02-26 08:09:28', '2026-02-26 08:09:28', 1, NULL, NULL),
(18, '', '2026-03-06 09:01:36', 1, 30, '2026-03-06 09:02:43', NULL, 0, NULL, NULL),
(19, '', '2026-03-06 09:14:31', 2, 31, '2026-03-06 09:14:39', NULL, 0, NULL, NULL),
(20, '', '2026-03-06 09:18:05', 3, 32, '2026-03-06 09:18:15', NULL, 0, NULL, NULL);

-- --------------------------------------------------------

--
-- Table structure for table `clinical_records`
--

CREATE TABLE `clinical_records` (
  `record_id` int(11) NOT NULL,
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
  `tooth_image` varchar(255) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `clinical_records`
--

INSERT INTO `clinical_records` (`record_id`, `appointment_id`, `schedule_id`, `doctor_id`, `patient_id`, `summary`, `recommended_services`, `treatment_plan`, `materials_used`, `odontogram`, `doctor_notes`, `follow_up_date`, `status`, `submitted_by`, `submitted_at`, `images`, `tooth_image`) VALUES
(7, 23, 15, 2, 1, NULL, '[1]', NULL, '[{\"name\":\"gloves and surgical masks\",\"qty\":1,\"price\":500}]', NULL, 'No more issues', NULL, 'submitted', 2, '2026-02-25 21:45:12', '[\"assets\\/uploads\\/clinical_images\\/23\\/1772027112_731870fc66c6.jpeg\"]', 'assets/uploads/clinical_images/23/1772027112_731870fc66c6.jpeg'),
(8, 24, 17, 2, 3, NULL, '[1]', NULL, '[{\"name\":\"Gloves\",\"qty\":2,\"price\":50},{\"name\":\"SteelBrush\",\"qty\":1,\"price\":500}]', NULL, 'None', '2026-02-27', 'submitted', 2, '2026-02-25 22:02:36', '[\"assets\\/uploads\\/clinical_images\\/24\\/1772028156_f3600203914d.jpeg\"]', 'assets/uploads/clinical_images/24/1772028156_f3600203914d.jpeg'),
(9, 29, NULL, 2, 2, NULL, NULL, NULL, NULL, '{\"1\":[],\"2\":[],\"3\":[],\"4\":[],\"5\":[],\"6\":[],\"7\":[],\"8\":[],\"9\":[],\"10\":[],\"11\":[],\"12\":[],\"13\":[],\"14\":[],\"15\":[],\"16\":[],\"17\":[],\"18\":[],\"19\":[\"missing\"],\"20\":[],\"21\":[],\"22\":[],\"23\":[],\"24\":[],\"25\":[],\"26\":[],\"27\":[],\"28\":[],\"29\":[],\"30\":[],\"31\":[],\"32\":[]}', '', NULL, 'submitted', 2, '2026-02-26 08:09:28', NULL, NULL);

-- --------------------------------------------------------

--
-- Table structure for table `doctor_availability`
--

CREATE TABLE `doctor_availability` (
  `availability_id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `weekday` tinyint(4) NOT NULL COMMENT '0=Sun..6=Sat',
  `start_time` time NOT NULL,
  `end_time` time NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `doctor_leaves`
--

CREATE TABLE `doctor_leaves` (
  `leave_id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `leave_start_date` date NOT NULL,
  `leave_end_date` date NOT NULL,
  `reason` varchar(255) DEFAULT NULL,
  `status` enum('pending','approved','rejected') NOT NULL DEFAULT 'approved',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `medical_history`
--

CREATE TABLE `medical_history` (
  `history_id` int(11) NOT NULL,
  `patients_id` int(11) NOT NULL,
  `history_type` varchar(150) DEFAULT NULL,
  `history_from` date DEFAULT NULL,
  `history_to` date DEFAULT NULL,
  `detailed_info` text DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `notifications`
--

CREATE TABLE `notifications` (
  `notification_id` int(11) NOT NULL,
  `user_id` int(11) DEFAULT NULL,
  `title` varchar(255) NOT NULL,
  `body` text DEFAULT NULL,
  `link` varchar(255) DEFAULT NULL,
  `is_read` tinyint(1) DEFAULT 0,
  `created_at` datetime DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `notifications`
--

INSERT INTO `notifications` (`notification_id`, `user_id`, `title`, `body`, `link`, `is_read`, `created_at`) VALUES
(1, 1, 'Upcoming appointment reminder', 'Appointment #22 is scheduled at 2026-02-25 21:59:34. Please be prepared.', '/patient/patient.php?page=appointments&appointment_id=22', 0, '2026-02-25 21:01:22'),
(2, 2, 'Upcoming appointment reminder', 'Appointment #22 is scheduled at 2026-02-25 21:59:34. Please be prepared.', '/patient/patient.php?page=appointments&appointment_id=22', 1, '2026-02-25 21:01:22'),
(3, 3, 'Upcoming appointment reminder', 'Appointment #22 is scheduled at 2026-02-25 21:59:34. Please be prepared.', '/patient/patient.php?page=appointments&appointment_id=22', 0, '2026-02-25 21:01:22');

-- --------------------------------------------------------

--
-- Table structure for table `patients`
--

CREATE TABLE `patients` (
  `patient_id` int(11) NOT NULL,
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
  `profiling` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`profiling`))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `patients`
--

INSERT INTO `patients` (`patient_id`, `first_name`, `last_name`, `middle_name`, `password_hash`, `date_of_birth`, `gender`, `phone_number`, `email`, `address`, `created_at`, `added_by`, `profiling`) VALUES
(1, 'Paul', 'Gonzales', 'Gomez', '$2y$10$ZLCKM7rJkOAvioSH.prRhecUglgiIhU9unocyiWzDKfT7MzjEdbO2', '1990-01-01', 'Unspecified', '0927562616', 'patient1@example.com', 'Brgy 7 CDO', '2026-01-23 15:50:00', NULL, '{\"medical_history\":\"Highblood\",\"medications\":\"Penicilin\",\"allergies\":\"No\",\"smoking\":\"current\",\"alcohol\":\"social\",\"asa\":\"II\",\"last_visit\":\"\"}'),
(2, 'Harry', 'Tan', 'Richard', '$2y$10$7HUIHgDjz4Y9o5bpCCgl5OAxBXeEYVWH/6MAZAzOM.cF08ZP5cpJG', '1985-02-02', 'Unspecified', '0920912616', 'patient2@example.com', 'Gusa CDO', '2026-01-23 15:50:00', NULL, NULL),
(3, 'Ivan Louiz', 'Gonzales', '', '$2y$10$vYHRz7lVG32ZlPa.BaWP7.0L/.BhKlxrepn0sWWVUdfGoa7x0oATi', '1559-11-09', 'Male', '09123456789', 'ivan@gmail.com', 'Unahan Agusan CDO', '2026-02-25 21:55:55', NULL, NULL);

-- --------------------------------------------------------

--
-- Table structure for table `patient_arrivals`
--

CREATE TABLE `patient_arrivals` (
  `arrival_id` int(11) NOT NULL,
  `appointment_id` int(11) NOT NULL,
  `timeslot_id` int(11) NOT NULL,
  `expected_time` time NOT NULL,
  `arrived_at` datetime DEFAULT NULL,
  `status` enum('pending','arrived','late','no_show') DEFAULT 'pending',
  `marked_by` int(11) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `patient_profiling_audit`
--

CREATE TABLE `patient_profiling_audit` (
  `audit_id` int(11) NOT NULL,
  `patient_id` int(11) NOT NULL,
  `profiling` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`profiling`)),
  `changed_by` int(11) DEFAULT NULL,
  `changed_at` datetime DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `patient_profiling_audit`
--

INSERT INTO `patient_profiling_audit` (`audit_id`, `patient_id`, `profiling`, `changed_by`, `changed_at`) VALUES
(1, 1, '{\"medical_history\":\"Highblood\",\"medications\":\"Penicilin\",\"allergies\":\"No\",\"smoking\":\"current\",\"alcohol\":\"social\",\"asa\":\"II\",\"last_visit\":\"\"}', 3, '2026-03-06 09:02:22');

-- --------------------------------------------------------

--
-- Table structure for table `prescriptions`
--

CREATE TABLE `prescriptions` (
  `prescription_id` int(11) NOT NULL,
  `appointment_id` int(11) DEFAULT NULL,
  `prescription_date` date DEFAULT NULL,
  `medicine_name` varchar(255) DEFAULT NULL,
  `dosage` varchar(100) DEFAULT NULL,
  `medicine_brand` varchar(150) DEFAULT NULL,
  `duration` varchar(100) DEFAULT NULL,
  `selected_treatment_id` int(11) DEFAULT NULL,
  `notes` text DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `role`
--

CREATE TABLE `role` (
  `role_id` int(11) NOT NULL,
  `role_name` enum('admin','doctor','secretary','patient') DEFAULT NULL,
  `description` text DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `role`
--

INSERT INTO `role` (`role_id`, `role_name`, `description`) VALUES
(1, 'admin', 'Administrator with full system access'),
(2, 'doctor', 'Doctor/Dentist - Can manage appointments and patient data'),
(3, 'secretary', 'Secretary/Receptionist - Can schedule appointments and manage front desk'),
(4, 'patient', 'Patient - Can view appointments and medical records');

-- --------------------------------------------------------

--
-- Table structure for table `schedule`
--

CREATE TABLE `schedule` (
  `schedule_id` int(11) NOT NULL,
  `status` enum('active','inactive','suspended') DEFAULT 'active',
  `available_from` time NOT NULL,
  `available_to` time NOT NULL,
  `day_of_week` varchar(20) NOT NULL,
  `user_id` int(11) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `schedule`
--

INSERT INTO `schedule` (`schedule_id`, `status`, `available_from`, `available_to`, `day_of_week`, `user_id`) VALUES
(8, 'active', '09:00:00', '17:00:00', 'Monday', 2),
(9, 'active', '09:00:00', '17:00:00', 'Tuesday', 2),
(10, 'active', '09:00:00', '17:00:00', 'Wednesday', 2),
(11, 'active', '09:00:00', '17:00:00', 'Thursday', 2),
(12, 'active', '09:00:00', '17:00:00', 'Friday', 2),
(13, 'active', '09:00:00', '14:00:00', 'Saturday', 2),
(14, 'inactive', '10:00:00', '14:00:00', 'Sunday', 2),
(15, 'active', '08:00:00', '17:00:00', 'Monday', 3),
(16, 'active', '08:00:00', '17:00:00', 'Tuesday', 3),
(17, 'active', '08:00:00', '17:00:00', 'Wednesday', 3),
(18, 'active', '08:00:00', '17:00:00', 'Thursday', 3),
(19, 'active', '08:00:00', '17:00:00', 'Friday', 3),
(20, 'active', '08:00:00', '14:00:00', 'Saturday', 3),
(21, 'inactive', '10:00:00', '14:00:00', 'Sunday', 3);

-- --------------------------------------------------------

--
-- Table structure for table `schedules`
--

CREATE TABLE `schedules` (
  `schedule_id` int(11) NOT NULL,
  `appointment_id` int(11) NOT NULL,
  `user_id` int(11) DEFAULT NULL COMMENT 'doctor assigned',
  `scheduled_start` datetime NOT NULL,
  `scheduled_end` datetime DEFAULT NULL,
  `status` enum('scheduled','rescheduled','cancelled','completed') DEFAULT 'scheduled',
  `priority` tinyint(4) DEFAULT 1,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT NULL ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `schedules`
--

INSERT INTO `schedules` (`schedule_id`, `appointment_id`, `user_id`, `scheduled_start`, `scheduled_end`, `status`, `priority`, `created_at`, `updated_at`) VALUES
(14, 22, 2, '2026-02-25 21:59:34', '2026-02-25 22:29:34', 'scheduled', 1, '2026-02-25 12:59:34', NULL),
(15, 23, 2, '2026-02-25 13:42:20', NULL, '', 1, '2026-02-25 13:42:20', NULL),
(16, 24, 2, '2026-02-25 13:58:16', NULL, '', 1, '2026-02-25 13:58:16', NULL),
(17, 24, 2, '2026-02-27 09:00:00', '2026-02-27 09:30:00', 'scheduled', 1, '2026-02-25 14:02:36', NULL),
(18, 28, 2, '2026-02-26 08:07:53', NULL, '', 1, '2026-02-26 00:07:53', NULL),
(19, 30, 2, '2026-03-06 09:02:43', NULL, '', 1, '2026-03-06 01:02:43', NULL),
(20, 31, 2, '2026-03-06 09:14:39', NULL, '', 1, '2026-03-06 01:14:39', NULL),
(21, 32, 2, '2026-03-06 09:18:15', NULL, '', 1, '2026-03-06 01:18:15', NULL);

-- --------------------------------------------------------

--
-- Table structure for table `schedule_reminders`
--

CREATE TABLE `schedule_reminders` (
  `reminder_id` int(11) NOT NULL,
  `schedule_id` int(11) NOT NULL,
  `remind_at` datetime NOT NULL,
  `channel` enum('email','sms','push') NOT NULL DEFAULT 'email',
  `sent_at` datetime DEFAULT NULL,
  `payload` text DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `schedule_reminders`
--

INSERT INTO `schedule_reminders` (`reminder_id`, `schedule_id`, `remind_at`, `channel`, `sent_at`, `payload`) VALUES
(6, 14, '2026-02-25 20:59:34', 'email', '2026-02-25 21:01:22', '{\"test\": \"true\"}'),
(7, 17, '2026-02-26 09:00:00', '', NULL, '{\"appointment_id\":24,\"schedule_id\":17}');

-- --------------------------------------------------------

--
-- Table structure for table `selected_services`
--

CREATE TABLE `selected_services` (
  `selected_service_id` int(11) NOT NULL,
  `appointment_id` int(11) NOT NULL,
  `notes` text DEFAULT NULL,
  `created_at` datetime DEFAULT current_timestamp(),
  `service_id` int(11) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `selected_treatments`
--

CREATE TABLE `selected_treatments` (
  `selected_treatment_id` int(11) NOT NULL,
  `treatment_id` int(11) NOT NULL,
  `selected_service_id` int(11) DEFAULT NULL,
  `status` enum('pending','in_progress','completed','cancelled','declined') DEFAULT 'pending',
  `notes` text DEFAULT NULL,
  `created_at` datetime DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `services`
--

CREATE TABLE `services` (
  `service_id` int(11) NOT NULL,
  `service_name` varchar(200) NOT NULL,
  `initial_price` decimal(12,2) NOT NULL DEFAULT 0.00,
  `description` text DEFAULT NULL,
  `created_at` datetime DEFAULT current_timestamp(),
  `added_by` int(11) DEFAULT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `requires_schedule` tinyint(1) NOT NULL DEFAULT 0,
  `default_duration_minutes` int(11) NOT NULL DEFAULT 30
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `services`
--

INSERT INTO `services` (`service_id`, `service_name`, `initial_price`, `description`, `created_at`, `added_by`, `is_active`, `requires_schedule`, `default_duration_minutes`) VALUES
(1, 'Teeth Cleaning', 500.00, 'Professional teeth cleaning and scaling', '2026-01-23 15:54:14', 1, 1, 1, 30),
(2, 'Root Canal Treatment', 3000.00, 'Root canal therapy for damaged teeth', '2026-01-23 15:54:14', 1, 1, 1, 120),
(3, 'Crown Placement', 5000.00, 'Dental crown installation', '2026-01-23 15:54:14', 1, 1, 1, 90),
(4, 'Filling', 800.00, 'Tooth filling for cavities', '2026-01-23 15:54:14', 1, 1, 1, 45),
(5, 'Extraction', 1500.00, 'Tooth extraction', '2026-01-23 15:54:14', 1, 1, 1, 60),
(6, 'Whitening', 2000.00, 'Professional teeth whitening', '2026-01-23 15:54:14', 1, 1, 0, 30),
(7, 'Orthodontic Consultation', 1000.00, 'Braces and alignment consultation', '2026-01-23 15:54:14', 1, 1, 0, 30),
(8, 'Denture Fitting', 4000.00, 'Partial or complete denture fitting', '2026-01-23 15:54:14', 1, 1, 1, 180),
(9, 'Implant Consultation', 2000.00, 'Dental implant consultation and planning', '2026-01-23 15:54:14', 1, 1, 1, 120),
(10, 'Periodic Checkup', 300.00, 'Regular dental examination', '2026-01-23 15:54:14', 1, 1, 0, 30);

-- --------------------------------------------------------

--
-- Table structure for table `specialization`
--

CREATE TABLE `specialization` (
  `specialization_id` int(11) NOT NULL,
  `specialization_name` varchar(150) NOT NULL,
  `description` text DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `specialization`
--

INSERT INTO `specialization` (`specialization_id`, `specialization_name`, `description`) VALUES
(1, 'General Dentistry', 'General dental care and treatments'),
(2, 'Orthodontics', 'Teeth alignment and braces'),
(3, 'Periodontics', 'Gum disease and dental implants'),
(4, 'Pedodontics', 'Pediatric dental care'),
(5, 'Endodontics', 'Root canal treatment'),
(6, 'Prosthodontics', 'Dentures and bridges'),
(7, 'Oral Surgery', 'Extraction and surgical procedures'),
(8, 'Cosmetic Dentistry', 'Whitening and aesthetic procedures');

-- --------------------------------------------------------

--
-- Table structure for table `timeslot`
--

CREATE TABLE `timeslot` (
  `timeslot_id` int(11) NOT NULL,
  `doctor_id` int(11) NOT NULL,
  `slot_date` date NOT NULL,
  `start_time` time NOT NULL,
  `end_time` time NOT NULL,
  `status` enum('available','booked','blocked','break') DEFAULT 'available',
  `appointment_id` int(11) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `timeslot_service`
--

CREATE TABLE `timeslot_service` (
  `id` int(11) NOT NULL,
  `service_id` int(11) NOT NULL,
  `duration_minutes` int(11) NOT NULL,
  `buffer_minutes` int(11) DEFAULT 5,
  `slots_per_day_limit` int(11) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `timeslot_service`
--

INSERT INTO `timeslot_service` (`id`, `service_id`, `duration_minutes`, `buffer_minutes`, `slots_per_day_limit`, `created_at`) VALUES
(1, 1, 30, 5, NULL, '2026-03-10 02:22:02'),
(2, 2, 120, 10, NULL, '2026-03-10 02:22:02'),
(3, 3, 90, 10, NULL, '2026-03-10 02:22:02'),
(4, 4, 45, 5, NULL, '2026-03-10 02:22:02');

-- --------------------------------------------------------

--
-- Table structure for table `tooth`
--

CREATE TABLE `tooth` (
  `tooth_id` int(11) NOT NULL,
  `tooth_number` varchar(10) NOT NULL,
  `upper_lower` tinyint(1) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `tooth`
--

INSERT INTO `tooth` (`tooth_id`, `tooth_number`, `upper_lower`) VALUES
(1, '11', 1),
(2, '12', 1),
(3, '13', 1),
(4, '14', 1),
(5, '15', 1),
(6, '16', 1),
(7, '17', 1),
(8, '18', 1),
(9, '21', 1),
(10, '22', 1),
(11, '23', 1),
(12, '24', 1),
(13, '25', 1),
(14, '26', 1),
(15, '27', 1),
(16, '28', 1),
(17, '31', 0),
(18, '32', 0),
(19, '33', 0),
(20, '34', 0),
(21, '35', 0),
(22, '36', 0),
(23, '37', 0),
(24, '38', 0),
(25, '41', 0),
(26, '42', 0),
(27, '43', 0),
(28, '44', 0),
(29, '45', 0),
(30, '46', 0),
(31, '47', 0),
(32, '48', 0);

-- --------------------------------------------------------

--
-- Table structure for table `tooth_images`
--

CREATE TABLE `tooth_images` (
  `image_id` int(11) NOT NULL,
  `image_file` longblob DEFAULT NULL,
  `uploaded_at` datetime DEFAULT current_timestamp(),
  `file_name` varchar(255) DEFAULT NULL,
  `file_path` varchar(500) DEFAULT NULL,
  `selected_treatment_id` int(11) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `tooth_status`
--

CREATE TABLE `tooth_status` (
  `tooth_status_id` int(11) NOT NULL,
  `tooth_selected` int(11) NOT NULL,
  `status_type_id` int(11) DEFAULT NULL,
  `tooth_surface` varchar(50) DEFAULT NULL,
  `status` enum('healthy','decayed','filled','missing','crowned','root_canal','restored','unknown') DEFAULT 'unknown',
  `selected_treatment_id` int(11) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `tooth_status_type`
--

CREATE TABLE `tooth_status_type` (
  `status_type_id` int(11) NOT NULL,
  `tooth_id` int(11) NOT NULL,
  `category` enum('decay','restoration','missing','other') DEFAULT 'other',
  `code` varchar(50) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `tooth_status_type`
--

INSERT INTO `tooth_status_type` (`status_type_id`, `tooth_id`, `category`, `code`) VALUES
(1, 1, 'decay', 'DCY'),
(2, 1, 'restoration', 'RST'),
(3, 1, 'missing', 'MIS'),
(4, 1, 'other', 'OTH'),
(5, 2, 'decay', 'DCY'),
(6, 2, 'restoration', 'RST'),
(7, 2, 'missing', 'MIS'),
(8, 2, 'other', 'OTH');

-- --------------------------------------------------------

--
-- Table structure for table `treatments`
--

CREATE TABLE `treatments` (
  `treatment_id` int(11) NOT NULL,
  `treatment_name` varchar(200) NOT NULL,
  `treatment_type` varchar(100) DEFAULT NULL,
  `description` text DEFAULT NULL,
  `created_at` datetime DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `treatments`
--

INSERT INTO `treatments` (`treatment_id`, `treatment_name`, `treatment_type`, `description`, `created_at`) VALUES
(1, 'Scaling and Polishing', 'Cleaning', 'Professional cleaning and polishing of teeth', '2026-01-23 15:54:14'),
(2, 'Endodontic Therapy', 'Root Canal', 'Treatment of root canal infection', '2026-01-23 15:54:14'),
(3, 'Crown Preparation', 'Restoration', 'Preparation and placement of dental crown', '2026-01-23 15:54:14'),
(4, 'Amalgam Filling', 'Filling', 'Traditional silver filling for cavities', '2026-01-23 15:54:14'),
(5, 'Composite Filling', 'Filling', 'Tooth-colored resin filling', '2026-01-23 15:54:14'),
(6, 'Simple Extraction', 'Extraction', 'Extraction of visible teeth', '2026-01-23 15:54:14'),
(7, 'Surgical Extraction', 'Extraction', 'Extraction of impacted or embedded teeth', '2026-01-23 15:54:14'),
(8, 'Teeth Bleaching', 'Cosmetic', 'Professional whitening treatment', '2026-01-23 15:54:14'),
(9, 'Bracket Installation', 'Orthodontics', 'Installation of orthodontic brackets', '2026-01-23 15:54:14'),
(10, 'Provisional Restoration', 'Temporary', 'Temporary restoration while permanent treatment is pending', '2026-01-23 15:54:14');

-- --------------------------------------------------------

--
-- Table structure for table `users`
--

CREATE TABLE `users` (
  `user_id` int(11) NOT NULL,
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
  `work_type` enum('regular','part_time') NOT NULL DEFAULT 'regular',
  `added_by` int(11) DEFAULT NULL,
  `must_change_password` tinyint(1) NOT NULL DEFAULT 0,
  `password_updated_at` datetime DEFAULT NULL,
  `failed_login_attempts` int(11) NOT NULL DEFAULT 0,
  `locked_until` datetime DEFAULT NULL,
  `last_login_at` datetime DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `users`
--

INSERT INTO `users` (`user_id`, `first_name`, `last_name`, `middle_name`, `password_hash`, `date_of_birth`, `gender`, `phone_number`, `email`, `address`, `is_active`, `created_at`, `specialization_id`, `role_id`, `added_by`) VALUES
(1, 'Admin', 'User', 'Admin', '$2y$10$SGC4OvqS7z6AlGU.kSso4..eLxUPwZl7vaFT4TX9h01Evp9pa.bbG', '1980-01-15', 'Male', '1234567890', 'admin@dentalclinic.com', '123 Admin Street', 1, '2026-01-20 22:57:04', NULL, 1, NULL),
(2, 'Dr. John', 'Dentist', 'Michael', '$2y$10$AWB.PZdQnCwF/22OcGiGMeicUCr7iUvFe1m0And9uokIiEzzlbagi', '1985-05-20', 'Male', '0987654321', 'doctor@dentalclinic.com', '456 Doctor Avenue', 1, '2026-01-20 22:57:04', NULL, 2, NULL),
(3, 'Mary', 'Secretary', 'Jane', '$2y$10$z24y2ohXWzcpfwxlIfvVp.bOEX/fyy2Y5ZiCEiYPfhArHjNKIDioK', '1990-08-10', 'Female', '1112223333', 'secretary@dentalclinic.com', '789 Secretary Lane', 1, '2026-01-20 22:57:04', NULL, 3, NULL);

-- --------------------------------------------------------

--
-- Table structure for table `user_access_modules`
--

CREATE TABLE `user_access_modules` (
  `user_module_id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `modules` varchar(150) DEFAULT NULL,
  `can_view` tinyint(1) DEFAULT 0,
  `can_edit` tinyint(1) DEFAULT 0,
  `can_deactivate` tinyint(1) DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `user_logs`
--

CREATE TABLE `user_logs` (
  `user_log_id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `login_time` datetime DEFAULT NULL,
  `logout_time` datetime DEFAULT NULL,
  `ip_address` varchar(100) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Indexes for dumped tables
--

--
-- Indexes for table `appointments`
--
ALTER TABLE `appointments`
  ADD PRIMARY KEY (`appointment_id`),
  ADD KEY `fk_appointments_user` (`user_id`),
  ADD KEY `fk_appointments_rescheduled_from` (`rescheduled_from`),
  ADD KEY `fk_appointments_patient` (`patient_id`),
  ADD KEY `timeslot_id` (`timeslot_id`),
  ADD KEY `service_id` (`service_id`);

--
-- Indexes for table `backup`
--
ALTER TABLE `backup`
  ADD PRIMARY KEY (`backup_id`),
  ADD KEY `fk_backup_user` (`user_id`);

--
-- Indexes for table `billing`
--
ALTER TABLE `billing`
  ADD PRIMARY KEY (`billing_id`),
  ADD UNIQUE KEY `appointment_id` (`appointment_id`),
  ADD KEY `fk_billing_user` (`user_id`),
  ADD KEY `fk_billing_processed_by` (`processed_by`);

--
-- Indexes for table `check_in_queue`
--
ALTER TABLE `check_in_queue`
  ADD PRIMARY KEY (`checkin_id`),
  ADD UNIQUE KEY `appointment_id` (`appointment_id`),
  ADD KEY `timeslot_id` (`timeslot_id`);

--
-- Indexes for table `clinical_records`
--
ALTER TABLE `clinical_records`
  ADD PRIMARY KEY (`record_id`),
  ADD KEY `appointment_id` (`appointment_id`),
  ADD KEY `schedule_id` (`schedule_id`);

--
-- Indexes for table `doctor_availability`
--
ALTER TABLE `doctor_availability`
  ADD PRIMARY KEY (`availability_id`),
  ADD KEY `user_id` (`user_id`);

--
-- Indexes for table `doctor_leaves`
--
ALTER TABLE `doctor_leaves`
  ADD PRIMARY KEY (`leave_id`),
  ADD KEY `idx_doctor_leave_dates` (`user_id`,`leave_start_date`,`leave_end_date`),
  ADD KEY `idx_doctor_leave_status` (`status`);

--
-- Indexes for table `medical_history`
--
ALTER TABLE `medical_history`
  ADD PRIMARY KEY (`history_id`),
  ADD KEY `fk_medical_history_patient` (`patients_id`);

--
-- Indexes for table `notifications`
--
ALTER TABLE `notifications`
  ADD PRIMARY KEY (`notification_id`),
  ADD KEY `user_id` (`user_id`),
  ADD KEY `idx_notifications_user` (`user_id`),
  ADD KEY `idx_notifications_is_read` (`is_read`);

--
-- Indexes for table `patients`
--
ALTER TABLE `patients`
  ADD PRIMARY KEY (`patient_id`),
  ADD KEY `fk_patients_added_by` (`added_by`);

--
-- Indexes for table `patient_arrivals`
--
ALTER TABLE `patient_arrivals`
  ADD PRIMARY KEY (`arrival_id`),
  ADD UNIQUE KEY `uq_appointment` (`appointment_id`),
  ADD KEY `timeslot_id` (`timeslot_id`),
  ADD KEY `marked_by` (`marked_by`);

--
-- Indexes for table `patient_profiling_audit`
--
ALTER TABLE `patient_profiling_audit`
  ADD PRIMARY KEY (`audit_id`),
  ADD KEY `patient_id` (`patient_id`);

--
-- Indexes for table `prescriptions`
--
ALTER TABLE `prescriptions`
  ADD PRIMARY KEY (`prescription_id`),
  ADD KEY `fk_prescriptions_appointment` (`appointment_id`),
  ADD KEY `fk_prescriptions_treatment` (`selected_treatment_id`);

--
-- Indexes for table `role`
--
ALTER TABLE `role`
  ADD PRIMARY KEY (`role_id`);

--
-- Indexes for table `schedule`
--
ALTER TABLE `schedule`
  ADD PRIMARY KEY (`schedule_id`),
  ADD KEY `fk_schedule_user` (`user_id`);

--
-- Indexes for table `schedules`
--
ALTER TABLE `schedules`
  ADD PRIMARY KEY (`schedule_id`),
  ADD KEY `appointment_id` (`appointment_id`),
  ADD KEY `user_id` (`user_id`);

--
-- Indexes for table `schedule_reminders`
--
ALTER TABLE `schedule_reminders`
  ADD PRIMARY KEY (`reminder_id`),
  ADD KEY `schedule_id` (`schedule_id`);

--
-- Indexes for table `selected_services`
--
ALTER TABLE `selected_services`
  ADD PRIMARY KEY (`selected_service_id`),
  ADD KEY `fk_selected_services_appointment` (`appointment_id`),
  ADD KEY `fk_selected_services_service` (`service_id`);

--
-- Indexes for table `selected_treatments`
--
ALTER TABLE `selected_treatments`
  ADD PRIMARY KEY (`selected_treatment_id`),
  ADD KEY `fk_selected_treatments_treatment` (`treatment_id`),
  ADD KEY `fk_selected_treatments_service` (`selected_service_id`);

--
-- Indexes for table `services`
--
ALTER TABLE `services`
  ADD PRIMARY KEY (`service_id`),
  ADD KEY `fk_services_added_by` (`added_by`);

--
-- Indexes for table `specialization`
--
ALTER TABLE `specialization`
  ADD PRIMARY KEY (`specialization_id`);

--
-- Indexes for table `timeslot`
--
ALTER TABLE `timeslot`
  ADD PRIMARY KEY (`timeslot_id`),
  ADD UNIQUE KEY `uq_doctor_slot` (`doctor_id`,`slot_date`,`start_time`),
  ADD KEY `appointment_id` (`appointment_id`),
  ADD KEY `idx_timeslot_doc_date_status` (`doctor_id`,`slot_date`,`status`);

--
-- Indexes for table `timeslot_service`
--
ALTER TABLE `timeslot_service`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_service` (`service_id`);

--
-- Indexes for table `tooth`
--
ALTER TABLE `tooth`
  ADD PRIMARY KEY (`tooth_id`);

--
-- Indexes for table `tooth_images`
--
ALTER TABLE `tooth_images`
  ADD PRIMARY KEY (`image_id`),
  ADD KEY `fk_tooth_images_treatment` (`selected_treatment_id`);

--
-- Indexes for table `tooth_status`
--
ALTER TABLE `tooth_status`
  ADD PRIMARY KEY (`tooth_status_id`),
  ADD KEY `fk_tooth_status_tooth` (`tooth_selected`),
  ADD KEY `fk_tooth_status_type` (`status_type_id`),
  ADD KEY `fk_tooth_status_selected_treatment` (`selected_treatment_id`);

--
-- Indexes for table `tooth_status_type`
--
ALTER TABLE `tooth_status_type`
  ADD PRIMARY KEY (`status_type_id`),
  ADD KEY `fk_tst_tooth` (`tooth_id`);

--
-- Indexes for table `treatments`
--
ALTER TABLE `treatments`
  ADD PRIMARY KEY (`treatment_id`);

--
-- Indexes for table `users`
--
ALTER TABLE `users`
  ADD PRIMARY KEY (`user_id`),
  ADD UNIQUE KEY `email` (`email`),
  ADD KEY `fk_users_specialization` (`specialization_id`),
  ADD KEY `fk_users_role` (`role_id`),
  ADD KEY `fk_users_added_by` (`added_by`);

--
-- Indexes for table `user_access_modules`
--
ALTER TABLE `user_access_modules`
  ADD PRIMARY KEY (`user_module_id`),
  ADD KEY `fk_uam_user` (`user_id`);

--
-- Indexes for table `user_logs`
--
ALTER TABLE `user_logs`
  ADD PRIMARY KEY (`user_log_id`),
  ADD KEY `fk_user_logs_user` (`user_id`);

--
-- AUTO_INCREMENT for dumped tables
--

--
-- AUTO_INCREMENT for table `appointments`
--
ALTER TABLE `appointments`
  MODIFY `appointment_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=33;

--
-- AUTO_INCREMENT for table `backup`
--
ALTER TABLE `backup`
  MODIFY `backup_id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `billing`
--
ALTER TABLE `billing`
  MODIFY `billing_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=10;

--
-- AUTO_INCREMENT for table `check_in_queue`
--
ALTER TABLE `check_in_queue`
  MODIFY `checkin_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=21;

--
-- AUTO_INCREMENT for table `clinical_records`
--
ALTER TABLE `clinical_records`
  MODIFY `record_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=10;

--
-- AUTO_INCREMENT for table `doctor_availability`
--
ALTER TABLE `doctor_availability`
  MODIFY `availability_id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `doctor_leaves`
--
ALTER TABLE `doctor_leaves`
  MODIFY `leave_id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `medical_history`
--
ALTER TABLE `medical_history`
  MODIFY `history_id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `notifications`
--
ALTER TABLE `notifications`
  MODIFY `notification_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=4;

--
-- AUTO_INCREMENT for table `patients`
--
ALTER TABLE `patients`
  MODIFY `patient_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=4;

--
-- AUTO_INCREMENT for table `patient_arrivals`
--
ALTER TABLE `patient_arrivals`
  MODIFY `arrival_id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `patient_profiling_audit`
--
ALTER TABLE `patient_profiling_audit`
  MODIFY `audit_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

--
-- AUTO_INCREMENT for table `prescriptions`
--
ALTER TABLE `prescriptions`
  MODIFY `prescription_id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `role`
--
ALTER TABLE `role`
  MODIFY `role_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=5;

--
-- AUTO_INCREMENT for table `schedule`
--
ALTER TABLE `schedule`
  MODIFY `schedule_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=22;

--
-- AUTO_INCREMENT for table `schedules`
--
ALTER TABLE `schedules`
  MODIFY `schedule_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=22;

--
-- AUTO_INCREMENT for table `schedule_reminders`
--
ALTER TABLE `schedule_reminders`
  MODIFY `reminder_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=8;

--
-- AUTO_INCREMENT for table `selected_services`
--
ALTER TABLE `selected_services`
  MODIFY `selected_service_id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `selected_treatments`
--
ALTER TABLE `selected_treatments`
  MODIFY `selected_treatment_id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `services`
--
ALTER TABLE `services`
  MODIFY `service_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=11;

--
-- AUTO_INCREMENT for table `specialization`
--
ALTER TABLE `specialization`
  MODIFY `specialization_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=9;

--
-- AUTO_INCREMENT for table `timeslot`
--
ALTER TABLE `timeslot`
  MODIFY `timeslot_id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `timeslot_service`
--
ALTER TABLE `timeslot_service`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=5;

--
-- AUTO_INCREMENT for table `tooth`
--
ALTER TABLE `tooth`
  MODIFY `tooth_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=33;

--
-- AUTO_INCREMENT for table `tooth_images`
--
ALTER TABLE `tooth_images`
  MODIFY `image_id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `tooth_status`
--
ALTER TABLE `tooth_status`
  MODIFY `tooth_status_id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `tooth_status_type`
--
ALTER TABLE `tooth_status_type`
  MODIFY `status_type_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=9;

--
-- AUTO_INCREMENT for table `treatments`
--
ALTER TABLE `treatments`
  MODIFY `treatment_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=11;

--
-- AUTO_INCREMENT for table `users`
--
ALTER TABLE `users`
  MODIFY `user_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=4;

--
-- AUTO_INCREMENT for table `user_access_modules`
--
ALTER TABLE `user_access_modules`
  MODIFY `user_module_id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `user_logs`
--
ALTER TABLE `user_logs`
  MODIFY `user_log_id` int(11) NOT NULL AUTO_INCREMENT;

--
-- Constraints for dumped tables
--

--
-- Constraints for table `appointments`
--
ALTER TABLE `appointments`
  ADD CONSTRAINT `appointments_ibfk_1` FOREIGN KEY (`timeslot_id`) REFERENCES `timeslot` (`timeslot_id`) ON DELETE SET NULL,
  ADD CONSTRAINT `appointments_ibfk_2` FOREIGN KEY (`service_id`) REFERENCES `services` (`service_id`) ON DELETE SET NULL,
  ADD CONSTRAINT `fk_appointments_patient` FOREIGN KEY (`patient_id`) REFERENCES `patients` (`patient_id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_appointments_rescheduled_from` FOREIGN KEY (`rescheduled_from`) REFERENCES `appointments` (`appointment_id`) ON DELETE SET NULL,
  ADD CONSTRAINT `fk_appointments_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`user_id`) ON DELETE SET NULL;

--
-- Constraints for table `backup`
--
ALTER TABLE `backup`
  ADD CONSTRAINT `fk_backup_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`user_id`) ON DELETE SET NULL;

--
-- Constraints for table `billing`
--
ALTER TABLE `billing`
  ADD CONSTRAINT `fk_billing_appointment` FOREIGN KEY (`appointment_id`) REFERENCES `appointments` (`appointment_id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_billing_processed_by` FOREIGN KEY (`processed_by`) REFERENCES `users` (`user_id`) ON DELETE SET NULL ON UPDATE CASCADE,
  ADD CONSTRAINT `fk_billing_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`user_id`) ON DELETE SET NULL;

--
-- Constraints for table `check_in_queue`
--
ALTER TABLE `check_in_queue`
  ADD CONSTRAINT `check_in_queue_ibfk_1` FOREIGN KEY (`timeslot_id`) REFERENCES `timeslot` (`timeslot_id`),
  ADD CONSTRAINT `fk_checkin_appointment` FOREIGN KEY (`appointment_id`) REFERENCES `appointments` (`appointment_id`) ON DELETE CASCADE;

--
-- Constraints for table `clinical_records`
--
ALTER TABLE `clinical_records`
  ADD CONSTRAINT `fk_clinrec_schedule` FOREIGN KEY (`schedule_id`) REFERENCES `schedules` (`schedule_id`) ON DELETE SET NULL;

--
-- Constraints for table `doctor_availability`
--
ALTER TABLE `doctor_availability`
  ADD CONSTRAINT `fk_avail_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`user_id`) ON DELETE CASCADE;

--
-- Constraints for table `doctor_leaves`
--
ALTER TABLE `doctor_leaves`
  ADD CONSTRAINT `fk_doctor_leaves_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`user_id`) ON DELETE CASCADE;

--
-- Constraints for table `medical_history`
--
ALTER TABLE `medical_history`
  ADD CONSTRAINT `fk_medical_history_patient` FOREIGN KEY (`patients_id`) REFERENCES `patients` (`patient_id`) ON DELETE CASCADE;

--
-- Constraints for table `notifications`
--
ALTER TABLE `notifications`
  ADD CONSTRAINT `fk_notifications_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`user_id`) ON DELETE CASCADE;

--
-- Constraints for table `patients`
--
ALTER TABLE `patients`
  ADD CONSTRAINT `fk_patients_added_by` FOREIGN KEY (`added_by`) REFERENCES `users` (`user_id`) ON DELETE SET NULL;

--
-- Constraints for table `patient_arrivals`
--
ALTER TABLE `patient_arrivals`
  ADD CONSTRAINT `patient_arrivals_ibfk_1` FOREIGN KEY (`appointment_id`) REFERENCES `appointments` (`appointment_id`) ON DELETE CASCADE,
  ADD CONSTRAINT `patient_arrivals_ibfk_2` FOREIGN KEY (`timeslot_id`) REFERENCES `timeslot` (`timeslot_id`) ON DELETE CASCADE,
  ADD CONSTRAINT `patient_arrivals_ibfk_3` FOREIGN KEY (`marked_by`) REFERENCES `users` (`user_id`) ON DELETE SET NULL;

--
-- Constraints for table `prescriptions`
--
ALTER TABLE `prescriptions`
  ADD CONSTRAINT `fk_prescriptions_appointment` FOREIGN KEY (`appointment_id`) REFERENCES `appointments` (`appointment_id`) ON DELETE SET NULL,
  ADD CONSTRAINT `fk_prescriptions_treatment` FOREIGN KEY (`selected_treatment_id`) REFERENCES `selected_treatments` (`selected_treatment_id`) ON DELETE SET NULL;

--
-- Constraints for table `schedule`
--
ALTER TABLE `schedule`
  ADD CONSTRAINT `fk_schedule_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`user_id`) ON DELETE SET NULL;

--
-- Constraints for table `schedules`
--
ALTER TABLE `schedules`
  ADD CONSTRAINT `fk_schedules_appointment` FOREIGN KEY (`appointment_id`) REFERENCES `appointments` (`appointment_id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_schedules_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`user_id`) ON DELETE SET NULL;

--
-- Constraints for table `schedule_reminders`
--
ALTER TABLE `schedule_reminders`
  ADD CONSTRAINT `fk_schedule_reminder` FOREIGN KEY (`schedule_id`) REFERENCES `schedules` (`schedule_id`) ON DELETE CASCADE;

--
-- Constraints for table `selected_services`
--
ALTER TABLE `selected_services`
  ADD CONSTRAINT `fk_selected_services_appointment` FOREIGN KEY (`appointment_id`) REFERENCES `appointments` (`appointment_id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_selected_services_service` FOREIGN KEY (`service_id`) REFERENCES `services` (`service_id`);

--
-- Constraints for table `selected_treatments`
--
ALTER TABLE `selected_treatments`
  ADD CONSTRAINT `fk_selected_treatments_service` FOREIGN KEY (`selected_service_id`) REFERENCES `selected_services` (`selected_service_id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_selected_treatments_treatment` FOREIGN KEY (`treatment_id`) REFERENCES `treatments` (`treatment_id`);

--
-- Constraints for table `services`
--
ALTER TABLE `services`
  ADD CONSTRAINT `fk_services_added_by` FOREIGN KEY (`added_by`) REFERENCES `users` (`user_id`) ON DELETE SET NULL;

--
-- Constraints for table `timeslot`
--
ALTER TABLE `timeslot`
  ADD CONSTRAINT `timeslot_ibfk_1` FOREIGN KEY (`doctor_id`) REFERENCES `users` (`user_id`),
  ADD CONSTRAINT `timeslot_ibfk_2` FOREIGN KEY (`appointment_id`) REFERENCES `appointments` (`appointment_id`) ON DELETE SET NULL;

--
-- Constraints for table `timeslot_service`
--
ALTER TABLE `timeslot_service`
  ADD CONSTRAINT `timeslot_service_ibfk_1` FOREIGN KEY (`service_id`) REFERENCES `services` (`service_id`);

--
-- Constraints for table `tooth_images`
--
ALTER TABLE `tooth_images`
  ADD CONSTRAINT `fk_tooth_images_treatment` FOREIGN KEY (`selected_treatment_id`) REFERENCES `selected_treatments` (`selected_treatment_id`) ON DELETE SET NULL;

--
-- Constraints for table `tooth_status`
--
ALTER TABLE `tooth_status`
  ADD CONSTRAINT `fk_tooth_status_selected_treatment` FOREIGN KEY (`selected_treatment_id`) REFERENCES `selected_treatments` (`selected_treatment_id`) ON DELETE SET NULL,
  ADD CONSTRAINT `fk_tooth_status_tooth` FOREIGN KEY (`tooth_selected`) REFERENCES `tooth` (`tooth_id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_tooth_status_type` FOREIGN KEY (`status_type_id`) REFERENCES `tooth_status_type` (`status_type_id`) ON DELETE SET NULL;

--
-- Constraints for table `tooth_status_type`
--
ALTER TABLE `tooth_status_type`
  ADD CONSTRAINT `fk_tst_tooth` FOREIGN KEY (`tooth_id`) REFERENCES `tooth` (`tooth_id`) ON DELETE CASCADE;

--
-- Constraints for table `users`
--
ALTER TABLE `users`
  ADD CONSTRAINT `fk_users_added_by` FOREIGN KEY (`added_by`) REFERENCES `users` (`user_id`) ON DELETE SET NULL,
  ADD CONSTRAINT `fk_users_role` FOREIGN KEY (`role_id`) REFERENCES `role` (`role_id`) ON DELETE SET NULL,
  ADD CONSTRAINT `fk_users_specialization` FOREIGN KEY (`specialization_id`) REFERENCES `specialization` (`specialization_id`) ON DELETE SET NULL;

--
-- Constraints for table `user_access_modules`
--
ALTER TABLE `user_access_modules`
  ADD CONSTRAINT `fk_uam_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`user_id`) ON DELETE CASCADE;

--
-- Constraints for table `user_logs`
--
ALTER TABLE `user_logs`
  ADD CONSTRAINT `fk_user_logs_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`user_id`) ON DELETE CASCADE;
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
