@props(['variant' => null])
<span {{ $attributes->class(['badge', 'badge--'.$variant => $variant !== null]) }} @if ($variant === 'sample') data-sample-badge @endif>{{ $slot }}</span>
