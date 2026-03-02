#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * WordPress SQL 域名替換腳本
 *
 * 仿照 All-in-One WP Migration 的域名替換核心邏輯
 * 支援序列化安全替換、Base64 解碼替換、多種 URL 編碼格式覆蓋
 *
 * 使用方式：
 *   php replace.php --old-url=https://old.com --new-url=https://new.com --sql=dump.sql
 *
 * 完整參數：
 *   --old-url=<URL>           舊站 URL（必填）
 *   --new-url=<URL>           新站 URL（必填）
 *   --sql=<path>              SQL 檔案路徑（必填）
 *   --old-prefix=<prefix>     舊資料表前綴，例：wp_（選填）
 *   --new-prefix=<prefix>     新資料表前綴（選填，需與 --old-prefix 一起使用）
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

use WpMigrate\Src\Replacer;
use WpMigrate\Src\UrlVariantBuilder;

// -------------------------------------------------------------------------
// 解析 CLI 參數
// -------------------------------------------------------------------------

$options = getopt('', [
    'old-url:',
    'new-url:',
    'sql:',
    'old-prefix:',
    'new-prefix:',
    'old-path:',
    'new-path:',
    'no-email-replace',
    'visual-composer',
    'oxygen-builder',
    'betheme-avada',
    'extra-old:',
    'extra-new:',
]);

// -------------------------------------------------------------------------
// 驗證必填參數
// -------------------------------------------------------------------------

$errors = [];

if (empty($options['old-url'])) {
    $errors[] = '缺少必填參數：--old-url';
}
if (empty($options['new-url'])) {
    $errors[] = '缺少必填參數：--new-url';
}
if (empty($options['sql'])) {
    $errors[] = '缺少必填參數：--sql';
}

if (!empty($errors)) {
    foreach ($errors as $error) {
        fwrite(STDERR, "[錯誤] {$error}\n");
    }
    fwrite(STDERR, "\n使用方式：\n");
    fwrite(STDERR, "  php replace.php --old-url=https://old.com --new-url=https://new.com --sql=dump.sql\n\n");
    fwrite(STDERR, "執行 php replace.php --help 查看完整說明\n");
    exit(1);
}

// 顯示說明
if (isset($options['help'])) {
    echo file_get_contents(__FILE__);
    exit(0);
}

$oldUrl        = (string) $options['old-url'];
$newUrl        = (string) $options['new-url'];
$sqlPath       = (string) $options['sql'];
$oldPrefix     = isset($options['old-prefix']) ? (string) $options['old-prefix'] : '';
$newPrefix     = isset($options['new-prefix']) ? (string) $options['new-prefix'] : '';
$oldPath       = isset($options['old-path'])   ? (string) $options['old-path']   : '';
$newPath       = isset($options['new-path'])   ? (string) $options['new-path']   : '';
$replaceEmail  = !isset($options['no-email-replace']);
$visualComposer = isset($options['visual-composer']);
$oxygenBuilder  = isset($options['oxygen-builder']);
$bethemeAvada   = isset($options['betheme-avada']);

// 驗證 SQL 檔案是否存在
if (!is_file($sqlPath)) {
    fwrite(STDERR, "[錯誤] SQL 檔案不存在：{$sqlPath}\n");
    exit(1);
}

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
// 建立替換對照表
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

// 加入自訂額外替換規則
foreach ($extraOld as $i => $extra) {
    $builder->addCustomPair($extra, $extraNew[$i]);
}

$replacePairs = $builder->build();

$oldValues    = $replacePairs['old'];
$newValues    = $replacePairs['new'];
$oldRawValues = $replacePairs['oldRaw'];
$newRawValues = $replacePairs['newRaw'];

echo "[資訊] 生成替換對照表：" . count($oldValues) . " 個替換規則（含多種 URL 編碼變體）\n";

// -------------------------------------------------------------------------
// 組裝 Replacer
// -------------------------------------------------------------------------

$replacer = new Replacer();
$replacer->setOldValues($oldValues);
$replacer->setNewValues($newValues);
$replacer->setOldRawValues($oldRawValues);
$replacer->setNewRawValues($newRawValues);
$replacer->setVisualComposer($visualComposer);
$replacer->setOxygenBuilder($oxygenBuilder);
$replacer->setBethemeOrAvada($bethemeAvada);

// 表前綴替換
if ($oldPrefix !== '' && $newPrefix !== '') {
    echo "[資訊] 表前綴替換：{$oldPrefix} → {$newPrefix}\n";
    $replacer->setOldTablePrefixes([$oldPrefix]);
    $replacer->setNewTablePrefixes([$newPrefix]);
}

// -------------------------------------------------------------------------
// 執行替換
// -------------------------------------------------------------------------

echo "[執行] 開始替換 SQL 檔案：{$sqlPath}\n";

$startTime = microtime(true);

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
