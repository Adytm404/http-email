@props(['title', 'close', 'wide' => false])

{{-- Rendered only while open (wrap in @if). $close is the Livewire expression that closes it. --}}
<div class="fixed inset-0 z-40 flex items-end justify-center bg-slate-950/40 p-0 backdrop-blur-[2px] sm:items-center sm:p-6"
     x-data x-on:keydown.escape.window="$wire.{{ $close }}" role="dialog" aria-modal="true" aria-label="{{ $title }}">
    <div class="absolute inset-0" wire:click="{{ $close }}"></div>
    <div @class([
        'relative flex max-h-[92vh] w-full flex-col overflow-hidden rounded-t-3xl border border-line bg-surface shadow-2xl sm:rounded-3xl',
        'sm:max-w-2xl' => $wide,
        'sm:max-w-lg' => ! $wide,
    ])>
        <div class="flex items-center justify-between border-b border-line px-6 py-4">
            <h2 class="text-[16px] font-semibold">{{ $title }}</h2>
            <button type="button" wire:click="{{ $close }}" class="grid size-8 place-items-center rounded-[4px] text-muted hover:bg-surface-2 hover:text-ink" aria-label="Close">
                <svg class="size-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M6 6l12 12M18 6 6 18"/></svg>
            </button>
        </div>
        <div class="overflow-y-auto px-6 py-5">
            {{ $slot }}
        </div>
        @isset($footer)
            <div class="flex justify-end gap-2 border-t border-line bg-canvas/60 px-6 py-4">{{ $footer }}</div>
        @endisset
    </div>
</div>
