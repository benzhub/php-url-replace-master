<?php

declare(strict_types=1);

namespace WpMigrate\Src;

use PDO;
use PDOException;
use RuntimeException;

/**
 * 直連 MySQL 域名替換器
 *
 * 流程：
 *   1. 以 PDO 連線至 MySQL
 *   2. 逐資料表執行序列化安全替換（per-table transaction）
 *   3. 每張表：BEGIN → 逐 chunk 讀取 → 替換 → UPDATE → COMMIT
 *   4. 任何步驟失敗 → ROLLBACK 當前表，繼續下一張表（靜默降級）
 *
 * 效能優化（仿 AI1WM replace_table_values()）：
 *   - 整列所有 text 欄位拼接後先做快速預檢（containsAny）
 *     任何 old value 都不包含時直接跳過，避免不必要的序列化替換呼叫
 *   - chunk 大小可配置（預設 500，可透過 setChunkSize() 或 --chunk-size 調整）
 *   - 記憶體使用監控：每個 chunk 後檢查，超過閾值時自動縮小 chunk
 *   - 每個 chunk 處理完後 unset($rows) 強制釋放
 *
 * 韌性設計：
 *   - per-table transaction：每張表一個 transaction，失敗只影響當前表
 *   - MySQL 斷線重連（偵測 "MySQL server has gone away"，自動重連並重試）
 *   - 連線 keepalive（每隔 keepaliveInterval 列執行 SELECT 1）
 *   - charset 錯誤（1366）：逐欄 fallback，確保其他欄位仍被替換
 */
final class DatabaseReplacer
{
    private PDO $_pdo;

    /** @var string[] */
    private array $_oldValues = [];

    /** @var string[] */
    private array $_newValues = [];

    /** @var string[] */
    private array $_oldRawValues = [];

    /** @var string[] */
    private array $_newRawValues = [];

    private bool $_visualComposer = false;
    private bool $_oxygenBuilder  = false;
    private bool $_bethemeOrAvada = false;

    private string $_tablePrefix = 'wp_';

    /** @var string[] */
    private array $_skipTableSuffixes = [
        // Action Scheduler (WooCommerce / 各外掛共用)
        'actionscheduler_actions',
        'actionscheduler_claims',
        'actionscheduler_groups',
        'actionscheduler_logs',
        // WooCommerce 統計 / 查詢 index（無 URL）
        'wc_download_log',
        'wc_order_coupon_lookup',
        'wc_order_product_lookup',
        'wc_order_stats',
        'wc_order_tax_lookup',
        'wc_product_meta_lookup',
        'wc_rate_limits',
        'wc_reserved_stock',
        'wc_tax_rate_classes',
        // WooCommerce sessions（row-level 已略過，table-level 加速）
        'wc_sessions',
        'woocommerce_sessions',
        // WooCommerce webhooks（含 delivery_url，但不需跟站點 URL 替換）
        'wc_webhooks',
        // WP Security Audit Log（純 log，不影響網站功能）
        'wsal_metadata',
        'wsal_occurrences',
    ];

    private readonly SerializedReplacer $_serializedReplacer;

    /** @var array<string, int> */
    private array $_stats = [];

    /**
     * chunk 大小（每批讀取列數）
     */
    private int $_chunkSize = 500;

    /**
     * 記憶體使用閾值（bytes）：超過此值時自動縮小 chunk
     * 預設 768 MB
     */
    private int $_memoryThreshold = 768 * 1024 * 1024;

    /**
     * keepalive 間隔（每隔多少列執行 SELECT 1）
     */
    private int $_keepaliveInterval = 5000;

    /**
     * 最大重連嘗試次數
     */
    private int $_maxReconnectAttempts = 3;

    /**
     * PDO 連線設定（用於重連）
     */
    private string $_dsn    = '';
    private string $_dbUser = '';
    private string $_dbPass = '';
    private array  $_pdoOptions = [];

    /**
     * 已處理的總列數（用於 keepalive）
     */
    private int $_totalProcessed = 0;

    public function __construct(private readonly WpConfigReader $config)
    {
        $this->_serializedReplacer = new SerializedReplacer();
    }

    // -------------------------------------------------------------------------
    // 設定方法（鏈式呼叫）
    // -------------------------------------------------------------------------

    /** @param string[] $old */
    public function setOldValues(array $old): static
    {
        $this->_oldValues = $old;
        return $this;
    }

