@props(['page', 'totalPages', 'path', 'query' => []])
@php
    $page = (int) $page;
    $totalPages = (int) $totalPages;
    $url = function (int $n) use ($path, $query): string {
        $params = array_filter($query, static fn ($v) => $v !== null && $v !== '');
        if ($n > 1) {
            $params['page'] = $n;
        }
        return $path.($params === [] ? '' : '?'.http_build_query($params, '', '&', PHP_QUERY_RFC3986));
    };
    $window = [];
    foreach ([1, $page - 2, $page - 1, $page, $page + 1, $page + 2, $totalPages] as $n) {
        if ($n >= 1 && $n <= $totalPages) {
            $window[$n] = true;
        }
    }
    ksort($window);
    $previous = null;
@endphp
@if ($totalPages > 1)
    <nav class="pagination-nav" aria-label="分頁">
        <ul class="pagination">
            <li>
                @if ($page > 1)
                    <a href="{{ $url($page - 1) }}" rel="prev">上一頁</a>
                @else
                    <span aria-disabled="true" class="pagination__disabled">上一頁</span>
                @endif
            </li>
            @foreach (array_keys($window) as $n)
                @if ($previous !== null && $n > $previous + 1)
                    <li><span class="pagination__gap" aria-hidden="true">…</span></li>
                @endif
                <li>
                    @if ($n === $page)
                        <span aria-current="page"><span class="visually-hidden">第 </span>{{ $n }}<span class="visually-hidden"> 頁（目前）</span></span>
                    @else
                        <a href="{{ $url($n) }}"><span class="visually-hidden">第 </span>{{ $n }}<span class="visually-hidden"> 頁</span></a>
                    @endif
                </li>
                @php $previous = $n; @endphp
            @endforeach
            <li>
                @if ($page < $totalPages)
                    <a href="{{ $url($page + 1) }}" rel="next">下一頁</a>
                @else
                    <span aria-disabled="true" class="pagination__disabled">下一頁</span>
                @endif
            </li>
        </ul>
        <p class="pagination__status">第 {{ $page }} 頁，共 {{ $totalPages }} 頁</p>
    </nav>
@endif
