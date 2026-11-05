@extends(view()->exists('layouts.app') ? 'layouts.app' : 'shop._layout-fallback')

@section('title', '模擬結帳')

@php($fmt = static fn ($m) => \App\Domain\Commerce\MoneyFormatter::format($m))

@section('content')
<div class="shop shop-checkout">
    <h1>模擬結帳</h1>
    @include('shop._sim-notice')
    @include('shop._flash')

    @if ($cart->hasPriceChanges())
        <div class="notice notice--info" role="status" data-price-changed>
            <p class="notice__title">價格已更新</p>
            <p>部分商品價格已變動，下方為最新價格；確認後再送出。</p>
        </div>
    @endif

    <section aria-labelledby="summary-heading">
        <h2 id="summary-heading">訂單摘要</h2>
        <div class="table-wrap">
            <table class="table">
                <thead><tr><th scope="col">商品</th><th scope="col">單價</th><th scope="col">數量</th><th scope="col">小計</th></tr></thead>
                <tbody>
                @foreach ($cart->lines as $line)
                    <tr>
                        <td>{{ $line->variant?->product->title }}<span class="shop-cart__variant">{{ $line->variant?->name }}</span></td>
                        <td>{{ $line->unitPrice ? $fmt($line->unitPrice) : '—' }}</td>
                        <td>{{ $line->line->quantity }}</td>
                        <td>{{ $line->lineTotal ? $fmt($line->lineTotal) : '—' }}</td>
                    </tr>
                @endforeach
                </tbody>
                <tfoot><tr><th scope="row" colspan="3">總計（模擬，不收款）</th><td class="shop-total">{{ $fmt($cart->subtotal) }}</td></tr></tfoot>
            </table>
        </div>
    </section>

    <form class="form shop-checkout__form" method="post" action="{{ route('checkout.store') }}" data-checkout-form>
        @csrf
        <input type="hidden" name="idempotency_key" value="{{ $idempotencyKey }}">
        <div class="field">
            <label class="field__label" for="buyer_code">測試代號（選填）</label>
            <input class="input" id="buyer_code" name="buyer_code" type="text" maxlength="20" pattern="[A-Za-z0-9-]{1,20}" autocomplete="off" spellcheck="false" placeholder="例如 TEST-001" value="{{ old('buyer_code') }}">
            <span class="field__hint">只用來辨識測試訂單的合成代號，請勿輸入姓名、電話、地址或任何個人資料。</span>
        </div>
        <p class="field__hint">本頁不收集信用卡、付款、收件地址或電話資料。送出後只會建立模擬訂單，不會收款或出貨。</p>
        <button class="button button--primary" type="submit" data-submit-once>送出模擬訂單</button>
    </form>
</div>
@endsection
