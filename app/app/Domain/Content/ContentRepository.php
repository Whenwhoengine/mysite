<?php

declare(strict_types=1);

namespace App\Domain\Content;

use App\Domain\Content\Cache\ContentCache;
use App\Domain\Content\Dto\ContentItem;
use App\Domain\Content\Dto\ContentPage;
use App\Domain\Content\Dto\MenuItem;
use App\Domain\Content\Dto\SiteInfo;
use App\Domain\Content\Dto\Taxonomies;
use App\Domain\Content\Http\WordPressRestClient;
use App\Domain\Content\Support\Slug;
use App\Support\Errors\ErrorCode;
use App\Support\Errors\MysiteException;

/**
 * 內容讀取（v5 §8.3）：WP REST client＋DTO 驗證＋sanitizer＋正文快取與可見性租約。
 *
 * 正文供應規則（API-10）：必須持有未過期的可見性證明；過期就同步呼叫 visibility 端點；
 * WP 回 visible=false → 404 並清除；WP 不可達 → 503（例外往外拋），絕不回舊正文。
 */
final class ContentRepository
{
    public const DEFAULT_PER_PAGE = 12;

    public const MAX_PER_PAGE = 50;

    public const MAX_PAGE = 1000;

    public function __construct(
        private readonly WordPressRestClient $client,
        private readonly ContentMapper $mapper,
        private readonly ContentCache $cache,
    ) {
    }

    public function list(ContentType $type, int $page = 1, int $perPage = self::DEFAULT_PER_PAGE, ?string $categoryApiSlug = null, ?string $tagApiSlug = null): ContentPage
    {
        $this->assertPaging($page, $perPage);
        $query = [
            'page' => $page,
            'per_page' => $perPage,
            'category' => $categoryApiSlug,
            'tag' => $tagApiSlug,
            'orderby' => 'date',
            'order' => 'desc',
        ];
        $envelope = $this->cache->collection('list:'.$type->value, $query, fn (): array => $this->client->getPublic('/'.$type->endpoint(), $query));

        return $this->mapper->page($envelope, $type);
    }

    public function search(string $q, ?ContentType $type, int $page = 1, int $perPage = self::DEFAULT_PER_PAGE): ContentPage
    {
        $this->assertPaging($page, $perPage);
        $query = ['q' => $q, 'type' => $type?->value, 'page' => $page, 'per_page' => $perPage];
        $envelope = $this->cache->collection('search', $query, fn (): array => $this->client->getPublic('/search', $query));

        return $this->mapper->page($envelope);
    }

    public function taxonomies(): Taxonomies
    {
        return $this->mapper->taxonomies($this->cache->collection('taxonomies', [], fn (): array => $this->client->getPublic('/taxonomies')));
    }

    /** @return list<MenuItem> */
    public function menu(string $location): array
    {
        if (! in_array($location, ['primary', 'footer'], true)) {
            throw new MysiteException(ErrorCode::ContentNotFound, 'menu_location');
        }

        return $this->mapper->menu($this->cache->collection('menu', ['location' => $location], fn (): array => $this->client->getPublic('/menus/'.$location)));
    }

    public function site(): SiteInfo
    {
        return $this->mapper->site($this->cache->collection('site', [], fn (): array => $this->client->getPublic('/site')));
    }

