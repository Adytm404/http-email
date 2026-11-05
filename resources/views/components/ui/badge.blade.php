@props(['tone' => 'neutral', 'dot' => false])

@php
    $tones = [
        'neutral' => 'bg-surface-2 text-muted',
        'accent' => 'bg-accent-soft text-accent',
        'ok' => 'bg-ok-soft text-ok',
        'warn' => 'bg-warn-soft text-warn',
        'bad' => 'bg-bad-soft text-bad',
    ];
@endphp

<span {{ $attributes->class('inline-flex items-center gap-1.5 rounded-full px-2 py-0.5 text-[12px] font-medium '.$tones[$tone]) }}>
    @if ($dot)<span class="size-1.5 rounded-full bg-current" aria-hidden="true"></span>@endif
    {{ $slot }}
</span>
