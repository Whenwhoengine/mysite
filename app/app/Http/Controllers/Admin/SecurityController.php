<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;
use Laravel\Fortify\Fortify;

/**
 * 帳號安全：2FA 啟用／確認／停用、recovery codes、更新密碼（端點由 Fortify 提供，此頁為 Blade 表單）。
 * QR code 以 data URI 的 <img> 呈現，不輸出未跳脫 HTML。
 */
final class SecurityController extends Controller
{
    public function show(Request $request): View
    {
        /** @var User $user */
        $user = $request->user();
        $pending = $user->two_factor_secret !== null && $user->two_factor_confirmed_at === null;
        $status = (string) $request->session()->get('status', '');
        $showCodes = $user->two_factor_confirmed_at !== null
            && in_array($status, ['two-factor-authentication-confirmed', 'recovery-codes-generated'], true);

        return view('admin.security', [
            'user' => $user,
            'pending' => $pending,
            'qrDataUri' => $pending ? 'data:image/svg+xml;base64,'.base64_encode($user->twoFactorQrCodeSvg()) : null,
            'secretKey' => $pending ? Fortify::currentEncrypter()->decrypt($user->two_factor_secret) : null,
            'recoveryCodes' => $showCodes ? $user->recoveryCodes() : [],
        ]);
    }
}
