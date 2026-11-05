{{-- YouTube：驗證過的 11 碼 ID → youtube-nocookie.com；點擊後才載入 iframe（app.js）。 --}}
<figure {{ $attributes->class(['player', 'player--video', 'is-broken' => $videoId === null]) }}
    @if ($videoId !== null && ! $preview) data-youtube-player data-embed-src="{{ $embedUrl() }}" data-title="{{ $title }}" @endif>
    @if ($videoId !== null && ! $preview)
        <div class="player__frame">
            <x-image :image="$poster" placeholder="" class="player__poster" :eager="true" />
            <a class="player__start" href="{{ $watchUrl() }}" data-youtube-start rel="noopener noreferrer">
                <span class="player__start-icon" aria-hidden="true"><x-icon name="play" /></span>
                <span class="player__start-label">播放影片<span class="visually-hidden">：{{ $title }}</span></span>
            </a>
        </div>
        <figcaption class="player__caption">
            點擊播放後才會連線到 YouTube（youtube-nocookie.com）並載入播放器。
            @if ($duration !== null)片長 <time datetime="{{ $durationIso }}">{{ $duration }}</time>。@endif
            <a href="{{ $watchUrl() }}" rel="noopener noreferrer">在 YouTube 開啟<x-icon name="external" class="icon--inline" /></a>
        </figcaption>
        <noscript>
            <p class="player__noscript">瀏覽器未啟用 JavaScript，無法在本頁嵌入播放；請<a href="{{ $watchUrl() }}" rel="noopener noreferrer">在 YouTube 觀看這部影片</a>。</p>
        </noscript>
    @elseif ($videoId !== null)
        <div class="player__frame player__frame--static">
            <div class="player__state">
                <p class="player__state-title">預覽模式不嵌入外部播放器</p>
                <p>為避免在預覽頁載入第三方資源，這裡只提供連結。</p>
                <p><a href="{{ $watchUrl() }}" rel="noopener noreferrer">在 YouTube 開啟影片（{{ $videoId }}）</a></p>
            </div>
        </div>
    @else
        <div class="player__frame player__frame--static" role="note">
            <div class="player__state">
                <x-icon name="alert" class="player__state-icon" />
                <p class="player__state-title">影片目前無法播放</p>
                <p>影片識別碼缺少或格式不正確，因此不嵌入播放器；下方仍可閱讀完整說明。</p>
            </div>
        </div>
    @endif
</figure>
