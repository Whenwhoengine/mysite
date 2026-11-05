<?php

declare(strict_types=1);

namespace App\Domain\Content;

use App\Domain\Content\Dto\ContentItem;
use App\Domain\Content\Dto\ContentPage;
use App\Domain\Content\Dto\MenuItem;
use App\Domain\Content\Dto\SiteInfo;
use App\Domain\Content\Dto\Term;
use App\Domain\Content\Support\Slug;
use App\Support\Errors\ErrorCode;
use App\Support\Errors\MysiteException;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * 頁面層的內容查詢（Controller 只呼叫這裡，不自行拼 URL 或持有憑證）。
 */
final class ContentQueryService
{
    public const HOME_POSTS = 6;

    public const HOME_MEDIA = 4;

    public const TAXONOMY_PREVIEW = 6;

    public const FEED_ITEMS = 20;

    /** sitemap 每個型別最多讀取的列表頁數（每頁 50 筆；暫定值，見完工報告）。 */
    public const SITEMAP_MAX_PAGES = 20;

    public function __construct(private readonly ContentRepository $repository)
    {
    }

    /**
     * @return array{posts: ContentPage, videos: ContentPage, podcasts: ContentPage, featured: ?ContentItem}
     */
    public function home(): array
    {
        $posts = $this->repository->list(ContentType::Post, 1, self::HOME_POSTS);
        $videos = $this->repository->list(ContentType::Video, 1, self::HOME_MEDIA);
        $podcasts = $this->repository->list(ContentType::Podcast, 1, self::HOME_MEDIA);

        $candidates = array_filter([$posts->items[0] ?? null, $videos->items[0] ?? null, $podcasts->items[0] ?? null]);
        usort($candidates, static fn (ContentItem $a, ContentItem $b): int => $b->publishedAt <=> $a->publishedAt);

        return ['posts' => $posts, 'videos' => $videos, 'podcasts' => $podcasts, 'featured' => $candidates[0] ?? null];
    }

    public function listing(ContentType $type, int $page): ContentPage
    {
        return $this->assertPageInRange($this->repository->list($type, $page), $page);
    }

    public function detail(ContentType $type, string $slug): ContentItem
    {
        return $this->repository->findBySlug($type, $slug);
    }

    /** @param 'category'|'tag' $kind */
    public function term(string $kind, string $slug): Term
    {
        $apiSlug = Slug::toApi($slug) ?? throw new MysiteException(ErrorCode::ContentNotFound, 'term_slug_format');
        $term = $this->repository->taxonomies()->find($kind, $apiSlug);
        if ($term === null) {
            throw new MysiteException(ErrorCode::ContentNotFound, 'term_missing');
        }

        return $term;
    }

    /**
     * 分類／標籤總覽：各型別最新幾筆。
     *
     * @param  'category'|'tag'  $kind
     * @return array<string, ContentPage> 以 ContentType value 為鍵
     */
    public function termOverview(string $kind, Term $term): array
    {
        $result = [];
        foreach (ContentType::listable() as $type) {
            $result[$type->value] = $this->repository->list(
                $type, 1, self::TAXONOMY_PREVIEW,
                $kind === 'category' ? $term->apiSlug : null,
                $kind === 'tag' ? $term->apiSlug : null,
            );
        }

        return $result;
    }

    /** @param 'category'|'tag' $kind */
    public function termListing(string $kind, Term $term, ContentType $type, int $page): ContentPage
    {
        return $this->assertPageInRange($this->repository->list(
            $type, $page, ContentRepository::DEFAULT_PER_PAGE,
            $kind === 'category' ? $term->apiSlug : null,
            $kind === 'tag' ? $term->apiSlug : null,
        ), $page);
    }

    public function search(string $q, ?ContentType $type, int $page): ContentPage
    {
        return $this->assertPageInRange($this->repository->search($q, $type, $page), $page);
    }

    /**
     * 主選單（WP menus/primary）；讀不到時使用內建後備選單，不讓整頁失敗。
     *
     * @return list<MenuItem>
     */
    public function primaryMenu(): array
    {
        $items = $this->withFallback('menu_primary', fn (): array => $this->repository->menu('primary'), []);

        return $items !== [] ? $items : self::fallbackMenu();
    }

    /** @return list<MenuItem> */
    public function footerMenu(): array
    {
        return $this->withFallback('menu_footer', fn (): array => $this->repository->menu('footer'), []);
    }

    public function site(): ?SiteInfo
    {
        return $this->withFallback('site', fn (): SiteInfo => $this->repository->site(), null);
    }

    /** @return list<MenuItem> */
    public static function fallbackMenu(): array
    {
        return [
            new MenuItem('文章', '/articles', false),
            new MenuItem('影片', '/videos', false),
            new MenuItem('Podcast', '/podcasts', false),
            new MenuItem('搜尋', '/search', false),
        ];
    }

    /**
     * RSS：文章、影片、Podcast 最新內容合併排序。
     *
     * @return list<ContentItem>
     */
    public function feedItems(): array
    {
        $items = [];
        foreach (ContentType::listable() as $type) {
            array_push($items, ...$this->repository->list($type, 1, self::FEED_ITEMS)->items);
        }
        usort($items, static fn (ContentItem $a, ContentItem $b): int => $b->publishedAt <=> $a->publishedAt);

        return array_slice($items, 0, self::FEED_ITEMS);
    }

    /**
     * sitemap：只有主站公開網址（列表與詳情、有公開內容的分類／標籤）。
     *
     * @return list<array{path: string, lastmod: ?\Carbon\CarbonImmutable}>
     */
    public function sitemapEntries(): array
    {
        $entries = [['path' => '/', 'lastmod' => null]];
        foreach (ContentType::listable() as $type) {
            $entries[] = ['path' => '/'.$type->pathPrefix(), 'lastmod' => null];
        }
        foreach ([ContentType::Post, ContentType::Video, ContentType::Podcast, ContentType::Page] as $type) {
            $page = 1;
            do {
                $result = $this->repository->list($type, $page, ContentRepository::MAX_PER_PAGE);
                foreach ($result->items as $item) {
                    $entries[] = ['path' => $item->path(), 'lastmod' => $item->modifiedAt];
                }
                $page++;
            } while ($page <= min($result->totalPages, self::SITEMAP_MAX_PAGES));
        }
        $taxonomies = $this->repository->taxonomies();
        foreach (['categories' => $taxonomies->categories, 'tags' => $taxonomies->tags] as $prefix => $terms) {
            foreach ($terms as $term) {
                if (($term->count ?? 0) > 0) {
                    $entries[] = ['path' => '/'.$prefix.'/'.rawurlencode($term->slug), 'lastmod' => null];
                }
            }
        }

        return $entries;
    }

    private function assertPageInRange(ContentPage $result, int $page): ContentPage
    {
        if ($page > 1 && $page > $result->totalPages) {
            throw new MysiteException(ErrorCode::ContentNotFound, 'page_out_of_range');
        }

        return $result;
    }

    /**
     * @template T
     *
     * @param  callable(): T  $load
     * @param  T  $fallback
     * @return T
     */
    private function withFallback(string $what, callable $load, mixed $fallback): mixed
    {
        $request = app()->bound('request') ? app('request') : null;
        if ($request?->attributes->get('mysite.content_upstream_failed') === true) {
            return $fallback;
        }
        try {
            return $load();
        } catch (Throwable $e) {
            Log::notice('content_layout_fallback', ['what' => $what, 'reason' => $e instanceof MysiteException ? $e->getMessage() : $e::class]);

            return $fallback;
        }
    }
}
