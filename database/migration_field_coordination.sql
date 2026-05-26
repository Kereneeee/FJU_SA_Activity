-- 場地協調系統遷移腳本
-- 此腳本新增場協登記管理功能

-- 1. 建立場協設定表 - 管理員控制場協開放時間
CREATE TABLE IF NOT EXISTS `field_coordination_settings` (
  `setting_id` INT AUTO_INCREMENT PRIMARY KEY,
  `academic_year` INT NOT NULL COMMENT '民國學年（如：114）',
  `semester` INT NOT NULL COMMENT '學期（1=上學期 8/1-1/31 / 2=下學期 2/1-7/31）',
  `registration_start_date` DATETIME NOT NULL COMMENT '場協登記開始時間',
  `registration_end_date` DATETIME NOT NULL COMMENT '場協登記結束時間',
  `coordination_meeting_date` DATETIME NOT NULL COMMENT '場地協調大會日期',
  `borrow_start_date` DATETIME NULL COMMENT '場協可借用起始日期',
  `borrow_end_date` DATETIME NULL COMMENT '場協可借用結束日期',
  `borrow_start_time` TIME NULL COMMENT '場協可借用最早時段',
  `borrow_end_time` TIME NULL COMMENT '場協可借用最晚時段',
  `status` VARCHAR(50) NOT NULL DEFAULT 'active' COMMENT '狀態：active(啟用) / inactive(禁用)',
  `allow_conflict_during_coordination` TINYINT(1) DEFAULT 1 COMMENT '場協期間是否允許衝突提示',
  `description` TEXT NULL COMMENT '說明',
  `created_by_student_id` INT NOT NULL COMMENT '建立人（管理員 student_id）',
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY `year_semester` (`academic_year`, `semester`),
  INDEX `status_idx` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 2. 建立場協登記紀錄表 - 存儲社團的場協申請
CREATE TABLE IF NOT EXISTS `field_coordination_registrations` (
  `registration_id` INT AUTO_INCREMENT PRIMARY KEY,
  `setting_id` INT NOT NULL COMMENT '關聯到field_coordination_settings',
  `event_id` INT NOT NULL COMMENT '關聯到events表',
  `student_id` INT NOT NULL COMMENT '社團社長/負責人 student_id',
  `club_id` INT NOT NULL COMMENT '社團 club_id',
  `club_name` VARCHAR(255) NOT NULL COMMENT '社團名稱',
  `is_approved` TINYINT(1) DEFAULT NULL COMMENT '是否在協調大會後被批准（NULL=待定, 1=已批准, 0=被拒絕）',
  `approval_note` TEXT NULL COMMENT '批准/拒絕備註',
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  KEY `setting_id_idx` (`setting_id`),
  KEY `student_id_idx` (`student_id`),
  KEY `club_id_idx` (`club_id`),
  KEY `event_id_idx` (`event_id`),
  FOREIGN KEY (`event_id`) REFERENCES `events` (`event_id`) ON DELETE CASCADE,
  FOREIGN KEY (`setting_id`) REFERENCES `field_coordination_settings` (`setting_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 3. 为 events 表添加字段
ALTER TABLE `events` ADD COLUMN `is_field_coordination` TINYINT(1) DEFAULT 0 COMMENT '是否為場協登記' AFTER `status`;
ALTER TABLE `events` ADD COLUMN `field_coordination_setting_id` INT NULL COMMENT '關聯的field_coordination_settings.setting_id' AFTER `is_field_coordination`;
ALTER TABLE `events` ADD INDEX `field_coord_idx` (`is_field_coordination`);
ALTER TABLE `events` ADD INDEX `field_coord_setting_idx` (`field_coordination_setting_id`);

-- 4. 为 reservations 表添加字段
ALTER TABLE `reservations` ADD COLUMN `is_field_coordination_preliminary` TINYINT(1) DEFAULT 0 COMMENT '是否為場協期間的預訂（允許衝突提示）' AFTER `end_time`;
ALTER TABLE `reservations` ADD COLUMN `conflict_warnings` TEXT NULL COMMENT '衝突警告JSON' AFTER `is_field_coordination_preliminary`;
ALTER TABLE `reservations` ADD INDEX `field_coord_res_idx` (`is_field_coordination_preliminary`);

-- 5. 创建冲突记录表
CREATE TABLE IF NOT EXISTS `coordination_conflicts` (
  `conflict_id` INT AUTO_INCREMENT PRIMARY KEY,
  `setting_id` INT NOT NULL COMMENT '關聯field_coordination_settings',
  `registration_id_1` INT NOT NULL COMMENT '第一個field_coordination_registrations id',
  `registration_id_2` INT NOT NULL COMMENT '第二個field_coordination_registrations id',
  `space_id` INT NOT NULL COMMENT '衝突場地id',
  `conflict_start_time` DATETIME NOT NULL COMMENT '衝突開始時間',
  `conflict_end_time` DATETIME NOT NULL COMMENT '衝突結束時間',
  `status` VARCHAR(50) DEFAULT 'unresolved' COMMENT '狀態：unresolved(未解決) / resolved(已解決)',
  `resolution_note` TEXT NULL COMMENT '解決方案備註',
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (`setting_id`) REFERENCES `field_coordination_settings` (`setting_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 6. 插入測試數據 - 114-2 寒假場協設定
INSERT IGNORE INTO `field_coordination_settings` 
(`academic_year`, `semester`, `registration_start_date`, `registration_end_date`, `coordination_meeting_date`, `borrow_start_date`, `borrow_end_date`, `borrow_start_time`, `borrow_end_time`, `status`, `allow_conflict_during_coordination`, `description`, `created_by_student_id`) 
VALUES 
(114, 2, '2026-01-20 00:00:00', '2026-02-22 23:59:00', '2026-03-03 12:30:00', '2026-03-09 00:00:00', '2026-06-30 23:59:59', '08:30:00', '21:30:00', 'active', 1, '114-2 寒假場協登記 1/20-2/22，協調大會 3/3，借用 3/9-6/30 08:30-21:30', 1);
