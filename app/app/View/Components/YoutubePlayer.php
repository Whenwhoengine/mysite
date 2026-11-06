<?php

declare(strict_types=1);

namespace App\View\Components;

use App\Domain\Content\Dto\Image as ImageDto;
use App\Domain\Content\Dto\VideoInfo;
use App\Domain\Content\Media\MediaPolicy;
use App\Domain\Content\Support\DisplayTime;
use Illuminate\View\Component;
use Illuminate\View\View;

/**
 * YouTube 播放器（ADR-010、UI-03）：只用驗證過的 11 碼 ID 產生 youtube-nocookie.com 嵌入網址，
 * 點擊後才建立 iframe；無 JavaScript 時提供 YouTube 連結；ID 不合法顯示失效狀態；預覽模式只顯示連結。
 */
final class YoutubePlayer extends Component
{
    /** 政策本體在 MediaPolicy（v1 §5.6）；保留常數名稱供既有呼叫端使用。 */
    public const ID_PATTERN = MediaPolicy::YOUTUBE_ID_PATTERN;

    public readonly ?string $videoId;

    public readonly ?string $duration;

    public readonly ?string $durationIso;

    public function __construct(
        public ?VideoInfo $video = null,
        public string $title = '',
        public ?ImageDto $poster = null,
        public bool $preview = false,
    ) {
        $this->videoId = self::validId($video?->youtubeId);
        $this->duration = DisplayTime::duration($video?->durationSeconds);
        $this->durationIso = DisplayTime::isoDuration($video?->durationSeconds);
    }

    public static function validId(?string $id): ?string
    {
        return MediaPolicy::youtubeId($id);
    }

    public function embedUrl(): string
    {
        return MediaPolicy::youtubeEmbedUrl((string) $this->videoId);
    }

    public function watchUrl(): string
    {
        return MediaPolicy::youtubeWatchUrl((string) $this->videoId);
    }

    public function render(): View
    {
        return view('components.youtube-player');
    }
}
