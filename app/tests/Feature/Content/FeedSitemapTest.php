<?php

declare(strict_types=1);

namespace Tests\Feature\Content;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Sleep;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\Content\Concerns\FakesWordPress;
use Tests\TestCase;

/** UI-06：RSS 與 sitemap 只含主站公開網址；API-13：不含預覽或非公開內容。 */
final class FeedSitemapTest extends TestCase
{
    use FakesWordPress;

    protected function setUp(): void
    {
        parent::setUp();
        $this->configureContent();
        $this->fakeWordPress([
            '/posts' => fn (HttpRequest $r) => self::listEnvelope((int) $r->data()['page'] === 1 ? [
                self::wpItem(['id' => 1, 'slug' => 'post-a', 'title' => '文章 A & <B>', 'published_at' => '2026-11-03T00:00:00Z']),
            ] : [], (int) $r->data()['page'], (int) $r->data()['per_page'], 1),
            '/videos' => fn (HttpRequest $r) => self::listEnvelope([self::wpItem(['id' => 2, 'type' => 'video', 'slug' => 'video-a', 'title' => '影片 A', 'is_sample' => false])], 1, (int) $r->data()['per_page']),
            '/podcasts' => fn (HttpRequest $r) => self::listEnvelope([self::wpItem(['id' => 3, 'type' => 'podcast', 'slug' => '%e7%af%80%e7%9b%ae', 'title' => '節目一'])], 1, (int) $r->data()['per_page']),
            '/pages' => fn (HttpRequest $r) => self::listEnvelope([self::wpItem(['id' => 4, 'type' => 'page', 'slug' => 'about', 'title' => '關於'])], 1, (int) $r->data()['per_page']),
            '/taxonomies' => ['data' => ['categories' => [['id' => 3, 'slug' => 'news', 'name' => '新聞', 'count' => 2], ['id' => 4, 'slug' => 'empty', 'name' => '空', 'count' => 0]], 'tags' => [['id' => 7, 'slug' => 'demo', 'name' => '示例', 'count' => 1]]], 'meta' => self::meta()],
            '/site' => ['data' => ['name' => 'mysite', 'tagline' => '影音創作紀錄', 'author_bio' => '', 'social_links' => [], 'logo' => null, 'is_sample' => true], 'meta' => self::meta()],
        ]);
    }

    #[Test]
    #[Group('UI-06')]
    #[Group('API-13')]
    public function rss_feed_is_valid_and_points_to_the_main_site(): void
    {
        $response = $this->get('/feed.xml')->assertOk()->assertHeader('Content-Type', 'application/rss+xml; charset=utf-8');
        $xml = simplexml_load_string($response->getContent());
        $this->assertNotFalse($xml);
        $this->assertSame('2.0', (string) $xml['version']);
        $this->assertSame('mysite', (string) $xml->channel->title);
        $this->assertSame('影音創作紀錄', (string) $xml->channel->description);

        $links = [];
        foreach ($xml->channel->item as $item) {
            $links[] = (string) $item->link;
            $this->assertStringStartsWith('http://mysite.localhost:8080/', (string) $item->link);
            $this->assertSame((string) $item->link, (string) $item->guid);
            $this->assertNotFalse(strtotime((string) $item->pubDate));
        }
        $this->assertSame([
            'http://mysite.localhost:8080/articles/post-a',
            'http://mysite.localhost:8080/videos/video-a',
            'http://mysite.localhost:8080/podcasts/%E7%AF%80%E7%9B%AE',
        ], $links, '依發布時間排序（同時間保持型別順序）');
        $this->assertSame('［示例內容］文章 A & <B>', (string) $xml->channel->item[0]->title);
        $this->assertStringContainsString('【影片．4:05】', (string) $xml->channel->item[1]->description);
        $this->assertStringNotContainsString('cms.mysite.localhost', $response->getContent());
        $this->assertStringNotContainsString('/preview', $response->getContent());
    }

    #[Test]
    #[Group('UI-06')]
    #[Group('API-13')]
    public function sitemap_lists_only_public_main_site_urls(): void
    {
        $response = $this->get('/sitemap.xml')->assertOk()->assertHeader('Content-Type', 'application/xml; charset=utf-8');
        $xml = simplexml_load_string($response->getContent());
        $this->assertNotFalse($xml);
        $locs = [];
        foreach ($xml->url as $url) {
            $locs[] = (string) $url->loc;
        }

        foreach (['/', '/articles', '/videos', '/podcasts', '/articles/post-a', '/videos/video-a', '/podcasts/%E7%AF%80%E7%9B%AE', '/pages/about', '/categories/news', '/tags/demo'] as $path) {
            $this->assertContains('http://mysite.localhost:8080'.$path, $locs);
        }
        $this->assertNotContains('http://mysite.localhost:8080/categories/empty', $locs, '沒有公開內容的分類不列入');
        foreach ($locs as $loc) {
            $this->assertStringStartsWith('http://mysite.localhost:8080/', $loc);
            $this->assertStringNotContainsString('/search', $loc);
            $this->assertStringNotContainsString('/preview', $loc);
            $this->assertStringNotContainsString('/shop', $loc);
            $this->assertStringNotContainsString('/admin', $loc);
        }
        $this->assertSame(count($locs), count(array_unique($locs)), 'canonical 唯一');
        $this->assertStringContainsString('<lastmod>2026-11-02T03:00:00Z</lastmod>', $response->getContent());
    }

    #[Test]
    #[Group('UI-03')]
    public function feed_fails_closed_when_wordpress_is_down(): void
    {
        Sleep::fake();
        $this->wpRoutes = ['/*' => fn () => throw new ConnectionException('cURL error 7: Failed to connect')];

        $this->get('/feed.xml')->assertStatus(503);
        $this->get('/sitemap.xml')->assertStatus(503);
    }
}
