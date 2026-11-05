<?php

declare(strict_types=1);

namespace App\Domain\Content\Cache;

use App\Domain\Content\ContentType;
use Closure;
use Illuminate\Contracts\Cache\Repository as CacheRepository;

/**
 * 內容快取與可見性租約（ADR-007、v5 §8.6、API-10、API-15）。
 *
 * - 正文（body）與「仍可公開」證明（lease）分開存放；lease 期限 = min(visibility_ttl, 60)。
 * - 列表、首頁、搜尋、分類、選單、RSS、sitemap 一律用 collection 快取：TTL ≤ lease 期限，
 *   並在值內記錄內容世代號（generation）；收到事件時 generation +1，舊值立即失效。
 * - 每筆值都帶明確到期時間並以目前時鐘比對（可用 $this->travel() 測試），不依賴快取驅動的過期清理。
 * - 沒有任何 stale fallback：過期就是不存在。
 */
final class ContentCache
{
    public const PREFIX = 'mysite:content:';

    public const HARD_LIMIT_SECONDS = 60;

    /** @param array<string, mixed> $config config('mysite.content') */
    public function __construct(
        private readonly CacheRepository $cache,
        private readonly array $config,
    ) {}

    public function leaseSeconds(): int
    {
        $hard = min(self::HARD_LIMIT_SECONDS, (int) ($this->config['visibility_hard_limit'] ?? self::HARD_LIMIT_SECONDS));

        return max(1, min((int) ($this->config['visibility_ttl'] ?? 30), $hard));
    }

    public function collectionSeconds(): int
    {
        return $this->leaseSeconds();
    }

    public function bodySeconds(): int
    {
        return max(1, (int) ($this->config['body_ttl'] ?? 300));
    }

    public function generation(): int
    {
        return (int) $this->cache->get(self::PREFIX.'generation', 0);
    }

    public function bumpGeneration(): int
    {
        $key = self::PREFIX.'generation';
        if ($this->cache->add($key, 1)) {
            return 1;
        }
        $value = $this->cache->increment($key);

        return is_int($value) ? $value : $this->generation();
    }

    /**
     * 列表類快取：同一世代且未過期才命中；抓取失敗的例外直接往外拋（不快取失敗、不回舊值）。
     *
     * @param  array<string, mixed>  $params
     * @param  Closure(): array<string, mixed>  $fetch
     * @return array<string, mixed>
     */
    public function collection(string $name, array $params, Closure $fetch): array
    {
        $key = self::PREFIX.'c:'.$name.':'.hash('xxh128', (string) json_encode($params));
        $generation = $this->generation();
        $hit = $this->cache->get($key);
        if (is_array($hit) && ($hit['g'] ?? null) === $generation && ($hit['exp'] ?? 0) > $this->now() && is_array($hit['d'] ?? null)) {
            return $hit['d'];
        }
        $data = $fetch();
        $seconds = $this->collectionSeconds();
        $this->cache->put($key, ['g' => $generation, 'exp' => $this->now() + $seconds, 'd' => $data], $seconds);

        return $data;
    }

    public function slugRef(ContentType $type, string $apiSlug): ?int
    {
        $hit = $this->cache->get($this->slugKey($type, $apiSlug));
        if (is_array($hit) && ($hit['g'] ?? null) === $this->generation() && ($hit['exp'] ?? 0) > $this->now() && is_int($hit['id'] ?? null)) {
            return $hit['id'];
        }

        return null;
    }

    public function putSlugRef(ContentType $type, string $apiSlug, int $id, int $generation): void
    {
        $seconds = $this->leaseSeconds();
        $this->cache->put($this->slugKey($type, $apiSlug), ['g' => $generation, 'id' => $id, 'exp' => $this->now() + $seconds], $seconds);
    }

    public function forgetSlugRef(ContentType $type, string $apiSlug): void
    {
        $this->cache->forget($this->slugKey($type, $apiSlug));
    }

    /** @return array{version: int, expires_at: int}|null 未過期的「可公開」證明 */
    public function lease(ContentType $type, int $id): ?array
    {
        $hit = $this->cache->get($this->key('lease', $type, $id));
        if (is_array($hit) && is_int($hit['version'] ?? null) && is_int($hit['expires_at'] ?? null) && $hit['expires_at'] > $this->now()) {
            return ['version' => $hit['version'], 'expires_at' => $hit['expires_at']];
        }

        return null;
    }

    /** @return array{version: int, expires_at: int} */
    public function putLease(ContentType $type, int $id, int $version): array
    {
        $seconds = $this->leaseSeconds();
        $lease = ['version' => $version, 'expires_at' => $this->now() + $seconds];
        $this->cache->put($this->key('lease', $type, $id), $lease, $seconds);

        return $lease;
    }

    /** @return array{version: int, api_slug: string, item: array<string, mixed>}|null */
    public function body(ContentType $type, int $id): ?array
    {
        $hit = $this->cache->get($this->key('body', $type, $id));
        if (is_array($hit) && is_int($hit['version'] ?? null) && is_array($hit['item'] ?? null) && ($hit['exp'] ?? 0) > $this->now()) {
            return ['version' => $hit['version'], 'api_slug' => (string) ($hit['api_slug'] ?? ''), 'item' => $hit['item']];
        }

        return null;
    }

    /** @param array<string, mixed> $item */
    public function putBody(ContentType $type, int $id, int $version, string $apiSlug, array $item): void
    {
        $seconds = $this->bodySeconds();
        $this->cache->put($this->key('body', $type, $id), ['version' => $version, 'api_slug' => $apiSlug, 'item' => $item, 'exp' => $this->now() + $seconds], $seconds);
    }

    /** 撤下標記：記錄已知的撤下版本號；WP 回報的可見版本必須大於它才可供應。 */
    public function takedownVersion(ContentType $type, int $id): ?int
    {
        $value = $this->cache->get($this->key('gone', $type, $id));

        return is_int($value) ? $value : null;
    }

    public function markTakenDown(ContentType $type, int $id, int $version): void
    {
        $current = $this->takedownVersion($type, $id);
        if ($current === null || $version > $current) {
            $this->cache->forever($this->key('gone', $type, $id), $version);
        }
    }

    public function clearTakedown(ContentType $type, int $id): void
    {
        $this->cache->forget($this->key('gone', $type, $id));
    }

    /** 清除單一內容的正文與租約（下次請求必須重新向 WP 確認）。 */
    public function forgetContent(ContentType $type, int $id): void
    {
        $this->cache->forget($this->key('lease', $type, $id));
        $this->cache->forget($this->key('body', $type, $id));
    }

    private function key(string $kind, ContentType $type, int $id): string
    {
        return self::PREFIX.$kind.':'.$type->value.':'.$id;
    }

    private function slugKey(ContentType $type, string $apiSlug): string
    {
        return self::PREFIX.'slug:'.$type->value.':'.hash('xxh128', $apiSlug);
    }

    private function now(): int
    {
        return now()->getTimestamp();
    }
}
