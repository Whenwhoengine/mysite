<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use Illuminate\Testing\TestResponse;
use Osteel\OpenApi\Testing\ValidatorBuilder;
use Osteel\OpenApi\Testing\ValidatorInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\Content\Concerns\FakesWordPress;
use Tests\TestCase;

/**
 * 公開唯讀 API（MySite React 前端專案 F2）：PAPI-01～PAPI-07。
 * 每一個回應都以契約 app/resources/contracts/public-api-v1.openapi.json 驗證（PAPI-01），
 * 並檢查沒有 Set-Cookie、一律 no-store（PAPI-02）。
 */
final class PublicApiTest extends TestCase
{
    use FakesWordPress;
    use RefreshDatabase;

    private static ?ValidatorInterface $contract = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->configureContent();
        Sleep::fake();
        $this->freezeSecond();
    }

    private function contract(): ValidatorInterface
    {
        return self::$contract ??= ValidatorBuilder::fromJsonFile(base_path('resources/contracts/public-api-v1.openapi.json'))->getValidator();
    }

    /** 送出請求並驗證：契約（PAPI-01）、無 cookie 與 no-store（PAPI-02）。 */
    private function api(string $uri, string $operationPath, int $status = 200): TestResponse
    {
        $response = $this->get($uri);
        $response->assertStatus($status);
        $this->assertSame([], $response->baseResponse->headers->getCookies(), "{$uri} 不得帶 Set-Cookie");
        $this->assertFalse($response->baseResponse->headers->has('Set-Cookie'), "{$uri} 不得帶 Set-Cookie");
        $this->assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'), "{$uri} 必須 no-store");
        $this->assertStringStartsWith('application/json', (string) $response->headers->get('Content-Type'));
        $this->assertTrue($this->contract()->validate($response->baseResponse, $operationPath, 'get'));
        if ($status >= 400) {
            $this->assertMatchesRegularExpression('/^[a-f0-9]{32}$/', (string) $response->json('request_id'));
        }

        return $response;
    }

    /** @return array<string, mixed> */
    private function siteRoute(): array
    {
        return [
            '/menus/primary' => ['data' => ['location' => 'primary', 'items' => [['label' => '文章', 'url' => '/articles', 'external' => false]]], 'meta' => self::meta()],
            '/menus/footer' => ['data' => ['location' => 'footer', 'items' => [['label' => '隱私說明', 'url' => '/pages/privacy', 'external' => false]]], 'meta' => self::meta()],
            '/site' => ['data' => ['name' => 'mysite', 'tagline' => '影音創作紀錄', 'author_bio' => '作者簡介', 'social_links' => [['label' => '示例', 'url' => 'https://example.com/']], 'logo' => null, 'is_sample' => true], 'meta' => self::meta()],
        ];
    }

    #[Test]
    #[Group('PAPI-01')]
    #[Group('PAPI-02')]
    public function site_endpoint_matches_contract(): void
    {
        $this->fakeWordPress($this->siteRoute());
        $this->api('/api/v1/site', '/api/v1/site')
            ->assertJsonPath('data.name', 'mysite')
            ->assertJsonPath('data.fallback', false)
            ->assertJsonPath('data.menus.footer.0.url', '/pages/privacy')
            ->assertJsonPath('data.commerce_enabled', false);
    }

    #[Test]
    #[Group('PAPI-01')]
    public function site_endpoint_uses_builtin_fallback_when_wordpress_is_unreachable(): void
    {
        // WordPress 讀不到：沿用內建後備選單與站名，不回 503（錯誤頁仍有版面可畫）
        $this->fakeWordPress(['/site' => Http::response('', 500), '/menus/*' => Http::response('', 500)]);
        $this->api('/api/v1/site', '/api/v1/site')
            ->assertJsonPath('data.fallback', true)
            ->assertJsonPath('data.name', 'mysite')
            ->assertJsonPath('data.menus.primary.0.url', '/articles')
            ->assertJsonPath('data.menus.footer', []);
    }

    #[Test]
    #[Group('PAPI-01')]
    public function home_listing_and_detail_endpoints_match_contract(): void
    {
        $post = self::wpItem([], true);
        $video = self::wpItem(['id' => 202, 'type' => 'video', 'slug' => 'my-video', 'title' => '影片標題'], true);
        $podcast = self::wpItem(['id' => 303, 'type' => 'podcast', 'slug' => 'ep-1', 'title' => '第一集'], true);
        $page = self::wpItem(['id' => 404, 'type' => 'page', 'slug' => 'about', 'title' => '關於'], true);
        $byType = ['posts' => $post, 'videos' => $video, 'podcasts' => $podcast, 'pages' => $page];
        $routes = $this->siteRoute();
        foreach ($byType as $endpoint => $item) {
            $routes['/'.$endpoint] = function (HttpRequest $r) use ($item) {
                $slug = $r->data()['slug'] ?? null;

                return $slug === null || $slug === $item['slug'] ? self::listEnvelope([$item]) : Http::response(['code' => 'site_content_not_found', 'message' => 'x', 'data' => ['status' => 404]], 404);
            };
        }
        $routes['/content/*/*/visibility'] = function (HttpRequest $r) use ($byType) {
            foreach ($byType as $item) {
                if (str_contains($r->url(), '/content/'.$item['type'].'/'.$item['id'].'/')) {
                    return self::visibilityEnvelope($item['type'], $item['id'], true, $item['content_version']);
                }
            }

            return Http::response('', 404);
        };
        $this->fakeWordPress($routes);

        $home = $this->api('/api/v1/home', '/api/v1/home');
        $home->assertJsonPath('data.posts.0.path', '/articles/sample-article')
            ->assertJsonPath('seo.title', 'mysite')
            ->assertJsonPath('seo.canonical_path', '/');

        foreach (['articles', 'videos', 'podcasts'] as $prefix) {
            $this->api('/api/v1/'.$prefix, '/api/v1/'.$prefix)
                ->assertJsonPath('meta.page', 1)
                ->assertJsonPath('collection.type', ['articles' => 'post', 'videos' => 'video', 'podcasts' => 'podcast'][$prefix])
                ->assertJsonPath('seo.canonical_path', '/'.$prefix);
        }

        $article = $this->api('/api/v1/articles/sample-article', '/api/v1/articles/{slug}');
        $article->assertJsonPath('data.content_html', '<p>第一段正文。</p><h2>小標</h2><p>第二段正文。</p>')
            ->assertJsonPath('data.video', null)
            ->assertJsonPath('data.podcast', null)
            ->assertJsonPath('data.categories.0.path', '/categories/news')
            ->assertJsonPath('data.published_at', '2026-11-01T02:00:00Z')
            ->assertJsonPath('seo.og_type', 'article')
            ->assertJsonPath('seo.canonical_path', '/articles/sample-article');

        $this->api('/api/v1/videos/my-video', '/api/v1/videos/{slug}')
            ->assertJsonPath('data.video.youtube_id', 'dQw4w9WgXcQ')
            ->assertJsonPath('data.video.embed_url', 'https://www.youtube-nocookie.com/embed/dQw4w9WgXcQ?autoplay=1&rel=0')
            ->assertJsonPath('data.video.video_unavailable', false)
            ->assertJsonPath('data.duration_seconds', 245)
            ->assertJsonPath('seo.og_type', 'video.other');

        $this->api('/api/v1/podcasts/ep-1', '/api/v1/podcasts/{slug}')
            ->assertJsonPath('data.podcast.audio_url', 'http://cms.mysite.localhost:8081/wp-content/uploads/2026/11/ep1.wav')
            ->assertJsonPath('data.podcast.audio_mime', 'audio/wav')
            ->assertJsonPath('data.podcast.audio_unavailable', false);

        $this->api('/api/v1/pages/about', '/api/v1/pages/{slug}')->assertJsonPath('data.type', 'page')->assertJsonPath('seo.og_type', 'website');
        $this->api('/api/v1/articles/missing', '/api/v1/articles/{slug}', 404)->assertJsonPath('error', 'CONTENT_NOT_FOUND');
    }

    #[Test]
    #[Group('PAPI-01')]
    public function taxonomy_and_search_endpoints_match_contract(): void
    {
        $this->fakeWordPress($this->siteRoute() + [
            '/taxonomies' => ['data' => ['categories' => [['id' => 3, 'slug' => 'news', 'name' => '新聞', 'count' => 2]], 'tags' => [['id' => 7, 'slug' => 'demo', 'name' => '示例', 'count' => 1]]], 'meta' => self::meta()],
            '/posts' => fn (HttpRequest $r) => self::listEnvelope([self::wpItem(['title' => '分類下的文章'])], 1, (int) $r->data()['per_page'], 20),
            '/videos' => self::listEnvelope([]),
            '/podcasts' => self::listEnvelope([]),
            '/search' => fn (HttpRequest $r) => self::listEnvelope($r->data()['q'] === '影片' ? [self::wpItem(['type' => 'video', 'title' => '找到的影片'])] : [], (int) $r->data()['page']),
        ]);

        $this->api('/api/v1/categories/news', '/api/v1/categories/{slug}')
            ->assertJsonPath('data.mode', 'overview')
            ->assertJsonPath('data.term.count', 2)
            ->assertJsonPath('data.overview.post.total', 20)
            ->assertJsonPath('data.items', null)
            ->assertJsonPath('meta', null)
            ->assertJsonPath('seo.title', '分類：新聞');
        $this->api('/api/v1/categories/news?type=post', '/api/v1/categories/{slug}')
            ->assertJsonPath('data.mode', 'listing')
            ->assertJsonPath('data.items.0.title', '分類下的文章')
            ->assertJsonPath('meta.total_pages', 2)
            ->assertJsonPath('seo.canonical_path', '/categories/news?type=post');
        $this->api('/api/v1/tags/demo', '/api/v1/tags/{slug}')->assertJsonPath('data.label', '標籤')->assertJsonPath('data.term.path', '/tags/demo');
        $this->api('/api/v1/tags/unknown', '/api/v1/tags/{slug}', 404);

        $this->api('/api/v1/search', '/api/v1/search')->assertJsonPath('data.searched', false)->assertJsonPath('meta', null)->assertJsonPath('seo.noindex', true);
        $this->assertSame([], $this->wpCallsMatching('/search*'), '空查詢不送往上游');
        $this->api('/api/v1/search?q=%E5%BD%B1%E7%89%87', '/api/v1/search')
            ->assertJsonPath('data.q', '影片')
            ->assertJsonPath('data.searched', true)
            ->assertJsonPath('data.items.0.title', '找到的影片')
            ->assertJsonPath('seo.title', '搜尋「影片」');
        $this->api('/api/v1/search?q=%E6%B2%92%E6%9C%89', '/api/v1/search')->assertJsonPath('data.items', [])->assertJsonPath('meta.total', 0);
    }

    #[Test]
    #[Group('PAPI-03')]
    public function takedown_event_returns_404_on_the_next_request(): void
    {
        $visible = true;
        $version = 5;
        $down = false;
        $this->fakeSingleItem(self::wpItem([], true), $visible, $version, $down);
        $uri = '/api/v1/articles/sample-article';

        $this->api($uri, '/api/v1/articles/{slug}')->assertSee('第一段正文');

        // 撤下事件：下一次請求立即 404
        $visible = false;
        $version = 6;
        $body = self::eventBody(['event_type' => 'content.unpublished', 'status' => 'draft', 'content_version' => 6]);
        $this->postEvent($body, self::signedEventHeaders($body, (string) json_decode($body, true)['event_id']))->assertStatus(202);
        $this->api($uri, '/api/v1/articles/{slug}', 404)->assertDontSee('第一段正文');
    }

    #[Test]
    #[Group('PAPI-03')]
    public function lost_takedown_event_with_wordpress_outage_stops_serving_after_the_lease(): void
    {
        $visible = true;
        $version = 5;
        $down = false;
        $this->fakeSingleItem(self::wpItem([], true), $visible, $version, $down);
        $uri = '/api/v1/articles/sample-article';
        $this->api($uri, '/api/v1/articles/{slug}')->assertSee('第一段正文');

        // WordPress 撤下但事件遺失，接著失聯：租約內照常，過期後一律 503，絕不回舊正文
        $visible = false;
        $version = 6;
        $down = true;
        $this->travel(10)->seconds();
        $this->api($uri, '/api/v1/articles/{slug}');
        foreach ([21, 30, 60] as $seconds) {
            $this->travel($seconds)->seconds();
            $this->api($uri, '/api/v1/articles/{slug}', 503)
                ->assertJsonPath('error', 'CONTENT_UPSTREAM_UNAVAILABLE')
                ->assertDontSee('第一段正文')
                ->assertDontSee('示例文章標題');
        }

        // WordPress 恢復：明確不存在 → 404
        $down = false;
        $this->api($uri, '/api/v1/articles/{slug}', 404)->assertDontSee('第一段正文');
    }

    /** @return array<string, array{\Closure(): mixed, string}> */
    public static function upstreamFailures(): array
    {
        return [
            '連線失敗' => [fn () => throw new ConnectionException('cURL error 7: Failed to connect'), 'CONTENT_UPSTREAM_UNAVAILABLE'],
            '逾時' => [fn () => throw new ConnectionException('cURL error 28: Operation timed out after 5000 milliseconds'), 'CONTENT_UPSTREAM_UNAVAILABLE'],
            '5xx' => [fn () => Http::response('', 502), 'CONTENT_UPSTREAM_UNAVAILABLE'],
            '不是 JSON' => [fn () => Http::response('<html>oops</html>', 200, ['Content-Type' => 'text/html']), 'CONTENT_UPSTREAM_UNAVAILABLE'],
            '格式不符' => [fn () => ['data' => [['id' => 'x']], 'meta' => self::meta(['page' => 1, 'per_page' => 12, 'total' => 1, 'total_pages' => 1])], 'CONTENT_MODEL_UNSUPPORTED'],
        ];
    }

    #[Test]
    #[Group('PAPI-04')]
    #[DataProvider('upstreamFailures')]
    public function upstream_failures_return_503_with_request_id_and_no_stale_body(\Closure $failure, string $code): void
    {
        $this->fakeWordPress(['/posts' => $failure, '/menus/*' => Http::response('', 500), '/site' => Http::response('', 500)]);

        $this->api('/api/v1/articles', '/api/v1/articles', 503)->assertJsonPath('error', $code);
        $this->api('/api/v1/articles/sample-article', '/api/v1/articles/{slug}', 503)->assertJsonPath('error', $code)->assertDontSee('第一段正文');
        $this->api('/api/v1/home', '/api/v1/home', 503)->assertJsonPath('error', $code);
    }

    #[Test]
    #[Group('PAPI-05')]
    public function responses_never_expose_internal_or_private_fields(): void
    {
        $item = self::wpItem(['author' => ['display_name' => '編輯部', 'email' => 'editor@example.org'], 'preview' => true, 'snapshot_id' => 'snap-1', 'revision_id' => 99], true);
        $this->fakeSingleItem($item);

        foreach (['/api/v1/articles/sample-article', '/api/v1/site'] as $uri) {
            $body = $this->get($uri)->assertOk()->getContent();
            foreach (['@example.org', 'editor@', 'mysite-service', 'test-service-app-password', self::EVENT_KEY, self::PREVIEW_KEY, 'snapshot', 'revision', 'content_version', 'api_slug', '"id"', 'is_preview', 'preview', 'wp-json', 'wp-web', '/var/www', 'Exception', 'stack'] as $forbidden) {
                $this->assertStringNotContainsString($forbidden, (string) $body, "{$uri} 不得出現 {$forbidden}");
            }
        }

    }

    #[Test]
    #[Group('PAPI-05')]
    public function errors_only_carry_code_message_and_request_id(): void
    {
        // 上游丟出帶內部路徑的例外：只回固定錯誤碼與請求編號，不含路徑或上游細節
        $this->fakeWordPress(['/posts' => fn () => throw new \RuntimeException('secret detail /var/www/app'), '/menus/*' => Http::response('', 500), '/site' => Http::response('', 500)]);
        $error = $this->api('/api/v1/articles', '/api/v1/articles', 503)->getContent();
        $this->assertStringNotContainsString('secret detail', (string) $error);
        $this->assertStringNotContainsString('/var/www', (string) $error);
        $this->assertSame(['error', 'message', 'request_id'], array_keys((array) json_decode((string) $error, true)));
    }

    #[Test]
    #[Group('PAPI-06')]
    public function invalid_video_id_and_disallowed_cover_match_blade(): void
    {
        $video = self::wpItem(['type' => 'video', 'slug' => 'bad-video', 'video' => ['youtube_id' => 'bad/id', 'youtube_url' => 'https://www.youtube.com/watch?v=bad', 'duration_seconds' => 10], 'cover' => ['url' => 'http://evil.example/a.png', 'alt' => 'x', 'width' => 10, 'height' => 10]], true);
        $this->fakeSingleItem($video);
        $this->api('/api/v1/videos/bad-video', '/api/v1/videos/{slug}')
            ->assertJsonPath('data.video.youtube_id', null)
            ->assertJsonPath('data.video.watch_url', null)
            ->assertJsonPath('data.video.embed_url', null)
            ->assertJsonPath('data.video.video_unavailable', true)
            ->assertJsonPath('data.cover', null)
            ->assertJsonPath('seo.og_image', null);
        $this->get('/videos/bad-video')->assertOk()->assertSee('影片目前無法播放')->assertDontSee('evil.example')->assertDontSee('data-embed-src', false);
    }

    #[Test]
    #[Group('PAPI-06')]
    public function disallowed_audio_source_is_null_and_marked_unavailable_like_blade(): void
    {
        $podcast = self::wpItem(['type' => 'podcast', 'slug' => 'bad-ep', 'podcast' => ['audio_url' => 'https://evil.example/ep.mp3', 'audio_mime' => 'audio/mpeg', 'duration_seconds' => 60], 'cover' => ['url' => 'https://cdn.example.org/ok.png', 'alt' => '外部封面', 'width' => 10, 'height' => 10]], true);
        $this->fakeSingleItem($podcast);
        $this->api('/api/v1/podcasts/bad-ep', '/api/v1/podcasts/{slug}')
            ->assertJsonPath('data.podcast.audio_url', null)
            ->assertJsonPath('data.podcast.audio_mime', null)
            ->assertJsonPath('data.podcast.audio_unavailable', true)
            ->assertJsonPath('data.cover.url', 'https://cdn.example.org/ok.png')
            ->assertJsonPath('seo.og_image', 'https://cdn.example.org/ok.png');
        $this->get('/podcasts/bad-ep')->assertOk()->assertSee('音訊目前無法播放')->assertDontSee('evil.example')->assertSee('https://cdn.example.org/ok.png', false);
    }

    #[Test]
    #[Group('PAPI-06')]
    public function allowed_audio_with_unknown_mime_keeps_source_and_drops_mime(): void
    {
        $okPodcast = self::wpItem(['type' => 'podcast', 'slug' => 'ok-ep', 'podcast' => ['audio_url' => 'https://media.example.org/ep.mp3', 'audio_mime' => 'video/mp4', 'duration_seconds' => 60]], true);
        $this->fakeSingleItem($okPodcast);
        $this->api('/api/v1/podcasts/ok-ep', '/api/v1/podcasts/{slug}')
            ->assertJsonPath('data.podcast.audio_url', 'https://media.example.org/ep.mp3')
            ->assertJsonPath('data.podcast.audio_mime', null)
            ->assertJsonPath('data.podcast.audio_unavailable', false);
    }

    /** @return array<string, array{string, string, string, int}> */
    public static function invalidParameters(): array
    {
        $long = str_repeat('a', 201);

        return [
            'page=0' => ['/articles?page=0', '/api/v1/articles?page=0', '/api/v1/articles', 404],
            'page=1001' => ['/articles?page=1001', '/api/v1/articles?page=1001', '/api/v1/articles', 404],
            'page=abc' => ['/articles?page=abc', '/api/v1/articles?page=abc', '/api/v1/articles', 404],
            'page 超過總頁數' => ['/articles?page=5', '/api/v1/articles?page=5', '/api/v1/articles', 404],
            'slug 201 字' => ['/articles/'.$long, '/api/v1/articles/'.$long, '/api/v1/articles/{slug}', 404],
            'q 101 字' => ['/search?q='.str_repeat('字', 101), '/api/v1/search?q='.str_repeat('字', 101), '/api/v1/search', 422],
            'type 不正確' => ['/search?q=x&type=nope', '/api/v1/search?q=x&type=nope', '/api/v1/search', 422],
            '分類總覽帶 page' => ['/categories/news?page=2', '/api/v1/categories/news?page=2', '/api/v1/categories/{slug}', 404],
            '分類 type 不正確' => ['/categories/news?type=page', '/api/v1/categories/news?type=page', '/api/v1/categories/{slug}', 404],
        ];
    }

    #[Test]
    #[Group('PAPI-07')]
    #[DataProvider('invalidParameters')]
    public function invalid_parameters_match_public_page_status(string $pageUri, string $apiUri, string $operation, int $status): void
    {
        $this->fakeWordPress($this->siteRoute() + [
            '/posts' => fn (HttpRequest $r) => self::listEnvelope([self::wpItem()], (int) ($r->data()['page'] ?? 1), 12, 1),
            '/search' => self::listEnvelope([]),
            '/taxonomies' => ['data' => ['categories' => [['id' => 3, 'slug' => 'news', 'name' => '新聞', 'count' => 2]], 'tags' => []], 'meta' => self::meta()],
        ]);

        $this->get($pageUri)->assertStatus($status);
        $response = $this->api($apiUri, $operation, $status);
        $response->assertJsonPath('error', $status === 422 ? 'SEARCH_INVALID' : 'CONTENT_NOT_FOUND');
        if ($status === 422) {
            $this->assertStringContainsString($response->json('message'), (string) $this->get($pageUri)->getContent(), '錯誤訊息與搜尋頁相同');
        }
    }

    #[Test]
    #[Group('PAPI-02')]
    public function only_get_is_allowed_and_errors_are_json(): void
    {
        $this->fakeWordPress($this->siteRoute());
        $this->post('/api/v1/site')->assertStatus(405)->assertJsonPath('error', 'HTTP_405')->assertHeader('Cache-Control')->assertCookieMissing('mysite_session');
        $this->get('/api/v1/unknown')->assertNotFound()->assertJsonPath('error', 'CONTENT_NOT_FOUND');
        $this->assertStringContainsString('no-store', (string) $this->get('/api/v1/unknown')->headers->get('Cache-Control'));
    }
}
