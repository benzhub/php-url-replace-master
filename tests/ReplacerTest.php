<?php

declare(strict_types=1);

namespace WpMigrate\Tests;

use PHPUnit\Framework\TestCase;
use WpMigrate\Src\Replacer;
use WpMigrate\Src\SerializedReplacer;

/**
 * Replacer 單元測試
 *
 * 涵蓋：
 *   - processQuery() 基本替換
 *   - 快速預檢跳過（不含 old value 時）
 *   - 表前綴替換
 *   - 跳過快取查詢（transient / wc_session）
 *   - 大語句保護（超過閾值時跳過 regex）
 *   - raw value 替換
 *   - SQL 檔案模式（process()）
 */
class ReplacerTest extends TestCase
{
    private function makeReplacer(
        array $oldValues = ['https://old.com'],
        array $newValues = ['https://new.com'],
        array $oldRaw = [],
        array $newRaw = [],
    ): Replacer {
        $r = new Replacer();
        $r->setOldValues($oldValues);
        $r->setNewValues($newValues);
        $r->setOldRawValues($oldRaw);
        $r->setNewRawValues($newRaw);
        return $r;
    }

    // -------------------------------------------------------------------------
    // processQuery() 基本替換
    // -------------------------------------------------------------------------

    public function testProcessQueryReplacesUrl(): void
    {
        $r      = $this->makeReplacer();
        $sql    = "INSERT INTO `wp_options` VALUES ('siteurl','https://old.com');";
        $result = $r->processQuery($sql);
        $this->assertStringContainsString('https://new.com', $result);
        $this->assertStringNotContainsString('https://old.com', $result);
    }

    public function testProcessQuerySkipsWhenNoOldValueFound(): void
    {
        $r      = $this->makeReplacer();
        $sql    = "INSERT INTO `wp_options` VALUES ('siteurl','https://other.com');";
        $result = $r->processQuery($sql);
        $this->assertStringNotContainsString('https://new.com', $result);
        $this->assertSame($sql, $result);
    }

    public function testProcessQueryHandlesSerializedValue(): void
    {
        $r    = $this->makeReplacer();
        $data = serialize(['url' => 'https://old.com']);
        $esc  = SerializedReplacer::escapeMysql($data);
        $sql  = "INSERT INTO `wp_options` VALUES ('widget','{$esc}');";

        $result = $r->processQuery($sql);
        $this->assertStringContainsString('https://new.com', $result);
    }

    // -------------------------------------------------------------------------
    // 表前綴替換
    // -------------------------------------------------------------------------

    public function testProcessQueryReplacesTablePrefix(): void
    {
        $r = new Replacer();
        $r->setOldValues([]);
        $r->setNewValues([]);
        $r->setOldTablePrefixes(['old_']);
        $r->setNewTablePrefixes(['wp_']);

        $sql    = "INSERT INTO `old_options` VALUES ('key','value');";
        $result = $r->processQuery($sql);
        $this->assertStringContainsString('wp_options', $result);
        $this->assertStringNotContainsString('old_options', $result);
    }

    // -------------------------------------------------------------------------
    // 跳過快取查詢
    // -------------------------------------------------------------------------

    public function testProcessQuerySkipsTransient(): void
    {
        $r   = $this->makeReplacer();
        $sql = "INSERT INTO `wp_options` VALUES ('_transient_foo','https://old.com');";
        // 包含 _transient_，但查詢中有舊值，shouldIgnore 應讓它直接透傳
        $result = $r->processQuery($sql);
        // transient 查詢應跳過替換（原樣返回）
        $this->assertSame($sql, $result);
    }

    public function testProcessQuerySkipsSiteTransient(): void
    {
        $r      = $this->makeReplacer();
        $sql    = "INSERT INTO `wp_options` VALUES ('_site_transient_foo','https://old.com');";
        $result = $r->processQuery($sql);
        $this->assertSame($sql, $result);
    }

