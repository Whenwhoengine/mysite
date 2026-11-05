@extends(view()->exists('layouts.app') ? 'layouts.app' : 'shop._layout-fallback')

@section('title', '購物車｜模擬商店')

@php($fmt = static fn ($m) => \App\Domain\Commerce\MoneyFormatter::format($m))

@section('content')
<div class="shop shop-cart">
    <h1>購物車</h1>
    @include('shop._sim-notice')
    @include('shop._flash')

    @if ($cart->hasPriceChanges())
        <div class="notice notice--info" role="status" data-price-changed>
            <p class="notice__title">價格已更新</p>
            <p>部分商品價格在您加入購物車後有變動，以下已改為最新價格；結帳一律以伺服器上的現價計算。</p>
        </div>
    @endif

    @if ($cart->isEmpty())
        <p class="empty">購物車是空的。<a href="{{ route('shop.index') }}">前往模擬商店</a></p>
    @else
        <div class="table-wrap">
            <table class="table shop-cart__table">
                <caption class="visually-hidden">購物車內容（最多 {{ $maxLines }} 個品項）</caption>
                <thead>
                    <tr><th scope="col">商品</th><th scope="col">單價</th><th scope="col">數量</th><th scope="col">小計</th><th scope="col"><span class="visually-hidden">操作</span></th></tr>
                </thead>
                <tbody>
                @foreach ($cart->lines as $line)
                    <tr @class(['shop-cart__row--issue' => $line->blocking()])>
                        <td>
                            @if ($line->variant)
                                <a href="{{ route('shop.show', $line->variant->product->slug) }}">{{ $line->variant->product->title }}</a>
                                <span class="shop-cart__variant">{{ $line->variant->name }}</span>
                            @else
                                <span>已移除的商品</span>
                            @endif
                            @if ($line->unavailable)<p class="field__error">此商品已下架或無法購買，請移除。</p>@endif
                            @if ($line->insufficientStock)<p class="field__error">庫存不足（剩 {{ $line->variant?->stock ?? 0 }} 件）。</p>@endif
                            @if ($line->priceChanged)<p class="field__hint">價格已更新為最新價格。</p>@endif
                        </td>
                        <td>{{ $line->unitPrice ? $fmt($line->unitPrice) : '—' }}</td>
                        <td>
                            <form class="shop-cart__qty" method="post" action="{{ route('cart.update', $line->line->variantId) }}">
                                @csrf
                                @method('PATCH')
                                <label class="visually-hidden" for="qty-{{ $line->line->variantId }}">數量</label>
                                <input class="input shop-qty" id="qty-{{ $line->line->variantId }}" name="quantity" type="number" inputmode="numeric" min="1" max="{{ $maxQuantity }}" value="{{ $line->line->quantity }}" required>
                                <button class="button" type="submit">更新</button>
                            </form>
                        </td>
                        <td>{{ $line->lineTotal ? $fmt($line->lineTotal) : '—' }}</td>
                        <td>
                            <form method="post" action="{{ route('cart.destroy', $line->line->variantId) }}">
                                @csrf
                                @method('DELETE')
                                <button class="button button--danger" type="submit">移除</button>
                            </form>
                        </td>
                    </tr>
                @endforeach
                </tbody>
                <tfoot>
                    <tr><th scope="row" colspan="3">小計（伺服器計算）</th><td colspan="2" class="shop-total">{{ $fmt($cart->subtotal) }}</td></tr>
                </tfoot>
            </table>
        </div>
        <p class="shop-cart__actions">
            <a class="button" href="{{ route('shop.index') }}">繼續逛逛</a>
            @if (! $cart->hasBlockingIssues())
                <a class="button button--primary" href="{{ route('checkout.show') }}">前往模擬結帳</a>
            @endif
        </p>
    @endif
</div>
@endsection
