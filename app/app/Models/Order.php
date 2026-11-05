<?php

declare(strict_types=1);

namespace App\Models;

use App\Domain\Commerce\Casts\MoneyCast;
use App\Domain\Commerce\Money;
use Carbon\CarbonImmutable;
use Database\Factories\OrderFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * 模擬訂單（不收款、不出貨）。只由 CheckoutService 建立、OrderCancellationService 取消、RetentionService 匿名化。
 *
 * @property int $id
 * @property string|null $token_hash
 * @property OrderStatus $status
 * @property string $currency
 * @property Money $subtotal
 * @property Money $total
 * @property int $item_count
 * @property string|null $idempotency_key
 * @property string|null $session_binding_hash
 * @property string|null $buyer_code
 * @property CarbonImmutable $placed_at
 * @property CarbonImmutable|null $cancelled_at
 * @property CarbonImmutable|null $anonymized_at
 */
class Order extends Model
{
    /** @use HasFactory<OrderFactory> */
    use HasFactory;

    protected $guarded = ['*'];

    protected $hidden = ['token_hash', 'session_binding_hash', 'idempotency_key'];

    protected function casts(): array
    {
        return [
            'status' => OrderStatus::class,
            'subtotal' => MoneyCast::class,
            'total' => MoneyCast::class,
            'item_count' => 'integer',
            'placed_at' => 'datetime',
            'cancelled_at' => 'datetime',
            'anonymized_at' => 'datetime',
        ];
    }

    /** @return HasMany<OrderItem, $this> */
    public function items(): HasMany
    {
        return $this->hasMany(OrderItem::class)->orderBy('id');
    }

    /** @return HasMany<InventoryMutation, $this> */
    public function mutations(): HasMany
    {
        return $this->hasMany(InventoryMutation::class)->orderBy('id');
    }

    public function isAnonymized(): bool
    {
        return $this->anonymized_at !== null;
    }

    /** 對外顯示用的訂單編號（不可用來查詢訂單）。 */
    public function displayNumber(): string
    {
        return 'SIM-'.str_pad((string) $this->id, 6, '0', STR_PAD_LEFT);
    }
}
