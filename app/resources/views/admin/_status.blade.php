{{-- Fortify 狀態代碼轉為正體中文（避免顯示英文鍵名）。 --}}
@php
    $statusMessages = [
        'two-factor-authentication-enabled' => '已產生 2FA 金鑰：請用驗證器 App 掃描 QR code，並輸入驗證碼完成啟用。',
        'two-factor-authentication-confirmed' => '兩步驟驗證已啟用。',
        'two-factor-authentication-disabled' => '兩步驟驗證已停用。',
        'recovery-codes-generated' => '已重新產生復原碼，舊的復原碼全部失效。',
        'password-updated' => '密碼已更新。',
    ];
@endphp
@if (session('status') && isset($statusMessages[session('status')]))
    <div class="notice notice--success" role="status"><p>{{ $statusMessages[session('status')] }}</p></div>
@elseif (session('status'))
    <div class="notice notice--success" role="status"><p>{{ \App\Http\Controllers\Admin\Concerns\AuthMessages::translate((string) session('status')) }}</p></div>
@endif
@if ($errors->any())
    <div class="notice notice--danger" role="alert">
        <p class="notice__title">無法完成操作</p>
        <ul class="flash-list">
            @foreach ($errors->all() as $message)
                <li>{{ \App\Http\Controllers\Admin\Concerns\AuthMessages::translate($message) }}</li>
            @endforeach
        </ul>
    </div>
@endif
