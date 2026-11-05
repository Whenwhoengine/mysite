<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * staging／production：尚未完成 2FA 登記的後臺帳號只能進入「帳號安全」頁完成登記。
 * development／testing 不強制（方便開發），但流程相同。
 */
final class RequireAdminTwoFactor
{
    public const ENFORCED_ENVIRONMENTS = ['staging', 'production'];

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        if ($user instanceof User
            && app()->environment(self::ENFORCED_ENVIRONMENTS)
            && ! $user->hasConfirmedTwoFactor()
            && ! $request->routeIs('admin.security')) {
            return redirect()->route('admin.security')->with('status', '請先完成兩步驟驗證（2FA）登記。');
        }

        return $next($request);
    }
}
