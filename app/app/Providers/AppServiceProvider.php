<?php

declare(strict_types=1);

namespace App\Providers;

use App\Support\Config\MysiteConfig;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        //
    }

    public function boot(): void
    {
        // 啟動即驗證本案設定；不合法時拒絕服務，而不是默默以預設值運作（GOV-01）。
        if (! $this->app->runningUnitTests()) {
            MysiteConfig::validateOrFail((array) config('mysite'), [
                'app.debug' => (bool) config('app.debug'),
                'app.url' => (string) config('app.url'),
                'session.secure' => (bool) config('session.secure'),
                'database.default' => (string) config('database.default'),
                'db.username' => (string) config('database.connections.mysql.username'),
            ]);
        }

        Date::use(\Carbon\CarbonImmutable::class);
    }
}
