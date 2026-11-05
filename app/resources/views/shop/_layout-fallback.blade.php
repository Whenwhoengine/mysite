{{--
    商店暫用最小版型：只在共用版型 layouts.app 尚未存在時使用（由整合者統一後即不再被載入）。
    與 layouts.app 相同的介面：@yield('title')、@yield('content')、@stack('head')、@stack('scripts')。
--}}
<!doctype html>
<html lang="zh-Hant-TW">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>@yield('title') — {{ config('mysite.site_name', 'mysite') }}</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    @stack('head')
</head>
<body>
    <a class="skip-link" href="#main">跳到主要內容</a>
    <header class="site-header">
        <div class="container site-header__inner">
            <a class="site-brand" href="{{ url('/') }}"><span class="site-brand__mark" aria-hidden="true">▶</span>{{ config('mysite.site_name', 'mysite') }}</a>
            <nav class="site-nav" aria-label="商店導覽">
                <ul class="site-nav__list">
                    <li><a class="site-nav__link" href="{{ route('shop.index') }}" @if (request()->routeIs('shop.*')) aria-current="page" @endif>模擬商店</a></li>
                    <li><a class="site-nav__link" href="{{ route('cart.show') }}" @if (request()->routeIs('cart.*')) aria-current="page" @endif>購物車</a></li>
                </ul>
            </nav>
        </div>
    </header>
    <main id="main" class="site-main container" tabindex="-1">
        @yield('content')
    </main>
    <footer class="site-footer">
        <div class="container"><p>模擬商店僅供展示：不收款、不出貨，不收集真實地址、電話或付款資料。</p></div>
    </footer>
    @stack('scripts')
</body>
</html>
