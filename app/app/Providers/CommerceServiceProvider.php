<?php

declare(strict_types=1);

namespace App\Providers;

use Illuminate\Support\ServiceProvider;

/**
 * 模擬商店與管理後臺的服務註冊（COMMERCE_MODE 旗標、policies）。由商店工作包負責。
 */
class CommerceServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        //
    }

    public function boot(): void
    {
        //
    }
}
