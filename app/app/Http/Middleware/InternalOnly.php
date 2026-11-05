<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\IpUtils;
use Symfony\Component\HttpFoundation\Response;

/**
 * /internal/* 只接受容器內直接連線：經 gateway（受信任代理子網）或帶轉送標頭的請求一律 404。
 */
final class InternalOnly
{
    public function handle(Request $request, Closure $next): Response
    {
        $remote = (string) $request->server('REMOTE_ADDR', '');
        $proxies = (array) config('mysite.trusted_proxies', []);
        $viaProxy = $proxies !== [] && IpUtils::checkIp($remote, $proxies);
        $hasForwarded = $request->headers->has('X-Forwarded-For') || $request->headers->has('Forwarded');

        if ($viaProxy || $hasForwarded) {
            abort(404);
        }

        return $next($request);
    }
}
