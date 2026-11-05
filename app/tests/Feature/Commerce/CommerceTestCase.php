<?php

declare(strict_types=1);

namespace Tests\Feature\Commerce;

use RuntimeException;
use Tests\TestCase;

/**
 * 商店／後臺測試基底：在框架啟動「之前」把測試用環境變數同步到 $_SERVER／$_ENV／putenv。
 *
 * 原因：tools 容器的 compose 環境變數（DB_DATABASE=mysite_app、CACHE_STORE=database…）存在 $_SERVER，
 * Laravel 的 env() 會先讀 $_SERVER，phpunit.xml 的 <env force> 蓋不掉；服務提供者 boot 時的 RateLimiter
 * 會解析 database 快取並以「開發庫」建立連線，之後 Tests\TestCase 才改庫名已來不及，RefreshDatabase 會清空開發庫。
 * 這裡先同步環境，再在建立後斷言實際連線一定是 mysite_app_test*，否則拒絕執行。
 */
abstract class CommerceTestCase extends TestCase
{
    public function createApplication()
    {
        $db = self::resolveTestDatabase();
        foreach ([
            'APP_ENV' => 'testing',
            'DB_CONNECTION' => 'mysql',
            'DB_DATABASE' => $db,
            'CACHE_STORE' => 'array',
            'SESSION_DRIVER' => 'array',
            'QUEUE_CONNECTION' => 'sync',
            'MAIL_MAILER' => 'array',
            'COMMERCE_MODE' => 'disabled',
            'APP_DEBUG' => 'false',
            'BCRYPT_ROUNDS' => '4',
        ] as $key => $value) {
            putenv("{$key}={$value}");
            $_ENV[$key] = $value;
            $_SERVER[$key] = $value;
        }

        $app = parent::createApplication();
        $live = $app['db']->connection()->getDatabaseName();
        if ($live !== $db || $app['config']->get('cache.default') !== 'array') {
            throw new RuntimeException("測試連線不是測試庫（實際：{$live}），拒絕執行以免清空開發資料。");
        }

        return $app;
    }

    public static function resolveTestDatabase(): string
    {
        $db = getenv('MYSITE_TEST_DB') ?: 'mysite_app_test';
        if (preg_match('/^mysite_app_test(_[a-z0-9]{1,20})?$/', $db) !== 1) {
            throw new RuntimeException('MYSITE_TEST_DB 必須是 mysite_app_test 或 mysite_app_test_<後綴>');
        }

        return $db;
    }
}
