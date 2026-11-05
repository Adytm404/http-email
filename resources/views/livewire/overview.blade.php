<div wire:poll.15s>
    <x-ui.page-header eyebrow="Overview" title="Mail traffic at a glance" description="Everything sent through the API in the last seven days.">
        <x-slot:actions>
            <x-ui.button variant="secondary" :href="route('docs')">API quickstart</x-ui.button>
            <x-ui.button :href="route('providers')">Add provider</x-ui.button>
        </x-slot:actions>
    </x-ui.page-header>

    <div class="grid gap-4 lg:grid-cols-[1.15fr_1fr]">
        {{-- Headline numbers --}}
        <div class="panel-gradient rounded-3xl p-4 sm:p-5">
            <div class="grid grid-cols-2 gap-3">
                <div class="glass col-span-2 rounded-2xl p-5">
                    <p class="text-[13px] text-white/75">Delivered today</p>
                    <p class="mt-5 text-[56px] font-light leading-none tracking-tight tabular-nums">{{ number_format($sentToday) }}</p>
                    <p class="mt-2 text-[13px] text-white/70">{{ number_format($failedToday) }} failed today</p>
                </div>
                <div class="glass rounded-2xl p-5">
                    <p class="text-[13px] text-white/75">Success rate, 7 days</p>
                    <p class="mt-6 text-[34px] font-light leading-none tabular-nums">{{ $successRate !== null ? $successRate.'%' : '—' }}</p>
                </div>
                <div class="glass rounded-2xl p-5">
                    <p class="text-[13px] text-white/75">In queue</p>
                    <p class="mt-6 text-[34px] font-light leading-none tabular-nums">{{ number_format($pending) }}</p>
                </div>
            </div>
        </div>

        {{-- 7-day chart --}}
        <x-ui.card>
            <div class="flex items-baseline justify-between">
                <h2 class="text-[15px] font-semibold">Last 7 days</h2>
                <div class="flex items-center gap-4 text-[12px] text-muted">
                    <span class="flex items-center gap-1.5"><span class="size-2 rounded-sm bg-accent"></span>Sent</span>
                    <span class="flex items-center gap-1.5"><span class="size-2 rounded-sm bg-bad"></span>Failed</span>
                </div>
            </div>
            <div class="mt-6 flex h-48 items-end gap-2 sm:gap-3" role="img" aria-label="Emails per day for the last 7 days">
                @foreach ($days as $d)
                    @php $total = $d['sent'] + $d['failed']; @endphp
                    <div class="group flex h-full flex-1 flex-col items-center justify-end gap-2">
                        <div class="relative flex w-full max-w-10 flex-1 flex-col justify-end">
                            <span @class([
                                'pointer-events-none absolute -top-1 hidden -translate-y-full whitespace-nowrap rounded-md bg-ink px-2 py-1 text-[11px] text-canvas group-hover:block',
                                'left-0' => $loop->index < 2,
                                'right-0' => $loop->index > 4,
                                'left-1/2 -translate-x-1/2' => $loop->index >= 2 && $loop->index <= 4,
                            ])>
                                {{ $d['date'] }} · {{ $d['sent'] }} sent · {{ $d['failed'] }} failed
                            </span>
                            @if ($total === 0)
                                <div class="h-1 rounded-sm bg-surface-2"></div>
                            @else
                                @if ($d['failed'])<div class="rounded-t-sm bg-bad" style="height: {{ $d['failed'] / $maxDay * 100 }}%"></div>@endif
                                <div @class(['bg-accent', 'rounded-t-sm' => ! $d['failed']]) style="height: {{ $d['sent'] / $maxDay * 100 }}%"></div>
                            @endif
                        </div>
                        <span class="text-[11px] text-muted">{{ $d['label'] }}</span>
                    </div>
                @endforeach
            </div>
        </x-ui.card>
    </div>

    <div class="mt-4 grid gap-4 lg:grid-cols-[1fr_1.15fr]">
        {{-- Providers --}}
        <x-ui.card :padding="false">
            <div class="flex items-center justify-between px-5 pb-2 pt-5 sm:px-6">
                <h2 class="text-[15px] font-semibold">Providers</h2>
                <a href="{{ route('providers') }}" wire:navigate class="text-[13px] text-accent hover:underline">Manage</a>
            </div>
            @forelse ($providers as $p)
                @php $share = ($byProvider[$p->id] ?? 0) / $weekSent * 100; @endphp
                <div class="flex items-center gap-3 border-t border-line px-5 py-3.5 first-of-type:border-t-0 sm:px-6">
                    <x-ui.provider-icon :type="$p->type" />
                    <div class="min-w-0 flex-1">
                        <div class="flex items-center gap-2">
                            <p class="truncate text-sm font-medium">{{ $p->name }}</p>
                            @unless ($p->is_active)<x-ui.badge>Paused</x-ui.badge>@endunless
                        </div>
                        <div class="mt-1.5 h-1 overflow-hidden rounded-full bg-surface-2">
                            <div class="h-full rounded-full bg-accent" style="width: {{ $share }}%"></div>
                        </div>
                    </div>
                    <span class="w-16 text-right text-[13px] tabular-nums text-muted">{{ number_format($byProvider[$p->id] ?? 0) }}</span>
                    <x-ui.status :status="$p->health" class="hidden sm:inline-flex" />
                </div>
            @empty
                <x-ui.empty title="No providers yet" description="Connect an SMTP server or an email API to start sending.">
                    <x-ui.button :href="route('providers')" size="sm">Add provider</x-ui.button>
                </x-ui.empty>
            @endforelse
        </x-ui.card>

        {{-- Recent --}}
        <x-ui.card :padding="false">
            <div class="flex items-center justify-between px-5 pb-2 pt-5 sm:px-6">
                <h2 class="text-[15px] font-semibold">Recent emails</h2>
                <a href="{{ route('emails') }}" wire:navigate class="text-[13px] text-accent hover:underline">View all</a>
            </div>
            @forelse ($recent as $e)
                <a href="{{ route('emails.show', $e) }}" wire:navigate class="flex items-center gap-3 border-t border-line px-5 py-3 transition-colors first-of-type:border-t-0 hover:bg-surface-2/60 sm:px-6">
                    <div class="min-w-0 flex-1">
                        <p class="truncate text-sm font-medium">{{ $e->subject }}</p>
                        <p class="truncate text-[12.5px] text-muted">{{ implode(', ', $e->to) }}</p>
                    </div>
                    <span class="hidden text-[12.5px] text-muted sm:block">{{ $e->provider?->name }}</span>
                    <x-ui.status :status="$e->status" />
                </a>
            @empty
                <x-ui.empty title="Nothing sent yet" description="Create an API key and send your first email." >
                    <x-ui.button :href="route('docs')" size="sm" variant="secondary">Read the quickstart</x-ui.button>
                </x-ui.empty>
            @endforelse
        </x-ui.card>
    </div>
</div>
