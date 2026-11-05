<?php

declare(strict_types=1);

namespace App\Support\Config;

use DateTimeZone;
use RuntimeException;

/**
 * 驗證 mysite 自訂設定與框架關鍵設定（GOV-01）。
 * 回傳錯誤清單；validateOrFail() 在任何錯誤時拋出例外以拒絕啟動。
 */
final class MysiteConfig
{
    public const ENVIRONMENTS = ['development', 'testing', 'staging', 'production'];

    public const COMMERCE_MODES = ['disabled', 'simulation'];

    /**
     * @param  array<string, mixed>  $mysite  config('mysite')
     * @param  array<string, mixed>  $framework  ['app.debug'=>..,'app.url'=>..,'session.secure'=>..,'database.default'=>..,'db.username'=>..]
     * @return list<string>
     */
    public static function validate(array $mysite, array $framework): array
    {
        $errors = [];
        $env = (string) ($mysite['environment'] ?? '');
        $isProdLike = in_array($env, ['staging', 'production'], true);

        if (! in_array($env, self::ENVIRONMENTS, true)) {
            $errors[] = "APP_ENV 不合法：{$env}";
        }
        if ($isProdLike && ($framework['app.debug'] ?? false)) {
            $errors[] = 'staging／production 不得開啟 APP_DEBUG';
        }
        $appUrl = (string) ($framework['app.url'] ?? '');
        $wpUrl = (string) ($mysite['wp_public_url'] ?? '');
        foreach (['APP_URL' => $appUrl, 'WP_PUBLIC_URL' => $wpUrl] as $key => $url) {
            $scheme = parse_url($url, PHP_URL_SCHEME);
            if (! in_array($scheme, ['http', 'https'], true) || parse_url($url, PHP_URL_HOST) === null) {
                $errors[] = "{$key} 必須是完整 http(s) 網址";
            } elseif ($isProdLike && $scheme !== 'https') {
                $errors[] = "{$key} 在 staging／production 必須使用 https";
            }
        }
        if ($appUrl !== '' && $wpUrl !== '' && self::origin($appUrl) === self::origin($wpUrl)) {
            $errors[] = 'APP_URL 與 WP_PUBLIC_URL 不得為同一來源（cookie 與 canonical 必須分離）';
        }
        try {
            new DateTimeZone((string) ($mysite['display_timezone'] ?? ''));
        } catch (\Throwable) {
            $errors[] = 'APP_TIMEZONE 不是有效時區';
        }

        $content = (array) ($mysite['content'] ?? []);
        $allowed = (array) ($content['allowed_hosts'] ?? []);
        foreach (['CONTENT_API_BASE_URL' => $content['api_base_url'] ?? '', 'CONTENT_PRIVATE_API_BASE_URL' => $content['private_api_base_url'] ?? ''] as $key => $url) {
            $url = (string) $url;
            $scheme = parse_url($url, PHP_URL_SCHEME);
            $host = parse_url($url, PHP_URL_HOST);
            $path = (string) parse_url($url, PHP_URL_PATH);
            if (! in_array($scheme, ['http', 'https'], true) || $host === null) {
                $errors[] = "{$key} 必須是完整網址";

                continue;
            }
            if (! in_array($host, $allowed, true)) {
                $errors[] = "{$key} 的 host 不在 CONTENT_ALLOWED_HOSTS";
            }
            if (! str_ends_with($path, '/wp-json/site-content/v1')) {
                $errors[] = "{$key} 必須以 /wp-json/site-content/v1 結尾";
            }
        }
        if (parse_url((string) ($content['private_api_base_url'] ?? ''), PHP_URL_SCHEME) !== 'https') {
            $errors[] = 'CONTENT_PRIVATE_API_BASE_URL 必須使用 https';
        }
        self::range($errors, 'CONTENT_CONNECT_TIMEOUT_SECONDS', $content['connect_timeout'] ?? 0, 1, 10);
        self::range($errors, 'CONTENT_HTTP_TIMEOUT_SECONDS', $content['timeout'] ?? 0, 1, 30);
        self::range($errors, 'CONTENT_VISIBILITY_TTL_SECONDS', $content['visibility_ttl'] ?? 0, 1, 60);
        self::range($errors, 'CONTENT_BODY_CACHE_TTL_SECONDS', $content['body_ttl'] ?? 0, 1, 3600);
        if (! preg_match('/^[a-z0-9]{1,16}$/', (string) ($content['event_key_id'] ?? ''))) {
            $errors[] = 'CONTENT_EVENT_KEY_ID 格式不合法';
        }

        $preview = (array) ($mysite['preview'] ?? []);
        self::range($errors, 'PREVIEW_TTL_SECONDS', $preview['ttl'] ?? 0, 60, 900);

        $mode = (string) (($mysite['commerce'] ?? [])['mode'] ?? '');
        if ($mode === 'real') {
            $errors[] = 'COMMERCE_MODE=real 不被允許：本案只有模擬商店';
        } elseif (! in_array($mode, self::COMMERCE_MODES, true)) {
            $errors[] = "COMMERCE_MODE 不合法：{$mode}";
        }

        $ret = (array) ($mysite['retention'] ?? []);
        self::range($errors, 'SIMULATION_DATA_RETENTION_DAYS', $ret['simulation_days'] ?? 0, 1, 365);
        self::range($errors, 'CART_RETENTION_DAYS', $ret['cart_days'] ?? 0, 1, 90);
        self::range($errors, 'AUDIT_RETENTION_DAYS', $ret['audit_days'] ?? 0, 7, 730);

        if (($framework['database.default'] ?? null) !== 'mysql' && $env !== 'testing') {
            $errors[] = 'DB_CONNECTION 必須是 mysql';
        }
        if (strtolower((string) ($framework['db.username'] ?? '')) === 'root') {
            $errors[] = 'Laravel 不得使用資料庫 root 帳號';
        }
        if ($isProdLike && ! ($framework['session.secure'] ?? false)) {
            $errors[] = 'staging／production 必須 SESSION_SECURE_COOKIE=true';
        }
        if ($isProdLike && ! ($framework['session.encrypt'] ?? false)) {
            $errors[] = 'staging／production 必須 SESSION_ENCRYPT=true（Session 內含訂單查詢 token 明文）';
        }
        if ($isProdLike) {
            foreach (['event_hmac_key' => 'CONTENT_EVENT_HMAC_KEY', 'service_app_password' => 'CONTENT_SERVICE_APP_PASSWORD'] as $k => $label) {
                if (empty($content[$k])) {
                    $errors[] = "缺少必要秘密 {$label}";
                }
            }
            if (empty($preview['signing_key'])) {
                $errors[] = '缺少必要秘密 PREVIEW_SIGNING_KEY';
            }
            $deploy = (array) ($mysite['deploy'] ?? []);
            if (($deploy['environment'] ?? null) !== $env) {
                $errors[] = 'DEPLOY_ENVIRONMENT 必須與 APP_ENV 一致';
            }
        }

        return $errors;
    }

    /**
     * @param  array<string, mixed>  $mysite
     * @param  array<string, mixed>  $framework
     */
    public static function validateOrFail(array $mysite, array $framework): void
    {
        $errors = self::validate($mysite, $framework);
        if ($errors !== []) {
            throw new RuntimeException('mysite 設定不合法：'.implode('；', $errors));
        }
    }

    /** @param list<string> $errors */
    private static function range(array &$errors, string $key, mixed $value, int $min, int $max): void
    {
        if (! is_int($value) || $value < $min || $value > $max) {
            $errors[] = "{$key} 必須介於 {$min} 與 {$max}";
        }
    }

    private static function origin(string $url): string
    {
        $p = parse_url($url);

        return strtolower(($p['scheme'] ?? '').'://'.($p['host'] ?? '').':'.($p['port'] ?? ''));
    }
}
