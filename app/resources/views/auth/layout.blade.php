<!doctype html>
<html lang="zh-Hant-TW">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <meta name="referrer" content="no-referrer">
    <title>@yield('title') — 管理後臺</title>
    @vite(['resources/css/admin.css', 'resources/js/admin.js'])
</head>
<body class="admin admin--auth">
    <main id="main" class="auth-card" tabindex="-1">
        <p class="site-brand"><span class="site-brand__mark" aria-hidden="true">▶</span>管理後臺</p>
        <h1>@yield('title')</h1>
        @include('admin._status')
        @yield('content')
        <p class="field__hint auth-card__note">此後臺帳號與 WordPress 內容管理帳號互不相通；沒有公開註冊。</p>
    </main>
</body>
</html>
