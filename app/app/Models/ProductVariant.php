<?php

declare(strict_types=1);

namespace App\Models;

use App\Domain\Commerce\Casts\MoneyCast;
use Database\Factories\ProductVariantFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * 商品規格。stock 不可大量指派：只能經 InventoryService／CheckoutService 在交易內變動並留異動紀錄。
 *
 * @property int $id
 * @property int $product_id
 * @property string $sku
 * @property string $name
 * @property \App\Domain\Commerce\Money $price
 * @property int $stock
 * @property bool $is_active
 * @property bool $is_default
 * @property-read Product $product
 */
class ProductVariant extends Model
{
    /** @use HasFactory<ProductVariantFactory> */
    use HasFactory;

    protected $fillable = ['product_id', 'sku', 'name', 'price', 'is_active', 'is_default'];

    protected function casts(): array
    {
        return [
            'price' => MoneyCast::class,
            'stock' => 'integer',
            'is_active' => 'boolean',
            'is_default' => 'boolean',
        ];
    }

    /** @return BelongsTo<Product, $this> */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    /** @return HasMany<InventoryMutation, $this> */
    public function mutations(): HasMany
    {
        return $this->hasMany(InventoryMutation::class)->orderBy('id');
    }

    public function isPurchasable(): bool
    {
        return $this->is_active && $this->product !== null && $this->product->isPurchasable();
    }
}
