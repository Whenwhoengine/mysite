<?php

declare(strict_types=1);

namespace Tests\Feature\Content;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\Content\Concerns\FakesWordPress;
use Tests\TestCase;

/**
 * 公開路由（UI-01、UI-03、UI-06、UI-07、API-02、API-03、API-14）：以 Http::fake 依契約模擬 WP。
 */
final class PublicRoutesTest extends TestCase
{
    use FakesWordPress;

    protected function setUp(): void
    {
        parent::setUp();
        $this->configureContent();
    }

    /** @return array<string, mixed> 首頁需要的完整假 WP */
    private function homeRoutes(array $posts, array $videos, array $podcasts, array $extra = []): array
    {
        return array_merge([
            '/posts' => self::listEnvelope($posts, 1, 6),
            '/videos' => self::listEnvelope($videos, 1, 4),
            '/podcasts' => self::listEnvelope($podcasts, 1, 4),
            '/menus/primary' => ['data' => ['location' => 'primary', 'items' => [['label' => '關於', 'url' => '/pages/about', 'external' => false]]], 'meta' => self::meta()],
            '/menus/footer' => ['data' => ['location' => 'footer', 'items' => [['label' => '隱私權', 'url' => '/pages/privacy', 'external' => false]]], 'meta' => self::meta()],
            '/site' => ['data' => ['name' => 'mysite', 'tagline' => '影音創作紀錄', 'author_bio' => '（示例）作者介紹', 'social_links' => [['label' => 'YouTube', 'url' => 'https://www.youtube.com/@example']], 'logo' => null, 'is_sample' => true], 'meta' => self::meta()],
        ], $extra);
    }

    #[Test]
    #[Group('UI-01')]
    #[Group('UI-06')]
    public function home_renders_real_lists_with_unique_meta(): void
    {
        $this->fakeWordPress($this->homeRoutes(
            [self::wpItem(['id' => 1, 'slug' => 'first-post', 'title' => '第一篇文章'])],
            [self::wpItem(['id' => 2, 'type' => 'video', 'slug' => 'first-video', 'title' => '第一支影片', 'published_at' => '2026-11-03T00:00:00Z'])],
            [self::wpItem(['id' => 3, 'type' => 'podcast', 'slug' => 'ep-1', 'title' => '第一集', 'is_sample' => false])],
        ));

        $response = $this->get('/')->assertOk();

        $response->assertSee('<title>mysite</title>', false)
            ->assertSee('<link rel="canonical" href="http://mysite.localhost:8080/">', false)
            ->assertSee('<meta property="og:title" content="mysite">', false)
            ->assertSee('第一篇文章')->assertSee('第一支影片')->assertSee('第一集')
            ->assertSee('href="/articles/first-post"', false)
            ->assertSee('href="/videos/first-video"', false)
            ->assertSee('href="/podcasts/ep-1"', false)
            ->assertSee('示例內容')
            ->assertSee('影音創作紀錄')
            ->assertSee('href="/pages/about"', false)
            ->assertSee('href="/pages/privacy"', false)
            ->assertSee('https://www.youtube.com/@example', false)
            ->assertSee('跳到主要內容')
            ->assertDontSee('noindex');
        // 最新發布（featured）為發布時間最新者
        $this->assertMatchesRegularExpression('/hero__feature.*?第一支影片/s', $response->getContent());
        // 公開內容頁不建立 Session cookie
        $this->assertEmpty(array_filter($response->headers->getCookies(), fn ($c) => $c->getName() === config('session.cookie')));
        $this->assertNotEmpty($this->wpCallsMatching('/posts?*per_page=6*'));
    }

    #[Test]
    #[Group('UI-03')]
    public function home_with_no_content_shows_empty_state_not_an_error(): void
    {
        $this->fakeWordPress($this->homeRoutes([], [], []));

        $this->get('/')->assertOk()
            ->assertSee('還沒有公開內容')
            ->assertSee('目前沒有公開的影片')
            ->assertSee('目前沒有公開的 Podcast 節目')
            ->assertSee('目前沒有公開的文章');
    }

