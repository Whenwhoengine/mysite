<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Order;
use App\Models\User;

final class OrderPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->canAccessAdmin();
    }

    public function view(User $user, Order $order): bool
    {
        return $user->canAccessAdmin();
    }

    public function cancel(User $user, Order $order): bool
    {
        return $user->isAdmin();
    }

    /** 匯出屬敏感操作：只限 admin，並寫稽核。 */
    public function export(User $user): bool
    {
        return $user->isAdmin();
    }
}
