<?php

declare(strict_types=1);

namespace App\Support\Errors;

use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Http\Exceptions\ThrottleRequestsException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Throwable;

/**
 * 統一錯誤呈現：固定錯誤碼、正體中文訊息、隨機 request ID；不暴露 stack trace、路徑或上游細節。
 */
final class ErrorRenderer
{
    public static function register(Exceptions $exceptions): void
    {
        $exceptions->dontReportDuplicates();

        $exceptions->report(function (MysiteException $e): bool {
            Log::warning('mysite_error', ['error_code' => $e->errorCode->value, 'detail' => $e->getMessage()]);

            return false;
        });

        $exceptions->render(function (MysiteException $e, Request $request): Response {
            return self::respond($request, $e->errorCode->status(), $e->errorCode->value, $e->errorCode->publicMessage());
        });

        $exceptions->render(function (ThrottleRequestsException $e, Request $request): Response {
            $code = ErrorCode::RateLimited;
            $response = self::respond($request, 429, $code->value, $code->publicMessage());
            foreach (['Retry-After', 'X-RateLimit-Limit', 'X-RateLimit-Remaining'] as $h) {
                if (isset($e->getHeaders()[$h])) {
                    $response->headers->set($h, (string) $e->getHeaders()[$h]);
                }
            }

            return $response;
        });

        $exceptions->render(function (Throwable $e, Request $request): ?Response {
            if (config('app.debug')) {
                return null; // 開發環境沿用框架除錯頁
            }
            if ($e instanceof \Illuminate\Validation\ValidationException || $e instanceof \Illuminate\Auth\AuthenticationException) {
                return null; // 框架原生處理（表單錯誤、導向登入）
            }
            if ($e instanceof \Illuminate\Session\TokenMismatchException) {
                return self::respond($request, 419, 'SESSION_EXPIRED', '頁面已逾時，請重新整理後再試一次。');
            }
            $status = $e instanceof HttpExceptionInterface ? $e->getStatusCode() : 500;
            $message = match (true) {
                $status === 404 => '找不到這個頁面。',
                $status === 403 => '您沒有權限檢視這個頁面。',
                $status === 405 => '不支援這個操作。',
                $status === 419 => '頁面已逾時，請重新整理後再試一次。',
                $status === 503 => '服務暫時無法使用，請稍後再試。',
                $status >= 500 => '系統發生錯誤，請稍後再試。',
                default => '無法完成請求。',
            };

            return self::respond($request, $status, 'HTTP_'.$status, $message);
        });
    }

    public static function respond(Request $request, int $status, string $code, string $message): Response
    {
        $requestId = (string) ($request->attributes->get('request_id') ?? bin2hex(random_bytes(16)));
        if ($request->is('internal/*') || $request->expectsJson()) {
            return response()->json(['error' => $code, 'message' => $message, 'request_id' => $requestId], $status, ['Cache-Control' => 'no-store']);
        }
        $view = view()->exists('errors.mysite') ? 'errors.mysite' : null;
        if ($view === null) {
            return response($message."\n請求編號：".$requestId, $status, ['Content-Type' => 'text/plain; charset=utf-8', 'Cache-Control' => 'no-store']);
        }

        return response()->view($view, [
            'status' => $status,
            'code' => $code,
            'message' => $message,
            'requestId' => $requestId,
        ], $status, ['Cache-Control' => 'no-store']);
    }
}
