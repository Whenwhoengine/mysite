@extends('admin.layout')

@section('title', '編輯商品：'.$product->title)

@php($fmt = static fn ($m) => \App\Domain\Commerce\MoneyFormatter::format($m))

@section('content')
<h1>{{ $canWrite ? '編輯商品' : '商品詳情' }}：{{ $product->title }}</h1>
@unless ($canWrite)
    <p class="notice notice--info">您是唯讀人員，只能檢視。</p>
@endunless

<form class="form" method="post" action="{{ route('admin.products.update', $product) }}">
    @csrf
    @method('PUT')
    @include('admin.products._fields', ['product' => $product, 'canWrite' => $canWrite])
    @if ($canWrite)
        <button class="button button--primary" type="submit">儲存商品</button>
    @endif
</form>

<section aria-labelledby="variants-heading">
    <h2 id="variants-heading">規格與庫存</h2>
    <div class="table-wrap"><table class="table">
        <thead><tr><th scope="col">SKU</th><th scope="col">名稱／價格／啟用</th><th scope="col">庫存</th><th scope="col">庫存調整</th></tr></thead>
        <tbody>
        @foreach ($product->variants as $variant)
            <tr>
                <td><code>{{ $variant->sku }}</code>@if ($variant->is_default) <span class="badge">預設</span>@endif</td>
                <td>
                    @if ($canWrite)
                        <form class="admin-inline-form" method="post" action="{{ route('admin.variants.update', $variant) }}">
                            @csrf
                            @method('PUT')
                            <label class="visually-hidden" for="vname-{{ $variant->id }}">規格名稱</label>
                            <input class="input" id="vname-{{ $variant->id }}" name="name" maxlength="120" required value="{{ $variant->name }}">
                            <label class="visually-hidden" for="vprice-{{ $variant->id }}">價格</label>
                            <input class="input admin-input--short" id="vprice-{{ $variant->id }}" name="price" inputmode="decimal" pattern="\d{1,10}(\.\d{1,2})?" required value="{{ $variant->price->toDecimal() }}">
                            <input type="hidden" name="is_active" value="0">
                            <label><input type="checkbox" name="is_active" value="1" @checked($variant->is_active)> 啟用</label>
                            <button class="button" type="submit">儲存</button>
                        </form>
                    @else
                        {{ $variant->name }}／{{ $fmt($variant->price) }}／{{ $variant->is_active ? '啟用' : '停用' }}
                    @endif
                </td>
                <td>{{ $variant->stock }}</td>
                <td>
                    @if ($canWrite)
                        <form class="admin-inline-form" method="post" action="{{ route('admin.variants.stock', $variant) }}">
                            @csrf
                            <label class="visually-hidden" for="delta-{{ $variant->id }}">增減數量</label>
                            <input class="input admin-input--short" id="delta-{{ $variant->id }}" name="delta" type="number" required placeholder="+5 或 -2">
                            <label class="visually-hidden" for="note-{{ $variant->id }}">備註</label>
                            <input class="input" id="note-{{ $variant->id }}" name="note" maxlength="200" placeholder="備註（選填，勿填個資）">
                            <button class="button" type="submit">調整</button>
                        </form>
                    @else
                        —
                    @endif
                </td>
            </tr>
        @endforeach
        </tbody>
    </table></div>

    @if ($canWrite)
        <h3>新增規格</h3>
        <form class="form" method="post" action="{{ route('admin.variants.store', $product) }}">
            @csrf
            <div class="field"><label class="field__label" for="new-sku">SKU</label><input class="input" id="new-sku" name="sku" maxlength="64" pattern="[A-Z0-9][A-Z0-9-]{0,63}" required></div>
            <div class="field"><label class="field__label" for="new-name">規格名稱</label><input class="input" id="new-name" name="name" maxlength="120" required></div>
            <div class="field"><label class="field__label" for="new-price">價格</label><input class="input" id="new-price" name="price" inputmode="decimal" pattern="\d{1,10}(\.\d{1,2})?" required></div>
            <div class="field"><label class="field__label" for="new-stock">初始庫存</label><input class="input" id="new-stock" name="initial_stock" type="number" min="0" value="0" required></div>
            <button class="button" type="submit">新增規格</button>
        </form>
    @endif
</section>

<section aria-labelledby="mutations-heading">
    <h2 id="mutations-heading">最近庫存異動（只追加）</h2>
    @if ($mutations->isEmpty())
        <p class="empty">尚無異動。</p>
    @else
        <div class="table-wrap"><table class="table">
            <thead><tr><th scope="col">時間</th><th scope="col">規格</th><th scope="col">增減</th><th scope="col">異動後</th><th scope="col">原因</th><th scope="col">訂單</th><th scope="col">備註</th></tr></thead>
            <tbody>
            @foreach ($mutations as $m)
                <tr>
                    <td>{{ $m->created_at->setTimezone(config('mysite.display_timezone'))->format('Y-m-d H:i') }}</td>
                    <td>#{{ $m->product_variant_id }}</td>
                    <td>{{ $m->delta > 0 ? '+'.$m->delta : $m->delta }}</td>
                    <td>{{ $m->stock_after }}</td>
                    <td>{{ $m->reasonLabel() }}</td>
                    <td>@if ($m->order_id)<a href="{{ route('admin.orders.show', $m->order_id) }}">#{{ $m->order_id }}</a>@else — @endif</td>
                    <td>{{ $m->note }}</td>
                </tr>
            @endforeach
            </tbody>
        </table></div>
    @endif
</section>
@endsection
