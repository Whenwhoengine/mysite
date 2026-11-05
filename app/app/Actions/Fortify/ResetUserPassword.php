<?php

declare(strict_types=1);

namespace App\Actions\Fortify;

use App\Domain\Audit\AuditLogger;
use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use Laravel\Fortify\Contracts\ResetsUserPasswords;

/** 以重設連結更新密碼（郵件只進捕捉器）。停用帳號不可重設。 */
final class ResetUserPassword implements ResetsUserPasswords
{
    use PasswordValidationRules;

    public function __construct(private readonly AuditLogger $audit) {}

    /** @param array<string, string> $input */
    public function reset(User $user, array $input): void
    {
        if ($user->isDisabled()) {
            throw ValidationException::withMessages(['email' => '無法重設此帳號的密碼。']);
        }
        Validator::make($input, ['password' => $this->passwordRules()], $this->passwordMessages())->validate();

        $user->forceFill(['password' => Hash::make($input['password'])])->save();
        $this->audit->record('auth.password_reset', $user, $user);
    }
}
