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
 *     直接對線上資料庫替換，使用 transaction，失敗自動 rollback
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
 *
 *   --- 模式二（直連 MySQL）---
 *   --wp-root=<path>          WordPress 根目錄路徑（含 wp-config.php 的目錄）
 *   --table-prefix=<prefix>   覆寫資料表前綴（選填，預設從 wp-config.php 讀取）
 *
 *   --- 共用選填參數 ---
 *   --old-path=<path>         舊站 wp-content 實體路徑（選填）
 *   --new-path=<path>         新站 wp-content 實體路徑（選填）
 *   --no-email-replace        停用 Email 域名替換（選填）
 *   --visual-composer         啟用 Visual Composer Base64 替換（選填）
 *   --oxygen-builder          啟用 Oxygen Builder Base64 替換（選填）
 *   --betheme-avada           啟用 BeTheme/Avada Base64 替換（選填）
 *   --extra-old=<value>       額外替換舊值（可重複使用多次）
 *   --extra-new=<value>       額外替換新值（可重複使用多次）
 */

require_once __DIR__ . '/src/UrlVariantBuilder.php';
require_once __DIR__ . '/src/SerializedReplacer.php';
require_once __DIR__ . '/src/Replacer.php';
require_once __DIR__ . '/src/WpConfigReader.php';
require_once __DIR__ . '/src/DatabaseReplacer.php';

use WpMigrate\Src\DatabaseReplacer;
use WpMigrate\Src\Replacer;
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
    // 模式二
    'wp-root:',
    'table-prefix:',
    // 共用
    'old-path:',
    'new-path:',
    'no-email-replace',
    'visual-composer',
    'oxygen-builder',
    'betheme-avada',
    'extra-old:',
    'extra-new:',
    'help',
]);

// 顯示說明
if (isset($options['help'])) {
    $header = array_slice(file(__FILE__), 2, 46);
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
$oldPath        = isset($options['old-path'])   ? (string) $options['old-path']   : '';
$newPath        = isset($options['new-path'])   ? (string) $options['new-path']   : '';
$replaceEmail   = !isset($options['no-email-replace']);
$visualComposer = isset($options['visual-composer']);
$oxygenBuilder  = isset($options['oxygen-builder']);
$bethemeAvada   = isset($options['betheme-avada']);

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
);

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
    $sqlPath   = (string) $options['sql'];
    $oldPrefix = isset($options['old-prefix']) ? (string) $options['old-prefix'] : '';
    $newPrefix = isset($options['new-prefix']) ? (string) $options['new-prefix'] : '';

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

$wpRoot      = rtrim((string) $options['wp-root'], '/');
$tablePrefix = isset($options['table-prefix']) ? (string) $options['table-prefix'] : '';

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
echo "[警告] 即將直接修改線上資料庫，操作不可逆（已啟用 transaction，失敗將 rollback）\n";

$dbReplacer = new DatabaseReplacer($configReader);
$dbReplacer->setOldValues($oldValues);
$dbReplacer->setNewValues($newValues);
$dbReplacer->setOldRawValues($oldRawValues);
$dbReplacer->setNewRawValues($newRawValues);
$dbReplacer->setVisualComposer($visualComposer);
$dbReplacer->setOxygenBuilder($oxygenBuilder);
$dbReplacer->setBethemeOrAvada($bethemeAvada);
$dbReplacer->setTablePrefix($tablePrefix);

try {
    $dbReplacer->process();
} catch (\RuntimeException $e) {
    fwrite(STDERR, "[錯誤] " . $e->getMessage() . "\n");
    exit(1);
}

$elapsed     = round(microtime(true) - $startTime, 2);
$stats       = $dbReplacer->getStats();
$totalRows   = array_sum($stats);

echo "[完成] 直連替換成功！耗時 {$elapsed}s，共更新 {$totalRows} 列\n";
exit(0);
