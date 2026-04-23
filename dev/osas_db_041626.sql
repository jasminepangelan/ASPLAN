-- phpMyAdmin SQL Dump
-- version 5.2.1
-- https://www.phpmyadmin.net/
--
-- Host: 127.0.0.1
-- Generation Time: Apr 16, 2026 at 04:13 AM
-- Server version: 10.4.32-MariaDB
-- PHP Version: 8.1.25

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
START TRANSACTION;
SET time_zone = "+00:00";


/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;

--
-- Database: `osas_db`
--

-- --------------------------------------------------------

--
-- Table structure for table `accounts`
--

CREATE TABLE `accounts` (
  `account_id` int(11) NOT NULL,
  `student_number` int(11) DEFAULT NULL,
  `username` varchar(255) DEFAULT NULL,
  `password_hash` varchar(255) DEFAULT NULL,
  `role` varchar(255) DEFAULT NULL,
  `program_scope` varchar(255) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `admin`
--

CREATE TABLE `admin` (
  `last_name` varchar(255) DEFAULT NULL,
  `first_name` varchar(255) DEFAULT NULL,
  `middle_name` varchar(255) DEFAULT NULL,
  `username` varchar(255) NOT NULL,
  `password` varchar(255) NOT NULL,
  `prefix` varchar(255) DEFAULT NULL,
  `suffix` varchar(255) DEFAULT NULL,
  `admin_email` varchar(255) DEFAULT NULL,
  `can_manage_job` tinyint(1) DEFAULT NULL,
  `can_manage_user` tinyint(1) DEFAULT NULL,
  `can_manage_announcement` tinyint(1) DEFAULT NULL,
  `can_view_reports` tinyint(1) DEFAULT NULL,
  `admin_id` int(11) DEFAULT NULL,
  `department` varchar(255) DEFAULT NULL,
  `position` varchar(255) DEFAULT NULL,
  `office_location` varchar(255) DEFAULT NULL,
  `internal_phone` varchar(255) DEFAULT NULL,
  `admin_level` enum('super_admin','admin','moderator') DEFAULT NULL,
  `can_manage_users` tinyint(1) DEFAULT NULL,
  `can_manage_jobs` tinyint(1) DEFAULT NULL,
  `can_manage_announcements` tinyint(1) DEFAULT NULL,
  `last_activity` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `admin`
--

INSERT INTO `admin` (`last_name`, `first_name`, `middle_name`, `username`, `password`, `prefix`, `suffix`, `admin_email`, `can_manage_job`, `can_manage_user`, `can_manage_announcement`, `can_view_reports`, `admin_id`, `department`, `position`, `office_location`, `internal_phone`, `admin_level`, `can_manage_users`, `can_manage_jobs`, `can_manage_announcements`, `last_activity`) VALUES
('Administrator', 'System', NULL, 'admin', '$2y$10$CobAxJoXOzW8788svnRqUu8wVjKqnlVVH4i/2PoiaVIt3g1Me92qy', NULL, NULL, 'admin@cvsu.edu.ph', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 'super_admin', NULL, NULL, NULL, NULL),
('Admin', 'Local', NULL, 'admin01', '$2y$10$0v.kRRBVvpZAk0RbpVt9v.yRf30MSxuUgeCM3ehMTnuEvYiJyBOW2', NULL, NULL, NULL, 1, 1, 1, 1, NULL, NULL, NULL, NULL, NULL, 'admin', 1, 1, 1, NULL);

-- --------------------------------------------------------

--
-- Table structure for table `admin_active_sessions`
--

CREATE TABLE `admin_active_sessions` (
  `admin_username` varchar(255) NOT NULL,
  `session_id` varchar(255) NOT NULL,
  `user_ip` varchar(64) DEFAULT NULL,
  `user_agent` varchar(255) DEFAULT NULL,
  `last_seen_at` datetime NOT NULL DEFAULT current_timestamp(),
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `admin_active_sessions`
--

INSERT INTO `admin_active_sessions` (`admin_username`, `session_id`, `user_ip`, `user_agent`, `last_seen_at`, `created_at`, `updated_at`) VALUES
('admin01', 'bs4hv9pe83acio0lt4cl4gasts', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/147.0.0.0 Safari/537.36', '2026-04-16 09:42:02', '2026-04-16 09:35:54', '2026-04-16 09:42:02');

-- --------------------------------------------------------

--
-- Table structure for table `admin_audit_logs`
--

CREATE TABLE `admin_audit_logs` (
  `id` int(11) NOT NULL,
  `admin_id` varchar(255) NOT NULL,
  `action_type` varchar(120) NOT NULL,
  `action_target` varchar(120) NOT NULL,
  `summary` varchar(500) NOT NULL,
  `metadata_json` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `admin_audit_logs`
--

INSERT INTO `admin_audit_logs` (`id`, `admin_id`, `action_type`, `action_target`, `summary`, `metadata_json`, `created_at`) VALUES
(1, 'admin', 'settings_update', 'policy_settings', 'Updated security and rate-limit policy settings.', '{\"changed_settings\":[\"session_timeout_seconds\",\"min_password_length\",\"password_reset_expiry_seconds\",\"rate_limit_login_max_attempts\",\"rate_limit_login_window_seconds\",\"rate_limit_forgot_password_max_attempts\",\"rate_limit_forgot_password_window_seconds\"]}', '2026-03-26 05:33:38'),
(2, 'admin', 'settings_update', 'policy_settings', 'Updated security and rate-limit policy settings.', '{\"changed_settings\":[\"session_timeout_seconds\"]}', '2026-03-26 07:14:12'),
(3, 'admin', 'settings_update', 'policy_settings', 'Updated security and rate-limit policy settings.', '{\"changed_settings\":[\"session_timeout_seconds\"]}', '2026-03-27 08:20:28'),
(4, 'admin01', 'account_security_update', 'admin_account', 'Updated admin account credentials.', '{\"changed_fields\":[\"username\",\"password\"]}', '2026-04-16 01:35:46'),
(5, 'admin01', 'masterlist_upload', 'Bachelor of Science in Computer Science', 'Uploaded official student masterlist for program.', '{\"program\":\"Bachelor of Science in Computer Science\",\"row_count\":28,\"source_filename\":\"student_masterlist_template.csv\"}', '2026-04-16 01:36:33'),
(6, 'admin01', 'masterlist_upload', 'Bachelor of Science in Computer Science', 'Uploaded official student masterlist for program.', '{\"program\":\"Bachelor of Science in Computer Science\",\"row_count\":28,\"source_filename\":\"student_masterlist_template.csv\"}', '2026-04-16 01:39:57');

-- --------------------------------------------------------

--
-- Table structure for table `admin_two_factor_auth`
--

CREATE TABLE `admin_two_factor_auth` (
  `admin_username` varchar(255) NOT NULL,
  `secret_encrypted` text NOT NULL,
  `enabled_at` datetime NOT NULL DEFAULT current_timestamp(),
  `last_verified_at` datetime DEFAULT NULL,
  `last_verified_time_slice` bigint(20) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `adviser`
--

CREATE TABLE `adviser` (
  `last_name` varchar(255) DEFAULT NULL,
  `first_name` varchar(255) DEFAULT NULL,
  `middle_name` varchar(255) DEFAULT NULL,
  `username` varchar(255) NOT NULL,
  `password` varchar(255) DEFAULT NULL,
  `prefix` varchar(255) DEFAULT NULL,
  `suffix` varchar(255) DEFAULT NULL,
  `program` varchar(255) DEFAULT NULL,
  `id` int(11) DEFAULT NULL,
  `adviser_email` varchar(255) DEFAULT NULL,
  `sex` enum('Male','Female') DEFAULT NULL,
  `pronoun` enum('Mr.','Ms.','Mrs.') DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `adviser`
--

INSERT INTO `adviser` (`last_name`, `first_name`, `middle_name`, `username`, `password`, `prefix`, `suffix`, `program`, `id`, `adviser_email`, `sex`, `pronoun`) VALUES
('Bayan', 'Robert', '', 'bayan', '$2y$10$2SbX8Yvjn0Pt9ZpBR89y5ugUdtPS4jbcGd0GpfgEVeqeXbRUhZRtO', NULL, NULL, 'Bachelor of Science in Computer Engineering', 8, NULL, 'Male', 'Mr.'),
('Supan', 'Daryl Lyndon', '', 'daryl', '$2y$10$nrUX2ANVf0Vv6fScmLJwX.3fmSnue38Xv7vNfvX.X9TkuMCX4JSJG', NULL, NULL, 'Bachelor of Science in Information Technology', 7, NULL, 'Male', 'Mr.'),
('Hugo', 'Alonel', NULL, 'Hugo', '$2y$10$hiUpmoVs4YL6JG7HO9vE5eaOrdvDISYnfm.xzgtm2cVnY5HrKlaui', NULL, NULL, 'Bachelor of Science in Computer Science', 2, NULL, 'Male', 'Mr.'),
('Gabriel E. Marmeto', 'Ian', NULL, 'ian', '$2y$10$hiUpmoVs4YL6JG7HO9vE5eaOrdvDISYnfm.xzgtm2cVnY5HrKlaui', NULL, NULL, 'Bachelor of Science in Computer Science', 6, NULL, 'Male', 'Mr.'),
('Moneva', 'Leah', NULL, 'leah', '$2y$10$4DjR3324xoEzMcTBBMGop.jZvfHuaCgJL5./DU3XRvGKdc9znOKvK', NULL, NULL, 'Bachelor of Science in Business Administration - Major in Human Resource Management', 9, NULL, 'Female', 'Mrs.'),
('Rizaldo Alingod', 'Jose', NULL, 'Nortz', '$2y$10$hiUpmoVs4YL6JG7HO9vE5eaOrdvDISYnfm.xzgtm2cVnY5HrKlaui', NULL, NULL, 'Bachelor of Science in Computer Science', 4, NULL, 'Male', 'Mr.'),
('Marlou Opella', 'Joe', NULL, 'Sir Ops', '$2y$10$hiUpmoVs4YL6JG7HO9vE5eaOrdvDISYnfm.xzgtm2cVnY5HrKlaui', NULL, NULL, 'Bachelor of Science in Computer Science', 5, NULL, 'Male', 'Mr.');

-- --------------------------------------------------------

--
-- Table structure for table `adviser_batch`
--

CREATE TABLE `adviser_batch` (
  `id` int(11) NOT NULL,
  `adviser_id` int(11) NOT NULL,
  `batch` varchar(10) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `adviser_batch`
--

INSERT INTO `adviser_batch` (`id`, `adviser_id`, `batch`) VALUES
(40, 2, '2201'),
(43, 2, '2301'),
(21, 5, '2001'),
(42, 5, '2201'),
(45, 5, '2301'),
(41, 6, '2201'),
(44, 6, '2301'),
(25, 7, '2201'),
(24, 7, '2301'),
(48, 9, '2001'),
(49, 9, '2301');

-- --------------------------------------------------------

--
-- Table structure for table `announcements`
--

CREATE TABLE `announcements` (
  `announcement_id` int(11) NOT NULL,
  `title` varchar(255) DEFAULT NULL,
  `content` text DEFAULT NULL,
  `announcement_type` enum('general','job','event','scholarship','urgent') DEFAULT NULL,
  `target_audience` enum('all','students','graduates','employers') DEFAULT NULL,
  `course_filter` varchar(255) DEFAULT NULL,
  `start_date` datetime DEFAULT NULL,
  `end_date` datetime DEFAULT NULL,
  `location` varchar(255) DEFAULT NULL,
  `attachment_url` varchar(255) DEFAULT NULL,
  `is_published` tinyint(1) DEFAULT NULL,
  `created_by` int(11) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `views_count` int(11) DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `batches`
--

CREATE TABLE `batches` (
  `id` int(11) NOT NULL,
  `batches` varchar(255) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `certificate`
--

CREATE TABLE `certificate` (
  `cert_id` int(11) NOT NULL,
  `student_number` int(11) DEFAULT NULL,
  `cert_type` varchar(255) DEFAULT NULL,
  `req_date` date DEFAULT NULL,
  `status` varchar(255) DEFAULT NULL,
  `issued_date` date DEFAULT NULL,
  `remarks` varchar(255) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `comments`
--

CREATE TABLE `comments` (
  `comment_id` int(11) NOT NULL,
  `post_id` int(11) DEFAULT NULL,
  `account_id` int(11) DEFAULT NULL,
  `content` text DEFAULT NULL,
  `timestamp` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `counseling_req`
--

CREATE TABLE `counseling_req` (
  `counseling_req_id` int(11) NOT NULL,
  `student_number` int(11) DEFAULT NULL,
  `req_date` date DEFAULT NULL,
  `status` varchar(255) DEFAULT NULL,
  `counselor_assigned` varchar(255) DEFAULT NULL,
  `session_date` date DEFAULT NULL,
  `remarks` varchar(255) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `curriculum_courses`
--

CREATE TABLE `curriculum_courses` (
  `id` int(11) NOT NULL,
  `curriculum_year` int(4) NOT NULL,
  `program` varchar(255) NOT NULL,
  `year_level` varchar(50) NOT NULL,
  `semester` varchar(50) NOT NULL,
  `course_code` varchar(20) NOT NULL,
  `course_title` varchar(255) NOT NULL,
  `credit_units_lec` int(2) DEFAULT 0,
  `credit_units_lab` int(2) DEFAULT 0,
  `lect_hrs_lec` int(2) DEFAULT 0,
  `lect_hrs_lab` int(2) DEFAULT 0,
  `pre_requisite` varchar(255) DEFAULT 'NONE'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `curriculum_courses`
--

INSERT INTO `curriculum_courses` (`id`, `curriculum_year`, `program`, `year_level`, `semester`, `course_code`, `course_title`, `credit_units_lec`, `credit_units_lab`, `lect_hrs_lec`, `lect_hrs_lab`, `pre_requisite`) VALUES
(1, 2018, 'Bachelor of Science in Industrial Technology', 'First Year', 'First Semester', 'GNED 03', 'Mathematics in the Modern World', 3, 0, 3, 0, 'NONE'),
(2, 2018, 'Bachelor of Science in Industrial Technology', 'First Year', 'First Semester', 'GNED 05', 'Purposive Communication', 3, 0, 3, 0, 'NONE'),
(3, 2018, 'Bachelor of Science in Industrial Technology', 'First Year', 'First Semester', 'GNED 11', 'Kontextwalisadong Komunikasyon sa Filipino', 3, 0, 3, 0, 'NONE'),
(4, 2018, 'Bachelor of Science in Industrial Technology', 'First Year', 'First Semester', 'ELEX 50', 'Electronics Devices, Instruments and Circuit', 2, 3, 2, 0, 'NONE'),
(5, 2018, 'Bachelor of Science in Industrial Technology', 'First Year', 'First Semester', 'ELEX 55', 'Electronics Design and Fabrication', 2, 1, 2, 3, 'NONE'),
(6, 2018, 'Bachelor of Science in Industrial Technology', 'First Year', 'First Semester', 'DRAW 24', 'Technology Drawing 1 (CADD 2D)', 0, 1, 0, 3, 'NONE'),
(7, 2018, 'Bachelor of Science in Industrial Technology', 'First Year', 'First Semester', 'INDT 21', 'Basic Occupational Health and Safety', 2, 0, 2, 0, 'NONE'),
(8, 2018, 'Bachelor of Science in Industrial Technology', 'First Year', 'First Semester', 'FITT 1', 'Movement Enhancement', 2, 0, 2, 0, 'NONE'),
(9, 2018, 'Bachelor of Science in Industrial Technology', 'First Year', 'First Semester', 'NSTP 1', 'National Service Training Program I', 3, 0, 3, 0, 'NONE'),
(10, 2018, 'Bachelor of Science in Industrial Technology', 'First Year', 'First Semester', 'CVSU 101', 'Institutional Orientation', 1, 0, 1, 0, 'NONE'),
(11, 2018, 'Bachelor of Science in Industrial Technology', 'First Year', 'Second Semester', 'ELEX 60', 'Electronics Communication 1', 2, 3, 2, 9, 'ELEX 50, 55'),
(12, 2018, 'Bachelor of Science in Industrial Technology', 'First Year', 'Second Semester', 'ELEX 65', 'Semiconductor Devices', 2, 1, 2, 3, 'ELEX 50, 55'),
(13, 2018, 'Bachelor of Science in Industrial Technology', 'First Year', 'Second Semester', 'DRAW 25', 'Industrial Technology Drawing II (CADD 3D)', 0, 1, 0, 3, 'DRAW 24'),
(14, 2018, 'Bachelor of Science in Industrial Technology', 'First Year', 'Second Semester', 'GNED 06', 'Science, Technology and Society', 3, 0, 3, 0, ' NONE'),
(15, 2018, 'Bachelor of Science in Industrial Technology', 'First Year', 'Second Semester', 'GNED 12', 'Dalumat ng/sa Filipino', 3, 0, 3, 0, 'GNED 10'),
(16, 2018, 'Bachelor of Science in Industrial Technology', 'First Year', 'Second Semester', 'CpEN 21', 'Programming Logic and Design', 0, 2, 0, 6, 'NONE'),
(17, 2018, 'Bachelor of Science in Industrial Technology', 'First Year', 'Second Semester', 'INDT 22', 'Digital Electronics', 1, 1, 1, 3, 'NONE'),
(18, 2018, 'Bachelor of Science in Industrial Technology', 'First Year', 'Second Semester', 'FITT 2', 'Fitness Exercises', 2, 0, 2, 0, 'FITT 1'),
(19, 2018, 'Bachelor of Science in Industrial Technology', 'First Year', 'Second Semester', 'NSTP 2', 'National Service Training Program 2', 3, 0, 3, 0, 'NSTP 1'),
(20, 2018, 'Bachelor of Science in Industrial Technology', 'First Year', 'Mid Year', 'ELEX 199a', 'Supervised Industrial Training 1', 0, 3, 0, 240, 'ELEX 50, 55, 60, 65'),
(21, 2018, 'Bachelor of Science in Industrial Technology', 'Second Year', 'First Semester', 'ELEX 70', 'Electronics Communication 2', 2, 3, 2, 9, 'ELEX 60, 65'),
(22, 2018, 'Bachelor of Science in Industrial Technology', 'Second Year', 'First Semester', 'ELEX 75', 'Advanced Electronics', 2, 1, 2, 3, 'INDT 22, ELEX 65'),
(23, 2018, 'Bachelor of Science in Industrial Technology', 'Second Year', 'First Semester', 'ENGS 32', 'Technopreneurship 101', 3, 0, 3, 0, 'NONE'),
(24, 2018, 'Bachelor of Science in Industrial Technology', 'Second Year', 'First Semester', 'GNED 04', 'Mga Babasahin Hinggil sa Kasaysayan', 3, 0, 3, 0, 'NONE'),
(25, 2018, 'Bachelor of Science in Industrial Technology', 'Second Year', 'First Semester', 'GNED 08', 'Understanding the Self', 3, 0, 3, 0, 'NONE'),
(26, 2018, 'Bachelor of Science in Industrial Technology', 'Second Year', 'First Semester', 'GNED 10', 'Gender and Society', 3, 0, 3, 0, 'NONE'),
(27, 2018, 'Bachelor of Science in Industrial Technology', 'Second Year', 'First Semester', 'MATH 11', 'Differential and Integral Calculus', 3, 0, 3, 0, 'NONE'),
(28, 2018, 'Bachelor of Science in Industrial Technology', 'Second Year', 'First Semester', 'FITT 3', 'Physical Activities Towards Health and Fitness I', 2, 0, 2, 0, 'FITT 1'),
(29, 2018, 'Bachelor of Science in Industrial Technology', 'Second Year', 'Second Semester', 'ELEX 80', 'Instrumentation and Process Control', 2, 3, 2, 9, 'ELEX 70, 75'),
(30, 2018, 'Bachelor of Science in Industrial Technology', 'Second Year', 'Second Semester', 'ELEX 85', 'Sensor and Interfacing', 2, 1, 2, 3, 'ELEX 70, 75'),
(31, 2018, 'Bachelor of Science in Industrial Technology', 'Second Year', 'Second Semester', 'INDT 23', 'Programmable Controls', 1, 1, 1, 3, 'INDT 22'),
(32, 2018, 'Bachelor of Science in Industrial Technology', 'Second Year', 'Second Semester', 'ENOS 24b', 'Mechanics of Deformable Bodies', 3, 0, 3, 0, 'NONE'),
(33, 2018, 'Bachelor of Science in Industrial Technology', 'Second Year', 'Second Semester', 'GNED 01', 'Art Appreciation', 3, 0, 3, 0, 'NONE'),
(34, 2018, 'Bachelor of Science in Industrial Technology', 'Second Year', 'Second Semester', 'GNED 02', 'Ethics', 3, 0, 3, 0, 'NONE'),
(35, 2018, 'Bachelor of Science in Industrial Technology', 'Second Year', 'Second Semester', 'GNED 07', 'The Contemporary World', 3, 0, 3, 0, 'NONE'),
(36, 2018, 'Bachelor of Science in Industrial Technology', 'Second Year', 'Second Semester', 'GNED 14', 'Panitikan Filipino', 3, 0, 3, 0, 'GNED 11'),
(37, 2018, 'Bachelor of Science in Industrial Technology', 'Second Year', 'Second Semester', 'FITT 4', 'Physical Activities Towards Health and Fitness II', 2, 0, 2, 0, 'FITT 1'),
(38, 2018, 'Bachelor of Science in Industrial Technology', 'Third Year', 'First Semester', 'ELEX 90', 'Microprocessor and Interfacing', 2, 3, 2, 9, 'ELEX 80, 85'),
(39, 2018, 'Bachelor of Science in Industrial Technology', 'Third Year', 'First Semester', 'ELEX 95', 'Industrial Electronics', 2, 1, 2, 3, 'ELEX 80, 85'),
(40, 2018, 'Bachelor of Science in Industrial Technology', 'Third Year', 'First Semester', 'ELEX 200a', 'ELEX Design Project 1', 0, 1, 0, 3, 'NONE'),
(41, 2018, 'Bachelor of Science in Industrial Technology', 'Third Year', 'First Semester', 'ALAN 21', 'Foreign Language', 3, 0, 3, 0, 'NONE'),
(42, 2018, 'Bachelor of Science in Industrial Technology', 'Third Year', 'First Semester', 'ENGS 33', 'Environmental Science', 3, 0, 3, 0, 'NONE'),
(43, 2018, 'Bachelor of Science in Industrial Technology', 'Third Year', 'First Semester', 'INDT 24', 'Pneumatics and Hydraulics (P&H)', 1, 1, 1, 3, 'INDT 23'),
(44, 2018, 'Bachelor of Science in Industrial Technology', 'Third Year', 'First Semester', 'INDT 25', 'Intellectual Property Rights', 3, 0, 3, 0, 'NONE'),
(45, 2018, 'Bachelor of Science in Industrial Technology', 'Third Year', 'First Semester', 'INDT 26', 'Human Resources Management for Technology', 3, 0, 3, 0, 'NONE'),
(46, 2018, 'Bachelor of Science in Industrial Technology', 'Third Year', 'First Semester', 'INDT 27', 'Materials and Business Technology Management', 3, 0, 3, 0, 'NONE'),
(47, 2018, 'Bachelor of Science in Industrial Technology', 'Third Year', 'Second Semester', 'ELEX 100', 'Programmable Controller Application', 2, 3, 2, 9, 'ELEX 90, 95'),
(48, 2018, 'Bachelor of Science in Industrial Technology', 'Third Year', 'Second Semester', 'ELEX 105', 'Industrial Robotics', 2, 1, 2, 3, 'ELEX 90, 95'),
(49, 2018, 'Bachelor of Science in Industrial Technology', 'Third Year', 'Second Semester', 'ELEX 200b', 'ELEX Design Project 2', 0, 1, 0, 3, 'ELEX 200a'),
(50, 2018, 'Bachelor of Science in Industrial Technology', 'Third Year', 'Second Semester', 'ENGS 29', 'Applied Thermodynamics', 1, 1, 1, 3, 'INDT 24'),
(51, 2018, 'Bachelor of Science in Industrial Technology', 'Second Year', 'Second Semester', 'GNED 09', 'Life and Works of Rizal', 3, 0, 3, 0, 'GNED 04'),
(52, 2018, 'Bachelor of Science in Industrial Technology', 'Third Year', 'Second Semester', 'INDT 28', 'Industrial Organization and Management Practice', 3, 0, 3, 0, 'INDT 26'),
(53, 2018, 'Bachelor of Science in Industrial Technology', 'Third Year', 'Second Semester', 'INDT 29', 'Quality Control', 3, 0, 3, 0, 'INDT 27'),
(54, 2018, 'Bachelor of Science in Industrial Technology', 'Third Year', 'Second Semester', 'INDT 30', 'Production Technology Management', 3, 0, 3, 0, 'INDT 27'),
(55, 2018, 'Bachelor of Science in Industrial Technology', 'Fourth Year', 'First Semester', 'ELEX 199b', 'Supervised Industrial Training 2', 0, 8, 0, 640, 'All Major Subjects ELEX 199a'),
(56, 2018, 'Bachelor of Science in Industrial Technology', 'Fourth Year', 'Second Semester', 'ELEX 199c', 'Supervised Industrial Training 3', 0, 8, 0, 640, 'ELEX 199b'),
(57, 2018, 'Bachelor of Science in Computer Engineering', 'First Year', 'First Semester', 'CHEM 14', 'Chemistry for Engineers', 3, 1, 3, 3, 'NONE'),
(58, 2018, 'Bachelor of Science in Computer Engineering', 'First Year', 'First Semester', 'CPEN 21', 'Programming Logic and Design', 0, 2, 0, 0, 'NONE'),
(59, 2018, 'Bachelor of Science in Computer Engineering', 'First Year', 'First Semester', 'CPEN 50', 'Computer Engineering as Discipline', 1, 0, 1, 0, 'NONE'),
(60, 2018, 'Bachelor of Science in Computer Engineering', 'First Year', 'First Semester', 'CVSU 101', 'Institutional Orientation', 1, 0, 1, 0, 'NONE'),
(61, 2018, 'Bachelor of Science in Computer Engineering', 'First Year', 'First Semester', 'GNED 03', 'Mathematics in the Modern World', 3, 0, 3, 0, 'NONE'),
(62, 2018, 'Bachelor of Science in Computer Engineering', 'First Year', 'First Semester', 'GNED 08', 'Understanding the Self', 3, 0, 3, 0, 'NONE'),
(63, 2018, 'Bachelor of Science in Computer Engineering', 'First Year', 'First Semester', 'GNED 11', 'Kontekstwalisadong Komunikasyon sa Filipino', 3, 0, 3, 0, 'NONE'),
(64, 2018, 'Bachelor of Science in Computer Engineering', 'First Year', 'First Semester', 'MATH 11', 'Calculus 1', 3, 0, 3, 0, 'NONE'),
(65, 2018, 'Bachelor of Science in Computer Engineering', 'First Year', 'First Semester', 'FITT 1', 'Movement Enhancement', 2, 0, 2, 0, 'NONE'),
(66, 2018, 'Bachelor of Science in Computer Engineering', 'First Year', 'First Semester', 'NSTP 1', 'National Service Training Program I', 3, 0, 3, 0, 'NONE'),
(67, 2018, 'Bachelor of Science in Computer Engineering', 'First Year', 'Second Semester', 'CPEN 60', 'Object Oriented Programming', 0, 2, 0, 6, 'CPEN 21'),
(68, 2018, 'Bachelor of Science in Computer Engineering', 'First Year', 'Second Semester', 'DCEE 21', 'Discrete Mathematics', 3, 0, 3, 0, 'MATH 11'),
(69, 2018, 'Bachelor of Science in Computer Engineering', 'First Year', 'Second Semester', 'GNED 02', 'Ethics', 3, 0, 3, 0, 'NONE'),
(70, 2018, 'Bachelor of Science in Computer Engineering', 'First Year', 'Second Semester', 'GNED 12', 'Dalumat Ng/Sa Filipino', 3, 0, 3, 0, 'NONE'),
(71, 2018, 'Bachelor of Science in Computer Engineering', 'First Year', 'Second Semester', 'MATH 12', 'Calculus 2', 3, 0, 3, 0, 'MATH 11'),
(72, 2018, 'Bachelor of Science in Computer Engineering', 'First Year', 'Second Semester', 'PHYS 14', 'Physics for Engineers', 3, 1, 3, 3, 'CHEM 14'),
(73, 2018, 'Bachelor of Science in Computer Engineering', 'First Year', 'Second Semester', 'FITT 2', 'Fitness Exercises', 2, 0, 2, 0, 'FITT 1'),
(74, 2018, 'Bachelor of Science in Computer Engineering', 'First Year', 'Second Semester', 'NSTP 2', 'National Service Training Program II', 3, 0, 3, 0, 'NSTP 1'),
(75, 2018, 'Bachelor of Science in Computer Engineering', 'Second Year', 'First Semester', 'CPEN 65', 'Data Structures and Algorithms', 0, 2, 0, 6, 'CPEN 60'),
(76, 2018, 'Bachelor of Science in Computer Engineering', 'Second Year', 'First Semester', 'EENG 50', 'Electrical Circuits 1', 3, 1, 3, 3, 'PHYS 14'),
(77, 2018, 'Bachelor of Science in Computer Engineering', 'Second Year', 'First Semester', 'ENGS 31', 'Engineering Economics', 3, 0, 3, 0, '2nd Year Standing'),
(78, 2018, 'Bachelor of Science in Computer Engineering', 'Second Year', 'First Semester', 'GNED 06', 'Science, Technology and Society', 3, 0, 3, 0, 'NONE'),
(79, 2018, 'Bachelor of Science in Computer Engineering', 'Second Year', 'First Semester', 'GNED 10', 'Gender and Society', 3, 0, 3, 0, 'NONE'),
(80, 2018, 'Bachelor of Science in Computer Engineering', 'Second Year', 'First Semester', 'MATH 13', 'Differential Equations', 3, 0, 3, 0, 'MATH 12'),
(81, 2018, 'Bachelor of Science in Computer Engineering', 'Second Year', 'First Semester', 'DRAW 23', 'Computer Aided Drafting and Design(CADD)', 0, 1, 0, 3, 'NONE'),
(82, 2018, 'Bachelor of Science in Computer Engineering', 'Second Year', 'First Semester', 'FITT 3', 'Physical Activities Towards Health and Fitness I', 2, 0, 2, 0, 'FITT 2'),
(83, 2018, 'Bachelor of Science in Computer Engineering', 'Second Year', 'Second Semester', 'CPEN 70', 'Fundamentals of Information Systems', 0, 1, 0, 3, 'CPEN 65'),
(84, 2018, 'Bachelor of Science in Computer Engineering', 'Second Year', 'Second Semester', 'DCEE 23', 'Numerical Methods and Analysis', 2, 1, 2, 3, 'MATH 13'),
(85, 2018, 'Bachelor of Science in Computer Engineering', 'Second Year', 'Second Semester', 'ECEN 60', 'Electronics 1 (Electronic Devices and Circuits)', 3, 1, 3, 3, 'EENG 50'),
(86, 2018, 'Bachelor of Science in Computer Engineering', 'Second Year', 'Second Semester', 'GNED 01', 'Art Appreciation', 3, 0, 3, 0, 'NONE'),
(87, 2018, 'Bachelor of Science in Computer Engineering', 'Second Year', 'Second Semester', 'GNED 05', 'Purposive Communication', 3, 0, 3, 0, 'NONE'),
(88, 2018, 'Bachelor of Science in Computer Engineering', 'Second Year', 'Second Semester', 'GNED 07', 'The Contemporary World', 3, 0, 3, 0, 'MATH 12'),
(89, 2018, 'Bachelor of Science in Computer Engineering', 'Second Year', 'Second Semester', 'FITT 4', 'Physical Activities Towards Health and Fitness II', 2, 0, 2, 0, 'FITT 1'),
(90, 2018, 'Bachelor of Science in Computer Engineering', 'Third Year', 'First Semester', 'CPEN 75', 'Logic Circuits and Design', 3, 1, 3, 3, 'ECEN 60'),
(91, 2018, 'Bachelor of Science in Computer Engineering', 'Third Year', 'First Semester', 'CPEN 80', 'Data and Digital Communications', 3, 0, 3, 0, 'ECEN 60'),
(92, 2018, 'Bachelor of Science in Computer Engineering', 'Third Year', 'First Semester', 'CPEN 85', 'Microprocessors of Network Routing and Sensors', 2, 1, 2, 3, 'ECEN 60'),
(93, 2018, 'Bachelor of Science in Computer Engineering', 'Third Year', 'First Semester', 'CPEN 90', 'Fundamentals of Engineering Drafting and Design', 0, 1, 0, 3, 'ECEN 60'),
(94, 2018, 'Bachelor of Science in Computer Engineering', 'Third Year', 'First Semester', 'CPEN 101', 'Elective Course #1', 3, 0, 3, 0, '3rd year standing'),
(95, 2018, 'Bachelor of Science in Computer Engineering', 'Third Year', 'First Semester', 'DCEE 24', 'Feedback and Control Systems', 3, 1, 3, 3, 'ECEN 60,DCEE 23'),
(96, 2018, 'Bachelor of Science in Computer Engineering', 'Third Year', 'First Semester', 'DCEE 25', 'Basic Occupational Health and Safety (BOSH)', 3, 0, 3, 0, '3rd year standing, EENG 50'),
(97, 2018, 'Bachelor of Science in Computer Engineering', 'Third Year', 'First Semester', 'MATH 14', 'Engineering Data Analysis', 3, 0, 3, 0, 'MATH 11'),
(98, 2018, 'Bachelor of Science in Computer Engineering', 'Third Year', 'Second Semester', 'CPEN 95', 'Operating Systems', 2, 1, 2, 3, 'CPEN 65'),
(99, 2018, 'Bachelor of Science in Computer Engineering', 'Third Year', 'Second Semester', 'CPEN 100', 'Microprocessors and Microcontrollers Systems', 3, 1, 3, 3, 'CPEN 75'),
(100, 2018, 'Bachelor of Science in Computer Engineering', 'Third Year', 'Second Semester', 'CPEN 105', 'Computer Networks and Security', 3, 1, 3, 3, 'CPEN 85'),
(101, 2018, 'Bachelor of Science in Computer Engineering', 'Third Year', 'Second Semester', 'CPEN 110', 'CpE Laws and Professional Practice', 2, 0, 2, 0, '3rd year standing'),
(102, 2018, 'Bachelor of Science in Computer Engineering', 'Third Year', 'Second Semester', 'CPEN 115', 'Introduction to HDL', 0, 1, 0, 3, 'CPEN 101,ECEN 60'),
(103, 2018, 'Bachelor of Science in Computer Engineering', 'Third Year', 'Second Semester', 'CPEN 106', 'Elective Course #2', 3, 0, 3, 0, 'CPEN 101'),
(104, 2018, 'Bachelor of Science in Computer Engineering', 'Third Year', 'Second Semester', 'DCEE 26', 'Methods of Research', 2, 0, 2, 0, 'CPEN 75, GNED 05, MATH 14'),
(105, 2018, 'Bachelor of Science in Computer Engineering', 'Third Year', 'Second Semester', 'ENGS 35', 'Technopreneurship 101', 3, 0, 3, 0, '3rd year standing'),
(106, 2018, 'Bachelor of Science in Computer Engineering', 'Third Year', 'Summer', 'CPEN 199', 'On-the-Job Training/CpE Practice (240 Hrs)', 3, 0, 0, 240, '3rd Year Stading'),
(107, 2018, 'Bachelor of Science in Computer Engineering', 'Fourth Year', 'First Semester', 'CPEN 111', 'Elective Course #3', 3, 0, 3, 0, 'CPEN 106'),
(108, 2018, 'Bachelor of Science in Computer Engineering', 'Fourth Year', 'First Semester', 'CPEN 120', 'Embedded Systems', 3, 1, 3, 3, 'CPEN 100'),
(109, 2018, 'Bachelor of Science in Computer Engineering', 'Fourth Year', 'First Semester', 'CPEN 125', 'Computer Architecture and Organization', 3, 1, 3, 3, 'CPEN 100'),
(110, 2018, 'Bachelor of Science in Computer Engineering', 'Fourth Year', 'First Semester', 'CPEN 130', 'Emerging Technologies in CpE', 3, 0, 3, 0, '4th year standing'),
(111, 2018, 'Bachelor of Science in Computer Engineering', 'Fourth Year', 'First Semester', 'CPEN 135', 'Digital Signal Processing', 3, 1, 0, 3, 'DCEE 23'),
(112, 2018, 'Bachelor of Science in Computer Engineering', 'Fourth Year', 'First Semester', 'CPEN 200a', 'CpE Design Project 1', 0, 1, 0, 3, 'CPEN 100, DCEE 25'),
(113, 2018, 'Bachelor of Science in Computer Engineering', 'Fourth Year', 'First Semester', 'GNED 14', 'Mga Babasahin Hinggil Sa Kasaysayan', 3, 0, 3, 0, 'NONE'),
(114, 2018, 'Bachelor of Science in Computer Engineering', 'Fourth Year', 'Second Semester', 'CPEN 140', 'Extreme Project Training', 1, 0, 1, 0, 'Graduating Only'),
(115, 2018, 'Bachelor of Science in Computer Engineering', 'Fourth Year', 'Second Semester', 'CPEN 190', 'Seminars and Fieldtrips', 0, 1, 0, 3, '4th Year Standing'),
(116, 2018, 'Bachelor of Science in Computer Engineering', 'Fourth Year', 'Second Semester', 'CPEN 200b', 'CpE Design Project 2', 0, 2, 0, 6, 'CPEN 200a'),
(117, 2018, 'Bachelor of Science in Computer Engineering', 'Fourth Year', 'Second Semester', 'GNED 09', 'Life and Works of Rizal', 3, 0, 3, 0, 'NONE'),
(118, 2018, 'Bachelor of Science in Computer Engineering', 'Fourth Year', 'Second Semester', 'GNED 11', 'Panitikang Panlipunan', 3, 0, 3, 0, 'GNED 04'),
(119, 2018, 'Bachelor of Science in Information Technology', 'First Year', 'First Semester', 'GNED 02', 'Ethics', 3, 0, 3, 0, 'NONE'),
(120, 2018, 'Bachelor of Science in Information Technology', 'First Year', 'First Semester', 'GNED 05', 'Purposive Communication', 3, 0, 3, 0, 'NONE'),
(121, 2018, 'Bachelor of Science in Information Technology', 'First Year', 'First Semester', 'GNED 11', 'Kontekstwalisadong Komunikasyon', 3, 0, 3, 0, 'NONE'),
(122, 2018, 'Bachelor of Science in Information Technology', 'First Year', 'First Semester', 'COSC 50', 'Discrete Structure', 3, 0, 3, 0, 'NONE'),
(123, 2018, 'Bachelor of Science in Information Technology', 'First Year', 'First Semester', 'DCIT 21', 'Introduction to Computing', 2, 1, 2, 3, 'NONE'),
(124, 2018, 'Bachelor of Science in Information Technology', 'First Year', 'First Semester', 'DCIT 22', 'Computer Programming 1', 1, 2, 1, 6, 'NONE'),
(125, 2018, 'Bachelor of Science in Information Technology', 'First Year', 'First Semester', 'FITT 1', 'Movement Enhancement', 2, 0, 2, 0, 'NONE'),
(126, 2018, 'Bachelor of Science in Information Technology', 'First Year', 'First Semester', 'NSTP 1', 'National Service Training Program 1', 3, 0, 3, 0, 'NONE'),
(127, 2018, 'Bachelor of Science in Information Technology', 'First Year', 'First Semester', 'CVSU 101', 'Institutional Orientation', 1, 0, 1, 0, 'NONE'),
(128, 2018, 'Bachelor of Science in Information Technology', 'First Year', 'Second Semester', 'GNED 01', 'Arts Appreciation', 3, 0, 3, 0, 'NONE'),
(129, 2018, 'Bachelor of Science in Information Technology', 'First Year', 'Second Semester', 'GNED 06', 'Science, Technology, and Society', 3, 0, 3, 0, 'NONE'),
(130, 2018, 'Bachelor of Science in Information Technology', 'First Year', 'Second Semester', 'GNED 14', 'Panitikan Panlipunan', 3, 0, 3, 0, 'GNED 11'),
(131, 2018, 'Bachelor of Science in Information Technology', 'First Year', 'Second Semester', 'GNED 03', 'Mathematics in the Modern World', 3, 0, 3, 0, 'NONE'),
(132, 2018, 'Bachelor of Science in Information Technology', 'First Year', 'Second Semester', 'DCIT 23', 'Computer Programming 2', 1, 2, 1, 6, 'DCIT 22'),
(133, 2018, 'Bachelor of Science in Information Technology', 'First Year', 'Second Semester', 'ITEC 50', 'Web System and Technologies 1', 2, 1, 2, 3, 'DCIT 21'),
(134, 2018, 'Bachelor of Science in Information Technology', 'First Year', 'Second Semester', 'FITT 2', 'Fitness Exercise', 2, 0, 2, 0, 'NONE'),
(135, 2018, 'Bachelor of Science in Information Technology', 'First Year', 'Second Semester', 'NSTP 2', 'National Service Training Program 2', 3, 0, 3, 0, 'NONE'),
(136, 2018, 'Bachelor of Science in Information Technology', 'Second Year', 'First Semester', 'GNED 04', 'Mga Babasahin Hinggil sa Kasaysayan ng Pilipinas', 3, 0, 3, 0, 'NONE'),
(137, 2018, 'Bachelor of Science in Information Technology', 'Second Year', 'First Semester', 'GNED 07', 'The Contemporary World', 3, 0, 3, 0, 'NONE'),
(138, 2018, 'Bachelor of Science in Information Technology', 'Second Year', 'First Semester', 'GNED 10', 'Gender and Society', 3, 0, 3, 0, 'NONE'),
(139, 2018, 'Bachelor of Science in Information Technology', 'Second Year', 'First Semester', 'GNED 12', 'Dalumat Ng/Sa Filipino', 3, 0, 3, 0, 'GNED 11'),
(140, 2018, 'Bachelor of Science in Information Technology', 'Second Year', 'First Semester', 'ITEC 55', 'Platform Technologies', 2, 1, 2, 3, 'DCIT 23'),
(141, 2018, 'Bachelor of Science in Information Technology', 'Second Year', 'First Semester', 'DCIT 24', 'Information Management', 2, 1, 2, 3, 'DCIT 23'),
(142, 2018, 'Bachelor of Science in Information Technology', 'Second Year', 'First Semester', 'DCIT 50', 'Object Oriented Programming', 2, 1, 2, 3, 'DCIT 23'),
(143, 2018, 'Bachelor of Science in Information Technology', 'Second Year', 'First Semester', 'FITT 3', 'Physical Activities towards Health and Fitness I', 2, 0, 2, 0, 'FITT 1'),
(144, 2018, 'Bachelor of Science in Information Technology', 'Second Year', 'Second Semester', 'GNED 08', 'Understanding the Self', 3, 0, 3, 0, 'NONE'),
(145, 2018, 'Bachelor of Science in Information Technology', 'Second Year', 'Second Semester', 'DCIT 25', 'Data Structures and Algorithms', 2, 1, 2, 3, 'DCIT 50'),
(146, 2018, 'Bachelor of Science in Information Technology', 'Second Year', 'Second Semester', 'ITEC 60', 'Integrated Programming and Technologies 1', 2, 1, 2, 3, 'DCIT 50, ITEC 50'),
(147, 2018, 'Bachelor of Science in Information Technology', 'Second Year', 'Second Semester', 'ITEC 65', 'Open Source Technology', 2, 1, 2, 3, '2nd Year Standing'),
(148, 2018, 'Bachelor of Science in Information Technology', 'Second Year', 'Second Semester', 'DCIT 55', 'Advanced Database System', 2, 1, 2, 3, 'DCIT 24'),
(149, 2018, 'Bachelor of Science in Information Technology', 'Second Year', 'Second Semester', 'ITEC 70', 'Multimedia Systems', 2, 1, 2, 3, '2nd Year Standing'),
(150, 2018, 'Bachelor of Science in Information Technology', 'Second Year', 'Second Semester', 'FITT 4', 'Physical Activities towards Health and Fitness II', 2, 0, 2, 0, 'FITT 1'),
(151, 2018, 'Bachelor of Science in Information Technology', 'Second Year', 'Mid Year', 'STAT 2', 'Applied Statistics', 3, 0, 3, 0, '2nd Year Standing'),
(152, 2018, 'Bachelor of Science in Information Technology', 'Second Year', 'Mid Year', 'ITEC 75', 'System Integration and Architecture 1', 2, 1, 2, 3, 'ITEC 60'),
(153, 2018, 'Bachelor of Science in Information Technology', 'Third Year', 'First Semester', 'ITEC 80', 'Introduction to Human Computer Interaction', 2, 1, 2, 3, '3rd Year Standing'),
(154, 2018, 'Bachelor of Science in Information Technology', 'Third Year', 'First Semester', 'ITEC 85', 'Information Assurance and Security 1', 2, 1, 2, 3, 'ITEC 75'),
(155, 2018, 'Bachelor of Science in Information Technology', 'Third Year', 'First Semester', 'ITEC 90', 'Network Fundamentals', 2, 1, 2, 3, 'ITEC 55'),
(156, 2018, 'Bachelor of Science in Information Technology', 'Third Year', 'First Semester', 'INSY 55', 'System Analysis and Design', 2, 1, 2, 3, '3rd Year Standing'),
(157, 2018, 'Bachelor of Science in Information Technology', 'Third Year', 'First Semester', 'DCIT 26', 'Application Development and Emerging Technologies', 2, 1, 2, 3, 'DCIT 55'),
(158, 2018, 'Bachelor of Science in Information Technology', 'Third Year', 'First Semester', 'DCIT 60', 'Methods of Research', 3, 0, 3, 0, '3rd Year Standing  '),
(159, 2018, 'Bachelor of Science in Information Technology', 'Third Year', 'Second Semester', 'GNED 09', 'Rizals Life, Works, and Writings', 3, 0, 3, 0, 'GNED 04'),
(160, 2018, 'Bachelor of Science in Information Technology', 'Third Year', 'Second Semester', 'ITEC 95', 'Quantitative Methods (Modeling & Simulation)', 3, 0, 3, 3, 'COSC 50, STAT 2'),
(161, 2018, 'Bachelor of Science in Information Technology', 'Third Year', 'Second Semester', 'ITEC 101', 'IT Elective 1 (Human Computer Interaction 2)', 2, 1, 2, 3, 'ITEC 80'),
(162, 2018, 'Bachelor of Science in Information Technology', 'Third Year', 'Second Semester', 'ITEC 106', 'IT Elective 2 (Web System and Technologies 2)', 2, 1, 2, 3, 'ITEC 50'),
(163, 2018, 'Bachelor of Science in Information Technology', 'Third Year', 'Second Semester', 'ITEC 100', 'Information Assurance and Security 2', 2, 1, 2, 3, 'ITEC 85'),
(164, 2018, 'Bachelor of Science in Information Technology', 'Third Year', 'Second Semester', 'ITEC 105', 'Network Management', 2, 1, 2, 3, 'ITEC 90'),
(165, 2018, 'Bachelor of Science in Information Technology', 'Third Year', 'Second Semester', 'ITEC 200A', 'Capstone Project and Research 1', 3, 0, 3, 0, 'DCIT 60, DCIT 26, ITEC 85, 70% Total Units taken'),
(166, 2018, 'Bachelor of Science in Information Technology', 'Fourth Year', 'First Semester', 'DCIT 65', 'Social and Professional Issues', 3, 0, 3, 0, '4th Year Standing'),
(167, 2018, 'Bachelor of Science in Information Technology', 'Fourth Year', 'First Semester', 'ITEC 111', 'IT Elective 3 (Integrated Programming and Technologies 2)', 2, 1, 2, 3, 'ITEC 101'),
(168, 2018, 'Bachelor of Science in Information Technology', 'Fourth Year', 'First Semester', 'ITEC 116', 'IT Elective 4 (Systems Integration and Architecture 2)', 2, 1, 2, 3, 'ITEC 75'),
(169, 2018, 'Bachelor of Science in Information Technology', 'Fourth Year', 'First Semester', 'ITEC 110', 'Systems Administration and Maintenance', 2, 1, 2, 3, 'ITEC 101'),
(170, 2018, 'Bachelor of Science in Information Technology', 'Fourth Year', 'First Semester', 'ITEC 200B', 'Capstone Project and Research 2', 3, 0, 3, 0, 'ITEC 200A'),
(171, 2018, 'Bachelor of Science in Information Technology', 'Fourth Year', 'Second Semester', 'ITEC 199', 'Practicum (minimum 486 hours)', 6, 0, 0, 0, 'DCIT 26, ITEC 85, 70% Total Units taken'),
(229, 2017, 'Bachelor of Science in Hospitality Management', 'First Year', 'First Semester', 'GNED 01', 'Arts Appreciation', 3, 0, 3, 0, 'NONE'),
(230, 2017, 'Bachelor of Science in Hospitality Management', 'First Year', 'First Semester', 'GNED 02', 'Ethics', 3, 0, 3, 0, 'NONE'),
(231, 2017, 'Bachelor of Science in Hospitality Management', 'First Year', 'First Semester', 'GNED 08', 'Understanding the Self', 3, 0, 3, 0, 'NONE'),
(232, 2017, 'Bachelor of Science in Hospitality Management', 'First Year', 'First Semester', 'BSHM 21', 'Fundamentals in Lodging Operations', 3, 0, 3, 0, 'NONE'),
(233, 2017, 'Bachelor of Science in Hospitality Management', 'First Year', 'First Semester', 'BSHM 50', 'Macro Perspective of Tourism & Hospitality', 3, 1, 2, 3, 'NONE'),
(234, 2017, 'Bachelor of Science in Hospitality Management', 'First Year', 'First Semester', 'BSHM 55', 'Risk Management as Applied to Safety, Security and Sanitation', 3, 0, 3, 0, 'NONE'),
(235, 2017, 'Bachelor of Science in Hospitality Management', 'First Year', 'First Semester', 'CVSU 101', 'Institutional Orientation', 1, 0, 1, 0, 'NONE'),
(236, 2017, 'Bachelor of Science in Hospitality Management', 'First Year', 'First Semester', 'FITT 1', 'Movement Enhancement', 2, 0, 2, 0, 'NONE'),
(237, 2017, 'Bachelor of Science in Hospitality Management', 'First Year', 'First Semester', 'NSTP 1', 'CWTS/LTS/ROTC', 3, 0, 3, 0, 'NONE'),
(238, 2017, 'Bachelor of Science in Hospitality Management', 'First Year', 'Second Semester', 'GNED 03', 'Purposive Communication', 3, 0, 3, 0, 'NONE'),
(239, 2017, 'Bachelor of Science in Hospitality Management', 'First Year', 'Second Semester', 'GNED 04', 'Mathematics in Modern World', 3, 0, 3, 0, 'NONE'),
(240, 2017, 'Bachelor of Science in Hospitality Management', 'First Year', 'Second Semester', 'BSHM 60', 'Philippine Tourism, Geography and Culture', 3, 0, 3, 0, 'BSHM 50'),
(241, 2017, 'Bachelor of Science in Hospitality Management', 'First Year', 'Second Semester', 'BSHM 65', 'Micro Perspective of Tourism and Hospitality', 3, 0, 3, 0, 'BSHM 50'),
(242, 2017, 'Bachelor of Science in Hospitality Management', 'First Year', 'Second Semester', 'BSHM 22', 'Kitchen Essentials & Basic Food Preparation', 2, 1, 2, 3, 'BSHM 55'),
(243, 2017, 'Bachelor of Science in Hospitality Management', 'First Year', 'Second Semester', 'BSHM 101', 'Food and Beverage Service', 2, 1, 2, 3, 'BSHM 55'),
(244, 2017, 'Bachelor of Science in Hospitality Management', 'First Year', 'Second Semester', 'FITT 2', 'Fitness Exercises', 2, 0, 2, 0, 'FITT 1'),
(245, 2017, 'Bachelor of Science in Hospitality Management', 'First Year', 'Second Semester', 'NSTP 2', 'CWTS/LTS/ROTC', 3, 0, 3, 0, 'NSTP 1'),
(246, 2017, 'Bachelor of Science in Hospitality Management', 'Second Year', 'First Semester', 'GNED 14', 'Panitikang Panlipunan', 3, 0, 3, 0, 'NONE'),
(247, 2017, 'Bachelor of Science in Hospitality Management', 'Second Year', 'First Semester', 'ALAN 1', 'Asian Language 1', 3, 0, 3, 0, 'NONE'),
(248, 2017, 'Bachelor of Science in Hospitality Management', 'Second Year', 'First Semester', 'BSHM 23', 'Applied Business Tools and Technologies (GDS) with Lab', 2, 1, 2, 3, 'NONE'),
(249, 2017, 'Bachelor of Science in Hospitality Management', 'Second Year', 'First Semester', 'BSHM 141', 'Cost Control', 2, 1, 2, 3, 'GNED 03'),
(250, 2017, 'Bachelor of Science in Hospitality Management', 'Second Year', 'First Semester', 'BSHM 24', 'Supply Chain/Logistics Purchasing Management', 3, 0, 3, 0, 'NONE'),
(251, 2017, 'Bachelor of Science in Hospitality Management', 'Second Year', 'First Semester', 'BSHM 26', 'Bar and Beverage Management with Lab', 2, 1, 2, 3, 'BSHM 101'),
(252, 2017, 'Bachelor of Science in Hospitality Management', 'Second Year', 'First Semester', 'BSHM 111', 'Front Office Operation', 2, 1, 2, 3, 'BSHM 21'),
(253, 2017, 'Bachelor of Science in Hospitality Management', 'Second Year', 'First Semester', 'FITT 3', 'Physical Activities toward Health and Fitness 1', 2, 0, 2, 0, 'FITT 1'),
(254, 2017, 'Bachelor of Science in Hospitality Management', 'Second Year', 'Second Semester', 'BSHM 199-A', 'Hospitality Practicum 1-Housekeeping and Food and Beverage', 0, 3, 0, 300, 'All 1st and 2nd year'),
(255, 2017, 'Bachelor of Science in Hospitality Management', 'Second Year', 'Second Semester', 'FITT 4', 'Physical Activities toward Health and Fitness 2', 2, 0, 2, 0, 'NONE'),
(256, 2017, 'Bachelor of Science in Hospitality Management', 'Third Year', 'First Semester', 'GNED 04', 'Mga Babasahin hinggil sa kasaysayan ng Pilipinas', 3, 0, 3, 0, 'NONE'),
(257, 2017, 'Bachelor of Science in Hospitality Management', 'Third Year', 'First Semester', 'GNED 11', 'Kontekstwalisadong komunikasyon sa Filipino', 3, 0, 3, 0, 'NONE'),
(258, 2017, 'Bachelor of Science in Hospitality Management', 'Third Year', 'First Semester', 'ALAN 2', 'Asian Language 2', 3, 0, 3, 0, 'ALAN 1'),
(259, 2017, 'Bachelor of Science in Hospitality Management', 'Third Year', 'First Semester', 'BSHM 29', 'Introduction to MICE as applied in Hospitality', 2, 1, 2, 3, 'BSHM 55'),
(260, 2017, 'Bachelor of Science in Hospitality Management', 'Third Year', 'First Semester', 'BSHM 70', 'Professional Development & Applied Ethics', 3, 0, 3, 0, 'GNED 02'),
(261, 2017, 'Bachelor of Science in Hospitality Management', 'Third Year', 'First Semester', 'BSHM 75', 'Tourism and Hospitality Marketing', 3, 0, 3, 0, 'BSHM 23'),
(262, 2017, 'Bachelor of Science in Hospitality Management', 'Third Year', 'First Semester', 'BSHM 121', 'Sustainable Hospitality Management', 3, 0, 3, 0, 'NONE'),
(263, 2017, 'Bachelor of Science in Hospitality Management', 'Third Year', 'Second Semester', 'GNED 06', 'Science, Technology World', 3, 0, 3, 0, 'NONE'),
(264, 2017, 'Bachelor of Science in Hospitality Management', 'Third Year', 'Second Semester', 'GNED 12', 'Dalumat Ng/Sa Filipino', 3, 0, 3, 0, 'GNED 11'),
(265, 2017, 'Bachelor of Science in Hospitality Management', 'Third Year', 'Second Semester', 'BSHM 80', 'Legal Aspect in Tourism and Hospitality', 3, 0, 3, 0, 'BSHM 121'),
(266, 2017, 'Bachelor of Science in Hospitality Management', 'Third Year', 'Second Semester', 'BSHM 85', 'Multicultural Diversity in Workplace for the Tourism and Professional', 3, 0, 3, 0, 'BSHM 70'),
(267, 2017, 'Bachelor of Science in Hospitality Management', 'Third Year', 'Second Semester', 'BSHM 90', 'Enterpreneurship in Tourism and Hospitality', 3, 0, 3, 0, 'NONE'),
(268, 2017, 'Bachelor of Science in Hospitality Management', 'Third Year', 'Second Semester', 'BSHM 27', 'Ergonomics & Facilities Planning for the Hospitality Industry', 2, 1, 2, 3, 'NONE'),
(269, 2017, 'Bachelor of Science in Hospitality Management', 'Third Year', 'Second Semester', 'BSHM 131', 'Bread and Pastry Production', 1, 2, 1, 6, 'BSHM 55, BSHM 22'),
(270, 2017, 'Bachelor of Science in Hospitality Management', 'Fourth Year', 'First Semester', 'GNED 07', 'The Contemporary World', 3, 0, 3, 0, 'NONE'),
(271, 2017, 'Bachelor of Science in Hospitality Management', 'Fourth Year', 'First Semester', 'GNED 09', 'Life and Works of Rizal', 3, 0, 3, 0, 'GNED 04'),
(272, 2017, 'Bachelor of Science in Hospitality Management', 'Fourth Year', 'First Semester', 'GNED 10', 'Gender and Society', 3, 0, 3, 0, 'NONE'),
(273, 2017, 'Bachelor of Science in Hospitality Management', 'Fourth Year', 'First Semester', 'BSHM 28', 'Research in Hospitality', 2, 1, 2, 3, 'NONE'),
(274, 2017, 'Bachelor of Science in Hospitality Management', 'Fourth Year', 'First Semester', 'BSHM 151', 'Strategic management in Tourism and Hospitality', 3, 0, 3, 0, 'NONE'),
(275, 2017, 'Bachelor of Science in Hospitality Management', 'Fourth Year', 'First Semester', 'BSHM 161', 'Operations Management in Tourism and Hospitality Industry', 3, 0, 3, 0, 'BSHM 23'),
(276, 2017, 'Bachelor of Science in Hospitality Management', 'Fourth Year', 'First Semester', 'BSHM 100', 'Tourism and Hospitality Service Quality Management', 3, 0, 3, 0, 'BSHM 80'),
(277, 2017, 'Bachelor of Science in Hospitality Management', 'Fourth Year', 'Second Semester', 'BSHM 199-B', 'PRACTICUM 2- Hotel Operations', 0, 6, 0, 600, 'All Subjects'),
(278, 2023, 'Bachelor of Science in Business Administration Major in Human Resource Management', 'First Year', 'First Semester', 'GNED 03', 'Mathematics in the Modern World', 3, 0, 3, 0, 'NONE'),
(279, 2023, 'Bachelor of Science in Business Administration Major in Human Resource Management', 'First Year', 'First Semester', 'GNED 04', 'Mga Babasahin Hinggil sa Kasaysayan ng Pilipinas', 3, 0, 3, 0, 'NONE'),
(280, 2023, 'Bachelor of Science in Business Administration Major in Human Resource Management', 'First Year', 'First Semester', 'GNED 05', 'Purposive Communication', 3, 0, 3, 0, 'NONE'),
(281, 2023, 'Bachelor of Science in Business Administration Major in Human Resource Management', 'First Year', 'First Semester', 'GNED 07', 'The Contemporary World', 3, 0, 3, 0, 'NONE'),
(282, 2023, 'Bachelor of Science in Business Administration Major in Human Resource Management', 'First Year', 'First Semester', 'GNED 11', 'Kontekstwalisadong Komunikasyon sa Filipino', 3, 0, 3, 0, 'NONE'),
(283, 2023, 'Bachelor of Science in Business Administration Major in Human Resource Management', 'First Year', 'First Semester', 'ECON 23', 'Basic Microeconomics', 3, 0, 3, 0, 'NONE'),
(284, 2023, 'Bachelor of Science in Business Administration Major in Human Resource Management', 'First Year', 'First Semester', 'FITT 1', 'Movement Enhancement', 2, 0, 2, 0, 'NONE'),
(285, 2023, 'Bachelor of Science in Business Administration Major in Human Resource Management', 'First Year', 'First Semester', 'NSTP 1', 'National Service Training Program', 3, 0, 3, 0, 'NONE'),
(286, 2023, 'Bachelor of Science in Business Administration Major in Human Resource Management', 'First Year', 'First Semester', 'ORNT 1', 'Institutional Orientation', 1, 0, 1, 0, 'NONE'),
(287, 2023, 'Bachelor of Science in Business Administration Major in Human Resource Management', 'First Year', 'Second Semester', 'GNED 01', 'Art Appreciation', 3, 0, 3, 0, 'NONE'),
(288, 2023, 'Bachelor of Science in Business Administration Major in Human Resource Management', 'First Year', 'Second Semester', 'GNED 02', 'Ethics', 3, 0, 3, 0, 'NONE'),
(289, 2023, 'Bachelor of Science in Business Administration Major in Human Resource Management', 'First Year', 'Second Semester', 'GNED 08', 'Understanding the Self', 3, 0, 3, 0, 'NONE'),
(290, 2023, 'Bachelor of Science in Business Administration Major in Human Resource Management', 'First Year', 'Second Semester', 'BADM 21', 'Quantitative Techniques in Business', 3, 0, 3, 0, 'NONE'),
(291, 2023, 'Bachelor of Science in Business Administration Major in Human Resource Management', 'First Year', 'Second Semester', 'BADM 22', 'Human Resource Management', 3, 0, 3, 0, 'NONE'),
(292, 2023, 'Bachelor of Science in Business Administration Major in Human Resource Management', 'First Year', 'Second Semester', 'BADM 23', 'Business Law (Obligations and Contracts)', 3, 0, 3, 0, 'NONE'),
(293, 2023, 'Bachelor of Science in Business Administration Major in Human Resource Management', 'First Year', 'Second Semester', 'FITT 2', 'Fitness Exercises', 2, 0, 2, 0, 'FITT 1'),
(294, 2023, 'Bachelor of Science in Business Administration Major in Human Resource Management', 'First Year', 'Second Semester', 'NSTP 2', 'National Service Training Program', 3, 0, 3, 0, 'NSTP 1'),
(295, 2023, 'Bachelor of Science in Business Administration Major in Human Resource Management', 'Second Year', 'First Semester', 'GNED 06', 'Science, Technology and Society', 3, 0, 3, 0, 'NONE'),
(296, 2023, 'Bachelor of Science in Business Administration Major in Human Resource Management', 'Second Year', 'First Semester', 'GNED 10', 'Gender and Society', 3, 0, 3, 0, 'NONE'),
(297, 2023, 'Bachelor of Science in Business Administration Major in Human Resource Management', 'Second Year', 'First Semester', 'GNED 12', 'Dalumat Ng/Sa Filipino', 3, 0, 3, 0, 'GNED 11'),
(298, 2023, 'Bachelor of Science in Business Administration Major in Human Resource Management', 'Second Year', 'First Semester', 'BADM 24', 'Operations Management', 3, 0, 3, 0, 'BADM 21'),
(299, 2023, 'Bachelor of Science in Business Administration Major in Human Resource Management', 'Second Year', 'First Semester', 'ECON 24', 'International Trade and Agreements', 3, 0, 3, 0, 'NONE'),
(300, 2023, 'Bachelor of Science in Business Administration Major in Human Resource Management', 'Second Year', 'First Semester', 'HRMT 50', 'Administrative Office Management', 3, 0, 3, 0, 'BADM 22'),
(301, 2023, 'Bachelor of Science in Business Administration Major in Human Resource Management', 'Second Year', 'First Semester', 'HRMT 55', 'Recruitment and Selection', 3, 0, 3, 0, 'BADM 22'),
(302, 2023, 'Bachelor of Science in Business Administration Major in Human Resource Management', 'Second Year', 'First Semester', 'FITT 3', 'Physical Activities towards Health and Fitness I', 2, 0, 2, 0, 'FITT 1'),
(303, 2023, 'Bachelor of Science in Business Administration Major in Human Resource Management', 'Second Year', 'Second Semester', 'GNED 14', 'Panitikang Panlipunan', 3, 0, 3, 0, 'NONE'),
(304, 2023, 'Bachelor of Science in Business Administration Major in Human Resource Management', 'Second Year', 'Second Semester', 'BADM 25', 'Good Governance and Social Responsibility', 3, 0, 3, 0, 'NONE'),
(305, 2023, 'Bachelor of Science in Business Administration Major in Human Resource Management', 'Second Year', 'Second Semester', 'TAXN 21', 'Taxation (Income and Taxation)', 3, 0, 3, 0, 'NONE'),
(306, 2023, 'Bachelor of Science in Business Administration Major in Human Resource Management', 'Second Year', 'Second Semester', 'HRMT 60', 'Training and Development', 3, 0, 3, 0, 'BADM 22'),
(307, 2023, 'Bachelor of Science in Business Administration Major in Human Resource Management', 'Second Year', 'Second Semester', 'HRMT 65', 'Labor Law and Legislation', 3, 0, 3, 0, 'BADM 22'),
(308, 2023, 'Bachelor of Science in Business Administration Major in Human Resource Management', 'Second Year', 'Second Semester', 'HRMT 70', 'Compensation Administration', 3, 0, 3, 0, 'BADM 22'),
(309, 2023, 'Bachelor of Science in Business Administration Major in Human Resource Management', 'Second Year', 'Second Semester', 'FITT 4', 'Physical Activities towards Health and Fitness II', 2, 0, 2, 0, 'FITT 1'),
(310, 2023, 'Bachelor of Science in Business Administration Major in Human Resource Management', 'Third Year', 'First Semester', 'GNED 09', 'Rizal\'s Life and Works', 3, 0, 3, 0, 'GNED 04'),
(311, 2023, 'Bachelor of Science in Business Administration Major in Human Resource Management', 'Third Year', 'First Semester', 'BADM 26', 'Business Research', 3, 0, 3, 0, 'NONE'),
(312, 2023, 'Bachelor of Science in Business Administration Major in Human Resource Management', 'Third Year', 'First Semester', 'HRMT 75', 'Organizational Development', 3, 0, 3, 0, 'BADM 22'),
(313, 2023, 'Bachelor of Science in Business Administration Major in Human Resource Management', 'Third Year', 'First Semester', 'HRMT 80', 'Labor Relation and Negotiations', 3, 0, 3, 0, 'BADM 22'),
(314, 2023, 'Bachelor of Science in Business Administration Major in Human Resource Management', 'Third Year', 'Second Semester', 'HRMT 85', 'Special Topics in Human Resource Management', 3, 0, 3, 0, '3rd Year Standing'),
(315, 2023, 'Bachelor of Science in Business Administration Major in Human Resource Management', 'Third Year', 'Second Semester', 'ELEC 1', 'Human Resource Management System', 3, 0, 3, 0, '3rd Year Standing'),
(316, 2023, 'Bachelor of Science in Business Administration Major in Human Resource Management', 'Fourth Year', 'First Semester', 'BADM 27', 'Strategic Management', 3, 0, 3, 0, 'All Major Subjects'),
(317, 2023, 'Bachelor of Science in Business Administration Major in Human Resource Management', 'Fourth Year', 'First Semester', 'BADM 200', 'Research/EDP Proposal', 1, 0, 1, 0, 'All Major Subjects'),
(318, 2023, 'Bachelor of Science in Business Administration Major in Human Resource Management', 'Fourth Year', 'First Semester', 'ELEC 2', 'Logistic Management', 3, 0, 3, 0, 'NONE'),
(319, 2023, 'Bachelor of Science in Business Administration Major in Human Resource Management', 'Fourth Year', 'First Semester', 'ELEC 3', 'Marketing Management', 3, 0, 3, 0, 'NONE'),
(320, 2023, 'Bachelor of Science in Business Administration Major in Human Resource Management', 'Fourth Year', 'First Semester', 'ELEC 4', 'Strategic Human Resource Management', 3, 0, 3, 0, 'NONE'),
(321, 2023, 'Bachelor of Science in Business Administration Major in Human Resource Management', 'Fourth Year', 'Second Semester', 'BMGT 200b', 'Research/EDP Final Manuscript', 2, 0, 2, 0, 'BADM 200'),
(322, 2023, 'Bachelor of Science in Business Administration Major in Human Resource Management', 'Fourth Year', 'Second Semester', 'BADM 199', 'Practicum Integrated Learning 2 (600 hours)', 6, 0, 6, 0, 'All Subjects'),
(323, 2023, 'Bachelor of Science in Business Administration Major in Marketing Management', 'First Year', 'First Semester', 'GNED 03', 'Mathematics in the Modern World', 3, 0, 3, 0, 'NONE'),
(324, 2023, 'Bachelor of Science in Business Administration Major in Marketing Management', 'First Year', 'First Semester', 'GNED 04', 'Mga Babasahin Hinggil sa Kasaysayan ng Pilipinas', 3, 0, 3, 0, 'NONE'),
(325, 2023, 'Bachelor of Science in Business Administration Major in Marketing Management', 'First Year', 'First Semester', 'GNED 05', 'Purposive Communication', 3, 0, 3, 0, 'NONE'),
(326, 2023, 'Bachelor of Science in Business Administration Major in Marketing Management', 'First Year', 'First Semester', 'GNED 07', 'The Contemporary World', 3, 0, 3, 0, 'NONE'),
(327, 2023, 'Bachelor of Science in Business Administration Major in Marketing Management', 'First Year', 'First Semester', 'GNED 11', 'Kontekstwalisadong Komunikasyon sa Filipino', 3, 0, 3, 0, 'NONE'),
(328, 2023, 'Bachelor of Science in Business Administration Major in Marketing Management', 'First Year', 'First Semester', 'ECON 23', 'Basic Microeconomics', 3, 0, 3, 0, 'NONE'),
(329, 2023, 'Bachelor of Science in Business Administration Major in Marketing Management', 'First Year', 'First Semester', 'FITT 1', 'Movement Enhancement', 2, 0, 2, 0, 'NONE'),
(330, 2023, 'Bachelor of Science in Business Administration Major in Marketing Management', 'First Year', 'First Semester', 'NSTP 1', 'National Service Training Program', 3, 0, 3, 0, 'NONE'),
(331, 2023, 'Bachelor of Science in Business Administration Major in Marketing Management', 'First Year', 'First Semester', 'ORNT 1', 'Institutional Orientation', 1, 0, 1, 0, 'NONE'),
(332, 2023, 'Bachelor of Science in Business Administration Major in Marketing Management', 'First Year', 'Second Semester', 'GNED 01', 'Art Appreciation', 3, 0, 3, 0, 'NONE'),
(333, 2023, 'Bachelor of Science in Business Administration Major in Marketing Management', 'First Year', 'Second Semester', 'GNED 02', 'Ethics', 3, 0, 3, 0, 'NONE'),
(334, 2023, 'Bachelor of Science in Business Administration Major in Marketing Management', 'First Year', 'Second Semester', 'GNED 08', 'Understanding the Self', 3, 0, 3, 0, 'NONE'),
(335, 2023, 'Bachelor of Science in Business Administration Major in Marketing Management', 'First Year', 'Second Semester', 'BADM 21', 'Quantitative Techniques in Business', 3, 0, 3, 0, 'NONE'),
(336, 2023, 'Bachelor of Science in Business Administration Major in Marketing Management', 'First Year', 'Second Semester', 'BADM 22', 'Human Resource Management', 3, 0, 3, 0, 'NONE'),
(337, 2023, 'Bachelor of Science in Business Administration Major in Marketing Management', 'First Year', 'Second Semester', 'BADM 23', 'Business Law (Obligations and Contracts)', 3, 0, 3, 0, 'NONE'),
(338, 2023, 'Bachelor of Science in Business Administration Major in Marketing Management', 'First Year', 'Second Semester', 'FITT 2', 'Fitness Exercises', 2, 0, 2, 0, 'FITT 1'),
(339, 2023, 'Bachelor of Science in Business Administration Major in Marketing Management', 'First Year', 'Second Semester', 'NSTP 2', 'National Service Training Program', 3, 0, 3, 0, 'NSTP 1'),
(340, 2023, 'Bachelor of Science in Business Administration Major in Marketing Management', 'Second Year', 'First Semester', 'GNED 06', 'Science, Technology and Society', 3, 0, 3, 0, 'NONE'),
(341, 2023, 'Bachelor of Science in Business Administration Major in Marketing Management', 'Second Year', 'First Semester', 'GNED 10', 'Gender and Society', 3, 0, 3, 0, 'NONE'),
(342, 2023, 'Bachelor of Science in Business Administration Major in Marketing Management', 'Second Year', 'First Semester', 'GNED 12', 'Dalumat Ng/Sa Filipino', 3, 0, 3, 0, 'GNED 11'),
(343, 2023, 'Bachelor of Science in Business Administration Major in Marketing Management', 'Second Year', 'First Semester', 'BADM 24', 'Operations Management', 3, 0, 3, 0, 'BADM 21'),
(344, 2023, 'Bachelor of Science in Business Administration Major in Marketing Management', 'Second Year', 'First Semester', 'ECON 24', 'International Trade and Agreements', 3, 0, 3, 0, 'NONE'),
(345, 2023, 'Bachelor of Science in Business Administration Major in Marketing Management', 'Second Year', 'First Semester', 'MKTG 50', 'Consumer and Behavior', 3, 0, 3, 0, 'NONE'),
(346, 2023, 'Bachelor of Science in Business Administration Major in Marketing Management', 'Second Year', 'First Semester', 'MKTG 55', 'Market Research', 3, 0, 3, 0, 'NONE'),
(347, 2023, 'Bachelor of Science in Business Administration Major in Marketing Management', 'Second Year', 'First Semester', 'FITT 3', 'Physical Activities towards Health and Fitness I', 2, 0, 2, 0, 'FITT 1'),
(348, 2023, 'Bachelor of Science in Business Administration Major in Marketing Management', 'Second Year', 'Second Semester', 'GNED 14', 'Panitikang Panlipunan', 3, 0, 3, 0, 'NONE'),
(349, 2023, 'Bachelor of Science in Business Administration Major in Marketing Management', 'Second Year', 'Second Semester', 'BADM 25', 'Good Governance and Social Responsibility', 3, 0, 3, 0, 'NONE'),
(350, 2023, 'Bachelor of Science in Business Administration Major in Marketing Management', 'Second Year', 'Second Semester', 'TAXN 21', 'Taxation (Income and Taxation)', 3, 0, 3, 0, 'NONE'),
(351, 2023, 'Bachelor of Science in Business Administration Major in Marketing Management', 'Second Year', 'Second Semester', 'MKTG 60', 'Product Management', 3, 0, 3, 0, 'MKTG 50, MKTG 55'),
(352, 2023, 'Bachelor of Science in Business Administration Major in Marketing Management', 'Second Year', 'Second Semester', 'MKTG 65', 'Retail Management', 3, 0, 3, 0, 'MKTG 50, MKTG 55'),
(353, 2023, 'Bachelor of Science in Business Administration Major in Marketing Management', 'Second Year', 'Second Semester', 'MKTG 70', 'Advertising', 3, 0, 3, 0, 'MKTG 50, MKTG 55');
INSERT INTO `curriculum_courses` (`id`, `curriculum_year`, `program`, `year_level`, `semester`, `course_code`, `course_title`, `credit_units_lec`, `credit_units_lab`, `lect_hrs_lec`, `lect_hrs_lab`, `pre_requisite`) VALUES
(354, 2023, 'Bachelor of Science in Business Administration Major in Marketing Management', 'Second Year', 'Second Semester', 'FITT 4', 'Physical Activities towards Health and Fitness II', 2, 0, 2, 0, 'FITT 1'),
(355, 2023, 'Bachelor of Science in Business Administration Major in Marketing Management', 'Third Year', 'First Semester', 'GNED 09', 'Rizal\'s Life and Works', 3, 0, 3, 0, 'GNED 04'),
(356, 2023, 'Bachelor of Science in Business Administration Major in Marketing Management', 'Third Year', 'First Semester', 'BADM 26', 'Business Research', 3, 0, 3, 0, 'NONE'),
(357, 2023, 'Bachelor of Science in Business Administration Major in Marketing Management', 'Third Year', 'First Semester', 'MKTG 75', 'Professional Salesmanship', 3, 0, 3, 0, 'MKTG 50, MKTG 55'),
(358, 2023, 'Bachelor of Science in Business Administration Major in Marketing Management', 'Third Year', 'First Semester', 'MKTG 80', 'Marketing Management', 3, 0, 3, 0, 'MKTG 50, MKTG 55'),
(359, 2023, 'Bachelor of Science in Business Administration Major in Marketing Management', 'Third Year', 'First Semester', 'MKTG 85', 'Special Topics in Marketing Management', 3, 0, 3, 0, 'MKTG 50, MKTG 55'),
(360, 2023, 'Bachelor of Science in Business Administration Major in Marketing Management', 'Third Year', 'Second Semester', 'ELEC 1', 'Distribution Management', 3, 0, 3, 0, '3rd Year Standing'),
(361, 2023, 'Bachelor of Science in Business Administration Major in Marketing Management', 'Third Year', 'Second Semester', 'BADM 27', 'Strategic Management', 3, 0, 3, 0, 'All Major Subjects'),
(362, 2023, 'Bachelor of Science in Business Administration Major in Marketing Management', 'Third Year', 'Second Semester', 'BADM 200', 'Research/EDP Proposal', 1, 0, 1, 0, 'All Major Subjects'),
(363, 2023, 'Bachelor of Science in Business Administration Major in Marketing Management', 'Third Year', 'Second Semester', 'ELEC 2', 'International Marketing', 3, 0, 3, 0, 'NONE'),
(364, 2023, 'Bachelor of Science in Business Administration Major in Marketing Management', 'Third Year', 'Second Semester', 'ELEC 3', 'E-commerce and Internet Marketing', 3, 0, 3, 0, 'NONE'),
(365, 2023, 'Bachelor of Science in Business Administration Major in Marketing Management', 'Third Year', 'Second Semester', 'ELEC 4', 'Service Marketing', 3, 0, 3, 0, 'NONE'),
(366, 2023, 'Bachelor of Science in Business Administration Major in Marketing Management', 'Fourth Year', 'First Semester', 'BMGT 200b', 'Research/EDP Final Manuscript', 2, 0, 2, 0, 'BADM 200'),
(367, 2023, 'Bachelor of Science in Business Administration Major in Marketing Management', 'Fourth Year', 'Second Semester', 'BADM 199', 'Practicum Integrated Learning 2 (600 hours)', 6, 0, 600, 0, 'All Subjects'),
(368, 2021, 'Bachelor of Secondary Education Major in English', 'First Year', 'First Semester', 'GNED 03', 'Mathematics in the Modern World', 3, 0, 3, 0, 'NONE'),
(369, 2021, 'Bachelor of Secondary Education Major in English', 'First Year', 'First Semester', 'GNED 05', 'Purposive Communication', 3, 0, 3, 0, 'NONE'),
(370, 2021, 'Bachelor of Secondary Education Major in English', 'First Year', 'First Semester', 'GNED 11', 'Kontekstwalisadong Komunikasyon sa Filipino', 3, 0, 3, 0, 'NONE'),
(371, 2021, 'Bachelor of Secondary Education Major in English', 'First Year', 'First Semester', 'EDUC 55', 'The Teaching Profession', 3, 0, 3, 0, 'NONE'),
(372, 2021, 'Bachelor of Secondary Education Major in English', 'First Year', 'First Semester', 'EDUC 60', 'The Teacher and The Community, School Culture and Organizational Leadership', 3, 0, 3, 0, 'NONE'),
(373, 2021, 'Bachelor of Secondary Education Major in English', 'First Year', 'First Semester', 'BSEE 21', 'Introduction to Linguistics', 2, 0, 3, 0, 'NONE'),
(374, 2021, 'Bachelor of Secondary Education Major in English', 'First Year', 'First Semester', 'FITT 1', 'Movement Enhancement', 2, 0, 2, 0, 'NONE'),
(375, 2021, 'Bachelor of Secondary Education Major in English', 'First Year', 'First Semester', 'NSTP 1', 'CWTS/LTS/ROTC', 3, 0, 3, 0, 'NONE'),
(376, 2021, 'Bachelor of Secondary Education Major in English', 'First Year', 'First Semester', 'CVSU 101', 'Institutional Orientation', 1, 0, 1, 0, 'NONE'),
(377, 2021, 'Bachelor of Secondary Education Major in English', 'First Year', 'Second Semester', 'GNED 06', 'Science, Technology and Society', 3, 0, 3, 0, 'NONE'),
(378, 2021, 'Bachelor of Secondary Education Major in English', 'First Year', 'Second Semester', 'GNED 08', 'Understanding the Self', 3, 0, 3, 0, 'NONE'),
(379, 2021, 'Bachelor of Secondary Education Major in English', 'First Year', 'Second Semester', 'GNED 14', 'Panitikang Panlipunan', 3, 0, 3, 0, 'NONE'),
(380, 2021, 'Bachelor of Secondary Education Major in English', 'First Year', 'Second Semester', 'EDUC 65', 'Foundation of Special and Inclusive Education', 3, 0, 3, 0, 'NONE'),
(381, 2021, 'Bachelor of Secondary Education Major in English', 'First Year', 'Second Semester', 'BSEE 22', 'Language Culture and Society', 3, 0, 3, 0, 'BSEE 21'),
(382, 2021, 'Bachelor of Secondary Education Major in English', 'First Year', 'Second Semester', 'EDUC 85', 'Technology for Teaching and Learning 1', 3, 0, 3, 0, 'NONE'),
(383, 2021, 'Bachelor of Secondary Education Major in English', 'First Year', 'Second Semester', 'FITT 2', 'Fitness Exercises', 2, 0, 2, 0, 'FITT 1'),
(384, 2021, 'Bachelor of Secondary Education Major in English', 'First Year', 'Second Semester', 'NSTP 2', 'CWTS/LTS/ROTC', 3, 0, 3, 0, 'NSTP 1'),
(385, 2021, 'Bachelor of Secondary Education Major in English', 'First Year', 'Midyear', 'EDUC 50', 'Child and Adolescent Learner and Learning Principles', 3, 0, 3, 0, 'NONE'),
(386, 2021, 'Bachelor of Secondary Education Major in English', 'First Year', 'Midyear', 'EDUC 70', 'Facilitating Learner-Centered Teaching', 3, 0, 3, 0, 'NONE'),
(387, 2021, 'Bachelor of Secondary Education Major in English', 'Second Year', 'First Semester', 'EDUC 75', 'Assessment in Learning 1', 3, 0, 3, 0, 'NONE'),
(388, 2021, 'Bachelor of Secondary Education Major in English', 'Second Year', 'First Semester', 'EDUC 90', 'The Teacher and The School Curriculum', 3, 0, 3, 0, 'NONE'),
(389, 2021, 'Bachelor of Secondary Education Major in English', 'Second Year', 'First Semester', 'BSEE 23', 'Structure of English', 3, 0, 3, 0, 'BSEE 21'),
(390, 2021, 'Bachelor of Secondary Education Major in English', 'Second Year', 'First Semester', 'BSEE 24', 'Principles and Theories of Language Acquisition and Learning', 3, 0, 3, 0, 'BSEE 22'),
(391, 2021, 'Bachelor of Secondary Education Major in English', 'Second Year', 'First Semester', 'BSEE 33', 'Mythology and Folklore', 3, 0, 3, 0, 'BSEE 22'),
(392, 2021, 'Bachelor of Secondary Education Major in English', 'Second Year', 'First Semester', 'BSEE 110', 'Stylistics and Discourse Analysis', 3, 0, 3, 0, 'NONE'),
(393, 2021, 'Bachelor of Secondary Education Major in English', 'Second Year', 'First Semester', 'GNED 04', 'Mga Babasahin Hinggil sa Kasaysayan ng Pilipinas', 3, 0, 3, 0, 'NONE'),
(394, 2021, 'Bachelor of Secondary Education Major in English', 'Second Year', 'First Semester', 'FITT 3', 'Physical Activities towards Health and Fitness I', 2, 0, 2, 0, 'FITT 1'),
(395, 2021, 'Bachelor of Secondary Education Major in English', 'Second Year', 'Second Semester', 'EDUC 80', 'Assessment in Learning 2', 3, 0, 3, 0, 'NONE'),
(396, 2021, 'Bachelor of Secondary Education Major in English', 'Second Year', 'Second Semester', 'EDUC 95', 'Building and Enhancing New Literacies Across the Curriculum', 3, 0, 3, 0, 'NONE'),
(397, 2021, 'Bachelor of Secondary Education Major in English', 'Second Year', 'Second Semester', 'BSEE 25', 'Language Programs and Policies in Multilingual Society', 3, 0, 3, 0, 'BSEE 24'),
(398, 2021, 'Bachelor of Secondary Education Major in English', 'Second Year', 'Second Semester', 'BSEE 26', 'Language Learning Materials Development', 3, 0, 3, 0, 'BSEE 24, EDUC 85'),
(399, 2021, 'Bachelor of Secondary Education Major in English', 'Second Year', 'Second Semester', 'BSEE 32', 'Children and Adolescent Literature', 3, 0, 3, 0, 'BSEE 24'),
(400, 2021, 'Bachelor of Secondary Education Major in English', 'Second Year', 'Second Semester', 'BSEE 39', 'Technical Writing', 3, 0, 3, 0, 'BSEE 23'),
(401, 2021, 'Bachelor of Secondary Education Major in English', 'Second Year', 'Second Semester', 'GNED 15', 'World Literature', 3, 0, 3, 0, 'NONE'),
(402, 2021, 'Bachelor of Secondary Education Major in English', 'Second Year', 'Second Semester', 'FITT 4', 'Physical Activities towards Health and Fitness II', 2, 0, 2, 0, 'FITT 1'),
(403, 2021, 'Bachelor of Secondary Education Major in English', 'Second Year', 'Midyear', 'BSEE 30', 'Speech and Theater Arts', 3, 0, 3, 0, 'BSEE 23'),
(404, 2021, 'Bachelor of Secondary Education Major in English', 'Second Year', 'Midyear', 'BSEE 34', 'Survey of Philippine Literature in English', 3, 0, 3, 0, 'BSEE 32'),
(405, 2021, 'Bachelor of Secondary Education Major in English', 'Second Year', 'Midyear', 'BSEE 35', 'Survey of Afro-Asian Literature', 3, 0, 3, 0, 'BSEE 32'),
(406, 2021, 'Bachelor of Secondary Education Major in English', 'Third Year', 'First Semester', 'EDFS 21', 'Field Study 1 - Observations of Teaching-Learning in Actual School Environment', 3, 0, 3, 0, 'PROF Ed Courses'),
(407, 2021, 'Bachelor of Secondary Education Major in English', 'Third Year', 'First Semester', 'BSEE 28', 'Teaching and Assessment of Macroskills', 3, 0, 3, 0, 'BSEE 22'),
(408, 2021, 'Bachelor of Secondary Education Major in English', 'Third Year', 'First Semester', 'BSEE 29', 'Teaching and Assessment of Grammar', 3, 0, 3, 0, 'BSEE 23'),
(409, 2021, 'Bachelor of Secondary Education Major in English', 'Third Year', 'First Semester', 'BSEE 36', 'Survey of English and American Literature', 3, 0, 3, 0, 'BSEE 32'),
(410, 2021, 'Bachelor of Secondary Education Major in English', 'Third Year', 'First Semester', 'BSEE 37', 'Contemporary, Popular, and Emergent Literature', 3, 0, 3, 0, 'BSEE 32'),
(411, 2021, 'Bachelor of Secondary Education Major in English', 'Third Year', 'First Semester', 'BSEE 111', 'English for Specific Purposes', 3, 0, 3, 0, 'NONE'),
(412, 2021, 'Bachelor of Secondary Education Major in English', 'Third Year', 'First Semester', 'GNED 12', 'Dalumat Ng/Sa Filipino', 3, 0, 3, 0, 'GNED 11'),
(413, 2021, 'Bachelor of Secondary Education Major in English', 'Third Year', 'Second Semester', 'EDFS 22', 'Field Study 2 - Participation and Teaching Assistantship', 3, 0, 3, 0, 'EDFS 21'),
(414, 2021, 'Bachelor of Secondary Education Major in English', 'Third Year', 'Second Semester', 'BSEE 27', 'Teaching and Assessment of Literature', 3, 0, 3, 0, 'BSEE 38'),
(415, 2021, 'Bachelor of Secondary Education Major in English', 'Third Year', 'Second Semester', 'BSEE 31', 'Language and Education Research', 3, 0, 3, 0, 'BSEE 24'),
(416, 2021, 'Bachelor of Secondary Education Major in English', 'Third Year', 'Second Semester', 'BSEE 38', 'Literary Criticism', 3, 0, 3, 0, 'BSEE 32, BSEE 33, BSEE 34, BSEE 35, BSEE 36, BSEE 37'),
(417, 2021, 'Bachelor of Secondary Education Major in English', 'Third Year', 'Second Semester', 'BSEE 40', 'Campus Journalism', 3, 0, 3, 0, 'BSEE 39'),
(418, 2021, 'Bachelor of Secondary Education Major in English', 'Third Year', 'Second Semester', 'BSEE 41', 'Technology for Teaching and Learning 2 (Technology in Secondary Language Education)', 2, 0, 3, 0, 'EDUC 85'),
(419, 2021, 'Bachelor of Secondary Education Major in English', 'Third Year', 'Second Semester', 'EDUC 197', 'Competency Appraisal 1', 3, 0, 3, 0, 'PROF Ed Courses'),
(420, 2021, 'Bachelor of Secondary Education Major in English', 'Fourth Year', 'First Semester', 'EDFS 23', 'Teaching Internship', 6, 0, 0, 40, 'EDFS 21, EDFS 22'),
(421, 2021, 'Bachelor of Secondary Education Major in English', 'Fourth Year', 'Second Semester', 'GNED 01', 'Art Appreciation', 3, 0, 3, 0, 'NONE'),
(422, 2021, 'Bachelor of Secondary Education Major in English', 'Fourth Year', 'Second Semester', 'GNED 02', 'Ethics', 3, 0, 3, 0, 'NONE'),
(423, 2021, 'Bachelor of Secondary Education Major in English', 'Fourth Year', 'Second Semester', 'GNED 07', 'The Contemporary World', 3, 0, 3, 0, 'NONE'),
(424, 2021, 'Bachelor of Secondary Education Major in English', 'Fourth Year', 'Second Semester', 'GNED 09', 'Life and Works of Rizal', 3, 0, 3, 0, 'GNED 04'),
(425, 2021, 'Bachelor of Secondary Education Major in English', 'Fourth Year', 'Second Semester', 'GNED 10', 'Gender and Society', 3, 0, 3, 0, 'NONE'),
(426, 2021, 'Bachelor of Secondary Education Major in English', 'Fourth Year', 'Second Semester', 'GNED 13', 'Retorika: Masining na Pagpapahayag', 3, 0, 3, 0, 'GNED 11, GNED 12'),
(427, 2021, 'Bachelor of Secondary Education Major in English', 'Fourth Year', 'Second Semester', 'EDUC 198', 'Competency Appraisal 2', 3, 0, 3, 0, 'EDUC 197'),
(428, 2021, 'Bachelor of Secondary Education Major in Science', 'First Year', 'First Semester', 'GNED 03', 'Mathematics in the Modern World', 3, 0, 3, 0, 'NONE'),
(429, 2021, 'Bachelor of Secondary Education Major in Science', 'First Year', 'First Semester', 'GNED 05', 'Purposive Communication', 3, 0, 3, 0, 'NONE'),
(430, 2021, 'Bachelor of Secondary Education Major in Science', 'First Year', 'First Semester', 'GNED 11', 'Kontekstwalisadong Komunikasyon sa Filipino', 3, 0, 3, 0, 'NONE'),
(431, 2021, 'Bachelor of Secondary Education Major in Science', 'First Year', 'First Semester', 'EDUC 50', 'Child and Adolescent Learner and Learning Principles', 3, 0, 3, 0, 'NONE'),
(432, 2021, 'Bachelor of Secondary Education Major in Science', 'First Year', 'First Semester', 'EDUC 55', 'The Teaching Profession', 3, 0, 3, 0, 'NONE'),
(433, 2021, 'Bachelor of Secondary Education Major in Science', 'First Year', 'First Semester', 'EDUC 60', 'The Teacher and The Community, School Culture and Organizational Leadership', 3, 0, 3, 0, 'NONE'),
(434, 2021, 'Bachelor of Secondary Education Major in Science', 'First Year', 'First Semester', 'FITT 1', 'Movement Enhancement', 2, 0, 2, 0, 'NONE'),
(435, 2021, 'Bachelor of Secondary Education Major in Science', 'First Year', 'First Semester', 'NSTP 1', 'CWTS/LTS/ROTC', 3, 0, 3, 0, 'NONE'),
(436, 2021, 'Bachelor of Secondary Education Major in Science', 'First Year', 'First Semester', 'CVSU 101', 'Institutional Orientation', 1, 0, 1, 0, 'NONE'),
(437, 2021, 'Bachelor of Secondary Education Major in Science', 'First Year', 'Second Semester', 'GNED 06', 'Science, Technology, and Society', 3, 0, 3, 0, 'NONE'),
(438, 2021, 'Bachelor of Secondary Education Major in Science', 'First Year', 'Second Semester', 'GNED 08', 'Understanding the Self', 3, 0, 3, 0, 'NONE'),
(439, 2021, 'Bachelor of Secondary Education Major in Science', 'First Year', 'Second Semester', 'GNED 14', 'Panitikang Panlipunan', 3, 0, 3, 0, 'NONE'),
(440, 2021, 'Bachelor of Secondary Education Major in Science', 'First Year', 'Second Semester', 'EDUC 65', 'Foundation of Special and Inclusive Education', 3, 0, 3, 0, 'NONE'),
(441, 2021, 'Bachelor of Secondary Education Major in Science', 'First Year', 'Second Semester', 'EDUC 70', 'Facilitating Learner-Centered Teaching', 3, 0, 3, 0, 'NONE'),
(442, 2021, 'Bachelor of Secondary Education Major in Science', 'First Year', 'Second Semester', 'EDUC 85', 'Technology for Teaching and Learning 1', 3, 0, 3, 0, 'NONE'),
(443, 2021, 'Bachelor of Secondary Education Major in Science', 'First Year', 'Second Semester', 'BSES 30', 'Fluid Mechanics', 3, 0, 3, 0, 'NONE'),
(444, 2021, 'Bachelor of Secondary Education Major in Science', 'First Year', 'Second Semester', 'FITT 2', 'Fitness Exercises', 2, 0, 2, 0, 'FITT 1'),
(445, 2021, 'Bachelor of Secondary Education Major in Science', 'First Year', 'Second Semester', 'NSTP 2', 'CWTS/LTS/ROTC', 3, 0, 3, 0, 'NSTP 1'),
(446, 2021, 'Bachelor of Secondary Education Major in Science', 'First Year', 'Midyear', 'BSES 25', 'Inorganic Chemistry', 3, 2, 3, 6, 'NONE'),
(447, 2021, 'Bachelor of Secondary Education Major in Science', 'Second Year', 'First Semester', 'EDUC 75', 'Assessment in Learning 1', 3, 0, 3, 0, 'NONE'),
(448, 2021, 'Bachelor of Secondary Education Major in Science', 'Second Year', 'First Semester', 'EDUC 90', 'The Teacher and The School Curriculum', 3, 0, 3, 0, 'NONE'),
(449, 2021, 'Bachelor of Secondary Education Major in Science', 'Second Year', 'First Semester', 'BSES 21', 'Genetics', 3, 1, 3, 3, 'NONE'),
(450, 2021, 'Bachelor of Secondary Education Major in Science', 'Second Year', 'First Semester', 'BSES 26', 'Organic Chemistry', 3, 2, 3, 6, 'BSES 25'),
(451, 2021, 'Bachelor of Secondary Education Major in Science', 'Second Year', 'First Semester', 'BSES 29', 'Thermodynamics', 3, 1, 3, 3, 'HS Physics-Mechanics'),
(452, 2021, 'Bachelor of Secondary Education Major in Science', 'Second Year', 'First Semester', 'GNED 04', 'Mga Babasahin Hinggil sa Kasaysayan ng Pilipinas', 3, 0, 3, 0, 'NONE'),
(453, 2021, 'Bachelor of Secondary Education Major in Science', 'Second Year', 'First Semester', 'FITT 3', 'Physical Activities towards Health and Fitness I', 2, 0, 2, 0, 'FITT 1'),
(454, 2021, 'Bachelor of Secondary Education Major in Science', 'Second Year', 'Second Semester', 'EDUC 80', 'Assessment in Learning 2', 3, 0, 3, 0, 'NONE'),
(455, 2021, 'Bachelor of Secondary Education Major in Science', 'Second Year', 'Second Semester', 'EDUC 95', 'Building and Enhancing New Literacies Across the Curriculum', 3, 0, 3, 0, 'NONE'),
(456, 2021, 'Bachelor of Secondary Education Major in Science', 'Second Year', 'Second Semester', 'BSES 27', 'Biochemistry', 3, 0, 3, 0, 'BSES 26'),
(457, 2021, 'Bachelor of Secondary Education Major in Science', 'Second Year', 'Second Semester', 'BSES 31', 'Electricity and Magnetism', 3, 1, 3, 3, 'BSES 30'),
(458, 2021, 'Bachelor of Secondary Education Major in Science', 'Second Year', 'Second Semester', 'BSES 34', 'Earth Science', 3, 0, 3, 0, 'NONE'),
(459, 2021, 'Bachelor of Secondary Education Major in Science', 'Second Year', 'Second Semester', 'BSES 36', 'Environmental Science', 3, 0, 3, 0, 'NONE'),
(460, 2021, 'Bachelor of Secondary Education Major in Science', 'Second Year', 'Second Semester', 'GNED 15', 'World Literature', 3, 0, 3, 0, 'NONE'),
(461, 2021, 'Bachelor of Secondary Education Major in Science', 'Second Year', 'Second Semester', 'FITT 4', 'Physical Activities towards Health and Fitness II', 2, 0, 2, 0, 'FITT 1'),
(462, 2021, 'Bachelor of Secondary Education Major in Science', 'Second Year', 'Midyear', 'BSES 28', 'Analytical Chemistry', 3, 2, 3, 6, 'BSES 25'),
(463, 2021, 'Bachelor of Secondary Education Major in Science', 'Third Year', 'First Semester', 'EDFS 21', 'Field Study 1 - Observations of Teaching Learning in Actual School Environment', 3, 0, 3, 0, 'NONE'),
(464, 2021, 'Bachelor of Secondary Education Major in Science', 'Third Year', 'First Semester', 'BSES 22', 'Cell and Molecular Biology', 3, 1, 3, 3, 'BSES 21 & 27'),
(465, 2021, 'Bachelor of Secondary Education Major in Science', 'Third Year', 'First Semester', 'BSES 32', 'Waves and Optics', 3, 1, 3, 3, 'BSES 30 & 31'),
(466, 2021, 'Bachelor of Secondary Education Major in Science', 'Third Year', 'First Semester', 'BSES 38', 'Technology for Teaching and Learning 2 (for Science)', 3, 0, 3, 0, 'EDUC 75, 80, 85'),
(467, 2021, 'Bachelor of Secondary Education Major in Science', 'Third Year', 'First Semester', 'BSES 39', 'Research in Teaching', 3, 0, 3, 0, 'NONE'),
(468, 2021, 'Bachelor of Secondary Education Major in Science', 'Third Year', 'First Semester', 'BSES 40', 'Meteorology', 3, 0, 3, 0, 'NONE'),
(469, 2021, 'Bachelor of Secondary Education Major in Science', 'Third Year', 'First Semester', 'GNED 12', 'Dalumat Ng/Sa Filipino', 3, 0, 3, 0, 'GNED 11'),
(470, 2021, 'Bachelor of Secondary Education Major in Science', 'Third Year', 'Second Semester', 'EDFS 22', 'Field Study 2 - Participation and Teaching Assistantship', 3, 0, 3, 0, 'PROF Ed Courses'),
(471, 2021, 'Bachelor of Secondary Education Major in Science', 'Third Year', 'Second Semester', 'BSES 23', 'Microbiology and Parasitology', 3, 1, 3, 3, 'NONE'),
(472, 2021, 'Bachelor of Secondary Education Major in Science', 'Third Year', 'Second Semester', 'BSES 24', 'Anatomy and Physiology', 3, 1, 3, 3, 'NONE'),
(473, 2021, 'Bachelor of Secondary Education Major in Science', 'Third Year', 'Second Semester', 'BSES 33', 'Modern Physics', 3, 0, 3, 0, 'BSES 30 & 31'),
(474, 2021, 'Bachelor of Secondary Education Major in Science', 'Third Year', 'Second Semester', 'BSES 35', 'Astronomy', 3, 0, 3, 0, 'NONE'),
(475, 2021, 'Bachelor of Secondary Education Major in Science', 'Third Year', 'Second Semester', 'BSES 37', 'The Teaching of Science', 3, 0, 3, 0, 'NONE'),
(476, 2021, 'Bachelor of Secondary Education Major in Science', 'Third Year', 'Second Semester', 'EDUC 197', 'Competency Appraisal 1', 3, 0, 3, 0, 'PROF Ed'),
(477, 2021, 'Bachelor of Secondary Education Major in Science', 'Fourth Year', 'First Semester', 'EDFS 23', 'Teaching Internship', 6, 0, 0, 40, 'EDFS 21, EDFS 22'),
(478, 2021, 'Bachelor of Secondary Education Major in Science', 'Fourth Year', 'Second Semester', 'GNED 01', 'Art Appreciation', 3, 0, 3, 0, 'NONE'),
(479, 2021, 'Bachelor of Secondary Education Major in Science', 'Fourth Year', 'Second Semester', 'GNED 02', 'Ethics', 3, 0, 3, 0, 'NONE'),
(480, 2021, 'Bachelor of Secondary Education Major in Science', 'Fourth Year', 'Second Semester', 'GNED 07', 'The Contemporary World', 3, 0, 3, 0, 'NONE'),
(481, 2021, 'Bachelor of Secondary Education Major in Science', 'Fourth Year', 'Second Semester', 'GNED 09', 'Life and Works of Rizal', 3, 0, 3, 0, 'GNED 04'),
(482, 2021, 'Bachelor of Secondary Education Major in Science', 'Fourth Year', 'Second Semester', 'GNED 10', 'Gender and Society', 3, 0, 3, 0, 'NONE'),
(483, 2021, 'Bachelor of Secondary Education Major in Science', 'Fourth Year', 'Second Semester', 'GNED 13', 'Retorika/Masining na Pagpapahayag', 3, 0, 3, 0, 'GNED 11 & 12'),
(484, 2021, 'Bachelor of Secondary Education Major in Science', 'Fourth Year', 'Second Semester', 'EDUC 198', 'Competency Appraisal 2', 3, 0, 3, 0, 'EDUC 197'),
(485, 2021, 'Bachelor of Secondary Education Major in Mathematics', 'First Year', 'First Semester', 'GNED 03', 'Mathematics in the Modern World', 3, 0, 3, 0, 'NONE'),
(486, 2021, 'Bachelor of Secondary Education Major in Mathematics', 'First Year', 'First Semester', 'GNED 05', 'Purposive Communication', 3, 0, 3, 0, 'NONE'),
(487, 2021, 'Bachelor of Secondary Education Major in Mathematics', 'First Year', 'First Semester', 'GNED 11', 'Kontekstwalisadong Komunikasyon sa Filipino', 3, 0, 3, 0, 'NONE'),
(488, 2021, 'Bachelor of Secondary Education Major in Mathematics', 'First Year', 'First Semester', 'EDUC 50', 'Child and Adolescent Learner and Learning Principles', 3, 0, 3, 0, 'NONE'),
(489, 2021, 'Bachelor of Secondary Education Major in Mathematics', 'First Year', 'First Semester', 'EDUC 55', 'The Teaching Profession', 3, 0, 3, 0, 'NONE'),
(490, 2021, 'Bachelor of Secondary Education Major in Mathematics', 'First Year', 'First Semester', 'EDUC 60', 'The Teacher and The Community, School Culture and Organizational Leadership', 3, 0, 3, 0, 'NONE'),
(491, 2021, 'Bachelor of Secondary Education Major in Mathematics', 'First Year', 'First Semester', 'FITT 1', 'Movement Enhancement', 2, 0, 2, 0, 'NONE'),
(492, 2021, 'Bachelor of Secondary Education Major in Mathematics', 'First Year', 'First Semester', 'NSTP 1', 'CWTS/LTS/ROTC', 3, 0, 3, 0, 'NONE'),
(493, 2021, 'Bachelor of Secondary Education Major in Mathematics', 'First Year', 'First Semester', 'CVSU 101', 'Institutional Orientation 1', 1, 0, 1, 0, 'NONE'),
(494, 2021, 'Bachelor of Secondary Education Major in Mathematics', 'First Year', 'Second Semester', 'GNED 06', 'Science, Technology, and Society', 3, 0, 3, 0, 'NONE'),
(495, 2021, 'Bachelor of Secondary Education Major in Mathematics', 'First Year', 'Second Semester', 'GNED 08', 'Understanding the Self', 3, 0, 3, 0, 'NONE'),
(496, 2021, 'Bachelor of Secondary Education Major in Mathematics', 'First Year', 'Second Semester', 'GNED 14', 'Panitikang Panlipunan', 3, 0, 3, 0, 'NONE'),
(497, 2021, 'Bachelor of Secondary Education Major in Mathematics', 'First Year', 'Second Semester', 'EDUC 65', 'Foundation of Special and Inclusive Education', 3, 0, 3, 0, 'NONE'),
(498, 2021, 'Bachelor of Secondary Education Major in Mathematics', 'First Year', 'Second Semester', 'EDUC 70', 'Facilitating Learner-Centered Teaching', 3, 0, 3, 0, 'NONE'),
(499, 2021, 'Bachelor of Secondary Education Major in Mathematics', 'First Year', 'Second Semester', 'EDUC 85', 'Technology for Teaching and Learning 1', 3, 0, 3, 0, 'NONE'),
(500, 2021, 'Bachelor of Secondary Education Major in Mathematics', 'First Year', 'Second Semester', 'FITT 2', 'Fitness Exercises', 2, 0, 2, 0, 'FITT 1'),
(501, 2021, 'Bachelor of Secondary Education Major in Mathematics', 'First Year', 'Second Semester', 'NSTP 2', 'CWTS/LTS/ROTC', 3, 0, 3, 0, 'NSTP 1'),
(502, 2021, 'Bachelor of Secondary Education Major in Mathematics', 'First Year', 'Midyear', 'BSEM 21', 'History of Mathematics', 3, 0, 3, 0, 'NONE'),
(503, 2021, 'Bachelor of Secondary Education Major in Mathematics', 'First Year', 'Midyear', 'BSEM 22', 'College and Advanced Algebra', 3, 0, 3, 0, 'NONE'),
(504, 2021, 'Bachelor of Secondary Education Major in Mathematics', 'Second Year', 'First Semester', 'EDUC 75', 'Assessment in Learning 1', 3, 0, 3, 0, 'NONE'),
(505, 2021, 'Bachelor of Secondary Education Major in Mathematics', 'Second Year', 'First Semester', 'EDUC 90', 'The Teacher and The School Curriculum', 3, 0, 3, 0, 'NONE'),
(506, 2021, 'Bachelor of Secondary Education Major in Mathematics', 'Second Year', 'First Semester', 'BSEM 23', 'Trigonometry', 3, 0, 3, 0, 'BSEM 22'),
(507, 2021, 'Bachelor of Secondary Education Major in Mathematics', 'Second Year', 'First Semester', 'BSEM 24', 'Plane and Solid Geometry', 3, 0, 3, 0, 'BSEM 22'),
(508, 2021, 'Bachelor of Secondary Education Major in Mathematics', 'Second Year', 'First Semester', 'BSEM 25', 'Logic and Set Theory', 3, 0, 3, 0, 'NONE'),
(509, 2021, 'Bachelor of Secondary Education Major in Mathematics', 'Second Year', 'First Semester', 'BSEM 26', 'Elementary Statistics and Probability', 3, 0, 3, 0, 'NONE'),
(510, 2021, 'Bachelor of Secondary Education Major in Mathematics', 'Second Year', 'First Semester', 'GNED 04', 'Mga Babasahin Hinggil sa Kasaysayan ng Pilipinas', 3, 0, 3, 0, 'NONE'),
(511, 2021, 'Bachelor of Secondary Education Major in Mathematics', 'Second Year', 'First Semester', 'FITT 3', 'Physical Activities towards Health and Fitness I', 2, 0, 2, 0, 'FITT 1'),
(512, 2021, 'Bachelor of Secondary Education Major in Mathematics', 'Second Year', 'Second Semester', 'EDUC 80', 'Assessment in Learning 2', 3, 0, 3, 0, 'NONE'),
(513, 2021, 'Bachelor of Secondary Education Major in Mathematics', 'Second Year', 'Second Semester', 'EDUC 95', 'Building and Enhancing New Literacies Across the Curriculum', 3, 0, 3, 0, 'NONE'),
(514, 2021, 'Bachelor of Secondary Education Major in Mathematics', 'Second Year', 'Second Semester', 'BSEM 30', 'Modern Geometry', 3, 0, 3, 0, 'BSEM 24, BSEM 25'),
(515, 2021, 'Bachelor of Secondary Education Major in Mathematics', 'Second Year', 'Second Semester', 'BSEM 33', 'Linear Algebra', 3, 0, 3, 0, 'BSEM 25'),
(516, 2021, 'Bachelor of Secondary Education Major in Mathematics', 'Second Year', 'Second Semester', 'BSEM 34', 'Advanced Statistics', 3, 0, 3, 0, 'BSEM 26'),
(517, 2021, 'Bachelor of Secondary Education Major in Mathematics', 'Second Year', 'Second Semester', 'BSEM 36', 'Principles and Methods of Teaching Mathematics', 3, 0, 3, 0, 'NONE'),
(518, 2021, 'Bachelor of Secondary Education Major in Mathematics', 'Second Year', 'Second Semester', 'GNED 15', 'World Literature', 3, 0, 3, 0, 'NONE'),
(519, 2021, 'Bachelor of Secondary Education Major in Mathematics', 'Second Year', 'Second Semester', 'FITT 4', 'Physical Activities towards Health and Fitness II', 2, 0, 2, 0, 'FITT 1'),
(520, 2021, 'Bachelor of Secondary Education Major in Mathematics', 'Third Year', 'First Semester', 'EDFS 21', 'Field Study 1 - Observations of Teaching-Learning in Actual School Environment', 3, 0, 3, 0, 'PROF Ed Courses'),
(521, 2021, 'Bachelor of Secondary Education Major in Mathematics', 'Third Year', 'First Semester', 'BSEM 27', 'Calculus 1 with Analytic Geometry', 4, 0, 4, 0, 'BSEM 22, BSEM 23,BSEM 30'),
(522, 2021, 'Bachelor of Secondary Education Major in Mathematics', 'Third Year', 'First Semester', 'BSEM 32', 'Number Theory', 3, 0, 3, 0, 'BSEM 22, BSEM 25'),
(523, 2021, 'Bachelor of Secondary Education Major in Mathematics', 'Third Year', 'First Semester', 'BSEM 35', 'Problem-Solving, Mathematical Investigations and Modelling', 3, 0, 3, 0, 'BSEM 22, BSEM 25, BSEM 30'),
(524, 2021, 'Bachelor of Secondary Education Major in Mathematics', 'Third Year', 'First Semester', 'BSEM 38', 'Research in Mathematics', 4, 0, 4, 0, 'BSEM 34'),
(525, 2021, 'Bachelor of Secondary Education Major in Mathematics', 'Third Year', 'First Semester', 'BSEM 39', 'Technology for Teaching and Learning 2 (Instrumentation & Technology in Mathematics)', 3, 0, 3, 0, 'EDUC 75, EDUC 80, EDUC 85'),
(526, 2021, 'Bachelor of Secondary Education Major in Mathematics', 'Third Year', 'First Semester', 'GNED 12', 'Dalumat Ng/Sa Filipino', 3, 0, 3, 0, 'GNED 11'),
(527, 2021, 'Bachelor of Secondary Education Major in Mathematics', 'Third Year', 'Second Semester', 'EDFS 22', 'Field Study 2 - Participation and Teaching Assistantship', 3, 0, 3, 0, 'EDFS 21'),
(528, 2021, 'Bachelor of Secondary Education Major in Mathematics', 'Third Year', 'Second Semester', 'BSEM 28', 'Calculus 2', 4, 0, 4, 0, 'BSEM 27'),
(529, 2021, 'Bachelor of Secondary Education Major in Mathematics', 'Third Year', 'Second Semester', 'BSEM 31', 'Mathematics of Investment', 3, 0, 3, 0, 'BSEM 22'),
(530, 2021, 'Bachelor of Secondary Education Major in Mathematics', 'Third Year', 'Second Semester', 'BSEM 37', 'Abstract Algebra', 3, 0, 3, 0, 'BSEM 25'),
(531, 2021, 'Bachelor of Secondary Education Major in Mathematics', 'Third Year', 'Second Semester', 'BSEM 40', 'Assessment and Evaluation in Mathematics', 3, 0, 3, 0, 'BSEM 34'),
(532, 2021, 'Bachelor of Secondary Education Major in Mathematics', 'Third Year', 'Second Semester', 'EDUC 197', 'Competency Appraisal 1', 3, 0, 3, 0, 'PROF Ed Courses'),
(533, 2021, 'Bachelor of Secondary Education Major in Mathematics', 'Third Year', 'Midyear', 'BSEM 29', 'Calculus 3', 3, 0, 3, 0, 'BSEM 28'),
(534, 2021, 'Bachelor of Secondary Education Major in Mathematics', 'Fourth Year', 'First Semester', 'EDFS 23', 'Teaching Internship', 6, 0, 0, 40, 'EDFS 21, EDFS 22'),
(535, 2021, 'Bachelor of Secondary Education Major in Mathematics', 'Fourth Year', 'Second Semester', 'GNED 01', 'Art Appreciation', 3, 0, 3, 0, 'NONE'),
(536, 2021, 'Bachelor of Secondary Education Major in Mathematics', 'Fourth Year', 'Second Semester', 'GNED 02', 'Ethics', 3, 0, 3, 0, 'NONE'),
(537, 2021, 'Bachelor of Secondary Education Major in Mathematics', 'Fourth Year', 'Second Semester', 'GNED 07', 'Contemporary World', 3, 0, 3, 0, 'NONE'),
(538, 2021, 'Bachelor of Secondary Education Major in Mathematics', 'Fourth Year', 'Second Semester', 'GNED 09', 'Life and Works of Rizal', 3, 0, 3, 0, 'GNED 04'),
(539, 2021, 'Bachelor of Secondary Education Major in Mathematics', 'Fourth Year', 'Second Semester', 'GNED 10', 'Gender and Society', 3, 0, 3, 0, 'NONE'),
(540, 2021, 'Bachelor of Secondary Education Major in Mathematics', 'Fourth Year', 'Second Semester', 'GNED 13', 'Retorika/Masining na Pagpapahayag', 3, 0, 3, 0, 'GNED 11, GNED 12'),
(541, 2021, 'Bachelor of Secondary Education Major in Mathematics', 'Fourth Year', 'Second Semester', 'EDUC 198', 'Competency Appraisal 2', 3, 0, 3, 0, 'EDUC 197'),
(741, 2018, 'Bachelor of Science in Computer Science', 'First Year', 'First Semester', 'GNED 02', 'Ethics', 3, 0, 3, 0, 'NONE'),
(742, 2018, 'Bachelor of Science in Computer Science', 'First Year', 'First Semester', 'GNED 05', 'Purposive Communication', 3, 0, 3, 0, 'NONE'),
(743, 2018, 'Bachelor of Science in Computer Science', 'First Year', 'First Semester', 'GNED 11', 'Kontekstwalisadong Komunikasyon sa Filipino', 3, 0, 3, 0, 'NONE'),
(744, 2018, 'Bachelor of Science in Computer Science', 'First Year', 'First Semester', 'COSC 50', 'Discrete Structures I', 3, 0, 3, 0, 'NONE'),
(745, 2018, 'Bachelor of Science in Computer Science', 'First Year', 'First Semester', 'DCIT 21', 'Introduction to Computing', 2, 1, 2, 3, 'NONE'),
(746, 2018, 'Bachelor of Science in Computer Science', 'First Year', 'First Semester', 'DCIT 22', 'Computer Programming I', 1, 2, 1, 6, 'NONE'),
(747, 2018, 'Bachelor of Science in Computer Science', 'First Year', 'First Semester', 'FITT 1', 'Movement Enhancement', 2, 0, 2, 0, 'NONE'),
(748, 2018, 'Bachelor of Science in Computer Science', 'First Year', 'First Semester', 'NSTP 1', 'National Service Training Program 1', 3, 0, 3, 0, 'NONE'),
(749, 2018, 'Bachelor of Science in Computer Science', 'First Year', 'First Semester', 'CVSU 101', 'Institutional Orientation (non-credit)', 1, 0, 1, 0, 'NONE'),
(750, 2018, 'Bachelor of Science in Computer Science', 'First Year', 'Second Semester', 'GNED 01', 'Art Appreciation', 3, 0, 3, 0, 'NONE'),
(751, 2018, 'Bachelor of Science in Computer Science', 'First Year', 'Second Semester', 'GNED 03', 'Mathematics in the Modern World', 3, 0, 3, 0, 'NONE'),
(752, 2018, 'Bachelor of Science in Computer Science', 'First Year', 'Second Semester', 'GNED 06', 'Science, Technology and Society', 3, 0, 3, 0, 'NONE'),
(753, 2018, 'Bachelor of Science in Computer Science', 'First Year', 'Second Semester', 'GNED 12', 'Dalumat Ng/Sa Filipino', 3, 0, 3, 0, 'GNED 11'),
(754, 2018, 'Bachelor of Science in Computer Science', 'First Year', 'Second Semester', 'DCIT 23', 'Computer Programming II', 1, 2, 1, 6, 'DCIT 22'),
(755, 2018, 'Bachelor of Science in Computer Science', 'First Year', 'Second Semester', 'ITEC 50', 'Web Systems and Technologies', 2, 1, 2, 3, 'DCIT 21'),
(756, 2018, 'Bachelor of Science in Computer Science', 'First Year', 'Second Semester', 'FITT 2', 'Fitness Exercises', 2, 0, 2, 0, 'FITT 1'),
(757, 2018, 'Bachelor of Science in Computer Science', 'First Year', 'Second Semester', 'NSTP 2', 'National Service Training Program 2', 3, 0, 3, 0, 'NSTP 1'),
(758, 2018, 'Bachelor of Science in Computer Science', 'Second Year', 'First Semester', 'GNED 04', 'Mga Babasahin Hinggil sa Kasaysayan ng Pilipinas', 3, 0, 3, 0, 'NONE'),
(759, 2018, 'Bachelor of Science in Computer Science', 'Second Year', 'First Semester', 'MATH 1', 'Analytic Geometry', 3, 0, 3, 0, 'GNED 03'),
(760, 2018, 'Bachelor of Science in Computer Science', 'Second Year', 'First Semester', 'COSC 55', 'Discrete Structures II', 3, 0, 3, 0, 'COSC 50'),
(761, 2018, 'Bachelor of Science in Computer Science', 'Second Year', 'First Semester', 'COSC 60', 'Digital Logic Design', 2, 1, 2, 3, 'COSC 50, DCIT 23'),
(762, 2018, 'Bachelor of Science in Computer Science', 'Second Year', 'First Semester', 'DCIT 50', 'Object Oriented Programming', 2, 1, 2, 3, 'DCIT 23'),
(763, 2018, 'Bachelor of Science in Computer Science', 'Second Year', 'First Semester', 'DCIT 24', 'Information Management', 2, 1, 2, 3, 'DCIT 23'),
(764, 2018, 'Bachelor of Science in Computer Science', 'Second Year', 'First Semester', 'INSY 50', 'Fundamentals of Information Systems', 3, 0, 3, 0, 'DCIT 21'),
(765, 2018, 'Bachelor of Science in Computer Science', 'Second Year', 'First Semester', 'FITT 3', 'Physical Activities towards Health and Fitness 1', 2, 0, 2, 0, 'FITT 1'),
(766, 2018, 'Bachelor of Science in Computer Science', 'Second Year', 'Second Semester', 'GNED 08', 'Understanding the Self', 3, 0, 3, 0, 'NONE'),
(767, 2018, 'Bachelor of Science in Computer Science', 'Second Year', 'Second Semester', 'GNED 14', 'Panitikang Panlipunan', 3, 0, 3, 0, 'NONE'),
(768, 2018, 'Bachelor of Science in Computer Science', 'Second Year', 'Second Semester', 'MATH 2', 'Calculus', 3, 0, 3, 0, 'MATH 1'),
(769, 2018, 'Bachelor of Science in Computer Science', 'Second Year', 'Second Semester', 'COSC 65', 'Architecture and Organization', 2, 1, 2, 3, 'COSC 60'),
(770, 2018, 'Bachelor of Science in Computer Science', 'Second Year', 'Second Semester', 'COSC 70', 'Software Engineering I', 3, 0, 3, 0, 'DCIT 50, DCIT 24'),
(771, 2018, 'Bachelor of Science in Computer Science', 'Second Year', 'Second Semester', 'DCIT 25', 'Data Structures and Algorithms', 2, 1, 2, 3, 'DCIT 23'),
(772, 2018, 'Bachelor of Science in Computer Science', 'Second Year', 'Second Semester', 'DCIT 55', 'Advanced Database Management System', 2, 1, 2, 3, 'DCIT 24'),
(773, 2018, 'Bachelor of Science in Computer Science', 'Second Year', 'Second Semester', 'FITT 4', 'Physical Activities towards Health and Fitness 2', 2, 0, 2, 0, 'FITT 1'),
(774, 2018, 'Bachelor of Science in Computer Science', 'Third Year', 'First Semester', 'MATH 3', 'Linear Algebra', 3, 0, 3, 0, 'MATH 2'),
(775, 2018, 'Bachelor of Science in Computer Science', 'Third Year', 'First Semester', 'COSC 75', 'Software Engineering II', 2, 1, 2, 3, 'COSC 70'),
(776, 2018, 'Bachelor of Science in Computer Science', 'Third Year', 'First Semester', 'COSC 80', 'Operating Systems', 2, 1, 2, 3, 'DCIT 25'),
(777, 2018, 'Bachelor of Science in Computer Science', 'Third Year', 'First Semester', 'COSC 85', 'Networks and Communication', 2, 1, 2, 3, 'ITEC 50'),
(778, 2018, 'Bachelor of Science in Computer Science', 'Third Year', 'First Semester', 'COSC 101', 'CS Elective 1 (Computer Graphics and Visual Computing)', 2, 1, 2, 3, 'DCIT 23'),
(779, 2018, 'Bachelor of Science in Computer Science', 'Third Year', 'First Semester', 'DCIT 26', 'Applications Development and Emerging Technologies', 2, 1, 2, 3, 'ITEC 50'),
(780, 2018, 'Bachelor of Science in Computer Science', 'Third Year', 'First Semester', 'DCIT 65', 'Social and Professional Issues', 3, 0, 3, 0, 'NONE'),
(781, 2018, 'Bachelor of Science in Computer Science', 'Third Year', 'Second Semester', 'GNED 09', 'Life and Works of Rizal', 3, 0, 3, 0, 'GNED 04'),
(782, 2018, 'Bachelor of Science in Computer Science', 'Third Year', 'Second Semester', 'MATH 4', 'Experimental Statistics', 2, 1, 2, 3, 'MATH 2'),
(783, 2018, 'Bachelor of Science in Computer Science', 'Third Year', 'Second Semester', 'COSC 90', 'Design and Analysis of Algorithm', 3, 0, 3, 0, 'DCIT 25'),
(784, 2018, 'Bachelor of Science in Computer Science', 'Third Year', 'Second Semester', 'COSC 95', 'Programming Languages', 3, 0, 3, 0, 'DCIT 25'),
(785, 2018, 'Bachelor of Science in Computer Science', 'Third Year', 'Second Semester', 'COSC 106', 'CS Elective 2 (Introduction to Game Development)', 2, 1, 2, 3, 'MATH 3, COSC 101'),
(786, 2018, 'Bachelor of Science in Computer Science', 'Third Year', 'Second Semester', 'DCIT 60', 'Methods of Research', 3, 0, 3, 0, '3rd Year Standing'),
(787, 2018, 'Bachelor of Science in Computer Science', 'Third Year', 'Second Semester', 'ITEC 85', 'Information Assurance and Security', 3, 0, 3, 0, 'DCIT 24'),
(788, 2018, 'Bachelor of Science in Computer Science', 'Third Year', 'Mid Year', 'COSC 199', 'Practicum (minimum of 240 hrs.)', 3, 0, 0, 0, 'Incoming 4th yr.'),
(789, 2018, 'Bachelor of Science in Computer Science', 'Fourth Year', 'First Semester', 'ITEC 80', 'Human Computer Interaction', 3, 0, 3, 0, 'ITEC 85'),
(790, 2018, 'Bachelor of Science in Computer Science', 'Fourth Year', 'First Semester', 'COSC 100', 'Automata Theory and Formal Languages', 3, 0, 3, 0, 'COSC 90'),
(791, 2018, 'Bachelor of Science in Computer Science', 'Fourth Year', 'First Semester', 'COSC 105', 'Intelligent Systems', 2, 1, 2, 3, 'MATH 4, COSC 55, DCIT 50'),
(792, 2018, 'Bachelor of Science in Computer Science', 'Fourth Year', 'First Semester', 'COSC 111', 'CS Elective 3 (Internet of Things)', 2, 1, 2, 3, 'COSC 60'),
(793, 2018, 'Bachelor of Science in Computer Science', 'Fourth Year', 'First Semester', 'COSC 200A', 'Undergraduate Thesis I', 3, 0, 3, 0, '4th Year Standing'),
(794, 2018, 'Bachelor of Science in Computer Science', 'Fourth Year', 'Second Semester', 'GNED 07', 'The Contemporary World', 3, 0, 3, 0, 'NONE'),
(795, 2018, 'Bachelor of Science in Computer Science', 'Fourth Year', 'Second Semester', 'GNED 10', 'Gender and Society', 3, 0, 3, 0, 'NONE'),
(796, 2018, 'Bachelor of Science in Computer Science', 'Fourth Year', 'Second Semester', 'COSC 110', 'Numerical and Symbolic Computation', 2, 1, 2, 3, 'COSC 60'),
(797, 2018, 'Bachelor of Science in Computer Science', 'Fourth Year', 'Second Semester', 'COSC 200B', 'Undergraduate Thesis II', 3, 0, 3, 0, 'COSC 200A');

-- --------------------------------------------------------

--
-- Table structure for table `curriculum_feedback`
--

CREATE TABLE `curriculum_feedback` (
  `feedback_id` int(11) NOT NULL,
  `student_number` int(11) DEFAULT NULL,
  `relevance_level` varchar(255) DEFAULT NULL,
  `useful_competencies` text DEFAULT NULL,
  `suggestions` text DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `cvsucarmona_courses`
--

CREATE TABLE `cvsucarmona_courses` (
  `curriculumyear_coursecode` varchar(50) NOT NULL,
  `programs` text NOT NULL,
  `course_title` varchar(255) NOT NULL,
  `year_level` varchar(50) NOT NULL,
  `semester` varchar(50) NOT NULL,
  `credit_units_lec` int(2) DEFAULT 0,
  `credit_units_lab` int(2) DEFAULT 0,
  `lect_hrs_lec` int(2) DEFAULT 0,
  `lect_hrs_lab` int(2) DEFAULT 0,
  `pre_requisite` varchar(255) DEFAULT 'NONE'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `cvsucarmona_courses`
--

INSERT INTO `cvsucarmona_courses` (`curriculumyear_coursecode`, `programs`, `course_title`, `year_level`, `semester`, `credit_units_lec`, `credit_units_lab`, `lect_hrs_lec`, `lect_hrs_lab`, `pre_requisite`) VALUES
('2017_ALAN 1', 'BSHM', 'Asian Language 1', 'Second Year', 'First Semester', 3, 0, 3, 0, 'NONE'),
('2017_ALAN 2', 'BSHM', 'Asian Language 2', 'Third Year', 'First Semester', 3, 0, 3, 0, 'ALAN 1'),
('2017_BSHM 100', 'BSHM', 'Tourism and Hospitality Service Quality Management', 'Fourth Year', 'First Semester', 3, 0, 3, 0, 'BSHM 80'),
('2017_BSHM 101', 'BSHM', 'Food and Beverage Service', 'First Year', 'Second Semester', 2, 1, 2, 3, 'BSHM 55'),
('2017_BSHM 111', 'BSHM', 'Front Office Operation', 'Second Year', 'First Semester', 2, 1, 2, 3, 'BSHM 21'),
('2017_BSHM 121', 'BSHM', 'Sustainable Hospitality Management', 'Third Year', 'First Semester', 3, 0, 3, 0, 'NONE'),
('2017_BSHM 131', 'BSHM', 'Bread and Pastry Production', 'Third Year', 'Second Semester', 1, 2, 1, 6, 'BSHM 55, BSHM 22'),
('2017_BSHM 141', 'BSHM', 'Cost Control', 'Second Year', 'First Semester', 2, 1, 2, 3, 'GNED 03'),
('2017_BSHM 151', 'BSHM', 'Strategic management in Tourism and Hospitality', 'Fourth Year', 'First Semester', 3, 0, 3, 0, 'NONE'),
('2017_BSHM 161', 'BSHM', 'Operations Management in Tourism and Hospitality Industry', 'Fourth Year', 'First Semester', 3, 0, 3, 0, 'BSHM 23'),
('2017_BSHM 199-A', 'BSHM', 'Hospitality Practicum 1-Housekeeping and Food and Beverage', 'Second Year', 'Second Semester', 0, 3, 0, 300, 'All 1st and 2nd year'),
('2017_BSHM 199-B', 'BSHM', 'PRACTICUM 2- Hotel Operations', 'Fourth Year', 'Second Semester', 0, 6, 0, 600, 'All Subjects'),
('2017_BSHM 21', 'BSHM', 'Fundamentals in Lodging Operations', 'First Year', 'First Semester', 3, 0, 3, 0, 'NONE'),
('2017_BSHM 22', 'BSHM', 'Kitchen Essentials & Basic Food Preparation', 'First Year', 'Second Semester', 2, 1, 2, 3, 'BSHM 55'),
('2017_BSHM 23', 'BSHM', 'Applied Business Tools and Technologies (GDS) with Lab', 'Second Year', 'First Semester', 2, 1, 2, 3, 'NONE'),
('2017_BSHM 24', 'BSHM', 'Supply Chain/Logistics Purchasing Management', 'Second Year', 'First Semester', 3, 0, 3, 0, 'NONE'),
('2017_BSHM 26', 'BSHM', 'Bar and Beverage Management with Lab', 'Second Year', 'First Semester', 2, 1, 2, 3, 'BSHM 101'),
('2017_BSHM 27', 'BSHM', 'Ergonomics & Facilities Planning for the Hospitality Industry', 'Third Year', 'Second Semester', 2, 1, 2, 3, 'NONE'),
('2017_BSHM 28', 'BSHM', 'Research in Hospitality', 'Fourth Year', 'First Semester', 2, 1, 2, 3, 'NONE'),
('2017_BSHM 29', 'BSHM', 'Introduction to MICE as applied in Hospitality', 'Third Year', 'First Semester', 2, 1, 2, 3, 'BSHM 55'),
('2017_BSHM 50', 'BSHM', 'Macro Perspective of Tourism & Hospitality', 'First Year', 'First Semester', 3, 1, 2, 3, 'NONE'),
('2017_BSHM 55', 'BSHM', 'Risk Management as Applied to Safety, Security and Sanitation', 'First Year', 'First Semester', 3, 0, 3, 0, 'NONE'),
('2017_BSHM 60', 'BSHM', 'Philippine Tourism, Geography and Culture', 'First Year', 'Second Semester', 3, 0, 3, 0, 'BSHM 50'),
('2017_BSHM 65', 'BSHM', 'Micro Perspective of Tourism and Hospitality', 'First Year', 'Second Semester', 3, 0, 3, 0, 'BSHM 50'),
('2017_BSHM 70', 'BSHM', 'Professional Development & Applied Ethics', 'Third Year', 'First Semester', 3, 0, 3, 0, 'GNED 02'),
('2017_BSHM 75', 'BSHM', 'Tourism and Hospitality Marketing', 'Third Year', 'First Semester', 3, 0, 3, 0, 'BSHM 23'),
('2017_BSHM 80', 'BSHM', 'Legal Aspect in Tourism and Hospitality', 'Third Year', 'Second Semester', 3, 0, 3, 0, 'BSHM 121'),
('2017_BSHM 85', 'BSHM', 'Multicultural Diversity in Workplace for the Tourism and Professional', 'Third Year', 'Second Semester', 3, 0, 3, 0, 'BSHM 70'),
('2017_BSHM 90', 'BSHM', 'Enterpreneurship in Tourism and Hospitality', 'Third Year', 'Second Semester', 3, 0, 3, 0, 'NONE'),
('2017_CVSU 101', 'BSHM', 'Institutional Orientation', 'First Year', 'First Semester', 1, 0, 1, 0, 'NONE'),
('2017_FITT 1', 'BSHM', 'Movement Enhancement', 'First Year', 'First Semester', 2, 0, 2, 0, 'NONE'),
('2017_FITT 2', 'BSHM', 'Fitness Exercises', 'First Year', 'Second Semester', 2, 0, 2, 0, 'FITT 1'),
('2017_FITT 3', 'BSHM', 'Physical Activities toward Health and Fitness 1', 'Second Year', 'First Semester', 2, 0, 2, 0, 'FITT 1'),
('2017_FITT 4', 'BSHM', 'Physical Activities toward Health and Fitness 2', 'Second Year', 'Second Semester', 2, 0, 2, 0, 'NONE'),
('2017_GNED 01', 'BSHM', 'Arts Appreciation', 'First Year', 'First Semester', 3, 0, 3, 0, 'NONE'),
('2017_GNED 02', 'BSHM', 'Ethics', 'First Year', 'First Semester', 3, 0, 3, 0, 'NONE'),
('2017_GNED 03', 'BSHM', 'Mathematics in Modern World', 'First Year', 'Second Semester', 3, 0, 3, 0, 'NONE'),
('2017_GNED 04', 'BSHM', 'Mga Babasahin hinggil sa kasaysayan ng Pilipinas', 'Third Year', 'First Semester', 3, 0, 3, 0, 'NONE'),
('2017_GNED 05', 'BSHM', 'Purposive Communication', 'First Year', 'Second Semester', 3, 0, 3, 0, 'NONE'),
('2017_GNED 06', 'BSHM', 'Science, Technology World', 'Third Year', 'Second Semester', 3, 0, 3, 0, 'NONE'),
('2017_GNED 07', 'BSHM', 'The Contemporary World', 'Fourth Year', 'First Semester', 3, 0, 3, 0, 'NONE'),
('2017_GNED 08', 'BSHM', 'Understanding the Self', 'First Year', 'First Semester', 3, 0, 3, 0, 'NONE'),
('2017_GNED 09', 'BSHM', 'Life and Works of Rizal', 'Fourth Year', 'First Semester', 3, 0, 3, 0, 'GNED 04'),
('2017_GNED 10', 'BSHM', 'Gender and Society', 'Fourth Year', 'First Semester', 3, 0, 3, 0, 'NONE'),
('2017_GNED 11', 'BSHM', 'Kontekstwalisadong komunikasyon sa Filipino', 'Third Year', 'First Semester', 3, 0, 3, 0, 'NONE'),
('2017_GNED 12', 'BSHM', 'Dalumat Ng/Sa Filipino', 'Third Year', 'Second Semester', 3, 0, 3, 0, 'GNED 11'),
('2017_GNED 14', 'BSHM', 'Panitikang Panlipunan', 'Second Year', 'First Semester', 3, 0, 3, 0, 'NONE'),
('2017_NSTP 1', 'BSHM', 'CWTS/LTS/ROTC', 'First Year', 'First Semester', 3, 0, 3, 0, 'NONE'),
('2017_NSTP 2', 'BSHM', 'CWTS/LTS/ROTC', 'First Year', 'Second Semester', 3, 0, 3, 0, 'NSTP 1'),
('2018_ALAN 21', 'BSIndT', 'Foreign Language', 'Third Year', 'First Semester', 3, 0, 3, 0, 'NONE'),
('2018_CHEM 14', 'BSCpE', 'Chemistry for Engineers', 'First Year', 'First Semester', 3, 1, 3, 3, 'NONE'),
('2018_COSC 100', 'BSCS', 'Automata Theory and Formal Languages', 'Fourth Year', 'First Semester', 3, 0, 3, 0, 'COSC 90'),
('2018_COSC 101', 'BSCS', 'CS Elective 1 (Computer Graphics and Visual Computing)', 'Third Year', 'First Semester', 2, 1, 2, 3, 'DCIT 23'),
('2018_COSC 105', 'BSCS', 'Intelligent Systems', 'Fourth Year', 'First Semester', 2, 1, 2, 3, 'MATH 4, COSC 55, DCIT 50'),
('2018_COSC 106', 'BSCS', 'CS Elective 2 (Introduction to Game Development)', 'Third Year', 'Second Semester', 2, 1, 2, 3, 'MATH 3, COSC 101'),
('2018_COSC 110', 'BSCS', 'Numerical and Symbolic Computation', 'Fourth Year', 'Second Semester', 2, 1, 2, 3, 'COSC 60'),
('2018_COSC 111', 'BSCS', 'CS Elective 3 (Internet of Things)', 'Fourth Year', 'First Semester', 2, 1, 2, 3, 'COSC 60'),
('2018_COSC 199', 'BSCS', 'Practicum (minimum of 240 hrs.)', 'Third Year', 'Mid Year', 3, 0, 0, 0, 'Incoming 4th yr.'),
('2018_COSC 200A', 'BSCS', 'Undergraduate Thesis I', 'Fourth Year', 'First Semester', 3, 0, 3, 0, '4th Year Standing'),
('2018_COSC 200B', 'BSCS', 'Undergraduate Thesis II', 'Fourth Year', 'Second Semester', 3, 0, 3, 0, 'COSC 200A'),
('2018_COSC 50 CS', 'BSCS', 'Discrete Structures I', 'First Year', 'First Semester', 3, 0, 3, 0, 'NONE'),
('2018_COSC 50 IT', 'BSIT', 'Discrete Structure', 'First Year', 'First Semester', 3, 0, 3, 0, 'NONE'),
('2018_COSC 55', 'BSCS', 'Discrete Structures II', 'Second Year', 'First Semester', 3, 0, 3, 0, 'COSC 50'),
('2018_COSC 60', 'BSCS', 'Digital Logic Design', 'Second Year', 'First Semester', 2, 1, 2, 3, 'COSC 50, DCIT 23'),
('2018_COSC 65', 'BSCS', 'Architecture and Organization', 'Second Year', 'Second Semester', 2, 1, 2, 3, 'COSC 60'),
('2018_COSC 70', 'BSCS', 'Software Engineering I', 'Second Year', 'Second Semester', 3, 0, 3, 0, 'DCIT 50, DCIT 24'),
('2018_COSC 75', 'BSCS', 'Software Engineering II', 'Third Year', 'First Semester', 2, 1, 2, 3, 'COSC 70'),
('2018_COSC 80', 'BSCS', 'Operating Systems', 'Third Year', 'First Semester', 2, 1, 2, 3, 'DCIT 25'),
('2018_COSC 85', 'BSCS', 'Networks and Communication', 'Third Year', 'First Semester', 2, 1, 2, 3, 'ITEC 50'),
('2018_COSC 90', 'BSCS', 'Design and Analysis of Algorithm', 'Third Year', 'Second Semester', 3, 0, 3, 0, 'DCIT 25'),
('2018_COSC 95', 'BSCS', 'Programming Languages', 'Third Year', 'Second Semester', 3, 0, 3, 0, 'DCIT 25'),
('2018_CPEN 100', 'BSCpE', 'Microprocessors and Microcontrollers Systems', 'Third Year', 'Second Semester', 3, 1, 3, 3, 'CPEN 75'),
('2018_CPEN 101', 'BSCpE', 'Elective Course #1', 'Third Year', 'First Semester', 3, 0, 3, 0, '3rd year standing'),
('2018_CPEN 105', 'BSCpE', 'Computer Networks and Security', 'Third Year', 'Second Semester', 3, 1, 3, 3, 'CPEN 85'),
('2018_CPEN 106', 'BSCpE', 'Elective Course #2', 'Third Year', 'Second Semester', 3, 0, 3, 0, 'CPEN 101'),
('2018_CPEN 110', 'BSCpE', 'CpE Laws and Professional Practice', 'Third Year', 'Second Semester', 2, 0, 2, 0, '3rd year standing'),
('2018_CPEN 111', 'BSCpE', 'Elective Course #3', 'Fourth Year', 'First Semester', 3, 0, 3, 0, 'CPEN 106'),
('2018_CPEN 115', 'BSCpE', 'Introduction to HDL', 'Third Year', 'Second Semester', 0, 1, 0, 3, 'CPEN 101,ECEN 60'),
('2018_CPEN 120', 'BSCpE', 'Embedded Systems', 'Fourth Year', 'First Semester', 3, 1, 3, 3, 'CPEN 100'),
('2018_CPEN 125', 'BSCpE', 'Computer Architecture and Organization', 'Fourth Year', 'First Semester', 3, 1, 3, 3, 'CPEN 100'),
('2018_CPEN 130', 'BSCpE', 'Emerging Technologies in CpE', 'Fourth Year', 'First Semester', 3, 0, 3, 0, '4th year standing'),
('2018_CPEN 135', 'BSCpE', 'Digital Signal Processing', 'Fourth Year', 'First Semester', 3, 1, 0, 3, 'DCEE 23'),
('2018_CPEN 140', 'BSCpE', 'Extreme Project Training', 'Fourth Year', 'Second Semester', 1, 0, 1, 0, 'Graduating Only'),
('2018_CPEN 190', 'BSCpE', 'Seminars and Fieldtrips', 'Fourth Year', 'Second Semester', 0, 1, 0, 3, '4th Year Standing'),
('2018_CPEN 199', 'BSCpE', 'On-the-Job Training/CpE Practice (240 Hrs)', 'Third Year', 'Summer', 3, 0, 0, 240, '3rd Year Stading'),
('2018_CPEN 200a', 'BSCpE', 'CpE Design Project 1', 'Fourth Year', 'First Semester', 0, 1, 0, 3, 'CPEN 100, DCEE 25'),
('2018_CPEN 200b', 'BSCpE', 'CpE Design Project 2', 'Fourth Year', 'Second Semester', 0, 2, 0, 6, 'CPEN 200a'),
('2018_CpEN 21', 'BSIndT', 'Programming Logic and Design', 'First Year', 'Second Semester', 0, 2, 0, 6, 'NONE'),
('2018_CPEN 21 CpE', 'BSCpE', 'Programming Logic and Design', 'First Year', 'First Semester', 0, 2, 0, 0, 'NONE'),
('2018_CPEN 50', 'BSCpE', 'Computer Engineering as Discipline', 'First Year', 'First Semester', 1, 0, 1, 0, 'NONE'),
('2018_CPEN 60', 'BSCpE', 'Object Oriented Programming', 'First Year', 'Second Semester', 0, 2, 0, 6, 'CPEN 21'),
('2018_CPEN 65', 'BSCpE', 'Data Structures and Algorithms', 'Second Year', 'First Semester', 0, 2, 0, 6, 'CPEN 60'),
('2018_CPEN 70', 'BSCpE', 'Fundamentals of Information Systems', 'Second Year', 'Second Semester', 0, 1, 0, 3, 'CPEN 65'),
('2018_CPEN 75', 'BSCpE', 'Logic Circuits and Design', 'Third Year', 'First Semester', 3, 1, 3, 3, 'ECEN 60'),
('2018_CPEN 80', 'BSCpE', 'Data and Digital Communications', 'Third Year', 'First Semester', 3, 0, 3, 0, 'ECEN 60'),
('2018_CPEN 85', 'BSCpE', 'Microprocessors of Network Routing and Sensors', 'Third Year', 'First Semester', 2, 1, 2, 3, 'ECEN 60'),
('2018_CPEN 90', 'BSCpE', 'Fundamentals of Engineering Drafting and Design', 'Third Year', 'First Semester', 0, 1, 0, 3, 'ECEN 60'),
('2018_CPEN 95', 'BSCpE', 'Operating Systems', 'Third Year', 'Second Semester', 2, 1, 2, 3, 'CPEN 65'),
('2018_CVSU 101 CpE-IndT-IT', 'BSCpE, BSIndT, BSIT', 'Institutional Orientation', 'First Year', 'First Semester', 1, 0, 1, 0, 'NONE'),
('2018_CVSU 101 CS', 'BSCS', 'Institutional Orientation (non-credit)', 'First Year', 'First Semester', 1, 0, 1, 0, 'NONE'),
('2018_DCEE 21', 'BSCpE', 'Discrete Mathematics', 'First Year', 'Second Semester', 3, 0, 3, 0, 'MATH 11'),
('2018_DCEE 23', 'BSCpE', 'Numerical Methods and Analysis', 'Second Year', 'Second Semester', 2, 1, 2, 3, 'MATH 13'),
('2018_DCEE 24', 'BSCpE', 'Feedback and Control Systems', 'Third Year', 'First Semester', 3, 1, 3, 3, 'ECEN 60,DCEE 23'),
('2018_DCEE 25', 'BSCpE', 'Basic Occupational Health and Safety (BOSH)', 'Third Year', 'First Semester', 3, 0, 3, 0, '3rd year standing, EENG 50'),
('2018_DCEE 26', 'BSCpE', 'Methods of Research', 'Third Year', 'Second Semester', 2, 0, 2, 0, 'CPEN 75, GNED 05, MATH 14'),
('2018_DCIT 21', 'BSIT, BSCS', 'Introduction to Computing', 'First Year', 'First Semester', 2, 1, 2, 3, 'NONE'),
('2018_DCIT 22 CS', 'BSCS', 'Computer Programming I', 'First Year', 'First Semester', 1, 2, 1, 6, 'NONE'),
('2018_DCIT 22 IT', 'BSIT', 'Computer Programming 1', 'First Year', 'First Semester', 1, 2, 1, 6, 'NONE'),
('2018_DCIT 23 CS', 'BSCS', 'Computer Programming II', 'First Year', 'Second Semester', 1, 2, 1, 6, 'DCIT 22'),
('2018_DCIT 23 IT', 'BSIT', 'Computer Programming 2', 'First Year', 'Second Semester', 1, 2, 1, 6, 'DCIT 22'),
('2018_DCIT 24', 'BSIT, BSCS', 'Information Management', 'Second Year', 'First Semester', 2, 1, 2, 3, 'DCIT 23'),
('2018_DCIT 25 CS', 'BSCS', 'Data Structures and Algorithms', 'Second Year', 'Second Semester', 2, 1, 2, 3, 'DCIT 23'),
('2018_DCIT 25 IT', 'BSIT', 'Data Structures and Algorithms', 'Second Year', 'Second Semester', 2, 1, 2, 3, 'DCIT 50'),
('2018_DCIT 26 CS', 'BSCS', 'Applications Development and Emerging Technologies', 'Third Year', 'First Semester', 2, 1, 2, 3, 'ITEC 50'),
('2018_DCIT 26 IT', 'BSIT', 'Application Development and Emerging Technologies', 'Third Year', 'First Semester', 2, 1, 2, 3, 'DCIT 55'),
('2018_DCIT 50', 'BSIT, BSCS', 'Object Oriented Programming', 'Second Year', 'First Semester', 2, 1, 2, 3, 'DCIT 23'),
('2018_DCIT 55 CS', 'BSCS', 'Advanced Database Management System', 'Second Year', 'Second Semester', 2, 1, 2, 3, 'DCIT 24'),
('2018_DCIT 55 IT', 'BSIT', 'Advanced Database System', 'Second Year', 'Second Semester', 2, 1, 2, 3, 'DCIT 24'),
('2018_DCIT 60 CS', 'BSCS', 'Methods of Research', 'Third Year', 'Second Semester', 3, 0, 3, 0, '3rd Year Standing'),
('2018_DCIT 60 IT', 'BSIT', 'Methods of Research', 'Third Year', 'First Semester', 3, 0, 3, 0, '3rd Year Standing'),
('2018_DCIT 65 CS', 'BSCS', 'Social and Professional Issues', 'Third Year', 'First Semester', 3, 0, 3, 0, 'NONE'),
('2018_DCIT 65 IT', 'BSIT', 'Social and Professional Issues', 'Fourth Year', 'First Semester', 3, 0, 3, 0, '4th Year Standing'),
('2018_DRAW 23', 'BSCpE', 'Computer Aided Drafting and Design(CADD)', 'Second Year', 'First Semester', 0, 1, 0, 3, 'NONE'),
('2018_DRAW 24', 'BSIndT', 'Technology Drawing 1 (CADD 2D)', 'First Year', 'First Semester', 0, 1, 0, 3, 'NONE'),
('2018_DRAW 25', 'BSIndT', 'Industrial Technology Drawing II (CADD 3D)', 'First Year', 'Second Semester', 0, 1, 0, 3, 'DRAW 24'),
('2018_ECEN 60', 'BSCpE', 'Electronics 1 (Electronic Devices and Circuits)', 'Second Year', 'Second Semester', 3, 1, 3, 3, 'EENG 50'),
('2018_EENG 50', 'BSCpE', 'Electrical Circuits 1', 'Second Year', 'First Semester', 3, 1, 3, 3, 'PHYS 14'),
('2018_ELEX 100', 'BSIndT', 'Programmable Controller Application', 'Third Year', 'Second Semester', 2, 3, 2, 9, 'ELEX 90, 95'),
('2018_ELEX 105', 'BSIndT', 'Industrial Robotics', 'Third Year', 'Second Semester', 2, 1, 2, 3, 'ELEX 90, 95'),
('2018_ELEX 199a', 'BSIndT', 'Supervised Industrial Training 1', 'First Year', 'Mid Year', 0, 3, 0, 240, 'ELEX 50, 55, 60, 65'),
('2018_ELEX 199b', 'BSIndT', 'Supervised Industrial Training 2', 'Fourth Year', 'First Semester', 0, 8, 0, 640, 'All Major Subjects ELEX 199a'),
('2018_ELEX 199c', 'BSIndT', 'Supervised Industrial Training 3', 'Fourth Year', 'Second Semester', 0, 8, 0, 640, 'ELEX 199b'),
('2018_ELEX 200a', 'BSIndT', 'ELEX Design Project 1', 'Third Year', 'First Semester', 0, 1, 0, 3, 'NONE'),
('2018_ELEX 200b', 'BSIndT', 'ELEX Design Project 2', 'Third Year', 'Second Semester', 0, 1, 0, 3, 'ELEX 200a'),
('2018_ELEX 50', 'BSIndT', 'Electronics Devices, Instruments and Circuit', 'First Year', 'First Semester', 2, 3, 2, 0, 'NONE'),
('2018_ELEX 55', 'BSIndT', 'Electronics Design and Fabrication', 'First Year', 'First Semester', 2, 1, 2, 3, 'NONE'),
('2018_ELEX 60', 'BSIndT', 'Electronics Communication 1', 'First Year', 'Second Semester', 2, 3, 2, 9, 'ELEX 50, 55'),
('2018_ELEX 65', 'BSIndT', 'Semiconductor Devices', 'First Year', 'Second Semester', 2, 1, 2, 3, 'ELEX 50, 55'),
('2018_ELEX 70', 'BSIndT', 'Electronics Communication 2', 'Second Year', 'First Semester', 2, 3, 2, 9, 'ELEX 60, 65'),
('2018_ELEX 75', 'BSIndT', 'Advanced Electronics', 'Second Year', 'First Semester', 2, 1, 2, 3, 'INDT 22, ELEX 65'),
('2018_ELEX 80', 'BSIndT', 'Instrumentation and Process Control', 'Second Year', 'Second Semester', 2, 3, 2, 9, 'ELEX 70, 75'),
('2018_ELEX 85', 'BSIndT', 'Sensor and Interfacing', 'Second Year', 'Second Semester', 2, 1, 2, 3, 'ELEX 70, 75'),
('2018_ELEX 90', 'BSIndT', 'Microprocessor and Interfacing', 'Third Year', 'First Semester', 2, 3, 2, 9, 'ELEX 80, 85'),
('2018_ELEX 95', 'BSIndT', 'Industrial Electronics', 'Third Year', 'First Semester', 2, 1, 2, 3, 'ELEX 80, 85'),
('2018_ENGS 29', 'BSIndT', 'Applied Thermodynamics', 'Third Year', 'Second Semester', 1, 1, 1, 3, 'INDT 24'),
('2018_ENGS 31', 'BSCpE', 'Engineering Economics', 'Second Year', 'First Semester', 3, 0, 3, 0, '2nd Year Standing'),
('2018_ENGS 32', 'BSIndT', 'Technopreneurship 101', 'Second Year', 'First Semester', 3, 0, 3, 0, 'NONE'),
('2018_ENGS 33', 'BSIndT', 'Environmental Science', 'Third Year', 'First Semester', 3, 0, 3, 0, 'NONE'),
('2018_ENGS 35', 'BSCpE', 'Technopreneurship 101', 'Third Year', 'Second Semester', 3, 0, 3, 0, '3rd year standing'),
('2018_ENOS 24b', 'BSIndT', 'Mechanics of Deformable Bodies', 'Second Year', 'Second Semester', 3, 0, 3, 0, 'NONE'),
('2018_FITT 1', 'BSIndT, BSCpE, BSIT, BSCS', 'Movement Enhancement', 'First Year', 'First Semester', 2, 0, 2, 0, 'NONE'),
('2018_FITT 2 CpE-CS-IndT', 'BSCpE, BSCS, BSIndT', 'Fitness Exercises', 'First Year', 'Second Semester', 2, 0, 2, 0, 'FITT 1'),
('2018_FITT 2 IT', 'BSIT', 'Fitness Exercise', 'First Year', 'Second Semester', 2, 0, 2, 0, 'NONE'),
('2018_FITT 3 CpE', 'BSCpE', 'Physical Activities Towards Health and Fitness I', 'Second Year', 'First Semester', 2, 0, 2, 0, 'FITT 2'),
('2018_FITT 3 CS', 'BSCS', 'Physical Activities towards Health and Fitness 1', 'Second Year', 'First Semester', 2, 0, 2, 0, 'FITT 1'),
('2018_FITT 3 IndT', 'BSIndT', 'Physical Activities Towards Health and Fitness I', 'Second Year', 'First Semester', 2, 0, 2, 0, 'FITT 1'),
('2018_FITT 3 IT', 'BSIT', 'Physical Activities towards Health and Fitness I', 'Second Year', 'First Semester', 2, 0, 2, 0, 'FITT 1'),
('2018_FITT 4 CpE-IndT', 'BSCpE, BSIndT', 'Physical Activities Towards Health and Fitness II', 'Second Year', 'Second Semester', 2, 0, 2, 0, 'FITT 1'),
('2018_FITT 4 CS', 'BSCS', 'Physical Activities towards Health and Fitness 2', 'Second Year', 'Second Semester', 2, 0, 2, 0, 'FITT 1'),
('2018_FITT 4 IT', 'BSIT', 'Physical Activities towards Health and Fitness II', 'Second Year', 'Second Semester', 2, 0, 2, 0, 'FITT 1'),
('2018_GNED 01 CpE-IndT', 'BSCpE, BSIndT', 'Art Appreciation', 'Second Year', 'Second Semester', 3, 0, 3, 0, 'NONE'),
('2018_GNED 01 CS', 'BSCS', 'Art Appreciation', 'First Year', 'Second Semester', 3, 0, 3, 0, 'NONE'),
('2018_GNED 01 IT', 'BSIT', 'Arts Appreciation', 'First Year', 'Second Semester', 3, 0, 3, 0, 'NONE'),
('2018_GNED 02 CpE', 'BSCpE', 'Ethics', 'First Year', 'Second Semester', 3, 0, 3, 0, 'NONE'),
('2018_GNED 02 CS-IT', 'BSCS, BSIT', 'Ethics', 'First Year', 'First Semester', 3, 0, 3, 0, 'NONE'),
('2018_GNED 02 IndT', 'BSIndT', 'Ethics', 'Second Year', 'Second Semester', 3, 0, 3, 0, 'NONE'),
('2018_GNED 03 CpE-IndT', 'BSCpE, BSIndT', 'Mathematics in the Modern World', 'First Year', 'First Semester', 3, 0, 3, 0, 'NONE'),
('2018_GNED 03 CS-IT', 'BSCS, BSIT', 'Mathematics in the Modern World', 'First Year', 'Second Semester', 3, 0, 3, 0, 'NONE'),
('2018_GNED 04 CpE', 'BSCpE', 'Mga Babasahin Hinggil Sa Kasaysayan', 'Fourth Year', 'First Semester', 3, 0, 3, 0, 'NONE'),
('2018_GNED 04 CS-IT', 'BSCS, BSIT', 'Mga Babasahin Hinggil sa Kasaysayan ng Pilipinas', 'Second Year', 'First Semester', 3, 0, 3, 0, 'NONE'),
('2018_GNED 04 IndT', 'BSIndT', 'Mga Babasahin Hinggil sa Kasaysayan', 'Second Year', 'First Semester', 3, 0, 3, 0, 'NONE'),
('2018_GNED 05 CpE', 'BSCpE', 'Purposive Communication', 'Second Year', 'Second Semester', 3, 0, 3, 0, 'NONE'),
('2018_GNED 05 CS-IndT-IT', 'BSCS, BSIndT, BSIT', 'Purposive Communication', 'First Year', 'First Semester', 3, 0, 3, 0, 'NONE'),
('2018_GNED 06 CpE', 'BSCpE', 'Science, Technology and Society', 'Second Year', 'First Semester', 3, 0, 3, 0, 'NONE'),
('2018_GNED 06 CS-IndT', 'BSCS, BSIndT', 'Science, Technology and Society', 'First Year', 'Second Semester', 3, 0, 3, 0, 'NONE'),
('2018_GNED 06 IT', 'BSIT', 'Science, Technology, and Society', 'First Year', 'Second Semester', 3, 0, 3, 0, 'NONE'),
('2018_GNED 07 CpE', 'BSCpE', 'The Contemporary World', 'Second Year', 'Second Semester', 3, 0, 3, 0, 'MATH 12'),
('2018_GNED 07 CS', 'BSCS', 'The Contemporary World', 'Fourth Year', 'Second Semester', 3, 0, 3, 0, 'NONE'),
('2018_GNED 07 IndT', 'BSIndT', 'The Contemporary World', 'Second Year', 'Second Semester', 3, 0, 3, 0, 'NONE'),
('2018_GNED 07 IT', 'BSIT', 'The Contemporary World', 'Second Year', 'First Semester', 3, 0, 3, 0, 'NONE'),
('2018_GNED 08 CpE', 'BSCpE', 'Understanding the Self', 'First Year', 'First Semester', 3, 0, 3, 0, 'NONE'),
('2018_GNED 08 CS-IT', 'BSCS, BSIT', 'Understanding the Self', 'Second Year', 'Second Semester', 3, 0, 3, 0, 'NONE'),
('2018_GNED 08 IndT', 'BSIndT', 'Understanding the Self', 'Second Year', 'First Semester', 3, 0, 3, 0, 'NONE'),
('2018_GNED 09 CpE', 'BSCpE', 'Life and Works of Rizal', 'Fourth Year', 'Second Semester', 3, 0, 3, 0, 'NONE'),
('2018_GNED 09 CS', 'BSCS', 'Life and Works of Rizal', 'Third Year', 'Second Semester', 3, 0, 3, 0, 'GNED 04'),
('2018_GNED 09 IndT', 'BSIndT', 'Life and Works of Rizal', 'Second Year', 'Second Semester', 3, 0, 3, 0, 'GNED 04'),
('2018_GNED 09 IT', 'BSIT', 'Rizals Life, Works, and Writings', 'Third Year', 'Second Semester', 3, 0, 3, 0, 'GNED 04'),
('2018_GNED 10 CpE-IndT-IT', 'BSCpE, BSIndT, BSIT', 'Gender and Society', 'Second Year', 'First Semester', 3, 0, 3, 0, 'NONE'),
('2018_GNED 10 CS', 'BSCS', 'Gender and Society', 'Fourth Year', 'Second Semester', 3, 0, 3, 0, 'NONE'),
('2018_GNED 11 CpE-CS', 'BSCpE, BSCS', 'Kontekstwalisadong Komunikasyon sa Filipino', 'First Year', 'First Semester', 3, 0, 3, 0, 'NONE'),
('2018_GNED 11 IndT', 'BSIndT', 'Kontextwalisadong Komunikasyon sa Filipino', 'First Year', 'First Semester', 3, 0, 3, 0, 'NONE'),
('2018_GNED 11 IT', 'BSIT', 'Kontekstwalisadong Komunikasyon', 'First Year', 'First Semester', 3, 0, 3, 0, 'NONE'),
('2018_GNED 12 CpE', 'BSCpE', 'Dalumat Ng/Sa Filipino', 'First Year', 'Second Semester', 3, 0, 3, 0, 'NONE'),
('2018_GNED 12 CS', 'BSCS', 'Dalumat Ng/Sa Filipino', 'First Year', 'Second Semester', 3, 0, 3, 0, 'GNED 11'),
('2018_GNED 12 IndT', 'BSIndT', 'Dalumat ng/sa Filipino', 'First Year', 'Second Semester', 3, 0, 3, 0, 'GNED 10'),
('2018_GNED 12 IT', 'BSIT', 'Dalumat Ng/Sa Filipino', 'Second Year', 'First Semester', 3, 0, 3, 0, 'GNED 11'),
('2018_GNED 14 CpE', 'BSCpE', 'Panitikang Panlipunan', 'Fourth Year', 'Second Semester', 3, 0, 3, 0, 'GNED 04'),
('2018_GNED 14 CS', 'BSCS', 'Panitikang Panlipunan', 'Second Year', 'Second Semester', 3, 0, 3, 0, 'NONE'),
('2018_GNED 14 IndT', 'BSIndT', 'Panitikan Filipino', 'Second Year', 'Second Semester', 3, 0, 3, 0, 'GNED 11'),
('2018_GNED 14 IT', 'BSIT', 'Panitikan Panlipunan', 'First Year', 'Second Semester', 3, 0, 3, 0, 'GNED 11'),
('2018_INDT 21', 'BSIndT', 'Basic Occupational Health and Safety', 'First Year', 'First Semester', 2, 0, 2, 0, 'NONE'),
('2018_INDT 22', 'BSIndT', 'Digital Electronics', 'First Year', 'Second Semester', 1, 1, 1, 3, 'NONE'),
('2018_INDT 23', 'BSIndT', 'Programmable Controls', 'Second Year', 'Second Semester', 1, 1, 1, 3, 'INDT 22'),
('2018_INDT 24', 'BSIndT', 'Pneumatics and Hydraulics (P&H)', 'Third Year', 'First Semester', 1, 1, 1, 3, 'INDT 23'),
('2018_INDT 25', 'BSIndT', 'Intellectual Property Rights', 'Third Year', 'First Semester', 3, 0, 3, 0, 'NONE'),
('2018_INDT 26', 'BSIndT', 'Human Resources Management for Technology', 'Third Year', 'First Semester', 3, 0, 3, 0, 'NONE'),
('2018_INDT 27', 'BSIndT', 'Materials and Business Technology Management', 'Third Year', 'First Semester', 3, 0, 3, 0, 'NONE'),
('2018_INDT 28', 'BSIndT', 'Industrial Organization and Management Practice', 'Third Year', 'Second Semester', 3, 0, 3, 0, 'INDT 26'),
('2018_INDT 29', 'BSIndT', 'Quality Control', 'Third Year', 'Second Semester', 3, 0, 3, 0, 'INDT 27'),
('2018_INDT 30', 'BSIndT', 'Production Technology Management', 'Third Year', 'Second Semester', 3, 0, 3, 0, 'INDT 27'),
('2018_INSY 50', 'BSCS', 'Fundamentals of Information Systems', 'Second Year', 'First Semester', 3, 0, 3, 0, 'DCIT 21'),
('2018_INSY 55', 'BSIT', 'System Analysis and Design', 'Third Year', 'First Semester', 2, 1, 2, 3, '3rd Year Standing'),
('2018_ITEC 100', 'BSIT', 'Information Assurance and Security 2', 'Third Year', 'Second Semester', 2, 1, 2, 3, 'ITEC 85'),
('2018_ITEC 101', 'BSIT', 'IT Elective 1 (Human Computer Interaction 2)', 'Third Year', 'Second Semester', 2, 1, 2, 3, 'ITEC 80'),
('2018_ITEC 105', 'BSIT', 'Network Management', 'Third Year', 'Second Semester', 2, 1, 2, 3, 'ITEC 90'),
('2018_ITEC 106', 'BSIT', 'IT Elective 2 (Web System and Technologies 2)', 'Third Year', 'Second Semester', 2, 1, 2, 3, 'ITEC 50'),
('2018_ITEC 110', 'BSIT', 'Systems Administration and Maintenance', 'Fourth Year', 'First Semester', 2, 1, 2, 3, 'ITEC 101'),
('2018_ITEC 111', 'BSIT', 'IT Elective 3 (Integrated Programming and Technologies 2)', 'Fourth Year', 'First Semester', 2, 1, 2, 3, 'ITEC 101'),
('2018_ITEC 116', 'BSIT', 'IT Elective 4 (Systems Integration and Architecture 2)', 'Fourth Year', 'First Semester', 2, 1, 2, 3, 'ITEC 75'),
('2018_ITEC 199', 'BSIT', 'Practicum (minimum 486 hours)', 'Fourth Year', 'Second Semester', 6, 0, 0, 0, 'DCIT 26, ITEC 85, 70% Total Units taken'),
('2018_ITEC 200A', 'BSIT', 'Capstone Project and Research 1', 'Third Year', 'Second Semester', 3, 0, 3, 0, 'DCIT 60, DCIT 26, ITEC 85, 70% Total Units taken'),
('2018_ITEC 200B', 'BSIT', 'Capstone Project and Research 2', 'Fourth Year', 'First Semester', 3, 0, 3, 0, 'ITEC 200A'),
('2018_ITEC 50 CS', 'BSCS', 'Web Systems and Technologies', 'First Year', 'Second Semester', 2, 1, 2, 3, 'DCIT 21'),
('2018_ITEC 50 IT', 'BSIT', 'Web System and Technologies 1', 'First Year', 'Second Semester', 2, 1, 2, 3, 'DCIT 21'),
('2018_ITEC 55', 'BSIT', 'Platform Technologies', 'Second Year', 'First Semester', 2, 1, 2, 3, 'DCIT 23'),
('2018_ITEC 60', 'BSIT', 'Integrated Programming and Technologies 1', 'Second Year', 'Second Semester', 2, 1, 2, 3, 'DCIT 50, ITEC 50'),
('2018_ITEC 65', 'BSIT', 'Open Source Technology', 'Second Year', 'Second Semester', 2, 1, 2, 3, '2nd Year Standing'),
('2018_ITEC 70', 'BSIT', 'Multimedia Systems', 'Second Year', 'Second Semester', 2, 1, 2, 3, '2nd Year Standing'),
('2018_ITEC 75', 'BSIT', 'System Integration and Architecture 1', 'Second Year', 'Mid Year', 2, 1, 2, 3, 'ITEC 60'),
('2018_ITEC 80 CS', 'BSCS', 'Human Computer Interaction', 'Fourth Year', 'First Semester', 3, 0, 3, 0, 'ITEC 85'),
('2018_ITEC 80 IT', 'BSIT', 'Introduction to Human Computer Interaction', 'Third Year', 'First Semester', 2, 1, 2, 3, '3rd Year Standing'),
('2018_ITEC 85 CS', 'BSCS', 'Information Assurance and Security', 'Third Year', 'Second Semester', 3, 0, 3, 0, 'DCIT 24'),
('2018_ITEC 85 IT', 'BSIT', 'Information Assurance and Security 1', 'Third Year', 'First Semester', 2, 1, 2, 3, 'ITEC 75'),
('2018_ITEC 90', 'BSIT', 'Network Fundamentals', 'Third Year', 'First Semester', 2, 1, 2, 3, 'ITEC 55'),
('2018_ITEC 95', 'BSIT', 'Quantitative Methods (Modeling & Simulation)', 'Third Year', 'Second Semester', 3, 0, 3, 3, 'COSC 50, STAT 2'),
('2018_MATH 1', 'BSCS', 'Analytic Geometry', 'Second Year', 'First Semester', 3, 0, 3, 0, 'GNED 03'),
('2018_MATH 11 CpE', 'BSCpE', 'Calculus 1', 'First Year', 'First Semester', 3, 0, 3, 0, 'NONE'),
('2018_MATH 11 IndT', 'BSIndT', 'Differential and Integral Calculus', 'Second Year', 'First Semester', 3, 0, 3, 0, 'NONE'),
('2018_MATH 12', 'BSCpE', 'Calculus 2', 'First Year', 'Second Semester', 3, 0, 3, 0, 'MATH 11'),
('2018_MATH 13', 'BSCpE', 'Differential Equations', 'Second Year', 'First Semester', 3, 0, 3, 0, 'MATH 12'),
('2018_MATH 14', 'BSCpE', 'Engineering Data Analysis', 'Third Year', 'First Semester', 3, 0, 3, 0, 'MATH 11'),
('2018_MATH 2', 'BSCS', 'Calculus', 'Second Year', 'Second Semester', 3, 0, 3, 0, 'MATH 1'),
('2018_MATH 3', 'BSCS', 'Linear Algebra', 'Third Year', 'First Semester', 3, 0, 3, 0, 'MATH 2'),
('2018_MATH 4', 'BSCS', 'Experimental Statistics', 'Third Year', 'Second Semester', 2, 1, 2, 3, 'MATH 2'),
('2018_NSTP 1 CpE-IndT', 'BSCpE, BSIndT', 'National Service Training Program I', 'First Year', 'First Semester', 3, 0, 3, 0, 'NONE'),
('2018_NSTP 1 CS-IT', 'BSCS, BSIT', 'National Service Training Program 1', 'First Year', 'First Semester', 3, 0, 3, 0, 'NONE'),
('2018_NSTP 2 CpE', 'BSCpE', 'National Service Training Program II', 'First Year', 'Second Semester', 3, 0, 3, 0, 'NSTP 1'),
('2018_NSTP 2 CS-IndT', 'BSCS, BSIndT', 'National Service Training Program 2', 'First Year', 'Second Semester', 3, 0, 3, 0, 'NSTP 1'),
('2018_NSTP 2 IT', 'BSIT', 'National Service Training Program 2', 'First Year', 'Second Semester', 3, 0, 3, 0, 'NONE'),
('2018_PHYS 14', 'BSCpE', 'Physics for Engineers', 'First Year', 'Second Semester', 3, 1, 3, 3, 'CHEM 14'),
('2018_STAT 2', 'BSIT', 'Applied Statistics', 'Second Year', 'Mid Year', 3, 0, 3, 0, '2nd Year Standing'),
('2021_BSEE 110', 'BSEd-English', 'Stylistics and Discourse Analysis', 'Second Year', 'First Semester', 3, 0, 3, 0, 'NONE'),
('2021_BSEE 111', 'BSEd-English', 'English for Specific Purposes', 'Third Year', 'First Semester', 3, 0, 3, 0, 'NONE'),
('2021_BSEE 21', 'BSEd-English', 'Introduction to Linguistics', 'First Year', 'First Semester', 2, 0, 3, 0, 'NONE'),
('2021_BSEE 22', 'BSEd-English', 'Language Culture and Society', 'First Year', 'Second Semester', 3, 0, 3, 0, 'BSEE 21'),
('2021_BSEE 23', 'BSEd-English', 'Structure of English', 'Second Year', 'First Semester', 3, 0, 3, 0, 'BSEE 21'),
('2021_BSEE 24', 'BSEd-English', 'Principles and Theories of Language Acquisition and Learning', 'Second Year', 'First Semester', 3, 0, 3, 0, 'BSEE 22'),
('2021_BSEE 25', 'BSEd-English', 'Language Programs and Policies in Multilingual Society', 'Second Year', 'Second Semester', 3, 0, 3, 0, 'BSEE 24'),
('2021_BSEE 26', 'BSEd-English', 'Language Learning Materials Development', 'Second Year', 'Second Semester', 3, 0, 3, 0, 'BSEE 24, EDUC 85'),
('2021_BSEE 27', 'BSEd-English', 'Teaching and Assessment of Literature', 'Third Year', 'Second Semester', 3, 0, 3, 0, 'BSEE 38'),
('2021_BSEE 28', 'BSEd-English', 'Teaching and Assessment of Macroskills', 'Third Year', 'First Semester', 3, 0, 3, 0, 'BSEE 22'),
('2021_BSEE 29', 'BSEd-English', 'Teaching and Assessment of Grammar', 'Third Year', 'First Semester', 3, 0, 3, 0, 'BSEE 23'),
('2021_BSEE 30', 'BSEd-English', 'Speech and Theater Arts', 'Second Year', 'Midyear', 3, 0, 3, 0, 'BSEE 23'),
('2021_BSEE 31', 'BSEd-English', 'Language and Education Research', 'Third Year', 'Second Semester', 3, 0, 3, 0, 'BSEE 24'),
('2021_BSEE 32', 'BSEd-English', 'Children and Adolescent Literature', 'Second Year', 'Second Semester', 3, 0, 3, 0, 'BSEE 24'),
('2021_BSEE 33', 'BSEd-English', 'Mythology and Folklore', 'Second Year', 'First Semester', 3, 0, 3, 0, 'BSEE 22'),
('2021_BSEE 34', 'BSEd-English', 'Survey of Philippine Literature in English', 'Second Year', 'Midyear', 3, 0, 3, 0, 'BSEE 32'),
('2021_BSEE 35', 'BSEd-English', 'Survey of Afro-Asian Literature', 'Second Year', 'Midyear', 3, 0, 3, 0, 'BSEE 32'),
('2021_BSEE 36', 'BSEd-English', 'Survey of English and American Literature', 'Third Year', 'First Semester', 3, 0, 3, 0, 'BSEE 32'),
('2021_BSEE 37', 'BSEd-English', 'Contemporary, Popular, and Emergent Literature', 'Third Year', 'First Semester', 3, 0, 3, 0, 'BSEE 32'),
('2021_BSEE 38', 'BSEd-English', 'Literary Criticism', 'Third Year', 'Second Semester', 3, 0, 3, 0, 'BSEE 32, BSEE 33, BSEE 34, BSEE 35, BSEE 36, BSEE 37'),
('2021_BSEE 39', 'BSEd-English', 'Technical Writing', 'Second Year', 'Second Semester', 3, 0, 3, 0, 'BSEE 23'),
('2021_BSEE 40', 'BSEd-English', 'Campus Journalism', 'Third Year', 'Second Semester', 3, 0, 3, 0, 'BSEE 39'),
('2021_BSEE 41', 'BSEd-English', 'Technology for Teaching and Learning 2 (Technology in Secondary Language Education)', 'Third Year', 'Second Semester', 2, 0, 3, 0, 'EDUC 85'),
('2021_BSEM 21', 'BSEd-Math', 'History of Mathematics', 'First Year', 'Midyear', 3, 0, 3, 0, 'NONE'),
('2021_BSEM 22', 'BSEd-Math', 'College and Advanced Algebra', 'First Year', 'Midyear', 3, 0, 3, 0, 'NONE'),
('2021_BSEM 23', 'BSEd-Math', 'Trigonometry', 'Second Year', 'First Semester', 3, 0, 3, 0, 'BSEM 22'),
('2021_BSEM 24', 'BSEd-Math', 'Plane and Solid Geometry', 'Second Year', 'First Semester', 3, 0, 3, 0, 'BSEM 22'),
('2021_BSEM 25', 'BSEd-Math', 'Logic and Set Theory', 'Second Year', 'First Semester', 3, 0, 3, 0, 'NONE'),
('2021_BSEM 26', 'BSEd-Math', 'Elementary Statistics and Probability', 'Second Year', 'First Semester', 3, 0, 3, 0, 'NONE'),
('2021_BSEM 27', 'BSEd-Math', 'Calculus 1 with Analytic Geometry', 'Third Year', 'First Semester', 4, 0, 4, 0, 'BSEM 22, BSEM 23,BSEM 30'),
('2021_BSEM 28', 'BSEd-Math', 'Calculus 2', 'Third Year', 'Second Semester', 4, 0, 4, 0, 'BSEM 27'),
('2021_BSEM 29', 'BSEd-Math', 'Calculus 3', 'Third Year', 'Midyear', 3, 0, 3, 0, 'BSEM 28'),
('2021_BSEM 30', 'BSEd-Math', 'Modern Geometry', 'Second Year', 'Second Semester', 3, 0, 3, 0, 'BSEM 24, BSEM 25'),
('2021_BSEM 31', 'BSEd-Math', 'Mathematics of Investment', 'Third Year', 'Second Semester', 3, 0, 3, 0, 'BSEM 22'),
('2021_BSEM 32', 'BSEd-Math', 'Number Theory', 'Third Year', 'First Semester', 3, 0, 3, 0, 'BSEM 22, BSEM 25'),
('2021_BSEM 33', 'BSEd-Math', 'Linear Algebra', 'Second Year', 'Second Semester', 3, 0, 3, 0, 'BSEM 25'),
('2021_BSEM 34', 'BSEd-Math', 'Advanced Statistics', 'Second Year', 'Second Semester', 3, 0, 3, 0, 'BSEM 26'),
('2021_BSEM 35', 'BSEd-Math', 'Problem-Solving, Mathematical Investigations and Modelling', 'Third Year', 'First Semester', 3, 0, 3, 0, 'BSEM 22, BSEM 25, BSEM 30'),
('2021_BSEM 36', 'BSEd-Math', 'Principles and Methods of Teaching Mathematics', 'Second Year', 'Second Semester', 3, 0, 3, 0, 'NONE'),
('2021_BSEM 37', 'BSEd-Math', 'Abstract Algebra', 'Third Year', 'Second Semester', 3, 0, 3, 0, 'BSEM 25'),
('2021_BSEM 38', 'BSEd-Math', 'Research in Mathematics', 'Third Year', 'First Semester', 4, 0, 4, 0, 'BSEM 34'),
('2021_BSEM 39', 'BSEd-Math', 'Technology for Teaching and Learning 2 (Instrumentation & Technology in Mathematics)', 'Third Year', 'First Semester', 3, 0, 3, 0, 'EDUC 75, EDUC 80, EDUC 85'),
('2021_BSEM 40', 'BSEd-Math', 'Assessment and Evaluation in Mathematics', 'Third Year', 'Second Semester', 3, 0, 3, 0, 'BSEM 34'),
('2021_BSES 21', 'BSEd-Science', 'Genetics', 'Second Year', 'First Semester', 3, 1, 3, 3, 'NONE'),
('2021_BSES 22', 'BSEd-Science', 'Cell and Molecular Biology', 'Third Year', 'First Semester', 3, 1, 3, 3, 'BSES 21 & 27'),
('2021_BSES 23', 'BSEd-Science', 'Microbiology and Parasitology', 'Third Year', 'Second Semester', 3, 1, 3, 3, 'NONE'),
('2021_BSES 24', 'BSEd-Science', 'Anatomy and Physiology', 'Third Year', 'Second Semester', 3, 1, 3, 3, 'NONE'),
('2021_BSES 25', 'BSEd-Science', 'Inorganic Chemistry', 'First Year', 'Midyear', 3, 2, 3, 6, 'NONE'),
('2021_BSES 26', 'BSEd-Science', 'Organic Chemistry', 'Second Year', 'First Semester', 3, 2, 3, 6, 'BSES 25'),
('2021_BSES 27', 'BSEd-Science', 'Biochemistry', 'Second Year', 'Second Semester', 3, 0, 3, 0, 'BSES 26'),
('2021_BSES 28', 'BSEd-Science', 'Analytical Chemistry', 'Second Year', 'Midyear', 3, 2, 3, 6, 'BSES 25'),
('2021_BSES 29', 'BSEd-Science', 'Thermodynamics', 'Second Year', 'First Semester', 3, 1, 3, 3, 'HS Physics-Mechanics'),
('2021_BSES 30', 'BSEd-Science', 'Fluid Mechanics', 'First Year', 'Second Semester', 3, 0, 3, 0, 'NONE'),
('2021_BSES 31', 'BSEd-Science', 'Electricity and Magnetism', 'Second Year', 'Second Semester', 3, 1, 3, 3, 'BSES 30'),
('2021_BSES 32', 'BSEd-Science', 'Waves and Optics', 'Third Year', 'First Semester', 3, 1, 3, 3, 'BSES 30 & 31'),
('2021_BSES 33', 'BSEd-Science', 'Modern Physics', 'Third Year', 'Second Semester', 3, 0, 3, 0, 'BSES 30 & 31'),
('2021_BSES 34', 'BSEd-Science', 'Earth Science', 'Second Year', 'Second Semester', 3, 0, 3, 0, 'NONE'),
('2021_BSES 35', 'BSEd-Science', 'Astronomy', 'Third Year', 'Second Semester', 3, 0, 3, 0, 'NONE'),
('2021_BSES 36', 'BSEd-Science', 'Environmental Science', 'Second Year', 'Second Semester', 3, 0, 3, 0, 'NONE'),
('2021_BSES 37', 'BSEd-Science', 'The Teaching of Science', 'Third Year', 'Second Semester', 3, 0, 3, 0, 'NONE'),
('2021_BSES 38', 'BSEd-Science', 'Technology for Teaching and Learning 2 (for Science)', 'Third Year', 'First Semester', 3, 0, 3, 0, 'EDUC 75, 80, 85'),
('2021_BSES 39', 'BSEd-Science', 'Research in Teaching', 'Third Year', 'First Semester', 3, 0, 3, 0, 'NONE'),
('2021_BSES 40', 'BSEd-Science', 'Meteorology', 'Third Year', 'First Semester', 3, 0, 3, 0, 'NONE'),
('2021_CVSU 101 Ed-English-Ed-Science', 'BSEd-English, BSEd-Science', 'Institutional Orientation', 'First Year', 'First Semester', 1, 0, 1, 0, 'NONE'),
('2021_CVSU 101 Ed-Math', 'BSEd-Math', 'Institutional Orientation 1', 'First Year', 'First Semester', 1, 0, 1, 0, 'NONE'),
('2021_EDFS 21 Ed-English-Ed-Math', 'BSEd-English, BSEd-Math', 'Field Study 1 - Observations of Teaching-Learning in Actual School Environment', 'Third Year', 'First Semester', 3, 0, 3, 0, 'PROF Ed Courses'),
('2021_EDFS 21 Ed-Science', 'BSEd-Science', 'Field Study 1 - Observations of Teaching Learning in Actual School Environment', 'Third Year', 'First Semester', 3, 0, 3, 0, 'NONE'),
('2021_EDFS 22 Ed-English-Ed-Math', 'BSEd-English, BSEd-Math', 'Field Study 2 - Participation and Teaching Assistantship', 'Third Year', 'Second Semester', 3, 0, 3, 0, 'EDFS 21'),
('2021_EDFS 22 Ed-Science', 'BSEd-Science', 'Field Study 2 - Participation and Teaching Assistantship', 'Third Year', 'Second Semester', 3, 0, 3, 0, 'PROF Ed Courses'),
('2021_EDFS 23', 'BSEd-English, BSEd-Science, BSEd-Math', 'Teaching Internship', 'Fourth Year', 'First Semester', 6, 0, 0, 40, 'EDFS 21, EDFS 22'),
('2021_EDUC 197 Ed-English-Ed-Math', 'BSEd-English, BSEd-Math', 'Competency Appraisal 1', 'Third Year', 'Second Semester', 3, 0, 3, 0, 'PROF Ed Courses'),
('2021_EDUC 197 Ed-Science', 'BSEd-Science', 'Competency Appraisal 1', 'Third Year', 'Second Semester', 3, 0, 3, 0, 'PROF Ed'),
('2021_EDUC 198', 'BSEd-English, BSEd-Science, BSEd-Math', 'Competency Appraisal 2', 'Fourth Year', 'Second Semester', 3, 0, 3, 0, 'EDUC 197'),
('2021_EDUC 50 Ed-English', 'BSEd-English', 'Child and Adolescent Learner and Learning Principles', 'First Year', 'Midyear', 3, 0, 3, 0, 'NONE'),
('2021_EDUC 50 Ed-Math-Ed-Science', 'BSEd-Math, BSEd-Science', 'Child and Adolescent Learner and Learning Principles', 'First Year', 'First Semester', 3, 0, 3, 0, 'NONE'),
('2021_EDUC 55', 'BSEd-English, BSEd-Science, BSEd-Math', 'The Teaching Profession', 'First Year', 'First Semester', 3, 0, 3, 0, 'NONE'),
('2021_EDUC 60', 'BSEd-English, BSEd-Science, BSEd-Math', 'The Teacher and The Community, School Culture and Organizational Leadership', 'First Year', 'First Semester', 3, 0, 3, 0, 'NONE'),
('2021_EDUC 65', 'BSEd-English, BSEd-Science, BSEd-Math', 'Foundation of Special and Inclusive Education', 'First Year', 'Second Semester', 3, 0, 3, 0, 'NONE'),
('2021_EDUC 70 Ed-English', 'BSEd-English', 'Facilitating Learner-Centered Teaching', 'First Year', 'Midyear', 3, 0, 3, 0, 'NONE'),
('2021_EDUC 70 Ed-Math-Ed-Science', 'BSEd-Math, BSEd-Science', 'Facilitating Learner-Centered Teaching', 'First Year', 'Second Semester', 3, 0, 3, 0, 'NONE'),
('2021_EDUC 75', 'BSEd-English, BSEd-Science, BSEd-Math', 'Assessment in Learning 1', 'Second Year', 'First Semester', 3, 0, 3, 0, 'NONE'),
('2021_EDUC 80', 'BSEd-English, BSEd-Science, BSEd-Math', 'Assessment in Learning 2', 'Second Year', 'Second Semester', 3, 0, 3, 0, 'NONE'),
('2021_EDUC 85', 'BSEd-English, BSEd-Science, BSEd-Math', 'Technology for Teaching and Learning 1', 'First Year', 'Second Semester', 3, 0, 3, 0, 'NONE'),
('2021_EDUC 90', 'BSEd-English, BSEd-Science, BSEd-Math', 'The Teacher and The School Curriculum', 'Second Year', 'First Semester', 3, 0, 3, 0, 'NONE'),
('2021_EDUC 95', 'BSEd-English, BSEd-Science, BSEd-Math', 'Building and Enhancing New Literacies Across the Curriculum', 'Second Year', 'Second Semester', 3, 0, 3, 0, 'NONE'),
('2021_FITT 1', 'BSEd-English, BSEd-Science, BSEd-Math', 'Movement Enhancement', 'First Year', 'First Semester', 2, 0, 2, 0, 'NONE'),
('2021_FITT 2', 'BSEd-English, BSEd-Science, BSEd-Math', 'Fitness Exercises', 'First Year', 'Second Semester', 2, 0, 2, 0, 'FITT 1'),
('2021_FITT 3', 'BSEd-English, BSEd-Science, BSEd-Math', 'Physical Activities towards Health and Fitness I', 'Second Year', 'First Semester', 2, 0, 2, 0, 'FITT 1'),
('2021_FITT 4', 'BSEd-English, BSEd-Science, BSEd-Math', 'Physical Activities towards Health and Fitness II', 'Second Year', 'Second Semester', 2, 0, 2, 0, 'FITT 1'),
('2021_GNED 01', 'BSEd-English, BSEd-Science, BSEd-Math', 'Art Appreciation', 'Fourth Year', 'Second Semester', 3, 0, 3, 0, 'NONE'),
('2021_GNED 02', 'BSEd-English, BSEd-Science, BSEd-Math', 'Ethics', 'Fourth Year', 'Second Semester', 3, 0, 3, 0, 'NONE'),
('2021_GNED 03', 'BSEd-English, BSEd-Science, BSEd-Math', 'Mathematics in the Modern World', 'First Year', 'First Semester', 3, 0, 3, 0, 'NONE'),
('2021_GNED 04', 'BSEd-English, BSEd-Science, BSEd-Math', 'Mga Babasahin Hinggil sa Kasaysayan ng Pilipinas', 'Second Year', 'First Semester', 3, 0, 3, 0, 'NONE'),
('2021_GNED 05', 'BSEd-English, BSEd-Science, BSEd-Math', 'Purposive Communication', 'First Year', 'First Semester', 3, 0, 3, 0, 'NONE'),
('2021_GNED 06 Ed-English', 'BSEd-English', 'Science, Technology and Society', 'First Year', 'Second Semester', 3, 0, 3, 0, 'NONE'),
('2021_GNED 06 Ed-Math-Ed-Science', 'BSEd-Math, BSEd-Science', 'Science, Technology, and Society', 'First Year', 'Second Semester', 3, 0, 3, 0, 'NONE'),
('2021_GNED 07 Ed-English-Ed-Science', 'BSEd-English, BSEd-Science', 'The Contemporary World', 'Fourth Year', 'Second Semester', 3, 0, 3, 0, 'NONE'),
('2021_GNED 07 Ed-Math', 'BSEd-Math', 'Contemporary World', 'Fourth Year', 'Second Semester', 3, 0, 3, 0, 'NONE'),
('2021_GNED 08', 'BSEd-English, BSEd-Science, BSEd-Math', 'Understanding the Self', 'First Year', 'Second Semester', 3, 0, 3, 0, 'NONE'),
('2021_GNED 09', 'BSEd-English, BSEd-Science, BSEd-Math', 'Life and Works of Rizal', 'Fourth Year', 'Second Semester', 3, 0, 3, 0, 'GNED 04'),
('2021_GNED 10', 'BSEd-English, BSEd-Science, BSEd-Math', 'Gender and Society', 'Fourth Year', 'Second Semester', 3, 0, 3, 0, 'NONE'),
('2021_GNED 11', 'BSEd-English, BSEd-Science, BSEd-Math', 'Kontekstwalisadong Komunikasyon sa Filipino', 'First Year', 'First Semester', 3, 0, 3, 0, 'NONE'),
('2021_GNED 12', 'BSEd-English, BSEd-Science, BSEd-Math', 'Dalumat Ng/Sa Filipino', 'Third Year', 'First Semester', 3, 0, 3, 0, 'GNED 11'),
('2021_GNED 13 Ed-English', 'BSEd-English', 'Retorika: Masining na Pagpapahayag', 'Fourth Year', 'Second Semester', 3, 0, 3, 0, 'GNED 11, GNED 12'),
('2021_GNED 13 Ed-Math', 'BSEd-Math', 'Retorika/Masining na Pagpapahayag', 'Fourth Year', 'Second Semester', 3, 0, 3, 0, 'GNED 11, GNED 12'),
('2021_GNED 13 Ed-Science', 'BSEd-Science', 'Retorika/Masining na Pagpapahayag', 'Fourth Year', 'Second Semester', 3, 0, 3, 0, 'GNED 11 & 12'),
('2021_GNED 14', 'BSEd-English, BSEd-Science, BSEd-Math', 'Panitikang Panlipunan', 'First Year', 'Second Semester', 3, 0, 3, 0, 'NONE'),
('2021_GNED 15', 'BSEd-English, BSEd-Science, BSEd-Math', 'World Literature', 'Second Year', 'Second Semester', 3, 0, 3, 0, 'NONE'),
('2021_NSTP 1', 'BSEd-English, BSEd-Science, BSEd-Math', 'CWTS/LTS/ROTC', 'First Year', 'First Semester', 3, 0, 3, 0, 'NONE'),
('2021_NSTP 2', 'BSEd-English, BSEd-Science, BSEd-Math', 'CWTS/LTS/ROTC', 'First Year', 'Second Semester', 3, 0, 3, 0, 'NSTP 1'),
('2023_BADM 199 BA-HRM', 'BSBA-HRM', 'Practicum Integrated Learning 2 (600 hours)', 'Fourth Year', 'Second Semester', 6, 0, 6, 0, 'All Subjects'),
('2023_BADM 199 BA-MM', 'BSBA-MM', 'Practicum Integrated Learning 2 (600 hours)', 'Fourth Year', 'Second Semester', 6, 0, 600, 0, 'All Subjects'),
('2023_BADM 200 BA-HRM', 'BSBA-HRM', 'Research/EDP Proposal', 'Fourth Year', 'First Semester', 1, 0, 1, 0, 'All Major Subjects'),
('2023_BADM 200 BA-MM', 'BSBA-MM', 'Research/EDP Proposal', 'Third Year', 'Second Semester', 1, 0, 1, 0, 'All Major Subjects'),
('2023_BADM 21', 'BSBA-HRM, BSBA-MM', 'Quantitative Techniques in Business', 'First Year', 'Second Semester', 3, 0, 3, 0, 'NONE'),
('2023_BADM 22', 'BSBA-HRM, BSBA-MM', 'Human Resource Management', 'First Year', 'Second Semester', 3, 0, 3, 0, 'NONE'),
('2023_BADM 23', 'BSBA-HRM, BSBA-MM', 'Business Law (Obligations and Contracts)', 'First Year', 'Second Semester', 3, 0, 3, 0, 'NONE'),
('2023_BADM 24', 'BSBA-HRM, BSBA-MM', 'Operations Management', 'Second Year', 'First Semester', 3, 0, 3, 0, 'BADM 21'),
('2023_BADM 25', 'BSBA-HRM, BSBA-MM', 'Good Governance and Social Responsibility', 'Second Year', 'Second Semester', 3, 0, 3, 0, 'NONE'),
('2023_BADM 26', 'BSBA-HRM, BSBA-MM', 'Business Research', 'Third Year', 'First Semester', 3, 0, 3, 0, 'NONE'),
('2023_BADM 27 BA-HRM', 'BSBA-HRM', 'Strategic Management', 'Fourth Year', 'First Semester', 3, 0, 3, 0, 'All Major Subjects'),
('2023_BADM 27 BA-MM', 'BSBA-MM', 'Strategic Management', 'Third Year', 'Second Semester', 3, 0, 3, 0, 'All Major Subjects'),
('2023_BMGT 200b BA-HRM', 'BSBA-HRM', 'Research/EDP Final Manuscript', 'Fourth Year', 'Second Semester', 2, 0, 2, 0, 'BADM 200'),
('2023_BMGT 200b BA-MM', 'BSBA-MM', 'Research/EDP Final Manuscript', 'Fourth Year', 'First Semester', 2, 0, 2, 0, 'BADM 200'),
('2023_ECON 23', 'BSBA-HRM, BSBA-MM', 'Basic Microeconomics', 'First Year', 'First Semester', 3, 0, 3, 0, 'NONE'),
('2023_ECON 24', 'BSBA-HRM, BSBA-MM', 'International Trade and Agreements', 'Second Year', 'First Semester', 3, 0, 3, 0, 'NONE'),
('2023_ELEC 1 BA-HRM', 'BSBA-HRM', 'Human Resource Management System', 'Third Year', 'Second Semester', 3, 0, 3, 0, '3rd Year Standing'),
('2023_ELEC 1 BA-MM', 'BSBA-MM', 'Distribution Management', 'Third Year', 'Second Semester', 3, 0, 3, 0, '3rd Year Standing'),
('2023_ELEC 2 BA-HRM', 'BSBA-HRM', 'Logistic Management', 'Fourth Year', 'First Semester', 3, 0, 3, 0, 'NONE'),
('2023_ELEC 2 BA-MM', 'BSBA-MM', 'International Marketing', 'Third Year', 'Second Semester', 3, 0, 3, 0, 'NONE'),
('2023_ELEC 3 BA-HRM', 'BSBA-HRM', 'Marketing Management', 'Fourth Year', 'First Semester', 3, 0, 3, 0, 'NONE'),
('2023_ELEC 3 BA-MM', 'BSBA-MM', 'E-commerce and Internet Marketing', 'Third Year', 'Second Semester', 3, 0, 3, 0, 'NONE'),
('2023_ELEC 4 BA-HRM', 'BSBA-HRM', 'Strategic Human Resource Management', 'Fourth Year', 'First Semester', 3, 0, 3, 0, 'NONE'),
('2023_ELEC 4 BA-MM', 'BSBA-MM', 'Service Marketing', 'Third Year', 'Second Semester', 3, 0, 3, 0, 'NONE'),
('2023_FITT 1', 'BSBA-HRM, BSBA-MM', 'Movement Enhancement (sample 2023)', 'First Year', 'First Semester', 2, 0, 2, 0, 'NONE'),
('2023_FITT 2', 'BSBA-HRM, BSBA-MM', 'Fitness Exercises', 'First Year', 'Second Semester', 2, 0, 2, 0, 'FITT 1'),
('2023_FITT 3', 'BSBA-HRM, BSBA-MM', 'Physical Activities towards Health and Fitness I', 'Second Year', 'First Semester', 2, 0, 2, 0, 'FITT 1'),
('2023_FITT 4', 'BSBA-HRM, BSBA-MM', 'Physical Activities towards Health and Fitness II', 'Second Year', 'Second Semester', 2, 0, 2, 0, 'FITT 1'),
('2023_GNED 01', 'BSBA-HRM, BSBA-MM', 'Art Appreciation', 'First Year', 'Second Semester', 3, 0, 3, 0, 'NONE'),
('2023_GNED 02', 'BSBA-HRM, BSBA-MM', 'Ethics', 'First Year', 'Second Semester', 3, 0, 3, 0, 'NONE'),
('2023_GNED 03', 'BSBA-HRM, BSBA-MM', 'Mathematics in the Modern World', 'First Year', 'First Semester', 3, 0, 3, 0, 'NONE'),
('2023_GNED 04', 'BSBA-HRM, BSBA-MM', 'Mga Babasahin Hinggil sa Kasaysayan ng Pilipinas', 'First Year', 'First Semester', 3, 0, 3, 0, 'NONE'),
('2023_GNED 05', 'BSBA-HRM, BSBA-MM', 'Purposive Communication', 'First Year', 'First Semester', 3, 0, 3, 0, 'NONE'),
('2023_GNED 06', 'BSBA-HRM, BSBA-MM', 'Science, Technology and Society', 'Second Year', 'First Semester', 3, 0, 3, 0, 'NONE'),
('2023_GNED 07', 'BSBA-HRM, BSBA-MM', 'The Contemporary World', 'First Year', 'First Semester', 3, 0, 3, 0, 'NONE'),
('2023_GNED 08', 'BSBA-HRM, BSBA-MM', 'Understanding the Self', 'First Year', 'Second Semester', 3, 0, 3, 0, 'NONE'),
('2023_GNED 10', 'BSBA-HRM, BSBA-MM', 'Gender and Society', 'Second Year', 'First Semester', 3, 0, 3, 0, 'NONE'),
('2023_GNED 11', 'BSBA-HRM, BSBA-MM', 'Kontekstwalisadong Komunikasyon sa Filipino', 'First Year', 'First Semester', 3, 0, 3, 0, 'NONE'),
('2023_GNED 12', 'BSBA-HRM, BSBA-MM', 'Dalumat Ng/Sa Filipino', 'Second Year', 'First Semester', 3, 0, 3, 0, 'GNED 11'),
('2023_GNED 14', 'BSBA-HRM, BSBA-MM', 'Panitikang Panlipunan', 'Second Year', 'Second Semester', 3, 0, 3, 0, 'NONE'),
('2023_HRMT 50', 'BSBA-HRM', 'Administrative Office Management', 'Second Year', 'First Semester', 3, 0, 3, 0, 'BADM 22'),
('2023_HRMT 55', 'BSBA-HRM', 'Recruitment and Selection', 'Second Year', 'First Semester', 3, 0, 3, 0, 'BADM 22'),
('2023_HRMT 60', 'BSBA-HRM', 'Training and Development', 'Second Year', 'Second Semester', 3, 0, 3, 0, 'BADM 22'),
('2023_HRMT 65', 'BSBA-HRM', 'Labor Law and Legislation', 'Second Year', 'Second Semester', 3, 0, 3, 0, 'BADM 22'),
('2023_HRMT 70', 'BSBA-HRM', 'Compensation Administration', 'Second Year', 'Second Semester', 3, 0, 3, 0, 'BADM 22'),
('2023_HRMT 75', 'BSBA-HRM', 'Organizational Development', 'Third Year', 'First Semester', 3, 0, 3, 0, 'BADM 22'),
('2023_HRMT 80', 'BSBA-HRM', 'Labor Relation and Negotiations', 'Third Year', 'First Semester', 3, 0, 3, 0, 'BADM 22'),
('2023_HRMT 85', 'BSBA-HRM', 'Special Topics in Human Resource Management', 'Third Year', 'Second Semester', 3, 0, 3, 0, '3rd Year Standing'),
('2023_MKTG 50', 'BSBA-MM', 'Consumer and Behavior', 'Second Year', 'First Semester', 3, 0, 3, 0, 'NONE');
INSERT INTO `cvsucarmona_courses` (`curriculumyear_coursecode`, `programs`, `course_title`, `year_level`, `semester`, `credit_units_lec`, `credit_units_lab`, `lect_hrs_lec`, `lect_hrs_lab`, `pre_requisite`) VALUES
('2023_MKTG 55', 'BSBA-MM', 'Market Research', 'Second Year', 'First Semester', 3, 0, 3, 0, 'NONE'),
('2023_MKTG 60', 'BSBA-MM', 'Product Management', 'Second Year', 'Second Semester', 3, 0, 3, 0, 'MKTG 50, MKTG 55'),
('2023_MKTG 65', 'BSBA-MM', 'Retail Management', 'Second Year', 'Second Semester', 3, 0, 3, 0, 'MKTG 50, MKTG 55'),
('2023_MKTG 70', 'BSBA-MM', 'Advertising', 'Second Year', 'Second Semester', 3, 0, 3, 0, 'MKTG 50, MKTG 55'),
('2023_MKTG 75', 'BSBA-MM', 'Professional Salesmanship', 'Third Year', 'First Semester', 3, 0, 3, 0, 'MKTG 50, MKTG 55'),
('2023_MKTG 80', 'BSBA-MM', 'Marketing Management', 'Third Year', 'First Semester', 3, 0, 3, 0, 'MKTG 50, MKTG 55'),
('2023_MKTG 85', 'BSBA-MM', 'Special Topics in Marketing Management', 'Third Year', 'First Semester', 3, 0, 3, 0, 'MKTG 50, MKTG 55'),
('2023_NSTP 1', 'BSBA-HRM, BSBA-MM', 'National Service Training Program', 'First Year', 'First Semester', 3, 0, 3, 0, 'NONE'),
('2023_NSTP 2', 'BSBA-HRM, BSBA-MM', 'National Service Training Program', 'First Year', 'Second Semester', 3, 0, 3, 0, 'NSTP 1'),
('2023_ORNT 1', 'BSBA-HRM, BSBA-MM', 'Institutional Orientation', 'First Year', 'First Semester', 1, 0, 1, 0, 'NONE'),
('2023_TAXN 21', 'BSBA-HRM, BSBA-MM', 'Taxation (Income and Taxation)', 'Second Year', 'Second Semester', 3, 0, 3, 0, 'NONE');

-- --------------------------------------------------------

--
-- Table structure for table `cvsucarmona_courses_backup_20260327_224348`
--

CREATE TABLE `cvsucarmona_courses_backup_20260327_224348` (
  `curriculumyear_coursecode` varchar(50) NOT NULL,
  `programs` text NOT NULL,
  `course_title` varchar(255) NOT NULL,
  `year_level` varchar(50) NOT NULL,
  `semester` varchar(50) NOT NULL,
  `credit_units_lec` int(2) DEFAULT 0,
  `credit_units_lab` int(2) DEFAULT 0,
  `lect_hrs_lec` int(2) DEFAULT 0,
  `lect_hrs_lab` int(2) DEFAULT 0,
  `pre_requisite` varchar(255) DEFAULT 'NONE'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `cvsucarmona_courses_backup_20260327_224348`
--

INSERT INTO `cvsucarmona_courses_backup_20260327_224348` (`curriculumyear_coursecode`, `programs`, `course_title`, `year_level`, `semester`, `credit_units_lec`, `credit_units_lab`, `lect_hrs_lec`, `lect_hrs_lab`, `pre_requisite`) VALUES
('17v1_GNED 04', 'BSHM', 'Mathematics in Modern World', 'First Year', 'Second Semester', 3, 0, 3, 0, 'NONE'),
('2017_ALAN 1', 'BSHM', 'Asian Language 1', 'Second Year', 'First Semester', 3, 0, 3, 0, 'NONE'),
('2017_ALAN 2', 'BSHM', 'Asian Language 2', 'Third Year', 'First Semester', 3, 0, 3, 0, 'ALAN 1'),
('2017_BSHM 100', 'BSHM', 'Tourism and Hospitality Service Quality Management', 'Fourth Year', 'First Semester', 3, 0, 3, 0, 'BSHM 80'),
('2017_BSHM 101', 'BSHM', 'Food and Beverage Service', 'First Year', 'Second Semester', 2, 1, 2, 3, 'BSHM 55'),
('2017_BSHM 111', 'BSHM', 'Front Office Operation', 'Second Year', 'First Semester', 2, 1, 2, 3, 'BSHM 21'),
('2017_BSHM 121', 'BSHM', 'Sustainable Hospitality Management', 'Third Year', 'First Semester', 3, 0, 3, 0, 'NONE'),
('2017_BSHM 131', 'BSHM', 'Bread and Pastry Production', 'Third Year', 'Second Semester', 1, 2, 1, 6, 'BSHM 55, BSHM 22'),
('2017_BSHM 141', 'BSHM', 'Cost Control', 'Second Year', 'First Semester', 2, 1, 2, 3, 'GNED 03'),
('2017_BSHM 151', 'BSHM', 'Strategic management in Tourism and Hospitality', 'Fourth Year', 'First Semester', 3, 0, 3, 0, 'NONE'),
('2017_BSHM 161', 'BSHM', 'Operations Management in Tourism and Hospitality Industry', 'Fourth Year', 'First Semester', 3, 0, 3, 0, 'BSHM 23'),
('2017_BSHM 199-A', 'BSHM', 'Hospitality Practicum 1-Housekeeping and Food and Beverage', 'Second Year', 'Second Semester', 0, 3, 0, 300, 'All 1st and 2nd year'),
('2017_BSHM 199-B', 'BSHM', 'PRACTICUM 2- Hotel Operations', 'Fourth Year', 'Second Semester', 0, 6, 0, 600, 'All Subjects'),
('2017_BSHM 21', 'BSHM', 'Fundamentals in Lodging Operations', 'First Year', 'First Semester', 3, 0, 3, 0, 'NONE'),
('2017_BSHM 22', 'BSHM', 'Kitchen Essentials & Basic Food Preparation', 'First Year', 'Second Semester', 2, 1, 2, 3, 'BSHM 55'),
('2017_BSHM 23', 'BSHM', 'Applied Business Tools and Technologies (GDS) with Lab', 'Second Year', 'First Semester', 2, 1, 2, 3, 'NONE'),
('2017_BSHM 24', 'BSHM', 'Supply Chain/Logistics Purchasing Management', 'Second Year', 'First Semester', 3, 0, 3, 0, 'NONE'),
('2017_BSHM 26', 'BSHM', 'Bar and Beverage Management with Lab', 'Second Year', 'First Semester', 2, 1, 2, 3, 'BSHM 101'),
('2017_BSHM 27', 'BSHM', 'Ergonomics & Facilities Planning for the Hospitality Industry', 'Third Year', 'Second Semester', 2, 1, 2, 3, 'NONE'),
('2017_BSHM 28', 'BSHM', 'Research in Hospitality', 'Fourth Year', 'First Semester', 2, 1, 2, 3, 'NONE'),
('2017_BSHM 29', 'BSHM', 'Introduction to MICE as applied in Hospitality', 'Third Year', 'First Semester', 2, 1, 2, 3, 'BSHM 55'),
('2017_BSHM 50', 'BSHM', 'Macro Perspective of Tourism & Hospitality', 'First Year', 'First Semester', 3, 1, 2, 3, 'NONE'),
('2017_BSHM 55', 'BSHM', 'Risk Management as Applied to Safety, Security and Sanitation', 'First Year', 'First Semester', 3, 0, 3, 0, 'NONE'),
('2017_BSHM 60', 'BSHM', 'Philippine Tourism, Geography and Culture', 'First Year', 'Second Semester', 3, 0, 3, 0, 'BSHM 50'),
('2017_BSHM 65', 'BSHM', 'Micro Perspective of Tourism and Hospitality', 'First Year', 'Second Semester', 3, 0, 3, 0, 'BSHM 50'),
('2017_BSHM 70', 'BSHM', 'Professional Development & Applied Ethics', 'Third Year', 'First Semester', 3, 0, 3, 0, 'GNED 02'),
('2017_BSHM 75', 'BSHM', 'Tourism and Hospitality Marketing', 'Third Year', 'First Semester', 3, 0, 3, 0, 'BSHM 23'),
('2017_BSHM 80', 'BSHM', 'Legal Aspect in Tourism and Hospitality', 'Third Year', 'Second Semester', 3, 0, 3, 0, 'BSHM 121'),
('2017_BSHM 85', 'BSHM', 'Multicultural Diversity in Workplace for the Tourism and Professional', 'Third Year', 'Second Semester', 3, 0, 3, 0, 'BSHM 70'),
('2017_BSHM 90', 'BSHM', 'Enterpreneurship in Tourism and Hospitality', 'Third Year', 'Second Semester', 3, 0, 3, 0, 'NONE'),
('2017_CVSU 101', 'BSHM', 'Institutional Orientation', 'First Year', 'First Semester', 1, 0, 1, 0, 'NONE'),
('2017_FITT 1', 'BSHM', 'Movement Enhancement', 'First Year', 'First Semester', 2, 0, 2, 0, 'NONE'),
('2017_FITT 2', 'BSHM', 'Fitness Exercises', 'First Year', 'Second Semester', 2, 0, 2, 0, 'FITT 1'),
('2017_FITT 3', 'BSHM', 'Physical Activities toward Health and Fitness 1', 'Second Year', 'First Semester', 2, 0, 2, 0, 'FITT 1'),
('2017_FITT 4', 'BSHM', 'Physical Activities toward Health and Fitness 2', 'Second Year', 'Second Semester', 2, 0, 2, 0, 'NONE'),
('2017_GNED 01', 'BSHM', 'Arts Appreciation', 'First Year', 'First Semester', 3, 0, 3, 0, 'NONE'),
('2017_GNED 02', 'BSHM', 'Ethics', 'First Year', 'First Semester', 3, 0, 3, 0, 'NONE'),
('2017_GNED 03', 'BSHM', 'Purposive Communication', 'First Year', 'Second Semester', 3, 0, 3, 0, 'NONE'),
('2017_GNED 04', 'BSHM', 'Mga Babasahin hinggil sa kasaysayan ng Pilipinas', 'Third Year', 'First Semester', 3, 0, 3, 0, 'NONE'),
('2017_GNED 06', 'BSHM', 'Science, Technology World', 'Third Year', 'Second Semester', 3, 0, 3, 0, 'NONE'),
('2017_GNED 07', 'BSHM', 'The Contemporary World', 'Fourth Year', 'First Semester', 3, 0, 3, 0, 'NONE'),
('2017_GNED 08', 'BSHM', 'Understanding the Self', 'First Year', 'First Semester', 3, 0, 3, 0, 'NONE'),
('2017_GNED 09', 'BSHM', 'Life and Works of Rizal', 'Fourth Year', 'First Semester', 3, 0, 3, 0, 'GNED 04'),
('2017_GNED 10', 'BSHM', 'Gender and Society', 'Fourth Year', 'First Semester', 3, 0, 3, 0, 'NONE'),
('2017_GNED 11', 'BSHM', 'Kontekstwalisadong komunikasyon sa Filipino', 'Third Year', 'First Semester', 3, 0, 3, 0, 'NONE'),
('2017_GNED 12', 'BSHM', 'Dalumat Ng/Sa Filipino', 'Third Year', 'Second Semester', 3, 0, 3, 0, 'GNED 11'),
('2017_GNED 14', 'BSHM', 'Panitikang Panlipunan', 'Second Year', 'First Semester', 3, 0, 3, 0, 'NONE'),
('2017_NSTP 1', 'BSHM', 'CWTS/LTS/ROTC', 'First Year', 'First Semester', 3, 0, 3, 0, 'NONE'),
('2017_NSTP 2', 'BSHM', 'CWTS/LTS/ROTC', 'First Year', 'Second Semester', 3, 0, 3, 0, 'NSTP 1'),
('2018_ALAN 21', 'BSIndT', 'Foreign Language', 'Third Year', 'First Semester', 3, 0, 3, 0, 'NONE'),
('2018_CHEM 14', 'BSCpE', 'Chemistry for Engineers', 'First Year', 'First Semester', 3, 1, 3, 3, 'NONE'),
('2018_COSC 100', 'BSCS', 'Automata Theory and Formal Languages', 'Fourth Year', 'First Semester', 3, 0, 3, 0, 'COSC 90'),
('2018_COSC 101', 'BSCS', 'CS Elective 1 (Computer Graphics and Visual Computing)', 'Third Year', 'First Semester', 2, 1, 2, 3, 'DCIT 23'),
('2018_COSC 105', 'BSCS', 'Intelligent Systems', 'Fourth Year', 'First Semester', 2, 1, 2, 3, 'MATH 4, COSC 55, DCIT 50'),
('2018_COSC 106', 'BSCS', 'CS Elective 2 (Introduction to Game Development)', 'Third Year', 'Second Semester', 2, 1, 2, 3, 'MATH 3, COSC 101'),
('2018_COSC 110', 'BSCS', 'Numerical and Symbolic Computation', 'Fourth Year', 'Second Semester', 2, 1, 2, 3, 'COSC 60'),
('2018_COSC 111', 'BSCS', 'CS Elective 3 (Internet of Things)', 'Fourth Year', 'First Semester', 2, 1, 2, 3, 'COSC 60'),
('2018_COSC 199', 'BSCS', 'Practicum (minimum of 240 hrs.)', 'Third Year', 'Mid Year', 3, 0, 0, 0, 'Incoming 4th yr.'),
('2018_COSC 200A', 'BSCS', 'Undergraduate Thesis I', 'Fourth Year', 'First Semester', 3, 0, 3, 0, '4th Year Standing'),
('2018_COSC 200B', 'BSCS', 'Undergraduate Thesis II', 'Fourth Year', 'Second Semester', 3, 0, 3, 0, 'COSC 200A'),
('2018_COSC 50', 'BSCS, BSIT', 'Discrete Structure', 'First Year', 'First Semester', 3, 0, 3, 0, 'NONE'),
('2018_COSC 55', 'BSCS', 'Discrete Structures II', 'Second Year', 'First Semester', 3, 0, 3, 0, 'COSC 50'),
('2018_COSC 60', 'BSCS', 'Digital Logic Design', 'Second Year', 'First Semester', 2, 1, 2, 3, 'COSC 50, DCIT 23'),
('2018_COSC 65', 'BSCS', 'Architecture and Organization', 'Second Year', 'Second Semester', 2, 1, 2, 3, 'COSC 60'),
('2018_COSC 70', 'BSCS', 'Software Engineering I', 'Second Year', 'Second Semester', 3, 0, 3, 0, 'DCIT 50, DCIT 24'),
('2018_COSC 75', 'BSCS', 'Software Engineering II', 'Third Year', 'First Semester', 2, 1, 2, 3, 'COSC 70'),
('2018_COSC 80', 'BSCS', 'Operating Systems', 'Third Year', 'First Semester', 2, 1, 2, 3, 'DCIT 25'),
('2018_COSC 85', 'BSCS', 'Networks and Communication', 'Third Year', 'First Semester', 2, 1, 2, 3, 'ITEC 50'),
('2018_COSC 90', 'BSCS', 'Design and Analysis of Algorithm', 'Third Year', 'Second Semester', 3, 0, 3, 0, 'DCIT 25'),
('2018_COSC 95', 'BSCS', 'Programming Languages', 'Third Year', 'Second Semester', 3, 0, 3, 0, 'DCIT 25'),
('2018_CPEN 100', 'BSCpE', 'Microprocessors and Microcontrollers Systems', 'Third Year', 'Second Semester', 3, 1, 3, 3, 'CPEN 75'),
('2018_CPEN 101', 'BSCpE', 'Elective Course #1', 'Third Year', 'First Semester', 3, 0, 3, 0, '3rd year standing'),
('2018_CPEN 105', 'BSCpE', 'Computer Networks and Security', 'Third Year', 'Second Semester', 3, 1, 3, 3, 'CPEN 85'),
('2018_CPEN 106', 'BSCpE', 'Elective Course #2', 'Third Year', 'Second Semester', 3, 0, 3, 0, 'CPEN 101'),
('2018_CPEN 110', 'BSCpE', 'CpE Laws and Professional Practice', 'Third Year', 'Second Semester', 2, 0, 2, 0, '3rd year standing'),
('2018_CPEN 111', 'BSCpE', 'Elective Course #3', 'Fourth Year', 'First Semester', 3, 0, 3, 0, 'CPEN 106'),
('2018_CPEN 115', 'BSCpE', 'Introduction to HDL', 'Third Year', 'Second Semester', 0, 1, 0, 3, 'CPEN 101,ECEN 60'),
('2018_CPEN 120', 'BSCpE', 'Embedded Systems', 'Fourth Year', 'First Semester', 3, 1, 3, 3, 'CPEN 100'),
('2018_CPEN 125', 'BSCpE', 'Computer Architecture and Organization', 'Fourth Year', 'First Semester', 3, 1, 3, 3, 'CPEN 100'),
('2018_CPEN 130', 'BSCpE', 'Emerging Technologies in CpE', 'Fourth Year', 'First Semester', 3, 0, 3, 0, '4th year standing'),
('2018_CPEN 135', 'BSCpE', 'Digital Signal Processing', 'Fourth Year', 'First Semester', 3, 1, 0, 3, 'DCEE 23'),
('2018_CPEN 140', 'BSCpE', 'Extreme Project Training', 'Fourth Year', 'Second Semester', 1, 0, 1, 0, 'Graduating Only'),
('2018_CPEN 190', 'BSCpE', 'Seminars and Fieldtrips', 'Fourth Year', 'Second Semester', 0, 1, 0, 3, '4th Year Standing'),
('2018_CPEN 200a', 'BSCpE', 'CpE Design Project 1', 'Fourth Year', 'First Semester', 0, 1, 0, 3, 'CPEN 100, DCEE 25'),
('2018_CPEN 200b', 'BSCpE', 'CpE Design Project 2', 'Fourth Year', 'Second Semester', 0, 2, 0, 6, 'CPEN 200a'),
('2018_CpEN 21', 'BSIndT', 'Programming Logic and Design', 'First Year', 'Second Semester', 0, 2, 0, 6, 'NONE'),
('2018_CPEN 21 CpE', 'BSCpE', 'Programming Logic and Design', 'First Year', 'First Semester', 0, 2, 0, 0, 'NONE'),
('2018_CPEN 50', 'BSCpE', 'Computer Engineering as Discipline', 'First Year', 'First Semester', 1, 0, 1, 0, 'NONE'),
('2018_CPEN 60', 'BSCpE', 'Object Oriented Programming', 'First Year', 'Second Semester', 0, 2, 0, 6, 'CPEN 21'),
('2018_CPEN 65', 'BSCpE', 'Data Structures and Algorithms', 'Second Year', 'First Semester', 0, 2, 0, 6, 'CPEN 60'),
('2018_CPEN 70', 'BSCpE', 'Fundamentals of Information Systems', 'Second Year', 'Second Semester', 0, 1, 0, 3, 'CPEN 65'),
('2018_CPEN 75', 'BSCpE', 'Logic Circuits and Design', 'Third Year', 'First Semester', 3, 1, 3, 3, 'ECEN 60'),
('2018_CPEN 80', 'BSCpE', 'Data and Digital Communications', 'Third Year', 'First Semester', 3, 0, 3, 0, 'ECEN 60'),
('2018_CPEN 85', 'BSCpE', 'Microprocessors of Network Routing and Sensors', 'Third Year', 'First Semester', 2, 1, 2, 3, 'ECEN 60'),
('2018_CPEN 90', 'BSCpE', 'Fundamentals of Engineering Drafting and Design', 'Third Year', 'First Semester', 0, 1, 0, 3, 'ECEN 60'),
('2018_CPEN 95', 'BSCpE', 'Operating Systems', 'Third Year', 'Second Semester', 2, 1, 2, 3, 'CPEN 65'),
('2018_CVSU 101', 'BSCpE, BSCS, BSIndT, BSIT', 'Institutional Orientation (non-credit)', 'First Year', 'First Semester', 1, 0, 1, 0, 'NONE'),
('2018_DCEE 21', 'BSCpE', 'Discrete Mathematics', 'First Year', 'Second Semester', 3, 0, 3, 0, 'MATH 11'),
('2018_DCEE 23', 'BSCpE', 'Numerical Methods and Analysis', 'Second Year', 'Second Semester', 2, 1, 2, 3, 'MATH 13'),
('2018_DCEE 24', 'BSCpE', 'Feedback and Control Systems', 'Third Year', 'First Semester', 3, 1, 3, 3, 'ECEN 60,DCEE 23'),
('2018_DCEE 25', 'BSCpE', 'Basic Occupational Health and Safety (BOSH)', 'Third Year', 'First Semester', 3, 0, 3, 0, '3rd year standing, EENG 50'),
('2018_DCEE 26', 'BSCpE', 'Methods of Research', 'Third Year', 'Second Semester', 2, 0, 2, 0, 'CPEN 75, GNED 05, MATH 14'),
('2018_DCIT 21', 'BSIT, BSCS', 'Introduction to Computing', 'First Year', 'First Semester', 2, 1, 2, 3, 'NONE'),
('2018_DCIT 22', 'BSCS, BSIT', 'Computer Programming 1', 'First Year', 'First Semester', 1, 2, 1, 6, 'NONE'),
('2018_DCIT 23', 'BSCS, BSIT', 'Computer Programming 2', 'First Year', 'Second Semester', 1, 2, 1, 6, 'DCIT 22'),
('2018_DCIT 24', 'BSIT, BSCS', 'Information Management', 'Second Year', 'First Semester', 2, 1, 2, 3, 'DCIT 23'),
('2018_DCIT 25', 'BSCS, BSIT', 'Data Structures and Algorithms', 'Second Year', 'Second Semester', 2, 1, 2, 3, 'DCIT 50'),
('2018_DCIT 26', 'BSCS, BSIT', 'Application Development and Emerging Technologies', 'Third Year', 'First Semester', 2, 1, 2, 3, 'DCIT 55'),
('2018_DCIT 50', 'BSIT, BSCS', 'Object Oriented Programming', 'Second Year', 'First Semester', 2, 1, 2, 3, 'DCIT 23'),
('2018_DCIT 55', 'BSCS, BSIT', 'Advanced Database System', 'Second Year', 'Second Semester', 2, 1, 2, 3, 'DCIT 24'),
('2018_DCIT 60', 'BSCS, BSIT', 'Methods of Research', 'Third Year', 'First Semester', 3, 0, 3, 0, '3rd Year Standing'),
('2018_DCIT 65', 'BSCS, BSIT', 'Social and Professional Issues', 'Fourth Year', 'First Semester', 3, 0, 3, 0, '4th Year Standing'),
('2018_DRAW 23', 'BSCpE', 'Computer Aided Drafting and Design(CADD)', 'Second Year', 'First Semester', 0, 1, 0, 3, 'NONE'),
('2018_DRAW 24', 'BSIndT', 'Technology Drawing 1 (CADD 2D)', 'First Year', 'First Semester', 0, 1, 0, 3, 'NONE'),
('2018_DRAW 25', 'BSIndT', 'Industrial Technology Drawing II (CADD 3D)', 'First Year', 'Second Semester', 0, 1, 0, 3, 'DRAW 24'),
('2018_ECEN 60', 'BSCpE', 'Electronics 1 (Electronic Devices and Circuits)', 'Second Year', 'Second Semester', 3, 1, 3, 3, 'EENG 50'),
('2018_EENG 50', 'BSCpE', 'Electrical Circuits 1', 'Second Year', 'First Semester', 3, 1, 3, 3, 'PHYS 14'),
('2018_ELEX 100', 'BSIndT', 'Programmable Controller Application', 'Third Year', 'Second Semester', 2, 3, 2, 9, 'ELEX 90, 95'),
('2018_ELEX 105', 'BSIndT', 'Industrial Robotics', 'Third Year', 'Second Semester', 2, 1, 2, 3, 'ELEX 90, 95'),
('2018_ELEX 199a', 'BSIndT', 'Supervised Industrial Training 1', 'First Year', 'Mid Year', 0, 3, 0, 240, 'ELEX 50, 55, 60, 65'),
('2018_ELEX 199b', 'BSIndT', 'Supervised Industrial Training 2', 'Fourth Year', 'First Semester', 0, 8, 0, 640, 'All Major Subjects ELEX 199a'),
('2018_ELEX 199c', 'BSIndT', 'Supervised Industrial Training 3', 'Fourth Year', 'Second Semester', 0, 8, 0, 640, 'ELEX 199b'),
('2018_ELEX 200a', 'BSIndT', 'ELEX Design Project 1', 'Third Year', 'First Semester', 0, 1, 0, 3, 'NONE'),
('2018_ELEX 200b', 'BSIndT', 'ELEX Design Project 2', 'Third Year', 'Second Semester', 0, 1, 0, 3, 'ELEX 200a'),
('2018_ELEX 50', 'BSIndT', 'Electronics Devices, Instruments and Circuit', 'First Year', 'First Semester', 2, 3, 2, 0, 'NONE'),
('2018_ELEX 55', 'BSIndT', 'Electronics Design and Fabrication', 'First Year', 'First Semester', 2, 1, 2, 3, 'NONE'),
('2018_ELEX 60', 'BSIndT', 'Electronics Communication 1', 'First Year', 'Second Semester', 2, 3, 2, 9, 'ELEX 50, 55'),
('2018_ELEX 65', 'BSIndT', 'Semiconductor Devices', 'First Year', 'Second Semester', 2, 1, 2, 3, 'ELEX 50, 55'),
('2018_ELEX 70', 'BSIndT', 'Electronics Communication 2', 'Second Year', 'First Semester', 2, 3, 2, 9, 'ELEX 60, 65'),
('2018_ELEX 75', 'BSIndT', 'Advanced Electronics', 'Second Year', 'First Semester', 2, 1, 2, 3, 'INDT 22, ELEX 65'),
('2018_ELEX 80', 'BSIndT', 'Instrumentation and Process Control', 'Second Year', 'Second Semester', 2, 3, 2, 9, 'ELEX 70, 75'),
('2018_ELEX 85', 'BSIndT', 'Sensor and Interfacing', 'Second Year', 'Second Semester', 2, 1, 2, 3, 'ELEX 70, 75'),
('2018_ELEX 90', 'BSIndT', 'Microprocessor and Interfacing', 'Third Year', 'First Semester', 2, 3, 2, 9, 'ELEX 80, 85'),
('2018_ELEX 95', 'BSIndT', 'Industrial Electronics', 'Third Year', 'First Semester', 2, 1, 2, 3, 'ELEX 80, 85'),
('2018_ENGS 29', 'BSIndT', 'Applied Thermodynamics', 'Third Year', 'Second Semester', 1, 1, 1, 3, 'INDT 24'),
('2018_ENGS 31', 'BSCpE', 'Engineering Economics', 'Second Year', 'First Semester', 3, 0, 3, 0, '2nd Year Standing'),
('2018_ENGS 32', 'BSIndT', 'Technopreneurship 101', 'Second Year', 'First Semester', 3, 0, 3, 0, 'NONE'),
('2018_ENGS 33', 'BSIndT', 'Environmental Science', 'Third Year', 'First Semester', 3, 0, 3, 0, 'NONE'),
('2018_ENGS 35', 'BSCpE', 'Technopreneurship 101', 'Third Year', 'Second Semester', 3, 0, 3, 0, '3rd year standing'),
('2018_ENOS 24b', 'BSIndT', 'Mechanics of Deformable Bodies', 'Second Year', 'Second Semester', 3, 0, 3, 0, 'NONE'),
('2018_FITT 1', 'BSIndT, BSIT, BSCS, BSCpE', 'Movement Enhancement', 'First Year', 'First Semester', 2, 0, 2, 0, 'NONE'),
('2018_FITT 2', 'BSCpE, BSCS, BSIndT, BSIT', 'Fitness Exercises', 'First Year', 'Second Semester', 2, 0, 2, 0, 'FITT 1'),
('2018_FITT 3', 'BSCpE, BSCS, BSIndT, BSIT', 'Physical Activities Towards Health and Fitness I', 'Second Year', 'First Semester', 2, 0, 2, 0, 'FITT 1'),
('2018_FITT 4', 'BSCpE, BSCS, BSIndT, BSIT', 'Fitness II', 'Second Year', 'Second Semester', 2, 0, 2, 0, 'FITT 1'),
('2018_GNED 01', 'BSCpE, BSCS, BSIndT, BSIT', 'Art Appreciation', 'Second Year', 'Second Semester', 3, 0, 3, 0, 'NONE'),
('2018_GNED 02', 'BSCpE, BSCS, BSIndT, BSIT', 'Ethics', 'Second Year', 'Second Semester', 3, 0, 3, 0, 'NONE'),
('2018_GNED 03', 'BSCpE, BSCS, BSIndT, BSIT', 'Mathematics in the Modern World', 'First Year', 'First Semester', 3, 0, 3, 0, 'NONE'),
('2018_GNED 04', 'BSCS, BSIndT, BSIT', 'Mga Babasahin Hinggil sa Kasaysayan', 'Second Year', 'First Semester', 3, 0, 3, 0, 'NONE'),
('2018_GNED 05', 'BSCpE, BSCS, BSIndT, BSIT', 'Purposive Communication', 'First Year', 'First Semester', 3, 0, 3, 0, 'NONE'),
('2018_GNED 06', 'BSCpE, BSCS, BSIndT, BSIT', 'Science, Technology and Society', 'First Year', 'Second Semester', 3, 0, 3, 0, 'NONE'),
('2018_GNED 07', 'BSCpE, BSCS, BSIndT, BSIT', 'The Contemporary World', 'Second Year', 'Second Semester', 3, 0, 3, 0, 'NONE'),
('2018_GNED 08', 'BSCpE, BSCS, BSIndT, BSIT', 'Understanding the Self', 'Second Year', 'First Semester', 3, 0, 3, 0, 'NONE'),
('2018_GNED 09', 'BSCpE, BSCS, BSIndT, BSIT', 'Life and Works of Rizal', 'Second Year', 'Second Semester', 3, 0, 3, 0, 'GNED 04'),
('2018_GNED 10', 'BSCpE, BSCS, BSIndT, BSIT', 'Gender and Society', 'Second Year', 'First Semester', 3, 0, 3, 0, 'NONE'),
('2018_GNED 11', 'BSCpE, BSCS, BSIndT, BSIT', 'Kontextwalisadong Komunikasyon sa Filipino', 'First Year', 'First Semester', 3, 0, 3, 0, 'NONE'),
('2018_GNED 12', 'BSCpE, BSCS, BSIndT, BSIT', 'Dalumat ng/sa Filipino', 'First Year', 'Second Semester', 3, 0, 3, 0, 'GNED 10'),
('2018_GNED 14', 'BSCpE, BSCS, BSIndT, BSIT', 'Panitikan Filipino', 'Second Year', 'Second Semester', 3, 0, 3, 0, 'GNED 11'),
('2018_INDT 21', 'BSIndT', 'Basic Occupational Health and Safety', 'First Year', 'First Semester', 2, 0, 2, 0, 'NONE'),
('2018_INDT 22', 'BSIndT', 'Digital Electronics', 'First Year', 'Second Semester', 1, 1, 1, 3, 'NONE'),
('2018_INDT 23', 'BSIndT', 'Programmable Controls', 'Second Year', 'Second Semester', 1, 1, 1, 3, 'INDT 22'),
('2018_INDT 24', 'BSIndT', 'Pneumatics and Hydraulics (P&H)', 'Third Year', 'First Semester', 1, 1, 1, 3, 'INDT 23'),
('2018_INDT 25', 'BSIndT', 'Intellectual Property Rights', 'Third Year', 'First Semester', 3, 0, 3, 0, 'NONE'),
('2018_INDT 26', 'BSIndT', 'Human Resources Management for Technology', 'Third Year', 'First Semester', 3, 0, 3, 0, 'NONE'),
('2018_INDT 27', 'BSIndT', 'Materials and Business Technology Management', 'Third Year', 'First Semester', 3, 0, 3, 0, 'NONE'),
('2018_INDT 28', 'BSIndT', 'Industrial Organization and Management Practice', 'Third Year', 'Second Semester', 3, 0, 3, 0, 'INDT 26'),
('2018_INDT 29', 'BSIndT', 'Quality Control', 'Third Year', 'Second Semester', 3, 0, 3, 0, 'INDT 27'),
('2018_INDT 30', 'BSIndT', 'Production Technology Management', 'Third Year', 'Second Semester', 3, 0, 3, 0, 'INDT 27'),
('2018_INSY 50', 'BSCS', 'Fundamentals of Information Systems', 'Second Year', 'First Semester', 3, 0, 3, 0, 'DCIT 21'),
('2018_INSY 55', 'BSIT', 'System Analysis and Design', 'Third Year', 'First Semester', 2, 1, 2, 3, '3rd Year Standing'),
('2018_ITEC 100', 'BSIT', 'Information Assurance and Security 2', 'Third Year', 'Second Semester', 2, 1, 2, 3, 'ITEC 85'),
('2018_ITEC 101', 'BSIT', 'IT Elective 1 (Human Computer Interaction 2)', 'Third Year', 'Second Semester', 2, 1, 2, 3, 'ITEC 80'),
('2018_ITEC 105', 'BSIT', 'Network Management', 'Third Year', 'Second Semester', 2, 1, 2, 3, 'ITEC 90'),
('2018_ITEC 106', 'BSIT', 'IT Elective 2 (Web System and Technologies 2)', 'Third Year', 'Second Semester', 2, 1, 2, 3, 'ITEC 50'),
('2018_ITEC 110', 'BSIT', 'Systems Administration and Maintenance', 'Fourth Year', 'First Semester', 2, 1, 2, 3, 'ITEC 101'),
('2018_ITEC 111', 'BSIT', 'IT Elective 3 (Integrated Programming and Technologies 2)', 'Fourth Year', 'First Semester', 2, 1, 2, 3, 'ITEC 101'),
('2018_ITEC 116', 'BSIT', 'IT Elective 4 (Systems Integration and Architecture 2)', 'Fourth Year', 'First Semester', 2, 1, 2, 3, 'ITEC 75'),
('2018_ITEC 199', 'BSIT', 'Practicum (minimum 486 hours)', 'Fourth Year', 'Second Semester', 6, 0, 0, 0, 'DCIT 26, ITEC 85, 70% Total Units taken'),
('2018_ITEC 200A', 'BSIT', 'Capstone Project and Research 1', 'Third Year', 'Second Semester', 3, 0, 3, 0, 'DCIT 60, DCIT 26, ITEC 85, 70% Total Units taken'),
('2018_ITEC 200B', 'BSIT', 'Capstone Project and Research 2', 'Fourth Year', 'First Semester', 3, 0, 3, 0, 'ITEC 200A'),
('2018_ITEC 50', 'BSCS, BSIT', 'Web System and Technologies 1', 'First Year', 'Second Semester', 2, 1, 2, 3, 'DCIT 21'),
('2018_ITEC 55', 'BSIT', 'Platform Technologies', 'Second Year', 'First Semester', 2, 1, 2, 3, 'DCIT 23'),
('2018_ITEC 60', 'BSIT', 'Integrated Programming and Technologies 1', 'Second Year', 'Second Semester', 2, 1, 2, 3, 'DCIT 50, ITEC 50'),
('2018_ITEC 65', 'BSIT', 'Open Source Technology', 'Second Year', 'Second Semester', 2, 1, 2, 3, '2nd Year Standing'),
('2018_ITEC 70', 'BSIT', 'Multimedia Systems', 'Second Year', 'Second Semester', 2, 1, 2, 3, '2nd Year Standing'),
('2018_ITEC 75', 'BSIT', 'System Integration and Architecture 1', 'Second Year', 'Mid Year', 2, 1, 2, 3, 'ITEC 60'),
('2018_ITEC 80', 'BSCS, BSIT', 'Introduction to Human Computer Interaction', 'Third Year', 'First Semester', 2, 1, 2, 3, '3rd Year Standing'),
('2018_ITEC 85', 'BSCS, BSIT', 'Information Assurance and Security 1', 'Third Year', 'First Semester', 2, 1, 2, 3, 'ITEC 75'),
('2018_ITEC 90', 'BSIT', 'Network Fundamentals', 'Third Year', 'First Semester', 2, 1, 2, 3, 'ITEC 55'),
('2018_ITEC 95', 'BSIT', 'Quantitative Methods (Modeling & Simulation)', 'Third Year', 'Second Semester', 3, 0, 3, 3, 'COSC 50, STAT 2'),
('2018_MATH 1', 'BSCS', 'Analytic Geometry', 'Second Year', 'First Semester', 3, 0, 3, 0, 'GNED 03'),
('2018_MATH 11', 'BSCpE, BSIndT', 'Differential and Integral Calculus', 'Second Year', 'First Semester', 3, 0, 3, 0, 'NONE'),
('2018_MATH 12', 'BSCpE', 'Calculus 2', 'First Year', 'Second Semester', 3, 0, 3, 0, 'MATH 11'),
('2018_MATH 13', 'BSCpE', 'Differential Equations', 'Second Year', 'First Semester', 3, 0, 3, 0, 'MATH 12'),
('2018_MATH 14', 'BSCpE', 'Engineering Data Analysis', 'Third Year', 'First Semester', 3, 0, 3, 0, 'MATH 11'),
('2018_MATH 2', 'BSCS', 'Calculus', 'Second Year', 'Second Semester', 3, 0, 3, 0, 'MATH 1'),
('2018_MATH 3', 'BSCS', 'Linear Algebra', 'Third Year', 'First Semester', 3, 0, 3, 0, 'MATH 2'),
('2018_MATH 4', 'BSCS', 'Experimental Statistics', 'Third Year', 'Second Semester', 2, 1, 2, 3, 'MATH 2'),
('2018_NSTP 1', 'BSCpE, BSCS, BSIndT, BSIT', 'National Service Training Program I', 'First Year', 'First Semester', 3, 0, 3, 0, 'NONE'),
('2018_NSTP 2', 'BSCpE, BSCS, BSIndT, BSIT', 'National Service Training Program 2', 'First Year', 'Second Semester', 3, 0, 3, 0, 'NSTP 1'),
('2018_PHYS 14', 'BSCpE', 'Physics for Engineers', 'First Year', 'Second Semester', 3, 1, 3, 3, 'CHEM 14'),
('2018_STAT 2', 'BSIT', 'Applied Statistics', 'Second Year', 'Mid Year', 3, 0, 3, 0, '2nd Year Standing'),
('2021_BSEE 110', 'BSEd-English', 'Stylistics and Discourse Analysis', 'Second Year', 'First Semester', 3, 0, 3, 0, 'NONE'),
('2021_BSEE 111', 'BSEd-English', 'English for Specific Purposes', 'Third Year', 'First Semester', 3, 0, 3, 0, 'NONE'),
('2021_BSEE 21', 'BSEd-English', 'Introduction to Linguistics', 'First Year', 'First Semester', 2, 0, 3, 0, 'NONE'),
('2021_BSEE 22', 'BSEd-English', 'Language Culture and Society', 'First Year', 'Second Semester', 3, 0, 3, 0, 'BSEE 21'),
('2021_BSEE 23', 'BSEd-English', 'Structure of English', 'Second Year', 'First Semester', 3, 0, 3, 0, 'BSEE 21'),
('2021_BSEE 24', 'BSEd-English', 'Principles and Theories of Language Acquisition and Learning', 'Second Year', 'First Semester', 3, 0, 3, 0, 'BSEE 22'),
('2021_BSEE 25', 'BSEd-English', 'Language Programs and Policies in Multilingual Society', 'Second Year', 'Second Semester', 3, 0, 3, 0, 'BSEE 24'),
('2021_BSEE 26', 'BSEd-English', 'Language Learning Materials Development', 'Second Year', 'Second Semester', 3, 0, 3, 0, 'BSEE 24, EDUC 85'),
('2021_BSEE 27', 'BSEd-English', 'Teaching and Assessment of Literature', 'Third Year', 'Second Semester', 3, 0, 3, 0, 'BSEE 38'),
('2021_BSEE 28', 'BSEd-English', 'Teaching and Assessment of Macroskills', 'Third Year', 'First Semester', 3, 0, 3, 0, 'BSEE 22'),
('2021_BSEE 29', 'BSEd-English', 'Teaching and Assessment of Grammar', 'Third Year', 'First Semester', 3, 0, 3, 0, 'BSEE 23'),
('2021_BSEE 30', 'BSEd-English', 'Speech and Theater Arts', 'Second Year', 'Midyear', 3, 0, 3, 0, 'BSEE 23'),
('2021_BSEE 31', 'BSEd-English', 'Language and Education Research', 'Third Year', 'Second Semester', 3, 0, 3, 0, 'BSEE 24'),
('2021_BSEE 32', 'BSEd-English', 'Children and Adolescent Literature', 'Second Year', 'Second Semester', 3, 0, 3, 0, 'BSEE 24'),
('2021_BSEE 33', 'BSEd-English', 'Mythology and Folklore', 'Second Year', 'First Semester', 3, 0, 3, 0, 'BSEE 22'),
('2021_BSEE 34', 'BSEd-English', 'Survey of Philippine Literature in English', 'Second Year', 'Midyear', 3, 0, 3, 0, 'BSEE 32'),
('2021_BSEE 35', 'BSEd-English', 'Survey of Afro-Asian Literature', 'Second Year', 'Midyear', 3, 0, 3, 0, 'BSEE 32'),
('2021_BSEE 36', 'BSEd-English', 'Survey of English and American Literature', 'Third Year', 'First Semester', 3, 0, 3, 0, 'BSEE 32'),
('2021_BSEE 37', 'BSEd-English', 'Contemporary, Popular, and Emergent Literature', 'Third Year', 'First Semester', 3, 0, 3, 0, 'BSEE 32'),
('2021_BSEE 38', 'BSEd-English', 'Literary Criticism', 'Third Year', 'Second Semester', 3, 0, 3, 0, 'BSEE 32, BSEE 33, BSEE 34, BSEE 35, BSEE 36, BSEE 37'),
('2021_BSEE 39', 'BSEd-English', 'Technical Writing', 'Second Year', 'Second Semester', 3, 0, 3, 0, 'BSEE 23'),
('2021_BSEE 40', 'BSEd-English', 'Campus Journalism', 'Third Year', 'Second Semester', 3, 0, 3, 0, 'BSEE 39'),
('2021_BSEE 41', 'BSEd-English', 'Technology for Teaching and Learning 2 (Technology in Secondary Language Education)', 'Third Year', 'Second Semester', 2, 0, 3, 0, 'EDUC 85'),
('2021_BSEM 21', 'BSEd-Math', 'History of Mathematics', 'First Year', 'Midyear', 3, 0, 3, 0, 'NONE'),
('2021_BSEM 22', 'BSEd-Math', 'College and Advanced Algebra', 'First Year', 'Midyear', 3, 0, 3, 0, 'NONE'),
('2021_BSEM 23', 'BSEd-Math', 'Trigonometry', 'Second Year', 'First Semester', 3, 0, 3, 0, 'BSEM 22'),
('2021_BSEM 24', 'BSEd-Math', 'Plane and Solid Geometry', 'Second Year', 'First Semester', 3, 0, 3, 0, 'BSEM 22'),
('2021_BSEM 25', 'BSEd-Math', 'Logic and Set Theory', 'Second Year', 'First Semester', 3, 0, 3, 0, 'NONE'),
('2021_BSEM 26', 'BSEd-Math', 'Elementary Statistics and Probability', 'Second Year', 'First Semester', 3, 0, 3, 0, 'NONE'),
('2021_BSEM 27', 'BSEd-Math', 'Calculus 1 with Analytic Geometry', 'Third Year', 'First Semester', 4, 0, 4, 0, 'BSEM 22, BSEM 23,BSEM 30'),
('2021_BSEM 28', 'BSEd-Math', 'Calculus 2', 'Third Year', 'Second Semester', 4, 0, 4, 0, 'BSEM 27'),
('2021_BSEM 29', 'BSEd-Math', 'Calculus 3', 'Third Year', 'Midyear', 3, 0, 3, 0, 'BSEM 28'),
('2021_BSEM 30', 'BSEd-Math', 'Modern Geometry', 'Second Year', 'Second Semester', 3, 0, 3, 0, 'BSEM 24, BSEM 25'),
('2021_BSEM 31', 'BSEd-Math', 'Mathematics of Investment', 'Third Year', 'Second Semester', 3, 0, 3, 0, 'BSEM 22'),
('2021_BSEM 32', 'BSEd-Math', 'Number Theory', 'Third Year', 'First Semester', 3, 0, 3, 0, 'BSEM 22, BSEM 25'),
('2021_BSEM 33', 'BSEd-Math', 'Linear Algebra', 'Second Year', 'Second Semester', 3, 0, 3, 0, 'BSEM 25'),
('2021_BSEM 34', 'BSEd-Math', 'Advanced Statistics', 'Second Year', 'Second Semester', 3, 0, 3, 0, 'BSEM 26'),
('2021_BSEM 35', 'BSEd-Math', 'Problem-Solving, Mathematical Investigations and Modelling', 'Third Year', 'First Semester', 3, 0, 3, 0, 'BSEM 22, BSEM 25, BSEM 30'),
('2021_BSEM 36', 'BSEd-Math', 'Principles and Methods of Teaching Mathematics', 'Second Year', 'Second Semester', 3, 0, 3, 0, 'NONE'),
('2021_BSEM 37', 'BSEd-Math', 'Abstract Algebra', 'Third Year', 'Second Semester', 3, 0, 3, 0, 'BSEM 25'),
('2021_BSEM 38', 'BSEd-Math', 'Research in Mathematics', 'Third Year', 'First Semester', 4, 0, 4, 0, 'BSEM 34'),
('2021_BSEM 39', 'BSEd-Math', 'Technology for Teaching and Learning 2 (Instrumentation & Technology in Mathematics)', 'Third Year', 'First Semester', 3, 0, 3, 0, 'EDUC 75, EDUC 80, EDUC 85'),
('2021_BSEM 40', 'BSEd-Math', 'Assessment and Evaluation in Mathematics', 'Third Year', 'Second Semester', 3, 0, 3, 0, 'BSEM 34'),
('2021_BSES 21', 'BSEd-Science', 'Genetics', 'Second Year', 'First Semester', 3, 1, 3, 3, 'NONE'),
('2021_BSES 22', 'BSEd-Science', 'Cell and Molecular Biology', 'Third Year', 'First Semester', 3, 1, 3, 3, 'BSES 21 & 27'),
('2021_BSES 23', 'BSEd-Science', 'Microbiology and Parasitology', 'Third Year', 'Second Semester', 3, 1, 3, 3, 'NONE'),
('2021_BSES 24', 'BSEd-Science', 'Anatomy and Physiology', 'Third Year', 'Second Semester', 3, 1, 3, 3, 'NONE'),
('2021_BSES 25', 'BSEd-Science', 'Inorganic Chemistry', 'First Year', 'Midyear', 3, 2, 3, 6, 'NONE'),
('2021_BSES 26', 'BSEd-Science', 'Organic Chemistry', 'Second Year', 'First Semester', 3, 2, 3, 6, 'BSES 25'),
('2021_BSES 27', 'BSEd-Science', 'Biochemistry', 'Second Year', 'Second Semester', 3, 0, 3, 0, 'BSES 26'),
('2021_BSES 28', 'BSEd-Science', 'Analytical Chemistry', 'Second Year', 'Midyear', 3, 2, 3, 6, 'BSES 25'),
('2021_BSES 29', 'BSEd-Science', 'Thermodynamics', 'Second Year', 'First Semester', 3, 1, 3, 3, 'HS Physics-Mechanics'),
('2021_BSES 30', 'BSEd-Science', 'Fluid Mechanics', 'First Year', 'Second Semester', 3, 0, 3, 0, 'NONE'),
('2021_BSES 31', 'BSEd-Science', 'Electricity and Magnetism', 'Second Year', 'Second Semester', 3, 1, 3, 3, 'BSES 30'),
('2021_BSES 32', 'BSEd-Science', 'Waves and Optics', 'Third Year', 'First Semester', 3, 1, 3, 3, 'BSES 30 & 31'),
('2021_BSES 33', 'BSEd-Science', 'Modern Physics', 'Third Year', 'Second Semester', 3, 0, 3, 0, 'BSES 30 & 31'),
('2021_BSES 34', 'BSEd-Science', 'Earth Science', 'Second Year', 'Second Semester', 3, 0, 3, 0, 'NONE'),
('2021_BSES 35', 'BSEd-Science', 'Astronomy', 'Third Year', 'Second Semester', 3, 0, 3, 0, 'NONE'),
('2021_BSES 36', 'BSEd-Science', 'Environmental Science', 'Second Year', 'Second Semester', 3, 0, 3, 0, 'NONE'),
('2021_BSES 37', 'BSEd-Science', 'The Teaching of Science', 'Third Year', 'Second Semester', 3, 0, 3, 0, 'NONE'),
('2021_BSES 38', 'BSEd-Science', 'Technology for Teaching and Learning 2 (for Science)', 'Third Year', 'First Semester', 3, 0, 3, 0, 'EDUC 75, 80, 85'),
('2021_BSES 39', 'BSEd-Science', 'Research in Teaching', 'Third Year', 'First Semester', 3, 0, 3, 0, 'NONE'),
('2021_BSES 40', 'BSEd-Science', 'Meteorology', 'Third Year', 'First Semester', 3, 0, 3, 0, 'NONE'),
('2021_CVSU 101', 'BSEd-English, BSEd-Math, BSEd-Science', 'Institutional Orientation', 'First Year', 'First Semester', 1, 0, 1, 0, 'NONE'),
('2021_EDFS 21', 'BSEd-English, BSEd-Math, BSEd-Science', 'Field Study 1 - Observations of Teaching-Learning in Actual School Environment', 'Third Year', 'First Semester', 3, 0, 3, 0, 'PROF Ed Courses'),
('2021_EDFS 22', 'BSEd-English, BSEd-Math, BSEd-Science', 'Field Study 2 - Participation and Teaching Assistantship', 'Third Year', 'Second Semester', 3, 0, 3, 0, 'EDFS 21'),
('2021_EDFS 23', 'BSEd-English, BSEd-Science, BSEd-Math', 'Teaching Internship', 'Fourth Year', 'First Semester', 6, 0, 0, 40, 'EDFS 21, EDFS 22'),
('2021_EDUC 197', 'BSEd-English, BSEd-Math, BSEd-Science', 'Competency Appraisal 1', 'Third Year', 'Second Semester', 3, 0, 3, 0, 'PROF Ed Courses'),
('2021_EDUC 198', 'BSEd-English, BSEd-Science, BSEd-Math', 'Competency Appraisal 2', 'Fourth Year', 'Second Semester', 3, 0, 3, 0, 'EDUC 197'),
('2021_EDUC 50', 'BSEd-English, BSEd-Math, BSEd-Science', 'Child and Adolescent Learner and Learning Principles', 'First Year', 'Midyear', 3, 0, 3, 0, 'NONE'),
('2021_EDUC 55', 'BSEd-English, BSEd-Science, BSEd-Math', 'The Teaching Profession', 'First Year', 'First Semester', 3, 0, 3, 0, 'NONE'),
('2021_EDUC 60', 'BSEd-English, BSEd-Science, BSEd-Math', 'The Teacher and The Community, School Culture and Organizational Leadership', 'First Year', 'First Semester', 3, 0, 3, 0, 'NONE'),
('2021_EDUC 65', 'BSEd-English, BSEd-Science, BSEd-Math', 'Foundation of Special and Inclusive Education', 'First Year', 'Second Semester', 3, 0, 3, 0, 'NONE'),
('2021_EDUC 70', 'BSEd-English, BSEd-Math, BSEd-Science', 'Facilitating Learner-Centered Teaching', 'First Year', 'Midyear', 3, 0, 3, 0, 'NONE'),
('2021_EDUC 75', 'BSEd-English, BSEd-Science, BSEd-Math', 'Assessment in Learning 1', 'Second Year', 'First Semester', 3, 0, 3, 0, 'NONE'),
('2021_EDUC 80', 'BSEd-English, BSEd-Science, BSEd-Math', 'Assessment in Learning 2', 'Second Year', 'Second Semester', 3, 0, 3, 0, 'NONE'),
('2021_EDUC 85', 'BSEd-English, BSEd-Science, BSEd-Math', 'Technology for Teaching and Learning 1', 'First Year', 'Second Semester', 3, 0, 3, 0, 'NONE'),
('2021_EDUC 90', 'BSEd-English, BSEd-Science, BSEd-Math', 'The Teacher and The School Curriculum', 'Second Year', 'First Semester', 3, 0, 3, 0, 'NONE'),
('2021_EDUC 95', 'BSEd-English, BSEd-Science, BSEd-Math', 'Building and Enhancing New Literacies Across the Curriculum', 'Second Year', 'Second Semester', 3, 0, 3, 0, 'NONE'),
('2021_FITT 1', 'BSEd-English, BSEd-Science, BSEd-Math', 'Movement Enhancement', 'First Year', 'First Semester', 2, 0, 2, 0, 'NONE'),
('2021_FITT 2', 'BSEd-English, BSEd-Science, BSEd-Math', 'Fitness Exercises', 'First Year', 'Second Semester', 2, 0, 2, 0, 'FITT 1'),
('2021_FITT 3', 'BSEd-English, BSEd-Science, BSEd-Math', 'Physical Activities towards Health and Fitness I', 'Second Year', 'First Semester', 2, 0, 2, 0, 'FITT 1'),
('2021_FITT 4', 'BSEd-English, BSEd-Science, BSEd-Math', 'Physical Activities towards Health and Fitness II', 'Second Year', 'Second Semester', 2, 0, 2, 0, 'FITT 1'),
('2021_GNED 01', 'BSEd-English, BSEd-Science, BSEd-Math', 'Art Appreciation', 'Fourth Year', 'Second Semester', 3, 0, 3, 0, 'NONE'),
('2021_GNED 02', 'BSEd-English, BSEd-Science, BSEd-Math', 'Ethics', 'Fourth Year', 'Second Semester', 3, 0, 3, 0, 'NONE'),
('2021_GNED 03', 'BSEd-English, BSEd-Science, BSEd-Math', 'Mathematics in the Modern World', 'First Year', 'First Semester', 3, 0, 3, 0, 'NONE'),
('2021_GNED 04', 'BSEd-English, BSEd-Science, BSEd-Math', 'Mga Babasahin Hinggil sa Kasaysayan ng Pilipinas', 'Second Year', 'First Semester', 3, 0, 3, 0, 'NONE'),
('2021_GNED 05', 'BSEd-English, BSEd-Science, BSEd-Math', 'Purposive Communication', 'First Year', 'First Semester', 3, 0, 3, 0, 'NONE'),
('2021_GNED 06', 'BSEd-English, BSEd-Math, BSEd-Science', 'Science, Technology and Society', 'First Year', 'Second Semester', 3, 0, 3, 0, 'NONE'),
('2021_GNED 07', 'BSEd-English, BSEd-Math, BSEd-Science', 'The Contemporary World', 'Fourth Year', 'Second Semester', 3, 0, 3, 0, 'NONE'),
('2021_GNED 08', 'BSEd-English, BSEd-Science, BSEd-Math', 'Understanding the Self', 'First Year', 'Second Semester', 3, 0, 3, 0, 'NONE'),
('2021_GNED 09', 'BSEd-English, BSEd-Science, BSEd-Math', 'Life and Works of Rizal', 'Fourth Year', 'Second Semester', 3, 0, 3, 0, 'GNED 04'),
('2021_GNED 10', 'BSEd-English, BSEd-Science, BSEd-Math', 'Gender and Society', 'Fourth Year', 'Second Semester', 3, 0, 3, 0, 'NONE'),
('2021_GNED 11', 'BSEd-English, BSEd-Science, BSEd-Math', 'Kontekstwalisadong Komunikasyon sa Filipino', 'First Year', 'First Semester', 3, 0, 3, 0, 'NONE'),
('2021_GNED 12', 'BSEd-English, BSEd-Science, BSEd-Math', 'Dalumat Ng/Sa Filipino', 'Third Year', 'First Semester', 3, 0, 3, 0, 'GNED 11'),
('2021_GNED 13', 'BSEd-English, BSEd-Math, BSEd-Science', 'Retorika: Masining na Pagpapahayag', 'Fourth Year', 'Second Semester', 3, 0, 3, 0, 'GNED 11, GNED 12'),
('2021_GNED 14', 'BSEd-English, BSEd-Science, BSEd-Math', 'Panitikang Panlipunan', 'First Year', 'Second Semester', 3, 0, 3, 0, 'NONE'),
('2021_GNED 15', 'BSEd-English, BSEd-Science, BSEd-Math', 'World Literature', 'Second Year', 'Second Semester', 3, 0, 3, 0, 'NONE'),
('2021_NSTP 1', 'BSEd-English, BSEd-Science, BSEd-Math', 'CWTS/LTS/ROTC', 'First Year', 'First Semester', 3, 0, 3, 0, 'NONE'),
('2021_NSTP 2', 'BSEd-English, BSEd-Science, BSEd-Math', 'CWTS/LTS/ROTC', 'First Year', 'Second Semester', 3, 0, 3, 0, 'NSTP 1'),
('2023_BADM 199', 'BSBA-HRM, BSBA-MM', 'Practicum Integrated Learning 2 (600 hours)', 'Fourth Year', 'Second Semester', 6, 0, 6, 0, 'All Subjects'),
('2023_BADM 200', 'BSBA-HRM, BSBA-MM', 'Research/EDP Proposal', 'Fourth Year', 'First Semester', 1, 0, 1, 0, 'All Major Subjects'),
('2023_BADM 21', 'BSBA-HRM, BSBA-MM', 'Quantitative Techniques in Business', 'First Year', 'Second Semester', 3, 0, 3, 0, 'NONE'),
('2023_BADM 22', 'BSBA-HRM, BSBA-MM', 'Human Resource Management', 'First Year', 'Second Semester', 3, 0, 3, 0, 'NONE'),
('2023_BADM 23', 'BSBA-HRM, BSBA-MM', 'Business Law (Obligations and Contracts)', 'First Year', 'Second Semester', 3, 0, 3, 0, 'NONE'),
('2023_BADM 24', 'BSBA-HRM, BSBA-MM', 'Operations Management', 'Second Year', 'First Semester', 3, 0, 3, 0, 'BADM 21'),
('2023_BADM 25', 'BSBA-HRM, BSBA-MM', 'Good Governance and Social Responsibility', 'Second Year', 'Second Semester', 3, 0, 3, 0, 'NONE'),
('2023_BADM 26', 'BSBA-HRM, BSBA-MM', 'Business Research', 'Third Year', 'First Semester', 3, 0, 3, 0, 'NONE'),
('2023_BADM 27', 'BSBA-HRM, BSBA-MM', 'Strategic Management', 'Fourth Year', 'First Semester', 3, 0, 3, 0, 'All Major Subjects'),
('2023_BMGT 200b', 'BSBA-HRM, BSBA-MM', 'Research/EDP Final Manuscript', 'Fourth Year', 'Second Semester', 2, 0, 2, 0, 'BADM 200'),
('2023_ECON 23', 'BSBA-HRM, BSBA-MM', 'Basic Microeconomics', 'First Year', 'First Semester', 3, 0, 3, 0, 'NONE'),
('2023_ECON 24', 'BSBA-HRM, BSBA-MM', 'International Trade and Agreements', 'Second Year', 'First Semester', 3, 0, 3, 0, 'NONE'),
('2023_ELEC 1', 'BSBA-HRM, BSBA-MM', 'Human Resource Management System', 'Third Year', 'Second Semester', 3, 0, 3, 0, '3rd Year Standing'),
('2023_ELEC 2', 'BSBA-HRM, BSBA-MM', 'Logistic Management', 'Fourth Year', 'First Semester', 3, 0, 3, 0, 'NONE'),
('2023_ELEC 3', 'BSBA-HRM, BSBA-MM', 'Marketing Management', 'Fourth Year', 'First Semester', 3, 0, 3, 0, 'NONE'),
('2023_ELEC 4', 'BSBA-HRM, BSBA-MM', 'Strategic Human Resource Management', 'Fourth Year', 'First Semester', 3, 0, 3, 0, 'NONE'),
('2023_FITT 1', 'BSBA-HRM, BSBA-MM', 'Movement Enhancement', 'First Year', 'First Semester', 2, 0, 2, 0, 'NONE'),
('2023_FITT 2', 'BSBA-HRM, BSBA-MM', 'Fitness Exercises', 'First Year', 'Second Semester', 2, 0, 2, 0, 'FITT 1'),
('2023_FITT 3', 'BSBA-HRM, BSBA-MM', 'Physical Activities towards Health and Fitness I', 'Second Year', 'First Semester', 2, 0, 2, 0, 'FITT 1'),
('2023_FITT 4', 'BSBA-HRM, BSBA-MM', 'Physical Activities towards Health and Fitness II', 'Second Year', 'Second Semester', 2, 0, 2, 0, 'FITT 1'),
('2023_GNED 01', 'BSBA-HRM, BSBA-MM', 'Art Appreciation', 'First Year', 'Second Semester', 3, 0, 3, 0, 'NONE'),
('2023_GNED 02', 'BSBA-HRM, BSBA-MM', 'Ethics', 'First Year', 'Second Semester', 3, 0, 3, 0, 'NONE'),
('2023_GNED 03', 'BSBA-HRM, BSBA-MM', 'Mathematics in the Modern World', 'First Year', 'First Semester', 3, 0, 3, 0, 'NONE'),
('2023_GNED 04', 'BSBA-HRM, BSBA-MM', 'Mga Babasahin Hinggil sa Kasaysayan ng Pilipinas', 'First Year', 'First Semester', 3, 0, 3, 0, 'NONE'),
('2023_GNED 05', 'BSBA-HRM, BSBA-MM', 'Purposive Communication', 'First Year', 'First Semester', 3, 0, 3, 0, 'NONE'),
('2023_GNED 06', 'BSBA-HRM, BSBA-MM', 'Science, Technology and Society', 'Second Year', 'First Semester', 3, 0, 3, 0, 'NONE'),
('2023_GNED 07', 'BSBA-HRM, BSBA-MM', 'The Contemporary World', 'First Year', 'First Semester', 3, 0, 3, 0, 'NONE'),
('2023_GNED 08', 'BSBA-HRM, BSBA-MM', 'Understanding the Self', 'First Year', 'Second Semester', 3, 0, 3, 0, 'NONE'),
('2023_GNED 09', 'BSBA-HRM, BSBA-MM', 'Rizal\'s Life and Works', 'Third Year', 'First Semester', 3, 0, 3, 0, 'GNED 04'),
('2023_GNED 10', 'BSBA-HRM, BSBA-MM', 'Gender and Society', 'Second Year', 'First Semester', 3, 0, 3, 0, 'NONE'),
('2023_GNED 11', 'BSBA-HRM, BSBA-MM', 'Kontekstwalisadong Komunikasyon sa Filipino', 'First Year', 'First Semester', 3, 0, 3, 0, 'NONE'),
('2023_GNED 12', 'BSBA-HRM, BSBA-MM', 'Dalumat Ng/Sa Filipino', 'Second Year', 'First Semester', 3, 0, 3, 0, 'GNED 11'),
('2023_GNED 14', 'BSBA-HRM, BSBA-MM', 'Panitikang Panlipunan', 'Second Year', 'Second Semester', 3, 0, 3, 0, 'NONE'),
('2023_HRMT 50', 'BSBA-HRM', 'Administrative Office Management', 'Second Year', 'First Semester', 3, 0, 3, 0, 'BADM 22'),
('2023_HRMT 55', 'BSBA-HRM', 'Recruitment and Selection', 'Second Year', 'First Semester', 3, 0, 3, 0, 'BADM 22'),
('2023_HRMT 60', 'BSBA-HRM', 'Training and Development', 'Second Year', 'Second Semester', 3, 0, 3, 0, 'BADM 22'),
('2023_HRMT 65', 'BSBA-HRM', 'Labor Law and Legislation', 'Second Year', 'Second Semester', 3, 0, 3, 0, 'BADM 22'),
('2023_HRMT 70', 'BSBA-HRM', 'Compensation Administration', 'Second Year', 'Second Semester', 3, 0, 3, 0, 'BADM 22'),
('2023_HRMT 75', 'BSBA-HRM', 'Organizational Development', 'Third Year', 'First Semester', 3, 0, 3, 0, 'BADM 22'),
('2023_HRMT 80', 'BSBA-HRM', 'Labor Relation and Negotiations', 'Third Year', 'First Semester', 3, 0, 3, 0, 'BADM 22'),
('2023_HRMT 85', 'BSBA-HRM', 'Special Topics in Human Resource Management', 'Third Year', 'Second Semester', 3, 0, 3, 0, '3rd Year Standing'),
('2023_MKTG 50', 'BSBA-MM', 'Consumer and Behavior', 'Second Year', 'First Semester', 3, 0, 3, 0, 'NONE'),
('2023_MKTG 55', 'BSBA-MM', 'Market Research', 'Second Year', 'First Semester', 3, 0, 3, 0, 'NONE'),
('2023_MKTG 60', 'BSBA-MM', 'Product Management', 'Second Year', 'Second Semester', 3, 0, 3, 0, 'MKTG 50, MKTG 55'),
('2023_MKTG 65', 'BSBA-MM', 'Retail Management', 'Second Year', 'Second Semester', 3, 0, 3, 0, 'MKTG 50, MKTG 55'),
('2023_MKTG 70', 'BSBA-MM', 'Advertising', 'Second Year', 'Second Semester', 3, 0, 3, 0, 'MKTG 50, MKTG 55'),
('2023_MKTG 75', 'BSBA-MM', 'Professional Salesmanship', 'Third Year', 'First Semester', 3, 0, 3, 0, 'MKTG 50, MKTG 55'),
('2023_MKTG 80', 'BSBA-MM', 'Marketing Management', 'Third Year', 'First Semester', 3, 0, 3, 0, 'MKTG 50, MKTG 55'),
('2023_MKTG 85', 'BSBA-MM', 'Special Topics in Marketing Management', 'Third Year', 'First Semester', 3, 0, 3, 0, 'MKTG 50, MKTG 55'),
('2023_NSTP 1', 'BSBA-HRM, BSBA-MM', 'National Service Training Program', 'First Year', 'First Semester', 3, 0, 3, 0, 'NONE'),
('2023_NSTP 2', 'BSBA-HRM, BSBA-MM', 'National Service Training Program', 'First Year', 'Second Semester', 3, 0, 3, 0, 'NSTP 1'),
('2023_ORNT 1', 'BSBA-HRM, BSBA-MM', 'Institutional Orientation', 'First Year', 'First Semester', 1, 0, 1, 0, 'NONE'),
('2023_TAXN 21', 'BSBA-HRM, BSBA-MM', 'Taxation (Income and Taxation)', 'Second Year', 'Second Semester', 3, 0, 3, 0, 'NONE');

-- --------------------------------------------------------

--
-- Table structure for table `eligilibity_cond`
--

CREATE TABLE `eligilibity_cond` (
  `cond_id` int(11) NOT NULL,
  `scholarship_id` int(11) DEFAULT NULL,
  `field_name` varchar(255) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `employers`
--

CREATE TABLE `employers` (
  `employer_id` int(11) NOT NULL,
  `company_name` varchar(255) NOT NULL,
  `company_description` text DEFAULT NULL,
  `industry` varchar(255) DEFAULT NULL,
  `company_size` enum('1-10','11-50','51-200','201-500','500+') DEFAULT NULL,
  `website` varchar(255) DEFAULT NULL,
  `logo_url` varchar(255) DEFAULT NULL,
  `address` varchar(255) DEFAULT NULL,
  `city` varchar(100) DEFAULT NULL,
  `province` varchar(100) DEFAULT NULL,
  `zip_code` varchar(20) DEFAULT NULL,
  `contact_person` varchar(255) DEFAULT NULL,
  `contact_email` varchar(255) DEFAULT NULL,
  `contact_phone` varchar(50) DEFAULT NULL,
  `is_verified` tinyint(1) DEFAULT 0,
  `is_ojt_partner` tinyint(1) DEFAULT 0,
  `partnership_start_date` date DEFAULT NULL,
  `partnership_end_date` date DEFAULT NULL,
  `status` enum('pending','active','inactive','blacklisted') DEFAULT 'pending',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `employment_history`
--

CREATE TABLE `employment_history` (
  `employment_id` int(11) NOT NULL,
  `student_number` int(11) DEFAULT NULL,
  `company_name` varchar(255) DEFAULT NULL,
  `job_position` varchar(255) DEFAULT NULL,
  `job_status` varchar(255) DEFAULT NULL,
  `monthly_earning` decimal(12,2) DEFAULT NULL,
  `means_of_finding` varchar(255) DEFAULT NULL,
  `is_first_job` tinyint(1) DEFAULT NULL,
  `stay_duration` varchar(255) DEFAULT NULL,
  `change_reason` varchar(255) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `event`
--

CREATE TABLE `event` (
  `event_id` int(11) NOT NULL,
  `account_id` int(11) DEFAULT NULL,
  `event_title` varchar(255) DEFAULT NULL,
  `event_date` date DEFAULT NULL,
  `description` text DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `event_req`
--

CREATE TABLE `event_req` (
  `event_req` varchar(255) NOT NULL,
  `student_number` int(11) DEFAULT NULL,
  `event_name` varchar(255) DEFAULT NULL,
  `req_date` date DEFAULT NULL,
  `status` varchar(255) DEFAULT NULL,
  `remarks` varchar(255) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `failed_jobs`
--

CREATE TABLE `failed_jobs` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `uuid` varchar(255) NOT NULL,
  `connection` text NOT NULL,
  `queue` text NOT NULL,
  `payload` longtext NOT NULL,
  `exception` longtext NOT NULL,
  `failed_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `forum_posts`
--

CREATE TABLE `forum_posts` (
  `post_id` int(11) NOT NULL,
  `account_id` int(11) DEFAULT NULL,
  `title` varchar(255) DEFAULT NULL,
  `content` text DEFAULT NULL,
  `timestamp` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `good_moral_req`
--

CREATE TABLE `good_moral_req` (
  `good_moral_req_id` int(11) NOT NULL,
  `student_id` int(11) DEFAULT NULL,
  `req_date` date DEFAULT NULL,
  `req_time` time DEFAULT NULL,
  `status` varchar(255) DEFAULT NULL,
  `purpose` varchar(255) DEFAULT NULL,
  `remarks` varchar(255) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `graduates`
--

CREATE TABLE `graduates` (
  `graduate_id` int(11) NOT NULL,
  `student_number` int(11) DEFAULT NULL,
  `last_name` varchar(255) DEFAULT NULL,
  `first_name` varchar(255) DEFAULT NULL,
  `middle_name` varchar(255) DEFAULT NULL,
  `sex` varchar(255) DEFAULT NULL,
  `civil_status` varchar(255) DEFAULT NULL,
  `date_of_birth` date DEFAULT NULL,
  `program` varchar(255) DEFAULT NULL,
  `major` varchar(255) DEFAULT NULL,
  `year_graduated` int(11) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `job_applications`
--

CREATE TABLE `job_applications` (
  `application_id` int(11) NOT NULL,
  `job_id` int(11) DEFAULT NULL,
  `student_id` int(11) DEFAULT NULL,
  `application_date` timestamp NOT NULL DEFAULT current_timestamp(),
  `status` enum('pending','reviewed','shortlisted','interviewed','offered','hired','rejected','withdrawn') DEFAULT 'pending',
  `cover_letter` text DEFAULT NULL,
  `resume_url` varchar(255) DEFAULT NULL,
  `match_score` decimal(5,2) DEFAULT NULL,
  `employer_notes` text DEFAULT NULL,
  `interview_date` datetime DEFAULT NULL,
  `interview_location` varchar(255) DEFAULT NULL,
  `interview_notes` text DEFAULT NULL,
  `offer_salary` decimal(12,2) DEFAULT NULL,
  `offer_date` date DEFAULT NULL,
  `response_date` date DEFAULT NULL,
  `rejection_reason` varchar(255) DEFAULT NULL,
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `job_approvals`
--

CREATE TABLE `job_approvals` (
  `approval_id` int(11) NOT NULL,
  `job_id` int(11) DEFAULT NULL,
  `admin_id` int(11) DEFAULT NULL,
  `status` enum('pending','approved','rejected','revision') DEFAULT NULL,
  `review_date` timestamp NULL DEFAULT NULL,
  `comments` text DEFAULT NULL,
  `required_changes` text DEFAULT NULL,
  `reviewed_by` int(11) DEFAULT NULL,
  `reviewed_at` timestamp NULL DEFAULT NULL,
  `is_urgent` tinyint(1) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `job_postings`
--

CREATE TABLE `job_postings` (
  `job_id` int(11) NOT NULL,
  `employer_id` int(11) DEFAULT NULL,
  `title` varchar(255) DEFAULT NULL,
  `description` text DEFAULT NULL,
  `requirements` text DEFAULT NULL,
  `responsibilities` text DEFAULT NULL,
  `job_type` enum('full-time','part-time','contract','internship','ojt') DEFAULT NULL,
  `industry` varchar(255) DEFAULT NULL,
  `location` varchar(255) DEFAULT NULL,
  `salary_range_min` decimal(12,2) DEFAULT NULL,
  `salary_range_max` decimal(12,2) DEFAULT NULL,
  `min_gwa` decimal(3,2) DEFAULT NULL,
  `min_year_level` tinyint(4) DEFAULT NULL,
  `is_ojt` tinyint(1) DEFAULT NULL,
  `ojt_duration` varchar(255) DEFAULT NULL,
  `ojt_credits` int(11) DEFAULT NULL,
  `posted_date` timestamp NOT NULL DEFAULT current_timestamp(),
  `deadline_date` date DEFAULT NULL,
  `status` enum('draft','active','closed','expired') DEFAULT NULL,
  `views_count` int(11) DEFAULT 0,
  `applications_count` int(11) DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `login_lockouts`
--

CREATE TABLE `login_lockouts` (
  `login_identifier` varchar(120) NOT NULL,
  `failed_attempts` int(11) NOT NULL DEFAULT 0,
  `lockout_until` datetime DEFAULT NULL,
  `last_failed_at` datetime DEFAULT NULL,
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `login_lockouts`
--

INSERT INTO `login_lockouts` (`login_identifier`, `failed_attempts`, `lockout_until`, `last_failed_at`, `updated_at`) VALUES
('220100064', 1, NULL, '2026-04-02 01:24:46', '2026-04-01 17:24:46'),
('999999999', 1, NULL, '2026-03-26 18:56:20', '2026-03-26 10:56:20'),
('leah', 1, NULL, '2026-03-30 04:33:03', '2026-03-29 20:33:03');

-- --------------------------------------------------------

--
-- Table structure for table `matching_algorithm`
--

CREATE TABLE `matching_algorithm` (
  `config_id` int(11) NOT NULL,
  `algorithm_name` varchar(255) DEFAULT NULL,
  `skill_weight` decimal(5,2) DEFAULT NULL,
  `gwa_weight` decimal(5,2) DEFAULT NULL,
  `course_weight` decimal(5,2) DEFAULT NULL,
  `experience_weight` decimal(5,2) DEFAULT NULL,
  `certification_weight` decimal(5,2) DEFAULT NULL,
  `ojt_preference_boos` decimal(5,2) DEFAULT NULL,
  `min_match_thresho` decimal(5,2) DEFAULT NULL,
  `max_recommendati` int(11) DEFAULT NULL,
  `is_active` tinyint(1) DEFAULT NULL,
  `created_by` int(11) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `migrations`
--

CREATE TABLE `migrations` (
  `id` int(10) UNSIGNED NOT NULL,
  `migration` varchar(255) NOT NULL,
  `batch` int(11) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `migrations`
--

INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES
(1, '2014_10_12_000000_create_users_table', 1),
(2, '2014_10_12_100000_create_password_reset_tokens_table', 1),
(3, '2019_08_19_000000_create_failed_jobs_table', 1),
(4, '2019_12_14_000001_create_personal_access_tokens_table', 1);

-- --------------------------------------------------------

--
-- Table structure for table `notification`
--

CREATE TABLE `notification` (
  `notif_id` int(11) NOT NULL,
  `student_number` int(11) DEFAULT NULL,
  `title` varchar(255) DEFAULT NULL,
  `message` varchar(255) DEFAULT NULL,
  `date_sent` date DEFAULT NULL,
  `is_red` tinyint(1) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `ojt_records`
--

CREATE TABLE `ojt_records` (
  `ojt_record_id` int(11) NOT NULL,
  `student_id` int(11) DEFAULT NULL,
  `employer_id` int(11) DEFAULT NULL,
  `job_id` int(11) DEFAULT NULL,
  `start_date` date DEFAULT NULL,
  `end_date` date DEFAULT NULL,
  `total_hours` int(11) DEFAULT NULL,
  `supervisor_name` varchar(255) DEFAULT NULL,
  `supervisor_contact` varchar(255) DEFAULT NULL,
  `status` enum('ongoing','completed','terminated','on_hold') DEFAULT NULL,
  `final_grade` decimal(3,2) DEFAULT NULL,
  `evaluation_form_url` varchar(255) DEFAULT NULL,
  `certificate_url` varchar(255) DEFAULT NULL,
  `student_feedback` text DEFAULT NULL,
  `employer_feedback` text DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `password_history`
--

CREATE TABLE `password_history` (
  `id` int(11) NOT NULL,
  `student_number` varchar(50) NOT NULL,
  `password_hash` varchar(255) NOT NULL,
  `changed_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `password_resets`
--

CREATE TABLE `password_resets` (
  `email` varchar(255) NOT NULL,
  `code` varchar(255) DEFAULT NULL,
  `expires_at` datetime DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `password_resets`
--

INSERT INTO `password_resets` (`email`, `code`, `expires_at`) VALUES
('cc.johnkher.semilla@cvsu.edu.ph', '$2y$10$gJ2gA3rF6s0yzJVEoI3xV.VhgSuGva6WgyFf6x01CE5jXrY/n8huC', '2026-04-16 10:14:28'),
('cc.stephen.tiozon@cvsu.edu.ph', '3219', '2026-03-25 16:19:38');

-- --------------------------------------------------------

--
-- Table structure for table `password_reset_tokens`
--

CREATE TABLE `password_reset_tokens` (
  `email` varchar(255) NOT NULL,
  `token` varchar(255) NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `personal_access_tokens`
--

CREATE TABLE `personal_access_tokens` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `tokenable_type` varchar(255) NOT NULL,
  `tokenable_id` bigint(20) UNSIGNED NOT NULL,
  `name` varchar(255) NOT NULL,
  `token` varchar(64) NOT NULL,
  `abilities` text DEFAULT NULL,
  `last_used_at` timestamp NULL DEFAULT NULL,
  `expires_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `placement_repo`
--

CREATE TABLE `placement_repo` (
  `report_id` int(11) NOT NULL,
  `report_type` enum('monthly','quarterly','annual','custom') DEFAULT NULL,
  `period_start` date DEFAULT NULL,
  `period_end` date DEFAULT NULL,
  `total_students` int(11) DEFAULT NULL,
  `total_employers` int(11) DEFAULT NULL,
  `total_jobs` int(11) DEFAULT NULL,
  `total_applications` int(11) DEFAULT NULL,
  `total_hires` int(11) DEFAULT NULL,
  `ojt_count` int(11) DEFAULT NULL,
  `avg_match_score` decimal(5,2) DEFAULT NULL,
  `top_industry` varchar(255) DEFAULT NULL,
  `top_course` varchar(255) DEFAULT NULL,
  `generated_by` int(11) DEFAULT NULL,
  `generated_at` timestamp NULL DEFAULT NULL,
  `report_url` varchar(255) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `profile_completion_trac`
--

CREATE TABLE `profile_completion_trac` (
  `tracking_id` int(11) NOT NULL,
  `student_id` int(11) DEFAULT NULL,
  `personal_info_complete` tinyint(1) DEFAULT NULL,
  `education_complete` tinyint(1) DEFAULT NULL,
  `skills_complete` tinyint(1) DEFAULT NULL,
  `certifications_complete` tinyint(1) DEFAULT NULL,
  `resume_uploaded` tinyint(1) DEFAULT NULL,
  `profile_photo_uploaded` tinyint(1) DEFAULT NULL,
  `last_updated_section` varchar(255) DEFAULT NULL,
  `last_updated` timestamp NOT NULL DEFAULT current_timestamp(),
  `completion_score` tinyint(4) DEFAULT NULL,
  `reminder_sent` tinyint(1) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `programs`
--

CREATE TABLE `programs` (
  `id` int(11) NOT NULL,
  `code` varchar(64) DEFAULT NULL,
  `name` varchar(255) NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `programs`
--

INSERT INTO `programs` (`id`, `code`, `name`, `created_at`, `updated_at`) VALUES
(1, 'BSIT', 'BS Information Technology', '2026-04-16 01:35:13', '2026-04-16 01:35:13'),
(2, 'BSCS', 'BS Computer Science', '2026-04-16 01:35:13', '2026-04-16 01:35:13');

-- --------------------------------------------------------

--
-- Table structure for table `program_coordinator`
--

CREATE TABLE `program_coordinator` (
  `last_name` varchar(255) DEFAULT NULL,
  `first_name` varchar(255) DEFAULT NULL,
  `middle_name` varchar(255) DEFAULT NULL,
  `username` varchar(255) NOT NULL,
  `password` varchar(255) DEFAULT NULL,
  `prefix` varchar(255) DEFAULT NULL,
  `suffix` varchar(255) DEFAULT NULL,
  `id` int(11) DEFAULT NULL,
  `adviser_email` varchar(255) DEFAULT NULL,
  `sex` enum('Male','Female') DEFAULT NULL,
  `pronoun` enum('Mr.','Ms.','Mrs.') DEFAULT NULL,
  `program` varchar(255) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `program_coordinator`
--

INSERT INTO `program_coordinator` (`last_name`, `first_name`, `middle_name`, `username`, `password`, `prefix`, `suffix`, `id`, `adviser_email`, `sex`, `pronoun`, `program`) VALUES
('Opella', 'Joe Marlou', '', 'Ops', '$2y$10$Pq8gCV.rcE9j.S9v3uqRG.cKDQcpm0VjwE/kQhH/dHhmsQzHzCGUK', 'Dr', '', NULL, '', 'Male', 'Mr.', 'Bachelor of Science in Computer Science'),
('Ignas', 'Jhumel', '', 'jhums', '$2y$10$O5faami/pHYk4rfa2R6v2O4o/v8EPlqRT6YSqAXqt2zu/VhmY7fta', NULL, NULL, NULL, NULL, 'Male', 'Mr.', 'Bachelor of Science in Information Technology'),
('Abuan', 'John Benneth', '', 'jeth', '$2y$10$/tSW8yJJ3lr9hsWPXOFZ/.e9Ebn9bxSYolMZFjRCU7616WCGlcojW', NULL, NULL, NULL, NULL, 'Male', 'Mr.', 'Bachelor of Science in Computer Engineering'),
('Torrevillas', 'Klenton', NULL, 'klenton', '$2y$10$z3vMawy1PeQmqKvcT/ffEOCDeUYi4QNpOQ68mWjHSHBPtz2AIrnR.', NULL, NULL, NULL, NULL, 'Male', 'Mr.', 'Bachelor of Science in Hospitality Management'),
('Francia', 'Maria Andrea', NULL, 'francia', '$2y$10$csERrpDEw1sb2qAXpBTFFeeUyKnYwbNxXMFxj9lcJ0Y5KOYiZtAXe', NULL, NULL, NULL, NULL, 'Female', 'Mrs.', 'Bachelor of Science in Business Administration - Major in Marketing Management, Bachelor of Science in Business Administration - Major in Human Resource Management');

-- --------------------------------------------------------

--
-- Table structure for table `program_curriculum_years`
--

CREATE TABLE `program_curriculum_years` (
  `id` int(11) NOT NULL,
  `program` varchar(64) NOT NULL,
  `curriculum_year` char(4) NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `program_curriculum_years`
--

INSERT INTO `program_curriculum_years` (`id`, `program`, `curriculum_year`, `created_at`, `updated_at`) VALUES
(1, 'BSCpE', '2018', '2026-03-25 20:07:30', '2026-03-25 21:26:33'),
(10, 'BSCS', '2018', '2026-03-26 05:58:03', '2026-03-30 00:39:16');

-- --------------------------------------------------------

--
-- Table structure for table `program_shift_approvals`
--

CREATE TABLE `program_shift_approvals` (
  `id` int(11) NOT NULL,
  `request_id` int(11) NOT NULL,
  `stage` enum('adviser','coordinator') NOT NULL,
  `action` enum('approve','reject') NOT NULL,
  `actor_username` varchar(100) DEFAULT NULL,
  `actor_name` varchar(255) DEFAULT NULL,
  `actor_program` varchar(255) DEFAULT NULL,
  `comments` text DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `program_shift_approvals`
--

INSERT INTO `program_shift_approvals` (`id`, `request_id`, `stage`, `action`, `actor_username`, `actor_name`, `actor_program`, `comments`, `created_at`) VALUES
(1, 1, 'adviser', 'reject', 'bayan', 'Robert  Bayan', 'BSCPE', '', '2026-03-26 03:24:39'),
(2, 2, 'adviser', 'approve', 'bayan', 'Robert  Bayan', 'BSCPE', '', '2026-03-26 11:15:38'),
(5, 2, 'coordinator', 'approve', 'jeth', 'John Benneth  Abuan', 'BSCPE', 'This is noted', '2026-03-26 11:28:53');

-- --------------------------------------------------------

--
-- Table structure for table `program_shift_audit`
--

CREATE TABLE `program_shift_audit` (
  `id` int(11) NOT NULL,
  `request_id` int(11) DEFAULT NULL,
  `event_key` varchar(100) NOT NULL,
  `event_message` varchar(255) NOT NULL,
  `actor_username` varchar(100) DEFAULT NULL,
  `actor_role` varchar(60) DEFAULT NULL,
  `metadata_json` longtext DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `program_shift_audit`
--

INSERT INTO `program_shift_audit` (`id`, `request_id`, `event_key`, `event_message`, `actor_username`, `actor_role`, `metadata_json`, `created_at`) VALUES
(1, 1, 'request_submitted', 'Program shift request submitted by student.', '220100064', 'student', '{\"current_program\":\"Bachelor of Science in Computer Engineering\",\"requested_program\":\"Bachelor of Science in Industrial Technology\"}', '2026-03-25 16:48:52'),
(2, 1, 'adviser_reject', 'Adviser rejected the shift request.', 'bayan', 'adviser', '{\"comment\":\"\",\"next_status\":\"rejected\"}', '2026-03-26 03:24:39'),
(3, 2, 'request_submitted', 'Program shift request submitted by student.', '220100064', 'student', '{\"current_program\":\"Bachelor of Science in Computer Engineering\",\"requested_program\":\"Bachelor of Science in Business Administration - Major in Marketing Management\"}', '2026-03-26 11:08:10'),
(4, 2, 'adviser_approve', 'Adviser approved and forwarded the shift request to Program Coordinator.', 'bayan', 'adviser', '{\"comment\":\"\",\"next_status\":\"pending_coordinator\"}', '2026-03-26 11:15:38'),
(7, 2, 'coordinator_approve', 'Program Coordinator approved the shift request.', 'jeth', 'program_coordinator', '{\"comment\":\"This is noted\"}', '2026-03-26 11:28:53'),
(8, 2, 'shift_executed', 'Program shift executed and student program updated.', 'jeth', 'program_coordinator', '{\"student_number\":\"220100064\",\"source_program\":\"Bachelor of Science in Computer Engineering\",\"destination_program\":\"Bachelor of Science in Business Administration - Major in Marketing Management\",\"credited_courses\":0,\"auto_credit_skipped\":true,\"auto_credit_skip_reason\":\"Auto-credit skipped because curriculum entries are missing for the source or destination program.\"}', '2026-03-26 11:28:53'),
(9, 3, 'request_submitted', 'Program shift request submitted by student.', '200100745', 'student', '{\"current_program\":\"Bachelor of Science in Business Administration Major in Human Resource Management\",\"requested_program\":\"Bachelor of Science in Computer Science\"}', '2026-03-29 19:52:00'),
(44, 14, 'request_submitted', 'Program shift request submitted by student.', '229999931', 'student', '{\"current_program\":\"Bachelor of Science in Computer Science\",\"requested_program\":\"Bachelor of Science in Business Administration Major in Human Resource Management\"}', '2026-04-16 10:10:37');

-- --------------------------------------------------------

--
-- Table structure for table `program_shift_credit_map`
--

CREATE TABLE `program_shift_credit_map` (
  `id` int(11) NOT NULL,
  `request_id` int(11) NOT NULL,
  `student_number` varchar(20) NOT NULL,
  `source_program` varchar(255) NOT NULL,
  `destination_program` varchar(255) NOT NULL,
  `source_course_code` varchar(50) NOT NULL,
  `destination_course_code` varchar(50) NOT NULL,
  `final_grade` varchar(20) DEFAULT NULL,
  `evaluator_remarks` varchar(255) DEFAULT NULL,
  `mapped_at` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `program_shift_requests`
--

CREATE TABLE `program_shift_requests` (
  `id` int(11) NOT NULL,
  `request_code` varchar(40) NOT NULL,
  `student_number` varchar(20) NOT NULL,
  `student_name` varchar(255) DEFAULT NULL,
  `current_program` varchar(255) NOT NULL,
  `requested_program` varchar(255) NOT NULL,
  `reason` text DEFAULT NULL,
  `status` enum('pending_adviser','pending_current_coordinator','pending_destination_coordinator','pending_coordinator','approved','rejected','cancelled') NOT NULL DEFAULT 'pending_adviser',
  `adviser_action_by` varchar(100) DEFAULT NULL,
  `adviser_action_name` varchar(255) DEFAULT NULL,
  `adviser_action_at` datetime DEFAULT NULL,
  `adviser_comment` text DEFAULT NULL,
  `coordinator_action_by` varchar(100) DEFAULT NULL,
  `coordinator_action_name` varchar(255) DEFAULT NULL,
  `coordinator_action_at` datetime DEFAULT NULL,
  `coordinator_comment` text DEFAULT NULL,
  `executed_by` varchar(100) DEFAULT NULL,
  `executed_at` datetime DEFAULT NULL,
  `execution_note` text DEFAULT NULL,
  `requested_at` datetime NOT NULL DEFAULT current_timestamp(),
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `rate_limits`
--

CREATE TABLE `rate_limits` (
  `id` int(11) NOT NULL,
  `ip_address` varchar(45) NOT NULL,
  `action` varchar(50) NOT NULL,
  `attempts` int(11) DEFAULT 0,
  `first_attempt` datetime NOT NULL,
  `last_attempt` datetime NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `rate_limits`
--

INSERT INTO `rate_limits` (`id`, `ip_address`, `action`, `attempts`, `first_attempt`, `last_attempt`) VALUES
(3, '::1', 'forgot_password', 3, '2026-04-16 09:44:14', '2026-04-16 09:45:01'),
(4, '::1', 'forgot_password', 2, '2026-04-16 10:00:36', '2026-04-16 10:04:32');

-- --------------------------------------------------------

--
-- Table structure for table `recommendations`
--

CREATE TABLE `recommendations` (
  `recommendation_id` int(11) NOT NULL,
  `student_id` int(11) DEFAULT NULL,
  `job_id` int(11) DEFAULT NULL,
  `algorithm_type` varchar(255) DEFAULT NULL,
  `match_score` decimal(5,2) DEFAULT NULL,
  `score_breakdown` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`score_breakdown`)),
  `reason` text DEFAULT NULL,
  `generated_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `viewed` tinyint(1) DEFAULT 0,
  `clicked` tinyint(1) DEFAULT 0,
  `applied` tinyint(1) DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `required_skills`
--

CREATE TABLE `required_skills` (
  `skill_id` int(11) NOT NULL,
  `job_id` int(11) DEFAULT NULL,
  `skill_name` varchar(255) DEFAULT NULL,
  `importance_level` enum('required','preferred','optional') DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `scholarship`
--

CREATE TABLE `scholarship` (
  `scho_id` int(11) NOT NULL,
  `description` varchar(255) DEFAULT NULL,
  `number_of_slots` varchar(255) DEFAULT NULL,
  `scho_name` varchar(255) DEFAULT NULL,
  `sponsor` varchar(255) DEFAULT NULL,
  `acad_yr` varchar(255) DEFAULT NULL,
  `scholarship_sem` varchar(255) DEFAULT NULL,
  `date_created` date DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `student_checklists`
--

CREATE TABLE `student_checklists` (
  `id` int(11) NOT NULL,
  `student_id` int(11) DEFAULT NULL,
  `course_code` varchar(50) DEFAULT NULL,
  `status` varchar(50) DEFAULT NULL,
  `grade` varchar(10) DEFAULT NULL,
  `semester` varchar(50) DEFAULT NULL,
  `school_year` varchar(20) DEFAULT NULL,
  `final_grade` varchar(10) DEFAULT NULL,
  `evaluator_remarks` text DEFAULT NULL,
  `professor_instructor` varchar(255) DEFAULT NULL,
  `grade_submitted_at` datetime DEFAULT NULL,
  `updated_at` datetime DEFAULT NULL,
  `submitted_by` varchar(255) DEFAULT NULL,
  `grade_approved` tinyint(1) DEFAULT NULL,
  `approved_at` datetime DEFAULT NULL,
  `approved_by` varchar(255) DEFAULT NULL,
  `created_at` datetime DEFAULT current_timestamp(),
  `final_grade_2` varchar(20) DEFAULT NULL,
  `evaluator_remarks_2` varchar(50) DEFAULT NULL,
  `final_grade_3` varchar(20) DEFAULT NULL,
  `evaluator_remarks_3` varchar(50) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `student_email_verifications`
--

CREATE TABLE `student_email_verifications` (
  `student_number` varchar(50) NOT NULL,
  `email` varchar(255) NOT NULL,
  `otp_code` varchar(255) DEFAULT NULL,
  `otp_expires_at` datetime DEFAULT NULL,
  `verified_at` datetime DEFAULT NULL,
  `last_sent_at` datetime DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `student_email_verifications`
--

INSERT INTO `student_email_verifications` (`student_number`, `email`, `otp_code`, `otp_expires_at`, `verified_at`, `last_sent_at`, `created_at`, `updated_at`) VALUES
('220100109', 'qa.220100109@cvsu.edu.ph', NULL, NULL, NULL, NULL, '2026-04-16 01:44:12', '2026-04-16 01:44:12'),
('229999902', 'qa.229999902@cvsu.edu.ph', NULL, NULL, NULL, NULL, '2026-04-16 01:48:40', '2026-04-16 01:48:40');

-- --------------------------------------------------------

--
-- Table structure for table `student_info`
--

CREATE TABLE `student_info` (
  `student_number` int(11) NOT NULL,
  `last_name` varchar(255) DEFAULT NULL,
  `first_name` varchar(255) DEFAULT NULL,
  `middle_name` varchar(255) DEFAULT NULL,
  `contact_number` varchar(20) DEFAULT NULL,
  `date_of_admission` date DEFAULT NULL,
  `stud_landline` varchar(255) DEFAULT NULL,
  `program` varchar(255) DEFAULT NULL,
  `curriculum_year` char(4) DEFAULT NULL,
  `cvsu_email` varchar(255) DEFAULT NULL,
  `if_ojt` varchar(255) DEFAULT NULL,
  `house_number_street` varchar(255) DEFAULT NULL,
  `strand` varchar(50) DEFAULT NULL,
  `brgy` varchar(255) DEFAULT NULL,
  `town` varchar(255) DEFAULT NULL,
  `province` varchar(255) DEFAULT NULL,
  `zip_code` int(10) DEFAULT NULL,
  `prefix` varchar(255) DEFAULT NULL,
  `suffix` varchar(255) DEFAULT NULL,
  `stud_classification` varchar(255) DEFAULT NULL,
  `reg_status` varchar(255) DEFAULT NULL,
  `date_of_birth` date DEFAULT NULL,
  `place_of_birth` varchar(255) DEFAULT NULL,
  `age` int(11) DEFAULT NULL,
  `sex` varchar(255) DEFAULT NULL,
  `religion` varchar(255) DEFAULT NULL,
  `nationality` varchar(255) DEFAULT NULL,
  `civil_status` varchar(255) DEFAULT NULL,
  `parent_guardian` varchar(255) DEFAULT NULL,
  `parent_guardian_addrs` varchar(255) DEFAULT NULL,
  `parent_guardian_occup` varchar(255) DEFAULT NULL,
  `parent_guardian_landline` varchar(255) DEFAULT NULL,
  `parent_guardian_number` int(11) DEFAULT NULL,
  `student_special_popu` varchar(255) DEFAULT NULL,
  `password` varchar(255) DEFAULT NULL,
  `email` varchar(100) DEFAULT NULL,
  `created_at` datetime DEFAULT NULL,
  `status` varchar(255) DEFAULT NULL,
  `approved_by` varchar(255) DEFAULT NULL,
  `year_level` tinyint(4) DEFAULT NULL,
  `remember_token` varchar(255) DEFAULT NULL,
  `remember_token_expiry` datetime DEFAULT NULL,
  `ojt_completed` tinyint(1) DEFAULT NULL,
  `ojt_eligible` tinyint(1) DEFAULT NULL,
  `general_weighted_average` decimal(3,2) DEFAULT NULL,
  `course` varchar(100) DEFAULT NULL,
  `section` varchar(20) DEFAULT NULL,
  `honors` varchar(255) DEFAULT NULL,
  `picture` varchar(255) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `student_info`
--

INSERT INTO `student_info` (`student_number`, `last_name`, `first_name`, `middle_name`, `contact_number`, `date_of_admission`, `stud_landline`, `program`, `curriculum_year`, `cvsu_email`, `if_ojt`, `house_number_street`, `strand`, `brgy`, `town`, `province`, `zip_code`, `prefix`, `suffix`, `stud_classification`, `reg_status`, `date_of_birth`, `place_of_birth`, `age`, `sex`, `religion`, `nationality`, `civil_status`, `parent_guardian`, `parent_guardian_addrs`, `parent_guardian_occup`, `parent_guardian_landline`, `parent_guardian_number`, `student_special_popu`, `password`, `email`, `created_at`, `status`, `approved_by`, `year_level`, `remember_token`, `remember_token_expiry`, `ojt_completed`, `ojt_eligible`, `general_weighted_average`, `course`, `section`, `honors`, `picture`) VALUES
(229999910, 'SEMILLA', 'JOHNKHER', 'S', '09123456789', '2024-08-15', NULL, 'Bachelor of Science in Computer Science', NULL, NULL, NULL, 'QA Address', 'STEM', NULL, NULL, NULL, NULL, NULL, NULL, 'Regular', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, '$2y$10$xDBaq.HVv0OY6YVL4HdS0.eoS1gxzWQejfH1.VPudlMxY8r/juhUa', 'cc.johnkher.semilla@cvsu.edu.ph', NULL, 'approved', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 'pix/anonymous.jpg');

-- --------------------------------------------------------

--
-- Table structure for table `student_masterlist`
--

CREATE TABLE `student_masterlist` (
  `id` int(11) NOT NULL,
  `student_number` varchar(32) NOT NULL,
  `last_name` varchar(150) NOT NULL,
  `first_name` varchar(150) NOT NULL,
  `middle_initial` varchar(8) DEFAULT NULL,
  `program` varchar(255) NOT NULL,
  `source_filename` varchar(255) DEFAULT NULL,
  `uploaded_by` varchar(120) DEFAULT NULL,
  `uploaded_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `student_masterlist`
--

INSERT INTO `student_masterlist` (`id`, `student_number`, `last_name`, `first_name`, `middle_initial`, `program`, `source_filename`, `uploaded_by`, `uploaded_at`, `updated_at`) VALUES
(29, '220100109', 'ABO-ABO', 'WENNIE GRACE', 'H', 'Bachelor of Science in Computer Science', 'student_masterlist_template.csv', 'admin01', '2026-04-16 09:39:57', '2026-04-16 09:39:57'),
(30, '220100021', 'APAN', 'JEDEDIAH', 'B', 'Bachelor of Science in Computer Science', 'student_masterlist_template.csv', 'admin01', '2026-04-16 09:39:57', '2026-04-16 09:39:57'),
(31, '220100133', 'BANTOLO', 'MICHELLE VALERIE', 'M', 'Bachelor of Science in Computer Science', 'student_masterlist_template.csv', 'admin01', '2026-04-16 09:39:57', '2026-04-16 09:39:57'),
(32, '220100020', 'BENJAMIN', 'RYAN', 'T', 'Bachelor of Science in Computer Science', 'student_masterlist_template.csv', 'admin01', '2026-04-16 09:39:57', '2026-04-16 09:39:57'),
(33, '220100110', 'BERMAS', 'NERY III', 'L', 'Bachelor of Science in Computer Science', 'student_masterlist_template.csv', 'admin01', '2026-04-16 09:39:57', '2026-04-16 09:39:57'),
(34, '190100693', 'BOLOYOS', 'MIKO', 'B', 'Bachelor of Science in Computer Science', 'student_masterlist_template.csv', 'admin01', '2026-04-16 09:39:57', '2026-04-16 09:39:57'),
(35, '220100104', 'CONTRERAS', 'CRISTHINA', 'R', 'Bachelor of Science in Computer Science', 'student_masterlist_template.csv', 'admin01', '2026-04-16 09:39:57', '2026-04-16 09:39:57'),
(36, '220101574', 'CORRE', 'MARIENEL', 'L', 'Bachelor of Science in Computer Science', 'student_masterlist_template.csv', 'admin01', '2026-04-16 09:39:57', '2026-04-16 09:39:57'),
(37, '220100060', 'DE BAGUIO', 'JORELINE', 'F', 'Bachelor of Science in Computer Science', 'student_masterlist_template.csv', 'admin01', '2026-04-16 09:39:57', '2026-04-16 09:39:57'),
(38, '220100112', 'DE LARA', 'ERVIE', '', 'Bachelor of Science in Computer Science', 'student_masterlist_template.csv', 'admin01', '2026-04-16 09:39:57', '2026-04-16 09:39:57'),
(39, '220100113', 'ESTRADA', 'PJ', 'F', 'Bachelor of Science in Computer Science', 'student_masterlist_template.csv', 'admin01', '2026-04-16 09:39:57', '2026-04-16 09:39:57'),
(40, '220101107', 'FALCON', 'JOPHINE', 'D', 'Bachelor of Science in Computer Science', 'student_masterlist_template.csv', 'admin01', '2026-04-16 09:39:57', '2026-04-16 09:39:57'),
(41, '220100083', 'FERNANDEZ', 'ALLENE ZOE', 'M', 'Bachelor of Science in Computer Science', 'student_masterlist_template.csv', 'admin01', '2026-04-16 09:39:57', '2026-04-16 09:39:57'),
(42, '220100118', 'FRESCO', 'AIVIE', 'S', 'Bachelor of Science in Computer Science', 'student_masterlist_template.csv', 'admin01', '2026-04-16 09:39:57', '2026-04-16 09:39:57'),
(43, '220100106', 'GLORIOSO', 'JHANIEL', 'P', 'Bachelor of Science in Computer Science', 'student_masterlist_template.csv', 'admin01', '2026-04-16 09:39:57', '2026-04-16 09:39:57'),
(44, '190100504', 'JAURIGUE', 'MARK ANGELO', 'R', 'Bachelor of Science in Computer Science', 'student_masterlist_template.csv', 'admin01', '2026-04-16 09:39:57', '2026-04-16 09:39:57'),
(45, '220100108', 'LAPATING', 'RHEYENNE', 'T', 'Bachelor of Science in Computer Science', 'student_masterlist_template.csv', 'admin01', '2026-04-16 09:39:57', '2026-04-16 09:39:57'),
(46, '220100124', 'MAQUINCIO', 'GHIMAN', 'G', 'Bachelor of Science in Computer Science', 'student_masterlist_template.csv', 'admin01', '2026-04-16 09:39:57', '2026-04-16 09:39:57'),
(47, '220100111', 'MARTINEZ', 'JANNAH MARIEL', 'D', 'Bachelor of Science in Computer Science', 'student_masterlist_template.csv', 'admin01', '2026-04-16 09:39:57', '2026-04-16 09:39:57'),
(48, '220100140', 'ORELLOSA', 'HALLY', 'N', 'Bachelor of Science in Computer Science', 'student_masterlist_template.csv', 'admin01', '2026-04-16 09:39:57', '2026-04-16 09:39:57'),
(49, '220100136', 'OSIO', 'TIMOTHY', 'C', 'Bachelor of Science in Computer Science', 'student_masterlist_template.csv', 'admin01', '2026-04-16 09:39:57', '2026-04-16 09:39:57'),
(50, '220100000', 'RAGOS', 'KATHLEEN', 'M', 'Bachelor of Science in Computer Science', 'student_masterlist_template.csv', 'admin01', '2026-04-16 09:39:57', '2026-04-16 09:39:57'),
(51, '220100138', 'SANTOS', 'CALAHAN', 'P', 'Bachelor of Science in Computer Science', 'student_masterlist_template.csv', 'admin01', '2026-04-16 09:39:57', '2026-04-16 09:39:57'),
(52, '210101352', 'SANTOS', 'JUSTINE ANNE', 'A', 'Bachelor of Science in Computer Science', 'student_masterlist_template.csv', 'admin01', '2026-04-16 09:39:57', '2026-04-16 09:39:57'),
(53, '220100114', 'SANTOS', 'SEYMOUR', 'P', 'Bachelor of Science in Computer Science', 'student_masterlist_template.csv', 'admin01', '2026-04-16 09:39:57', '2026-04-16 09:39:57'),
(54, '220100126', 'SINFUEGO', 'JEFFSON', 'P', 'Bachelor of Science in Computer Science', 'student_masterlist_template.csv', 'admin01', '2026-04-16 09:39:57', '2026-04-16 09:39:57'),
(55, '220101063', 'SOBRADO', 'IZZA MAY', '', 'Bachelor of Science in Computer Science', 'student_masterlist_template.csv', 'admin01', '2026-04-16 09:39:57', '2026-04-16 09:39:57'),
(56, '210100750', 'TABORNAL', 'FRANCIS MIGUEL', 'O', 'Bachelor of Science in Computer Science', 'student_masterlist_template.csv', 'admin01', '2026-04-16 09:39:57', '2026-04-16 09:39:57'),
(61, '229999910', 'SEMILLA', 'JOHNKHER', 'S', 'Bachelor of Science in Computer Science', 'smtp_live_test.csv', 'admin_local', '2026-04-16 10:04:28', '2026-04-16 10:04:28');

-- --------------------------------------------------------

--
-- Table structure for table `student_rejection_log`
--

CREATE TABLE `student_rejection_log` (
  `student_number` varchar(50) NOT NULL,
  `rejected_at` datetime NOT NULL,
  `rejected_by` varchar(120) DEFAULT NULL,
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `student_skills`
--

CREATE TABLE `student_skills` (
  `student_skill_id` int(11) NOT NULL,
  `student_id` int(11) DEFAULT NULL,
  `skill_name` varchar(255) DEFAULT NULL,
  `proficiency_level` enum('beginner','intermediate','advanced','expert') DEFAULT NULL,
  `years_experience` decimal(4,2) DEFAULT NULL,
  `certification` varchar(255) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `student_study_plan_overrides`
--

CREATE TABLE `student_study_plan_overrides` (
  `id` int(11) NOT NULL,
  `student_id` varchar(32) NOT NULL,
  `course_code` varchar(64) NOT NULL,
  `target_year` varchar(20) NOT NULL,
  `target_semester` varchar(20) NOT NULL,
  `updated_by` varchar(120) DEFAULT NULL,
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `stud_educ_background`
--

CREATE TABLE `stud_educ_background` (
  `educ_id` int(11) NOT NULL,
  `student_number` int(11) DEFAULT NULL,
  `elem_school_name` varchar(255) DEFAULT NULL,
  `elem_yr_graduated` int(4) DEFAULT NULL,
  `elem_address` varchar(255) DEFAULT NULL,
  `elem_type_of_school` varchar(255) DEFAULT NULL,
  `high_school_name` varchar(255) DEFAULT NULL,
  `highschool_yr_graduated` int(4) DEFAULT NULL,
  `highschool_address` varchar(255) DEFAULT NULL,
  `hs_type_of_school` varchar(255) DEFAULT NULL,
  `shs_school_name` varchar(255) DEFAULT NULL,
  `shs_yr_graduated` int(4) DEFAULT NULL,
  `shs_address` varchar(255) DEFAULT NULL,
  `shs_type_of_school` varchar(255) DEFAULT NULL,
  `transferee` tinyint(1) DEFAULT NULL,
  `school_last_attended` varchar(255) DEFAULT NULL,
  `last_school_address` varchar(255) DEFAULT NULL,
  `college_school_name` varchar(255) DEFAULT NULL,
  `college_yr_graduated` int(4) DEFAULT NULL,
  `college_address` varchar(255) DEFAULT NULL,
  `college_type_of_school` varchar(255) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `system_settings`
--

CREATE TABLE `system_settings` (
  `id` int(11) NOT NULL,
  `setting_name` varchar(255) DEFAULT NULL,
  `setting_value` int(11) DEFAULT NULL,
  `updated_by` varchar(255) DEFAULT NULL,
  `updated_at` varchar(255) DEFAULT NULL,
  `created_at` varchar(255) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `system_settings`
--

INSERT INTO `system_settings` (`id`, `setting_name`, `setting_value`, `updated_by`, `updated_at`, `created_at`) VALUES
(1, 'auto_approve_students', 1, 'admin', NULL, NULL),
(2, 'auto_approve_students', 1, 'system', NULL, NULL),
(3, 'session_timeout_seconds', 5900, 'admin', '2026-03-27 16:20:28', NULL),
(4, 'min_password_length', 8, 'admin', '2026-03-27 16:20:28', NULL),
(5, 'password_reset_expiry_seconds', 600, 'admin', '2026-03-27 16:20:28', NULL),
(6, 'rate_limit_login_max_attempts', 5, 'admin', '2026-03-27 16:20:28', NULL),
(7, 'rate_limit_login_window_seconds', 300, 'admin', '2026-03-27 16:20:28', NULL),
(8, 'rate_limit_forgot_password_max_attempts', 3, 'admin', '2026-03-27 16:20:28', NULL),
(9, 'rate_limit_forgot_password_window_seconds', 600, 'admin', '2026-03-27 16:20:28', NULL);

-- --------------------------------------------------------

--
-- Table structure for table `users`
--

CREATE TABLE `users` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `name` varchar(255) NOT NULL,
  `email` varchar(255) NOT NULL,
  `email_verified_at` timestamp NULL DEFAULT NULL,
  `password` varchar(255) NOT NULL,
  `remember_token` varchar(100) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Indexes for dumped tables
--

--
-- Indexes for table `accounts`
--
ALTER TABLE `accounts`
  ADD PRIMARY KEY (`account_id`),
  ADD KEY `fk_accounts_student` (`student_number`);

--
-- Indexes for table `admin`
--
ALTER TABLE `admin`
  ADD PRIMARY KEY (`username`);

--
-- Indexes for table `admin_active_sessions`
--
ALTER TABLE `admin_active_sessions`
  ADD PRIMARY KEY (`admin_username`);

--
-- Indexes for table `admin_audit_logs`
--
ALTER TABLE `admin_audit_logs`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_admin_audit_created_at` (`created_at`),
  ADD KEY `idx_admin_audit_action_type` (`action_type`);

--
-- Indexes for table `admin_two_factor_auth`
--
ALTER TABLE `admin_two_factor_auth`
  ADD PRIMARY KEY (`admin_username`);

--
-- Indexes for table `adviser`
--
ALTER TABLE `adviser`
  ADD PRIMARY KEY (`username`);

--
-- Indexes for table `adviser_batch`
--
ALTER TABLE `adviser_batch`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `unique_assignment` (`adviser_id`,`batch`);

--
-- Indexes for table `announcements`
--
ALTER TABLE `announcements`
  ADD PRIMARY KEY (`announcement_id`),
  ADD KEY `fk_announcement_creator` (`created_by`);

--
-- Indexes for table `batches`
--
ALTER TABLE `batches`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `certificate`
--
ALTER TABLE `certificate`
  ADD PRIMARY KEY (`cert_id`),
  ADD KEY `fk_certificate_student` (`student_number`);

--
-- Indexes for table `comments`
--
ALTER TABLE `comments`
  ADD PRIMARY KEY (`comment_id`),
  ADD KEY `fk_comment_post` (`post_id`),
  ADD KEY `fk_comment_account` (`account_id`);

--
-- Indexes for table `counseling_req`
--
ALTER TABLE `counseling_req`
  ADD PRIMARY KEY (`counseling_req_id`),
  ADD KEY `fk_counseling_student` (`student_number`);

--
-- Indexes for table `curriculum_courses`
--
ALTER TABLE `curriculum_courses`
  ADD PRIMARY KEY (`id`),
  ADD KEY `curriculum_year` (`curriculum_year`),
  ADD KEY `program` (`program`),
  ADD KEY `course_code` (`course_code`);

--
-- Indexes for table `curriculum_feedback`
--
ALTER TABLE `curriculum_feedback`
  ADD PRIMARY KEY (`feedback_id`),
  ADD KEY `fk_curriculum_student` (`student_number`);

--
-- Indexes for table `cvsucarmona_courses`
--
ALTER TABLE `cvsucarmona_courses`
  ADD PRIMARY KEY (`curriculumyear_coursecode`),
  ADD KEY `year_level` (`year_level`),
  ADD KEY `semester` (`semester`);

--
-- Indexes for table `eligilibity_cond`
--
ALTER TABLE `eligilibity_cond`
  ADD PRIMARY KEY (`cond_id`),
  ADD KEY `fk_eligibility_scholarship` (`scholarship_id`);

--
-- Indexes for table `employers`
--
ALTER TABLE `employers`
  ADD PRIMARY KEY (`employer_id`);

--
-- Indexes for table `employment_history`
--
ALTER TABLE `employment_history`
  ADD PRIMARY KEY (`employment_id`),
  ADD KEY `fk_employment_student` (`student_number`);

--
-- Indexes for table `event`
--
ALTER TABLE `event`
  ADD PRIMARY KEY (`event_id`),
  ADD KEY `fk_event_account` (`account_id`);

--
-- Indexes for table `event_req`
--
ALTER TABLE `event_req`
  ADD PRIMARY KEY (`event_req`),
  ADD KEY `fk_event_student` (`student_number`);

--
-- Indexes for table `failed_jobs`
--
ALTER TABLE `failed_jobs`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `failed_jobs_uuid_unique` (`uuid`);

--
-- Indexes for table `forum_posts`
--
ALTER TABLE `forum_posts`
  ADD PRIMARY KEY (`post_id`),
  ADD KEY `fk_forum_account` (`account_id`);

--
-- Indexes for table `good_moral_req`
--
ALTER TABLE `good_moral_req`
  ADD PRIMARY KEY (`good_moral_req_id`),
  ADD KEY `fk_goodmoral_student` (`student_id`);

--
-- Indexes for table `graduates`
--
ALTER TABLE `graduates`
  ADD PRIMARY KEY (`graduate_id`),
  ADD KEY `fk_graduates_student` (`student_number`);

--
-- Indexes for table `job_applications`
--
ALTER TABLE `job_applications`
  ADD PRIMARY KEY (`application_id`),
  ADD KEY `fk_application_job` (`job_id`),
  ADD KEY `fk_application_student` (`student_id`);

--
-- Indexes for table `job_approvals`
--
ALTER TABLE `job_approvals`
  ADD PRIMARY KEY (`approval_id`),
  ADD KEY `fk_approval_job` (`job_id`),
  ADD KEY `fk_approval_admin` (`admin_id`),
  ADD KEY `fk_approval_reviewer` (`reviewed_by`);

--
-- Indexes for table `job_postings`
--
ALTER TABLE `job_postings`
  ADD PRIMARY KEY (`job_id`),
  ADD KEY `fk_job_employer` (`employer_id`);

--
-- Indexes for table `login_lockouts`
--
ALTER TABLE `login_lockouts`
  ADD PRIMARY KEY (`login_identifier`);

--
-- Indexes for table `matching_algorithm`
--
ALTER TABLE `matching_algorithm`
  ADD PRIMARY KEY (`config_id`),
  ADD KEY `fk_algorithm_creator` (`created_by`);

--
-- Indexes for table `migrations`
--
ALTER TABLE `migrations`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `notification`
--
ALTER TABLE `notification`
  ADD PRIMARY KEY (`notif_id`),
  ADD KEY `fk_notification_student` (`student_number`);

--
-- Indexes for table `ojt_records`
--
ALTER TABLE `ojt_records`
  ADD PRIMARY KEY (`ojt_record_id`),
  ADD KEY `fk_ojt_student` (`student_id`),
  ADD KEY `fk_ojt_employer` (`employer_id`),
  ADD KEY `fk_ojt_job` (`job_id`);

--
-- Indexes for table `password_history`
--
ALTER TABLE `password_history`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_password_history_student` (`student_number`,`changed_at`);

--
-- Indexes for table `password_resets`
--
ALTER TABLE `password_resets`
  ADD PRIMARY KEY (`email`);

--
-- Indexes for table `password_reset_tokens`
--
ALTER TABLE `password_reset_tokens`
  ADD PRIMARY KEY (`email`);

--
-- Indexes for table `personal_access_tokens`
--
ALTER TABLE `personal_access_tokens`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `personal_access_tokens_token_unique` (`token`),
  ADD KEY `personal_access_tokens_tokenable_type_tokenable_id_index` (`tokenable_type`,`tokenable_id`);

--
-- Indexes for table `placement_repo`
--
ALTER TABLE `placement_repo`
  ADD PRIMARY KEY (`report_id`),
  ADD KEY `fk_placement_admin` (`generated_by`);

--
-- Indexes for table `profile_completion_trac`
--
ALTER TABLE `profile_completion_trac`
  ADD PRIMARY KEY (`tracking_id`),
  ADD KEY `fk_profile_student` (`student_id`);

--
-- Indexes for table `programs`
--
ALTER TABLE `programs`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `name` (`name`);

--
-- Indexes for table `program_curriculum_years`
--
ALTER TABLE `program_curriculum_years`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uniq_program_year` (`program`,`curriculum_year`);

--
-- Indexes for table `program_shift_approvals`
--
ALTER TABLE `program_shift_approvals`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_shift_approvals_request` (`request_id`);

--
-- Indexes for table `program_shift_audit`
--
ALTER TABLE `program_shift_audit`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_shift_audit_request` (`request_id`);

--
-- Indexes for table `program_shift_credit_map`
--
ALTER TABLE `program_shift_credit_map`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_shift_credit_request` (`request_id`),
  ADD KEY `idx_shift_credit_student` (`student_number`);

--
-- Indexes for table `program_shift_requests`
--
ALTER TABLE `program_shift_requests`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uk_program_shift_request_code` (`request_code`),
  ADD KEY `idx_program_shift_student` (`student_number`),
  ADD KEY `idx_program_shift_status` (`status`);

--
-- Indexes for table `rate_limits`
--
ALTER TABLE `rate_limits`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_ip_action` (`ip_address`,`action`),
  ADD KEY `idx_last_attempt` (`last_attempt`);

--
-- Indexes for table `recommendations`
--
ALTER TABLE `recommendations`
  ADD PRIMARY KEY (`recommendation_id`),
  ADD KEY `fk_recommendation_student` (`student_id`),
  ADD KEY `fk_recommendation_job` (`job_id`);

--
-- Indexes for table `required_skills`
--
ALTER TABLE `required_skills`
  ADD PRIMARY KEY (`skill_id`),
  ADD KEY `fk_required_skills_job` (`job_id`);

--
-- Indexes for table `scholarship`
--
ALTER TABLE `scholarship`
  ADD PRIMARY KEY (`scho_id`);

--
-- Indexes for table `student_checklists`
--
ALTER TABLE `student_checklists`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uk_student_course` (`student_id`,`course_code`),
  ADD KEY `fk_checklist_student` (`student_id`);

--
-- Indexes for table `student_email_verifications`
--
ALTER TABLE `student_email_verifications`
  ADD PRIMARY KEY (`student_number`);

--
-- Indexes for table `student_info`
--
ALTER TABLE `student_info`
  ADD PRIMARY KEY (`student_number`);

--
-- Indexes for table `student_masterlist`
--
ALTER TABLE `student_masterlist`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uniq_student_masterlist_student_number` (`student_number`),
  ADD KEY `idx_student_masterlist_program` (`program`),
  ADD KEY `idx_student_masterlist_uploaded_at` (`uploaded_at`);

--
-- Indexes for table `student_rejection_log`
--
ALTER TABLE `student_rejection_log`
  ADD PRIMARY KEY (`student_number`);

--
-- Indexes for table `student_skills`
--
ALTER TABLE `student_skills`
  ADD PRIMARY KEY (`student_skill_id`),
  ADD KEY `fk_student_skills_student` (`student_id`);

--
-- Indexes for table `student_study_plan_overrides`
--
ALTER TABLE `student_study_plan_overrides`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uniq_student_course` (`student_id`,`course_code`),
  ADD KEY `idx_student` (`student_id`);

--
-- Indexes for table `stud_educ_background`
--
ALTER TABLE `stud_educ_background`
  ADD PRIMARY KEY (`educ_id`),
  ADD KEY `fk_educ_student` (`student_number`);

--
-- Indexes for table `system_settings`
--
ALTER TABLE `system_settings`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `users`
--
ALTER TABLE `users`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `users_email_unique` (`email`);

--
-- AUTO_INCREMENT for dumped tables
--

--
-- AUTO_INCREMENT for table `accounts`
--
ALTER TABLE `accounts`
  MODIFY `account_id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `admin_audit_logs`
--
ALTER TABLE `admin_audit_logs`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=7;

--
-- AUTO_INCREMENT for table `adviser_batch`
--
ALTER TABLE `adviser_batch`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=50;

--
-- AUTO_INCREMENT for table `announcements`
--
ALTER TABLE `announcements`
  MODIFY `announcement_id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `batches`
--
ALTER TABLE `batches`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `certificate`
--
ALTER TABLE `certificate`
  MODIFY `cert_id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `comments`
--
ALTER TABLE `comments`
  MODIFY `comment_id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `counseling_req`
--
ALTER TABLE `counseling_req`
  MODIFY `counseling_req_id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `curriculum_courses`
--
ALTER TABLE `curriculum_courses`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=799;

--
-- AUTO_INCREMENT for table `curriculum_feedback`
--
ALTER TABLE `curriculum_feedback`
  MODIFY `feedback_id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `eligilibity_cond`
--
ALTER TABLE `eligilibity_cond`
  MODIFY `cond_id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `employers`
--
ALTER TABLE `employers`
  MODIFY `employer_id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `employment_history`
--
ALTER TABLE `employment_history`
  MODIFY `employment_id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `event`
--
ALTER TABLE `event`
  MODIFY `event_id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `failed_jobs`
--
ALTER TABLE `failed_jobs`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `forum_posts`
--
ALTER TABLE `forum_posts`
  MODIFY `post_id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `good_moral_req`
--
ALTER TABLE `good_moral_req`
  MODIFY `good_moral_req_id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `graduates`
--
ALTER TABLE `graduates`
  MODIFY `graduate_id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `job_applications`
--
ALTER TABLE `job_applications`
  MODIFY `application_id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `job_approvals`
--
ALTER TABLE `job_approvals`
  MODIFY `approval_id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `job_postings`
--
ALTER TABLE `job_postings`
  MODIFY `job_id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `matching_algorithm`
--
ALTER TABLE `matching_algorithm`
  MODIFY `config_id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `migrations`
--
ALTER TABLE `migrations`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=5;

--
-- AUTO_INCREMENT for table `notification`
--
ALTER TABLE `notification`
  MODIFY `notif_id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `ojt_records`
--
ALTER TABLE `ojt_records`
  MODIFY `ojt_record_id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `password_history`
--
ALTER TABLE `password_history`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=12;

--
-- AUTO_INCREMENT for table `personal_access_tokens`
--
ALTER TABLE `personal_access_tokens`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `placement_repo`
--
ALTER TABLE `placement_repo`
  MODIFY `report_id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `profile_completion_trac`
--
ALTER TABLE `profile_completion_trac`
  MODIFY `tracking_id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `programs`
--
ALTER TABLE `programs`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=3;

--
-- AUTO_INCREMENT for table `program_curriculum_years`
--
ALTER TABLE `program_curriculum_years`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=26;

--
-- AUTO_INCREMENT for table `program_shift_approvals`
--
ALTER TABLE `program_shift_approvals`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=24;

--
-- AUTO_INCREMENT for table `program_shift_audit`
--
ALTER TABLE `program_shift_audit`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=45;

--
-- AUTO_INCREMENT for table `program_shift_credit_map`
--
ALTER TABLE `program_shift_credit_map`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=3;

--
-- AUTO_INCREMENT for table `program_shift_requests`
--
ALTER TABLE `program_shift_requests`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=15;

--
-- AUTO_INCREMENT for table `rate_limits`
--
ALTER TABLE `rate_limits`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=5;

--
-- AUTO_INCREMENT for table `recommendations`
--
ALTER TABLE `recommendations`
  MODIFY `recommendation_id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `required_skills`
--
ALTER TABLE `required_skills`
  MODIFY `skill_id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `scholarship`
--
ALTER TABLE `scholarship`
  MODIFY `scho_id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `student_checklists`
--
ALTER TABLE `student_checklists`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=3323;

--
-- AUTO_INCREMENT for table `student_masterlist`
--
ALTER TABLE `student_masterlist`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=65;

--
-- AUTO_INCREMENT for table `student_skills`
--
ALTER TABLE `student_skills`
  MODIFY `student_skill_id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `student_study_plan_overrides`
--
ALTER TABLE `student_study_plan_overrides`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=5;

--
-- AUTO_INCREMENT for table `stud_educ_background`
--
ALTER TABLE `stud_educ_background`
  MODIFY `educ_id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `system_settings`
--
ALTER TABLE `system_settings`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=10;

--
-- AUTO_INCREMENT for table `users`
--
ALTER TABLE `users`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=21;

--
-- Constraints for dumped tables
--

--
-- Constraints for table `accounts`
--
ALTER TABLE `accounts`
  ADD CONSTRAINT `fk_accounts_student` FOREIGN KEY (`student_number`) REFERENCES `student_info` (`student_number`) ON DELETE CASCADE ON UPDATE CASCADE;

--
-- Constraints for table `certificate`
--
ALTER TABLE `certificate`
  ADD CONSTRAINT `fk_certificate_student` FOREIGN KEY (`student_number`) REFERENCES `student_info` (`student_number`) ON DELETE CASCADE ON UPDATE CASCADE;

--
-- Constraints for table `comments`
--
ALTER TABLE `comments`
  ADD CONSTRAINT `fk_comment_account` FOREIGN KEY (`account_id`) REFERENCES `accounts` (`account_id`) ON DELETE CASCADE ON UPDATE CASCADE,
  ADD CONSTRAINT `fk_comment_post` FOREIGN KEY (`post_id`) REFERENCES `forum_posts` (`post_id`) ON DELETE CASCADE ON UPDATE CASCADE;

--
-- Constraints for table `counseling_req`
--
ALTER TABLE `counseling_req`
  ADD CONSTRAINT `fk_counseling_student` FOREIGN KEY (`student_number`) REFERENCES `student_info` (`student_number`) ON DELETE CASCADE ON UPDATE CASCADE;

--
-- Constraints for table `curriculum_feedback`
--
ALTER TABLE `curriculum_feedback`
  ADD CONSTRAINT `fk_curriculum_student` FOREIGN KEY (`student_number`) REFERENCES `student_info` (`student_number`) ON DELETE CASCADE ON UPDATE CASCADE;

--
-- Constraints for table `eligilibity_cond`
--
ALTER TABLE `eligilibity_cond`
  ADD CONSTRAINT `fk_eligibility_scholarship` FOREIGN KEY (`scholarship_id`) REFERENCES `scholarship` (`scho_id`) ON DELETE CASCADE ON UPDATE CASCADE;

--
-- Constraints for table `employment_history`
--
ALTER TABLE `employment_history`
  ADD CONSTRAINT `fk_employment_student` FOREIGN KEY (`student_number`) REFERENCES `student_info` (`student_number`) ON DELETE CASCADE ON UPDATE CASCADE;

--
-- Constraints for table `event`
--
ALTER TABLE `event`
  ADD CONSTRAINT `fk_event_account` FOREIGN KEY (`account_id`) REFERENCES `accounts` (`account_id`) ON DELETE CASCADE ON UPDATE CASCADE;

--
-- Constraints for table `event_req`
--
ALTER TABLE `event_req`
  ADD CONSTRAINT `fk_event_student` FOREIGN KEY (`student_number`) REFERENCES `student_info` (`student_number`) ON DELETE CASCADE ON UPDATE CASCADE;

--
-- Constraints for table `forum_posts`
--
ALTER TABLE `forum_posts`
  ADD CONSTRAINT `fk_forum_account` FOREIGN KEY (`account_id`) REFERENCES `accounts` (`account_id`) ON DELETE CASCADE ON UPDATE CASCADE;

--
-- Constraints for table `good_moral_req`
--
ALTER TABLE `good_moral_req`
  ADD CONSTRAINT `fk_goodmoral_student` FOREIGN KEY (`student_id`) REFERENCES `student_info` (`student_number`) ON DELETE CASCADE ON UPDATE CASCADE;

--
-- Constraints for table `graduates`
--
ALTER TABLE `graduates`
  ADD CONSTRAINT `fk_graduates_student` FOREIGN KEY (`student_number`) REFERENCES `student_info` (`student_number`) ON DELETE CASCADE ON UPDATE CASCADE;

--
-- Constraints for table `job_applications`
--
ALTER TABLE `job_applications`
  ADD CONSTRAINT `fk_application_job` FOREIGN KEY (`job_id`) REFERENCES `job_postings` (`job_id`) ON DELETE CASCADE ON UPDATE CASCADE,
  ADD CONSTRAINT `fk_application_student` FOREIGN KEY (`student_id`) REFERENCES `student_info` (`student_number`) ON DELETE CASCADE ON UPDATE CASCADE;

--
-- Constraints for table `job_postings`
--
ALTER TABLE `job_postings`
  ADD CONSTRAINT `fk_job_employer` FOREIGN KEY (`employer_id`) REFERENCES `employers` (`employer_id`) ON DELETE CASCADE ON UPDATE CASCADE;

--
-- Constraints for table `notification`
--
ALTER TABLE `notification`
  ADD CONSTRAINT `fk_notification_student` FOREIGN KEY (`student_number`) REFERENCES `student_info` (`student_number`) ON DELETE CASCADE ON UPDATE CASCADE;

--
-- Constraints for table `ojt_records`
--
ALTER TABLE `ojt_records`
  ADD CONSTRAINT `fk_ojt_student` FOREIGN KEY (`student_id`) REFERENCES `student_info` (`student_number`) ON DELETE CASCADE ON UPDATE CASCADE;

--
-- Constraints for table `profile_completion_trac`
--
ALTER TABLE `profile_completion_trac`
  ADD CONSTRAINT `fk_profile_student` FOREIGN KEY (`student_id`) REFERENCES `student_info` (`student_number`) ON DELETE CASCADE ON UPDATE CASCADE;

--
-- Constraints for table `recommendations`
--
ALTER TABLE `recommendations`
  ADD CONSTRAINT `fk_recommendation_job` FOREIGN KEY (`job_id`) REFERENCES `job_postings` (`job_id`) ON DELETE CASCADE ON UPDATE CASCADE,
  ADD CONSTRAINT `fk_recommendation_student` FOREIGN KEY (`student_id`) REFERENCES `student_info` (`student_number`) ON DELETE CASCADE ON UPDATE CASCADE;

--
-- Constraints for table `student_checklists`
--
ALTER TABLE `student_checklists`
  ADD CONSTRAINT `fk_checklist_student` FOREIGN KEY (`student_id`) REFERENCES `student_info` (`student_number`) ON DELETE CASCADE ON UPDATE CASCADE;

--
-- Constraints for table `student_skills`
--
ALTER TABLE `student_skills`
  ADD CONSTRAINT `fk_student_skills_student` FOREIGN KEY (`student_id`) REFERENCES `student_info` (`student_number`) ON DELETE CASCADE ON UPDATE CASCADE;

--
-- Constraints for table `stud_educ_background`
--
ALTER TABLE `stud_educ_background`
  ADD CONSTRAINT `fk_educ_student` FOREIGN KEY (`student_number`) REFERENCES `student_info` (`student_number`) ON DELETE CASCADE ON UPDATE CASCADE;
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
