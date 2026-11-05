<!doctype html>
<html lang="zh-Hant-TW">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <meta name="referrer" content="same-origin">
    <title>@yield('title') — 管理後臺</title>
    @vite(['resources/css/admin.css', 'resources/js/admin.js'])
    @stack('head')
</head>
<body class="admin">
    <a class="skip-link" href="#main">跳到主要內容</a>
    <header class="admin-header">
        <div class="admin-header__inner">
            <a class="site-brand" href="{{ route('admin.dashboard') }}"><span class="site-brand__mark" aria-hidden="true">▶</span>管理後臺</a>
            @auth
                <nav class="admin-nav" aria-label="後臺導覽">
                    <ul class="admin-nav__list">
                        <li><a class="admin-nav__link" href="{{ route('admin.dashboard') }}" @if (request()->routeIs('admin.dashboard')) aria-current="page" @endif>儀表板</a></li>
                        <li><a class="admin-nav__link" href="{{ route('admin.products.index') }}" @if (request()->routeIs('admin.products.*')) aria-current="page" @endif>商品</a></li>
                        <li><a class="admin-nav__link" href="{{ route('admin.orders.index') }}" @if (request()->routeIs('admin.orders.*')) aria-current="page" @endif>模擬訂單</a></li>
                        <li><a class="admin-nav__link" href="{{ route('admin.audit.index') }}" @if (request()->routeIs('admin.audit.*')) aria-current="page" @endif>稽核紀錄</a></li>
                        <li><a class="admin-nav__link" href="{{ route('admin.users.index') }}" @if (request()->routeIs('admin.users.*')) aria-current="page" @endif>使用者</a></li>
                        <li><a class="admin-nav__link" href="{{ route('admin.security') }}" @if (request()->routeIs('admin.security')) aria-current="page" @endif>帳號安全</a></li>
                    </ul>
                </nav>
                <div class="admin-user">
                    <span>{{ auth()->user()->name }}</span>
                    <span class="badge">{{ auth()->user()->role?->label() }}</span>
                    <form method="post" action="{{ route('logout') }}">
                        @csrf
                        <button class="button" type="submit">登出</button>
                    </form>
                </div>
            @endauth
        </div>
    </header>
    <main id="main" class="admin-main" tabindex="-1">
        @if (\App\Domain\Commerce\CommerceMode::isDisabled())
            <p class="notice notice--info admin-mode">商店模式：<strong>disabled</strong>（前臺商店關閉；後臺可先準備商品）。</p>
        @else
            <p class="notice notice--warning admin-mode">商店模式：<strong>simulation</strong>（模擬操作，不收款、不出貨）。</p>
        @endif
        @include('admin._status')
        @yield('content')
    </main>
    @stack('scripts')
</body>
</html>
