<?php

declare(strict_types=1);

namespace Tests\Feature\Commerce\Concerns;

use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\User;
use Illuminate\Testing\TestResponse;

/**
 * 商店／後臺功能測試共用工具。測試以 simulation 模式驗證完整流程；disabled 另有專門測試。
 */
trait InteractsWithCommerce
{
    protected function setUpCommerce(string $mode = 'simulation'): void
    {
        $this->withoutVite();
        config(['mysite.commerce.mode' => $mode]);
    }

    protected function variant(string $price = '100.00', int $stock = 10, array $productState = []): ProductVariant
    {
        $product = Product::factory()->create($productState);

        return ProductVariant::factory()->price($price)->withStock($stock)->create(['product_id' => $product->id])->refresh();
    }

    protected function addToCart(ProductVariant $variant, int $quantity = 1, array $extra = []): TestResponse
    {
        return $this->post('/cart/items', array_merge(['variant_id' => $variant->id, 'quantity' => $quantity], $extra));
    }

    /** 進入結帳頁並取得表單中的冪等鍵。 */
    protected function checkoutKey(): string
    {
        $response = $this->get('/checkout');
        $response->assertOk();
        preg_match('/name="idempotency_key" value="([a-f0-9]{32})"/', (string) $response->getContent(), $m);
        $this->assertNotEmpty($m[1] ?? null, '結帳頁沒有冪等鍵');

        return $m[1];
    }

    /** 送出結帳；回傳 [response, token|null]。 */
    protected function submitCheckout(string $key, array $extra = []): array
    {
        $response = $this->post('/checkout', array_merge(['idempotency_key' => $key], $extra));
        $location = (string) $response->headers->get('Location');
        $token = preg_match('#/orders/([A-Za-z0-9_-]{43})$#', $location, $m) === 1 ? $m[1] : null;

        return [$response, $token];
    }

    /** 從加入購物車到建立訂單的完整流程。 */
    protected function placeOrder(ProductVariant $variant, int $quantity = 1): string
    {
        $this->addToCart($variant, $quantity)->assertRedirect('/cart');
        [$response, $token] = $this->submitCheckout($this->checkoutKey());
        $response->assertRedirect();
        $this->assertNotNull($token, '結帳沒有導向訂單頁：'.$response->headers->get('Location'));

        return $token;
    }

    /** 模擬換成另一位訪客（全新 Session）。 */
    protected function switchToNewSession(): void
    {
        $this->app['session']->flush();
        $this->app['session']->regenerate(true);
        $this->app['auth']->forgetGuards();
    }

    protected function admin(): User
    {
        return User::factory()->admin()->create();
    }

    protected function viewer(): User
    {
        return User::factory()->viewer()->create();
    }

    /** 讓 CSRF 中介層在測試中生效（框架預設在單元測試中略過）。 */
    protected function enforceCsrf(): void
    {
        $this->app['env'] = 'development';
    }
}