    #[Test]
    #[Group('UI-03')]
    #[Group('API-02')]
    public function upstream_outage_shows_503_without_internal_details(): void
    {
        Http::fake(fn () => throw new ConnectionException('cURL error 7: Failed to connect to wp-web port 8080: Connection refused'));
        \Illuminate\Support\Sleep::fake();

        $response = $this->get('/')->assertStatus(503);

        $response->assertSee('內容暫時無法讀取')
            ->assertSee('請求編號')
            ->assertSee('<meta name="robots" content="noindex, nofollow">', false)
            ->assertHeader('Cache-Control', 'no-store, private')
            ->assertDontSee('wp-web')
            ->assertDontSee('cURL')
            ->assertDontSee('Exception')
            ->assertDontSee('vendor/');
        // 版型的選單在上游已失敗時直接用內建後備，不再重複等待
        $response->assertSee('href="/articles"', false)->assertSee('href="/podcasts"', false);
    }

    #[Test]
    #[Group('UI-01')]
    #[Group('UI-06')]
    #[Group('API-03')]
    public function listing_pagination_canonical_and_bounds(): void
    {
        $items = array_map(fn (int $i) => self::wpItem(['id' => $i, 'slug' => "post-{$i}", 'title' => "文章 {$i}"]), range(13, 24));
        $this->fakeWordPress([
            '/posts' => fn (HttpRequest $r) => self::listEnvelope($items, (int) $r->data()['page'], 12, 30),
            '/menus/*' => ['data' => ['location' => 'primary', 'items' => []], 'meta' => self::meta()],
            '/site' => Http::response('', 500),
        ]);

        $this->get('/articles?page=2')->assertOk()
            ->assertSee('<title>文章（第 2 頁）｜mysite</title>', false)
            ->assertSee('<link rel="canonical" href="http://mysite.localhost:8080/articles?page=2">', false)
            ->assertSee('href="/articles"', false)
            ->assertSee('href="/articles?page=3"', false)
            ->assertSee('第 2 頁，共 3 頁')
            ->assertSee('共 30 則');
        $this->get('/articles')->assertOk()
            ->assertSee('<link rel="canonical" href="http://mysite.localhost:8080/articles">', false)
            ->assertSee('<title>文章｜mysite</title>', false);
        $this->get('/articles?page=4')->assertNotFound();

        $before = count($this->wpCallsMatching('/posts*'));
        foreach (['0', '-1', 'abc', '1001', '2.5', '9999'] as $bad) {
            $this->get('/articles?page='.$bad)->assertNotFound();
        }
        $this->get('/articles?page[]=2')->assertNotFound();
        $this->assertCount($before, $this->wpCallsMatching('/posts*'), '不合法的 page 不送往上游');
    }

    #[Test]
    #[Group('UI-01')]
    #[Group('UI-06')]
    #[Group('API-05')]
    public function article_detail_renders_sanitized_body_and_meta(): void
    {
        $item = self::wpItem([
            'title' => '一篇很長很長的中文標題用來確認版面不會被撐破而且會自動換行的文章標題範例',
            'body_html' => '<p>正文第一段<script>alert(1)</script></p><p><img src="x" onerror="alert(1)"><a href="javascript:alert(2)">壞連結</a><a href="https://example.org/">外連</a></p>',
        ], true);
        $this->fakeSingleItem($item);

        $response = $this->get('/articles/sample-article')->assertOk();

        $response->assertSee('<title>一篇很長很長的中文標題用來確認版面不會被撐破而且會自動換行的文章標題範例｜mysite</title>', false)
            ->assertSee('<link rel="canonical" href="http://mysite.localhost:8080/articles/sample-article">', false)
            ->assertSee('<meta property="og:type" content="article">', false)
            ->assertSee('<meta property="og:image" content="http://cms.mysite.localhost:8081/wp-content/uploads/2026/11/a.png">', false)
            ->assertSee('<meta name="description" content="這是一段示例摘要。">', false)
            ->assertSee('正文第一段')
            ->assertSee('<a href="https://example.org/" rel="noopener noreferrer nofollow">外連</a>', false)
            ->assertSee('href="/categories/news"', false)
            ->assertSee('href="/tags/demo"', false)
            ->assertSee('示例內容')
            ->assertSee('2026年11月1日')
            ->assertSee('datetime="2026-11-01T02:00:00Z"', false);
        $html = strtolower($response->getContent());
        $this->assertStringNotContainsString('<script>alert', $html);
        $this->assertStringNotContainsString('onerror', $html);
        $this->assertStringNotContainsString('javascript:', $html);
    }

