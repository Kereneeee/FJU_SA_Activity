# 快速開始指南 - 雙狀態框實施

## ⚡ 快速步驟

### 步驟 1：執行數據庫遷移
在 MySQL 中執行 `database/migration_equipment_status.sql` 中的 SQL 命令：

```bash
mysql -u root -p12345678 fjusa < database/migration_equipment_status.sql
```

或在 phpMyAdmin 中複製並執行 SQL 語句。

### 步驟 2：驗證文件已創建
確保以下新文件已存在：
- ✓ `student/add_equipment.php` - 追加申請器材頁面
- ✓ `api/add_equipment_request.php` - API
- ✓ `api/review_equipment.php` - 管理員 API
- ✓ 已修改 `student/my_applications.php`

### 步驟 3：測試功能

#### 測試1：查看申請列表
1. 以學生身份登入
2. 進入"我的申請"頁面
3. 驗證每個申請卡片右上角顯示雙狀態框

#### 測試2：無器材申請的情況
1. 找一個沒有器材申請的申請
2. 驗證右上角顯示"追加申請器材"按鈕
3. 點擊按鈕進入 `add_equipment.php`

#### 測試3：追加器材申請
1. 在 `add_equipment.php` 上：
   - 驗證活動信息顯示為只讀
   - 選擇一些器材並輸入數量
   - 點擊"提交申請"
2. 驗證返回我的申請頁面後：
   - 該申請現在顯示器材狀態
   - 器材詳情顯示在卡片下方

#### 測試4：有器材申請的情況
1. 找一個已有器材申請的申請
2. 驗證右上角顯示"器材"狀態框
3. 驗證器材詳情顯示在卡片下方

## 📋 功能特性列表

### 學生端
- [x] 查看活動和器材的分別狀態
- [x] 追加申請器材
- [x] 修改器材申請
- [x] 查看器材申請詳情
- [x] 按狀態篩選申請

### 管理員端（已建立 API，待開發 UI）
- [x] 審核器材申請 (API: `api/review_equipment.php`)
- [ ] 管理員審核頁面 (待開發)
- [ ] 批量審核功能 (待開發)

## 🔄 狀態流程

```
申請流程：
學生申請 (pending) 
  ↓
管理員審核 (approved/rejected)
  ↓
活動完成 (completed)

器材申請流程：
追加申請 (pending)
  ↓
管理員審核 (approved/rejected)
  ↓
器材借用完成 (completed)
```

## 🐛 常見問題

### Q: 如果申請活動已拒絕，還能追加申請器材嗎？
A: 可以，系統允許在任何狀態下追加器材申請。建議在管理員審核層面控制邏輯。

### Q: 如何確保不會重複器材申請？
A: 提交時會自動刪除舊的器材申請記錄，然後插入新的。

### Q: 器材狀態在哪裡設置？
A: 通過 `api/review_equipment.php` 或待開發的管理員 UI。

## 📝 數據庫表結構變更

### equipment_borrow 表新增字段
```
status       VARCHAR(50)     器材審核狀態
created_at   TIMESTAMP       申請時間
updated_at   TIMESTAMP       最後更新時間
review_note  TEXT            審核意見
```

### events 表新增字段（如果沒有）
```
created_at   TIMESTAMP       申請時間
updated_at   TIMESTAMP       最後更新時間
```

## 🔐 權限驗證

- `api/add_equipment_request.php` - 需要登入
- `api/review_equipment.php` - 需要管理員權限
- `student/add_equipment.php` - 需要登入

## 📚 相關頁面

| 頁面 | URL | 說明 |
|------|-----|------|
| 我的申請 | `/student/my_applications.php` | 查看所有申請 |
| 追加器材 | `/student/add_equipment.php?event_id=X` | 追加申請器材 |
| 活動申請 | `/student/apply_event.php` | 新建活動申請 |

## 🎨 UI/UX 改進

- 雙狀態框清晰顯示活動和器材的狀態
- "追加申請器材"按鈕醒目且易於點擊
- 器材詳情以卡片形式展示
- 只讀字段有視覺區分

## ✅ 驗收清單

- [ ] 數據庫遷移成功
- [ ] my_applications.php 顯示正常
- [ ] 能創建無器材的申請
- [ ] 能追加器材申請
- [ ] 器材狀態顯示正確
- [ ] 分類邏輯工作正常
- [ ] 響應式設計正常工作

## 📞 支持

如有問題，請檢查：
1. 數據庫是否正確遷移
2. 文件是否正確上傳
3. 瀏覽器開發者工具是否有錯誤
4. 服務器日誌是否有錯誤信息

---
**最後更新**：2026-05-18
**版本**：1.0
