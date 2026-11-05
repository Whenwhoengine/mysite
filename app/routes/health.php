<?php

declare(strict_types=1);

use App\Http\Controllers\System\DeployStatusController;
use App\Http\Controllers\System\HealthController;
use Illuminate\Support\Facades\Route;

// 健康檢查：不經 web 群組（不啟動 Session、不寫 cookie），DB 故障時 /readyz 仍能回 503。
Route::get('/healthz', [HealthController::class, 'live'])->name('healthz');
Route::get('/readyz', [HealthController::class, 'ready'])->name('readyz');

// 部署狀態：獨立唯讀權杖、限流；gateway 正式設定只放行這一條 /internal 路徑（v5 §14.8）
Route::get('/internal/deploy-status', DeployStatusController::class)
    ->middleware('throttle:30,1')
    ->name('internal.deploy-status');
