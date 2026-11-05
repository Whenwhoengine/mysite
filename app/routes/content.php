<?php

declare(strict_types=1);

use App\Domain\Content\ContentType;
use App\Http\Controllers\Content\ContentController;
use App\Http\Controllers\Content\FeedController;
use Illuminate\Support\Facades\Route;

/*
 * 內容站公開路由（內容工作包）。全部為 GET、資料來自 WordPress REST；
 * 不需要 Session 與 CSRF：移除 StartSession 等中介層，匿名訪客不會被設定 Session cookie、也不寫 Session 表。
 */

$stateless = [
    \Illuminate\Session\Middleware\StartSession::class,
    \Illuminate\View\Middleware\ShareErrorsFromSession::class,
    \Illuminate\Foundation\Http\Middleware\PreventRequestForgery::class,
];
$slug = '[^/]{1,200}';

Route::withoutMiddleware($stateless)->group(function () use ($slug): void {
    Route::get('/', [ContentController::class, 'home'])->name('content.home');

    foreach (ContentType::listable() as $type) {
        $prefix = $type->pathPrefix();
        Route::get('/'.$prefix, [ContentController::class, 'index'])
            ->defaults('type', $type->value)
            ->name("content.{$prefix}.index");
        Route::get('/'.$prefix.'/{slug}', [ContentController::class, 'show'])
            ->defaults('type', $type->value)
            ->where('slug', $slug)
            ->name("content.{$prefix}.show");
    }

    Route::get('/pages/{slug}', [ContentController::class, 'page'])->where('slug', $slug)->name('content.pages.show');
    Route::get('/categories/{slug}', [ContentController::class, 'category'])->where('slug', $slug)->name('content.categories.show');
    Route::get('/tags/{slug}', [ContentController::class, 'tag'])->where('slug', $slug)->name('content.tags.show');
    Route::get('/search', [ContentController::class, 'search'])->middleware('throttle:content-search')->name('content.search');
    Route::get('/feed.xml', [FeedController::class, 'feed'])->name('content.feed');
    Route::get('/sitemap.xml', [FeedController::class, 'sitemap'])->name('content.sitemap');
});