    /** @param string[] $new */
    public function setNewValues(array $new): static
    {
        $this->_newValues = $new;
        return $this;
    }

    /** @param string[] $old */
    public function setOldRawValues(array $old): static
    {
        $this->_oldRawValues = $old;
        return $this;
    }

    /** @param string[] $new */
    public function setNewRawValues(array $new): static
    {
        $this->_newRawValues = $new;
        return $this;
    }

    public function setVisualComposer(bool $active): static
    {
        $this->_visualComposer = $active;
        return $this;
    }

    public function setOxygenBuilder(bool $active): static
    {
        $this->_oxygenBuilder = $active;
        return $this;
    }

    public function setBethemeOrAvada(bool $active): static
    {
        $this->_bethemeOrAvada = $active;
        return $this;
    }

    public function setTablePrefix(string $prefix): static
    {
        $this->_tablePrefix = $prefix;
        return $this;
    }

    /**
     * 設定每批讀取列數（chunk size）
     */
    public function setChunkSize(int $size): static
    {
        $this->_chunkSize = max(1, $size);
        return $this;
    }

    /**
     * 設定記憶體使用閾值（bytes），超過時自動縮小 chunk
     */
    public function setMemoryThreshold(int $bytes): static
    {
        $this->_memoryThreshold = $bytes;
        return $this;
    }

    /**
     * 追加額外要略過的資料表後綴（不含前綴）
     *
     * @param string[] $suffixes
     */
    public function addSkipTableSuffixes(array $suffixes): static
    {
        $this->_skipTableSuffixes = array_unique(
            array_merge($this->_skipTableSuffixes, $suffixes)
        );
        return $this;
    }

    // -------------------------------------------------------------------------
    // 核心執行
    // -------------------------------------------------------------------------

    /**
     * 執行資料庫直連替換（per-table transaction）
     *
     * @throws RuntimeException 連線失敗時拋出
     */
    public function process(): void
    {
        $this->_connect();

        $tables = $this->_fetchWordPressTables();

        if (empty($tables)) {
            throw new RuntimeException(
                "資料庫中找不到前綴為「{$this->_tablePrefix}」的 WordPress 資料表，"
                . '請確認 --table-prefix 設定是否正確。'
            );
        }

        echo '[資訊] 發現 ' . count($tables) . " 個 WordPress 資料表\n";

        foreach ($tables as $table) {
            if ($this->_shouldSkipTable($table)) {
                echo "[略過] {$table}（在略過清單中，跳過）\n";
                continue;
            }
            $this->_processTableWithTransaction($table);
        }
    }

    /** @return array<string, int> */
    public function getStats(): array
    {
        return $this->_stats;
    }

    // -------------------------------------------------------------------------
    // 內部工具
    // -------------------------------------------------------------------------

    /**
     * 建立 PDO 連線並儲存連線參數（用於重連）
     */
    private function _connect(): void
    {
        if (!extension_loaded('pdo_mysql')) {
            throw new RuntimeException(
                'PHP 擴充 pdo_mysql 未載入。請執行：docker-php-ext-install pdo_mysql 或 apt-get install php-mysql'
            );
        }

        $this->_dsn  = $this->config->buildDsn();
        $this->_dbUser = $this->config->getDbUser();
        $this->_dbPass = $this->config->getDbPassword();

        $this->_pdoOptions = [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ];

        if (defined('PDO::MYSQL_ATTR_INIT_COMMAND')) {
            $this->_pdoOptions[PDO::MYSQL_ATTR_INIT_COMMAND] = "SET NAMES '" . $this->config->getDbCharset() . "'";
        }

        $this->_pdo = $this->_createPdo();

        // 若 pdo_mysql 常數不可用，改用 SET NAMES 指令
        if (!defined('PDO::MYSQL_ATTR_INIT_COMMAND')) {
            $charset = $this->config->getDbCharset() ?: 'utf8';
            $this->_pdo->exec("SET NAMES '{$charset}'");
        }

        echo '[連線] 成功連線至資料庫：' . $this->config->getDbName() . "\n";
    }

    /**
     * 建立新的 PDO 實例
     */
    private function _createPdo(): PDO
    {
        try {
            return new PDO($this->_dsn, $this->_dbUser, $this->_dbPass, $this->_pdoOptions);
        } catch (PDOException $e) {
            throw new RuntimeException('MySQL 連線失敗：' . $e->getMessage(), previous: $e);
        }
    }

