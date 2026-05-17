# 活動申請系統 - 雙狀態框實施指南

## 概述
本實施指南說明如何為課外活動申請系統添加分離的活動/場地狀態和器材狀態追蹤功能。

## 已實施的功能

### 1. 數據庫變更
已創建遷移腳本：`database/migration_equipment_status.sql`

需要執行的 SQL 命令：
```sql
-- 為 equipment_borrow 表添加 status 字段
ALTER TABLE `equipment_borrow` ADD COLUMN `status` VARCHAR(50) NOT NULL DEFAULT 'pending' COMMENT '器材審核狀態: pending(審核中), approved(已通過), rejected(已拒絕), completed(已完成)' AFTER `quantity`;

-- 為 equipment_borrow 表添加時間戳字段
ALTER TABLE `equipment_borrow` ADD COLUMN `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP COMMENT '申請時間' AFTER `status`;
ALTER TABLE `equipment_borrow` ADD COLUMN `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP COMMENT '最後更新時間' AFTER `created_at`;
ALTER TABLE `equipment_borrow` ADD COLUMN `review_note` TEXT NULL COMMENT '審核意見' AFTER `updated_at`;

-- 為 events 表添加時間戳字段（如果還沒有）
ALTER TABLE `events` ADD COLUMN `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP COMMENT '申請時間' AFTER `review_note`;
ALTER TABLE `events` ADD COLUMN `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP COMMENT '最後更新時間' AFTER `created_at`;
```

### 2. 前端頁面修改

#### 修改的文件：`student/my_applications.php`
- 修改 SQL 查詢以獲取器材申請信息
- 添加雙狀態框顯示器材和活動/場地的狀態
- 添加"追加申請器材"按鈕（當無器材申請時）
- 顯示器材申請詳情（當有器材申請時）
- 添加 CSS 樣式以支持新的布局
- 添加 JavaScript 函數 `redirectToAddEquipment()`

#### 新增頁面：`student/add_equipment.php`
- 追加申請器材的頁面
- 顯示活動信息為只讀（已填好，不能更動）
- 允許選擇和修改器材申請
- 支持新增或更新器材申請

### 3. API 端點

#### 新增 API：`api/add_equipment_request.php`
- 用途：提交或更新器材申請
- 參數：`event_id`, `equipment[]`
- 返回：JSON 響應

#### 新增 API：`api/review_equipment.php`
- 用途：管理員審核器材申請
- 參數：`borrow_id`, `status`, `review_note`
- 返回：JSON 響應

## 功能說明

### 學生端（我的申請）
1. **雙狀態框顯示**
   - 左側框：【活動、場地申請中/已通過/已完成】
   - 右側框：【器材審核中/已通過/已完成】或【追加申請器材】按鈕

2. **應用邏輯**
   - 如果申請中有器材項目，顯示器材狀態
   - 如果沒有器材申請，顯示"追加申請器材"按鈕

3. **器材詳情**
   - 如果有器材申請，在卡片下方顯示器材列表及其狀態

### 追加申請器材
1. 點擊"追加申請器材"按鈕進入 `add_equipment.php`
2. 活動信息和場地為只讀（已填好）
3. 可以選擇和輸入器材數量
4. 提交後器材狀態為"審核中"

### 分類邏輯
- **審核中**：活動或器材任一個為"審核中"
- **已通過**：活動和器材都為"已通過"（如果有器材申請）
- **已完成**：活動和器材都為"已完成"
- **已拒絕**：活動被拒絕（優先級最高）

## 狀態說明

### 事件狀態（events 表的 status）
- `pending`：審核中
- `approved`：已通過
- `rejected`：已拒絕
- `completed`：已完成
- `cancelled`：已取消

### 器材申請狀態（equipment_borrow 表的 status）
- `pending`：審核中（新申請或編輯後的默認狀態）
- `approved`：已通過（管理員審核通過）
- `rejected`：已拒絕（管理員審核拒絕）
- `completed`：已完成（活動結束後標記）

## 管理員側功能（待開發）

需要為管理員創建器材審核頁面，允許：
1. 查看待審核的器材申請
2. 批准或拒絕申請
3. 添加審核意見
4. 查看申請歷史

## 測試清單

- [ ] 執行數據庫遷移 SQL
- [ ] 驗證 my_applications.php 顯示正確
- [ ] 點擊"追加申請器材"進入 add_equipment.php
- [ ] 成功提交器材申請
- [ ] 驗證器材狀態在列表中顯示正確
- [ ] 驗證分類邏輯工作正常
- [ ] 測試沒有器材的申請顯示"追加申請器材"按鈕
- [ ] 測試有器材的申請顯示器材詳情

## 文件列表

### 修改的文件
- `student/my_applications.php` - 雙狀態框顯示

### 新增的文件
- `database/migration_equipment_status.sql` - 數據庫遷移腳本
- `student/add_equipment.php` - 追加申請器材頁面
- `api/add_equipment_request.php` - 器材申請 API
- `api/review_equipment.php` - 器材審核 API

## 下一步工作

1. 執行數據庫遷移
2. 測試前端功能
3. 為管理員創建器材審核管理頁面
4. 添加 email 通知功能（可選）
5. 添加器材借用單據生成（可選）

## 相關文件位置

- 前端文件：`c:\AppServ\www\SA_FJU\student\`
- API 文件：`c:\AppServ\www\SA_FJU\api\`
- 數據庫：`c:\AppServ\www\SA_FJU\database\`
- 數據庫連接配置：`c:\AppServ\www\SA_FJU\DB\db_config.php`
