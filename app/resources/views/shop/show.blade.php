@extends(view()->exists('layouts.app') ? 'layouts.app' : 'shop._layout-fallback')

@section('title', $product->title.'｜模擬商店')

@section('content')
<article class="shop shop-product">
    <nav class="shop-breadcrumb" aria-label="麵包屑"><a href="{{ route('shop.index') }}">模擬商店</a> / <span aria-current="page">{{ $product->title }}</span></nav>

    @include('shop._sim-notice')
    @include('shop._flash')

    <div class="shop-product__layout">
        <div class="shop-product__media">
            @if ($product->cover_url)
                <img src="{{ $product->cover_url }}" alt="{{ $product->title }} 封面">
            @else
                <div class="shop-card__placeholder shop-card__placeholder--large" aria-hidden="true">{{ mb_substr($product->title, 0, 1) }}</div>
            @endif
        </div>
        <div class="shop-product__info">
            <h1>{{ $product->title }} @if ($product->is_sample)<span class="badge badge--sample">示例</span>@endif</h1>
            @if ($product->description)
                <div class="shop-product__description">
                    @foreach (preg_split('/\R{2,}/u', $product->description) as $paragraph)
                        <p>{{ $paragraph }}</p>
                    @endforeach
                </div>
            @endif

            @if ($product->variants->isEmpty())
                <p class="empty">此商品目前沒有可購買的規格。</p>
            @else
                <form class="form shop-add" method="post" action="{{ route('cart.store') }}">
                    @csrf
                    <fieldset class="field shop-variants">
                        <legend class="field__label">規格</legend>
                        @foreach ($product->variants as $variant)
                            <label class="shop-variant">
                                <input type="radio" name="variant_id" value="{{ $variant->id }}" @checked($loop->first && $variant->stock > 0) @disabled($variant->stock === 0) required>
                                <span class="shop-variant__name">{{ $variant->name }}</span>
                                <span class="shop-price">{{ \App\Domain\Commerce\MoneyFormatter::format($variant->price) }}</span>
                                <span class="shop-stock {{ $variant->stock === 0 ? 'shop-stock--out' : '' }}">{{ $variant->stock === 0 ? '已售完' : '庫存 '.$variant->stock }}</span>
                            </label>
                        @endforeach
                    </fieldset>
                    <div class="field">
                        <label class="field__label" for="quantity">數量</label>
                        <input class="input shop-qty" id="quantity" name="quantity" type="number" inputmode="numeric" min="1" max="{{ $maxQuantity }}" value="1" required>
                        <span class="field__hint">每項商品最多 {{ $maxQuantity }} 件。</span>
                    </div>
                    <button class="button button--primary" type="submit">加入購物車（模擬）</button>
                </form>
            @endif
        </div>
    </div>
</article>
@endsection
