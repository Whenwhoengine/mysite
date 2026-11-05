<?php

declare(strict_types=1);

use App\Http\Controllers\Admin\AuditLogController;
use App\Http\Controllers\Admin\DashboardController;
use App\Http\Controllers\Admin\OrderController;
use App\Http\Controllers\Admin\ProductController;
use App\Http\Controllers\Admin\SecurityController;
use App\Http\Controllers\Admin\UserController;
use App\Http\Controllers\Admin\VariantController;
use Illuminate\Support\Facades\Route;

/*
 * Laravel 管理後臺（M7、COM-10）。登入、登出、密碼重設、2FA 端點由 Fortify 提供（prefix=admin，見 config/fortify.php）。
 * COMMERCE_MODE=disabled 時後臺仍可登入管理商品（供上線前準備），前臺商店維持關閉。
 * 權限：auth → 停用即登出 → 必須有後臺角色 → staging／production 必須完成 2FA → policies（admin 寫、viewer 讀）。
 */
Route::prefix('admin')
    ->name('admin.')
    ->middleware(['auth', 'account.active', 'admin.access', 'admin.2fa', 'private.no-store'])
    ->group(function (): void {
        Route::get('/', DashboardController::class)->name('dashboard');
        Route::get('/security', [SecurityController::class, 'show'])->name('security');

        Route::get('/products', [ProductController::class, 'index'])->name('products.index');
        Route::get('/products/create', [ProductController::class, 'create'])->name('products.create');
        Route::get('/products/{product}/edit', [ProductController::class, 'edit'])->whereNumber('product')->name('products.edit');

        Route::get('/orders', [OrderController::class, 'index'])->name('orders.index');
        Route::get('/orders/export', [OrderController::class, 'export'])->middleware('throttle:admin-export')->name('orders.export');
        Route::get('/orders/{order}', [OrderController::class, 'show'])->whereNumber('order')->name('orders.show');

        Route::get('/audit', [AuditLogController::class, 'index'])->name('audit.index');
        Route::get('/users', [UserController::class, 'index'])->name('users.index');

        Route::middleware('throttle:admin-write')->group(function (): void {
            Route::post('/products', [ProductController::class, 'store'])->name('products.store');
            Route::put('/products/{product}', [ProductController::class, 'update'])->whereNumber('product')->name('products.update');
            Route::post('/products/{product}/variants', [VariantController::class, 'store'])->whereNumber('product')->name('variants.store');
            Route::put('/variants/{variant}', [VariantController::class, 'update'])->whereNumber('variant')->name('variants.update');
            Route::post('/variants/{variant}/stock', [VariantController::class, 'adjustStock'])->whereNumber('variant')->name('variants.stock');
            Route::post('/orders/{order}/cancel', [OrderController::class, 'cancel'])->whereNumber('order')->name('orders.cancel');
            Route::post('/users/{user}/disable', [UserController::class, 'disable'])->whereNumber('user')->name('users.disable');
            Route::post('/users/{user}/enable', [UserController::class, 'enable'])->whereNumber('user')->name('users.enable');
        });
    });
