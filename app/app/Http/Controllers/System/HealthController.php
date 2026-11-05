<?php

declare(strict_types=1);

namespace App\Http\Controllers\System;

use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * /healthz：程序存活（不碰 DB）；/readyz：必要依賴（DB、framework 資料表）就緒（INF-05）。
 * 公開回應只有 ok／unavailable，不暴露版本、路徑或錯誤細節。
 */
final class HealthController
{
    public function live(): JsonResponse
    {
        return response()->json(['status' => 'ok'], 200, ['Cache-Control' => 'no-store']);
    }

    public function ready(): JsonResponse
    {
        try {
            DB::connection()->select('SELECT 1');
            foreach (['migrations', 'cache', 'sessions', 'jobs'] as $table) {
                if (! Schema::hasTable($table)) {
                    throw new \RuntimeException("缺少資料表 {$table}");
                }
            }
        } catch (Throwable $e) {
            Log::warning('readiness_failed', ['error_code' => 'READINESS_DB', 'reason' => $e::class]);

            return response()->json(['status' => 'unavailable'], 503, ['Cache-Control' => 'no-store']);
        }

        return response()->json(['status' => 'ok'], 200, ['Cache-Control' => 'no-store']);
    }
}