    /**
     * MySQL 斷線重連（仿 AI1WM 的 2006 error 重連機制）
     *
     * 偵測 "MySQL server has gone away" 等斷線情況，自動重連並重試
     */
    private function _reconnect(): void
    {
        echo "\n[警告] MySQL 連線中斷，嘗試重連...\n";

        $attempts = 0;
        while ($attempts < $this->_maxReconnectAttempts) {
            $attempts++;
            try {
                $this->_pdo = $this->_createPdo();
                if (!defined('PDO::MYSQL_ATTR_INIT_COMMAND')) {
                    $charset = $this->config->getDbCharset() ?: 'utf8';
                    $this->_pdo->exec("SET NAMES '{$charset}'");
                }
                echo "[重連] 成功重連至資料庫（第 {$attempts} 次）\n";
                return;
            } catch (RuntimeException) {
                if ($attempts < $this->_maxReconnectAttempts) {
                    sleep(1);
                }
            }
        }

        throw new RuntimeException("MySQL 重連失敗，已嘗試 {$attempts} 次");
    }

    /**
     * keepalive：定期執行 SELECT 1 保持連線
     */
    private function _keepalive(): void
    {
        try {
            $this->_pdo->query('SELECT 1');
        } catch (PDOException) {
            $this->_reconnect();
        }
    }

    /**
     * 偵測 PDOException 是否為連線中斷錯誤
     */
    private function _isConnectionLostError(PDOException $e): bool
    {
        $message = $e->getMessage();
        return str_contains($message, 'MySQL server has gone away')
            || str_contains($message, 'Lost connection to MySQL server')
            || str_contains($message, 'SQLSTATE[HY000] [2006]')
            || $e->getCode() === '2006';
    }

    /**
     * 對單一資料表執行替換（per-table transaction）
     *
     * 失敗時 ROLLBACK 當前表，繼續下一張表（靜默降級）
     */
    private function _processTableWithTransaction(string $table): void
    {
        try {
            $this->_pdo->beginTransaction();
            $this->_processTable($table);
            $this->_pdo->commit();
        } catch (\Throwable $e) {
            if ($this->_pdo->inTransaction()) {
                $this->_pdo->rollBack();
            }
            echo "\n[錯誤] {$table} 替換失敗，已 ROLLBACK：" . $e->getMessage() . "\n";
        }
    }

    /**
     * 取得所有符合前綴的 WordPress 資料表名稱
     *
     * @return string[]
     */
    private function _fetchWordPressTables(): array
    {
        $stmt    = $this->_pdo->prepare('SHOW TABLES LIKE :pattern');
        $pattern = addcslashes($this->_tablePrefix, '%_') . '%';
        $stmt->execute(['pattern' => $pattern]);

        return $stmt->fetchAll(PDO::FETCH_COLUMN);
    }

