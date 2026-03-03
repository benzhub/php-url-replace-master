<?php

declare(strict_types=1);

namespace WpMigrate\Tests;

use PHPUnit\Framework\TestCase;
use WpMigrate\Src\UrlVariantBuilder;

/**
 * UrlVariantBuilder 單元測試
 *
 * 涵蓋：
 *   - 變體數量與必要格式
 *   - www 互換
 *   - scheme 變體（http / https / //）
 *   - URL 編碼變體（urlencode / rawurlencode / addcslashes）
 *   - Email 替換
 *   - raw 格式（'domain','path/'）
 *   - Multisite 屬性格式（='domain/path 和 ="domain/path）← AI1WM 補齊
 *   - 寬鬆模式（trailing slash / 跨 scheme）
 *   - uploads URL 映射
 *   - 自訂替換對
 */
class UrlVariantBuilderTest extends TestCase
{
    private function build(
        string $oldUrl = 'https://old.com',
        string $newUrl = 'https://new.com',
        bool $replaceEmail = true,
        string $oldPath = '',
        string $newPath = '',
        bool $looseMode = true,
        string $oldUploadsUrl = '',
        string $newUploadsUrl = '',
    ): array {
        $builder = new UrlVariantBuilder(
            oldUrl: $oldUrl,
            newUrl: $newUrl,
            replaceEmail: $replaceEmail,
            oldPath: $oldPath,
            newPath: $newPath,
            looseMode: $looseMode,
            oldUploadsUrl: $oldUploadsUrl,
            newUploadsUrl: $newUploadsUrl,
        );
        return $builder->build();
    }

    // -------------------------------------------------------------------------
    // 基本結構
    // -------------------------------------------------------------------------

    public function testBuildReturnsExpectedKeys(): void
    {
        $result = $this->build();
        $this->assertArrayHasKey('old', $result);
        $this->assertArrayHasKey('new', $result);
        $this->assertArrayHasKey('oldRaw', $result);
        $this->assertArrayHasKey('newRaw', $result);
    }

    public function testBuildOldAndNewHaveSameCount(): void
    {
        $result = $this->build();
        $this->assertCount(count($result['old']), $result['new']);
    }

    public function testBuildRawOldAndNewHaveSameCount(): void
    {
        $result = $this->build();
        $this->assertCount(count($result['oldRaw']), $result['newRaw']);
    }

    public function testBuildProducesNoDuplicates(): void
    {
        $result = $this->build();
        $unique = array_unique($result['old']);
        $this->assertCount(count($unique), $result['old'], '老值陣列不應有重複');
    }

    // -------------------------------------------------------------------------
    // scheme 變體
    // -------------------------------------------------------------------------

    public function testBuildContainsHttpVariant(): void
    {
        $result = $this->build('https://old.com', 'https://new.com');
        $this->assertContains('http://old.com', $result['old']);
    }

    public function testBuildContainsHttpsVariant(): void
    {
        $result = $this->build('https://old.com', 'https://new.com');
        $this->assertContains('https://old.com', $result['old']);
    }

    public function testBuildContainsSchemeRelativeVariant(): void
    {
        $result = $this->build('https://old.com', 'https://new.com');
        $this->assertContains('//old.com', $result['old']);
    }

    // -------------------------------------------------------------------------
    // www 互換
    // -------------------------------------------------------------------------

    public function testBuildContainsWwwVariantWhenOriginalHasNoWww(): void
    {
        $result = $this->build('https://old.com', 'https://new.com');
        $this->assertContains('https://www.old.com', $result['old']);
    }

    public function testBuildContainsNoWwwVariantWhenOriginalHasWww(): void
    {
        $result = $this->build('https://www.old.com', 'https://new.com');
        $this->assertContains('https://old.com', $result['old']);
    }

    // -------------------------------------------------------------------------
    // 編碼變體
    // -------------------------------------------------------------------------

    public function testBuildContainsUrlEncoded(): void
    {
        $result = $this->build('https://old.com', 'https://new.com');
        $this->assertContains(urlencode('https://old.com'), $result['old']);
    }

    public function testBuildContainsRawUrlEncoded(): void
    {
        $result = $this->build('https://old.com', 'https://new.com');
        $this->assertContains(rawurlencode('https://old.com'), $result['old']);
    }

    public function testBuildContainsJsonEscaped(): void
    {
        $result = $this->build('https://old.com', 'https://new.com');
        $this->assertContains(addcslashes('https://old.com', '/'), $result['old']);
    }

    // -------------------------------------------------------------------------
    // Email 替換
    // -------------------------------------------------------------------------

    public function testBuildContainsEmailVariant(): void
    {
        $result = $this->build('https://old.com', 'https://new.com', replaceEmail: true);
        $this->assertContains('@old.com', $result['old']);
    }

