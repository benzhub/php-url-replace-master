<?php

declare(strict_types=1);

namespace WpMigrate\Src;

use RuntimeException;

/**
 * SQL 域名替換器
 *
 * 仿照 All-in-One WP Migration 的 Ai1wm_Database::import() 流程
 * 逐行串流讀取 SQL，依序執行：
 *   1. 表前綴替換
 *   2. Base64 + 序列化安全替換
 *   3. raw values 直接替換
 * 替換完成後覆寫原始 SQL 檔案
 *
 * 參考：class-ai1wm-database.php import() / replace_table_values()
 */
final class Replacer
{
    private readonly SerializedReplacer $serializedReplacer;

    /** @var string[] */
    private array $oldTablePrefixes = [];

    /** @var string[] */
    private array $newTablePrefixes = [];

    /** @var string[] */
    private array $oldValues = [];

    /** @var string[] */
    private array $newValues = [];

    /** @var string[] */
    private array $oldRawValues = [];

    /** @var string[] */
    private array $newRawValues = [];

    private bool $visualComposer  = false;
    private bool $oxygenBuilder   = false;
    private bool $bethemeOrAvada  = false;

    public function __construct()
    {
        $this->serializedReplacer = new SerializedReplacer();
    }

    // -------------------------------------------------------------------------
    // 設定方法（鏈式呼叫）
    // -------------------------------------------------------------------------

    /** @param string[] $old */
    public function setOldTablePrefixes(array $old): static
    {
        $this->oldTablePrefixes = $old;
        return $this;
    }

    /** @param string[] $new */
    public function setNewTablePrefixes(array $new): static
    {
        $this->newTablePrefixes = $new;
        return $this;
    }

    /** @param string[] $old */
    public function setOldValues(array $old): static
    {
        $this->oldValues = $old;
        return $this;
    }

    /** @param string[] $new */
    public function setNewValues(array $new): static
    {
        $this->newValues = $new;
        return $this;
    }

    /** @param string[] $old */
    public function setOldRawValues(array $old): static
    {
        $this->oldRawValues = $old;
        return $this;
    }

    /** @param string[] $new */
    public function setNewRawValues(array $new): static
    {
        $this->newRawValues = $new;
        return $this;
    }

    public function setVisualComposer(bool $active): static
    {
        $this->visualComposer = $active;
        return $this;
    }

    public function setOxygenBuilder(bool $active): static
    {
        $this->oxygenBuilder = $active;
        return $this;
    }

    public function setBethemeOrAvada(bool $active): static
    {
        $this->bethemeOrAvada = $active;
        return $this;
    }

    // -------------------------------------------------------------------------
    // 核心執行
    // -------------------------------------------------------------------------

    /**
     * 對 SQL 檔案執行全面替換並覆寫原始檔案
     *
     * 採用「先寫暫存檔、完成後替換」策略，避免中途失敗導致原檔損毀
     */
    public function process(string $sqlFilePath): void
    {
        if (!is_file($sqlFilePath)) {
            throw new RuntimeException("SQL 檔案不存在：{$sqlFilePath}");
        }

        $tmpFile = $sqlFilePath . '.tmp_' . uniqid('', true);

        $in  = fopen($sqlFilePath, 'rb');
        $out = fopen($tmpFile, 'wb');

        if ($in === false || $out === false) {
            throw new RuntimeException("無法開啟檔案：{$sqlFilePath}");
        }

        try {
            $query = '';

            while (($line = fgets($in)) !== false) {
                $query .= $line;

                // 等待完整 SQL 語句（以 ; 結尾）
                if (!preg_match('/;\s*$/S', $query)) {
                    continue;
                }

                $query = trim($query);
                $query = $this->processQuery($query);

                fwrite($out, $query . "\n");
                $query = '';
            }

            // 寫入最後可能沒有 ; 結尾的殘餘內容
            if (trim($query) !== '') {
                fwrite($out, $this->processQuery(trim($query)) . "\n");
            }
        } finally {
            fclose($in);
            fclose($out);
        }

        if (!rename($tmpFile, $sqlFilePath)) {
            unlink($tmpFile);
            throw new RuntimeException("無法覆寫原始 SQL 檔案：{$sqlFilePath}");
        }
    }

    /**
     * 對單一完整 SQL 語句執行所有替換
     */
    public function processQuery(string $query): string
    {
        // Step 1：表前綴替換（全域 str_ireplace）
        $query = $this->replaceTablePrefixes($query);

        // Step 2：跳過快取類查詢（transient / wc_session）
        if ($this->shouldIgnore($query)) {
            return $query;
        }

        // Step 3：BASE64 + 序列化安全替換
        $query = $this->serializedReplacer->replaceInSqlLine(
            $query,
            $this->oldValues,
            $this->newValues,
            $this->visualComposer,
            $this->oxygenBuilder,
            $this->bethemeOrAvada,
        );

        // Step 4：raw values 直接替換（domain,'path/' 格式）
        $query = SerializedReplacer::replaceValues($this->oldRawValues, $this->newRawValues, $query);

        return $query;
    }

    // -------------------------------------------------------------------------
    // 內部工具
    // -------------------------------------------------------------------------

    /**
     * 表前綴全域替換（str_ireplace）
     * 仿 Ai1wm_Database::replace_table_prefixes() 無 $position 版本
     */
    private function replaceTablePrefixes(string $input): string
    {
        if (empty($this->oldTablePrefixes)) {
            return $input;
        }

        return str_ireplace($this->oldTablePrefixes, $this->newTablePrefixes, $input);
    }

    /**
     * 跳過不需要替換的快取查詢
     * 仿 Ai1wm_Database::should_ignore_query()
     */
    private function shouldIgnore(string $query): bool
    {
        return str_contains($query, "'_transient_")
            || str_contains($query, "'_site_transient_")
            || str_contains($query, "'_wc_session_")
            || str_contains($query, "'_wpallimport_session_");
    }
}
