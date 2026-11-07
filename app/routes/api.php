<?php

declare(strict_types=1);

use App\Domain\Content\ContentType;
use App\Http\Controllers\Api\V1\PublicContentController;
use Illuminate\Support\Facades\Route;

/*
 * 公開唯讀 JSON API（MySite React 前端專案 v1 §5、F2；契約 app/resources/contracts/public-api-v1.openapi.json）。
 * 由 bootstrap/app.php 的 withRouting(then:) 以「不經 web 群組」掛上：沒有 Session、CSRF、cookie。
 * 只有 GET（HEAD 由框架隨 GET 提供）；其他方法、OPTIONS 與「/index.php/api/…」由全域中介層 PublicApiHeaders 擋下。
 * 不裝 Sanctum、不需要認證、不提供跨來源讀取（config/cors.php 的 paths 為空）：只回公開內容。
 * 存取範圍：gateway 對外 /api/ 一律 404；app-web 對前端專用網路只放行 /api/v1/（F6，PAPI-08）。
 * 搜尋不在這裡限流：next 模式下 Laravel 看到的來源是前端容器，每 IP 限流在 gateway（D13a、FE-08）。
 */

$slug = '[^/]{1,200}';

Route::prefix('api/v1')->name('api.v1.')->group(function () use ($slug): void {
    Route::get('/site', [PublicContentController::class, 'site'])->name('site');
    Route::get('/home', [PublicContentController::class, 'home'])->name('home');

    foreach (ContentType::listable() as $type) {
        $prefix = $type->pathPrefix();
        Route::get('/'.$prefix, [PublicContentController::class, 'index'])
            ->defaults('type', $type->value)
            ->name("{$prefix}.index");
        Route::get('/'.$prefix.'/{slug}', [PublicContentController::class, 'show'])
            ->defaults('type', $type->value)
            ->where('slug', $slug)
            ->name("{$prefix}.show");
    }

    Route::get('/pages/{slug}', [PublicContentController::class, 'page'])->where('slug', $slug)->name('pages.show');
    Route::get('/categories/{slug}', [PublicContentController::class, 'category'])->where('slug', $slug)->name('categories.show');
    Route::get('/tags/{slug}', [PublicContentController::class, 'tag'])->where('slug', $slug)->name('tags.show');
    Route::get('/search', [PublicContentController::class, 'search'])->name('search');
});
