{{-- 分頁（商店與後臺共用；樣式見 components.css 的 .pagination）。 --}}
@if ($paginator->hasPages())
    <nav aria-label="分頁">
        <ul class="pagination">
            <li>
                @if ($paginator->onFirstPage())
                    <span aria-disabled="true">上一頁</span>
                @else
                    <a href="{{ $paginator->previousPageUrl() }}" rel="prev">上一頁</a>
                @endif
            </li>
            @foreach ($elements as $element)
                @if (is_string($element))
                    <li><span aria-disabled="true">{{ $element }}</span></li>
                @endif
                @if (is_array($element))
                    @foreach ($element as $page => $url)
                        <li>
                            @if ($page == $paginator->currentPage())
                                <span aria-current="page">{{ $page }}</span>
                            @else
                                <a href="{{ $url }}">{{ $page }}</a>
                            @endif
                        </li>
                    @endforeach
                @endif
            @endforeach
            <li>
                @if ($paginator->hasMorePages())
                    <a href="{{ $paginator->nextPageUrl() }}" rel="next">下一頁</a>
                @else
                    <span aria-disabled="true">下一頁</span>
                @endif
            </li>
        </ul>
    </nav>
@endif
