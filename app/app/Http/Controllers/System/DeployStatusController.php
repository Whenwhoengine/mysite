<?php

declare(strict_types=1);

namespace App\Http\Controllers\System;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * 受限部署狀態（v5 §14.8）：以獨立唯讀權杖（DEPLOY_STATUS_TOKEN）取得目前 release ID 與就緒狀態，
 * 供部署 workflow 核對「主機實際換上的版本」。不回 DB 位址、設定或套件資訊。
 */
final class DeployStatusController
{
    public function __invoke(Request $request): JsonResponse
    {
        $token = (string) config('mysite.deploy.status_token', '');
        $given = (string) $request->bearerToken();
        if ($token === '' || $given === '' || ! hash_equals($token, $given)) {
            abort(404);
        }

        $dbOk = true;
        try {
            DB::connection()->select('SELECT 1');
        } catch (Throwable) {
            $dbOk = false;
        }
        $heartbeat = Cache::get('mysite:scheduler:heartbeat');
        $schedulerOk = is_int($heartbeat) && (now()->getTimestamp() - $heartbeat) < 300;

        return response()->json([
            'release_id' => (string) config('mysite.deploy.release_id'),
            'environment' => (string) config('mysite.deploy.environment'),
            'source_commit' => (string) config('mysite.source_commit'),
            'status' => $dbOk ? 'active' : 'degraded',
            'checks' => ['database' => $dbOk, 'scheduler' => $schedulerOk],
            'checked_at' => now()->utc()->format('Y-m-d\TH:i:s\Z'),
        ], 200, ['Cache-Control' => 'no-store']);
    }
}
