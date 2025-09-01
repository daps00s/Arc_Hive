-- Database Backup for arc-hive-maindb
-- Generated: 2025-08-21 19:02:39
-- Type: Automatic

-- Table structure for departments

CREATE TABLE `departments` (
  `department_id` int(11) NOT NULL AUTO_INCREMENT,
  `department_name` varchar(255) NOT NULL COMMENT 'Name of the department',
  `department_type` enum('college','office','sub_department') NOT NULL COMMENT 'Type (e.g., college, office, sub_department)',
  `name_type` enum('Academic','Administrative','Program') NOT NULL COMMENT 'Category (e.g., Academic, Administrative, Program)',
  `parent_department_id` int(11) DEFAULT NULL COMMENT 'Recursive reference to parent department',
  `folder_path` varchar(512) DEFAULT NULL COMMENT 'Physical folder path for the department',
  PRIMARY KEY (`department_id`),
  UNIQUE KEY `idx_department_name` (`department_name`),
  KEY `fk_departments_parent` (`parent_department_id`),
  CONSTRAINT `fk_departments_parent` FOREIGN KEY (`parent_department_id`) REFERENCES `departments` (`department_id`) ON DELETE SET NULL
) ENGINE=InnoDB AUTO_INCREMENT=31 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Data for departments
INSERT INTO `departments` VALUES ('1','College of Education','college','Academic',NULL,'Uploads/College of Education');
INSERT INTO `departments` VALUES ('2','College of Arts and Sciences','college','Academic',NULL,'Uploads/College of Arts and Sciences');
INSERT INTO `departments` VALUES ('3','College of Engineering and Technology','college','Academic',NULL,'Uploads/College of Engineering and Technology');
INSERT INTO `departments` VALUES ('4','College of Business and Management','college','Academic',NULL,'Uploads/College of Business and Management');
INSERT INTO `departments` VALUES ('5','College of Agriculture and Forestry','college','Academic',NULL,'Uploads/College of Agriculture and Forestry');
INSERT INTO `departments` VALUES ('6','College of Veterinary Medicine','college','Academic',NULL,'Uploads/College of Veterinary Medicine');
INSERT INTO `departments` VALUES ('7','Bachelor of Elementary Education','sub_department','Program','1','Uploads/College of Education/Bachelor of Elementary Education');
INSERT INTO `departments` VALUES ('8','Early Childhood Education','sub_department','Program','1','Uploads/College of Education/Early Childhood Education');
INSERT INTO `departments` VALUES ('9','Secondary Education','sub_department','Program','1','Uploads/College of Education/Secondary Education');
INSERT INTO `departments` VALUES ('10','Technology and Livelihood Education','sub_department','Program','1','Uploads/College of Education/Technology and Livelihood Education');
INSERT INTO `departments` VALUES ('11','BS Development Communication','sub_department','Program','2','Uploads/College of Arts and Sciences/BS Development Communication');
INSERT INTO `departments` VALUES ('12','BS Psychology','sub_department','Program','2','Uploads/College of Arts and Sciences/BS Psychology');
INSERT INTO `departments` VALUES ('13','AB Economics','sub_department','Program','2','Uploads/College of Arts and Sciences/AB Economics');
INSERT INTO `departments` VALUES ('14','BS Geodetic Engineering','sub_department','Program','3','Uploads/College of Engineering and Technology/BS Geodetic Engineering');
INSERT INTO `departments` VALUES ('15','BS Agricultural and Biosystems Engineering','sub_department','Program','3','Uploads/College of Engineering and Technology/BS Agricultural and Biosystems Engineering');
INSERT INTO `departments` VALUES ('16','BS Information Technology','sub_department','Program','3','Uploads/College of Engineering and Technology/BS Information Technology');
INSERT INTO `departments` VALUES ('17','BS Business Administration','sub_department','Program','4','Uploads/College of Business and Management/BS Business Administration');
INSERT INTO `departments` VALUES ('18','BS Tourism Management','sub_department','Program','4','Uploads/College of Business and Management/BS Tourism Management');
INSERT INTO `departments` VALUES ('19','BS Entrepreneurship','sub_department','Program','4','Uploads/College of Business and Management/BS Entrepreneurship');
INSERT INTO `departments` VALUES ('20','BS Agribusiness','sub_department','Program','4','Uploads/College of Business and Management/BS Agribusiness');
INSERT INTO `departments` VALUES ('21','BS Agriculture','sub_department','Program','5','Uploads/College of Agriculture and Forestry/BS Agriculture');
INSERT INTO `departments` VALUES ('22','BS Forestry','sub_department','Program','5','Uploads/College of Agriculture and Forestry/BS Forestry');
INSERT INTO `departments` VALUES ('23','BS Animal Science','sub_department','Program','5','Uploads/College of Agriculture and Forestry/BS Animal Science');
INSERT INTO `departments` VALUES ('24','BS Food Technology','sub_department','Program','5','Uploads/College of Agriculture and Forestry/BS Food Technology');
INSERT INTO `departments` VALUES ('25','Doctor of Veterinary Medicine','sub_department','Program','6','Uploads/College of Veterinary Medicine/Doctor of Veterinary Medicine');
INSERT INTO `departments` VALUES ('26','Admission and Registration Services','office','Administrative',NULL,'Uploads/Admission and Registration Services');
INSERT INTO `departments` VALUES ('27','Audit Offices','office','Administrative',NULL,'Uploads/Audit Offices');
INSERT INTO `departments` VALUES ('28','External Linkages and International Affairs','office','Administrative',NULL,'Uploads/External Linkages and International Affairs');
INSERT INTO `departments` VALUES ('29','Management Information Systems','office','Administrative',NULL,'Uploads/Management Information Systems');
INSERT INTO `departments` VALUES ('30','Office of the President','office','Administrative',NULL,'Uploads/Office of the President');

