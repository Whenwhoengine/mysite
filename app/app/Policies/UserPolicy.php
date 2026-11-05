<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\User;

final class UserPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->canAccessAdmin();
    }

    /** 停用／啟用他人帳號：只限 admin，且不可停用自己（避免把最後一位管理員鎖在門外）。 */
    public function disable(User $user, User $target): bool
    {
        return $user->isAdmin() && $user->id !== $target->id;
    }

    public function enable(User $user, User $target): bool
    {
        return $user->isAdmin() && $user->id !== $target->id;
    }
}
