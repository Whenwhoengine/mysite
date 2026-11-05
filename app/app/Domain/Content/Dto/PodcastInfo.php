<?php

declare(strict_types=1);

namespace App\Domain\Content\Dto;

/** Podcast 音訊欄位：來源 host 由播放器元件依 allowlist 檢查。 */
final readonly class PodcastInfo
{
    public function __construct(
        public ?string $audioUrl,
        public ?string $audioMime,
        public ?int $durationSeconds,
    ) {}
}
