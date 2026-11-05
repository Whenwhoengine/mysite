<?php

declare(strict_types=1);

/*
 * 內容工作包的 Artisan 指令與排程（由 app-scheduler 的 schedule:work 單一實例執行；不在 Web request 內執行）。
 */

use App\Domain\Content\Maintenance\ContentPruner;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('mysite:content-prune', function (ContentPruner $pruner): int {
    $result = $pruner->prune();
    foreach ($result as $what => $count) {
        $this->line("{$what}: {$count}");
    }

    return 0;
})->purpose('清理過期預覽 session／票券使用紀錄、30 天前的內容事件收據與過期內容快取列');

// 每小時清理一次（暫定頻率，見完工報告）；過期資料在讀取時本來就會被拒絕，清理只影響儲存量
Schedule::command('mysite:content-prune')
    ->hourlyAt(17)
    ->name('content-prune')
    ->withoutOverlapping(30);
