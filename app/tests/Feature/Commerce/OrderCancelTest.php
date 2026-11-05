<?php

declare(strict_types=1);

namespace Tests\Feature\Commerce;

use App\Domain\Commerce\Exceptions\InvalidOrderTransition;
use App\Domain\Commerce\Orders\OrderCancellationService;
use App\Models\AuditLog;
use App\Models\InventoryMutation;
use App\Models\Order;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\Commerce\Concerns\InteractsWithCommerce;

/** COM-08：取消只允許 placed → cancelled；重送不重複補庫存；不刪明細。 */
final class OrderCancelTest extends CommerceTestCase
{
    use InteractsWithCommerce;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpCommerce();
    }

    #[Test]
    #[Group('COM-08')]
    public function cancel_restocks_once_and_resend_is_a_noop(): void
    {
        $variant = $this->variant('10.00', 5);
        $token = $this->placeOrder($variant, 3);
        $this->assertSame(2, $variant->refresh()->stock);

        $this->post('/orders/'.$token.'/cancel')->assertRedirect('/orders/'.$token)->assertSessionHas('status', '模擬訂單已取消，庫存已回補。');
        $this->assertSame(5, $variant->refresh()->stock);
        $this->post('/orders/'.$token.'/cancel')->assertRedirect('/orders/'.$token)->assertSessionHas('status', '這筆模擬訂單先前已取消；重複送出不會再次回補庫存。');
        $this->post('/orders/'.$token.'/cancel');
        $this->assertSame(5, $variant->refresh()->stock);

        $order = Order::query()->sole();
        $this->assertSame('cancelled', $order->status->value);
        $this->assertNotNull($order->cancelled_at);
        $this->assertSame(1, $order->items()->count(), '取消不刪明細');
        $this->assertSame([-3, 3], InventoryMutation::query()->where('order_id', $order->id)->orderBy('id')->pluck('delta')->all());
        $this->assertSame(1, AuditLog::query()->where('action', 'order.cancelled')->count());
        $this->get('/orders/'.$token)->assertOk()->assertSee('已取消')->assertDontSee('取消模擬訂單</button>', false);
    }

    #[Test]
    #[Group('COM-08')]
    public function database_constraint_blocks_a_second_restock_row(): void
    {
        $variant = $this->variant('10.00', 5);
        $this->placeOrder($variant, 1);
        $order = Order::query()->sole();
        app(OrderCancellationService::class)->cancel($order, null, 'test');
        $this->expectException(QueryException::class);
        $m = new InventoryMutation;
        $m->forceFill([
            'product_variant_id' => $variant->id, 'delta' => 1, 'stock_after' => 6,
            'reason' => InventoryMutation::REASON_ORDER_CANCELLED, 'order_id' => $order->id, 'created_at' => now(),
        ])->save();
    }

    #[Test]
    #[Group('COM-08')]
    public function anonymized_orders_cannot_transition(): void
    {
        $variant = $this->variant('10.00', 5);
        $this->placeOrder($variant, 1);
        $order = Order::query()->sole();
        $order->forceFill(['anonymized_at' => now()])->save();
        $this->expectException(InvalidOrderTransition::class);
        app(OrderCancellationService::class)->cancel($order, null, 'test');
    }

    #[Test]
    #[Group('COM-08')]
    public function inventory_mutations_are_append_only(): void
    {
        $variant = $this->variant('10.00', 5);
        $this->placeOrder($variant, 1);
        $m = InventoryMutation::query()->firstOrFail();
        try {
            $m->forceFill(['delta' => 100])->save();
            $this->fail('異動不可修改');
        } catch (\LogicException) {
            $this->addToAssertionCount(1);
        }
        $this->expectException(\LogicException::class);
        $m->delete();
    }
}
