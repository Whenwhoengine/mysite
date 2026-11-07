<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Domain\Content\ContentType;
use App\Domain\Content\Dto\ContentItem;
use App\Domain\Content\Dto\ContentPage;
use App\Domain\Content\Dto\Image;
use App\Domain\Content\Dto\MenuItem;
use App\Domain\Content\Dto\SiteInfo;
use App\Domain\Content\Dto\Term;
use App\Domain\Content\Media\MediaPolicy;
use App\Domain\Content\Seo\PageMeta;
use App\Domain\Content\Seo\PageMetaFactory;
use Carbon\CarbonImmutable;

/**
 * 把既有 DTO 轉成公開 API 契約（app/resources/contracts/public-api-v1.openapi.json）的形狀。
 *
 * 只輸出公開內容與畫面需要的欄位：不含內部 ID、api slug、內容版本、預覽／快照資訊、服務帳號或任何 email（PAPI-05）。
 * 媒體一律經 MediaPolicy（PAPI-06）；富文字只來自 Sanitizer 產生的 SafeContent。
 */
final class PublicApiPresenter
{
    public function __construct(private readonly MediaPolicy $media) {}

    /** @param list<MenuItem> $primary @param list<MenuItem> $footer @return array<string, mixed> */
    public function site(?SiteInfo $site, array $primary, array $footer): array
    {
        return [
            'name' => $site?->name ?? (string) config('mysite.site_name'),
            'tagline' => $site?->tagline ?? '',
            'author_bio' => $site?->authorBio ?? '',
            'social_links' => array_map(static fn (array $l): array => ['label' => $l['label'], 'url' => $l['url']], $site?->socialLinks ?? []),
            'logo' => $this->image($site?->logo),
            'is_sample' => $site?->isSample ?? false,
            'fallback' => $site === null,
            'menus' => [
                'primary' => array_map($this->menuItem(...), $primary),
                'footer' => array_map($this->menuItem(...), $footer),
            ],
            'commerce_enabled' => config('mysite.commerce.mode') === 'simulation',
        ];
    }

    /** @return array<string, mixed> */
    public function summary(ContentItem $item): array
    {
        return [
            'type' => $item->type->value,
            'slug' => $item->slug,
            'path' => $item->path(),
            'title' => $item->title,
            'excerpt' => $item->excerpt,
            'published_at' => self::time($item->publishedAt),
            'modified_at' => self::time($item->modifiedAt),
            'author_name' => $item->authorName,
            'cover' => $this->image($item->cover),
            'categories' => array_map(fn (Term $t): array => $this->term('category', $t), $item->categories),
            'tags' => array_map(fn (Term $t): array => $this->term('tag', $t), $item->tags),
            'duration_seconds' => $item->video?->durationSeconds ?? $item->podcast?->durationSeconds,
            'is_sample' => $item->isSample,
        ];
    }

    /** @return array<string, mixed> */
    public function detail(ContentItem $item): array
    {
        $data = $this->summary($item);
        $data['content_html'] = $item->body?->html() ?? '';
        $data['video'] = null;
        $data['podcast'] = null;
        if ($item->type === ContentType::Video) {
            $id = MediaPolicy::youtubeId($item->video?->youtubeId);
            $data['video'] = [
                'youtube_id' => $id,
                'watch_url' => $id !== null ? MediaPolicy::youtubeWatchUrl($id) : null,
                'embed_url' => $id !== null ? MediaPolicy::youtubeEmbedUrl($id) : null,
                'duration_seconds' => $item->video?->durationSeconds,
                'video_unavailable' => $id === null,
            ];
        } elseif ($item->type === ContentType::Podcast) {
            $src = MediaPolicy::audioSource($item->podcast?->audioUrl);
            $data['podcast'] = [
                'audio_url' => $src,
                'audio_mime' => $src !== null ? MediaPolicy::audioMime($item->podcast?->audioMime) : null,
                'duration_seconds' => $item->podcast?->durationSeconds,
                'audio_unavailable' => $src === null,
            ];
        }

        return $data;
    }

    /** @return list<array<string, mixed>> */
    public function summaries(ContentPage $page): array
    {
        return array_map($this->summary(...), $page->items);
    }

    /** @return array{page: int, per_page: int, total: int, total_pages: int} */
    public function pagination(ContentPage $page): array
    {
        return ['page' => $page->page, 'per_page' => $page->perPage, 'total' => $page->total, 'total_pages' => $page->totalPages];
    }

    /** @return array{type: string, label: string, title: string, lede: string} */
    public function collection(ContentType $type): array
    {
        return ['type' => $type->value, 'label' => $type->label(), 'title' => $type->collectionTitle(), 'lede' => $type->collectionLede()];
    }

    /** @param 'category'|'tag' $kind @return array{slug: string, name: string, path: string, count: ?int} */
    public function term(string $kind, Term $term): array
    {
        return ['slug' => $term->slug, 'name' => $term->name, 'path' => PageMetaFactory::taxonomyPath($kind, $term), 'count' => $term->count];
    }

    /** @return array<string, mixed> */
    public function seo(PageMeta $meta): array
    {
        return [
            'title' => $meta->title,
            'description' => $meta->description,
            'canonical_path' => self::canonicalPath((string) $meta->canonical),
            'noindex' => $meta->noindex,
            'og_type' => $meta->ogType,
            'og_image' => $meta->ogImage,
            'og_image_alt' => $meta->ogImageAlt,
        ];
    }

    /** @return array{url: string, alt: string, width: ?int, height: ?int}|null */
    public function image(?Image $image): ?array
    {
        $url = $image !== null ? $this->media->imageUrl($image->url) : null;
        if ($image === null || $url === null) {
            return null;
        }

        return ['url' => $url, 'alt' => $image->alt, 'width' => $image->width, 'height' => $image->height];
    }

    /** @return array{label: string, url: string, external: bool} */
    private function menuItem(MenuItem $item): array
    {
        return ['label' => $item->label, 'url' => $item->url, 'external' => $item->external];
    }

    /** canonical 去掉主站網址前綴，只留路徑與查詢字串（前端以自己的主站網址環境變數組回完整網址）。 */
    private static function canonicalPath(string $canonical): string
    {
        $base = rtrim((string) config('app.url'), '/');
        $path = str_starts_with($canonical, $base) ? substr($canonical, strlen($base)) : (string) parse_url($canonical, PHP_URL_PATH);

        return $path === '' || $path[0] !== '/' ? '/'.$path : $path;
    }

    private static function time(CarbonImmutable $time): string
    {
        return $time->utc()->format('Y-m-d\TH:i:s\Z');
    }
}
