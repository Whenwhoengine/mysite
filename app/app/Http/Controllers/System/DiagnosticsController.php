<?php

declare(strict_types=1);

namespace App\Http\Controllers\System;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * 內部診斷（只限容器內直接連線＋診斷權杖）：經 HTTP→Nginx→FPM 讀回實際生效的 PHP 設定白名單欄位。
 * 不公開 phpinfo，不回路徑或環境值（INF-01、INF-07）。
 */
final class DiagnosticsController
{
    public function php(Request $request): JsonResponse
    {
        $token = (string) config('mysite.diagnostics_token', '');
        $given = (string) $request->headers->get('X-Mysite-Diagnostics-Token', '');
        if ($token === '' || ! hash_equals($token, $given)) {
            abort(404);
        }

        return response()->json([
            'service' => 'laravel',
            'sapi' => PHP_SAPI,
            'php_version' => PHP_VERSION,
            'session.upload_progress.enabled' => ini_get('session.upload_progress.enabled'),
            'expose_php' => ini_get('expose_php'),
            'display_errors' => ini_get('display_errors'),
            'log_errors' => ini_get('log_errors'),
            'opcache_enabled' => function_exists('opcache_get_status') && (bool) (@opcache_get_status(false)['opcache_enabled'] ?? false),
            'app_env' => config('app.env'),
            'app_debug' => (bool) config('app.debug'),
            'source_commit' => config('mysite.source_commit'),
            'release_id' => config('mysite.deploy.release_id'),
        ], 200, ['Cache-Control' => 'no-store']);
    }
}
