# PHP All-in-One Master

WordPress 站台域名遷移 CLI 工具，仿照 [All-in-One WP Migration](https://wordpress.org/plugins/all-in-one-wp-migration/) 插件的核心替換邏輯實作。

在搬移 WordPress 網站時，將資料庫中所有舊域名批次替換為新域名，正確處理 PHP 序列化格式、多種 URL 編碼變體與主流 Page Builder 的 Base64 資料，確保替換後的資料庫完全可用。

---

## 功能特色

- **序列化安全替換**：自動解序列化 → 替換 → 重新序列化，修正長度計數，不會損毀 WordPress 資料
- **多種 URL 編碼變體**：自動產生 `urlencode`、`rawurlencode`、JSON escape 等多種編碼形式，確保完整替換
- **www 互換**：自動處理 `www.old.com` ↔ `old.com`、HTTP ↔ HTTPS 所有組合
- **Email 域名替換**：`user@old.com` → `user@new.com`（可停用）
- **實體路徑替換**：支援 `wp-content` 實體路徑替換
- **Page Builder 支援**：Visual Composer、Oxygen Builder、BeTheme / Avada 的 Base64 資料替換
- **資料表前綴替換**：支援 SQL 檔案模式下的資料表前綴變更
- **快取跳過**：自動跳過 `_transient_`、`_wc_session_` 等快取資料，避免無謂處理
- **Transaction 保護**：直連 MySQL 模式使用 Transaction，失敗自動 ROLLBACK
- **串流處理**：SQL 檔案模式逐行讀取，適合處理大型 SQL 檔案（GB 級）

---

## 系統需求

| 項目 | 版本需求 |
|------|---------|
| PHP | `>= 8.1` |
| PHP 擴充 | `pdo`、`pdo_mysql`（直連模式必須） |
| 作業系統 | Linux / macOS / Windows（WSL） |

---

## 安裝

無需 Composer，下載後即可使用：

```bash
git clone https://github.com/your-org/php-all-in-one-master.git
cd php-all-in-one-master
php replace.php --help
```

---

## 快速開始

### 模式一：SQL 檔案替換

先從舊站匯出 SQL，再執行替換，最後匯入至新站：

```bash
# 基本用法
php replace.php \
  --old-url=https://old.com \
  --new-url=https://new.com \
  --sql=dump.sql

# 同時替換資料表前綴（舊前綴 → 新前綴）
php replace.php \
  --old-url=https://old.com \
  --new-url=https://new.com \
  --sql=dump.sql \
  --old-prefix=wp_ \
  --new-prefix=mysite_
```

### 模式二：直連 MySQL 替換

直接對線上資料庫操作，自動讀取 `wp-config.php`：

```bash
# 基本用法（自動讀取 wp-config.php）
php replace.php \
  --old-url=https://old.com \
  --new-url=https://new.com \
  --wp-root=/var/www/html

# 覆寫資料表前綴
php replace.php \
  --old-url=https://old.com \
  --new-url=https://new.com \
  --wp-root=/var/www/html \
  --table-prefix=wp_
```

> **注意**：直連模式會直接修改線上資料庫，建議操作前先備份。

---

## 完整 CLI 參數

```
php replace.php [選項]
```

### 必填參數

| 參數 | 說明 | 範例 |
|------|------|------|
| `--old-url=<URL>` | 舊站 URL | `--old-url=https://old.com` |
| `--new-url=<URL>` | 新站 URL | `--new-url=https://new.com` |

### 模式一：SQL 檔案（三者擇一必填）

| 參數 | 說明 | 範例 |
|------|------|------|
| `--sql=<path>` | SQL 檔案路徑（`.sql`） | `--sql=dump.sql` |
| `--old-prefix=<prefix>` | 舊資料表前綴（選填） | `--old-prefix=wp_` |
| `--new-prefix=<prefix>` | 新資料表前綴（選填，需與 `--old-prefix` 一起使用） | `--new-prefix=mysite_` |

### 模式二：直連 MySQL

| 參數 | 說明 | 範例 |
|------|------|------|
| `--wp-root=<path>` | WordPress 根目錄（含 `wp-config.php`） | `--wp-root=/var/www/html` |
| `--table-prefix=<prefix>` | 覆寫資料表前綴（選填，預設從 `wp-config.php` 讀取） | `--table-prefix=wp_` |

### 共用選填參數

| 參數 | 說明 | 範例 |
|------|------|------|
| `--old-path=<path>` | 舊站 `wp-content` 實體路徑 | `--old-path=/home/old/wp-content` |
| `--new-path=<path>` | 新站 `wp-content` 實體路徑 | `--new-path=/var/www/wp-content` |
| `--no-email-replace` | 停用 Email 域名替換 | `--no-email-replace` |
| `--visual-composer` | 啟用 Visual Composer Base64 替換 | `--visual-composer` |
| `--oxygen-builder` | 啟用 Oxygen Builder Base64 替換 | `--oxygen-builder` |
| `--betheme-avada` | 啟用 BeTheme / Avada Base64 替換 | `--betheme-avada` |
| `--extra-old=<value>` | 額外替換舊值（可重複多次） | `--extra-old=old-cdn.com` |
| `--extra-new=<value>` | 對應額外新值（數量須與 `--extra-old` 相同） | `--extra-new=new-cdn.com` |
| `--help` | 顯示說明文字 | `--help` |

---

## 架構圖

```
replace.php（CLI 入口）
    │
    ├── UrlVariantBuilder
    │       產生舊/新 URL 的所有編碼變體對照表
    │       （plain / urlencode / rawurlencode / JSON escape /
    │         www互換 / HTTP→HTTPS / Email / 實體路徑）
    │
    ├── [模式一] Replacer
    │       串流讀取 SQL 檔案 → processQuery() → 安全覆寫
    │       └── SerializedReplacer（序列化安全替換引擎）
    │
    └── [模式二] DatabaseReplacer
            PDO 連線 → 逐表逐列替換 → Transaction COMMIT / ROLLBACK
            ├── WpConfigReader（解析 wp-config.php）
            └── SerializedReplacer（序列化安全替換引擎）
```

---

## 目錄結構

```
php-all-in-one-master/
├── replace.php                  CLI 入口腳本
└── src/
    ├── UrlVariantBuilder.php    URL 編碼變體產生器
    ├── SerializedReplacer.php   序列化安全替換引擎
    ├── Replacer.php             SQL 檔案替換器
    ├── DatabaseReplacer.php     直連 MySQL 替換器
    └── WpConfigReader.php       wp-config.php 解析器
```

---

## 類別 API 說明

### `UrlVariantBuilder`

**命名空間：** `WpMigrate\Src`

對舊/新 URL 產生多種編碼形式的替換對照表，確保資料庫中以各種格式儲存的舊域名都能被完整替換。

#### 建構子

```php
new UrlVariantBuilder(
    string $oldUrl,
    string $newUrl,
    bool   $replaceEmail = true,
    string $oldPath      = '',
    string $newPath      = '',
)
```

#### 方法

| 方法 | 回傳 | 說明 |
|------|------|------|
| `build()` | `array` | 建立替換對照表，格式為 `['old', 'new', 'oldRaw', 'newRaw']` |
| `addCustomPair(string $old, string $new)` | `void` | 新增自訂替換對（自動產生 4 種編碼變體） |

#### 產生的 URL 變體

每個 URL 自動產生以下所有組合：

- **Scheme**：`https://`、`http://`、`//`（scheme-relative）
- **www**：有 www / 無 www 互換
- **編碼格式**：plain、`urlencode()`、`rawurlencode()`、JSON escape（`addcslashes()`）
- **Email**：`@old.com` → `@new.com`（可停用）
- **Raw 格式**：WordPress multisite 的 `domain`/`path` 欄位
- **實體路徑**：若指定 `--old-path`/`--new-path`

---

### `SerializedReplacer`

**命名空間：** `WpMigrate\Src`

WordPress 資料庫大量使用 PHP 序列化格式（`serialize()`）儲存資料，普通字串替換會讓序列化的長度計數失效，導致資料損毀。本類別正確處理此問題。

#### 公開方法

| 方法 | 回傳 | 說明 |
|------|------|------|
| `replaceInSqlLine(string $input, array $oldValues, array $newValues, bool $visualComposer, bool $oxygenBuilder, bool $bethemeOrAvada)` | `string` | 對 SQL 行執行所有替換 |
| `static replaceValues(array $oldValues, array $newValues, string $data)` | `string` | 批次純文字替換（使用 `strtr` 效率最高） |
| `static replaceSerializedValues(array $from, array $to, mixed $data, bool $serialized)` | `mixed` | 遞迴序列化安全替換 |
| `static escapeMysql(string $data)` | `string` | MySQL 字串跳脫 |
| `static unescapeMysql(string $data)` | `string` | MySQL 字串反跳脫 |
| `static base64Validate(string $data)` | `bool` | 驗證是否為合法 Base64 字串 |

#### 支援的 Page Builder 格式

| Page Builder | 資料格式 |
|---|---|
| Visual Composer | `[vc_raw_html]BASE64[/vc_raw_html]` |
| Oxygen Builder | `\"code-php\":\"BASE64\"` |
| BeTheme / Avada | `'BASE64'` |

---

### `Replacer`

**命名空間：** `WpMigrate\Src`

讀取 `.sql` 檔案，逐行串流處理。替換完成後，先寫入暫存檔（`.tmp_xxx`），成功才替換原始檔案，避免中途失敗損毀原檔。

#### 方法（支援鏈式呼叫）

| 方法 | 說明 |
|------|------|
| `setOldTablePrefixes(array $prefixes)` | 設定舊資料表前綴清單 |
| `setNewTablePrefixes(array $prefixes)` | 設定新資料表前綴清單 |
| `setOldValues(array $values)` | 設定舊值清單（序列化安全替換） |
| `setNewValues(array $values)` | 設定新值清單 |
| `setOldRawValues(array $values)` | 設定舊 raw 值清單（純字串替換） |
| `setNewRawValues(array $values)` | 設定新 raw 值清單 |
| `setVisualComposer(bool $enabled)` | 啟用 Visual Composer Base64 替換 |
| `setOxygenBuilder(bool $enabled)` | 啟用 Oxygen Builder Base64 替換 |
| `setBethemeOrAvada(bool $enabled)` | 啟用 BeTheme / Avada Base64 替換 |
| `process(string $sqlFilePath)` | 核心執行：串流讀取並覆寫 SQL 檔案 |
| `processQuery(string $query)` | 對單一 SQL 語句執行替換（可用於單元測試） |

#### 替換順序

1. 資料表前綴替換（`str_ireplace`）
2. 跳過快取型查詢（`_transient_`、`_wc_session_` 等）
3. Base64 Page Builder 資料替換
4. 序列化安全替換
5. raw values 純文字替換

---

### `DatabaseReplacer`

**命名空間：** `WpMigrate\Src`

透過 PDO 直接連線至 WordPress 資料庫，逐資料表、逐欄位執行替換，全程包裹在 Transaction 中（失敗自動 ROLLBACK）。

#### 建構子

```php
new DatabaseReplacer(WpConfigReader $config)
```

#### 方法（支援鏈式呼叫）

| 方法 | 說明 |
|------|------|
| `setOldValues / setNewValues / setOldRawValues / setNewRawValues` | 設定替換對照表 |
| `setVisualComposer / setOxygenBuilder / setBethemeOrAvada` | 啟用對應 Page Builder 替換 |
| `setTablePrefix(string $prefix)` | 設定 WordPress 資料表前綴 |
| `process()` | 核心執行：連線 → 取得所有資料表 → 逐表替換 → COMMIT |
| `getStats()` | 回傳各資料表更新列數（`['wp_posts' => 5, ...]`） |

#### 安全機制

- 僅處理文字相關欄位型別（`text`、`mediumtext`、`longtext`、`varchar`、`char`、`blob` 等）
- 自動跳過快取資料（`_transient_`、`_site_transient_`、`_wc_session_`）
- PDO prepared statement 防止 SQL injection
- 整個替換包裹在單一 Transaction 中

---

### `WpConfigReader`

**命名空間：** `WpMigrate\Src`

解析 WordPress 的 `wp-config.php` 取得資料庫連線設定。採用 PHP `token_get_all()` 進行精確的 token 解析，避免正規表達式誤判多行字串或 heredoc。

#### 建構子

```php
new WpConfigReader(string $wpRoot)
```

#### 方法

| 方法 | 回傳 | 說明 |
|------|------|------|
| `parse()` | `static` | 解析 wp-config.php，缺少必要常數時拋出 `RuntimeException` |
| `getDbHost()` | `string` | 資料庫主機（`DB_HOST`） |
| `getDbName()` | `string` | 資料庫名稱（`DB_NAME`） |
| `getDbUser()` | `string` | 資料庫使用者（`DB_USER`） |
| `getDbPassword()` | `string` | 資料庫密碼（`DB_PASSWORD`） |
| `getDbCharset()` | `string` | 字元集（`DB_CHARSET`，預設 `utf8`） |
| `getDbCollate()` | `string` | Collation（`DB_COLLATE`） |
| `getTablePrefix()` | `string` | 資料表前綴（`$table_prefix` 變數，預設 `wp_`） |
| `buildDsn()` | `string` | 建立 PDO DSN 字串（支援 port 與 Unix socket） |

---

## 進階用法

### 同時替換 CDN 網域

使用 `--extra-old` / `--extra-new` 替換額外的域名：

```bash
php replace.php \
  --old-url=https://old.com \
  --new-url=https://new.com \
  --sql=dump.sql \
  --extra-old=https://old-cdn.com \
  --extra-new=https://new-cdn.com \
  --extra-old=https://old-assets.com \
  --extra-new=https://new-assets.com
```

### 搭配 Page Builder 的完整替換

啟用所有支援的 Page Builder：

```bash
php replace.php \
  --old-url=https://old.com \
  --new-url=https://new.com \
  --sql=dump.sql \
  --visual-composer \
  --oxygen-builder \
  --betheme-avada
```

### 替換實體路徑

當伺服器路徑也發生變化時：

```bash
php replace.php \
  --old-url=https://old.com \
  --new-url=https://new.com \
  --sql=dump.sql \
  --old-path=/home/olduser/public_html/wp-content \
  --new-path=/var/www/html/wp-content
```

### 停用 Email 域名替換

若不希望替換 Email 地址中的域名（例如管理員 Email 要保留舊域名）：

```bash
php replace.php \
  --old-url=https://old.com \
  --new-url=https://new.com \
  --sql=dump.sql \
  --no-email-replace
```

### 典型遷移流程（SQL 檔案模式）

```bash
# 1. 在舊站匯出資料庫
mysqldump -u root -p old_db > dump.sql

# 2. 執行域名替換
php replace.php \
  --old-url=https://old.com \
  --new-url=https://new.com \
  --sql=dump.sql

# 3. 將替換後的 SQL 匯入新站
mysql -u root -p new_db < dump.sql

# 4. 將檔案同步至新站並更新 wp-config.php
```

---

## 注意事項

### 操作前備份

- **SQL 檔案模式**：腳本會直接覆寫原始 SQL 檔案，操作前請先備份
- **直連模式**：雖有 Transaction 保護，仍強烈建議先備份資料庫

### URL 格式

- `--old-url` 與 `--new-url` 必須為完整 URL（包含 `http://` 或 `https://`）
- 腳本會自動處理末尾斜線、www 有無、HTTP/HTTPS 等各種組合

### `--sql` 與 `--wp-root` 互斥

兩個模式不可同時使用，請擇一：

```bash
# 錯誤用法
php replace.php --old-url=... --new-url=... --sql=dump.sql --wp-root=/var/www/html

# 正確：選擇其中一種模式
php replace.php --old-url=... --new-url=... --sql=dump.sql
php replace.php --old-url=... --new-url=... --wp-root=/var/www/html
```

### `--extra-old` 與 `--extra-new` 必須成對

兩者數量必須完全相同，一一對應：

```bash
# 正確：2 組對應
php replace.php ... --extra-old=a --extra-new=A --extra-old=b --extra-new=B

# 錯誤：數量不對應
php replace.php ... --extra-old=a --extra-new=A --extra-old=b
```

### WordPress Multisite 支援

本工具同時處理 WordPress Multisite 的 `domain`/`path` 欄位（raw 格式），支援多站網路的域名遷移。

---

## 參考來源

本工具的核心替換邏輯參考自 All-in-One WP Migration 插件的以下檔案：

- `class-ai1wm-database-utility.php`（`replace_serialized_values`）
- `class-ai1wm-database.php`（逐行串流替換）
