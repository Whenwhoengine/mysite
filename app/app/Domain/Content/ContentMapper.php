<?php

declare(strict_types=1);

namespace App\Domain\Content;

use App\Domain\Content\Dto\ContentItem;
use App\Domain\Content\Dto\ContentPage;
use App\Domain\Content\Dto\Image;
use App\Domain\Content\Dto\MenuItem;
use App\Domain\Content\Dto\PodcastInfo;
use App\Domain\Content\Dto\SiteInfo;
use App\Domain\Content\Dto\Taxonomies;
use App\Domain\Content\Dto\Term;
use App\Domain\Content\Dto\VideoInfo;
use App\Domain\Content\Dto\Visibility;
use App\Domain\Content\Support\Slug;
use App\Support\Errors\ErrorCode;
use App\Support\Errors\MysiteException;
use App\Support\Html\Sanitizer;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Log;

/**
 * DTO 與 model_version 驗證（契約 §1、§2）。
 *
 * 只接受 meta.api_version === "v1" 且 meta.model_version === 1；缺必要欄位或型別不符一律
 * CONTENT_MODEL_UNSUPPORTED（503），不把它偽裝成「沒有內容」。
 */
final class ContentMapper
{
    public const API_VERSION = 'v1';

    public const MODEL_VERSION = 1;

    public function __construct(private readonly Sanitizer $sanitizer)
    {
    }

    /**
     * 驗證共同回應結構，回傳 data。
     *
     * @param  array<string, mixed>  $envelope
     */
    public function data(array $envelope): mixed
    {
        $meta = $envelope['meta'] ?? null;
        if (! is_array($meta)) {
            $this->unsupported('meta');
        }
        if (($meta['model_version'] ?? null) !== self::MODEL_VERSION) {
            $this->unsupported('model_version');
        }
        if (($meta['api_version'] ?? null) !== self::API_VERSION) {
            $this->unsupported('api_version');
        }
        if (! array_key_exists('data', $envelope)) {
            $this->unsupported('data');
        }

        return $envelope['data'];
    }

    /**
     * 列表回應 → ContentPage。
     *
     * @param  array<string, mixed>  $envelope
     */
    public function page(array $envelope, ?ContentType $expectedType = null): ContentPage
    {
        $data = $this->data($envelope);
        if (! is_array($data) || ! array_is_list($data)) {
            $this->unsupported('data_list');
        }
        $meta = $envelope['meta'];
        foreach (['page', 'per_page', 'total', 'total_pages'] as $key) {
            if (! is_int($meta[$key] ?? null) || $meta[$key] < 0) {
                $this->unsupported('meta.'.$key);
            }
        }
        $items = [];
        foreach ($data as $raw) {
            if (! is_array($raw)) {
                $this->unsupported('item');
            }
            $item = $this->item($raw, false);
            if ($expectedType !== null && $item->type !== $expectedType) {
                $this->unsupported('item.type_mismatch');
            }
            $items[] = $item;
        }

        return new ContentPage($items, max(1, $meta['page']), max(1, $meta['per_page']), $meta['total'], $meta['total_pages']);
    }

