<?php

declare(strict_types=1);

use App\Http\Controllers\Preview\PreviewController;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\Support\Facades\Route;
use Illuminate\View\Middleware\ShareErrorsFromSession;

/*
 * 草稿預覽（契約 §5）：票券交換 → preview session cookie（path /preview）→ 顯示快照。
 * 不使用 Session／CSRF（只有 GET）；回應標頭由 PreviewController 中介層統一設定。
 */

Route::prefix('preview')
    ->withoutMiddleware([
        StartSession::class,
        ShareErrorsFromSession::class,
        PreventRequestForgery::class,
    ])
    ->group(function (): void {
        Route::get('/exchange', [PreviewController::class, 'exchange'])->middleware('throttle:preview-exchange')->name('preview.exchange');
        Route::get('/view', [PreviewController::class, 'view'])->name('preview.view');
    });
