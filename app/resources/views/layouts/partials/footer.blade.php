<footer class="site-footer" data-site-footer>
    <div class="container site-footer__grid">
        <section class="site-footer__brand" aria-labelledby="footer-brand">
            <p id="footer-brand" class="site-footer__name">
                <span class="site-brand__mark" aria-hidden="true"><x-icon name="play" /></span>{{ $siteName }}
                @if ($site?->isSample)<x-badge variant="sample">示例內容</x-badge>@endif
            </p>
            @if ($site !== null && $site->tagline !== '')
                <p class="site-footer__tagline">{{ $site->tagline }}</p>
            @endif
            @if ($site !== null && $site->authorBio !== '')
                <p class="site-footer__bio">{{ $site->authorBio }}</p>
            @endif
        </section>
        <nav class="site-footer__nav" aria-label="頁尾選單">
            <ul class="site-footer__links">
                @php $footerUrls = array_map(static fn ($m) => $m->url, $footerMenu); @endphp
                @foreach ($footerMenu as $menuItem)
                    <li><a href="{{ $menuItem->url }}" @if ($menuItem->external) rel="noopener noreferrer" @endif>{{ $menuItem->label }}@if ($menuItem->external)<x-icon name="external" class="icon--inline" /><span class="visually-hidden">（外部網站）</span>@endif</a></li>
                @endforeach
                @if (! in_array('/search', $footerUrls, true))
                    <li><a href="{{ url('/search') }}">搜尋</a></li>
                @endif
                @if (! in_array('/feed.xml', $footerUrls, true))
                    <li><a href="{{ url('/feed.xml') }}"><x-icon name="rss" class="icon--inline" />RSS</a></li>
                @endif
            </ul>
            @if ($site !== null && $site->socialLinks !== [])
                <ul class="site-footer__social" aria-label="社群連結">
                    @foreach ($site->socialLinks as $link)
                        <li><a href="{{ $link['url'] }}" rel="noopener noreferrer me">{{ $link['label'] }}<x-icon name="external" class="icon--inline" /><span class="visually-hidden">（外部網站）</span></a></li>
                    @endforeach
                </ul>
            @endif
        </nav>
    </div>
    <div class="container site-footer__legal">
        <p>© {{ now()->setTimezone(config('mysite.display_timezone', 'Asia/Taipei'))->year }} {{ $siteName }}。播放影片時才會連線到 YouTube；音訊由本站內容系統提供。</p>
    </div>
</footer>
