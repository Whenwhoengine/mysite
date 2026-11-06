<?php

declare(strict_types=1);

namespace Tests\Unit\Content;

use App\Domain\Content\Media\MediaPolicy;
use App\Support\Html\Sanitizer;
use App\View\Components\AudioPlayer;
use App\View\Components\YoutubePlayer;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * PAPI-06：媒體政策只有一份（MySite React 前端專案 v1 §5.6）。
 * 逐項驗證 MediaPolicy 的判定，並確認 Blade 元件沿用它（同一輸入、同一結果）。
 */
final class MediaPolicyTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config([
            'mysite.wp_public_url' => 'http://cms.mysite.localhost:8081',
            'mysite.content.podcast_audio_allowed_hosts' => ['media.example.org'],
        ]);
        $this->app->instance(Sanitizer::class, new Sanitizer('http://mysite.localhost:8080', 'http://cms.mysite.localhost:8081', 2 * 1024 * 1024));
    }

    /** @return array<string, array{?string, ?string}> */
    public static function youtubeIds(): array
    {
        return [
            '合法 11 碼' => ['dQw4w9WgXcQ', 'dQw4w9WgXcQ'],
            '含 - 與 _' => ['a-b_c-d_e-f', 'a-b_c-d_e-f'],
            '10 碼' => ['dQw4w9WgXc', null],
            '12 碼' => ['dQw4w9WgXcQQ', null],
            '含斜線' => ['dQw4w9/gXcQ', null],
            '結尾換行' => ["dQw4w9WgXcQ\n", null],
            '空字串' => ['', null],
            'null' => [null, null],
        ];
    }

    #[Test]
    #[DataProvider('youtubeIds')]
    public function youtube_id_policy_matches_blade_component(?string $input, ?string $expected): void
    {
        $this->assertSame($expected, MediaPolicy::youtubeId($input));
        $this->assertSame($expected, YoutubePlayer::validId($input));
    }

    #[Test]
    public function youtube_urls_use_nocookie_embed_and_watch_link(): void
    {
        $this->assertSame('https://www.youtube-nocookie.com/embed/dQw4w9WgXcQ?autoplay=1&rel=0', MediaPolicy::youtubeEmbedUrl('dQw4w9WgXcQ'));
        $this->assertSame('https://www.youtube.com/watch?v=dQw4w9WgXcQ', MediaPolicy::youtubeWatchUrl('dQw4w9WgXcQ'));
    }

    /** @return array<string, array{?string, bool}> */
    public static function audioSources(): array
    {
        return [
            'CMS 同源 http' => ['http://cms.mysite.localhost:8081/wp-content/uploads/a.mp3', true],
            '白名單 https' => ['https://media.example.org/ep1.mp3', true],
            '白名單主機但 http' => ['http://media.example.org/ep1.mp3', false],
            '不在白名單' => ['https://evil.example/ep1.mp3', false],
            'CMS 主機但埠不同' => ['http://cms.mysite.localhost:9999/a.mp3', false],
            '帶帳密' => ['https://user:pw@media.example.org/ep1.mp3', false],
            'javascript' => ['javascript:alert(1)', false],
            '含空白' => ['https://media.example.org/a b.mp3', false],
            '含反斜線' => ['https://media.example.org\\a.mp3', false],
            '空字串' => ['', false],
            'null' => [null, false],
        ];
    }

    #[Test]
    #[DataProvider('audioSources')]
    public function audio_source_policy_matches_blade_component(?string $url, bool $allowed): void
    {
        $this->assertSame($allowed, MediaPolicy::isAllowedAudioSource($url));
        $this->assertSame($allowed ? $url : null, MediaPolicy::audioSource($url));
        $this->assertSame($allowed, AudioPlayer::isAllowedSource($url));
    }

    #[Test]
    public function audio_mime_allowlist_is_case_insensitive_and_strict(): void
    {
        $this->assertSame('audio/mpeg', MediaPolicy::audioMime(' Audio/MPEG '));
        $this->assertSame('audio/x-m4a', MediaPolicy::audioMime('audio/x-m4a'));
        $this->assertNull(MediaPolicy::audioMime('video/mp4'));
        $this->assertNull(MediaPolicy::audioMime('audio/mpeg; codecs=mp3'));
        $this->assertNull(MediaPolicy::audioMime(''));
        $this->assertNull(MediaPolicy::audioMime(null));
    }

    /** @return array<string, array{?string, bool, bool}> */
    public static function imageUrls(): array
    {
        return [
            'https 任一主機' => ['https://cdn.example.org/a.jpg', false, true],
            'CMS 同源' => ['http://cms.mysite.localhost:8081/wp-content/uploads/a.jpg', false, true],
            '其他 http 主機' => ['http://cdn.example.org/a.jpg', false, false],
            '預覽：https 外部主機不許' => ['https://cdn.example.org/a.jpg', true, false],
            '預覽：CMS 同源可' => ['http://cms.mysite.localhost:8081/wp-content/uploads/a.jpg', true, true],
            'data URL' => ['data:image/png;base64,AAAA', false, false],
            'javascript' => ['javascript:alert(1)', false, false],
            'null' => [null, false, false],
        ];
    }

    #[Test]
    #[DataProvider('imageUrls')]
    public function image_url_policy_is_the_sanitizer_media_policy(?string $url, bool $preview, bool $allowed): void
    {
        $policy = $this->app->make(MediaPolicy::class);
        $this->assertSame($allowed ? $url : null, $policy->imageUrl($url, $preview));
        $this->assertSame($allowed, $this->app->make(Sanitizer::class)->isAllowedMediaUrl($url, $preview));
    }
}
