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
        // 映像建置階段（尚未注入任何執行期設定）可由 CLI 以 MYSITE_SKIP_CONFIG_VALIDATION=1 略過；
        // PHP-FPM 等非 CLI 一律驗證，容器 entrypoint 也會在啟動前再驗一次。
        $buildTimeCli = PHP_SAPI === 'cli' && getenv('MYSITE_SKIP_CONFIG_VALIDATION') === '1';
        if (! $this->app->runningUnitTests() && ! $buildTimeCli) {
            MysiteConfig::validateOrFail((array) config('mysite'), [
                'app.debug' => (bool) config('app.debug'),
                'app.url' => (string) config('app.url'),
                'session.secure' => (bool) config('session.secure'),
                'session.encrypt' => (bool) config('session.encrypt'),
                'database.default' => (string) config('database.default'),
                'db.username' => (string) config('database.connections.mysql.username'),
            ]);
        }

        Date::use(\Carbon\CarbonImmutable::class);
    }
}
