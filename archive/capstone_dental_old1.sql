-- phpMyAdmin SQL Dump
-- version 5.2.1
-- https://www.phpmyadmin.net/
--
-- Host: 127.0.0.1
-- Generation Time: Jan 25, 2026 at 02:05 AM
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
  `reason` varchar(255) DEFAULT NULL,
  `is_online_appointment` tinyint(1) DEFAULT 0,
  `notes` text DEFAULT NULL,
  `status` enum('scheduled','confirmed','checked_in','in_progress','completed','cancelled','no_show','rescheduled') DEFAULT 'scheduled',
  `created_at` datetime DEFAULT current_timestamp(),
  `timeslot_id` int(11) DEFAULT NULL,
  `user_id` int(11) DEFAULT NULL,
  `branch_id` int(11) DEFAULT NULL,
  `rescheduled_from` int(11) DEFAULT NULL,
  `patient_id` int(11) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `appointments`
--

INSERT INTO `appointments` (`appointment_id`, `appointment_date`, `reason`, `is_online_appointment`, `notes`, `status`, `created_at`, `timeslot_id`, `user_id`, `branch_id`, `rescheduled_from`, `patient_id`) VALUES
(1, '2026-01-24', 'ouchy', 0, '', 'scheduled', '2026-01-23 16:03:40', 1, 1, NULL, NULL, 2),
(4, '2026-01-24', '0', 1, 'Ambot', 'scheduled', '2026-01-23 16:26:53', 4, 1, 1, NULL, 1),
(5, '2026-01-24', '0', 0, 'lala', 'scheduled', '2026-01-23 16:31:35', 5, 1, 1, NULL, 1);

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
  `status` enum('unpaid','partial','paid','refunded','void') DEFAULT 'unpaid',
  `processed_by` int(11) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `branch`
--

