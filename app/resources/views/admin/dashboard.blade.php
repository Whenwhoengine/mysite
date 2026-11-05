@extends('admin.layout')

@section('title', '儀表板')

@section('content')
<h1>儀表板</h1>
<ul class="admin-stats" role="list">
    <li class="admin-stat"><span class="admin-stat__label">商品</span><span class="admin-stat__value">{{ $productCount }}</span><span class="admin-stat__hint">上架中 {{ $activeProductCount }}</span></li>
    <li class="admin-stat"><span class="admin-stat__label">模擬訂單（成立）</span><span class="admin-stat__value">{{ $placedCount }}</span></li>
    <li class="admin-stat"><span class="admin-stat__label">模擬訂單（取消）</span><span class="admin-stat__value">{{ $cancelledCount }}</span></li>
</ul>

<section aria-labelledby="low-stock">
    <h2 id="low-stock">低庫存規格（≤ {{ $lowStockThreshold }}）</h2>
    @if ($lowStock->isEmpty())
        <p class="empty">沒有低庫存規格。</p>
    @else
        <div class="table-wrap"><table class="table">
            <thead><tr><th scope="col">商品</th><th scope="col">規格</th><th scope="col">SKU</th><th scope="col">庫存</th></tr></thead>
            <tbody>
            @foreach ($lowStock as $variant)
                <tr>
                    <td><a href="{{ route('admin.products.edit', $variant->product_id) }}">{{ $variant->product->title }}</a></td>
                    <td>{{ $variant->name }}</td>
                    <td><code>{{ $variant->sku }}</code></td>
                    <td>{{ $variant->stock }}</td>
                </tr>
            @endforeach
            </tbody>
        </table></div>
    @endif
</section>

<section aria-labelledby="recent-audit">
    <h2 id="recent-audit">最近稽核紀錄</h2>
    @include('admin.audit._table', ['logs' => $recentAudit])
    <p><a href="{{ route('admin.audit.index') }}">查看全部稽核紀錄</a></p>
</section>
@endsection
