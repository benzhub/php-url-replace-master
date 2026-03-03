<?php

declare(strict_types=1);

namespace WpMigrate\Tests;

use PHPUnit\Framework\TestCase;
use WpMigrate\Src\SerializedReplacer;

/**
 * SerializedReplacer 單元測試
 *
 * 涵蓋：
 *   - 純字串替換
 *   - 序列化陣列替換（s:N: 長度自動修正）
 *   - 序列化物件替換
 *   - unserialize 失敗 fallback（replaceInSerializedString）
 *   - JSON 欄位替換
 *   - Base64 替換（Visual Composer / Oxygen Builder / BeTheme）
 *   - MySQL escape / unescape
 *   - containsAny 快速預檢
 *   - replaceInSqlLine SQL 行替換
 */
class SerializedReplacerTest extends TestCase
{
    private readonly SerializedReplacer $replacer;

    protected function setUp(): void
    {
        $this->replacer = new SerializedReplacer();
    }

    // -------------------------------------------------------------------------
    // replaceValues() 純文字替換
    // -------------------------------------------------------------------------

    public function testReplaceValuesBasic(): void
    {
        $result = SerializedReplacer::replaceValues(
            ['https://old.com'],
            ['https://new.com'],
            'Visit https://old.com/page'
        );
        $this->assertSame('Visit https://new.com/page', $result);
    }

    public function testReplaceValuesMultiple(): void
    {
        $result = SerializedReplacer::replaceValues(
            ['https://old.com', 'http://old.com'],
            ['https://new.com', 'https://new.com'],
            'https://old.com and http://old.com'
        );
        $this->assertSame('https://new.com and https://new.com', $result);
    }

    public function testReplaceValuesEmptyArrays(): void
    {
        $result = SerializedReplacer::replaceValues([], [], 'unchanged');
        $this->assertSame('unchanged', $result);
    }

    // -------------------------------------------------------------------------
    // replaceSerializedValues() 序列化替換
    // -------------------------------------------------------------------------

    public function testReplaceSerializedValuesString(): void
    {
        $result = SerializedReplacer::replaceSerializedValues(
            ['https://old.com'],
            ['https://new.com'],
            'https://old.com',
            false
        );
        $this->assertSame('https://new.com', $result);
    }

    public function testReplaceSerializedValuesArray(): void
    {
        $data = serialize(['siteurl' => 'https://old.com', 'home' => 'https://old.com']);
        $result = SerializedReplacer::replaceSerializedValues(
            ['https://old.com'],
            ['https://new.com'],
            $data,
            false
        );
        $unserialized = unserialize($result);
        $this->assertSame('https://new.com', $unserialized['siteurl']);
        $this->assertSame('https://new.com', $unserialized['home']);
    }

    public function testReplaceSerializedValuesNestedArray(): void
    {
        $data = serialize([
            'url'    => 'https://old.com',
            'nested' => ['inner_url' => 'https://old.com/page'],
        ]);
        $result = SerializedReplacer::replaceSerializedValues(
            ['https://old.com'],
            ['https://new.com'],
            $data,
            false
        );
        $unserialized = unserialize($result);
        $this->assertSame('https://new.com', $unserialized['url']);
        $this->assertSame('https://new.com/page', $unserialized['nested']['inner_url']);
    }

    public function testReplaceSerializedValuesObject(): void
    {
        $obj      = new \stdClass();
        $obj->url = 'https://old.com';
        $data     = serialize($obj);

        $result = SerializedReplacer::replaceSerializedValues(
            ['https://old.com'],
            ['https://new.com'],
            $data,
            false
        );
        $unserialized = unserialize($result);
        $this->assertSame('https://new.com', $unserialized->url);
    }

    public function testReplaceSerializedValuesWithLengthCorrection(): void
    {
        // 替換後字串變長，s:N: 需正確更新
        $data   = serialize('https://old.com');
        $result = SerializedReplacer::replaceSerializedValues(
            ['https://old.com'],
            ['https://very-long-new-domain.com'],
            $data,
            false
        );
        $this->assertSame('https://very-long-new-domain.com', unserialize($result));
    }

    public function testReplaceSerializedValuesReturnsSerializedWhenFlagSet(): void
    {
        $data   = ['url' => 'https://old.com'];
        $result = SerializedReplacer::replaceSerializedValues(
            ['https://old.com'],
            ['https://new.com'],
            $data,
            true
        );
        $this->assertIsString($result);
        $unserialized = unserialize($result);
        $this->assertSame('https://new.com', $unserialized['url']);
    }

