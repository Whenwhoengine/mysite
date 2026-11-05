<?php

declare(strict_types=1);

namespace App\Domain\Content\Dto;

/** 影片欄位：youtube_id 尚未驗證，由播放器元件以 11 碼規則驗證後才嵌入。 */
final readonly class VideoInfo
{
    public function __construct(
        public ?string $youtubeId,
        public ?string $youtubeUrl,
        public ?int $durationSeconds,
    ) {}
}
