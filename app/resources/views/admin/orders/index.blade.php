@extends('admin.layout')

@section('title', '模擬訂單')

@php($fmt = static fn ($m) => \App\Domain\Commerce\MoneyFormatter::format($m))

@section('content')
<div class="admin-toolbar">
    <h1>模擬訂單</h1>
    @if ($canExport)
        <a class="button" href="{{ route('admin.orders.export') }}">匯出 CSV（留稽核）</a>
    @endif
</div>
<form class="search-form" method="get" action="{{ route('admin.orders.index') }}">
    <label class="visually-hidden" for="status">狀態</label>
    <select class="select" id="status" name="status">
        <option value="">全部狀態</option>
        @foreach (\App\Models\OrderStatus::cases() as $s)
            <option value="{{ $s->value }}" @selected($status === $s)>{{ $s->label() }}</option>
        @endforeach
    </select>
    <button class="button" type="submit">篩選</button>
</form>
@if ($orders->isEmpty())
    <p class="empty">尚無模擬訂單。</p>
@else
    <div class="table-wrap"><table class="table">
        <thead><tr><th scope="col">編號</th><th scope="col">狀態</th><th scope="col">件數</th><th scope="col">總計</th><th scope="col">建立時間</th><th scope="col">匿名化</th></tr></thead>
        <tbody>
        @foreach ($orders as $order)
            <tr>
                <td><a href="{{ route('admin.orders.show', $order) }}">{{ $order->displayNumber() }}</a></td>
                <td>{{ $order->status->label() }}</td>
                <td>{{ $order->item_count }}</td>
                <td>{{ $fmt($order->total) }}</td>
                <td>{{ $order->placed_at->setTimezone(config('mysite.display_timezone'))->format('Y-m-d H:i') }}</td>
                <td>{{ $order->isAnonymized() ? '是' : '否' }}</td>
            </tr>
        @endforeach
        </tbody>
    </table></div>
    {{ $orders->links('shop._pagination') }}
@endif
@endsection
