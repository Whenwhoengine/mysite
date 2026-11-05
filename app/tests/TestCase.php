<?php

declare(strict_types=1);

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use RuntimeException;

abstract class TestCase extends BaseTestCase
{
    /**
     * 測試一律使用 MySQL 測試庫（名稱必須是 mysite_app_test 或 mysite_app_test_<後綴>），
     * 避免任何測試觸及開發或正式資料庫。可用 MYSITE_TEST_DB 指定並行用的獨立測試庫。
     */
    public function createApplication()
    {
        $app = parent::createApplication();
        $db = getenv('MYSITE_TEST_DB') ?: 'mysite_app_test';
        if (preg_match('/^mysite_app_test(_[a-z0-9]{1,20})?$/', $db) !== 1) {
            throw new RuntimeException('MYSITE_TEST_DB 必須是 mysite_app_test 或 mysite_app_test_<後綴>');
        }
        $app['config']->set('database.connections.mysql.database', $db);

        return $app;
    }
}
