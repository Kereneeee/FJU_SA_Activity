-- phpMyAdmin SQL Dump
-- version 4.9.1
-- https://www.phpmyadmin.net/
--
-- 主機： localhost
-- 產生時間： 
-- 伺服器版本： 8.0.17
-- PHP 版本： 7.3.10

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
SET AUTOCOMMIT = 0;
START TRANSACTION;
SET time_zone = "+00:00";


/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;

--
-- 資料庫： `fjusa`
--

-- --------------------------------------------------------

--
-- 資料表結構 `clubs`
--

CREATE TABLE `clubs` (
  `club_id` varchar(10) NOT NULL,
  `club_name` varchar(100) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8;

--
-- 傾印資料表的資料 `clubs`
--

INSERT INTO `clubs` (`club_id`, `club_name`) VALUES
('A049', '健言社'),
('A050', '大千社'),
('A051', '天文社'),
('A053', '中華醫藥研習社'),
('A054', '國際經濟商管學生會'),
('A056', '占星塔羅社'),
('A058', '信望愛社'),
('A099', '淨仁社'),
('A140', '學園團契社'),
('A141', '禪學社'),
('A142', '聖經研究社'),
('A159', '教育學程學會'),
('A161', '福智青年社'),
('A174', '性別研究社'),
('A191', '永續影響力大使社'),
('A192', '創新創業社'),
('A196', '租稅研究社'),
('A229', '光鹽社'),
('A401', '金融投資研究社'),
('B042', '僑生聯誼會'),
('B043', '高中校友聯合總會'),
('B060', '轉學生聯誼會'),
('B076', '野營社'),
('B080', '魔術社'),
('B082', '棋藝社'),
('B083', '飲料調製社'),
('B129', '努瑪社'),
('B163', '國際菁英學生會'),
('B168', '桌上遊戲社'),
('B184', '電子競技社'),
('B185', '二輪社'),
('B193', '咖啡研究社'),
('B198', '韓國流行文化研究社'),
('C097', '同舟共濟服務社'),
('C098', '醒新愛愛服務社'),
('C100', '急救康輔社'),
('C101', '崇德志工服務社'),
('C116', '基層文化服務社'),
('C126', '慈濟青年社'),
('C148', '繪本服務學習社'),
('C189', '勵德青少年服務社'),
('D075', '登山社'),
('D084', '國術社'),
('D086', '跆拳道社'),
('D087', '柔道社'),
('D088', '劍道社'),
('D089', '擊劍社'),
('D090', '羽球社'),
('D091', '桌球社'),
('D092', '網球社'),
('D093', '射箭社'),
('D118', '同心救生社'),
('D131', '空手道社'),
('D136', '黑輪社'),
('D166', '合氣道社'),
('D172', '歐洲劍術社'),
('D188', '撞球社'),
('D190', 'Kali武術社'),
('D199', '自由潛水社'),
('D402', '跑步社'),
('D403', '袋棍球社'),
('E064', '書法社'),
('E066', '攝影社'),
('E067', '熱舞社'),
('E070', '戲劇社'),
('E072', '國際標準舞蹈社'),
('E081', '廣播演藝社'),
('E132', '動漫電玩研習社'),
('E157', '影片創作社'),
('E171', '弓道社'),
('E178', '光火藝術社'),
('E179', '民俗體育社'),
('E194', '生活花藝設計社'),
('F061', '國樂社'),
('F068', '管弦樂社'),
('F071', '民謠吉他社'),
('F074', '搖滾音樂研究社'),
('F123', '鋼琴社'),
('F124', '數位音樂創作研習社'),
('F167', '烏克麗麗社'),
('F186', '嘻哈文化社'),
('F223', '爵士鋼琴社');

-- --------------------------------------------------------

--
-- 資料表結構 `club_members`
--

CREATE TABLE `club_members` (
  `membership_id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `club_id` varchar(10) NOT NULL,
  `is_officer` tinyint(1) DEFAULT '0',
  `officer_title` varchar(50) DEFAULT NULL,
  `join_date` date DEFAULT NULL,
  `officer_confirmation_date` date DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8;

--
-- 傾印資料表的資料 `club_members`
--

INSERT INTO `club_members` (`membership_id`, `user_id`, `club_id`, `is_officer`, `officer_title`, `join_date`, `officer_confirmation_date`) VALUES
(1, 3, 'F071', 1, '器材幹部', '2025-01-01', '2025-05-01'),
(2, 3, 'C148', 1, '教學幹部', '2025-01-01', '2025-05-01');

-- --------------------------------------------------------

--
-- 資料表結構 `equipment`
--

CREATE TABLE `equipment` (
  `equipment_id` int(11) NOT NULL,
  `code` varchar(10) DEFAULT NULL,
  `name` varchar(100) NOT NULL,
  `description` text,
  `borrowing_limit` int(11) DEFAULT NULL,
  `total_quantity` int(11) NOT NULL,
  `equipment_status` enum('available','maintenance') DEFAULT 'available',
  `status` int(11) NOT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8;

--
-- 傾印資料表的資料 `equipment`
--

INSERT INTO `equipment` (`equipment_id`, `code`, `name`, `description`, `borrowing_limit`, `total_quantity`, `equipment_status`, `status`, `created_at`) VALUES
(1, 'A1', '喊話器 (充電電池*1)', '內含充電電池', 1, 12, 'available', 0, '2026-05-02 01:35:10'),
(2, 'A2', '有線麥克風', NULL, 2, 9, 'available', 0, '2026-05-02 01:35:10'),
(3, 'A3', '短麥克風架', NULL, 1, 2, 'available', 0, '2026-05-02 01:35:10'),
(4, 'A4', '長麥克風架', NULL, 2, 10, 'available', 0, '2026-05-02 01:35:10'),
(5, 'A5', 'MIPRO擴音器MA-100SB (無線麥克風*1)', '一支無線麥克風、充電線、音源線。※不要用到沒電', 1, 10, 'available', 0, '2026-05-02 01:35:10'),
(6, 'A6', 'MIPRO擴音器MA-708 (無線麥克風*2)', '兩支無線麥克風、充電線、音源線。※不要用到沒電', 1, 10, 'available', 0, '2026-05-02 01:35:10'),
(7, 'A7', 'YAMAHA擴音器 600BT (喇叭*2、無線麥克風*2)', '兩台喇叭、兩支無線麥克風、電源線、音源線、兩條喇叭線', 1, 1, 'available', 0, '2026-05-02 01:35:10'),
(8, 'A8', '金嗓卡拉ok (無線麥克風*2)', '一台主機、腳架、兩支無線麥克風、電源線、HDMI線、點歌本、遙控器', 1, 1, 'available', 0, '2026-05-02 01:35:10'),
(9, 'A9', '戶外高級音響MA-808 (喇叭*2、無線麥克風*4)', '兩台喇叭、兩個腳架、四支無線麥克風、充電線、音源線、喇叭線。※不要用到沒電', 1, 1, 'available', 0, '2026-05-02 01:35:10'),
(10, 'A10', '電鋼琴', '腳架、踏板、譜架、電源線、訊號線', 1, 1, 'available', 0, '2026-05-02 01:35:10'),
(11, 'B1', '投影布幕 (長150*寬210*高240cm)', '使用時記得下面「底板」要轉開。在戶外時請人在後面扶著。※收放時，慢收慢放', 1, 3, 'available', 0, '2026-05-02 01:35:10'),
(12, 'B2', '單槍投影機', '內附電源線和HDMI線。※開關機要等一段時間', 1, 5, 'available', 0, '2026-05-02 01:35:10'),
(13, 'B3', '數位相機', '內附兩顆電池和充電器。※記憶卡需自行準備', 1, 3, 'available', 0, '2026-05-02 01:35:10'),
(14, 'B4', 'DV攝影機', '內附兩顆電池和充電器。※記憶卡須自行準備', 1, 3, 'available', 0, '2026-05-02 01:35:10'),
(15, 'B5', 'DV腳架', NULL, 1, 6, 'available', 0, '2026-05-02 01:35:10'),
(16, 'C1', 'A字看板 (木/鋁製)', NULL, 2, 29, 'available', 0, '2026-05-02 01:35:10'),
(17, 'C2', '珍珠椅', NULL, 40, 200, 'available', 0, '2026-05-02 01:35:10'),
(18, 'C3', '折疊鐵椅', NULL, 10, 27, 'available', 0, '2026-05-02 01:35:10'),
(19, 'C4', '折疊長桌 (長180*寬70*高75cm)', NULL, 4, 40, 'available', 0, '2026-05-02 01:35:10'),
(20, 'C5', '司令帳(沙袋*2) (開-長300*寬300*高345cm)', '搭配兩個沙袋', 4, 40, 'available', 0, '2026-05-02 01:35:10'),
(21, 'C6', 'TRUSS 帆布立架組 (300*200cm長方形)', '附工具箱、CUBE轉接頭、底板', 1, 2, 'available', 0, '2026-05-02 01:35:10'),
(22, 'C7', '交通警示錐', NULL, 20, 85, 'available', 0, '2026-05-02 01:35:10'),
(23, 'C8', '交通警示橫桿 (長200cm)', NULL, 15, 80, 'available', 0, '2026-05-02 01:35:10'),
(24, 'C9', '插旗組 (旗桿、旗帽)', '旗桿、旗帽', 20, 130, 'available', 0, '2026-05-02 01:35:10'),
(25, 'C10', '旗座', NULL, 10, 25, 'available', 0, '2026-05-02 01:35:10'),
(26, 'D1', '地燈 (黃光/白光)', NULL, 2, 16, 'available', 0, '2026-05-02 01:35:10'),
(27, 'D2', '地燈架', NULL, 2, 10, 'available', 0, '2026-05-02 01:35:10'),
(28, 'D3', '七彩旋轉燈', NULL, 1, 3, 'available', 0, '2026-05-02 01:35:10'),
(29, 'D4', '追蹤燈組', '內附延長線', 1, 1, 'available', 0, '2026-05-02 01:35:10'),
(30, 'E1', '延長線捲', NULL, 2, 10, 'available', 0, '2026-05-02 01:35:10'),
(31, 'E2', '無線電對講機', '內附天線、充電座、充電線', 4, 16, 'available', 0, '2026-05-02 01:35:10'),
(32, 'E3', '茶桶 40L', NULL, 1, 5, 'available', 0, '2026-05-02 01:35:10'),
(33, 'E4', '睡袋', NULL, NULL, 84, 'available', 0, '2026-05-02 01:35:10');

-- --------------------------------------------------------

--
-- 資料表結構 `equipment_borrow`
--

CREATE TABLE `equipment_borrow` (
  `borrow_id` int(11) NOT NULL,
  `event_id` int(11) DEFAULT NULL,
  `equipment_id` int(11) DEFAULT NULL,
  `quantity` int(11) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8;

--
-- 傾印資料表的資料 `equipment_borrow`
--

INSERT INTO `equipment_borrow` (`borrow_id`, `event_id`, `equipment_id`, `quantity`) VALUES
(12, 11, 10, 1),
(13, 10, 10, 1);

-- --------------------------------------------------------

--
-- 資料表結構 `events`
--

CREATE TABLE `events` (
  `event_id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `event_name` varchar(255) NOT NULL,
  `club_name` varchar(100) NOT NULL,
  `description` text,
  `document_path` varchar(255) DEFAULT NULL,
  `venue_doc_path` varchar(255) DEFAULT NULL,
  `equipment_doc_path` varchar(255) DEFAULT NULL,
  `start_time` datetime NOT NULL,
  `end_time` datetime NOT NULL,
  `status` enum('pending','approved','rejected','cancelled') DEFAULT 'pending',
  `review_note` text,
  `original_event_id` int(11) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

--
-- 傾印資料表的資料 `events`
--

INSERT INTO `events` (`event_id`, `user_id`, `event_name`, `club_name`, `description`, `document_path`, `venue_doc_path`, `equipment_doc_path`, `start_time`, `end_time`, `status`, `review_note`, `created_at`) VALUES
(9, 3, '社課', '吉他社', '1234567890', NULL, NULL, NULL, '2026-05-21 10:10:00', '2026-05-21 12:00:00', 'pending', '', '2026-05-15 01:48:02'),
(10, 3, '開會', '舞蹈社', '1234567890', 'event_1779072450_6a0a7dc2b925a.pdf', 'venue_1779072450_6a0a7dc2b9436.pdf', 'equip_1779460434_6a106952a0470.pdf', '2026-06-05 10:00:00', '2026-06-05 12:00:00', 'pending', '', '2026-05-18 02:47:30'),
(11, 3, '', '', '追加申請器材（原活動ID：10）', NULL, NULL, 'equip_1779459273_6a1064c90517d.pdf', '2026-06-05 10:00:00', '2026-06-05 12:00:00', 'cancelled', NULL, '2026-05-22 14:14:33');

-- --------------------------------------------------------

--
-- 資料表結構 `field_coordination`
--

CREATE TABLE `field_coordination` (
  `coord_id` int(11) NOT NULL,
  `user_id` int(11) DEFAULT NULL,
  `event_id` int(11) DEFAULT NULL,
  `note` text,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8;

-- --------------------------------------------------------

--
-- 資料表結構 `reservations`
--

CREATE TABLE `reservations` (
  `reservation_id` int(11) NOT NULL,
  `event_id` int(11) DEFAULT NULL,
  `space_id` int(11) DEFAULT NULL,
  `start_time` datetime DEFAULT NULL,
  `end_time` datetime DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8;

--
-- 傾印資料表的資料 `reservations`
--

INSERT INTO `reservations` (`reservation_id`, `event_id`, `space_id`, `start_time`, `end_time`, `created_at`) VALUES
(14, 9, 4, '2026-05-21 10:10:00', '2026-05-21 12:00:00', '2026-05-15 01:48:02'),
(15, 10, 15, '2026-06-05 10:00:00', '2026-06-05 12:00:00', '2026-05-18 02:47:30');

-- --------------------------------------------------------

--
-- 資料表結構 `spaces`
--

CREATE TABLE `spaces` (
  `space_id` int(11) NOT NULL,
  `space_name` varchar(100) NOT NULL,
  `capacity` int(11) DEFAULT NULL,
  `space_status` enum('available','maintenance') CHARACTER SET utf8 COLLATE utf8_general_ci DEFAULT 'available',
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `status` int(11) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8;

--
-- 傾印資料表的資料 `spaces`
--

INSERT INTO `spaces` (`space_id`, `space_name`, `capacity`, `space_status`, `created_at`, `status`) VALUES
(1, 'A焯炤館－地下演講廳', 0, 'available', '2026-05-11 16:16:50', 0),
(2, 'A焯炤館－旋律廣場－冷氣損壞', 0, 'available', '2026-05-11 16:16:50', 0),
(3, 'A焯炤館－夢幻電影院', 0, 'available', '2026-05-11 16:16:50', 0),
(4, 'A焯炤館－鏡鏡屋', 0, 'available', '2026-05-11 16:16:50', 0),
(5, 'B進修部地下室教室（一）ES002', 0, 'available', '2026-05-11 16:16:50', 0),
(6, 'B進修部地下室教室（二）ES003', 0, 'available', '2026-05-11 16:16:50', 0),
(7, 'B進修部地下室教室（三）ES004', 0, 'available', '2026-05-11 16:16:50', 0),
(8, 'B進修部地下室教室（四）ES005', 0, 'available', '2026-05-11 16:16:50', 0),
(9, 'B進修部地下室教室（五）ES006', 0, 'available', '2026-05-11 16:16:50', 0),
(10, 'B進修部地下室演講廳', 0, 'available', '2026-05-11 16:16:50', 0),
(11, 'C仁愛學苑－一樓半空間', 0, 'available', '2026-05-11 16:16:50', 0),
(12, 'C仁愛學苑－二樓半空間', 0, 'available', '2026-05-11 16:16:50', 0),
(13, 'C仁愛學苑－三樓半空間', 0, 'available', '2026-05-11 16:16:50', 0),
(14, 'D文開地下舞蹈空間中間', 0, 'available', '2026-05-11 16:16:50', 0),
(15, 'D文開地下舞蹈空間右側（軟墊）', 0, 'available', '2026-05-11 16:16:50', 0),
(16, 'D文開地下舞蹈空間左側', 0, 'available', '2026-05-11 16:16:50', 0),
(17, 'D真善美聖廣場', 0, 'available', '2026-05-11 16:16:50', 0),
(18, 'E課指組204會議室', 0, 'available', '2026-05-11 16:16:50', 0),
(19, 'H校門口左側（AB）', 0, 'available', '2026-05-11 16:16:50', 0),
(20, 'H校門口左側（CD）', 0, 'available', '2026-05-11 16:16:50', 0);

-- --------------------------------------------------------

--
-- 資料表結構 `users`
--

CREATE TABLE `users` (
  `user_id` int(11) NOT NULL,
  `name` varchar(50) NOT NULL,
  `student_id` int(10) NOT NULL,
  `email` varchar(100) DEFAULT NULL,
  `phone` varchar(20) DEFAULT NULL,
  `password` varchar(255) NOT NULL,
  `role` enum('student','admin') DEFAULT 'student',
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `username` varchar(100) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8;

--
-- 傾印資料表的資料 `users`
--

INSERT INTO `users` (`user_id`, `name`, `student_id`, `email`, `phone`, `password`, `role`, `created_at`, `username`) VALUES
(3, '廖同學', 410123456, '410123456', '0912345678', '1234', 'student', '2026-04-22 09:29:48', NULL),
(4, '管理員', 0, 'admin@test.com', NULL, '12345678', 'admin', '2026-04-27 17:44:21', 'admin'),
(5, '學生', 0, 'student@test.com', NULL, '12345678', 'student', '2026-04-27 17:44:21', 'student'),
(6, '李同學', 0, 'lee@test.com', NULL, '12345678', 'student', '2026-04-27 17:44:21', 'lee'),
(7, '王同學', 0, 'wang@test.com', NULL, '12345678', 'student', '2026-04-27 17:44:21', 'wang');

--
-- 已傾印資料表的索引
--

--
-- 資料表索引 `clubs`
--
ALTER TABLE `clubs`
  ADD PRIMARY KEY (`club_id`);

--
-- 資料表索引 `club_members`
--
ALTER TABLE `club_members`
  ADD PRIMARY KEY (`membership_id`),
  ADD KEY `user_id` (`user_id`),
  ADD KEY `club_id` (`club_id`);

--
-- 資料表索引 `equipment`
--
ALTER TABLE `equipment`
  ADD PRIMARY KEY (`equipment_id`);

--
-- 資料表索引 `equipment_borrow`
--
ALTER TABLE `equipment_borrow`
  ADD PRIMARY KEY (`borrow_id`),
  ADD KEY `event_id` (`event_id`),
  ADD KEY `equipment_id` (`equipment_id`);

--
-- 資料表索引 `events`
--
ALTER TABLE `events`
  ADD PRIMARY KEY (`event_id`),
  ADD KEY `user_id` (`user_id`);

--
-- 資料表索引 `field_coordination`
--
ALTER TABLE `field_coordination`
  ADD PRIMARY KEY (`coord_id`),
  ADD KEY `user_id` (`user_id`),
  ADD KEY `event_id` (`event_id`);

--
-- 資料表索引 `reservations`
--
ALTER TABLE `reservations`
  ADD PRIMARY KEY (`reservation_id`),
  ADD KEY `event_id` (`event_id`),
  ADD KEY `space_id` (`space_id`);

--
-- 資料表索引 `spaces`
--
ALTER TABLE `spaces`
  ADD PRIMARY KEY (`space_id`);

--
-- 資料表索引 `users`
--
ALTER TABLE `users`
  ADD PRIMARY KEY (`user_id`),
  ADD UNIQUE KEY `email` (`email`),
  ADD UNIQUE KEY `username` (`username`);

--
-- 在傾印的資料表使用自動遞增(AUTO_INCREMENT)
--

--
-- 使用資料表自動遞增(AUTO_INCREMENT) `club_members`
--
ALTER TABLE `club_members`
  MODIFY `membership_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=3;

--
-- 使用資料表自動遞增(AUTO_INCREMENT) `equipment`
--
ALTER TABLE `equipment`
  MODIFY `equipment_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=34;

--
-- 使用資料表自動遞增(AUTO_INCREMENT) `equipment_borrow`
--
ALTER TABLE `equipment_borrow`
  MODIFY `borrow_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=14;

--
-- 使用資料表自動遞增(AUTO_INCREMENT) `events`
--
ALTER TABLE `events`
  MODIFY `event_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=12;

--
-- 使用資料表自動遞增(AUTO_INCREMENT) `field_coordination`
--
ALTER TABLE `field_coordination`
  MODIFY `coord_id` int(11) NOT NULL AUTO_INCREMENT;

--
-- 使用資料表自動遞增(AUTO_INCREMENT) `reservations`
--
ALTER TABLE `reservations`
  MODIFY `reservation_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=16;

--
-- 使用資料表自動遞增(AUTO_INCREMENT) `spaces`
--
ALTER TABLE `spaces`
  MODIFY `space_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=21;

--
-- 使用資料表自動遞增(AUTO_INCREMENT) `users`
--
ALTER TABLE `users`
  MODIFY `user_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=8;

--
-- 已傾印資料表的限制式
--

--
-- 資料表的限制式 `club_members`
--
ALTER TABLE `club_members`
  ADD CONSTRAINT `club_members_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`user_id`),
  ADD CONSTRAINT `club_members_ibfk_2` FOREIGN KEY (`club_id`) REFERENCES `clubs` (`club_id`);

--
-- 資料表的限制式 `equipment_borrow`
--
ALTER TABLE `equipment_borrow`
  ADD CONSTRAINT `equipment_borrow_ibfk_1` FOREIGN KEY (`event_id`) REFERENCES `events` (`event_id`),
  ADD CONSTRAINT `equipment_borrow_ibfk_2` FOREIGN KEY (`equipment_id`) REFERENCES `equipment` (`equipment_id`);

--
-- 資料表的限制式 `events`
--
ALTER TABLE `events`
  ADD CONSTRAINT `events_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`user_id`);

--
-- 資料表的限制式 `field_coordination`
--
ALTER TABLE `field_coordination`
  ADD CONSTRAINT `field_coordination_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`user_id`),
  ADD CONSTRAINT `field_coordination_ibfk_2` FOREIGN KEY (`event_id`) REFERENCES `events` (`event_id`);

--
-- 資料表的限制式 `reservations`
--
ALTER TABLE `reservations`
  ADD CONSTRAINT `reservations_ibfk_1` FOREIGN KEY (`event_id`) REFERENCES `events` (`event_id`),
  ADD CONSTRAINT `reservations_ibfk_2` FOREIGN KEY (`space_id`) REFERENCES `spaces` (`space_id`);
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;


-- 資料表結構 `notifications`
--

CREATE TABLE `notifications` (
  `id` int(11) NOT NULL,
  `title` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `content` text COLLATE utf8mb4_unicode_ci NOT NULL,
  `type` varchar(50) COLLATE utf8mb4_unicode_ci DEFAULT 'info',
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- 傾印資料表的資料 `notifications`
--

INSERT INTO `notifications` (`id`, `title`, `content`, `type`, `created_at`) VALUES
(1, '系統維護提醒', '今晚 22:00-24:00 進行社團系統維護與功能更新，期間可能短暫無法連線。', 'update', '2026-05-23 15:14:21');

--
-- 已傾印資料表的索引
--

--
-- 資料表索引 `notifications`
--
ALTER TABLE `notifications`
  ADD PRIMARY KEY (`id`);

--
-- 在傾印的資料表使用自動遞增(AUTO_INCREMENT)
--

--
-- 使用資料表自動遞增(AUTO_INCREMENT) `notifications`
--
ALTER TABLE `notifications`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;
COMMIT;