-- 數據庫遷移：分別追蹤活動/場地和器材的審核狀態

-- 1. 為 equipment_borrow 表添加 status 字段（如果不存在）
ALTER TABLE `equipment_borrow` ADD COLUMN `status` VARCHAR(50) NOT NULL DEFAULT 'pending' COMMENT '器材審核狀態: pending(審核中), approved(已通過), rejected(已拒絕), completed(已完成)' AFTER `quantity`;

-- 2. 為 equipment_borrow 表添加時間戳字段（如果不存在）
ALTER TABLE `equipment_borrow` ADD COLUMN `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP COMMENT '申請時間' AFTER `status`;
ALTER TABLE `equipment_borrow` ADD COLUMN `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP COMMENT '最後更新時間' AFTER `created_at`;
ALTER TABLE `equipment_borrow` ADD COLUMN `review_note` TEXT NULL COMMENT '審核意見' AFTER `updated_at`;

-- 3. 為 events 表添加時間戳字段（如果不存在）
ALTER TABLE `events` ADD COLUMN `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP COMMENT '申請時間' AFTER `review_note`;
ALTER TABLE `events` ADD COLUMN `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP COMMENT '最後更新時間' AFTER `created_at`;
