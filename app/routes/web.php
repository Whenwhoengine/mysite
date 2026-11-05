<?php

declare(strict_types=1);

use App\Http\Controllers\System\HealthController;
use Illuminate\Support\Facades\Route;

/*
 * 公開路由總表。各區塊分檔，由不同工作包負責：
 *   routes/content.php — 內容站（首頁、文章、影音、分類、搜尋、RSS、sitemap）
 *   routes/preview.php — 草稿預覽（票券交換、預覽 session）
 *   routes/shop.php    — 模擬商店（COMMERCE_MODE 旗標控制）
 *   routes/admin.php   — Laravel 管理後臺
 */

Route::get('/healthz', [HealthController::class, 'live'])->name('healthz');
Route::get('/readyz', [HealthController::class, 'ready'])->name('readyz');

foreach (['content', 'preview', 'shop', 'admin'] as $section) {
    $file = __DIR__."/{$section}.php";
    if (is_file($file)) {
        require $file;
    }
}
