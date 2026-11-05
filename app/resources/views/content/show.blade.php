@extends('layouts.app')

@section('body_class', 'page-entry page-entry--'.$item->type->value)

@section('content')
    @if ($item->type !== \App\Domain\Content\ContentType::Page)
        <nav class="breadcrumb" aria-label="麵包屑">
            <ol>
                <li><a href="/">首頁</a></li>
                <li><a href="/{{ $item->type->pathPrefix() }}">{{ $item->type->collectionTitle() }}</a></li>
                <li aria-current="page"><span>{{ \Illuminate\Support\Str::limit($item->title, 40, '…') }}</span></li>
            </ol>
        </nav>
    @endif
    @include('content.partials.entry', ['item' => $item, 'preview' => false])
@endsection
