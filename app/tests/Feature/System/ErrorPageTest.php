<?php

declare(strict_types=1);

namespace Tests\Feature\System;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/** UI-03／INF-07：4xx 一律顯示正體中文錯誤頁；正式模式 5xx 不暴露 stack trace。 */
final class ErrorPageTest extends TestCase
{
    #[Group('UI-03')]
    #[Test]
    public function not_found_uses_branded_page_even_in_debug_mode(): void
    {
        config(['app.debug' => true]);
        $this->get('/this-page-does-not-exist-'.bin2hex(random_bytes(4)))
            ->assertNotFound()
            ->assertSee('找不到這個頁面')
            ->assertDontSee('Not Found')
            ->assertHeader('Cache-Control', 'no-store, private');
    }

    #[Group('INF-07')]
    #[Test]
    public function server_error_in_production_mode_hides_internals(): void
    {
        config(['app.debug' => false]);
        \Illuminate\Support\Facades\Route::get('/__mysite_test_boom', static fn () => throw new \RuntimeException('secret-internal-path /var/www/app'));
        $response = $this->get('/__mysite_test_boom')->assertStatus(500);
        $response->assertSee('系統發生錯誤');
        $response->assertDontSee('secret-internal-path');
        $response->assertDontSee('/var/www/app');
        $response->assertDontSee('Stack trace');
    }
}
