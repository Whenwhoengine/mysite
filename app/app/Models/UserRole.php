<?php

declare(strict_types=1);

namespace App\Models;

/**
 * 管理後臺角色。users.role 為 NULL 的帳號沒有任何後臺權限。
 */
enum UserRole: string
{
    case Admin = 'admin';
    case Viewer = 'viewer';

    public function label(): string
    {
        return match ($this) {
            self::Admin => '管理員',
            self::Viewer => '唯讀人員',
        };
    }
}