-- Table structure for document_types

CREATE TABLE `document_types` (
  `document_type_id` int(11) NOT NULL AUTO_INCREMENT,
  `type_name` varchar(50) NOT NULL COMMENT 'Name of the document type (e.g., Memorandum)',
  `field_name` varchar(50) NOT NULL COMMENT 'Field identifier for the document type',
  `field_label` varchar(255) NOT NULL COMMENT 'Human-readable label for the field',
  `field_type` enum('text','number','date','file') NOT NULL COMMENT 'Data type of the field',
  PRIMARY KEY (`document_type_id`),
  KEY `idx_type_name` (`type_name`)
) ENGINE=InnoDB AUTO_INCREMENT=7 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Table structure for files

CREATE TABLE `files` (
  `file_id` int(11) NOT NULL AUTO_INCREMENT,
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
  `access_level` enum('personal','sub_department','college') NOT NULL DEFAULT 'personal',
  PRIMARY KEY (`file_id`),
  KEY `fk_files_department` (`department_id`),
  KEY `fk_files_sub_department` (`sub_department_id`),
  KEY `fk_files_document_type` (`document_type_id`),
  KEY `fk_files_user` (`user_id`),
  KEY `fk_files_parent` (`parent_file_id`),
  KEY `fk_files_location` (`location_id`),
  CONSTRAINT `fk_files_department` FOREIGN KEY (`department_id`) REFERENCES `departments` (`department_id`) ON DELETE SET NULL,
  CONSTRAINT `fk_files_document_type` FOREIGN KEY (`document_type_id`) REFERENCES `document_types` (`document_type_id`) ON DELETE SET NULL,
  CONSTRAINT `fk_files_location` FOREIGN KEY (`location_id`) REFERENCES `storage_locations` (`location_id`) ON DELETE SET NULL,
  CONSTRAINT `fk_files_parent` FOREIGN KEY (`parent_file_id`) REFERENCES `files` (`file_id`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `fk_files_sub_department` FOREIGN KEY (`sub_department_id`) REFERENCES `departments` (`department_id`) ON DELETE SET NULL,
  CONSTRAINT `fk_files_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`user_id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=115 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Table structure for storage_locations

CREATE TABLE `storage_locations` (
  `location_id` int(11) NOT NULL AUTO_INCREMENT,
  `department_id` int(11) DEFAULT NULL,
  `sub_department_id` int(11) DEFAULT NULL,
  `room` varchar(100) NOT NULL,
  `cabinet` varchar(100) NOT NULL,
  `layer` varchar(100) NOT NULL,
  `box` varchar(100) NOT NULL,
  `folder` varchar(100) NOT NULL,
  `folder_capacity` int(11) NOT NULL,
  `folder_path` varchar(512) NOT NULL COMMENT 'Full folder path for storage',
  PRIMARY KEY (`location_id`),
  KEY `fk_storage_locations_department` (`department_id`),
  KEY `fk_storage_locations_sub_department` (`sub_department_id`),
  CONSTRAINT `fk_storage_locations_department` FOREIGN KEY (`department_id`) REFERENCES `departments` (`department_id`) ON DELETE SET NULL,
  CONSTRAINT `fk_storage_locations_sub_department` FOREIGN KEY (`sub_department_id`) REFERENCES `departments` (`department_id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Table structure for text_repository

CREATE TABLE `text_repository` (
  `content_id` int(11) NOT NULL AUTO_INCREMENT,
  `file_id` int(11) NOT NULL,
  `content` text NOT NULL,
  PRIMARY KEY (`content_id`),
  KEY `fk_text_repository_file` (`file_id`),
  CONSTRAINT `fk_text_repository_file` FOREIGN KEY (`file_id`) REFERENCES `files` (`file_id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=143 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Table structure for transactions

CREATE TABLE `transactions` (
  `transaction_id` int(11) NOT NULL AUTO_INCREMENT,
  `user_id` int(11) DEFAULT NULL,
  `file_id` int(11) DEFAULT NULL,
  `users_department_id` int(11) DEFAULT NULL,
  `transaction_status` enum('completed','pending','failed') NOT NULL,
  `transaction_time` datetime NOT NULL,
  `description` varchar(255) DEFAULT NULL,
  PRIMARY KEY (`transaction_id`),
  KEY `fk_transactions_user` (`user_id`),
  KEY `fk_transactions_file` (`file_id`),
  KEY `fk_transactions_users_department` (`users_department_id`),
  KEY `idx_transaction_time` (`transaction_time`),
  CONSTRAINT `fk_transactions_file` FOREIGN KEY (`file_id`) REFERENCES `files` (`file_id`) ON DELETE SET NULL,
  CONSTRAINT `fk_transactions_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`user_id`) ON DELETE SET NULL,
  CONSTRAINT `fk_transactions_users_department` FOREIGN KEY (`users_department_id`) REFERENCES `users_department` (`users_department_id`) ON DELETE SET NULL
) ENGINE=InnoDB AUTO_INCREMENT=163 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Data for transactions
INSERT INTO `transactions` VALUES ('157',NULL,NULL,NULL,'failed','2025-08-20 15:37:03','Invalid login attempt for username: user');
INSERT INTO `transactions` VALUES ('158','15',NULL,NULL,'completed','2025-08-20 15:43:18','Successful login for username: user');
INSERT INTO `transactions` VALUES ('159','15',NULL,NULL,'completed','2025-08-20 15:48:39','Successful login for username: user');
INSERT INTO `transactions` VALUES ('160','15',NULL,NULL,'completed','2025-08-21 18:33:46','Successful login for username: user');
INSERT INTO `transactions` VALUES ('161','15',NULL,NULL,'completed','2025-08-21 18:36:05','Edited user: user');
INSERT INTO `transactions` VALUES ('162','15',NULL,NULL,'completed','2025-08-21 18:37:07','Successful login for username: user');

-- Table structure for users

CREATE TABLE `users` (
  `user_id` int(11) NOT NULL AUTO_INCREMENT,
  `username` varchar(50) NOT NULL,
  `email` varchar(100) NOT NULL,
  `password` varchar(255) NOT NULL,
  `role` enum('admin','user') NOT NULL,
  `personal_folder` varchar(512) DEFAULT NULL COMMENT 'Personal folder path for user',
  PRIMARY KEY (`user_id`),
  UNIQUE KEY `idx_username` (`username`),
  UNIQUE KEY `idx_email` (`email`),
  KEY `idx_role` (`role`)
) ENGINE=InnoDB AUTO_INCREMENT=26 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Data for users
INSERT INTO `users` VALUES ('1','AdminUser','admin@example.com','$2y$10$J3z1b3zK7G6kXz1Y6z3X9uJ7X8z1Y9z2K3z4L5z6M7z8N9z0P1z2','admin','Uploads/Office of the President/AdminUser');
INSERT INTO `users` VALUES ('10','Trevor Mundo','trevor@example.com','$2y$10$uv2Q/VDISAkVggfX92u1GeB9SVZRWryEAN0Mq8Cba1ugPtPMNFU8W','user','Uploads/College of Engineering and Technology/BS Information Technology/Trevor Mundo');
INSERT INTO `users` VALUES ('12','ADMIN1234','admin1234@example.com','$2y$10$TLlND66RAIX9Mo6D3z/Q9eQlbxsrG8ZVAB9ZLqjrTtHpVidVd4ay6','admin','Uploads/College of Arts and Sciences/AB Economics/ADMIN1234');
INSERT INTO `users` VALUES ('13','newuser','newuser@example.com','$2y$10$hW3hp.Ruo.ian6EEUKoADOxGZUX8enOuwdMhjhO.y85jfUkXswS6i','user','Uploads/College of Engineering and Technology/newuser');
INSERT INTO `users` VALUES ('14','Sgt Caleb Steven A Lagunilla PA (Res)','caleb@example.com','$2y$10$NHLno0YjMoh3NRgB4a76HutxvjLjBGz/5/lKEMypNY5MDH2MHiQBe','admin','Uploads/College of Education/Technology and Livelihood Education/Sgt Caleb Steven A Lagunilla PA (Res)');
INSERT INTO `users` VALUES ('15','user','user@example.com','$2y$10$OVU0nH8jZ7SIec6iNs8Ate8vuxx7xUSM10YePtoUZxhd0FIz3eRXW','admin','Uploads/Users/user');
INSERT INTO `users` VALUES ('20','Mary Johnson','mary@example.com','$2y$10$samplehash1','admin','Uploads/Office of the President/Mary Johnson');
INSERT INTO `users` VALUES ('21','Robert Lee','robert@example.com','$2y$10$samplehash2','user','Uploads/External Linkages and International Affairs/Robert Lee');
INSERT INTO `users` VALUES ('22','Susan Kim','susan@example.com','$2y$10$samplehash3','user','Uploads/External Linkages and International Affairs/Susan Kim');
INSERT INTO `users` VALUES ('23','James Brown','james@example.com','$2y$10$samplehash4','admin','Uploads/Office of the President/James Brown');
INSERT INTO `users` VALUES ('24','Linda Davis','linda@example.com','$2y$10$samplehash5','user','Uploads/Management Information Systems/Linda Davis');
INSERT INTO `users` VALUES ('25','Michael Chen','michael@example.com','$2y$10$samplehash6','admin','Uploads/Office of the President/Michael Chen');

-- Table structure for users_department

CREATE TABLE `users_department` (
  `users_department_id` int(11) NOT NULL AUTO_INCREMENT,
  `user_id` int(11) NOT NULL,
  `department_id` int(11) NOT NULL,
  PRIMARY KEY (`users_department_id`),
  UNIQUE KEY `idx_user_department` (`user_id`,`department_id`),
  KEY `fk_users_department_department` (`department_id`),
  CONSTRAINT `fk_users_department_department` FOREIGN KEY (`department_id`) REFERENCES `departments` (`department_id`) ON DELETE CASCADE,
  CONSTRAINT `fk_users_department_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`user_id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=17 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Data for users_department
INSERT INTO `users_department` VALUES ('16','15','16');

