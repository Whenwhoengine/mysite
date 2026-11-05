@extends('auth.layout')

@section('title', '忘記密碼')

@section('content')
<p>輸入後臺帳號的 email，我們會寄出重設連結。開發與測試環境的郵件只寫入捕捉器（log），不會實際寄出。</p>
<form class="form" method="post" action="{{ route('password.email') }}">
    @csrf
    <div class="field">
        <label class="field__label" for="email">Email</label>
        <input class="input" id="email" name="email" type="email" autocomplete="username" required value="{{ old('email') }}">
    </div>
    <button class="button button--primary" type="submit">寄送重設連結</button>
</form>
<p><a href="{{ route('login') }}">回登入</a></p>
@endsection
