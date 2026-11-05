<?php

declare(strict_types=1);

namespace App\Domain\Commerce\Catalog;

use App\Domain\Audit\AuditLogger;
use App\Domain\Commerce\Inventory\InventoryService;
use App\Domain\Commerce\Money;
use App\Models\InventoryMutation;
use App\Models\Product;
use App\Models\ProductStatus;
use App\Models\ProductVariant;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * 後臺商品與規格維護。所有寫入都留稽核；初始庫存經 InventoryService 寫異動。
 * 商品改價不影響既有訂單明細（明細為快照）。
 */
final class CatalogService
{
    public function __construct(
        private readonly AuditLogger $audit,
        private readonly InventoryService $inventory,
    ) {}

    /**
     * 建立商品與預設規格（無規格商品＝單一預設 variant）。
     *
     * @param  array{slug: string, title: string, description: ?string, price: string, status: string, cover_url: ?string, sku: string, initial_stock: int}  $data
     */
    public function createProduct(array $data, User $actor): Product
    {
        $product = DB::transaction(function () use ($data): Product {
            $product = Product::query()->create([
                'slug' => $data['slug'],
                'title' => $data['title'],
                'description' => $data['description'],
                'price' => Money::fromDecimal($data['price']),
                'status' => ProductStatus::from($data['status']),
                'cover_url' => $data['cover_url'],
                'is_sample' => false,
            ]);
            ProductVariant::query()->create([
                'product_id' => $product->id,
                'sku' => $data['sku'],
                'name' => '標準款',
                'price' => Money::fromDecimal($data['price']),
                'is_active' => true,
                'is_default' => true,
            ]);

            return $product;
        });
        $this->audit->record('product.created', $actor, $product, null, [
            'slug' => $product->slug, 'status' => $product->status, 'price' => $product->price->toDecimal(), 'sku' => $data['sku'],
        ]);
        if ($data['initial_stock'] > 0) {
            /** @var ProductVariant $variant */
            $variant = $product->defaultVariant()->firstOrFail();
            $this->inventory->adjust($variant, $data['initial_stock'], $actor, '建立商品時的初始庫存', InventoryMutation::REASON_INITIAL);
        }

        return $product;
    }

    /**
     * @param  array{slug: string, title: string, description: ?string, price: string, status: string, cover_url: ?string}  $data
     */
    public function updateProduct(Product $product, array $data, User $actor): Product
    {
        $product->fill([
            'slug' => $data['slug'],
            'title' => $data['title'],
            'description' => $data['description'],
            'price' => Money::fromDecimal($data['price']),
            'status' => ProductStatus::from($data['status']),
            'cover_url' => $data['cover_url'],
        ]);
        $changes = $this->diff($product);
        $product->save();
        if ($changes !== []) {
            $this->audit->record('product.updated', $actor, $product, null, ['changes' => $changes]);
        }

        return $product;
    }

    /**
     * @param  array{sku: string, name: string, price: string, initial_stock: int}  $data
     */
    public function addVariant(Product $product, array $data, User $actor): ProductVariant
    {
        $variant = ProductVariant::query()->create([
            'product_id' => $product->id,
            'sku' => $data['sku'],
            'name' => $data['name'],
            'price' => Money::fromDecimal($data['price']),
            'is_active' => true,
            'is_default' => false,
        ]);
        $this->audit->record('variant.created', $actor, $variant, null, [
            'product_id' => $product->id, 'sku' => $variant->sku, 'price' => $variant->price->toDecimal(),
        ]);
        if ($data['initial_stock'] > 0) {
            $this->inventory->adjust($variant, $data['initial_stock'], $actor, '新增規格時的初始庫存', InventoryMutation::REASON_INITIAL);
        }

        return $variant;
    }

    /**
     * @param  array{name: string, price: string, is_active: bool}  $data
     */
    public function updateVariant(ProductVariant $variant, array $data, User $actor): ProductVariant
    {
        $variant->fill([
            'name' => $data['name'],
            'price' => Money::fromDecimal($data['price']),
            'is_active' => $data['is_active'],
        ]);
        $changes = $this->diff($variant);
        $variant->save();
        if ($changes !== []) {
            $this->audit->record('variant.updated', $actor, $variant, null, ['changes' => $changes]);
        }

        return $variant;
    }

    /** @return array<string, array{from: mixed, to: mixed}> */
    private function diff(Product|ProductVariant $model): array
    {
        $changes = [];
        foreach (array_keys($model->getDirty()) as $key) {
            if ($key === 'updated_at') {
                continue;
            }
            $from = $model->getRawOriginal($key);
            $to = $model->getAttributes()[$key] ?? null;
            if ($key === 'description') {
                // 描述可能很長：只記錄長度變化
                $from = $from === null ? null : '（'.mb_strlen((string) $from).' 字）';
                $to = $to === null ? null : '（'.mb_strlen((string) $to).' 字）';
            }
            $changes[$key] = ['from' => $from, 'to' => $to];
        }

        return $changes;
    }
}