    /**
     * 處理單一資料表：分頁讀取、快速預檢、替換、寫回
     *
     * 效能優化：
     *   - 整列所有 text 欄位拼接後先做 containsAny 快速預檢
     *   - 只有包含 old value 的列才執行完整替換流程
     *   - 記憶體超過閾值時自動縮小 chunk size
     *
     * @param string $table 資料表名稱
     */
    private function _processTable(string $table): void
    {
        $text_columns = $this->_fetchTextColumns($table);

        if (empty($text_columns)) {
            return;
        }

        $primary_key = $this->_fetchPrimaryKey($table);

        if ($primary_key === null) {
            echo "[略過] {$table}（無主鍵，跳過）\n";
            return;
        }

        $col_list = implode(
            ', ',
            array_map(fn($c) => "`{$c}`", [...$text_columns, $primary_key])
        );

        // 取得總列數，用於進度顯示
        $count_stmt = $this->_pdo->prepare("SELECT COUNT(*) FROM `{$table}`");
        $count_stmt->execute();
        $total_rows    = (int) $count_stmt->fetchColumn();
        $show_progress = $total_rows >= 1000;

        $chunk_size    = $this->_chunkSize;
        $offset        = 0;
        $updated_count = 0;

        do {
            // keepalive 保持連線
            if ($this->_totalProcessed > 0 && $this->_totalProcessed % $this->_keepaliveInterval === 0) {
                $this->_keepalive();
            }

            // 記憶體監控：超過閾值時縮小 chunk
            if (memory_get_usage(true) > $this->_memoryThreshold) {
                $chunk_size = max(50, (int) ($chunk_size / 2));
                if ($show_progress) {
                    echo "\n[記憶體] 使用量超過閾值，chunk size 縮小至 {$chunk_size}\n";
                }
            }

            $stmt = $this->_pdo->prepare(
                "SELECT {$col_list} FROM `{$table}`"
                . " ORDER BY `{$primary_key}`"
                . " LIMIT {$chunk_size} OFFSET {$offset}"
            );

            try {
                $stmt->execute();
            } catch (PDOException $e) {
                if ($this->_isConnectionLostError($e)) {
                    $this->_reconnect();
                    $stmt->execute();
                } else {
                    throw $e;
                }
            }

            $rows = $stmt->fetchAll();

            if (empty($rows)) {
                break;
            }

            foreach ($rows as $row) {
                $pk_value = $row[$primary_key];
                $changed  = false;
                $updates  = [];

                // 快速預檢：將所有 text 欄位值拼接後檢查是否含任何 old value
                // 仿 AI1WM replace_table_values() 的 strpos 預檢機制
                $concatenated = implode(' ', array_filter(
                    array_map(fn($col) => (string) ($row[$col] ?? ''), $text_columns),
                    fn($v) => $v !== ''
                ));

                if (!SerializedReplacer::containsAny($concatenated, $this->_oldValues)
                    && !SerializedReplacer::containsAny($concatenated, $this->_oldRawValues)
                ) {
                    $this->_totalProcessed++;
                    continue;
                }

                foreach ($text_columns as $col) {
                    $original = $row[$col] ?? '';

                    if ($original === '' || $original === null) {
                        continue;
                    }

                    if ($this->_shouldIgnoreRow($table, $col, $row)) {
                        continue;
                    }

                    $replaced = $this->_replaceValue((string) $original);

                    if ($replaced !== $original) {
                        $updates[$col] = $replaced;
                        $changed       = true;
                    }
                }

                $this->_totalProcessed++;

                if (!$changed) {
                    continue;
                }

                $set_parts = [];
                $params    = [];

                foreach ($updates as $col => $value) {
                    $set_parts[] = "`{$col}` = :{$col}";
                    $params[":{$col}"] = $value;
                }

                $params[':pk'] = $pk_value;
                $update_sql    = "UPDATE `{$table}` SET "
                    . implode(', ', $set_parts)
                    . " WHERE `{$primary_key}` = :pk";

                $update_stmt = $this->_pdo->prepare($update_sql);

                try {
                    $update_stmt->execute($params);
                    $updated_count++;
                } catch (PDOException $e) {
                    if ($this->_isConnectionLostError($e)) {
                        $this->_reconnect();
                        $update_stmt->execute($params);
                        $updated_count++;
                    } elseif (count($updates) > 1 && $this->_isCharsetError($e)) {
                        $partial_updated = $this->_updateColumnsOneByOne(
                            $table,
                            $primary_key,
                            $pk_value,
                            $updates
                        );
                        if ($partial_updated) {
                            $updated_count++;
                        }
                    } elseif ($this->_isCharsetError($e)) {
                        echo "\n[略過] {$table} pk={$pk_value}：欄位 charset 不支援 4-byte 字元（emoji），跳過此列\n";
                    } else {
                        throw $e;
                    }
                }
            }

            unset($rows); // 強制釋放記憶體

            $offset += $chunk_size;

            // 大表進度顯示
            if ($show_progress) {
                $scanned = min($offset, $total_rows);
                $percent = $total_rows > 0 ? round($scanned / $total_rows * 100, 1) : 100.0;
                echo "\r[進度] {$table}：已掃描 {$scanned} / {$total_rows}（{$percent}%），已替換 {$updated_count} 列";
            }

        } while (isset($chunk_size) && $offset < $total_rows + $chunk_size);

        if ($show_progress) {
            echo "\n";
        }

        $this->_stats[$table] = $updated_count;

        if ($updated_count > 0) {
            echo "[更新] {$table}：{$updated_count} 列已替換\n";
        } else {
            echo "[略過] {$table}：無需替換\n";
        }
    }

