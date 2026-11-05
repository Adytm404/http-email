<div wire:poll.10s.visible>
    <x-ui.page-header eyebrow="Emails" title="Send log" description="Every email accepted by the API, with the provider that delivered it." />

    <div class="mb-4 flex flex-col gap-2 sm:flex-row sm:flex-wrap sm:items-center">
        <div class="relative sm:w-72">
            <svg class="pointer-events-none absolute left-3 top-1/2 size-4 -translate-y-1/2 text-subtle" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><circle cx="11" cy="11" r="7"/><path d="m20 20-3.5-3.5"/></svg>
            <input wire:model.live.debounce.400ms="search" type="search" class="field pl-9" placeholder="Search subject, address or ID" aria-label="Search emails">
        </div>
        <div class="grid grid-cols-3 gap-2 sm:flex">
            <select wire:model.live="status" class="field sm:w-36" aria-label="Status">
                <option value="">All statuses</option>
                @foreach (\App\Models\Email::STATUSES as $s)<option value="{{ $s }}">{{ ucfirst($s) }}</option>@endforeach
            </select>
            <select wire:model.live="provider" class="field sm:w-40" aria-label="Provider">
                <option value="">All providers</option>
                @foreach ($providers as $p)<option value="{{ $p->id }}">{{ $p->name }}</option>@endforeach
            </select>
            <select wire:model.live="range" class="field sm:w-36" aria-label="Time range">
                <option value="">Any time</option>
                <option value="1h">Last hour</option>
                <option value="24h">Last 24 hours</option>
                <option value="7d">Last 7 days</option>
                <option value="30d">Last 30 days</option>
            </select>
        </div>
        @if ($search || $status || $provider || $range)
            <x-ui.button variant="ghost" size="sm" wire:click="clearFilters">Clear</x-ui.button>
        @endif
    </div>

    <x-ui.card :padding="false" class="relative overflow-x-auto">
        @if ($emails->isEmpty())
            <x-ui.empty title="No emails found" :description="($search || $status || $provider || $range) ? 'Try different filters.' : 'Emails sent through the API show up here.'" />
        @else
            <table class="w-full min-w-[760px] text-sm">
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
                        <tr wire:key="e-{{ $e->id }}" class="relative border-t border-line transition-colors hover:bg-surface-2/60">
                            <td class="max-w-56 px-6 py-3">
                                <a href="{{ route('emails.show', $e) }}" wire:navigate class="block truncate font-medium after:absolute after:inset-0">{{ implode(', ', $e->to) }}</a>
                                <p class="truncate text-[12px] text-muted">{{ $e->from }}</p>
                            </td>
                            <td class="max-w-72 truncate px-3 py-3">{{ $e->subject }}</td>
                            <td class="px-3 py-3"><x-ui.status :status="$e->status" /></td>
                            <td class="px-3 py-3 text-muted">
                                @if ($e->provider)
                                    <span class="inline-flex items-center gap-1.5"><x-ui.provider-icon :type="$e->provider->type" class="size-5 text-[10px]" />{{ $e->provider->name }}</span>
                                @else
                                    —
                                @endif
                            </td>
                            <td class="whitespace-nowrap px-6 py-3 text-right text-muted" title="{{ $e->created_at }}">{{ $e->created_at->diffForHumans() }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        @endif
    </x-ui.card>

    <div class="mt-4">{{ $emails->links() }}</div>
</div>
