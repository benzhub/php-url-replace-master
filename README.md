# php-url-replace

WordPress 站台域名遷移 CLI 工具，仿照 [All-in-One WP Migration](https://wordpress.org/plugins/all-in-one-wp-migration/) 插件的核心替換邏輯實作。

在搬移 WordPress 網站時，將資料庫中所有舊域名批次替換為新域名，正確處理 PHP 序列化格式、多種 URL 編碼變體、Elementor / Bricks / Gutenberg JSON 資料與主流 Page Builder 的 Base64 資料，確保替換後的資料庫完全可用。

**PHP >= 8.1 ｜ 無需 Framework ｜ 支援 GB 級 SQL**

---

## 功能特色

- **序列化安全替換**：自動解序列化 → 替換 → 重新序列化，修正 `s:N:` 長度計數，不會損毀 WordPress 資料
- **JSON 格式支援**：Elementor `_elementor_data`、Bricks Builder、Gutenberg block data 等 JSON 欄位，自動 decode → 遞迴替換 → re-encode，保留原始格式
- **多種 URL 編碼變體**：自動產生 `urlencode`、`rawurlencode`、JSON escape（`addcslashes`）等多種編碼形式，確保完整替換
- **www 互換**：自動處理 `www.old.com` ↔ `old.com`、HTTP ↔ HTTPS 所有組合
- **寬鬆模式**：預設啟用 trailing slash 與跨 scheme 互換（`--no-loose` 可停用）
- **Email 域名替換**：`user@old.com` → `user@new.com`（`--no-email-replace` 可停用）
- **實體路徑替換**：支援 `wp-content` 實體路徑替換
- **Page Builder 支援**：Visual Composer、Oxygen Builder、BeTheme / Avada 的 Base64 資料替換
- **Elementor CSS 磁碟快取替換**：直連模式自動掃描並更新 `wp-content/uploads/elementor/css/*.css`，解決背景圖空白問題
- **資料表前綴替換**：支援 SQL 檔案模式下的資料表前綴變更
- **快取跳過**：自動跳過 `_transient_`、`_site_transient_`、`_wc_session_` 等快取資料
- **資料表略過清單**：內建 ActionScheduler、WooCommerce 統計、Simple History 等無需替換的資料表清單
- **Transaction 保護**：直連 MySQL 模式使用 per-table Transaction，失敗自動 ROLLBACK，不影響其他資料表
- **MySQL 斷線重連**：偵測連線中斷（MySQL server has gone away），自動重連並重試，最多嘗試 3 次
- **記憶體監控**：直連模式每個 chunk 後檢查記憶體，超過閾值自動縮小 chunk size
- **大表進度顯示**：超過 1000 列的資料表顯示即時掃描進度百分比
- **串流處理**：SQL 檔案模式逐行讀取，適合處理大型 SQL 檔案（GB 級）
- **大語句保護**：SQL 模式可設定最大語句大小（`--max-statement-mb`），超過時跳過 regex 替換
- **容器化環境支援**：直連模式支援 `--db-host`/`--db-name`/`--db-user`/`--db-pass` 覆寫 wp-config.php 的 getenv() 設定
- **Docker 自動安裝**：直連模式若 `pdo_mysql` 未載入且偵測到 `docker-php-ext-install`，自動安裝並重新執行

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
git clone https://github.com/your-org/php-url-replace.git
cd php-url-replace
php replace.php --help
```

若要使用 Composer autoload（開發測試用）：

```bash
composer install
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

### 模式一：SQL 檔案

| 參數 | 說明 | 範例 |
|------|------|------|
| `--sql=<path>` | SQL 檔案路徑（`.sql`） | `--sql=dump.sql` |
| `--old-prefix=<prefix>` | 舊資料表前綴（選填） | `--old-prefix=wp_` |
| `--new-prefix=<prefix>` | 新資料表前綴（選填，需與 `--old-prefix` 一起使用） | `--new-prefix=mysite_` |
| `--max-statement-mb=<MB>` | 大語句最大允許大小（MB），超過跳過 regex 替換，預設 `64` | `--max-statement-mb=128` |

### 模式二：直連 MySQL

| 參數 | 說明 | 範例 |
|------|------|------|
| `--wp-root=<path>` | WordPress 根目錄（含 `wp-config.php`） | `--wp-root=/var/www/html` |
| `--table-prefix=<prefix>` | 覆寫資料表前綴（選填，預設從 `wp-config.php` 讀取） | `--table-prefix=wp_` |
| `--chunk-size=<N>` | 每批讀取列數，預設 `500` | `--chunk-size=200` |
| `--memory-threshold-mb=<MB>` | 記憶體監控閾值（MB），超過自動縮小 chunk，預設 `768` | `--memory-threshold-mb=512` |
| `--skip-tables=<suffixes>` | 額外跳過的資料表後綴（逗號分隔，不含前綴） | `--skip-tables=my_log,my_cache` |

### 模式二：DB 連線覆寫（容器化環境）

當 `wp-config.php` 使用 `getenv()` 讀取環境變數，而執行腳本的環境無法取得這些環境變數時，可直接指定連線參數：

| 參數 | 說明 | 範例 |
|------|------|------|
| `--db-host=<host>` | 直接指定 DB 主機（含 port） | `--db-host=mysql-service:3306` |
| `--db-name=<name>` | 直接指定 DB 名稱 | `--db-name=wordpress` |
| `--db-user=<user>` | 直接指定 DB 使用者 | `--db-user=wordpress` |
| `--db-pass=<password>` | 直接指定 DB 密碼 | `--db-pass=secret` |

### 共用選填參數

| 參數 | 說明 | 範例 |
|------|------|------|
| `--old-path=<path>` | 舊站 `wp-content` 實體路徑 | `--old-path=/home/old/wp-content` |
| `--new-path=<path>` | 新站 `wp-content` 實體路徑 | `--new-path=/var/www/wp-content` |
| `--old-uploads-url=<URL>` | 舊站 uploads URL（Multisite 選填） | `--old-uploads-url=https://old.com/files` |
| `--new-uploads-url=<URL>` | 新站 uploads URL（Multisite 選填） | `--new-uploads-url=https://new.com/files` |
| `--no-email-replace` | 停用 Email 域名替換 | `--no-email-replace` |
| `--no-loose` | 停用寬鬆模式（trailing slash + 跨 scheme 互換） | `--no-loose` |
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
    │         www互換 / HTTP→HTTPS / Email / 實體路徑 / uploads URL /
    │         Multisite 屬性格式 ='domain/path）
    │
    ├── [模式一] Replacer
    │       串流讀取 SQL 檔案 → processQuery() → 安全覆寫
    │       └── SerializedReplacer（序列化 / JSON / Base64 安全替換引擎）
    │
    └── [模式二] DatabaseReplacer
            PDO 連線 → 逐表逐列替換 → per-table Transaction COMMIT / ROLLBACK
            → Elementor CSS 磁碟快取替換
            ├── WpConfigReader（解析 wp-config.php，支援 CLI DB 覆寫）
            └── SerializedReplacer（序列化 / JSON / Base64 安全替換引擎）
```

---

## 目錄結構

```
php-url-replace/
├── replace.php                  CLI 入口腳本
├── composer.json
├── phpunit.xml
├── src/
│   ├── UrlVariantBuilder.php    URL 編碼變體產生器
│   ├── SerializedReplacer.php   序列化 / JSON / Base64 安全替換引擎
│   ├── Replacer.php             SQL 檔案替換器
│   ├── DatabaseReplacer.php     直連 MySQL 替換器
│   └── WpConfigReader.php       wp-config.php 解析器
└── tests/
    ├── UrlVariantBuilderTest.php
    ├── SerializedReplacerTest.php
    └── ReplacerTest.php
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
    bool   $replaceEmail   = true,
    string $oldPath        = '',
    string $newPath        = '',
    bool   $looseMode      = true,
    string $oldUploadsUrl  = '',
    string $newUploadsUrl  = '',
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
- **Multisite 屬性格式**：`='domain/path` 與 `="domain/path`（HTML/PHP 屬性中的引號包圍格式）
- **寬鬆模式**（預設啟用）：trailing slash 有無互換、跨 scheme 互換（HTTP ↔ HTTPS）
- **Email**：`@old.com` → `@new.com`（可停用）
- **Raw 格式**：WordPress multisite 的 `'domain','path/'` 欄位格式
- **實體路徑**：若指定 `--old-path`/`--new-path`
- **Uploads URL**：若指定 `--old-uploads-url`/`--new-uploads-url`（Multisite）

---

### `SerializedReplacer`

**命名空間：** `WpMigrate\Src`

WordPress 資料庫大量使用 PHP 序列化格式（`serialize()`）及 JSON 格式儲存資料，普通字串替換會讓序列化的長度計數失效，導致資料損毀。本類別正確處理所有儲存格式。

#### 公開方法

| 方法 | 回傳 | 說明 |
|------|------|------|
| `replaceInSqlLine(string $input, array $oldValues, array $newValues, bool $visualComposer, bool $oxygenBuilder, bool $bethemeOrAvada)` | `string` | 對 SQL 行執行全套替換（Base64 → 序列化 → 字串） |
| `replaceFieldValue(string $input, array $oldValues, array $newValues, bool $visualComposer, bool $oxygenBuilder, bool $bethemeOrAvada)` | `string` | 對資料庫直連模式的原始欄位值執行全套替換（Base64 → JSON → 序列化） |
| `static replaceValues(array $oldValues, array $newValues, string $data)` | `string` | 批次純文字替換（`strtr`） |
| `static replaceSerializedValues(array $from, array $to, mixed $data, bool $serialized)` | `mixed` | 遞迴序列化安全替換 |
| `static replaceInSerializedString(array $from, array $to, string $data)` | `string` | 對序列化字串做精確替換並修正 `s:N:` 長度（substr 策略，非 regex） |
| `static containsAny(string $haystack, array $needles)` | `bool` | 快速預檢：字串是否包含任意一個舊值 |
| `static containsAnyEscaped(string $haystack, array $needles)` | `bool` | 快速預檢（needle 先 MySQL escape 後比對） |
| `static escapeMysql(string $data)` | `string` | MySQL 字串跳脫 |
| `static unescapeMysql(string $data)` | `string` | MySQL 字串反跳脫 |
| `static base64Validate(string $data)` | `bool` | 驗證是否為合法 Base64 字串 |

#### `replaceInSqlLine` vs `replaceFieldValue`

| | `replaceInSqlLine` | `replaceFieldValue` |
|---|---|---|
| 用途 | SQL 模式（`Replacer`） | 直連模式（`DatabaseReplacer`） |
| 輸入 | 整行 SQL 字串（含 `'...'` 引號） | 純欄位值 |
| 序列化 | 以 regex 提取 `'...'` 後再替換 | 直接對欄位值做序列化安全替換 |
| JSON | 不處理 | 偵測 JSON（`isJson()`），遞迴替換後 re-encode |

#### 序列化替換的特殊情境

- **`__PHP_Incomplete_Class`**：CLI 環境下遇到未定義類別（如 WooCommerce `WC_Email_xxx`）時，`unserialize()` 返回 `__PHP_Incomplete_Class`，自動 fallback 使用 `replaceInSerializedString()` 對原始序列化字串做精確替換並修正長度計數
- **大迭代保護**：`replaceInSerializedString` 設有最大迭代次數（100,000 次），防止畸形序列化字串導致無限迴圈
- **執行時間保護**：單一欄位替換超過 5 秒時自動 fallback 為純文字替換

#### 支援的 Page Builder 格式

| Page Builder | 資料格式 |
|---|---|
| Visual Composer | `[vc_raw_html]BASE64[/vc_raw_html]` |
| Oxygen Builder | `\"code-php\":\"BASE64\"` |
| BeTheme / Avada | `'BASE64'` |
| Elementor | `_elementor_data` JSON 欄位（自動偵測） |
| Bricks Builder | JSON 格式欄位（自動偵測） |
| Gutenberg | block data JSON 格式（自動偵測） |

---

### `Replacer`

**命名空間：** `WpMigrate\Src`

讀取 `.sql` 檔案，逐行串流處理。替換完成後，先寫入暫存檔（`.tmp_xxx`），成功才替換原始檔案（atomic write），避免中途失敗損毀原檔。

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
| `setMaxStatementBytes(int $bytes)` | 設定大語句保護閾值（bytes），超過跳過 regex 替換 |
| `process(string $sqlFilePath)` | 核心執行：串流讀取並 atomic 覆寫 SQL 檔案 |
| `processQuery(string $query)` | 對單一 SQL 語句執行替換（可用於單元測試） |

#### 替換順序

1. 資料表前綴替換（`str_ireplace`）
2. 跳過快取型查詢（`_transient_`、`_wc_session_` 等）
3. 大語句保護（超過閾值僅做 raw value 替換）
4. 快速預檢（不含舊值直接跳過）
5. Base64 Page Builder 資料替換
6. 序列化安全替換
7. raw values 純文字替換

---

### `DatabaseReplacer`

**命名空間：** `WpMigrate\Src`

透過 PDO 直接連線至 WordPress 資料庫，逐資料表、逐欄位執行替換，全程使用 per-table Transaction（每張表獨立，失敗只影響當前表，其餘繼續執行）。

#### 建構子

```php
new DatabaseReplacer(WpConfigReader $config)
```

#### 方法（支援鏈式呼叫）

| 方法 | 說明 |
|------|------|
| `setOldValues(array $old)` | 設定舊值清單（序列化安全替換） |
| `setNewValues(array $new)` | 設定新值清單 |
| `setOldRawValues(array $old)` | 設定舊 raw 值清單（純字串替換） |
| `setNewRawValues(array $new)` | 設定新 raw 值清單 |
| `setVisualComposer(bool $active)` | 啟用 Visual Composer Base64 替換 |
| `setOxygenBuilder(bool $active)` | 啟用 Oxygen Builder Base64 替換 |
| `setBethemeOrAvada(bool $active)` | 啟用 BeTheme / Avada Base64 替換 |
| `setTablePrefix(string $prefix)` | 設定 WordPress 資料表前綴 |
| `setChunkSize(int $size)` | 設定每批讀取列數（預設 500） |
| `setMemoryThreshold(int $bytes)` | 設定記憶體監控閾值（bytes），超過自動縮小 chunk |
| `addSkipTableSuffixes(array $suffixes)` | 追加額外要略過的資料表後綴（不含前綴） |
| `process()` | 核心執行：連線 → 取得所有資料表 → 逐表替換 → COMMIT |
| `getStats()` | 回傳各資料表更新列數（`['wp_posts' => 5, ...]`） |

#### 安全機制

- 僅處理文字相關欄位型別（`text`、`mediumtext`、`longtext`、`varchar`、`char`、`blob` 等）
- 自動跳過快取資料（`_transient_`、`_site_transient_`、`_wc_session_`、`_wpallimport_session_`）
- PDO prepared statement 防止 SQL injection
- per-table Transaction，失敗 ROLLBACK 當前表，繼續下一張表

#### 內建資料表略過清單

下列資料表不含網站 URL，固定略過以提高效能：

| 類別 | 資料表後綴 |
|------|------|
| Action Scheduler | `actionscheduler_actions`、`actionscheduler_claims`、`actionscheduler_groups`、`actionscheduler_logs` |
| WooCommerce 統計 | `wc_order_stats`、`wc_order_product_lookup`、`wc_order_tax_lookup`、`wc_product_meta_lookup` 等 |
| WooCommerce Sessions | `wc_sessions`、`woocommerce_sessions` |
| WP Security Audit Log | `wsal_metadata`、`wsal_occurrences` |
| Simple History | `simple_history`、`simple_history_contexts` |

可透過 `addSkipTableSuffixes()` 或 `--skip-tables` 參數追加自訂清單。

#### 韌性設計

| 機制 | 說明 |
|------|------|
| **快速預檢** | 整列所有 text 欄位拼接後先做 `containsAny` 檢查，無舊值直接跳過，避免多餘的序列化替換呼叫 |
| **MySQL 斷線重連** | 偵測「MySQL server has gone away」等斷線錯誤，自動重連並重試，最多 3 次 |
| **keepalive** | 每處理 5,000 列執行一次 `SELECT 1` 保持連線 |
| **記憶體監控** | 每個 chunk 後檢查記憶體用量，超過閾值時自動將 chunk size 縮小一半（最小 50） |
| **charset fallback** | MySQL 1366 charset 錯誤（如 emoji 寫入 utf8 欄位）時改為逐欄位 UPDATE，確保其他欄位仍被替換 |
| **大表進度顯示** | 超過 1,000 列的資料表顯示即時掃描進度（`已掃描 N/Total (X%)`） |

---

### `WpConfigReader`

**命名空間：** `WpMigrate\Src`

解析 WordPress 的 `wp-config.php` 取得資料庫連線設定。採用 PHP `token_get_all()` 進行精確的 token 解析，避免正規表達式誤判多行字串或 heredoc。支援 `getenv()` 動態取值。

#### 建構子

```php
new WpConfigReader(string $wpRoot)
```

#### 方法

| 方法 | 回傳 | 說明 |
|------|------|------|
| `setDbOverrides(string $host, string $name, string $user, string $password)` | `static` | 設定 CLI 覆寫的 DB 連線參數（非空值才覆寫，用於容器化環境） |
| `parse()` | `static` | 解析 wp-config.php，缺少必要常數時拋出 `RuntimeException` |
| `getDbHost()` | `string` | 資料庫主機（`DB_HOST`） |
| `getDbName()` | `string` | 資料庫名稱（`DB_NAME`） |
| `getDbUser()` | `string` | 資料庫使用者（`DB_USER`） |
| `getDbPassword()` | `string` | 資料庫密碼（`DB_PASSWORD`） |
| `getDbCharset()` | `string` | 字元集（`DB_CHARSET`，預設 `utf8`） |
| `getDbCollate()` | `string` | Collation（`DB_COLLATE`） |
| `getTablePrefix()` | `string` | 資料表前綴（`$table_prefix` 變數，預設 `wp_`） |
| `buildDsn()` | `string` | 建立 PDO DSN 字串（支援 TCP port 與 Unix socket） |

#### 容器化環境支援

當 `wp-config.php` 使用 `getenv()` 讀取 DB 連線資訊（如 Kubernetes Pod 的環境變數），但在本機或 CI 執行腳本時無法取得這些環境變數，可透過 `setDbOverrides()` 或 CLI 參數直接指定：

```bash
php replace.php \
  --old-url=https://old.com \
  --new-url=https://new.com \
  --wp-root=/var/www/html \
  --db-host=mysql-service:3306 \
  --db-name=wordpress \
  --db-user=wordpress \
  --db-pass=secret
```

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

### WordPress Multisite 的 Uploads URL 映射

當子站的 uploads URL 與主站不同時，可單獨指定：

```bash
php replace.php \
  --old-url=https://old.com \
  --new-url=https://new.com \
  --sql=dump.sql \
  --old-uploads-url=https://old.com/wp-content/uploads/sites/2 \
  --new-uploads-url=https://new.com/wp-content/uploads/sites/2
```

### 搭配 Page Builder 的完整替換

啟用所有支援的 Page Builder（Elementor 無需額外參數，自動偵測 JSON）：

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

### 停用寬鬆模式

預設會額外替換 trailing slash 有無與跨 scheme 組合（如 `https://old.com/` → `https://new.com/`）。若只想替換完全相符的 URL，可停用：

```bash
php replace.php \
  --old-url=https://old.com \
  --new-url=https://new.com \
  --sql=dump.sql \
  --no-loose
```

### 大型資料庫的效能調整（直連模式）

```bash
php replace.php \
  --old-url=https://old.com \
  --new-url=https://new.com \
  --wp-root=/var/www/html \
  --chunk-size=200 \
  --memory-threshold-mb=512
```

### 跳過特定資料表（直連模式）

```bash
php replace.php \
  --old-url=https://old.com \
  --new-url=https://new.com \
  --wp-root=/var/www/html \
  --skip-tables=my_log,my_import_cache
```

### 容器化 / Kubernetes 環境（直連模式）

```bash
php replace.php \
  --old-url=https://old.com \
  --new-url=https://new.com \
  --wp-root=/var/www/html \
  --db-host=mysql-service:3306 \
  --db-name=wordpress \
  --db-user=wordpress \
  --db-pass=secret
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

## 執行測試

```bash
composer install
composer test
```

---

## 注意事項

### 操作前備份

- **SQL 檔案模式**：腳本會直接覆寫原始 SQL 檔案，操作前請先備份
- **直連模式**：雖有 per-table Transaction 保護，仍強烈建議先備份資料庫

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

### 大語句保護（SQL 模式）

預設超過 64MB 的單一 SQL 語句會跳過 regex 替換（只做純文字 `strtr` 替換），避免 PCRE 記憶體溢出。若資料庫中有超大型語句（如包含大量 base64 圖片），可視情況調整：

```bash
php replace.php ... --max-statement-mb=128
```

### 記憶體與 Chunk（直連模式）

- 預設每批讀取 500 列（`--chunk-size`），記憶體監控閾值 768 MB（`--memory-threshold-mb`）
- 記憶體超過閾值時，系統會自動將 chunk size 縮小一半（最小 50），無需手動介入
- 若遇到 OOM 情況，可主動降低 `--chunk-size` 或提高 `--memory-threshold-mb`

### Elementor CSS 磁碟快取（直連模式）

直連模式執行完資料庫替換後，會自動掃描以下目錄並替換其中所有 `.css` 檔案的 URL：

- `wp-content/uploads/elementor/css/*.css`（單站）
- `wp-content/uploads/sites/{N}/elementor/css/*.css`（Multisite）

這可解決 Elementor 以 `status="file"` 模式快取 CSS 時，背景圖 URL 未被替換導致區塊背景空白的問題。

### WordPress Multisite 支援

本工具同時處理 WordPress Multisite 的 `domain`/`path` 欄位（raw 格式），支援多站網路的域名遷移。

### Docker 環境

直連模式若 `pdo_mysql` 擴充未載入，腳本會偵測 `docker-php-ext-install`，若存在則自動安裝並重新執行腳本。

---

## 參考來源

本工具的核心替換邏輯參考自 All-in-One WP Migration 插件的以下檔案：

- `class-ai1wm-database-utility.php`（`replace_serialized_values`）
- `class-ai1wm-database.php`（逐行串流替換、`replace_table_values`）
- `class-ai1wm-import-database.php`（URL 變體產生、Multisite 屬性格式）
