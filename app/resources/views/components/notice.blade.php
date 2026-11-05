@props(['variant' => 'info', 'title' => null, 'role' => null])
<div {{ $attributes->class(['notice', 'notice--'.$variant]) }} @if ($role) role="{{ $role }}" @endif>
    @if ($title !== null)
        <p class="notice__title">
            @if (in_array($variant, ['warning', 'danger'], true))<x-icon name="alert" class="notice__icon" />@endif
            {{ $title }}
        </p>
    @endif
    <div class="notice__body">{{ $slot }}</div>
</div>
