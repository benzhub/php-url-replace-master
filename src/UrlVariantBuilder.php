<?php

declare(strict_types=1);

namespace WpMigrate\Src;

/**
 * 仿照 All-in-One WP Migration 的域名替換邏輯
 * 對每個 URL 產生多種編碼變體，確保資料庫中所有形式的舊域名都能被替換
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

    public function __construct(
        private readonly string $oldUrl,
        private readonly string $newUrl,
        private readonly bool $replaceEmail = true,
        private readonly string $oldPath = '',
        private readonly string $newPath = '',
    ) {}

    /**
     * 建立完整替換對照表
     *
     * @return array{old: string[], new: string[], oldRaw: string[], newRaw: string[]}
     */
    public function build(): array
    {
        $this->processUrl($this->oldUrl, $this->newUrl);

        if ($this->oldPath !== '' && $this->newPath !== '') {
            $this->addPathVariants($this->oldPath, $this->newPath);
        }

        return [
            'old'    => $this->oldValues,
            'new'    => $this->newValues,
            'oldRaw' => $this->oldRawValues,
            'newRaw' => $this->newRawValues,
        ];
    }

    /**
     * 新增自訂替換對（plain + URL encoded 三種變體）
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
     * 每個 URL × 3 種 scheme（http, https, 無）× 5 種編碼
     * = plain + urlencode + rawurlencode + JSON escaped + www互換
     */
    private function addUrlVariants(string $oldUrl, string $newUrl): void
    {
        $oldDomain = (string) parse_url($oldUrl, PHP_URL_HOST);
        $newDomain = (string) parse_url($newUrl, PHP_URL_HOST);
        $oldPath   = (string) parse_url($oldUrl, PHP_URL_PATH);
        $newPath   = (string) parse_url($newUrl, PHP_URL_PATH);
        $newScheme = (string) parse_url($newUrl, PHP_URL_SCHEME);

        // raw 替換：domain,'path/' 格式（用於 wp_options 的 domain/path 欄位對）
        $rawOld = sprintf("'%s','%s'", $oldDomain, $this->trailingSlashIt($oldPath));
        $rawNew = sprintf("'%s','%s'", $newDomain, $this->trailingSlashIt($newPath));
        $this->addRawIfNotExists($rawOld, $rawNew);

        // domain+path 帶引號格式（HTML 屬性值中）
        $this->addIfNotExists(
            $this->oldValues,
            $this->newValues,
            sprintf("='%s%s", $oldDomain, $this->untrailingSlashIt($oldPath)),
            sprintf("='%s%s", $newDomain, $this->untrailingSlashIt($newPath))
        );
        $this->addIfNotExists(
            $this->oldValues,
            $this->newValues,
            sprintf('="%s%s', $oldDomain, $this->untrailingSlashIt($oldPath)),
            sprintf('="%s%s', $newDomain, $this->untrailingSlashIt($newPath))
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

        // Email 替換：@old.com → @new.com
        if ($this->replaceEmail && $oldDomain !== '') {
            $newEmailDomain = str_ireplace('@www.', '@', sprintf('@%s', $newDomain));
            $this->addIfNotExists($this->oldValues, $this->newValues, sprintf('@%s', $oldDomain), $newEmailDomain);
        }
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
