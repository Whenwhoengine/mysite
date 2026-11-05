<?php

declare(strict_types=1);

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use RuntimeException;

/**
 * 預設 seeder：只在 development／testing 載入合成示例資料；不建立任何固定帳密
 * （管理員一律以 `php artisan mysite:admin:create --password-file=` 建立）。
 */
class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        if (! app()->environment(['development', 'testing'])) {
            throw new RuntimeException('正式／staging 環境不得執行示例 seed。');
        }

        $this->call(DevelopmentSeeder::class);
    }
}