    public function testReplaceSerializedValuesHandlesException(): void
    {
        // 即使 data 無法序列化，也不應拋出例外
        $result = SerializedReplacer::replaceSerializedValues(
            ['old'],
            ['new'],
            'plain string',
            false
        );
        $this->assertSame('plain string', $result);
    }

    // -------------------------------------------------------------------------
    // replaceInSerializedString() fallback 替換
    // -------------------------------------------------------------------------

    public function testReplaceInSerializedStringBasic(): void
    {
        $original = 'https://old.com';
        $new      = 'https://new.com';
        $data     = 's:' . strlen($original) . ':"' . $original . '";';
        $expected = 's:' . strlen($new) . ':"' . $new . '";';
        $result   = SerializedReplacer::replaceInSerializedString(
            [$original],
            [$new],
            $data
        );
        $this->assertSame($expected, $result);
    }

    public function testReplaceInSerializedStringLengthCorrection(): void
    {
        // 替換後字串長度變化，s:N: 必須正確更新
        $original = 'https://old.com';
        $new      = 'https://very-long-new-domain.com';
        $data     = 's:' . strlen($original) . ':"' . $original . '";';

        $result = SerializedReplacer::replaceInSerializedString(
            [$original],
            [$new],
            $data
        );

        $this->assertSame('s:' . strlen($new) . ':"' . $new . '";', $result);
    }

    public function testReplaceInSerializedStringComplexData(): void
    {
        // 用 serialize 產生正確的序列化字串，確保 s:N: 長度正確
        $data   = serialize(['siteurl' => 'https://old.com', 'home' => 'https://old.com']);
        $result = SerializedReplacer::replaceInSerializedString(
            ['https://old.com'],
            ['https://new.com'],
            $data
        );
        $this->assertStringContainsString('https://new.com', $result);
        // 驗證長度計數被正確更新
        $this->assertStringContainsString('s:' . strlen('https://new.com') . ':"https://new.com"', $result);
    }

    public function testReplaceInSerializedStringEmptyFromTo(): void
    {
        $data   = 's:16:"https://old.com";';
        $result = SerializedReplacer::replaceInSerializedString([], [], $data);
        $this->assertSame($data, $result);
    }

    public function testReplaceInSerializedStringPreservesUnmatchedContent(): void
    {
        $data   = 'a:1:{s:3:"key";s:16:"https://old.com";}';
        $result = SerializedReplacer::replaceInSerializedString(
            ['https://old.com'],
            ['https://new.com'],
            $data
        );
        $this->assertStringContainsString('a:1:{s:3:"key";', $result);
    }

    // -------------------------------------------------------------------------
    // containsAny() 快速預檢
    // -------------------------------------------------------------------------

    public function testContainsAnyFound(): void
    {
        $this->assertTrue(
            SerializedReplacer::containsAny('https://old.com/page', ['https://old.com'])
        );
    }

    public function testContainsAnyNotFound(): void
    {
        $this->assertFalse(
            SerializedReplacer::containsAny('https://new.com/page', ['https://old.com'])
        );
    }

    public function testContainsAnyEmptyNeedles(): void
    {
        $this->assertFalse(SerializedReplacer::containsAny('anything', []));
    }

    public function testContainsAnyEmptyNeedle(): void
    {
        $this->assertFalse(SerializedReplacer::containsAny('anything', ['']));
    }

    // -------------------------------------------------------------------------
    // escapeMysql() / unescapeMysql()
    // -------------------------------------------------------------------------

    public function testEscapeMysql(): void
    {
        $this->assertSame("\\'", SerializedReplacer::escapeMysql("'"));
        $this->assertSame('\\\\', SerializedReplacer::escapeMysql('\\'));
        $this->assertSame('\\n', SerializedReplacer::escapeMysql("\n"));
        $this->assertSame('\\0', SerializedReplacer::escapeMysql("\x00"));
    }

    public function testUnescapeMysql(): void
    {
        $this->assertSame("'", SerializedReplacer::unescapeMysql("\\'"));
        $this->assertSame('\\', SerializedReplacer::unescapeMysql('\\\\'));
        $this->assertSame("\n", SerializedReplacer::unescapeMysql('\\n'));
    }

    public function testEscapeUnescapeRoundtrip(): void
    {
        $original = "https://old.com/page?a=1&b='test'\n";
        $escaped  = SerializedReplacer::escapeMysql($original);
        $restored = SerializedReplacer::unescapeMysql($escaped);
        $this->assertSame($original, $restored);
    }

