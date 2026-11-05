@props(['title', 'eyebrow' => null, 'description' => null])

<div class="mb-8 flex flex-col gap-4 sm:flex-row sm:items-end sm:justify-between">
    <div class="min-w-0">
        @if ($eyebrow)
            <p class="eyebrow mb-2">{{ $eyebrow }}</p>
        @endif
        <h1 class="text-[28px] font-semibold leading-tight tracking-tight sm:text-[32px]">{{ $title }}</h1>
        @if ($description)
            <p class="mt-2 max-w-2xl text-[15px] leading-relaxed text-muted">{{ $description }}</p>
        @endif
    </div>
    @isset($actions)
        <div class="flex shrink-0 flex-wrap items-center gap-2">{{ $actions }}</div>
    @endisset
</div>
