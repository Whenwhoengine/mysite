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

// 金鑰圈狀態（GAP-KEYRING-01）：只輸出 `<key_id> <active|accept>`，絕不輸出金鑰；
// scripts/ops/rotate-secrets.sh 在重建容器後以此確認 Laravel 實際載入的金鑰圈與秘密檔一致。
Artisan::command('mysite:keyring {kind : event-hmac、preview 或 app-key}', function (string $kind): int {
    if ($kind === 'app-key') {
        // APP_KEY 輪替（APP_PREVIOUS_KEYS_FILE → app.previous_keys）：只輸出舊金鑰數量
        $this->line('previous_keys '.count(array_filter((array) config('app.previous_keys'))));

        return 0;
    }
    $ring = match ($kind) {
        'event-hmac' => app(\App\Domain\Content\Events\EventSignatureVerifier::class)->keyRing(),
        'preview' => app(\App\Domain\Preview\PreviewTicketVerifier::class)->keyRing(),
        default => null,
    };
    if ($ring === null) {
        $this->error('kind 只允許 event-hmac、preview 或 app-key');

        return 10;
    }
    foreach ($ring->states() as $id => $state) {
        $this->line("{$id} {$state}");
    }

    return 0;
})->purpose('列出目前載入的事件 HMAC／預覽簽章金鑰圈（key ID 與狀態）或舊 APP_KEY 數量；不輸出任何金鑰');

// 每小時清理一次（暫定頻率，見完工報告）；過期資料在讀取時本來就會被拒絕，清理只影響儲存量
Schedule::command('mysite:content-prune')
    ->hourlyAt(17)
    ->name('content-prune')
    ->withoutOverlapping(30);
