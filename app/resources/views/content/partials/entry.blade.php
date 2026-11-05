@php
    /** @var \App\Domain\Content\Dto\ContentItem $item */
    $preview = $preview ?? false;
    $type = $item->type;
    $T = \App\Domain\Content\Support\DisplayTime::class;
@endphp
<article class="entry entry--{{ $type->value }}" aria-labelledby="entry-title">
    <header class="entry__header">
        <p class="entry__eyebrow">
            @if ($type !== \App\Domain\Content\ContentType::Page)
                <a class="type-chip type-chip--{{ $type->value }}" href="/{{ $type->pathPrefix() }}"><x-icon :name="$type->value" />{{ $type->label() }}</a>
            @endif
            @if ($item->isSample)<x-badge variant="sample">示例內容</x-badge>@endif
            @if ($preview)<x-badge variant="accent">預覽</x-badge>@endif
        </p>
        <h1 id="entry-title" class="entry__title">{{ $item->title !== '' ? $item->title : '（未命名）' }}</h1>
        @if ($item->excerpt !== '' && $type !== \App\Domain\Content\ContentType::Page)
            <p class="entry__lede">{{ $item->excerpt }}</p>
        @endif
        @if ($type !== \App\Domain\Content\ContentType::Page)
            <p class="entry__meta">
                <time datetime="{{ $T::iso($item->publishedAt) }}">{{ $T::date($item->publishedAt) }}</time>
                @if ($item->modifiedAt->greaterThan($item->publishedAt->addDay()))
                    <span>更新於 <time datetime="{{ $T::iso($item->modifiedAt) }}">{{ $T::date($item->modifiedAt) }}</time></span>
                @endif
                @if ($item->authorName)<span>作者：{{ $item->authorName }}</span>@endif
            </p>
        @endif
    </header>

    @if ($type === \App\Domain\Content\ContentType::Video)
        <x-youtube-player :video="$item->video" :title="$item->title" :poster="$item->cover" :preview="$preview" class="entry__player" />
    @elseif ($type === \App\Domain\Content\ContentType::Podcast)
        <x-audio-player :podcast="$item->podcast" :title="$item->title" :cover="$item->cover" :preview="$preview" class="entry__player" />
    @elseif ($item->cover !== null)
        <figure class="entry__cover">
            <x-image :image="$item->cover" :eager="true" :preview="$preview" sizes="(min-width: 1200px) 1100px, 100vw" />
            @if ($item->cover->alt !== '')<figcaption class="visually-hidden">{{ $item->cover->alt }}</figcaption>@endif
        </figure>
    @endif

    <div class="entry__body">
        @if ($item->body !== null && ! $item->body->isEmpty())
            <x-rich-content :content="$item->body" />
        @else
            <p class="empty">這個內容目前沒有內文。</p>
        @endif
    </div>

    @if ($item->categories !== [] || $item->tags !== [])
        <footer class="entry__footer">
            @if ($item->categories !== [])
                <div class="term-list">
                    <h2 class="term-list__title">分類</h2>
                    <ul class="chips">
                        @foreach ($item->categories as $term)
                            <li><a class="chip" href="/categories/{{ rawurlencode($term->slug) }}">{{ $term->name }}</a></li>
                        @endforeach
                    </ul>
                </div>
            @endif
            @if ($item->tags !== [])
                <div class="term-list">
                    <h2 class="term-list__title">標籤</h2>
                    <ul class="chips">
                        @foreach ($item->tags as $term)
                            <li><a class="chip chip--tag" href="/tags/{{ rawurlencode($term->slug) }}">#{{ $term->name }}</a></li>
                        @endforeach
                    </ul>
                </div>
            @endif
        </footer>
    @endif
</article>