    #[Test]
    #[Group('UI-03')]
    #[Group('API-03')]
    public function missing_or_malformed_slugs_are_404(): void
    {
        $this->fakeSingleItem(self::wpItem([], true));

        $this->get('/articles/does-not-exist')->assertNotFound()->assertSee('找不到這個頁面')->assertSee('<meta name="robots" content="noindex, nofollow">', false);
        $upstream = fn (): int => count(array_filter($this->wpCalls, fn ($c) => ! str_starts_with($c, '/menus') && $c !== '/site'));
        $before = $upstream();
        foreach (['/articles/Sample-Article', '/articles/a_b', '/articles/a.b', '/videos/%3Cscript%3E', '/podcasts/'.str_repeat('a', 191)] as $path) {
            $this->get($path)->assertNotFound();
        }
        $this->assertSame($before, $upstream(), '格式不合的 slug 不送往上游');
        // 不同型別的同 slug 不會被當成文章
        $this->get('/videos/sample-article')->assertNotFound();
    }

    #[Test]
    #[Group('API-02')]
    public function unsupported_model_version_is_503_not_empty(): void
    {
        $envelope = self::listEnvelope([]);
        $envelope['meta']['model_version'] = 2;
        $this->fakeWordPress(['/posts' => $envelope, '/menus/*' => Http::response('', 404), '/site' => Http::response('', 404)]);

        $this->get('/articles')->assertStatus(503)->assertSee('內容暫時無法顯示')->assertDontSee('目前沒有公開的');
    }

    #[Test]
    #[Group('UI-03')]
    #[Group('UI-01')]
    public function video_detail_embeds_only_after_click_and_shows_broken_state(): void
    {
        $item = self::wpItem(['type' => 'video', 'slug' => 'my-video', 'title' => '影片標題'], true);
        $this->fakeSingleItem($item);

        $response = $this->get('/videos/my-video')->assertOk();
        $response->assertSee('data-embed-src="https://www.youtube-nocookie.com/embed/dQw4w9WgXcQ?autoplay=1&amp;rel=0"', false)
            ->assertSee('href="https://www.youtube.com/watch?v=dQw4w9WgXcQ"', false)
            ->assertSee('<noscript>', false)
            ->assertSee('片長')
            ->assertSee('<meta property="og:type" content="video.other">', false)
            ->assertDontSee('<iframe', false);

        $broken = self::wpItem(['id' => 202, 'type' => 'video', 'slug' => 'bad-video', 'video' => ['youtube_id' => 'abc"><script>', 'youtube_url' => null, 'duration_seconds' => null]], true);
        $this->fakeSingleItem($broken);
        $this->get('/videos/bad-video')->assertOk()
            ->assertSee('影片目前無法播放')
            ->assertSee('第一段正文')
            ->assertDontSee('data-embed-src', false)
            ->assertDontSee('youtube-nocookie', false);
    }

