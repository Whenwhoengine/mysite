@extends('admin.layout')

@section('title', '商品')

@section('content')
<div class="admin-toolbar">
    <h1>商品</h1>
    @can('create', \App\Models\Product::class)
        <a class="button button--primary" href="{{ route('admin.products.create') }}">新增商品</a>
    @endcan
</div>
@if ($products->isEmpty())
    <p class="empty">尚無商品。</p>
@else
    <div class="table-wrap"><table class="table">
        <thead><tr><th scope="col">ID</th><th scope="col">標題</th><th scope="col">代稱</th><th scope="col">狀態</th><th scope="col">基準價</th><th scope="col">規格數</th><th scope="col">總庫存</th></tr></thead>
        <tbody>
        @foreach ($products as $product)
            <tr>
                <td>{{ $product->id }}</td>
                <td><a href="{{ route('admin.products.edit', $product) }}">{{ $product->title }}</a> @if ($product->is_sample)<span class="badge badge--sample">示例</span>@endif</td>
                <td><code>{{ $product->slug }}</code></td>
                <td>{{ $product->status->label() }}</td>
                <td>{{ \App\Domain\Commerce\MoneyFormatter::format($product->price) }}</td>
                <td>{{ $product->variants_count }}</td>
                <td>{{ (int) $product->stock_total }}</td>
            </tr>
        @endforeach
        </tbody>
    </table></div>
    {{ $products->links('shop._pagination') }}
@endif
@endsection
