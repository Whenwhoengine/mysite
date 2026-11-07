<?php

declare(strict_types=1);

namespace App\Domain\Content\Media;

use App\Support\Html\Sanitizer;

/**
 * 媒體來源的唯一政策（MySite React 前端專案 v1 §5.6、PAPI-06）。
 *
 * 原本寫在 Blade 元件類別裡的三項政策抽到這裡，Blade 元件與公開 API 都呼叫同一份，
 * 讓兩種畫法（blade／next）拿到的值完全相同：
 * 1. YouTube 影片 ID：只接受 11 碼 `[A-Za-z0-9_-]`。
 * 2. 圖片網址：沿用 `Sanitizer::isAllowedMediaUrl`（https 任一主機，或與 WP_PUBLIC_URL 同源；預覽只許後者）。
 * 3. 音訊：來源須與 WP_PUBLIC_URL 同源，或為 PODCAST_AUDIO_ALLOWED_HOSTS 列出的 https 主機；MIME 只接受白名單。
 * 不合格一律回 null，由呼叫端顯示失效狀態（Blade）或輸出 `*_unavailable`（API）。
 */
final class MediaPolicy
{
    public const YOUTUBE_ID_PATTERN = '/^[A-Za-z0-9_-]{11}$/D';

    public const AUDIO_MIME_ALLOWLIST = ['audio/mpeg', 'audio/mp3', 'audio/mp4', 'audio/x-m4a', 'audio/aac', 'audio/ogg', 'audio/opus', 'audio/wav', 'audio/x-wav', 'audio/wave', 'audio/webm', 'audio/flac'];

    public function __construct(private readonly Sanitizer $sanitizer) {}

    public static function youtubeId(?string $id): ?string
    {
        return is_string($id) && preg_match(self::YOUTUBE_ID_PATTERN, $id) === 1 ? $id : null;
    }

    public static function youtubeEmbedUrl(string $validId): string
    {
        return 'https://www.youtube-nocookie.com/embed/'.$validId.'?autoplay=1&rel=0';
    }

    public static function youtubeWatchUrl(string $validId): string
    {
        return 'https://www.youtube.com/watch?v='.$validId;
    }

    public function imageUrl(?string $url, bool $preview = false): ?string
    {
        return $this->sanitizer->isAllowedMediaUrl($url, $preview) ? $url : null;
    }

    public static function audioSource(?string $url): ?string
    {
        return self::isAllowedAudioSource($url) ? $url : null;
    }

    public static function audioMime(?string $mime): ?string
    {
        $mime = strtolower(trim((string) $mime));

        return in_array($mime, self::AUDIO_MIME_ALLOWLIST, true) ? $mime : null;
    }

    public static function isAllowedAudioSource(?string $url): bool
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
}
