<?php

declare(strict_types=1);

namespace App\View\Components;

use App\Domain\Content\Dto\Image as ImageDto;
use App\Domain\Content\Dto\PodcastInfo;
use App\Domain\Content\Media\MediaPolicy;
use App\Domain\Content\Support\DisplayTime;
use Illuminate\View\Component;
use Illuminate\View\View;

/**
 * Podcast 播放器（UI-07）：`<audio controls preload="none">`，使用者主動播放。
 * 音訊來源 host 必須在 PODCAST_AUDIO_ALLOWED_HOSTS（https）或為 WP_PUBLIC_URL 同一來源；否則不播放、保留簡介。
 */
final class AudioPlayer extends Component
{
    public readonly ?string $src;

    public readonly ?string $mime;

    public readonly ?string $duration;

    public readonly ?string $durationIso;

    public readonly ?string $durationSpoken;

    public function __construct(
        public ?PodcastInfo $podcast = null,
        public string $title = '',
        public ?ImageDto $cover = null,
        public bool $preview = false,
    ) {
        $this->src = MediaPolicy::audioSource($podcast?->audioUrl);
        $this->mime = MediaPolicy::audioMime($podcast?->audioMime);
        $this->duration = DisplayTime::duration($podcast?->durationSeconds);
        $this->durationIso = DisplayTime::isoDuration($podcast?->durationSeconds);
        $this->durationSpoken = DisplayTime::durationSpoken($podcast?->durationSeconds);
    }

    /** 政策本體在 MediaPolicy（v1 §5.6）；保留方法名稱供既有呼叫端使用。 */
    public static function isAllowedSource(?string $url): bool
    {
        return MediaPolicy::isAllowedAudioSource($url);
    }

    public function mimeLabel(): ?string
    {
        return match ($this->mime) {
            'audio/mpeg', 'audio/mp3' => 'MP3',
            'audio/mp4', 'audio/x-m4a', 'audio/aac' => 'AAC／M4A',
            'audio/ogg', 'audio/opus' => 'Ogg',
            'audio/wav', 'audio/x-wav', 'audio/wave' => 'WAV',
            'audio/webm' => 'WebM',
            'audio/flac' => 'FLAC',
            default => null,
        };
    }

    public function render(): View
    {
        return view('components.audio-player');
    }
}
