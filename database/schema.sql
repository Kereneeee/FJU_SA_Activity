-- 為 users 表添加 username 欄位（如果還沒有的話）
ALTER TABLE `users` ADD COLUMN `username` VARCHAR(100) UNIQUE NULL DEFAULT NULL;

-- 插入管理員帳號
INSERT IGNORE INTO `users` (`name`, `email`, `password`, `role`, `username`) VALUES
('系統管理員', 'admin', 'admin123', 'admin', 'admin');

-- 插入測試資料
INSERT IGNORE INTO `users` (`name`, `email`, `password`, `role`, `username`) VALUES
('廖同學', '410123456', '1234', 'student', 'student')

-- 插入測試場地
INSERT IGNORE INTO `spaces` (`space_id`, `space_name`, `capacity`, `status`) VALUES
(1, '活動中心大廳', 200, 'available'),
(2, '會議室 A', 50, 'available'),
(3, '戶外操場', 500, 'available');

-- 插入測試器材
INSERT IGNORE INTO `equipment` (`equipment_id`, `name`, `total_quantity`, `available_quantity`, `status`) VALUES
(1, '投影機', 5, 3, 'available'),
(2, '音響設備', 3, 2, 'available'),
(3, '折疊椅', 100, 80, 'available'),
(4, '長桌', 20, 15, 'available');

-- 插入測試活動（待審核狀態）
INSERT IGNORE INTO `events` (`event_id`, `user_id`, `event_name`, `club_name`, `description`, `start_time`, `end_time`, `status`, `review_note`) VALUES
(1, 3, '吉他社成果發表會', '吉他社', '年度吉他社成果發表演奏會', '2026-05-10 18:00:00', '2026-05-10 20:30:00', 'pending', NULL),
(2, 4, '創意手工坊工作坊', '美勞社', '教授各種手工藝製作技巧', '2026-05-15 14:00:00', '2026-05-15 17:00:00', 'pending', NULL),
(3, 3, '英文讀書會', '英文讀書會', '分享和討論英文文學作品', '2026-05-18 15:00:00', '2026-05-18 17:00:00', 'pending', NULL);

-- 插入測試預約
INSERT IGNORE INTO `reservations` (`reservation_id`, `event_id`, `space_id`, `start_time`, `end_time`) VALUES
(1, 1, 1, '2026-05-10 17:00:00', '2026-05-10 21:00:00'),
(2, 2, 2, '2026-05-15 13:30:00', '2026-05-15 17:30:00'),
(3, 3, 2, '2026-05-18 14:30:00', '2026-05-18 17:30:00');

-- 插入測試器材借用
INSERT IGNORE INTO `equipment_borrow` (`borrow_id`, `event_id`, `equipment_id`, `quantity`) VALUES
(1, 1, 1, 2),
(2, 1, 2, 1),
(3, 2, 3, 30),
(4, 2, 4, 5),
(5, 3, 3, 20);