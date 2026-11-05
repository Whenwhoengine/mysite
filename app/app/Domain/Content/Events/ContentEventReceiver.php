<?php

declare(strict_types=1);

namespace App\Domain\Content\Events;

use App\Jobs\Content\ProcessContentEvent;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * 事件持久保存與去重（API-07）：先寫入 content_event_receipts，再交給 content 佇列（afterCommit）。
 */
final class ContentEventReceiver
{
    public const ACCEPTED = 'accepted';

    public const DUPLICATE = 'duplicate';

    public const CONFLICT = 'conflict';

    public function receive(ContentEventPayload $payload, string $rawBody, string $keyId): string
    {
        $sha = hash('sha256', $rawBody);

        try {
            DB::transaction(function () use ($payload, $sha, $keyId): void {
                $receipt = ContentEventReceipt::query()->create([
                    'event_id' => $payload->eventId,
                    'key_id' => $keyId,
                    'body_sha256' => $sha,
                    'event_type' => $payload->eventType,
                    'content_type' => $payload->contentType->value,
                    'content_id' => $payload->contentId,
                    'content_version' => $payload->contentVersion,
                    'status' => $payload->status,
                    'occurred_at' => $payload->occurredAt,
                    'state' => ContentEventReceipt::STATE_PENDING,
                    'received_count' => 1,
                ]);
                ProcessContentEvent::dispatch($receipt->id);
            });

            return self::ACCEPTED;
        } catch (UniqueConstraintViolationException) {
            $existing = ContentEventReceipt::query()->where('event_id', $payload->eventId)->first();
            if ($existing === null) {
                throw new \RuntimeException('content_event_receipt_race');
            }
            if (! hash_equals($existing->body_sha256, $sha)) {
                Log::warning('content_event_conflict', [
                    'event_id' => $payload->eventId,
                    'content_type' => $payload->contentType->value,
                    'content_id' => $payload->contentId,
                ]);

                return self::CONFLICT;
            }
            ContentEventReceipt::query()->whereKey($existing->id)->increment('received_count');
            if ($existing->state === ContentEventReceipt::STATE_FAILED) {
                // 前次處理失敗才重新排入；已處理或處理中不重複套用
                ProcessContentEvent::dispatch($existing->id);
            }

            return self::DUPLICATE;
        }
    }
}
