-- Migration: officer_nominations 幹部提名資料表
-- 社長提交的幹部提名，待管理者審核後生效

CREATE TABLE IF NOT EXISTS `officer_nominations` (
  `nomination_id`     int(11)      NOT NULL AUTO_INCREMENT,
  `club_id`           varchar(10)  NOT NULL,
  `nominated_user_id` int(11)      NOT NULL,
  `nominator_user_id` int(11)      NOT NULL,
  `officer_title`     varchar(50)  NOT NULL DEFAULT '',
  `status`            enum('pending','approved','rejected') NOT NULL DEFAULT 'pending',
  `review_note`       text         DEFAULT NULL,
  `created_at`        timestamp    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `reviewed_at`       datetime     DEFAULT NULL,
  PRIMARY KEY (`nomination_id`),
  KEY `idx_club_status`      (`club_id`, `status`),
  KEY `nominated_user_id`    (`nominated_user_id`),
  KEY `nominator_user_id`    (`nominator_user_id`),
  CONSTRAINT `officer_nominations_ibfk_1` FOREIGN KEY (`club_id`)           REFERENCES `clubs` (`club_id`),
  CONSTRAINT `officer_nominations_ibfk_2` FOREIGN KEY (`nominated_user_id`) REFERENCES `users` (`user_id`),
  CONSTRAINT `officer_nominations_ibfk_3` FOREIGN KEY (`nominator_user_id`) REFERENCES `users` (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
