<?php
/**
 * 直連 MySQL 域名替換器
 *
 * @category WpMigrate
 * @package  WpMigrate\Src
 * @author   WpMigrate
 * @license  GPL-2.0+
 * @link     https://github.com/wp-migrate
 */

declare(strict_types=1);

namespace WpMigrate\Src;

use PDO;
use PDOException;
use RuntimeException;

/**
 * 直連 MySQL 的域名替換器
 *
 * 流程：
 *   1. 以 PDO 連線至 MySQL
 *   2. BEGIN TRANSACTION
 *   3. 逐資料表、逐欄位執行序列化安全替換
 *   4. 所有資料表處理完畢 → COMMIT
 *   5. 任何步驟失敗 → ROLLBACK，拋出例外
 *
 * @category WpMigrate
 * @package  WpMigrate\Src
 * @author   WpMigrate
 * @license  GPL-2.0+
 * @link     https://github.com/wp-migrate
 */
final class DatabaseReplacer
{
    /**
     * PDO 連線實例
     *
     * @var PDO
     */
    private PDO $_pdo;

    /**
     * 待替換的舊值清單（序列化安全）
     *
     * @var string[]
     */
    private array $_oldValues = array();

    /**
     * 對應的新值清單（序列化安全）
     *
     * @var string[]
     */
    private array $_newValues = array();

    /**
     * 待替換的舊 raw 值清單（直接字串替換）
     *
     * @var string[]
     */
    private array $_oldRawValues = array();

    /**
     * 對應的新 raw 值清單
     *
     * @var string[]
     */
    private array $_newRawValues = array();

    /**
     * 是否啟用 Visual Composer Base64 替換
     *
     * @var bool
     */
    private bool $_visualComposer = false;

    /**
     * 是否啟用 Oxygen Builder Base64 替換
     *
     * @var bool
     */
    private bool $_oxygenBuilder = false;

    /**
     * 是否啟用 BeTheme/Avada Base64 替換
     *
     * @var bool
     */
    private bool $_bethemeOrAvada = false;

    /**
     * WordPress 資料表前綴
     *
     * @var string
     */
    private string $_tablePrefix = 'wp_';

    /**
     * 序列化替換器
     *
     * @var SerializedReplacer
     */
    private readonly SerializedReplacer $_serializedReplacer;

    /**
     * 各資料表替換列數統計
     *
     * @var array<string, int>
     */
    private array $_stats = array();

    /**
     * 建構子
     *
     * @param WpConfigReader $config wp-config.php 解析結果
     */
    public function __construct( private readonly WpConfigReader $config )
    {
        $this->_serializedReplacer = new SerializedReplacer();
    }

    // -------------------------------------------------------------------------
    // 設定方法（鏈式呼叫）
    // -------------------------------------------------------------------------

    /**
     * 設定舊值清單（序列化安全）
     *
     * @param string[] $old 舊值陣列
     *
     * @return static
     */
    public function setOldValues( array $old ): static
    {
        $this->_oldValues = $old;
        return $this;
    }

    /**
     * 設定新值清單（序列化安全）
     *
     * @param string[] $new 新值陣列
     *
     * @return static
     */
    public function setNewValues( array $new ): static
    {
        $this->_newValues = $new;
        return $this;
    }

    /**
     * 設定舊 raw 值清單
     *
     * @param string[] $old 舊 raw 值陣列
     *
     * @return static
     */
    public function setOldRawValues( array $old ): static
    {
        $this->_oldRawValues = $old;
        return $this;
    }

    /**
     * 設定新 raw 值清單
     *
     * @param string[] $new 新 raw 值陣列
     *
     * @return static
     */
    public function setNewRawValues( array $new ): static
    {
        $this->_newRawValues = $new;
        return $this;
    }

    /**
     * 設定是否啟用 Visual Composer Base64 替換
     *
     * @param bool $active 是否啟用
     *
     * @return static
     */
    public function setVisualComposer( bool $active ): static
    {
        $this->_visualComposer = $active;
        return $this;
    }

    /**
     * 設定是否啟用 Oxygen Builder Base64 替換
     *
     * @param bool $active 是否啟用
     *
     * @return static
     */
    public function setOxygenBuilder( bool $active ): static
    {
        $this->_oxygenBuilder = $active;
        return $this;
    }

    /**
     * 設定是否啟用 BeTheme/Avada Base64 替換
     *
     * @param bool $active 是否啟用
     *
     * @return static
     */
    public function setBethemeOrAvada( bool $active ): static
    {
        $this->_bethemeOrAvada = $active;
        return $this;
    }

    /**
     * 設定資料表前綴
     *
     * @param string $prefix 前綴字串，例：wp_
     *
     * @return static
     */
    public function setTablePrefix( string $prefix ): static
    {
        $this->_tablePrefix = $prefix;
        return $this;
    }

    // -------------------------------------------------------------------------
    // 核心執行
    // -------------------------------------------------------------------------

