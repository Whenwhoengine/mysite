<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Domain\Audit\AuditLogger;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/** 後臺帳號：列表、停用、重新啟用。帳號只能以 `php artisan mysite:admin:create` 建立（無公開註冊）。 */
final class UserController extends Controller
{
    public function index(): View
    {
        Gate::authorize('viewAny', User::class);
        $users = User::query()->whereNotNull('role')->orderBy('id')->get();

        return view('admin.users.index', ['users' => $users]);
    }

    public function disable(Request $request, User $user, AuditLogger $audit): RedirectResponse
    {
        Gate::authorize('disable', $user);
        if (! $user->isDisabled()) {
            $user->forceFill(['disabled_at' => now(), 'remember_token' => null])->save();
            // 立即終止該帳號所有 Session（database session driver）
            DB::table('sessions')->where('user_id', $user->id)->delete();
            $audit->record('user.disabled', $request->user(), $user);
        }

        return redirect()->route('admin.users.index')->with('status', '帳號已停用。');
    }

    public function enable(Request $request, User $user, AuditLogger $audit): RedirectResponse
    {
        Gate::authorize('enable', $user);
        if ($user->isDisabled()) {
            $user->forceFill(['disabled_at' => null])->save();
            $audit->record('user.enabled', $request->user(), $user);
        }

        return redirect()->route('admin.users.index')->with('status', '帳號已重新啟用。');
    }
}
