<?php

declare(strict_types=1);

namespace App\Domain\Content\Events;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;

/**
 * 內容事件接收紀錄（先持久保存才回 202；event_id 唯一）。
 *
 * @property int $id
 * @property string $event_id
 * @property string $key_id
 * @property string $body_sha256
 * @property string $event_type
 * @property string $content_type
 * @property int $content_id
 * @property int $content_version
 * @property string $status
 * @property CarbonImmutable $occurred_at
 * @property string $state
 * @property int $received_count
 * @property ?CarbonImmutable $processed_at
 * @property ?string $last_error
 */
final class ContentEventReceipt extends Model
{
    public const STATE_PENDING = 'pending';

    public const STATE_PROCESSED = 'processed';

    public const STATE_FAILED = 'failed';

    protected $table = 'content_event_receipts';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'content_id' => 'integer',
            'content_version' => 'integer',
            'received_count' => 'integer',
            'occurred_at' => 'immutable_datetime',
            'processed_at' => 'immutable_datetime',
        ];
    }
}
