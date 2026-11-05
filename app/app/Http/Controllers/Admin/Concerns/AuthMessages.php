<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin\Concerns;

/**
 * Fortify／框架預設訊息鍵或英文訊息的正體中文對照（專案目前沒有 lang/zh_TW 的 auth／passwords 檔時仍顯示中文）。
 */
final class AuthMessages
{
    private const MAP = [
        'auth.failed' => '帳號或密碼錯誤，或此帳號沒有後臺權限。',
        'auth.throttle' => '嘗試次數過多，請稍後再試。',
        'auth.password' => '密碼不正確。',
        'passwords.reset' => '密碼已重設，請重新登入。',
        'passwords.sent' => '若此 email 屬於後臺帳號，重設連結已送出（開發環境只寫入郵件捕捉器）。',
        'passwords.throttled' => '若此 email 屬於後臺帳號，重設連結已送出（開發環境只寫入郵件捕捉器）。',
        'passwords.token' => '重設連結無效或已過期，請重新申請。',
        'passwords.user' => '若此 email 屬於後臺帳號，重設連結已送出（開發環境只寫入郵件捕捉器）。',
        'The provided two factor authentication code was invalid.' => '驗證碼或復原碼不正確。',
        'The provided password was incorrect.' => '密碼不正確。',
        'The provided password does not match your current password.' => '目前的密碼不正確。',
    ];

    public static function translate(string $message): string
    {
        if (isset(self::MAP[$message])) {
            return self::MAP[$message];
        }
        if (str_starts_with($message, 'Too many login attempts')) {
            return self::MAP['auth.throttle'];
        }

        return $message;
    }
}
