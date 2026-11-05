<?php

declare(strict_types=1);

use Laravel\Fortify\Features;

/*
 * Laravel Fortify：只用於 Laravel 管理後臺（/admin）。
 * 啟用：登入、兩步驟驗證（含 recovery codes、啟用需確認密碼）、密碼重設（郵件只進捕捉器）、更新密碼。
 * 關閉：公開註冊、Email 驗證、個人資料更新、passkeys。
 * WordPress 帳號與此處帳號互不相通（不做 SSO）。
 */
return [
    'guard' => 'web',

    'passwords' => 'users',

    'username' => 'email',

    'email' => 'email',

    'lowercase_usernames' => true,

    'home' => '/admin',

    // 所有 Fortify 路由都在 /admin 之下：/admin/login、/admin/forgot-password、/admin/two-factor-challenge、/admin/user/...
    'prefix' => 'admin',

    'domain' => null,

    // 停用帳號即時登出；所有 Fortify 路由另有整體限流；頁面禁止共享快取
    'middleware' => ['web', 'account.active', 'throttle:fortify', 'private.no-store'],

    'auth_middleware' => 'auth',

    'limiters' => [
        'login' => 'login',
        'two-factor' => 'two-factor',
        'passkeys' => null,
    ],

    'views' => true,

    'redirects' => [
        'login' => '/admin',
        'logout' => '/admin/login',
        'password-confirmation' => null,
        'register' => null,
        'email-verification' => null,
        'password-reset' => '/admin/login',
    ],

    'features' => [
        Features::resetPasswords(),
        Features::updatePasswords(),
        Features::twoFactorAuthentication([
            'confirm' => true,
            'confirmPassword' => true,
        ]),
    ],
];