CREATE TABLE `branch` (
  `branch_id` int(11) NOT NULL,
  `branch_name` varchar(200) NOT NULL,
  `reason` varchar(255) DEFAULT NULL,
  `address` text DEFAULT NULL,
  `telephone_no` varchar(50) DEFAULT NULL,
  `email` varchar(150) DEFAULT NULL,
  `status` enum('active','inactive','closed','pending') DEFAULT 'active',
  `created_at` datetime DEFAULT current_timestamp(),
  `user_id` int(11) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `branch`
--

INSERT INTO `branch` (`branch_id`, `branch_name`, `reason`, `address`, `telephone_no`, `email`, `status`, `created_at`, `user_id`) VALUES
(1, 'Main Branch - Agusan', NULL, '123 Dental Clinic Street, Agusan', '1234567890', 'main@azucenadental.com', 'active', '2026-01-23 15:55:23', NULL),
(2, 'Branch 2 - Butuan', NULL, '456 Dental Plaza, Butuan', '0987654321', 'branch2@azucenadental.com', 'active', '2026-01-23 15:55:23', NULL),
(3, 'Branch 3 - Surigao', NULL, '789 Medical Center, Surigao', '1112223333', 'branch3@azucenadental.com', 'inactive', '2026-01-23 15:55:23', NULL);

-- --------------------------------------------------------

--
-- Table structure for table `check_in_queue`
--

CREATE TABLE `check_in_queue` (
  `checkin_id` int(11) NOT NULL,
  `status` enum('waiting','called','served','cancelled','no_show') DEFAULT 'waiting',
  `checkin_time` datetime DEFAULT current_timestamp(),
  `que_number` int(11) DEFAULT NULL,
  `appointment_id` int(11) DEFAULT NULL,
  `served_time` datetime DEFAULT NULL,
  `completed_time` datetime DEFAULT NULL
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
-- Table structure for table `patients`
--

CREATE TABLE `patients` (
  `patient_id` int(11) NOT NULL,
  `first_name` varchar(100) NOT NULL,
  `last_name` varchar(100) NOT NULL,
  `middle_name` varchar(100) DEFAULT NULL,
  `username` varchar(100) DEFAULT NULL,
  `password_hash` varchar(255) DEFAULT NULL,
  `date_of_birth` date DEFAULT NULL,
  `gender` varchar(20) DEFAULT NULL,
  `phone_number` varchar(30) DEFAULT NULL,
  `email` varchar(150) DEFAULT NULL,
  `address` text DEFAULT NULL,
  `created_at` datetime DEFAULT current_timestamp(),
  `added_by` int(11) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `patients`
--

INSERT INTO `patients` (`patient_id`, `first_name`, `last_name`, `middle_name`, `username`, `password_hash`, `date_of_birth`, `gender`, `phone_number`, `email`, `address`, `created_at`, `added_by`) VALUES
(1, 'John', 'Smith', 'Robert', 'patient1', 'patient123', '1990-05-15', 'Male', '5551234567', 'patient1@example.com', '321 Patient Road', '2026-01-20 22:57:04', NULL),
(2, 'Paul Benjie', 'Gonzales', 'Maglupay', 'paulbgonzales', 'paul123', '1997-08-25', 'Male', '096546511564', 'paul@gmail.com', 'Zone 3 Agusan', '2026-01-20 23:18:27', NULL);

-- --------------------------------------------------------

--
-- Table structure for table `payment`
--

CREATE TABLE `payment` (
  `payment_id` int(11) NOT NULL,
  `billing_id` int(11) NOT NULL,
  `payment_method` varchar(100) DEFAULT NULL,
  `payment_date` date DEFAULT curdate(),
  `amount_paid` decimal(12,2) NOT NULL,
  `reference_no` varchar(200) DEFAULT NULL,
  `remarks` text DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

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
  `branch_id` int(11) NOT NULL,
  `available_from` time NOT NULL,
  `available_to` time NOT NULL,
  `day_of_week` varchar(20) NOT NULL,
  `user_id` int(11) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `schedule`
--

INSERT INTO `schedule` (`schedule_id`, `status`, `branch_id`, `available_from`, `available_to`, `day_of_week`, `user_id`) VALUES
(8, 'active', 1, '09:00:00', '17:00:00', 'Monday', 2),
(9, 'active', 1, '09:00:00', '17:00:00', 'Tuesday', 2),
(10, 'active', 1, '09:00:00', '17:00:00', 'Wednesday', 2),
(11, 'active', 1, '09:00:00', '17:00:00', 'Thursday', 2),
(12, 'active', 1, '09:00:00', '17:00:00', 'Friday', 2),
(13, 'active', 1, '09:00:00', '14:00:00', 'Saturday', 2),
(14, 'inactive', 1, '10:00:00', '14:00:00', 'Sunday', 2),
(15, 'active', 1, '08:00:00', '17:00:00', 'Monday', 3),
(16, 'active', 1, '08:00:00', '17:00:00', 'Tuesday', 3),
(17, 'active', 1, '08:00:00', '17:00:00', 'Wednesday', 3),
(18, 'active', 1, '08:00:00', '17:00:00', 'Thursday', 3),
(19, 'active', 1, '08:00:00', '17:00:00', 'Friday', 3),
(20, 'active', 1, '08:00:00', '14:00:00', 'Saturday', 3),
(21, 'inactive', 1, '10:00:00', '14:00:00', 'Sunday', 3);

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
-- Dumping data for table `services`
--

INSERT INTO `services` (`service_id`, `service_name`, `initial_price`, `description`, `created_at`, `added_by`) VALUES
(1, 'Teeth Cleaning', 500.00, 'Professional teeth cleaning and scaling', '2026-01-23 15:54:14', 1),
(2, 'Root Canal Treatment', 3000.00, 'Root canal therapy for damaged teeth', '2026-01-23 15:54:14', 1),
(3, 'Crown Placement', 5000.00, 'Dental crown installation', '2026-01-23 15:54:14', 1),
(4, 'Filling', 800.00, 'Tooth filling for cavities', '2026-01-23 15:54:14', 1),
(5, 'Extraction', 1500.00, 'Tooth extraction', '2026-01-23 15:54:14', 1),
(6, 'Whitening', 2000.00, 'Professional teeth whitening', '2026-01-23 15:54:14', 1),
(7, 'Orthodontic Consultation', 1000.00, 'Braces and alignment consultation', '2026-01-23 15:54:14', 1),
(8, 'Denture Fitting', 4000.00, 'Partial or complete denture fitting', '2026-01-23 15:54:14', 1),
(9, 'Implant Consultation', 2000.00, 'Dental implant consultation and planning', '2026-01-23 15:54:14', 1),
(10, 'Periodic Checkup', 300.00, 'Regular dental examination', '2026-01-23 15:54:14', 1);

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
  `start_time` datetime NOT NULL,
  `end_time` datetime NOT NULL,
  `status` enum('available','booked','blocked','cancelled') DEFAULT 'available'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `timeslot`
--

INSERT INTO `timeslot` (`timeslot_id`, `start_time`, `end_time`, `status`) VALUES
(1, '2026-01-24 09:00:00', '2026-01-24 09:30:00', 'booked'),
(2, '2026-01-24 09:30:00', '2026-01-24 10:00:00', 'booked'),
(3, '2026-01-24 10:00:00', '2026-01-24 10:30:00', 'booked'),
(4, '2026-01-24 10:30:00', '2026-01-24 11:00:00', 'booked'),
(5, '2026-01-24 11:00:00', '2026-01-24 11:30:00', 'booked'),
(6, '2026-01-24 11:30:00', '2026-01-24 12:00:00', 'available'),
(7, '2026-01-24 13:00:00', '2026-01-24 13:30:00', 'available'),
(8, '2026-01-24 13:30:00', '2026-01-24 14:00:00', 'available'),
(9, '2026-01-24 14:00:00', '2026-01-24 14:30:00', 'available'),
(10, '2026-01-24 14:30:00', '2026-01-24 15:00:00', 'available'),
(11, '2026-01-24 15:00:00', '2026-01-24 15:30:00', 'available'),
(12, '2026-01-24 15:30:00', '2026-01-24 16:00:00', 'available'),
(13, '2026-01-27 09:00:00', '2026-01-27 09:30:00', 'available'),
(14, '2026-01-27 09:30:00', '2026-01-27 10:00:00', 'available'),
(15, '2026-01-27 10:00:00', '2026-01-27 10:30:00', 'available'),
(16, '2026-01-27 10:30:00', '2026-01-27 11:00:00', 'available'),
(17, '2026-01-27 11:00:00', '2026-01-27 11:30:00', 'available'),
(18, '2026-01-27 11:30:00', '2026-01-27 12:00:00', 'available'),
(19, '2026-01-27 13:00:00', '2026-01-27 13:30:00', 'available'),
(20, '2026-01-27 13:30:00', '2026-01-27 14:00:00', 'available'),
(21, '2026-01-27 14:00:00', '2026-01-27 14:30:00', 'available'),
(22, '2026-01-27 14:30:00', '2026-01-27 15:00:00', 'available'),
(23, '2026-01-27 15:00:00', '2026-01-27 15:30:00', 'available'),
(24, '2026-01-27 15:30:00', '2026-01-27 16:00:00', 'available'),
(25, '2026-01-28 09:00:00', '2026-01-28 09:30:00', 'available'),
(26, '2026-01-28 09:30:00', '2026-01-28 10:00:00', 'available'),
(27, '2026-01-28 10:00:00', '2026-01-28 10:30:00', 'available'),
(28, '2026-01-28 10:30:00', '2026-01-28 11:00:00', 'available'),
(29, '2026-01-28 11:00:00', '2026-01-28 11:30:00', 'available'),
(30, '2026-01-28 11:30:00', '2026-01-28 12:00:00', 'available'),
(31, '2026-01-28 13:00:00', '2026-01-28 13:30:00', 'available'),
(32, '2026-01-28 13:30:00', '2026-01-28 14:00:00', 'available'),
(33, '2026-01-28 14:00:00', '2026-01-28 14:30:00', 'available'),
(34, '2026-01-28 14:30:00', '2026-01-28 15:00:00', 'available'),
(35, '2026-01-28 15:00:00', '2026-01-28 15:30:00', 'available'),
(36, '2026-01-28 15:30:00', '2026-01-28 16:00:00', 'available'),
(37, '2026-01-29 09:00:00', '2026-01-29 09:30:00', 'available'),
(38, '2026-01-29 09:30:00', '2026-01-29 10:00:00', 'available'),
(39, '2026-01-29 10:00:00', '2026-01-29 10:30:00', 'available'),
(40, '2026-01-29 10:30:00', '2026-01-29 11:00:00', 'available'),
(41, '2026-01-29 11:00:00', '2026-01-29 11:30:00', 'available'),
(42, '2026-01-29 11:30:00', '2026-01-29 12:00:00', 'available'),
(43, '2026-01-29 13:00:00', '2026-01-29 13:30:00', 'available'),
(44, '2026-01-29 13:30:00', '2026-01-29 14:00:00', 'available'),
(45, '2026-01-29 14:00:00', '2026-01-29 14:30:00', 'available'),
(46, '2026-01-29 14:30:00', '2026-01-29 15:00:00', 'available'),
(47, '2026-01-29 15:00:00', '2026-01-29 15:30:00', 'available'),
(48, '2026-01-29 15:30:00', '2026-01-29 16:00:00', 'available'),
(49, '2026-01-30 09:00:00', '2026-01-30 09:30:00', 'available'),
(50, '2026-01-30 09:30:00', '2026-01-30 10:00:00', 'available'),
(51, '2026-01-30 10:00:00', '2026-01-30 10:30:00', 'available'),
(52, '2026-01-30 10:30:00', '2026-01-30 11:00:00', 'available'),
(53, '2026-01-30 11:00:00', '2026-01-30 11:30:00', 'available'),
(54, '2026-01-30 11:30:00', '2026-01-30 12:00:00', 'available'),
(55, '2026-01-30 13:00:00', '2026-01-30 13:30:00', 'available'),
(56, '2026-01-30 13:30:00', '2026-01-30 14:00:00', 'available'),
(57, '2026-01-30 14:00:00', '2026-01-30 14:30:00', 'available'),
(58, '2026-01-30 14:30:00', '2026-01-30 15:00:00', 'available'),
(59, '2026-01-30 15:00:00', '2026-01-30 15:30:00', 'available'),
(60, '2026-01-30 15:30:00', '2026-01-30 16:00:00', 'available'),
(61, '2026-01-31 09:00:00', '2026-01-31 09:30:00', 'available'),
(62, '2026-01-31 09:30:00', '2026-01-31 10:00:00', 'available'),
(63, '2026-01-31 10:00:00', '2026-01-31 10:30:00', 'available'),
(64, '2026-01-31 10:30:00', '2026-01-31 11:00:00', 'available'),
(65, '2026-01-31 11:00:00', '2026-01-31 11:30:00', 'available'),
(66, '2026-01-31 11:30:00', '2026-01-31 12:00:00', 'available'),
(67, '2026-01-31 13:00:00', '2026-01-31 13:30:00', 'available'),
(68, '2026-01-31 13:30:00', '2026-01-31 14:00:00', 'available'),
(69, '2026-01-31 14:00:00', '2026-01-31 14:30:00', 'available'),
(70, '2026-01-31 14:30:00', '2026-01-31 15:00:00', 'available'),
(71, '2026-01-31 15:00:00', '2026-01-31 15:30:00', 'available'),
(72, '2026-01-31 15:30:00', '2026-01-31 16:00:00', 'available');

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
  `username` varchar(100) NOT NULL,
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
  `added_by` int(11) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `users`
--

INSERT INTO `users` (`user_id`, `first_name`, `last_name`, `middle_name`, `username`, `password_hash`, `date_of_birth`, `gender`, `phone_number`, `email`, `address`, `is_active`, `created_at`, `specialization_id`, `role_id`, `added_by`) VALUES
(1, 'Admin', 'User', NULL, 'admin', 'admin123', '1980-01-15', 'Male', '1234567890', 'admin@dentalclinic.com', '123 Admin Street', 1, '2026-01-20 22:57:04', NULL, 1, NULL),
(2, 'Dr. John', 'Dentist', 'Michael', 'doctor', 'doctor123', '1985-05-20', 'Male', '0987654321', 'doctor@dentalclinic.com', '456 Doctor Avenue', 1, '2026-01-20 22:57:04', NULL, 2, NULL),
(3, 'Mary', 'Secretary', 'Jane', 'secretary', 'secretary123', '1990-08-10', 'Female', '1112223333', 'secretary@dentalclinic.com', '789 Secretary Lane', 1, '2026-01-20 22:57:04', NULL, 3, NULL);

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
  `ip_address` varchar(100) DEFAULT NULL,
  `branch_id` int(11) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Indexes for dumped tables
--

--
-- Indexes for table `appointments`
--
ALTER TABLE `appointments`
  ADD PRIMARY KEY (`appointment_id`),
  ADD KEY `fk_appointments_timeslot` (`timeslot_id`),
  ADD KEY `fk_appointments_user` (`user_id`),
  ADD KEY `fk_appointments_branch` (`branch_id`),
  ADD KEY `fk_appointments_rescheduled_from` (`rescheduled_from`),
  ADD KEY `fk_appointments_patient` (`patient_id`);

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
-- Indexes for table `branch`
--
ALTER TABLE `branch`
  ADD PRIMARY KEY (`branch_id`),
  ADD KEY `fk_branch_user` (`user_id`);

--
-- Indexes for table `check_in_queue`
--
ALTER TABLE `check_in_queue`
  ADD PRIMARY KEY (`checkin_id`),
  ADD UNIQUE KEY `appointment_id` (`appointment_id`);

--
-- Indexes for table `medical_history`
--
ALTER TABLE `medical_history`
  ADD PRIMARY KEY (`history_id`),
  ADD KEY `fk_medical_history_patient` (`patients_id`);

--
-- Indexes for table `patients`
--
ALTER TABLE `patients`
  ADD PRIMARY KEY (`patient_id`),
  ADD UNIQUE KEY `username` (`username`),
  ADD KEY `fk_patients_added_by` (`added_by`);

--
-- Indexes for table `payment`
--
ALTER TABLE `payment`
  ADD PRIMARY KEY (`payment_id`),
  ADD KEY `fk_payment_billing` (`billing_id`);

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
  ADD KEY `fk_schedule_branch` (`branch_id`),
  ADD KEY `fk_schedule_user` (`user_id`);

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
  ADD PRIMARY KEY (`timeslot_id`);

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
  ADD UNIQUE KEY `username` (`username`),
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
  ADD KEY `fk_user_logs_user` (`user_id`),
  ADD KEY `fk_user_logs_branch` (`branch_id`);

--
-- AUTO_INCREMENT for dumped tables
--

--
-- AUTO_INCREMENT for table `appointments`
--
ALTER TABLE `appointments`
  MODIFY `appointment_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=6;

--
-- AUTO_INCREMENT for table `backup`
--
ALTER TABLE `backup`
  MODIFY `backup_id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `billing`
--
ALTER TABLE `billing`
  MODIFY `billing_id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `branch`
--
ALTER TABLE `branch`
  MODIFY `branch_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=4;

--
-- AUTO_INCREMENT for table `check_in_queue`
--
ALTER TABLE `check_in_queue`
  MODIFY `checkin_id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `medical_history`
--
ALTER TABLE `medical_history`
  MODIFY `history_id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `patients`
--
ALTER TABLE `patients`
  MODIFY `patient_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=3;

--
-- AUTO_INCREMENT for table `payment`
--
ALTER TABLE `payment`
  MODIFY `payment_id` int(11) NOT NULL AUTO_INCREMENT;

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
  MODIFY `timeslot_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=73;

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
  ADD CONSTRAINT `fk_appointments_branch` FOREIGN KEY (`branch_id`) REFERENCES `branch` (`branch_id`) ON DELETE SET NULL,
  ADD CONSTRAINT `fk_appointments_patient` FOREIGN KEY (`patient_id`) REFERENCES `patients` (`patient_id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_appointments_rescheduled_from` FOREIGN KEY (`rescheduled_from`) REFERENCES `appointments` (`appointment_id`) ON DELETE SET NULL,
  ADD CONSTRAINT `fk_appointments_timeslot` FOREIGN KEY (`timeslot_id`) REFERENCES `timeslot` (`timeslot_id`) ON DELETE SET NULL,
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
  ADD CONSTRAINT `fk_billing_processed_by` FOREIGN KEY (`processed_by`) REFERENCES `users` (`user_id`) ON DELETE SET NULL,
  ADD CONSTRAINT `fk_billing_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`user_id`) ON DELETE SET NULL;

--
-- Constraints for table `branch`
--
ALTER TABLE `branch`
  ADD CONSTRAINT `fk_branch_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`user_id`) ON DELETE SET NULL;

--
-- Constraints for table `check_in_queue`
--
ALTER TABLE `check_in_queue`
  ADD CONSTRAINT `fk_checkin_appointment` FOREIGN KEY (`appointment_id`) REFERENCES `appointments` (`appointment_id`) ON DELETE CASCADE;

--
-- Constraints for table `medical_history`
--
ALTER TABLE `medical_history`
  ADD CONSTRAINT `fk_medical_history_patient` FOREIGN KEY (`patients_id`) REFERENCES `patients` (`patient_id`) ON DELETE CASCADE;

--
-- Constraints for table `patients`
--
ALTER TABLE `patients`
  ADD CONSTRAINT `fk_patients_added_by` FOREIGN KEY (`added_by`) REFERENCES `users` (`user_id`) ON DELETE SET NULL;

--
-- Constraints for table `payment`
--
ALTER TABLE `payment`
  ADD CONSTRAINT `fk_payment_billing` FOREIGN KEY (`billing_id`) REFERENCES `billing` (`billing_id`) ON DELETE CASCADE;

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
  ADD CONSTRAINT `fk_schedule_branch` FOREIGN KEY (`branch_id`) REFERENCES `branch` (`branch_id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_schedule_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`user_id`) ON DELETE SET NULL;

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
  ADD CONSTRAINT `fk_user_logs_branch` FOREIGN KEY (`branch_id`) REFERENCES `branch` (`branch_id`) ON DELETE SET NULL,
  ADD CONSTRAINT `fk_user_logs_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`user_id`) ON DELETE CASCADE;
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
