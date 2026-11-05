<?php

declare(strict_types=1);

namespace App\Support\Secrets;

use RuntimeException;

/**
 * `*_FILE` 秘密檔案契約（ADR-006、docs/設定目錄.md）。
 *
 * 本類別在框架啟動前就會被 bootstrap/secrets.php 直接 require，因此不得依賴 Composer 或 Laravel。
 * 規則：檔案存在、為一般檔、非空、單行、其他使用者不可讀寫；去除結尾換行。
 *
 * 例外（GAP-KEYRING-01）：
 * - 金鑰圈（KEYRINGS）：多行 `<key_id> <64 hex> <active|accept>`，由 KeyRing 嚴格驗證後以單行精簡格式匯出。
 * - APP_PREVIOUS_KEYS_FILE：檔案可不存在或為空（表示沒有舊金鑰）；有內容時每行或逗號分隔一把
 *   `base64:` 格式的 32 位元組金鑰，匯出為 Laravel 原生 APP_PREVIOUS_KEYS（逗號分隔，config app.previous_keys）。
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
        'DEPLOY_STATUS_TOKEN',
        // APP_KEY 輪替過渡期的舊金鑰（逗號分隔，Laravel 原生 app.previous_keys），只在輪替時提供
        'APP_PREVIOUS_KEYS',
        // 簽章金鑰圈（多行檔案 → 單行精簡格式），見 KEYRINGS
        'CONTENT_EVENT_HMAC_KEYRING',
        'PREVIEW_SIGNING_KEYRING',
    ];

    /** 以金鑰圈格式讀取的秘密（NAME_FILE 為多行金鑰圈檔）。 */
    public const KEYRINGS = [
        'CONTENT_EVENT_HMAC_KEYRING',
        'PREVIEW_SIGNING_KEYRING',
    ];

    /** 可選且可為空的秘密：檔案不存在或為空時視為未設定。 */
    public const OPTIONAL_EMPTY = [
        'APP_PREVIOUS_KEYS',
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

        return self::valueFor($name, $path);
    }

    /**
     * 依秘密種類讀取並驗證：金鑰圈 → 精簡格式；APP_PREVIOUS_KEYS → 逗號分隔（可為 null）；其他 → 單行值。
     *
     * @throws RuntimeException
     */
    public static function valueFor(string $name, string $path): ?string
    {
        if (in_array($name, self::KEYRINGS, true)) {
            return self::readKeyRingPath($name, $path)->toCompact();
        }
        if ($name === 'APP_PREVIOUS_KEYS') {
            return self::readPreviousKeysPath($path);
        }

        return self::readPath($name, $path);
    }

    /**
     * 讀取金鑰圈檔（權限規則同一般秘密；內容可多行），格式不合法即拋出例外。
     *
     * @throws RuntimeException
     */
    public static function readKeyRingPath(string $name, string $path): KeyRing
    {
        return KeyRing::parse("{$name}_FILE", self::readRaw($name, $path));
    }

    /**
     * 讀取 APP_PREVIOUS_KEYS_FILE：檔案不存在或內容為空 → null；否則回傳逗號分隔的舊金鑰。
     * 每把舊金鑰必須是 `base64:` 加上可解碼為 32 位元組（AES-256-CBC）的值。
     *
     * @throws RuntimeException
     */
    public static function readPreviousKeysPath(string $path): ?string
    {
        clearstatcache(true, $path);
        if (! file_exists($path) && ! is_link($path)) {
            return null;
        }
        $raw = self::readRaw('APP_PREVIOUS_KEYS', $path);
        $keys = [];
        foreach (preg_split('/[\r\n,]+/', $raw) ?: [] as $entry) {
            $entry = trim($entry);
            if ($entry === '') {
                continue;
            }
            $decoded = str_starts_with($entry, 'base64:') ? base64_decode(substr($entry, 7), true) : false;
            if ($decoded === false || strlen($decoded) !== 32) {
                throw new RuntimeException('APP_PREVIOUS_KEYS_FILE 第 '.(count($keys) + 1).' 把金鑰格式不合法（必須是 base64: 加 32 位元組金鑰）');
            }
            $keys[] = $entry;
        }

        return $keys === [] ? null : implode(',', $keys);
    }

    /**
     * @throws RuntimeException
     */
    public static function readPath(string $name, string $path): string
    {
        $value = rtrim(self::readRaw($name, $path), "\r\n");
        if (trim($value) === '') {
            throw new RuntimeException("{$name}_FILE 內容為空");
        }
        if (str_contains($value, "\n") || str_contains($value, "\r")) {
            throw new RuntimeException("{$name}_FILE 不得含多行內容");
        }

        return $value;
    }

    /**
     * 共用檔案檢查：存在、為一般檔、其他使用者不可讀寫、可讀取；回傳原始內容（不做格式驗證）。
     *
     * @throws RuntimeException
     */
    private static function readRaw(string $name, string $path): string
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
        return $raw;
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
            $value = self::valueFor($name, $file);
            if ($value === null) {
                // 可選秘密（APP_PREVIOUS_KEYS）檔案不存在或為空：不匯出
                continue;
            }
            $_ENV[$name] = $value;
            $_SERVER[$name] = $value;
            putenv($name.'='.$value);
            // 標記為本程式匯出（子程序繼承環境時不誤判為設定衝突）
            $marker = 'MYSITE_SECRET_EXPORTED_'.$name;
            $_ENV[$marker] = '1';
            $_SERVER[$marker] = '1';
            putenv($marker.'=1');
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
        return self::env('MYSITE_SECRET_EXPORTED_'.$name) === '1';
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
