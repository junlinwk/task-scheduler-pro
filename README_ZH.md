# Scheduler - Task Management Application

**Language / 語言:** [English](README.md) | [繁體中文](README_ZH.md)

> A feature-rich task scheduler with Google OAuth, email notifications, Spotlight-style quick search, and real-time statistics dashboard. Built with PHP & MySQL.

[![PHP Version](https://img.shields.io/badge/PHP-%3E%3D7.4-blue)](https://www.php.net/)
[![MySQL](https://img.shields.io/badge/MySQL-8.0-orange)](https://www.mysql.com/)
[![License](https://img.shields.io/badge/License-MIT-green.svg)](LICENSE)

## 立即前往
[![Visit Web App](https://img.shields.io/badge/點擊造訪網頁-orange?style=for-the-badge&logo=google-chrome)](https://task-scheduler-pro.infinityfreeapp.com/index.php)
## 目錄
1. [專案概述](#專案概述)
2. [主要特色](#主要特色)
3. [快速開始](#快速開始)
4. [功能速覽](#功能速覽)
5. [檔案結構](#檔案結構)
6. [Database設計](#database)
7. [核心功能介紹](#核心功能)
8. [前端特性](#前端特性)
9. [安全考量](#安全考量)
10. [數據流程](#數據流程)


## 專案概述

一個功能完整的任務管理應用程式，採用 PHP、MySQL、HTML、CSS 和 JavaScript 開發。提供用戶帳戶管理、任務分類、筆記系統和即時互動界面，並整合 Google OAuth 登入和 Email 通知功能。

---

## 主要特色

- **多重認證方式**: 支援傳統登入與 Google OAuth 2.0
- **Spotlight 快速搜尋**: 仿 macOS Spotlight，按 `Shift+T` 快速新增任務，`Shift+F` 快速搜尋
- **Email 通知系統**: 整合 PHPMailer，支援定時任務提醒
- **即時統計儀表板**: 完成率、任務分佈、逾期提醒一目了然
- **多視圖模式**: 日曆視圖、時間線視圖、分類視圖
- **自訂分類顏色**: 支援十六進位顏色選擇，視覺化管理任務
- **深色模式**: 支援淺色/深色主題切換
- **AJAX 即時更新**: 無需重新整理頁面即可完成大部分操作

---

## 快速開始

### 前置需求
- PHP >= 7.4
- MySQL 伺服器 (或 MariaDB)
- 網頁伺服器 (Apache 或 PHP 內建伺服器用於測試)
- Composer (用於安裝 PHPMailer 與 phpdotenv): 在專案Root執行 `composer install`
- 郵件(Mailer) 設定：若要使用 Email 通知功能，需要配置郵件發送方式（建議使用 SMTP）和相關環境變數。

- 基本 Mailer 設定說明：
  - 此專案使用 PHPMailer（已在 `composer.json` 中宣告）並會在 `send_tasks_email.php` 中嘗試從 `.env` 或系統環境變數讀取下列參數。
  - 建議把設定放在專案Root的 `.env` 檔案（避免將密碼推到版本庫）；若使用 `.env`，請確認 `vlucas/phpdotenv` 已經安裝。

  範例 `.env`: (請參考.env.example)
  ```env
  # SMTP server
  SMTP_HOST=smtp.example.com
  SMTP_PORT=587
  SMTP_USER=you@example.com
  SMTP_PASS=your-smtp-password
  SMTP_SECURE=tls    # tls 或 ssl，或者留空表示不使用 TLS/SSL
  SMTP_TIMEOUT=20
  SMTP_DEBUG=0

  # 寄件人資訊
  SMTP_FROM=noreply@todo.example.com
  SMTP_FROM_NAME="TODO Scheduler"

  # 直接測試（CLI）
  RECIPIENT_EMAIL=you@example.com
  USER_ID=1
  ```

  - 使用 Gmail SMTP 的提示：
    - SMTP_HOST: `smtp.gmail.com`
    - SMTP_PORT: `587`（TLS）或 `465`（SSL）
    - SMTP_USER: 您的 Gmail 帳號
    - SMTP_PASS: 使用「應用程式密碼」（需先啟用 2FA）
    - 應用程式密碼申請: Google 帳戶 → 安全性 → 兩步驟驗證 → 應用程式密碼


  測試 Mailer 是否正常運作：
  - CLI 測試：
    ```bash
    RECIPIENT_EMAIL=you@example.com USER_ID=1 php send_tasks_email.php
    ```
  - HTTP POST 測試（local）：
    ```bash
    curl -X POST -d "email=you@example.com&user_id=1&topic=Daily" http://127.0.0.1:8080/send_tasks_email.php
    ```

  - 失敗時fallback `mail()`（PHP 的內建函數），若仍失敗用`SMTP_DEBUG=1` 展示detail

### 安裝步驟

1. **Clone 專案**:
```bash
git clone https://github.com/yourusername/scheduler.git
cd scheduler
```

2. **安裝 PHP 相依套件**：
```bash
composer install
```

3. **配置數據庫**:
```bash
# 登入 MySQL
mysql -u your_username -p

# 匯入數據庫結構與範例資料
SOURCE /path/to/scheduler.sql;
```

4. **配置環境變數** (可選，用於 Email 功能):
```bash
# 複製範例配置檔
cp .env.example .env.local

# 編輯 .env.local，填入您的 SMTP 設定
```

5. **配置資料庫連線**:
編輯 `db.php`，修改以下參數：
```php
$DB_HOST = 'localhost';
$DB_USER = 'your_username';
$DB_PASS = 'your_password';
$DB_NAME = 'Scheduler';
```

6. **啟動開發伺服器**:
```bash
# 從專案根目錄執行
php -S 127.0.0.1:8080

# 瀏覽器開啟
# http://127.0.0.1:8080/index.php
```

7. **測試登入** (使用預設帳號):
- 用戶名: `demo`
- 密碼: `demo123`

---

## 功能速覽

### 任務管理
- **新增任務**: 支援標題、截止日期、分類選擇
- **內聯編輯**: 直接點擊任務標題即可編輯，即時保存
- **變更分類**: 下拉選單快速切換分類，視覺即時更新
- **筆記系統**: 支援長文本筆記，AJAX 非同步儲存
- **標記完成**: 點選複選框切換狀態，統計自動更新
- **刪除任務**: 附帶確認對話框，防止誤刪
- **任務統計**: 即時計算總數、完成數、逾期數、即將到期數

### 分類管理
- **自訂分類**: 支援自訂名稱和十六進位顏色 (#RRGGBBAA)
- **預設分類**: 每個用戶自動擁有不可刪除的 "None" 分類
- **重命名分類**: 內聯編輯分類名稱（預設分類除外）
- **智慧刪除**: 提供兩種刪除模式
  - **Delete All**: 刪除分類及其所有任務
  - **Detach**: 刪除分類，任務移至 "None"
- **顏色同步**: 變更分類顏色時，所有相關任務標籤同步更新
- **分類篩選**: 點擊分類可篩選顯示該分類的所有任務

### 使用者系統
- **用戶註冊**: 建立新帳戶，自動生成預設分類
- **傳統登入**: Username/Password 驗證，Session 管理
- **Google OAuth**: 支援 Google 帳號快速登入
- **安全登出**: 清除 Session，重新導向登入頁

### 進階功能
- **Spotlight 快速搜尋**: 
  - `Shift+T`: 快速新增任務
  - `Shift+F`: 全域搜尋任務
- **Email 通知**: 整合 PHPMailer，支援定時或即時發送任務提醒
- **Today's Mission**: Dashboard 自動顯示當日到期任務
- **優先級排序**: 可手動編排待辦清單的優先順序
- **多視圖切換**:
  - **日曆視圖**: 月曆顯示，點擊日期查看當日任務
  - **時間線視圖**: 按截止日期排序（逾期 → 今天 → 未來）
  - **統計視圖**: 完成率圓形圖、任務分布（依數量高至低排序）

### 介面特色
- **深色模式**: 支援淺色/深色主題切換，偏好記憶於 localStorage
- **AJAX 互動**: 筆記儲存、完成切換等操作無需重新整理頁面
- **響應式設計**: 支援桌面、平板、手機多種裝置
- **視覺化標籤**: 分類標籤顯示自訂顏色，一目了然


---

## 檔案結構

```
.
├── index.php                    # 登入/主頁
├── register.php                 # 用戶註冊頁面
├── todo.php                     # 主儀表板 (需要登入)
├── logout.php                   # 登出功能
├── auth.php                     # Session 管理輔助函數
├── db.php                       # MySQL 連接配置
├── scheduler.sql                # 數據庫初始化腳本
├── update_schema.php            # 數據庫結構升級工具
├── send_tasks_email.php         # 郵件發送模組
├── google_auth.php              # Google 認證配置
├── google_callback.php          # Google OAuth 回調
├── google_login.php             # Google 登入路由
├── assets/
│   ├── style.css                # 主樣式表
│   ├── style_login.css          # 登入頁面樣式
│   └── script.js                # 前端邏輯 (3128+ 行)
├── vendor/                      # 第三方程式庫 (PHPMailer 等)
└── README.md                    # 本文件
```

---

## Database

### DB名: `Scheduler`

### 結構

#### users 表 - 用戶信息
| 欄位 | 類型 | 說明 |
|------|------|------|
| id | INT (PK) | 用戶主鍵 |
| username | VARCHAR(255) | 用戶名 (唯一) |
| password | VARCHAR(255) | 密碼 (可為空，用於 Google 認證) |
| google_id | VARCHAR(255) | Google 帳號 ID (可為空) |

#### categories 表 - 任務分類
| 欄位 | 類型 | 說明 |
|------|------|------|
| id | INT (PK) | 分類主鍵 |
| user_id | INT (FK) | 用戶 ID |
| name | VARCHAR(255) | 分類名稱 |
| is_default | TINYINT | 1 = 默認 "None" 分類 |
| color | VARCHAR(9) | 標籤顏色 (十六進位: #RRGGBBAA) |

#### tasks 表 - 待辦任務
| 欄位 | 類型 | 說明 |
|------|------|------|
| id | INT (PK) | 任務主鍵 |
| user_id | INT (FK) | 用戶 ID |
| category_id | INT (FK) | 分類 ID |
| title | VARCHAR(255) | 任務標題 |
| deadline | DATE | 截止日期 (可為空) |
| is_done | TINYINT | 0 = 未完成, 1 = 已完成 |
| notes | LONGTEXT | 任務筆記 (可為空) |

---

## 核心功能

### 1. 認證系統 (authentication)

#### 用戶註冊 (`register.php`)
- **功能**: 創建新用戶帳戶
- **必填欄位**: 用戶名、密碼、確認密碼
- **驗證**:
  - 檢查用戶名唯一性
  - 密碼長度驗證
  - 密碼確認匹配檢查
- **自動操作**: 註冊成功後自動創建默認 "None" 分類
- **數據持久化**: 用戶信息存儲在 `users` 表

#### 用戶登入 (`index.php`)
- **功能**: 用戶身份驗證
- **登入表單**: 用戶名和密碼欄位
- **驗證流程**:
  1. 檢查用戶名是否存在
  2. 驗證密碼是否正確
  3. 成功時設置 `$_SESSION['user_id']` 和 `$_SESSION['username']`
  4. 重定向到主儀表板 (`todo.php`)
- **演示帳戶**: 可用 demo/demo123 進行測試

#### 用戶登出 (`logout.php`)
- **功能**: 結束用戶會話
- **流程**:
  1. 銷毀 `$_SESSION`
  2. 清除所有會話變量
  3. 重定向回登入頁 (`index.php`)
- **會話管理**: 由 `auth.php` 中的 `is_logged_in()` 和 `require_login()` 函數保護

### 2. 任務管理 (task management)

#### 新增任務 (`action=add_task`)
- **提交位置**: todo.php 中的任務表單
- **輸入欄位**:
  - 任務標題 (必填)
  - 截止日期 (可選, 日期格式)
  - 分類選擇 (預設 = "None")
- **數據庫操作**:
  ```sql
  INSERT INTO tasks (user_id, category_id, title, deadline, is_done, notes)
  VALUES (?, ?, ?, ?, 0, '')
  ```
- **邏輯**:
  1. 檢查標題是否為空
  2. 如果分類 ID = 0，自動設為用戶默認 "None" 分類
  3. 保存到 tasks 表，is_done 初始化為 0

#### 編輯任務名稱 (`action=update_task_name`)
- **提交方式**: 內聯編輯表單 (contenteditable 欄位)
- **操作流程**:
  1. 任務卡片上的標題為可編輯狀態
  2. 用戶編輯後按 Enter 或點擊保存按鈕
  3. 後端更新 `tasks.title` 並返回成功狀態
- **數據持久化**: 修改立即保存到 MySQL

#### 變更任務分類 (`action=update_task_category`)
- **提交方式**: 下拉選單選擇
- **操作流程**:
  1. 在任務卡片中選擇新分類
  2. POST 提交 task_id 和新的 category_id
  3. 更新 `tasks.category_id`
- **即時反映**: 頁面刷新或 AJAX 更新後顯示新分類標籤

#### 截止日期管理
- **設定方式**: 新增/編輯任務時選擇日期
- **統計功能**: 主儀表板中顯示:
  - 逾期任務數量
  - 即將到期任務數量
  - 下一個截止日期提示

#### 標記任務完成 (`action=toggle_done`)
- **提交方式**: 任務卡片上的複選框
- **操作流程**:
  1. 點擊複選框切換任務完成狀態
  2. 支持 AJAX 方式 (無需整頁刷新)
  3. 後端更新 `tasks.is_done` (0 ↔ 1)
- **即時統計更新**: 如使用 AJAX，返回更新後的統計數據
- **視覺反饋**: 完成的任務顯示不同樣式 (刪除線、灰色)

#### 刪除任務 (`action=delete_task`)
- **提交方式**: 任務卡片上的刪除按鈕
- **確認機制**: 顯示確認對話框防止誤刪
- **操作**:
  1. 用戶點擊刪除按鈕
  2. 彈出確認提示
  3. 確認後 DELETE FROM tasks WHERE id = ?
- **級聯考慮**: 刪除後自動更新統計數據

#### 管理任務筆記 (`action=update_task_notes`)
- **提交方式**: 
  - 任務卡片點擊打開筆記Overlay
  - 筆記輸入框完成編輯後提交
- **功能特性**:
  - 支持 AJAX 非同步提交 (返回 JSON 格式)
  - 任意長度文本 (LONGTEXT 類型)
  - 保存後立即在 UI 中更新顯示
- **多處顯示**: 筆記內容顯示在:
  - 主任務卡片摘要
  - 日曆視圖任務列表
  - 統計面板任務重疊層
  - 計劃視圖任務清單

#### 任務統計數據
應用程式會計算並顯示:
- **總任務數**: 用戶所有任務計數
- **已完成**: is_done = 1 的任務數
- **活躍任務**: is_done = 0 的任務數
- **逾期**: 未完成且截止日期已過的任務數
- **即將到期**: 未完成且有未來截止日期的任務數
- **完成率**: (已完成 / 總任務) × 100%
- **下一截止日期**: 最早的即將到期任務日期

### 3. 分類管理 (category management)

#### 新增分類 (`action=add_category`)
- **提交位置**: todo.php 中的 "新增分類" 表單
- **輸入欄位**:
  - 分類名稱 (必填)
  - 顏色選擇 (十六進位顏色選擇器, 可選)
- **數據庫操作**:
  ```sql
  INSERT INTO categories (user_id, name, is_default, color)
  VALUES (?, ?, 0, ?)
  ```
- **預設顏色**: 如未選擇，使用系統默認色

#### 重命名分類 (`action=update_category_name`)
- **提交方式**: 分類列表中的內聯編輯
- **限制**: 不允許重命名默認 "None" 分類 (is_default = 1)
- **操作**:
  1. 點擊分類名稱進入編輯模式
  2. 輸入新名稱並按 Enter 或保存
  3. 後端更新 `categories.name`

#### 變更分類顏色 (`action=update_category_color`)
- **提交方式**: 分類卡片上的顏色選擇器
- **顏色格式**: 十六進位 (#RRGGBB 或 #RRGGBBAA，支持透明度)
- **操作**:
  1. 選擇新顏色
  2. 提交 category_id 和新顏色值
  3. 更新 `categories.color` 並即時反映在標籤上
- **視覺更新**: 所有使用此分類的任務標籤顏色同時更新

#### 刪除分類 (`action=delete_category`)
- **提交方式**: 分類卡片上的刪除按鈕
- **刪除模式**:
  - **delete_all**: 刪除分類及其所有任務
  - **detach**: 刪除分類，將其任務移至默認 "None" 分類
- **限制**: 不允許刪除默認 "None" 分類
- **確認機制**: 顯示刪除模式選擇對話框
- **數據操作**:
  - delete_all 模式: `DELETE FROM tasks WHERE category_id = ?` 然後 `DELETE FROM categories WHERE id = ?`
  - detach 模式: `UPDATE tasks SET category_id = <default_id> WHERE category_id = ?` 然後刪除分類

#### 默認分類 ("None")
- **自動創建**: 每個用戶註冊時自動創建
- **用途**: 沒有指定分類的任務的兜底分類
- **特性**:
  - is_default = 1
  - 不允許重命名或刪除
  - 所有新建任務如果分類選擇無效，自動分配到此分類

### 4. 筆記與Overlay系統 (notes & overlay)

#### 任務筆記功能
- **存儲位置**: `tasks.notes` 欄位 (LONGTEXT)
- **編輯方式**:
  1. 點擊任務卡片打開全局筆記Overlay
  2. 在Overlay中編輯長文本
  3. 點擊保存或 Ctrl+S 提交
- **提交方式**: AJAX (無需整頁刷新)，支持即時保存
- **字符限制**: 無限制 (LONGTEXT 最大 4GB)

#### Overlay系統
應用程式包含多個Overlay模組:

**全局任務筆記Overlay**
- 點擊主任務列表中的任務打開
- 包含任務摘要和完整筆記編輯區域
- 支持 Markdown 預覽 (可選)
- 保存按鈕和取消按鈕

**統計面板Overlay**
- 顯示應用程式統計信息
- 中央卡片顯示:
  - 完成率圓形進度圖
  - 總數、完成數、活躍數、逾期數、即將到期數
  - 下一截止日期提示

**計劃視圖Overlay**
- 按時間線顯示所有任務
- 按截止日期排序 (逾期 → 今天 → 明天 → 未來)
- 支持任務快速操作 (完成/刪除/編輯筆記)
- Overlay都有背景鎖定

**日曆視圖**
- 月份日曆顯示
- 有任務的日期用視覺指示標記
- 點擊日期查看當天所有任務
- 支持月份導航 (上月/下月)

**設置Overlay** (額外功能)
- 主題切換 (淺色/深色模式)
- 電子郵件通知設置 (localStorage 存儲)

**確認對話框**
- 刪除操作前的確認
- 分類刪除模式選擇
- 自訂提示文本

####  Overlay管理
前端使用 JS 管理Overlay生命週期:
- `lockBodyScroll()`: 打開Overlay時鎖定背景滾動
- `unlockBodyScroll()`: 關閉Overlay時解鎖
- 支持多個疊加Overlay
- ESC 鍵快速關閉
- 背景點擊關閉 (設置相依)

---

## 前端特性

### 用戶界面組件

#### 主儀表板 (todo.php)
- **頂部導航**: 用戶名、主題切換、登出按鈕
- **統計面板**: 四張卡片顯示主要指標
- **快速操作區**: 新增任務表單、新增分類表單
- **任務列表**: 按分類分組顯示，支持內聯編輯
- **側邊欄**: 分類選擇、日曆視圖

#### 任務卡片設計
- **卡片信息**:
  - 任務標題 (可點擊內聯編輯)
  - 完成狀態複選框
  - 所屬分類標籤 (帶顏色)
  - 截止日期 (相對時間: "今天", "明天", "3 天內" 等)
  - 筆記摘要 (點擊打開完整筆記)
- **互動方式**:
  - 點擊卡片打開筆記Overlay
  - 點擊標題編輯名稱
  - 下拉菜單變更分類
  - 複選框標記完成
  - 右鍵菜單或按鈕刪除

#### 分類管理 UI
- **分類列表**: 顯示所有用戶分類
- **分類卡片**: 包含:
  - 分類名稱 (可編輯, 除默認 "None")
  - 分類顏色方塊 (可點擊選擇新顏色)
  - 分類計數 (該分類下的任務數)
  - 刪除按鈕 (帶刪除模式選擇)

#### 響應式設計
- **移動優化**: 觸摸友好的按鈕和輸入
- **斷點適配**: 平板和桌面視圖
- **CSS 網格**: 卡片排列自適應

### JavaScript 功能 (script.js - 3128+ 行)

#### 事件處理
- 表單提交事件
- 複選框變更事件
- 點擊委託處理
- 鍵盤快捷鍵 (Enter 保存, Esc 取消, Ctrl+S 提交)

#### AJAX 操作
- 任務筆記保存 (非同步)
- 任務完成切換 (非同步)
- 統計數據更新 (無需刷新)
- 分類顏色更新 (即時反映)

#### 日曆模組
- 月份視圖渲染
- 日期事件標記
- 月份導航控制
- 日期點擊事件

#### 主題系統
- 淺色/深色模式切換
- CSS 變量動態切換
- localStorage 記憶用戶偏好

#### 電子郵件通知 (send_tasks_email.php)
- 定時任務郵件提醒
- 基於 PHPMailer 庫
- SMTP 配置支持

---

## 安全考量

### 當前實現
- 登入保護: `require_login()` 保護所有受限頁面
- 會話管理: 基於 PHP SESSION
- 數據庫連接: MySQLi prepared statements (防 SQL 注入)
- HTML 轉義: `htmlspecialchars()` 防 XSS

### 已知限制與改進建議
- **密碼存儲**: 當前使用明文存儲 (僅用於演示)
  - **改進**: 使用 `password_hash()` 和 `password_verify()`
  ```php
  // 改進方案
  $hashed = password_hash($_POST['password'], PASSWORD_BCRYPT, ['cost' => 12]);
  ```

- **CSRF 保護**: 未實現表單令牌
  - **改進**: 為每個表單添加 CSRF 令牌驗證
  ```php
  // 生成令牌
  $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
  // 驗證令牌
  if ($_POST['csrf_token'] !== $_SESSION['csrf_token']) die('CSRF');
  ```

- **速率限制**: 無登入嘗試限制
  - 改進: 實現登入失敗計數器和 IP 黑名單

- **輸入驗證**: 基礎驗證，可強化
  - 改進: 添加伺服器端長度、格式、字符集驗證

---

## 數據流程

### 任務創建流程
```
用戶填寫新增任務表單
  ↓
JavaScript 驗證表單
  ↓
POST 請求到 todo.php (action=add_task)
  ↓
PHP 後端驗證並清理輸入
  ↓
檢查分類有效性，設置默認分類
  ↓
執行 INSERT 語句到 tasks 表
  ↓
重新加載頁面或 AJAX 更新
  ↓
新任務在 UI 中出現
```

### 任務筆記更新流程
```
用戶在Overlay編輯筆記
  ↓
點擊保存或 Ctrl+S
  ↓
AJAX POST 請求 (action=update_task_notes)
  ↓
PHP 後端更新 tasks.notes 欄位
  ↓
返回 JSON 響應 { success: true, notes: '...' }
  ↓
JavaScript 更新 DOM (無需刷新)
  ↓
顯示成功提示
```

### 會話保護流程
```
用戶訪問 todo.php
  ↓
require_once 'auth.php'
  ↓
調用 require_login()
  ↓
檢查 $_SESSION['user_id'] 是否存在
  ↓
如不存在，重定向到 index.php
  ↓
如存在，載入用戶特定數據
  ↓
顯示受保護的儀表板
```

---

## 技術棧 / Tech Stack

### Backend
- **PHP** >= 7.4 - Server-side logic
- **MySQL** 8.0 - Relational database
- **MySQLi** - Database driver with prepared statements
- **Composer** - Dependency management

### Frontend
- **Vanilla JavaScript** - 3000+ lines of custom code
- **HTML5** & **CSS3** - Semantic markup and modern styling
- **AJAX** - Asynchronous data updates

### Libraries
- **PHPMailer** - SMTP email functionality
- **vlucas/phpdotenv** - Environment variable management
- **Google OAuth 2.0** - Third-party authentication

---


## 授權 / License

本專案採用 MIT 授權條款 - 詳見 [LICENSE](LICENSE) 檔案

---


