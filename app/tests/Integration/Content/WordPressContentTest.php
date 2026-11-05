<?php

declare(strict_types=1);

namespace Tests\Integration\Content;

use App\Domain\Content\ContentMapper;
use App\Domain\Content\ContentQueryService;
use App\Domain\Content\ContentRepository;
use App\Domain\Content\ContentType;
use App\Domain\Content\Http\WordPressRestClient;
use App\Support\Errors\ErrorCode;
use App\Support\Errors\MysiteException;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;
use Throwable;

/**
 * 對開發環境真實 WordPress（容器內 http://wp-web:8080/wp-json/site-content/v1）的整合測試（API-01、UI-01）。
 * WP 不可達時「失敗」，不略過。
 */
final class WordPressContentTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['app.debug' => false, 'cache.default' => 'array']);
        $this->withoutVite();

        try {
            $health = $this->app->make(WordPressRestClient::class)->getPublic('/health');
        } catch (Throwable $e) {
            $this->fail('WordPress 不可達（'.config('mysite.content.api_base_url').'）：'.$e->getMessage());
        }
        $this->assertSame('ok', $health['data']['status'] ?? null, 'WP /health 必須回 ok');
    }

    private function repository(): ContentRepository
    {
        return $this->app->make(ContentRepository::class);
    }

    #[Test]
    #[Group('API-01')]
    #[Group('UI-01')]
    #[Group('integration-wp')]
    public function lists_and_details_come_from_real_wordpress(): void
    {
        $checked = 0;
        foreach (ContentType::cases() as $type) {
            $page = $this->repository()->list($type, 1, 5);
            $this->assertSame(1, $page->page);
            $this->assertLessThanOrEqual(5, count($page->items));
            foreach ($page->items as $item) {
                $this->assertSame($type, $item->type);
                $this->assertNull($item->body, '列表不含正文');
            }
            // 開發環境 WP 由其他工作包同時寫入：列表與詳情之間被撤下的項目略過，但至少要有一筆成功
            foreach ($page->items as $summary) {
                try {
                    $detail = $this->repository()->findBySlug($type, $summary->slug);
                } catch (MysiteException $e) {
                    $this->assertSame(ErrorCode::ContentNotFound, $e->errorCode);

                    continue;
                }
                $this->assertSame($summary->id, $detail->id);
                $this->assertNotNull($detail->body, '詳情含 SafeContent 正文');
                $lease = $this->repository()->confirmVisible($type, $summary->id);
                $this->assertGreaterThan(now()->getTimestamp(), $lease['expires_at'], '取得可見性租約');
                $checked++;
                break;
            }
        }
        $this->assertGreaterThan(0, $checked, '開發環境 WP 至少要有一種型別的公開內容');
    }

    #[Test]
    #[Group('API-01')]
    #[Group('UI-01')]
    #[Group('integration-wp')]
    public function public_pages_render_real_content_over_http(): void
    {
        $posts = $this->repository()->list(ContentType::Post, 1, 10);
        $this->assertNotEmpty($posts->items, '開發環境 WP 需要至少一篇公開文章');
        $item = null;
        foreach ($posts->items as $candidate) {
            if ($this->get($candidate->path())->status() === 200) {
                $item = $candidate;
                break;
            }
        }
        $this->assertNotNull($item, '至少一篇公開文章的詳情頁可讀');

        $this->get('/')->assertOk()->assertSee('<link rel="canonical"', false);
        $this->get('/articles')->assertOk()->assertSee($item->title);
        $this->get($item->path())->assertOk()->assertSee($item->title)->assertSee('class="prose"', false);
        $this->get('/feed.xml')->assertOk()->assertSee($item->url(), false);
        $this->get('/sitemap.xml')->assertOk()->assertSee(htmlspecialchars($item->url(), ENT_XML1), false);
    }

    #[Test]
    #[Group('API-01')]
    #[Group('UI-03')]
    #[Group('integration-wp')]
    public function unknown_slug_is_not_found(): void
    {
        try {
            $this->repository()->findBySlug(ContentType::Post, 'definitely-not-an-existing-slug-'.bin2hex(random_bytes(4)));
            $this->fail('不存在的 slug 必須是 CONTENT_NOT_FOUND');
        } catch (MysiteException $e) {
            $this->assertSame(ErrorCode::ContentNotFound, $e->errorCode);
        }
        $this->get('/articles/definitely-not-an-existing-slug')->assertNotFound();

        $visibility = $this->app->make(ContentMapper::class)
            ->visibility($this->app->make(WordPressRestClient::class)->getPublic('/content/post/999999999/visibility'));
        $this->assertFalse($visibility->visible);
    }

    #[Test]
    #[Group('API-01')]
    #[Group('UI-01')]
    #[Group('integration-wp')]
    public function search_taxonomies_menus_and_site_from_real_wordpress(): void
    {
        $posts = $this->repository()->list(ContentType::Post, 1, 1);
        $this->assertNotEmpty($posts->items);
        $word = mb_substr($posts->items[0]->title, 0, 2);

        $result = $this->repository()->search($word, null, 1);
        $this->assertGreaterThanOrEqual(1, $result->total, "搜尋「{$word}」應找到至少一筆");
        $this->get('/search?q='.rawurlencode($word))->assertOk()->assertSee('則符合');

        $empty = $this->repository()->search('zzzz-no-such-term-'.bin2hex(random_bytes(3)), null, 1);
        $this->assertSame(0, $empty->total);

        $taxonomies = $this->repository()->taxonomies();
        $this->assertIsArray($taxonomies->categories);
        $this->assertIsArray($this->repository()->menu('primary'));
        $this->assertNotSame('', $this->repository()->site()->name);
        $this->assertNotEmpty($this->app->make(ContentQueryService::class)->sitemapEntries());
    }
}
