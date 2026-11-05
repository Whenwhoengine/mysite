@extends(view()->exists('layouts.app') ? 'layouts.app' : 'shop._layout-fallback')

@section('title', '模擬訂單 '.$order->displayNumber())

@push('head')
    <meta name="robots" content="noindex, nofollow">
    <meta name="referrer" content="no-referrer">
@endpush

@php($fmt = static fn ($m) => \App\Domain\Commerce\MoneyFormatter::format($m))

@section('content')
<div class="shop shop-order">
    <h1>模擬訂單 {{ $order->displayNumber() }}</h1>
    @include('shop._sim-notice')
    @include('shop._flash')

    <dl class="shop-order__meta">
        <div><dt>狀態</dt><dd><span class="badge {{ $order->status === \App\Models\OrderStatus::Placed ? 'badge--accent' : '' }}">{{ $order->status->label() }}</span></dd></div>
        <div><dt>建立時間</dt><dd><time datetime="{{ $order->placed_at->toIso8601String() }}">{{ $order->placed_at->setTimezone(config('mysite.display_timezone'))->format('Y-m-d H:i') }}</time></dd></div>
        @if ($order->cancelled_at)
            <div><dt>取消時間</dt><dd><time datetime="{{ $order->cancelled_at->toIso8601String() }}">{{ $order->cancelled_at->setTimezone(config('mysite.display_timezone'))->format('Y-m-d H:i') }}</time></dd></div>
        @endif
        @if ($order->buyer_code)
            <div><dt>測試代號</dt><dd>{{ $order->buyer_code }}</dd></div>
        @endif
        <div><dt>付款</dt><dd>未收款（模擬）</dd></div>
        <div><dt>出貨</dt><dd>不出貨（模擬）</dd></div>
    </dl>

    <div class="table-wrap">
        <table class="table">
            <caption class="visually-hidden">訂單明細（下單當下的價格快照）</caption>
            <thead><tr><th scope="col">商品</th><th scope="col">SKU</th><th scope="col">單價</th><th scope="col">數量</th><th scope="col">小計</th></tr></thead>
            <tbody>
            @foreach ($order->items as $item)
                <tr>
                    <td>{{ $item->product_title }}<span class="shop-cart__variant">{{ $item->variant_name }}</span></td>
                    <td><code>{{ $item->sku }}</code></td>
                    <td>{{ $fmt($item->unit_price) }}</td>
                    <td>{{ $item->quantity }}</td>
                    <td>{{ $fmt($item->line_total) }}</td>
                </tr>
            @endforeach
            </tbody>
            <tfoot><tr><th scope="row" colspan="4">總計（模擬，不收款）</th><td class="shop-total">{{ $fmt($order->total) }}</td></tr></tfoot>
        </table>
    </div>

    <p class="field__hint">請保存本頁網址；網址只在建立訂單的同一個瀏覽器工作階段內有效，他人取得也無法查看。</p>

    @if ($canCancel)
        <form method="post" action="{{ route('orders.cancel', ['token' => $token]) }}" data-confirm="確定要取消這筆模擬訂單嗎？">
            @csrf
            <button class="button button--danger" type="submit">取消模擬訂單</button>
        </form>
    @endif
    <p><a href="{{ route('shop.index') }}">回模擬商店</a></p>
</div>
@endsection