    #[Test]
    #[Group('UI-07')]
    #[Group('UI-03')]
    public function podcast_player_requires_allowed_audio_host(): void
    {
        $this->fakeSingleItem(self::wpItem(['type' => 'podcast', 'slug' => 'ep-1', 'title' => '第一集'], true));
        $this->get('/podcasts/ep-1')->assertOk()
            ->assertSee('<audio class="player__audio-control" controls preload="none"', false)
            ->assertSee('<source src="http://cms.mysite.localhost:8081/wp-content/uploads/2026/11/ep1.wav" type="audio/wav">', false)
            ->assertSee('下載音訊')
            ->assertSee('datetime="PT30M"', false)
            ->assertSee('30:00');

        $this->fakeSingleItem(self::wpItem(['id' => 303, 'type' => 'podcast', 'slug' => 'ep-2', 'podcast' => ['audio_url' => 'https://media.example.org/ep2.mp3', 'audio_mime' => 'audio/mpeg', 'duration_seconds' => 60]], true));
        $this->get('/podcasts/ep-2')->assertOk()->assertSee('src="https://media.example.org/ep2.mp3"', false);

        foreach (['http://media.example.org/ep3.mp3', 'https://evil.example/ep3.mp3', 'javascript:alert(1)', 'https://user:pw@media.example.org/x.mp3'] as $i => $url) {
            $this->fakeSingleItem(self::wpItem(['id' => 400 + $i, 'type' => 'podcast', 'slug' => "bad-{$i}", 'podcast' => ['audio_url' => $url, 'audio_mime' => 'audio/mpeg', 'duration_seconds' => 60]], true));
            $this->get("/podcasts/bad-{$i}")->assertOk()
                ->assertSee('音訊目前無法播放')
                ->assertSee('第一段正文', false)
                ->assertDontSee('<audio', false);
        }
    }

    #[Test]
    #[Group('UI-01')]
    public function static_pages_category_and_tag_pages(): void
    {
        $page = self::wpItem(['id' => 9, 'type' => 'page', 'slug' => 'about', 'title' => '關於本站'], true);
        $this->fakeSingleItem($page);
        $this->get('/pages/about')->assertOk()->assertSee('關於本站')->assertSee('<link rel="canonical" href="http://mysite.localhost:8080/pages/about">', false);

        $this->fakeWordPress([
            '/taxonomies' => ['data' => ['categories' => [['id' => 3, 'slug' => 'news', 'name' => '新聞', 'count' => 2]], 'tags' => [['id' => 7, 'slug' => 'demo', 'name' => '示例', 'count' => 1]]], 'meta' => self::meta()],
            '/posts' => fn (HttpRequest $r) => self::listEnvelope(($r->data()['category'] ?? $r->data()['tag'] ?? null) !== null ? [self::wpItem(['title' => '分類下的文章'])] : [], 1, (int) $r->data()['per_page']),
            '/videos' => self::listEnvelope([]),
            '/podcasts' => self::listEnvelope([]),
            '/menus/*' => ['data' => ['location' => 'primary', 'items' => []], 'meta' => self::meta()],
            '/site' => Http::response('', 404),
        ]);

        $this->get('/categories/news')->assertOk()
            ->assertSee('<title>分類：新聞｜mysite</title>', false)
            ->assertSee('分類下的文章')
            ->assertSee('href="/categories/news?type=video"', false);
        $this->assertNotEmpty($this->wpCallsMatching('/posts?*category=news*'));
        $this->get('/categories/news?type=post')->assertOk()
            ->assertSee('<link rel="canonical" href="http://mysite.localhost:8080/categories/news?type=post">', false);
        $this->get('/categories/news?type=video')->assertOk()->assertSee('這個分類目前沒有公開的影片');
        $this->get('/categories/news?type=product')->assertNotFound();
        $this->get('/categories/news?page=2')->assertNotFound();
        $this->get('/categories/unknown')->assertNotFound();
        $this->get('/tags/demo')->assertOk()->assertSee('#示例')->assertSee('分類下的文章');
        $this->assertNotEmpty($this->wpCallsMatching('/posts?*tag=demo*'));
    }

