-- MySQL dump 10.13  Distrib 8.0.17, for Win64 (x86_64)
--
-- Host: localhost    Database: fjusa
-- ------------------------------------------------------
-- Server version	8.0.17

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

--
-- Table structure for table `club_members`
--

DROP TABLE IF EXISTS `club_members`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `club_members` (
  `membership_id` int(11) NOT NULL AUTO_INCREMENT,
  `user_id` int(11) NOT NULL,
  `club_id` varchar(10) NOT NULL,
  `is_officer` tinyint(1) DEFAULT '0',
  `officer_title` varchar(50) DEFAULT NULL,
  `join_date` date DEFAULT NULL,
  `officer_confirmation_date` date DEFAULT NULL,
  PRIMARY KEY (`membership_id`),
  KEY `user_id` (`user_id`),
  KEY `club_id` (`club_id`),
  CONSTRAINT `club_members_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`user_id`),
  CONSTRAINT `club_members_ibfk_2` FOREIGN KEY (`club_id`) REFERENCES `clubs` (`club_id`)
) ENGINE=InnoDB AUTO_INCREMENT=11 DEFAULT CHARSET=utf8;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `clubs`
--

DROP TABLE IF EXISTS `clubs`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `clubs` (
  `club_id` varchar(10) NOT NULL,
  `club_name` varchar(100) NOT NULL,
  PRIMARY KEY (`club_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `coordination_conflicts`
--

DROP TABLE IF EXISTS `coordination_conflicts`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `coordination_conflicts` (
  `conflict_id` int(11) NOT NULL AUTO_INCREMENT,
  `setting_id` int(11) NOT NULL,
  `registration_id_1` int(11) NOT NULL,
  `registration_id_2` int(11) NOT NULL,
  `space_id` int(11) NOT NULL,
  `conflict_start_time` datetime NOT NULL,
  `conflict_end_time` datetime NOT NULL,
  `status` varchar(50) DEFAULT 'unresolved',
  `resolution_note` text,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`conflict_id`),
  KEY `setting_id` (`setting_id`),
  CONSTRAINT `coordination_conflicts_ibfk_1` FOREIGN KEY (`setting_id`) REFERENCES `field_coordination_settings` (`setting_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `equipment`
--

DROP TABLE IF EXISTS `equipment`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `equipment` (
  `equipment_id` int(11) NOT NULL AUTO_INCREMENT,
  `code` varchar(10) DEFAULT NULL,
  `name` varchar(100) NOT NULL,
  `description` text,
  `borrowing_limit` int(11) DEFAULT NULL,
  `total_quantity` int(11) NOT NULL,
  `equipment_status` enum('available','maintenance') DEFAULT 'available',
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`equipment_id`)
) ENGINE=InnoDB AUTO_INCREMENT=34 DEFAULT CHARSET=utf8;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `equipment_borrow`
--

DROP TABLE IF EXISTS `equipment_borrow`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `equipment_borrow` (
  `borrow_id` int(11) NOT NULL AUTO_INCREMENT,
  `event_id` int(11) DEFAULT NULL,
  `equipment_id` int(11) DEFAULT NULL,
  `quantity` int(11) DEFAULT NULL,
  `reservation_id` int(11) DEFAULT NULL,
  `borrow_start` datetime DEFAULT NULL,
  `borrow_end` datetime DEFAULT NULL,
  `request_id` int(11) DEFAULT NULL,
  PRIMARY KEY (`borrow_id`),
  KEY `event_id` (`event_id`),
  KEY `equipment_id` (`equipment_id`),
  KEY `fk_eb_reservation` (`reservation_id`),
  KEY `fk_eb_req` (`request_id`),
  KEY `idx_eb_borrow_time` (`borrow_start`,`borrow_end`),
  CONSTRAINT `equipment_borrow_ibfk_1` FOREIGN KEY (`event_id`) REFERENCES `events` (`event_id`),
  CONSTRAINT `equipment_borrow_ibfk_2` FOREIGN KEY (`equipment_id`) REFERENCES `equipment` (`equipment_id`),
  CONSTRAINT `fk_eb_req` FOREIGN KEY (`request_id`) REFERENCES `equipment_requests` (`request_id`) ON DELETE SET NULL,
  CONSTRAINT `fk_eb_reservation` FOREIGN KEY (`reservation_id`) REFERENCES `reservations` (`reservation_id`) ON DELETE SET NULL
) ENGINE=InnoDB AUTO_INCREMENT=34 DEFAULT CHARSET=utf8;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `equipment_requests`
--

DROP TABLE IF EXISTS `equipment_requests`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `equipment_requests` (
  `request_id` int(11) NOT NULL AUTO_INCREMENT,
  `parent_event_id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `club_name` varchar(100) NOT NULL,
  `borrow_start` datetime NOT NULL,
  `borrow_end` datetime NOT NULL,
  `status` enum('pending','approved','rejected','cancelled') DEFAULT 'pending',
  `review_note` text,
  `reviewed_at` datetime DEFAULT NULL,
  `reviewed_by` int(11) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`request_id`),
  KEY `parent_event_id` (`parent_event_id`),
  KEY `user_id` (`user_id`),
  CONSTRAINT `equipment_requests_ibfk_1` FOREIGN KEY (`parent_event_id`) REFERENCES `events` (`event_id`) ON DELETE CASCADE,
  CONSTRAINT `equipment_requests_ibfk_2` FOREIGN KEY (`user_id`) REFERENCES `users` (`user_id`)
) ENGINE=InnoDB AUTO_INCREMENT=3 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `events`
--

