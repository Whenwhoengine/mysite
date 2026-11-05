<?php

declare(strict_types=1);

namespace Tests\Feature\Content\Concerns;

use App\Domain\Content\Events\EventSignatureVerifier;
use App\Domain\Content\Http\UrlGuard;
use App\Domain\Preview\PreviewTicketVerifier;
use App\Support\Html\Sanitizer;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;

/**
 * 依 docs/API契約.md 產生 WP site-content/v1 假回應（只用於測試；正式 Controller 不含假資料）。
 */
trait FakesWordPress
{
    protected const WP_BASE = 'http://wp-web:8080/wp-json/site-content/v1';

    protected const WP_PRIVATE_BASE = 'https://wp-web:8443/wp-json/site-content/v1';

    protected const EVENT_KEY = 'test-event-hmac-key-0123456789abcdef';

    protected const PREVIEW_KEY = 'test-preview-signing-key-0123456789abcdef';

    /** @var list<string> 依序記錄送往 WP 的請求（路徑＋查詢字串） */
    protected array $wpCalls = [];

    /** @var array<string, mixed> 目前的假路由（可在同一測試中替換） */
    protected array $wpRoutes = [];

    private bool $wpFakeInstalled = false;

    protected function configureContent(): void
    {
        $ca = storage_path('framework/testing/mysite-test-ca.pem');
        if (! is_dir(dirname($ca))) {
            mkdir(dirname($ca), 0775, true);
        }
        file_put_contents($ca, "-----BEGIN CERTIFICATE-----\nTEST\n-----END CERTIFICATE-----\n");

        config([
            'app.debug' => false,
            'app.url' => 'http://mysite.localhost:8080',
            'mysite.wp_public_url' => 'http://cms.mysite.localhost:8081',
            'mysite.site_name' => 'mysite',
            'mysite.commerce.mode' => 'disabled',
            'mysite.content.api_base_url' => self::WP_BASE,
            'mysite.content.private_api_base_url' => self::WP_PRIVATE_BASE,
            'mysite.content.private_api_ca_file' => $ca,
            'mysite.content.allowed_hosts' => ['wp-web'],
            'mysite.content.service_username' => 'mysite-service',
            'mysite.content.service_app_password' => 'test-service-app-password',
            'mysite.content.connect_timeout' => 2,
            'mysite.content.timeout' => 5,
            'mysite.content.max_attempts' => 2,
            'mysite.content.max_response_bytes' => 2 * 1024 * 1024,
            'mysite.content.visibility_ttl' => 30,
            'mysite.content.visibility_hard_limit' => 60,
            'mysite.content.body_ttl' => 300,
            'mysite.content.event_key_id' => 'k1',
            'mysite.content.event_hmac_key' => self::EVENT_KEY,
            'mysite.content.event_clock_skew' => 300,
            'mysite.content.podcast_audio_allowed_hosts' => ['media.example.org'],
            'mysite.preview.signing_key' => self::PREVIEW_KEY,
            'mysite.preview.ttl' => 600,
            'mysite.preview.max_ttl' => 900,
            'cache.default' => 'array',
        ]);
        $this->app->forgetInstance(UrlGuard::class);
        $this->app->forgetInstance(Sanitizer::class);
        $this->wpCalls = [];
        Http::preventStrayRequests();
        $this->withoutVite();
    }

    /**
     * 以路徑樣式（Str::is，不含查詢字串）路由假回應；未定義的路徑回 WP 的 rest_no_route 404。
     *
     * @param  array<string, mixed>  $routes  例：['/posts' => fn (HttpRequest $r) => ..., '/content/post/1/visibility' => [...]]
     */
    protected function fakeWordPress(array $routes): void
    {
        // Controller 實例會被路由快取（連同其注入的 HTTP client），因此只安裝一次 fake，之後只替換路由表
        $this->wpRoutes = $routes;
        if ($this->wpFakeInstalled) {
            return;
        }
        $this->wpFakeInstalled = true;
        Http::fake(function (HttpRequest $request) {
            $url = $request->url();
            $path = (string) parse_url($url, PHP_URL_PATH);
            foreach (['/wp-json/site-content/v1'] as $prefix) {
                if (str_starts_with($path, $prefix)) {
                    $path = substr($path, strlen($prefix));
                }
            }
            $query = (string) parse_url($url, PHP_URL_QUERY);
            $this->wpCalls[] = $path.($query !== '' ? '?'.$query : '');
            foreach ($this->wpRoutes as $pattern => $response) {
                if (Str::is($pattern, $path)) {
                    $value = is_callable($response) && ! is_array($response) ? $response($request) : $response;

                    return is_array($value) ? Http::response($value, 200) : $value;
                }
            }

            return Http::response(['code' => 'rest_no_route', 'message' => 'No route', 'data' => ['status' => 404]], 404);
        });
    }

