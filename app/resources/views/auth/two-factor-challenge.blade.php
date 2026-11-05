@extends('auth.layout')

@section('title', '兩步驟驗證')

@section('content')
<form class="form" method="post" action="{{ route('two-factor.login.store') }}">
    @csrf
    <div class="field">
        <label class="field__label" for="code">驗證器 App 的 6 位數驗證碼</label>
        <input class="input" id="code" name="code" inputmode="numeric" autocomplete="one-time-code" pattern="\d{6}" autofocus>
    </div>
    <p class="field__hint">無法使用驗證器時，改輸入一組復原碼（每組只能用一次）：</p>
    <div class="field">
        <label class="field__label" for="recovery_code">復原碼</label>
        <input class="input" id="recovery_code" name="recovery_code" autocomplete="off" spellcheck="false">
    </div>
    <button class="button button--primary" type="submit">驗證並登入</button>
</form>
@endsection