    // -------------------------------------------------------------------------
    // replaceInSqlLine() SQL 行替換
    // -------------------------------------------------------------------------

    public function testReplaceInSqlLineBasic(): void
    {
        $sql    = "INSERT INTO `wp_options` VALUES ('siteurl','https://old.com');";
        $result = $this->replacer->replaceInSqlLine(
            $sql,
            ['https://old.com'],
            ['https://new.com']
        );
        $this->assertStringContainsString('https://new.com', $result);
    }

    public function testReplaceInSqlLineSkipsWhenNotFound(): void
    {
        $sql = "INSERT INTO `wp_options` VALUES ('siteurl','https://other.com');";
        $result = $this->replacer->replaceInSqlLine(
            $sql,
            ['https://old.com'],
            ['https://new.com']
        );
        // 不含 old value，應原樣返回
        $this->assertStringContainsString('https://other.com', $result);
        $this->assertStringNotContainsString('https://new.com', $result);
    }

    public function testReplaceInSqlLineWithSerializedValue(): void
    {
        $serialized = serialize(['url' => 'https://old.com']);
        $escaped    = SerializedReplacer::escapeMysql($serialized);
        $sql        = "INSERT INTO `wp_options` VALUES ('widget','{$escaped}');";

        $result = $this->replacer->replaceInSqlLine(
            $sql,
            ['https://old.com'],
            ['https://new.com']
        );

        // 提取替換後的序列化值
        preg_match("/'([^']+)'\);$/", $result, $m);
        if (isset($m[1])) {
            $restored = SerializedReplacer::unescapeMysql($m[1]);
            $data     = unserialize($restored);
            $this->assertSame('https://new.com', $data['url']);
        } else {
            $this->assertStringContainsString('https://new.com', $result);
        }
    }

    // -------------------------------------------------------------------------
    // replaceFieldValue() 直連模式欄位值替換
    // -------------------------------------------------------------------------

    public function testReplaceFieldValuePlainString(): void
    {
        $result = $this->replacer->replaceFieldValue(
            'https://old.com',
            ['https://old.com'],
            ['https://new.com']
        );
        $this->assertSame('https://new.com', $result);
    }

    public function testReplaceFieldValueSerialized(): void
    {
        $data   = serialize(['siteurl' => 'https://old.com']);
        $result = $this->replacer->replaceFieldValue(
            $data,
            ['https://old.com'],
            ['https://new.com']
        );
        $unserialized = unserialize($result);
        $this->assertSame('https://new.com', $unserialized['siteurl']);
    }

    public function testReplaceFieldValueJson(): void
    {
        $data   = json_encode(['url' => 'https://old.com', 'title' => 'Test'], JSON_UNESCAPED_SLASHES);
        $result = $this->replacer->replaceFieldValue(
            $data,
            ['https://old.com'],
            ['https://new.com']
        );
        $decoded = json_decode($result, true);
        $this->assertSame('https://new.com', $decoded['url']);
        $this->assertSame('Test', $decoded['title']);
    }

    public function testReplaceFieldValueNestedJson(): void
    {
        $inner = json_encode(['inner_url' => 'https://old.com'], JSON_UNESCAPED_SLASHES);
        $outer = json_encode(['data' => $inner], JSON_UNESCAPED_SLASHES);

        $result  = $this->replacer->replaceFieldValue(
            $outer,
            ['https://old.com'],
            ['https://new.com']
        );
        $decoded = json_decode($result, true);
        $inner2  = json_decode($decoded['data'], true);
        $this->assertSame('https://new.com', $inner2['inner_url']);
    }

    public function testReplaceFieldValueVisualComposer(): void
    {
        $content   = 'See https://old.com for details';
        $encoded   = base64_encode($content);
        $fieldValue = "[vc_raw_html]{$encoded}[/vc_raw_html]";

        $result = $this->replacer->replaceFieldValue(
            $fieldValue,
            ['https://old.com'],
            ['https://new.com'],
            visualComposer: true
        );

        preg_match('/\[vc_raw_html\]([a-zA-Z0-9\/+=]+)\[\/vc_raw_html\]/', $result, $m);
        $this->assertNotEmpty($m[1] ?? '');
        $decoded = base64_decode($m[1]);
        $this->assertStringContainsString('https://new.com', $decoded);
    }
}