    /**
     * 執行資料庫直連替換（含 transaction + rollback）
     *
     * @throws RuntimeException 連線失敗或替換過程發生錯誤
     *
     * @return void
     */
    public function process(): void
    {
        $this->_connect();

        $tables = $this->_fetchWordPressTables();

        if ( empty( $tables ) ) {
            throw new RuntimeException(
                "資料庫中找不到前綴為「{$this->_tablePrefix}」的 WordPress 資料表，"
                . '請確認 --table-prefix 設定是否正確。'
            );
        }

        echo '[資訊] 發現 ' . count( $tables ) . " 個 WordPress 資料表\n";

        $this->_pdo->beginTransaction();

        try {
            foreach ( $tables as $table ) {
                $this->_processTable( $table );
            }

            $this->_pdo->commit();
        } catch ( \Throwable $e ) {
            $this->_pdo->rollBack();
            throw new RuntimeException(
                '替換失敗，已執行 ROLLBACK：' . $e->getMessage(),
                previous: $e
            );
        }
    }

    /**
     * 回傳各資料表替換統計
     *
     * @return array<string, int>
     */
    public function getStats(): array
    {
        return $this->_stats;
    }

    // -------------------------------------------------------------------------
    // 內部工具
    // -------------------------------------------------------------------------

    /**
     * 建立 PDO 連線
     *
     * @throws RuntimeException 連線失敗時拋出
     *
     * @return void
     */
    private function _connect(): void
    {
        if ( ! extension_loaded( 'pdo_mysql' ) ) {
            throw new RuntimeException(
                'PHP 擴充 pdo_mysql 未載入。請執行：docker-php-ext-install pdo_mysql 或 apt-get install php-mysql'
            );
        }

        $dsn = $this->config->buildDsn();

        $options = array(
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        );

        // PDO::MYSQL_ATTR_INIT_COMMAND 僅在 pdo_mysql 已載入時才存在
        if ( defined( 'PDO::MYSQL_ATTR_INIT_COMMAND' ) ) {
            $options[ PDO::MYSQL_ATTR_INIT_COMMAND ] = "SET NAMES '" . $this->config->getDbCharset() . "'";
        }

        $charset_via_sql = ! defined( 'PDO::MYSQL_ATTR_INIT_COMMAND' );

        try {
            $this->_pdo = new PDO(
                $dsn,
                $this->config->getDbUser(),
                $this->config->getDbPassword(),
                $options
            );

            // 若 pdo_mysql 常數不可用，改用 SET NAMES 指令設定字元集
            if ( $charset_via_sql ) {
                $charset = $this->config->getDbCharset() ?: 'utf8';
                $this->_pdo->exec( "SET NAMES '{$charset}'" );
            }
        } catch ( PDOException $e ) {
            throw new RuntimeException( 'MySQL 連線失敗：' . $e->getMessage(), previous: $e );
        }

        echo '[連線] 成功連線至資料庫：' . $this->config->getDbName() . "\n";
    }

    /**
     * 取得所有符合前綴的 WordPress 資料表名稱
     *
     * @return string[]
     */
    private function _fetchWordPressTables(): array
    {
        $stmt    = $this->_pdo->prepare( 'SHOW TABLES LIKE :pattern' );
        $pattern = addcslashes( $this->_tablePrefix, '%_' ) . '%';
        $stmt->execute( array( 'pattern' => $pattern ) );

        return $stmt->fetchAll( PDO::FETCH_COLUMN );
    }

