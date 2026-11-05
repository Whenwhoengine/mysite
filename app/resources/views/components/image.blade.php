{{-- 封面圖：比例固定（避免 CLS）、延後載入；無封面或來源不合政策時顯示佔位。 --}}
<div {{ $attributes->class(['media-frame', 'media-frame--'.$ratio, 'media-frame--empty' => $src === null]) }}>
    @if ($src !== null)
        <img src="{{ $src }}" alt="{{ $image->alt }}"
             @if ($image->width && $image->height) width="{{ $image->width }}" height="{{ $image->height }}" @endif
             sizes="{{ $sizes }}"
             loading="{{ $eager ? 'eager' : 'lazy' }}" decoding="async" data-media-img>
        <span class="media-frame__fallback" aria-hidden="true">圖片無法載入</span>
    @else
        <span class="media-frame__placeholder" @if ($placeholder === '') aria-hidden="true" @endif>{{ $placeholder }}</span>
    @endif
</div>
