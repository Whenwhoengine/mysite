@extends('layouts.app')

@section('body_class', 'page-search')

@section('content')
    <header class="page-header">
        <h1>搜尋</h1>
        <p class="page-header__lede">搜尋站內公開的文章、影片、Podcast 與頁面。</p>
    </header>

    <form class="search-panel" role="search" action="/search" method="get" novalidate>
        <div class="field search-panel__q">
            <label class="field__label" for="search-q">搜尋字詞</label>
            <input id="search-q" class="input" type="search" name="q" value="{{ $q }}" maxlength="100"
                   @if ($error !== null) aria-invalid="true" aria-describedby="search-error" @endif>
        </div>
        <div class="field search-panel__type">
            <label class="field__label" for="search-type">內容類型</label>
            <select id="search-type" class="select" name="type">
                <option value="">全部</option>
                @foreach (\App\Domain\Content\ContentType::cases() as $t)
                    <option value="{{ $t->value }}" @selected($type === $t)>{{ $t->collectionTitle() }}</option>
                @endforeach
            </select>
        </div>
        <button class="button button--primary search-panel__submit" type="submit"><x-icon name="search" />搜尋</button>
    </form>

    @if ($error !== null)
        <x-notice variant="danger" title="無法搜尋" role="alert" id="search-error">{{ $error }}</x-notice>
    @elseif ($q === '')
        <p class="search-hint">輸入關鍵字開始搜尋，例如作品名稱或主題。</p>
    @elseif ($result !== null && $result->isEmpty())
        <div class="empty" role="status">
            <p class="empty__title">找不到符合「{{ $q }}」的內容</p>
            <p>請換個關鍵字、減少字數，或改選「全部」類型再試一次。</p>
        </div>
    @elseif ($result !== null)
        <p class="search-summary" role="status">找到 {{ $result->total }} 則符合「{{ $q }}」的內容</p>
        <ul class="search-results" role="list">
            @foreach ($result->items as $item)
                <li class="search-result">
                    <p class="search-result__type">
                        <span class="type-chip type-chip--{{ $item->type->value }}"><x-icon :name="$item->type->value" />{{ $item->type->label() }}</span>
                        @if ($item->isSample)<x-badge variant="sample">示例內容</x-badge>@endif
                    </p>
                    <h2 class="search-result__title"><a href="{{ $item->path() }}">{{ $item->title }}</a></h2>
                    @if ($item->excerpt !== '')<p class="search-result__excerpt">{{ $item->excerpt }}</p>@endif
                    <p class="search-result__meta"><time datetime="{{ \App\Domain\Content\Support\DisplayTime::iso($item->publishedAt) }}">{{ \App\Domain\Content\Support\DisplayTime::date($item->publishedAt) }}</time></p>
                </li>
            @endforeach
        </ul>
        <x-pagination :page="$result->page" :total-pages="$result->totalPages" path="/search" :query="['q' => $q, 'type' => $type?->value]" />
    @endif
@endsection
