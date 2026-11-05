<?php

declare(strict_types=1);

use App\Http\Controllers\System\DiagnosticsController;
use Illuminate\Support\Facades\Route;

/*
 * /internal/*：只接受容器內直接連線（InternalOnly），gateway 正式設定另以 404 阻擋。
 * 內容事件接收端由 routes/internal-content.php 定義（內容工作包）。
 */

Route::prefix('internal')->group(function (): void {
    Route::get('/diagnostics/php', [DiagnosticsController::class, 'php'])->name('internal.diagnostics.php');

    $content = __DIR__.'/internal-content.php';
    if (is_file($content)) {
        require $content;
    }
});