    /**
     * 單一內容物件。
     *
     * @param  array<string, mixed>  $raw
     */
    public function item(array $raw, bool $requireBody, bool $preview = false): ContentItem
    {
        $id = $this->positiveInt($raw, 'id');
        $type = ContentType::tryFrom($this->string($raw, 'type'));
        if ($type === null) {
            $this->unsupported('type');
        }
        $apiSlug = $this->string($raw, 'slug');
        if ($apiSlug === '' || preg_match('/^[%a-z0-9-]{1,200}$/', $apiSlug) !== 1) {
            if (! $preview || $apiSlug !== '') {
                $this->unsupported('slug');
            }
        }
        $body = null;
        if (array_key_exists('body_html', $raw)) {
            if (! is_string($raw['body_html'])) {
                $this->unsupported('body_html');
            }
            $body = $this->sanitizer->sanitize($raw['body_html'], $preview);
        } elseif ($requireBody) {
            $this->unsupported('body_html');
        }

        $author = $raw['author'] ?? null;
        if ($author !== null && (! is_array($author) || ! is_string($author['display_name'] ?? null))) {
            $this->unsupported('author');
        }

        $video = null;
        $podcast = null;
        if ($type === ContentType::Video) {
            $video = $this->video($raw['video'] ?? null);
        }
        if ($type === ContentType::Podcast) {
            $podcast = $this->podcast($raw['podcast'] ?? null);
        }
        if (! is_bool($raw['is_sample'] ?? null)) {
            $this->unsupported('is_sample');
        }

        return new ContentItem(
            id: $id,
            type: $type,
            slug: $apiSlug === '' ? '' : Slug::fromApi($apiSlug),
            apiSlug: $apiSlug,
            title: $this->string($raw, 'title'),
            excerpt: $this->string($raw, 'excerpt'),
            body: $body,
            authorName: $author !== null ? trim($author['display_name']) : null,
            categories: $this->terms($raw['categories'] ?? null, 'categories'),
            tags: $this->terms($raw['tags'] ?? null, 'tags'),
            cover: $this->image($raw['cover'] ?? null, 'cover'),
            // 預覽中的草稿可能尚無發布時間：以修改時間代替顯示
            publishedAt: $preview && ($raw['published_at'] ?? null) === null ? $this->time($raw, 'modified_at') : $this->time($raw, 'published_at'),
            modifiedAt: $this->time($raw, 'modified_at'),
            contentVersion: $this->nonNegativeInt($raw, 'content_version'),
            isSample: $raw['is_sample'],
            video: $video,
            podcast: $podcast,
            isPreview: $preview,
            snapshotId: $preview ? (is_string($raw['snapshot_id'] ?? null) ? $raw['snapshot_id'] : null) : null,
            revisionId: $preview ? (is_int($raw['revision_id'] ?? null) ? $raw['revision_id'] : null) : null,
        );
    }

    /** @param array<string, mixed> $envelope */
    public function visibility(array $envelope): Visibility
    {
        $data = $this->data($envelope);
        if (! is_array($data)) {
            $this->unsupported('visibility');
        }
        $type = ContentType::tryFrom(is_string($data['type'] ?? null) ? $data['type'] : '');
        if ($type === null || ! is_bool($data['visible'] ?? null)) {
            $this->unsupported('visibility');
        }

        return new Visibility($type, $this->positiveInt($data, 'id'), $data['visible'], $this->nonNegativeInt($data, 'content_version'));
    }

    /** @param array<string, mixed> $envelope */
    public function taxonomies(array $envelope): Taxonomies
    {
        $data = $this->data($envelope);
        if (! is_array($data)) {
            $this->unsupported('taxonomies');
        }

        return new Taxonomies(
            $this->terms($data['categories'] ?? null, 'categories', true),
            $this->terms($data['tags'] ?? null, 'tags', true),
        );
    }

    /**
     * 選單：不合規則的單一項目略過（內部必須是主站相對路徑、外部只允許 https）。
     *
     * @param  array<string, mixed>  $envelope
     * @return list<MenuItem>
     */
    public function menu(array $envelope): array
    {
        $data = $this->data($envelope);
        if (! is_array($data) || ! is_array($data['items'] ?? null)) {
            $this->unsupported('menu');
        }
        $items = [];
        foreach ($data['items'] as $raw) {
            if (! is_array($raw) || ! is_string($raw['label'] ?? null) || ! is_string($raw['url'] ?? null)) {
                continue;
            }
            $label = trim($raw['label']);
            $url = trim($raw['url']);
            $external = (bool) ($raw['external'] ?? false);
            if ($label === '' || mb_strlen($label) > 60) {
                continue;
            }
            $isRelative = preg_match('#^/(?!/)[^\s\\\\]*$#', $url) === 1;
            $isHttps = preg_match('#^https://[^\s/\\\\@]+(/[^\s\\\\]*)?$#i', $url) === 1;
            if ($external ? ! $isHttps : ! $isRelative) {
                continue;
            }
            $items[] = new MenuItem($label, $url, $external);
        }

        return $items;
    }

    /** @param array<string, mixed> $envelope */
    public function site(array $envelope): SiteInfo
    {
        $data = $this->data($envelope);
        if (! is_array($data) || ! is_string($data['name'] ?? null)) {
            $this->unsupported('site');
        }
        $links = [];
        foreach ((array) ($data['social_links'] ?? []) as $link) {
            if (is_array($link) && is_string($link['label'] ?? null) && is_string($link['url'] ?? null)
                && preg_match('#^https://[^\s/\\\\@]+(/[^\s\\\\]*)?$#i', $link['url']) === 1) {
                $links[] = ['label' => trim($link['label']), 'url' => $link['url']];
            }
        }

        return new SiteInfo(
            name: trim($data['name']) !== '' ? trim($data['name']) : (string) config('mysite.site_name'),
            tagline: is_string($data['tagline'] ?? null) ? trim($data['tagline']) : '',
            authorBio: is_string($data['author_bio'] ?? null) ? trim($data['author_bio']) : '',
            socialLinks: $links,
            logo: $this->image($data['logo'] ?? null, 'logo'),
            isSample: (bool) ($data['is_sample'] ?? false),
        );
    }

