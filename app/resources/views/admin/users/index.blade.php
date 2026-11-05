@extends('admin.layout')

@section('title', '使用者')

@section('content')
<h1>後臺使用者</h1>
<p class="field__hint">帳號只能由維運者以 <code>php artisan mysite:admin:create</code> 建立（沒有公開註冊）。WordPress 帳號與此處帳號互不相通。</p>
<div class="table-wrap"><table class="table">
    <thead><tr><th scope="col">ID</th><th scope="col">名稱</th><th scope="col">Email</th><th scope="col">角色</th><th scope="col">2FA</th><th scope="col">狀態</th><th scope="col"><span class="visually-hidden">操作</span></th></tr></thead>
    <tbody>
    @foreach ($users as $u)
        <tr>
            <td>{{ $u->id }}</td>
            <td>{{ $u->name }}</td>
            <td>{{ $u->email }}</td>
            <td>{{ $u->role?->label() }}</td>
            <td>{{ $u->hasConfirmedTwoFactor() ? '已啟用' : '未啟用' }}</td>
            <td>{{ $u->isDisabled() ? '已停用' : '使用中' }}</td>
            <td>
                @if ($u->isDisabled())
                    @can('enable', $u)
                        <form method="post" action="{{ route('admin.users.enable', $u) }}">@csrf<button class="button" type="submit">重新啟用</button></form>
                    @endcan
                @else
                    @can('disable', $u)
                        <form method="post" action="{{ route('admin.users.disable', $u) }}" data-confirm="確定停用此帳號？該帳號會立即被登出。">@csrf<button class="button button--danger" type="submit">停用</button></form>
                    @endcan
                @endif
            </td>
        </tr>
    @endforeach
    </tbody>
</table></div>
@endsection
