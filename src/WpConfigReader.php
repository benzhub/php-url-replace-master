<?php
/**
 * WordPress wp-config.php 解析器
 *
 * @category WpMigrate
 * @package  WpMigrate\Src
 * @author   WpMigrate
 * @license  GPL-2.0+
 * @link     https://github.com/wp-migrate
 */

declare(strict_types=1);

namespace WpMigrate\Src;

use RuntimeException;

/**
 * 解析 WordPress wp-config.php，提取資料庫連線設定
 *
 * 採用 token_get_all() 解析 PHP token，準確找出 define() 呼叫的常數值，
 * 避免 regex 誤判多行字串或 heredoc。
 *
 * @category WpMigrate
 * @package  WpMigrate\Src
 * @author   WpMigrate
 * @license  GPL-2.0+
 * @link     https://github.com/wp-migrate
 */
final class WpConfigReader
{
    /**
     * 資料庫主機
     *
     * @var string
     */
    private string $_dbHost = '';

    /**
     * 資料庫名稱
     *
     * @var string
     */
    private string $_dbName = '';

    /**
     * 資料庫使用者
     *
     * @var string
     */
    private string $_dbUser = '';

    /**
     * 資料庫密碼
     *
     * @var string
     */
    private string $_dbPassword = '';

    /**
     * 資料庫字元集
     *
     * @var string
     */
    private string $_dbCharset = 'utf8';

    /**
     * 資料表前綴
     *
     * @var string
     */
    private string $_tablePrefix = 'wp_';

    /**
     * 資料庫 Collation
     *
     * @var string
     */
    private string $_dbCollate = '';

    /**
     * 建構子
     *
     * @param string $wpRoot WordPress 根目錄路徑
     */
    public function __construct( private readonly string $wpRoot ) {}

    /**
     * 解析 wp-config.php
     *
     * @throws RuntimeException 若檔案不存在或缺少必要常數
     *
     * @return static
     */
    public function parse(): static
    {
        $config_path = rtrim( $this->wpRoot, '/' ) . '/wp-config.php';

        if ( ! is_file( $config_path ) ) {
            throw new RuntimeException( "找不到 wp-config.php：{$config_path}" );
        }

        $source = file_get_contents( $config_path );

        if ( $source === false ) {
            throw new RuntimeException( "無法讀取 wp-config.php：{$config_path}" );
        }

        $constants          = $this->_extractDefineConstants( $source );
        $table_prefix       = $this->_extractTablePrefix( $source );

        $this->_dbHost      = $constants['DB_HOST']     ?? '';
        $this->_dbName      = $constants['DB_NAME']     ?? '';
        $this->_dbUser      = $constants['DB_USER']     ?? '';
        $this->_dbPassword  = $constants['DB_PASSWORD'] ?? '';
        $this->_dbCharset   = $constants['DB_CHARSET']  ?? 'utf8';
        $this->_dbCollate   = $constants['DB_COLLATE']  ?? '';
        $this->_tablePrefix = $table_prefix;

        $missing = array();
        foreach ( array( 'DB_HOST', 'DB_NAME', 'DB_USER' ) as $required ) {
            if ( empty( $constants[ $required ] ) ) {
                $missing[] = $required;
            }
        }

        if ( ! empty( $missing ) ) {
            throw new RuntimeException(
                'wp-config.php 缺少必要常數：' . implode( ', ', $missing )
            );
        }

        return $this;
    }

    // -------------------------------------------------------------------------
    // Getter
    // -------------------------------------------------------------------------

    /**
     * 取得資料庫主機
     *
     * @return string
     */
    public function getDbHost(): string
    {
        return $this->_dbHost;
    }

    /**
     * 取得資料庫名稱
     *
     * @return string
     */
    public function getDbName(): string
    {
        return $this->_dbName;
    }

    /**
     * 取得資料庫使用者
     *
     * @return string
     */
    public function getDbUser(): string
    {
        return $this->_dbUser;
    }

    /**
     * 取得資料庫密碼
     *
     * @return string
     */
    public function getDbPassword(): string
    {
        return $this->_dbPassword;
    }

