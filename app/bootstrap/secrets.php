<?php

declare(strict_types=1);

/*
 * 在 Laravel 啟動前讀取 *_FILE 秘密（ADR-006）。
 * 秘密不合法時：staging／production 拒絕啟動；development／testing 也拒絕（避免落入示範值）。
 */

require_once __DIR__.'/../app/Support/Secrets/SecretFiles.php';

if (! defined('MYSITE_SECRETS_LOADED')) {
    define('MYSITE_SECRETS_LOADED', true);
    try {
        \App\Support\Secrets\SecretFiles::exportToEnvironment();
    } catch (\RuntimeException $e) {
        error_log('mysite: 秘密檔案不合法：'.$e->getMessage());
        if (PHP_SAPI === 'cli') {
            fwrite(STDERR, 'mysite: '.$e->getMessage().PHP_EOL);
            exit(20);
        }
        http_response_code(503);
        header('Content-Type: text/plain; charset=utf-8');
        echo "服務暫時無法使用。\n";
        exit;
    }
}
