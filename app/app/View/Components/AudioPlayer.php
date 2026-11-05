<?php

declare(strict_types=1);

namespace App\View\Components;

use App\Domain\Content\Dto\Image as ImageDto;
use App\Domain\Content\Dto\PodcastInfo;
use App\Domain\Content\Support\DisplayTime;
use Illuminate\View\Component;
use Illuminate\View\View;

/**
 * Podcast 播放器（UI-07）：`<audio controls preload="none">`，使用者主動播放。
 * 音訊來源 host 必須在 PODCAST_AUDIO_ALLOWED_HOSTS（https）或為 WP_PUBLIC_URL 同一來源；否則不播放、保留簡介。
 */
final class AudioPlayer extends Component
{
    private const MIME_ALLOWLIST = ['audio/mpeg', 'audio/mp3', 'audio/mp4', 'audio/x-m4a', 'audio/aac', 'audio/ogg', 'audio/opus', 'audio/wav', 'audio/x-wav', 'audio/wave', 'audio/webm', 'audio/flac'];

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
        $url = $podcast?->audioUrl;
        $this->src = self::isAllowedSource($url) ? $url : null;
        $mime = strtolower(trim((string) $podcast?->audioMime));
        $this->mime = in_array($mime, self::MIME_ALLOWLIST, true) ? $mime : null;
        $this->duration = DisplayTime::duration($podcast?->durationSeconds);
        $this->durationIso = DisplayTime::isoDuration($podcast?->durationSeconds);
        $this->durationSpoken = DisplayTime::durationSpoken($podcast?->durationSeconds);
    }

    public static function isAllowedSource(?string $url): bool
    {
        if ($url === null || $url === '' || preg_match('/[\x00-\x20\\\\]/', $url) === 1) {
            return false;
        }
        $parts = parse_url($url);
        if ($parts === false || isset($parts['user']) || isset($parts['pass'])) {
            return false;
        }
        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        $host = strtolower((string) ($parts['host'] ?? ''));
        if ($host === '' || ! in_array($scheme, ['http', 'https'], true)) {
            return false;
        }

        $wp = parse_url((string) config('mysite.wp_public_url'));
        if (is_array($wp) && strtolower((string) ($wp['host'] ?? '')) === $host
            && strtolower((string) ($wp['scheme'] ?? '')) === $scheme
            && ($wp['port'] ?? null) === ($parts['port'] ?? null)) {
            return true;
        }
        $allowed = array_map('strtolower', (array) config('mysite.content.podcast_audio_allowed_hosts', []));

        return $scheme === 'https' && in_array($host, $allowed, true);
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
