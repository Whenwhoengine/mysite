<?php

declare(strict_types=1);

use App\Http\Controllers\Internal\ContentEventController;
use Illuminate\Support\Facades\Route;

/*
 * 已位於 prefix('internal') 與 internal 中介層群組內（只接受容器內直接連線、無 CSRF）。
 * 完整路徑：POST /internal/content-events/v1（契約 §4）。
 */

Route::post('/content-events/v1', ContentEventController::class)->name('internal.content-events');
