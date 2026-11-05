<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

/**
 * 每個請求帶隨機 request ID：僅在格式正確時沿用 gateway 的 X-Request-Id，否則自行產生。
 */
final class AssignRequestId
{
    public function handle(Request $request, Closure $next): Response
    {
        $incoming = (string) $request->headers->get('X-Request-Id', '');
        $id = preg_match('/^[a-f0-9]{32}$/', $incoming) === 1 ? $incoming : bin2hex(random_bytes(16));
        $request->attributes->set('request_id', $id);
        Log::shareContext(['request_id' => $id]);

        $response = $next($request);
        $response->headers->set('X-Request-Id', $id);

        return $response;
    }
}
