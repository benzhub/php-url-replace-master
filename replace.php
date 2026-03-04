#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * WordPress SQL 域名替換腳本
 *
 * 仿照 All-in-One WP Migration 的域名替換核心邏輯
 * 支援兩種模式：
 *
 *   【模式一：SQL 檔案模式】
 *     需要一份 dump 出來的 .sql 檔案，替換後覆寫原始 SQL 檔案
 *     php replace.php --old-url=https://old.com --new-url=https://new.com --sql=dump.sql
 *
 *   【模式二：直連 MySQL 模式】
 *     在 WordPress 根目錄執行，自動讀取 wp-config.php 取得 DB 連線，
 *     直接對線上資料庫替換，per-table transaction，失敗自動 rollback
 *     php replace.php --old-url=https://old.com --new-url=https://new.com --wp-root=/var/www/html
 *
 * 完整參數：
 *   --old-url=<URL>           舊站 URL（必填）
 *   --new-url=<URL>           新站 URL（必填）
 *
 *   --- 模式一（SQL 檔案）---
 *   --sql=<path>              SQL 檔案路徑
 *   --old-prefix=<prefix>     舊資料表前綴，例：wp_（選填）
 *   --new-prefix=<prefix>     新資料表前綴（選填，需與 --old-prefix 一起使用）
 *   --max-statement-mb=<MB>   大語句最大允許大小（MB），超過跳過 regex 替換，預設 64
 *
 *   --- 模式二（直連 MySQL）---
 *   --wp-root=<path>          WordPress 根目錄路徑（含 wp-config.php 的目錄）
 *   --table-prefix=<prefix>   覆寫資料表前綴（選填，預設從 wp-config.php 讀取）
 *   --chunk-size=<N>          每批讀取列數（預設 500）
 *   --memory-threshold-mb=<MB> 記憶體使用閾值（MB），超過時自動縮小 chunk，預設 768
 *   --skip-tables=suffix1,suffix2 額外跳過的資料表後綴（逗號分隔，不含前綴）
 *
 *   【模式二附加行為】
 *   替換資料庫後，自動掃描並更新 Elementor 磁碟 CSS 快取檔案：
 *     wp-content/uploads/elementor/css/*.css
 *     wp-content/uploads/sites/{N}/elementor/css/*.css（Multisite）
 *   解決 Elementor 以 status="file" 模式快取 CSS 時，背景圖 URL 未被替換導致
 *   輪播/區塊背景圖空白的問題。
 *
 *   --- 共用選填參數 ---
 *   --old-path=<path>         舊站 wp-content 實體路徑（選填）
 *   --new-path=<path>         新站 wp-content 實體路徑（選填）
 *   --old-uploads-url=<URL>   舊站 uploads URL（Multisite 選填）
 *   --new-uploads-url=<URL>   新站 uploads URL（Multisite 選填）
 *   --no-email-replace        停用 Email 域名替換（選填）
 *   --visual-composer         啟用 Visual Composer Base64 替換（選填）
 *   --oxygen-builder          啟用 Oxygen Builder Base64 替換（選填）
 *   --betheme-avada           啟用 BeTheme/Avada Base64 替換（選填）
 *   --no-loose                停用寬鬆模式（trailing slash + 跨 scheme 互換）
 *   --extra-old=<value>       額外替換舊值（可重複使用多次）
 *   --extra-new=<value>       額外替換新值（可重複使用多次）
 */

// Elementor 等 Page Builder 的 JSON 資料可能超過 PCRE 預設回溯限制
// 提高上限，避免超長 SQL 字串的 regex 匹配靜默失敗
ini_set('pcre.backtrack_limit', '10000000');
ini_set('pcre.recursion_limit', '10000000');

// 直連模式需要 pdo_mysql。若未載入且環境為 docker-php，自動安裝並重啟
if (!extension_loaded('pdo_mysql')) {
    $is_db_mode = false;
    foreach ($argv ?? [] as $arg) {
        if (str_starts_with($arg, '--wp-root')) {
            $is_db_mode = true;
            break;
        }
    }

    if ($is_db_mode) {
        $ext_install = trim((string) shell_exec('which docker-php-ext-install 2>/dev/null'));
        if ($ext_install !== '') {
            echo "[資訊] pdo_mysql 未載入，正在安裝（docker-php-ext-install）...\n";
            passthru('docker-php-ext-install pdo_mysql 2>&1');
            echo "[資訊] 安裝完成，重新執行腳本\n";
            $cmd = implode(' ', array_map('escapeshellarg', $argv));
            passthru("php {$cmd}");
            exit(0);
        }
    }
}

