-- phpMyAdmin SQL Dump
-- version 4.9.0.1
-- https://www.phpmyadmin.net/
--
-- Host: sql310.byetcluster.com
-- Generation Time: Oct 03, 2026 at 11:44 AM
-- Server version: 11.4.13-MariaDB
-- PHP Version: 7.2.22

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
SET AUTOCOMMIT = 0;
START TRANSACTION;
SET time_zone = "+00:00";


/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;

--
-- Database: `ezyro_42446564_db`
--

-- --------------------------------------------------------

--
-- Table structure for table `admins`
--

CREATE TABLE `admins` (
  `id` int(11) NOT NULL,
  `username` varchar(50) NOT NULL,
  `email` varchar(200) DEFAULT NULL,
  `full_name` varchar(200) DEFAULT NULL,
  `phone` varchar(50) DEFAULT NULL,
  `password_hash` varchar(255) NOT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `admins`
--

INSERT INTO `admins` (`id`, `username`, `email`, `full_name`, `phone`, `password_hash`, `created_at`, `updated_at`) VALUES

-- --------------------------------------------------------

--
-- Table structure for table `admin_email_settings`
--

CREATE TABLE `admin_email_settings` (
  `id` int(11) NOT NULL,
  `email` varchar(100) NOT NULL,
  `is_primary` tinyint(1) DEFAULT 0,
  `created_at` timestamp NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `admin_email_settings`
--

INSERT INTO `admin_email_settings` (`id`, `email`, `is_primary`, `created_at`) VALUES
(),
();

-- --------------------------------------------------------

--
-- Table structure for table `admin_login_logs`
--

CREATE TABLE `admin_login_logs` (
  `id` int(11) NOT NULL,
  `admin_id` varchar(50) DEFAULT 'admin',
  `admin_name` varchar(200) DEFAULT NULL,
  `login_time` timestamp NULL DEFAULT current_timestamp(),
  `logout_time` timestamp NULL DEFAULT NULL,
  `ip_address` varchar(50) DEFAULT NULL,
  `user_agent` text DEFAULT NULL,
  `session_duration` int(11) DEFAULT 0 COMMENT 'Duration in seconds',
  `status` enum('active','logged_out','expired') DEFAULT 'active'
) ENGINE=MyISAM DEFAULT CHARSET=latin1 COLLATE=latin1_swedish_ci;

--
-- Dumping data for table `admin_login_logs`
--

INSERT INTO `admin_login_logs` (`id`, `admin_id`, `admin_name`, `login_time`, `logout_time`, `ip_address`, `user_agent`, `session_duration`, `status`) VALUES

-- --------------------------------------------------------

--
-- Table structure for table `admin_password_resets`
--

CREATE TABLE `admin_password_resets` (
  `id` int(11) NOT NULL,
  `admin_id` int(11) NOT NULL,
  `email` varchar(200) NOT NULL,
  `otp` varchar(10) NOT NULL,
  `token` varchar(100) NOT NULL,
  `expires_at` timestamp NOT NULL,
  `used` tinyint(1) DEFAULT 0,
  `created_at` timestamp NULL DEFAULT current_timestamp()
) ENGINE=MyISAM DEFAULT CHARSET=latin1 COLLATE=latin1_swedish_ci;

--
-- Dumping data for table `admin_password_resets`
--

INSERT INTO `admin_password_resets` (`id`, `admin_id`, `email`, `otp`, `token`, `expires_at`, `used`, `created_at`) VALUES

-- --------------------------------------------------------

--
-- Table structure for table `admin_targets`
--

CREATE TABLE `admin_targets` (
  `id` int(11) NOT NULL,
  `month_year` varchar(7) NOT NULL,
  `total_team_target` int(11) DEFAULT 0,
  `credit_score_team_target` int(11) DEFAULT 0,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=MyISAM DEFAULT CHARSET=latin1 COLLATE=latin1_swedish_ci;

--
-- Dumping data for table `admin_targets`
--

INSERT INTO `admin_targets` (`id`, `month_year`, `total_team_target`, `credit_score_team_target`, `created_at`, `updated_at`) VALUES

-- --------------------------------------------------------

--
-- Table structure for table `agents`
--

CREATE TABLE `agents` (
  `agent_id` varchar(10) NOT NULL COMMENT 'Unique Agent ID, e.g., BGS10001',
  `date_of_birth` varchar(255) NOT NULL COMMENT 'Encrypted DOB',
  `account_status` enum('active','inactive','verified','rejected','correction_requested') DEFAULT 'active',
  `created_at` timestamp NULL DEFAULT current_timestamp() COMMENT 'Account creation timestamp',
  `onboarding_completed` tinyint(1) DEFAULT 0,
  `employment_status` enum('active','resigned','terminated','absconded','on_hold') DEFAULT 'active',
  `termination_date` date DEFAULT NULL,
  `termination_type` enum('resignation','termination','absconding','contract_end','retirement','other') DEFAULT NULL,
  `termination_reason` text DEFAULT NULL,
  `termination_notes` text DEFAULT NULL,
  `terminated_by` varchar(50) DEFAULT NULL,
  `last_working_date` date DEFAULT NULL,
  `notice_period_served` tinyint(1) DEFAULT 0,
  `completion_date` timestamp NULL DEFAULT NULL COMMENT 'Timestamp when onboarding was submitted',
  `full_name` varchar(100) DEFAULT '',
  `father_name` varchar(100) DEFAULT '',
  `mother_name` varchar(100) DEFAULT '',
  `mobile` varchar(15) DEFAULT '',
  `alt_mobile_1` varchar(15) DEFAULT NULL,
  `alt_mobile_2` varchar(15) DEFAULT NULL,
  `email` varchar(100) DEFAULT '',
  `email_verified` tinyint(1) DEFAULT 0,
  `resume_path` varchar(255) DEFAULT '',
  `pan_number` varchar(255) DEFAULT '' COMMENT 'Encrypted PAN',
  `pan_image_path` varchar(255) DEFAULT '',
  `aadhaar_number` varchar(255) DEFAULT '' COMMENT 'Encrypted Aadhaar',
  `aadhaar_front_path` varchar(255) DEFAULT '',
  `aadhaar_back_path` varchar(255) DEFAULT '',
  `candidate_photo_path` varchar(255) DEFAULT '',
  `latitude` varchar(255) DEFAULT '' COMMENT 'Encrypted latitude',
  `longitude` varchar(255) DEFAULT '' COMMENT 'Encrypted longitude',
  `accuracy` varchar(50) DEFAULT '',
  `location_timestamp` timestamp NULL DEFAULT NULL,
  `house_photo_1` varchar(255) DEFAULT '',
  `house_photo_2` varchar(255) DEFAULT '',
  `street_photo` varchar(255) DEFAULT '',
  `house_number` varchar(50) DEFAULT '',
  `street` varchar(255) DEFAULT '',
  `locality` varchar(255) DEFAULT '',
  `landmark` varchar(255) DEFAULT '',
  `city` varchar(100) DEFAULT '',
  `state` varchar(100) DEFAULT '',
  `pincode` varchar(10) DEFAULT '',
  `country` varchar(50) DEFAULT 'India',
  `submitted` tinyint(1) DEFAULT 0,
  `submitted_at` timestamp NULL DEFAULT NULL,
  `verified_by` varchar(50) DEFAULT '',
  `verification_status` enum('pending','verified','rejected','correction_requested') DEFAULT 'pending',
  `verification_timestamp` timestamp NULL DEFAULT NULL,
  `verification_reason` text DEFAULT NULL,
  `onboarding_data` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL
) ;

--
-- Dumping data for table `agents`
--

INSERT INTO `agents` (`agent_id`, `date_of_birth`, `account_status`, `created_at`, `onboarding_completed`, `employment_status`, `termination_date`, `termination_type`, `termination_reason`, `termination_notes`, `terminated_by`, `last_working_date`, `notice_period_served`, `completion_date`, `full_name`, `father_name`, `mother_name`, `mobile`, `alt_mobile_1`, `alt_mobile_2`, `email`, `email_verified`, `resume_path`, `pan_number`, `pan_image_path`, `aadhaar_number`, `aadhaar_front_path`, `aadhaar_back_path`, `candidate_photo_path`, `latitude`, `longitude`, `accuracy`, `location_timestamp`, `house_photo_1`, `house_photo_2`, `street_photo`, `house_number`, `street`, `locality`, `landmark`, `city`, `state`, `pincode`, `country`, `submitted`, `submitted_at`, `verified_by`, `verification_status`, `verification_timestamp`, `verification_reason`, `onboarding_data`, `audit_log`, `storage_path`, `current_address`, `verified_fields`, `correction_requests`, `corrections_made`, `total_bio_breaks`, `shift_type`, `work_location`, `department`, `designation`, `joining_date`, `status`, `shift_assigned_by`, `shift_assigned_at`, `shift_updated_at`, `salary`, `edu_10_school`, `highest_education`, `edu_10_roll`, `edu_10_board`, `edu_10_year`, `edu_10_percent`, `edu_10_doc`, `edu_12_school`, `edu_12_roll`, `edu_12_board`, `edu_12_year`, `edu_12_percent`, `edu_12_doc`, `edu_grad_college`, `edu_grad_roll`, `edu_grad_degree`, `edu_grad_year`, `edu_grad_percent`, `edu_grad_doc`, `edu_pg_college`, `edu_pg_roll`, `edu_pg_degree`, `edu_pg_year`, `edu_pg_percent`, `edu_pg_doc`, `privacy_accepted`) VALUES

-- --------------------------------------------------------

--
-- Table structure for table `agent_login_logs`
--

CREATE TABLE `agent_login_logs` (
  `id` int(11) NOT NULL,
  `agent_id` varchar(50) NOT NULL,
  `login_time` timestamp NULL DEFAULT current_timestamp(),
  `ip_address` varchar(50) DEFAULT NULL,
  `user_agent` text DEFAULT NULL
) ENGINE=MyISAM DEFAULT CHARSET=latin1 COLLATE=latin1_swedish_ci;

--
-- Dumping data for table `agent_login_logs`
--

INSERT INTO `agent_login_logs` (`id`, `agent_id`, `login_time`, `ip_address`, `user_agent`) VALUES

-- --------------------------------------------------------

--
-- Table structure for table `agent_salaries`
--

CREATE TABLE `agent_salaries` (
  `id` int(11) NOT NULL,
  `agent_id` varchar(50) NOT NULL,
  `salary` decimal(10,2) NOT NULL DEFAULT 13000.00,
  `effective_date` date NOT NULL,
  `salary_type` enum('in_hand','ctc','gross') DEFAULT 'in_hand',
  `revision_reason` text DEFAULT NULL,
  `revision_notes` text DEFAULT NULL,
  `performance_rating` varchar(50) DEFAULT NULL,
  `status` enum('active','inactive') DEFAULT 'active',
  `created_by` varchar(50) DEFAULT 'admin',
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=MyISAM DEFAULT CHARSET=latin1 COLLATE=latin1_swedish_ci;

--
-- Dumping data for table `agent_salaries`
--

INSERT INTO `agent_salaries` (`id`, `agent_id`, `salary`, `effective_date`, `salary_type`, `revision_reason`, `revision_notes`, `performance_rating`, `status`, `created_by`, `created_at`, `updated_at`) VALUES

-- --------------------------------------------------------

--
-- Table structure for table `agent_targets`
--

CREATE TABLE `agent_targets` (
  `id` int(11) NOT NULL,
  `agent_id` varchar(50) NOT NULL,
  `month` int(11) NOT NULL,
  `year` int(11) NOT NULL,
  `target` int(11) NOT NULL DEFAULT 0,
  `set_by` varchar(50) DEFAULT NULL,
  `created_at` datetime DEFAULT current_timestamp(),
  `updated_at` datetime DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `agent_targets`
--

INSERT INTO `agent_targets` (`id`, `agent_id`, `month`, `year`, `target`, `set_by`, `created_at`, `updated_at`) VALUES

-- --------------------------------------------------------

--
-- Table structure for table `agent_termination_history`
--

CREATE TABLE `agent_termination_history` (
  `id` int(11) NOT NULL,
  `agent_id` varchar(50) NOT NULL,
  `agent_name` varchar(200) DEFAULT NULL,
  `action_type` enum('terminated','resigned','absconded','reinstated','on_hold') NOT NULL,
  `termination_type` varchar(50) DEFAULT NULL,
  `termination_date` date DEFAULT NULL,
  `last_working_date` date DEFAULT NULL,
  `reason` text DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `action_by` varchar(50) DEFAULT NULL,
  `action_at` timestamp NULL DEFAULT current_timestamp()
) ENGINE=MyISAM DEFAULT CHARSET=latin1 COLLATE=latin1_swedish_ci;

--
-- Dumping data for table `agent_termination_history`
--

INSERT INTO `agent_termination_history` (`id`, `agent_id`, `agent_name`, `action_type`, `termination_type`, `termination_date`, `last_working_date`, `reason`, `notes`, `action_by`, `action_at`) VALUES

-- --------------------------------------------------------

--
-- Table structure for table `announcements`
--

CREATE TABLE `announcements` (
  `id` int(11) NOT NULL,
  `title` varchar(200) NOT NULL,
  `message` text DEFAULT NULL,
  `type` enum('info','success','warning','danger') DEFAULT 'info',
  `target` enum('all','agents','admin') DEFAULT 'all',
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `expires_at` date DEFAULT NULL,
  `created_by` varchar(50) DEFAULT NULL
) ENGINE=MyISAM DEFAULT CHARSET=latin1 COLLATE=latin1_swedish_ci;

-- --------------------------------------------------------

--
-- Table structure for table `attendance`
--

CREATE TABLE `attendance` (
  `id` int(11) NOT NULL,
  `agent_id` varchar(10) NOT NULL,
  `date` date NOT NULL,
  `login_time` time DEFAULT NULL,
  `logout_time` time DEFAULT NULL,
  `lunch_start` time DEFAULT NULL,
  `lunch_end` time DEFAULT NULL,
  `bio_start` time DEFAULT NULL,
  `bio_end` time DEFAULT NULL,
  `snack_start` time DEFAULT NULL,
  `snack_end` time DEFAULT NULL,
  `status` enum('present','absent','logged_out','holiday') DEFAULT 'present',
  `attendance_status` enum('present','absent','half_day','holiday','weekend') DEFAULT 'present',
  `marked_by` varchar(50) DEFAULT NULL,
  `marked_at` timestamp NULL DEFAULT NULL,
  `attendance_note` text DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `break_comment` text DEFAULT NULL,
  `job_start_time` datetime DEFAULT NULL,
  `job_end_time` datetime DEFAULT NULL,
  `total_working_seconds` int(11) DEFAULT 0,
  `break_timer_start` datetime DEFAULT NULL,
  `break_timer_end` datetime DEFAULT NULL,
  `break_timer_type` varchar(20) DEFAULT NULL,
  `snack_count` int(11) DEFAULT 0,
  `break_alert_shown` tinyint(1) DEFAULT 0,
  `job_paused` tinyint(1) DEFAULT 0,
  `job_pause_time` datetime DEFAULT NULL,
  `total_pause_seconds` int(11) DEFAULT 0,
  `total_break_seconds` int(11) DEFAULT 0,
  `break_ended_alert` tinyint(1) DEFAULT 0,
  `resume_alert_shown` tinyint(1) DEFAULT 0,
  `daily_report_generated` tinyint(1) DEFAULT 0,
  `bio_count` int(11) DEFAULT 0,
  `location_lat` varchar(255) DEFAULT NULL,
  `location_lng` varchar(255) DEFAULT NULL,
  `location_accuracy` varchar(50) DEFAULT NULL,
  `location_captured_at` datetime DEFAULT NULL,
  `location_ip` varchar(50) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `attendance`
--

INSERT INTO `attendance` (`id`, `agent_id`, `date`, `login_time`, `logout_time`, `lunch_start`, `lunch_end`, `bio_start`, `bio_end`, `snack_start`, `snack_end`, `status`, `attendance_status`, `marked_by`, `marked_at`, `attendance_note`, `created_at`, `updated_at`, `break_comment`, `job_start_time`, `job_end_time`, `total_working_seconds`, `break_timer_start`, `break_timer_end`, `break_timer_type`, `snack_count`, `break_alert_shown`, `job_paused`, `job_pause_time`, `total_pause_seconds`, `total_break_seconds`, `break_ended_alert`, `resume_alert_shown`, `daily_report_generated`, `bio_count`, `location_lat`, `location_lng`, `location_accuracy`, `location_captured_at`, `location_ip`) VALUES

-- --------------------------------------------------------

--
-- Table structure for table `audit_log`
--

CREATE TABLE `audit_log` (
  `audit_id` int(11) NOT NULL,
  `agent_id` varchar(10) NOT NULL,
  `action` varchar(50) NOT NULL COMMENT 'e.g., agent_created, email_verified, etc.',
  `details` text DEFAULT NULL,
  `timestamp` timestamp NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `audit_log`
--

INSERT INTO `audit_log` (`audit_id`, `agent_id`, `action`, `details`, `timestamp`) VALUES

INSERT INTO `audit_log` (`audit_id`, `agent_id`, `action`, `details`, `timestamp`) VALUES

-- --------------------------------------------------------

--
-- Table structure for table `company_settings`
--

CREATE TABLE `company_settings` (
  `id` int(11) NOT NULL,
  `setting_key` varchar(100) DEFAULT NULL,
  `setting_value` text DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=MyISAM DEFAULT CHARSET=latin1 COLLATE=latin1_swedish_ci;

--
-- Dumping data for table `company_settings`
--

INSERT INTO `company_settings` (`id`, `setting_key`, `setting_value`, `updated_at`) VALUES

-- --------------------------------------------------------

--
-- Table structure for table `company_targets`
--

CREATE TABLE `company_targets` (
  `id` int(11) NOT NULL,
  `month` int(11) NOT NULL,
  `year` int(11) NOT NULL,
  `target` int(11) NOT NULL DEFAULT 0,
  `set_by` varchar(50) DEFAULT NULL,
  `created_at` datetime DEFAULT current_timestamp(),
  `updated_at` datetime DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `company_targets`
--

INSERT INTO `company_targets` (`id`, `month`, `year`, `target`, `set_by`, `created_at`, `updated_at`) VALUES

-- --------------------------------------------------------

--
-- Table structure for table `compliance_agreements`
--

CREATE TABLE `compliance_agreements` (
  `id` int(11) NOT NULL,
  `agent_id` varchar(50) NOT NULL,
  `full_name` varchar(200) NOT NULL,
  `email` varchar(200) NOT NULL,
  `phone` varchar(50) NOT NULL,
  `address` text DEFAULT NULL,
  `photo_path` varchar(500) DEFAULT NULL,
  `signature_data` longtext NOT NULL COMMENT 'Base64 signature image',
  `agreed_at` timestamp NULL DEFAULT current_timestamp(),
  `ip_address` varchar(50) DEFAULT NULL,
  `user_agent` text DEFAULT NULL,
  `status` enum('pending','approved','rejected') DEFAULT 'pending',
  `approved_by` varchar(50) DEFAULT NULL,
  `approved_at` timestamp NULL DEFAULT NULL,
  `admin_notes` text DEFAULT NULL
) ENGINE=MyISAM DEFAULT CHARSET=latin1 COLLATE=latin1_swedish_ci;

--
-- Dumping data for table `compliance_agreements`
--

INSERT INTO `compliance_agreements` (`id`, `agent_id`, `full_name`, `email`, `phone`, `address`, `photo_path`, `signature_data`, `agreed_at`, `ip_address`, `user_agent`, `status`, `approved_by`, `approved_at`, `admin_notes`) VALUES

-- --------------------------------------------------------

--
-- Table structure for table `daily_sales`
--

CREATE TABLE `daily_sales` (
  `id` int(11) NOT NULL,
  `agent_id` varchar(50) NOT NULL,
  `sale_date` date NOT NULL,
  `credit_score` int(11) DEFAULT 0,
  `credit_strong` int(11) DEFAULT 0,
  `kikoff` int(11) DEFAULT 0,
  `other_sales` int(11) DEFAULT 0,
  `total_sales` int(11) GENERATED ALWAYS AS (`credit_score` + `credit_strong` + `kikoff` + `other_sales`) STORED,
  `notes` text DEFAULT NULL,
  `status` enum('pending','approved','rejected') DEFAULT 'pending',
  `approved_by` varchar(50) DEFAULT NULL,
  `approved_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=MyISAM DEFAULT CHARSET=latin1 COLLATE=latin1_swedish_ci;

--
-- Dumping data for table `daily_sales`
--

INSERT INTO `daily_sales` (`id`, `agent_id`, `sale_date`, `credit_score`, `credit_strong`, `kikoff`, `other_sales`, `notes`, `status`, `approved_by`, `approved_at`, `created_at`, `updated_at`) VALUES

-- --------------------------------------------------------

--
-- Table structure for table `email_logs`
--

CREATE TABLE `email_logs` (
  `id` int(11) NOT NULL,
  `recipient_email` varchar(100) NOT NULL,
  `subject` varchar(255) NOT NULL,
  `message` text NOT NULL,
  `attachment_path` varchar(255) DEFAULT NULL,
  `status` enum('sent','failed') DEFAULT 'sent',
  `sent_at` timestamp NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `issue_conversations`
--

CREATE TABLE `issue_conversations` (
  `id` int(11) NOT NULL,
  `issue_id` varchar(50) NOT NULL,
  `sender_type` enum('agent','admin') NOT NULL,
  `message` text NOT NULL,
  `sent_at` datetime DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `joining_letters`
--

CREATE TABLE `joining_letters` (
  `id` int(11) NOT NULL,
  `agent_id` varchar(50) NOT NULL,
  `full_name` varchar(200) NOT NULL,
  `email` varchar(200) NOT NULL,
  `phone` varchar(50) NOT NULL,
  `photo_path` varchar(500) DEFAULT NULL,
  `joining_date` date NOT NULL,
  `designation` varchar(200) DEFAULT 'Sales Executive',
  `department` varchar(100) DEFAULT 'Sales',
  `salary` decimal(10,2) DEFAULT 13000.00,
  `shift_type` varchar(20) DEFAULT 'night',
  `work_location` varchar(50) DEFAULT 'home',
  `signature_data` longtext DEFAULT NULL COMMENT 'Base64 agent signature',
  `company_stamp_path` varchar(500) DEFAULT NULL COMMENT 'Company stamp image path',
  `hr_sign_path` varchar(500) DEFAULT NULL COMMENT 'HR signature image path',
  `agreed_at` timestamp NULL DEFAULT current_timestamp(),
  `ip_address` varchar(50) DEFAULT NULL,
  `user_agent` text DEFAULT NULL,
  `status` enum('pending','approved','rejected') DEFAULT 'pending',
  `approved_by` varchar(50) DEFAULT NULL,
  `approved_at` timestamp NULL DEFAULT NULL,
  `admin_notes` text DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `salary_revised` decimal(10,2) DEFAULT NULL,
  `salary_revision_reason` text DEFAULT NULL,
  `salary_revision_date` datetime DEFAULT NULL,
  `admin_comment` text DEFAULT NULL,
  `performance_remarks` text DEFAULT NULL,
  `rejection_reason` text DEFAULT NULL,
  `rejected_at` datetime DEFAULT NULL,
  `rejected_by` varchar(100) DEFAULT NULL
) ENGINE=MyISAM DEFAULT CHARSET=latin1 COLLATE=latin1_swedish_ci;

--
-- Dumping data for table `joining_letters`
--

INSERT INTO `joining_letters` (`id`, `agent_id`, `full_name`, `email`, `phone`, `photo_path`, `joining_date`, `designation`, `department`, `salary`, `shift_type`, `work_location`, `signature_data`, `company_stamp_path`, `hr_sign_path`, `agreed_at`, `ip_address`, `user_agent`, `status`, `approved_by`, `approved_at`, `admin_notes`, `created_at`, `salary_revised`, `salary_revision_reason`, `salary_revision_date`, `admin_comment`, `performance_remarks`, `rejection_reason`, `rejected_at`, `rejected_by`) VALUES

-- --------------------------------------------------------

--
-- Table structure for table `sales`
--

CREATE TABLE `sales` (
  `id` varchar(50) NOT NULL,
  `agent_id` varchar(50) NOT NULL,
  `sale_date` date NOT NULL,
  `sale_type` varchar(50) NOT NULL,
  `quantity` int(11) NOT NULL DEFAULT 1,
  `reference` varchar(100) DEFAULT NULL,
  `comment` text DEFAULT NULL,
  `created_by` varchar(50) DEFAULT NULL,
  `created_at` datetime DEFAULT current_timestamp(),
  `updated_at` datetime DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `sales`
--

INSERT INTO `sales` (`id`, `agent_id`, `sale_date`, `sale_type`, `quantity`, `reference`, `comment`, `created_by`, `created_at`, `updated_at`) VALUES

-- --------------------------------------------------------

--
-- Table structure for table `sales_attendance`
--

CREATE TABLE `sales_attendance` (
  `id` int(11) NOT NULL,
  `agent_id` varchar(50) NOT NULL,
  `attendance_date` date NOT NULL,
  `status` enum('present','absent','weekly_off','holiday','leave') NOT NULL DEFAULT 'absent',
  `holiday_name` varchar(100) DEFAULT NULL,
  `comment` text DEFAULT NULL,
  `updated_by` varchar(50) DEFAULT NULL,
  `created_at` datetime DEFAULT current_timestamp(),
  `updated_at` datetime DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `sales_history`
--

CREATE TABLE `sales_history` (
  `id` int(11) NOT NULL,
  `sale_id` varchar(50) DEFAULT NULL,
  `agent_id` varchar(50) DEFAULT NULL,
  `old_quantity` int(11) DEFAULT 0,
  `new_quantity` int(11) DEFAULT 0,
  `old_type` varchar(50) DEFAULT NULL,
  `new_type` varchar(50) DEFAULT NULL,
  `changed_by` varchar(50) DEFAULT NULL,
  `change_reason` text DEFAULT NULL,
  `changed_at` datetime DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `sales_issues`
--

CREATE TABLE `sales_issues` (
  `id` varchar(50) NOT NULL,
  `agent_id` varchar(50) NOT NULL,
  `subject` varchar(255) NOT NULL,
  `category` varchar(50) NOT NULL,
  `message` text NOT NULL,
  `status` enum('open','in_review','resolved','closed') NOT NULL DEFAULT 'open',
  `admin_response` text DEFAULT NULL,
  `admin_response_at` datetime DEFAULT NULL,
  `created_at` datetime DEFAULT current_timestamp(),
  `updated_at` datetime DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `sales_reports`
--

CREATE TABLE `sales_reports` (
  `id` varchar(50) NOT NULL,
  `agent_id` varchar(50) DEFAULT NULL,
  `report_type` enum('daily','weekly','monthly','custom') NOT NULL,
  `month` int(11) DEFAULT NULL,
  `year` int(11) DEFAULT NULL,
  `report_data` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL
) ;

-- --------------------------------------------------------

--
-- Table structure for table `sales_report_requests`
--

CREATE TABLE `sales_report_requests` (
  `id` int(11) NOT NULL,
  `agent_id` varchar(50) NOT NULL,
  `request_type` enum('daily','weekly','monthly') DEFAULT 'monthly',
  `month_year` varchar(7) DEFAULT NULL,
  `status` enum('pending','approved','rejected','sent') DEFAULT 'pending',
  `requested_at` timestamp NULL DEFAULT current_timestamp(),
  `approved_by` varchar(50) DEFAULT NULL,
  `approved_at` timestamp NULL DEFAULT NULL,
  `sent_at` timestamp NULL DEFAULT NULL
) ENGINE=MyISAM DEFAULT CHARSET=latin1 COLLATE=latin1_swedish_ci;

--
-- Dumping data for table `sales_report_requests`
--

INSERT INTO `sales_report_requests` (`id`, `agent_id`, `request_type`, `month_year`, `status`, `requested_at`, `approved_by`, `approved_at`, `sent_at`) VALUES

-- --------------------------------------------------------

--
-- Table structure for table `sales_targets`
--

CREATE TABLE `sales_targets` (
  `id` int(11) NOT NULL,
  `agent_id` varchar(50) NOT NULL,
  `month_year` varchar(7) NOT NULL COMMENT 'Format: YYYY-MM',
  `monthly_target` int(11) DEFAULT 0,
  `credit_score_target` int(11) DEFAULT 0,
  `credit_strong_target` int(11) DEFAULT 0,
  `kikoff_target` int(11) DEFAULT 0,
  `other_target` int(11) DEFAULT 0,
  `working_days` int(11) DEFAULT 22,
  `weekly_target` int(11) DEFAULT 0,
  `daily_target` decimal(10,2) DEFAULT 0.00 COMMENT 'Auto-calculated',
  `created_by` varchar(50) DEFAULT 'admin',
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=MyISAM DEFAULT CHARSET=latin1 COLLATE=latin1_swedish_ci;

--
-- Dumping data for table `sales_targets`
--

INSERT INTO `sales_targets` (`id`, `agent_id`, `month_year`, `monthly_target`, `credit_score_target`, `credit_strong_target`, `kikoff_target`, `other_target`, `working_days`, `weekly_target`, `daily_target`, `created_by`, `created_at`, `updated_at`) VALUES

-- --------------------------------------------------------

--
-- Table structure for table `sale_types`
--

CREATE TABLE `sale_types` (
  `id` varchar(50) NOT NULL,
  `name` varchar(100) NOT NULL,
  `short_name` varchar(20) DEFAULT NULL,
  `priority` int(11) NOT NULL DEFAULT 1,
  `counts_toward_target` tinyint(1) NOT NULL DEFAULT 0,
  `active` tinyint(1) NOT NULL DEFAULT 1,
  `color` varchar(20) DEFAULT '#6c757d',
  `description` text DEFAULT NULL,
  `created_at` datetime DEFAULT current_timestamp(),
  `updated_at` datetime DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `sale_types`
--

INSERT INTO `sale_types` (`id`, `name`, `short_name`, `priority`, `counts_toward_target`, `active`, `color`, `description`, `created_at`, `updated_at`) VALUES

-- --------------------------------------------------------

--
-- Table structure for table `training_letters`
--

CREATE TABLE `training_letters` (
  `id` int(11) NOT NULL,
  `agent_id` varchar(50) NOT NULL,
  `full_name` varchar(200) NOT NULL,
  `email` varchar(200) NOT NULL,
  `phone` varchar(50) NOT NULL,
  `address` text DEFAULT NULL,
  `photo_path` varchar(500) DEFAULT NULL,
  `signature_data` longtext NOT NULL COMMENT 'Base64 agent signature',
  `agreed_at` timestamp NULL DEFAULT current_timestamp(),
  `ip_address` varchar(50) DEFAULT NULL,
  `user_agent` text DEFAULT NULL,
  `status` enum('pending','approved','rejected') DEFAULT 'pending',
  `approved_by` varchar(50) DEFAULT NULL,
  `approved_at` timestamp NULL DEFAULT NULL,
  `admin_notes` text DEFAULT NULL
) ENGINE=MyISAM DEFAULT CHARSET=latin1 COLLATE=latin1_swedish_ci;

--
-- Indexes for dumped tables
--

--
-- Indexes for table `admins`
--
ALTER TABLE `admins`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `username` (`username`);

--
-- Indexes for table `admin_email_settings`
--
ALTER TABLE `admin_email_settings`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `admin_login_logs`
--
ALTER TABLE `admin_login_logs`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_admin_date` (`admin_id`,`login_time`);

--
-- Indexes for table `admin_password_resets`
--
ALTER TABLE `admin_password_resets`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `admin_targets`
--
ALTER TABLE `admin_targets`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `month_year` (`month_year`);

--
-- Indexes for table `agent_login_logs`
--
ALTER TABLE `agent_login_logs`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_agent_date` (`agent_id`,`login_time`);

--
-- Indexes for table `agent_salaries`
--
ALTER TABLE `agent_salaries`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `unique_agent_active` (`agent_id`,`status`),
  ADD KEY `idx_agent` (`agent_id`),
  ADD KEY `idx_status` (`status`);

--
-- Indexes for table `agent_targets`
--
ALTER TABLE `agent_targets`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `unique_agent_month` (`agent_id`,`month`,`year`),
  ADD KEY `idx_month_year` (`month`,`year`),
  ADD KEY `idx_agent` (`agent_id`);

--
-- Indexes for table `agent_termination_history`
--
ALTER TABLE `agent_termination_history`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_agent` (`agent_id`),
  ADD KEY `idx_action_date` (`action_at`);

--
-- Indexes for table `announcements`
--
ALTER TABLE `announcements`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `attendance`
--
ALTER TABLE `attendance`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_agent` (`agent_id`),
  ADD KEY `idx_date` (`date`),
  ADD KEY `idx_attendance_status` (`attendance_status`,`date`);

--
-- Indexes for table `audit_log`
--
ALTER TABLE `audit_log`
  ADD PRIMARY KEY (`audit_id`),
  ADD KEY `idx_agent` (`agent_id`),
  ADD KEY `idx_timestamp` (`timestamp`);

--
-- Indexes for table `company_settings`
--
ALTER TABLE `company_settings`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `setting_key` (`setting_key`);

--
-- Indexes for table `company_targets`
--
ALTER TABLE `company_targets`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `unique_month_year` (`month`,`year`);

--
-- Indexes for table `compliance_agreements`
--
ALTER TABLE `compliance_agreements`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `agent_id` (`agent_id`),
  ADD KEY `idx_status` (`status`),
  ADD KEY `idx_agent` (`agent_id`);

--
-- Indexes for table `daily_sales`
--
ALTER TABLE `daily_sales`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `unique_agent_date` (`agent_id`,`sale_date`),
  ADD KEY `idx_agent_date` (`agent_id`,`sale_date`),
  ADD KEY `idx_status` (`status`);

--
-- Indexes for table `email_logs`
--
ALTER TABLE `email_logs`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `issue_conversations`
--
ALTER TABLE `issue_conversations`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_issue_id` (`issue_id`);

--
-- Indexes for table `joining_letters`
--
ALTER TABLE `joining_letters`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `agent_id` (`agent_id`);

--
-- Indexes for table `sales`
--
ALTER TABLE `sales`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_agent_date` (`agent_id`,`sale_date`),
  ADD KEY `idx_date` (`sale_date`),
  ADD KEY `idx_type` (`sale_type`);

--
-- Indexes for table `sales_attendance`
--
ALTER TABLE `sales_attendance`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `unique_agent_date` (`agent_id`,`attendance_date`),
  ADD KEY `idx_date` (`attendance_date`);

--
-- Indexes for table `sales_history`
--
ALTER TABLE `sales_history`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_sale_id` (`sale_id`),
  ADD KEY `idx_agent` (`agent_id`);

--
-- Indexes for table `sales_issues`
--
ALTER TABLE `sales_issues`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_agent_status` (`agent_id`,`status`);

--
-- Indexes for table `sales_report_requests`
--
ALTER TABLE `sales_report_requests`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `sales_targets`
--
ALTER TABLE `sales_targets`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `unique_agent_month` (`agent_id`,`month_year`),
  ADD KEY `idx_month` (`month_year`);

--
-- Indexes for table `sale_types`
--
ALTER TABLE `sale_types`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `training_letters`
--
ALTER TABLE `training_letters`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `agent_id` (`agent_id`);

--
-- AUTO_INCREMENT for dumped tables
--

--
-- AUTO_INCREMENT for table `admins`
--
ALTER TABLE `admins`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

--
-- AUTO_INCREMENT for table `admin_email_settings`
--
ALTER TABLE `admin_email_settings`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=3;

--
-- AUTO_INCREMENT for table `admin_login_logs`
--
ALTER TABLE `admin_login_logs`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=152;

--
-- AUTO_INCREMENT for table `admin_password_resets`
--
ALTER TABLE `admin_password_resets`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=4;

--
-- AUTO_INCREMENT for table `admin_targets`
--
ALTER TABLE `admin_targets`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

--
-- AUTO_INCREMENT for table `agent_login_logs`
--
ALTER TABLE `agent_login_logs`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=467;

--
-- AUTO_INCREMENT for table `agent_salaries`
--
ALTER TABLE `agent_salaries`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=3;

--
-- AUTO_INCREMENT for table `agent_targets`
--
ALTER TABLE `agent_targets`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

--
-- AUTO_INCREMENT for table `agent_termination_history`
--
ALTER TABLE `agent_termination_history`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=8;

--
-- AUTO_INCREMENT for table `announcements`
--
ALTER TABLE `announcements`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `attendance`
--
ALTER TABLE `attendance`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=77;

--
-- AUTO_INCREMENT for table `audit_log`
--
ALTER TABLE `audit_log`
  MODIFY `audit_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=499;

--
-- AUTO_INCREMENT for table `company_settings`
--
ALTER TABLE `company_settings`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

--
-- AUTO_INCREMENT for table `company_targets`
--
ALTER TABLE `company_targets`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

--
-- AUTO_INCREMENT for table `compliance_agreements`
--
ALTER TABLE `compliance_agreements`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=10;

--
-- AUTO_INCREMENT for table `daily_sales`
--
ALTER TABLE `daily_sales`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

--
-- AUTO_INCREMENT for table `email_logs`
--
ALTER TABLE `email_logs`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `issue_conversations`
--
ALTER TABLE `issue_conversations`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `joining_letters`
--
ALTER TABLE `joining_letters`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=10;

--
-- AUTO_INCREMENT for table `sales_attendance`
--
ALTER TABLE `sales_attendance`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=6;

--
-- AUTO_INCREMENT for table `sales_history`
--
ALTER TABLE `sales_history`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `sales_report_requests`
--
ALTER TABLE `sales_report_requests`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=5;

--
-- AUTO_INCREMENT for table `sales_targets`
--
ALTER TABLE `sales_targets`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=3;

--
-- AUTO_INCREMENT for table `training_letters`
--
ALTER TABLE `training_letters`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- Constraints for dumped tables
--

--
-- Constraints for table `attendance`
--
ALTER TABLE `attendance`
  ADD CONSTRAINT `attendance_ibfk_1` FOREIGN KEY (`agent_id`) REFERENCES `agents` (`agent_id`) ON DELETE CASCADE;

--
-- Constraints for table `audit_log`
--
ALTER TABLE `audit_log`
  ADD CONSTRAINT `audit_log_ibfk_1` FOREIGN KEY (`agent_id`) REFERENCES `agents` (`agent_id`) ON DELETE CASCADE;
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
