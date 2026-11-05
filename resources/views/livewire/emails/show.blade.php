<div @if (in_array($email->status, ['queued', 'sending', 'scheduled'])) wire:poll.5s @endif>
    <a href="{{ route('emails') }}" wire:navigate class="mb-5 inline-flex items-center gap-1 text-[13px] text-muted hover:text-ink">
        <x-ui.icon name="chevron-left" class="size-3.5" />
        Send log
    </a>

    <x-ui.page-header :title="$email->subject">
        <x-slot:actions>
            <x-ui.status :status="$email->status" class="text-[13px]" />
            @can('view_email_content')
                <x-ui.button variant="secondary" size="sm" :href="route('emails.eml', $email)" x-on:click.prevent="window.location = $el.href"><x-ui.icon name="download" class="size-4" /> Download .eml</x-ui.button>
            @endcan
            @can('send_emails')
                <x-ui.button variant="secondary" size="sm" :href="route('compose', ['from_email' => $email->id])"><x-ui.icon name="copy" class="size-4" /> Duplicate</x-ui.button>
            @endcan
            @can('manage_emails')
                @if ($email->status === 'scheduled')
                    <x-ui.button variant="danger" size="sm" wire:click="cancel" wire:confirm="Cancel this scheduled email?">Cancel send</x-ui.button>
                @endif
                @if (in_array($email->status, ['failed', 'canceled']))
                    <x-ui.button size="sm" wire:click="retry"><x-ui.icon name="refresh" class="size-4" /> Retry now</x-ui.button>
                @endif
            @endcan
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

            <x-ui.card :padding="false" class="overflow-hidden">
                @can('view_email_content')
                    <x-email-body :email="$email" height="h-[600px]" />
                @else
                    <div class="flex flex-col items-center px-6 py-16 text-center">
                        <x-ui.icon name="shield" class="size-6 text-subtle" />
                        <p class="mt-2 text-sm font-medium">Content hidden</p>
                        <p class="mt-1 text-[13px] text-muted">Your role can see delivery details but not email content.</p>
                    </div>
                @endcan
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
                                <dd class="truncate">
                                    @can('view_email_content')
                                        <a href="{{ route('emails.attachment', [$email, $att]) }}" class="text-accent hover:underline">{{ $att->filename }}</a>
                                    @else
                                        {{ $att->filename }}
                                    @endcan
                                    <span class="text-muted">· {{ $att->size < 1024 ? $att->size.' B' : ($att->size < 1048576 ? round($att->size / 1024, 1).' KB' : round($att->size / 1048576, 1).' MB') }}</span></dd>
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