if (is_file(__DIR__ . '/vendor/autoload.php')) {
    require_once __DIR__ . '/vendor/autoload.php';
} else {
    require_once __DIR__ . '/src/UrlVariantBuilder.php';
    require_once __DIR__ . '/src/SerializedReplacer.php';
    require_once __DIR__ . '/src/Replacer.php';
    require_once __DIR__ . '/src/WpConfigReader.php';
    require_once __DIR__ . '/src/DatabaseReplacer.php';
}

use WpMigrate\Src\DatabaseReplacer;
use WpMigrate\Src\Replacer;
use WpMigrate\Src\SerializedReplacer;
use WpMigrate\Src\UrlVariantBuilder;
use WpMigrate\Src\WpConfigReader;

// -------------------------------------------------------------------------
// 解析 CLI 參數
// -------------------------------------------------------------------------

$options = getopt('', [
    'old-url:',
    'new-url:',
    // 模式一
    'sql:',
    'old-prefix:',
    'new-prefix:',
    'max-statement-mb:',
    // 模式二
    'wp-root:',
    'table-prefix:',
    'chunk-size:',
    'memory-threshold-mb:',
    'skip-tables:',
    // 共用
    'old-path:',
    'new-path:',
    'old-uploads-url:',
    'new-uploads-url:',
    'no-email-replace',
    'visual-composer',
    'oxygen-builder',
    'betheme-avada',
    'no-loose',
    'extra-old:',
    'extra-new:',
    'help',
]);

// 顯示說明
if (isset($options['help'])) {
    $header = array_slice(file(__FILE__), 2, 55);
    echo implode('', $header);
    exit(0);
}

// -------------------------------------------------------------------------
// 判斷執行模式
// -------------------------------------------------------------------------

$hasSql    = !empty($options['sql']);
$hasWpRoot = !empty($options['wp-root']);

if ($hasSql && $hasWpRoot) {
    fwrite(STDERR, "[錯誤] --sql 與 --wp-root 不可同時使用，請擇一\n");
    exit(1);
}

if (!$hasSql && !$hasWpRoot) {
    fwrite(STDERR, "[錯誤] 請指定執行模式：\n");
    fwrite(STDERR, "  模式一（SQL 檔案）：--sql=dump.sql\n");
    fwrite(STDERR, "  模式二（直連 MySQL）：--wp-root=/var/www/html\n");
    exit(1);
}

// -------------------------------------------------------------------------
// 驗證共用必填參數
// -------------------------------------------------------------------------

$errors = [];

if (empty($options['old-url'])) {
    $errors[] = '缺少必填參數：--old-url';
}
if (empty($options['new-url'])) {
    $errors[] = '缺少必填參數：--new-url';
}

if (!empty($errors)) {
    foreach ($errors as $error) {
        fwrite(STDERR, "[錯誤] {$error}\n");
    }
    fwrite(STDERR, "\n使用方式：\n");
    fwrite(STDERR, "  php replace.php --old-url=https://old.com --new-url=https://new.com --sql=dump.sql\n");
    fwrite(STDERR, "  php replace.php --old-url=https://old.com --new-url=https://new.com --wp-root=/var/www/html\n\n");
    fwrite(STDERR, "執行 php replace.php --help 查看完整說明\n");
    exit(1);
}

$oldUrl         = (string) $options['old-url'];
$newUrl         = (string) $options['new-url'];
$oldPath        = isset($options['old-path'])        ? (string) $options['old-path']        : '';
$newPath        = isset($options['new-path'])        ? (string) $options['new-path']        : '';
$oldUploadsUrl  = isset($options['old-uploads-url']) ? (string) $options['old-uploads-url'] : '';
$newUploadsUrl  = isset($options['new-uploads-url']) ? (string) $options['new-uploads-url'] : '';
$replaceEmail   = !isset($options['no-email-replace']);
$visualComposer = isset($options['visual-composer']);
$oxygenBuilder  = isset($options['oxygen-builder']);
$bethemeAvada   = isset($options['betheme-avada']);
$looseMode      = !isset($options['no-loose']);

// 驗證 URL 格式
foreach (['old-url' => $oldUrl, 'new-url' => $newUrl] as $param => $url) {
    if (!filter_var($url, FILTER_VALIDATE_URL)) {
        fwrite(STDERR, "[錯誤] {$param} 不是有效的 URL：{$url}\n");
        exit(1);
    }
}

// 解析 extra-old / extra-new（可多次重複的參數）
$extraOld = isset($options['extra-old'])
    ? (is_array($options['extra-old']) ? $options['extra-old'] : [$options['extra-old']])
    : [];