    /**
     * 對單一欄位值執行全套替換（序列化安全 + raw）
     */
    private function _replaceValue(string $value): string
    {
        $value = $this->_serializedReplacer->replaceFieldValue(
            $value,
            $this->_oldValues,
            $this->_newValues,
            $this->_visualComposer,
            $this->_oxygenBuilder,
            $this->_bethemeOrAvada,
        );

        if (!empty($this->_oldRawValues)) {
            $value = SerializedReplacer::replaceValues(
                $this->_oldRawValues,
                $this->_newRawValues,
                $value
            );
        }

        return $value;
    }

    /**
     * 判斷此資料表是否在略過清單中
     */
    private function _shouldSkipTable(string $table): bool
    {
        $prefix = $this->_tablePrefix;
        foreach ($this->_skipTableSuffixes as $suffix) {
            if ($table === $prefix . $suffix) {
                return true;
            }
        }
        return false;
    }

    /**
     * 判斷此列是否應跳過替換（快取型資料）
     *
     * @param array<string, mixed> $row
     */
    private function _shouldIgnoreRow(string $table, string $col, array $row): bool
    {
        if (str_ends_with($table, '_options') && isset($row['option_name'])) {
            $name = (string) $row['option_name'];
            if (
                str_starts_with($name, '_transient_')
                || str_starts_with($name, '_site_transient_')
                || str_starts_with($name, '_wc_session_')
                || str_starts_with($name, '_wpallimport_session_')
            ) {
                return true;
            }
        }

        return false;
    }

    /**
     * 取得資料表的 TEXT 類型欄位清單
     *
     * @return string[]
     */
    private function _fetchTextColumns(string $table): array
    {
        $stmt = $this->_pdo->prepare("SHOW COLUMNS FROM `{$table}`");
        $stmt->execute();
        $columns = $stmt->fetchAll();

        $text_types = [
            'text', 'mediumtext', 'longtext', 'tinytext',
            'varchar', 'char', 'blob', 'mediumblob', 'longblob',
        ];

        return array_values(
            array_filter(
                array_map(fn($col) => $col['Field'], $columns),
                function (string $field) use ($columns, $text_types) {
                    $col_def = array_values(
                        array_filter($columns, fn($c) => $c['Field'] === $field)
                    )[0] ?? null;

                    if ($col_def === null) {
                        return false;
                    }

                    $type = strtolower(
                        preg_replace('/\(.*\)/', '', $col_def['Type']) ?? ''
                    );

                    return in_array($type, $text_types, true);
                }
            )
        );
    }

    /**
     * 偵測 PDOException 是否為 charset/encoding 相容性錯誤
     * MySQL 1366 - Incorrect string value（utf8 欄位寫入 4-byte emoji）
     */
    private function _isCharsetError(PDOException $e): bool
    {
        $code    = (string) $e->getCode();
        $message = $e->getMessage();

        return $code === 'HY000'
            && (
                str_contains($message, '1366')
                || str_contains($message, 'Incorrect string value')
            );
    }

    /**
     * 逐欄位嘗試更新，跳過 charset 不相容的欄位
     *
     * @param array<string, string> $updates
     */
    private function _updateColumnsOneByOne(
        string $table,
        string $primary_key,
        mixed $pk_value,
        array $updates
    ): bool {
        $any_updated = false;

        foreach ($updates as $col => $value) {
            $sql  = "UPDATE `{$table}` SET `{$col}` = :val WHERE `{$primary_key}` = :pk";
            $stmt = $this->_pdo->prepare($sql);

            try {
                $stmt->execute([':val' => $value, ':pk' => $pk_value]);
                $any_updated = true;
            } catch (PDOException $e) {
                if ($this->_isCharsetError($e)) {
                    echo "\n[略過] {$table}.{$col} pk={$pk_value}：欄位 charset 不支援 4-byte 字元（emoji），跳過此欄位\n";
                } else {
                    throw $e;
                }
            }
        }

        return $any_updated;
    }

    /**
     * 取得資料表的主鍵欄位名稱
     * 優先使用 PRIMARY KEY，無主鍵時 fallback 使用第一個欄位
     */
    private function _fetchPrimaryKey(string $table): ?string
    {
        $stmt = $this->_pdo->prepare("SHOW KEYS FROM `{$table}` WHERE Key_name = 'PRIMARY'");
        $stmt->execute();
        $row = $stmt->fetch();

        if ($row !== false) {
            return $row['Column_name'];
        }

        $stmt = $this->_pdo->prepare("SHOW COLUMNS FROM `{$table}`");
        $stmt->execute();
        $first_col = $stmt->fetch();

        return $first_col ? $first_col['Field'] : null;
    }
}
