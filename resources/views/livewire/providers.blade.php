<div>
    <x-ui.page-header eyebrow="Providers" title="Upstream mail servers"
        description="Every SMTP server or email API the router can send through. Credentials are encrypted at rest.">
        <x-slot:actions>
            <x-ui.button wire:click="create">Add provider</x-ui.button>
        </x-slot:actions>
    </x-ui.page-header>

    <x-ui.card :padding="false">
        @forelse ($providers as $p)
            <div wire:key="provider-{{ $p->id }}" class="flex flex-col gap-4 border-t border-line px-5 py-4 first:border-t-0 sm:px-6 lg:flex-row lg:items-center">
                <div class="flex min-w-0 flex-1 items-center gap-3">
                    <x-ui.provider-icon :type="$p->type" class="size-9 text-[14px]" />
                    <div class="min-w-0">
                        <div class="flex flex-wrap items-center gap-2">
                            <p class="truncate text-[15px] font-semibold">{{ $p->name }}</p>
                            <x-ui.status :status="$p->is_active ? $p->health : 'paused'" />
                        </div>
                        <p class="mt-0.5 truncate text-[13px] text-muted">
                            {{ $p->typeLabel() }}
                            @if ($p->type === 'smtp') · {{ $p->config['host'] ?? '' }}:{{ $p->config['port'] ?? '' }} @endif
                            @if ($p->type === 'mailgun') · {{ $p->config['domain'] ?? '' }} @endif
                            · priority {{ $p->priority }} · weight {{ $p->weight }}
                            @if ($p->from_address) · from {{ $p->from_address }} @endif
                        </p>
                        @if ($p->health !== 'healthy' && $p->last_error)
                            <p class="mt-1 line-clamp-1 text-[12.5px] text-bad" title="{{ $p->last_error }}">{{ $p->last_error }}</p>
                        @endif
                        @if ($p->isCoolingDown())
                            <p class="mt-0.5 text-[12.5px] text-warn">Skipped until {{ $p->cooldown_until->diffForHumans() }}</p>
                        @endif
                    </div>
                </div>

                <div class="flex flex-wrap items-center gap-x-6 gap-y-2 lg:w-72">
                    <div class="text-[12.5px]" title="Emails sent in the last 24 hours (rolling). Not the same as the per-day limit, which counts the current UTC day.">
                        <p class="text-muted">Sent 24h</p>
                        <p class="font-medium tabular-nums">{{ number_format($p->sent_24h) }}</p>
                    </div>
                    @forelse ($usage[$p->id] as $window => $u)
                        <div class="min-w-16 text-[12.5px]">
                            <p class="text-muted">Per {{ $window }}</p>
                            <p class="font-medium tabular-nums">{{ $u['used'] }}<span class="text-subtle">/{{ $u['limit'] }}</span></p>
                            <div class="mt-1 h-1 overflow-hidden rounded-full bg-surface-2">
                                <div @class(['h-full rounded-full', 'bg-bad' => $u['used'] >= $u['limit'], 'bg-accent' => $u['used'] < $u['limit']]) style="width: {{ min(100, $u['used'] / $u['limit'] * 100) }}%"></div>
                            </div>
                            @if ($window !== 'minute')
                                <p class="mt-1 text-[11px] text-subtle">resets {{ $window === 'day' ? '00:00' : 'on the hour' }} UTC</p>
                            @endif
                        </div>
                    @empty
                        <p class="text-[12.5px] text-muted">No rate limit</p>
                    @endforelse
                </div>

                <div class="flex flex-wrap items-center gap-1.5">
                    <x-ui.button variant="secondary" size="sm" wire:click="testConnection({{ $p->id }})" wire:loading.attr="disabled" wire:target="testConnection({{ $p->id }})">
                        <span wire:loading.remove wire:target="testConnection({{ $p->id }})">Test</span>
                        <span wire:loading wire:target="testConnection({{ $p->id }})">Testing…</span>
                    </x-ui.button>
                    <x-ui.button variant="secondary" size="sm" wire:click="openTest({{ $p->id }})">Send test</x-ui.button>
                    <x-ui.button variant="ghost" size="sm" wire:click="edit({{ $p->id }})">Edit</x-ui.button>
                    <div x-data="{ open: false }" class="relative">
                        <x-ui.button variant="ghost" size="sm" x-on:click="open = !open" aria-label="More actions">
                            <svg class="size-4" viewBox="0 0 24 24" fill="currentColor"><circle cx="5" cy="12" r="1.6"/><circle cx="12" cy="12" r="1.6"/><circle cx="19" cy="12" r="1.6"/></svg>
                        </x-ui.button>
                        <div x-show="open" x-cloak x-on:click.outside="open = false" class="absolute right-0 z-10 mt-1 w-44 overflow-hidden rounded-xl border border-line bg-surface py-1 text-sm shadow-lg">
                            <button type="button" wire:click="toggle({{ $p->id }})" x-on:click="open = false" class="block w-full px-3 py-2 text-left hover:bg-surface-2">{{ $p->is_active ? 'Pause' : 'Resume' }}</button>
                            <button type="button" wire:click="resetHealth({{ $p->id }})" x-on:click="open = false" class="block w-full px-3 py-2 text-left hover:bg-surface-2">Reset health</button>
                            <button type="button" wire:click="delete({{ $p->id }})" wire:confirm="Delete {{ $p->name }}? Routes that use it will skip it." class="block w-full px-3 py-2 text-left text-bad hover:bg-bad-soft">Delete</button>
                        </div>
                    </div>
                </div>
            </div>
        @empty
            <x-ui.empty title="No providers yet" description="Add an SMTP server, Resend, SendGrid or Mailgun account. Without a routing rule, every active provider is used in priority order.">
                <x-ui.button wire:click="create">Add provider</x-ui.button>
            </x-ui.empty>
        @endforelse
    </x-ui.card>

    @if ($showForm)
        <x-ui.modal :title="$editingId ? 'Edit provider' : 'Add provider'" close="$set('showForm', false)" wide>
            <form id="provider-form" wire:submit="save" class="space-y-5">
                <div>
                    <span class="label">Type</span>
                    <div class="grid grid-cols-2 gap-2 sm:grid-cols-4">
                        @foreach (\App\Models\Provider::TYPES as $value => $label)
                            <label @class([
                                'flex cursor-pointer items-center gap-2 rounded-xl border px-3 py-2.5 text-sm transition-colors',
                                'border-accent bg-accent-soft font-medium' => $form['type'] === $value,
                                'border-line hover:bg-surface-2' => $form['type'] !== $value,
                                'pointer-events-none opacity-50' => $editingId && $form['type'] !== $value,
                            ])>
                                <input type="radio" wire:model.live="form.type" value="{{ $value }}" class="sr-only" @disabled($editingId)>
                                <x-ui.provider-icon :type="$value" class="size-6 text-[11px]" /> {{ $label }}
                            </label>
                        @endforeach
                    </div>
                </div>

                <div>
                    <label for="p-name" class="label">Name</label>
                    <input id="p-name" wire:model="form.name" class="field" placeholder="e.g. Office SMTP, Resend main" required>
                    @error('form.name') <p class="mt-1.5 text-[13px] text-bad">{{ $message }}</p> @enderror
                </div>

                <div>
                    <label for="p-from" class="label">Default sender (From)</label>
                    <input id="p-from" wire:model="form.from_address" class="field" placeholder="Acme <noreply@your-verified-domain.com>" autocomplete="off">
                    <p class="mt-1.5 text-[12.5px] text-muted">Mail sent through this provider uses this From, and the original sender becomes the Reply-To. Use an address on a domain verified with this provider. Leave blank to send with each email's own From.</p>
                    @error('form.from_address') <p class="mt-1.5 text-[13px] text-bad">{{ $message }}</p> @enderror
                </div>

                <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                    @foreach ($fields as $f)
                        <div wire:key="field-{{ $form['type'] }}-{{ $f['key'] }}" @class(['sm:col-span-2' => in_array($f['key'], ['api_key', 'host'])])>
                            <label for="cfg-{{ $f['key'] }}" class="label">{{ $f['label'] }}</label>
                            @if ($f['type'] === 'select')
                                <select id="cfg-{{ $f['key'] }}" wire:model="config.{{ $f['key'] }}" class="field">
                                    @foreach ($f['options'] as $v => $l)
                                        <option value="{{ $v }}">{{ $l }}</option>
                                    @endforeach
                                </select>
                            @else
                                <input id="cfg-{{ $f['key'] }}" wire:model="config.{{ $f['key'] }}" type="{{ $f['type'] }}" class="field"
                                       placeholder="{{ ! empty($f['secret']) && $editingId ? 'Leave blank to keep current (re-enter it if you change the connection details)' : ($f['hint'] ?? '') }}"
                                       autocomplete="off">
                            @endif
                            @error('config.'.$f['key']) <p class="mt-1.5 text-[13px] text-bad">{{ $message }}</p> @enderror
                        </div>
                    @endforeach
                </div>

                <div class="rounded-2xl border border-line bg-canvas/60 p-4">
                    <p class="text-[13px] font-semibold">Routing</p>
                    <p class="mt-0.5 text-[12.5px] text-muted">Used when no routing rule applies. Lower priority is tried first.</p>
                    <div class="mt-3 grid grid-cols-2 gap-3">
                        <div>
                            <label for="p-priority" class="label">Priority</label>
                            <input id="p-priority" type="number" min="0" wire:model="form.priority" class="field">
                        </div>
                        <div>
                            <label for="p-weight" class="label">Weight</label>
                            <input id="p-weight" type="number" min="0" wire:model="form.weight" class="field">
                        </div>
                    </div>
                    <p class="mt-4 text-[13px] font-semibold">Rate limits</p>
                    <p class="mt-0.5 text-[12.5px] text-muted">When a limit is reached, mail moves to the next provider. Leave blank for no limit.</p>
                    <div class="mt-3 grid grid-cols-3 gap-3">
                        @foreach (['minute', 'hour', 'day'] as $w)
                            <div>
                                <label for="p-limit-{{ $w }}" class="label">Per {{ $w }}</label>
                                <input id="p-limit-{{ $w }}" type="number" min="1" wire:model="form.limit_per_{{ $w }}" class="field" placeholder="∞">
                                @error('form.limit_per_'.$w) <p class="mt-1.5 text-[13px] text-bad">{{ $message }}</p> @enderror
                            </div>
                        @endforeach
                    </div>
                </div>

                <label class="flex items-center gap-2 text-sm">
                    <input type="checkbox" wire:model="form.is_active" class="size-4 accent-[#1677ff]"> Active
                </label>
            </form>
            <x-slot:footer>
                <x-ui.button variant="secondary" wire:click="$set('showForm', false)">Cancel</x-ui.button>
                <x-ui.button type="submit" form="provider-form">{{ $editingId ? 'Save changes' : 'Add provider' }}</x-ui.button>
            </x-slot:footer>
        </x-ui.modal>
    @endif

    @if ($testingId)
        <x-ui.modal title="Send a test email" close="$set('testingId', null)">
            <form id="test-form" wire:submit="sendTest" class="space-y-4">
                <p class="text-sm text-muted">Sends directly through this provider, bypassing routing.</p>
                <div>
                    <label for="t-from" class="label">From</label>
                    <input id="t-from" wire:model="testFrom" class="field" placeholder="Acme <hello@acme.com>">
                    @error('testFrom') <p class="mt-1.5 text-[13px] text-bad">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label for="t-to" class="label">To</label>
                    <input id="t-to" wire:model="testTo" type="email" class="field" placeholder="you@example.com">
                    @error('testTo') <p class="mt-1.5 break-words text-[13px] text-bad">{{ $message }}</p> @enderror
                </div>
            </form>
            <x-slot:footer>
                <x-ui.button variant="secondary" wire:click="$set('testingId', null)">Cancel</x-ui.button>
                <x-ui.button type="submit" form="test-form" wire:loading.attr="disabled" wire:target="sendTest">
                    <span wire:loading.remove wire:target="sendTest">Send</span>
                    <span wire:loading wire:target="sendTest">Sending…</span>
                </x-ui.button>
            </x-slot:footer>
        </x-ui.modal>
    @endif
</div>
