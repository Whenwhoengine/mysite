<?php

declare(strict_types=1);

namespace App\Support\Secrets;

use RuntimeException;

/**
 * `*_FILE` 秘密檔案契約（ADR-006、docs/設定目錄.md）。
 *
 * 本類別在框架啟動前就會被 bootstrap/secrets.php 直接 require，因此不得依賴 Composer 或 Laravel。
 * 規則：檔案存在、為一般檔、非空、單行、其他使用者不可讀寫；去除結尾換行。
 */
final class SecretFiles
{
    /** 讀檔後匯出到環境變數的秘密（NAME_FILE → NAME）。 */
    public const EXPORTED = [
        'APP_KEY',
        'DB_PASSWORD',
        'CONTENT_EVENT_HMAC_KEY',
        'PREVIEW_SIGNING_KEY',
        'CONTENT_SERVICE_APP_PASSWORD',
        'DIAGNOSTICS_TOKEN',
    ];

    /** staging／production 必須全部具備。 */
    public const REQUIRED_PRODUCTION = [
        'APP_KEY',
        'DB_PASSWORD',
        'CONTENT_EVENT_HMAC_KEY',
        'PREVIEW_SIGNING_KEY',
        'CONTENT_SERVICE_APP_PASSWORD',
    ];

    /** development／testing 至少要有的秘密。 */
    public const REQUIRED_DEVELOPMENT = [
        'APP_KEY',
        'DB_PASSWORD',
    ];

    /**
     * 讀取單一秘密檔；未設定 NAME_FILE 時回傳 null。
     *
     * @throws RuntimeException 檔案不合法時
     */
    public static function read(string $name): ?string
    {
        $path = self::env($name.'_FILE');
        if ($path === null) {
            return null;
        }

        return self::readPath($name, $path);
    }

    /**
     * @throws RuntimeException
     */
    public static function readPath(string $name, string $path): string
    {
        clearstatcache(true, $path);
        if (! is_file($path)) {
            throw new RuntimeException("{$name}_FILE 指向的檔案不存在");
        }
        $perms = fileperms($path);
        if ($perms === false) {
            throw new RuntimeException("{$name}_FILE 無法讀取權限");
        }
        if (($perms & 0007) !== 0) {
            throw new RuntimeException("{$name}_FILE 權限過寬（其他使用者可存取）");
        }
        $raw = @file_get_contents($path);
        if ($raw === false) {
            throw new RuntimeException("{$name}_FILE 無法讀取");
        }
        $value = rtrim($raw, "\r\n");
        if (trim($value) === '') {
            throw new RuntimeException("{$name}_FILE 內容為空");
        }
        if (str_contains($value, "\n") || str_contains($value, "\r")) {
            throw new RuntimeException("{$name}_FILE 不得含多行內容");
        }

        return $value;
    }

    /**
     * 讀取所有已設定的 *_FILE 並匯出到 $_ENV／$_SERVER／putenv。
     * 同時設定 NAME 與 NAME_FILE 視為設定衝突。
     *
     * @throws RuntimeException
     */
    public static function exportToEnvironment(): void
    {
        foreach (self::EXPORTED as $name) {
            $file = self::env($name.'_FILE');
            if ($file === null) {
                continue;
            }
            if (self::env($name) !== null && ! self::isExportedByUs($name)) {
                throw new RuntimeException("{$name} 與 {$name}_FILE 不可同時設定");
            }
            $value = self::readPath($name, $file);
            $_ENV[$name] = $value;
            $_SERVER[$name] = $value;
            putenv($name.'='.$value);
            $_ENV['MYSITE_SECRET_EXPORTED_'.$name] = '1';
        }
    }

    /**
     * 啟動前檢查：依環境確認必要秘密可讀。
     *
     * @throws RuntimeException
     */
    public static function assertRequiredForEnvironment(string $environment): void
    {
        $required = match ($environment) {
            'staging', 'production' => self::REQUIRED_PRODUCTION,
            'development', 'testing' => self::REQUIRED_DEVELOPMENT,
            default => throw new RuntimeException('未知環境：'.$environment),
        };
        foreach ($required as $name) {
            if (self::env($name.'_FILE') === null) {
                throw new RuntimeException("缺少必要秘密 {$name}_FILE");
            }
            self::read($name);
        }
        // 其他已設定的秘密檔也必須合法
        foreach (self::EXPORTED as $name) {
            self::read($name);
        }
    }

    private static function isExportedByUs(string $name): bool
    {
        return ($_ENV['MYSITE_SECRET_EXPORTED_'.$name] ?? null) === '1';
    }

    private static function env(string $name): ?string
    {
        $value = $_ENV[$name] ?? $_SERVER[$name] ?? getenv($name);
        if ($value === false || $value === null || $value === '') {
            return null;
        }

        return (string) $value;
    }
}
