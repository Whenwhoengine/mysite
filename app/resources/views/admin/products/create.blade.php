@extends('admin.layout')

@section('title', '新增商品')

@section('content')
<h1>新增商品</h1>
<form class="form" method="post" action="{{ route('admin.products.store') }}">
    @csrf
    @include('admin.products._fields', ['product' => null, 'canWrite' => true])
    <div class="field">
        <label class="field__label" for="sku">預設規格 SKU</label>
        <input class="input" id="sku" name="sku" maxlength="64" pattern="[A-Z0-9][A-Z0-9-]{0,63}" required value="{{ old('sku') }}">
    </div>
    <div class="field">
        <label class="field__label" for="initial_stock">初始庫存</label>
        <input class="input" id="initial_stock" name="initial_stock" type="number" min="0" required value="{{ old('initial_stock', 0) }}">
    </div>
    <button class="button button--primary" type="submit">建立商品</button>
</form>
@endsection
