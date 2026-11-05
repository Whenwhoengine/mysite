@extends('admin.layout')

@section('title', '帳號安全')

@section('content')
<h1>帳號安全</h1>

<section aria-labelledby="tfa-heading" class="admin-panel">
    <h2 id="tfa-heading">兩步驟驗證（2FA）</h2>
    @if ($user->hasConfirmedTwoFactor())
        <p>狀態：<strong>已啟用</strong></p>
        @if ($recoveryCodes !== [])
            <div class="notice notice--warning">
                <p class="notice__title">復原碼（只顯示這一次，請離線保存）</p>
                <ul class="admin-codes">
                    @foreach ($recoveryCodes as $code)
                        <li><code>{{ $code }}</code></li>
                    @endforeach
                </ul>
            </div>
        @endif
        <form method="post" action="{{ route('two-factor.regenerate-recovery-codes') }}">
            @csrf
            <button class="button" type="submit">重新產生復原碼</button>
        </form>
        <form method="post" action="{{ route('two-factor.disable') }}" data-confirm="確定停用兩步驟驗證？">
            @csrf
            @method('DELETE')
            <button class="button button--danger" type="submit">停用 2FA</button>
        </form>
    @elseif ($pending)
        <p>請用驗證器 App 掃描 QR code，或手動輸入金鑰，然後輸入 6 位數驗證碼完成啟用。</p>
        <img class="admin-qr" src="{{ $qrDataUri }}" alt="2FA 設定用 QR code" width="192" height="192">
        <p>手動輸入金鑰：<code>{{ $secretKey }}</code></p>
        <form class="form" method="post" action="{{ route('two-factor.confirm') }}">
            @csrf
            <div class="field">
                <label class="field__label" for="code">驗證碼</label>
                <input class="input" id="code" name="code" inputmode="numeric" autocomplete="one-time-code" pattern="\d{6}" required>
                @error('code', 'confirmTwoFactorAuthentication')<span class="field__error">驗證碼不正確，請再試一次。</span>@enderror
            </div>
            <button class="button button--primary" type="submit">確認啟用</button>
        </form>
    @else
        <p>狀態：<strong>未啟用</strong>。staging／production 必須完成 2FA 才能使用後臺。</p>
        <form method="post" action="{{ route('two-factor.enable') }}">
            @csrf
            <button class="button button--primary" type="submit">開始設定 2FA</button>
        </form>
    @endif
</section>

<section aria-labelledby="pw-heading" class="admin-panel">
    <h2 id="pw-heading">更新密碼</h2>
    <form class="form" method="post" action="{{ route('user-password.update') }}">
        @csrf
        @method('PUT')
        <div class="field">
            <label class="field__label" for="current_password">目前密碼</label>
            <input class="input" id="current_password" name="current_password" type="password" autocomplete="current-password" required>
            @error('current_password', 'updatePassword')<span class="field__error">{{ $message }}</span>@enderror
        </div>
        <div class="field">
            <label class="field__label" for="password">新密碼（至少 12 字元）</label>
            <input class="input" id="password" name="password" type="password" autocomplete="new-password" minlength="12" maxlength="72" required>
            @error('password', 'updatePassword')<span class="field__error">{{ $message }}</span>@enderror
        </div>
        <div class="field">
            <label class="field__label" for="password_confirmation">再次輸入新密碼</label>
            <input class="input" id="password_confirmation" name="password_confirmation" type="password" autocomplete="new-password" required>
        </div>
        <button class="button" type="submit">更新密碼</button>
    </form>
</section>
@endsection
