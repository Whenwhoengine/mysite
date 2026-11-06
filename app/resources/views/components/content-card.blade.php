@props(['item', 'level' => 3, 'variant' => null])
@php
    /** @var \App\Domain\Content\Dto\ContentItem $item */
    $duration = \App\Domain\Content\Support\DisplayTime::duration($item->video?->durationSeconds ?? $item->podcast?->durationSeconds);
    $durationSpoken = \App\Domain\Content\Support\DisplayTime::durationSpoken($item->video?->durationSeconds ?? $item->podcast?->durationSeconds);
    $level = max(2, min(4, (int) $level));
@endphp
<article {{ $attributes->class(['card', 'content-card', 'content-card--'.$item->type->value, 'content-card--'.$variant => $variant !== null]) }} data-content-card>
    <div class="content-card__media">
        <x-image :image="$item->cover" :ratio="$item->type === \App\Domain\Content\ContentType::Podcast && $variant !== 'feature' ? 'square' : 'wide'" :placeholder="$item->type->label()" :eager="$variant === 'feature'" />
        @if ($item->type === \App\Domain\Content\ContentType::Video)
            <span class="content-card__play" aria-hidden="true"><x-icon name="play" /></span>
        @endif
        @if ($duration !== null)
            <span class="content-card__duration"><span class="visually-hidden">片長 {{ $durationSpoken }}</span><span aria-hidden="true">{{ $duration }}</span></span>
        @endif
    </div>
    <div class="card__body content-card__body">
        <p class="content-card__type">
            <span class="type-chip type-chip--{{ $item->type->value }}"><x-icon :name="$item->type->value" />{{ $item->type->label() }}</span>
            @if ($item->isSample)<x-badge variant="sample">示例內容</x-badge>@endif
        </p>
        <h{{ $level }} class="card__title"><a href="{{ $item->path() }}" data-content-link>{{ $item->title }}</a></h{{ $level }}>
        @if ($item->excerpt !== '')
            <p class="card__excerpt">{{ $item->excerpt }}</p>
        @endif
        <p class="card__meta">
            <time datetime="{{ \App\Domain\Content\Support\DisplayTime::iso($item->publishedAt) }}">{{ \App\Domain\Content\Support\DisplayTime::date($item->publishedAt) }}</time>
            @if ($item->authorName)<span>{{ $item->authorName }}</span>@endif
        </p>
    </div>
</article>
