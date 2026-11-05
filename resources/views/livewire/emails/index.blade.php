<div wire:poll.15s.visible>
    <x-ui.page-header eyebrow="Emails" title="Send log" description="Every email accepted by the API or the dashboard. Click a row to read it.">
        <x-slot:actions>
            @can('manage_emails')
                <x-ui.button variant="secondary" wire:click="retryFailed" wire:confirm="Queue every failed email matching these filters again?">
                    <x-ui.icon name="refresh" class="size-4" /> Retry failed
                </x-ui.button>
            @endcan
            @can('export_emails')
                <x-ui.button variant="secondary" :href="$exportUrl" x-on:click.prevent="window.location = $el.href"><x-ui.icon name="download" class="size-4" /> Export CSV</x-ui.button>
            @endcan
            @can('send_emails')
                <x-ui.button :href="route('compose')"><x-ui.icon name="send" class="size-4" /> Send email</x-ui.button>
            @endcan
        </x-slot:actions>
    </x-ui.page-header>

    {{-- Status tabs --}}
    <div class="mb-4 flex gap-1 overflow-x-auto border-b border-line [scrollbar-width:none]">
        @foreach (['' => 'All'] + array_combine(\App\Models\Email::STATUSES, array_map('ucfirst', \App\Models\Email::STATUSES)) as $value => $label)
            <button type="button" wire:click="$set('status', '{{ $value }}')" @class([
                '-mb-px flex items-center gap-2 whitespace-nowrap border-b-2 px-3 py-2.5 text-[13.5px] transition-colors',
                'border-accent font-medium text-ink' => $status === $value,
                'border-transparent text-muted hover:text-ink' => $status !== $value,
            ])>
                {{ $label }}
                <span class="rounded-full bg-surface-2 px-1.5 text-[11px] tabular-nums text-muted">{{ number_format($value === '' ? $counts->sum() : ($counts[$value] ?? 0)) }}</span>
            </button>
        @endforeach
    </div>

    <div class="mb-4 flex flex-col gap-2 sm:flex-row sm:flex-wrap sm:items-center">
        <div class="relative sm:w-80">
            <x-ui.icon name="search" class="pointer-events-none absolute left-3 top-1/2 size-4 -translate-y-1/2 text-subtle" />
            <input wire:model.live.debounce.400ms="search" type="search" class="field pl-9" placeholder="Search subject, address or ID" aria-label="Search emails">
        </div>
        <div class="grid grid-cols-2 gap-2 sm:flex">
            <select wire:model.live="provider" class="field sm:w-44" aria-label="Provider">
                <option value="">All providers</option>
                @foreach ($providers as $p)<option value="{{ $p->id }}">{{ $p->name }}</option>@endforeach
            </select>
            <select wire:model.live="range" class="field sm:w-40" aria-label="Time range">
                <option value="">Any time</option>
                <option value="1h">Last hour</option>
                <option value="24h">Last 24 hours</option>
                <option value="7d">Last 7 days</option>
                <option value="30d">Last 30 days</option>
            </select>
        </div>
        @if ($search || $status || $provider || $range)
            <x-ui.button variant="ghost" size="sm" wire:click="clearFilters">Clear filters</x-ui.button>
        @endif
        <span class="text-[13px] text-muted sm:ml-auto">{{ number_format($emails->total()) }} {{ Str::plural('email', $emails->total()) }}</span>
    </div>

    <x-ui.card :padding="false" class="relative overflow-x-auto">
        @if ($emails->isEmpty())
            <x-ui.empty title="No emails found" :description="($search || $status || $provider || $range) ? 'Try different filters.' : 'Emails sent through the API show up here.'" />
        @else
            <table class="w-full min-w-[820px] text-sm">
                <thead>
                    <tr class="text-left text-[12px] text-muted">
                        <th class="px-6 py-3 font-medium">Recipient</th>
                        <th class="px-3 py-3 font-medium">Subject</th>
                        <th class="px-3 py-3 font-medium">Status</th>
                        <th class="px-3 py-3 font-medium">Provider</th>
                        <th class="px-6 py-3 text-right font-medium">Created</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($emails as $e)
                        <tr wire:key="e-{{ $e->id }}" wire:click="open('{{ $e->id }}')" @class([
                            'cursor-pointer border-t border-line transition-colors hover:bg-surface-2/60',
                            'bg-accent-soft/60' => $previewId === $e->id,
                        ])>
                            <td class="max-w-64 px-6 py-3">
                                <p class="truncate font-medium">{{ implode(', ', $e->to) }}</p>
                                <p class="truncate text-[12px] text-muted">{{ $e->from }}</p>
                            </td>
                            <td class="max-w-96 px-3 py-3">
                                <div class="flex items-center gap-1.5">
                                    <span class="truncate">{{ $e->subject }}</span>
                                    @if ($e->attachments_count)<x-ui.icon name="paperclip" class="size-3.5 text-subtle" />@endif
                                </div>
                                @if ($e->tags)
                                    <div class="mt-1 flex gap-1">@foreach (array_slice($e->tags, 0, 2) as $t)<span class="rounded bg-surface-2 px-1.5 text-[11px] text-muted">{{ $t['name'] }}{{ ($t['value'] ?? '') !== '' ? ':'.$t['value'] : '' }}</span>@endforeach</div>
                                @endif
                            </td>
                            <td class="px-3 py-3"><x-ui.status :status="$e->status" /></td>
                            <td class="px-3 py-3 text-muted">
                                @if ($e->provider)
                                    <span class="inline-flex items-center gap-1.5"><x-ui.provider-icon :type="$e->provider->type" class="size-5 text-[10px]" /><span class="max-w-40 truncate">{{ $e->provider->name }}</span></span>
                                @else — @endif
                            </td>
                            <td class="whitespace-nowrap px-6 py-3 text-right text-muted" title="{{ $e->created_at }}">{{ $e->created_at->diffForHumans() }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        @endif
    </x-ui.card>

    <div class="mt-4">{{ $emails->links() }}</div>

    {{-- Drawer --}}
    @if ($preview)
        <div class="fixed inset-0 z-40 bg-slate-950/30 backdrop-blur-[1px]" wire:click="close" x-data x-on:keydown.escape.window="$wire.close()"></div>
        <aside class="fixed inset-y-0 right-0 z-50 flex w-full max-w-2xl flex-col border-l border-line bg-surface shadow-2xl [animation:drawer-in_.25s_ease-out]" role="dialog" aria-modal="true" aria-label="Email {{ $preview->subject }}">
            <div class="flex items-start gap-3 border-b border-line px-6 py-4">
                <div class="min-w-0 flex-1">
                    <div class="flex items-center gap-2"><x-ui.status :status="$preview->status" /><span class="text-[12px] text-muted">{{ $preview->created_at->format('j M Y, H:i') }}</span></div>
                    <h2 class="mt-1.5 text-[18px] font-semibold leading-snug">{{ $preview->subject }}</h2>
                </div>
                <button type="button" wire:click="close" class="grid size-8 shrink-0 place-items-center rounded-[4px] text-muted hover:bg-surface-2 hover:text-ink" aria-label="Close"><x-ui.icon name="x" /></button>
            </div>

            <div class="flex-1 overflow-y-auto">
                <dl class="grid gap-x-4 gap-y-1.5 px-6 py-4 text-[13px] sm:grid-cols-[80px_1fr]">
                    <dt class="text-muted">From</dt><dd class="break-words">{{ $preview->from }}</dd>
                    <dt class="text-muted">To</dt><dd class="break-words">{{ implode(', ', $preview->to) }}</dd>
                    @if ($preview->cc)<dt class="text-muted">Cc</dt><dd class="break-words">{{ implode(', ', $preview->cc) }}</dd>@endif
                    @if ($preview->bcc)<dt class="text-muted">Bcc</dt><dd class="break-words">{{ implode(', ', $preview->bcc) }}</dd>@endif
                    @if ($preview->reply_to)<dt class="text-muted">Reply-To</dt><dd class="break-words">{{ implode(', ', $preview->reply_to) }}</dd>@endif
                    <dt class="text-muted">Provider</dt><dd>{{ $preview->provider?->name ?? '—' }}{{ $preview->routingRule ? ' · route '.$preview->routingRule->name : '' }}</dd>
                </dl>

                @if ($preview->failed_reason && $preview->status !== 'sent')
                    <div class="mx-6 mb-4 rounded-xl border border-bad/25 bg-bad-soft px-4 py-3 text-[13px] text-bad">{{ $preview->failed_reason }}</div>
                @endif

                <div class="mx-6 overflow-hidden rounded-2xl border border-line">
                    @can('view_email_content')
                        <x-email-body :email="$preview" height="h-[420px]" />
                    @else
                        <div class="flex flex-col items-center px-6 py-12 text-center">
                            <x-ui.icon name="shield" class="size-6 text-subtle" />
                            <p class="mt-2 text-sm font-medium">Content hidden</p>
                            <p class="mt-1 text-[13px] text-muted">Your role can see delivery details but not email content.</p>
                        </div>
                    @endcan
                </div>

                @if ($preview->attachments->isNotEmpty())
                    <div class="px-6 pt-4">
                        <p class="eyebrow mb-2">Attachments</p>
                        <div class="flex flex-wrap gap-2">
                            @foreach ($preview->attachments as $att)
                                @can('view_email_content')
                                    <a href="{{ route('emails.attachment', [$preview, $att]) }}" class="inline-flex items-center gap-2 rounded-lg border border-line px-3 py-2 text-[13px] hover:bg-surface-2">
                                        <x-ui.icon name="paperclip" class="size-4 text-muted" />{{ $att->filename }}
                                    </a>
                                @else
                                    <span class="inline-flex items-center gap-2 rounded-lg border border-line px-3 py-2 text-[13px]"><x-ui.icon name="paperclip" class="size-4 text-muted" />{{ $att->filename }}</span>
                                @endcan
                            @endforeach
                        </div>
                    </div>
                @endif

                <div class="px-6 py-5">
                    <p class="eyebrow mb-3">Delivery attempts</p>
                    @forelse ($preview->attemptLogs as $a)
                        <div class="flex gap-3 pb-3 text-[13px]">
                            <span @class(['mt-1.5 size-2 shrink-0 rounded-full', 'bg-ok' => $a->status === 'sent', 'bg-bad' => $a->status === 'failed', 'bg-subtle' => $a->status === 'skipped'])></span>
                            <div class="min-w-0">
                                <p><span class="font-medium">{{ ucfirst($a->status) }}</span> via {{ $a->provider?->name ?? 'deleted provider' }} <span class="text-muted">· {{ $a->created_at->format('H:i:s') }} · {{ $a->duration_ms }} ms</span></p>
                                @if ($a->error_message)<p class="break-words text-bad">{{ Str::limit($a->error_message, 240) }}</p>@endif
                            </div>
                        </div>
                    @empty
                        <p class="text-[13px] text-muted">No attempts yet.</p>
                    @endforelse
                </div>
            </div>

            <div class="flex flex-wrap gap-2 border-t border-line bg-canvas/60 px-6 py-3">
                <x-ui.button size="sm" :href="route('emails.show', $preview)"><x-ui.icon name="external" class="size-4" /> Open full view</x-ui.button>
                @can('view_email_content')
                    <x-ui.button size="sm" variant="secondary" :href="route('emails.eml', $preview)" x-on:click.prevent="window.location = $el.href"><x-ui.icon name="download" class="size-4" /> .eml</x-ui.button>
                @endcan
                @can('send_emails')
                    <x-ui.button size="sm" variant="secondary" :href="route('compose', ['from_email' => $preview->id])"><x-ui.icon name="copy" class="size-4" /> Duplicate</x-ui.button>
                @endcan
                @can('manage_emails')
                    @if (in_array($preview->status, ['failed', 'canceled']))
                        <x-ui.button size="sm" variant="secondary" wire:click="retry('{{ $preview->id }}')"><x-ui.icon name="refresh" class="size-4" /> Retry</x-ui.button>
                    @endif
                @endcan
            </div>
        </aside>
    @endif
</div>
