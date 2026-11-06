@extends('layouts.app')

@section('body_class', 'page-home')

@php
    $allEmpty = $posts->isEmpty() && $videos->isEmpty() && $podcasts->isEmpty();
@endphp

@section('content')
    <section class="hero" aria-labelledby="hero-title">
        <div class="hero__intro">
            <p class="hero__eyebrow">影片．Podcast．文章</p>
            <h1 id="hero-title" class="hero__title" data-page-title>{{ $site?->name ?? $siteName ?? config('mysite.site_name') }}</h1>
            @if ($site !== null && $site->tagline !== '')
                <p class="hero__tagline">{{ $site->tagline }}</p>
            @endif
            <div class="hero__actions">
                <a class="button button--primary" href="/videos"><x-icon name="play" />看影片</a>
                <a class="button" href="/podcasts"><x-icon name="podcast" />聽 Podcast</a>
                <a class="button" href="/articles"><x-icon name="post" />讀文章</a>
            </div>
            @if ($site?->isSample)
                <p class="hero__sample"><x-badge variant="sample">示例內容</x-badge> 目前顯示的是示例資料，正式內容發布後會自動取代。</p>
            @endif
        </div>
        @if ($featured !== null)
            <div class="hero__feature">
                <p class="hero__feature-label">最新發布</p>
                <x-content-card :item="$featured" :level="2" variant="feature" />
            </div>
        @endif
    </section>

    @if ($allEmpty)
        <x-notice variant="info" title="還沒有公開內容">
            內容發布後會出現在這裡。您也可以稍後再回來看看。
        </x-notice>
    @endif

    <section class="section section--videos" aria-labelledby="home-videos">
        <div class="section__header">
            <h2 id="home-videos" class="section__title"><x-icon name="video" />最新影片</h2>
            <a class="section__more" href="/videos">全部影片<x-icon name="arrow" class="icon--inline" /></a>
        </div>
        @if ($videos->isEmpty())
            <p class="empty">目前沒有公開的影片。</p>
        @else
            <ul class="grid grid--media" role="list">
                @foreach ($videos->items as $item)
                    <li><x-content-card :item="$item" /></li>
                @endforeach
            </ul>
        @endif
    </section>

    <section class="section section--podcasts" aria-labelledby="home-podcasts">
        <div class="section__header">
            <h2 id="home-podcasts" class="section__title"><x-icon name="podcast" />Podcast</h2>
            <a class="section__more" href="/podcasts">全部節目<x-icon name="arrow" class="icon--inline" /></a>
        </div>
        @if ($podcasts->isEmpty())
            <p class="empty">目前沒有公開的 Podcast 節目。</p>
        @else
            <ul class="grid grid--podcast" role="list">
                @foreach ($podcasts->items as $item)
                    <li><x-content-card :item="$item" variant="row" /></li>
                @endforeach
            </ul>
        @endif
    </section>

    <section class="section section--posts" aria-labelledby="home-posts">
        <div class="section__header">
            <h2 id="home-posts" class="section__title"><x-icon name="post" />最新文章</h2>
            <a class="section__more" href="/articles">全部文章<x-icon name="arrow" class="icon--inline" /></a>
        </div>
        @if ($posts->isEmpty())
            <p class="empty">目前沒有公開的文章。</p>
        @else
            <ul class="grid" role="list">
                @foreach ($posts->items as $item)
                    <li><x-content-card :item="$item" /></li>
                @endforeach
            </ul>
        @endif
    </section>
@endsection
