<?php

declare(strict_types=1);

namespace Tests\Feature\Content;

use App\Domain\Content\Cache\ContentCache;
use App\Domain\Content\ContentType;
use App\Domain\Content\Maintenance\ContentPruner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\Content\Concerns\FakesWordPress;
use Tests\TestCase;

/**
 * 正式環境使用 database 快取驅動（CACHE_STORE=database）：在測試庫上驗證世代號、租約期限與過期列清理。
 */
final class DatabaseCacheTest extends TestCase
{
    use FakesWordPress;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->configureContent();
        config(['cache.default' => 'database', 'cache.stores.database.connection' => null, 'cache.stores.database.lock_connection' => null]);
        $this->freezeSecond();
    }

    #[Test]
    #[Group('API-15')]
    public function generation_and_collections_work_on_the_database_store(): void
    {
        $cache = $this->app->make(ContentCache::class);
        $this->assertSame(0, $cache->generation());
        $this->assertSame(1, $cache->bumpGeneration());
        $this->assertSame(2, $cache->bumpGeneration());

        $calls = 0;
        $fetch = function () use (&$calls): array {
            $calls++;

            return ['n' => $calls];
        };
        $this->assertSame(['n' => 1], $cache->collection('list:post', ['page' => 1], $fetch));
        $this->assertSame(['n' => 1], $cache->collection('list:post', ['page' => 1], $fetch));
        $cache->bumpGeneration();
        $this->assertSame(['n' => 2], $cache->collection('list:post', ['page' => 1], $fetch), '事件後立即失效');
        $this->travel(31)->seconds();
        $this->assertSame(['n' => 3], $cache->collection('list:post', ['page' => 1], $fetch), '期限不超過可見性期限');
    }

    #[Test]
    #[Group('API-10')]
    public function leases_expire_by_clock_on_the_database_store(): void
    {
        $cache = $this->app->make(ContentCache::class);
        $lease = $cache->putLease(ContentType::Video, 7, 3);
        $this->assertSame(now()->getTimestamp() + 30, $lease['expires_at']);
        $this->assertSame(3, $cache->lease(ContentType::Video, 7)['version'] ?? null);

        $cache->markTakenDown(ContentType::Video, 7, 5);
        $cache->markTakenDown(ContentType::Video, 7, 4);
        $this->assertSame(5, $cache->takedownVersion(ContentType::Video, 7));

        $this->travel(30)->seconds();
        $this->assertNull($cache->lease(ContentType::Video, 7));
    }

    #[Test]
    #[Group('API-15')]
    public function pruning_removes_only_expired_content_cache_rows(): void
    {
        $cache = $this->app->make(ContentCache::class);
        $cache->collection('search', ['q' => 'a'], fn (): array => ['x' => 1]);
        $cache->putBody(ContentType::Post, 1, 1, 'a', ['id' => 1]);
        Cache::store('database')->put('other:key', 'keep', 3600);
        $table = (string) config('cache.stores.database.table', 'cache');
        $before = DB::table($table)->count();

        $this->travel(31)->seconds();
        $result = $this->app->make(ContentPruner::class)->prune();

        $this->assertSame(1, $result['content_cache_rows'], '只刪除已過期的內容快取列（搜尋），正文仍在期限內');
        $this->assertSame($before - 1, DB::table($table)->count());
        $this->assertNotNull($cache->body(ContentType::Post, 1));
        $this->assertSame('keep', Cache::store('database')->get('other:key'), '非內容快取不受影響');
    }
}
