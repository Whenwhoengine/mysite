{{-- Podcast：使用者主動播放（preload="none"）；來源不在 allowlist 時不播放並保留簡介。 --}}
<figure {{ $attributes->class(['player', 'player--audio', 'is-broken' => $src === null]) }} data-audio-player>
    <div class="player__audio">
        <x-image :image="$cover" ratio="square" placeholder="Podcast" class="player__art" :preview="$preview" :eager="true" />
        <div class="player__audio-body">
            <p class="player__eyebrow"><x-icon name="podcast" />Podcast</p>
            <p class="player__audio-title">{{ $title }}</p>
            @if ($src !== null)
                <audio class="player__audio-control" controls preload="none" aria-label="收聽：{{ $title }}" @if ($mime === null) src="{{ $src }}" @endif>
                    @if ($mime !== null)
                        <source src="{{ $src }}" type="{{ $mime }}">
                    @endif
                    <p>您的瀏覽器不支援內嵌音訊播放，請<a href="{{ $src }}" download>下載音訊檔</a>收聽。</p>
                </audio>
                <p class="player__audio-meta">
                    @if ($duration !== null)
                        <span>片長 <time datetime="{{ $durationIso }}" data-audio-duration><span aria-hidden="true">{{ $duration }}</span><span class="visually-hidden">{{ $durationSpoken }}</span></time></span>
                    @endif
                    <a href="{{ $src }}" download data-audio-download><x-icon name="download" class="icon--inline" />下載音訊@if ($mimeLabel())（{{ $mimeLabel() }}）@endif</a>
                </p>
                <p class="player__error" data-audio-error hidden>這個音訊目前無法播放。您可以改用上方的下載連結，或閱讀下方的節目簡介。</p>
            @else
                <div class="player__state player__state--inline" role="note" data-audio-unavailable>
                    <p class="player__state-title">音訊目前無法播放</p>
                    <p>音訊來源缺少或不在允許清單內，因此不提供播放；下方保留完整節目簡介。</p>
                </div>
            @endif
        </div>
    </div>
</figure>
