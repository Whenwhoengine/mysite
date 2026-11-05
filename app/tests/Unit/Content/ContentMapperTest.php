<?php

declare(strict_types=1);

namespace Tests\Unit\Content;

use App\Domain\Content\ContentMapper;
use App\Domain\Content\ContentType;
use App\Support\Errors\ErrorCode;
use App\Support\Errors\MysiteException;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\Content\Concerns\FakesWordPress;
use Tests\TestCase;

/** API-02：model_version／欄位驗證；API-14：只保留契約欄位。 */
final class ContentMapperTest extends TestCase
{
    use FakesWordPress;

    protected function setUp(): void
    {
        parent::setUp();
        $this->configureContent();
    }

    private function mapper(): ContentMapper
    {
        return $this->app->make(ContentMapper::class);
    }

    private function assertUnsupported(callable $call, string $field): void
    {
        try {
            $call();
            $this->fail('預期 CONTENT_MODEL_UNSUPPORTED：'.$field);
        } catch (MysiteException $e) {
            $this->assertSame(ErrorCode::ContentModelUnsupported, $e->errorCode, $field);
            $this->assertSame(503, $e->errorCode->status());
            $this->assertStringContainsString($field, $e->getMessage());
        }
    }

    #[Test]
    #[Group('API-02')]
    public function unsupported_model_or_api_versions_are_rejected(): void
    {
        foreach ([['model_version' => 2], ['model_version' => '1'], ['api_version' => 'v2']] as $override) {
            $envelope = self::listEnvelope([self::wpItem()]);
            $envelope['meta'] = array_merge($envelope['meta'], $override);
            $this->assertUnsupported(fn () => $this->mapper()->page($envelope), array_key_first($override));
        }
        $this->assertUnsupported(fn () => $this->mapper()->page(['data' => []]), 'meta');
        $this->assertUnsupported(fn () => $this->mapper()->page(['meta' => self::meta()]), 'data');
        $this->assertUnsupported(fn () => $this->mapper()->page(['data' => [], 'meta' => self::meta()]), 'meta.page');
    }

    #[Test]
    #[Group('API-02')]
    public function missing_or_mistyped_fields_are_rejected(): void
    {
        $cases = [
            'title' => ['title' => null],
            'id' => ['id' => '101'],
            'type' => ['type' => 'product'],
            'slug' => ['slug' => 'Bad Slug'],
            'published_at' => ['published_at' => '2026-11-01 02:00:00'],
            'content_version' => ['content_version' => -1],
            'is_sample' => ['is_sample' => 'yes'],
            'author' => ['author' => 'x'],
            'categories' => ['categories' => 'news'],
            'cover' => ['cover' => ['alt' => 'x']],
        ];
        foreach ($cases as $field => $override) {
            $this->assertUnsupported(fn () => $this->mapper()->item(self::wpItem($override), false), $field);
        }
        $this->assertUnsupported(fn () => $this->mapper()->item(self::wpItem(), true), 'body_html');
        $this->assertUnsupported(fn () => $this->mapper()->item(self::wpItem(['type' => 'video', 'video' => 'x']), false), 'video');
    }

    #[Test]
    #[Group('API-01')]
    #[Group('API-14')]
    public function items_map_to_dtos_with_only_contract_fields(): void
    {
        $raw = self::wpItem([
            'type' => 'video',
            'body_html' => '<p>正文</p><script>alert(1)</script>',
            'author' => ['display_name' => '編輯部', 'email' => 'secret@example.org', 'user_login' => 'admin'],
            'post_password' => 'hunter2',
        ]);
        $item = $this->mapper()->item($raw, true);

        $this->assertSame(ContentType::Video, $item->type);
        $this->assertSame('/videos/sample-article', $item->path());
        $this->assertSame('http://mysite.localhost:8080/videos/sample-article', $item->url());
        $this->assertSame('dQw4w9WgXcQ', $item->video?->youtubeId);
        $this->assertSame(245, $item->video?->durationSeconds);
        $this->assertSame('<p>正文</p>', $item->body?->html());
        $this->assertSame('UTC', $item->publishedAt->getTimezone()->getName());
        $this->assertSame('2026-11-01T02:00:00Z', $item->publishedAt->format('Y-m-d\TH:i:s\Z'));
        $this->assertSame('news', $item->categories[0]->slug);
        $this->assertTrue($item->isSample);
        $this->assertSame('編輯部', $item->authorName);
        $serialized = serialize($item);
        foreach (['secret@example.org', 'hunter2', 'user_login'] as $leak) {
            $this->assertStringNotContainsString($leak, $serialized);
        }
    }

    #[Test]
    #[Group('API-02')]
    public function visibility_taxonomies_menu_and_site_are_validated(): void
    {
        $visibility = $this->mapper()->visibility(self::visibilityEnvelope('post', 5, false, 0));
        $this->assertFalse($visibility->visible);
        $this->assertSame(0, $visibility->contentVersion);
        $this->assertUnsupported(fn () => $this->mapper()->visibility(['data' => ['type' => 'post', 'id' => 5, 'visible' => 'no', 'content_version' => 1], 'meta' => self::meta()]), 'visibility');

        $tax = $this->mapper()->taxonomies(['data' => ['categories' => [['id' => 1, 'slug' => 'news', 'name' => '新聞', 'count' => 3]], 'tags' => []], 'meta' => self::meta()]);
        $this->assertSame(3, $tax->find('category', 'news')?->count);
        $this->assertNull($tax->find('tag', 'news'));

        $menu = $this->mapper()->menu(['data' => ['location' => 'primary', 'items' => [
            ['label' => '文章', 'url' => '/articles', 'external' => false],
            ['label' => 'YouTube', 'url' => 'https://www.youtube.com/@x', 'external' => true],
            ['label' => '明文外連', 'url' => 'http://example.org/', 'external' => true],
            ['label' => '腳本', 'url' => 'javascript:alert(1)', 'external' => false],
            ['label' => '協定相對', 'url' => '//evil.example/', 'external' => false],
            ['label' => 'CMS 絕對網址', 'url' => 'http://cms.mysite.localhost:8081/x', 'external' => false],
            ['label' => '', 'url' => '/x', 'external' => false],
        ]], 'meta' => self::meta()]);
        $this->assertSame(['文章', 'YouTube'], array_map(fn ($m) => $m->label, $menu));

        $site = $this->mapper()->site(['data' => ['name' => 'mysite', 'tagline' => '標語', 'author_bio' => '介紹', 'social_links' => [
            ['label' => 'YouTube', 'url' => 'https://youtube.com/@x'],
            ['label' => '明文', 'url' => 'http://example.org/'],
            ['label' => '腳本', 'url' => 'javascript:alert(1)'],
        ], 'logo' => null, 'is_sample' => true], 'meta' => self::meta()]);
        $this->assertSame([['label' => 'YouTube', 'url' => 'https://youtube.com/@x']], $site->socialLinks);
        $this->assertTrue($site->isSample);
    }
}
