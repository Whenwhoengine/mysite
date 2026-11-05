<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Product;
use App\Models\User;

/** 權限矩陣：admin 可讀寫；viewer 唯讀；無角色或停用者全部拒絕。 */
final class ProductPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->canAccessAdmin();
    }

    public function view(User $user, Product $product): bool
    {
        return $user->canAccessAdmin();
    }

    public function create(User $user): bool
    {
        return $user->isAdmin();
    }

    public function update(User $user, Product $product): bool
    {
        return $user->isAdmin();
    }

    public function adjustStock(User $user, Product $product): bool
    {
        return $user->isAdmin();
    }
}
