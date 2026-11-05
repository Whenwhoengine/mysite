<?php

declare(strict_types=1);

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use RuntimeException;

abstract class TestCase extends BaseTestCase
{
    /**
     * 測試只准連 MySQL 測試庫（mysite_app_test 或 mysite_app_test_<後綴>）。
     *
     * 事故紀錄（2026-11-05，docs/incidents/2026-11-05-test-wiped-dev-db.md）：Compose 注入的
     * DB_DATABASE 等變數位於 $_SERVER，而 phpunit.xml 的 <env force> 只改 $_ENV／putenv；
     * Laravel env() 先讀 $_SERVER，加上 service provider 在 boot 時就建立 DB 連線，
     * 導致 RefreshDatabase 對開發庫執行 db:wipe。因此必須在框架啟動「之前」覆寫三處環境，
     * 並在啟動後以實際連線確認資料庫名稱，不符即中止。
     */
    public function createApplication()
    {
        $db = getenv('MYSITE_TEST_DB') ?: 'mysite_app_test';
        if (preg_match('/^mysite_app_test(_[a-z0-9]{1,20})?$/', $db) !== 1) {
            throw new RuntimeException('MYSITE_TEST_DB 必須是 mysite_app_test 或 mysite_app_test_<後綴>');
        }

        $forced = [
            'APP_ENV' => 'testing',
            'DB_CONNECTION' => 'mysql',
            'DB_DATABASE' => $db,
            'CACHE_STORE' => 'array',
            'SESSION_DRIVER' => 'array',
            'QUEUE_CONNECTION' => 'sync',
            'MAIL_MAILER' => 'array',
            'BROADCAST_CONNECTION' => 'null',
        ];
        foreach ($forced as $key => $value) {
            $_SERVER[$key] = $value;
            $_ENV[$key] = $value;
            putenv($key.'='.$value);
        }

        $app = parent::createApplication();
        $app['config']->set('database.connections.mysql.database', $db);
        $app['db']->purge('mysql');

        $actual = $app['db']->connection('mysql')->selectOne('SELECT DATABASE() AS db')->db ?? null;
        if ($actual !== $db) {
            throw new RuntimeException("測試連線到非測試庫（實際：{$actual}），已中止以保護資料。");
        }

        return $app;
    }
}
