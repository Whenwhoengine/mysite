<?php

declare(strict_types=1);

namespace Tests\Feature\Commerce;

use App\Models\InventoryMutation;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\User;
use Database\Seeders\DevelopmentSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;

/** 合成示例資料：只在 development／testing、可重跑、不建管理員。 */
final class DevelopmentSeederTest extends CommerceTestCase
{
    use RefreshDatabase;

    #[Test]
    #[Group('COM-04')]
    public function seeds_sample_products_including_a_last_item(): void
    {
        $this->seed(DevelopmentSeeder::class);
        $this->seed(DevelopmentSeeder::class); // 可重跑

        $this->assertSame(count(DevelopmentSeeder::PRODUCTS), Product::query()->count());
        $this->assertSame(0, Product::query()->where('is_sample', false)->count());
        $this->assertTrue(Product::query()->where('title', 'like', '示例%')->count() === Product::query()->count());
        $this->assertSame(1, ProductVariant::query()->where('sku', 'SAMPLE-LAST-1')->value('stock'));
        $this->assertSame(0, User::query()->count(), '不建立任何帳號');
        // 每個有庫存的規格只有一筆初始異動（重跑不重複）
        $this->assertSame(
            ProductVariant::query()->where('stock', '>', 0)->count(),
            InventoryMutation::query()->where('reason', InventoryMutation::REASON_INITIAL)->count(),
        );
    }

    #[Test]
    #[Group('COM-12')]
    public function refuses_to_run_in_production(): void
    {
        $this->app['env'] = 'production';
        try {
            $this->expectException(RuntimeException::class);
            (new DevelopmentSeeder)->run();
        } finally {
            $this->app['env'] = 'testing';
        }
    }
}
