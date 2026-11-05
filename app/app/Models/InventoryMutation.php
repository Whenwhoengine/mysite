<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * 庫存異動（只追加）。reason：initial_stock／manual_adjustment／order_placed／order_cancelled。
 *
 * @property int $id
 * @property int $product_variant_id
 * @property int $delta
 * @property int $stock_after
 * @property string $reason
 * @property int|null $order_id
 * @property int|null $actor_user_id
 * @property string|null $note
 */
class InventoryMutation extends Model
{
    public const UPDATED_AT = null;

    public const REASON_INITIAL = 'initial_stock';

    public const REASON_MANUAL = 'manual_adjustment';

    public const REASON_ORDER_PLACED = 'order_placed';

    public const REASON_ORDER_CANCELLED = 'order_cancelled';

    protected $guarded = ['*'];

    protected function casts(): array
    {
        return [
            'delta' => 'integer',
            'stock_after' => 'integer',
            'created_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::updating(static function (): never {
            throw new LogicException('庫存異動只能追加，不可修改');
        });
        static::deleting(static function (): never {
            throw new LogicException('庫存異動只能追加，不可刪除');
        });
    }

    /** @return BelongsTo<ProductVariant, $this> */
    public function variant(): BelongsTo
    {
        return $this->belongsTo(ProductVariant::class, 'product_variant_id');
    }

    /** @return BelongsTo<Order, $this> */
    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function reasonLabel(): string
    {
        return match ($this->reason) {
            self::REASON_INITIAL => '初始庫存',
            self::REASON_MANUAL => '人工調整',
            self::REASON_ORDER_PLACED => '模擬下單扣減',
            self::REASON_ORDER_CANCELLED => '取消回補',
            default => $this->reason,
        };
    }
}