    public function testBuildDoesNotContainEmailVariantWhenDisabled(): void
    {
        $result = $this->build('https://old.com', 'https://new.com', replaceEmail: false);
        $this->assertNotContains('@old.com', $result['old']);
    }

    public function testBuildEmailNewValueStripsWww(): void
    {
        $result = $this->build('https://old.com', 'https://www.new.com', replaceEmail: true);
        $idx    = array_search('@old.com', $result['old'], true);
        $this->assertNotFalse($idx);
        $this->assertSame('@new.com', $result['new'][$idx]);
    }

    // -------------------------------------------------------------------------
    // raw 格式（'domain','path/'）
    // -------------------------------------------------------------------------

    public function testBuildContainsRawDomainPathFormat(): void
    {
        $result = $this->build('https://old.com/blog', 'https://new.com/blog');
        $this->assertContains("'old.com','/blog/'", $result['oldRaw']);
    }

    public function testBuildRawFormatRootPath(): void
    {
        $result = $this->build('https://old.com', 'https://new.com');
        $this->assertContains("'old.com','/'", $result['oldRaw']);
    }

    // -------------------------------------------------------------------------
    // Multisite 屬性格式（AI1WM 補齊）
    // -------------------------------------------------------------------------

    public function testBuildContainsSingleQuoteAttributeFormat(): void
    {
        $result = $this->build('https://old.com/blog', 'https://new.com/blog');
        $this->assertContains("='old.com/blog", $result['old']);
    }

    public function testBuildContainsDoubleQuoteAttributeFormat(): void
    {
        $result = $this->build('https://old.com/blog', 'https://new.com/blog');
        $this->assertContains('="old.com/blog', $result['old']);
    }

    public function testBuildAttributeFormatNewValue(): void
    {
        $result = $this->build('https://old.com/blog', 'https://new.com/newblog');
        $idx    = array_search("='old.com/blog", $result['old'], true);
        $this->assertNotFalse($idx);
        $this->assertSame("='new.com/newblog", $result['new'][$idx]);
    }

    // -------------------------------------------------------------------------
    // 實體路徑替換
    // -------------------------------------------------------------------------

    public function testBuildContainsPathVariant(): void
    {
        $result = $this->build(
            oldPath: '/var/www/old/wp-content',
            newPath: '/var/www/new/wp-content'
        );
        $this->assertContains('/var/www/old/wp-content', $result['old']);
    }

    public function testBuildContainsJsonEscapedPath(): void
    {
        $result = $this->build(
            oldPath: '/var/www/old',
            newPath: '/var/www/new'
        );
        $this->assertContains(addcslashes('/var/www/old', '/'), $result['old']);
    }

    // -------------------------------------------------------------------------
    // 寬鬆模式
    // -------------------------------------------------------------------------

    public function testBuildLooseModeContainsTrailingSlash(): void
    {
        $result = $this->build('https://old.com', 'https://new.com', looseMode: true);
        $this->assertContains('https://old.com/', $result['old']);
    }

    public function testBuildLooseModeContainsCrossScheme(): void
    {
        $result = $this->build('http://old.com', 'https://new.com', looseMode: true);
        $this->assertContains('https://old.com', $result['old']);
    }

    public function testBuildLooseModeDisabled(): void
    {
        $result  = $this->build('https://old.com', 'https://new.com', looseMode: false);
        $looseModeResult = $this->build('https://old.com', 'https://new.com', looseMode: true);

        // 關閉 loose mode 應生成更少的替換規則
        $this->assertLessThan(count($looseModeResult['old']), count($result['old']));
    }

    // -------------------------------------------------------------------------
    // Uploads URL 映射
    // -------------------------------------------------------------------------

    public function testBuildUploadsUrlMapping(): void
    {
        $result = $this->build(
            oldUploadsUrl: 'https://old.com/files',
            newUploadsUrl: 'https://new.com/wp-content/uploads',
        );
        $this->assertContains('https://old.com/files', $result['old']);
        $idx = array_search('https://old.com/files', $result['old'], true);
        $this->assertNotFalse($idx);
        $this->assertSame('https://new.com/wp-content/uploads', $result['new'][$idx]);
    }

    // -------------------------------------------------------------------------
    // 自訂替換對
    // -------------------------------------------------------------------------

    public function testAddCustomPairAddsAllEncodings(): void
    {
        $builder = new UrlVariantBuilder(
            oldUrl: 'https://old.com',
            newUrl: 'https://new.com',
        );
        $builder->addCustomPair('custom_old_value', 'custom_new_value');
        $result = $builder->build();

        $this->assertContains('custom_old_value', $result['old']);
        $this->assertContains(urlencode('custom_old_value'), $result['old']);
        $this->assertContains(rawurlencode('custom_old_value'), $result['old']);
        $this->assertContains(addcslashes('custom_old_value', '/'), $result['old']);
    }
}
