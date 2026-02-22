-- phpMyAdmin SQL Dump
-- version 5.2.1
-- https://www.phpmyadmin.net/
--
-- Host: 127.0.0.1
-- Generation Time: Jan 20, 2026 at 04:02 PM
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
(1, 'John', 'Smith', 'Robert', 'patient1', 'patient123', '1990-05-15', 'Male', '5551234567', 'patient1@example.com', '321 Patient Road', '2026-01-20 22:57:04', NULL);

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
  `added_by` int(11) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `specialization`
--

CREATE TABLE `specialization` (
  `specialization_id` int(11) NOT NULL,
  `specialization_name` varchar(150) NOT NULL,
  `description` text DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

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

-- --------------------------------------------------------

--
-- Table structure for table `tooth`
--

CREATE TABLE `tooth` (
  `tooth_id` int(11) NOT NULL,
  `tooth_number` varchar(10) NOT NULL,
  `upper_lower` tinyint(1) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

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
  MODIFY `appointment_id` int(11) NOT NULL AUTO_INCREMENT;

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
  MODIFY `branch_id` int(11) NOT NULL AUTO_INCREMENT;

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
  MODIFY `patient_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

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
  MODIFY `schedule_id` int(11) NOT NULL AUTO_INCREMENT;

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
  MODIFY `service_id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `specialization`
--
ALTER TABLE `specialization`
  MODIFY `specialization_id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `timeslot`
--
ALTER TABLE `timeslot`
  MODIFY `timeslot_id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `tooth`
--
ALTER TABLE `tooth`
  MODIFY `tooth_id` int(11) NOT NULL AUTO_INCREMENT;

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
  MODIFY `status_type_id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `treatments`
--
ALTER TABLE `treatments`
  MODIFY `treatment_id` int(11) NOT NULL AUTO_INCREMENT;

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
