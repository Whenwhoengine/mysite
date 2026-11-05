@extends('layouts.app', ['isPreview' => true])

@section('title', '預覽：'.($item->title !== '' ? $item->title : '（未命名）'))
@section('robots', 'noindex, nofollow')
@section('body_class', 'page-preview')

@push('head')
    <meta name="referrer" content="no-referrer">
@endpush

@section('banner')
    <div class="preview-banner" role="status">
        <div class="container preview-banner__inner">
            <span class="preview-banner__label">預覽</span>
            <p class="preview-banner__text">
                這是尚未公開的草稿快照（修訂 #{{ $revision }}），只供編輯檢視，請勿分享此頁。
                預覽於 <time datetime="{{ \App\Domain\Content\Support\DisplayTime::iso($expiresAt) }}">{{ \App\Domain\Content\Support\DisplayTime::dateTime($expiresAt) }}</time> 失效；修改後請回到編輯器重新產生預覽。
            </p>
        </div>
    </div>
@endsection

@section('content')
    @include('content.partials.entry', ['item' => $item, 'preview' => true])
@endsection