$extraNew = isset($options['extra-new'])
    ? (is_array($options['extra-new']) ? $options['extra-new'] : [$options['extra-new']])
    : [];

if (count($extraOld) !== count($extraNew)) {
    fwrite(STDERR, "[錯誤] --extra-old 與 --extra-new 的數量必須相同\n");
    exit(1);
}

// -------------------------------------------------------------------------
// 建立替換對照表（兩種模式共用）
// -------------------------------------------------------------------------

echo "[開始] 分析舊 URL：{$oldUrl}\n";
echo "[開始] 目標新 URL：{$newUrl}\n";

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

if (!$looseMode) {
    echo "[資訊] 寬鬆模式已停用（--no-loose）\n";
}

if ($oldUploadsUrl !== '' && $newUploadsUrl !== '') {
    echo "[資訊] Uploads URL 映射：{$oldUploadsUrl} → {$newUploadsUrl}\n";
}

foreach ($extraOld as $i => $extra) {
    $builder->addCustomPair($extra, $extraNew[$i]);
}

$replacePairs = $builder->build();

$oldValues    = $replacePairs['old'];
$newValues    = $replacePairs['new'];
$oldRawValues = $replacePairs['oldRaw'];
$newRawValues = $replacePairs['newRaw'];

echo "[資訊] 生成替換對照表：" . count($oldValues) . " 個替換規則（含多種 URL 編碼變體）\n";

$startTime = microtime(true);

// =========================================================================
// 模式一：SQL 檔案替換
// =========================================================================

if ($hasSql) {
    $sqlPath          = (string) $options['sql'];
    $oldPrefix        = isset($options['old-prefix'])      ? (string) $options['old-prefix']      : '';
    $newPrefix        = isset($options['new-prefix'])      ? (string) $options['new-prefix']      : '';
    $maxStatementMb   = isset($options['max-statement-mb']) ? (int) $options['max-statement-mb']  : 64;

    if (!is_file($sqlPath)) {
        fwrite(STDERR, "[錯誤] SQL 檔案不存在：{$sqlPath}\n");
        exit(1);
    }

    $replacer = new Replacer();
    $replacer->setOldValues($oldValues);
    $replacer->setNewValues($newValues);
    $replacer->setOldRawValues($oldRawValues);
    $replacer->setNewRawValues($newRawValues);
    $replacer->setVisualComposer($visualComposer);
    $replacer->setOxygenBuilder($oxygenBuilder);
    $replacer->setBethemeOrAvada($bethemeAvada);
    $replacer->setMaxStatementBytes($maxStatementMb * 1024 * 1024);

    if ($oldPrefix !== '' && $newPrefix !== '') {
        echo "[資訊] 表前綴替換：{$oldPrefix} → {$newPrefix}\n";
        $replacer->setOldTablePrefixes([$oldPrefix]);
        $replacer->setNewTablePrefixes([$newPrefix]);
    }

    echo "[執行] 模式一：替換 SQL 檔案 {$sqlPath}\n";

    try {
        $replacer->process($sqlPath);
    } catch (\RuntimeException $e) {
        fwrite(STDERR, "[錯誤] " . $e->getMessage() . "\n");
        exit(1);
    }

    $elapsed  = round(microtime(true) - $startTime, 2);
    $fileSize = round(filesize($sqlPath) / 1024 / 1024, 2);

    echo "[完成] 替換成功！耗時 {$elapsed}s，檔案大小 {$fileSize} MB\n";
    echo "[完成] 已覆寫原始檔案：{$sqlPath}\n";
    exit(0);
}

// =========================================================================
// 模式二：直連 MySQL 替換
// =========================================================================

$wpRoot            = rtrim((string) $options['wp-root'], '/');
$tablePrefix       = isset($options['table-prefix'])        ? (string) $options['table-prefix']       : '';
$chunkSize         = isset($options['chunk-size'])          ? (int) $options['chunk-size']             : 500;
$memoryThresholdMb = isset($options['memory-threshold-mb']) ? (int) $options['memory-threshold-mb']   : 768;

if (!is_dir($wpRoot)) {
    fwrite(STDERR, "[錯誤] WordPress 根目錄不存在：{$wpRoot}\n");
    exit(1);
}

echo "[執行] 模式二：直連 MySQL，讀取 wp-config.php from {$wpRoot}\n";

try {
    $configReader = new WpConfigReader($wpRoot);
    $configReader->parse();
} catch (\RuntimeException $e) {
    fwrite(STDERR, "[錯誤] " . $e->getMessage() . "\n");
    exit(1);
}

// 優先使用 CLI 參數覆寫 tablePrefix，否則從 wp-config.php 讀取
if ($tablePrefix === '') {
    $tablePrefix = $configReader->getTablePrefix();
}

