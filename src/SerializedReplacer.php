<?php

declare(strict_types=1);

namespace WpMigrate\Src;

/**
 * 序列化安全替換器
 *
 * 仿照 All-in-One WP Migration 的 Ai1wm_Database_Utility::replace_serialized_values()
 * 遞迴解序列化後替換，再重新序列化（自動修正長度計數）
 * 同時處理 Base64 編碼儲存的 Page Builder 資料
 *
 * 參考：class-ai1wm-database-utility.php + class-ai1wm-database.php
 */
final class SerializedReplacer
{
    /**
     * 對 SQL 行執行所有替換（Base64 → 序列化 → 普通字串）
     *
     * @param string[] $oldValues
     * @param string[] $newValues
     */
    public function replaceInSqlLine(
        string $input,
        array $oldValues,
        array $newValues,
        bool $visualComposer = false,
        bool $oxygenBuilder = false,
        bool $bethemeOrAvada = false,
    ): string {
        // Visual Composer：[vc_raw_html]BASE64[/vc_raw_html]
        if ($visualComposer) {
            $input = preg_replace_callback(
                '/\[vc_raw_html\]([a-zA-Z0-9\/+]+={0,2})\[\/vc_raw_html\]/S',
                fn(array $m) => $this->replaceBase64Callback($m, $oldValues, $newValues, '[vc_raw_html]', '[/vc_raw_html]', false),
                $input
            ) ?? $input;
        }

        // Oxygen Builder：\"code-php\":\"BASE64\"
        if ($oxygenBuilder) {
            $input = preg_replace_callback(
                '/\\\\"(code-php|code-css|code-js)\\\\":\\\\"([a-zA-Z0-9\/+]+={0,2})\\\\"/S',
                fn(array $m) => $this->replaceOxygenCallback($m, $oldValues, $newValues),
                $input
            ) ?? $input;
        }

        // BeTheme / Optimize Press / Avada Fusion Builder：'BASE64'
        if ($bethemeOrAvada) {
            $input = preg_replace_callback(
                "/'([a-zA-Z0-9\/+]+={0,2})'/S",
                fn(array $m) => $this->replaceBase64SerializedCallback($m, $oldValues, $newValues),
                $input
            ) ?? $input;
        }

        // 序列化字串替換（每個 SQL 字串值用 preg_replace_callback 提取後處理）
        $needsReplace = false;
        foreach ($oldValues as $old) {
            if (str_contains($input, self::escapeMysql($old))) {
                $needsReplace = true;
                break;
            }
        }

        if ($needsReplace) {
            $input = preg_replace_callback(
                "/'(.*?)(?<!\\\\)'/S",
                fn(array $m) => $this->replaceSerializedCallback($m, $oldValues, $newValues),
                $input
            ) ?? $input;
        }

        return $input;
    }

    /**
     * 對資料庫直連模式下的原始欄位值執行全套替換
     *
     * 與 replaceInSqlLine() 的差異：
     *   - replaceInSqlLine() 設計給 SQL 行，需先用正則提取 '...' 字串後才替換
     *   - 此方法設計給直連模式，欄位值本身即純字串，直接做 Base64 + 序列化安全替換
     *
     * 適用於 DatabaseReplacer，處理如 Elementor _elementor_data（JSON）、
     * 序列化 PHP 物件、普通字串等各種欄位格式
     *
     * @param string[] $oldValues
     * @param string[] $newValues
     */
    public function replaceFieldValue(
        string $input,
        array $oldValues,
        array $newValues,
        bool $visualComposer = false,
        bool $oxygenBuilder = false,
        bool $bethemeOrAvada = false,
    ): string {
        // Visual Composer：[vc_raw_html]BASE64[/vc_raw_html]
        if ($visualComposer) {
            $input = preg_replace_callback(
                '/\[vc_raw_html\]([a-zA-Z0-9\/+]+={0,2})\[\/vc_raw_html\]/S',
                fn(array $m) => $this->replaceBase64Callback($m, $oldValues, $newValues, '[vc_raw_html]', '[/vc_raw_html]', false),
                $input
            ) ?? $input;
        }

        // Oxygen Builder：\"code-php\":\"BASE64\"
        if ($oxygenBuilder) {
            $input = preg_replace_callback(
                '/\\\\"(code-php|code-css|code-js)\\\\":\\\\"([a-zA-Z0-9\/+]+={0,2})\\\\"/S',
                fn(array $m) => $this->replaceOxygenCallback($m, $oldValues, $newValues),
                $input
            ) ?? $input;
        }

        // BeTheme / Optimize Press / Avada Fusion Builder：'BASE64'
        if ($bethemeOrAvada) {
            $input = preg_replace_callback(
                "/'([a-zA-Z0-9\/+]+={0,2})'/S",
                fn(array $m) => $this->replaceBase64SerializedCallback($m, $oldValues, $newValues),
                $input
            ) ?? $input;
        }

        // 直接對欄位值做序列化安全替換（無需 SQL 引號提取）
        return (string) self::replaceSerializedValues($oldValues, $newValues, $input, false);
    }