    /** @return list<Term> */
    private function terms(mixed $value, string $field, bool $withCount = false): array
    {
        if ($value === null) {
            return [];
        }
        if (! is_array($value) || ! array_is_list($value)) {
            $this->unsupported($field);
        }
        $terms = [];
        foreach ($value as $raw) {
            if (! is_array($raw) || ! is_int($raw['id'] ?? null) || ! is_string($raw['slug'] ?? null) || ! is_string($raw['name'] ?? null)) {
                $this->unsupported($field);
            }
            if (preg_match('/^[%a-z0-9-]{1,200}$/', $raw['slug']) !== 1) {
                continue;
            }
            $terms[] = new Term($raw['id'], Slug::fromApi($raw['slug']), $raw['slug'], trim($raw['name']), $withCount && is_int($raw['count'] ?? null) ? $raw['count'] : null);
        }

        return $terms;
    }

    private function image(mixed $value, string $field): ?Image
    {
        if ($value === null) {
            return null;
        }
        if (! is_array($value) || ! is_string($value['url'] ?? null)) {
            $this->unsupported($field);
        }
        $dimension = static fn (mixed $v): ?int => is_int($v) && $v > 0 && $v <= 20000 ? $v : null;

        return new Image(
            $value['url'],
            is_string($value['alt'] ?? null) ? trim($value['alt']) : '',
            $dimension($value['width'] ?? null),
            $dimension($value['height'] ?? null),
        );
    }

    private function video(mixed $value): ?VideoInfo
    {
        if ($value === null) {
            return null;
        }
        if (! is_array($value)) {
            $this->unsupported('video');
        }

        return new VideoInfo(
            is_string($value['youtube_id'] ?? null) ? $value['youtube_id'] : null,
            is_string($value['youtube_url'] ?? null) ? $value['youtube_url'] : null,
            is_int($value['duration_seconds'] ?? null) && $value['duration_seconds'] >= 0 ? $value['duration_seconds'] : null,
        );
    }

    private function podcast(mixed $value): ?PodcastInfo
    {
        if ($value === null) {
            return null;
        }
        if (! is_array($value)) {
            $this->unsupported('podcast');
        }

        return new PodcastInfo(
            is_string($value['audio_url'] ?? null) ? $value['audio_url'] : null,
            is_string($value['audio_mime'] ?? null) ? $value['audio_mime'] : null,
            is_int($value['duration_seconds'] ?? null) && $value['duration_seconds'] >= 0 ? $value['duration_seconds'] : null,
        );
    }

    /** @param array<string, mixed> $raw */
    private function string(array $raw, string $key): string
    {
        if (! is_string($raw[$key] ?? null)) {
            $this->unsupported($key);
        }

        return trim($raw[$key]);
    }

    /** @param array<string, mixed> $raw */
    private function positiveInt(array $raw, string $key): int
    {
        if (! is_int($raw[$key] ?? null) || $raw[$key] < 1) {
            $this->unsupported($key);
        }

        return $raw[$key];
    }

    /** @param array<string, mixed> $raw */
    private function nonNegativeInt(array $raw, string $key): int
    {
        if (! is_int($raw[$key] ?? null) || $raw[$key] < 0) {
            $this->unsupported($key);
        }

        return $raw[$key];
    }

    /** @param array<string, mixed> $raw */
    private function time(array $raw, string $key): CarbonImmutable
    {
        $value = $raw[$key] ?? null;
        if (! is_string($value) || preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/', $value) !== 1) {
            $this->unsupported($key);
        }
        $time = CarbonImmutable::createFromFormat('Y-m-d\TH:i:s\Z', $value, 'UTC');
        if ($time === null || $time->format('Y-m-d\TH:i:s\Z') !== $value) {
            $this->unsupported($key);
        }

        return $time;
    }

    private function unsupported(string $field): never
    {
        // 內部告警：模型版本或欄位不符（不揭露給訪客）
        Log::error('content_model_unsupported', ['field' => $field]);

        throw new MysiteException(ErrorCode::ContentModelUnsupported, 'content_model_unsupported:'.$field);
    }
}
