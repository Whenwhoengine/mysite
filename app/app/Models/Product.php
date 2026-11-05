<?php

declare(strict_types=1);

namespace App\Models;

use App\Domain\Commerce\Casts\MoneyCast;
use App\Domain\Commerce\Money;
use Database\Factories\ProductFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * 模擬商店商品。price 為列表顯示的基準價；結帳一律以 variant 的 DB 價格計算。
 *
 * @property int $id
 * @property string $slug
 * @property string $title
 * @property string|null $description
 * @property Money $price
 * @property ProductStatus $status
 * @property string|null $cover_url
 * @property bool $is_sample
 */
class Product extends Model
{
    /** @use HasFactory<ProductFactory> */
    use HasFactory;

    protected $fillable = ['slug', 'title', 'description', 'price', 'status', 'cover_url', 'is_sample'];

    protected function casts(): array
    {
        return [
            'price' => MoneyCast::class,
            'status' => ProductStatus::class,
            'is_sample' => 'boolean',
        ];
    }

    /** @return HasMany<ProductVariant, $this> */
    public function variants(): HasMany
    {
        return $this->hasMany(ProductVariant::class)->orderBy('id');
    }

    /** @return HasOne<ProductVariant, $this> */
    public function defaultVariant(): HasOne
    {
        return $this->hasOne(ProductVariant::class)->where('is_default', true);
    }

    /** @param Builder<Product> $query */
    public function scopeActive(Builder $query): void
    {
        $query->where('status', ProductStatus::Active->value);
    }

    public function isPurchasable(): bool
    {
        return $this->status === ProductStatus::Active;
    }
}