    /**
     * 處理單一資料表：分頁讀取、替換、寫回，避免大表 OOM
     *
     * 使用 LIMIT/OFFSET 分頁替代 fetchAll()，每批最多 CHUNK_SIZE 列，
     * 確保在記憶體受限的容器（如 512Mi）中也能處理大型 wp_postmeta。
     *
     * @param string $table 資料表名稱
     *
     * @return void
     */
    private function _processTable( string $table ): void
    {
        $text_columns = $this->_fetchTextColumns( $table );

        if ( empty( $text_columns ) ) {
            return;
        }

        $primary_key = $this->_fetchPrimaryKey( $table );

        if ( $primary_key === null ) {
            echo "[略過] {$table}（無主鍵，跳過）\n";
            return;
        }

        $col_list = implode(
            ', ',
            array_map( fn( $c ) => "`{$c}`", array( ...$text_columns, $primary_key ) )
        );

        $chunk_size    = 500;
        $offset        = 0;
        $updated_count = 0;

        do {
            $stmt = $this->_pdo->prepare(
                "SELECT {$col_list} FROM `{$table}`"
                . " ORDER BY `{$primary_key}`"
                . " LIMIT {$chunk_size} OFFSET {$offset}"
            );
            $stmt->execute();
            $rows = $stmt->fetchAll();

            if ( empty( $rows ) ) {
                break;
            }

            foreach ( $rows as $row ) {
                $pk_value = $row[ $primary_key ];
                $changed  = false;
                $updates  = array();

                foreach ( $text_columns as $col ) {
                    $original = $row[ $col ] ?? '';

                    if ( $original === '' || $original === null ) {
                        continue;
                    }

                    if ( $this->_shouldIgnoreRow( $table, $col, $row ) ) {
                        continue;
                    }

                    $replaced = $this->_replaceValue( (string) $original );

                    if ( $replaced !== $original ) {
                        $updates[ $col ] = $replaced;
                        $changed         = true;
                    }
                }

                if ( ! $changed ) {
                    continue;
                }

                $set_parts = array();
                $params    = array();

                foreach ( $updates as $col => $value ) {
                    $set_parts[] = "`{$col}` = :{$col}";
                    $params[ ":{$col}" ] = $value;
                }

                $params[':pk'] = $pk_value;
                $update_sql    = "UPDATE `{$table}` SET "
                    . implode( ', ', $set_parts )
                    . " WHERE `{$primary_key}` = :pk";

                $update_stmt = $this->_pdo->prepare( $update_sql );
                $update_stmt->execute( $params );
                $updated_count++;
            }

            $offset += $chunk_size;

        } while ( count( $rows ) === $chunk_size );

        $this->_stats[ $table ] = $updated_count;

        if ( $updated_count > 0 ) {
            echo "[更新] {$table}：{$updated_count} 列已替換\n";
        } else {
            echo "[略過] {$table}：無需替換\n";
        }
    }

    /**
     * 對單一欄位值執行全套替換（序列化安全 + raw）
     *
     * @param string $value 原始欄位值
     *
     * @return string
     */
    private function _replaceValue( string $value ): string
    {
        $value = $this->_serializedReplacer->replaceFieldValue(
            $value,
            $this->_oldValues,
            $this->_newValues,
            $this->_visualComposer,
            $this->_oxygenBuilder,
            $this->_bethemeOrAvada,
        );

        if ( ! empty( $this->_oldRawValues ) ) {
            $value = SerializedReplacer::replaceValues(
                $this->_oldRawValues,
                $this->_newRawValues,
                $value
            );
        }

        return $value;
    }

    /**
     * 判斷此列是否應跳過替換（快取型資料）
     *
     * @param string               $table 資料表名稱
     * @param string               $col   欄位名稱
     * @param array<string, mixed> $row   當前列資料
     *
     * @return bool
     */
    private function _shouldIgnoreRow( string $table, string $col, array $row ): bool
    {
        if ( str_ends_with( $table, '_options' ) && isset( $row['option_name'] ) ) {
            $name = (string) $row['option_name'];
            if (
                str_starts_with( $name, '_transient_' )
                || str_starts_with( $name, '_site_transient_' )
                || str_starts_with( $name, '_wc_session_' )
                || str_starts_with( $name, '_wpallimport_session_' )
            ) {
                return true;
            }
        }

        return false;
    }

    /**
     * 取得資料表的 TEXT 類型欄位清單
     *
     * @param string $table 資料表名稱
     *
     * @return string[]
     */
    private function _fetchTextColumns( string $table ): array
    {
        $stmt = $this->_pdo->prepare( "SHOW COLUMNS FROM `{$table}`" );
        $stmt->execute();
        $columns = $stmt->fetchAll();

        $text_types = array(
            'text', 'mediumtext', 'longtext', 'tinytext',
            'varchar', 'char', 'blob', 'mediumblob', 'longblob',
        );

        return array_values(
            array_filter(
                array_map( fn( $col ) => $col['Field'], $columns ),
                function ( string $field ) use ( $columns, $text_types ) {
                    $col_def = array_values(
                        array_filter( $columns, fn( $c ) => $c['Field'] === $field )
                    )[0] ?? null;

                    if ( $col_def === null ) {
                        return false;
                    }

                    $type = strtolower(
                        preg_replace( '/\(.*\)/', '', $col_def['Type'] ) ?? ''
                    );

                    return in_array( $type, $text_types, true );
                }
            )
        );
    }

    /**
     * 取得資料表的主鍵欄位名稱
     *
     * 優先使用 PRIMARY KEY，無主鍵時 fallback 使用第一個欄位。
     *
     * @param string $table 資料表名稱
     *
     * @return string|null
     */
    private function _fetchPrimaryKey( string $table ): ?string
    {
        $stmt = $this->_pdo->prepare( "SHOW KEYS FROM `{$table}` WHERE Key_name = 'PRIMARY'" );
        $stmt->execute();
        $row = $stmt->fetch();

        if ( $row !== false ) {
            return $row['Column_name'];
        }

        $stmt = $this->_pdo->prepare( "SHOW COLUMNS FROM `{$table}`" );
        $stmt->execute();
        $first_col = $stmt->fetch();

        return $first_col ? $first_col['Field'] : null;
    }
}
