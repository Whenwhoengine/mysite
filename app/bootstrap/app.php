<?php

use App\Http\Middleware\AssignRequestId;
use App\Http\Middleware\InternalOnly;
use App\Support\Errors\ErrorRenderer;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

// 先讀取 *_FILE 秘密（ADR-006），再啟動框架。
require __DIR__.'/secrets.php';

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        then: function (): void {
            \Illuminate\Support\Facades\Route::middleware([])
                ->group(base_path('routes/health.php'));
            \Illuminate\Support\Facades\Route::middleware(['internal'])
                ->group(base_path('routes/internal.php'));
            // 公開唯讀 API（MySite React 前端專案）：不經 web 群組，沒有 Session、CSRF、cookie；
            // 方法、/index.php 前綴與回應標頭由全域的 PublicApiHeaders 管（含路由不存在與維護模式的回應）
            \Illuminate\Support\Facades\Route::middleware([])
                ->group(base_path('routes/api.php'));
        },
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $proxies = array_values(array_filter(array_map('trim', explode(',', (string) env('TRUSTED_PROXIES', '')))));
        $middleware->trustProxies(
            at: $proxies === [] ? null : $proxies,
            headers: Request::HEADER_X_FORWARDED_FOR | Request::HEADER_X_FORWARDED_HOST | Request::HEADER_X_FORWARDED_PROTO,
        );
        // 順序：AssignRequestId 在最外層（錯誤回應也帶同一個請求編號），其次公開 API 守門與標頭
        $middleware->prepend(\App\Http\Middleware\PublicApiHeaders::class);
        $middleware->prepend(AssignRequestId::class);
        $middleware->append(\App\Http\Middleware\RejectDisabledCommerceEarly::class);
        // 維護模式期間健康檢查與部署狀態仍須可用（部署流程依此判斷換版結果）
        $middleware->preventRequestsDuringMaintenance(except: ['healthz', 'readyz', 'internal/deploy-status']);
        $middleware->group('internal', [
            InternalOnly::class,
            \Illuminate\Routing\Middleware\SubstituteBindings::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        ErrorRenderer::register($exceptions);
    })->create();
