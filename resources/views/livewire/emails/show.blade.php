<div @if (in_array($email->status, ['queued', 'sending', 'scheduled'])) wire:poll.5s @endif>
    <a href="{{ route('emails') }}" wire:navigate class="mb-5 inline-flex items-center gap-1 text-[13px] text-muted hover:text-ink">
        <svg class="size-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="m15 6-6 6 6 6"/></svg>
        Send log
    </a>

    <x-ui.page-header :title="$email->subject">
        <x-slot:actions>
            <x-ui.status :status="$email->status" class="text-[13px]" />
            @if ($email->status === 'scheduled')
                <x-ui.button variant="danger" size="sm" wire:click="cancel" wire:confirm="Cancel this scheduled email?">Cancel send</x-ui.button>
            @endif
            @if (in_array($email->status, ['failed', 'canceled']))
                <x-ui.button size="sm" wire:click="retry">Retry now</x-ui.button>
            @endif
        </x-slot:actions>
    </x-ui.page-header>

    @if ($email->failed_reason && $email->status !== 'sent')
        <div class="mb-4 rounded-2xl border border-bad/25 bg-bad-soft px-5 py-3.5 text-sm text-bad">{{ $email->failed_reason }}</div>
    @endif

    <div class="grid gap-4 lg:grid-cols-[1fr_360px]">
        <div class="min-w-0 space-y-4">
            <x-ui.card :padding="false">
                <dl class="text-sm">
                    @foreach (array_filter([
                        'From' => $email->from,
                        'To' => implode(', ', $email->to),
                        'Cc' => $email->cc ? implode(', ', $email->cc) : null,
                        'Bcc' => $email->bcc ? implode(', ', $email->bcc) : null,
                        'Reply-To' => $email->reply_to ? implode(', ', $email->reply_to) : null,
                    ]) as $label => $value)
                        <div class="grid gap-0.5 border-t border-line px-5 py-3 first:border-t-0 sm:grid-cols-[120px_1fr] sm:gap-4">
                            <dt class="text-muted">{{ $label }}</dt>
                            <dd class="break-words">{{ $value }}</dd>
                        </div>
                    @endforeach
                </dl>
            </x-ui.card>

            <x-ui.card :padding="false" x-data="{ tab: '{{ $email->html ? 'html' : 'text' }}' }">
                <div class="flex gap-1 border-b border-line px-3 pt-2" role="tablist">
                    @foreach (['html' => 'Preview', 'text' => 'Plain text', 'source' => 'HTML source'] as $key => $label)
                        <button type="button" role="tab" x-on:click="tab = '{{ $key }}'" :aria-selected="tab === '{{ $key }}'"
                                class="-mb-px border-b-2 px-3 py-2 text-[13px] transition-colors"
                                :class="tab === '{{ $key }}' ? 'border-accent font-medium text-ink' : 'border-transparent text-muted hover:text-ink'">{{ $label }}</button>
                    @endforeach
                </div>
                <div x-show="tab === 'html'" class="bg-white">
                    @if ($email->html)
                        {{-- sandbox without allow-scripts: the email's HTML cannot run code or reach this page. --}}
                        <iframe srcdoc="{{ $email->html }}" sandbox="allow-popups allow-popups-to-escape-sandbox" referrerpolicy="no-referrer" class="h-[520px] w-full rounded-b-2xl" title="Email preview"></iframe>
                    @else
                        <p class="p-6 text-sm text-slate-500">No HTML body.</p>
                    @endif
                </div>
                <pre x-show="tab === 'text'" x-cloak class="max-h-[520px] overflow-auto whitespace-pre-wrap p-5 font-mono text-[12.5px]">{{ $email->text ?? 'No plain-text body.' }}</pre>
                <pre x-show="tab === 'source'" x-cloak class="max-h-[520px] overflow-auto whitespace-pre-wrap p-5 font-mono text-[12.5px]">{{ $email->html ?? 'No HTML body.' }}</pre>
            </x-ui.card>
        </div>

        <div class="space-y-4">
            <x-ui.card>
                <h2 class="text-[15px] font-semibold">Delivery</h2>
                <ol class="mt-4 space-y-0">
                    <li class="relative flex gap-3 pb-5">
                        <span class="absolute left-[5px] top-4 h-full w-px bg-line" aria-hidden="true"></span>
                        <span class="mt-1.5 size-[11px] shrink-0 rounded-full border-2 border-accent bg-surface"></span>
                        <div class="text-sm">
                            <p class="font-medium">Accepted</p>
                            <p class="text-[12.5px] text-muted">{{ $email->created_at->format('j M Y, H:i:s') }}{{ $email->apiKey ? ' · key '.$email->apiKey->name : '' }}</p>
                            @if ($email->scheduled_at)<p class="text-[12.5px] text-muted">Scheduled for {{ $email->scheduled_at->format('j M Y, H:i') }}</p>@endif
                            <p class="text-[12.5px] text-muted">Route: {{ $email->routingRule?->name ?? ($email->attempts ? 'none (all providers)' : 'pending') }}</p>
                        </div>
                    </li>
                    @foreach ($email->attemptLogs as $a)
                        <li class="relative flex gap-3 pb-5 last:pb-0">
                            @unless ($loop->last)<span class="absolute left-[5px] top-4 h-full w-px bg-line" aria-hidden="true"></span>@endunless
                            <span @class(['mt-1.5 size-[11px] shrink-0 rounded-full', 'bg-ok' => $a->status === 'sent', 'bg-bad' => $a->status === 'failed', 'bg-subtle' => $a->status === 'skipped'])></span>
                            <div class="min-w-0 text-sm">
                                <p class="font-medium">{{ ucfirst($a->status) }} via {{ $a->provider?->name ?? 'deleted provider' }}</p>
                                <p class="text-[12.5px] text-muted">{{ $a->created_at->format('H:i:s') }} · {{ $a->duration_ms }} ms{{ $a->error_code ? ' · '.$a->error_code : '' }}</p>
                                @if ($a->error_message)
                                    <p class="mt-1 break-words text-[12.5px] text-bad">{{ \Illuminate\Support\Str::limit($a->error_message, 300) }}</p>
                                @endif
                            </div>
                        </li>
                    @endforeach
                </ol>
            </x-ui.card>

            <x-ui.card>
                <h2 class="text-[15px] font-semibold">Details</h2>
                <dl class="mt-3 space-y-2.5 text-sm">
                    <div><dt class="text-[12px] text-muted">Email ID</dt><dd class="break-all font-mono text-[12.5px]">{{ $email->id }}</dd></div>
                    @if ($email->provider_message_id)
                        <div><dt class="text-[12px] text-muted">Provider message ID</dt><dd class="break-all font-mono text-[12.5px]">{{ $email->provider_message_id }}</dd></div>
                    @endif
                    @if ($email->tags)
                        <div>
                            <dt class="text-[12px] text-muted">Tags</dt>
                            <dd class="mt-1 flex flex-wrap gap-1">@foreach ($email->tags as $t)<x-ui.badge>{{ $t['name'] }}{{ ($t['value'] ?? '') !== '' ? ': '.$t['value'] : '' }}</x-ui.badge>@endforeach</dd>
                        </div>
                    @endif
                    @if ($email->attachments->isNotEmpty())
                        <div>
                            <dt class="text-[12px] text-muted">Attachments</dt>
                            @foreach ($email->attachments as $att)
                                <dd class="truncate">{{ $att->filename }} <span class="text-muted">· {{ $att->size < 1024 ? $att->size.' B' : ($att->size < 1048576 ? round($att->size / 1024, 1).' KB' : round($att->size / 1048576, 1).' MB') }}</span></dd>
                            @endforeach
                        </div>
                    @endif
                    @if ($email->headers)
                        <div>
                            <dt class="text-[12px] text-muted">Headers</dt>
                            @foreach ($email->headers as $h => $v)<dd class="break-all font-mono text-[12px]">{{ $h }}: {{ $v }}</dd>@endforeach
                        </div>
                    @endif
                </dl>
            </x-ui.card>
        </div>
    </div>
</div>