    /**
     * 以 slug 取得含正文的內容（受可見性租約約束）。
     */
    public function findBySlug(ContentType $type, string $slug): ContentItem
    {
        $apiSlug = Slug::toApi($slug) ?? throw new MysiteException(ErrorCode::ContentNotFound, 'slug_format');

        $generation = $this->cache->generation();
        $id = $this->cache->slugRef($type, $apiSlug);
        $fresh = null;
        if ($id === null) {
            $fresh = $this->fetchDetail($type, $apiSlug);
            $id = $fresh['id'];
            $this->cache->putSlugRef($type, $apiSlug, $id, $generation);
        }

        $lease = $this->confirmVisible($type, $id);

        $body = $this->cache->body($type, $id);
        if ($body === null || $body['version'] < $lease['version'] || $body['api_slug'] !== $apiSlug) {
            $fresh ??= $this->fetchDetail($type, $apiSlug);
            if ($fresh['id'] !== $id) {
                // slug 已改指向另一筆內容：以新 id 重新確認可見性
                $id = $fresh['id'];
                $this->confirmVisible($type, $id);
                $this->cache->putSlugRef($type, $apiSlug, $id, $generation);
            }
            $this->cache->putBody($type, $id, $fresh['content_version'], $apiSlug, $fresh);
            $body = ['item' => $fresh];
        }

        $item = $this->mapper->item($body['item'], true);
        if ($item->type !== $type || $item->id !== $id) {
            throw new MysiteException(ErrorCode::ContentNotFound, 'detail_mismatch');
        }

        return $item;
    }

    /**
     * 取得（或重新確認）可見性證明。
     *
     * @return array{version: int, expires_at: int}
     */
    public function confirmVisible(ContentType $type, int $id): array
    {
        $lease = $this->cache->lease($type, $id);
        if ($lease !== null) {
            return $lease;
        }

        $visibility = $this->mapper->visibility($this->client->getPublic('/content/'.$type->value.'/'.$id.'/visibility'));
        if ($visibility->type !== $type || $visibility->id !== $id) {
            throw new MysiteException(ErrorCode::ContentModelUnsupported, 'visibility_mismatch');
        }

        $takedown = $this->cache->takedownVersion($type, $id);
        if (! $visibility->visible || ($takedown !== null && $visibility->contentVersion <= $takedown)) {
            $this->cache->forgetContent($type, $id);
            $this->cache->bumpGeneration();

            throw new MysiteException(ErrorCode::ContentNotFound, 'not_visible');
        }
        if ($takedown !== null) {
            $this->cache->clearTakedown($type, $id);
        }

        return $this->cache->putLease($type, $id, $visibility->contentVersion);
    }

    /**
     * slug 精確查詢；若回應未附正文則改讀 /{endpoint}/{id}。
     *
     * @return array<string, mixed> 含 body_html 的原始內容物件
     */
    private function fetchDetail(ContentType $type, string $apiSlug): array
    {
        $data = $this->mapper->data($this->client->getPublic('/'.$type->endpoint(), ['slug' => $apiSlug, 'per_page' => 1]));
        if (! is_array($data) || ! array_is_list($data)) {
            throw new MysiteException(ErrorCode::ContentModelUnsupported, 'slug_lookup_shape');
        }
        if ($data === [] || ! is_array($data[0])) {
            throw new MysiteException(ErrorCode::ContentNotFound, 'slug_empty');
        }
        $raw = $data[0];
        $summary = $this->mapper->item(array_diff_key($raw, ['body_html' => true]), false);
        if ($summary->type !== $type || strtolower($summary->apiSlug) !== strtolower($apiSlug)) {
            throw new MysiteException(ErrorCode::ContentNotFound, 'slug_mismatch');
        }
        if (array_key_exists('body_html', $raw)) {
            return $raw;
        }

        $detail = $this->mapper->data($this->client->getPublic('/'.$type->endpoint().'/'.$summary->id));
        if (! is_array($detail) || array_is_list($detail) || ! array_key_exists('body_html', $detail)) {
            throw new MysiteException(ErrorCode::ContentModelUnsupported, 'detail_shape');
        }
        $check = $this->mapper->item(array_diff_key($detail, ['body_html' => true]), false);
        if ($check->id !== $summary->id || $check->type !== $type) {
            throw new MysiteException(ErrorCode::ContentModelUnsupported, 'detail_mismatch');
        }

        return $detail;
    }

    private function assertPaging(int $page, int $perPage): void
    {
        if ($page < 1 || $page > self::MAX_PAGE || $perPage < 1 || $perPage > self::MAX_PER_PAGE) {
            throw new MysiteException(ErrorCode::ContentNotFound, 'paging_out_of_range');
        }
    }
}
