<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Carbon\CarbonImmutable;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Fortify\TwoFactorAuthenticatable;

/**
 * Laravel 管理後臺帳號（與 WordPress 帳號互不相通，不做 SSO）。
 * role 為 NULL 者沒有後臺權限；disabled_at 有值者不可登入。role 與 disabled_at 不可大量指派。
 *
 * @property int $id
 * @property string $name
 * @property string $email
 * @property UserRole|null $role
 * @property CarbonImmutable|null $disabled_at
 * @property CarbonImmutable|null $two_factor_confirmed_at
 */
#[Fillable(['name', 'email', 'password'])]
#[Hidden(['password', 'remember_token', 'two_factor_secret', 'two_factor_recovery_codes'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable, TwoFactorAuthenticatable;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'role' => UserRole::class,
            'disabled_at' => 'datetime',
            'two_factor_confirmed_at' => 'datetime',
        ];
    }

    public function isAdmin(): bool
    {
        return $this->role === UserRole::Admin && ! $this->isDisabled();
    }

    public function isDisabled(): bool
    {
        return $this->disabled_at !== null;
    }

    /** 可進入後臺（admin 或 viewer，且未停用）。 */
    public function canAccessAdmin(): bool
    {
        return $this->role !== null && ! $this->isDisabled();
    }

    public function hasConfirmedTwoFactor(): bool
    {
        return $this->two_factor_secret !== null && $this->two_factor_confirmed_at !== null;
    }
}
