<?php

declare(strict_types=1);

namespace WpMigrate\Src;

/**
 * 仿照 All-in-One WP Migration 的域名替換邏輯
 * 對每個 URL 產生多種編碼變體，確保資料庫中所有形式的舊域名都能被替換
 *
 * 變體涵蓋（仿 class-ai1wm-import-database.php）：
 *   - 三種 scheme：http / https / scheme-relative（//）
 *   - www 互換：有 www ↔ 無 www
 *   - 四種編碼：plain / urlencode / rawurlencode / addcslashes（JSON escape）
 *   - Email：@old.com → @new.com
 *   - raw：'domain','path/' 格式（wp_blogs Multisite）
 *   - Multisite 屬性格式：='domain/path 和 ="domain/path（AI1WM 補齊）
 *   - Uploads URL 映射（可選）
 *   - 寬鬆模式：trailing slash 變體、跨 scheme 互換
 *
 * 參考：class-ai1wm-import-database.php 第 80–813 行
 */
final class UrlVariantBuilder
{
    /** @var string[] */
    private array $oldValues = [];

    /** @var string[] */
    private array $newValues = [];

    /** @var string[] */
    private array $oldRawValues = [];

    /** @var string[] */
    private array $newRawValues = [];

    /**
     * @param string $oldUrl        舊站 URL
     * @param string $newUrl        新站 URL
     * @param bool   $replaceEmail  是否替換 Email 域名
     * @param string $oldPath       舊站 wp-content 實體路徑（選填）
     * @param string $newPath       新站 wp-content 實體路徑（選填）
     * @param bool   $looseMode     寬鬆模式（trailing slash + 跨 scheme 互換）
     * @param string $oldUploadsUrl 舊站 uploads URL（Multisite 選填）
     * @param string $newUploadsUrl 新站 uploads URL（Multisite 選填）
     */
    public function __construct(
        private readonly string $oldUrl,
        private readonly string $newUrl,
        private readonly bool $replaceEmail = true,
        private readonly string $oldPath = '',
        private readonly string $newPath = '',
        private readonly bool $looseMode = true,
        private readonly string $oldUploadsUrl = '',
        private readonly string $newUploadsUrl = '',
    ) {}

    /**
     * 建立完整替換對照表
     *
     * @return array{old: string[], new: string[], oldRaw: string[], newRaw: string[]}
     */
    public function build(): array
    {
        $this->processUrl($this->oldUrl, $this->newUrl);

        // uploads URL 映射（仿 AI1WM 的 /files/ → uploads/sites/N/ 映射）
        if ($this->oldUploadsUrl !== '' && $this->newUploadsUrl !== '') {
            $this->processUrl($this->oldUploadsUrl, $this->newUploadsUrl);
        }

        if ($this->oldPath !== '' && $this->newPath !== '') {
            $this->addPathVariants($this->oldPath, $this->newPath);
        }

        if ($this->looseMode) {
            $this->addLooseVariants($this->oldUrl, $this->newUrl);
        }

        return [
            'old'    => $this->oldValues,
            'new'    => $this->newValues,
            'oldRaw' => $this->oldRawValues,
            'newRaw' => $this->newRawValues,
        ];
    }

    /**
     * 新增自訂替換對（plain + URL encoded 四種變體）
     */
    public function addCustomPair(string $oldValue, string $newValue): void
    {
        $this->addIfNotExists($this->oldValues, $this->newValues, $oldValue, $newValue);
        $this->addIfNotExists($this->oldValues, $this->newValues, urlencode($oldValue), urlencode($newValue));
        $this->addIfNotExists($this->oldValues, $this->newValues, rawurlencode($oldValue), rawurlencode($newValue));
        $this->addIfNotExists($this->oldValues, $this->newValues, addcslashes($oldValue, '/'), addcslashes($newValue, '/'));
    }

    /**
     * 處理一組 URL（含 www 互換）
     */
    private function processUrl(string $oldUrl, string $newUrl): void
    {
        $oldUrlWwwInversion = $this->invertWww($oldUrl);

        foreach ([$oldUrl, $oldUrlWwwInversion] as $url) {
            $this->addUrlVariants($url, $newUrl);
        }
    }

