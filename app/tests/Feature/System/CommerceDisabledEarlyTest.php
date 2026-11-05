<?php

declare(strict_types=1);

namespace Tests\Feature\System;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/** COM-01／OPS-12：商店關閉時商店路徑回 404，且不啟動 Session、不設 cookie。 */
final class CommerceDisabledEarlyTest extends TestCase
{
    #[Group('COM-01')]
    #[Test]
    public function disabled_shop_paths_return_404_without_cookies(): void
    {
        config(['mysite.commerce.mode' => 'disabled']);
        foreach (['/shop', '/shop/sample-sticker', '/cart', '/checkout', '/orders/abc'] as $path) {
            $response = $this->get($path);
            $response->assertNotFound();
            $this->assertSame([], $response->headers->getCookies(), "{$path} 不應設定任何 cookie");
        }
        $this->post('/checkout')->assertNotFound();
    }
}
