@php
    $currentPath = '/'.ltrim(request()->path(), '/');
    $isCurrent = static function (string $url) use ($currentPath): bool {
        if (! str_starts_with($url, '/')) {
            return false;
        }
        $path = (string) parse_url($url, PHP_URL_PATH);
        return $path === '/' ? $currentPath === '/' : ($currentPath === $path || str_starts_with($currentPath, rtrim($path, '/').'/'));
    };
@endphp
<header class="site-header" data-site-header>
    <div class="container site-header__inner">
        <a class="site-brand" href="{{ url('/') }}">
            <span class="site-brand__mark" aria-hidden="true"><x-icon name="play" /></span>
            <span class="site-brand__name">{{ $siteName }}</span>
            <span class="visually-hidden">（回首頁）</span>
        </a>
        <button type="button" class="button site-nav__toggle" data-site-nav-toggle aria-controls="site-nav" aria-expanded="false">
            <x-icon name="menu" /><span>選單</span>
        </button>
        <nav id="site-nav" class="site-nav" data-site-nav aria-label="主選單">
            <ul class="site-nav__list" data-site-nav-list>
                @foreach ($primaryMenu as $menuItem)
                    <li>
                        <a class="site-nav__link" href="{{ $menuItem->url }}" data-site-nav-link
                           @if ($menuItem->external) rel="noopener noreferrer" @endif
                           @if ($isCurrent($menuItem->url)) aria-current="page" @endif>{{ $menuItem->label }}@if ($menuItem->external)<x-icon name="external" class="icon--inline" /><span class="visually-hidden">（外部網站）</span>@endif</a>
                    </li>
                @endforeach
                @if ($commerceEnabled ?? false)
                    <li><a class="site-nav__link site-nav__link--shop" href="{{ url('/shop') }}" data-site-nav-link data-site-nav-shop @if ($isCurrent('/shop')) aria-current="page" @endif>商店</a></li>
                @endif
            </ul>
            <form class="site-search" role="search" action="{{ url('/search') }}" method="get">
                <label class="visually-hidden" for="site-search-q">搜尋站內內容</label>
                <input id="site-search-q" class="input site-search__input" type="search" name="q" maxlength="100" placeholder="搜尋文章、影片、Podcast" autocomplete="off">
                <button class="button site-search__button" type="submit"><x-icon name="search" /><span class="visually-hidden">搜尋</span></button>
            </form>
        </nav>
    </div>
</header>