    /**
     * 取得字元集
     *
     * @return string
     */
    public function getDbCharset(): string
    {
        return $this->_dbCharset;
    }

    /**
     * 取得 Collation
     *
     * @return string
     */
    public function getDbCollate(): string
    {
        return $this->_dbCollate;
    }

    /**
     * 取得資料表前綴
     *
     * @return string
     */
    public function getTablePrefix(): string
    {
        return $this->_tablePrefix;
    }

    /**
     * 建立 PDO DSN 字串
     *
     * 支援 DB_HOST 含 port（例：localhost:3306）
     * 及 socket（例：localhost:/var/run/mysqld/mysqld.sock）
     *
     * @return string
     */
    public function buildDsn(): string
    {
        $host    = $this->_dbHost;
        $port    = 3306;
        $socket  = '';
        $charset = $this->_dbCharset ?: 'utf8';

        if ( str_contains( $host, ':' ) ) {
            [ $host, $suffix ] = explode( ':', $host, 2 );
            if ( is_numeric( $suffix ) ) {
                $port = (int) $suffix;
            } else {
                $socket = $suffix;
            }
        }

        if ( $socket !== '' ) {
            return sprintf(
                'mysql:unix_socket=%s;dbname=%s;charset=%s',
                $socket,
                $this->_dbName,
                $charset
            );
        }

        return sprintf(
            'mysql:host=%s;port=%d;dbname=%s;charset=%s',
            $host,
            $port,
            $this->_dbName,
            $charset
        );
    }

    // -------------------------------------------------------------------------
    // 內部解析工具
    // -------------------------------------------------------------------------

    /**
     * 使用 token_get_all() 提取所有 define('CONST_NAME', 'value') 的值
     *
     * @param string $source PHP 原始碼
     *
     * @return array<string, string>
     */
    private function _extractDefineConstants( string $source ): array
    {
        $tokens    = token_get_all( $source );
        $constants = array();
        $count     = count( $tokens );

        for ( $i = 0; $i < $count; $i++ ) {
            if (
                ! is_array( $tokens[ $i ] )
                || $tokens[ $i ][0] !== T_STRING
                || strtolower( $tokens[ $i ][1] ) !== 'define'
            ) {
                continue;
            }

            $j = $i + 1;
            while (
                $j < $count
                && is_array( $tokens[ $j ] )
                && in_array( $tokens[ $j ][0], array( T_WHITESPACE, T_COMMENT, T_DOC_COMMENT ), true )
            ) {
                $j++;
            }

            if ( ! isset( $tokens[ $j ] ) || $tokens[ $j ] !== '(' ) {
                continue;
            }

            $j++;

            while ( $j < $count && is_array( $tokens[ $j ] ) && $tokens[ $j ][0] === T_WHITESPACE ) {
                $j++;
            }

            if (
                ! isset( $tokens[ $j ] )
                || ! is_array( $tokens[ $j ] )
                || $tokens[ $j ][0] !== T_CONSTANT_ENCAPSED_STRING
            ) {
                continue;
            }

            $const_name = trim( $tokens[ $j ][1], '\'"' );
            $j++;

            while (
                $j < $count
                && (
                    ( is_array( $tokens[ $j ] ) && $tokens[ $j ][0] === T_WHITESPACE )
                    || $tokens[ $j ] === ','
                )
            ) {
                $j++;
            }

            if ( ! isset( $tokens[ $j ] ) ) {
                continue;
            }

            $value = $this->_extractTokenValue( $tokens[ $j ] );

            // 若 token 是 T_STRING 且名稱為 getenv，嘗試解析環境變數
            if (
                $value === null
                && is_array( $tokens[ $j ] )
                && $tokens[ $j ][0] === T_STRING
                && strtolower( $tokens[ $j ][1] ) === 'getenv'
            ) {
                $value = $this->_extractGetenvValue( $tokens, $j );
            }

            if ( $value !== null ) {
                $constants[ $const_name ] = $value;
            }
        }

        return $constants;
    }

