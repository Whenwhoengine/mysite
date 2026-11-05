<?php

declare(strict_types=1);

namespace App\Models;

use App\Domain\Commerce\Casts\MoneyCast;
use App\Domain\Commerce\Money;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * 下單快照（COM-07）：建立後不得修改或刪除；商品改價不回寫。
 *
 * @property int $id
 * @property int $order_id
 * @property int $product_variant_id
 * @property int $product_id
 * @property string $product_title
 * @property string $variant_name
 * @property string $sku
 * @property Money $unit_price
 * @property int $quantity
 * @property Money $line_total
 */
class OrderItem extends Model
{
    protected $guarded = ['*'];

    protected function casts(): array
    {
        return [
            'unit_price' => MoneyCast::class,
            'line_total' => MoneyCast::class,
            'quantity' => 'integer',
        ];
    }

    protected static function booted(): void
    {
        static::updating(static function (): never {
            throw new LogicException('訂單明細為下單快照，不可修改');
        });
        static::deleting(static function (): never {
            throw new LogicException('訂單明細不可刪除');
        });
    }

    /** @return BelongsTo<Order, $this> */
    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    /** @return BelongsTo<ProductVariant, $this> */
    public function variant(): BelongsTo
    {
        return $this->belongsTo(ProductVariant::class, 'product_variant_id');
    }
}
