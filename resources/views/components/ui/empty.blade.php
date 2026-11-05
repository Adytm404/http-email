@props(['title', 'description' => null])

<div {{ $attributes->class('flex flex-col items-center px-6 py-14 text-center') }}>
    <p class="text-[15px] font-semibold">{{ $title }}</p>
    @if ($description)
        <p class="mt-1.5 max-w-sm text-sm text-muted">{{ $description }}</p>
    @endif
    @if ($slot->isNotEmpty())
        <div class="mt-5">{{ $slot }}</div>
    @endif
</div>
