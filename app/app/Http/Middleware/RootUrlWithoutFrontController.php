<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\URL;
use Symfony\Component\HttpFoundation\Response;

/**
 * 直接開「/index.php」時，Laravel 預設會把 /index.php 當成網站根目錄，頁面上的連結變成「/index.php/search」等；
 * 這類路徑在 gateway 與 app-web 一律 404（防止繞過依路徑前綴設的規則，MySite React 前端專案第一輪查錯）。
 * 只對這種請求把產生連結的根目錄改回「協定＋主機」（不含 /index.php），主機名照請求本身；其他請求完全不受影響。
 */
final class RootUrlWithoutFrontController
{
    public function handle(Request $request, Closure $next): Response
    {
        if ($request->getBaseUrl() === '') {
            return $next($request);
        }

        URL::forceRootUrl($request->getSchemeAndHttpHost());
        try {
            return $next($request);
        } finally {
            URL::forceRootUrl(null);
        }
    }
}
