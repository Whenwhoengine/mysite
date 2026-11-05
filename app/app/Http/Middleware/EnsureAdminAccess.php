<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * 後臺入口：必須是有角色（admin／viewer）的 Laravel 帳號。
 * WordPress 帳號不會因同名或同 email 取得任何權限（兩套帳號互不相通）。細部寫入權限由 policies 判斷。
 */
final class EnsureAdminAccess
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        if (! $user instanceof User || ! $user->canAccessAdmin()) {
            abort(403);
        }

        return $next($request);
    }
}
