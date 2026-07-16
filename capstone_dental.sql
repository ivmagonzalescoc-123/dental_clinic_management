SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
START TRANSACTION;
SET time_zone = "+00:00";

CREATE TABLE `role` (
  `role_id` int(11) NOT NULL,
  `role_name` enum('admin','doctor','secretary','patient') DEFAULT NULL,
  `description` text DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

INSERT INTO `role` (`role_id`, `role_name`, `description`) VALUES
(1, 'admin', 'Administrator with full system access'),
(2, 'doctor', 'Doctor/Dentist - Can manage appointments and patient data'),
(3, 'secretary', 'Secretary/Receptionist - Can schedule appointments and manage front desk'),
(4, 'patient', 'Patient - Can view appointments and medical records');

CREATE TABLE `specialization` (
  `specialization_id` int(11) NOT NULL,
  `specialization_name` varchar(150) NOT NULL,
  `description` text DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

INSERT INTO `specialization` (`specialization_id`, `specialization_name`, `description`) VALUES
(1, 'General Dentistry', 'General dental care and treatments'),
(2, 'Orthodontics', 'Teeth alignment and braces'),
(3, 'Periodontics', 'Gum disease and dental implants'),
(4, 'Pedodontics', 'Pediatric dental care'),
(5, 'Endodontics', 'Root canal treatment'),
(6, 'Prosthodontics', 'Dentures and bridges'),
(7, 'Oral Surgery', 'Extraction and surgical procedures'),
(8, 'Cosmetic Dentistry', 'Whitening and aesthetic procedures');

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
  `role_id` int(11) DEFAULT NULL,
  `added_by` int(11) DEFAULT NULL,
  `must_change_password` tinyint(1) NOT NULL DEFAULT 0,
  `password_updated_at` datetime DEFAULT NULL,
  `failed_login_attempts` int(11) NOT NULL DEFAULT 0,
  `locked_until` datetime DEFAULT NULL,
  `last_login_at` datetime DEFAULT NULL,
  `profile_image` varchar(255) DEFAULT NULL,
  `work_type` enum('regular','part_time') NOT NULL DEFAULT 'regular'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

INSERT INTO `users` (`user_id`, `first_name`, `last_name`, `middle_name`, `password_hash`, `date_of_birth`, `gender`, `phone_number`, `email`, `address`, `is_active`, `created_at`, `role_id`, `added_by`, `must_change_password`, `password_updated_at`, `failed_login_attempts`, `locked_until`, `last_login_at`, `work_type`) VALUES
(1, 'Admin', 'User', 'Admin', '$2y$10$jxi94smqf8APzJhrmp5nguVWrMDxlY.p0GZOhAVSl9Jhrn5/m4phS', '1980-01-15', 'Male', '1234567890', 'admin@dentalclinic.com', '123 Admin Street', 1, '2026-01-20 22:57:04', 1, NULL, 0, '2026-03-12 20:37:18', 0, NULL, '2026-03-28 18:03:24', 'regular'),
(2, 'Dr. John', 'Dentist', 'Michael', '$2y$10$tswCVb9AxlWOb4AM2gVTE.iYgxgamR1n.8rfXTjvPU8pcbZC8IX0m', '1985-05-20', 'Male', '0987654321', 'doctor@dentalclinic.com', '456 Doctor Avenue', 1, '2026-01-20 22:57:04', 2, NULL, 0, '2026-03-12 20:38:23', 0, NULL, '2026-03-28 11:19:18', 'part_time'),
(3, 'Mary', 'Secretary', 'Jane', '$2y$10$L4her2tbM6RSUR5ONC5ySOVZmnFAW7OFK0aAvdx2hc756ZlqqKw76', '1990-08-10', 'Female', '1112223333', 'secretary@dentalclinic.com', '789 Secretary Lane', 1, '2026-01-20 22:57:04', 3, NULL, 0, '2026-03-12 20:17:52', 0, NULL, '2026-03-28 18:13:54', 'regular'),
(4, 'Hanz', 'Tan', NULL, '$2y$10$9IZkUO7.4CJobHU3YTt3I.sJLuDADpfWTqckZrR3c/khjUtAFVpV2', '1111-11-11', 'Male', '+63 9275269342', 'tan@gmail.com', 'Zone 5 Gusa', 1, '2026-03-12 21:26:09', 2, NULL, 1, '2026-03-12 21:26:09', 0, NULL, '2026-03-12 21:26:43', 'regular'),
(5, 'Justine', 'Gaamil', NULL, '$2y$10$K09tcM1R.bS90VhNS6rdEueHSzZdfSRuvbxjpRd.8zgHQRixGa9Ni', '2021-01-12', 'Male', '+09232323232', 'Gaamil@dentalclinic.com', 'Brgy 15 CDO', 1, '2026-03-28 18:18:39', 2, NULL, 1, '2026-03-28 18:18:39', 0, NULL, NULL, 'part_time');

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
  `profile_image` varchar(255) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE `patient_profiling` (
  `profiling_id` int(11) NOT NULL,
  `patient_id` int(11) NOT NULL,
  `record_id` int(11) DEFAULT NULL,
  `race` varchar(100) DEFAULT NULL,
  `occupation` varchar(150) DEFAULT NULL,
  `medical_history` text DEFAULT NULL,
  `medications` text DEFAULT NULL,
  `allergies` varchar(500) DEFAULT NULL,
  `smoking` varchar(100) DEFAULT NULL,
  `alcohol` varchar(100) DEFAULT NULL,
  `is_hypertensive` tinyint(1) DEFAULT 0,
  `is_diabetic` tinyint(1) DEFAULT 0,
  `is_asthmatic` tinyint(1) DEFAULT 0,
  `has_heart_disease` tinyint(1) DEFAULT 0,
  `is_hiv_positive` tinyint(1) DEFAULT 0,
  `bleeding_disorder` varchar(255) DEFAULT NULL,
  `asa_classification` varchar(50) DEFAULT NULL,
  `pregnancy_status` varchar(100) DEFAULT NULL,
  `previous_dental_treatment` text DEFAULT NULL,
  `dental_anxiety` varchar(100) DEFAULT NULL,
  `emergency_contact` varchar(255) DEFAULT NULL,
  `emergency_phone` varchar(30) DEFAULT NULL,
  `background_notes` text DEFAULT NULL,
  `created_at` datetime DEFAULT current_timestamp(),
  `updated_at` datetime DEFAULT NULL ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE `user_logs` (
  `user_log_id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `login_time` datetime DEFAULT NULL,
  `last_activity` datetime DEFAULT NULL,
  `logout_time` datetime DEFAULT NULL,
  `ip_address` varchar(100) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE `patient_logs` (
  `patient_log_id` int(11) NOT NULL,
  `patient_id` int(11) NOT NULL,
  `login_time` datetime DEFAULT NULL,
  `last_activity` datetime DEFAULT NULL,
  `logout_time` datetime DEFAULT NULL,
  `ip_address` varchar(100) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE `password_resets` (
  `reset_id` int(11) NOT NULL,
  `user_type` enum('user','patient') NOT NULL,
  `user_id` int(11) NOT NULL,
  `email` varchar(150) NOT NULL,
  `token_hash` char(64) NOT NULL,
  `otp_hash` char(64) NOT NULL,
  `expires_at` datetime NOT NULL,
  `used_at` datetime DEFAULT NULL,
  `ip_address` varchar(45) DEFAULT NULL,
  `created_at` datetime NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE `dentist_specialization` (
  `doctor_specialized_id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `specialization_id` int(11) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

INSERT INTO `dentist_specialization` (`doctor_specialized_id`, `user_id`, `specialization_id`) VALUES
(1, 2, 2),
(2, 5, 2);

CREATE TABLE `doctor_availability` (
  `availability_id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `weekday` tinyint(4) NOT NULL,
  `start_time` time NOT NULL,
  `end_time` time NOT NULL,
  `status` enum('active','inactive','suspended') NOT NULL DEFAULT 'active',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

INSERT INTO `doctor_availability` (`availability_id`, `user_id`, `weekday`, `start_time`, `end_time`, `status`, `created_at`) VALUES
(31, 2, 1, '08:00:00', '17:00:00', 'active', '2026-03-28 10:06:41'),
(32, 2, 2, '08:00:00', '17:00:00', 'active', '2026-03-28 10:06:41'),
(33, 2, 4, '08:00:00', '17:00:00', 'active', '2026-03-28 10:06:41'),
(34, 2, 6, '08:00:00', '17:00:00', 'active', '2026-03-28 10:06:41'),
(35, 5, 1, '08:00:00', '17:00:00', 'active', '2026-03-28 10:19:31'),
(36, 5, 3, '08:00:00', '17:00:00', 'active', '2026-03-28 10:19:31'),
(37, 5, 4, '08:00:00', '17:00:00', 'active', '2026-03-28 10:19:31'),
(38, 5, 5, '08:00:00', '17:00:00', 'active', '2026-03-28 10:19:31');

CREATE TABLE `doctor_leaves` (
  `leave_id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `leave_start_date` date NOT NULL,
  `leave_end_date` date NOT NULL,
  `reason` varchar(255) DEFAULT NULL,
  `status` enum('pending','approved','rejected') NOT NULL DEFAULT 'approved',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

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

CREATE TABLE `timeslot_service` (
  `id` int(11) NOT NULL,
  `service_id` int(11) NOT NULL,
  `duration_minutes` int(11) NOT NULL,
  `buffer_minutes` int(11) DEFAULT 5,
  `slots_per_day_limit` int(11) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

INSERT INTO `timeslot_service` (`id`, `service_id`, `duration_minutes`, `buffer_minutes`, `slots_per_day_limit`, `created_at`) VALUES
(1, 1, 30, 5, NULL, '2026-03-10 02:22:02'),
(2, 2, 120, 10, NULL, '2026-03-10 02:22:02'),
(3, 3, 90, 10, NULL, '2026-03-10 02:22:02'),
(4, 4, 45, 5, NULL, '2026-03-10 02:22:02');

CREATE TABLE `appointments` (
  `appointment_id` int(11) NOT NULL,
  `appointment_date` date NOT NULL,
  `timeslot_id` int(11) DEFAULT NULL,
  `reason` varchar(255) DEFAULT NULL,
  `is_online_appointment` tinyint(1) DEFAULT 0,
  `notes` text DEFAULT NULL,
  `status` enum('scheduled','confirmed','arrived','checked_in','in_progress','completed_by_doctor','awaiting_secretary_records','records_complete','completed','cancelled','no_show','rescheduled') DEFAULT 'scheduled',
  `created_at` datetime DEFAULT current_timestamp(),
  `user_id` int(11) DEFAULT NULL,
  `rescheduled_from` int(11) DEFAULT NULL,
  `patient_id` int(11) NOT NULL,
  `is_emergency` tinyint(1) DEFAULT 0,
  `completed_by_doctor_at` datetime DEFAULT NULL,
  `session_started_at` datetime DEFAULT NULL,
  `secretary_records_completed_at` datetime DEFAULT NULL,
  `booking_id` int(11) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE `appointment_reschedule_requests` (
  `request_id` int(11) NOT NULL,
  `appointment_id` int(11) NOT NULL,
  `patient_id` int(11) NOT NULL,
  `requested_doctor_id` int(11) DEFAULT NULL,
  `requested_date` date NOT NULL,
  `requested_timeslot_id` int(11) NOT NULL,
  `request_note` varchar(500) DEFAULT NULL,
  `status` enum('pending','approved','rejected','cancelled') NOT NULL DEFAULT 'pending',
  `reviewed_by` int(11) DEFAULT NULL,
  `review_note` varchar(500) DEFAULT NULL,
  `reviewed_at` datetime DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE `appointment_media` (
  `media_id` int(11) NOT NULL,
  `appointment_id` int(11) NOT NULL,
  `file_name` varchar(255) NOT NULL,
  `file_path` varchar(500) NOT NULL,
  `file_type` enum('photo','xray','document') DEFAULT 'photo',
  `file_size` int(11) DEFAULT NULL,
  `media_order` int(11) DEFAULT 0,
  `tooth_number` int(11) DEFAULT NULL,
  `uploaded_by` int(11) DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `uploaded_at` datetime DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE `appointment_doctor_notes` (
  `note_id` int(11) NOT NULL,
  `appointment_id` int(11) NOT NULL,
  `doctor_id` int(11) NOT NULL,
  `note_text` text DEFAULT NULL,
  `created_at` datetime DEFAULT current_timestamp(),
  `updated_at` datetime DEFAULT NULL ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE `selected_services` (
  `selected_service_id` int(11) NOT NULL,
  `appointment_id` int(11) NOT NULL,
  `notes` text DEFAULT NULL,
  `created_at` datetime DEFAULT current_timestamp(),
  `service_id` int(11) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE `patient_schedules` (
  `schedule_id` int(11) NOT NULL,
  `appointment_id` int(11) NOT NULL,
  `user_id` int(11) DEFAULT NULL,
  `scheduled_start` datetime NOT NULL,
  `scheduled_end` datetime DEFAULT NULL,
  `status` enum('scheduled','rescheduled','cancelled','completed') DEFAULT 'scheduled',
  `priority` tinyint(4) DEFAULT 1,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT NULL ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE `schedule_reminders` (
  `reminder_id` int(11) NOT NULL,
  `patient_schedule_id` int(11) NOT NULL,
  `remind_at` datetime NOT NULL,
  `channel` enum('email','sms','push') NOT NULL DEFAULT 'email',
  `sent_at` datetime DEFAULT NULL,
  `payload` text DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE `tooth` (
  `tooth_id` int(11) NOT NULL,
  `tooth_number` varchar(10) NOT NULL,
  `upper_lower` tinyint(1) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

INSERT INTO `tooth` (`tooth_id`, `tooth_number`, `upper_lower`) VALUES
(1, '11', 1), (2, '12', 1), (3, '13', 1), (4, '14', 1), (5, '15', 1), (6, '16', 1), (7, '17', 1), (8, '18', 1),
(9, '21', 1), (10, '22', 1), (11, '23', 1), (12, '24', 1), (13, '25', 1), (14, '26', 1), (15, '27', 1), (16, '28', 1),
(17, '31', 0), (18, '32', 0), (19, '33', 0), (20, '34', 0), (21, '35', 0), (22, '36', 0), (23, '37', 0), (24, '38', 0),
(25, '41', 0), (26, '42', 0), (27, '43', 0), (28, '44', 0), (29, '45', 0), (30, '46', 0), (31, '47', 0), (32, '48', 0);

CREATE TABLE `tooth_status_type` (
  `status_type_id` int(11) NOT NULL,
  `label` varchar(120) NOT NULL,
  `category` enum('decay','restoration','missing','other') DEFAULT 'other',
  `code` varchar(50) NOT NULL,
  `description` varchar(255) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

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

CREATE TABLE `tooth_status` (
  `tooth_status_id` int(11) NOT NULL,
  `record_id` int(11) DEFAULT NULL,
  `tooth_selected` int(11) NOT NULL,
  `status_type_id` int(11) DEFAULT NULL,
  `tooth_surface` varchar(50) DEFAULT NULL,
  `status` enum('healthy','decayed','filled','missing','crowned','root_canal','restored','unknown') DEFAULT 'unknown'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE `clinical_records` (
  `record_id` int(11) NOT NULL,
  `appointment_id` int(11) NOT NULL,
  `doctor_id` int(11) DEFAULT NULL,
  `patient_id` int(11) DEFAULT NULL,
  `summary` text DEFAULT NULL,
  `doctor_notes` text DEFAULT NULL,
  `follow_up_date` date DEFAULT NULL,
  `status` enum('in_progress','submitted','finalized') DEFAULT 'in_progress',
  `submitted_by` int(11) DEFAULT NULL,
  `submitted_at` datetime DEFAULT NULL,
  `primary_image` varchar(500) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE `clinical_record_images` (
  `image_id` int(11) NOT NULL,
  `record_id` int(11) NOT NULL,
  `file_path` varchar(500) NOT NULL,
  `file_type` enum('photo','xray','document') DEFAULT 'photo',
  `tooth_number` int(11) DEFAULT NULL,
  `uploaded_by` int(11) DEFAULT NULL,
  `uploaded_at` datetime DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE `clinical_record_services` (
  `id` int(11) NOT NULL,
  `record_id` int(11) NOT NULL,
  `service_id` int(11) NOT NULL,
  `notes` text DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE `treatment_plans` (
  `treatment_id` int(11) NOT NULL,
  `record_id` int(11) NOT NULL,
  `service_id` int(11) DEFAULT NULL,
  `tooth_number` int(11) DEFAULT NULL,
  `description` text NOT NULL,
  `status` enum('planned','in_progress','completed','cancelled') DEFAULT 'planned',
  `estimated_cost` decimal(12,2) DEFAULT NULL,
  `created_at` datetime DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE `treatment_materials` (
  `material_id` int(11) NOT NULL,
  `record_id` int(11) NOT NULL,
  `material_name` varchar(255) NOT NULL,
  `quantity` decimal(10,2) DEFAULT 1,
  `unit` varchar(50) DEFAULT NULL,
  `cost` decimal(12,2) DEFAULT NULL,
  `notes` text DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE `prescriptions` (
  `prescription_id` int(11) NOT NULL,
  `appointment_id` int(11) DEFAULT NULL,
  `prescription_date` date DEFAULT NULL,
  `medicine_name` varchar(255) DEFAULT NULL,
  `dosage` varchar(100) DEFAULT NULL,
  `medicine_brand` varchar(150) DEFAULT NULL,
  `duration` varchar(100) DEFAULT NULL,
  `notes` text DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE `billing` (
  `billing_id` int(11) NOT NULL,
  `appointment_id` int(11) DEFAULT NULL,
  `user_id` int(11) DEFAULT NULL,
  `total_amount` decimal(12,2) NOT NULL DEFAULT 0.00,
  `amount_paid` decimal(12,2) NOT NULL DEFAULT 0.00,
  `balance` decimal(12,2) NOT NULL DEFAULT 0.00,
  `subtotal_amount` decimal(12,2) NOT NULL DEFAULT 0.00,
  `discount_type` enum('none','senior','pwd','custom') NOT NULL DEFAULT 'none',
  `discount_percent` decimal(5,2) NOT NULL DEFAULT 0.00,
  `discount_notes` varchar(255) DEFAULT NULL,
  `created_at` datetime DEFAULT current_timestamp(),
  `status` enum('unpaid','partial','paid') NOT NULL DEFAULT 'unpaid',
  `processed_by` int(11) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE `billing_line_items` (
  `item_id` int(11) NOT NULL,
  `billing_id` int(11) DEFAULT NULL,
  `appointment_id` int(11) NOT NULL,
  `service_id` int(11) DEFAULT NULL,
  `description` varchar(255) NOT NULL,
  `amount` decimal(12,2) NOT NULL DEFAULT 0.00,
  `item_type` enum('service','extra') NOT NULL DEFAULT 'service',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE `billing_installments` (
  `installment_id` int(11) NOT NULL,
  `billing_id` int(11) NOT NULL,
  `total_amount` decimal(12,2) NOT NULL DEFAULT 0.00,
  `down_payment_percent` decimal(5,2) NOT NULL DEFAULT 50.00,
  `down_payment_amount` decimal(12,2) NOT NULL DEFAULT 0.00,
  `num_terms` int(11) NOT NULL DEFAULT 0,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE `billing_installment_terms` (
  `term_id` int(11) NOT NULL,
  `installment_id` int(11) NOT NULL,
  `term_number` int(11) NOT NULL,
  `due_date` date NOT NULL,
  `amount` decimal(12,2) NOT NULL DEFAULT 0.00,
  `status` enum('pending','paid') NOT NULL DEFAULT 'pending',
  `paid_at` datetime DEFAULT NULL,
  `due_soon_reminder_sent_at` datetime DEFAULT NULL,
  `overdue_reminder_sent_at` datetime DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE `payments` (
  `payment_id` int(11) NOT NULL,
  `billing_id` int(11) NOT NULL,
  `amount` decimal(12,2) NOT NULL DEFAULT 0.00,
  `payment_method` varchar(50) DEFAULT 'cash',
  `or_number` varchar(50) NOT NULL,
  `notes` varchar(500) DEFAULT NULL,
  `recorded_by` int(11) DEFAULT NULL,
  `paid_at` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE `invoices` (
  `invoice_id` int(11) NOT NULL,
  `billing_id` int(11) NOT NULL,
  `invoice_number` varchar(30) NOT NULL,
  `issued_at` datetime NOT NULL DEFAULT current_timestamp(),
  `issued_by` int(11) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE `notifications` (
  `notification_id` int(11) NOT NULL,
  `user_id` int(11) DEFAULT NULL,
  `title` varchar(255) NOT NULL,
  `body` text DEFAULT NULL,
  `link` varchar(255) DEFAULT NULL,
  `is_read` tinyint(1) DEFAULT 0,
  `created_at` datetime DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE `patient_notifications` (
  `patient_notification_id` int(11) NOT NULL,
  `patient_id` int(11) NOT NULL,
  `title` varchar(255) NOT NULL,
  `body` text NOT NULL,
  `link` varchar(255) DEFAULT NULL,
  `is_read` tinyint(1) NOT NULL DEFAULT 0,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE `contact_messages` (
  `message_id` int(11) NOT NULL,
  `full_name` varchar(150) NOT NULL,
  `email` varchar(150) NOT NULL,
  `phone_number` varchar(30) DEFAULT NULL,
  `message` text NOT NULL,
  `status` enum('new','read','archived') NOT NULL DEFAULT 'new',
  `read_by` int(11) DEFAULT NULL,
  `read_at` datetime DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE `system_logs` (
  `system_log_id` int(11) NOT NULL,
  `user_id` int(11) DEFAULT NULL,
  `action` varchar(120) NOT NULL,
  `entity_type` varchar(100) DEFAULT NULL,
  `entity_id` int(11) DEFAULT NULL,
  `details` text DEFAULT NULL,
  `ip_address` varchar(100) DEFAULT NULL,
  `user_agent` varchar(255) DEFAULT NULL,
  `created_at` datetime DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE `backup` (
  `backup_id` int(11) NOT NULL,
  `user_id` int(11) DEFAULT NULL,
  `backup_date` datetime DEFAULT current_timestamp(),
  `file_name` varchar(255) DEFAULT NULL,
  `file_path` varchar(500) DEFAULT NULL,
  `file_size` decimal(12,2) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE `bookings` (
  `booking_id` int(11) NOT NULL AUTO_INCREMENT,
  `first_name` varchar(100) NOT NULL,
  `last_name` varchar(100) NOT NULL,
  `email` varchar(150) NOT NULL,
  `phone_number` varchar(30) DEFAULT NULL,
  `date_of_birth` date DEFAULT NULL,
  `preferred_date` date NOT NULL,
  `preferred_time` varchar(20) NOT NULL DEFAULT '8:00 AM',
  `reason` varchar(500) DEFAULT NULL,
  `otp_hash` char(64) DEFAULT NULL,
  `otp_expires_at` datetime DEFAULT NULL,
  `status` enum('pending_verification','verified','approved','converted','cancelled') NOT NULL DEFAULT 'pending_verification',
  `converted_to_appointment_id` int(11) DEFAULT NULL,
  `converted_to_patient_id` int(11) DEFAULT NULL,
  `created_at` datetime DEFAULT current_timestamp(),
  PRIMARY KEY (`booking_id`),
  KEY `idx_bookings_status` (`status`),
  KEY `idx_bookings_email` (`email`),
  KEY `idx_bookings_preferred_date` (`preferred_date`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

ALTER TABLE `role`
  ADD PRIMARY KEY (`role_id`);

ALTER TABLE `specialization`
  ADD PRIMARY KEY (`specialization_id`);

ALTER TABLE `users`
  ADD PRIMARY KEY (`user_id`),
  ADD UNIQUE KEY `email` (`email`),
  ADD KEY `fk_users_role` (`role_id`),
  ADD KEY `fk_users_added_by` (`added_by`);

ALTER TABLE `patients`
  ADD PRIMARY KEY (`patient_id`),
  ADD KEY `fk_patients_added_by` (`added_by`);

ALTER TABLE `patient_profiling`
  ADD PRIMARY KEY (`profiling_id`),
  ADD KEY `idx_pp_patient` (`patient_id`),
  ADD KEY `idx_pp_record` (`record_id`);

ALTER TABLE `user_logs`
  ADD PRIMARY KEY (`user_log_id`),
  ADD KEY `fk_user_logs_user` (`user_id`);

ALTER TABLE `patient_logs`
  ADD PRIMARY KEY (`patient_log_id`),
  ADD KEY `fk_patient_logs_patient` (`patient_id`);

ALTER TABLE `password_resets`
  ADD PRIMARY KEY (`reset_id`);

ALTER TABLE `dentist_specialization`
  ADD PRIMARY KEY (`doctor_specialized_id`),
  ADD UNIQUE KEY `uq_dentist_specialization_user_spec` (`user_id`,`specialization_id`),
  ADD KEY `idx_dentist_specialization_specialization` (`specialization_id`);

ALTER TABLE `doctor_availability`
  ADD PRIMARY KEY (`availability_id`),
  ADD KEY `user_id` (`user_id`);

ALTER TABLE `doctor_leaves`
  ADD PRIMARY KEY (`leave_id`),
  ADD KEY `idx_doctor_leave_dates` (`user_id`,`leave_start_date`,`leave_end_date`),
  ADD KEY `idx_doctor_leave_status` (`status`);

ALTER TABLE `services`
  ADD PRIMARY KEY (`service_id`),
  ADD KEY `fk_services_added_by` (`added_by`);

ALTER TABLE `timeslot`
  ADD PRIMARY KEY (`timeslot_id`),
  ADD UNIQUE KEY `uq_doctor_slot` (`doctor_id`,`slot_date`,`start_time`),
  ADD KEY `idx_timeslot_doc_date_status` (`doctor_id`,`slot_date`,`status`),
  ADD KEY `idx_timeslot_appointment_id` (`appointment_id`);

ALTER TABLE `timeslot_service`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_service` (`service_id`);

ALTER TABLE `appointments`
  ADD PRIMARY KEY (`appointment_id`),
  ADD KEY `fk_appointments_user` (`user_id`),
  ADD KEY `fk_appointments_rescheduled_from` (`rescheduled_from`),
  ADD KEY `fk_appointments_patient` (`patient_id`),
  ADD KEY `timeslot_id` (`timeslot_id`);

ALTER TABLE `appointment_reschedule_requests`
  ADD PRIMARY KEY (`request_id`),
  ADD KEY `idx_arr_status_created` (`status`,`created_at`),
  ADD KEY `idx_arr_appointment_status` (`appointment_id`,`status`),
  ADD KEY `idx_arr_patient_status` (`patient_id`,`status`);

ALTER TABLE `appointment_media`
  ADD PRIMARY KEY (`media_id`),
  ADD KEY `idx_appointment` (`appointment_id`);

ALTER TABLE `appointment_doctor_notes`
  ADD PRIMARY KEY (`note_id`),
  ADD KEY `idx_appointment` (`appointment_id`);

ALTER TABLE `selected_services`
  ADD PRIMARY KEY (`selected_service_id`),
  ADD KEY `fk_selected_services_appointment` (`appointment_id`),
  ADD KEY `fk_selected_services_service` (`service_id`);

ALTER TABLE `patient_schedules`
  ADD PRIMARY KEY (`schedule_id`),
  ADD KEY `appointment_id` (`appointment_id`),
  ADD KEY `user_id` (`user_id`);

ALTER TABLE `schedule_reminders`
  ADD PRIMARY KEY (`reminder_id`),
  ADD KEY `patient_schedule_id` (`patient_schedule_id`);

ALTER TABLE `tooth`
  ADD PRIMARY KEY (`tooth_id`);

ALTER TABLE `tooth_status_type`
  ADD PRIMARY KEY (`status_type_id`),
  ADD UNIQUE KEY `uk_chart_legend_code` (`code`);

ALTER TABLE `tooth_status`
  ADD PRIMARY KEY (`tooth_status_id`),
  ADD UNIQUE KEY `uq_tooth_status_record_tooth_surface` (`record_id`, `tooth_selected`, `tooth_surface`, `status`),
  ADD KEY `fk_tooth_status_tooth` (`tooth_selected`),
  ADD KEY `fk_tooth_status_type` (`status_type_id`),
  ADD KEY `fk_tooth_status_record` (`record_id`);

ALTER TABLE `clinical_records`
  ADD PRIMARY KEY (`record_id`),
  ADD KEY `appointment_id` (`appointment_id`),
  ADD KEY `doctor_id` (`doctor_id`),
  ADD KEY `patient_id` (`patient_id`),
  ADD KEY `submitted_by` (`submitted_by`);

ALTER TABLE `clinical_record_images`
  ADD PRIMARY KEY (`image_id`),
  ADD KEY `idx_cri_record` (`record_id`);

ALTER TABLE `clinical_record_services`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_crs_record_service` (`record_id`,`service_id`),
  ADD KEY `idx_crs_record` (`record_id`),
  ADD KEY `idx_crs_service` (`service_id`);

ALTER TABLE `treatment_plans`
  ADD PRIMARY KEY (`treatment_id`),
  ADD KEY `idx_tp_record` (`record_id`),
  ADD KEY `idx_tp_service` (`service_id`);

ALTER TABLE `treatment_materials`
  ADD PRIMARY KEY (`material_id`),
  ADD KEY `idx_tm_record` (`record_id`);

ALTER TABLE `prescriptions`
  ADD PRIMARY KEY (`prescription_id`),
  ADD KEY `fk_prescriptions_appointment` (`appointment_id`);

ALTER TABLE `billing`
  ADD PRIMARY KEY (`billing_id`),
  ADD UNIQUE KEY `appointment_id` (`appointment_id`),
  ADD KEY `fk_billing_user` (`user_id`),
  ADD KEY `fk_billing_processed_by` (`processed_by`);

ALTER TABLE `billing_line_items`
  ADD PRIMARY KEY (`item_id`),
  ADD KEY `idx_bli_appointment` (`appointment_id`),
  ADD KEY `idx_bli_billing` (`billing_id`);

ALTER TABLE `billing_installments`
  ADD PRIMARY KEY (`installment_id`),
  ADD UNIQUE KEY `uq_installment_billing` (`billing_id`);

ALTER TABLE `billing_installment_terms`
  ADD PRIMARY KEY (`term_id`),
  ADD KEY `idx_bit_installment` (`installment_id`),
  ADD KEY `idx_bit_due` (`due_date`);

ALTER TABLE `payments`
  ADD PRIMARY KEY (`payment_id`),
  ADD KEY `idx_payments_billing` (`billing_id`);

ALTER TABLE `invoices`
  ADD PRIMARY KEY (`invoice_id`),
  ADD UNIQUE KEY `uq_invoice_billing` (`billing_id`),
  ADD UNIQUE KEY `uq_invoice_number` (`invoice_number`);

ALTER TABLE `notifications`
  ADD PRIMARY KEY (`notification_id`),
  ADD KEY `idx_notifications_user` (`user_id`),
  ADD KEY `idx_notifications_is_read` (`is_read`);

ALTER TABLE `patient_notifications`
  ADD PRIMARY KEY (`patient_notification_id`),
  ADD KEY `idx_patient_notifications_patient` (`patient_id`),
  ADD KEY `idx_patient_notifications_read` (`is_read`);

ALTER TABLE `contact_messages`
  ADD PRIMARY KEY (`message_id`),
  ADD KEY `idx_contact_status_created` (`status`,`created_at`),
  ADD KEY `idx_contact_email` (`email`);

ALTER TABLE `system_logs`
  ADD PRIMARY KEY (`system_log_id`),
  ADD KEY `fk_system_logs_user` (`user_id`),
  ADD KEY `idx_system_logs_action_created` (`action`,`created_at`);

ALTER TABLE `backup`
  ADD PRIMARY KEY (`backup_id`),
  ADD KEY `fk_backup_user` (`user_id`);

ALTER TABLE `role`
  MODIFY `role_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=5;

ALTER TABLE `specialization`
  MODIFY `specialization_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=9;

ALTER TABLE `users`
  MODIFY `user_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=6;

ALTER TABLE `patients`
  MODIFY `patient_id` int(11) NOT NULL AUTO_INCREMENT;

ALTER TABLE `patient_profiling`
  MODIFY `profiling_id` int(11) NOT NULL AUTO_INCREMENT;

ALTER TABLE `user_logs`
  MODIFY `user_log_id` int(11) NOT NULL AUTO_INCREMENT;

ALTER TABLE `patient_logs`
  MODIFY `patient_log_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

ALTER TABLE `password_resets`
  MODIFY `reset_id` int(11) NOT NULL AUTO_INCREMENT;

ALTER TABLE `dentist_specialization`
  MODIFY `doctor_specialized_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=3;

ALTER TABLE `doctor_availability`
  MODIFY `availability_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=39;

ALTER TABLE `doctor_leaves`
  MODIFY `leave_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=4;

ALTER TABLE `services`
  MODIFY `service_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=11;

ALTER TABLE `timeslot`
  MODIFY `timeslot_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=7704;

ALTER TABLE `timeslot_service`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=5;

ALTER TABLE `tooth`
  MODIFY `tooth_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=33;

ALTER TABLE `tooth_status_type`
  MODIFY `status_type_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=15;

ALTER TABLE `tooth_status`
  MODIFY `tooth_status_id` int(11) NOT NULL AUTO_INCREMENT;

ALTER TABLE `appointments`
  MODIFY `appointment_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=33;

ALTER TABLE `appointment_reschedule_requests`
  MODIFY `request_id` int(11) NOT NULL AUTO_INCREMENT;

ALTER TABLE `appointment_media`
  MODIFY `media_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=1;

ALTER TABLE `appointment_doctor_notes`
  MODIFY `note_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=1;

ALTER TABLE `selected_services`
  MODIFY `selected_service_id` int(11) NOT NULL AUTO_INCREMENT;

ALTER TABLE `patient_schedules`
  MODIFY `schedule_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=22;

ALTER TABLE `schedule_reminders`
  MODIFY `reminder_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=8;

ALTER TABLE `clinical_records`
  MODIFY `record_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=1;

ALTER TABLE `clinical_record_images`
  MODIFY `image_id` int(11) NOT NULL AUTO_INCREMENT;

ALTER TABLE `clinical_record_services`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

ALTER TABLE `treatment_plans`
  MODIFY `treatment_id` int(11) NOT NULL AUTO_INCREMENT;

ALTER TABLE `treatment_materials`
  MODIFY `material_id` int(11) NOT NULL AUTO_INCREMENT;

ALTER TABLE `prescriptions`
  MODIFY `prescription_id` int(11) NOT NULL AUTO_INCREMENT;

ALTER TABLE `billing`
  MODIFY `billing_id` int(11) NOT NULL AUTO_INCREMENT;

ALTER TABLE `billing_line_items`
  MODIFY `item_id` int(11) NOT NULL AUTO_INCREMENT;

ALTER TABLE `billing_installments`
  MODIFY `installment_id` int(11) NOT NULL AUTO_INCREMENT;

ALTER TABLE `billing_installment_terms`
  MODIFY `term_id` int(11) NOT NULL AUTO_INCREMENT;

ALTER TABLE `payments`
  MODIFY `payment_id` int(11) NOT NULL AUTO_INCREMENT;

ALTER TABLE `invoices`
  MODIFY `invoice_id` int(11) NOT NULL AUTO_INCREMENT;

ALTER TABLE `notifications`
  MODIFY `notification_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=4;

ALTER TABLE `patient_notifications`
  MODIFY `patient_notification_id` int(11) NOT NULL AUTO_INCREMENT;

ALTER TABLE `contact_messages`
  MODIFY `message_id` int(11) NOT NULL AUTO_INCREMENT;

ALTER TABLE `system_logs`
  MODIFY `system_log_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=39;

ALTER TABLE `backup`
  MODIFY `backup_id` int(11) NOT NULL AUTO_INCREMENT;

ALTER TABLE `appointments`
  ADD CONSTRAINT `appointments_ibfk_1` FOREIGN KEY (`timeslot_id`) REFERENCES `timeslot` (`timeslot_id`) ON DELETE SET NULL,
  ADD CONSTRAINT `fk_appointments_booking` FOREIGN KEY (`booking_id`) REFERENCES `bookings` (`booking_id`) ON DELETE SET NULL,
  ADD CONSTRAINT `fk_appointments_patient` FOREIGN KEY (`patient_id`) REFERENCES `patients` (`patient_id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_appointments_rescheduled_from` FOREIGN KEY (`rescheduled_from`) REFERENCES `appointments` (`appointment_id`) ON DELETE SET NULL,
  ADD CONSTRAINT `fk_appointments_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`user_id`) ON DELETE SET NULL;

ALTER TABLE `appointment_media`
  ADD CONSTRAINT `appointment_media_ibfk_1` FOREIGN KEY (`appointment_id`) REFERENCES `appointments` (`appointment_id`) ON DELETE CASCADE;

ALTER TABLE `timeslot`
  ADD CONSTRAINT `fk_timeslot_appointment` FOREIGN KEY (`appointment_id`) REFERENCES `appointments` (`appointment_id`) ON DELETE SET NULL;

ALTER TABLE `appointment_doctor_notes`
  ADD CONSTRAINT `appointment_doctor_notes_ibfk_1` FOREIGN KEY (`appointment_id`) REFERENCES `appointments` (`appointment_id`) ON DELETE CASCADE;

ALTER TABLE `backup`
  ADD CONSTRAINT `fk_backup_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`user_id`) ON DELETE SET NULL;

ALTER TABLE `billing`
  ADD CONSTRAINT `fk_billing_appointment` FOREIGN KEY (`appointment_id`) REFERENCES `appointments` (`appointment_id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_billing_processed_by` FOREIGN KEY (`processed_by`) REFERENCES `users` (`user_id`) ON DELETE SET NULL ON UPDATE CASCADE,
  ADD CONSTRAINT `fk_billing_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`user_id`) ON DELETE SET NULL;

ALTER TABLE `clinical_records`
  ADD CONSTRAINT `fk_clinrec_appointment` FOREIGN KEY (`appointment_id`) REFERENCES `appointments` (`appointment_id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_clinrec_doctor` FOREIGN KEY (`doctor_id`) REFERENCES `users` (`user_id`) ON DELETE SET NULL,
  ADD CONSTRAINT `fk_clinrec_patient` FOREIGN KEY (`patient_id`) REFERENCES `patients` (`patient_id`) ON DELETE SET NULL,
  ADD CONSTRAINT `fk_clinrec_submitted_by` FOREIGN KEY (`submitted_by`) REFERENCES `users` (`user_id`) ON DELETE SET NULL;

ALTER TABLE `clinical_record_images`
  ADD CONSTRAINT `fk_cri_record` FOREIGN KEY (`record_id`) REFERENCES `clinical_records` (`record_id`) ON DELETE CASCADE;

ALTER TABLE `clinical_record_services`
  ADD CONSTRAINT `fk_crs_record` FOREIGN KEY (`record_id`) REFERENCES `clinical_records` (`record_id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_crs_service` FOREIGN KEY (`service_id`) REFERENCES `services` (`service_id`);

ALTER TABLE `treatment_plans`
  ADD CONSTRAINT `fk_tp_record` FOREIGN KEY (`record_id`) REFERENCES `clinical_records` (`record_id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_tp_service` FOREIGN KEY (`service_id`) REFERENCES `services` (`service_id`) ON DELETE SET NULL;

ALTER TABLE `treatment_materials`
  ADD CONSTRAINT `fk_tm_record` FOREIGN KEY (`record_id`) REFERENCES `clinical_records` (`record_id`) ON DELETE CASCADE;

ALTER TABLE `dentist_specialization`
  ADD CONSTRAINT `fk_dentist_specialization_specialization` FOREIGN KEY (`specialization_id`) REFERENCES `specialization` (`specialization_id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_dentist_specialization_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`user_id`) ON DELETE CASCADE;

ALTER TABLE `doctor_availability`
  ADD CONSTRAINT `fk_avail_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`user_id`) ON DELETE CASCADE;

ALTER TABLE `doctor_leaves`
  ADD CONSTRAINT `fk_doctor_leaves_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`user_id`) ON DELETE CASCADE;

ALTER TABLE `notifications`
  ADD CONSTRAINT `fk_notifications_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`user_id`) ON DELETE CASCADE;

ALTER TABLE `patient_profiling`
  ADD CONSTRAINT `fk_pp_patient` FOREIGN KEY (`patient_id`) REFERENCES `patients` (`patient_id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_pp_record` FOREIGN KEY (`record_id`) REFERENCES `clinical_records` (`record_id`) ON DELETE SET NULL;

ALTER TABLE `patients`
  ADD CONSTRAINT `fk_patients_added_by` FOREIGN KEY (`added_by`) REFERENCES `users` (`user_id`) ON DELETE SET NULL;

ALTER TABLE `patient_logs`
  ADD CONSTRAINT `fk_patient_logs_patient` FOREIGN KEY (`patient_id`) REFERENCES `patients` (`patient_id`) ON DELETE CASCADE;

ALTER TABLE `patient_schedules`
  ADD CONSTRAINT `fk_patient_schedules_appointment` FOREIGN KEY (`appointment_id`) REFERENCES `appointments` (`appointment_id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_patient_schedules_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`user_id`) ON DELETE SET NULL;

ALTER TABLE `prescriptions`
  ADD CONSTRAINT `fk_prescriptions_appointment` FOREIGN KEY (`appointment_id`) REFERENCES `appointments` (`appointment_id`) ON DELETE SET NULL;

ALTER TABLE `schedule_reminders`
  ADD CONSTRAINT `fk_schedule_reminder` FOREIGN KEY (`patient_schedule_id`) REFERENCES `patient_schedules` (`schedule_id`) ON DELETE CASCADE;

ALTER TABLE `selected_services`
  ADD CONSTRAINT `fk_selected_services_appointment` FOREIGN KEY (`appointment_id`) REFERENCES `appointments` (`appointment_id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_selected_services_service` FOREIGN KEY (`service_id`) REFERENCES `services` (`service_id`);

ALTER TABLE `services`
  ADD CONSTRAINT `fk_services_added_by` FOREIGN KEY (`added_by`) REFERENCES `users` (`user_id`) ON DELETE SET NULL;

ALTER TABLE `system_logs`
  ADD CONSTRAINT `fk_system_logs_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`user_id`) ON DELETE SET NULL;

ALTER TABLE `timeslot`
  ADD CONSTRAINT `timeslot_ibfk_1` FOREIGN KEY (`doctor_id`) REFERENCES `users` (`user_id`);

ALTER TABLE `timeslot_service`
  ADD CONSTRAINT `timeslot_service_ibfk_1` FOREIGN KEY (`service_id`) REFERENCES `services` (`service_id`);

ALTER TABLE `tooth_status`
  ADD CONSTRAINT `fk_tooth_status_tooth` FOREIGN KEY (`tooth_selected`) REFERENCES `tooth` (`tooth_id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_tooth_status_type` FOREIGN KEY (`status_type_id`) REFERENCES `tooth_status_type` (`status_type_id`) ON DELETE SET NULL,
  ADD CONSTRAINT `fk_tooth_status_record` FOREIGN KEY (`record_id`) REFERENCES `clinical_records` (`record_id`) ON DELETE CASCADE;

ALTER TABLE `users`
  ADD CONSTRAINT `fk_users_added_by` FOREIGN KEY (`added_by`) REFERENCES `users` (`user_id`) ON DELETE SET NULL,
  ADD CONSTRAINT `fk_users_role` FOREIGN KEY (`role_id`) REFERENCES `role` (`role_id`) ON DELETE SET NULL;

ALTER TABLE `user_logs`
  ADD CONSTRAINT `fk_user_logs_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`user_id`) ON DELETE CASCADE;

CREATE TABLE `clinic_settings` (
  `setting_key` varchar(100) NOT NULL,
  `setting_value` text DEFAULT NULL,
  `description` varchar(255) DEFAULT NULL,
  `updated_at` datetime DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`setting_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

INSERT INTO `clinic_settings` (`setting_key`, `setting_value`, `description`) VALUES
('consultation_fee', '500.00', 'Default consultation fee for consultation-only appointments');

COMMIT;
