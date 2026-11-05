<?php

declare(strict_types=1);

namespace App\Domain\Content\Events;

use App\Domain\Content\ContentType;
use Carbon\CarbonImmutable;

/**
 * 內容事件 body（契約 §4）。parse() 對不合法結構回 null（→ 422 EVENT_INVALID）。
 */
final readonly class ContentEventPayload
{
    public const EVENT_TYPES = ['content.published', 'content.updated', 'content.unpublished', 'content.deleted'];

    public const STATUSES = ['publish', 'draft', 'pending', 'private', 'future', 'trash', 'deleted'];

    public const UUID_V4 = '/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/D';

    public function __construct(
        public string $eventId,
        public string $eventType,
        public ContentType $contentType,
        public int $contentId,
        public int $contentVersion,
        public string $status,
        public CarbonImmutable $occurredAt,
    ) {}

    public static function parse(string $raw): ?self
    {
        try {
            $data = json_decode($raw, true, 8, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return null;
        }
        if (! is_array($data) || array_is_list($data)) {
            return null;
        }
        $eventId = $data['event_id'] ?? null;
        $eventType = $data['event_type'] ?? null;
        $contentType = is_string($data['content_type'] ?? null) ? ContentType::tryFrom($data['content_type']) : null;
        $contentId = $data['content_id'] ?? null;
        $version = $data['content_version'] ?? null;
        $status = $data['status'] ?? null;
        $occurred = $data['occurred_at'] ?? null;

        if (! is_string($eventId) || preg_match(self::UUID_V4, $eventId) !== 1
            || ! in_array($eventType, self::EVENT_TYPES, true)
            || $contentType === null
            || ! is_int($contentId) || $contentId < 1
            || ! is_int($version) || $version < 0
            || ! in_array($status, self::STATUSES, true)
            || ! is_string($occurred) || preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/D', $occurred) !== 1) {
            return null;
        }
        $time = CarbonImmutable::createFromFormat('Y-m-d\TH:i:s\Z', $occurred, 'UTC');
        if ($time === null || $time->format('Y-m-d\TH:i:s\Z') !== $occurred) {
            return null;
        }

        return new self($eventId, $eventType, $contentType, $contentId, $version, $status, $time);
    }

    /** 事件是否表示「不再公開」（撤下、刪除或狀態非 publish）。 */
    public function isTakedown(): bool
    {
        return in_array($this->eventType, ['content.unpublished', 'content.deleted'], true) || $this->status !== 'publish';
    }
}
