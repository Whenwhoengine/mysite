<?php

declare(strict_types=1);

namespace App\Http\Controllers\Preview;

use App\Domain\Preview\PreviewInvalidException;
use App\Domain\Preview\PreviewService;
use App\Http\Controllers\Controller;
use App\Support\Errors\ErrorCode;
use App\Support\Errors\ErrorRenderer;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Cookie;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * 草稿預覽（API-11、API-13、契約 §5）。
 *
 * 所有回應（含錯誤頁、302）都帶 `Cache-Control: private, no-store`、`X-Robots-Tag: noindex, nofollow`、
 * `Referrer-Policy: no-referrer`。票券不寫入 log、不保留在網址：交換後立刻 302 到不含參數的 /preview/view。
 */
final class PreviewController extends Controller implements HasMiddleware
{
    public static function middleware(): array
    {
        return [
            new Middleware(static function (Request $request, Closure $next): Response {
                /** @var Response $response */
                $response = $next($request);
                $response->headers->set('Cache-Control', 'private, no-store, max-age=0');
                $response->headers->set('X-Robots-Tag', 'noindex, nofollow');
                $response->headers->set('Referrer-Policy', 'no-referrer');
                $response->headers->remove('Last-Modified');
                $response->headers->remove('ETag');

                return $response;
            }),
        ];
    }

    public function exchange(Request $request, PreviewService $service): Response
    {
        $ticket = $request->query('ticket');
        if (! is_string($ticket) || $ticket === '') {
            return $this->invalid($request, 'ticket_missing');
        }

        try {
            $session = $service->exchange($ticket);
        } catch (PreviewInvalidException $e) {
            return $this->invalid($request, $e->reason);
        } catch (Throwable $e) {
            // 任何非預期錯誤（含並發鎖等待逾時）都拒絕交換，不洩漏細節
            Log::warning('preview_exchange_failed', ['error' => $e::class]);

            return $this->invalid($request, 'exchange_error');
        }

        $seconds = max(1, $session['expires_at']->getTimestamp() - now()->getTimestamp());
        $cookie = Cookie::create(
            name: (string) config('mysite.preview.cookie', 'mysite_preview'),
            value: $session['token'],
            expire: now()->addSeconds($seconds),
            path: '/preview',
            domain: null,
            secure: (bool) config('session.secure', false),
            httpOnly: true,
            raw: false,
            sameSite: Cookie::SAMESITE_LAX,
        );

        // 清除網址參數後導向（不帶 ticket）
        return redirect()->to('/preview/view', 302)->withCookie($cookie);
    }

    public function view(Request $request, PreviewService $service): Response
    {
        $token = $request->cookie((string) config('mysite.preview.cookie', 'mysite_preview'));
        $context = $service->resolve(is_string($token) ? $token : null);
        $item = $service->snapshot($context);

        return response()->view('preview.show', [
            'item' => $item,
            'expiresAt' => CarbonImmutable::createFromTimestampUTC($context->claims->exp),
            'revision' => $context->claims->rev,
        ]);
    }

    private function invalid(Request $request, string $reason): Response
    {
        Log::notice('preview_invalid', ['reason' => $reason]);
        $code = ErrorCode::PreviewInvalid;

        return ErrorRenderer::respond($request, $code->status(), $code->value, $code->publicMessage());
    }
}
