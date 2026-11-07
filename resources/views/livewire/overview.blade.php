<div wire:poll.30s.visible>
    @php
        $hour = now()->hour;
        $greeting = $hour < 11 ? 'Good morning' : ($hour < 15 ? 'Good afternoon' : ($hour < 19 ? 'Good evening' : 'Good night'));
        $statusMeta = [
            'sent' => ['Delivered', 'var(--c-accent)'],
            'failed' => ['Failed', 'var(--c-bad)'],
            'queued' => ['Queued', 'var(--c-warn)'],
            'sending' => ['Sending', '#8b5cf6'],
            'scheduled' => ['Scheduled', 'var(--c-subtle)'],
            'canceled' => ['Canceled', 'var(--c-line)'],
        ];
    @endphp

    <div class="mb-8 flex flex-col gap-4 xl:flex-row xl:items-end xl:justify-between">
        <div>
            <p class="eyebrow mb-2">Overview</p>
            <h1 class="text-[28px] font-semibold leading-tight tracking-tight sm:text-[34px]">{{ $greeting }}, {{ strtok(auth()->user()->name, ' ') }}</h1>
            <p class="mt-2 text-[15px] text-muted">{{ $range === 1 ? 'Mail traffic today.' : 'Mail traffic for the last '.$ranges[$range].'.' }}</p>
        </div>
        <div class="flex flex-wrap items-center gap-2">
            <div class="inline-flex rounded-lg border border-line bg-surface p-0.5" role="group" aria-label="Time range">
                @foreach ($ranges as $value => $label)
                    <button type="button" wire:click="$set('range', {{ $value }})" @class([
                        'rounded-md px-3 py-1.5 text-[13px] transition-colors',
                        'bg-accent text-white shadow-sm' => $range === $value,
                        'text-muted hover:text-ink' => $range !== $value,
                    ]) aria-pressed="{{ $range === $value ? 'true' : 'false' }}">{{ $label }}</button>
                @endforeach
            </div>
            @can('send_emails')
                <x-ui.button :href="route('compose')"><x-ui.icon name="send" class="size-4" /> Send email</x-ui.button>
            @endcan
        </div>
    </div>

    {{-- Headline --}}
    <div class="grid grid-cols-1 gap-4 xl:grid-cols-[1.25fr_1fr]">
        <div class="panel-gradient anim-fade-up relative overflow-hidden rounded-3xl p-6 sm:p-7">
            <div class="flex items-start justify-between gap-4">
                <div>
                    <p class="text-[13px] text-white/75">Delivered</p>
                    <p class="mt-4 text-[56px] font-light leading-none tracking-tight tabular-nums sm:text-[72px]"><x-ui.count :value="$sent" id="sent" /></p>
                </div>
                @if ($trend !== null)
                    <span class="glass rounded-full px-3 py-1 text-[12.5px]">{{ $trend >= 0 ? '▲' : '▼' }} {{ abs($trend) }}% vs {{ $range === 1 ? 'yesterday so far' : 'previous '.$ranges[$range] }}</span>
                @endif
            </div>
            {{-- Sparkline of delivered per day --}}
            @php
                $maxSent = max(1, $days->max('sent'));
                $count = $days->count();
                $points = $days->values()->map(fn ($d, $i) => round($count > 1 ? $i / ($count - 1) * 100 : 50, 2).','.round(38 - $d['sent'] / $maxSent * 34, 2))->implode(' ');
            @endphp
            <svg viewBox="0 0 100 40" preserveAspectRatio="none" class="mt-6 h-20 w-full" aria-hidden="true">
                <defs>
                    <linearGradient id="spark" x1="0" y1="0" x2="0" y2="1">
                        <stop offset="0" stop-color="#fff" stop-opacity=".35" />
                        <stop offset="1" stop-color="#fff" stop-opacity="0" />
                    </linearGradient>
                </defs>
                <polygon points="0,40 {{ $points }} 100,40" fill="url(#spark)" class="anim-fade-up" style="animation-delay: .3s" />
                <polyline points="{{ $points }}" fill="none" stroke="#fff" stroke-width="1.6" vector-effect="non-scaling-stroke" stroke-linejoin="round" stroke-linecap="round"
                          class="anim-reveal-x" />
            </svg>        </div>

        <div class="grid grid-cols-2 gap-4">
            @foreach ([
                ['Success rate', $successRate, 1, '%', 'check', 'ok'],
                ['Failed', $failed, 0, '', 'alert', 'bad'],
                ['In queue now', $pending, 0, '', 'clock', 'warn'],
                ['Avg. delivery', $avgSeconds, 1, 's', 'send', 'accent'],
            ] as $i => [$label, $value, $dec, $suffix, $icon, $tone])
                <x-ui.card class="anim-fade-up flex flex-col justify-between" style="animation-delay: {{ 80 + $i * 60 }}ms">
                    <div class="flex items-center justify-between">
                        <p class="text-[13px] text-muted">{{ $label }}</p>
                        <span class="grid size-8 place-items-center rounded-lg bg-{{ $tone }}-soft text-{{ $tone }}"><x-ui.icon :name="$icon" class="size-4" /></span>
                    </div>
                    <p class="mt-6 text-[32px] font-light leading-none tabular-nums">
                        @if ($value === null) — @else <x-ui.count :value="$value" :decimals="$dec" :suffix="$suffix" :id="$label" /> @endif
                    </p>
                </x-ui.card>
            @endforeach
        </div>
    </div>

    {{-- Volume chart + status donut --}}
    <div class="mt-4 grid grid-cols-1 gap-4 xl:grid-cols-[1fr_340px]">
        <x-ui.card wire:key="chart-{{ $range }}">
            <div class="flex flex-wrap items-baseline justify-between gap-2">
                <div>
                    <h2 class="text-[15px] font-semibold">Volume per {{ $hourly ? 'hour' : 'day' }}</h2>
                    <p class="text-[13px] text-muted">{{ number_format($total) }} emails accepted {{ $range === 1 ? 'today' : 'in this period' }}</p>
                </div>
                <div class="flex items-center gap-4 text-[12px] text-muted">
                    <span class="flex items-center gap-1.5"><span class="size-2.5 rounded-sm bg-accent"></span>Delivered</span>
                    <span class="flex items-center gap-1.5"><span class="size-2.5 rounded-sm bg-bad"></span>Failed</span>
                </div>
            </div>

            <div class="mt-6 flex gap-3">
                {{-- Y axis --}}
                <div class="relative h-[232px] w-8 shrink-0 text-right text-[11px] tabular-nums text-subtle" aria-hidden="true">
                    @for ($v = 4; $v >= 0; $v--)
                        <span class="absolute right-0 -translate-y-1/2 leading-none" style="top: {{ (4 - $v) * 25 }}%">{{ number_format($axis['step'] * $v) }}</span>
                    @endfor
                </div>
                <div class="relative min-w-0 flex-1">
                    {{-- Gridlines --}}
                    <div class="pointer-events-none absolute inset-x-0 top-0 flex h-[232px] flex-col justify-between">
                        @for ($v = 0; $v < 5; $v++)<div class="border-t border-dashed border-line"></div>@endfor
                    </div>
                    <div class="relative flex h-64 items-end gap-[3px] sm:gap-1.5" role="img" aria-label="Emails per {{ $hourly ? 'hour' : 'day' }}">
                        @foreach ($days as $i => $d)
                            @php $edge = $i < 2 ? 'left-0' : ($i > $days->count() - 3 ? 'right-0' : 'left-1/2 -translate-x-1/2'); @endphp
                            <div class="group relative flex h-full min-w-0 flex-1 flex-col items-center justify-end">
                                <div class="relative flex h-[232px] w-full max-w-12 flex-col justify-end">
                                    <div class="anim-grow-y flex flex-col justify-end overflow-hidden rounded-t-md transition-opacity group-hover:opacity-80" style="height: {{ ($d['sent'] + $d['failed']) / $axis['top'] * 100 }}%; animation-delay: {{ $i * 35 }}ms">
                                        @if ($d['failed'])<div class="bg-bad" style="height: {{ $d['failed'] / max(1, $d['sent'] + $d['failed']) * 100 }}%"></div>@endif
                                        <div class="flex-1 bg-accent"></div>
                                    </div>
                                    @if ($d['sent'] + $d['failed'] === 0)<div class="h-1 rounded-sm bg-surface-2"></div>@endif
                                    <div class="pointer-events-none absolute bottom-full z-10 mb-2 hidden whitespace-nowrap rounded-lg bg-ink px-3 py-2 text-[12px] text-canvas shadow-lg group-hover:block {{ $edge }}">
                                        <p class="font-medium">{{ $d['date'] }}</p>
                                        <p>{{ number_format($d['sent']) }} delivered · {{ number_format($d['failed']) }} failed</p>
                                    </div>
                                </div>
                                <span @class(['mt-2 h-4 truncate text-[11px] text-muted', 'hidden sm:block' => $days->count() > 14 && $i % 3 !== 0])>{{ $days->count() > 7 ? $d['label'] : $d['weekday'] }}</span>
                            </div>
                        @endforeach
                    </div>
                </div>
            </div>
        </x-ui.card>

        <x-ui.card wire:key="donut-{{ $range }}">
            <h2 class="text-[15px] font-semibold">By status</h2>
            @php
                $offset = 25;
                $segments = collect($statusMeta)->map(fn ($m, $s) => ['status' => $s, 'label' => $m[0], 'color' => $m[1], 'n' => $statusCounts[$s] ?? 0])->filter(fn ($s) => $s['n'] > 0)->values();
            @endphp
            <div class="relative mx-auto mt-4 size-48">
                <svg viewBox="0 0 42 42" class="size-full -rotate-0" aria-hidden="true">
                    <circle cx="21" cy="21" r="15.915" fill="none" stroke="var(--c-surface-2)" stroke-width="5" />
                    @foreach ($segments as $i => $s)
                        @php $pct = $s['n'] / max(1, $total) * 100; @endphp
                        <circle cx="21" cy="21" r="15.915" fill="none" stroke="{{ $s['color'] }}" stroke-width="5"
                                stroke-dasharray="{{ $pct }} {{ 100 - $pct }}" stroke-dashoffset="{{ $offset }}"
                                class="anim-donut" style="animation-delay: {{ $i * 120 }}ms" />
                        @php $offset -= $pct; @endphp
                    @endforeach
                </svg>
                <div class="absolute inset-0 grid place-items-center text-center">
                    <div>
                        <p class="text-[28px] font-light leading-none tabular-nums"><x-ui.count :value="$total" id="donut-total" /></p>
                        <p class="mt-1 text-[12px] text-muted">emails</p>
                    </div>
                </div>
            </div>
            <ul class="mt-5 space-y-2 text-[13px]">
                @forelse ($segments as $s)
                    <li class="flex items-center gap-2">
                        <span class="size-2.5 rounded-sm" style="background: {{ $s['color'] }}"></span>
                        <span class="flex-1">{{ $s['label'] }}</span>
                        <span class="tabular-nums text-muted">{{ number_format($s['n']) }}</span>
                        <span class="w-12 text-right tabular-nums text-subtle">{{ round($s['n'] / max(1, $total) * 100) }}%</span>
                    </li>
                @empty
                    <li class="text-center text-muted">No emails in this period.</li>
                @endforelse
            </ul>
        </x-ui.card>
    </div>

    <div class="mt-4 grid grid-cols-1 gap-4 xl:grid-cols-3">
        {{-- Providers --}}
        <x-ui.card :padding="false" wire:key="prov-{{ $range }}">
            <div class="flex items-center justify-between px-6 pb-3 pt-5">
                <h2 class="text-[15px] font-semibold">Providers</h2>
                @can('manage_providers')<a href="{{ route('providers') }}" wire:navigate class="text-[13px] text-accent hover:underline">Manage</a>@endcan
            </div>
            @forelse ($providers as $i => $p)
                @php $n = $byProvider[$p->id] ?? 0; @endphp
                <div class="border-t border-line px-6 py-3.5">
                    <div class="flex items-center gap-3">
                        <x-ui.provider-icon :type="$p->type" />
                        <p class="min-w-0 flex-1 truncate text-sm font-medium">{{ $p->name }}</p>
                        <span class="text-[13px] tabular-nums text-muted">{{ number_format($n) }}</span>
                        <x-ui.status :status="$p->is_active ? $p->health : 'paused'" />
                    </div>
                    <div class="mt-2.5 h-1.5 overflow-hidden rounded-full bg-surface-2">
                        <div class="anim-grow-x h-full rounded-full bg-accent" style="width: {{ $n / $providerTotal * 100 }}%; animation-delay: {{ 200 + $i * 80 }}ms"></div>
                    </div>
                </div>
            @empty
                <x-ui.empty title="No providers yet" description="Connect an SMTP server or an email API to start sending." />
            @endforelse
        </x-ui.card>

        {{-- Top recipient domains --}}
        <x-ui.card :padding="false" wire:key="dom-{{ $range }}">
            <div class="px-6 pb-3 pt-5">
                <h2 class="text-[15px] font-semibold">Top recipient domains</h2>
            </div>
            @php $maxDomain = max(1, $topDomains->max('n') ?? 1); @endphp
            @forelse ($topDomains as $i => $d)
                <div class="relative border-t border-line px-6 py-3">
                    <div class="anim-grow-x absolute inset-y-1 left-0 rounded-r-md bg-accent-soft" style="width: {{ $d->n / $maxDomain * 100 }}%; animation-delay: {{ 200 + $i * 80 }}ms"></div>
                    <div class="relative flex items-center justify-between text-sm">
                        <span class="truncate font-medium">{{ $d->domain ?: 'unknown' }}</span>
                        <span class="tabular-nums text-muted">{{ number_format($d->n) }}</span>
                    </div>
                </div>
            @empty
                <x-ui.empty title="No recipients yet" />
            @endforelse
        </x-ui.card>

        {{-- Recent --}}
        <x-ui.card :padding="false">
            <div class="flex items-center justify-between px-6 pb-3 pt-5">
                <h2 class="text-[15px] font-semibold">Recent emails</h2>
                @can('view_emails')<a href="{{ route('emails') }}" wire:navigate class="text-[13px] text-accent hover:underline">View all</a>@endcan
            </div>
            @forelse ($recent as $e)
                <a href="{{ route('emails.show', $e) }}" wire:navigate class="flex items-center gap-3 border-t border-line px-6 py-3 transition-colors hover:bg-surface-2/60">
                    <div class="min-w-0 flex-1">
                        <p class="truncate text-sm font-medium">{{ $e->subject }}</p>
                        <p class="truncate text-[12.5px] text-muted">{{ implode(', ', $e->to) }} · {{ $e->created_at->diffForHumans(short: true) }}</p>
                    </div>
                    <x-ui.status :status="$e->status" />
                </a>
            @empty
                <x-ui.empty title="Nothing sent yet" description="Create an API key and send your first email." />
            @endforelse
        </x-ui.card>
    </div>
</div>