DROP TABLE IF EXISTS `events`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `events` (
  `event_id` int(11) NOT NULL AUTO_INCREMENT,
  `user_id` int(11) NOT NULL,
  `event_name` varchar(255) NOT NULL,
  `club_name` varchar(100) NOT NULL,
  `description` text,
  `start_time` datetime NOT NULL,
  `end_time` datetime NOT NULL,
  `status` enum('pending','approved','rejected','cancelled') DEFAULT 'pending',
  `is_field_coordination` tinyint(1) DEFAULT '0',
  `field_coordination_setting_id` int(11) DEFAULT NULL,
  `review_note` text,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `reviewed_at` datetime DEFAULT NULL,
  `reviewed_by` int(11) DEFAULT NULL,
  `responsible_person` varchar(100) DEFAULT NULL,
  `event_type` varchar(10) DEFAULT '校內',
  `activity_location` varchar(255) DEFAULT NULL,
  `activity_scale` varchar(200) DEFAULT '一般活動',
  `proposal_doc_path` varchar(255) DEFAULT NULL,
  PRIMARY KEY (`event_id`),
  KEY `user_id` (`user_id`),
  KEY `idx_ev_time` (`start_time`,`end_time`),
  CONSTRAINT `events_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`user_id`)
) ENGINE=InnoDB AUTO_INCREMENT=47 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `field_coordination`
--

DROP TABLE IF EXISTS `field_coordination`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `field_coordination` (
  `coord_id` int(11) NOT NULL AUTO_INCREMENT,
  `user_id` int(11) DEFAULT NULL,
  `event_id` int(11) DEFAULT NULL,
  `note` text,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`coord_id`),
  KEY `user_id` (`user_id`),
  KEY `event_id` (`event_id`),
  CONSTRAINT `field_coordination_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`user_id`),
  CONSTRAINT `field_coordination_ibfk_2` FOREIGN KEY (`event_id`) REFERENCES `events` (`event_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `field_coordination_registrations`
--

DROP TABLE IF EXISTS `field_coordination_registrations`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `field_coordination_registrations` (
  `registration_id` int(11) NOT NULL AUTO_INCREMENT,
  `setting_id` int(11) NOT NULL,
  `event_id` int(11) NOT NULL,
  `student_id` int(11) NOT NULL,
  `club_id` varchar(10) NOT NULL,
  `club_name` varchar(255) NOT NULL,
  `is_approved` tinyint(1) DEFAULT NULL,
  `approval_note` text,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`registration_id`),
  KEY `setting_id_idx` (`setting_id`),
  KEY `event_id_idx` (`event_id`),
  CONSTRAINT `field_coordination_registrations_ibfk_1` FOREIGN KEY (`event_id`) REFERENCES `events` (`event_id`) ON DELETE CASCADE,
  CONSTRAINT `field_coordination_registrations_ibfk_2` FOREIGN KEY (`setting_id`) REFERENCES `field_coordination_settings` (`setting_id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=9 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `field_coordination_settings`
--

DROP TABLE IF EXISTS `field_coordination_settings`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `field_coordination_settings` (
  `setting_id` int(11) NOT NULL AUTO_INCREMENT,
  `academic_year` int(11) NOT NULL,
  `semester` int(11) NOT NULL,
  `registration_start_date` datetime NOT NULL,
  `registration_end_date` datetime NOT NULL,
  `coordination_meeting_date` datetime NOT NULL,
  `borrow_start_date` datetime DEFAULT NULL,
  `borrow_end_date` datetime DEFAULT NULL,
  `borrow_start_time` time DEFAULT NULL,
  `borrow_end_time` time DEFAULT NULL,
  `status` varchar(50) NOT NULL DEFAULT 'active',
  `allow_conflict_during_coordination` tinyint(1) DEFAULT '1',
  `description` text,
  `created_by_student_id` int(11) NOT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`setting_id`),
  UNIQUE KEY `year_semester` (`academic_year`,`semester`)
) ENGINE=InnoDB AUTO_INCREMENT=2 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `notifications`
--

