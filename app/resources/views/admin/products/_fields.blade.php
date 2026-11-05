{{-- 商品欄位（新增與編輯共用）。$product 可能為 null。 --}}
<div class="field">
    <label class="field__label" for="title">標題</label>
    <input class="input" id="title" name="title" maxlength="200" required value="{{ old('title', $product?->title) }}" @disabled(! $canWrite)>
</div>
<div class="field">
    <label class="field__label" for="slug">代稱（網址）</label>
    <input class="input" id="slug" name="slug" maxlength="120" pattern="[a-z0-9]+(-[a-z0-9]+)*" required value="{{ old('slug', $product?->slug) }}" @disabled(! $canWrite)>
    <span class="field__hint">小寫英數字與連字號，例如 sample-mug。</span>
</div>
<div class="field">
    <label class="field__label" for="description">描述</label>
    <textarea class="textarea" id="description" name="description" rows="5" maxlength="5000" @disabled(! $canWrite)>{{ old('description', $product?->description) }}</textarea>
</div>
<div class="field">
    <label class="field__label" for="price">基準價（新臺幣）</label>
    <input class="input" id="price" name="price" inputmode="decimal" pattern="\d{1,10}(\.\d{1,2})?" required value="{{ old('price', $product?->price?->toDecimal()) }}" @disabled(! $canWrite)>
    <span class="field__hint">列表顯示用；結帳以各規格價格計算。</span>
</div>
<div class="field">
    <label class="field__label" for="status">狀態</label>
    <select class="select" id="status" name="status" @disabled(! $canWrite)>
        @foreach ($statuses as $status)
            <option value="{{ $status->value }}" @selected(old('status', $product?->status?->value ?? 'draft') === $status->value)>{{ $status->label() }}</option>
        @endforeach
    </select>
</div>
<div class="field">
    <label class="field__label" for="cover_url">封面（https 網址或站內路徑，選填）</label>
    <input class="input" id="cover_url" name="cover_url" maxlength="500" value="{{ old('cover_url', $product?->cover_url) }}" @disabled(! $canWrite)>
</div>
