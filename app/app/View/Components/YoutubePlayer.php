<?php

declare(strict_types=1);

namespace App\View\Components;

use App\Domain\Content\Dto\Image as ImageDto;
use App\Domain\Content\Dto\VideoInfo;
use App\Domain\Content\Support\DisplayTime;
use Illuminate\View\Component;
use Illuminate\View\View;

/**
 * YouTube 播放器（ADR-010、UI-03）：只用驗證過的 11 碼 ID 產生 youtube-nocookie.com 嵌入網址，
 * 點擊後才建立 iframe；無 JavaScript 時提供 YouTube 連結；ID 不合法顯示失效狀態；預覽模式只顯示連結。
 */
final class YoutubePlayer extends Component
{
    public const ID_PATTERN = '/^[A-Za-z0-9_-]{11}$/D';

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
        return is_string($id) && preg_match(self::ID_PATTERN, $id) === 1 ? $id : null;
    }

    public function embedUrl(): string
    {
        return 'https://www.youtube-nocookie.com/embed/'.$this->videoId.'?autoplay=1&rel=0';
    }

    public function watchUrl(): string
    {
        return 'https://www.youtube.com/watch?v='.$this->videoId;
    }

    public function render(): View
    {
        return view('components.youtube-player');
    }
}
