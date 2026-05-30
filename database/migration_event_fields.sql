-- 活動申請新欄位 Migration
-- 新增活動負責人
ALTER TABLE events ADD COLUMN responsible_person VARCHAR(100) DEFAULT NULL COMMENT '活動負責人';

-- 新增活動類型 (校內/校外)
ALTER TABLE events ADD COLUMN event_type VARCHAR(10) DEFAULT '校內' COMMENT '活動類型';

-- 新增校外活動地點
ALTER TABLE events ADD COLUMN activity_location VARCHAR(255) DEFAULT NULL COMMENT '校外活動地點';

-- 新增活動規模 (一般活動, 大型活動, 含酒精活動, 使用火源活動 逗號分隔)
ALTER TABLE events ADD COLUMN activity_scale VARCHAR(200) DEFAULT '一般活動' COMMENT '活動規模標記';

-- 新增企劃書路徑
ALTER TABLE events ADD COLUMN proposal_doc_path VARCHAR(255) DEFAULT NULL COMMENT '活動企劃書路徑';
