@extends('layouts.app')

@section('body_class', 'page-taxonomy')

@php
    $types = \App\Domain\Content\ContentType::listable();
@endphp

@section('content')
    <header class="page-header">
        <p class="page-header__eyebrow">{{ $label }}</p>
        <h1 data-page-title>{{ $kind === 'tag' ? '#' : '' }}{{ $term->name }}</h1>
        @if ($term->count !== null)
            <p class="page-header__count">共 {{ $term->count }} 則公開內容</p>
        @endif
    </header>

    <nav class="tabs" aria-label="依內容類型篩選">
        <ul>
            <li><a href="{{ $basePath }}" @if ($type === null) aria-current="page" @endif>全部</a></li>
            @foreach ($types as $t)
                <li><a href="{{ $basePath }}?type={{ $t->value }}" @if ($type === $t) aria-current="page" @endif>{{ $t->collectionTitle() }}</a></li>
            @endforeach
        </ul>
    </nav>

    @if ($overview !== null)
        @php $nothing = collect($overview)->every(fn ($p) => $p->isEmpty()); @endphp
        @if ($nothing)
            <div class="empty">
                <p class="empty__title">這個{{ $label }}目前沒有公開內容</p>
                <p><a href="/">回首頁</a>或<a href="/search">搜尋其他內容</a>。</p>
            </div>
        @endif
        @foreach ($types as $t)
            @php $section = $overview[$t->value]; @endphp
            @if (! $section->isEmpty())
                <section class="section" aria-labelledby="tax-{{ $t->value }}">
                    <div class="section__header">
                        <h2 id="tax-{{ $t->value }}" class="section__title"><x-icon :name="$t->value" />{{ $t->collectionTitle() }}</h2>
                        @if ($section->total > $section->perPage)
                            <a class="section__more" href="{{ $basePath }}?type={{ $t->value }}">查看全部 {{ $section->total }} 則<x-icon name="arrow" class="icon--inline" /></a>
                        @endif
                    </div>
                    <ul class="grid {{ $t === \App\Domain\Content\ContentType::Podcast ? 'grid--podcast' : '' }}" role="list">
                        @foreach ($section->items as $item)
                            <li><x-content-card :item="$item" :variant="$t === \App\Domain\Content\ContentType::Podcast ? 'row' : null" /></li>
                        @endforeach
                    </ul>
                </section>
            @endif
        @endforeach
    @else
        @if ($result->isEmpty())
            <div class="empty">
                <p class="empty__title">這個{{ $label }}目前沒有公開的{{ $type->label() }}</p>
                <p><a href="{{ $basePath }}">查看全部類型</a></p>
            </div>
        @else
            <ul class="grid {{ $type === \App\Domain\Content\ContentType::Podcast ? 'grid--podcast' : '' }}" role="list">
                @foreach ($result->items as $item)
                    <li><x-content-card :item="$item" :level="2" :variant="$type === \App\Domain\Content\ContentType::Podcast ? 'row' : null" /></li>
                @endforeach
            </ul>
            <x-pagination :page="$result->page" :total-pages="$result->totalPages" :path="$basePath" :query="['type' => $type->value]" />
        @endif
    @endif
@endsection
