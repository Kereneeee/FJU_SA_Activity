-- 忘記密碼重設 token 資料表
CREATE TABLE IF NOT EXISTS `password_resets` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `email` varchar(100) NOT NULL COMMENT '對應 users.email（登入帳號）',
  `token` varchar(64) NOT NULL COMMENT '安全隨機 token',
  `expires_at` datetime NOT NULL COMMENT 'Token 過期時間（1小時後）',
  `used` tinyint(1) DEFAULT 0 COMMENT '0=未使用, 1=已使用',
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_token` (`token`),
  KEY `idx_email` (`email`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8 COMMENT='密碼重設 token 紀錄';
