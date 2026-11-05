@extends('admin.layout')

@section('title', '稽核紀錄')

@section('content')
<h1>稽核紀錄</h1>
<p class="field__hint">保存 {{ (int) config('mysite.retention.audit_days', 90) }} 天（專案預設，非法定期限）。不保存密碼、token、驗證碼或完整表單；IP 只保留前綴。</p>
<form class="search-form" method="get" action="{{ route('admin.audit.index') }}">
    <label class="visually-hidden" for="action">動作前綴</label>
    <input class="input" id="action" name="action" value="{{ $action }}" placeholder="例如 product. 或 auth." pattern="[a-z_.]{1,64}">
    <button class="button" type="submit">篩選</button>
</form>
@include('admin.audit._table', ['logs' => $logs])
{{ $logs->links('shop._pagination') }}
@endsection