    /** 丟棄先前的 Http::fake 設定與紀錄（Laravel 的 fake 會累加，迴圈測試需先重設）。 */
    protected function resetHttpFake(): void
    {
        $this->app->forgetInstance(Factory::class);
        Http::clearResolvedInstance(Factory::class);
        Http::preventStrayRequests();
    }

    /** @return list<string> */
    protected function wpCallsMatching(string $pattern): array
    {
        return array_values(array_filter($this->wpCalls, static fn (string $c): bool => Str::is($pattern, $c)));
    }

    /** @return array<string, mixed> */
    protected static function meta(array $extra = []): array
    {
        return array_merge(['api_version' => 'v1', 'model_version' => 1, 'generated_at' => '2026-11-05T01:00:00Z'], $extra);
    }

    /**
     * 契約 §2 的內容物件。
     *
     * @return array<string, mixed>
     */
    protected static function wpItem(array $override = [], bool $withBody = false): array
    {
        $type = $override['type'] ?? 'post';
        $item = [
            'id' => 101,
            'type' => $type,
            'slug' => 'sample-article',
            'title' => '示例文章標題',
            'excerpt' => '這是一段示例摘要。',
            'author' => ['display_name' => '編輯部'],
            'categories' => [['id' => 3, 'slug' => 'news', 'name' => '新聞']],
            'tags' => [['id' => 7, 'slug' => 'demo', 'name' => '示例']],
            'cover' => ['url' => 'http://cms.mysite.localhost:8081/wp-content/uploads/2026/11/a.png', 'alt' => '封面替代文字', 'width' => 1200, 'height' => 630],
            'published_at' => '2026-11-01T02:00:00Z',
            'modified_at' => '2026-11-02T03:00:00Z',
            'content_version' => 5,
            'is_sample' => true,
        ];
        if ($type === 'video') {
            $item['video'] = ['youtube_id' => 'dQw4w9WgXcQ', 'youtube_url' => 'https://www.youtube.com/watch?v=dQw4w9WgXcQ', 'duration_seconds' => 245];
        }
        if ($type === 'podcast') {
            $item['podcast'] = ['audio_url' => 'http://cms.mysite.localhost:8081/wp-content/uploads/2026/11/ep1.wav', 'audio_mime' => 'audio/wav', 'duration_seconds' => 1800];
        }
        if ($withBody) {
            $item['body_html'] = '<p>第一段正文。</p><h2>小標</h2><p>第二段正文。</p>';
        }

        return array_merge($item, $override);
    }

    /** @return array<string, mixed> */
    protected static function listEnvelope(array $items, int $page = 1, int $perPage = 12, ?int $total = null): array
    {
        $total ??= count($items);

        return [
            'data' => array_values($items),
            'meta' => self::meta(['page' => $page, 'per_page' => $perPage, 'total' => $total, 'total_pages' => (int) ceil($total / max(1, $perPage))]),
        ];
    }

    /** @return array<string, mixed> */
    protected static function detailEnvelope(array $item): array
    {
        return ['data' => $item, 'meta' => self::meta()];
    }

    /** @return array<string, mixed> */
    protected static function visibilityEnvelope(string $type, int $id, bool $visible, int $version): array
    {
        return ['data' => ['type' => $type, 'id' => $id, 'visible' => $visible, 'content_version' => $version, 'checked_at' => '2026-11-05T01:00:00Z'], 'meta' => self::meta()];
    }

