<div>
    <x-ui.page-header eyebrow="Settings" title="Router settings" />

    <form wire:submit="save" class="max-w-2xl space-y-4">
        <x-ui.card>
            <h2 class="text-[15px] font-semibold">Sending</h2>
            <div class="mt-4">
                <label for="s-from" class="label">Default from address</label>
                <input id="s-from" wire:model="default_from" class="field" placeholder="Acme <noreply@acme.com>">
                <p class="mt-1.5 text-[12.5px] text-muted">Used when an API request leaves out <code class="font-mono">from</code>, and prefilled for test emails.</p>
                @error('default_from') <p class="mt-1.5 text-[13px] text-bad">{{ $message }}</p> @enderror
            </div>
        </x-ui.card>

        <x-ui.card>
            <h2 class="text-[15px] font-semibold">Failover</h2>
            <p class="mt-1 text-[13px] text-muted">After this many failures in a row, a provider is skipped for the cooldown period. The next email after the cooldown checks whether it recovered.</p>
            <div class="mt-4 grid gap-4 sm:grid-cols-2">
                <div>
                    <label for="s-threshold" class="label">Failures before skipping</label>
                    <input id="s-threshold" type="number" min="1" wire:model="breaker_threshold" class="field">
                    @error('breaker_threshold') <p class="mt-1.5 text-[13px] text-bad">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label for="s-cooldown" class="label">Cooldown (seconds)</label>
                    <input id="s-cooldown" type="number" min="10" wire:model="breaker_cooldown_seconds" class="field">
                    @error('breaker_cooldown_seconds') <p class="mt-1.5 text-[13px] text-bad">{{ $message }}</p> @enderror
                </div>
            </div>
        </x-ui.card>

        <x-ui.card>
            <h2 class="text-[15px] font-semibold">Data retention</h2>
            <div class="mt-4 sm:w-1/2">
                <label for="s-retention" class="label">Keep emails for (days)</label>
                <input id="s-retention" type="number" min="1" wire:model="retention_days" class="field">
                <p class="mt-1.5 text-[12.5px] text-muted">Older emails, attempts and attachments are deleted daily by <code class="font-mono">emails:prune</code>.</p>
                @error('retention_days') <p class="mt-1.5 text-[13px] text-bad">{{ $message }}</p> @enderror
            </div>
        </x-ui.card>

        <div class="flex justify-end">
            <x-ui.button type="submit">Save settings</x-ui.button>
        </div>
    </form>
</div>
