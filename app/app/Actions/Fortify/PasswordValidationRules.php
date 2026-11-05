<?php

declare(strict_types=1);

namespace App\Actions\Fortify;

use Illuminate\Validation\Rules\Password;

trait PasswordValidationRules
{
    /** 管理帳號密碼：至少 12 字元（暫定）、最多 72 bytes（bcrypt 上限）。 */
    protected function passwordRules(): array
    {
        return ['required', 'string', Password::min(12), 'max:72', 'confirmed'];
    }

    /** @return array<string, string> */
    protected function passwordMessages(): array
    {
        return [
            'password.required' => '請輸入新密碼。',
            'password.min' => '密碼至少需要 12 個字元。',
            'password.max' => '密碼最多 72 個字元。',
            'password.confirmed' => '兩次輸入的新密碼不一致。',
            'current_password.required' => '請輸入目前的密碼。',
            'current_password.current_password' => '目前的密碼不正確。',
        ];
    }
}