    /**
     * 對單一 URL 產生所有變體並加入對照表
     *
     * 每個 URL × 3 種 scheme（http, https, 無）× 4 種編碼
     * + raw 格式（'domain','path/'）
     * + Multisite 屬性格式（='domain/path 和 ="domain/path）  ← AI1WM 補齊
     * + Email（@old.com → @new.com）
     */
    private function addUrlVariants(string $oldUrl, string $newUrl): void
    {
        $oldDomain = (string) parse_url($oldUrl, PHP_URL_HOST);
        $newDomain = (string) parse_url($newUrl, PHP_URL_HOST);
        $oldPath   = (string) parse_url($oldUrl, PHP_URL_PATH);
        $newPath   = (string) parse_url($newUrl, PHP_URL_PATH);
        $newScheme = (string) parse_url($newUrl, PHP_URL_SCHEME);

        // raw 替換：'domain','path/' 格式（用於 wp_options 或 wp_blogs 的 domain/path 欄位對）
        $rawOld = sprintf("'%s','%s'", $oldDomain, $this->trailingSlashIt($oldPath));
        $rawNew = sprintf("'%s','%s'", $newDomain, $this->trailingSlashIt($newPath));
        $this->addRawIfNotExists($rawOld, $rawNew);

        // Multisite 屬性格式（仿 AI1WM class-ai1wm-import-database.php 第 232-241 行）
        // 用於 wp_blogs 表中 domain/path 屬性格式及部分外掛設定
        $oldDomainPath = $oldDomain . $this->untrailingSlashIt($oldPath);
        $newDomainPath = $newDomain . $this->untrailingSlashIt($newPath);

        // ='domain/path 格式（HTML/PHP 屬性中的單引號包圍）
        $this->addIfNotExists(
            $this->oldValues, $this->newValues,
            sprintf("='%s", $oldDomainPath),
            sprintf("='%s", $newDomainPath)
        );

        // ="domain/path 格式（HTML/PHP 屬性中的雙引號包圍）
        $this->addIfNotExists(
            $this->oldValues, $this->newValues,
            sprintf('="%s', $oldDomainPath),
            sprintf('="%s', $newDomainPath)
        );

        // 三種 scheme 迴圈
        $oldSchemes = ['http', 'https', ''];
        $newSchemes = [$newScheme, $newScheme, ''];

        for ($i = 0; $i < 3; $i++) {
            $oldBase = $this->applyScheme($this->untrailingSlashIt($oldUrl), $oldSchemes[$i]);
            $newBase = $this->applyScheme($this->untrailingSlashIt($newUrl), $newSchemes[$i]);

            // plain
            $this->addIfNotExists($this->oldValues, $this->newValues, $oldBase, $newBase);
            // urlencode
            $this->addIfNotExists($this->oldValues, $this->newValues, urlencode($oldBase), urlencode($newBase));
            // rawurlencode
            $this->addIfNotExists($this->oldValues, $this->newValues, rawurlencode($oldBase), rawurlencode($newBase));
            // JSON escaped（斜線轉義）
            $this->addIfNotExists($this->oldValues, $this->newValues, addcslashes($oldBase, '/'), addcslashes($newBase, '/'));
        }

        // Email 替換：@old.com → @new.com（仿 AI1WM 第 304-308 行）
        if ($this->replaceEmail && $oldDomain !== '') {
            $newEmailDomain = str_ireplace('@www.', '@', sprintf('@%s', $newDomain));
            $this->addIfNotExists($this->oldValues, $this->newValues, sprintf('@%s', $oldDomain), $newEmailDomain);
        }
    }

