<?php

declare(strict_types=1);

namespace Tests\Feature\Commerce;

use App\Models\Order;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\Commerce\Concerns\InteractsWithCommerce;
use Tests\Feature\Commerce\CommerceTestCase;

/** COM-01：功能旗標。disabled 時頁面與寫入全部 404 COMMERCE_DISABLED；real 不能收款。 */
final class CommerceFlagTest extends CommerceTestCase
{
    use InteractsWithCommerce;
    use RefreshDatabase;

    /** @return array<string, array{string, string}> */
    public static function shopRoutes(): array
    {
        return [
            'list' => ['GET', '/shop'],
            'detail' => ['GET', '/shop/sample-mug'],
            'cart' => ['GET', '/cart'],
            'cart add' => ['POST', '/cart/items'],
            'cart update' => ['PATCH', '/cart/items/1'],
            'cart remove' => ['DELETE', '/cart/items/1'],
            'checkout page' => ['GET', '/checkout'],
            'checkout submit' => ['POST', '/checkout'],
            'payment' => ['POST', '/checkout/payment'],
            'order' => ['GET', '/orders/'.str_repeat('a', 43)],
            'order cancel' => ['POST', '/orders/'.str_repeat('a', 43).'/cancel'],
        ];
    }

    #[Test]
    #[Group('COM-01')]
    #[DataProvider('shopRoutes')]
    public function disabled_mode_closes_every_shop_page_and_write(string $method, string $uri): void
    {
        $this->setUpCommerce('disabled');
        $variant = $this->variant();
        $response = $this->json($method, $uri, ['variant_id' => $variant->id, 'quantity' => 1, 'idempotency_key' => str_repeat('a', 32)]);
        $response->assertStatus(404)->assertJson(['error' => 'COMMERCE_DISABLED']);
        $this->assertSame(0, Order::query()->count());
    }

    #[Test]
    #[Group('COM-01')]
    public function disabled_is_the_default_mode(): void
    {
        $this->withoutVite();
        $this->assertSame('disabled', config('mysite.commerce.mode'));
        $this->get('/shop')->assertNotFound();
    }

    #[Test]
    #[Group('COM-01')]
    public function simulation_mode_opens_the_shop(): void
    {
        $this->setUpCommerce();
        $variant = $this->variant();
        $this->get('/shop')->assertOk()->assertSee($variant->product->title);
        $this->get('/shop/'.$variant->product->slug)->assertOk();
    }

    #[Test]
    #[Group('COM-01')]
    #[Group('COM-11')]
    public function real_mode_at_runtime_is_rejected_as_simulation_only(): void
    {
        // MysiteConfig 已在啟動時拒絕 real；即使執行期被改成 real，商店也只回 SIMULATION_ONLY。
        $this->setUpCommerce('real');
        $this->getJson('/shop')->assertStatus(422)->assertJson(['error' => 'SIMULATION_ONLY']);
        $this->postJson('/checkout', [])->assertStatus(422)->assertJson(['error' => 'SIMULATION_ONLY']);
    }

    #[Test]
    #[Group('COM-01')]
    #[Group('COM-11')]
    public function payment_endpoint_is_always_simulation_only(): void
    {
        $this->setUpCommerce();
        $this->postJson('/checkout/payment', ['amount' => '100'])->assertStatus(422)->assertJson(['error' => 'SIMULATION_ONLY']);
        $this->getJson('/checkout/payment')->assertStatus(422)->assertJson(['error' => 'SIMULATION_ONLY']);
    }

    /** @return array<string, array{array<string, mixed>}> */
    public static function paymentPayloads(): array
    {
        return [
            'card number' => [['card_number' => '4111111111111111']],
            'cvv' => [['cvv' => '123']],
            'expiry' => [['expiry' => '12/30']],
            'payment method' => [['payment_method' => 'credit']],
            'shipping address' => [['shipping_address' => '某路 1 號']],
            'phone' => [['phone' => '0912345678']],
            'nested card' => [['buyer' => ['card' => ['pan' => '4111']]]],
        ];
    }

    #[Test]
    #[Group('COM-11')]
    #[DataProvider('paymentPayloads')]
    public function payment_or_real_address_fields_are_rejected(array $payload): void
    {
        $this->setUpCommerce();
        $variant = $this->variant();
        $this->postJson('/cart/items', ['variant_id' => $variant->id, 'quantity' => 1] + $payload)
            ->assertStatus(422)->assertJson(['error' => 'SIMULATION_ONLY']);
        $this->postJson('/checkout', ['idempotency_key' => str_repeat('a', 32)] + $payload)
            ->assertStatus(422)->assertJson(['error' => 'SIMULATION_ONLY']);
        $this->assertSame(0, Order::query()->count());
    }
}
