<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Domain\Commerce\Money;
use App\Models\Order;
use App\Models\OrderStatus;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * 測試用訂單殼（不含明細與庫存異動；完整流程請走 CheckoutService）。
 *
 * @extends Factory<Order>
 */
final class OrderFactory extends Factory
{
    protected $model = Order::class;

    public function definition(): array
    {
        return [
            'token_hash' => hash('sha256', bin2hex(random_bytes(16))),
            'status' => OrderStatus::Placed,
            'currency' => 'TWD',
            'subtotal' => Money::fromDecimal('100.00'),
            'total' => Money::fromDecimal('100.00'),
            'item_count' => 1,
            'idempotency_key' => bin2hex(random_bytes(16)),
            'session_binding_hash' => hash('sha256', bin2hex(random_bytes(16))),
            'buyer_code' => 'TEST-001',
            'placed_at' => now(),
        ];
    }

    /** Order 的屬性受 guarded 保護，factory 以 forceFill 建立。 */
    public function newModel(array $attributes = [])
    {
        return (new Order)->forceFill($attributes);
    }
}
