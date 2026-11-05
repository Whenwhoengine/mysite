<?php

declare(strict_types=1);

namespace App\Domain\Content\Events;

use App\Domain\Content\Cache\ContentCache;
use App\Domain\Content\ContentType;
use Illuminate\Support\Facades\Log;

/**
 * 套用內容事件（API-09、API-15）：只做「失效」與「撤下標記」，絕不把內容標成可見。
 *
 * - 任何事件：清除該內容正文與可見性租約、內容世代號 +1（列表、首頁、搜尋、分類、選單、RSS、sitemap 立即失效）。
 * - 撤下類事件：記錄撤下版本；之後 WP 回報的可見版本必須大於它才再供應。
 * - 舊事件晚到：同樣只觸發重新確認（下一次請求問 WP），不會讓舊狀態復活已撤下內容。
 */
final class ContentEventApplier
{
    public function __construct(private readonly ContentCache $cache) {}

    public function apply(int $receiptId): void
    {
        $receipt = ContentEventReceipt::query()->find($receiptId);
        if ($receipt === null || $receipt->state === ContentEventReceipt::STATE_PROCESSED) {
            return;
        }
        $type = ContentType::from($receipt->content_type);
        $takedown = in_array($receipt->event_type, ['content.unpublished', 'content.deleted'], true) || $receipt->status !== 'publish';

        $this->cache->forgetContent($type, $receipt->content_id);
        if ($takedown) {
            $this->cache->markTakenDown($type, $receipt->content_id, $receipt->content_version);
        }
        $this->cache->bumpGeneration();

        $receipt->forceFill([
            'state' => ContentEventReceipt::STATE_PROCESSED,
            'processed_at' => now(),
            'last_error' => null,
        ])->save();

        Log::info('content_event_applied', [
            'event_id' => $receipt->event_id,
            'event_type' => $receipt->event_type,
            'content_type' => $type->value,
            'content_id' => $receipt->content_id,
            'content_version' => $receipt->content_version,
        ]);
    }

    public function markFailed(int $receiptId, string $error): void
    {
        ContentEventReceipt::query()->whereKey($receiptId)->update([
            'state' => ContentEventReceipt::STATE_FAILED,
            'last_error' => mb_substr($error, 0, 190),
            'updated_at' => now(),
        ]);
    }
}
