@extends('auth.layout')

@section('title', '確認密碼')

@section('content')
<p>這是敏感操作，請先再次輸入密碼。</p>
<form class="form" method="post" action="{{ route('password.confirm.store') }}">
    @csrf
    <div class="field">
        <label class="field__label" for="password">密碼</label>
        <input class="input" id="password" name="password" type="password" autocomplete="current-password" required autofocus>
    </div>
    <button class="button button--primary" type="submit">確認</button>
</form>
@endsection
