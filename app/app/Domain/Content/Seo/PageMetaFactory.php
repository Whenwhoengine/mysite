<?php

declare(strict_types=1);

namespace App\Domain\Content\Seo;

use App\Domain\Content\ContentType;
use App\Domain\Content\Dto\ContentItem;
use App\Domain\Content\Dto\SiteInfo;
use App\Domain\Content\Dto\Term;
use App\Domain\Content\Media\MediaPolicy;

/**
 * 公開頁的中繼資料（title、description、canonical、Open Graph）只在這裡組。
 * Blade 公開頁（ContentController）與公開 API（MySite React 前端專案）共用，兩種畫法的 SEO 結果相同（UI-06）。
 */
final class PageMetaFactory
{
    public function __construct(private readonly MediaPolicy $media) {}

    public function home(?SiteInfo $site): PageMeta
    {
        $siteName = $site?->name ?? (string) config('mysite.site_name');

        return new PageMeta(
            $siteName,
            PageMeta::describe($site?->tagline, $siteName.'：影片、Podcast 與文章。'),
            PageMeta::canonicalUrl('/'),
        );
    }

    public function listing(ContentType $type, int $page): PageMeta
    {
        return new PageMeta(
            $type->collectionTitle().($page > 1 ? "（第 {$page} 頁）" : ''),
            PageMeta::describe($type->collectionLede(), $type->collectionTitle().'列表'),
            PageMeta::canonicalUrl('/'.$type->pathPrefix(), ['page' => $page]),
        );
    }

    public function item(ContentItem $item): PageMeta
    {
        $coverUrl = $item->cover !== null ? $this->media->imageUrl($item->cover->url) : null;

        return new PageMeta(
            $item->title,
            PageMeta::describe($item->excerpt !== '' ? $item->excerpt : $item->body?->plainText(), $item->title),
            PageMeta::canonicalUrl($item->path()),
            ogType: match ($item->type) {
                ContentType::Video => 'video.other',
                ContentType::Page => 'website',
                default => 'article',
            },
            ogImage: $coverUrl,
            ogImageAlt: $coverUrl !== null ? $item->cover?->alt : null,
        );
    }

    /** @param 'category'|'tag' $kind */
    public static function taxonomyLabel(string $kind): string
    {
        return $kind === 'category' ? '分類' : '標籤';
    }

    /** @param 'category'|'tag' $kind */
    public static function taxonomyPath(string $kind, Term $term): string
    {
        return '/'.($kind === 'category' ? 'categories' : 'tags').'/'.rawurlencode($term->slug);
    }

    /** @param 'category'|'tag' $kind */
    public function taxonomyOverview(string $kind, Term $term): PageMeta
    {
        $label = self::taxonomyLabel($kind);

        return new PageMeta("{$label}：{$term->name}", "{$label}「{$term->name}」的文章、影片與 Podcast。", PageMeta::canonicalUrl(self::taxonomyPath($kind, $term)));
    }

    /** @param 'category'|'tag' $kind */
    public function taxonomyListing(string $kind, Term $term, ContentType $type, int $page): PageMeta
    {
        $label = self::taxonomyLabel($kind);

        return new PageMeta(
            "{$label}：{$term->name}－{$type->collectionTitle()}".($page > 1 ? "（第 {$page} 頁）" : ''),
            "{$label}「{$term->name}」的{$type->label()}。",
            PageMeta::canonicalUrl(self::taxonomyPath($kind, $term), ['type' => $type->value, 'page' => $page]),
        );
    }

    public function search(string $q, int $page): PageMeta
    {
        return new PageMeta(
            $q !== '' ? '搜尋「'.mb_strimwidth($q, 0, 40, '…').'」'.($page > 1 ? "（第 {$page} 頁）" : '') : '搜尋',
            '搜尋站內文章、影片與 Podcast。',
            PageMeta::canonicalUrl('/search'),
            noindex: true,
        );
    }
}
