<?php

declare(strict_types=1);

namespace App\Actions\Fortify;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Timebox;

/**
 * Fortify 登入驗證：帳密正確、未停用、且具後臺角色才通過；失敗訊息一律相同（不洩露帳號是否存在或已停用），
 * 失敗路徑以 Timebox 固定最短耗時，避免以回應時間探測帳號。
 * WordPress 帳號不在此表，同 email 也不會通過（不做 SSO）。
 */
final class AuthenticateAdminUser
{
    /** 失敗路徑最短耗時（微秒，暫定 250ms）。 */
    private const MIN_MICROSECONDS = 250_000;

    public function __invoke(Request $request): ?User
    {
        return (new Timebox)->call(static function (Timebox $timebox) use ($request): ?User {
            $email = strtolower(trim((string) $request->input('email')));
            $password = (string) $request->input('password');
            if ($email === '' || $password === '') {
                return null;
            }
            /** @var User|null $user */
            $user = User::query()->where('email', $email)->first();
            if ($user === null || ! Hash::check($password, $user->password) || ! $user->canAccessAdmin()) {
                return null;
            }
            $timebox->returnEarly();

            return $user;
        }, self::MIN_MICROSECONDS);
    }
}