    /**
     * 寬鬆模式（--loose）額外變體
     *
     * 在標準變體（plain / urlencode / rawurlencode / addcslashes）基礎上額外加入：
     *
     * 1. Trailing slash 變體：確保 `https://old.com/` 與 `https://old.com` 都能被替換
     *    （部分頁面建構器或外掛會在 URL 末尾加上斜線儲存）
     *
     * 2. 跨 scheme 互換：`http://old.com` → `https://new.com`（及反向）
     *    用於修復 Mixed Content 問題，或舊站 http 新站 https 的殘留 URL
     */
    private function addLooseVariants(string $oldUrl, string $newUrl): void
    {
        $oldUrlTrailing = rtrim($oldUrl, '/') . '/';
        $newUrlTrailing = rtrim($newUrl, '/') . '/';

        // Trailing slash 變體（含四種編碼）
        $this->addIfNotExists($this->oldValues, $this->newValues, $oldUrlTrailing, $newUrlTrailing);
        $this->addIfNotExists($this->oldValues, $this->newValues, urlencode($oldUrlTrailing), urlencode($newUrlTrailing));
        $this->addIfNotExists($this->oldValues, $this->newValues, rawurlencode($oldUrlTrailing), rawurlencode($newUrlTrailing));
        $this->addIfNotExists($this->oldValues, $this->newValues, addcslashes($oldUrlTrailing, '/'), addcslashes($newUrlTrailing, '/'));

        // 跨 scheme 互換：http://old → https://new（及 https://old → http://new）
        $oldScheme = (string) parse_url($oldUrl, PHP_URL_SCHEME);

        if ($oldScheme === 'http') {
            $oldHttps = preg_replace('#^http:#', 'https:', $oldUrl) ?? $oldUrl;
            $this->addIfNotExists($this->oldValues, $this->newValues, $oldHttps, $newUrl);
            $this->addIfNotExists($this->oldValues, $this->newValues, urlencode($oldHttps), urlencode($newUrl));
            $this->addIfNotExists($this->oldValues, $this->newValues, rawurlencode($oldHttps), rawurlencode($newUrl));
            $this->addIfNotExists($this->oldValues, $this->newValues, addcslashes($oldHttps, '/'), addcslashes($newUrl, '/'));
        } elseif ($oldScheme === 'https') {
            $oldHttp = preg_replace('#^https:#', 'http:', $oldUrl) ?? $oldUrl;
            $this->addIfNotExists($this->oldValues, $this->newValues, $oldHttp, $newUrl);
            $this->addIfNotExists($this->oldValues, $this->newValues, urlencode($oldHttp), urlencode($newUrl));
            $this->addIfNotExists($this->oldValues, $this->newValues, rawurlencode($oldHttp), rawurlencode($newUrl));
            $this->addIfNotExists($this->oldValues, $this->newValues, addcslashes($oldHttp, '/'), addcslashes($newUrl, '/'));
        }

        // www 互換的 trailing slash 變體
        $oldUrlWwwInversion = $this->invertWww($oldUrl);
        $oldInvTrailing     = rtrim($oldUrlWwwInversion, '/') . '/';
        $this->addIfNotExists($this->oldValues, $this->newValues, $oldInvTrailing, $newUrlTrailing);
    }

    /**
     * 實體路徑替換（wp-content 目錄路徑）
     * 四種編碼：plain + urlencode + rawurlencode + JSON escaped
     */
    private function addPathVariants(string $oldPath, string $newPath): void
    {
        $this->addIfNotExists($this->oldValues, $this->newValues, $oldPath, $newPath);
        $this->addIfNotExists($this->oldValues, $this->newValues, urlencode($oldPath), urlencode($newPath));
        $this->addIfNotExists($this->oldValues, $this->newValues, rawurlencode($oldPath), rawurlencode($newPath));
        $this->addIfNotExists($this->oldValues, $this->newValues, addcslashes($oldPath, '/'), addcslashes($newPath, '/'));
    }

    /**
     * 處理 www 互換：有 www 去掉，沒有 www 加上
     */
    private function invertWww(string $url): string
    {
        if (stripos($url, '//www.') !== false) {
            return str_ireplace('//www.', '//', $url);
        }

        return str_ireplace('//', '//www.', $url);
    }

    /**
     * 替換 URL scheme（http / https / 空）
     */
    private function applyScheme(string $url, string $scheme): string
    {
        if ($scheme === '') {
            return preg_replace('#^https?:#', '', $url) ?? $url;
        }

        return preg_replace('#^https?#', $scheme, $url) ?? $url;
    }

    private function trailingSlashIt(string $path): string
    {
        return rtrim($path, '/') . '/';
    }

    private function untrailingSlashIt(string $path): string
    {
        return rtrim($path, '/');
    }

    /**
     * 去重後加入 old/new 陣列
     *
     * @param string[] $olds
     * @param string[] $news
     */
    private function addIfNotExists(array &$olds, array &$news, string $old, string $new): void
    {
        if ($old === '') {
            return;
        }

        if (!in_array($old, $olds, true)) {
            $olds[] = $old;
            $news[] = $new;
        }
    }

    private function addRawIfNotExists(string $old, string $new): void
    {
        if (!in_array($old, $this->oldRawValues, true)) {
            $this->oldRawValues[] = $old;
            $this->newRawValues[] = $new;
        }
    }
}
