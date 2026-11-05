@extends('auth.layout')

@section('title', '登入')

@section('content')
<form class="form" method="post" action="{{ route('login.store') }}">
    @csrf
    <div class="field">
        <label class="field__label" for="email">Email</label>
        <input class="input" id="email" name="email" type="email" autocomplete="username" required autofocus value="{{ old('email') }}">
    </div>
    <div class="field">
        <label class="field__label" for="password">密碼</label>
        <input class="input" id="password" name="password" type="password" autocomplete="current-password" required>
    </div>
    <label><input type="checkbox" name="remember" value="1"> 記住我</label>
    <button class="button button--primary" type="submit">登入</button>
</form>
<p><a href="{{ route('password.request') }}">忘記密碼？</a></p>
@endsection
