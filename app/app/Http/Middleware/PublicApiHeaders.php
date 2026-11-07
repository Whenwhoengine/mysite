<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Support\Errors\ErrorCode;
use App\Support\Errors\ErrorRenderer;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * 公開 API 的入口守門與回應標頭（MySite React 前端專案 v1 §5.1、PAPI-02）。
 *
 * 掛在全域中介層、只對 `api/*` 生效，所以路由不存在的 404、方法不符、OPTIONS、維護模式的回應也一樣受管：
 * - 只接受 GET（HEAD 隨 GET）；其他方法（含 OPTIONS 預檢）一律 405，不提供跨來源讀取（定案 C-12）。
 * - 經「/index.php/api/…」進來的請求（base URL 不為空）一律 404：這種寫法會繞過 gateway、app-web 依路徑前綴設的規則。
 * - 任何一層都不得替內容保留副本（no-store），也不得帶 Set-Cookie；API 本身不給搜尋引擎收錄。
 */
final class PublicApiHeaders
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! ErrorRenderer::isPublicApi($request)) {
            return $next($request);
        }

        if ($request->getBaseUrl() !== '') {
            $code = ErrorCode::ContentNotFound;
            $response = ErrorRenderer::respond($request, 404, $code->value, $code->publicMessage());
        } elseif (! in_array($request->getMethod(), ['GET', 'HEAD'], true)) {
            $response = ErrorRenderer::respond($request, 405, 'HTTP_405', '不支援這個操作。');
            $response->headers->set('Allow', 'GET, HEAD');
        } else {
            $response = $next($request);
        }

        $response->headers->set('Cache-Control', 'no-store');
        $response->headers->set('X-Robots-Tag', 'noindex, nofollow');
        foreach ($response->headers->getCookies() as $cookie) {
            $response->headers->removeCookie($cookie->getName(), $cookie->getPath(), $cookie->getDomain());
        }

        return $response;
    }
}
