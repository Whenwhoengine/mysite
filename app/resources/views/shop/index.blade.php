@extends(view()->exists('layouts.app') ? 'layouts.app' : 'shop._layout-fallback')

@section('title', '模擬商店')

@section('content')
<div class="shop">
    <header class="shop__header">
        <h1>模擬商店</h1>
        <p class="shop__lead">展示用商品，所有流程皆為模擬。</p>
    </header>

    @include('shop._sim-notice')
    @include('shop._flash')

    @if ($products->isEmpty())
        <p class="empty">目前沒有上架中的商品。</p>
    @else
        <ul class="shop-grid" role="list">
            @foreach ($products as $product)
                <li class="card shop-card">
                    <div class="card__media">
                        @if ($product->cover_url)
                            <img src="{{ $product->cover_url }}" alt="" loading="lazy" decoding="async">
                        @else
                            <div class="shop-card__placeholder" aria-hidden="true">{{ mb_substr($product->title, 0, 1) }}</div>
                        @endif
                    </div>
                    <div class="card__body">
                        <h2 class="card__title"><a href="{{ route('shop.show', $product->slug) }}">{{ $product->title }}</a></h2>
                        <p class="card__meta">
                            @if ($product->is_sample)<span class="badge badge--sample">示例</span>@endif
                            <span class="shop-price">{{ \App\Domain\Commerce\MoneyFormatter::format($product->price) }}</span>
                        </p>
                        @if ($product->variants->sum('stock') === 0)
                            <p class="shop-stock shop-stock--out">已售完</p>
                        @endif
                    </div>
                </li>
            @endforeach
        </ul>
        {{ $products->links('shop._pagination') }}
    @endif
</div>
@endsection
