{{--
    全站版型（內容工作包）。其他區塊沿用同一介面：
      @section('title') 頁面標題（自動加上「｜站名」）、@section('description')、@section('robots')、
      @section('content') 主要內容、@section('banner') 頁首下方橫幅、@stack('head')、@stack('scripts')。
    內容頁另以 $meta（App\Domain\Content\Seo\PageMeta）提供 title／description／canonical／Open Graph。
    $siteName、$primaryMenu、$footerMenu、$site、$commerceEnabled 由 ContentServiceProvider 的 view composer 提供。
--}}
@php
    /** @var \App\Domain\Content\Seo\PageMeta|null $meta */
    $meta = $meta ?? null;
    $siteName = $siteName ?? (string) config('mysite.site_name', 'mysite');
    $canonical = $meta?->canonical;
    $robots = ($meta?->noindex ?? false) ? 'noindex, nofollow' : null;
    $ogTitle = $meta !== null && $meta->title !== '' ? $meta->title : $siteName;
@endphp
<!DOCTYPE html>
<html lang="zh-Hant-TW">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>@hasSection('title')@yield('title')｜{{ $siteName }}@else{{ $meta?->fullTitle($siteName) ?? $siteName }}@endif</title>
    @hasSection('description')
        <meta name="description" content="@yield('description')">
    @elseif ($meta !== null && $meta->description !== '')
        <meta name="description" content="{{ $meta->description }}">
    @endif
    @hasSection('robots')
        <meta name="robots" content="@yield('robots')">
    @elseif ($robots !== null)
        <meta name="robots" content="{{ $robots }}">
    @endif
    @if ($canonical !== null)
        <link rel="canonical" href="{{ $canonical }}">
        <meta property="og:url" content="{{ $canonical }}">
    @endif
    @if ($meta !== null)
        <meta property="og:type" content="{{ $meta->ogType }}">
        <meta property="og:title" content="{{ $ogTitle }}">
        <meta property="og:site_name" content="{{ $siteName }}">
        <meta property="og:locale" content="zh_TW">
        @if ($meta->description !== '')<meta property="og:description" content="{{ $meta->description }}">@endif
        @if ($meta->ogImage !== null)
            <meta property="og:image" content="{{ $meta->ogImage }}">
            @if ($meta->ogImageAlt)<meta property="og:image:alt" content="{{ $meta->ogImageAlt }}">@endif
            <meta name="twitter:card" content="summary_large_image">
        @else
            <meta name="twitter:card" content="summary">
        @endif
    @endif
    <meta name="theme-color" content="#0f1115">
    <meta name="color-scheme" content="dark">
    <link rel="alternate" type="application/rss+xml" title="{{ $siteName }}" href="{{ url('/feed.xml') }}">
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    @stack('head')
</head>
<body class="@yield('body_class')">
    <a class="skip-link" href="#main">跳到主要內容</a>
    @include('layouts.partials.header')
    @yield('banner')
    <main id="main" class="site-main" tabindex="-1">
        <div class="container">
            @yield('content')
        </div>
    </main>
    @include('layouts.partials.footer')
    @stack('scripts')
</body>
</html>