    #[Test]
    #[Group('UI-01')]
    #[Group('UI-03')]
    public function search_states(): void
    {
        $this->fakeWordPress([
            '/search' => fn (HttpRequest $r) => self::listEnvelope($r->data()['q'] === '影片' ? [self::wpItem(['type' => 'video', 'title' => '找到的影片'])] : [], (int) $r->data()['page']),
            '/menus/*' => ['data' => ['location' => 'primary', 'items' => []], 'meta' => self::meta()],
            '/site' => Http::response('', 404),
        ]);

        $this->get('/search')->assertOk()->assertSee('輸入關鍵字開始搜尋')->assertSee('<meta name="robots" content="noindex, nofollow">', false);
        $this->assertSame([], $this->wpCallsMatching('/search*'), '空查詢不送往上游');

        $this->get('/search?q=%E5%BD%B1%E7%89%87')->assertOk()
            ->assertSee('找到 1 則符合「影片」的內容')
            ->assertSee('找到的影片')
            ->assertSee('<title>搜尋「影片」｜mysite</title>', false)
            ->assertSee('<link rel="canonical" href="http://mysite.localhost:8080/search">', false);
        $this->get('/search?q=nothing&type=video')->assertOk()->assertSee('找不到符合「nothing」的內容');
        $this->assertNotEmpty($this->wpCallsMatching('/search?*type=video*'));

        $before = count($this->wpCallsMatching('/search*'));
        $this->get('/search?q='.str_repeat('長', 101))->assertStatus(422)->assertSee('搜尋字詞最多 100 個字');
        $this->get('/search?q=x&type=product')->assertStatus(422)->assertSee('內容類型不正確');
        $this->get('/search?q[]=x')->assertOk()->assertSee('輸入關鍵字開始搜尋');
        $this->assertCount($before, $this->wpCallsMatching('/search*'));
        $this->get('/search?q=%3Cscript%3Ealert(1)%3C/script%3E')->assertOk()->assertDontSee('<script>alert(1)</script>', false);
    }

    #[Test]
    #[Group('UI-03')]
    public function search_is_rate_limited_with_429_page(): void
    {
        $this->fakeWordPress(['/search' => self::listEnvelope([]), '/menus/*' => ['data' => ['location' => 'primary', 'items' => []], 'meta' => self::meta()], '/site' => Http::response('', 404)]);

        for ($i = 0; $i < \App\Providers\ContentServiceProvider::SEARCH_PER_MINUTE; $i++) {
            $this->get('/search?q=x'.$i)->assertOk();
        }
        $this->get('/search?q=again')->assertStatus(429)->assertSee('操作過於頻繁')->assertHeader('Retry-After');
    }

    #[Test]
    #[Group('UI-01')]
    #[Group('API-14')]
    public function menu_fallback_commerce_link_and_no_private_fields(): void
    {
        $item = self::wpItem([
            'author' => ['display_name' => '編輯部', 'email' => 'editor@example.org', 'user_login' => 'editor1'],
            'password_hash' => '$P$Bsecret',
            'db_host' => 'mysql',
        ], true);
        $this->fakeWordPress([
            '/posts' => self::listEnvelope([$item], 1, 1),
            '/content/post/101/visibility' => self::visibilityEnvelope('post', 101, true, 5),
            '/menus/primary' => Http::response('', 500),
            '/menus/footer' => Http::response('', 500),
            '/site' => Http::response('', 500),
        ]);
        \Illuminate\Support\Sleep::fake();

        $response = $this->get('/articles/sample-article')->assertOk();
        foreach (['/articles', '/videos', '/podcasts', '/search'] as $href) {
            $response->assertSee('href="'.$href.'"', false);
        }
        $response->assertDontSee('href="/shop"', false)
            ->assertDontSee('editor@example.org')->assertDontSee('editor1')->assertDontSee('$P$Bsecret', false)->assertDontSee('test-service-app-password');

        config(['mysite.commerce.mode' => 'simulation']);
        $this->get('/articles/sample-article')->assertOk()->assertSee('site-nav__link--shop', false)->assertSee('商店</a>', false);
    }

    #[Test]
    #[Group('UI-03')]
    public function session_expired_and_server_error_pages_are_friendly(): void
    {
        Route::middleware('web')->post('/__test/expired', fn () => throw new \Illuminate\Session\TokenMismatchException());
        Route::middleware('web')->get('/__test/boom', fn () => throw new \RuntimeException('secret detail /var/www/app'));
        $this->fakeWordPress(['/menus/*' => ['data' => ['location' => 'primary', 'items' => []], 'meta' => self::meta()], '/site' => Http::response('', 404)]);

        $this->post('/__test/expired')->assertStatus(419)->assertSee('頁面已逾時')->assertSee('重新整理');
        $this->get('/__test/boom')->assertStatus(500)->assertSee('系統發生錯誤')->assertDontSee('secret detail')->assertDontSee('/var/www');
        $this->get('/no-such-page')->assertNotFound()->assertSee('找不到這個頁面')->assertSee('搜尋站內內容');
    }
}
