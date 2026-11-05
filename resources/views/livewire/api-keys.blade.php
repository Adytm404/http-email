<div>
    <x-ui.page-header eyebrow="API keys" title="Keys for your applications"
        description="Send as a Bearer token. Sending-only keys can create emails but cannot read them back.">
        <x-slot:actions>
            <x-ui.button wire:click="create">Create key</x-ui.button>
        </x-slot:actions>
    </x-ui.page-header>

    <x-ui.card :padding="false" class="relative overflow-x-auto">
        @if ($keys->isEmpty())
            <x-ui.empty title="No API keys yet" description="Create a key, then point your Resend SDK or HTTP client at this server.">
                <x-ui.button wire:click="create">Create key</x-ui.button>
            </x-ui.empty>
        @else
            <table class="w-full min-w-[640px] text-sm">
                <thead>
                    <tr class="text-left text-[12px] text-muted">
                        <th class="px-6 py-3 font-medium">Name</th>
                        <th class="px-3 py-3 font-medium">Token</th>
                        <th class="px-3 py-3 font-medium">Permission</th>
                        <th class="px-3 py-3 text-right font-medium">Emails</th>
                        <th class="px-3 py-3 font-medium">Last used</th>
                        <th class="px-6 py-3"><span class="sr-only">Actions</span></th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($keys as $k)
                        <tr wire:key="key-{{ $k->id }}" @class(['border-t border-line', 'opacity-55' => $k->revoked_at])>
                            <td class="px-6 py-3.5 font-medium">{{ $k->name }}</td>
                            <td class="px-3 py-3.5 font-mono text-[12.5px] text-muted">{{ $k->prefix }}…</td>
                            <td class="px-3 py-3.5">
                                @if ($k->revoked_at)
                                    <x-ui.badge tone="bad">Revoked</x-ui.badge>
                                @else
                                    <x-ui.badge :tone="$k->permission === 'full_access' ? 'accent' : 'neutral'">{{ \App\Models\ApiKey::PERMISSIONS[$k->permission] }}</x-ui.badge>
                                @endif
                            </td>
                            <td class="px-3 py-3.5 text-right tabular-nums">{{ number_format($k->emails_count) }}</td>
                            <td class="px-3 py-3.5 text-muted">{{ $k->last_used_at?->diffForHumans() ?? 'Never' }}</td>
                            <td class="px-6 py-3.5 text-right">
                                @unless ($k->revoked_at)
                                    <x-ui.button variant="ghost" size="sm" wire:click="revoke({{ $k->id }})" wire:confirm="Revoke {{ $k->name }}? Apps using it will get 403 immediately." class="text-bad hover:bg-bad-soft hover:text-bad">Revoke</x-ui.button>
                                @endunless
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        @endif
    </x-ui.card>

    @if ($showForm)
        <x-ui.modal :title="$newToken ? 'Copy your key' : 'Create API key'" close="close">
            @if ($newToken)
                <div x-data="{ copied: false }">
                    <p class="text-sm text-muted">This is the only time the key is shown. Store it somewhere safe.</p>
                    <div class="mt-4 flex items-center gap-2 rounded-xl border border-line bg-canvas p-2 pl-3">
                        <code class="min-w-0 flex-1 break-all font-mono text-[13px]">{{ $newToken }}</code>
                        <x-ui.button size="sm" variant="secondary" x-on:click="navigator.clipboard.writeText(@js($newToken)); copied = true; setTimeout(() => copied = false, 1500)">
                            <span x-text="copied ? 'Copied' : 'Copy'">Copy</span>
                        </x-ui.button>
                    </div>
                </div>
            @else
                <form id="key-form" wire:submit="save" class="space-y-4">
                    <div>
                        <label for="k-name" class="label">Name</label>
                        <input id="k-name" wire:model="name" class="field" placeholder="e.g. Production app" autofocus>
                        @error('name') <p class="mt-1.5 text-[13px] text-bad">{{ $message }}</p> @enderror
                    </div>
                    <div>
                        <span class="label">Permission</span>
                        <div class="space-y-2">
                            @foreach (['full_access' => 'Send, read and cancel emails.', 'sending_access' => 'Only send emails.'] as $value => $desc)
                                <label @class(['flex cursor-pointer items-start gap-2.5 rounded-xl border p-3 text-sm', 'border-accent bg-accent-soft' => $permission === $value, 'border-line' => $permission !== $value])>
                                    <input type="radio" wire:model.live="permission" value="{{ $value }}" class="mt-0.5 accent-[#1677ff]">
                                    <span><span class="font-medium">{{ \App\Models\ApiKey::PERMISSIONS[$value] }}</span><br><span class="text-[12.5px] text-muted">{{ $desc }}</span></span>
                                </label>
                            @endforeach
                        </div>
                    </div>
                </form>
            @endif
            <x-slot:footer>
                @if ($newToken)
                    <x-ui.button wire:click="close">Done</x-ui.button>
                @else
                    <x-ui.button variant="secondary" wire:click="close">Cancel</x-ui.button>
                    <x-ui.button type="submit" form="key-form">Create key</x-ui.button>
                @endif
            </x-slot:footer>
        </x-ui.modal>
    @endif
</div>
