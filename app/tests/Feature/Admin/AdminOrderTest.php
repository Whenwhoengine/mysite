<?php

declare(strict_types=1);

namespace Tests\Feature\Admin;

use App\Models\AuditLog;
use App\Models\InventoryMutation;
use App\Models\Order;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\RateLimiter;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\Commerce\CommerceTestCase;
use Tests\Feature\Commerce\Concerns\InteractsWithCommerce;

/** 後臺訂單：取消（只回補一次）、匯出（留稽核、無秘密）、CSRF 與限流（COM-08、COM-10）。 */
final class AdminOrderTest extends CommerceTestCase
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
    #[Group('COM-10')]
    public function admin_cancels_once_and_resend_does_not_restock_again(): void
    {
        $variant = $this->variant('10.00', 4);
        $token = $this->placeOrder($variant, 3);
        $order = Order::query()->sole();
        $admin = $this->admin();
        $this->actingAs($admin);

        $this->get('/admin/orders')->assertOk()->assertSee($order->displayNumber());
        $this->get('/admin/orders?status=placed')->assertOk()->assertSee($order->displayNumber());
        $this->get('/admin/orders/'.$order->id)->assertOk()->assertSee('取消訂單並回補庫存');
        $this->post('/admin/orders/'.$order->id.'/cancel')->assertRedirect('/admin/orders/'.$order->id)->assertSessionHas('status', '訂單已取消，庫存已回補。');
        $this->post('/admin/orders/'.$order->id.'/cancel')->assertSessionHas('status', '此訂單先前已取消，未重複回補。');
        $this->assertSame(4, $variant->refresh()->stock);
        $this->assertSame(1, InventoryMutation::query()->where(['order_id' => $order->id, 'reason' => 'order_cancelled'])->count());
        $this->assertSame($admin->id, InventoryMutation::query()->where(['order_id' => $order->id, 'reason' => 'order_cancelled'])->value('actor_user_id'));
        $this->assertTrue(AuditLog::query()->where(['action' => 'order.cancelled', 'actor_user_id' => $admin->id])->exists());
        $this->get('/admin/orders/'.$order->id)->assertDontSee('取消訂單並回補庫存');
        $this->assertNotEmpty($token);
    }

    #[Test]
    #[Group('COM-10')]
    #[Group('DAT-02')]
    public function export_is_admin_only_audited_and_contains_no_tokens_or_bindings(): void
    {
        $variant = $this->variant('10.00', 4);
        $token = $this->placeOrder($variant, 1);
        $order = Order::query()->sole();
        $admin = $this->admin();

        $response = $this->actingAs($admin)->get('/admin/orders/export');
        $response->assertOk();
        $this->assertStringContainsString('text/csv', (string) $response->headers->get('Content-Type'));
        $csv = $response->streamedContent();
        $this->assertStringContainsString($order->displayNumber(), $csv);
        $this->assertStringContainsString('10.00', $csv);
        foreach ([$token, $order->token_hash, $order->session_binding_hash, $order->idempotency_key] as $secret) {
            $this->assertStringNotContainsString((string) $secret, $csv);
        }
        $audit = AuditLog::query()->where('action', 'orders.exported')->sole();
        $this->assertSame($admin->id, $audit->actor_user_id);
        $this->assertSame(1, $audit->metadata['rows']);
    }

    #[Test]
    #[Group('COM-10')]
    public function writes_without_csrf_token_are_rejected(): void
    {
        $variant = $this->variant('10.00', 4);
        $this->actingAs($this->admin());
        $this->enforceCsrf();

        $this->post('/admin/variants/'.$variant->id.'/stock', ['delta' => 5])->assertStatus(419);
        $this->withHeader('Sec-Fetch-Site', 'cross-site')->post('/admin/variants/'.$variant->id.'/stock', ['delta' => 5])->assertStatus(419);
        $this->post('/cart/items', ['variant_id' => $variant->id, 'quantity' => 1])->assertStatus(419);
        $this->post('/checkout', ['idempotency_key' => str_repeat('a', 32)])->assertStatus(419);
        $this->post('/admin/login', ['email' => 'x@example.com', 'password' => 'x'])->assertStatus(419);
        $this->assertSame(4, $variant->refresh()->stock);

        // 帶正確 token 才會通過
        $this->withSession(['_token' => 'known-token'])
            ->post('/admin/variants/'.$variant->id.'/stock', ['delta' => 5, '_token' => 'known-token'])->assertRedirect();
        $this->assertSame(9, $variant->refresh()->stock);
    }

    #[Test]
    #[Group('COM-10')]
    public function admin_writes_are_rate_limited(): void
    {
        RateLimiter::for('admin-write', static fn () => Limit::perMinute(2)->by('test'));
        $variant = $this->variant('10.00', 4);
        $this->actingAs($this->admin());
        $this->post('/admin/variants/'.$variant->id.'/stock', ['delta' => 1])->assertRedirect();
        $this->post('/admin/variants/'.$variant->id.'/stock', ['delta' => 1])->assertRedirect();
        $this->postJson('/admin/variants/'.$variant->id.'/stock', ['delta' => 1])->assertStatus(429)->assertJson(['error' => 'RATE_LIMITED']);
        $this->assertSame(6, $variant->refresh()->stock);
    }

    #[Test]
    #[Group('COM-10')]
    public function checkout_submissions_are_rate_limited(): void
    {
        RateLimiter::for('shop-checkout', static fn () => Limit::perMinute(1)->by('test'));
        $this->post('/checkout', ['idempotency_key' => str_repeat('a', 32)])->assertRedirect();
        $this->postJson('/checkout', ['idempotency_key' => str_repeat('a', 32)])->assertStatus(429)->assertJson(['error' => 'RATE_LIMITED']);
    }

    #[Test]
    #[Group('COM-08')]
    public function anonymized_orders_cannot_be_cancelled_from_admin(): void
    {
        $variant = $this->variant('10.00', 4);
        $this->placeOrder($variant, 1);
        $order = Order::query()->sole();
        $order->forceFill(['anonymized_at' => now()])->save();
        $this->actingAs($this->admin());
        $this->get('/admin/orders/'.$order->id)->assertOk()->assertDontSee('取消訂單並回補庫存');
        $this->post('/admin/orders/'.$order->id.'/cancel')->assertSessionHasErrors('order');
        $this->assertSame(3, $variant->refresh()->stock);
    }
}
