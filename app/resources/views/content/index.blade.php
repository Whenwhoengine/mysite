@extends('layouts.app')

@section('body_class', 'page-list page-list--'.$type->value)

@section('content')
    <header class="page-header">
        <p class="page-header__eyebrow"><span class="type-chip type-chip--{{ $type->value }}"><x-icon :name="$type->value" />{{ $type->label() }}</span></p>
        <h1>{{ $type->collectionTitle() }}@if ($result->page > 1)<span class="page-header__page">第 {{ $result->page }} 頁</span>@endif</h1>
        @if ($type->collectionLede() !== '')
            <p class="page-header__lede">{{ $type->collectionLede() }}</p>
        @endif
        @if ($result->total > 0)
            <p class="page-header__count">共 {{ $result->total }} 則</p>
        @endif
    </header>

    @if ($result->isEmpty())
        <div class="empty">
            <p class="empty__title">目前沒有公開的{{ $type->label() }}</p>
            <p>內容發布後會出現在這裡。<a href="/">回首頁</a></p>
        </div>
    @else
        <ul class="grid {{ $type === \App\Domain\Content\ContentType::Podcast ? 'grid--podcast' : ($type === \App\Domain\Content\ContentType::Video ? 'grid--media' : '') }}" role="list">
            @foreach ($result->items as $item)
                <li><x-content-card :item="$item" :level="2" :variant="$type === \App\Domain\Content\ContentType::Podcast ? 'row' : null" /></li>
            @endforeach
        </ul>
        <x-pagination :page="$result->page" :total-pages="$result->totalPages" :path="'/'.$type->pathPrefix()" />
    @endif
@endsection
