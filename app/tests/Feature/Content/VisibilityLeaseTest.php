<?php

declare(strict_types=1);

namespace Tests\Feature\Content;

use App\Domain\Content\Cache\ContentCache;
use App\Domain\Content\ContentType;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\Content\Concerns\FakesWordPress;
use Tests\TestCase;

/**
 * 撤下硬期限（API-10）、事件即時失效（API-15）、舊事件不復活（API-09）；以 $this->travel() 控制時鐘。
 */
final class VisibilityLeaseTest extends TestCase
{
    use FakesWordPress;
    use RefreshDatabase;

    private const PATH = '/articles/sample-article';

    private const BODY_MARK = '第一段正文';

    protected function setUp(): void
    {
        parent::setUp();
        $this->configureContent();
        Sleep::fake();
        $this->freezeSecond();
    }

    private function visibilityCalls(): int
    {
        return count($this->wpCallsMatching('/content/post/101/visibility'));
    }

    private function sendEvent(array $override): void
    {
        $body = self::eventBody($override);
        $eventId = json_decode($body, true)['event_id'];
        $this->postEvent($body, self::signedEventHeaders($body, $eventId))->assertStatus(202);
    }

    #[Test]
    #[Group('API-10')]
    public function visibility_lease_is_reused_until_it_expires(): void
    {
        $this->fakeSingleItem(self::wpItem([], true));

        $this->get(self::PATH)->assertOk()->assertSee(self::BODY_MARK);
        $this->assertSame(1, $this->visibilityCalls());

        $this->travel(29)->seconds();
        $this->get(self::PATH)->assertOk();
        $this->assertSame(1, $this->visibilityCalls(), '租約內不重複確認');

        $this->travel(2)->seconds();
        $this->get(self::PATH)->assertOk();
        $this->assertSame(2, $this->visibilityCalls(), '超過 30 秒必須同步重新確認');
    }

    #[Test]
    #[Group('API-10')]
    public function lease_never_exceeds_the_60_second_hard_limit(): void
    {
        config(['mysite.content.visibility_ttl' => 600, 'mysite.content.visibility_hard_limit' => 600]);
        $this->assertSame(60, $this->app->make(ContentCache::class)->leaseSeconds());
        $this->fakeSingleItem(self::wpItem([], true));

        $this->get(self::PATH)->assertOk();
        $this->travel(59)->seconds();
        $this->get(self::PATH)->assertOk();
        $this->assertSame(1, $this->visibilityCalls());
        $this->travel(2)->seconds();
        $this->get(self::PATH)->assertOk();
        $this->assertSame(2, $this->visibilityCalls());
    }

    #[Test]
    #[Group('API-10')]
    public function takedown_with_lost_event_and_wp_outage_stops_serving_after_the_lease(): void
    {
        $visible = true;
        $version = 5;
        $down = false;
        $this->fakeSingleItem(self::wpItem([], true), $visible, $version, $down);

        $this->get(self::PATH)->assertOk()->assertSee(self::BODY_MARK);

        // WP 撤下，但事件遺失；接著 WP 斷線
        $visible = false;
        $version = 6;
        $down = true;

        $this->travel(10)->seconds();
        $this->get(self::PATH)->assertOk(); // 仍在 30 秒租約內

        foreach ([21, 30, 60] as $seconds) {
            $this->travel($seconds)->seconds();
            $this->get(self::PATH)->assertStatus(503)
                ->assertSee('內容暫時無法讀取')
                ->assertDontSee(self::BODY_MARK)
                ->assertDontSee('示例文章標題');
        }
        $this->get('/articles')->assertStatus(503)->assertDontSee('示例文章標題');

        // WP 恢復：明確告知不存在 → 404（不揭露私人狀態）
        $down = false;
        $this->get(self::PATH)->assertNotFound()->assertDontSee(self::BODY_MARK);
    }

    #[Test]
    #[Group('API-10')]
    public function visible_false_returns_404_and_clears_cached_body(): void
    {
        $visible = true;
        $version = 5;
        $item = self::wpItem([], true);
        $this->fakeWordPress([
            '/posts' => self::listEnvelope([$item], 1, 1), // slug 查詢仍回舊資料（例如 WP 端快取），只有可見性端點知道已撤下
            '/content/post/101/visibility' => function () use (&$visible, &$version) {
                return self::visibilityEnvelope('post', 101, $visible, $version);
            },
            '/menus/*' => ['data' => ['location' => 'primary', 'items' => []], 'meta' => self::meta()],
            '/site' => Http::response('', 404),
        ]);
        $cache = $this->app->make(ContentCache::class);

        $this->get(self::PATH)->assertOk();
        $this->assertNotNull($cache->body(ContentType::Post, 101));

        $visible = false;
        $version = 6;
        $this->travel(31)->seconds();
        $this->get(self::PATH)->assertNotFound()->assertDontSee(self::BODY_MARK);
        $this->assertNull($cache->body(ContentType::Post, 101), 'visible=false 時清除正文快取');
        $this->assertNull($cache->lease(ContentType::Post, 101));
    }