    /**
     * 提取 $table_prefix 變數的值
     *
     * 支援三種常見寫法：
     *   1. 字面量：$table_prefix = 'wp_';
     *   2. getenv()：$table_prefix = getenv('WP_TABLE_PREFIX');
     *   3. 環境變數（$_ENV / $_SERVER）：無法靜態解析，fallback 'wp_' 並輸出警告
     *
     * @param string $source PHP 原始碼
     *
     * @return string
     */
    private function _extractTablePrefix( string $source ): string
    {
        // 字面量賦值
        if ( preg_match( '/\$table_prefix\s*=\s*[\'"]([a-zA-Z0-9_]+)[\'"]\s*;/', $source, $m ) ) {
            return $m[1];
        }

        // getenv() 賦值：$table_prefix = getenv('ENV_VAR');
        if ( preg_match( '/\$table_prefix\s*=\s*getenv\s*\(\s*[\'"]([^\'"]+)[\'"]\s*\)\s*;/', $source, $m ) ) {
            $env_name = $m[1];
            $value    = getenv( $env_name );

            if ( $value !== false && preg_match( '/^[a-zA-Z0-9_]+$/', $value ) ) {
                return $value;
            }

            fwrite(
                STDERR,
                "[警告] \$table_prefix 使用 getenv('{$env_name}')，但環境變數未設定或格式無效，"
                . "fallback 使用 'wp_'\n"
            );

            return 'wp_';
        }

        // 無法解析（如 $_ENV、條件判斷、變數間接賦值等），輸出警告
        if ( preg_match( '/\$table_prefix\s*=/', $source ) ) {
            fwrite(
                STDERR,
                "[警告] 無法靜態解析 \$table_prefix（可能使用 \$_ENV、\$_SERVER 或條件判斷），"
                . "fallback 使用 'wp_'，請以 --table-prefix 參數手動指定\n"
            );
        }

        return 'wp_';
    }

    /**
     * 從單一 token 提取純文字值（字串或整數）
     *
     * @param mixed $token PHP token
     *
     * @return string|null
     */
    private function _extractTokenValue( mixed $token ): ?string
    {
        if ( ! is_array( $token ) ) {
            return null;
        }

        return match ( $token[0] ) {
            T_CONSTANT_ENCAPSED_STRING => trim( $token[1], '\'"' ),
            T_LNUMBER, T_DNUMBER      => $token[1],
            default                   => null,
        };
    }

    /**
     * 從 token 串流中提取 getenv('ENV_VAR') 呼叫所對應的環境變數值
     *
     * 支援 wp-config.php 中常見的容器化模式：
     *   define('DB_HOST', getenv('WORDPRESS_DB_HOST'));
     *
     * 從當前 getenv token 位置向後找到引號括住的變數名稱，
     * 再呼叫 PHP 原生 getenv() 取得實際環境變數值。
     *
     * @param array<int, mixed> $tokens  全部 token 陣列
     * @param int               $pos     目前 getenv T_STRING token 的位置
     *
     * @return string|null 環境變數值，若解析失敗或環境變數不存在則返回 null
     */
    private function _extractGetenvValue( array $tokens, int $pos ): ?string
    {
        $count = count( $tokens );
        $j     = $pos + 1;

        while ( $j < $count && is_array( $tokens[ $j ] ) && $tokens[ $j ][0] === T_WHITESPACE ) {
            $j++;
        }

        if ( ! isset( $tokens[ $j ] ) || $tokens[ $j ] !== '(' ) {
            return null;
        }

        $j++;

        while ( $j < $count && is_array( $tokens[ $j ] ) && $tokens[ $j ][0] === T_WHITESPACE ) {
            $j++;
        }

        if (
            ! isset( $tokens[ $j ] )
            || ! is_array( $tokens[ $j ] )
            || $tokens[ $j ][0] !== T_CONSTANT_ENCAPSED_STRING
        ) {
            return null;
        }

        $env_name = trim( $tokens[ $j ][1], '\'"' );

        if ( $env_name === '' ) {
            return null;
        }

        $value = getenv( $env_name );

        return $value !== false ? $value : null;
    }
}