echo "[資訊] 資料庫主機：" . $configReader->getDbHost() . "\n";
echo "[資訊] 資料庫名稱：" . $configReader->getDbName() . "\n";
echo "[資訊] 資料表前綴：{$tablePrefix}\n";
echo "[資訊] Chunk 大小：{$chunkSize} 列\n";
echo "[資訊] 記憶體閾值：{$memoryThresholdMb} MB\n";
echo "[警告] 即將直接修改線上資料庫，操作不可逆（每張表獨立 transaction，失敗自動 rollback）\n";

$dbReplacer = new DatabaseReplacer($configReader);
$dbReplacer->setOldValues($oldValues);
$dbReplacer->setNewValues($newValues);
$dbReplacer->setOldRawValues($oldRawValues);
$dbReplacer->setNewRawValues($newRawValues);
$dbReplacer->setVisualComposer($visualComposer);
$dbReplacer->setOxygenBuilder($oxygenBuilder);
$dbReplacer->setBethemeOrAvada($bethemeAvada);
$dbReplacer->setTablePrefix($tablePrefix);
$dbReplacer->setChunkSize($chunkSize);
$dbReplacer->setMemoryThreshold($memoryThresholdMb * 1024 * 1024);

if (!empty($options['skip-tables'])) {
    $extraSkip = array_filter(array_map('trim', explode(',', (string) $options['skip-tables'])));
    if (!empty($extraSkip)) {
        $dbReplacer->addSkipTableSuffixes(array_values($extraSkip));
    }
}

try {
    $dbReplacer->process();
} catch (\RuntimeException $e) {
    fwrite(STDERR, "[錯誤] " . $e->getMessage() . "\n");
    exit(1);
}

// -------------------------------------------------------------------------
// Elementor CSS 磁碟快取檔案替換
//
// 問題背景：
//   Elementor 將 slide/section 背景圖的 CSS 存成磁碟檔案
//   （wp-content/uploads/elementor/css/*.css）。
//   當 _elementor_css.status = "file" 時，postmeta 中 css 欄位為空字串，
//   實際 CSS（含 background-image URL）僅存在磁碟上，DB 替換無法觸及。
//   若這些 CSS 檔案未同步更新，頁面仍會以舊域名的背景圖 URL 來渲染，
//   導致圖片空白。
//
// 解決方式：
//   直連模式擁有 wp-root 路徑，可直接存取磁碟，因此在 DB 替換後
//   額外掃描並替換 Elementor CSS 快取目錄下所有 .css 檔案的 URL。
//   同時支援 Multisite（wp-content/uploads/sites/{N}/elementor/css/）。
// -------------------------------------------------------------------------

$elementorCssDirs = [];

// 標準單站
$stdCssDir = $wpRoot . '/wp-content/uploads/elementor/css';
if (is_dir($stdCssDir)) {
    $elementorCssDirs[] = $stdCssDir;
}

// Multisite：wp-content/uploads/sites/{N}/elementor/css
$sitesUploadsDir = $wpRoot . '/wp-content/uploads/sites';
if (is_dir($sitesUploadsDir)) {
    foreach (glob($sitesUploadsDir . '/*/elementor/css', GLOB_ONLYDIR) ?: [] as $dir) {
        $elementorCssDirs[] = $dir;
    }
}

if (!empty($elementorCssDirs)) {
    $cssScanned = 0;
    $cssUpdated = 0;

    foreach ($elementorCssDirs as $cssDir) {
        $cssFiles = glob($cssDir . '/*.css') ?: [];
        $cssScanned += count($cssFiles);

        foreach ($cssFiles as $cssFile) {
            $content = file_get_contents($cssFile);
            if ($content === false) {
                echo "[警告] Elementor CSS：無法讀取 {$cssFile}\n";
                continue;
            }

            $newContent = SerializedReplacer::replaceValues($oldValues, $newValues, $content);

            if ($newContent !== $content) {
                if (file_put_contents($cssFile, $newContent) === false) {
                    echo "[警告] Elementor CSS：無法寫入 {$cssFile}\n";
                    continue;
                }
                $cssUpdated++;
            }
        }
    }

    echo "[更新] Elementor CSS 快取：掃描 {$cssScanned} 個檔案，替換 {$cssUpdated} 個\n";
}

$elapsed   = round(microtime(true) - $startTime, 2);
$stats     = $dbReplacer->getStats();
$totalRows = array_sum($stats);

echo "[完成] 直連替換成功！耗時 {$elapsed}s，共更新 {$totalRows} 列\n";
exit(0);