    /**
     * 批次純文字替換（raw values，不含序列化處理）
     *
     * @param string[] $oldValues
     * @param string[] $newValues
     */
    public static function replaceValues(array $oldValues, array $newValues, string $data): string
    {
        if (empty($oldValues) || empty($newValues)) {
            return $data;
        }

        return strtr($data, array_combine($oldValues, $newValues));
    }

    /**
     * 遞迴序列化安全替換
     *
     * @param string[] $from
     * @param string[] $to
     * @param mixed    $data
     */
    public static function replaceSerializedValues(array $from, array $to, mixed $data, bool $serialized = false): mixed
    {
        try {
            if (is_string($data) && self::isSerialized($data)) {
                $unserialized = @unserialize($data);
                if ($unserialized !== false) {
                    $data = self::replaceSerializedValues($from, $to, $unserialized, true);
                } else {
                    // unserialize 失敗時直接字串替換
                    $data = self::replaceValues($from, $to, $data);
                }
            } elseif (is_array($data)) {
                $tmp = [];
                foreach ($data as $key => $value) {
                    $tmp[$key] = self::replaceSerializedValues($from, $to, $value, false);
                }
                $data = $tmp;
            } elseif (is_object($data)) {
                if (!($data instanceof \__PHP_Incomplete_Class)) {
                    $props = get_object_vars($data);
                    foreach ($props as $key => $value) {
                        if (!empty($data->$key)) {
                            $data->$key = self::replaceSerializedValues($from, $to, $value, false);
                        }
                    }
                }
            } elseif (is_string($data)) {
                $data = self::replaceValues($from, $to, $data);
            }

            if ($serialized) {
                return serialize($data);
            }
        } catch (\Exception) {
        }

        return $data;
    }

    // -------------------------------------------------------------------------
    // Callback 方法
    // -------------------------------------------------------------------------

    /**
     * Visual Composer [vc_raw_html] callback
     *
     * @param string[] $oldValues
     * @param string[] $newValues
     */
    private function replaceBase64Callback(
        array $matches,
        array $oldValues,
        array $newValues,
        string $open,
        string $close,
        bool $serialized,
    ): string {
        $content = $matches[1];

        if (self::base64Validate($content)) {
            $decoded = base64_decode($content);
            $decoded = $serialized
                ? self::replaceSerializedValues($oldValues, $newValues, $decoded, false)
                : self::replaceValues($oldValues, $newValues, $decoded);
            $content = base64_encode((string) $decoded);
        }

        return $open . $content . $close;
    }

    /**
     * Oxygen Builder callback
     *
     * @param string[] $oldValues
     * @param string[] $newValues
     */
    private function replaceOxygenCallback(array $matches, array $oldValues, array $newValues): string
    {
        $type    = $matches[1];
        $content = $matches[2];

        if (self::base64Validate($content)) {
            $decoded = base64_decode($content);
            $decoded = self::replaceValues($oldValues, $newValues, $decoded);
            $content = base64_encode($decoded);
        }

        return '\"' . $type . '\":\"' . $content . '\"';
    }

    /**
     * BeTheme / Avada 'BASE64' callback（含序列化安全替換）
     *
     * @param string[] $oldValues
     * @param string[] $newValues
     */
    private function replaceBase64SerializedCallback(array $matches, array $oldValues, array $newValues): string
    {
        $content = $matches[1];

        if (self::base64Validate($content)) {
            $decoded = base64_decode($content);
            $decoded = self::replaceSerializedValues($oldValues, $newValues, $decoded, false);
            $content = base64_encode((string) $decoded);
        }

        return "'" . $content . "'";
    }

    /**
     * SQL 字串值 callback（unescape → 序列化替換 → re-escape）
     *
     * @param string[] $oldValues
     * @param string[] $newValues
     */
    private function replaceSerializedCallback(array $matches, array $oldValues, array $newValues): string
    {
        $value = self::unescapeMysql($matches[1]);
        $value = self::replaceSerializedValues($oldValues, $newValues, $value, false);
        $value = self::escapeMysql((string) $value);

        return "'" . $value . "'";
    }

    // -------------------------------------------------------------------------
    // 靜態工具方法
    // -------------------------------------------------------------------------

    public static function escapeMysql(string $data): string
    {
        return strtr($data, array_combine(
            ["\x00", "\n", "\r", '\\', "'", '"', "\x1a"],
            ['\\0',  '\\n', '\\r', '\\\\', "\\'", '\\"', '\\Z']
        ));
    }

    public static function unescapeMysql(string $data): string
    {
        return strtr($data, array_combine(
            ['\\0',  '\\n', '\\r', '\\\\', "\\'", '\\"', '\\Z'],
            ["\x00", "\n", "\r", '\\', "'", '"', "\x1a"]
        ));
    }

    public static function base64Validate(string $data): bool
    {
        return base64_encode(base64_decode($data, true)) === $data;
    }

    /**
     * 簡易序列化字串偵測（仿 WordPress is_serialized()）
     */
    private static function isSerialized(string $data): bool
    {
        $data = trim($data);
        if ('N;' === $data) {
            return true;
        }
        if (strlen($data) < 4 || ':' !== $data[1]) {
            return false;
        }

        return (bool) preg_match('/^[aOsibd]:[0-9]+:/S', $data);
    }
}