    public function testProcessQuerySkipsWcSession(): void
    {
        $r      = $this->makeReplacer();
        $sql    = "INSERT INTO `wp_options` VALUES ('_wc_session_foo','https://old.com');";
        $result = $r->processQuery($sql);
        $this->assertSame($sql, $result);
    }

    // -------------------------------------------------------------------------
    // 大語句保護
    // -------------------------------------------------------------------------

    public function testProcessQuerySkipsRegexForLargeStatements(): void
    {
        $r = $this->makeReplacer(
            oldRaw: ["'old.com','/'"],
            newRaw: ["'new.com','/'"],
        );
        $r->setMaxStatementBytes(10); // 設極小閾值

        // 大語句：超過 10 bytes 的 INSERT
        $sql = "INSERT INTO `wp_posts` VALUES ('1','https://old.com content here');";
        // 超過閾值：序列化替換被跳過，但 raw value 仍替換
        $result = $r->processQuery($sql);
        // https://old.com 應未被替換（超過閾值跳過 regex）
        $this->assertStringContainsString('https://old.com', $result);
    }

    // -------------------------------------------------------------------------
    // raw value 替換
    // -------------------------------------------------------------------------

    public function testProcessQueryReplacesRawValue(): void
    {
        $r = $this->makeReplacer(
            oldValues: [],
            newValues: [],
            oldRaw: ["'old.com','/'"],
            newRaw: ["'new.com','/'"],
        );
        // raw value 格式出現在 INSERT VALUES 中
        $sql    = "INSERT INTO `wp_blogs` VALUES (1,'old.com','/','old.wordpress.com',1,1);";
        $result = $r->processQuery($sql);
        $this->assertStringContainsString("'new.com','/'", $result);
    }

    // -------------------------------------------------------------------------
    // process() SQL 檔案模式
    // -------------------------------------------------------------------------

    public function testProcessSqlFile(): void
    {
        $tmpFile = tempnam(sys_get_temp_dir(), 'replacer_test_') . '.sql';
        $sql     = "INSERT INTO `wp_options` VALUES ('siteurl','https://old.com');\n";
        file_put_contents($tmpFile, $sql);

        $r = $this->makeReplacer();
        $r->process($tmpFile);

        $result = file_get_contents($tmpFile);
        $this->assertStringContainsString('https://new.com', $result);
        $this->assertStringNotContainsString('https://old.com', $result);

        unlink($tmpFile);
    }

    public function testProcessSqlFileThrowsForMissingFile(): void
    {
        $this->expectException(\RuntimeException::class);
        $r = $this->makeReplacer();
        $r->process('/non/existent/file.sql');
    }

    public function testProcessSqlFileAtomicWriteOnFailure(): void
    {
        // 原始檔案在替換成功後才被覆寫
        $tmpFile = tempnam(sys_get_temp_dir(), 'replacer_atomic_') . '.sql';
        $sql     = "SELECT 'https://old.com';\n";
        file_put_contents($tmpFile, $sql);

        $r = $this->makeReplacer();
        $r->process($tmpFile);

        // 沒有殘留暫存檔
        $tmpPattern = $tmpFile . '.tmp_*';
        $tmpFiles   = glob($tmpFile . '.tmp_*');
        $this->assertEmpty($tmpFiles ?? [], '不應有殘留的暫存檔');

        unlink($tmpFile);
    }

    public function testProcessSqlFileMultilineQuery(): void
    {
        $tmpFile = tempnam(sys_get_temp_dir(), 'replacer_multiline_') . '.sql';
        $sql     = "INSERT INTO `wp_options`\nVALUES\n('siteurl','https://old.com');\n";
        file_put_contents($tmpFile, $sql);

        $r = $this->makeReplacer();
        $r->process($tmpFile);

        $result = file_get_contents($tmpFile);
        $this->assertStringContainsString('https://new.com', $result);

        unlink($tmpFile);
    }
}
