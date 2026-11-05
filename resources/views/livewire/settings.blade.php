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

    {{-- Sign-in captcha --}}
    <form wire:submit="saveCaptcha" class="mt-8 max-w-2xl">
        <x-ui.card>
            <div class="flex flex-wrap items-start justify-between gap-3">
                <div>
                    <h2 class="text-[15px] font-semibold">Sign-in captcha</h2>
                    <p class="mt-1 text-[13px] text-muted">Google reCAPTCHA on the login page, on top of the 30-minute lockout after repeated wrong passwords.</p>
                </div>
                <x-ui.badge :tone="$captchaActive ? 'ok' : 'neutral'" dot>{{ $captchaActive ? 'Active' : 'Off' }}</x-ui.badge>
            </div>

            @if ($killSwitch)
                <div class="mt-4 rounded-xl border border-warn/30 bg-warn-soft px-4 py-3 text-[13px] text-warn">
                    <code class="font-mono">MAILROUTE_CAPTCHA_DISABLED=true</code> is set on the server, so the captcha is not shown regardless of this setting.
                </div>
            @endif

            <div class="mt-5">
                <span class="label">Mode</span>
                <div class="grid gap-2 sm:grid-cols-3">
                    @foreach ([
                        'off' => ['Off', 'No captcha on sign-in.'],
                        'v2' => ['reCAPTCHA v2', '"I\'m not a robot" checkbox.'],
                        'v3' => ['reCAPTCHA v3', 'Invisible, scores each sign-in.'],
                    ] as $value => [$label, $desc])
                        <label @class([
                            'flex cursor-pointer flex-col gap-1 rounded-xl border p-3 text-sm transition-colors',
                            'border-accent bg-accent-soft' => $captcha_mode === $value,
                            'border-line hover:bg-surface-2' => $captcha_mode !== $value,
                        ])>
                            <span class="flex items-center gap-2 font-medium">
                                <input type="radio" wire:model.live="captcha_mode" value="{{ $value }}" class="accent-[#1677ff]"> {{ $label }}
                            </span>
                            <span class="text-[12.5px] text-muted">{{ $desc }}</span>
                        </label>
                    @endforeach
                </div>
                @error('captcha_mode') <p class="mt-1.5 text-[13px] text-bad">{{ $message }}</p> @enderror
            </div>

            @if ($captcha_mode !== 'off')
                <div class="mt-5 space-y-4">
                    <p class="rounded-xl bg-surface-2 px-4 py-3 text-[12.5px] leading-relaxed text-muted">
                        Create keys at <a href="https://www.google.com/recaptcha/admin/create" target="_blank" rel="noopener noreferrer" class="text-accent hover:underline">google.com/recaptcha/admin</a>.
                        Pick <strong class="text-ink">{{ $captcha_mode === 'v2' ? 'reCAPTCHA v2 → "I\'m not a robot" Checkbox' : 'reCAPTCHA v3 (score based)' }}</strong>
                        and add the domain <strong class="text-ink">{{ request()->getHost() }}</strong>. v2 and v3 keys are not interchangeable.
                    </p>
                    <div>
                        <label for="cap-site" class="label">Site key</label>
                        <input id="cap-site" wire:model="captcha_site_key" class="field font-mono text-[13px]" autocomplete="off" spellcheck="false">
                        @error('captcha_site_key') <p class="mt-1.5 text-[13px] text-bad">{{ $message }}</p> @enderror
                    </div>
                    <div>
                        <label for="cap-secret" class="label">Secret key</label>
                        <input id="cap-secret" wire:model="captcha_secret" type="password" class="field font-mono text-[13px]" autocomplete="new-password"
                               placeholder="{{ $hasSecret ? 'Saved (encrypted). Leave blank to keep it.' : '' }}">
                        @error('captcha_secret') <p class="mt-1.5 text-[13px] text-bad">{{ $message }}</p> @enderror
                    </div>
                    @if ($captcha_mode === 'v3')
                        <div class="sm:w-1/2">
                            <label for="cap-score" class="label">Minimum score</label>
                            <input id="cap-score" wire:model="captcha_min_score" type="number" step="0.1" min="0.1" max="0.9" class="field">
                            <p class="mt-1.5 text-[12.5px] text-muted">0.1 lets almost everyone in, 0.9 is strict. 0.5 is Google's suggested start.</p>
                            @error('captcha_min_score') <p class="mt-1.5 text-[13px] text-bad">{{ $message }}</p> @enderror
                        </div>
                    @endif
                    <p class="text-[12.5px] text-muted">
                        Locked out by a wrong key? Run <code class="font-mono text-ink">php artisan app:captcha-off</code> on the server,
                        or set <code class="font-mono text-ink">MAILROUTE_CAPTCHA_DISABLED=true</code> in <code class="font-mono">.env</code>.
                    </p>
                </div>
            @endif

            <div class="mt-5 flex flex-wrap justify-end gap-2 border-t border-line pt-4">
                @if ($hasSecret)
                    <x-ui.button variant="ghost" wire:click="forgetCaptchaSecret" wire:confirm="Remove the saved secret key and turn the captcha off?" class="mr-auto text-bad hover:bg-bad-soft hover:text-bad">Remove keys</x-ui.button>
                @endif
                <x-ui.button type="submit">Save captcha</x-ui.button>
            </div>
        </x-ui.card>
    </form>
</div>
