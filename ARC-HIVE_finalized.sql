-- phpMyAdmin SQL Dump
-- version 5.2.2
-- https://www.phpmyadmin.net/
--
-- Host: 127.0.0.1
-- Generation Time: Aug 20, 2025 at 09:44 AM
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
-- Database: `arc-hive-maindb`
--

-- --------------------------------------------------------

--
-- Table structure for table `departments`
--

CREATE TABLE `departments` (
  `department_id` int(11) NOT NULL,
  `department_name` varchar(255) NOT NULL COMMENT 'Name of the department',
  `department_type` enum('college','office','sub_department') NOT NULL COMMENT 'Type (e.g., college, office, sub_department)',
  `name_type` enum('Academic','Administrative','Program') NOT NULL COMMENT 'Category (e.g., Academic, Administrative, Program)',
  `parent_department_id` int(11) DEFAULT NULL COMMENT 'Recursive reference to parent department',
  `folder_path` varchar(512) DEFAULT NULL COMMENT 'Physical folder path for the department'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `departments`
--

INSERT INTO `departments` (`department_id`, `department_name`, `department_type`, `name_type`, `parent_department_id`, `folder_path`) VALUES
(1, 'College of Education', 'college', 'Academic', NULL, 'Uploads/College of Education'),
(2, 'College of Arts and Sciences', 'college', 'Academic', NULL, 'Uploads/College of Arts and Sciences'),
(3, 'College of Engineering and Technology', 'college', 'Academic', NULL, 'Uploads/College of Engineering and Technology'),
(4, 'College of Business and Management', 'college', 'Academic', NULL, 'Uploads/College of Business and Management'),
(5, 'College of Agriculture and Forestry', 'college', 'Academic', NULL, 'Uploads/College of Agriculture and Forestry'),
(6, 'College of Veterinary Medicine', 'college', 'Academic', NULL, 'Uploads/College of Veterinary Medicine'),
(7, 'Bachelor of Elementary Education', 'sub_department', 'Program', 1, 'Uploads/College of Education/Bachelor of Elementary Education'),
(8, 'Early Childhood Education', 'sub_department', 'Program', 1, 'Uploads/College of Education/Early Childhood Education'),
(9, 'Secondary Education', 'sub_department', 'Program', 1, 'Uploads/College of Education/Secondary Education'),
(10, 'Technology and Livelihood Education', 'sub_department', 'Program', 1, 'Uploads/College of Education/Technology and Livelihood Education'),
(11, 'BS Development Communication', 'sub_department', 'Program', 2, 'Uploads/College of Arts and Sciences/BS Development Communication'),
(12, 'BS Psychology', 'sub_department', 'Program', 2, 'Uploads/College of Arts and Sciences/BS Psychology'),
(13, 'AB Economics', 'sub_department', 'Program', 2, 'Uploads/College of Arts and Sciences/AB Economics'),
(14, 'BS Geodetic Engineering', 'sub_department', 'Program', 3, 'Uploads/College of Engineering and Technology/BS Geodetic Engineering'),
(15, 'BS Agricultural and Biosystems Engineering', 'sub_department', 'Program', 3, 'Uploads/College of Engineering and Technology/BS Agricultural and Biosystems Engineering'),
(16, 'BS Information Technology', 'sub_department', 'Program', 3, 'Uploads/College of Engineering and Technology/BS Information Technology'),
(17, 'BS Business Administration', 'sub_department', 'Program', 4, 'Uploads/College of Business and Management/BS Business Administration'),
(18, 'BS Tourism Management', 'sub_department', 'Program', 4, 'Uploads/College of Business and Management/BS Tourism Management'),
(19, 'BS Entrepreneurship', 'sub_department', 'Program', 4, 'Uploads/College of Business and Management/BS Entrepreneurship'),
(20, 'BS Agribusiness', 'sub_department', 'Program', 4, 'Uploads/College of Business and Management/BS Agribusiness'),
(21, 'BS Agriculture', 'sub_department', 'Program', 5, 'Uploads/College of Agriculture and Forestry/BS Agriculture'),
(22, 'BS Forestry', 'sub_department', 'Program', 5, 'Uploads/College of Agriculture and Forestry/BS Forestry'),
(23, 'BS Animal Science', 'sub_department', 'Program', 5, 'Uploads/College of Agriculture and Forestry/BS Animal Science'),
(24, 'BS Food Technology', 'sub_department', 'Program', 5, 'Uploads/College of Agriculture and Forestry/BS Food Technology'),
(25, 'Doctor of Veterinary Medicine', 'sub_department', 'Program', 6, 'Uploads/College of Veterinary Medicine/Doctor of Veterinary Medicine'),
(26, 'Admission and Registration Services', 'office', 'Administrative', NULL, 'Uploads/Admission and Registration Services'),
(27, 'Audit Offices', 'office', 'Administrative', NULL, 'Uploads/Audit Offices'),
(28, 'External Linkages and International Affairs', 'office', 'Administrative', NULL, 'Uploads/External Linkages and International Affairs'),
(29, 'Management Information Systems', 'office', 'Administrative', NULL, 'Uploads/Management Information Systems'),
(30, 'Office of the President', 'office', 'Administrative', NULL, 'Uploads/Office of the President');

-- --------------------------------------------------------

--
-- Table structure for table `document_types`
--

CREATE TABLE `document_types` (
  `document_type_id` int(11) NOT NULL,
  `type_name` varchar(50) NOT NULL COMMENT 'Name of the document type (e.g., Memorandum)',
  `field_name` varchar(50) NOT NULL COMMENT 'Field identifier for the document type',
  `field_label` varchar(255) NOT NULL COMMENT 'Human-readable label for the field',
  `field_type` enum('text','number','date','file') NOT NULL COMMENT 'Data type of the field'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `files`
--

CREATE TABLE `files` (
  `file_id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `department_id` int(11) DEFAULT NULL,
  `sub_department_id` int(11) DEFAULT NULL,
  `document_type_id` int(11) DEFAULT NULL,
  `file_name` varchar(255) NOT NULL,
  `file_path` varchar(512) DEFAULT NULL,
  `copy_type` enum('hard_copy','soft_copy') NOT NULL,
  `folder_capacity` int(11) DEFAULT NULL,
  `upload_date` datetime NOT NULL DEFAULT current_timestamp(),
  `parent_file_id` int(11) DEFAULT NULL,
  `location_id` int(11) DEFAULT NULL,
  `access_level` enum('personal','sub_department','college') NOT NULL DEFAULT 'personal'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `storage_locations`
--

CREATE TABLE `storage_locations` (
  `location_id` int(11) NOT NULL,
  `department_id` int(11) DEFAULT NULL,
  `sub_department_id` int(11) DEFAULT NULL,
  `room` varchar(100) NOT NULL,
  `cabinet` varchar(100) NOT NULL,
  `layer` varchar(100) NOT NULL,
  `box` varchar(100) NOT NULL,
  `folder` varchar(100) NOT NULL,
  `folder_capacity` int(11) NOT NULL,
  `folder_path` varchar(512) NOT NULL COMMENT 'Full folder path for storage'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `text_repository`
--

CREATE TABLE `text_repository` (
  `content_id` int(11) NOT NULL,
  `file_id` int(11) NOT NULL,
  `content` text NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `transactions`
--

CREATE TABLE `transactions` (
  `transaction_id` int(11) NOT NULL,
  `user_id` int(11) DEFAULT NULL,
  `file_id` int(11) DEFAULT NULL,
  `users_department_id` int(11) DEFAULT NULL,
  `transaction_status` enum('completed','pending','failed') NOT NULL,
  `transaction_time` datetime NOT NULL,
  `description` varchar(255) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `transactions`
--

INSERT INTO `transactions` (`transaction_id`, `user_id`, `file_id`, `users_department_id`, `transaction_status`, `transaction_time`, `description`) VALUES
(157, NULL, NULL, NULL, 'failed', '2025-08-20 15:37:03', 'Invalid login attempt for username: user'),
(158, 15, NULL, NULL, 'completed', '2025-08-20 15:43:18', 'Successful login for username: user');

-- --------------------------------------------------------

--
-- Table structure for table `users`
--

CREATE TABLE `users` (
  `user_id` int(11) NOT NULL,
  `username` varchar(50) NOT NULL,
  `email` varchar(100) NOT NULL,
  `password` varchar(255) NOT NULL,
  `role` enum('admin','user') NOT NULL,
  `personal_folder` varchar(512) DEFAULT NULL COMMENT 'Personal folder path for user'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `users`
--

INSERT INTO `users` (`user_id`, `username`, `email`, `password`, `role`, `personal_folder`) VALUES
(1, 'AdminUser', 'admin@example.com', '$2y$10$J3z1b3zK7G6kXz1Y6z3X9uJ7X8z1Y9z2K3z4L5z6M7z8N9z0P1z2', 'admin', 'Uploads/Office of the President/AdminUser'),
(10, 'Trevor Mundo', 'trevor@example.com', '$2y$10$uv2Q/VDISAkVggfX92u1GeB9SVZRWryEAN0Mq8Cba1ugPtPMNFU8W', 'user', 'Uploads/College of Engineering and Technology/BS Information Technology/Trevor Mundo'),
(12, 'ADMIN1234', 'admin1234@example.com', '$2y$10$TLlND66RAIX9Mo6D3z/Q9eQlbxsrG8ZVAB9ZLqjrTtHpVidVd4ay6', 'admin', 'Uploads/College of Arts and Sciences/AB Economics/ADMIN1234'),
(13, 'newuser', 'newuser@example.com', '$2y$10$hW3hp.Ruo.ian6EEUKoADOxGZUX8enOuwdMhjhO.y85jfUkXswS6i', 'user', 'Uploads/College of Engineering and Technology/newuser'),
(14, 'Sgt Caleb Steven A Lagunilla PA (Res)', 'caleb@example.com', '$2y$10$NHLno0YjMoh3NRgB4a76HutxvjLjBGz/5/lKEMypNY5MDH2MHiQBe', 'admin', 'Uploads/College of Education/Technology and Livelihood Education/Sgt Caleb Steven A Lagunilla PA (Res)'),
(15, 'user', 'user@example.com', '$2y$10$OVU0nH8jZ7SIec6iNs8Ate8vuxx7xUSM10YePtoUZxhd0FIz3eRXW', 'admin', 'Uploads/College of Engineering and Technology/BS Information Technology/user'),
(20, 'Mary Johnson', 'mary@example.com', '$2y$10$samplehash1', 'admin', 'Uploads/Office of the President/Mary Johnson'),
(21, 'Robert Lee', 'robert@example.com', '$2y$10$samplehash2', 'user', 'Uploads/External Linkages and International Affairs/Robert Lee'),
(22, 'Susan Kim', 'susan@example.com', '$2y$10$samplehash3', 'user', 'Uploads/External Linkages and International Affairs/Susan Kim'),
(23, 'James Brown', 'james@example.com', '$2y$10$samplehash4', 'admin', 'Uploads/Office of the President/James Brown'),
(24, 'Linda Davis', 'linda@example.com', '$2y$10$samplehash5', 'user', 'Uploads/Management Information Systems/Linda Davis'),
(25, 'Michael Chen', 'michael@example.com', '$2y$10$samplehash6', 'admin', 'Uploads/Office of the President/Michael Chen');

-- --------------------------------------------------------

--
-- Table structure for table `users_department`
--

CREATE TABLE `users_department` (
  `users_department_id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `department_id` int(11) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Indexes for dumped tables
--

--
-- Indexes for table `departments`
--
ALTER TABLE `departments`
  ADD PRIMARY KEY (`department_id`),
  ADD UNIQUE KEY `idx_department_name` (`department_name`),
  ADD KEY `fk_departments_parent` (`parent_department_id`);

--
-- Indexes for table `document_types`
--
ALTER TABLE `document_types`
  ADD PRIMARY KEY (`document_type_id`),
  ADD KEY `idx_type_name` (`type_name`);

--
-- Indexes for table `files`
--
ALTER TABLE `files`
  ADD PRIMARY KEY (`file_id`),
  ADD KEY `fk_files_department` (`department_id`),
  ADD KEY `fk_files_sub_department` (`sub_department_id`),
  ADD KEY `fk_files_document_type` (`document_type_id`),
  ADD KEY `fk_files_user` (`user_id`),
  ADD KEY `fk_files_parent` (`parent_file_id`),
  ADD KEY `fk_files_location` (`location_id`);

--
-- Indexes for table `storage_locations`
--
ALTER TABLE `storage_locations`
  ADD PRIMARY KEY (`location_id`),
  ADD KEY `fk_storage_locations_department` (`department_id`),
  ADD KEY `fk_storage_locations_sub_department` (`sub_department_id`);

--
-- Indexes for table `text_repository`
--
ALTER TABLE `text_repository`
  ADD PRIMARY KEY (`content_id`),
  ADD KEY `fk_text_repository_file` (`file_id`);

--
-- Indexes for table `transactions`
--
ALTER TABLE `transactions`
  ADD PRIMARY KEY (`transaction_id`),
  ADD KEY `fk_transactions_user` (`user_id`),
  ADD KEY `fk_transactions_file` (`file_id`),
  ADD KEY `fk_transactions_users_department` (`users_department_id`),
  ADD KEY `idx_transaction_time` (`transaction_time`);

--
-- Indexes for table `users`
--
ALTER TABLE `users`
  ADD PRIMARY KEY (`user_id`),
  ADD UNIQUE KEY `idx_username` (`username`),
  ADD UNIQUE KEY `idx_email` (`email`),
  ADD KEY `idx_role` (`role`);

--
-- Indexes for table `users_department`
--
ALTER TABLE `users_department`
  ADD PRIMARY KEY (`users_department_id`),
  ADD UNIQUE KEY `idx_user_department` (`user_id`,`department_id`),
  ADD KEY `fk_users_department_department` (`department_id`);

--
-- AUTO_INCREMENT for dumped tables
--

--
-- AUTO_INCREMENT for table `departments`
--
ALTER TABLE `departments`
  MODIFY `department_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=31;

--
-- AUTO_INCREMENT for table `document_types`
--
ALTER TABLE `document_types`
  MODIFY `document_type_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=7;

--
-- AUTO_INCREMENT for table `files`
--
ALTER TABLE `files`
  MODIFY `file_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=115;

--
-- AUTO_INCREMENT for table `storage_locations`
--
ALTER TABLE `storage_locations`
  MODIFY `location_id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `text_repository`
--
ALTER TABLE `text_repository`
  MODIFY `content_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=143;

--
-- AUTO_INCREMENT for table `transactions`
--
ALTER TABLE `transactions`
  MODIFY `transaction_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=159;

--
-- AUTO_INCREMENT for table `users`
--
ALTER TABLE `users`
  MODIFY `user_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=26;

--
-- AUTO_INCREMENT for table `users_department`
--
ALTER TABLE `users_department`
  MODIFY `users_department_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=16;

--
-- Constraints for dumped tables
--

--
-- Constraints for table `departments`
--
ALTER TABLE `departments`
  ADD CONSTRAINT `fk_departments_parent` FOREIGN KEY (`parent_department_id`) REFERENCES `departments` (`department_id`) ON DELETE SET NULL;

--
-- Constraints for table `files`
--
ALTER TABLE `files`
  ADD CONSTRAINT `fk_files_department` FOREIGN KEY (`department_id`) REFERENCES `departments` (`department_id`) ON DELETE SET NULL,
  ADD CONSTRAINT `fk_files_document_type` FOREIGN KEY (`document_type_id`) REFERENCES `document_types` (`document_type_id`) ON DELETE SET NULL,
  ADD CONSTRAINT `fk_files_location` FOREIGN KEY (`location_id`) REFERENCES `storage_locations` (`location_id`) ON DELETE SET NULL,
  ADD CONSTRAINT `fk_files_parent` FOREIGN KEY (`parent_file_id`) REFERENCES `files` (`file_id`) ON DELETE SET NULL ON UPDATE CASCADE,
  ADD CONSTRAINT `fk_files_sub_department` FOREIGN KEY (`sub_department_id`) REFERENCES `departments` (`department_id`) ON DELETE SET NULL,
  ADD CONSTRAINT `fk_files_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`user_id`) ON DELETE CASCADE;

--
-- Constraints for table `storage_locations`
--
ALTER TABLE `storage_locations`
  ADD CONSTRAINT `fk_storage_locations_department` FOREIGN KEY (`department_id`) REFERENCES `departments` (`department_id`) ON DELETE SET NULL,
  ADD CONSTRAINT `fk_storage_locations_sub_department` FOREIGN KEY (`sub_department_id`) REFERENCES `departments` (`department_id`) ON DELETE SET NULL;

--
-- Constraints for table `text_repository`
--
ALTER TABLE `text_repository`
  ADD CONSTRAINT `fk_text_repository_file` FOREIGN KEY (`file_id`) REFERENCES `files` (`file_id`) ON DELETE CASCADE ON UPDATE CASCADE;

--
-- Constraints for table `transactions`
--
ALTER TABLE `transactions`
  ADD CONSTRAINT `fk_transactions_file` FOREIGN KEY (`file_id`) REFERENCES `files` (`file_id`) ON DELETE SET NULL,
  ADD CONSTRAINT `fk_transactions_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`user_id`) ON DELETE SET NULL,
  ADD CONSTRAINT `fk_transactions_users_department` FOREIGN KEY (`users_department_id`) REFERENCES `users_department` (`users_department_id`) ON DELETE SET NULL;

--
-- Constraints for table `users_department`
--
ALTER TABLE `users_department`
  ADD CONSTRAINT `fk_users_department_department` FOREIGN KEY (`department_id`) REFERENCES `departments` (`department_id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_users_department_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`user_id`) ON DELETE CASCADE;
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
