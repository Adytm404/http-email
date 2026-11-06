<div>
    <x-ui.page-header eyebrow="Routing" title="Decide where each email goes"
        description="Rules are checked top to bottom. The first rule whose conditions all match picks the providers; otherwise the default route is used.">
        <x-slot:actions>
            <x-ui.button wire:click="create">New route</x-ui.button>
        </x-slot:actions>
    </x-ui.page-header>

    <div class="grid grid-cols-1 gap-4 lg:grid-cols-[1fr_340px]">
        <x-ui.card :padding="false">
            @forelse ($rules as $i => $rule)
                <div wire:key="rule-{{ $rule->id }}" class="flex flex-wrap gap-3 border-t border-line px-4 py-4 first:border-t-0 sm:flex-nowrap sm:px-5">
                    <div class="flex flex-col items-center gap-0.5 pt-0.5">
                        <button type="button" wire:click="move({{ $rule->id }}, -1)" @disabled($i === 0) class="grid size-6 place-items-center rounded text-muted hover:bg-surface-2 hover:text-ink disabled:opacity-30" aria-label="Move up">
                            <svg class="size-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="m6 15 6-6 6 6"/></svg>
                        </button>
                        <span class="text-[11px] tabular-nums text-subtle">{{ $i + 1 }}</span>
                        <button type="button" wire:click="move({{ $rule->id }}, 1)" @disabled($loop->last) class="grid size-6 place-items-center rounded text-muted hover:bg-surface-2 hover:text-ink disabled:opacity-30" aria-label="Move down">
                            <svg class="size-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="m6 9 6 6 6-6"/></svg>
                        </button>
                    </div>

                    <div class="min-w-0 flex-1">
                        <div class="flex flex-wrap items-center gap-2">
                            <p class="text-[15px] font-semibold">{{ $rule->name }}</p>
                            @if ($rule->is_default)<x-ui.badge tone="accent">Default</x-ui.badge>@endif
                            @unless ($rule->is_active)<x-ui.badge>Paused</x-ui.badge>@endunless
                        </div>
                        <p class="mt-0.5 text-[13px] text-muted">
                            <span class="font-mono text-[12.5px]">{{ $rule->conditionSummary() }}</span>
                            · {{ \App\Models\RoutingRule::STRATEGIES[$rule->strategy] }}
                        </p>
                        <div class="mt-2.5 flex flex-wrap items-center gap-1.5">
                            @forelse ($rule->providers as $p)
                                <span class="inline-flex items-center gap-1.5 whitespace-nowrap rounded-full border border-line bg-canvas py-0.5 pl-0.5 pr-2.5 text-[12.5px]">
                                    <x-ui.provider-icon :type="$p->type" class="size-5 rounded-full text-[10px]" />
                                    {{ $p->name }}
                                    <span class="text-subtle">{{ $rule->strategy === 'weighted' ? $p->pivot->weight.'w' : '#'.$p->pivot->priority }}</span>
                                </span>
                                @if (! $loop->last && $rule->strategy === 'failover')
                                    <svg class="size-3 text-subtle" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="m9 6 6 6-6 6"/></svg>
                                @endif
                            @empty
                                <span class="text-[12.5px] text-bad">No providers</span>
                            @endforelse
                        </div>
                    </div>

                    <div class="flex w-full shrink-0 items-start gap-1 pl-7 sm:w-auto sm:pl-0">
                        <x-ui.button variant="ghost" size="sm" wire:click="edit({{ $rule->id }})">Edit</x-ui.button>
                        <x-ui.button variant="ghost" size="sm" wire:click="toggle({{ $rule->id }})">{{ $rule->is_active ? 'Pause' : 'Resume' }}</x-ui.button>
                        <x-ui.button variant="ghost" size="sm" wire:click="delete({{ $rule->id }})" wire:confirm="Delete route {{ $rule->name }}?" class="text-bad hover:bg-bad-soft hover:text-bad">Delete</x-ui.button>
                    </div>
                </div>
            @empty
                <x-ui.empty title="No routes yet" description="Without routes every active provider is used in priority order. Add a default route to control failover and weights, then add rules for specific domains or tags.">
                    <x-ui.button wire:click="create">Create the default route</x-ui.button>
                </x-ui.empty>
            @endforelse
        </x-ui.card>

        {{-- Simulator --}}
        <x-ui.card class="h-fit">
            <h2 class="text-[15px] font-semibold">Try a route</h2>
            <p class="mt-1 text-[13px] text-muted">See which route and providers an email would use. Nothing is sent.</p>
            <form wire:submit="simulate" class="mt-4 space-y-3">
                <div>
                    <label for="sim-from" class="label">From</label>
                    <input id="sim-from" wire:model="sim.from" class="field" placeholder="news@acme.com">
                    @error('sim.from') <p class="mt-1 text-[12.5px] text-bad">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label for="sim-to" class="label">To</label>
                    <input id="sim-to" wire:model="sim.to" class="field" placeholder="someone@gmail.com">
                    @error('sim.to') <p class="mt-1 text-[12.5px] text-bad">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label for="sim-tag" class="label">Tag <span class="font-normal text-muted">(optional)</span></label>
                    <input id="sim-tag" wire:model="sim.tag" class="field" placeholder="category:promo">
                </div>
                @if ($apiKeys->isNotEmpty())
                    <div>
                        <label for="sim-key" class="label">API key <span class="font-normal text-muted">(optional)</span></label>
                        <select id="sim-key" wire:model="sim.api_key_id" class="field">
                            <option value="">Any</option>
                            @foreach ($apiKeys as $k)<option value="{{ $k->id }}">{{ $k->name }}</option>@endforeach
                        </select>
                    </div>
                @endif
                <x-ui.button type="submit" variant="secondary" class="w-full">Simulate</x-ui.button>
            </form>

            @if ($simResult)
                <div class="mt-4 rounded-xl bg-canvas p-4 text-sm">
                    <p class="eyebrow">Route</p>
                    <p class="mt-1 font-medium">{{ $simResult['rule'] ?? 'No route (fallback)' }}</p>
                    <p class="text-[12.5px] text-muted">{{ $simResult['strategy'] }}</p>
                    <p class="eyebrow mt-3">Try order</p>
                    @if ($simResult['providers'])
                        <ol class="mt-1 list-inside list-decimal space-y-0.5">
                            @foreach ($simResult['providers'] as $name)<li>{{ $name }}</li>@endforeach
                        </ol>
                    @else
                        <p class="mt-1 text-bad">No available provider</p>
                    @endif
                </div>
            @endif
        </x-ui.card>
    </div>

    @if ($showForm)
        <x-ui.modal :title="$editingId ? 'Edit route' : 'New route'" close="$set('showForm', false)" wide>
            <form id="route-form" wire:submit="save" class="space-y-5">
                <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                    <div>
                        <label for="r-name" class="label">Name</label>
                        <input id="r-name" wire:model="form.name" class="field" placeholder="e.g. Gmail recipients">
                        @error('form.name') <p class="mt-1.5 text-[13px] text-bad">{{ $message }}</p> @enderror
                    </div>
                    <div>
                        <label for="r-strategy" class="label">Strategy</label>
                        <select id="r-strategy" wire:model.live="form.strategy" class="field">
                            @foreach (\App\Models\RoutingRule::STRATEGIES as $v => $l)<option value="{{ $v }}">{{ $l }}</option>@endforeach
                        </select>
                    </div>
                </div>

                <label class="flex items-start gap-2.5 rounded-xl border border-line p-3 text-sm">
                    <input type="checkbox" wire:model.live="form.is_default" class="mt-0.5 size-4 accent-[#1677ff]">
                    <span><span class="font-medium">Default route</span><br><span class="text-[12.5px] text-muted">Used for any email no other rule matches. Only one route can be the default.</span></span>
                </label>

                @unless ($form['is_default'])
                    <div>
                        <p class="label">Conditions <span class="font-normal text-muted">(all must match)</span></p>
                        <div class="grid grid-cols-1 gap-3 sm:grid-cols-2">
                            <div>
                                <label for="c-from" class="mb-1 block text-[12.5px] text-muted">From domain</label>
                                <input id="c-from" wire:model="form.conditions.from_domain" class="field" placeholder="acme.com, *.acme.com">
                            </div>
                            <div>
                                <label for="c-to" class="mb-1 block text-[12.5px] text-muted">Recipient domain</label>
                                <input id="c-to" wire:model="form.conditions.to_domain" class="field" placeholder="gmail.com, yahoo.com">
                            </div>
                            <div>
                                <label for="c-tag" class="mb-1 block text-[12.5px] text-muted">Tag</label>
                                <input id="c-tag" wire:model="form.conditions.tag" class="field" placeholder="category or category:promo">
                            </div>
                            <div>
                                <label for="c-key" class="mb-1 block text-[12.5px] text-muted">API key</label>
                                <select id="c-key" wire:model="form.conditions.api_key_id" class="field">
                                    <option value="">Any key</option>
                                    @foreach ($apiKeys as $k)<option value="{{ $k->id }}">{{ $k->name }}</option>@endforeach
                                </select>
                            </div>
                        </div>
                        @error('form.conditions') <p class="mt-1.5 text-[13px] text-bad">{{ $message }}</p> @enderror
                    </div>
                @endunless

                <div>
                    <p class="label">Providers</p>
                    <p class="-mt-1 mb-2 text-[12.5px] text-muted">
                        {{ $form['strategy'] === 'weighted'
                            ? 'Traffic is split by weight; if the picked provider fails, the others are tried by priority.'
                            : 'Tried in priority order (lowest first); the next one takes over when one fails or hits its limit.' }}
                    </p>
                    <div class="overflow-hidden rounded-xl border border-line">
                        <div class="grid grid-cols-[minmax(0,1fr)_80px_80px] gap-3 bg-canvas px-3 py-2 text-[12px] text-muted">
                            <span>Provider</span><span>Priority</span><span>Weight</span>
                        </div>
                        @forelse ($providers as $id => $p)
                            <div wire:key="m-{{ $id }}" class="grid grid-cols-[minmax(0,1fr)_80px_80px] items-center gap-3 border-t border-line px-3 py-2">
                                <label class="flex min-w-0 items-center gap-2 text-sm">
                                    <input type="checkbox" wire:model.live="members.{{ $id }}.enabled" class="size-4 accent-[#1677ff]">
                                    <x-ui.provider-icon :type="$p->type" class="size-5 text-[10px]" />
                                    <span class="truncate">{{ $p->name }}</span>
                                </label>
                                <input type="number" min="0" wire:model="members.{{ $id }}.priority" class="field px-2 py-1.5" aria-label="Priority for {{ $p->name }}" @disabled(empty($members[$id]['enabled']))>
                                <input type="number" min="0" wire:model="members.{{ $id }}.weight" class="field px-2 py-1.5" aria-label="Weight for {{ $p->name }}" @disabled(empty($members[$id]['enabled']))>
                            </div>
                        @empty
                            <p class="border-t border-line px-3 py-4 text-sm text-muted">Add a provider first.</p>
                        @endforelse
                    </div>
                    @error('members') <p class="mt-1.5 text-[13px] text-bad">{{ $message }}</p> @enderror
                </div>

                <label class="flex items-center gap-2 text-sm">
                    <input type="checkbox" wire:model="form.is_active" class="size-4 accent-[#1677ff]"> Active
                </label>
            </form>
            <x-slot:footer>
                <x-ui.button variant="secondary" wire:click="$set('showForm', false)">Cancel</x-ui.button>
                <x-ui.button type="submit" form="route-form">Save route</x-ui.button>
            </x-slot:footer>
        </x-ui.modal>
    @endif
</div>
