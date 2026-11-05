<?php

declare(strict_types=1);

use App\Http\Controllers\Shop\CartController;
use App\Http\Controllers\Shop\CheckoutController;
use App\Http\Controllers\Shop\OrderController;
use App\Http\Controllers\Shop\ShopController;
use Illuminate\Support\Facades\Route;

/*
 * 模擬商店（v5 §10；COM-01～COM-11）。由 routes/web.php 在 web 群組內載入（Session、CSRF）。
 * commerce.enabled：COMMERCE_MODE=disabled → 404 COMMERCE_DISABLED；非 simulation → 422 SIMULATION_ONLY。
 * commerce.no-payment：任何卡號／付款／地址／電話欄位 → 422 SIMULATION_ONLY。
 */
Route::middleware(['commerce.enabled'])->group(function (): void {
    Route::get('/shop', [ShopController::class, 'index'])->name('shop.index');
    Route::get('/shop/{slug}', [ShopController::class, 'show'])->where('slug', '[a-z0-9]+(?:-[a-z0-9]+)*')->name('shop.show');

    Route::middleware(['private.no-store'])->group(function (): void {
        Route::get('/cart', [CartController::class, 'show'])->name('cart.show');
        Route::middleware(['commerce.no-payment', 'throttle:shop-cart'])->group(function (): void {
            Route::post('/cart/items', [CartController::class, 'store'])->name('cart.store');
            Route::patch('/cart/items/{variantId}', [CartController::class, 'update'])->whereNumber('variantId')->name('cart.update');
            Route::delete('/cart/items/{variantId}', [CartController::class, 'destroy'])->whereNumber('variantId')->name('cart.destroy');
        });

        Route::get('/checkout', [CheckoutController::class, 'show'])->name('checkout.show');
        Route::post('/checkout', [CheckoutController::class, 'store'])
            ->middleware(['commerce.no-payment', 'throttle:shop-checkout'])
            ->name('checkout.store');
        // 付款語意的端點一律拒絕：本案沒有、也不會有可啟用的真實金流。
        Route::match(['get', 'post', 'put', 'patch'], '/checkout/payment', [CheckoutController::class, 'payment'])->name('checkout.payment');

        Route::get('/orders/{token}', [OrderController::class, 'show'])->where('token', '[A-Za-z0-9_-]{1,128}')->name('orders.show');
        Route::post('/orders/{token}/cancel', [OrderController::class, 'cancel'])
            ->where('token', '[A-Za-z0-9_-]{1,128}')
            ->middleware(['commerce.no-payment', 'throttle:shop-checkout'])
            ->name('orders.cancel');
    });
});
