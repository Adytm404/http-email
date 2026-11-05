@props(['variant' => 'primary', 'size' => 'md', 'href' => null, 'type' => 'button'])

@php
    $base = 'inline-flex items-center justify-center gap-1.5 rounded-[4px] font-medium transition-colors disabled:cursor-not-allowed disabled:opacity-50 whitespace-nowrap';
    $sizes = [
        'sm' => 'h-8 px-3 text-[13px]',
        'md' => 'h-9 px-4 text-sm',
        'lg' => 'h-11 px-5 text-[15px]',
    ];
    $variants = [
        'primary' => 'bg-accent text-white hover:bg-accent-hover',
        'secondary' => 'border border-line bg-surface text-ink hover:bg-surface-2',
        'ghost' => 'text-muted hover:bg-surface-2 hover:text-ink',
        'danger' => 'border border-line bg-surface text-bad hover:bg-bad-soft',
        'white' => 'bg-white text-[#1d4ed8] hover:bg-white/90 rounded-full',
    ];
    $classes = $base.' '.$sizes[$size].' '.$variants[$variant];
@endphp

@if ($href)
    <a href="{{ $href }}" wire:navigate {{ $attributes->class($classes) }}>{{ $slot }}</a>
@else
    <button type="{{ $type }}" {{ $attributes->class($classes) }}>{{ $slot }}</button>
@endif
