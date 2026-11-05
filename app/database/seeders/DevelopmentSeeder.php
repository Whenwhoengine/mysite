<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Domain\Commerce\Money;
use App\Models\InventoryMutation;
use App\Models\Product;
use App\Models\ProductStatus;
use App\Models\ProductVariant;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * 合成示例商品（只在 development／testing 執行；production／staging 拒絕）。
 * - 全部標示「示例」（is_sample=1），可重跑：以 slug／SKU 為鍵，已存在者不重設庫存。
 * - 含一件只剩 1 件庫存的商品，供「搶最後一件」測試。
 * - 不建立任何管理員帳號（請用 php artisan mysite:admin:create）。
 *
 * 用法：php artisan db:seed --class=Database\\Seeders\\DevelopmentSeeder
 */
final class DevelopmentSeeder extends Seeder
{
    public const PRODUCTS = [
        [
            'slug' => 'sample-mug', 'title' => '示例：節目馬克杯', 'price' => '350.00',
            'description' => "示例商品，僅供模擬商店展示。\n\n陶瓷杯身，容量約 350ml（示意）。",
            'variants' => [['sku' => 'SAMPLE-MUG-STD', 'name' => '標準款', 'price' => '350.00', 'stock' => 20, 'default' => true]],
        ],
        [
            'slug' => 'sample-tshirt', 'title' => '示例：頻道 T 恤', 'price' => '590.00',
            'description' => '示例商品，有三種尺寸規格，僅供模擬。',
            'variants' => [
                ['sku' => 'SAMPLE-TEE-S', 'name' => 'S', 'price' => '590.00', 'stock' => 5, 'default' => true],
                ['sku' => 'SAMPLE-TEE-M', 'name' => 'M', 'price' => '590.00', 'stock' => 8, 'default' => false],
                ['sku' => 'SAMPLE-TEE-L', 'name' => 'L', 'price' => '620.00', 'stock' => 0, 'default' => false],
            ],
        ],
        [
            'slug' => 'sample-last-one', 'title' => '示例：限量一件測試品', 'price' => '1280.50',
            'description' => '只剩 1 件庫存，用來測試同時搶購最後一件時只會成立一張模擬訂單。',
            'variants' => [['sku' => 'SAMPLE-LAST-1', 'name' => '標準款', 'price' => '1280.50', 'stock' => 1, 'default' => true]],
        ],
        [
            'slug' => 'sample-sticker', 'title' => '示例：貼紙組', 'price' => '80.00',
            'description' => '示例商品，價格不含小數。',
            'variants' => [['sku' => 'SAMPLE-STICKER', 'name' => '一組 5 張', 'price' => '80.00', 'stock' => 100, 'default' => true]],
        ],
    ];

    public function run(): void
    {
        $env = (string) app()->environment();
        if (! in_array($env, ['development', 'testing', 'local'], true)) {
            throw new RuntimeException("DevelopmentSeeder 只能在 development／testing 執行（目前：{$env}）");
        }

        foreach (self::PRODUCTS as $spec) {
            DB::transaction(function () use ($spec): void {
                $product = Product::query()->firstOrCreate(['slug' => $spec['slug']], [
                    'title' => $spec['title'],
                    'description' => $spec['description'],
                    'price' => Money::fromDecimal($spec['price']),
                    'status' => ProductStatus::Active,
                    'is_sample' => true,
                ]);
                foreach ($spec['variants'] as $v) {
                    $variant = ProductVariant::query()->where('sku', $v['sku'])->first();
                    if ($variant !== null) {
                        continue; // 可重跑：不重設既有庫存
                    }
                    $variant = ProductVariant::query()->create([
                        'product_id' => $product->id,
                        'sku' => $v['sku'],
                        'name' => $v['name'],
                        'price' => Money::fromDecimal($v['price']),
                        'is_active' => true,
                        'is_default' => $v['default'],
                    ]);
                    if ($v['stock'] > 0) {
                        $variant->forceFill(['stock' => $v['stock']])->save();
                        $m = new InventoryMutation;
                        $m->forceFill([
                            'product_variant_id' => $variant->id,
                            'delta' => $v['stock'],
                            'stock_after' => $v['stock'],
                            'reason' => InventoryMutation::REASON_INITIAL,
                            'note' => '示例資料',
                            'created_at' => now(),
                        ])->save();
                    }
                }
            });
        }
    }
}
