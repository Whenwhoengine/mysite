<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Support\Errors\ErrorCode;
use App\Support\Errors\MysiteException;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * 商店關閉（COMMERCE_MODE=disabled）時，在 Session／CSRF 啟動「之前」就以 404 拒絕商店路徑，
 * 不設任何 cookie、不暴露半成品（COM-01）。商店路由內的 EnsureCommerceEnabled 仍保留作第二道防線。
 */
final class RejectDisabledCommerceEarly
{
    private const PATHS = ['shop', 'shop/*', 'cart', 'cart/*', 'checkout', 'checkout/*', 'orders/*'];

    public function handle(Request $request, Closure $next): Response
    {
        if (config('mysite.commerce.mode') === 'disabled' && $request->is(...self::PATHS)) {
            throw new MysiteException(ErrorCode::CommerceDisabled, '商店未開放：'.$request->path());
        }

        return $next($request);
    }
}
