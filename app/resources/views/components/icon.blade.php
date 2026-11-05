@props(['name'])
{{-- 內嵌 SVG 圖示（不載入外部圖示字型）；裝飾用，對輔助科技隱藏。 --}}
<svg {{ $attributes->class(['icon', 'icon--'.$name]) }} viewBox="0 0 24 24" width="20" height="20" aria-hidden="true" focusable="false" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
    @switch($name)
        @case('play')
        @case('video')
            <path d="M8 5.5v13l11-6.5z" fill="currentColor" stroke="none"/>
            @break
        @case('podcast')
            <path d="M12 3a5 5 0 0 0-5 5v4a5 5 0 0 0 10 0V8a5 5 0 0 0-5-5z"/><path d="M5 11v1a7 7 0 0 0 14 0v-1"/><path d="M12 19v2"/>
            @break
        @case('post')
        @case('page')
            <path d="M7 3h7l5 5v13H7z"/><path d="M14 3v5h5"/><path d="M10 13h6M10 17h6"/>
            @break
        @case('search')
            <circle cx="11" cy="11" r="6.5"/><path d="m20 20-4.2-4.2"/>
            @break
        @case('menu')
            <path d="M4 7h16M4 12h16M4 17h16"/>
            @break
        @case('rss')
            <path d="M5 5a14 14 0 0 1 14 14"/><path d="M5 11a8 8 0 0 1 8 8"/><circle cx="6" cy="18" r="1.5" fill="currentColor"/>
            @break
        @case('arrow')
            <path d="M5 12h14M13 6l6 6-6 6"/>
            @break
        @case('external')
            <path d="M14 4h6v6"/><path d="M20 4 10 14"/><path d="M19 14v6H4V5h6"/>
            @break
        @case('download')
            <path d="M12 4v11"/><path d="m7 10 5 5 5-5"/><path d="M5 20h14"/>
            @break
        @case('alert')
            <path d="M12 4 2.5 20h19z"/><path d="M12 10v4"/><circle cx="12" cy="17" r="0.8" fill="currentColor"/>
            @break
        @default
            <circle cx="12" cy="12" r="8"/>
    @endswitch
</svg>
