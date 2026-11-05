<?php

declare(strict_types=1);

/*
 * Artisan 指令與排程總表；各區塊分檔：
 *   routes/console-content.php  — 內容（可見性、事件處理、清理）
 *   routes/console-commerce.php — 商店（購物車／模擬訂單保存期限、稽核清理）
 * scheduler 由 app-scheduler 容器以 `schedule:work` 單一實例執行，並寫入心跳。
 */

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schedule;

Schedule::call(static function (): void {
    Cache::put('mysite:scheduler:heartbeat', now()->getTimestamp(), 600);
})->everyMinute()->name('scheduler-heartbeat')->withoutOverlapping(5);

foreach (['console-content', 'console-commerce'] as $section) {
    $file = __DIR__."/{$section}.php";
    if (is_file($file)) {
        require $file;
    }
}
