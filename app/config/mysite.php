<?php

declare(strict_types=1);

/*
 * mysite 自訂設定（唯一讀取位置）。每個鍵都由 App\Support\Config\MysiteConfig 驗證，
 * 並列於 docs/設定目錄.md。
 */

$csv = static fn (?string $v): array => array_values(array_filter(array_map('trim', explode(',', (string) $v)), static fn ($x) => $x !== ''));

return [
    'environment' => env('APP_ENV', 'production'),
    'display_timezone' => env('APP_TIMEZONE', 'Asia/Taipei'),
    'wp_public_url' => rtrim((string) env('WP_PUBLIC_URL', ''), '/'),
    'source_commit' => env('MYSITE_SOURCE_COMMIT', 'unknown'),
    'site_name' => env('MYSITE_SITE_NAME', 'mysite'),

    'content' => [
        'api_base_url' => rtrim((string) env('CONTENT_API_BASE_URL', 'http://wp-web:8080/wp-json/site-content/v1'), '/'),
        'private_api_base_url' => rtrim((string) env('CONTENT_PRIVATE_API_BASE_URL', 'https://wp-web:8443/wp-json/site-content/v1'), '/'),
        'private_api_ca_file' => env('CONTENT_PRIVATE_API_CA_FILE', '/run/secrets/internal_ca_cert'),
        // 容器內固定 WP 來源的私網例外 allowlist（只適用這些 host）
        'allowed_hosts' => $csv(env('CONTENT_ALLOWED_HOSTS', 'wp-web')),
        'service_username' => env('CONTENT_SERVICE_USERNAME', 'mysite-service'),
        'service_app_password' => env('CONTENT_SERVICE_APP_PASSWORD'),
        'connect_timeout' => (int) env('CONTENT_CONNECT_TIMEOUT_SECONDS', 2),
        'timeout' => (int) env('CONTENT_HTTP_TIMEOUT_SECONDS', 5),
        'max_attempts' => 2,
        'max_response_bytes' => (int) env('CONTENT_MAX_RESPONSE_BYTES', 2 * 1024 * 1024),
        'visibility_ttl' => (int) env('CONTENT_VISIBILITY_TTL_SECONDS', 30),
        'visibility_hard_limit' => 60,
        'body_ttl' => (int) env('CONTENT_BODY_CACHE_TTL_SECONDS', 300),
        'model_version' => 1,
        'event_key_id' => env('CONTENT_EVENT_KEY_ID', 'k1'),
        'event_hmac_key' => env('CONTENT_EVENT_HMAC_KEY'),
        'event_clock_skew' => 300,
        'podcast_audio_allowed_hosts' => $csv(env('PODCAST_AUDIO_ALLOWED_HOSTS', '')),
    ],

    'preview' => [
        'ttl' => (int) env('PREVIEW_TTL_SECONDS', 600),
        'max_ttl' => 900,
        'signing_key' => env('PREVIEW_SIGNING_KEY'),
        'issuer' => 'mysite-cms',
        'audience' => 'mysite-app',
        'cookie' => 'mysite_preview',
    ],

    'commerce' => [
        'mode' => env('COMMERCE_MODE', 'disabled'),
        'currency' => 'TWD',
        'max_line_quantity' => 10,
        'max_cart_lines' => 20,
    ],

    'retention' => [
        'simulation_days' => (int) env('SIMULATION_DATA_RETENTION_DAYS', 30),
        'cart_days' => (int) env('CART_RETENTION_DAYS', 7),
        'audit_days' => (int) env('AUDIT_RETENTION_DAYS', 90),
    ],

    'deploy' => [
        'environment' => env('DEPLOY_ENVIRONMENT'),
        'channel' => env('DEPLOY_CHANNEL'),
        'release_id' => env('RELEASE_ID', 'unreleased'),
    ],

    'trusted_proxies' => $csv(env('TRUSTED_PROXIES', '')),
    'diagnostics_token' => env('DIAGNOSTICS_TOKEN'),
];