    /** 單篇內容的完整假 WP：slug 查詢（含正文）＋可見性端點。 */
    protected function fakeSingleItem(array $item, bool &$visible = true, ?int &$version = null, bool &$down = false): void
    {
        $version ??= $item['content_version'];
        $endpoint = ['post' => 'posts', 'page' => 'pages', 'video' => 'videos', 'podcast' => 'podcasts'][$item['type']];
        $this->fakeWordPress([
            '/'.$endpoint => function (HttpRequest $r) use ($item, &$visible, &$down) {
                if ($down) {
                    throw new ConnectionException('cURL error 7: Failed to connect to wp-web port 8080');
                }
                if (($r->data()['slug'] ?? null) === $item['slug'] && $visible) {
                    return self::listEnvelope([$item], 1, 1);
                }

                return Http::response(['code' => 'site_content_not_found', 'message' => '找不到內容', 'data' => ['status' => 404]], 404);
            },
            '/content/*/*/visibility' => function () use ($item, &$visible, &$version, &$down) {
                if ($down) {
                    throw new ConnectionException('cURL error 7: Failed to connect to wp-web port 8080');
                }

                return self::visibilityEnvelope($item['type'], $item['id'], $visible, $version);
            },
            '/menus/*' => ['data' => ['location' => 'primary', 'items' => []], 'meta' => self::meta()],
            '/site' => ['data' => ['name' => 'mysite', 'tagline' => '影音創作紀錄', 'author_bio' => '', 'social_links' => [], 'logo' => null, 'is_sample' => true], 'meta' => self::meta()],
        ]);
    }

    /** @return array<string, string> 事件 header */
    protected static function signedEventHeaders(string $body, string $eventId, ?int $timestamp = null, string $keyId = 'k1', string $key = self::EVENT_KEY): array
    {
        $timestamp ??= now()->getTimestamp();

        return [
            'Content-Type' => 'application/json',
            'X-Mysite-Key-Id' => $keyId,
            'X-Mysite-Timestamp' => (string) $timestamp,
            'X-Mysite-Event-Id' => $eventId,
            'X-Mysite-Signature' => EventSignatureVerifier::sign($key, (string) $timestamp, $eventId, $body),
        ];
    }

    protected static function eventBody(array $override = []): string
    {
        return (string) json_encode(array_merge([
            'event_id' => '3f1c2b4a-5d6e-4f70-8a9b-0c1d2e3f4a5b',
            'event_type' => 'content.updated',
            'content_type' => 'post',
            'content_id' => 101,
            'content_version' => 6,
            'status' => 'publish',
            'occurred_at' => '2026-11-05T01:23:45Z',
        ], $override), JSON_UNESCAPED_SLASHES);
    }

    /** 送出原始 body 的事件請求（不經 JSON 重新編碼）。 */
    protected function postEvent(string $body, array $headers): TestResponse
    {
        $server = [];
        foreach ($headers as $name => $value) {
            $key = strtoupper(str_replace('-', '_', $name));
            $server[$key === 'CONTENT_TYPE' ? $key : 'HTTP_'.$key] = $value;
        }
        $server['REMOTE_ADDR'] = '127.0.0.1';

        return $this->call('POST', '/internal/content-events/v1', [], [], [], $server, $body);
    }

    /** @return array<string, mixed> 有效預覽 claims */
    protected static function previewClaims(array $override = []): array
    {
        $now = now()->getTimestamp();

        return array_merge([
            'iss' => 'mysite-cms',
            'aud' => 'mysite-app',
            'typ' => 'preview',
            'ctype' => 'post',
            'cid' => 123,
            'sid' => str_repeat('a1', 16),
            'rev' => 456,
            'iat' => $now,
            'exp' => $now + 600,
            'jti' => bin2hex(random_bytes(16)),
            'sub' => 7,
        ], $override);
    }

    protected static function previewTicket(array $override = [], string $key = self::PREVIEW_KEY, ?array $header = null): string
    {
        return PreviewTicketVerifier::issue(self::previewClaims($override), $key, $header);
    }
}