    #[Test]
    #[Group('API-15')]
    #[Group('API-09')]
    public function unpublish_event_takes_effect_immediately(): void
    {
        $visible = true;
        $version = 5;
        $this->fakeSingleItem(self::wpItem([], true), $visible, $version);

        $this->get(self::PATH)->assertOk();
        $visible = false;
        $version = 6;
        $this->sendEvent(['event_type' => 'content.unpublished', 'status' => 'draft', 'content_version' => 6]);

        $this->get(self::PATH)->assertNotFound();
        $this->assertSame(6, $this->app->make(ContentCache::class)->takedownVersion(ContentType::Post, 101));
    }

    #[Test]
    #[Group('API-09')]
    public function late_old_events_never_resurrect_taken_down_content(): void
    {
        $visible = true;
        $version = 5;
        $this->fakeSingleItem(self::wpItem([], true), $visible, $version);
        $this->get(self::PATH)->assertOk();

        $this->sendEvent(['event_id' => '11111111-1111-4111-8111-111111111111', 'event_type' => 'content.unpublished', 'status' => 'private', 'content_version' => 6]);
        $visible = false;
        $this->get(self::PATH)->assertNotFound();

        // 舊的 published 事件晚到，且 WP 某節點回報過期的「可見 v5」：仍不可供應
        $this->sendEvent(['event_id' => '22222222-2222-4222-8222-222222222222', 'event_type' => 'content.published', 'status' => 'publish', 'content_version' => 4]);
        $visible = true;
        $version = 5;
        $this->get(self::PATH)->assertNotFound();
        $this->assertSame(6, $this->app->make(ContentCache::class)->takedownVersion(ContentType::Post, 101), '撤下版本不被舊事件降低');

        // 真正重新發布（版本更新）後才恢復
        $version = 7;
        $this->sendEvent(['event_id' => '33333333-3333-4333-8333-333333333333', 'event_type' => 'content.published', 'status' => 'publish', 'content_version' => 7]);
        $this->get(self::PATH)->assertOk()->assertSee(self::BODY_MARK);
        $this->assertNull($this->app->make(ContentCache::class)->takedownVersion(ContentType::Post, 101));
    }

    #[Test]
    #[Group('API-15')]
    public function updated_content_is_refetched_when_visibility_reports_a_newer_version(): void
    {
        $item = self::wpItem([], true);
        $version = 5;
        $this->fakeWordPress([
            '/posts' => function (HttpRequest $r) use (&$item) {
                return self::listEnvelope([$item], 1, 1);
            },
            '/content/post/101/visibility' => function () use (&$version) {
                return self::visibilityEnvelope('post', 101, true, $version);
            },
            '/menus/*' => ['data' => ['location' => 'primary', 'items' => []], 'meta' => self::meta()],
            '/site' => Http::response('', 404),
        ]);

        $this->get(self::PATH)->assertOk()->assertSee(self::BODY_MARK);

        $item = array_merge($item, ['content_version' => 6, 'body_html' => '<p>修改後的正文</p>', 'title' => '修改後標題']);
        $version = 6;
        $this->travel(31)->seconds();
        $this->get(self::PATH)->assertOk()->assertSee('修改後的正文')->assertSee('修改後標題')->assertDontSee(self::BODY_MARK);
    }

    #[Test]
    #[Group('API-15')]
    public function lists_feeds_and_sitemaps_are_invalidated_by_events_and_bounded_without_them(): void
    {
        $items = [self::wpItem(['id' => 101, 'title' => '會被撤下的文章'])];
        $this->fakeWordPress([
            '/posts' => function (HttpRequest $r) use (&$items) {
                return self::listEnvelope($items, (int) ($r->data()['page'] ?? 1), (int) ($r->data()['per_page'] ?? 12));
            },
            '/videos' => self::listEnvelope([]),
            '/podcasts' => self::listEnvelope([]),
            '/pages' => self::listEnvelope([]),
            '/taxonomies' => ['data' => ['categories' => [], 'tags' => []], 'meta' => self::meta()],
            '/search' => function () use (&$items) {
                return self::listEnvelope($items);
            },
            '/menus/*' => ['data' => ['location' => 'primary', 'items' => []], 'meta' => self::meta()],
            '/site' => Http::response('', 404),
        ]);

        foreach (['/articles', '/feed.xml', '/sitemap.xml', '/search?q=x', '/'] as $path) {
            $this->get($path)->assertOk()->assertSee($path === '/sitemap.xml' ? '/articles/sample-article' : '會被撤下的文章', false);
        }

        // WP 撤下：列表在快取期限內仍是舊值，收到事件後立即失效
        $items = [];
        $this->get('/articles')->assertSee('會被撤下的文章');
        $this->sendEvent(['event_type' => 'content.unpublished', 'status' => 'trash', 'content_version' => 6]);
        foreach (['/articles', '/feed.xml', '/sitemap.xml', '/search?q=x', '/'] as $path) {
            $this->get($path)->assertOk()->assertDontSee('會被撤下的文章')->assertDontSee('/articles/sample-article', false);
        }

        // 沒有事件時：列表快取期限不超過可見性期限
        $items = [self::wpItem(['id' => 102, 'slug' => 'new-one', 'title' => '新文章'])];
        $this->get('/articles')->assertDontSee('新文章');
        $this->travel(31)->seconds();
        $this->get('/articles')->assertSee('新文章');
    }
}
