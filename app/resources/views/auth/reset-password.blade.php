@extends('auth.layout')

@section('title', '重設密碼')

@section('content')
<form class="form" method="post" action="{{ route('password.update') }}">
    @csrf
    <input type="hidden" name="token" value="{{ $request->route('token') }}">
    <div class="field">
        <label class="field__label" for="email">Email</label>
        <input class="input" id="email" name="email" type="email" autocomplete="username" required value="{{ old('email', $request->query('email')) }}">
    </div>
    <div class="field">
        <label class="field__label" for="password">新密碼（至少 12 字元）</label>
        <input class="input" id="password" name="password" type="password" autocomplete="new-password" minlength="12" maxlength="72" required>
    </div>
    <div class="field">
        <label class="field__label" for="password_confirmation">再次輸入新密碼</label>
        <input class="input" id="password_confirmation" name="password_confirmation" type="password" autocomplete="new-password" required>
    </div>
    <button class="button button--primary" type="submit">重設密碼</button>
</form>
@endsection