DROP TABLE IF EXISTS `notifications`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `notifications` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `title` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `content` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `type` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT 'info',
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=2 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `officer_nominations`
--

DROP TABLE IF EXISTS `officer_nominations`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `officer_nominations` (
  `nomination_id` int(11) NOT NULL AUTO_INCREMENT,
  `club_id` varchar(10) NOT NULL,
  `nominated_user_id` int(11) NOT NULL,
  `nominator_user_id` int(11) NOT NULL,
  `officer_title` varchar(100) NOT NULL DEFAULT '',
  `status` enum('pending','approved','rejected') DEFAULT 'pending',
  `review_note` text,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `reviewed_at` datetime DEFAULT NULL,
  PRIMARY KEY (`nomination_id`),
  KEY `nominated_user_id` (`nominated_user_id`),
  KEY `nominator_user_id` (`nominator_user_id`),
  CONSTRAINT `officer_nominations_ibfk_1` FOREIGN KEY (`nominated_user_id`) REFERENCES `users` (`user_id`),
  CONSTRAINT `officer_nominations_ibfk_2` FOREIGN KEY (`nominator_user_id`) REFERENCES `users` (`user_id`)
) ENGINE=InnoDB AUTO_INCREMENT=4 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `password_resets`
--

DROP TABLE IF EXISTS `password_resets`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `password_resets` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `email` varchar(100) NOT NULL,
  `token` varchar(64) NOT NULL,
  `expires_at` datetime NOT NULL,
  `used` tinyint(1) DEFAULT '0',
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_token` (`token`),
  KEY `idx_email` (`email`)
) ENGINE=InnoDB AUTO_INCREMENT=19 DEFAULT CHARSET=utf8;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `reservations`
--

DROP TABLE IF EXISTS `reservations`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `reservations` (
  `reservation_id` int(11) NOT NULL AUTO_INCREMENT,
  `event_id` int(11) DEFAULT NULL,
  `space_id` int(11) DEFAULT NULL,
  `start_time` datetime DEFAULT NULL,
  `end_time` datetime DEFAULT NULL,
  `is_field_coordination_preliminary` tinyint(1) DEFAULT '0',
  `conflict_warnings` text,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`reservation_id`),
  KEY `event_id` (`event_id`),
  KEY `space_id` (`space_id`),
  CONSTRAINT `reservations_ibfk_1` FOREIGN KEY (`event_id`) REFERENCES `events` (`event_id`),
  CONSTRAINT `reservations_ibfk_2` FOREIGN KEY (`space_id`) REFERENCES `spaces` (`space_id`)
) ENGINE=InnoDB AUTO_INCREMENT=52 DEFAULT CHARSET=utf8;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `spaces`
--

DROP TABLE IF EXISTS `spaces`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `spaces` (
  `space_id` int(11) NOT NULL AUTO_INCREMENT,
  `space_name` varchar(100) NOT NULL,
  `capacity` int(11) DEFAULT NULL,
  `space_status` enum('available','maintenance') CHARACTER SET utf8 COLLATE utf8_general_ci DEFAULT 'available',
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`space_id`)
) ENGINE=InnoDB AUTO_INCREMENT=21 DEFAULT CHARSET=utf8;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `special_dates`
--

DROP TABLE IF EXISTS `special_dates`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `special_dates` (
  `id` bigint(20) NOT NULL AUTO_INCREMENT COMMENT '主鍵，唯一識別碼',
  `title` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL COMMENT '特殊日期名稱/原因（如：場地維修、國定假日）',
  `target_type` enum('ALL','VENUE','EQUIPMENT') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'ALL' COMMENT '適用對象：ALL(全部), VENUE(僅場地), EQUIPMENT(僅器材)',
  `date_type` enum('SINGLE','RANGE') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'SINGLE' COMMENT '日期模式：SINGLE(單一日期), RANGE(範圍日期)',
  `start_date` date NOT NULL COMMENT '開始日期',
  `end_date` date DEFAULT NULL COMMENT '結束日期（範圍日期時使用）',
  `is_full_day` tinyint(1) NOT NULL DEFAULT '1' COMMENT '是否為全天（1:是, 0:否）',
  `start_time` time DEFAULT NULL COMMENT '開始時間（非全天時必填）',
  `end_time` time DEFAULT NULL COMMENT '結束時間（非全天時必填）',
  `status_type` enum('UNAVAILABLE','CHARGEABLE') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'UNAVAILABLE' COMMENT '狀態：UNAVAILABLE(不可借用), CHARGEABLE(可借用但需收費)',
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT '建立時間',
  `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP COMMENT '最後更新時間',
  PRIMARY KEY (`id`),
  KEY `idx_date_range` (`start_date`,`end_date`),
  KEY `idx_status_type` (`status_type`)
) ENGINE=InnoDB AUTO_INCREMENT=42 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='課指組特殊日期時段設定表';
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `users`
--

DROP TABLE IF EXISTS `users`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `users` (
  `user_id` int(11) NOT NULL AUTO_INCREMENT,
  `name` varchar(50) NOT NULL,
  `student_id` int(10) NOT NULL,
  `email` varchar(100) DEFAULT NULL,
  `phone` varchar(20) DEFAULT NULL,
  `password` varchar(255) NOT NULL,
  `role` enum('student','admin') DEFAULT 'student',
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `username` varchar(100) DEFAULT NULL,
  PRIMARY KEY (`user_id`),
  UNIQUE KEY `email` (`email`),
  UNIQUE KEY `username` (`username`)
) ENGINE=InnoDB AUTO_INCREMENT=12 DEFAULT CHARSET=utf8;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40103 SET TIME_ZONE=@OLD_TIME_ZONE */;

/*!40101 SET SQL_MODE=@OLD_SQL_MODE */;
/*!40014 SET FOREIGN_KEY_CHECKS=@OLD_FOREIGN_KEY_CHECKS */;
/*!40014 SET UNIQUE_CHECKS=@OLD_UNIQUE_CHECKS */;
/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
/*!40111 SET SQL_NOTES=@OLD_SQL_NOTES */;

-- Dump completed on 2026-06-13 16:56:48
