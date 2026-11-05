@extends('admin.layout')

@section('title', '模擬訂單 '.$order->displayNumber())

@php($fmt = static fn ($m) => \App\Domain\Commerce\MoneyFormatter::format($m))

@section('content')
<h1>模擬訂單 {{ $order->displayNumber() }}</h1>
<dl class="shop-order__meta">
    <div><dt>狀態</dt><dd>{{ $order->status->label() }}</dd></div>
    <div><dt>建立時間</dt><dd>{{ $order->placed_at->setTimezone(config('mysite.display_timezone'))->format('Y-m-d H:i:s') }}</dd></div>
    @if ($order->cancelled_at)<div><dt>取消時間</dt><dd>{{ $order->cancelled_at->setTimezone(config('mysite.display_timezone'))->format('Y-m-d H:i:s') }}</dd></div>@endif
    <div><dt>測試代號</dt><dd>{{ $order->buyer_code ?? '—' }}</dd></div>
    <div><dt>匿名化</dt><dd>{{ $order->anonymized_at ? $order->anonymized_at->setTimezone(config('mysite.display_timezone'))->format('Y-m-d H:i') : '否' }}</dd></div>
    <div><dt>總計</dt><dd>{{ $fmt($order->total) }}（模擬，不收款）</dd></div>
</dl>

<h2>明細（下單快照）</h2>
<div class="table-wrap"><table class="table">
    <thead><tr><th scope="col">商品</th><th scope="col">SKU</th><th scope="col">單價</th><th scope="col">數量</th><th scope="col">小計</th></tr></thead>
    <tbody>
    @foreach ($order->items as $item)
        <tr><td>{{ $item->product_title }}／{{ $item->variant_name }}</td><td><code>{{ $item->sku }}</code></td><td>{{ $fmt($item->unit_price) }}</td><td>{{ $item->quantity }}</td><td>{{ $fmt($item->line_total) }}</td></tr>
    @endforeach
    </tbody>
</table></div>

<h2>庫存異動</h2>
<div class="table-wrap"><table class="table">
    <thead><tr><th scope="col">時間</th><th scope="col">規格</th><th scope="col">增減</th><th scope="col">異動後</th><th scope="col">原因</th></tr></thead>
    <tbody>
    @foreach ($order->mutations as $m)
        <tr><td>{{ $m->created_at->setTimezone(config('mysite.display_timezone'))->format('Y-m-d H:i:s') }}</td><td>#{{ $m->product_variant_id }}</td><td>{{ $m->delta > 0 ? '+'.$m->delta : $m->delta }}</td><td>{{ $m->stock_after }}</td><td>{{ $m->reasonLabel() }}</td></tr>
    @endforeach
    </tbody>
</table></div>

@if ($canCancel)
    <form method="post" action="{{ route('admin.orders.cancel', $order) }}" data-confirm="確定取消這筆模擬訂單並回補庫存？">
        @csrf
        <button class="button button--danger" type="submit">取消訂單並回補庫存</button>
    </form>
@endif
<p><a href="{{ route('admin.orders.index') }}">回訂單列表</a></p>
@endsection
